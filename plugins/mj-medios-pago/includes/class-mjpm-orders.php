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

		/* Marcar el pedido como "falta el comprobante" en cuanto nace. */
		add_action('woocommerce_checkout_order_created', [__CLASS__, 'on_order_created'], 30, 1);
		add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'on_order_created'], 30, 1);
		add_action('woocommerce_new_order', [__CLASS__, 'on_new_order'], 30, 2);
	}

	/**
	 * Arranca el circuito del comprobante: si el medio de pago lo pide, el pedido
	 * queda "a revisar" y se guarda cuándo se le pidió (de ahí sale el plazo).
	 *
	 * @param WC_Order $order Pedido.
	 * @return void
	 */
	public static function on_order_created($order) {
		if (!$order instanceof WC_Order || !MJPM_Receipts::enabled()) {
			return;
		}
		MJPM_Receipts::mark_asked($order);
		$order->save();
	}

	public static function on_new_order($order_id, $order = null) {
		self::on_order_created($order instanceof WC_Order ? $order : wc_get_order((int) $order_id));
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

		$data['receipt'] = self::rest_receipt($order);

		return $data;
	}

	/**
	 * Bloque de comprobante para la SPA: con esto el tema sabe si tiene que
	 * pintar el widget y qué mostrarle al cliente mientras espera.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array|null null cuando ese pedido no pide comprobante.
	 */
	private static function rest_receipt($order) {
		if (!MJPM_Receipts::enabled() || !MJPM_Settings::receipts('show_account')) {
			return null;
		}
		$req = MJPM_Receipts::requirement('order', $order->get_id());
		if (!$req['asks']) {
			return null;
		}
		$state = MJPM_Receipts::state('order', $order->get_id());
		$files = MJPM_Receipts::for_object('order', $order->get_id());

		return [
			'context'   => 'order',
			'objectId'  => $order->get_id(),
			'noun'      => MJPM_Receipts::noun(),
			'nounTitle' => MJPM_Receipts::noun(false, true),
			'required'  => (bool) $req['required'],
			'text'      => (string) $req['text'],
			'state'     => $state,
			'stateLabel' => '' !== $state ? MJPM_Receipts::status_label($state) : '',
			'count'     => count($files),
			'maxFiles'  => (int) $req['files'],
			'deadline'  => MJPM_Receipts::deadline('order', $order->get_id()),
			'reason'    => (string) $order->get_meta(MJPM_Receipts::META_REASON),
		];
	}
}
