<?php
/**
 * Cómo pagar: las instrucciones y los datos para pagar (alias, CBU, titular…)
 * de cada medio de pago, y todos los lugares donde los ve el cliente.
 *
 *   1. Página de gracias y "ver pedido" del checkout clásico.
 *   2. Email al cliente, mientras el pedido espera el pago.
 *   3. Tienda React: la confirmación del checkout los pide por REST con la clave
 *      del pedido, y Mi cuenta los recibe en el detalle (MJPM_Orders).
 *   4. Página del link para subir el comprobante (MJPM_Receipts_Front).
 *   5. Checkout, al elegir el medio, solo si ese medio lo tiene marcado: va en
 *      la config pública (MJPM_Methods::public_payload).
 *
 * Los datos NO van en la config pública salvo ese caso: quien no compró no
 * tiene por qué ver la cuenta de la tienda en el código de cada página.
 *
 *   GET /wp-json/mjpm/v1/como-pagar?pedido=151&clave=wc_order_xxx
 */

defined('ABSPATH') || exit;

class MJPM_Payment_Info {

	public static function init() {
		add_action('rest_api_init', [__CLASS__, 'routes']);

		/* Checkout clásico: después de las instrucciones del medio y antes del widget del comprobante. */
		add_action('woocommerce_thankyou', [__CLASS__, 'thankyou'], 5, 1);
		add_action('woocommerce_view_order', [__CLASS__, 'thankyou'], 5, 1);

		/* Email: después de las instrucciones que imprime cada gateway (prioridad 10). */
		add_action('woocommerce_email_before_order_table', [__CLASS__, 'email'], 11, 3);
	}

	/* ------------------------------- Datos -------------------------------- */

	/**
	 * Todo lo que el cliente necesita para pagar con un medio.
	 *
	 * @param string $gateway_id Id del gateway.
	 * @return array { method, title, instructions, detailsTitle, details, checkout }
	 */
	public static function for_method($gateway_id) {
		$gateway_id = (string) $gateway_id;
		$gateway    = MJPM_Methods::gateway($gateway_id);
		$cfg        = MJPM_Settings::method($gateway_id);
		$custom     = MJPM_Settings::custom_by_gateway($gateway_id);

		$instructions = '';
		if ($custom) {
			$instructions = (string) $custom['instructions'];
		} elseif ($gateway && isset($gateway->instructions) && is_string($gateway->instructions)) {
			/* Transferencia, efectivo y cheque de WooCommerce guardan las suyas acá. */
			$instructions = $gateway->instructions;
		}

		$title = trim((string) $cfg['details_title']);
		$info  = [
			'method'       => $gateway_id,
			'title'        => $gateway ? wp_strip_all_tags((string) $gateway->get_title()) : '',
			'instructions' => trim(wp_strip_all_tags($instructions)),
			'detailsTitle' => '' !== $title ? $title : (string) MJPM_Settings::get('details_title'),
			'details'      => array_map([__CLASS__, 'public_row'], (array) $cfg['details']),
			'checkout'     => (bool) $cfg['details_checkout'],
			'texts'        => self::texts(),
		];

		/**
		 * Lo que ve el cliente para pagar con un medio.
		 *
		 * @param array  $info       Instrucciones y datos.
		 * @param string $gateway_id Id del gateway.
		 */
		return (array) apply_filters('mjpm_payment_info', $info, $gateway_id);
	}

	/** Cómo se llaman el bloque y el botón de copiar en la tienda (MJ Medios de Pago → Ajustes generales). */
	public static function texts() {
		return [
			'howTo'  => (string) MJPM_Settings::get('howto_title'),
			'copy'   => (string) MJPM_Settings::get('copy_label'),
			'copied' => (string) MJPM_Settings::get('copied_label'),
		];
	}

	private static function public_row($row) {
		return [
			'label' => (string) $row['label'],
			'value' => (string) $row['value'],
			'copy'  => !empty($row['copy']),
		];
	}

