<?php
/**
 * Avisos de comprobantes: mail al cliente cuando aprobás o rechazás, aviso a la
 * tienda cuando entra uno nuevo, recordatorio si no lo subió, el adjunto en el
 * email del pedido y el texto del botón de WhatsApp.
 *
 * Ningún texto está fijo: asunto y cuerpo salen de los ajustes y admiten
 * marcadores. Los que existen:
 *
 *   {cliente} {numero} {total} {metodo} {comprobante} {motivo} {link} {tienda}
 *
 * Cada aviso se puede apagar por separado.
 */

defined('ABSPATH') || exit;

class MJPM_Receipts_Mail {

	public static function init() {
		/* Aviso a la tienda cuando entra un comprobante nuevo. */
		add_action('mjpm_receipt_created', [__CLASS__, 'notify_shop'], 10, 2);

		/* Adjuntar los comprobantes aprobados al email del pedido. */
		add_filter('woocommerce_email_attachments', [__CLASS__, 'attach_to_email'], 20, 4);

		/* Link para subirlo en el email que recibe el cliente. */
		add_action('woocommerce_email_after_order_table', [__CLASS__, 'email_upload_link'], 20, 4);
	}

	/**
	 * En el email del cliente, el link para subir el comprobante.
	 *
	 * Es el único camino que tiene quien compró sin cuenta, así que va en el mail
	 * de confirmación y no solo en la página de gracias.
	 *
	 * @param mixed  $order          Pedido.
	 * @param bool   $sent_to_admin  Si el mail va a la tienda.
	 * @param bool   $plain_text     Versión de texto plano.
	 * @param mixed  $email          Instancia del email.
	 * @return void
	 */
	public static function email_upload_link($order, $sent_to_admin = false, $plain_text = false, $email = null) {
		unset($email);
		if ($sent_to_admin || !$order instanceof WC_Order || !MJPM_Receipts::enabled()) {
			return;
		}
		$req = MJPM_Receipts::requirement('order', $order->get_id());
		if (!$req['asks']) {
			return;
		}
		$state = MJPM_Receipts::state('order', $order->get_id());
		if (MJPM_Receipts::STATUS_OK === $state) {
			return;
		}
		$link = MJPM_Receipts::link('order', $order->get_id());
		if ('' === $link) {
			return;
		}

		$noun     = MJPM_Receipts::noun();
		$intro    = '' !== (string) $req['text'] ? (string) $req['text'] : sprintf('Para confirmar el pago necesitamos el %s.', $noun);
		$deadline = MJPM_Receipts::deadline('order', $order->get_id());
		$when     = '' !== $deadline ? sprintf(' Tenés hasta el %s.', date_i18n('j \d\e F, H:i', strtotime($deadline))) : '';

		if ($plain_text) {
			echo "\n" . esc_html(MJPM_Receipts::noun(false, true)) . "\n";
			echo esc_html($intro . $when) . "\n";
			echo esc_url($link) . "\n\n";
			return;
		}
		printf(
			'<div style="margin:24px 0;padding:16px 18px;border:1px solid #e1e1e1;border-radius:10px"><p style="margin:0 0 8px;font-weight:600">%s</p><p style="margin:0 0 12px">%s</p><p style="margin:0"><a href="%s" style="display:inline-block;padding:10px 18px;border:1px solid currentColor;border-radius:999px;text-decoration:none">%s</a></p></div>',
			esc_html(MJPM_Receipts::noun(false, true)),
			esc_html($intro . $when),
			esc_url($link),
			esc_html(sprintf('Subir el %s', $noun))
		);
	}

	/* ---------------------------- Marcadores ------------------------------ */

	/**
	 * Valores de los marcadores para un objeto.
	 *
	 * @param string $context   Contexto.
	 * @param int    $object_id Objeto.
	 * @param string $reason    Motivo del rechazo.
	 * @return array
	 */
	public static function placeholders($context, $object_id, $reason = '') {
		$object = MJPM_Receipts::object($context, $object_id);
		$object = is_array($object) ? $object : [];

		return [
			'{cliente}'     => isset($object['customer']) ? (string) $object['customer'] : '',
			'{numero}'      => isset($object['title']) ? (string) $object['title'] : '',
			'{total}'       => isset($object['total']) && (float) $object['total'] > 0 ? MJPM_Receipts::money((float) $object['total']) : '',
			'{metodo}'      => isset($object['method_label']) ? (string) $object['method_label'] : '',
			'{comprobante}' => MJPM_Receipts::noun(),
			'{motivo}'      => (string) $reason,
			'{link}'        => MJPM_Receipts::link($context, $object_id),
			'{tienda}'      => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
		];
	}

