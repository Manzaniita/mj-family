<?php
/**
 * Capa de producto: meta USD, conversión en tiempo real y persistencia en pesos.
 *
 * Dos mecanismos que se complementan:
 *   1. apply(): escribe USD × TC en los campos normales de WooCommerce
 *      (precio normal / oferta). Así la base queda coherente para
 *      ordenamientos, filtros, informes y otros plugins.
 *   2. Filtros de lectura (contexto 'view'): cada vez que WooCommerce lee el
 *      precio de un producto con USD, se recalcula desde el USD y el TC
 *      vigente. Si el TC cambió y el recálculo masivo todavía no pasó por el
 *      producto, el cliente igual ve el precio nuevo.
 */

defined('ABSPATH') || exit;

class MJD_Product {

	const META_REGULAR = '_mjd_usd_regular';
	const META_SALE    = '_mjd_usd_sale';
	const META_APPLIED = '_mjd_rate_applied'; // TC con el que se escribieron los pesos por última vez.
	const META_INDEX   = '_mjd_usd_index';    // USD de referencia a nivel producto (simple: el normal; variable: el mínimo de sus variaciones). Sirve para ordenar y filtrar el listado.
	const META_MAX     = '_mjd_usd_max';      // Solo variables: el máximo.

	/** @var bool Desactiva los filtros de lectura (para leer los pesos guardados tal cual). */
	private static $bypass = false;

	public static function init() {
		foreach (['product', 'product_variation'] as $type) {
			add_filter("woocommerce_{$type}_get_price", [__CLASS__, 'filter_price'], 20, 2);
			add_filter("woocommerce_{$type}_get_regular_price", [__CLASS__, 'filter_regular_price'], 20, 2);
			add_filter("woocommerce_{$type}_get_sale_price", [__CLASS__, 'filter_sale_price'], 20, 2);
		}

		/* Rango de precios de productos variables: WooCommerce lo lee en contexto 'edit' y lo cachea por hash. */
		add_filter('woocommerce_variation_prices_price', [__CLASS__, 'filter_variation_prices_price'], 20, 3);
		add_filter('woocommerce_variation_prices_regular_price', [__CLASS__, 'filter_variation_prices_regular'], 20, 3);
		add_filter('woocommerce_variation_prices_sale_price', [__CLASS__, 'filter_variation_prices_sale'], 20, 3);
		add_filter('woocommerce_get_variation_prices_hash', [__CLASS__, 'variation_prices_hash'], 20, 3);

		/* Índice USD del padre variable, para ordenar/filtrar el listado. */
		add_action('woocommerce_save_product_variation', [__CLASS__, 'after_variation_saved'], 20, 2);

		/* Productos creados/editados por la REST API de WooCommerce (wc/v3) con meta USD. */
		add_action('woocommerce_rest_insert_product_object', [__CLASS__, 'rest_inserted'], 10, 3);
		add_action('woocommerce_rest_insert_product_variation_object', [__CLASS__, 'rest_inserted'], 10, 3);
	}

	/* ---------------------------------------------------------------------
	 * Lectura de meta
	 * ------------------------------------------------------------------ */

	public static function has_usd($product) {
		return $product instanceof WC_Product && '' !== (string) $product->get_meta(self::META_REGULAR);
	}

	/** USD normal o null. */
	public static function usd_regular($product) {
		if (!$product instanceof WC_Product) {
			return null;
		}
		$v = (string) $product->get_meta(self::META_REGULAR);
		return '' !== $v && is_numeric($v) && (float) $v > 0 ? (float) $v : null;
	}

	/** USD de oferta o null (sin validar fechas ni que sea menor al normal). */
	public static function usd_sale($product) {
		if (!$product instanceof WC_Product) {
			return null;
		}
		$v = (string) $product->get_meta(self::META_SALE);
		return '' !== $v && is_numeric($v) && (float) $v > 0 ? (float) $v : null;
	}

	/**
	 * [usd_normal, usd_oferta|null]: la oferta solo si es menor al normal y
	 * está dentro de las fechas programadas. [null, null] si no tiene USD.
	 */
	public static function usd_effective($product) {
		$usd = self::usd_regular($product);
		if (null === $usd) {
			return [null, null];
		}
		$sale = self::usd_sale($product);
		if (null === $sale || $sale >= $usd || !self::sale_dates_ok($product)) {
			$sale = null;
		}
		return [$usd, $sale];
	}

