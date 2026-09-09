<?php
/**
 * Ajustes: tres opciones.
 *   mjpm_settings  ajustes generales (precio base, método por defecto, redondeo, visibilidad)
 *   mjpm_methods   ajuste por medio de pago, indexado por id de gateway
 *   mjpm_custom    métodos propios, indexados por slug
 */

defined('ABSPATH') || exit;

class MJPM_Settings {

	const OPTION  = 'mjpm_settings';
	const METHODS = 'mjpm_methods';
	const CUSTOM  = 'mjpm_custom';

	private static $cache   = null;
	private static $methods = null;
	private static $custom  = null;

	/* ------------------------------ Generales ---------------------------- */

	public static function defaults() {
		return [
			'base_method'     => '',        // id del gateway cuyo precio es el cargado en WooCommerce.
			'display_method'  => '',        // id del gateway con el que se muestran los precios por defecto ('' = el base).
			'rounding'        => class_exists('MJD_Settings') ? (int) MJD_Settings::get('rounding') : 100,
			'rounding_mode'   => class_exists('MJD_Settings') ? (string) MJD_Settings::get('rounding_mode') : 'nearest',
			'apply_on_sale'   => 1,         // Los descuentos también aplican a productos en oferta.
			'stack_coupons'   => 1,         // Los descuentos se acumulan con cupones.
			'show_selector'   => 1,         // Selector "Ver precios con" en la tienda.
			'selector_label'  => 'Ver precios con',
			'show_badge'      => 1,         // Badge "10% OFF con transferencia" en tarjetas.
			'show_panel'      => 1,         // Panel de precios por medio de pago en la ficha.
			'show_list_price' => 1,         // Precio base tachado cuando el método mostrado tiene descuento.
		];
	}

	public static function rounding_choices() {
		return [
			0    => 'Sin redondeo (con centavos)',
			1    => 'Al peso',
			10   => 'A los $10',
			100  => 'A los $100',
			500  => 'A los $500',
			1000 => 'A los $1.000',
		];
	}

	public static function get($key = null) {
		if (null === self::$cache) {
			$saved       = get_option(self::OPTION, []);
			self::$cache = wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
		}
		if (null === $key) {
			return self::$cache;
		}
		return isset(self::$cache[$key]) ? self::$cache[$key] : null;
	}

	/** Guarda ajustes generales (parciales). Las casillas ausentes conservan su valor: mandá 0 explícito. */
	public static function update(array $values) {
		update_option(self::OPTION, self::sanitize_settings(wp_parse_args($values, self::get())));
		self::$cache = null;
	}

	public static function flush() {
		self::$cache   = null;
		self::$methods = null;
		self::$custom  = null;
	}

	public static function sanitize_settings($in) {
		$d   = self::defaults();
		$in  = is_array($in) ? $in : [];
		$out = [];

		$out['base_method']    = isset($in['base_method']) ? sanitize_key($in['base_method']) : '';
		$out['display_method'] = isset($in['display_method']) ? sanitize_key($in['display_method']) : '';

		$rounding        = isset($in['rounding']) ? absint($in['rounding']) : $d['rounding'];
		$out['rounding'] = array_key_exists($rounding, self::rounding_choices()) ? $rounding : $d['rounding'];
		$mode                 = isset($in['rounding_mode']) ? sanitize_key($in['rounding_mode']) : $d['rounding_mode'];
		$out['rounding_mode'] = in_array($mode, ['nearest', 'up'], true) ? $mode : 'nearest';

		foreach (['apply_on_sale', 'stack_coupons', 'show_selector', 'show_badge', 'show_panel', 'show_list_price'] as $flag) {
			$out[$flag] = empty($in[$flag]) ? 0 : 1;
		}
		$label                 = isset($in['selector_label']) ? trim(sanitize_text_field($in['selector_label'])) : $d['selector_label'];
		$out['selector_label'] = '' !== $label ? $label : $d['selector_label'];

		return $out;
	}

	/* ------------------------- Ajuste por método ------------------------- */

	public static function method_defaults() {
		return [
			'kind'       => 'none',    // none | discount | surcharge  (base se deriva de base_method)
			'mode'       => 'percent', // percent (por unidad, se ve en tarjetas) | fixed (monto fijo por pedido)
			'value'      => 0,
			'label'      => '',        // Justificación en el carrito. Placeholders: {metodo} {porcentaje} {monto}
			'badge'      => '',        // Texto en tarjetas (solo descuentos). Mismos placeholders.
			'short'      => '',        // Nombre corto para selector y panel ('' = título del gateway).
			'show'       => 1,         // Aparece en selector, panel y tarjetas.
			'min_amount' => 0,         // Subtotal mínimo (elegible) para aplicar el ajuste.
		];
	}

	/** Todos los ajustes guardados: id de gateway => config completa. */
	public static function methods() {
		if (null === self::$methods) {
			$saved         = get_option(self::METHODS, []);
			self::$methods = [];
			if (is_array($saved)) {
				foreach ($saved as $id => $cfg) {
					self::$methods[(string) $id] = wp_parse_args(is_array($cfg) ? $cfg : [], self::method_defaults());
				}
			}
		}
		return self::$methods;
	}

	public static function method($id) {
		$all = self::methods();
		return isset($all[$id]) ? $all[$id] : self::method_defaults();
	}

	public static function update_methods(array $all) {
		$clean = [];
		foreach ($all as $id => $cfg) {
			$id = sanitize_key($id);
			if ('' !== $id) {
				$clean[$id] = self::sanitize_method($cfg);
			}
		}
		update_option(self::METHODS, $clean);
		self::$methods = null;
	}

