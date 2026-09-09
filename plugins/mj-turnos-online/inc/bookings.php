<?php
/**
 * Reservas y estados: crear un turno (web o panel), transiciones de estado,
 * reprogramar, cancelación y aviso de seña de la clienta, ficha técnica
 * automática (visita) con precarga, fechas sugeridas y advertencias.
 *
 * Todo lo que llega del formulario público se valida contra los campos
 * configurados (mjto_form_fields()); la clienta se reutiliza por teléfono.
 * Los textos de error de la reserva salen de mjto_booking_messages()
 * (filtro mjto_booking_messages); el mensaje de "no se puede cancelar" es
 * la opción booking_cannot_cancel.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Helpers generales
 * ---------------------------------------------------------------------- */

/** Token público de un turno: 32 caracteres hex, único en la tabla. */
function mjto_new_token() {
	for ($i = 0; $i < 5; $i++) {
		try {
			$token = bin2hex(random_bytes(16));
		} catch (Exception $e) {
			$token = md5(wp_generate_password(32, true, true) . microtime(true) . wp_rand());
		}
		if (!mjto_get_appointment_by_token($token)) {
			return $token;
		}
	}
	return md5(wp_generate_password(32, true, true) . microtime(true) . wp_rand());
}

/**
 * Fila completa (joineada con la clienta) de un turno a partir de un id, una
 * fila cruda o una fila joineada. Devuelve null si no existe.
 */
function mjto_appointment_row($appointment) {
	if (is_array($appointment)) {
		if (array_key_exists('client_first_name', $appointment)) {
			return $appointment;
		}
		if (!empty($appointment['id'])) {
			$row = mjto_get_appointment($appointment['id']);
			return $row ? $row : $appointment;
		}
		return $appointment;
	}
	return mjto_get_appointment((int) $appointment);
}

/**
 * Mensajes de error de la reserva y del panel (clave => texto). Cada uno es
 * la opción msg_{clave} (Turnos → Ajustes → Textos → Mensajes; vacío = el
 * default de mjto_default_messages()); "no se puede cancelar" es la opción
 * booking_cannot_cancel. Después pasan por el filtro mjto_booking_messages.
 */
function mjto_booking_messages() {
	$o        = mjto_get_options();
	$messages = [];
	foreach (mjto_default_messages() as $key => $default) {
		$value          = isset($o['msg_' . $key]) && is_string($o['msg_' . $key]) ? trim($o['msg_' . $key]) : '';
		$messages[$key] = '' !== $value ? $value : (string) $default;
	}
	$messages['cannot_cancel'] = (string) ($o['booking_cannot_cancel'] ?? '');
	return (array) apply_filters('mjto_booking_messages', $messages);
}

/**
 * Reemplaza cada %s del mensaje por el siguiente argumento. Sin sprintf: un
 * "%" suelto en un texto configurable no rompe nada.
 */
function mjto_message_format($text, array $args) {
	$text = (string) $text;
	foreach ($args as $arg) {
		$pos = strpos($text, '%s');
		if (false === $pos) {
			break;
		}
		$text = substr_replace($text, (string) $arg, $pos, 2);
	}
	return $text;
}

/** WP_Error con el mensaje configurado. $data se suma a ['status' => $status]; los argumentos extra reemplazan los %s. */
function mjto_booking_error($key, $status = 400, array $data = [], ...$sprintf) {
	$messages = mjto_booking_messages();
	$message  = isset($messages[$key]) ? (string) $messages[$key] : (string) $key;
	if ($sprintf) {
		$message = mjto_message_format($message, $sprintf);
	}
	return new WP_Error('mjto_' . $key, $message, array_merge(['status' => (int) $status], $data));
}

/** Estado inicial de una reserva según confirm_mode y la seña calculada. */
function mjto_initial_status($deposit_required) {
	switch ((string) mjto_opt('confirm_mode')) {
		case 'auto':
			return 'confirmed';
		case 'manual':
			return 'pending';
		default:
			return ((float) $deposit_required > 0) ? 'awaiting_deposit' : 'pending';
	}
}

/* -------------------------------------------------------------------------
 * Validación de la clienta (formulario público y panel)
 * ---------------------------------------------------------------------- */

/** Un valor del formulario según el tipo del campo → string sanitizado. */
function mjto_sanitize_form_value($value, $type) {
	if (is_array($value)) {
		$value = implode(', ', array_map('strval', $value));
	}
	$value = (string) $value;
	switch ($type) {
		case 'textarea':
			return trim(sanitize_textarea_field($value));
		case 'email':
			return trim(sanitize_email($value));
		default:
			return trim(sanitize_text_field($value));
	}
}

/**
 * Valida el formulario público según mjto_form_fields(): obligatorios, tipos,
 * honeypot "website" y teléfono con al menos 6 dígitos. Los campos custom
 * pueden venir sueltos o dentro de 'extra'.
 * → ['client_id' => 0, 'client' => [...], 'extra' => [...], 'notes' => ''] o WP_Error
 *   (con data.errors y data.params = [key => mensaje], el mismo mapa).
 */
function mjto_validate_public_form(array $input) {
	if (!empty($input['website'])) {
		return mjto_booking_error('spam', 400);
	}
	$extra_in = isset($input['extra']) && is_array($input['extra']) ? $input['extra'] : [];
	$client   = ['first_name' => '', 'last_name' => '', 'phone' => '', 'email' => '', 'locality' => ''];
	$extra    = [];
	$notes    = '';
	$errors   = [];
	$messages = mjto_booking_messages();
	$err      = function ($key, $field, $msg_key) use (&$errors, $messages) {
		if (!isset($errors[$key])) {
			$text         = isset($messages[$msg_key]) ? (string) $messages[$msg_key] : $msg_key;
			$errors[$key] = mjto_message_format($text, [$field['label']]);
		}
	};

	foreach (mjto_form_fields() as $field) {
		if (empty($field['enabled'])) {
			continue;
		}
		$key = $field['key'];
		if (array_key_exists($key, $input)) {
			$raw = $input[$key];
		} elseif (array_key_exists($key, $extra_in)) {
			$raw = $extra_in[$key];
		} else {
			$raw = '';
		}
		if ('email' === $field['type']) {
			/*
			 * El email se valida sobre lo escrito (recortado) y recién después se
			 * sanitiza: sanitize_email() vacía cualquier valor inválido y haría
			 * pasar "ana@gmail" como campo vacío (sin aviso si no es obligatorio).
			 */
			$typed = mjto_sanitize_form_value($raw, 'text');
			if ('' !== $typed && !is_email($typed)) {
				$err($key, $field, 'invalid_email');
				continue;
			}
			$value = '' !== $typed ? trim(sanitize_email($typed)) : '';
		} else {
			$value = mjto_sanitize_form_value($raw, $field['type']);
		}
		if (mb_strlen($value) > ('textarea' === $field['type'] ? 2000 : 190)) {
			$err($key, $field, 'too_long');
			continue;
		}
		if ('' === $value) {
			if (!empty($field['required'])) {
				$err($key, $field, 'required');
			}
		} else {
			switch ($field['type']) {
				case 'tel':
					if (strlen(mjto_normalize_phone($value)) < 6) {
						$err($key, $field, 'invalid_phone');
					}
					break;
				case 'number':
					if (!is_numeric(str_replace(',', '.', $value))) {
						$err($key, $field, 'invalid_number');
					}
					break;
				case 'select':
					$valid = array_map(fn ($o) => (string) $o['value'], (array) $field['options']);
					if ($valid && !in_array($value, $valid, true)) {
						$err($key, $field, 'invalid_option');
					}
					break;
			}
		}
		if (!empty($field['system'])) {
			if ('notes' === $key) {
				$notes = $value;
			} else {
				$client[$key] = $value;
			}
		} else {
			$extra[$key] = $value;
		}
	}

	/* first_name y phone llegan siempre obligatorios y activos desde mjto_form_fields(); acá solo se cierra la validación. */
	if ($errors) {
		/* 'errors' y 'params' llevan el mismo mapa: el tema y el widget leen cualquiera de los dos. */
		return new WP_Error('mjto_invalid_fields', (string) reset($errors), ['status' => 400, 'errors' => $errors, 'params' => $errors]);
	}
	return ['client_id' => 0, 'client' => $client, 'extra' => $extra, 'notes' => $notes];
}

