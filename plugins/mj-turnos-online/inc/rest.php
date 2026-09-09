<?php
/**
 * REST del plugin: wp-json/mjto/v1
 *
 * Dos grupos de rutas:
 *  - Públicas (sin sesión): config, disponibilidad, crear reserva, estado de
 *    una reserva por token, cancelar y avisar la seña. Con límite por IP para
 *    crear reservas (rate_limit cada 10 minutos) y honeypot en el motor.
 *  - Panel (capability mjto_manage + cookie auth + X-WP-Nonce): bootstrap,
 *    turnos, clientas, visitas (ficha técnica), caja, bloqueos y horarios.
 *
 * Todas las entradas se sanitizan acá y las fechas se validan con regex +
 * checkdate (mjto_valid_date). El motor (inc/availability.php,
 * inc/bookings.php) hace la lógica de agenda y estados; acá solo se traducen
 * requests ↔ shapes públicos (mjto_*_public de inc/db.php). Los errores son
 * WP_Error con status HTTP.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Registro de rutas
 * ---------------------------------------------------------------------- */
add_action('rest_api_init', function () {
	$ns    = 'mjto/v1';
	$token = '(?P<token>[A-Za-z0-9]{8,64})';
	$id    = '(?P<id>\d+)';

	/* ---- Público ---- */
	register_rest_route($ns, '/config', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'mjto_rest_config',
		'permission_callback' => '__return_true',
	]);
	register_rest_route($ns, '/availability', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'mjto_rest_availability',
		'permission_callback' => '__return_true',
	]);
	register_rest_route($ns, '/bookings', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'mjto_rest_create_booking',
		'permission_callback' => '__return_true',
	]);
	register_rest_route($ns, '/bookings/' . $token, [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'mjto_rest_get_booking',
		'permission_callback' => '__return_true',
	]);
	register_rest_route($ns, '/bookings/' . $token . '/cancel', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'mjto_rest_cancel_booking',
		'permission_callback' => '__return_true',
	]);
	register_rest_route($ns, '/bookings/' . $token . '/deposit-notice', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'mjto_rest_deposit_notice',
		'permission_callback' => '__return_true',
	]);

	/* ---- Panel ---- */
	$panel = 'mjto_rest_can_manage';

	register_rest_route($ns, '/panel/bootstrap', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'mjto_rest_bootstrap',
		'permission_callback' => $panel,
	]);

	register_rest_route($ns, '/panel/appointments', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'mjto_rest_appointments',
			'permission_callback' => $panel,
		],
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'mjto_rest_create_appointment',
			'permission_callback' => $panel,
		],
	]);
	register_rest_route($ns, '/panel/appointments/' . $id, [
		[
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'mjto_rest_update_appointment',
			'permission_callback' => $panel,
		],
		[
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'mjto_rest_delete_appointment',
			'permission_callback' => $panel,
		],
	]);

	register_rest_route($ns, '/panel/clients', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'mjto_rest_clients',
			'permission_callback' => $panel,
		],
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'mjto_rest_create_client',
			'permission_callback' => $panel,
		],
	]);
	register_rest_route($ns, '/panel/clients/' . $id, [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'mjto_rest_get_client',
			'permission_callback' => $panel,
		],
		[
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'mjto_rest_update_client',
			'permission_callback' => $panel,
		],
		[
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'mjto_rest_delete_client',
			'permission_callback' => $panel,
		],
	]);
	register_rest_route($ns, '/panel/clients/' . $id . '/visits', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'mjto_rest_create_visit',
		'permission_callback' => $panel,
	]);

	register_rest_route($ns, '/panel/visits/' . $id, [
		[
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'mjto_rest_update_visit',
			'permission_callback' => $panel,
		],
		[
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'mjto_rest_delete_visit',
			'permission_callback' => $panel,
		],
	]);

	register_rest_route($ns, '/panel/cash', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'mjto_rest_cash',
		'permission_callback' => $panel,
	]);

	register_rest_route($ns, '/panel/blocks', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'mjto_rest_blocks',
			'permission_callback' => $panel,
		],
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'mjto_rest_create_block',
			'permission_callback' => $panel,
		],
	]);
	register_rest_route($ns, '/panel/blocks/' . $id, [
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'mjto_rest_delete_block',
		'permission_callback' => $panel,
	]);

	register_rest_route($ns, '/panel/availability', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'mjto_rest_panel_availability',
		'permission_callback' => $panel,
	]);
});

/* -------------------------------------------------------------------------
 * Permisos y helpers de request
 * ---------------------------------------------------------------------- */

/** Permission callback del panel: sesión + capability mjto_manage (cookie auth + X-WP-Nonce). */
function mjto_rest_can_manage() {
	if (!is_user_logged_in()) {
		return new WP_Error('mjto_not_logged_in', 'Tenés que iniciar sesión para usar el panel.', ['status' => 401]);
	}
	if (!current_user_can(MJTO_CAP)) {
		return new WP_Error('mjto_forbidden', 'No tenés permiso para usar el panel de turnos.', ['status' => 403]);
	}
	return true;
}

/** WP_Error con status HTTP. */
function mjto_rest_error($code, $message, $status = 400, array $data = []) {
	return new WP_Error($code, $message, array_merge(['status' => (int) $status], $data));
}

/** Garantiza que un WP_Error que viene del motor tenga status (default 400). */
function mjto_rest_ensure_status($error, $default = 400) {
	if (!is_wp_error($error)) {
		return $error;
	}
	$data = $error->get_error_data();
	if (!is_array($data) || empty($data['status'])) {
		$error->add_data(array_merge(is_array($data) ? $data : [], ['status' => (int) $default]));
	}
	return $error;
}

/** Fecha 'YYYY-MM-DD' válida o ''. */
function mjto_rest_date($value) {
	$value = trim((string) $value);
	return mjto_valid_date($value) ? $value : '';
}

/** Hora 'HH:MM' válida (normalizada a dos dígitos) o ''. */
function mjto_rest_time($value) {
	$value = trim((string) $value);
	if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
		return '';
	}
	$h = (int) $m[1];
	$i = (int) $m[2];
	if ($h > 23 || $i > 59) {
		return '';
	}
	return sprintf('%02d:%02d', $h, $i);
}

/** Booleano desde lo que mande el cliente (true/1/'1'/'true'/'on'/'yes'). */
function mjto_rest_bool($value, $default = false) {
	if (null === $value || '' === $value) {
		return (bool) $default;
	}
	if (is_bool($value)) {
		return $value;
	}
	return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes', 'si', 'sí'], true);
}

/** ¿El parámetro vino en el request (aunque sea vacío)? */
function mjto_rest_has(WP_REST_Request $request, $key) {
	return array_key_exists($key, (array) $request->get_params());
}

