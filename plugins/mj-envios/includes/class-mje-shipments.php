<?php
/**
 * Envíos: qué hay para despachar y en qué anda cada cosa.
 *
 * Es la pantalla que evita ir saltando entre la lista de pedidos, el panel de
 * Andreani y el de Correo Argentino para contestar la única pregunta que
 * importa a la mañana: qué falta mandar. Un pedido está pendiente cuando tiene
 * un envío de MJ Envíos, está en uno de los estados que declaraste como "listo
 * para despachar" y todavía no tiene número de seguimiento.
 *
 * Desde acá se carga el seguimiento (de a uno o de a varios), que es lo que le
 * avisa al cliente y le deja el link para mirarlo. Dar de alta el envío en el
 * correo y bajar el rótulo sigue siendo cosa de su propio panel: esto no
 * inventa envíos, muestra los que hay.
 *
 * Qué estados cuentan y a los cuántos días un envío se considera atrasado son
 * ajustes, no números escritos acá.
 */

defined('ABSPATH') || exit;

class MJE_Shipments {

	const PER_PAGE = 30;

	public static function init() {
		add_action('admin_post_mje_save_tracking', [__CLASS__, 'save_tracking']);
	}

	/* ------------------------------ Consulta ----------------------------- */

	/** Los estados de pedido que se consideran listos para despachar. */
	public static function statuses() {
		$saved = MJE_Settings::to_array(MJE_Settings::get('ship_statuses'));
		$known = array_keys(self::status_choices());
		$out   = [];
		foreach ($saved as $status) {
			$status = 'wc-' . preg_replace('/^wc-/', '', sanitize_key($status));
			if (in_array($status, $known, true)) {
				$out[] = $status;
			}
		}
		return $out ? $out : ['wc-processing'];
	}

	/** Todos los estados de pedido de la tienda: clave => nombre. */
	public static function status_choices() {
		return function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
	}

	public static function filters() {
		return [
			'pendientes' => 'Pendientes de despacho',
			'despachados' => 'Ya despachados',
			'todos'      => 'Todos los envíos',
		];
	}

	/**
	 * Los pedidos de una vista.
	 *
	 * → ['orders' => WC_Order[], 'total' => int, 'pages' => int]
	 */
	public static function query($view = 'pendientes', $page = 1, $carrier = '', $method = '') {
		if (!function_exists('wc_get_orders')) {
			return ['orders' => [], 'total' => 0, 'pages' => 0];
		}

		$meta = [['key' => '_mje_method', 'compare' => 'EXISTS']];
		if ('pendientes' === $view) {
			$meta[] = ['key' => '_mje_tracking', 'compare' => 'NOT EXISTS'];
		} elseif ('despachados' === $view) {
			$meta[] = ['key' => '_mje_tracking', 'compare' => 'EXISTS'];
		}
		if ('' !== $method) {
			$meta[] = ['key' => '_mje_method', 'value' => $method, 'compare' => '='];
		}
		if ('' !== $carrier) {
			/* Sirve para los dos: el correo propio y el que entra por su plugin. */
			$meta[] = [
				'relation' => 'OR',
				['key' => '_mje_carrier', 'value' => $carrier, 'compare' => '='],
				['key' => '_mje_native_plugin', 'value' => $carrier, 'compare' => '='],
			];
		}

		$args = [
			'limit'      => self::PER_PAGE,
			'page'       => max(1, (int) $page),
			'orderby'    => 'date',
			'order'      => 'DESC',
			'paginate'   => true,
			'meta_query' => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		];
		if ('pendientes' === $view) {
			$args['status'] = self::statuses();
		}

		$result = wc_get_orders($args);
		if (!is_object($result)) {
			return ['orders' => is_array($result) ? $result : [], 'total' => 0, 'pages' => 0];
		}
		return [
			'orders' => isset($result->orders) ? (array) $result->orders : [],
			'total'  => isset($result->total) ? (int) $result->total : 0,
			'pages'  => isset($result->max_num_pages) ? (int) $result->max_num_pages : 0,
		];
	}