	public static function render($template, array $placeholders) {
		return trim(strtr((string) $template, $placeholders));
	}

	/* --------------------------- Al cliente ------------------------------- */

	/**
	 * Mail al cliente con la decisión.
	 *
	 * @param string $context   Contexto.
	 * @param int    $object_id Objeto.
	 * @param string $decision  aprobado | rechazado.
	 * @param string $reason    Motivo.
	 * @return bool
	 */
	public static function notify_customer($context, $object_id, $decision, $reason = '') {
		$approved = MJPM_Receipts::STATUS_OK === $decision;
		$flag     = $approved ? 'mail_ok' : 'mail_no';
		if (!MJPM_Settings::receipts($flag)) {
			return false;
		}

		$object = MJPM_Receipts::object($context, $object_id);
		$to     = $object && !empty($object['email']) ? (string) $object['email'] : '';
		if (!is_email($to)) {
			return false;
		}

		$placeholders = self::placeholders($context, $object_id, $reason);
		$subject      = self::render(MJPM_Settings::receipts($approved ? 'mail_ok_subject' : 'mail_no_subject'), $placeholders);
		$body         = self::render(MJPM_Settings::receipts($approved ? 'mail_ok_body' : 'mail_no_body'), $placeholders);

		if ('' === $subject || '' === $body) {
			return false;
		}
		return self::send($to, $subject, $body);
	}

	/** Recordatorio: todavía no subió nada. Usa el texto del rechazo sin motivo. */
	public static function remind_customer($context, $object_id) {
		$object = MJPM_Receipts::object($context, $object_id);
		$to     = $object && !empty($object['email']) ? (string) $object['email'] : '';
		if (!is_email($to)) {
			return false;
		}
		$placeholders = self::placeholders($context, $object_id, '');
		$noun         = MJPM_Receipts::noun();

		$subject = self::render(sprintf('Nos falta el %s de tu pedido {numero}', $noun), $placeholders);
		$body    = self::render(
			sprintf("Hola {cliente},\n\nTodavía no nos llegó el %s de tu pedido {numero}. Podés subirlo acá:\n\n{link}\n\n{tienda}", $noun),
			$placeholders
		);

		/**
		 * Texto del recordatorio.
		 *
		 * @param array  $mail      { subject, body }
		 * @param string $context   Contexto.
		 * @param int    $object_id Objeto.
		 */
		$mail = (array) apply_filters('mjpm_receipt_reminder_mail', ['subject' => $subject, 'body' => $body], $context, (int) $object_id);

		return self::send($to, (string) $mail['subject'], (string) $mail['body']);
	}

	/* ---------------------------- A la tienda ----------------------------- */

	/**
	 * Aviso a la tienda: entró un comprobante nuevo.
	 *
	 * @param int   $id  Id del comprobante.
	 * @param array $row Fila guardada.
	 * @return bool
	 */
	public static function notify_shop($id, $row) {
		if (!MJPM_Settings::receipts('mail_new')) {
			return false;
		}
		$to = (string) MJPM_Settings::receipts('mail_new_to');
		if (!is_email($to)) {
			$to = (string) get_option('admin_email');
		}
		if (!is_email($to)) {
			return false;
		}

		$context   = isset($row['context']) ? (string) $row['context'] : 'order';
		$object_id = isset($row['object_id']) ? (int) $row['object_id'] : 0;
		$object    = MJPM_Receipts::object($context, $object_id);
		$noun      = MJPM_Receipts::noun();

		$subject = sprintf(
			'%s nuevo a revisar%s',
			MJPM_Receipts::noun(false, true),
			$object && '' !== (string) $object['title'] ? ' — ' . $object['title'] : ''
		);

		$lines = [];
		$lines[] = sprintf('Entró un %s para revisar.', $noun);
		$lines[] = '';
		if ($object) {
			$lines[] = 'Quién: ' . $object['customer'];
			$lines[] = MJPM_Receipts::context_label($context) . ': ' . $object['title'];
			if ((float) $object['total'] > 0) {
				$lines[] = 'Total: ' . MJPM_Receipts::money((float) $object['total']);
			}
			if ('' !== (string) $object['method_label']) {
				$lines[] = 'Medio de pago: ' . $object['method_label'];
			}
		}
		if (!empty($row['amount']) && (float) $row['amount'] > 0) {
			$lines[] = 'Importe declarado: ' . MJPM_Receipts::money((float) $row['amount']);
		}
		if (!empty($row['reference'])) {
			$lines[] = 'Referencia: ' . $row['reference'];
		}
		if (!empty($row['note'])) {
			$lines[] = 'Aclaración: ' . $row['note'];
		}
		$lines[] = '';
		$lines[] = 'Revisalo acá: ' . MJPM_Receipts_Admin::page_url();

		return self::send($to, $subject, implode("\n", $lines));
	}