	public static function sanitize_method($in) {
		$d   = self::method_defaults();
		$in  = is_array($in) ? $in : [];
		$out = [];

		$kind        = isset($in['kind']) ? sanitize_key($in['kind']) : $d['kind'];
		$out['kind'] = in_array($kind, ['none', 'discount', 'surcharge'], true) ? $kind : 'none';
		$mode        = isset($in['mode']) ? sanitize_key($in['mode']) : $d['mode'];
		$out['mode'] = in_array($mode, ['percent', 'fixed'], true) ? $mode : 'percent';

		$value = isset($in['value']) ? self::decimal($in['value']) : 0;
		if ('percent' === $out['mode']) {
			$value = min(100, $value);
		}
		$out['value'] = max(0, $value);
		if ($out['value'] <= 0) {
			$out['kind'] = 'none';
		}

		$out['label']      = isset($in['label']) ? sanitize_text_field($in['label']) : '';
		$out['badge']      = isset($in['badge']) ? sanitize_text_field($in['badge']) : '';
		$out['short']      = isset($in['short']) ? sanitize_text_field($in['short']) : '';
		$out['show']       = empty($in['show']) ? 0 : 1;
		$out['min_amount'] = isset($in['min_amount']) ? max(0, self::decimal($in['min_amount'])) : 0;

		return $out;
	}

	/* ---------------------------- Métodos propios ------------------------ */

	public static function custom_defaults() {
		return [
			'id'           => '',        // id de gateway: mjpm_{slug}
			'title'        => '',
			'description'  => '',        // Texto bajo el título en el checkout.
			'instructions' => '',        // Página de gracias y email al cliente.
			'status'       => 'on-hold', // Estado del pedido al confirmar.
			'currency'     => 'ARS',     // ARS | USD (USD: muestra el total en dólares con MJ Dólar).
		];
	}

	public static function statuses() {
		return [
			'on-hold'    => 'En espera (hasta que confirmes el pago)',
			'processing' => 'Procesando (el pago se da por confirmado)',
			'completed'  => 'Completado',
			'pending'    => 'Pendiente de pago',
		];
	}

	/** Métodos propios: slug => definición completa. */
	public static function custom() {
		if (null === self::$custom) {
			$saved        = get_option(self::CUSTOM, []);
			self::$custom = [];
			if (is_array($saved)) {
				foreach ($saved as $slug => $def) {
					$slug = sanitize_key($slug);
					if ('' === $slug || !is_array($def)) {
						continue;
					}
					$def = wp_parse_args($def, self::custom_defaults());
					if ('' === $def['id']) {
						$def['id'] = self::gateway_id($slug);
					}
					self::$custom[$slug] = $def;
				}
			}
		}
		return self::$custom;
	}

	public static function gateway_id($slug) {
		return 'mjpm_' . str_replace('-', '_', sanitize_key($slug));
	}

	public static function custom_by_gateway($gateway_id) {
		foreach (self::custom() as $def) {
			if ($def['id'] === $gateway_id) {
				return $def;
			}
		}
		return null;
	}

	public static function update_custom(array $all) {
		$clean = [];
		foreach ($all as $slug => $def) {
			$slug = sanitize_key($slug);
			if ('' === $slug) {
				continue;
			}
			$def       = self::sanitize_custom($def);
			$def['id'] = self::gateway_id($slug);
			$clean[$slug] = $def;
		}
		update_option(self::CUSTOM, $clean);
		self::$custom = null;
	}

	public static function sanitize_custom($in) {
		$d   = self::custom_defaults();
		$in  = is_array($in) ? $in : [];
		$out = [];

		$out['id']           = isset($in['id']) ? sanitize_key($in['id']) : '';
		$out['title']        = isset($in['title']) ? trim(sanitize_text_field($in['title'])) : '';
		$out['description']  = isset($in['description']) ? sanitize_textarea_field($in['description']) : '';
		$out['instructions'] = isset($in['instructions']) ? sanitize_textarea_field($in['instructions']) : '';
		$status              = isset($in['status']) ? sanitize_key($in['status']) : $d['status'];
		$out['status']       = array_key_exists($status, self::statuses()) ? $status : $d['status'];
		$currency            = isset($in['currency']) ? strtoupper(sanitize_key($in['currency'])) : $d['currency'];
		$out['currency']     = 'USD' === $currency ? 'USD' : 'ARS';

		return $out;
	}

	/* ------------------------------- Helpers ----------------------------- */

	/** "10", "10,5", "1.500" → float. */
	public static function decimal($raw) {
		if (class_exists('MJD_Product')) {
			$v = MJD_Product::parse_decimal($raw);
			return '' === $v ? 0.0 : (float) $v;
		}
		$raw = trim((string) $raw);
		$raw = str_replace([' ', '$', '%'], '', $raw);
		if (false !== strpos($raw, ',') && false !== strpos($raw, '.')) {
			$raw = strrpos($raw, ',') > strrpos($raw, '.') ? str_replace(',', '.', str_replace('.', '', $raw)) : str_replace(',', '', $raw);
		} elseif (false !== strpos($raw, ',')) {
			$raw = str_replace(',', '.', $raw);
		}
		return is_numeric($raw) ? (float) $raw : 0.0;
	}

	/** Cachés de página conocidas: el header y las tarjetas llevan los precios impresos. */
	public static function flush_caches() {
		if (function_exists('wc_delete_product_transients')) {
			wc_delete_product_transients();
		}
		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
		}
		if (function_exists('w3tc_flush_all')) {
			w3tc_flush_all();
		}
		if (function_exists('wp_cache_clear_cache')) {
			wp_cache_clear_cache();
		}
		if (function_exists('sg_cachepress_purge_cache')) {
			sg_cachepress_purge_cache();
		}
		do_action('litespeed_purge_all');
		do_action('mjpm_flush_caches');
	}
}
