<?php
/**
 * Seguimiento: el número del correo en el pedido, el link para mirarlo, el
 * aviso al cliente y el mensaje de WhatsApp listo para mandar.
 *
 * El link se arma con una plantilla que sale del panel: la del método de envío
 * y, si no tiene, la del correo. Los dos admiten la marca {codigo}. Nada de
 * esto está fijo en el código: si un correo cambia la URL de su rastreo, se
 * corrige en los ajustes.
 */

defined('ABSPATH') || exit;

class MJE_Tracking {

	public static function init() {
		add_action('mje_tracking_saved', [__CLASS__, 'maybe_notify'], 10, 3);
	}

	/** Plantilla del link de seguimiento para un pedido. */
	public static function template($order) {
		if (!$order instanceof WC_Order) {
			return '';
		}
		$slug = (string) $order->get_meta('_mje_method');
		$def  = '' !== $slug ? MJE_Methods::get($slug) : null;
		if ($def && '' !== trim((string) $def['tracking_url'])) {
			return (string) $def['tracking_url'];
		}
		$carrier_id = (string) $order->get_meta('_mje_carrier');
		$carrier    = '' !== $carrier_id ? MJE_Carriers::get($carrier_id) : null;
		if ($carrier) {
			$url = trim((string) $carrier->config('tracking_url'));
			if ('' !== $url) {
				return $url;
			}
		}
		return '';
	}

	/** Link para mirar el envío ('' si no hay código o no hay plantilla). */
	public static function url($order) {
		if (is_numeric($order)) {
			$order = wc_get_order($order);
		}
		if (!$order instanceof WC_Order) {
			return '';
		}
		$code = trim((string) $order->get_meta('_mje_tracking'));
		if ('' === $code) {
			return '';
		}
		$template = self::template($order);
		if ('' === $template) {
			return '';
		}
		return esc_url_raw(strtr($template, ['{codigo}' => rawurlencode($code)]));
	}

	/**
	 * Guarda el número de seguimiento.
	 *
	 * Solo hace algo si el número cambió: guardar el pedido de nuevo no vuelve a
	 * mandarle el mismo aviso al cliente.
	 */
	public static function save($order, $code, $notify = true) {
		if (is_numeric($order)) {
			$order = wc_get_order($order);
		}
		if (!$order instanceof WC_Order) {
			return false;
		}
		$code     = mb_substr(trim(sanitize_text_field((string) $code)), 0, 120);
		$previous = trim((string) $order->get_meta('_mje_tracking'));
		if ($code === $previous) {
			return false;
		}

		if ('' === $code) {
			$order->delete_meta_data('_mje_tracking');
			$order->delete_meta_data('_mje_shipped_at');
			$order->save();
			return true;
		}

		$order->update_meta_data('_mje_tracking', $code);
		$order->update_meta_data('_mje_shipped_at', current_time('mysql'));
		$order->save();

		$link = self::url($order);
		$order->add_order_note(sprintf('Seguimiento del envío: %s%s', $code, '' !== $link ? ' (' . $link . ')' : ''));

		do_action('mje_tracking_saved', $order, $code, (bool) $notify);
		return true;
	}

	/* ------------------------------- Avisos ------------------------------ */

	public static function maybe_notify($order, $code, $notify) {
		if (!$notify || !MJE_Settings::get('track_mail') || !$order instanceof WC_Order) {
			return;
		}
		$to = trim((string) $order->get_billing_email());
		if ('' === $to || !is_email($to)) {
			return;
		}
		$subject = self::render((string) MJE_Settings::get('track_subject'), $order, $code);
		$body    = self::render((string) MJE_Settings::get('track_body'), $order, $code);

		$mailer  = function_exists('WC') ? WC()->mailer() : null;
		$content = $mailer ? $mailer->wrap_message($subject, wpautop(wptexturize($body))) : wpautop($body);
		$headers = ['Content-Type: text/html; charset=UTF-8'];

		if ($mailer && method_exists($mailer, 'send')) {
			$mailer->send($to, $subject, $content, $headers);
			return;
		}
		wp_mail($to, $subject, $content, $headers);
	}

	/** Mensaje de WhatsApp listo para mandar desde el pedido. */
	public static function whatsapp_link($order) {
		/* Se ofrece siempre que haya teléfono: no depende de que el aviso por email esté encendido. */
		if (!$order instanceof WC_Order) {
			return '';
		}
		$phone = preg_replace('/\D+/', '', (string) $order->get_billing_phone());
		if ('' === $phone) {
			return '';
		}
		$text = self::render((string) MJE_Settings::get('track_whatsapp'), $order, (string) $order->get_meta('_mje_tracking'));
		return 'https://wa.me/' . $phone . '?text=' . rawurlencode($text);
	}

	/** Reemplaza las marcas de los textos configurables. */
	public static function render($text, $order, $code = '') {
		if (!$order instanceof WC_Order) {
			return (string) $text;
		}
		$name = trim((string) $order->get_billing_first_name());
		if ('' === $name) {
			$name = trim($order->get_formatted_billing_full_name());
		}
		return trim(strtr((string) $text, [
			'{cliente}' => $name,
			'{numero}'  => '#' . $order->get_order_number(),
			'{codigo}'  => (string) $code,
			'{link}'    => self::url($order),
			'{metodo}'  => (string) $order->get_meta('_mje_title'),
			'{tienda}'  => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
		]));
	}

	/**
	 * Estado del envío según el correo, si su API lo informa.
	 *
	 * Se guarda un rato: la pantalla del cliente no puede depender de que el
	 * correo conteste rápido.
	 */
	public static function status($order) {
		if (!$order instanceof WC_Order) {
			return [];
		}
		$code    = trim((string) $order->get_meta('_mje_tracking'));
		$carrier = (string) $order->get_meta('_mje_carrier');
		if ('' === $code || '' === $carrier) {
			return [];
		}
		$key = 'mje_track_' . md5($carrier . '|' . $code);
		$hit = get_transient($key);
		if (is_array($hit)) {
			return $hit;
		}
		$status = MJE_Carriers::track($carrier, $code);
		$status = is_array($status) ? $status : [];
		set_transient($key, $status, HOUR_IN_SECONDS);
		return $status;
	}
}
