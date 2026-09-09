<?php
/**
 * Carrito y checkout: método vigente en la sesión, línea de ajuste (fee) y
 * datos guardados en el pedido.
 *
 * El método vigente es el elegido en la sesión (chosen_payment_method, que
 * setea WooCommerce en el checkout clásico y este plugin desde la Store API
 * vía cart/extensions) o, si no hay, el método "por defecto" del admin.
 */

defined('ABSPATH') || exit;

class MJPM_Cart {

	const SESSION_KEY = 'mjpm_method';

	public static function init() {
		add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'add_fee'], 20, 1);

		/* Store API: el POST /checkout trae payment_method; fijarlo ANTES de que arme el pedido desde el carrito. */
		add_filter('rest_request_before_callbacks', [__CLASS__, 'capture_checkout_method'], 10, 3);

		/* Guardar método y ajuste en el pedido (checkout clásico y Store API). */
		add_action('woocommerce_checkout_create_order', [__CLASS__, 'stamp_order'], 20, 1);
		add_action('woocommerce_store_api_checkout_update_order_meta', [__CLASS__, 'stamp_order'], 20, 1);
		add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'stamp_and_save'], 20, 1);
		add_action('woocommerce_checkout_order_created', [__CLASS__, 'stamp_and_save'], 20, 1);

		/* Store API: POST /cart/extensions {namespace: "mjpm", data: {payment_method}} */
		if (did_action('woocommerce_blocks_loaded')) {
			self::register_update_callback();
		} else {
			add_action('woocommerce_blocks_loaded', [__CLASS__, 'register_update_callback']);
		}
	}

	public static function register_update_callback() {
		if (!function_exists('woocommerce_store_api_register_update_callback')) {
			return;
		}
		woocommerce_store_api_register_update_callback([
			'namespace' => 'mjpm',
			'callback'  => [__CLASS__, 'update_callback'],
		]);
	}

	public static function update_callback($data) {
		$id = is_array($data) && isset($data['payment_method']) ? (string) $data['payment_method'] : '';
		self::set_method($id);
	}

	/** Fija el método elegido en la sesión ('' = ninguno). false si no existe o no está activo. */
	public static function set_method($id) {
		if (!function_exists('WC') || !WC()->session) {
			return false;
		}
		$id = sanitize_key($id);
		if ('' !== $id && !in_array($id, MJPM_Methods::enabled_ids(), true)) {
			return false;
		}
		WC()->session->set('chosen_payment_method', $id);
		WC()->session->set(self::SESSION_KEY, $id);
		return true;
	}

	/** Id del método vigente: sesión → por defecto del admin → precio base → ''. */
	public static function effective_method_id() {
		$enabled = MJPM_Methods::enabled_ids();
		$chosen  = '';
		if (function_exists('WC') && WC()->session) {
			$chosen = sanitize_key((string) WC()->session->get('chosen_payment_method'));
		}
		if ('' !== $chosen && in_array($chosen, $enabled, true)) {
			return $chosen;
		}
		foreach ([(string) MJPM_Settings::get('display_method'), (string) MJPM_Settings::get('base_method')] as $candidate) {
			if ('' !== $candidate && in_array($candidate, $enabled, true)) {
				return $candidate;
			}
		}
		return '';
	}

	/** Línea de ajuste del carrito. */
	public static function add_fee($cart) {
		if (!$cart instanceof WC_Cart) {
			return;
		}
		$id = self::effective_method_id();
		if ('' === $id) {
			return;
		}
		$calc = MJPM_Methods::calculate_for_cart($cart, $id);
		if (abs((float) $calc['amount']) < 0.005 || '' === $calc['label']) {
			return;
		}
		$cart->add_fee($calc['label'], (float) $calc['amount'], false);
	}

	/** POST /wc/store/v1/checkout: el método viene en el body. */
	public static function capture_checkout_method($response, $handler, $request) {
		if ($request instanceof WP_REST_Request && 'POST' === $request->get_method() && preg_match('#/wc/store(/v\d+)?/checkout/?$#', (string) $request->get_route())) {
			$id = (string) $request->get_param('payment_method');
			if ('' !== $id) {
				self::set_method($id);
			}
		}
		return $response;
	}

	/** Método, ajuste y moneda en el pedido (el llamador guarda). */
	public static function stamp_order($order) {
		if (!$order instanceof WC_Order) {
			return;
		}
		$id = sanitize_key((string) $order->get_payment_method());
		if ('' === $id) {
			$id = self::effective_method_id();
		}
		if ('' === $id) {
			return;
		}
		$cfg  = MJPM_Methods::config($id);
		$calc = function_exists('WC') && WC()->cart ? MJPM_Methods::calculate_for_cart(WC()->cart, $id) : null;

		$order->update_meta_data('_mjpm_method', $id);
		$order->update_meta_data('_mjpm_kind', (string) $cfg['kind']);
		$order->update_meta_data('_mjpm_mode', (string) $cfg['mode']);
		$order->update_meta_data('_mjpm_value', (string) (float) $cfg['value']);
		$order->update_meta_data('_mjpm_label', $calc ? (string) $calc['label'] : MJPM_Methods::label($cfg));
		$order->update_meta_data('_mjpm_amount', $calc ? (string) $calc['amount'] : '0');

		$custom = MJPM_Settings::custom_by_gateway($id);
		if ($custom && 'USD' === $custom['currency'] && class_exists('MJD_Rate') && MJD_Rate::is_set()) {
			$order->update_meta_data('_mjpm_currency', 'USD');
			$order->update_meta_data('_mjpm_rate', MJD_Rate::normalize(MJD_Rate::get()));
		} else {
			$order->delete_meta_data('_mjpm_currency');
			$order->delete_meta_data('_mjpm_rate');
		}
	}

	public static function stamp_and_save($order) {
		if (!$order instanceof WC_Order) {
			return;
		}
		self::stamp_order($order);
		$order->save();
	}
}
