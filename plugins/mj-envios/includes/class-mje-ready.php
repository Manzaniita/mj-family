<?php
/**
 * "Listo para retirar": un estado de pedido propio para las compras que se
 * retiran en el local o en un punto a coordinar.
 *
 * Apagado por defecto (Envíos → Ajustes → Seguimiento y avisos). Prendido:
 *   - aparece en la lista de estados de WooCommerce, en el pedido y en las
 *     acciones en lote de la lista de pedidos;
 *   - cuenta como pedido pagado (reportes, descargas);
 *   - al pasar un pedido a ese estado, el cliente recibe un mail (plantilla
 *     configurable) con las instrucciones del método de envío;
 *   - en Mi cuenta el cliente lo ve con el nombre configurado.
 * El nombre del estado, el mail y el mensaje de WhatsApp salen del panel.
 */

defined('ABSPATH') || exit;

class MJE_Ready {

	/** Estado sin el prefijo wc- (máximo 20 caracteres con el prefijo). */
	const STATUS = 'mje-listo';

	public static function init() {
		if (!self::enabled()) {
			return;
		}
		add_action('init', [__CLASS__, 'register'], 9);
		add_filter('wc_order_statuses', [__CLASS__, 'statuses']);
		add_filter('woocommerce_order_is_paid_statuses', [__CLASS__, 'paid']);
		add_filter('woocommerce_reports_order_statuses', [__CLASS__, 'paid']);
		add_filter('woocommerce_order_is_download_permitted', [__CLASS__, 'downloads'], 10, 2);
		add_filter('bulk_actions-edit-shop_order', [__CLASS__, 'bulk']);
		add_filter('bulk_actions-woocommerce_page_wc-orders', [__CLASS__, 'bulk']);
		add_action('woocommerce_order_status_' . self::STATUS, [__CLASS__, 'notify'], 10, 2);
	}

	public static function enabled() {
		return (bool) MJE_Settings::get('ready_enabled');
	}

	public static function label() {
		return (string) MJE_Settings::get('ready_label');
	}

	public static function register() {
		$label = self::label();
		register_post_status('wc-' . self::STATUS, [
			'label'                     => $label,
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: cantidad de pedidos. Sin traducciones: castellano fijo. */
			'label_count'               => _n_noop($label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>'), // phpcs:ignore WordPress.WP.I18n -- rótulo configurable, no traducible.
		]);
	}

	/** Lo suma justo después de "Procesando". */
	public static function statuses($statuses) {
		$out = [];
		foreach ((array) $statuses as $key => $label) {
			$out[$key] = $label;
			if ('wc-processing' === $key) {
				$out['wc-' . self::STATUS] = self::label();
			}
		}
		if (!isset($out['wc-' . self::STATUS])) {
			$out['wc-' . self::STATUS] = self::label();
		}
		return $out;
	}

	public static function paid($statuses) {
		$statuses   = (array) $statuses;
		$statuses[] = self::STATUS;
		return array_values(array_unique($statuses));
	}

	public static function downloads($permitted, $order) {
		return ($order instanceof WC_Order && $order->has_status(self::STATUS)) ? true : $permitted;
	}

	public static function bulk($actions) {
		$actions['mark_' . self::STATUS] = 'Cambiar estado a: ' . self::label();
		return $actions;
	}

	/** Mail al cliente al pasar a "Listo para retirar". */
	public static function notify($order_id, $order = null) {
		if (!MJE_Settings::get('ready_mail')) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			return;
		}
		$to = trim((string) $order->get_billing_email());
		if (!is_email($to)) {
			return;
		}
		$subject = self::render((string) MJE_Settings::get('ready_subject'), $order);
		$body    = self::render((string) MJE_Settings::get('ready_body'), $order);
		$mailer  = function_exists('WC') ? WC()->mailer() : null;
		$content = $mailer ? $mailer->wrap_message($subject, wpautop(wptexturize($body))) : wpautop($body);
		$headers = ['Content-Type: text/html; charset=UTF-8'];
		if ($mailer && method_exists($mailer, 'send')) {
			$mailer->send($to, $subject, $content, $headers);
		} else {
			wp_mail($to, $subject, $content, $headers);
		}
		$order->add_order_note('Se avisó al cliente que el pedido está: ' . self::label() . '.');
	}

	/** Mensaje de WhatsApp listo para mandar ('' sin teléfono). */
	public static function whatsapp_link($order) {
		if (!$order instanceof WC_Order) {
			return '';
		}
		$phone = preg_replace('/\D+/', '', (string) $order->get_billing_phone());
		if ('' === $phone) {
			return '';
		}
		return 'https://wa.me/' . $phone . '?text=' . rawurlencode(self::render((string) MJE_Settings::get('ready_whatsapp'), $order));
	}

	/** Marcas del seguimiento más {instrucciones} (las del método de envío del pedido). */
	public static function render($text, $order) {
		$slug = (string) $order->get_meta('_mje_method');
		$def  = '' !== $slug ? MJE_Methods::get($slug) : null;
		$text = strtr((string) $text, ['{instrucciones}' => $def ? trim((string) ($def['instructions'] ?? '')) : '']);
		return MJE_Tracking::render($text, $order, '');
	}
}
