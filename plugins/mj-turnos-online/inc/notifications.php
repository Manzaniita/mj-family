<?php
/**
 * Notificaciones: placeholders, render de plantillas, mails (dueña y
 * clienta), links de WhatsApp y el cron horario (recordatorios y señas
 * vencidas).
 *
 * Las plantillas viven en las opciones tpl_* (Turnos → Ajustes →
 * Notificaciones) y se rellenan con mjto_placeholders(). Las fechas se
 * formatean siempre con wp_date() en la zona horaria del sitio.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Placeholders y render
 * ---------------------------------------------------------------------- */

/** URL pública del estado de un turno: /{slug}/t/{token}. */
function mjto_booking_status_url($token) {
	$token = preg_replace('/[^a-zA-Z0-9]/', '', (string) $token);
	if ('' === $token) {
		return home_url('/' . mjto_page_slug());
	}
	return home_url('/' . mjto_page_slug() . '/t/' . $token);
}

/** Fecha larga en español: "sábado 12 de septiembre" (formato filtrable). */
function mjto_long_date($datetime) {
	$ts = mjto_local_ts($datetime);
	if (null === $ts) {
		return '';
	}
	$format = (string) apply_filters('mjto_long_date_format', 'l j \d\e F');
	return (string) wp_date($format, $ts, wp_timezone());
}

/** Fecha y hora largas: "sábado 12 de septiembre a las 15:30" (formato filtrable). */
function mjto_long_datetime($datetime) {
	$ts = mjto_local_ts($datetime);
	if (null === $ts) {
		return '';
	}
	$format = (string) apply_filters('mjto_long_datetime_format', 'l j \d\e F \a \l\a\s H:i');
	return (string) wp_date($format, $ts, wp_timezone());
}

/**
 * Mapa de placeholders de un turno: ['{nombre}' => …, '{fecha}' => …].
 * $appointment: id, fila cruda o fila joineada. $extra pisa o agrega claves
 * (con o sin llaves: 'motivo' o '{motivo}').
 */
