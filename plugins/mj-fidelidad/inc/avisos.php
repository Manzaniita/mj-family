<?php
/**
 * Mails de tarjeta completa: a la clienta (con su premio) y a la dueña.
 * Plantillas y comodines en Fidelidad → Ajustes → Mails.
 *
 * @package MJ_Fidelidad
 */

defined('ABSPATH') || exit;

/** Comodines de un premio. */
function mjfid_placeholders(array $reward, array $holder) {
	$contact = mjfid_person_contact($holder);
	return [
		'nombre'          => $contact['first'],
		'nombre_completo' => $contact['name'],
		'premio'          => (string) $reward['reward_text'],
		'total'           => (string) mjfid_per_card(),
		'tarjeta'         => (string) (int) $reward['card_number'],
		'negocio'         => mjfid_business_name(),
	];
}

/** Mail de texto plano con el nombre del negocio como remitente. */
function mjfid_mail($to, $subject, $body, $reply_to = '') {
	if (!is_email($to)) {
		return false;
	}
	$headers = ['Content-Type: text/plain; charset=UTF-8'];
	if (is_email($reply_to)) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}
	$from   = preg_replace('/[\r\n"<>]+/', ' ', mjfid_business_name());
	$filter = function () use ($from) {
		return $from;
	};
	add_filter('wp_mail_from_name', $filter, 99);
	$sent = wp_mail($to, trim(preg_replace('/[\r\n]+/', ' ', (string) $subject)), (string) $body, $headers);
	remove_filter('wp_mail_from_name', $filter, 99);
	return (bool) $sent;
}

add_action('mjfid_card_completed', function ($reward, $holder) {
	if (!is_array($reward)) {
		return;
	}
	$map     = mjfid_placeholders($reward, $holder);
	$contact = mjfid_person_contact($holder);
	if ((int) mjfid_opt('mail_client') && is_email($contact['email'])) {
		mjfid_mail(
			$contact['email'],
			mjfid_fill((string) mjfid_opt('tpl_client_subject'), $map),
			mjfid_fill((string) mjfid_opt('tpl_client_body'), $map),
			mjfid_owner_email()
		);
	}
	if ((int) mjfid_opt('mail_owner')) {
		mjfid_mail(
			mjfid_owner_email(),
			mjfid_fill((string) mjfid_opt('tpl_owner_subject'), $map),
			mjfid_fill((string) mjfid_opt('tpl_owner_body'), $map),
			$contact['email']
		);
	}
}, 10, 2);