/**
 * Clienta desde el panel: 'client_id' de una existente o 'client' inline
 * (o los campos sueltos) con nombre + teléfono obligatorios.
 */
function mjto_validate_panel_client(array $input) {
	$notes = trim(sanitize_textarea_field((string) ($input['client_notes'] ?? ($input['notes'] ?? ''))));
	if (!empty($input['client_id'])) {
		$existing = mjto_get_client((int) $input['client_id']);
		if (!$existing) {
			return mjto_booking_error('client_not_found', 400);
		}
		return ['client_id' => (int) $existing['id'], 'client' => [], 'extra' => [], 'notes' => $notes];
	}
	$c          = (isset($input['client']) && is_array($input['client'])) ? $input['client'] : $input;
	$first_name = mjto_sanitize_form_value($c['first_name'] ?? '', 'text');
	$phone      = mjto_sanitize_form_value($c['phone'] ?? '', 'tel');
	if ('' === $first_name || strlen(mjto_normalize_phone($phone)) < 6) {
		return mjto_booking_error('client_required', 400);
	}
	$typed = mjto_sanitize_form_value($c['email'] ?? '', 'text');
	if ('' !== $typed && !is_email($typed)) {
		return mjto_booking_error('invalid_email', 400, ['field' => 'email']);
	}
	$email  = '' !== $typed ? trim(sanitize_email($typed)) : '';
	$client = [
		'first_name' => $first_name,
		'last_name'  => mjto_sanitize_form_value($c['last_name'] ?? '', 'text'),
		'phone'      => $phone,
		'email'      => $email,
		'locality'   => mjto_sanitize_form_value($c['locality'] ?? '', 'text'),
	];
	if (isset($c['age']) && '' !== $c['age']) {
		$client['age'] = (int) $c['age'];
	}
	if (isset($c['notes']) && is_array($input['client'] ?? null)) {
		$client['notes'] = sanitize_textarea_field((string) $c['notes']); // Notas internas de la clienta (solo si vino el bloque client).
	}
	$extra = (isset($c['extra']) && is_array($c['extra'])) ? array_map(fn ($v) => mjto_sanitize_form_value($v, 'text'), $c['extra']) : [];
	return ['client_id' => 0, 'client' => $client, 'extra' => $extra, 'notes' => $notes];
}

/**
 * Clienta de la reserva: la indicada por id, la existente con ese teléfono
 * (se completan email/localidad/apellido/extra si estaban vacíos, nunca se
 * pisa el nombre) o una nueva. → id o WP_Error.
 */
function mjto_resolve_booking_client(array $parsed) {
	if (!empty($parsed['client_id'])) {
		return (int) $parsed['client_id'];
	}
	$c        = $parsed['client'];
	$extra    = isset($parsed['extra']) && is_array($parsed['extra']) ? $parsed['extra'] : [];
	$existing = mjto_find_client_by_phone($c['phone']);
	if ($existing) {
		$patch = [];
		foreach (['last_name', 'email', 'locality'] as $key) {
			if ('' === trim((string) ($existing[$key] ?? '')) && '' !== (string) ($c[$key] ?? '')) {
				$patch[$key] = $c[$key];
			}
		}
		if (isset($c['age']) && empty($existing['age']) && (int) $c['age'] > 0) {
			$patch['age'] = (int) $c['age'];
		}
		if ('' === trim((string) ($existing['phone_raw'] ?? '')) && '' !== (string) $c['phone']) {
			$patch['phone_raw'] = $c['phone'];
			$patch['phone']     = $c['phone'];
		}
		if ($extra) {
			$merged  = mjto_json_to_array($existing['extra'] ?? '');
			$changed = false;
			foreach ($extra as $k => $v) {
				if ('' !== (string) $v && (!isset($merged[$k]) || '' === (string) $merged[$k])) {
					$merged[$k] = $v;
					$changed    = true;
				}
			}
			if ($changed) {
				$patch['extra'] = $merged;
			}
		}
		if ($patch) {
			mjto_update_client($existing['id'], $patch);
		}
		return (int) $existing['id'];
	}
	$data = $c;
	if ($extra) {
		$data['extra'] = $extra;
	}
	$id = mjto_insert_client($data);
	if (!$id) {
		return mjto_booking_error('db', 500);
	}
	do_action('mjto_client_created', $id, $data);
	return (int) $id;
}

/* -------------------------------------------------------------------------
 * Crear una reserva
 * ---------------------------------------------------------------------- */

/**
 * Crea un turno desde la web ($source = 'web') o el panel ('panel').
 * $input: service_id, date (YYYY-MM-DD), time (HH:MM), campos del formulario
 * (web) o client_id | client {…} + service_name, duration_min, price,
 * internal_notes, status (panel).
 * $opts: force (saltar disponibilidad), notify (default true), status (forzar),
 * ignore_notice (default true desde el panel).
 * → fila del turno (mjto_get_appointment) o WP_Error con status.
 */