/** Monto decimal (acepta coma) → float redondeado a 2, o null si no vino. */
function mjto_rest_money($value) {
	if (null === $value || '' === $value) {
		return null;
	}
	$value = str_replace(',', '.', (string) $value);
	return is_numeric($value) ? round((float) $value, 2) : null;
}

/** Lista de estados válidos desde array o CSV ('pending,confirmed'). */
function mjto_rest_statuses($value) {
	if (is_string($value)) {
		$value = explode(',', $value);
	}
	if (!is_array($value)) {
		return [];
	}
	return array_values(array_intersect(array_map('sanitize_key', array_map('strval', $value)), mjto_valid_statuses()));
}

/** Campos extra del formulario: {key: valor} sanitizado (máximo 50 claves). */
function mjto_rest_sanitize_extra($extra) {
	$out = [];
	if (is_string($extra) && '' !== trim($extra)) {
		$extra = json_decode($extra, true); // Por si viene form-encoded con el objeto serializado.
	}
	if (!is_array($extra)) {
		return $out;
	}
	foreach ($extra as $key => $value) {
		$key = sanitize_key((string) $key);
		if ('' === $key || count($out) >= 50) {
			continue;
		}
		if (is_array($value)) {
			$out[$key] = array_values(array_map(fn ($v) => sanitize_text_field((string) (is_scalar($v) ? $v : '')), $value));
		} elseif (is_scalar($value) || null === $value) {
			$out[$key] = sanitize_textarea_field((string) $value);
		}
	}
	return $out;
}

/** Paginación: [page, per_page] con tope. */
function mjto_rest_paging(WP_REST_Request $request, $default_per_page = 50, $max = 200) {
	$page     = max(1, (int) $request->get_param('page'));
	$per_page = (int) $request->get_param('per_page');
	if ($per_page <= 0) {
		$per_page = $default_per_page;
	}
	return [$page, max(1, min($max, $per_page))];
}

/** Filas → shapes públicos (sin nulos). */
function mjto_rest_items(array $rows, callable $map) {
	return array_values(array_filter(array_map($map, $rows)));
}

/**
 * IP del visitante. Por defecto solo REMOTE_ADDR: los headers de proxy se
 * pueden falsificar. Detrás de Cloudflare o de un balanceador de confianza,
 * el sitio puede devolver la IP real con el filtro mjto_client_ip (ver LEEME);
 * si lo que vuelve no es una IP válida, el límite cae en un cubo común.
 */
