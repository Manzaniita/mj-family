<?php
/**
 * La cuenta de la clienta.
 *
 * Una cuenta es un usuario de WordPress (con WooCommerce, un cliente de la
 * tienda: la misma cuenta sirve para comprar). Según Turnos → Ajustes →
 * Cuentas (account_mode):
 *
 *   off       como siempre: se reserva sin cuenta.
 *   optional  en el paso de datos puede tildar "crear mi cuenta" y elegir una
 *             contraseña.
 *   required  sin sesión, el paso de datos pide una contraseña y la cuenta se
 *             crea junto con la reserva (o ingresa, si ya tiene).
 *
 * El link de cada turno (/{slug}/t/{token}) sigue funcionando igual, con o
 * sin cuenta: es el "enlace de invitado".
 *
 * PRIVACIDAD
 *   Con la sesión iniciada, la clienta ve sus turnos y sus datos guardados
 *   (para no volver a cargarlos). Por eso una ficha que ya existía sin cuenta
 *   no se vincula a una cuenta nueva por coincidir un solo dato: hace falta
 *   que coincidan el teléfono Y el email (mjto_should_link_client). Si no, la
 *   reserva arma una ficha nueva y la dueña ve las dos en el panel. Nadie ve
 *   nunca los turnos de otra persona: "Mis turnos" busca solo por la cuenta.
 *
 * @package MJ_Turnos_Online
 */

defined('ABSPATH') || exit;

/** off | optional | required */
function mjto_account_mode() {
	$mode = (string) mjto_opt('account_mode');
	return in_array($mode, ['off', 'optional', 'required'], true) ? $mode : 'off';
}

/** Largo mínimo de la contraseña. */
function mjto_account_password_min() {
	return max(6, min(64, (int) mjto_opt('account_password_min')));
}

/**
 * Dirección de "Mis turnos". Orden: el ajuste, lo que diga el tema (filtro
 * mjto_my_bookings_url, MJ Appearance la pone en Mi cuenta) o la página de
 * turnos con ?mis-turnos=1 (el widget del shortcode la muestra).
 * $only_default: ignora el ajuste (para mostrar el valor por defecto).
 */
function mjto_my_bookings_url($only_default = false) {
	$own = trim((string) mjto_opt('my_bookings_url'));
	if (!$only_default && '' !== $own) {
		return 0 === strpos($own, '/') ? home_url($own) : esc_url_raw($own);
	}
	$default = add_query_arg('mis-turnos', '1', home_url('/' . mjto_page_slug()));
	return (string) apply_filters('mjto_my_bookings_url', $default);
}

/**
 * ¿Se puede vincular una ficha sin cuenta a esta cuenta? Por defecto, solo si
 * el teléfono y el email de la ficha son los mismos que escribió al reservar
 * y el email es también el de la cuenta. Filtro: mjto_link_existing_client.
 */
function mjto_should_link_client(array $existing, array $typed, $user_id) {
	$user       = get_userdata((int) $user_id);
	$user_email = $user ? strtolower((string) $user->user_email) : '';
	$email      = strtolower(trim((string) ($existing['email'] ?? '')));
	$same_email = '' !== $email && $email === strtolower(trim((string) ($typed['email'] ?? ''))) && $email === $user_email;
	$same_phone = '' !== (string) ($existing['phone'] ?? '') && (string) $existing['phone'] === mjto_normalize_phone($typed['phone'] ?? '');
	return (bool) apply_filters('mjto_link_existing_client', $same_email && $same_phone, $existing, $typed, (int) $user_id);
}

/**
 * Qué hacer con la cuenta en una reserva web (se llama antes de tomar el
 * horario: acá solo se valida). → ['user_id' => int, 'create' => bool, …] o WP_Error.
 */