function mjto_create_booking(array $input, $source = 'web', array $opts = []) {
	$source = ('panel' === $source) ? 'panel' : 'web';
	$opts   = wp_parse_args($opts, [
		'force'         => false,
		'notify'        => true,
		'status'        => '',
		'ignore_notice' => ('panel' === $source),
	]);

	/* 1. Clienta (validación; el alta se hace recién con el horario asegurado). */
	$parsed = ('web' === $source) ? mjto_validate_public_form($input) : mjto_validate_panel_client($input);
	if (is_wp_error($parsed)) {
		return $parsed;
	}

	/* 3. Servicio (antes que la fecha porque define la duración). */
	$service_id = (int) ($input['service_id'] ?? 0);
	$service    = $service_id > 0 ? mjto_get_service($service_id) : null;
	if ('web' === $source) {
		if (!$service || empty($service['active']) || empty($service['bookable'])) {
			return mjto_booking_error('invalid_service', 400);
		}
	} elseif ($service_id > 0 && !$service) {
		return mjto_booking_error('invalid_service', 400);
	}
	$duration = $service ? (int) $service['duration'] : 0;
	if ('panel' === $source && (int) ($input['duration_min'] ?? 0) > 0) {
		$duration = (int) $input['duration_min']; // La dueña puede ajustar la duración de este turno.
	} elseif (!$service) {
		$duration = (int) ($input['duration_min'] ?? 0);
	}
	if ($duration <= 0) {
		$duration = (int) mjto_opt('default_duration_min');
	}
	$duration     = max(5, min(1440, $duration));
	$service_name = $service ? (string) $service['name'] : sanitize_text_field((string) ($input['service_name'] ?? ''));
	$price        = $service ? (float) $service['price'] : 0.0;
	if ('panel' === $source && isset($input['price']) && '' !== $input['price'] && is_numeric(str_replace(',', '.', (string) $input['price']))) {
		$price = max(0, (float) str_replace(',', '.', (string) $input['price']));
	}
	$deposit_required = $service ? (float) mjto_service_deposit($service) : (float) mjto_service_deposit(['deposit' => 0, 'price' => $price]);
	if ('panel' === $source && isset($input['deposit_required']) && '' !== $input['deposit_required'] && is_numeric(str_replace(',', '.', (string) $input['deposit_required']))) {
		$deposit_required = max(0, (float) str_replace(',', '.', (string) $input['deposit_required']));
	}

	/* Fecha y hora. */
	$date = trim((string) ($input['date'] ?? ''));
	$time = mjto_normalize_time($input['time'] ?? '');
	if (!mjto_valid_date($date) || '' === $time) {
		return mjto_booking_error('invalid_date', 400);
	}
	/* 5. Rango válido (hoy…max_days_ahead) salvo panel/force. */
	if ('web' === $source && !$opts['force'] && !mjto_date_in_booking_range($date, false)) {
		return mjto_booking_error('out_of_range', 400);
	}
	$tz        = wp_timezone();
	$start     = new DateTimeImmutable($date . ' ' . $time . ':00', $tz);
	$starts_at = $start->format('Y-m-d H:i:s');
	$ends_at   = $start->modify('+' . $duration . ' minutes')->format('Y-m-d H:i:s');

	/* 4. Estado inicial. */
	$status = sanitize_key((string) $opts['status']);
	if ('' === $status && 'panel' === $source && !empty($input['status'])) {
		$status = sanitize_key((string) $input['status']);
	}
	if (!in_array($status, mjto_valid_statuses(), true)) {
		$status = mjto_initial_status($deposit_required);
	}
	$deposit_hours = max(0, (int) mjto_opt('deposit_hours'));
	$deadline      = null;
	if ('awaiting_deposit' === $status && $deposit_hours > 0) {
		$deadline = current_datetime()->modify('+' . $deposit_hours . ' hours')->format('Y-m-d H:i:s');
	}

	/* Lock por fecha + chequeo de disponibilidad. */
	$spec             = mjto_service_spec($service ? $service : null);
	$spec['duration'] = $duration;
	$locked           = false;
	if (!$opts['force']) {
		if (!mjto_acquire_lock($date)) {
			return mjto_booking_error('busy', 503);
		}
		$locked = true;
		if (!mjto_slot_is_free($spec, $date, $time, 0, (bool) $opts['ignore_notice'])) {
			mjto_release_lock($date);
			return mjto_booking_error('slot_taken', 409);
		}
	}

	/* 2. Clienta: reutilizar por teléfono o dar de alta. */
	$client_id = mjto_resolve_booking_client($parsed);
	if (is_wp_error($client_id)) {
		if ($locked) {
			mjto_release_lock($date);
		}
		return $client_id;
	}

	/* 6. Insertar. */
	$data = [
		'client_id'        => $client_id,
		'service_id'       => $service ? (int) $service['id'] : 0,
		'service_name'     => mb_substr($service_name, 0, 160),
		'starts_at'        => $starts_at,
		'ends_at'          => $ends_at,
		'duration_min'     => $duration,
		'status'           => $status,
		'source'           => $source,
		'price'            => $price,
		'deposit_required' => $deposit_required,
		'deposit_paid'     => 0,
		'deposit_method'   => '',
		'deposit_deadline' => $deadline,
		'token'            => mjto_new_token(),
		'client_notes'     => (string) ($parsed['notes'] ?? ''),
		'internal_notes'   => ('panel' === $source) ? sanitize_textarea_field((string) ($input['internal_notes'] ?? '')) : '',
	];
	$data = (array) apply_filters('mjto_booking_insert_data', $data, $input, $source, $opts);
	$id   = mjto_insert_appointment($data);
	if ($locked) {
		mjto_release_lock($date);
	}
	if (!$id) {
		return mjto_booking_error('db', 500);
	}

	/* 7. Ficha automática si nace confirmado. */
	if ('confirmed' === $status) {
		mjto_ensure_visit($id);
	}
	$appointment = mjto_get_appointment($id);

	/* 8. Notificaciones. */
	if (!empty($opts['notify']) && $appointment) {
		if (function_exists('mjto_notify_owner_new')) {
			mjto_notify_owner_new($appointment);
		}
		if (function_exists('mjto_notify_client')) {
			$kind = ('confirmed' === $status) ? 'confirmed' : (('awaiting_deposit' === $status) ? 'deposit' : 'received');
			if (in_array($status, ['pending', 'awaiting_deposit', 'confirmed'], true)) {
				mjto_notify_client($appointment, $kind);
			}
		}
	}

	/* 9. Aviso a quien quiera engancharse. */
	do_action('mjto_booking_created', $appointment, $source);
	return $appointment;
}

/* -------------------------------------------------------------------------
 * Estados
 * ---------------------------------------------------------------------- */

/**
 * Cambia el estado de un turno.
 * $ctx: notify (bool), reason, by (client|owner|system), deposit_paid, deposit_method.
 * cancelled desde cualquiera (cancelled_at, cancel_reason, cancelled_by);
 * confirmed crea la ficha (visita) y guarda la seña si vino. Mails a la
 * clienta en confirmed/cancelled (si notify) y a la dueña cuando cancela la
 * clienta (o el sistema). → fila del turno o WP_Error.
 */