	/** Cuántos pedidos están esperando que los despachen. */
	public static function pending_count() {
		$cached = get_transient('mje_pending_count');
		if (false !== $cached) {
			return (int) $cached;
		}
		$total = self::query('pendientes', 1)['total'];
		set_transient('mje_pending_count', $total, 5 * MINUTE_IN_SECONDS);
		return $total;
	}

	public static function flush_count() {
		delete_transient('mje_pending_count');
	}

	/* ------------------------------- Datos ------------------------------- */

	/** Lo que se muestra de un pedido en la lista. */
	public static function row(WC_Order $order) {
		$slug   = (string) $order->get_meta('_mje_method');
		$def    = '' !== $slug ? MJE_Methods::get($slug) : null;
		$plugin = MJE_Native::get((string) $order->get_meta('_mje_native_plugin'));

		$carrier = '';
		if ($plugin) {
			$carrier = $plugin->label();
		} elseif ('' !== (string) $order->get_meta('_mje_carrier')) {
			$own     = MJE_Carriers::get((string) $order->get_meta('_mje_carrier'));
			$carrier = $own ? $own->label() : '';
		}

		$branch = (string) $order->get_meta('_mje_branch_name');
		if ('' === $branch) {
			$branch = (string) $order->get_meta('_mje_branch');
		}

		$destination = 'local' === (string) $order->get_meta('_mje_destination')
			? 'Retira en el local'
			: trim(implode(' · ', array_filter([
				$order->get_shipping_city(),
				$order->get_shipping_postcode(),
			])));

		$created = $order->get_date_created();

		return [
			'order'       => $order,
			'method'      => $def ? MJE_Methods::name($def) : (string) $order->get_meta('_mje_title'),
			'carrier'     => $carrier,
			'branch'      => $branch,
			'destination' => $destination,
			'tracking'    => (string) $order->get_meta('_mje_tracking'),
			'link'        => MJE_Tracking::url($order),
			'days'        => $created ? (int) floor((time() - $created->getTimestamp()) / DAY_IN_SECONDS) : 0,
		];
	}

	/** Un envío está atrasado cuando lleva más días de los que declaraste. */
	public static function is_late(array $row) {
		$limit = (int) MJE_Settings::get('ship_late_days');
		return $limit > 0 && '' === $row['tracking'] && $row['days'] >= $limit;
	}

	/* ------------------------------ Guardado ----------------------------- */

	/**
	 * Guarda los números de seguimiento cargados en la pantalla.
	 *
	 * Van todos juntos: se cargan los que tengas a mano y se guarda una vez. Los
	 * que quedaron vacíos no se tocan, así que guardar no borra lo que ya estaba.
	 */
	public static function save_tracking() {
		check_admin_referer('mje_save_tracking');
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Sin permisos.');
		}

		$codes  = isset($_POST['tracking']) && is_array($_POST['tracking']) ? wp_unslash($_POST['tracking']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Lo sanea MJE_Tracking::save().
		$notify = !empty($_POST['notify']);
		$saved  = 0;

		foreach ($codes as $order_id => $code) {
			$code = trim((string) $code);
			if ('' === $code) {
				continue;
			}
			if (MJE_Tracking::save((int) $order_id, $code, $notify)) {
				$saved++;
			}
		}

		self::flush_count();
		wp_safe_redirect(add_query_arg([
			'page'   => MJE_Admin::SLUG,
			'tab'    => 'envios',
			'vista'  => isset($_POST['vista']) ? sanitize_key(wp_unslash($_POST['vista'])) : 'pendientes',
			'saved'  => $saved > 0 ? 'tracking' : '',
			'cuenta' => $saved,
		], admin_url('admin.php')));
		exit;
	}
}