function mjto_booking_account_plan(array $input, array $parsed) {
	$plan = ['user_id' => 0, 'create' => false];
	$mode = mjto_account_mode();

	if (is_user_logged_in()) {
		/*
		 * Con sesión: la reserva queda en su cuenta (también en modo "off", si la
		 * tiene). La dueña probando la reserva con ?vista=publica no se vincula a
		 * la clienta que carga.
		 */
		if (!current_user_can(MJTO_CAP)) {
			$plan['user_id'] = (int) get_current_user_id();
		}
		return $plan;
	}
	if ('off' === $mode) {
		return $plan;
	}

	$wants = ('required' === $mode) || mjto_rest_bool($input['create_account'] ?? false);
	if (!$wants) {
		return $plan;
	}

	$email    = sanitize_email((string) ($parsed['client']['email'] ?? ''));
	$password = (string) ($input['password'] ?? '');
	if (!is_email($email)) {
		return mjto_booking_error('invalid_email', 400, ['errors' => ['email' => mjto_booking_messages()['invalid_email']]]);
	}
	if (email_exists($email)) {
		return mjto_booking_error('account_exists', 409, ['login' => true, 'errors' => ['email' => mjto_booking_messages()['account_exists']]]);
	}
	$min = mjto_account_password_min();
	if (mb_strlen($password) < $min) {
		$message = mjto_message_format(mjto_booking_messages()['password_short'], [$min]);
		return new WP_Error('mjto_password_short', $message, ['status' => 400, 'errors' => ['password' => $message], 'params' => ['password' => $message]]);
	}

	return [
		'user_id'    => 0,
		'create'     => true,
		'email'      => $email,
		'password'   => $password,
		'first_name' => (string) ($parsed['client']['first_name'] ?? ''),
		'last_name'  => (string) ($parsed['client']['last_name'] ?? ''),
		'phone'      => (string) ($parsed['client']['phone'] ?? ''),
	];
}

/**
 * Crea la cuenta (cliente de WooCommerce si está; si no, un usuario con el rol
 * por defecto del sitio) y deja la sesión iniciada. → id del usuario o WP_Error.
 */
function mjto_booking_account_create(array $plan) {
	if (email_exists($plan['email'])) {
		return mjto_booking_error('account_exists', 409, ['login' => true, 'errors' => ['email' => mjto_booking_messages()['account_exists']]]);
	}
	$meta = ['first_name' => $plan['first_name'], 'last_name' => $plan['last_name']];

	if (function_exists('wc_create_new_customer')) {
		$user_id = wc_create_new_customer($plan['email'], '', $plan['password'], $meta);
	} else {
		$login = sanitize_user(current(explode('@', $plan['email'])), true);
		$base  = '' !== $login ? $login : 'clienta';
		$login = $base;
		$i     = 2;
		while ($i < 100 && username_exists($login)) {
			$login = $base . $i;
			$i++;
		}
		$user_id = wp_insert_user(array_merge($meta, [
			'user_login'   => $login,
			'user_email'   => $plan['email'],
			'user_pass'    => $plan['password'],
			'display_name' => trim($plan['first_name'] . ' ' . $plan['last_name']),
		]));
	}
	if (is_wp_error($user_id) || !$user_id) {
		return mjto_booking_error('account_failed', 500);
	}
	$user_id = (int) $user_id;
	if ('' !== $plan['phone']) {
		update_user_meta($user_id, 'billing_phone', $plan['phone']);
	}
	if ('' !== $plan['first_name']) {
		update_user_meta($user_id, 'billing_first_name', $plan['first_name']);
	}
	if ('' !== $plan['last_name']) {
		update_user_meta($user_id, 'billing_last_name', $plan['last_name']);
	}

	/* La sesión queda iniciada: al volver a cargar la página ya es su cuenta. */
	wp_set_current_user($user_id);
	wp_set_auth_cookie($user_id, true);
	mjto_account_created_now($user_id);
	do_action('mjto_account_created', $user_id, $plan);
	return $user_id;
}

/** Id de la cuenta creada en este mismo pedido (0 si no se creó ninguna). Con $set, la anota. */
function mjto_account_created_now($set = null) {
	static $created = 0;
	if (null !== $set) {
		$created = (int) $set;
	}
	return $created;
}

/** Ficha de la clienta de la sesión (o null). */
function mjto_current_client() {
	if (!is_user_logged_in()) {
		return null;
	}
	return mjto_find_client_by_user(get_current_user_id());
}

/* -------------------------------------------------------------------------
 * REST: /me y /me/bookings (solo con sesión, solo lo propio)
 * ---------------------------------------------------------------------- */

add_action('rest_api_init', function () {
	$ns   = 'mjto/v1';
	$auth = function () {
		if (!is_user_logged_in()) {
			return new WP_Error('mjto_not_logged_in', (string) mjto_opt('my_bookings_login'), ['status' => 401]);
		}
		return true;
	};
	register_rest_route($ns, '/me', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'mjto_rest_me',
		'permission_callback' => $auth,
	]);
	register_rest_route($ns, '/me/bookings', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'mjto_rest_me_bookings',
		'permission_callback' => $auth,
	]);
});