function mjto_set_status($id, $new, array $ctx = []) {
	$row = mjto_appointment_row($id);
	if (!$row || empty($row['id'])) {
		return mjto_booking_error('not_found', 404);
	}
	$new = sanitize_key((string) $new);
	if (!in_array($new, mjto_valid_statuses(), true)) {
		return mjto_booking_error('invalid_status', 400);
	}
	$ctx = wp_parse_args($ctx, [
		'notify'         => false,
		'reason'         => '',
		'by'             => 'owner',
		'deposit_paid'   => null,
		'deposit_method' => null,
	]);
	$id   = (int) $row['id'];
	$old  = (string) $row['status'];
	$by   = in_array((string) $ctx['by'], ['client', 'owner', 'system'], true) ? (string) $ctx['by'] : 'owner';
	$data = ['status' => $new];

	if (null !== $ctx['deposit_paid'] && '' !== $ctx['deposit_paid']) {
		$data['deposit_paid'] = max(0, (float) str_replace(',', '.', (string) $ctx['deposit_paid']));
	}
	if (null !== $ctx['deposit_method']) {
		$data['deposit_method'] = mb_substr(sanitize_text_field((string) $ctx['deposit_method']), 0, 60);
	}
	if ('cancelled' === $new) {
		$data['cancelled_at']  = mjto_now();
		$data['cancel_reason'] = mb_substr(sanitize_text_field((string) $ctx['reason']), 0, 255);
		$data['cancelled_by']  = $by;
	} elseif ('cancelled' === $old) {
		/* Se "descancela": limpiamos el rastro de la cancelación. */
		$data['cancelled_at']  = null;
		$data['cancel_reason'] = '';
		$data['cancelled_by']  = '';
	}
	if (!mjto_update_appointment($id, $data)) {
		return mjto_booking_error('db', 500);
	}

	if ('confirmed' === $new) {
		$visit = mjto_ensure_visit($id);
		if ($visit && array_key_exists('deposit_paid', $data)) {
			mjto_sync_visit_deposit($visit, $data['deposit_paid'], isset($data['deposit_method']) ? $data['deposit_method'] : (string) $row['deposit_method']);
		}
	}

	$fresh = mjto_get_appointment($id);
	if ($old !== $new && $fresh) {
		if (!empty($ctx['notify']) && function_exists('mjto_notify_client')) {
			if ('confirmed' === $new) {
				mjto_notify_client($fresh, 'confirmed');
			} elseif ('cancelled' === $new) {
				mjto_notify_client($fresh, 'cancelled');
			}
		}
		if ('cancelled' === $new && in_array($by, ['client', 'system'], true) && function_exists('mjto_notify_owner_cancel')) {
			mjto_notify_owner_cancel($fresh);
		}
		do_action('mjto_status_changed', $fresh, $old, $new, $ctx);
	}
	return $fresh;
}

/**
 * Reprograma un turno (panel). Recalcula fin y, si cambia el servicio,
 * nombre/precio/seña. Sin $force verifica disponibilidad (ignorando la
 * anticipación mínima, es la dueña) con lock. Reinicia el recordatorio.
 * Si hay ficha, mueve su fecha (y las sugeridas si no se habían tocado).
 * $notify: manda a la clienta el mail que corresponde a su estado.
 * → fila del turno o WP_Error.
 */
function mjto_reschedule($id, $date, $time, $duration_min = null, $service_id = null, $force = false, $notify = false) {
	$row = mjto_appointment_row($id);
	if (!$row || empty($row['id'])) {
		return mjto_booking_error('not_found', 404);
	}
	$id   = (int) $row['id'];
	$date = trim((string) $date);
	$time = mjto_normalize_time($time);
	if (!mjto_valid_date($date) || '' === $time) {
		return mjto_booking_error('invalid_date', 400);
	}

	$service = null;
	$data    = [];
	if (null !== $service_id && '' !== $service_id) {
		$service_id = (int) $service_id;
		if ($service_id > 0) {
			$service = mjto_get_service($service_id);
			if (!$service) {
				return mjto_booking_error('invalid_service', 400);
			}
			if ($service_id !== (int) $row['service_id']) {
				$data['service_id']       = $service_id;
				$data['service_name']     = mb_substr((string) $service['name'], 0, 160);
				$data['price']            = (float) $service['price'];
				$data['deposit_required'] = (float) mjto_service_deposit($service);
			}
		} else {
			$data['service_id'] = 0; // Pasa a turno libre; el nombre y el precio quedan como estaban.
		}
	}
	if (!$service && (int) $row['service_id'] > 0 && !isset($data['service_id'])) {
		$service = mjto_get_service((int) $row['service_id']);
	}

	$duration = (null !== $duration_min && '' !== $duration_min) ? (int) $duration_min : 0;
	if ($duration <= 0) {
		$duration = ($service && isset($data['service_id'])) ? (int) $service['duration'] : (int) $row['duration_min'];
	}
	if ($duration <= 0) {
		$duration = (int) mjto_opt('default_duration_min');
	}
	$duration = max(5, min(1440, $duration));

	$tz        = wp_timezone();
	$start     = new DateTimeImmutable($date . ' ' . $time . ':00', $tz);
	$starts_at = $start->format('Y-m-d H:i:s');
	$ends_at   = $start->modify('+' . $duration . ' minutes')->format('Y-m-d H:i:s');

	$spec = mjto_service_spec(['duration' => $duration, 'buffer' => $service ? $service['buffer'] : null]);

	/*
	 * Misma fecha y hora (solo cambia el servicio o la duración): si el fin no
	 * se alarga no hay nada que chequear, aunque el turno sea pasado, esté en
	 * curso o se haya cargado fuera de la grilla; si se alarga, alcanza con
	 * que la extensión (fin viejo → fin nuevo + descanso) no pise otro turno
	 * o bloqueo. Con la fecha u hora cambiadas se exige un hueco de la grilla.
	 */
	$same_start = ($starts_at === (string) $row['starts_at']);
	$old_ends   = (string) $row['ends_at'];
	$check      = !$force && !($same_start && $ends_at <= $old_ends);
	$locked     = false;
	if ($check) {
		if (!mjto_acquire_lock($date)) {
			return mjto_booking_error('busy', 503);
		}
		$locked = true;
		if ($same_start) {
			$until = $start->modify('+' . ($duration + (int) $spec['buffer']) . ' minutes')->format('Y-m-d H:i:s');
			$free  = mjto_interval_is_free($old_ends, $until, $id);
		} else {
			$free = mjto_slot_is_free($spec, $date, $time, $id, true);
		}
		if (!$free) {
			mjto_release_lock($date);
			return mjto_booking_error('slot_taken', 409);
		}
	}

	$old_starts               = (string) $row['starts_at'];
	$data['starts_at']        = $starts_at;
	$data['ends_at']          = $ends_at;
	$data['duration_min']     = $duration;
	$data['reminder_sent_at'] = null; // Fecha nueva, recordatorio nuevo.
	$ok = mjto_update_appointment($id, $data);
	if ($locked) {
		mjto_release_lock($date);
	}
	if (!$ok) {
		return mjto_booking_error('db', 500);
	}

	/* La ficha sigue al turno. */
	$visit = mjto_visit_of_appointment($id);
	if ($visit) {
		$old_date = substr($old_starts, 0, 10);
		$patch    = ['visited_at' => $date];
		if (isset($data['service_id'])) {
			$patch['service_id']   = (int) $data['service_id'];
			$patch['service_name'] = isset($data['service_name']) ? $data['service_name'] : (string) $visit['service_name'];
		}
		if ($old_date !== $date) {
			$old_suggest = mjto_suggest_dates($old_date);
			$new_suggest = mjto_suggest_dates($date);
			$vdata       = mjto_json_to_array($visit['data']);
			$roles       = mjto_ficha_fields_by_role();
			foreach (['control' => 'control_date', 'retouch' => 'retouch_date'] as $k => $col) {
				$current = (string) mjto_dt_or_null($visit[$col] ?? '');
				if ('' === $current || $current === (string) $old_suggest[$k]) {
					$patch[$col] = '' !== $new_suggest[$k] ? $new_suggest[$k] : null;
					if (isset($roles[$col])) {
						$vdata[$roles[$col]['key']] = (string) $new_suggest[$k];
					}
				}
			}
			$patch['data'] = $vdata;
		}
		mjto_update_visit($visit['id'], $patch);
	}

	$fresh = mjto_get_appointment($id);
	if ($notify && $fresh && function_exists('mjto_notify_client')) {
		$kinds = ['confirmed' => 'confirmed', 'awaiting_deposit' => 'deposit', 'pending' => 'received'];
		if (isset($kinds[$fresh['status']])) {
			mjto_notify_client($fresh, $kinds[$fresh['status']]);
		}
	}
	do_action('mjto_rescheduled', $fresh, $old_starts, $row);
	return $fresh;
}