function mjto_placeholders($appointment, array $extra = []) {
	$row = mjto_appointment_row($appointment);
	$o   = mjto_get_options();
	$tz  = wp_timezone();

	/* Datos de la clienta (fila joineada o búsqueda). */
	$first = '';
	$last  = '';
	$phone = '';
	if ($row) {
		if (array_key_exists('client_first_name', $row)) {
			$first = (string) $row['client_first_name'];
			$last  = (string) $row['client_last_name'];
			$phone = '' !== (string) ($row['client_phone_raw'] ?? '') ? (string) $row['client_phone_raw'] : (string) ($row['client_phone'] ?? '');
		} elseif (!empty($row['client_id'])) {
			$c = mjto_get_client($row['client_id']);
			if ($c) {
				$first = (string) $c['first_name'];
				$last  = (string) $c['last_name'];
				$phone = '' !== (string) $c['phone_raw'] ? (string) $c['phone_raw'] : (string) $c['phone'];
			}
		}
	}

	$starts   = $row ? (string) ($row['starts_at'] ?? '') : '';
	$ts       = mjto_local_ts($starts);
	$deposit  = $row ? (float) ($row['deposit_required'] ?? 0) : 0.0;
	$price    = $row ? (float) ($row['price'] ?? 0) : 0.0;
	$deadline = $row ? mjto_dt_or_null($row['deposit_deadline'] ?? '') : null;
	$status   = $row ? (string) ($row['status'] ?? '') : '';
	$date     = '' !== $starts ? substr($starts, 0, 10) : '';

	/* Control y retoque: los de la ficha si existe, si no los sugeridos. */
	$control = '';
	$retouch = '';
	if ($row && !empty($row['id'])) {
		$visit = null;
		if (array_key_exists('visit_id', $row)) {
			$visit = !empty($row['visit_id']) ? mjto_get_visit($row['visit_id']) : null;
		} else {
			$visit = mjto_visit_of_appointment($row['id']);
		}
		if ($visit) {
			$control = (string) mjto_dt_or_null($visit['control_date'] ?? '');
			$retouch = (string) mjto_dt_or_null($visit['retouch_date'] ?? '');
		}
	}
	if (('' === $control || '' === $retouch) && '' !== $date) {
		$suggest = mjto_suggest_dates($date);
		$control = '' !== $control ? $control : $suggest['control'];
		$retouch = '' !== $retouch ? $retouch : $suggest['retouch'];
	}

	$business_wa = mjto_business_whatsapp();
	$link_pago   = trim((string) $o['deposit_link']);

	$map = [
		'{nombre}'                  => $first,
		'{apellido}'                => $last,
		'{nombre_completo}'         => mjto_full_name($first, $last),
		'{telefono}'                => $phone,
		'{servicio}'                => $row ? (string) ($row['service_name'] ?? '') : '',
		'{fecha}'                   => null !== $ts ? mjto_long_date($starts) : '',
		'{hora}'                    => null !== $ts ? (string) wp_date('H:i', $ts, $tz) : '',
		'{fecha_hora}'              => null !== $ts ? mjto_long_datetime($starts) : '',
		'{duracion}'                => $row ? mjto_format_minutes((int) ($row['duration_min'] ?? 0)) : '',
		'{precio}'                  => $row ? mjto_format_price($price) : '',
		'{sena}'                    => $deposit > 0 ? mjto_format_price($deposit) : '',
		'{seña}'                    => $deposit > 0 ? mjto_format_price($deposit) : '',
		'{instrucciones_sena}'      => trim((string) $o['deposit_instructions']),
		'{instrucciones_seña}'      => trim((string) $o['deposit_instructions']),
		'{link_pago}'               => $link_pago,
		'{fecha_limite}'            => $deadline ? mjto_long_datetime($deadline) : '',
		'{negocio}'                 => mjto_business_name(),
		'{direccion}'               => mjto_business_address(),
		'{whatsapp_negocio}'        => '' !== $business_wa ? mjto_whatsapp_url($business_wa) : '',
		'{whatsapp_negocio_numero}' => $business_wa,
		'{link_turno}'              => $row && !empty($row['token']) ? mjto_booking_status_url($row['token']) : '',
		'{fecha_control}'           => '' !== $control ? mjto_long_date($control . ' 00:00:00') : '',
		'{fecha_retoque}'           => '' !== $retouch ? mjto_long_date($retouch . ' 00:00:00') : '',
		'{motivo}'                  => $row ? (string) ($row['cancel_reason'] ?? '') : '',
		'{estado}'                  => '' !== $status ? mjto_status_label($status) : '',
	];

	foreach ($extra as $key => $value) {
		$key = trim((string) $key);
		if ('' === $key) {
			continue;
		}
		if ('{' !== $key[0]) {
			$key = '{' . $key . '}';
		}
		$map[$key] = is_scalar($value) ? (string) $value : '';
	}
	return (array) apply_filters('mjto_placeholders', $map, $row, $extra);
}

/**
 * Rellena una plantilla con strtr. Las líneas que solo tenían placeholders
 * vacíos (o "Etiqueta: {vacío}") se quitan para que no queden renglones
 * sueltos; se colapsan los saltos de línea repetidos.
 */
function mjto_render_template($template, $placeholders) {
	$placeholders = is_array($placeholders) ? $placeholders : [];
	$lines        = preg_split('/\r\n|\r|\n/', (string) $template);
	$out          = [];
	foreach ($lines as $line) {
		if (preg_match_all('/\{[^{}\s]+\}/u', $line, $m) && !empty($m[0])) {
			$all_empty = true;
			$rest      = $line;
			foreach ($m[0] as $ph) {
				if (array_key_exists($ph, $placeholders) && '' !== trim((string) $placeholders[$ph])) {
					$all_empty = false;
					break;
				}
				$rest = str_replace($ph, '', $rest);
			}
			if ($all_empty) {
				$rest = trim(preg_replace('/[\s\-–—•·]+/u', ' ', $rest));
				if ('' === $rest || ':' === substr($rest, -1)) {
					continue; // Solo tenía placeholders vacíos (o una etiqueta sin valor).
				}
			}
		}
		$out[] = strtr($line, $placeholders);
	}
	$text = implode("\n", $out);
	$text = preg_replace("/[ \t]+\n/", "\n", $text);
	$text = preg_replace("/\n{3,}/", "\n\n", $text);
	return trim((string) $text);
}

