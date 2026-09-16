<?php
/**
 * Registro de medios de pago (gateways de WooCommerce + propios) y la
 * matemática del ajuste: factor, redondeo, precio por método, elegibilidad y
 * cálculo de la línea del carrito. Es la API que usan el carrito, la REST, la
 * SPA (misma regla en JS) y los plugins que vengan después.
 */

defined('ABSPATH') || exit;

class MJPM_Methods {

	public static function init() {
		/* Los métodos propios se registran como gateways reales. */
		add_filter('woocommerce_payment_gateways', [__CLASS__, 'register_custom_gateways'], 50);
	}

	public static function register_custom_gateways($gateways) {
		$custom = MJPM_Settings::custom();
		if (!$custom || !class_exists('WC_Payment_Gateway')) {
			return $gateways;
		}
		require_once MJPM_DIR . 'includes/class-mjpm-gateway.php';
		foreach ($custom as $def) {
			$gateways[] = new MJPM_Gateway($def);
		}
		return $gateways;
	}

	/* ------------------------------ Gateways ----------------------------- */

	/** Todos los gateways registrados, en el orden de WooCommerce: id => objeto. */
	public static function gateways() {
		if (!function_exists('WC') || !WC()->payment_gateways()) {
			return [];
		}
		$out = [];
		foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
			if ($gateway instanceof WC_Payment_Gateway) {
				$out[$gateway->id] = $gateway;
			}
		}
		return $out;
	}

	public static function gateway($id) {
		$all = self::gateways();
		return isset($all[$id]) ? $all[$id] : null;
	}

	public static function enabled_ids() {
		$ids = [];
		foreach (self::gateways() as $id => $gateway) {
			if ('yes' === $gateway->enabled) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	public static function title($id) {
		$gateway = self::gateway($id);
		return $gateway ? wp_strip_all_tags((string) $gateway->get_title()) : (string) $id;
	}

	/* ------------------------------ Config ------------------------------- */

	/** Config del método con 'kind' resuelto ('base' si es el del precio base) e 'id'. */
	public static function config($id) {
		$id  = (string) $id;
		$cfg = MJPM_Settings::method($id);
		$cfg['id']      = $id;
		$cfg['is_base'] = '' !== $id && $id === (string) MJPM_Settings::get('base_method');
		if ($cfg['is_base']) {
			$cfg['kind'] = 'base';
		}
		return $cfg;
	}

	public static function adjusts(array $cfg) {
		return in_array($cfg['kind'], ['discount', 'surcharge'], true) && (float) $cfg['value'] > 0;
	}

	/** Multiplicador del precio unitario (1 si el método no ajusta por porcentaje). */
	public static function factor(array $cfg) {
		if (!self::adjusts($cfg) || 'percent' !== $cfg['mode']) {
			return 1.0;
		}
		$pct = (float) $cfg['value'] / 100;
		return 'discount' === $cfg['kind'] ? max(0.0, 1 - $pct) : 1 + $pct;
	}

	public static function round_price($amount) {
		$amount = (float) $amount;
		$step   = (int) MJPM_Settings::get('rounding');
		if ($step <= 0) {
			return round($amount, (int) wc_get_price_decimals());
		}
		$q = $amount / $step;
		$q = 'up' === MJPM_Settings::get('rounding_mode') ? ceil($q - 1e-9) : round($q);
		return (float) ($q * $step);
	}

	/** Precio unitario con el método aplicado (misma regla que lib/payments.js en la SPA). */
	public static function price_for($unit, array $cfg) {
		if (!self::adjusts($cfg) || 'percent' !== $cfg['mode']) {
			return (float) $unit;
		}
		return self::round_price((float) $unit * self::factor($cfg));
	}

	/* ---------------------------- Elegibilidad --------------------------- */

	/** ¿El producto participa de descuentos/recargos? (exclusión por producto o categoría). */
	public static function product_eligible($product) {
		if (!$product instanceof WC_Product) {
			return false;
		}
		$parent_id = $product->is_type('variation') ? (int) $product->get_parent_id() : 0;
		if ('yes' === (string) $product->get_meta('_mjpm_exclude')) {
			return false;
		}
		if ($parent_id && 'yes' === (string) get_post_meta($parent_id, '_mjpm_exclude', true)) {
			return false;
		}
		$term_ids = wc_get_product_term_ids($parent_id ? $parent_id : $product->get_id(), 'product_cat');
		foreach ($term_ids as $term_id) {
			if ('yes' === (string) get_term_meta($term_id, 'mjpm_exclude', true)) {
				return false;
			}
		}
		return (bool) apply_filters('mjpm_product_eligible', true, $product);
	}

	/** Elegible para ESTE ajuste (además de la exclusión, la regla de ofertas). */
	public static function item_eligible($product, array $cfg) {
		if (!self::product_eligible($product)) {
			return false;
		}
		if ('discount' === $cfg['kind'] && !MJPM_Settings::get('apply_on_sale') && $product->is_on_sale()) {
			return false;
		}
		return true;
	}

	/* ------------------------------- Textos ------------------------------ */

	private static function placeholders(array $cfg) {
		$title = '' !== (string) $cfg['short'] ? (string) $cfg['short'] : self::title($cfg['id']);
		$pct   = 'percent' === $cfg['mode'] ? self::number((float) $cfg['value']) . '%' : '';
		$fixed = 'fixed' === $cfg['mode'] ? self::money((float) $cfg['value']) : '';
		return [
			'{metodo}'     => $title,
			'{porcentaje}' => $pct,
			'{monto}'      => $fixed,
		];
	}

	/** Justificación de la línea del carrito. */
	public static function label(array $cfg) {
		$text = trim((string) $cfg['label']);
		if ('' === $text) {
			if ('discount' === $cfg['kind']) {
				$text = 'percent' === $cfg['mode'] ? 'Descuento por pago con {metodo} ({porcentaje})' : 'Descuento por pago con {metodo}';
			} elseif ('surcharge' === $cfg['kind']) {
				$text = 'percent' === $cfg['mode'] ? 'Recargo por pago con {metodo} ({porcentaje})' : 'Recargo por pago con {metodo}';
			} else {
				return '';
			}
		}
		return trim(strtr($text, self::placeholders($cfg)));
	}

	/** Texto del badge en tarjetas (solo descuentos por porcentaje). */
	public static function badge(array $cfg) {
		if ('discount' !== $cfg['kind'] || 'percent' !== $cfg['mode'] || !self::adjusts($cfg)) {
			return '';
		}
		$text = trim((string) $cfg['badge']);
		if ('' === $text) {
			$text = '{porcentaje} OFF con {metodo}';
		}
		return trim(strtr($text, self::placeholders($cfg)));
	}

	public static function number($n) {
		$n = (float) $n;
		return number_format($n, abs($n - round($n)) < 0.005 ? 0 : 2, wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
	}

	public static function money($n) {
		return trim(html_entity_decode(wp_strip_all_tags(wc_price((float) $n)), ENT_QUOTES, 'UTF-8'));
	}

	/* ------------------------------ Carrito ------------------------------ */

	/**
	 * Ajuste del carrito para un método. amount negativo = descuento.
	 * Por porcentaje: suma por ítem de (precio con método − precio base) × cantidad,
	 * así el total coincide exacto con los precios que vio el cliente.
	 */
	public static function calculate_for_cart($cart, $id) {
		$cfg = self::config($id);
		$out = [
			'method'            => (string) $id,
			'kind'              => $cfg['kind'],
			'mode'              => $cfg['mode'],
			'value'             => (float) $cfg['value'],
			'amount'            => 0.0,
			'label'             => self::label($cfg),
			'eligible_subtotal' => 0.0,
			'reason'            => '',
		];
		if (!$cart instanceof WC_Cart || !self::adjusts($cfg)) {
			$out['reason'] = 'none';
			return $out;
		}
		if ('discount' === $cfg['kind'] && !MJPM_Settings::get('stack_coupons') && $cart->get_applied_coupons()) {
			$out['reason'] = 'coupon';
			return $out;
		}

		$eligible = 0.0;
		$amount   = 0.0;
		foreach ($cart->get_cart() as $item) {
			$product = isset($item['data']) ? $item['data'] : null;
			if (!$product instanceof WC_Product || !self::item_eligible($product, $cfg)) {
				continue;
			}
			$qty  = max(0, (int) $item['quantity']);
			$unit = (float) $product->get_price();
			$eligible += $unit * $qty;
			if ('percent' === $cfg['mode']) {
				$amount += (self::price_for($unit, $cfg) - $unit) * $qty;
			}
		}
		if ('fixed' === $cfg['mode']) {
			$amount = $eligible > 0 ? ('discount' === $cfg['kind'] ? -1 : 1) * (float) $cfg['value'] : 0.0;
		}
		if ((float) $cfg['min_amount'] > 0 && $eligible < (float) $cfg['min_amount']) {
			$amount        = 0.0;
			$out['reason'] = 'min';
		}
		$out['eligible_subtotal'] = $eligible;
		$out['amount']            = round($amount, (int) wc_get_price_decimals());

		return (array) apply_filters('mjpm_cart_adjustment', $out, $cart, $cfg);
	}

	/* --------------------------- Config pública -------------------------- */

	/** Lo que ve la SPA (window.MJ.paymentPricing) y GET /mjpm/v1/methods. */
	public static function public_payload() {
		$s       = MJPM_Settings::get();
		$methods = [];
		foreach (self::gateways() as $id => $gateway) {
			if ('yes' !== $gateway->enabled) {
				continue;
			}
			$cfg    = self::config($id);
			$custom = MJPM_Settings::custom_by_gateway($id);
			$title  = wp_strip_all_tags((string) $gateway->get_title());
			/* Los datos para pagar son públicos solo si el medio los muestra en el checkout. */
			$info      = $cfg['details_checkout'] ? MJPM_Payment_Info::for_method($id) : null;
			$methods[] = [
				'id'           => $id,
				'title'        => $title,
				'short'        => '' !== (string) $cfg['short'] ? (string) $cfg['short'] : $title,
				'description'  => wp_strip_all_tags((string) $gateway->get_description()),
				'kind'         => $cfg['kind'],
				'mode'         => $cfg['mode'],
				'value'        => (float) $cfg['value'],
				'label'        => self::label($cfg),
				'badge'        => self::badge($cfg),
				'minAmount'    => (float) $cfg['min_amount'],
				'show'         => (bool) $cfg['show'],
				'custom'       => (bool) $custom,
				'currency'     => $custom && 'USD' === $custom['currency'] ? 'USD' : get_woocommerce_currency(),
				'instructions' => $custom ? (string) $custom['instructions'] : '',
				'detailsTitle' => $info ? (string) $info['detailsTitle'] : '',
				'details'      => $info ? $info['details'] : [],
			];
		}
		$base    = (string) $s['base_method'];
		$display = (string) $s['display_method'];
		return [
			'base'           => $base,
			'defaultDisplay' => '' !== $display ? $display : $base,
			'rounding'       => (int) $s['rounding'],
			'roundingMode'   => (string) $s['rounding_mode'],
			'applyOnSale'    => (bool) $s['apply_on_sale'],
			'stackCoupons'   => (bool) $s['stack_coupons'],
			'showSelector'   => (bool) $s['show_selector'],
			'selectorLabel'  => (string) $s['selector_label'],
			'showBadge'      => (bool) $s['show_badge'],
			'showPanel'      => (bool) $s['show_panel'],
			'showListPrice'  => (bool) $s['show_list_price'],
			'texts'          => MJPM_Payment_Info::texts(),
			'methods'        => $methods,
		];
	}
}
