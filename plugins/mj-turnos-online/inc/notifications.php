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

/**
 * URL pública del estado de un turno. Con el tema MJ Appearance es
 * /{slug}/t/{token} (una ruta de su tienda); con cualquier otro tema, la
 * página del shortcode con ?turno={token}, que el widget abre solo (esa
 * ruta de tres partes ahí no existe y daría 404).
 */
function mjto_booking_status_url($token) {
	$token = preg_replace('/[^a-zA-Z0-9]/', '', (string) $token);
	if ('' === $token) {
		return home_url('/' . mjto_page_slug());
	}
	if (!function_exists('mj_spa_handles_path')) {
		return add_query_arg('turno', $token, home_url('/' . mjto_page_slug()));
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
	/* {sena}: lo que falta transferir (la seña menos el saldo a favor usado); si ya está paga, la seña entera. */
	$deposit  = $row ? (float) ($row['deposit_required'] ?? 0) : 0.0;
	$due      = $row ? max(0.0, $deposit - (float) ($row['deposit_paid'] ?? 0)) : 0.0;
	$deposit  = ($row && 'awaiting_deposit' === (string) ($row['status'] ?? '') && $due > 0) ? $due : $deposit;
	$paid     = $row ? (float) ($row['deposit_paid'] ?? 0) : 0.0;
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
		$suggest = mjto_suggest_dates($date, $row ? (int) ($row['service_id'] ?? 0) : 0);
		$control = '' !== $control ? $control : $suggest['control'];
		$retouch = '' !== $retouch ? $retouch : $suggest['retouch'];
	}

	$business_wa = mjto_business_whatsapp();
	$link_pago   = trim((string) $o['deposit_link']);
	$service_id  = $row ? (int) ($row['service_id'] ?? 0) : 0;
	$service     = $service_id > 0 ? mjto_get_service($service_id) : null;
	$link_book   = ($service && !empty($service['slug']))
		? add_query_arg('servicio', $service['slug'], home_url('/' . mjto_page_slug()))
		: home_url('/' . mjto_page_slug());
	$credit      = ($row && !empty($row['client_id']) && function_exists('mjto_credit_balance')) ? mjto_credit_balance((int) $row['client_id']) : 0.0;

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
		'{fecha_mantenimiento}'     => '' !== $retouch ? mjto_long_date($retouch . ' 00:00:00') : '',
		'{motivo}'                  => $row ? (string) ($row['cancel_reason'] ?? '') : '',
		'{estado}'                  => '' !== $status ? mjto_status_label($status) : '',
		'{sena_pagada}'             => $paid > 0 ? mjto_format_price($paid) : '',
		'{seña_pagada}'             => $paid > 0 ? mjto_format_price($paid) : '',
		'{seña_resultado}'          => '',
		'{fecha_anterior}'          => '',
		'{saldo}'                   => $credit > 0 ? mjto_format_price($credit) : '',
		'{link_reserva}'            => $link_book,
		'{link_mis_turnos}'         => function_exists('mjto_my_bookings_url') && 'off' !== mjto_account_mode() ? mjto_my_bookings_url() : '',
		'{link_opinion}'            => $row && !empty($row['token']) && function_exists('mjto_review_url') ? mjto_review_url($row['token']) : '',
		'{puntaje}'                 => '',
		'{opinion}'                 => '',
		'{estado_opinion}'          => '',
		'{instagram_negocio}'       => mjto_business_instagram(),
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

/**
 * Mail a la dueña ($kind: new | cancel | deposit_notice | reschedule | refund |
 * review), si la opción owner_notify_* está activa. $extra suma o pisa
 * comodines ({fecha_anterior}, {seña_resultado}, {puntaje}…). → bool
 */
function mjto_notify_owner($appointment, $kind, array $extra = []) {
	$kinds = [
		'new'            => ['owner_notify_new', 'tpl_owner_new_subject', 'tpl_owner_new_body'],
		'cancel'         => ['owner_notify_cancel', 'tpl_owner_cancel_subject', 'tpl_owner_cancel_body'],
		'deposit_notice' => ['owner_notify_deposit_notice', 'tpl_owner_deposit_notice_subject', 'tpl_owner_deposit_notice_body'],
		'reschedule'     => ['owner_notify_reschedule', 'tpl_owner_reschedule_subject', 'tpl_owner_reschedule_body'],
		'refund'         => ['owner_notify_refund', 'tpl_owner_refund_subject', 'tpl_owner_refund_body'],
		'review'         => ['owner_notify_review', 'tpl_owner_review_subject', 'tpl_owner_review_body'],
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
	$placeholders = mjto_placeholders($row, $extra);
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
		'received'     => ['tpl_client_received_subject', 'tpl_client_received_body'],
		'deposit'      => ['tpl_client_deposit_subject', 'tpl_client_deposit_body'],
		'confirmed'    => ['tpl_client_confirmed_subject', 'tpl_client_confirmed_body'],
		'reminder'     => ['tpl_client_reminder_subject', 'tpl_client_reminder_body'],
		'reminder2'    => ['tpl_client_reminder2_subject', 'tpl_client_reminder2_body'],
		'rescheduled'  => ['tpl_client_rescheduled_subject', 'tpl_client_rescheduled_body'],
		'cancelled'    => ['tpl_client_cancelled_subject', 'tpl_client_cancelled_body'],
		'owner_cancel' => ['tpl_client_owner_cancel_subject', 'tpl_client_owner_cancel_body'],
		'maintenance'  => ['tpl_client_maintenance_subject', 'tpl_client_maintenance_body'],
		'review'       => ['tpl_client_review_subject', 'tpl_client_review_body'],
	];
}

/**
 * Mail a la clienta (uno de mjto_client_mail_kinds()) si client_email_enabled
 * y tiene email. Reply-To: la dueña. $extra suma o pisa comodines. → bool
 */
function mjto_notify_client($appointment, $kind, array $extra = []) {
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
	$placeholders = mjto_placeholders($row, $extra);
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
add_action('mjto_tick', 'mjto_cron_hourly');

/**
 * Corre cada hora (mjto_hourly) y en la tarea frecuente (mjto_tick, cada 5
 * minutos). Cada paso marca lo que ya hizo, así que correrlo de más no
 * duplica nada.
 */
function mjto_cron_hourly() {
	mjto_cron_expire_deposits();
	mjto_cron_send_reminders();
	mjto_cron_send_reminders2();
	mjto_cron_maintenance();
	mjto_cron_review_requests();
	do_action('mjto_cron_hourly_done');
}

/**
 * Recordatorios: turnos confirmados que empiezan entre ahora y ahora +
 * reminder_hours_before, sin recordatorio enviado y con email. Se marca
 * reminder_sent_at solo si el mail salió. Con el segundo recordatorio
 * prendido, un turno que ya está dentro de su ventana recibe solo ese (no
 * dos mails seguidos). → cantidad enviada.
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
	$now  = current_datetime();
	$from = $now;
	if ((int) mjto_opt('reminder2_enabled')) {
		$h2 = max(0, (float) mjto_opt('reminder2_hours_before'));
		if ($h2 > 0 && $h2 < $hours) {
			$from = $now->modify('+' . (int) round($h2 * 60) . ' minutes');
		}
	}
	$until = $now->modify('+' . (int) round($hours * 60) . ' minutes')->format('Y-m-d H:i:s');
	$limit = max(1, (int) apply_filters('mjto_cron_batch', 50));
	$rows  = $wpdb->get_results($wpdb->prepare(
		mjto_appointment_select() . " WHERE a.status = 'confirmed' AND a.reminder_sent_at IS NULL AND a.starts_at > %s AND a.starts_at <= %s AND c.email <> '' ORDER BY a.starts_at ASC LIMIT %d",
		$from->format('Y-m-d H:i:s'),
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
 * Segundo recordatorio (el corto, "en un rato te espero"): turnos confirmados
 * que empiezan dentro de reminder2_hours_before. → cantidad enviada.
 */
function mjto_cron_send_reminders2() {
	global $wpdb;
	if (!(int) mjto_opt('reminder2_enabled') || !(int) mjto_opt('client_email_enabled')) {
		return 0;
	}
	$hours = max(0, (float) mjto_opt('reminder2_hours_before'));
	if ($hours <= 0) {
		return 0;
	}
	$now   = current_datetime();
	$until = $now->modify('+' . (int) round($hours * 60) . ' minutes')->format('Y-m-d H:i:s');
	$limit = max(1, (int) apply_filters('mjto_cron_batch', 50));
	$rows  = $wpdb->get_results($wpdb->prepare(
		mjto_appointment_select() . " WHERE a.status = 'confirmed' AND a.reminder2_sent_at IS NULL AND a.starts_at > %s AND a.starts_at <= %s AND c.email <> '' ORDER BY a.starts_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL -- el SELECT común es fijo; los valores van por prepare.
		$now->format('Y-m-d H:i:s'),
		$until,
		$limit
	), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	$sent = 0;
	foreach ((array) $rows as $row) {
		if (mjto_notify_client($row, 'reminder2')) {
			mjto_update_appointment($row['id'], ['reminder2_sent_at' => mjto_now()]);
			$sent++;
			do_action('mjto_reminder2_sent', $row);
		}
	}
	return $sent;
}

/**
 * Señas vencidas: turnos esperando seña cuyo plazo pasó y la clienta no
 * avisó que transfirió (ni subió el comprobante) → se cancelan (por el
 * sistema, con aviso) y el saldo a favor que hubieran usado vuelve. Solo si
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
		/*
		 * Con el candado del turno y releyendo: la tarea horaria, la frecuente y
		 * una consulta pública pueden llegar juntas, y solo una tiene que
		 * cancelar, devolver el saldo y mandar el mail.
		 */
		$done = mjto_with_appointment_lock((int) $row['id'], function ($fresh) {
			if ('awaiting_deposit' !== (string) $fresh['status'] || !empty($fresh['deposit_notified_at']) || empty($fresh['deposit_deadline']) || (string) $fresh['deposit_deadline'] >= mjto_now()) {
				return false;
			}
			$reason = (string) apply_filters('mjto_deposit_expired_reason', (string) mjto_opt('cancel_reason_deposit_expired'), $fresh);
			$result = mjto_set_status($fresh['id'], 'cancelled', ['by' => 'system', 'reason' => $reason, 'notify' => false]);
			if (is_wp_error($result)) {
				return false;
			}
			$returned = function_exists('mjto_return_applied_credit') ? mjto_return_applied_credit($result) : 0.0;
			$after    = mjto_get_appointment((int) $fresh['id']);
			mjto_notify_client($after, 'cancelled', [
				'seña_resultado' => $returned > 0 ? mjto_deposit_result_text('credit', $after, $returned) : '',
			]);
			do_action('mjto_deposit_expired', $after);
			return true;
		});
		if (true === $done) {
			$count++;
		}
	}
	return $count;
}

/**
 * Vence las señas atrasadas en el momento de una consulta (disponibilidad,
 * reserva, estado de un turno): así el horario de una seña que venció a la
 * hora vuelve a estar libre aunque la tarea programada todavía no haya
 * corrido. Una vez por minuto como mucho.
 */
function mjto_maybe_expire_deposits() {
	if ((int) mjto_opt('deposit_hours') <= 0 || get_transient('mjto_expire_check')) {
		return;
	}
	set_transient('mjto_expire_check', 1, MINUTE_IN_SECONDS);
	mjto_cron_expire_deposits();
}

/**
 * Aviso de mantenimiento: visitas cuya fecha de retoque / próximo
 * mantenimiento cae dentro de maintenance_days_before, sin aviso mandado, de
 * clientas con email y sin un turno ya reservado para adelante. Solo la
 * última visita de cada clienta (una visita vieja no avisa). → cantidad enviada.
 */
function mjto_cron_maintenance() {
	global $wpdb;
	if (!(int) mjto_opt('maintenance_enabled') || !(int) mjto_opt('client_email_enabled')) {
		return 0;
	}
	$today  = mjto_today();
	$until  = mjto_date_add($today, max(0, (int) mjto_opt('maintenance_days_before')));
	$visits = mjto_table('visits');
	$apps   = mjto_table('appointments');
	$limit  = max(1, (int) apply_filters('mjto_cron_batch', 50));
	// phpcs:disable WordPress.DB.PreparedSQL -- tablas propias; los valores van por prepare.
	$rows   = $wpdb->get_results($wpdb->prepare(
		"SELECT v.*, c.first_name AS client_first_name, c.last_name AS client_last_name, c.phone AS client_phone,
			c.phone_raw AS client_phone_raw, c.email AS client_email, c.locality AS client_locality
		FROM {$visits} v
		INNER JOIN " . mjto_table('clients') . " c ON c.id = v.client_id
		WHERE v.retouch_date IS NOT NULL AND v.retouch_date >= %s AND v.retouch_date <= %s
			AND v.maintenance_sent_at IS NULL AND c.email <> ''
			AND v.id = (SELECT v2.id FROM {$visits} v2 WHERE v2.client_id = v.client_id ORDER BY v2.visited_at DESC, v2.id DESC LIMIT 1)
			AND NOT EXISTS (SELECT 1 FROM {$apps} a WHERE a.client_id = v.client_id AND a.status IN ('pending','awaiting_deposit','confirmed') AND a.starts_at >= %s)
		ORDER BY v.retouch_date ASC LIMIT %d",
		$today,
		$until,
		$today . ' 00:00:00',
		$limit
	), ARRAY_A);
	// phpcs:enable
	$sent = 0;
	foreach ((array) $rows as $visit) {
		/* El mail se arma como si fuera un turno: la clienta, el servicio y la fecha de la visita. */
		$row = [
			'id'                => 0,
			'client_id'         => (int) $visit['client_id'],
			'service_id'        => (int) $visit['service_id'],
			'service_name'      => (string) $visit['service_name'],
			'starts_at'         => (string) $visit['visited_at'] . ' 00:00:00',
			'status'            => '',
			'client_first_name' => $visit['client_first_name'],
			'client_last_name'  => $visit['client_last_name'],
			'client_phone'      => $visit['client_phone'],
			'client_phone_raw'  => $visit['client_phone_raw'],
			'client_email'      => $visit['client_email'],
			'client_locality'   => $visit['client_locality'],
		];
		$extra = ['fecha_mantenimiento' => mjto_long_date($visit['retouch_date'] . ' 00:00:00')];
		if (mjto_notify_client($row, 'maintenance', $extra)) {
			mjto_update_visit((int) $visit['id'], ['maintenance_sent_at' => mjto_now()]);
			$sent++;
			do_action('mjto_maintenance_sent', $visit);
		}
	}
	return $sent;
}

/**
 * Pedido de opinión: turnos atendidos hace reviews_delay_hours o más (y no
 * más de 30 días), sin pedido mandado ni opinión cargada. → cantidad enviada.
 */
function mjto_cron_review_requests() {
	global $wpdb;
	if (!function_exists('mjto_reviews_enabled') || !mjto_reviews_enabled() || !(int) mjto_opt('client_email_enabled')) {
		return 0;
	}
	$now     = current_datetime();
	$ready   = $now->modify('-' . max(0, (int) mjto_opt('reviews_delay_hours')) . ' hours')->format('Y-m-d H:i:s');
	$oldest  = $now->modify('-' . max(1, (int) apply_filters('mjto_review_request_max_days', 30)) . ' days')->format('Y-m-d H:i:s');
	$reviews = mjto_table('reviews');
	$limit   = max(1, (int) apply_filters('mjto_cron_batch', 50));
	// phpcs:disable WordPress.DB.PreparedSQL -- el SELECT común y la tabla son fijos; los valores van por prepare.
	$rows    = $wpdb->get_results($wpdb->prepare(
		mjto_appointment_select() . " WHERE a.status = 'completed' AND a.completed_at IS NOT NULL AND a.completed_at <= %s AND a.completed_at >= %s
			AND a.review_requested_at IS NULL AND c.email <> ''
			AND NOT EXISTS (SELECT 1 FROM {$reviews} r WHERE r.appointment_id = a.id)
		ORDER BY a.completed_at ASC LIMIT %d",
		$ready,
		$oldest,
		$limit
	), ARRAY_A);
	// phpcs:enable
	$sent = 0;
	foreach ((array) $rows as $row) {
		if (mjto_notify_client($row, 'review')) {
			mjto_update_appointment((int) $row['id'], ['review_requested_at' => mjto_now()]);
			$sent++;
			do_action('mjto_review_requested', $row);
		}
	}
	return $sent;
}