/** Plantilla (opción tpl_*) ya renderizada para un turno. */
function mjto_render_option_template($option_key, $appointment, array $extra = []) {
	return mjto_render_template((string) mjto_opt($option_key), mjto_placeholders($appointment, $extra));
}

/* -------------------------------------------------------------------------
 * Mail
 * ---------------------------------------------------------------------- */

/** Nombre del remitente: mail_from_name o el nombre del negocio. */
function mjto_mail_from_name() {
	$name = trim((string) mjto_opt('mail_from_name'));
	return '' !== $name ? $name : mjto_business_name();
}

/** Limpia un nombre para un encabezado de mail (sin saltos, comillas ni <>). */
function mjto_mail_header_name($name) {
	$name = preg_replace('/[\r\n"<>]+/', ' ', (string) $name);
	return trim(preg_replace('/\s+/', ' ', $name));
}

/**
 * Envía un mail de texto plano con wp_mail. From con mail_from_name;
 * $args: reply_to (email), reply_to_name. → bool.
 */
function mjto_mail($to, $subject, $body, array $args = []) {
	$args = wp_parse_args($args, ['reply_to' => '', 'reply_to_name' => '']);
	$to   = sanitize_email((string) $to);
	if (!is_email($to)) {
		return false;
	}
	$subject = trim(preg_replace('/[\r\n]+/', ' ', (string) $subject));
	$body    = (string) $body;
	$headers = ['Content-Type: text/plain; charset=UTF-8'];
	$reply   = sanitize_email((string) $args['reply_to']);
	if (is_email($reply)) {
		$reply_name = mjto_mail_header_name($args['reply_to_name']);
		$headers[]  = 'Reply-To: ' . ('' !== $reply_name ? '"' . $reply_name . '" <' . $reply . '>' : $reply);
	}
	$mail = apply_filters('mjto_mail', [
		'to'      => $to,
		'subject' => $subject,
		'body'    => $body,
		'headers' => $headers,
	], $args);
	if (!is_array($mail) || empty($mail['to'])) {
		return false;
	}

	$from_name   = mjto_mail_header_name(mjto_mail_from_name());
	$name_filter = function () use ($from_name) {
		return $from_name;
	};
	if ('' !== $from_name) {
		add_filter('wp_mail_from_name', $name_filter, 99);
	}
	$sent = wp_mail($mail['to'], $mail['subject'], $mail['body'], $mail['headers']);
	if ('' !== $from_name) {
		remove_filter('wp_mail_from_name', $name_filter, 99);
	}
	return (bool) $sent;
}

/* -------------------------------------------------------------------------
 * Notificaciones a la dueña
 * ---------------------------------------------------------------------- */

/** Mail a la dueña ($kind: new | cancel | deposit_notice), si la opción owner_notify_* está activa. → bool */
function mjto_notify_owner($appointment, $kind) {
	$kinds = [
		'new'            => ['owner_notify_new', 'tpl_owner_new_subject', 'tpl_owner_new_body'],
		'cancel'         => ['owner_notify_cancel', 'tpl_owner_cancel_subject', 'tpl_owner_cancel_body'],
		'deposit_notice' => ['owner_notify_deposit_notice', 'tpl_owner_deposit_notice_subject', 'tpl_owner_deposit_notice_body'],
	];
	if (!isset($kinds[$kind])) {
		return false;
	}
	[$flag, $subject_key, $body_key] = $kinds[$kind];
	if (!(int) mjto_opt($flag)) {
		return false;
	}
	$row = mjto_appointment_row($appointment);
	if (!$row) {
		return false;
	}
	if (!apply_filters('mjto_notify_owner', true, $row, $kind)) {
		return false;
	}
	$placeholders = mjto_placeholders($row);
	$subject      = mjto_render_template((string) mjto_opt($subject_key), $placeholders);
	$body         = mjto_render_template((string) mjto_opt($body_key), $placeholders);
	$client_email = sanitize_email((string) ($row['client_email'] ?? ''));
	$sent         = mjto_mail(mjto_owner_email(), $subject, $body, [
		'reply_to'      => is_email($client_email) ? $client_email : '',
		'reply_to_name' => $placeholders['{nombre_completo}'] ?? '',
	]);
	do_action('mjto_owner_notified', $row, $kind, $sent);
	return $sent;
}

function mjto_notify_owner_new($appointment) {
	return mjto_notify_owner($appointment, 'new');
}

