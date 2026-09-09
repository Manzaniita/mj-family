<?php
/**
 * Pedidos: lo guardado por MJPM_Cart se muestra en el admin del pedido y se
 * expone a la SPA del tema (Mi cuenta → detalle del pedido).
 */

defined('ABSPATH') || exit;

class MJPM_Orders {

	public static function init() {
		add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'admin_order_details']);
		add_filter('mj_rest_order_detail', [__CLASS__, 'rest_order_detail'], 20, 2);
	}

	/** Total del pedido en dólares (solo métodos propios cobrados en USD), o null. */
	public static function usd_total($order) {
		if (!$order instanceof WC_Order || 'USD' !== (string) $order->get_meta('_mjpm_currency')) {
			return null;
		}
		$rate = (float) $order->get_meta('_mjpm_rate');
		if ($rate <= 0) {
			return null;
		}
		return round((float) $order->get_total() / $rate, 2);
	}

	public static function format_usd($amount) {
		if (class_exists('MJD_Rate')) {
			return MJD_Rate::format_usd($amount);
		}
		return 'USD ' . number_format((float) $amount, 2, wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
	}

	/** "Total a pagar en dólares: USD 118,31 (al dólar $ 1.450)" o ''. */
	public static function usd_total_text($order) {
		$usd = self::usd_total($order);
		if (null === $usd) {
			return '';
		}
		$rate = (float) $order->get_meta('_mjpm_rate');
		$text = 'Total a pagar en dólares: ' . self::format_usd($usd);
		if (class_exists('MJD_Rate')) {
			$text .= ' (al dólar ' . MJD_Rate::format_rate($rate) . ')';
		}
		return $text;
	}

	public static function admin_order_details($order) {
		if (!$order instanceof WC_Order) {
			return;
		}
		$method = (string) $order->get_meta('_mjpm_method');
		if ('' === $method) {
			return;
		}
		$amount = (float) $order->get_meta('_mjpm_amount');
		$label  = (string) $order->get_meta('_mjpm_label');
		echo '<div class="mjpm-order-box">';
		if (abs($amount) >= 0.005 && '' !== $label) {
			echo '<p class="form-field form-field-wide"><strong>' . esc_html($label) . ':</strong> ' . esc_html(($amount < 0 ? '− ' : '+ ') . MJPM_Methods::money(abs($amount))) . '</p>';
		} else {
			echo '<p class="form-field form-field-wide"><strong>Ajuste por medio de pago:</strong> ninguno (precio base)</p>';
		}
		$usd = self::usd_total_text($order);
		if ('' !== $usd) {
			echo '<p class="form-field form-field-wide"><strong>' . esc_html($usd) . '</strong></p>';
		}
		echo '</div>';
	}

	/** Detalle del pedido para la SPA: fees, método, ajuste, total en USD e instrucciones. */
	public static function rest_order_detail($data, $order) {
		if (!is_array($data) || !$order instanceof WC_Order) {
			return $data;
		}
		$fees = [];
		foreach ($order->get_fees() as $fee) {
			$fees[] = [
				'name'   => wp_strip_all_tags((string) $fee->get_name()),
				'amount' => (float) $fee->get_total() + (float) $fee->get_total_tax(),
			];
		}
		$data['fees']               = $fees;
		$data['paymentMethodTitle'] = (string) $order->get_payment_method_title();

		$amount = (float) $order->get_meta('_mjpm_amount');
		$label  = (string) $order->get_meta('_mjpm_label');
		$data['paymentAdjustment'] = abs($amount) >= 0.005 && '' !== $label
			? ['label' => $label, 'amount' => $amount, 'kind' => (string) $order->get_meta('_mjpm_kind')]
			: null;

		$usd                       = self::usd_total($order);
		$data['usdTotal']          = $usd;
		$data['usdTotalFormatted'] = null !== $usd ? self::format_usd($usd) : '';

		$custom                      = MJPM_Settings::custom_by_gateway((string) $order->get_payment_method());
		$data['paymentInstructions'] = $custom && in_array($order->get_status(), ['on-hold', 'pending', 'processing'], true) ? (string) $custom['instructions'] : '';

		return $data;
	}
}