	/**
	 * ¿Todavía hay que mostrarle cómo pagar este pedido?
	 *
	 * Mientras el pedido esté en un estado de espera y el comprobante no se haya
	 * aprobado: con el pago confirmado, repetirle la cuenta solo confunde.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function shows_for_order($order) {
		if (!$order instanceof WC_Order) {
			return false;
		}
		/**
		 * Estados de pedido en los que el cliente ve cómo pagar (los de Ajustes generales → Cómo pagar).
		 *
		 * @param string[] $statuses Sin el prefijo wc-.
		 * @param WC_Order $order    Pedido.
		 */
		$statuses = (array) apply_filters('mjpm_payment_info_statuses', (array) MJPM_Settings::get('howto_statuses'), $order);
		if (!in_array($order->get_status(), $statuses, true)) {
			return false;
		}
		if (class_exists('MJPM_Receipts') && MJPM_Receipts::enabled()
			&& MJPM_Receipts::STATUS_OK === MJPM_Receipts::state('order', $order->get_id())) {
			return false;
		}
		return true;
	}

	/**
	 * Cómo pagar un pedido, o null si ya no corresponde mostrarlo.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array|null
	 */
	public static function for_order($order) {
		if (!self::shows_for_order($order)) {
			return null;
		}
		$info = self::for_method((string) $order->get_payment_method());
		if ('' === $info['title']) {
			$info['title'] = wp_strip_all_tags((string) $order->get_payment_method_title());
		}
		return $info;
	}

	/* -------------------------------- REST -------------------------------- */

	public static function routes() {
		register_rest_route(MJPM_REST::NS, '/como-pagar', [
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'rest'],
			'permission_callback' => '__return_true',
			'args'                => [
				'pedido' => ['required' => true],
				'clave'  => ['required' => false],
			],
		]);
	}

	/**
	 * Cómo pagar un pedido recién hecho.
	 *
	 * Lo pide la confirmación de la tienda React. Alcanza con la clave del pedido
	 * (la devuelve la Store API al cerrar el checkout, y es la misma prueba que
	 * usa la página de gracias de WooCommerce) o con ser su dueño.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest(WP_REST_Request $request) {
		$order = wc_get_order((int) $request->get_param('pedido'));
		$key   = (string) $request->get_param('clave');
		$owner = $order instanceof WC_Order && is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
		$keyed = $order instanceof WC_Order && '' !== $key && hash_equals((string) $order->get_order_key(), $key);
		if (!$owner && !$keyed) {
			return new WP_Error('mjpm_not_found', 'No encontramos ese pedido.', ['status' => 404]);
		}
		$info = self::for_order($order);
		if (null === $info) {
			/* Ya no corresponde (pago confirmado): la misma forma, vacía. */
			$info = array_merge(self::for_method((string) $order->get_payment_method()), ['instructions' => '', 'details' => []]);
		}
		return rest_ensure_response($info);
	}

	/* --------------------------- Checkout clásico -------------------------- */

	public static function thankyou($order_id) {
		$order = wc_get_order((int) $order_id);
		if (!$order instanceof WC_Order) {
			return;
		}
		if (doing_action('woocommerce_view_order') && (int) $order->get_customer_id() !== get_current_user_id()) {
			return;
		}
		$info = self::for_order($order);
		if ($info) {
			echo self::html($info); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Los datos como lista, con las mismas clases que usa la transferencia de
	 * WooCommerce: cualquier tema que ya la estiliza, estiliza esto.
	 *
	 * @param array $info Resultado de for_method() o for_order().
	 * @return string
	 */
	public static function html(array $info) {
		if (empty($info['details'])) {
			return '';
		}
		$html  = '<section class="mjpm-pay-details woocommerce-bacs-bank-details">';
		$html .= '<h2 class="mjpm-pay-details__title wc-bacs-bank-details-heading">' . esc_html($info['detailsTitle']) . '</h2>';
		$html .= '<ul class="mjpm-pay-details__list wc-bacs-bank-details order_details bacs_details">';
		foreach ($info['details'] as $row) {
			$html .= '<li>';
			if ('' !== $row['label']) {
				$html .= esc_html($row['label']) . ': ';
			}
			$html .= '<strong>' . esc_html($row['value']) . '</strong></li>';
		}
		$html .= '</ul></section>';
		return $html;
	}

	/* -------------------------------- Email -------------------------------- */

	public static function email($order, $sent_to_admin, $plain_text = false) {
		if ($sent_to_admin || !$order instanceof WC_Order) {
			return;
		}
		$info = self::for_order($order);
		if (!$info || empty($info['details'])) {
			return;
		}
		if ($plain_text) {
			echo esc_html(wp_strip_all_tags($info['detailsTitle'])) . "\n";
			foreach ($info['details'] as $row) {
				echo esc_html(('' !== $row['label'] ? $row['label'] . ': ' : '') . $row['value']) . "\n";
			}
			echo "\n";
			return;
		}
		echo '<h2>' . esc_html($info['detailsTitle']) . '</h2>';
		echo '<table cellspacing="0" cellpadding="6" border="0" style="width:100%;margin-bottom:24px;">';
		foreach ($info['details'] as $row) {
			echo '<tr><td style="padding:4px 12px 4px 0;white-space:nowrap;vertical-align:top;">' . esc_html($row['label']) . '</td>';
			echo '<td style="padding:4px 0;vertical-align:top;"><strong>' . esc_html($row['value']) . '</strong></td></tr>';
		}
		echo '</table>';
	}
}