/* -------------------------------------------------------------------------
 * Acciones de la clienta (por token)
 * ---------------------------------------------------------------------- */

/** ¿La clienta puede cancelar este turno desde la web ahora mismo? */
function mjto_booking_can_cancel($appointment) {
	$row = mjto_appointment_row($appointment);
	if (!$row || !(int) mjto_opt('client_can_cancel')) {
		return false;
	}
	if (!in_array((string) $row['status'], ['pending', 'awaiting_deposit', 'confirmed'], true)) {
		return false;
	}
	$starts = mjto_local_ts($row['starts_at']);
	if (null === $starts) {
		return false;
	}
	$hours = max(0, (float) mjto_opt('cancel_hours_before'));
	return ($starts - current_datetime()->getTimestamp()) >= (int) round($hours * 3600);
}

/**
 * Cancelación desde la web. Respeta client_can_cancel y cancel_hours_before;
 * solo pending / awaiting_deposit / confirmed. → fila del turno o WP_Error.
 */
function mjto_client_cancel($token) {
	$row = mjto_get_appointment_by_token($token);
	if (!$row) {
		return mjto_booking_error('not_found', 404);
	}
	if ('cancelled' === $row['status']) {
		return mjto_booking_error('already_cancelled', 400);
	}
	if (!mjto_booking_can_cancel($row)) {
		return mjto_booking_error('cannot_cancel', 400);
	}
	$reason = (string) apply_filters('mjto_client_cancel_reason', (string) mjto_opt('cancel_reason_client'), $row);
	return mjto_set_status($row['id'], 'cancelled', ['by' => 'client', 'reason' => $reason, 'notify' => true]);
}

/**
 * "Ya transferí la seña": marca deposit_notified_at, suma la nota a
 * client_notes y avisa a la dueña (la primera vez). → fila del turno o WP_Error.
 */
function mjto_client_deposit_notice($token, $note = '') {
	$row = mjto_get_appointment_by_token($token);
	if (!$row) {
		return mjto_booking_error('not_found', 404);
	}
	if (!in_array((string) $row['status'], ['awaiting_deposit', 'pending'], true)) {
		return mjto_booking_error('deposit_not_pending', 400);
	}
	$note  = mb_substr(trim(sanitize_textarea_field((string) $note)), 0, 500);
	$first = empty(mjto_dt_or_null($row['deposit_notified_at'] ?? ''));
	$data  = ['deposit_notified_at' => mjto_now()];
	if ('' !== $note) {
		$stamp  = wp_date('d/m H:i', current_datetime()->getTimestamp(), wp_timezone());
		$prefix = (string) apply_filters('mjto_deposit_note_prefix', 'Aviso de seña', $row);
		$line   = $prefix . ' (' . $stamp . '): ' . $note;
		$notes  = trim((string) ($row['client_notes'] ?? ''));
		$data['client_notes'] = ('' !== $notes ? $notes . "\n" : '') . $line;
	}
	if (!mjto_update_appointment($row['id'], $data)) {
		return mjto_booking_error('db', 500);
	}
	$fresh = mjto_get_appointment($row['id']);
	if ($first && $fresh && function_exists('mjto_notify_owner_deposit_notice')) {
		mjto_notify_owner_deposit_notice($fresh);
	}
	do_action('mjto_deposit_notified', $fresh, $note, $first);
	return $fresh;
}

/* -------------------------------------------------------------------------
 * Ficha técnica: campos, precarga, fechas sugeridas y advertencias
 * ---------------------------------------------------------------------- */

/** Campos de la ficha en una lista plana: [key => campo (+ 'block' = key del bloque)]. */
function mjto_ficha_fields() {
	$out = [];
	foreach (mjto_ficha_blocks() as $block) {
		foreach ((array) ($block['fields'] ?? []) as $field) {
			$field['block']     = $block['key'];
			$out[$field['key']] = $field;
		}
	}
	return $out;
}

/** Primer campo de la ficha por rol: [role => campo] (total, deposit, deposit_flag, payment_method, control_date, retouch_date, product). */
function mjto_ficha_fields_by_role() {
	$out = [];
	foreach (mjto_ficha_fields() as $field) {
		$role = (string) ($field['role'] ?? '');
		if ('' !== $role && !isset($out[$role])) {
			$out[$role] = $field;
		}
	}
	return $out;
}

/** Suma meses (respetando fin de mes) y días a una fecha 'YYYY-MM-DD' → 'YYYY-MM-DD'. */
function mjto_date_add($date, $days = 0, $months = 0) {
	$d      = new DateTimeImmutable((string) $date . ' 00:00:00', wp_timezone());
	$months = (int) $months;
	$days   = (int) $days;
	if (0 !== $months) {
		$day   = (int) $d->format('j');
		$first = $d->modify('first day of this month')->modify(($months > 0 ? '+' : '') . $months . ' months');
		$d     = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, (int) $first->format('t')));
	}
	if (0 !== $days) {
		$d = $d->modify(($days > 0 ? '+' : '') . $days . ' days');
	}
	return $d->format('Y-m-d');
}

/**
 * Fechas sugeridas de control y retoque a partir de la fecha de la visita:
 * ['control' => 'YYYY-MM-DD' | '', 'retouch' => …]. Usa los offsets de los
 * campos con rol control_date / retouch_date; si no hay campo con ese rol,
 * 3 días / 4 meses. Un campo con ambos offsets en 0 no sugiere nada ('').
 */
function mjto_suggest_dates($date) {
	$out = ['control' => '', 'retouch' => ''];
	if (!mjto_valid_date($date)) {
		return $out;
	}
	$roles    = mjto_ficha_fields_by_role();
	$defaults = ['control' => [3, 0], 'retouch' => [0, 4]];
	foreach (['control' => 'control_date', 'retouch' => 'retouch_date'] as $k => $role) {
		if (isset($roles[$role])) {
			$days   = (int) ($roles[$role]['offset_days'] ?? 0);
			$months = (int) ($roles[$role]['offset_months'] ?? 0);
		} else {
			[$days, $months] = $defaults[$k];
		}
		if ($days > 0 || $months > 0) {
			$out[$k] = mjto_date_add($date, $days, $months);
		}
	}
	return (array) apply_filters('mjto_suggest_dates', $out, $date);
}