function mjto_notify_owner_cancel($appointment) {
	return mjto_notify_owner($appointment, 'cancel');
}

function mjto_notify_owner_deposit_notice($appointment) {
	return mjto_notify_owner($appointment, 'deposit_notice');
}

/* -------------------------------------------------------------------------
 * Notificaciones a la clienta
 * ---------------------------------------------------------------------- */

/** Tipos de mail a la clienta → [asunto, cuerpo]. */
function mjto_client_mail_kinds() {
	return [
		'received'  => ['tpl_client_received_subject', 'tpl_client_received_body'],
		'deposit'   => ['tpl_client_deposit_subject', 'tpl_client_deposit_body'],
		'confirmed' => ['tpl_client_confirmed_subject', 'tpl_client_confirmed_body'],
		'reminder'  => ['tpl_client_reminder_subject', 'tpl_client_reminder_body'],
		'cancelled' => ['tpl_client_cancelled_subject', 'tpl_client_cancelled_body'],
	];
}

/**
 * Mail a la clienta ($kind: received | deposit | confirmed | reminder | cancelled)
 * si client_email_enabled y tiene email. Reply-To: la dueña. → bool
 */
function mjto_notify_client($appointment, $kind) {
	$kinds = mjto_client_mail_kinds();
	if (!isset($kinds[$kind]) || !(int) mjto_opt('client_email_enabled')) {
		return false;
	}
	$row = mjto_appointment_row($appointment);
	if (!$row) {
		return false;
	}
	$email = sanitize_email((string) ($row['client_email'] ?? ''));
	if (!is_email($email) && !empty($row['client_id'])) {
		$c     = mjto_get_client($row['client_id']);
		$email = $c ? sanitize_email((string) $c['email']) : '';
	}
	if (!is_email($email)) {
		return false;
	}
	if (!apply_filters('mjto_notify_client', true, $row, $kind)) {
		return false;
	}
	[$subject_key, $body_key] = $kinds[$kind];
	$placeholders = mjto_placeholders($row);
	$subject      = mjto_render_template((string) mjto_opt($subject_key), $placeholders);
	$body         = mjto_render_template((string) mjto_opt($body_key), $placeholders);
	$sent         = mjto_mail($email, $subject, $body, [
		'reply_to'      => mjto_owner_email(),
		'reply_to_name' => mjto_business_name(),
	]);
	do_action('mjto_client_notified', $row, $kind, $sent);
	return $sent;
}

/* -------------------------------------------------------------------------
 * WhatsApp
 * ---------------------------------------------------------------------- */

/** https://wa.me/{número}?text={texto} ('' si no hay número). */
function mjto_whatsapp_url($number, $text = '') {
	$number = preg_replace('/\D+/', '', (string) $number);
	if ('' === $number) {
		return '';
	}
	$url  = 'https://wa.me/' . $number;
	$text = trim((string) $text);
	if ('' !== $text) {
		$url .= '?text=' . rawurlencode($text);
	}
	return $url;
}

/** Tipos de mensaje de WhatsApp que manda la dueña → opción de plantilla. */
function mjto_whatsapp_kinds() {
	return [
		'confirm'  => 'tpl_wa_confirm',
		'deposit'  => 'tpl_wa_deposit',
		'reminder' => 'tpl_wa_reminder',
		'control'  => 'tpl_wa_control',
		'retouch'  => 'tpl_wa_retouch',
		'generic'  => 'tpl_wa_generic',
	];
}

/**
 * Links de WhatsApp para escribirle a la clienta desde el panel:
 * {confirm, deposit, reminder, control, retouch, generic} (todos '' si no
 * tiene teléfono).
 */
function mjto_whatsapp_links($appointment) {
	$links = array_fill_keys(array_keys(mjto_whatsapp_kinds()), '');
	$row   = mjto_appointment_row($appointment);
	if (!$row) {
		return $links;
	}
	$phone = (string) ($row['client_phone'] ?? '');
	if ('' === $phone && !empty($row['client_id'])) {
		$c     = mjto_get_client($row['client_id']);
		$phone = $c ? (string) $c['phone'] : '';
	}
	$number = mjto_whatsapp_number($phone);
	if ('' === $number) {
		return $links;
	}
	$o            = mjto_get_options();
	$placeholders = mjto_placeholders($row);
	foreach (mjto_whatsapp_kinds() as $kind => $option_key) {
		$text         = mjto_render_template((string) ($o[$option_key] ?? ''), $placeholders);
		$links[$kind] = mjto_whatsapp_url($number, $text);
	}
	return (array) apply_filters('mjto_whatsapp_links', $links, $row, $placeholders);
}