	/* ---------------------- Adjunto en el email del pedido ----------------- */

	/**
	 * Adjunta los comprobantes aprobados a los emails del pedido, si el medio de
	 * pago lo pide. Solo a los que van al admin de la tienda: mandarle al cliente
	 * su propio comprobante de vuelta no suma, y mandarlo a otra dirección sería
	 * filtrar un dato bancario.
	 *
	 * @param array  $attachments Adjuntos.
	 * @param string $email_id    Id del email de WooCommerce.
	 * @param mixed  $object      Pedido u otro objeto.
	 * @param mixed  $email       Instancia del email.
	 * @return array
	 */
	public static function attach_to_email($attachments, $email_id, $object = null, $email = null) {
		if (!is_array($attachments) || !$object instanceof WC_Order || !MJPM_Receipts::enabled()) {
			return $attachments;
		}
		$admin_emails = ['new_order', 'cancelled_order', 'failed_order'];
		if (!in_array((string) $email_id, $admin_emails, true)) {
			return $attachments;
		}
		$req = MJPM_Receipts::requirement('order', $object->get_id());
		if (empty($req['attach'])) {
			return $attachments;
		}
		foreach (MJPM_Receipts::for_object('order', $object->get_id()) as $row) {
			if (MJPM_Receipts::STATUS_NO === $row['status'] || !$row['available']) {
				continue;
			}
			$path = MJPM_Vault::absolute($row['file_path']);
			if ('' !== $path && file_exists($path)) {
				$attachments[] = $path;
			}
		}
		return $attachments;
	}

	/* ------------------------------ WhatsApp ------------------------------ */

	/**
	 * Link de WhatsApp con el mensaje ya escrito.
	 *
	 * @param string $context   Contexto.
	 * @param int    $object_id Objeto.
	 * @param string $decision  aprobado | rechazado | '' (pendiente).
	 * @param string $reason    Motivo.
	 * @return string '' si no hay teléfono o el botón está apagado.
	 */
	public static function whatsapp_url($context, $object_id, $decision = '', $reason = '') {
		if (!MJPM_Settings::receipts('whatsapp')) {
			return '';
		}
		$object = MJPM_Receipts::object($context, $object_id);
		$phone  = $object ? preg_replace('/\D+/', '', (string) $object['phone']) : '';
		if ('' === $phone) {
			return '';
		}
		$template = MJPM_Settings::receipts(MJPM_Receipts::STATUS_NO === $decision ? 'whatsapp_no' : 'whatsapp_ok');
		$text     = self::render($template, self::placeholders($context, $object_id, $reason));
		if ('' === $text) {
			return '';
		}
		return 'https://wa.me/' . $phone . '?text=' . rawurlencode($text);
	}

	/* ------------------------------- Envío -------------------------------- */

	private static function send($to, $subject, $body) {
		$html = wpautop(esc_html($body));

		/* Con WooCommerce usamos su plantilla, así el mail sale con la marca de la tienda. */
		if (function_exists('WC') && WC() && WC()->mailer()) {
			$mailer  = WC()->mailer();
			$wrapped = $mailer->wrap_message($subject, $html);
			return (bool) $mailer->send($to, $subject, $wrapped, ['Content-Type: text/html; charset=UTF-8']);
		}
		return (bool) wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
	}
}