/**
 * Advertencias según las opciones marcadas como warn en la ficha:
 * [{field, label, option, text}]. radio/select: valor igual; checkbox_group: lo incluye.
 */
function mjto_compute_warnings(array $data) {
	$out = [];
	foreach (mjto_ficha_fields() as $key => $field) {
		if (!in_array($field['type'], ['checkbox_group', 'radio', 'select'], true) || empty($field['options'])) {
			continue;
		}
		if (!array_key_exists($key, $data)) {
			continue;
		}
		$value  = $data[$key];
		$values = is_array($value) ? array_map('strval', $value) : [(string) $value];
		foreach ((array) $field['options'] as $option) {
			if (empty($option['warn'])) {
				continue;
			}
			if (!in_array((string) $option['value'], $values, true)) {
				continue;
			}
			$text = trim((string) ($option['warn_text'] ?? ''));
			if ('' === $text) {
				$text = sprintf((string) apply_filters('mjto_warning_fallback', 'Atención: %1$s: %2$s'), $field['label'], $option['label']);
			}
			$out[] = [
				'field'  => $key,
				'label'  => (string) $field['label'],
				'option' => (string) $option['label'],
				'text'   => $text,
			];
		}
	}
	return $out;
}

/**
 * Datos de precarga para una visita nueva: los campos 'carry' de la última
 * visita de la clienta. Respeta visit_prefill_carry salvo $force.
 */
function mjto_carry_data($client_id, $force = false) {
	if (!$force && !(int) mjto_opt('visit_prefill_carry')) {
		return [];
	}
	$last = mjto_last_visit_of_client($client_id);
	if (!$last) {
		return [];
	}
	$data = mjto_json_to_array($last['data']);
	$out  = [];
	foreach (mjto_ficha_fields() as $key => $field) {
		if (!empty($field['carry']) && array_key_exists($key, $data)) {
			$out[$key] = $data[$key];
		}
	}
	return $out;
}

/** Un valor de la ficha según el tipo del campo (ver mjto_normalize_visit_data). */
function mjto_normalize_visit_value($value, array $field) {
	$type = (string) $field['type'];
	switch ($type) {
		case 'checkbox_group':
			if (is_string($value)) {
				$value = ('' === trim($value)) ? [] : preg_split('/\s*,\s*/', trim($value));
			}
			$valid = array_map(fn ($o) => (string) $o['value'], (array) $field['options']);
			$out   = [];
			foreach ((array) $value as $v) {
				if (!is_scalar($v)) {
					continue;
				}
				/* Sin opciones propias (p. ej. método de pago que usa payment_methods): texto libre corto, como radio/select. */
				$v = $valid ? sanitize_key((string) $v) : mb_substr(sanitize_text_field((string) $v), 0, 60);
				if ('' !== $v && (!$valid || in_array($v, $valid, true)) && !in_array($v, $out, true)) {
					$out[] = $v;
				}
			}
			return $out;
		case 'radio':
		case 'select':
			if (is_array($value)) {
				$value = reset($value);
			}
			$value = trim((string) $value);
			if (!empty($field['options'])) {
				$valid = array_map(fn ($o) => (string) $o['value'], (array) $field['options']);
				return in_array($value, $valid, true) ? $value : '';
			}
			/* Sin opciones (p. ej. método de pago que usa payment_methods): texto libre corto. */
			return mb_substr(sanitize_text_field($value), 0, 60);
		case 'money':
		case 'number':
			if (is_array($value) || null === $value || '' === trim((string) $value)) {
				return '';
			}
			$value = str_replace(',', '.', (string) $value);
			return is_numeric($value) ? round((float) $value, 'money' === $type ? 2 : 4) : '';
		case 'date':
			$value = is_array($value) ? '' : trim((string) $value);
			return mjto_valid_date($value) ? $value : '';
		case 'toggle':
			if (is_string($value)) {
				$value = in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes', 'si', 'sí'], true);
			}
			return (bool) $value;
		case 'textarea':
			return is_array($value) ? '' : sanitize_textarea_field((string) $value);
		default:
			return is_array($value) ? '' : sanitize_text_field((string) $value);
	}
}

/**
 * Normaliza los datos de una visita según los campos de la ficha:
 * checkbox_group → array de values válidos; radio/select → value válido o '';
 * money/number → float ('' si vacío); date → 'YYYY-MM-DD' o ''; toggle → bool;
 * text/textarea → sanitizado. Las claves que no están en la ficha se
 * conservan como texto (por si la ficha cambió después de cargar la visita).
 */
function mjto_normalize_visit_data(array $data) {
	$fields = mjto_ficha_fields();
	$out    = [];
	foreach ($data as $key => $value) {
		$key = sanitize_key((string) $key);
		if ('' === $key) {
			continue;
		}
		if (isset($fields[$key])) {
			$out[$key] = mjto_normalize_visit_value($value, $fields[$key]);
		} elseif (is_array($value)) {
			$out[$key] = array_values(array_map(fn ($v) => sanitize_text_field((string) $v), array_filter($value, 'is_scalar')));
		} elseif (is_bool($value)) {
			$out[$key] = $value;
		} else {
			$out[$key] = sanitize_textarea_field((string) $value);
		}
	}
	return $out;
}

/**
 * Sincroniza los campos con rol → columnas de la visita. Si el campo con rol
 * está en $data manda su valor; si no, el valor explícito de $input (y se
 * refleja en $data si el campo existe). Con $prefer_input, la columna
 * explícita de $input gana sobre $data (para editar solo columnas sin mandar
 * la ficha). deposit_flag en false → seña 0. → [$data, $columns].
 */
