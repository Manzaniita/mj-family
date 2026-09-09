<?php
/**
 * Pedidos: cada pedido con productos en USD guarda el TC con el que se cobró
 * (_mjd_rate) y cada línea el USD unitario (_mjd_usd_unit). Se muestran en el
 * admin del pedido y se exponen a la SPA del tema (Mis pedidos).
 *
 * El precio del pedido queda congelado: si el TC cambia después, el pedido
 * no se recalcula (WooCommerce guarda los importes en pesos al crearlo).
 */

defined('ABSPATH') || exit;

class MJD_Orders {

	const META_RATE = '_mjd_rate';
	const META_UNIT = '_mjd_usd_unit';

	/** @var array order_id => bool Si el pedido tiene líneas USD (para la tabla del admin). */
	private static $show = [];

	public static function init() {
		/* Checkout clásico y Store API (el tema usa la Store API). */
		add_action('woocommerce_checkout_create_order', [__CLASS__, 'stamp_order'], 10, 1);
		add_action('woocommerce_store_api_checkout_update_order_meta', [__CLASS__, 'stamp_order'], 10, 1);
		add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'stamp_line'], 10, 4);

		/* Admin del pedido. */
		add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'admin_order_rate']);
		add_action('woocommerce_admin_order_item_headers', [__CLASS__, 'admin_item_header']);
		add_action('woocommerce_admin_order_item_values', [__CLASS__, 'admin_item_value'], 10, 3);
		add_filter('woocommerce_hidden_order_itemmeta', [__CLASS__, 'hidden_item_meta']);

		/* Tema MJ Appearance ≥ 3.2: detalle del pedido en Mi cuenta. */
		add_filter('mj_rest_order_detail', [__CLASS__, 'rest_order_detail'], 10, 2);
	}

	/* ------------------------------ Checkout ----------------------------- */

	/** ¿El carrito actual tiene algún producto con precio USD? */
	private static function cart_has_usd() {
		if (!function_exists('WC') || !WC()->cart) {
			return false;
		}
		foreach (WC()->cart->get_cart() as $item) {
			if (!empty($item['data']) && MJD_Product::has_usd($item['data'])) {
				return true;
			}
		}
		return false;
	}

	/** Guarda el TC en el pedido (el llamador hace save()). Se sobreescribe en cada actualización desde el carrito. */
	public static function stamp_order($order) {
		if (!$order instanceof WC_Order || !MJD_Rate::is_set() || !self::cart_has_usd()) {
			return;
		}
		$order->update_meta_data(self::META_RATE, MJD_Rate::normalize(MJD_Rate::get()));
	}

	/** USD unitario efectivo (oferta si aplica) y TC en cada línea con USD. */
	public static function stamp_line($item, $cart_item_key, $values, $order) {
		$product = isset($values['data']) ? $values['data'] : null;
		if (!$product instanceof WC_Product || !MJD_Product::has_usd($product) || !MJD_Rate::is_set()) {
			return;
		}
		list($usd, $usd_sale) = MJD_Product::usd_effective($product);
		if (null === $usd) {
			return;
		}
		$item->add_meta_data(self::META_UNIT, MJD_Product::decimal(null !== $usd_sale ? $usd_sale : $usd), true);
		$item->add_meta_data(self::META_RATE, MJD_Rate::normalize(MJD_Rate::get()), true);
	}

	/* ------------------------------- Admin ------------------------------- */

	public static function admin_order_rate($order) {
		if (!$order instanceof WC_Order) {
			return;
		}
		$rate = (string) $order->get_meta(self::META_RATE);
		if ('' === $rate) {
			return;
		}
		echo '<p class="form-field form-field-wide mjd-order-rate"><strong>Tipo de cambio del pedido:</strong> ' . esc_html(MJD_Rate::format_rate($rate)) . ' <span class="description">(USD × TC, congelado al momento de la compra)</span></p>';
	}

	private static function order_has_usd($order) {
		foreach ($order->get_items() as $item) {
			if ('' !== (string) $item->get_meta(self::META_UNIT)) {
				return true;
			}
		}
		return false;
	}

	public static function admin_item_header($order) {
		if (!$order instanceof WC_Order) {
			return;
		}
		self::$show[$order->get_id()] = self::order_has_usd($order);
		if (self::$show[$order->get_id()]) {
			echo '<th class="mjd-item-usd">USD</th>';
		}
	}

	public static function admin_item_value($product, $item, $item_id) {
		if (!$item instanceof WC_Order_Item) {
			return;
		}
		$order_id = (int) $item->get_order_id();
		if (empty(self::$show[$order_id])) {
			return;
		}
		$usd = $item instanceof WC_Order_Item_Product ? (string) $item->get_meta(self::META_UNIT) : '';
		echo '<td class="mjd-item-usd">' . ('' !== $usd ? esc_html(MJD_Rate::format_usd($usd)) . ' <span class="description">c/u</span>' : '') . '</td>';
	}

	public static function hidden_item_meta($keys) {
		$keys[] = self::META_UNIT;
		$keys[] = self::META_RATE;
		return $keys;
	}

	/* ------------------------------ Tema (SPA) --------------------------- */

	/** Agrega exchangeRate al detalle del pedido y usdUnit a cada línea. */
	public static function rest_order_detail($data, $order) {
		if (!is_array($data) || !$order instanceof WC_Order) {
			return $data;
		}
		$rate                          = (string) $order->get_meta(self::META_RATE);
		$data['exchangeRate']          = '' !== $rate ? (float) $rate : null;
		$data['exchangeRateFormatted'] = '' !== $rate ? MJD_Rate::format_rate($rate) : '';

		if (!empty($data['items']) && is_array($data['items'])) {
			$units = [];
			foreach ($order->get_items() as $item) {
				$units[] = (string) $item->get_meta(self::META_UNIT);
			}
			foreach ($data['items'] as $i => $line) {
				$usd                      = isset($units[$i]) ? $units[$i] : '';
				$data['items'][$i]['usdUnit'] = '' !== $usd ? (float) $usd : null;
			}
		}
		return $data;
	}
}