/**
 * GET /me → datos para precargar el formulario y el saldo a favor.
 * {user: {name, email}, client: {firstName, lastName, phone, email, locality,
 *  birthdate, extra} | null, credit, prefill}
 */
function mjto_rest_me(WP_REST_Request $request) {
	unset($request); // Firma del callback REST: no usa parámetros.
	$user   = wp_get_current_user();
	$row    = mjto_current_client();
	$client = null;
	if ($row && (int) mjto_opt('account_prefill')) {
		/* Solo los campos que hoy tiene el formulario: nada de notas internas. */
		$extra  = mjto_json_to_array($row['extra'] ?? '');
		$keys   = array_map(fn ($f) => $f['key'], array_filter(mjto_form_fields(), fn ($f) => !empty($f['enabled']) && empty($f['system'])));
		$client = [
			'firstName' => (string) $row['first_name'],
			'lastName'  => (string) $row['last_name'],
			'phone'     => (string) ('' !== (string) $row['phone_raw'] ? $row['phone_raw'] : $row['phone']),
			'email'     => (string) $row['email'],
			'locality'  => (string) $row['locality'],
			'birthdate' => mjto_dt_or_null($row['birthdate'] ?? ''),
			'extra'     => (object) array_intersect_key($extra, array_flip($keys)),
		];
	}
	if (!$client) {
		/* Sin ficha todavía: lo que sabe la cuenta. */
		$client = [
			'firstName' => (string) $user->first_name,
			'lastName'  => (string) $user->last_name,
			'phone'     => (string) get_user_meta($user->ID, 'billing_phone', true),
			'email'     => (string) $user->user_email,
			'locality'  => (string) get_user_meta($user->ID, 'billing_city', true),
			'birthdate' => null,
			'extra'     => (object) [],
		];
	}
	return rest_ensure_response([
		'user'   => [
			'id'    => (int) $user->ID,
			'name'  => (string) $user->display_name,
			'email' => (string) $user->user_email,
		],
		'client' => $client,
		'credit' => ($row && (int) mjto_opt('credit_enabled')) ? mjto_credit_balance((int) $row['id']) : 0.0,
	]);
}

/** Resumen de un turno para "Mis turnos" (el detalle y las acciones están en su página, con el token). */
function mjto_my_booking_item(array $row) {
	$public = mjto_appointment_public($row, false);
	return [
		'token'           => $public['token'],
		'url'             => mjto_booking_status_url($public['token']),
		'status'          => $public['status'],
		'statusLabel'     => $public['statusLabel'],
		'serviceName'     => $public['serviceName'],
		'date'            => $public['date'],
		'time'            => $public['time'],
		'endTime'         => $public['endTime'],
		'dateLabel'       => $public['dateLabel'],
		'durationMin'     => $public['durationMin'],
		'price'           => $public['price'],
		'depositRequired' => $public['depositRequired'],
		'depositPaid'     => $public['depositPaid'],
		'ownerOffer'      => $public['ownerOffer'],
		'needsAction'     => ('awaiting_deposit' === $public['status']) || ('open' === $public['ownerOffer']),
	];
}

/**
 * GET /me/bookings → {upcoming: [...], past: [...], credit, movements}.
 * Solo los turnos de la ficha vinculada a la cuenta de la sesión.
 */
function mjto_rest_me_bookings(WP_REST_Request $request) {
	unset($request); // Firma del callback REST: no usa parámetros.
	$row = mjto_current_client();
	$out = ['upcoming' => [], 'past' => [], 'credit' => 0.0, 'movements' => []];
	if (!$row) {
		return rest_ensure_response($out);
	}
	$now   = mjto_now();
	$items = mjto_query_appointments(['client_id' => (int) $row['id'], 'order' => 'desc', 'per_page' => 200])['items'];
	foreach ($items as $item) {
		$active = in_array((string) $item['status'], ['pending', 'awaiting_deposit', 'confirmed'], true) || 'open' === (string) $item['owner_offer'];
		if ($active && (string) $item['ends_at'] >= $now) {
			$out['upcoming'][] = mjto_my_booking_item($item);
		} else {
			$out['past'][] = mjto_my_booking_item($item);
		}
	}
	$out['upcoming'] = array_reverse($out['upcoming']); // El más cercano primero.
	if ((int) mjto_opt('credit_enabled')) {
		$out['credit']    = mjto_credit_balance((int) $row['id']);
		$out['movements'] = array_values(array_filter(array_map('mjto_credit_public', mjto_credit_movements((int) $row['id'], 30))));
	}
	return rest_ensure_response($out);
}