function mjto_apply_visit_roles(array $data, array $input = [], $prefer_input = false) {
	$roles      = mjto_ficha_fields_by_role();
	$cols       = [];
	$from_input = [];
	$map        = [
		'total'          => ['total', 'money'],
		'deposit'        => ['deposit', 'money'],
		'payment_method' => ['payment_method', 'text'],
		'control_date'   => ['control_date', 'date'],
		'retouch_date'   => ['retouch_date', 'date'],
	];
	foreach ($map as $role => [$col, $kind]) {
		$field = isset($roles[$role]) ? $roles[$role] : null;
		$value = null;
		$has   = false;
		if ($prefer_input && array_key_exists($col, $input)) {
			$value             = $input[$col];
			$has               = true;
			$from_input[$col] = true;
		} elseif ($field && array_key_exists($field['key'], $data)) {
			$value = $data[$field['key']];
			$has   = true;
		} elseif (array_key_exists($col, $input)) {
			$value             = $input[$col];
			$has               = true;
			$from_input[$col] = true;
		}
		if (!$has) {
			continue;
		}
		switch ($kind) {
			case 'money':
				$clean = ('' === $value || null === $value || is_array($value)) ? 0.0 : (float) str_replace(',', '.', (string) $value);
				$clean = round(max(0.0, $clean), 2); // Siempre float: max(0, 0.0) devolvería el int 0.
				break;
			case 'date':
				$clean = (is_string($value) && mjto_valid_date($value)) ? $value : '';
				break;
			default:
				/* Un campo de casillas (varios métodos de pago) se guarda en la columna separado por coma. */
				$text  = is_array($value) ? implode(', ', array_map('strval', array_filter($value, 'is_scalar'))) : (string) $value;
				$clean = mb_substr(sanitize_text_field($text), 0, 60);
		}
		$cols[$col] = ('date' === $kind && '' === $clean) ? null : $clean;
		if ($field && (!empty($from_input[$col]) || !array_key_exists($field['key'], $data))) {
			if ('money' === $kind) {
				$data[$field['key']] = ($clean <= 0) ? '' : $clean;
			} elseif ('checkbox_group' === $field['type']) {
				$data[$field['key']] = '' === $clean ? [] : array_values(array_filter(array_map('trim', explode(',', $clean)), 'strlen'));
			} else {
				$data[$field['key']] = $clean;
			}
		}
	}
	if (isset($roles['deposit_flag'])) {
		$flag_key = $roles['deposit_flag']['key'];
		if (!empty($from_input['deposit']) && isset($cols['deposit']) && $cols['deposit'] > 0) {
			$data[$flag_key] = true; // Se cargó una seña explícita: el toggle acompaña.
		}
		if (array_key_exists($flag_key, $data)) {
			if (!$data[$flag_key]) {
				$cols['deposit'] = 0.0;
				if (isset($roles['deposit'])) {
					$data[$roles['deposit']['key']] = '';
				}
			}
		} elseif (isset($cols['deposit']) && $cols['deposit'] > 0) {
			$data[$flag_key] = true;
		}
	}
	return [$data, $cols];
}

/** ¿Un valor de la ficha está vacío? (arrays: sin ningún elemento con texto). */
function mjto_visit_value_empty($value) {
	if (is_array($value)) {
		return !array_filter($value, fn ($v) => is_scalar($v) && '' !== trim((string) $v));
	}
	return null === $value || '' === trim((string) $value);
}

/** Escribe el método de pago en el campo con ese rol respetando su tipo (las casillas guardan una lista). */
function mjto_visit_payment_value(array $field, $method) {
	$method = mb_substr(sanitize_text_field((string) $method), 0, 60);
	return ('checkbox_group' === (string) ($field['type'] ?? '')) ? [$method] : $method;
}

/**
 * Ajusta la seña de una visita cuando se cobra desde el turno. Por defecto
 * solo completa (seña > 0 y la ficha sin seña cargada); con $overwrite (edición
 * explícita de la seña desde el panel) pisa el monto, el toggle y el método.
 */
function mjto_sync_visit_deposit($visit, $deposit_paid, $deposit_method = '', $overwrite = false) {
	$visit = is_array($visit) ? $visit : mjto_get_visit($visit);
	if (!$visit) {
		return;
	}
	$amount = round(max(0.0, (float) str_replace(',', '.', (string) $deposit_paid)), 2);
	if (!$overwrite && ($amount <= 0 || (float) $visit['deposit'] > 0)) {
		return;
	}
	$method = mb_substr(sanitize_text_field((string) $deposit_method), 0, 60);
	$data   = mjto_json_to_array($visit['data']);
	$roles  = mjto_ficha_fields_by_role();
	if (isset($roles['deposit'])) {
		$data[$roles['deposit']['key']] = $amount > 0 ? $amount : '';
	}
	if (isset($roles['deposit_flag'])) {
		$data[$roles['deposit_flag']['key']] = ($amount > 0);
	}
	if (isset($roles['payment_method']) && '' !== $method && ($overwrite || mjto_visit_value_empty($data[$roles['payment_method']['key']] ?? ''))) {
		$data[$roles['payment_method']['key']] = mjto_visit_payment_value($roles['payment_method'], $method);
	}
	$patch = ['data' => $data, 'deposit' => $amount];
	if ('' !== $method && ($overwrite || '' === (string) $visit['payment_method'])) {
		$patch['payment_method'] = $method;
	}
	mjto_update_visit($visit['id'], $patch);
}

/**
 * Arma la fila de una visita nueva (sin insertar): precarga (carry), seña
 * del turno, fechas sugeridas en los campos con rol, columnas y advertencias.
 */
function mjto_new_visit_row($client_id, $appointment, $visited_at, $service_id, $service_name, array $data = [], $carry = true) {
	$base = $carry ? mjto_carry_data($client_id, 'force' === $carry) : [];
	$data = mjto_normalize_visit_data(array_merge($base, $data));
	$roles = mjto_ficha_fields_by_role();

	if ($appointment && (float) ($appointment['deposit_paid'] ?? 0) > 0) {
		if (isset($roles['deposit']) && '' === (string) ($data[$roles['deposit']['key']] ?? '')) {
			$data[$roles['deposit']['key']] = round((float) $appointment['deposit_paid'], 2);
		}
		if (isset($roles['deposit_flag']) && !array_key_exists($roles['deposit_flag']['key'], $data)) {
			$data[$roles['deposit_flag']['key']] = true;
		}
		if (isset($roles['payment_method']) && '' !== (string) ($appointment['deposit_method'] ?? '') && mjto_visit_value_empty($data[$roles['payment_method']['key']] ?? '')) {
			$data[$roles['payment_method']['key']] = mjto_visit_payment_value($roles['payment_method'], $appointment['deposit_method']);
		}
	}
	$suggest = mjto_suggest_dates($visited_at);
	foreach (['control' => 'control_date', 'retouch' => 'retouch_date'] as $k => $role) {
		if (isset($roles[$role]) && '' === (string) ($data[$roles[$role]['key']] ?? '')) {
			$data[$roles[$role]['key']] = $suggest[$k];
		}
	}

	$input = [
		'deposit'        => $appointment ? (float) ($appointment['deposit_paid'] ?? 0) : 0,
		'payment_method' => $appointment ? (string) ($appointment['deposit_method'] ?? '') : '',
		'control_date'   => $suggest['control'],
		'retouch_date'   => $suggest['retouch'],
	];
	[$data, $cols] = mjto_apply_visit_roles($data, $input);

	return array_merge([
		'client_id'      => (int) $client_id,
		'appointment_id' => $appointment ? (int) $appointment['id'] : null,
		'visited_at'     => $visited_at,
		'service_id'     => (int) $service_id,
		'service_name'   => mb_substr((string) $service_name, 0, 160),
		'data'           => $data,
		'warnings'       => mjto_compute_warnings($data),
		'total'          => 0,
		'deposit'        => 0,
		'payment_method' => '',
		'control_date'   => null,
		'retouch_date'   => null,
	], $cols);
}

/**
 * Devuelve la visita (ficha) de un turno o la crea: fecha del turno, servicio
 * snapshot, precarga desde la última visita, seña cobrada, fechas sugeridas.
 * → fila de la visita o null.
 */