	/** Misma regla de fechas que WC_Product::is_on_sale(). */
	public static function sale_dates_ok($product) {
		$now  = time();
		$from = $product->get_date_on_sale_from('edit');
		$to   = $product->get_date_on_sale_to('edit');
		if ($from && $from->getTimestamp() > $now) {
			return false;
		}
		if ($to && $to->getTimestamp() < $now) {
			return false;
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Escritura
	 * ------------------------------------------------------------------ */

	/**
	 * Normaliza un importe escrito por una persona o venido de un CSV:
	 * "USD 1.234,50", "1234.50", "1,234.5", "120,5" → "1234.5" / "120.5".
	 * Devuelve '' si está vacío o no es un número. Nunca negativo.
	 *
	 * Regla: si hay punto y coma, el ÚLTIMO es el decimal. Solo coma → decimal.
	 * Solo punto → decimal (formato del exportador de WooCommerce); varios
	 * puntos → separadores de miles.
	 */
	public static function parse_decimal($raw) {
		if (is_float($raw) || is_int($raw)) {
			return $raw < 0 ? '' : wc_format_decimal((float) $raw, false, true);
		}
		$raw = trim((string) $raw);
		if ('' === $raw) {
			return '';
		}
		$raw = preg_replace('/[^\d.,\-]/', '', $raw);
		if ('' === $raw || '-' === $raw) {
			return '';
		}
		$dot   = strrpos($raw, '.');
		$comma = strrpos($raw, ',');
		if (false !== $dot && false !== $comma) {
			if ($comma > $dot) {
				$raw = str_replace('.', '', $raw);
				$raw = str_replace(',', '.', $raw);
			} else {
				$raw = str_replace(',', '', $raw);
			}
		} elseif (false !== $comma) {
			$raw = substr_count($raw, ',') > 1 ? str_replace(',', '', $raw) : str_replace(',', '.', $raw);
		} elseif (false !== $dot && substr_count($raw, '.') > 1) {
			$raw = str_replace('.', '', $raw);
		}
		if (!is_numeric($raw) || (float) $raw < 0) {
			return '';
		}
		return wc_format_decimal((float) $raw, false, true);
	}

	/**
	 * Guarda los USD en el objeto (no persiste: el llamador hace save()).
	 * '' en el normal → el producto vuelve a pesos fijos (se borra todo el meta USD).
	 *
	 * @return bool true si el producto queda con precio USD.
	 */
	public static function set_usd($product, $regular, $sale = '') {
		$regular = self::parse_decimal($regular);
		$sale    = self::parse_decimal($sale);
		if ('' !== $regular && (float) $regular <= 0) {
			$regular = '';
		}
		if ('' === $regular || '' === $sale || (float) $sale <= 0 || (float) $sale >= (float) $regular) {
			$sale = '';
		}

		$is_variation = $product->is_type('variation');

		if ('' === $regular) {
			$product->delete_meta_data(self::META_REGULAR);
			$product->delete_meta_data(self::META_SALE);
			$product->delete_meta_data(self::META_APPLIED);
			if (!$is_variation) {
				$product->delete_meta_data(self::META_INDEX);
				$product->delete_meta_data(self::META_MAX);
			}
			return false;
		}

		$product->update_meta_data(self::META_REGULAR, $regular);
		if ('' === $sale) {
			$product->delete_meta_data(self::META_SALE);
		} else {
			$product->update_meta_data(self::META_SALE, $sale);
		}
		if (!$is_variation && !$product->is_type('variable')) {
			$product->update_meta_data(self::META_INDEX, $regular);
			$product->delete_meta_data(self::META_MAX);
		}
		return true;
	}

	/**
	 * Escribe USD × TC en precio normal / oferta del objeto.
	 *
	 * @param WC_Product $product
	 * @param float|null $rate TC a usar (null = vigente).
	 * @param bool       $save Persistir ahora o dejar que el llamador guarde.
	 * @return bool false si el producto no tiene USD o no hay TC (no se toca nada).
	 */
	public static function apply($product, $rate = null, $save = true) {
		$rate = null === $rate ? MJD_Rate::get() : (float) $rate;
		if ($rate <= 0 || !$product instanceof WC_Product) {
			return false;
		}
		$usd = self::usd_regular($product);
		if (null === $usd) {
			return false;
		}

		$regular  = MJD_Rate::convert($usd, $rate, $product);
		$sale     = '';
		$usd_sale = self::usd_sale($product);
		if (null !== $usd_sale && $usd_sale < $usd) {
			$s = MJD_Rate::convert($usd_sale, $rate, $product);
			if ($s < $regular) {
				$sale = $s;
			}
		}

		$product->set_regular_price(self::decimal($regular));
		$product->set_sale_price('' === $sale ? '' : self::decimal($sale));
		$product->update_meta_data(self::META_APPLIED, MJD_Rate::normalize($rate));

		if ($save) {
			$product->save();
		}
		return true;
	}

	/**
	 * Recalcula el índice USD (mín/máx) de un producto variable a partir de sus
	 * variaciones. Se escribe directo en postmeta: es un dato derivado.
	 */
	public static function sync_parent_usd($parent_id) {
		global $wpdb;
		$parent_id = (int) $parent_id;
		if (!$parent_id) {
			return;
		}
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT MIN(CAST(pm.meta_value AS DECIMAL(18,4))) AS min_usd, MAX(CAST(pm.meta_value AS DECIMAL(18,4))) AS max_usd
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status IN ('publish', 'private') AND pm.meta_value <> ''",
			self::META_REGULAR,
			$parent_id
		));
		if ($row && null !== $row->min_usd) {
			update_post_meta($parent_id, self::META_INDEX, wc_format_decimal((float) $row->min_usd, false, true));
			update_post_meta($parent_id, self::META_MAX, wc_format_decimal((float) $row->max_usd, false, true));
		} else {
			delete_post_meta($parent_id, self::META_INDEX);
			delete_post_meta($parent_id, self::META_MAX);
		}
	}