function mjto_rest_ip() {
	$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
	$ip = (string) apply_filters('mjto_client_ip', $ip);
	return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/** Clave del contador de reservas por IP. */
function mjto_rest_rate_key() {
	return 'mjto_rl_' . md5(mjto_rest_ip());
}

/**
 * ¿Esta IP ya llegó al límite? rate_limit reservas cada 10 minutos. Solo mira
 * el contador: los intentos fallidos (horario ocupado, campos incompletos) no
 * gastan cupo, se cuentan las reservas creadas con mjto_rest_rate_count().
 */
function mjto_rest_rate_limited() {
	$limit = max(1, (int) mjto_opt('rate_limit'));
	return (int) get_transient(mjto_rest_rate_key()) >= $limit;
}

/** Suma una reserva creada al contador de la IP (ventana de 10 minutos). */
function mjto_rest_rate_count() {
	$key = mjto_rest_rate_key();
	set_transient($key, ((int) get_transient($key)) + 1, 10 * MINUTE_IN_SECONDS);
}

/* -------------------------------------------------------------------------
 * Shapes para la vista pública de una reserva
 * ---------------------------------------------------------------------- */

/** ¿La clienta puede cancelar este turno desde la web? (ajustes + estado + anticipación). */
function mjto_rest_can_cancel(array $row) {
	if (!(int) mjto_opt('client_can_cancel')) {
		return false;
	}
	if (!in_array((string) ($row['status'] ?? ''), ['pending', 'awaiting_deposit', 'confirmed'], true)) {
		return false;
	}
	$ts = mjto_local_ts($row['starts_at'] ?? '');
	if (null === $ts) {
		return false;
	}
	$hours = max(0, (int) mjto_opt('cancel_hours_before'));
	return ($ts - current_datetime()->getTimestamp()) >= $hours * HOUR_IN_SECONDS;
}

/** Fecha y hora larga en español: "sábado 12 de septiembre, 14:00". */
function mjto_rest_long_label($datetime) {
	$ts = mjto_local_ts($datetime);
	if (null === $ts) {
		return '';
	}
	return (string) wp_date('l j \d\e F, H:i', $ts, wp_timezone());
}

/** Mensaje de resultado según el estado (textos de Ajustes → Textos). */
function mjto_rest_status_message($status) {
	switch ((string) $status) {
		case 'awaiting_deposit':
			return (string) mjto_opt('booking_success_deposit');
		case 'confirmed':
		case 'completed':
			return (string) mjto_opt('booking_success_auto');
		case 'pending':
			return (string) mjto_opt('booking_success_pending');
		case 'cancelled':
		case 'no_show':
			return (string) mjto_opt('booking_cancelled_text');
	}
	return '';
}

/**
 * Vista pública de un turno (lo que ve la clienta con su token): sin datos
 * internos, con el bloque de seña y el link para mandar el comprobante.
 */
function mjto_rest_booking_view(array $row) {
	$public   = mjto_appointment_public($row, false);
	$status   = (string) $public['status'];
	$amount   = (float) $public['depositRequired'];
	$required = ('awaiting_deposit' === $status && $amount > 0);
	$deadline = $public['depositDeadline'];
	$business = mjto_business_whatsapp();
	$wa_url   = '';
	if ('' !== $business) {
		if (function_exists('mjto_client_whatsapp_url')) {
			$wa_url = (string) mjto_client_whatsapp_url($row);
		} else {
			$wa_url = 'https://wa.me/' . $business;
		}
	}

	return [
		'token'           => $public['token'],
		'status'          => $status,
		'statusLabel'     => $public['statusLabel'],
		'message'         => mjto_rest_status_message($status),
		'appointment'     => [
			'id'          => $public['id'],
			'serviceName' => $public['serviceName'],
			'date'        => $public['date'],
			'time'        => $public['time'],
			'dateLabel'   => $public['dateLabel'],
			'endTime'     => $public['endTime'],
			'durationMin' => $public['durationMin'],
			'price'       => $public['price'],
		],
		'deposit'         => [
			'required'      => $required,
			'amount'        => $amount,
			'paid'          => (float) $public['depositPaid'],
			'instructions'  => (string) mjto_opt('deposit_instructions'),
			'link'          => (string) mjto_opt('deposit_link'),
			'deadline'      => $deadline,
			'deadlineLabel' => $deadline ? mjto_rest_long_label($deadline) : '',
		],
		'whatsapp'        => [
			'number' => $business,
			'url'    => $wa_url,
		],
		'canCancel'       => mjto_rest_can_cancel($row),
		'depositNotified' => !empty($public['depositNotifiedAt']),
		'cancelReason'    => 'cancelled' === $status ? $public['cancelReason'] : '',
	];
}

/* -------------------------------------------------------------------------
 * Público
 * ---------------------------------------------------------------------- */

/* GET /config → mismo shape que window.MJ.turnos */
function mjto_rest_config(WP_REST_Request $request) {
	return rest_ensure_response(mjto_public_config());
}

/* GET /availability?service_id=&from=&to= → {days: {date: [slots]}, timezone, now} */
function mjto_rest_availability(WP_REST_Request $request) {
	$service = mjto_get_service((int) $request->get_param('service_id'));
	if (!$service || !$service['active'] || !$service['bookable']) {
		return mjto_booking_error('invalid_service_public', 400);
	}
	$today = mjto_today();
	$from  = mjto_rest_date($request->get_param('from'));
	$to    = mjto_rest_date($request->get_param('to'));
	if ('' === $from) {
		$from = $today;
	}
	if ('' === $to) {
		$to = (new DateTimeImmutable($from, wp_timezone()))->modify('+' . max(1, (int) mjto_opt('max_days_ahead')) . ' days')->format('Y-m-d');
	}
	if ($to < $from) {
		return mjto_booking_error('invalid_range', 400);
	}
	return rest_ensure_response(mjto_availability($service, $from, $to));
}

/* POST /bookings → 201 vista pública de la reserva */
function mjto_rest_create_booking(WP_REST_Request $request) {
	if (mjto_rest_rate_limited()) {
		return mjto_booking_error('rate_limited', 429);
	}

	$input = [
		'service_id' => (int) $request->get_param('service_id'),
		'date'       => mjto_rest_date($request->get_param('date')),
		'time'       => mjto_rest_time($request->get_param('time')),
		'first_name' => sanitize_text_field((string) $request->get_param('first_name')),
		'last_name'  => sanitize_text_field((string) $request->get_param('last_name')),
		'phone'      => sanitize_text_field((string) $request->get_param('phone')),
		'email'      => sanitize_text_field((string) $request->get_param('email')), // Se valida con is_email() y recién ahí se sanitiza (mjto_validate_public_form).
		'locality'   => sanitize_text_field((string) $request->get_param('locality')),
		'notes'      => sanitize_textarea_field((string) $request->get_param('notes')),
		'extra'      => mjto_rest_sanitize_extra($request->get_param('extra')),
		'website'    => sanitize_text_field((string) $request->get_param('website')), // Honeypot: tiene que venir vacío.
	];

	/* Campos custom del formulario mandados sueltos (no dentro de extra). */
	foreach (mjto_form_fields() as $field) {
		if (!empty($field['system']) || empty($field['enabled'])) {
			continue;
		}
		$key = $field['key'];
		if (!isset($input['extra'][$key]) && mjto_rest_has($request, $key)) {
			$value = $request->get_param($key);
			if (is_array($value)) {
				$input['extra'][$key] = array_values(array_map(fn ($v) => sanitize_text_field((string) (is_scalar($v) ? $v : '')), $value));
			} elseif (is_scalar($value) || null === $value) {
				$input['extra'][$key] = sanitize_textarea_field((string) $value);
			}
		}
	}

	if ('' === $input['date'] || '' === $input['time']) {
		return mjto_booking_error('invalid_date', 400);
	}

	$appointment = mjto_create_booking($input, 'web');
	if (is_wp_error($appointment)) {
		return mjto_rest_ensure_status($appointment, 400);
	}
	if (!is_array($appointment)) {
		return mjto_booking_error('booking_failed', 500);
	}

	mjto_rest_rate_count(); // Recién acá: el cupo lo gastan las reservas creadas, no los intentos.
	$response = rest_ensure_response(mjto_rest_booking_view($appointment));
	$response->set_status(201);
	return $response;
}

/* GET /bookings/{token} → vista pública */
function mjto_rest_get_booking(WP_REST_Request $request) {
	$row = mjto_get_appointment_by_token((string) $request->get_param('token'));
	if (!$row) {
		return mjto_booking_error('not_found', 404);
	}
	return rest_ensure_response(mjto_rest_booking_view($row));
}

/* POST /bookings/{token}/cancel → {ok, status, statusLabel} */
function mjto_rest_cancel_booking(WP_REST_Request $request) {
	$token = (string) $request->get_param('token');
	$row   = mjto_get_appointment_by_token($token);
	if (!$row) {
		return mjto_booking_error('not_found', 404);
	}
	$result = mjto_client_cancel($token);
	if (is_wp_error($result)) {
		return mjto_rest_ensure_status($result, 400);
	}
	$fresh  = mjto_get_appointment_by_token($token);
	$status = $fresh ? (string) $fresh['status'] : 'cancelled';
	return rest_ensure_response([
		'ok'          => 'cancelled' === $status,
		'status'      => $status,
		'statusLabel' => mjto_status_label($status),
	]);
}

/* POST /bookings/{token}/deposit-notice {note} → {ok} */
function mjto_rest_deposit_notice(WP_REST_Request $request) {
	$token = (string) $request->get_param('token');
	$row   = mjto_get_appointment_by_token($token);
	if (!$row) {
		return mjto_booking_error('not_found', 404);
	}
	if (!in_array((string) $row['status'], ['awaiting_deposit', 'pending'], true)) {
		return mjto_booking_error('deposit_not_pending', 400);
	}
	$note   = mb_substr(sanitize_textarea_field((string) $request->get_param('note')), 0, 500);
	$result = mjto_client_deposit_notice($token, $note);
	if (is_wp_error($result)) {
		return mjto_rest_ensure_status($result, 400);
	}
	return rest_ensure_response(['ok' => true, 'depositNotified' => true]);
}

/* -------------------------------------------------------------------------
 * Panel: bootstrap
 * ---------------------------------------------------------------------- */

/** Enlaces que usa el panel (también van en window.MJTO.urls). */
function mjto_panel_urls() {
	return [
		'site'          => home_url('/'),
		'publicBooking' => add_query_arg('vista', 'publica', mjto_panel_url()),
		'settings'      => admin_url('admin.php?page=mjto'),
		'services'      => admin_url('edit.php?post_type=mjto_service'),
		/* wp_logout_url escapa el & como &amp; (está pensada para HTML): acá va a JS. */
		'logout'        => str_replace('&amp;', '&', wp_logout_url(home_url('/'))),
		'exportBase'    => admin_url('admin-post.php?action=mjto_export&_wpnonce=' . wp_create_nonce('mjto_export')),
	];
}

/** Contadores de la cabecera del panel. */
function mjto_panel_counts() {
	global $wpdb;
	$today  = mjto_today();
	$active = ['pending', 'awaiting_deposit', 'confirmed', 'completed'];

	$count = function (array $args) {
		$args['per_page'] = 1;
		$args['page']     = 1;
		return (int) mjto_query_appointments($args)['total'];
	};

	$notified = (int) $wpdb->get_var($wpdb->prepare(
		'SELECT COUNT(*) FROM ' . mjto_table('appointments') . ' WHERE status = %s AND deposit_notified_at IS NOT NULL AND starts_at >= %s',
		'awaiting_deposit',
		$today . ' 00:00:00'
	)); // phpcs:ignore WordPress.DB.PreparedSQL

	return [
		'today'           => $count(['from' => $today, 'to' => $today, 'status' => $active]),
		'pending'         => $count(['from' => $today, 'status' => ['pending']]),
		'awaitingDeposit' => $count(['from' => $today, 'status' => ['awaiting_deposit']]),
		'depositNotified' => $notified,
	];
}

/** Etiquetas que el panel toma de los ajustes (estados + textos configurables). */
function mjto_panel_labels() {
	$o      = mjto_get_options();
	$labels = mjto_statuses();
	$labels['statuses']     = mjto_statuses();
	$labels['duration']     = (string) $o['label_duration'];
	$labels['price']        = (string) $o['label_price'];
	$labels['deposit']      = (string) $o['label_deposit'];
	$labels['from']         = (string) $o['label_from'];
	$labels['minutes']      = (string) $o['label_minutes'];
	$labels['hours']        = (string) $o['label_hours'];
	$labels['businessName'] = mjto_business_name();
	$labels['panelTitle']   = mjto_panel_title();
	$labels['menuLabel']    = (string) $o['menu_label'];
	$labels['headerCta']    = (string) $o['header_cta'];
	$labels['bookingTitle'] = (string) $o['booking_title'];
	$labels['servicesTitle'] = (string) $o['services_title'];
	return $labels;
}

/**
 * Todo lo que el panel necesita al arrancar (va inline en window.MJTO.bootstrap
 * y se vuelve a pedir por GET /panel/bootstrap para refrescar).
 */
function mjto_panel_bootstrap() {
	$o    = mjto_get_options();
	$user = wp_get_current_user();
	$now  = current_datetime();

	return [
		'options'  => [
			'labels'            => mjto_panel_labels(),
			'fichaBlocks'       => mjto_ficha_blocks(),
			'formFields'        => mjto_form_fields(),
			'paymentMethods'    => mjto_payment_methods(),
			'schedule'          => [
				'weeklyHours'        => (object) mjto_weekly_hours(),
				'closedDates'        => (object) mjto_closed_dates(),
				'slotStep'           => (int) $o['slot_step'],
				'bufferMin'          => (int) $o['buffer_min'],
				'minNoticeHours'     => (int) $o['min_notice_hours'],
				'maxDaysAhead'       => (int) $o['max_days_ahead'],
				'weekStart'          => (7 === (int) $o['week_start']) ? 7 : 1,
				'defaultDurationMin' => (int) $o['default_duration_min'],
			],
			'confirmMode'       => (string) $o['confirm_mode'],
			'deposit'           => [
				'type'         => (string) $o['deposit_type'],
				'amount'       => (float) $o['deposit_amount'],
				'percent'      => (float) $o['deposit_percent'],
				'hours'        => (int) $o['deposit_hours'],
				'instructions' => (string) $o['deposit_instructions'],
				'link'         => (string) $o['deposit_link'],
			],
			'cancel'            => [
				'allowed'     => (bool) $o['client_can_cancel'],
				'hoursBefore' => (int) $o['cancel_hours_before'],
			],
			'cajaEnabled'       => (bool) $o['caja_enabled'],
			'visitPrefillCarry' => (bool) $o['visit_prefill_carry'],
			'priceVisible'      => (bool) $o['price_visible'],
			'business'          => [
				'name'      => mjto_business_name(),
				'address'   => mjto_business_address(),
				'whatsapp'  => mjto_business_whatsapp(),
				'instagram' => mjto_business_instagram(),
			],
			'whatsappCountry'   => (string) $o['whatsapp_country'],
			'whatsappPrefix'    => (string) $o['whatsapp_prefix'],
		],
		'services' => mjto_services(false, false),
		'currency' => mjto_currency(),
		'user'     => [
			'id'   => (int) $user->ID,
			'name' => (string) $user->display_name,
		],
		'today'    => $now->format('Y-m-d'),
		'now'      => $now->format('Y-m-d H:i'),
		'counts'   => mjto_panel_counts(),
		'urls'     => mjto_panel_urls(),
		'version'  => MJTO_VERSION,
		'timezone' => wp_timezone_string(),
	];
}

/* GET /panel/bootstrap */
function mjto_rest_bootstrap(WP_REST_Request $request) {
	return rest_ensure_response(mjto_panel_bootstrap());
}

/* -------------------------------------------------------------------------
 * Panel: turnos
 * ---------------------------------------------------------------------- */

/* GET /panel/appointments?from&to&status[]&q&client_id&order&page&per_page → {items, total} */
function mjto_rest_appointments(WP_REST_Request $request) {
	[$page, $per_page] = mjto_rest_paging($request, 50, 200);
	$result = mjto_query_appointments([
		'from'      => mjto_rest_date($request->get_param('from')),
		'to'        => mjto_rest_date($request->get_param('to')),
		'status'    => mjto_rest_statuses($request->get_param('status')),
		'q'         => sanitize_text_field((string) $request->get_param('q')),
		'client_id' => (int) $request->get_param('client_id'),
		'order'     => ('desc' === strtolower((string) $request->get_param('order'))) ? 'desc' : 'asc',
		'page'      => $page,
		'per_page'  => $per_page,
	]);
	return rest_ensure_response([
		'items'   => mjto_rest_items($result['items'], fn ($row) => mjto_appointment_public($row)),
		'total'   => (int) $result['total'],
		'page'    => $page,
		'perPage' => $per_page,
	]);
}

/** Datos inline de una clienta desde el panel (solo las claves que vinieron). */
function mjto_rest_client_input($data) {
	$out = [];
	if (!is_array($data)) {
		return $out;
	}
	foreach (['first_name', 'last_name', 'phone', 'locality'] as $key) {
		if (array_key_exists($key, $data)) {
			$out[$key] = sanitize_text_field((string) $data[$key]);
		}
	}
	if (array_key_exists('email', $data)) {
		$out['email'] = sanitize_email((string) $data['email']);
	}
	if (array_key_exists('age', $data)) {
		$age        = (int) $data['age'];
		$out['age'] = ($age > 0 && $age < 130) ? $age : null;
	}
	if (array_key_exists('notes', $data)) {
		$out['notes'] = sanitize_textarea_field((string) $data['notes']);
	}
	if (array_key_exists('extra', $data)) {
		$out['extra'] = mjto_rest_sanitize_extra($data['extra']);
	}
	return $out;
}

/* POST /panel/appointments → 201 turno */
function mjto_rest_create_appointment(WP_REST_Request $request) {
	$client_id = (int) $request->get_param('client_id');
	$client    = mjto_rest_client_input($request->get_param('client'));

	if ($client_id > 0 && !mjto_get_client($client_id)) {
		return mjto_rest_error('mjto_client_not_found', 'No encontramos esa clienta.', 404);
	}
	if ($client_id <= 0) {
		if ('' === trim((string) ($client['first_name'] ?? '')) || strlen(mjto_normalize_phone($client['phone'] ?? '')) < 6) {
			return mjto_rest_error('mjto_client_required', 'Para cargar el turno hacen falta el nombre y el teléfono de la clienta.', 400);
		}
	}

	$date = mjto_rest_date($request->get_param('date'));
	$time = mjto_rest_time($request->get_param('time'));
	if ('' === $date || '' === $time) {
		return mjto_rest_error('mjto_invalid_datetime', 'Elegí un día y un horario válidos.', 400);
	}

	$input = [
		'client_id'      => $client_id,
		'client'         => $client,
		'service_id'     => (int) $request->get_param('service_id'),
		'service_name'   => sanitize_text_field((string) $request->get_param('service_name')),
		'date'           => $date,
		'time'           => $time,
		'internal_notes' => sanitize_textarea_field((string) $request->get_param('internal_notes')),
		'client_notes'   => sanitize_textarea_field((string) $request->get_param('client_notes')),
	];
	$duration = (int) $request->get_param('duration_min');
	if ($duration > 0) {
		$input['duration_min'] = min(1440, $duration);
	}
	$price = mjto_rest_money($request->get_param('price'));
	if (null !== $price) {
		$input['price'] = max(0, $price);
	}
	$deposit_required = mjto_rest_money($request->get_param('deposit_required'));
	if (null !== $deposit_required) {
		$input['deposit_required'] = max(0, $deposit_required);
	}

	$opts = [
		'force'         => mjto_rest_bool($request->get_param('force')),
		'notify'        => mjto_rest_bool($request->get_param('notify')), // Desde el panel, avisar es una decisión explícita.
		'ignore_notice' => true,
	];
	$status = sanitize_key((string) $request->get_param('status'));
	if ('' !== $status) {
		if (!in_array($status, mjto_valid_statuses(), true)) {
			return mjto_rest_error('mjto_invalid_status', 'Ese estado no existe.', 400);
		}
		$opts['status'] = $status;
	}

	$appointment = mjto_create_booking($input, 'panel', $opts);
	if (is_wp_error($appointment)) {
		return mjto_rest_ensure_status($appointment, 400);
	}
	if (!is_array($appointment)) {
		return mjto_rest_error('mjto_booking_failed', 'No se pudo guardar el turno.', 500);
	}
	$response = rest_ensure_response(mjto_appointment_public($appointment));
	$response->set_status(201);
	return $response;
}

/* PATCH /panel/appointments/{id} → turno */
function mjto_rest_update_appointment(WP_REST_Request $request) {
	$id  = (int) $request->get_param('id');
	$row = mjto_get_appointment($id);
	if (!$row) {
		return mjto_rest_error('mjto_not_found', 'No encontramos ese turno.', 404);
	}
	$force  = mjto_rest_bool($request->get_param('force'));
	$notify = mjto_rest_bool($request->get_param('notify'));

	/* 1) Reprogramar: fecha/hora, duración o servicio (recalcula el fin y chequea el hueco). */
	$has_date     = mjto_rest_has($request, 'date');
	$has_time     = mjto_rest_has($request, 'time');
	$has_duration = mjto_rest_has($request, 'duration_min') && (int) $request->get_param('duration_min') > 0;
	$has_service  = mjto_rest_has($request, 'service_id');
	if ($has_date || $has_time || $has_duration || $has_service) {
		$date = $has_date ? mjto_rest_date($request->get_param('date')) : substr((string) $row['starts_at'], 0, 10);
		$time = $has_time ? mjto_rest_time($request->get_param('time')) : substr((string) $row['starts_at'], 11, 5);
		if ('' === $date || '' === $time) {
			return mjto_rest_error('mjto_invalid_datetime', 'Elegí un día y un horario válidos.', 400);
		}
		$duration   = $has_duration ? min(1440, (int) $request->get_param('duration_min')) : null;
		$service_id = $has_service ? (int) $request->get_param('service_id') : null;
		$result     = mjto_reschedule($id, $date, $time, $duration, $service_id, $force, $notify);
		if (is_wp_error($result)) {
			return mjto_rest_ensure_status($result, 400);
		}
	}

	/* 2) Campos directos. */
	$data = [];
	if (mjto_rest_has($request, 'service_name')) {
		$data['service_name'] = sanitize_text_field((string) $request->get_param('service_name'));
	}
	$price = mjto_rest_money($request->get_param('price'));
	if (null !== $price) {
		$data['price'] = max(0, $price);
	}
	$deposit_paid = mjto_rest_money($request->get_param('deposit_paid'));
	if (null !== $deposit_paid) {
		$data['deposit_paid'] = max(0, $deposit_paid);
	}
	$deposit_required = mjto_rest_money($request->get_param('deposit_required'));
	if (null !== $deposit_required) {
		$data['deposit_required'] = max(0, $deposit_required);
	}
	if (mjto_rest_has($request, 'deposit_method')) {
		$data['deposit_method'] = mb_substr(sanitize_text_field((string) $request->get_param('deposit_method')), 0, 60);
	}
	if (mjto_rest_has($request, 'internal_notes')) {
		$data['internal_notes'] = sanitize_textarea_field((string) $request->get_param('internal_notes'));
	}
	if (mjto_rest_has($request, 'client_notes')) {
		$data['client_notes'] = sanitize_textarea_field((string) $request->get_param('client_notes'));
	}
	$reason = mb_substr(sanitize_text_field((string) $request->get_param('cancel_reason')), 0, 255);
	if (mjto_rest_has($request, 'cancel_reason') && !mjto_rest_has($request, 'status')) {
		$data['cancel_reason'] = $reason;
	}
	if ($data && !mjto_update_appointment($id, $data)) {
		return mjto_rest_error('mjto_update_failed', 'No se pudo guardar el turno.', 500);
	}

	$status = sanitize_key((string) $request->get_param('status'));

	/*
	 * 3) Seña sin cambio de estado ("Registrar seña" / "Editar seña" sobre un
	 * turno ya confirmado): no pasa por mjto_set_status(), así que la ficha de
	 * esa visita —y con ella la Caja, que suma visits.deposit— se quedaría en
	 * cero. La pasamos acá pisando el monto y el método anteriores.
	 */
	if (null !== $deposit_paid && ('' === $status || $status === (string) $row['status'])) {
		$visit = mjto_visit_of_appointment($id);
		if ($visit) {
			$method = isset($data['deposit_method']) ? $data['deposit_method'] : (string) $row['deposit_method'];
			mjto_sync_visit_deposit($visit, max(0, $deposit_paid), $method, true);
		}
	}

	/* 4) Cambio de estado (con seña, motivo y aviso). */
	if ('' !== $status && $status !== (string) $row['status']) {
		if (!in_array($status, mjto_valid_statuses(), true)) {
			return mjto_rest_error('mjto_invalid_status', 'Ese estado no existe.', 400);
		}
		$ctx = [
			'notify' => $notify,
			'reason' => $reason,
			'by'     => 'owner',
		];
		if (null !== $deposit_paid) {
			$ctx['deposit_paid'] = max(0, $deposit_paid);
		}
		if (isset($data['deposit_method'])) {
			$ctx['deposit_method'] = $data['deposit_method'];
		}
		$result = mjto_set_status($id, $status, $ctx);
		if (is_wp_error($result)) {
			return mjto_rest_ensure_status($result, 400);
		}
	}

	return rest_ensure_response(mjto_appointment_public(mjto_get_appointment($id)));
}

/* DELETE /panel/appointments/{id} → {ok} */
function mjto_rest_delete_appointment(WP_REST_Request $request) {
	$id = (int) $request->get_param('id');
	if (!mjto_get_appointment($id)) {
		return mjto_rest_error('mjto_not_found', 'No encontramos ese turno.', 404);
	}
	if (!mjto_delete_appointment($id)) {
		return mjto_rest_error('mjto_delete_failed', 'No se pudo eliminar el turno.', 500);
	}
	return rest_ensure_response(['ok' => true]);
}

/* -------------------------------------------------------------------------
 * Panel: clientas
 * ---------------------------------------------------------------------- */

/* GET /panel/clients?q&year&month&order&page&per_page → {items, total} */
function mjto_rest_clients(WP_REST_Request $request) {
	[$page, $per_page] = mjto_rest_paging($request, 50, 200);
	$order  = sanitize_key((string) $request->get_param('order'));
	$result = mjto_search_clients([
		'q'        => sanitize_text_field((string) $request->get_param('q')),
		'year'     => (int) $request->get_param('year'),
		'month'    => (int) $request->get_param('month'),
		'order'    => in_array($order, ['last_visit_desc', 'name_asc', 'created_desc'], true) ? $order : 'last_visit_desc',
		'page'     => $page,
		'per_page' => $per_page,
	]);
	return rest_ensure_response([
		'items'   => mjto_rest_items($result['items'], fn ($row) => mjto_client_public($row, true)),
		'total'   => (int) $result['total'],
		'page'    => $page,
		'perPage' => $per_page,
	]);
}

/** Clienta con estadísticas (visitas, última visita, próximo turno) → shape público. */
function mjto_rest_client_with_stats(array $row, array $visits, array $appointments) {
	$now  = mjto_now();
	$next = null;
	foreach ($appointments as $a) {
		if (in_array((string) $a['status'], ['cancelled', 'no_show'], true) || (string) $a['starts_at'] < $now) {
			continue;
		}
		if (null === $next || (string) $a['starts_at'] < (string) $next['starts_at']) {
			$next = ['id' => (int) $a['id'], 'starts_at' => (string) $a['starts_at'], 'status' => (string) $a['status']];
		}
	}
	$row['visits_count']     = count($visits);
	$row['last_visit']       = $visits ? (string) $visits[0]['visited_at'] : null;
	$row['next_appointment'] = $next;
	return mjto_client_public($row, true);
}

/* GET /panel/clients/{id} → {client, visits, appointments} */
function mjto_rest_get_client(WP_REST_Request $request) {
	$id  = (int) $request->get_param('id');
	$row = mjto_get_client($id);
	if (!$row) {
		return mjto_rest_error('mjto_not_found', 'No encontramos esa clienta.', 404);
	}
	$visits       = mjto_visits_of_client($id);
	$appointments = mjto_query_appointments(['client_id' => $id, 'order' => 'desc', 'per_page' => 500])['items'];
	return rest_ensure_response([
		'client'       => mjto_rest_client_with_stats($row, $visits, $appointments),
		'visits'       => mjto_rest_items($visits, 'mjto_visit_public'),
		'appointments' => mjto_rest_items($appointments, fn ($a) => mjto_appointment_public($a)),
	]);
}

/* POST /panel/clients → 201 clienta */
function mjto_rest_create_client(WP_REST_Request $request) {
	$data = mjto_rest_client_input($request->get_params());
	if ('' === trim((string) ($data['first_name'] ?? ''))) {
		return mjto_rest_error('mjto_name_required', 'Falta el nombre de la clienta.', 400, ['field' => 'first_name']);
	}
	if (strlen(mjto_normalize_phone($data['phone'] ?? '')) < 6) {
		return mjto_rest_error('mjto_phone_required', 'Falta un teléfono válido (al menos 6 números).', 400, ['field' => 'phone']);
	}
	$existing = mjto_find_client_by_phone($data['phone']);
	if ($existing && !mjto_rest_bool($request->get_param('force'))) {
		return mjto_rest_error('mjto_client_exists', 'Ya hay una clienta con ese teléfono: ' . mjto_full_name($existing['first_name'], $existing['last_name']) . '.', 409, ['clientId' => (int) $existing['id']]);
	}
	$id = mjto_insert_client($data);
	if (!$id) {
		return mjto_rest_error('mjto_insert_failed', 'No se pudo guardar la clienta.', 500);
	}
	$response = rest_ensure_response(mjto_rest_client_with_stats(mjto_get_client($id), [], []));
	$response->set_status(201);
	return $response;
}

/* PATCH /panel/clients/{id} → clienta */
function mjto_rest_update_client(WP_REST_Request $request) {
	$id  = (int) $request->get_param('id');
	$row = mjto_get_client($id);
	if (!$row) {
		return mjto_rest_error('mjto_not_found', 'No encontramos esa clienta.', 404);
	}
	$data = mjto_rest_client_input($request->get_params());
	if (array_key_exists('first_name', $data) && '' === trim($data['first_name'])) {
		return mjto_rest_error('mjto_name_required', 'El nombre no puede quedar vacío.', 400, ['field' => 'first_name']);
	}
	if (array_key_exists('phone', $data) && strlen(mjto_normalize_phone($data['phone'])) < 6) {
		return mjto_rest_error('mjto_phone_required', 'El teléfono tiene que tener al menos 6 números.', 400, ['field' => 'phone']);
	}
	if (array_key_exists('phone', $data)) {
		$other = mjto_find_client_by_phone($data['phone']);
		if ($other && (int) $other['id'] !== $id && !mjto_rest_bool($request->get_param('force'))) {
			return mjto_rest_error('mjto_client_exists', 'Ese teléfono ya es de otra clienta: ' . mjto_full_name($other['first_name'], $other['last_name']) . '.', 409, ['clientId' => (int) $other['id']]);
		}
	}
	if ($data && !mjto_update_client($id, $data)) {
		return mjto_rest_error('mjto_update_failed', 'No se pudo guardar la clienta.', 500);
	}
	$visits       = mjto_visits_of_client($id);
	$appointments = mjto_query_appointments(['client_id' => $id, 'order' => 'desc', 'per_page' => 500])['items'];
	return rest_ensure_response(mjto_rest_client_with_stats(mjto_get_client($id), $visits, $appointments));
}

/* DELETE /panel/clients/{id} → {ok} (borra también visitas y turnos) */
function mjto_rest_delete_client(WP_REST_Request $request) {
	$id = (int) $request->get_param('id');
	if (!mjto_get_client($id)) {
		return mjto_rest_error('mjto_not_found', 'No encontramos esa clienta.', 404);
	}
	if (!mjto_delete_client($id)) {
		return mjto_rest_error('mjto_delete_failed', 'No se pudo eliminar la clienta.', 500);
	}
	return rest_ensure_response(['ok' => true]);
}

/* -------------------------------------------------------------------------
 * Panel: visitas (ficha técnica)
 * ---------------------------------------------------------------------- */

/** Resultado del motor (fila, id, bool o WP_Error) → fila fresca de la visita o WP_Error. */
function mjto_rest_visit_row($result, $fallback_id = 0) {
	if (is_wp_error($result)) {
		return mjto_rest_ensure_status($result, 400);
	}
	$id = 0;
	if (is_array($result) && !empty($result['id'])) {
		$id = (int) $result['id'];
	} elseif (is_numeric($result) && (int) $result > 0) {
		$id = (int) $result;
	} elseif ($fallback_id > 0 && false !== $result && null !== $result) {
		$id = (int) $fallback_id;
	}
	$row = $id > 0 ? mjto_get_visit($id) : null;
	if (!$row) {
		return mjto_rest_error('mjto_visit_failed', 'No se pudo guardar la visita.', 500);
	}
	return $row;
}

/** Entrada de una visita (solo las claves que vinieron). */
function mjto_rest_visit_input(WP_REST_Request $request) {
	$input = [];
	if (mjto_rest_has($request, 'visited_at')) {
		$input['visited_at'] = mjto_rest_date($request->get_param('visited_at'));
	}
	if (mjto_rest_has($request, 'service_id')) {
		$input['service_id'] = (int) $request->get_param('service_id');
	}
	if (mjto_rest_has($request, 'service_name')) {
		$input['service_name'] = sanitize_text_field((string) $request->get_param('service_name'));
	}
	if (mjto_rest_has($request, 'data')) {
		$data          = $request->get_param('data');
		$input['data'] = is_array($data) ? $data : []; // mjto_save_visit normaliza campo por campo según la ficha.
	}
	foreach (['total', 'deposit'] as $key) {
		if (mjto_rest_has($request, $key)) {
			$money       = mjto_rest_money($request->get_param($key));
			$input[$key] = null === $money ? 0 : max(0, $money);
		}
	}
	if (mjto_rest_has($request, 'payment_method')) {
		$input['payment_method'] = mb_substr(sanitize_text_field((string) $request->get_param('payment_method')), 0, 60);
	}
	foreach (['control_date', 'retouch_date'] as $key) {
		if (mjto_rest_has($request, $key)) {
			$input[$key] = mjto_rest_date($request->get_param($key));
		}
	}
	return $input;
}

/* POST /panel/clients/{id}/visits {appointment_id, visited_at, service_id, data, carry} → 201 visita */
function mjto_rest_create_visit(WP_REST_Request $request) {
	$client_id = (int) $request->get_param('id');
	if (!mjto_get_client($client_id)) {
		return mjto_rest_error('mjto_not_found', 'No encontramos esa clienta.', 404);
	}
	$input          = mjto_rest_visit_input($request);
	$input['carry'] = mjto_rest_bool($request->get_param('carry'));
	$appointment_id = (int) $request->get_param('appointment_id');

	if ($appointment_id > 0) {
		/* Ficha de un turno: se reutiliza la existente o se crea (con precarga). */
		$appointment = mjto_get_appointment($appointment_id);
		if (!$appointment || (int) $appointment['client_id'] !== $client_id) {
			return mjto_rest_error('mjto_appointment_mismatch', 'Ese turno no es de esta clienta.', 400);
		}
		$row = mjto_rest_visit_row(mjto_ensure_visit($appointment));
		if (is_wp_error($row)) {
			return $row;
		}
		unset($input['carry']);
		if ($input) {
			$row = mjto_rest_visit_row(mjto_save_visit((int) $row['id'], $input), (int) $row['id']);
			if (is_wp_error($row)) {
				return $row;
			}
		}
	} else {
		$row = mjto_rest_visit_row(mjto_create_visit($client_id, $input));
		if (is_wp_error($row)) {
			return $row;
		}
	}

	$response = rest_ensure_response(mjto_visit_public($row));
	$response->set_status(201);
	return $response;
}

/* PATCH /panel/visits/{id} → visita */
function mjto_rest_update_visit(WP_REST_Request $request) {
	$id = (int) $request->get_param('id');
	if (!mjto_get_visit($id)) {
		return mjto_rest_error('mjto_not_found', 'No encontramos esa visita.', 404);
	}
	$input = mjto_rest_visit_input($request);
	if (isset($input['visited_at']) && '' === $input['visited_at']) {
		return mjto_rest_error('mjto_invalid_date', 'La fecha de la visita no es válida.', 400, ['field' => 'visitedAt']);
	}
	$row = mjto_rest_visit_row(mjto_save_visit($id, $input), $id);
	if (is_wp_error($row)) {
		return $row;
	}
	return rest_ensure_response(mjto_visit_public($row));
}

/* DELETE /panel/visits/{id} → {ok} */
function mjto_rest_delete_visit(WP_REST_Request $request) {
	$id = (int) $request->get_param('id');
	if (!mjto_get_visit($id)) {
		return mjto_rest_error('mjto_not_found', 'No encontramos esa visita.', 404);
	}
	if (!mjto_delete_visit($id)) {
		return mjto_rest_error('mjto_delete_failed', 'No se pudo eliminar la visita.', 500);
	}
	return rest_ensure_response(['ok' => true]);
}

/* -------------------------------------------------------------------------
 * Panel: caja
 * ---------------------------------------------------------------------- */

/** Rango [from, to] de un request; por defecto el mes en curso. */
function mjto_rest_month_range(WP_REST_Request $request) {
	$now  = current_datetime();
	$from = mjto_rest_date($request->get_param('from'));
	$to   = mjto_rest_date($request->get_param('to'));
	if ('' === $from && '' === $to) {
		$from = $now->format('Y-m-01');
		$to   = $now->format('Y-m-t');
	} elseif ('' === $from) {
		$from = (new DateTimeImmutable($to, wp_timezone()))->format('Y-m-01');
	} elseif ('' === $to) {
		$to = (new DateTimeImmutable($from, wp_timezone()))->format('Y-m-t');
	}
	if ($to < $from) {
		[$from, $to] = [$to, $from];
	}
	return [$from, $to];
}

/* GET /panel/cash?from&to → {from, to, totals, items} */
function mjto_rest_cash(WP_REST_Request $request) {
	[$from, $to] = mjto_rest_month_range($request);
	$rows      = mjto_visits_between($from, $to);
	$collected = 0.0;
	$deposits  = 0.0;
	$by_method = [];
	$items     = [];
	foreach ($rows as $row) {
		$total   = (float) $row['total'];
		$deposit = (float) $row['deposit'];
		$method  = trim((string) $row['payment_method']);
		if ('' === $method) {
			$method = 'Sin especificar';
		}
		$collected += $total;
		$deposits  += $deposit;
		$by_method[$method] = round(($by_method[$method] ?? 0) + $total, 2);
		$items[] = [
			'visitId'       => (int) $row['id'],
			'clientId'      => (int) $row['client_id'],
			'clientName'    => mjto_full_name($row['client_first_name'] ?? '', $row['client_last_name'] ?? ''),
			'appointmentId' => !empty($row['appointment_id']) ? (int) $row['appointment_id'] : null,
			'date'          => (string) $row['visited_at'],
			'dateLabel'     => mjto_date_label($row['visited_at']),
			'serviceName'   => (string) $row['service_name'],
			'total'         => round($total, 2),
			'deposit'       => round($deposit, 2),
			'paymentMethod' => (string) $row['payment_method'],
		];
	}
	return rest_ensure_response([
		'from'   => $from,
		'to'     => $to,
		'totals' => [
			'collected' => round($collected, 2),
			'deposits'  => round($deposits, 2),
			'visits'    => count($rows),
			'byMethod'  => (object) $by_method,
		],
		'items'  => $items,
	]);
}

/* -------------------------------------------------------------------------
 * Panel: bloqueos de agenda
 * ---------------------------------------------------------------------- */

/* GET /panel/blocks?from&to → [{id, startsAt, endsAt, date, reason, allDay}] */
function mjto_rest_blocks(WP_REST_Request $request) {
	$from = mjto_rest_date($request->get_param('from'));
	$to   = mjto_rest_date($request->get_param('to'));
	if ('' === $from) {
		$from = mjto_today();
	}
	if ('' === $to) {
		$to = (new DateTimeImmutable($from, wp_timezone()))->modify('+365 days')->format('Y-m-d');
	}
	if ($to < $from) {
		[$from, $to] = [$to, $from];
	}
	$rows = mjto_get_blocks_between($from . ' 00:00:00', $to . ' 23:59:59');
	return rest_ensure_response(mjto_rest_items($rows, 'mjto_block_public'));
}

/* POST /panel/blocks {date, date_to, all_day, from_time, to_time, reason} → 201 bloqueo */
function mjto_rest_create_block(WP_REST_Request $request) {
	$date = mjto_rest_date($request->get_param('date'));
	if ('' === $date) {
		return mjto_rest_error('mjto_invalid_date', 'Elegí una fecha válida para el bloqueo.', 400);
	}
	$date_to = mjto_rest_date($request->get_param('date_to'));
	if ('' === $date_to) {
		$date_to = $date;
	}
	if ($date_to < $date) {
		return mjto_rest_error('mjto_invalid_range', 'La fecha "hasta" no puede ser anterior a la fecha de inicio.', 400);
	}
	$all_day = mjto_rest_bool($request->get_param('all_day'), !mjto_rest_has($request, 'from_time') && !mjto_rest_has($request, 'to_time'));
	if ($all_day) {
		$starts = $date . ' 00:00:00';
		$ends   = $date_to . ' 23:59:59';
	} else {
		$from_time = mjto_rest_time($request->get_param('from_time'));
		$to_time   = mjto_rest_time($request->get_param('to_time'));
		if ('' === $from_time || '' === $to_time) {
			return mjto_rest_error('mjto_invalid_time', 'Indicá desde y hasta qué hora bloqueás (o marcá "todo el día").', 400);
		}
		$starts = $date . ' ' . $from_time . ':00';
		$ends   = $date_to . ' ' . $to_time . ':00';
		if ($ends <= $starts) {
			return mjto_rest_error('mjto_invalid_range', 'La hora de fin tiene que ser posterior a la de inicio.', 400);
		}
	}
	$id = mjto_insert_block([
		'starts_at' => $starts,
		'ends_at'   => $ends,
		'reason'    => sanitize_text_field((string) $request->get_param('reason')),
	]);
	if (!$id) {
		return mjto_rest_error('mjto_insert_failed', 'No se pudo guardar el bloqueo.', 500);
	}
	$response = rest_ensure_response(mjto_block_public(mjto_get_block($id)));
	$response->set_status(201);
	return $response;
}

/* DELETE /panel/blocks/{id} → {ok} */
function mjto_rest_delete_block(WP_REST_Request $request) {
	$id = (int) $request->get_param('id');
	if (!mjto_get_block($id)) {
		return mjto_rest_error('mjto_not_found', 'No encontramos ese bloqueo.', 404);
	}
	if (!mjto_delete_block($id)) {
		return mjto_rest_error('mjto_delete_failed', 'No se pudo eliminar el bloqueo.', 500);
	}
	return rest_ensure_response(['ok' => true]);
}

/* -------------------------------------------------------------------------
 * Panel: horarios libres para "Nuevo turno" / reprogramar
 * ---------------------------------------------------------------------- */

/* GET /panel/availability?service_id&date&duration_min&exclude_id&ignore_notice=1 → {date, slots} */
function mjto_rest_panel_availability(WP_REST_Request $request) {
	$date = mjto_rest_date($request->get_param('date'));
	if ('' === $date) {
		return mjto_rest_error('mjto_invalid_date', 'Elegí una fecha válida.', 400);
	}
	$service_id = (int) $request->get_param('service_id');
	$service    = $service_id > 0 ? mjto_get_service($service_id) : null;
	if ($service_id > 0 && !$service) {
		return mjto_rest_error('mjto_invalid_service', 'No encontramos ese servicio.', 404);
	}
	if (!$service) {
		/* Turno libre ("Otro"): duración indicada o la general de la agenda. */
		$duration = (int) $request->get_param('duration_min');
		$service  = [
			'id'       => 0,
			'name'     => sanitize_text_field((string) $request->get_param('service_name')),
			'duration' => $duration > 0 ? min(1440, $duration) : (int) mjto_opt('default_duration_min'),
			'buffer'   => null,
			'price'    => 0,
			'deposit'  => 0,
		];
	}
	$exclude_id    = (int) $request->get_param('exclude_id');
	$ignore_notice = mjto_rest_bool($request->get_param('ignore_notice'), true);
	$slots         = mjto_available_slots($service, $date, $exclude_id, $ignore_notice);
	return rest_ensure_response([
		'date'  => $date,
		'slots' => array_values((array) $slots),
	]);
}