/** Link para que la clienta le escriba al negocio con el comprobante de la seña ('' si el negocio no tiene WhatsApp). */
function mjto_client_whatsapp_url($appointment) {
	$number = mjto_business_whatsapp();
	if ('' === $number) {
		return '';
	}
	$row = mjto_appointment_row($appointment);
	if (!$row) {
		return mjto_whatsapp_url($number);
	}
	$text = mjto_render_template((string) mjto_opt('tpl_wa_client_deposit'), mjto_placeholders($row));
	return mjto_whatsapp_url($number, $text);
}

/* -------------------------------------------------------------------------
 * Cron horario: recordatorios y señas vencidas
 * ---------------------------------------------------------------------- */

add_action('mjto_hourly', 'mjto_cron_hourly');

/** Corre cada hora (evento mjto_hourly, programado en la activación). */
function mjto_cron_hourly() {
	mjto_cron_send_reminders();
	mjto_cron_expire_deposits();
	do_action('mjto_cron_hourly_done');
}

/**
 * Recordatorios: turnos confirmados que empiezan entre ahora y ahora +
 * reminder_hours_before, sin recordatorio enviado y con email. Se marca
 * reminder_sent_at solo si el mail salió. → cantidad enviada.
 */
function mjto_cron_send_reminders() {
	global $wpdb;
	if (!(int) mjto_opt('reminder_enabled') || !(int) mjto_opt('client_email_enabled')) {
		return 0;
	}
	$hours = max(0, (float) mjto_opt('reminder_hours_before'));
	if ($hours <= 0) {
		return 0;
	}
	$now   = current_datetime();
	$from  = $now->format('Y-m-d H:i:s');
	$until = $now->modify('+' . (int) round($hours * 60) . ' minutes')->format('Y-m-d H:i:s');
	$limit = max(1, (int) apply_filters('mjto_cron_batch', 50));
	$rows  = $wpdb->get_results($wpdb->prepare(
		mjto_appointment_select() . " WHERE a.status = 'confirmed' AND a.reminder_sent_at IS NULL AND a.starts_at >= %s AND a.starts_at <= %s AND c.email <> '' ORDER BY a.starts_at ASC LIMIT %d",
		$from,
		$until,
		$limit
	), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	$sent = 0;
	foreach ((array) $rows as $row) {
		if (mjto_notify_client($row, 'reminder')) {
			mjto_update_appointment($row['id'], ['reminder_sent_at' => mjto_now()]);
			$sent++;
			do_action('mjto_reminder_sent', $row);
		}
	}
	return $sent;
}

/**
 * Señas vencidas: turnos esperando seña cuyo plazo pasó y la clienta no
 * avisó que transfirió → se cancelan (por el sistema, con aviso). Solo si
 * deposit_hours > 0. → cantidad cancelada.
 */
function mjto_cron_expire_deposits() {
	global $wpdb;
	if ((int) mjto_opt('deposit_hours') <= 0) {
		return 0;
	}
	$limit = max(1, (int) apply_filters('mjto_cron_batch', 50));
	$rows  = $wpdb->get_results($wpdb->prepare(
		mjto_appointment_select() . " WHERE a.status = 'awaiting_deposit' AND a.deposit_deadline IS NOT NULL AND a.deposit_deadline < %s AND a.deposit_notified_at IS NULL ORDER BY a.deposit_deadline ASC LIMIT %d",
		mjto_now(),
		$limit
	), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	$count = 0;
	foreach ((array) $rows as $row) {
		$reason = (string) apply_filters('mjto_deposit_expired_reason', (string) mjto_opt('cancel_reason_deposit_expired'), $row);
		$result = mjto_set_status($row['id'], 'cancelled', ['by' => 'system', 'reason' => $reason, 'notify' => true]);
		if (!is_wp_error($result)) {
			$count++;
			do_action('mjto_deposit_expired', $result);
		}
	}
	return $count;
}