function mjto_ensure_visit($appointment) {
	$row = mjto_appointment_row($appointment);
	if (!$row || empty($row['id'])) {
		return null;
	}
	$existing = mjto_visit_of_appointment($row['id']);
	if ($existing) {
		return $existing;
	}
	$visited_at = substr((string) $row['starts_at'], 0, 10);
	if (!mjto_valid_date($visited_at)) {
		$visited_at = mjto_today();
	}
	$insert = mjto_new_visit_row($row['client_id'], $row, $visited_at, (int) $row['service_id'], (string) $row['service_name'], [], true);
	$id     = mjto_insert_visit($insert);
	if (!$id) {
		return null;
	}
	$visit = mjto_get_visit($id);
	do_action('mjto_visit_created', $visit, $row);
	return $visit;
}

/**
 * Guarda una visita: normaliza data según la ficha, recalcula advertencias,
 * sincroniza roles → columnas y actualiza fecha/servicio si vienen.
 * $input: visited_at, service_id, service_name, appointment_id, data,
 * total, deposit, payment_method, control_date, retouch_date.
 * → fila de la visita o WP_Error.
 */
function mjto_save_visit($visit_id, array $input) {
	$visit = mjto_get_visit($visit_id);
	if (!$visit) {
		return mjto_booking_error('visit_not_found', 404);
	}
	$existing = mjto_json_to_array($visit['data']);
	/* Un data vacío ({}) se toma como "no vino": así "Abrir ficha" no borra la precarga. */
	$has_data = array_key_exists('data', $input) && is_array($input['data']) && $input['data'];
	if (!$has_data) {
		unset($input['data']);
	}
	$known = mjto_ficha_fields();
	$data  = mjto_normalize_visit_data($has_data ? $input['data'] : $existing);
	/* Claves viejas que el editor ya no manda (campos borrados de la ficha) se conservan. */
	foreach ($existing as $key => $value) {
		if (!isset($known[$key]) && !array_key_exists($key, $data)) {
			$data[$key] = $value;
		}
	}
	/* Si no vino la ficha, las columnas explícitas (total, seña, método, fechas) mandan y se reflejan en data. */
	[$data, $cols] = mjto_apply_visit_roles($data, $input, !array_key_exists('data', $input));

	$patch = array_merge($cols, [
		'data'     => $data,
		'warnings' => mjto_compute_warnings($data),
	]);
	if (isset($input['visited_at']) && mjto_valid_date($input['visited_at'])) {
		$patch['visited_at'] = (string) $input['visited_at'];
	}
	$service = null;
	if (array_key_exists('service_id', $input) && '' !== $input['service_id'] && null !== $input['service_id']) {
		$service_id = (int) $input['service_id'];
		$service    = $service_id > 0 ? mjto_get_service($service_id) : null;
		$patch['service_id']   = $service ? (int) $service['id'] : 0;
		$patch['service_name'] = $service ? (string) $service['name'] : (string) $visit['service_name'];
	}
	if (array_key_exists('service_name', $input) && !is_array($input['service_name'])) {
		$name = mb_substr(sanitize_text_field((string) $input['service_name']), 0, 160);
		/* Vacío también se guarda ("Otro / sin servicio" sin nombre), salvo que en este mismo guardado venga un servicio real. */
		if ('' !== $name || !$service) {
			$patch['service_name'] = $name;
		}
	}
	if (array_key_exists('appointment_id', $input)) {
		$aid = (int) $input['appointment_id'];
		if ($aid > 0) {
			$appointment = mjto_get_appointment($aid);
			if ($appointment && (int) $appointment['client_id'] === (int) $visit['client_id']) {
				$patch['appointment_id'] = $aid;
			}
		} else {
			$patch['appointment_id'] = null;
		}
	}
	if (!mjto_update_visit($visit['id'], $patch)) {
		return mjto_booking_error('db', 500);
	}
	$fresh = mjto_get_visit($visit['id']);
	do_action('mjto_visit_saved', $fresh, $input);
	return $fresh;
}

/**
 * Visita nueva para una clienta (sin turno, o "Abrir ficha"/"Nueva visita").
 * $input: appointment_id (si ya tiene visita, se devuelve esa), visited_at
 * (default: fecha del turno u hoy), service_id / service_name, data, carry
 * (true/ausente: precargar desde la última visita si visit_prefill_carry lo
 * permite; false: no precargar; 'force': precargar igual), y las columnas de
 * mjto_save_visit. → fila de la visita o WP_Error.
 */
function mjto_create_visit($client_id, array $input) {
	$client = mjto_get_client($client_id);
	if (!$client) {
		return mjto_booking_error('client_not_found', 404);
	}
	$appointment = null;
	if (!empty($input['appointment_id'])) {
		$appointment = mjto_get_appointment((int) $input['appointment_id']);
		if (!$appointment) {
			return mjto_booking_error('not_found', 404);
		}
		if ((int) $appointment['client_id'] !== (int) $client['id']) {
			return mjto_booking_error('client_mismatch', 400);
		}
		$existing = mjto_visit_of_appointment($appointment['id']);
		if ($existing) {
			return $existing;
		}
	}
	$visited_at = (isset($input['visited_at']) && mjto_valid_date($input['visited_at'])) ? (string) $input['visited_at'] : '';
	if ('' === $visited_at) {
		$visited_at = $appointment ? substr((string) $appointment['starts_at'], 0, 10) : mjto_today();
	}
	$service_id   = $appointment ? (int) $appointment['service_id'] : 0;
	$service_name = $appointment ? (string) $appointment['service_name'] : '';
	if (array_key_exists('service_id', $input) && '' !== $input['service_id'] && null !== $input['service_id']) {
		$service      = (int) $input['service_id'] > 0 ? mjto_get_service((int) $input['service_id']) : null;
		$service_id   = $service ? (int) $service['id'] : 0;
		$service_name = $service ? (string) $service['name'] : $service_name;
	}
	if (isset($input['service_name']) && '' !== trim((string) $input['service_name'])) {
		$service_name = sanitize_text_field((string) $input['service_name']);
	}
	$carry = true;
	if (array_key_exists('carry', $input)) {
		$carry = ('force' === $input['carry']) ? 'force' : !empty($input['carry']);
	}
	$data = (isset($input['data']) && is_array($input['data'])) ? $input['data'] : [];

	$insert = mjto_new_visit_row($client['id'], $appointment, $visited_at, $service_id, $service_name, $data, $carry);
	$id     = mjto_insert_visit($insert);
	if (!$id) {
		return mjto_booking_error('db', 500);
	}
	/* Columnas explícitas (total, seña, método, fechas) por encima de lo calculado. */
	$explicit = array_intersect_key($input, array_flip(['total', 'deposit', 'payment_method', 'control_date', 'retouch_date']));
	if ($explicit) {
		$saved = mjto_save_visit($id, $explicit);
		if (!is_wp_error($saved)) {
			do_action('mjto_visit_created', $saved, $appointment);
			return $saved;
		}
	}
	$visit = mjto_get_visit($id);
	do_action('mjto_visit_created', $visit, $appointment);
	return $visit;
}