	public static function after_variation_saved($variation_id, $i = 0) {
		$variation = wc_get_product($variation_id);
		if ($variation && $variation->is_type('variation')) {
			self::sync_parent_usd($variation->get_parent_id());
		}
	}

	/** wc/v3: si el request trajo meta USD, normalizar y recalcular pesos. */
	public static function rest_inserted($object, $request, $creating) {
		$meta = $request instanceof WP_REST_Request ? $request->get_param('meta_data') : null;
		if (!is_array($meta) || !$object instanceof WC_Product) {
			return;
		}
		$touched = false;
		foreach ($meta as $m) {
			$key = '';
			if (is_array($m) && isset($m['key'])) {
				$key = $m['key'];
			} elseif (is_object($m) && isset($m->key)) {
				$key = $m->key;
			}
			if (in_array($key, [self::META_REGULAR, self::META_SALE], true)) {
				$touched = true;
				break;
			}
		}
		if (!$touched) {
			return;
		}
		self::set_usd($object, $object->get_meta(self::META_REGULAR), $object->get_meta(self::META_SALE));
		self::apply($object, null, false);
		$object->save();
		if ($object->is_type('variation')) {
			self::sync_parent_usd($object->get_parent_id());
		}
	}

	/* ---------------------------------------------------------------------
	 * Filtros de lectura (tiempo real)
	 * ------------------------------------------------------------------ */

	/** Ejecuta $fn con los filtros de lectura apagados (pesos guardados tal cual). */
	public static function without_conversion(callable $fn) {
		$prev         = self::$bypass;
		self::$bypass = true;
		try {
			return $fn();
		} finally {
			self::$bypass = $prev;
		}
	}

	/** [pesos_normal, pesos_oferta|''] o null si el producto no se convierte. */
	private static function computed($product) {
		if (self::$bypass || !$product instanceof WC_Product) {
			return null;
		}
		$usd = self::usd_regular($product);
		if (null === $usd) {
			return null;
		}
		$rate = MJD_Rate::get();
		if ($rate <= 0) {
			return null;
		}
		$regular  = MJD_Rate::convert($usd, $rate, $product);
		$sale     = '';
		$usd_sale = self::usd_sale($product);
		if (null !== $usd_sale && $usd_sale < $usd) {
			$s = MJD_Rate::convert($usd_sale, $rate, $product);
			if ($s < $regular) {
				$sale = $s;
			}
		}
		return [$regular, $sale];
	}

	public static function filter_regular_price($value, $product) {
		$c = self::computed($product);
		return null === $c ? $value : self::decimal($c[0]);
	}

	public static function filter_sale_price($value, $product) {
		$c = self::computed($product);
		if (null === $c) {
			return $value;
		}
		return '' === $c[1] ? '' : self::decimal($c[1]);
	}

	public static function filter_price($value, $product) {
		$c = self::computed($product);
		if (null === $c) {
			return $value;
		}
		$on_sale = '' !== $c[1] && self::sale_dates_ok($product);
		return self::decimal($on_sale ? $c[1] : $c[0]);
	}

	public static function filter_variation_prices_price($price, $variation, $product) {
		return self::filter_price($price, $variation);
	}

	public static function filter_variation_prices_regular($price, $variation, $product) {
		return self::filter_regular_price($price, $variation);
	}

	public static function filter_variation_prices_sale($price, $variation, $product) {
		return self::filter_sale_price($price, $variation);
	}

	/** El caché de rangos de variables debe invalidarse cuando cambia el TC o el redondeo. */
	public static function variation_prices_hash($hash, $product, $for_display) {
		$hash['mjd'] = [MJD_Rate::get(), MJD_Settings::get('rounding'), MJD_Settings::get('rounding_mode')];
		return $hash;
	}

	/** float → string decimal sin ceros sobrantes ("145000", "1450.5"). */
	public static function decimal($n) {
		return wc_format_decimal((float) $n, false, true);
	}
}
