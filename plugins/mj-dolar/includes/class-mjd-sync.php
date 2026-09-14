<?php
/**
 * Recálculo masivo de precios en pesos.
 *
 * Se dispara al cambiar el TC o el redondeo (y a mano desde la página). Corre
 * en lotes cortos: los maneja Action Scheduler en segundo plano y, si la
 * página del admin está abierta, también la impulsa vía AJAX para mostrar el
 * progreso. Ambos comparten la misma cola y un lock, así nunca pisan lotes.
 *
 * Mientras corre, los clientes ya ven los precios nuevos gracias a los
 * filtros de lectura de MJD_Product: acá solo se deja la base coherente.
 */

defined('ABSPATH') || exit;

class MJD_Sync {

	const STATE  = 'mjd_sync_state';
	const QUEUE  = 'mjd_sync_queue';
	const LOCK   = 'mjd_sync_lock';
	const ACTION = 'mjd_sync_run';
	const GROUP  = 'mj-dolar';
	const BATCH  = 25;

	public static function init() {
		add_action(self::ACTION, [__CLASS__, 'run_background']);
		add_action('mjd_rate_updated', [__CLASS__, 'on_rate_updated'], 10, 0);
	}

	public static function on_rate_updated() {
		self::start('rate');
	}

	/** IDs (productos y variaciones) con precio USD, agrupados por padre. */
	public static function ids() {
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_type IN ('product', 'product_variation') AND p.post_status <> 'trash' AND pm.meta_value <> ''
			 ORDER BY p.post_parent ASC, p.ID ASC",
			MJD_Product::META_REGULAR
		));
		return array_map('intval', (array) $ids);
	}

	/** Conteos para la página: productos, variaciones y cuántos tienen pesos calculados con otro TC. */
	public static function counts() {
		global $wpdb;
		$rate = MJD_Rate::normalize(MJD_Rate::get());

		$products = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_type = 'product' AND p.post_status <> 'trash' AND pm.meta_value <> ''",
			MJD_Product::META_REGULAR
		));
		$variations = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_type = 'product_variation' AND p.post_status <> 'trash' AND pm.meta_value <> ''",
			MJD_Product::META_REGULAR
		));
		$stale = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} pa ON pa.post_id = p.ID AND pa.meta_key = %s
			 WHERE p.post_type IN ('product', 'product_variation') AND p.post_status <> 'trash' AND pm.meta_value <> ''
			   AND (pa.meta_value IS NULL OR pa.meta_value <> %s)",
			MJD_Product::META_REGULAR,
			MJD_Product::META_APPLIED,
			$rate
		));

		return [
			'products'   => $products,
			'variations' => $variations,
			'stale'      => $stale,
		];
	}

	public static function state() {
		$defaults = [
			'status'      => 'idle', // idle | running | done
			'run'         => '',
			'total'       => 0,
			'done'        => 0,
			'started_at'  => 0,
			'finished_at' => 0,
			'rate'        => '',
			'reason'      => '',
		];
		$state = get_option(self::STATE);
		return is_array($state) ? wp_parse_args($state, $defaults) : $defaults;
	}

	/** Arma la cola y programa el proceso en segundo plano. Devuelve el estado. */
	public static function start($reason = 'manual') {
		if (!MJD_Rate::is_set()) {
			return self::state();
		}
		$ids   = self::ids();
		$state = [
			'status'      => $ids ? 'running' : 'done',
			'run'         => uniqid('', true),
			'total'       => count($ids),
			'done'        => 0,
			'started_at'  => time(),
			'finished_at' => $ids ? 0 : time(),
			'rate'        => MJD_Rate::normalize(MJD_Rate::get()),
			'reason'      => sanitize_key($reason),
		];
		update_option(self::QUEUE, $ids, false);
		update_option(self::STATE, $state, false);
		if ('running' === $state['status']) {
			self::schedule();
		}
		return $state;
	}

	/**
	 * Programa una corrida en segundo plano.
	 *
	 * @param int  $delay Segundos de espera.
	 * @param bool $force true desde la propia corrida (para encadenar la siguiente
	 *                    aunque Action Scheduler todavía la vea "en curso").
	 */
	public static function schedule($delay = 0, $force = false) {
		$when = time() + max(0, (int) $delay);

		if (function_exists('as_schedule_single_action')) {
			if (!$force && function_exists('as_next_scheduled_action') && false !== as_next_scheduled_action(self::ACTION, [], self::GROUP)) {
				return;
			}
			as_schedule_single_action($when, self::ACTION, [], self::GROUP);
			return;
		}

		/* Sin Action Scheduler (no debería pasar con WooCommerce): WP-Cron. */
		if ($force) {
			wp_schedule_single_event($when, self::ACTION, [(string) microtime(true)]);
		} elseif (!wp_next_scheduled(self::ACTION)) {
			wp_schedule_single_event($when, self::ACTION);
		}
	}

	/**
	 * Procesa un lote. Devuelve el estado actualizado; con ['locked' => true]
	 * si otra corrida tenía el lock.
	 */
	public static function step($batch = self::BATCH) {
		$state = self::state();
		if ('running' !== $state['status']) {
			return $state;
		}
		if (get_transient(self::LOCK)) {
			$state['locked'] = true;
			return $state;
		}
		set_transient(self::LOCK, 1, 2 * MINUTE_IN_SECONDS);

		try {
			$queue   = get_option(self::QUEUE, []);
			$ids     = array_slice((array) $queue, (int) $state['done'], max(1, (int) $batch));
			$rate    = (float) $state['rate'];
			$parents = [];

			foreach ($ids as $id) {
				$product = wc_get_product((int) $id);
				if (!$product) {
					continue;
				}
				MJD_Product::apply($product, $rate, true);
				if ($product->is_type('variation')) {
					$parents[$product->get_parent_id()] = true;
				}
			}
			foreach (array_keys($parents) as $parent_id) {
				WC_Product_Variable::sync($parent_id);
				MJD_Product::sync_parent_usd($parent_id);
			}

			/* Si alguien reinició la cola mientras corríamos, este lote ya no cuenta. */
			$fresh = self::state();
			if ($fresh['run'] !== $state['run']) {
				return $fresh;
			}

			$state['done'] = min((int) $state['total'], (int) $state['done'] + count($ids));
			if (empty($ids) || $state['done'] >= (int) $state['total']) {
				$state['status']      = 'done';
				$state['finished_at'] = time();
				delete_option(self::QUEUE);
				if (function_exists('wc_delete_product_transients')) {
					wc_delete_product_transients();
				}
				do_action('mjd_sync_finished', $state);
			}
			update_option(self::STATE, $state, false);
		} catch (Throwable $e) {
			/* Si el recálculo se corta por la mitad, parte del catálogo queda
			   al tipo de cambio viejo y no se nota mirando la tienda. Queda
			   anotado; el error sigue subiendo como antes. */
			MJ_Log::error('dolar', 'Se cortó el recálculo de precios', [
				'motivo'    => $e->getMessage(),
				'archivo'   => $e->getFile() . ':' . $e->getLine(),
				'hechos'    => (int) $state['done'],
				'de'        => (int) $state['total'],
			]);
			throw $e;
		} finally {
			delete_transient(self::LOCK);
		}

		return $state;
	}

	/** Callback de Action Scheduler / WP-Cron: procesa ~20 s y encadena la siguiente corrida. */
	public static function run_background() {
		$started = time();
		while (time() - $started < 20) {
			$state = self::step();
			if ('running' !== $state['status']) {
				return;
			}
			if (!empty($state['locked'])) {
				self::schedule(30, true);
				return;
			}
		}
		self::schedule(0, true);
	}

	/** Estado + conteos, para AJAX/REST. */
	public static function status() {
		$state           = self::state();
		$state['counts'] = self::counts();
		$state['percent'] = $state['total'] > 0 ? (int) floor($state['done'] * 100 / $state['total']) : 100;
		return $state;
	}
}
