<?php
/**
 * Endpoints REST propios: wp-json/mj/v1
 *
 * Auth: cookie de WordPress + header X-WP-Nonce (nonce wp_rest, expuesto a la
 * SPA como MJ.wpNonce). Los endpoints de cuenta requieren sesión activa.
 * Los mensajes de error son genéricos a propósito: no revelan si un usuario
 * o email existe (anti-enumeración).
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Registro de rutas
 * ---------------------------------------------------------------------- */
add_action('rest_api_init', function () {
	$ns = 'mj/v1';

	/*
	 * Estado de sesión + nonces vigentes. La SPA lo consulta después de entrar
	 * o salir, y también para recuperarse sola si un nonce venció (por ejemplo
	 * si la sesión se cerró desde otra pestaña o desde wp-admin).
	 */
	register_rest_route($ns, '/session', [
		'methods'             => 'GET',
		'callback'            => 'mj_rest_session',
		'permission_callback' => '__return_true',
	]);

	register_rest_route($ns, '/auth/login', [
		'methods'             => 'POST',
		'callback'            => 'mj_rest_login',
		'permission_callback' => '__return_true',
	]);

	register_rest_route($ns, '/auth/register', [
		'methods'             => 'POST',
		'callback'            => 'mj_rest_register',
		'permission_callback' => '__return_true',
	]);

	register_rest_route($ns, '/auth/logout', [
		'methods'             => 'POST',
		'callback'            => 'mj_rest_logout',
		'permission_callback' => '__return_true',
	]);

	register_rest_route($ns, '/auth/recover', [
		'methods'             => 'POST',
		'callback'            => 'mj_rest_recover',
		'permission_callback' => '__return_true',
	]);

	register_rest_route($ns, '/account', [
		'methods'             => 'GET',
		'callback'            => 'mj_rest_account',
		'permission_callback' => 'mj_rest_require_login',
	]);

	register_rest_route($ns, '/account/profile', [
		'methods'             => 'POST',
		'callback'            => 'mj_rest_profile',
		'permission_callback' => 'mj_rest_require_login',
	]);

	register_rest_route($ns, '/account/addresses', [
		'methods'             => 'POST',
		'callback'            => 'mj_rest_addresses',
		'permission_callback' => 'mj_rest_require_login',
	]);

	register_rest_route($ns, '/account/orders/(?P<id>\d+)', [
		'methods'             => 'GET',
		'callback'            => 'mj_rest_order_detail',
		'permission_callback' => 'mj_rest_require_login',
	]);

	register_rest_route($ns, '/account/orders/(?P<id>\d+)/cancel', [
		'methods'             => 'POST',
		'callback'            => 'mj_rest_order_cancel',
		'permission_callback' => 'mj_rest_require_login',
	]);

	register_rest_route($ns, '/contact', [
		'methods'             => 'POST',
		'callback'            => 'mj_rest_contact',
		'permission_callback' => '__return_true',
	]);

	register_rest_route($ns, '/catalog-stock', [
		'methods'             => 'GET',
		'callback'            => 'mj_rest_catalog_stock',
		'permission_callback' => '__return_true',
	]);

	register_rest_route($ns, '/product-stock/(?P<id>\d+)', [
		'methods'             => 'GET',
		'callback'            => 'mj_rest_product_stock',
		'permission_callback' => '__return_true',
	]);
});

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/* Shape de usuario que consume la SPA. */
function mj_rest_user($user) {
	return [
		'id'    => $user->ID,
		'name'  => $user->display_name,
		'email' => $user->user_email,
	];
}

/* Permission callback para endpoints de cuenta: requiere sesión. */
function mj_rest_require_login() {
	if (is_user_logged_in()) {
		return true;
	}
	return new WP_Error('mj_not_logged_in', 'Tenés que iniciar sesión.', ['status' => 401]);
}

/* -------------------------------------------------------------------------
 * Freno por IP para los endpoints públicos
 *
 * Login, registro, recuperar contraseña y contacto son puertas abiertas a
 * cualquiera: sin un tope, un robot prueba contraseñas contra
 * wp_authenticate() o usa el formulario de contacto como relay de spam. El
 * contador vive en un transient por IP y por puerta, con la ventana y los
 * topes de Apariencia → MJ Appearance → Sesión y acceso.
 *
 * Solo se mira REMOTE_ADDR: las cabeceras de proxy se falsifican. Detrás de
 * Cloudflare o un balanceador, el filtro `mj_client_ip` devuelve la IP real
 * (mismo criterio que MJ Turnos Online).
 * ---------------------------------------------------------------------- */

/* IP del visitante (o 'unknown' si no hay una válida: todos al mismo cubo). */
function mj_rest_client_ip() {
	$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
	$ip = (string) apply_filters('mj_client_ip', $ip);
	return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/* Tope de intentos de una puerta ('auth' | 'contact'); 0 = sin freno. */
function mj_rest_rate_limit($bucket) {
	$options = mj_get_options();
	$key     = 'contact' === $bucket ? 'rate_limit_contact' : 'rate_limit_auth';
	$limit   = isset($options[$key]) ? (int) $options[$key] : 0;
	return max(0, (int) apply_filters('mj_rest_rate_limit', $limit, $bucket));
}

/* Ventana del contador, en segundos. */
function mj_rest_rate_window() {
	$options = mj_get_options();
	$minutes = isset($options['rate_limit_window']) ? (int) $options['rate_limit_window'] : 10;
	return max(1, $minutes) * MINUTE_IN_SECONDS;
}

function mj_rest_rate_key($bucket) {
	return 'mj_rl_' . sanitize_key($bucket) . '_' . md5(mj_rest_client_ip());
}

/* ¿Esta IP ya llegó al tope de esa puerta? Devuelve el WP_Error 429 listo, o null. */
function mj_rest_rate_limited($bucket) {
	$limit = mj_rest_rate_limit($bucket);
	if ($limit <= 0) {
		return null;
	}
	if ((int) get_transient(mj_rest_rate_key($bucket)) < $limit) {
		return null;
	}
	return new WP_Error(
		'mj_rate_limited',
		'Demasiados intentos. Esperá unos minutos y volvé a probar.',
		['status' => 429]
	);
}

/* Suma un intento de esa puerta para esta IP. */
function mj_rest_rate_hit($bucket) {
	if (mj_rest_rate_limit($bucket) <= 0) {
		return;
	}
	$key = mj_rest_rate_key($bucket);
	set_transient($key, ((int) get_transient($key)) + 1, mj_rest_rate_window());
}

/* Un login correcto limpia el contador: los intentos fallidos eran de esa misma persona. */
function mj_rest_rate_reset($bucket) {
	delete_transient(mj_rest_rate_key($bucket));
}

/* Claves permitidas por grupo de dirección (sin el prefijo billing_/shipping_). */
function mj_address_fields($type) {
	$fields = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
	if ('billing' === $type) {
		$fields[] = 'email';
		$fields[] = 'phone';
	}
	return $fields;
}

/* Direcciones del usuario desde user meta (misma forma en GET y POST). */
function mj_get_addresses($user_id) {
	$addresses = [];
	foreach (['billing', 'shipping'] as $type) {
		$data = [];
		foreach (mj_address_fields($type) as $key) {
			$data[$key] = get_user_meta($user_id, $type . '_' . $key, true);
		}
		$addresses[$type] = $data;
	}
	return $addresses;
}

/* -------------------------------------------------------------------------
 * Auth
 * ---------------------------------------------------------------------- */

/*
 * GET /session → estado de sesión y nonces vigentes.
 *
 * Este endpoint se consulta justamente cuando el nonce venció, y la REST API
 * ignora la cookie de sesión si el nonce no valida: sin esto contestaría
 * "no hay nadie" y devolvería nonces de visitante anónimo, que tampoco
 * servirían. Por eso se revalida la cookie firmada a mano.
 *
 * Es seguro: `wp_validate_auth_cookie()` verifica la firma igual que
 * WordPress en cualquier carga de página, la respuesta es de sólo lectura
 * (los datos del propio usuario) y ningún otro sitio puede leerla — el
 * navegador bloquea la lectura entre orígenes y WordPress sólo habilita CORS
 * para el dominio del propio sitio. La sesión revalidada vale nada más que
 * para este request: no autoriza ninguna acción.
 */
function mj_rest_session(WP_REST_Request $request) {
	if (!is_user_logged_in()) {
		$user_id = wp_validate_auth_cookie('', 'logged_in');
		if ($user_id) {
			wp_set_current_user($user_id);
		}
	}
	nocache_headers();
	return rest_ensure_response(mj_session_payload());
}

/*
 * POST /auth/login {username, password, remember?}
 *
 * Devuelve el usuario Y nonces nuevos: los que tenía la SPA se generaron para
 * la sesión anónima y dejan de servir en cuanto se crea la cookie de login.
 * Sin esto, la navegación siguiente (cuenta, pedidos, carrito) empieza a dar
 * 401 y parece que la sesión "no se recordó".
 */
function mj_rest_login(WP_REST_Request $request) {
	$limited = mj_rest_rate_limited('auth');
	if ($limited) {
		return $limited;
	}

	$username = (string) $request->get_param('username');
	$password = (string) $request->get_param('password');

	/* Sin el parámetro, manda el default configurado en los ajustes. */
	$remember = null === $request->get_param('remember')
		? (bool) mj_get_options()['login_remember_default']
		: (bool) rest_sanitize_boolean($request->get_param('remember'));

	$user = wp_authenticate($username, $password);
	if (is_wp_error($user)) {
		/* Cuentan solo los fallidos: quien entra bien no gasta cupo. */
		mj_rest_rate_hit('auth');
		return new WP_Error('mj_invalid_credentials', 'Credenciales inválidas.', ['status' => 401]);
	}
	mj_rest_rate_reset('auth');

	wp_set_current_user($user->ID);
	wp_set_auth_cookie($user->ID, $remember);

	return rest_ensure_response(array_merge(
		['user' => mj_rest_user($user), 'remember' => $remember],
		mj_session_payload()
	));
}

/* POST /auth/register {email, password, first_name?, last_name?} */
function mj_rest_register(WP_REST_Request $request) {
	if ('yes' !== get_option('woocommerce_enable_myaccount_registration')) {
		return new WP_Error('mj_registration_disabled', 'El registro de cuentas está deshabilitado.', ['status' => 403]);
	}

	/* Cada intento cuenta, salga bien o mal: crear cuentas en serie es el abuso que se frena. */
	$limited = mj_rest_rate_limited('auth');
	if ($limited) {
		return $limited;
	}
	mj_rest_rate_hit('auth');

	$email      = sanitize_email((string) $request->get_param('email'));
	$password   = (string) $request->get_param('password');
	$first_name = sanitize_text_field((string) $request->get_param('first_name'));
	$last_name  = sanitize_text_field((string) $request->get_param('last_name'));

	if (!is_email($email)) {
		return new WP_Error('mj_invalid_email', 'Ingresá un email válido.', ['status' => 400]);
	}
	if (email_exists($email)) {
		return new WP_Error('mj_email_exists', 'Ya existe una cuenta con ese email.', ['status' => 400]);
	}
	if (strlen($password) < 6) {
		return new WP_Error('mj_weak_password', 'La contraseña debe tener al menos 6 caracteres.', ['status' => 400]);
	}

	/* Username = parte local del email; sufijo numérico si ya existe. */
	$username = sanitize_user(strstr($email, '@', true), true);
	if ('' === $username) {
		$username = 'cliente';
	}
	$base = $username;
	$i    = 1;
	while (username_exists($username)) {
		$username = $base . $i;
		$i++;
	}

	$user_id = wp_create_user($username, $password, $email);
	if (is_wp_error($user_id)) {
		return new WP_Error('mj_register_failed', 'No se pudo crear la cuenta. Intentá de nuevo.', ['status' => 400]);
	}

	$user = new WP_User($user_id);
	$user->set_role('customer');

	update_user_meta($user_id, 'first_name', $first_name);
	update_user_meta($user_id, 'last_name', $last_name);
	update_user_meta($user_id, 'billing_email', $email);
	update_user_meta($user_id, 'billing_first_name', $first_name);
	update_user_meta($user_id, 'billing_last_name', $last_name);

	$remember = null === $request->get_param('remember')
		? (bool) mj_get_options()['login_remember_default']
		: (bool) rest_sanitize_boolean($request->get_param('remember'));

	wp_set_current_user($user_id);
	wp_set_auth_cookie($user_id, $remember);

	return rest_ensure_response(array_merge(
		['user' => mj_rest_user(get_userdata($user_id))],
		mj_session_payload()
	));
}

/* POST /auth/logout — devuelve los nonces del visitante ya deslogueado. */
function mj_rest_logout(WP_REST_Request $request) {
	wp_logout();
	wp_set_current_user(0);
	return rest_ensure_response(array_merge(['ok' => true], mj_session_payload()));
}

/* POST /auth/recover {login} — SIEMPRE {ok:true} (anti-enumeración). */
function mj_rest_recover(WP_REST_Request $request) {
	/* Sin freno, cada pedido dispara un mail: es la puerta más fácil de usar para molestar. */
	$limited = mj_rest_rate_limited('auth');
	if ($limited) {
		return $limited;
	}
	mj_rest_rate_hit('auth');

	$login = sanitize_text_field((string) $request->get_param('login'));

	if ('' !== $login) {
		$user = is_email($login) ? get_user_by('email', $login) : get_user_by('login', $login);
		if ($user && function_exists('retrieve_password')) {
			retrieve_password($user->user_login);
		}
	}

	return rest_ensure_response(['ok' => true]);
}

/* -------------------------------------------------------------------------
 * Cuenta
 * ---------------------------------------------------------------------- */

/*
 * Estado del pago de un pedido que pide comprobante (plugin MJ Medios de Pago):
 * ['state' => 'pendiente'|'aprobado'|'parcial'|'rechazado', 'label' => '…'],
 * o null sin el plugin o si el pedido no pide comprobante. Sin decisión
 * todavía, el estado es "pendiente".
 */
function mj_order_payment_state($order_id) {
	if (!class_exists('MJPM_Receipts') || !method_exists('MJPM_Receipts', 'requirement')) {
		return null;
	}
	$requirement = MJPM_Receipts::requirement('order', (int) $order_id);
	if (!is_array($requirement) || empty($requirement['asks'])) {
		return null;
	}
	$state = (string) MJPM_Receipts::order_state((int) $order_id);
	if ('' === $state && defined('MJPM_Receipts::STATUS_PENDING')) {
		$state = MJPM_Receipts::STATUS_PENDING;
	}
	if ('' === $state) {
		return null;
	}
	return [
		'state' => $state,
		'label' => (string) MJPM_Receipts::status_label($state),
	];
}

/* GET /account → {user, orders, addresses, downloads} */
function mj_rest_account(WP_REST_Request $request) {
	$user_id = get_current_user_id();

	$orders = [];
	if (function_exists('wc_get_orders')) {
		$list = wc_get_orders([
			'customer_id' => $user_id,
			'limit'       => 50,
			'orderby'     => 'date',
			'order'       => 'DESC',
		]);
		foreach ($list as $order) {
			$created  = $order->get_date_created();
			$orders[] = [
				'id'          => $order->get_id(),
				'number'      => $order->get_order_number(),
				'date'        => $created ? $created->date('c') : null,
				'status'      => $order->get_status(),
				'statusLabel' => wc_get_order_status_name($order->get_status()),
				'total'       => (float) $order->get_total(),
				'itemsCount'  => count($order->get_items()),
			];
			$payment = mj_order_payment_state($order->get_id());
			if ($payment) {
				$orders[count($orders) - 1]['payment'] = $payment;
			}
		}
	}

	$downloads = [];
	if (function_exists('wc_get_customer_available_downloads')) {
		foreach ((array) wc_get_customer_available_downloads($user_id) as $download) {
			$downloads[] = [
				'name' => isset($download['product_name']) ? $download['product_name'] : '',
				'url'  => isset($download['download_url']) ? $download['download_url'] : '',
			];
		}
	}

	/*
	 * Otros plugins suman lo suyo a «Mi cuenta» (MJ ERP: el saldo a favor). El tema
	 * no sabe nada de ellos: muestra lo que llegue.
	 */
	return rest_ensure_response(apply_filters('mj_rest_account', [
		'user'      => mj_rest_user(wp_get_current_user()),
		'orders'    => $orders,
		'addresses' => mj_get_addresses($user_id),
		'downloads' => $downloads,
	], $user_id));
}

/* POST /account/profile {first_name, last_name, display_name, email, current_password?, new_password?} */
function mj_rest_profile(WP_REST_Request $request) {
	$user_id = get_current_user_id();
	$user    = get_userdata($user_id);

	$email = sanitize_email((string) $request->get_param('email'));
	if (!is_email($email)) {
		return new WP_Error('mj_invalid_email', 'Ingresá un email válido.', ['status' => 400]);
	}
	$owner = email_exists($email);
	if ($owner && (int) $owner !== (int) $user_id) {
		return new WP_Error('mj_email_exists', 'Ese email ya está en uso por otra cuenta.', ['status' => 400]);
	}

	$display_name = sanitize_text_field((string) $request->get_param('display_name'));

	$updated = wp_update_user([
		'ID'           => $user_id,
		'first_name'   => sanitize_text_field((string) $request->get_param('first_name')),
		'last_name'    => sanitize_text_field((string) $request->get_param('last_name')),
		'display_name' => '' !== $display_name ? $display_name : $user->display_name,
		'user_email'   => $email,
	]);
	if (is_wp_error($updated)) {
		return new WP_Error('mj_profile_failed', 'No se pudo guardar el perfil. Intentá de nuevo.', ['status' => 400]);
	}

	/* Cambio de contraseña: requiere la actual y mantiene la sesión. */
	$new_password = (string) $request->get_param('new_password');
	if ('' !== $new_password) {
		if (strlen($new_password) < 6) {
			return new WP_Error('mj_weak_password', 'La contraseña debe tener al menos 6 caracteres.', ['status' => 400]);
		}
		$current_password = (string) $request->get_param('current_password');
		if (!wp_check_password($current_password, $user->user_pass, $user_id)) {
			return new WP_Error('mj_wrong_password', 'La contraseña actual no coincide.', ['status' => 400]);
		}
		wp_set_password($new_password, $user_id);
		wp_set_auth_cookie($user_id);
	}

	return rest_ensure_response(['user' => mj_rest_user(get_userdata($user_id))]);
}

/* POST /account/addresses {billing: {...}, shipping: {...}} */
function mj_rest_addresses(WP_REST_Request $request) {
	$user_id = get_current_user_id();

	foreach (['billing', 'shipping'] as $type) {
		$data = $request->get_param($type);
		if (!is_array($data)) {
			continue;
		}
		foreach (mj_address_fields($type) as $key) {
			if (!array_key_exists($key, $data)) {
				continue;
			}
			$value = 'email' === $key ? sanitize_email($data[$key]) : sanitize_text_field($data[$key]);
			update_user_meta($user_id, $type . '_' . $key, $value);
		}
	}

	return rest_ensure_response(['addresses' => mj_get_addresses($user_id)]);
}

/* -------------------------------------------------------------------------
 * Pedidos: detalle y cancelación (solo el dueño del pedido)
 * ---------------------------------------------------------------------- */

/* Pedido del usuario actual, o WP_Error 404 (no revela pedidos ajenos). */
function mj_rest_own_order($id) {
	if (!function_exists('wc_get_order')) {
		return new WP_Error('mj_no_woo', 'WooCommerce no está disponible.', ['status' => 404]);
	}
	$order = wc_get_order((int) $id);
	if (!$order || (int) $order->get_customer_id() !== (int) get_current_user_id()) {
		return new WP_Error('mj_order_not_found', 'Pedido no encontrado.', ['status' => 404]);
	}
	return $order;
}

/* Estados desde los que el cliente puede cancelar (mismo criterio que WooCommerce en Mi cuenta). */
function mj_order_cancellable($order) {
	$valid = (array) apply_filters('woocommerce_valid_order_statuses_for_cancel', ['pending', 'failed'], $order);
	return in_array($order->get_status(), $valid, true);
}

/* Shape de una línea de pedido para la SPA. */
function mj_order_line($item) {
	$qty      = (int) $item->get_quantity();
	$subtotal = (float) $item->get_subtotal();
	$product  = $item->get_product();
	$image_id = $product ? $product->get_image_id() : 0;

	/* Variación legible: "Color: Rojo, Talle: M". */
	$variant = '';
	if ($item->get_variation_id() && $product) {
		$variant = wc_get_formatted_variation($product, true, false);
	}

	return [
		'productId'   => $item->get_product_id(),
		'variationId' => $item->get_variation_id(),
		'name'        => $item->get_name(),
		'variant'     => $variant,
		'qty'         => $qty,
		'price'       => $qty > 0 ? $subtotal / $qty : 0,
		'subtotal'    => $subtotal,
		'total'       => (float) $item->get_total(),
		'image'       => $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : null,
	];
}

/* Dirección del pedido como campos simples para la SPA. */
function mj_order_address($order, $type) {
	$get = function ($field) use ($order, $type) {
		$method = "get_{$type}_{$field}";
		return method_exists($order, $method) ? trim((string) $order->$method()) : '';
	};
	$name = 'billing' === $type ? $order->get_formatted_billing_full_name() : $order->get_formatted_shipping_full_name();
	return [
		'name'    => $name,
		'address' => implode(', ', array_filter([$get('address_1'), $get('address_2'), $get('city'), $get('state'), $get('postcode')])),
		'email'   => $get('email'),
		'phone'   => $get('phone'),
	];
}

/* Datos de la factura ARCA del pedido (plugin MJ Facturador), o null si no aplica. */
function mj_order_factura($order) {
	$solicita = 'yes' === $order->get_meta('_mjf_solicita');
	$cae      = (string) $order->get_meta('_mjf_cae');

	if (!$solicita && '' === $cae) {
		return null;
	}

	$tipo  = (int) $order->get_meta('_mjf_cbte_tipo');
	$letra = [1 => 'A', 6 => 'B', 11 => 'C'][$tipo] ?? '';

	$doc_tipo = (int) $order->get_meta('_mjf_doc_tipo');
	$doc_nro  = (string) $order->get_meta('_mjf_doc_nro');
	if (80 === $doc_tipo && $doc_nro) {
		$documento = "CUIT {$doc_nro}";
	} elseif (96 === $doc_tipo && $doc_nro) {
		$documento = "DNI {$doc_nro}";
	} else {
		$documento = 'Consumidor Final';
	}

	$cond_map = [
		'cf'          => 'Consumidor Final',
		'ri'          => 'Responsable Inscripto',
		'monotributo' => 'Monotributo',
		'exento'      => 'Exento',
	];
	$cond = (string) $order->get_meta('_mjf_cond_iva_rec');

	$nombre = (string) $order->get_meta('_mjf_fact_nombre');
	if ('' === $nombre) {
		$nombre = $order->get_formatted_billing_full_name();
	}

	$pto = (int) $order->get_meta('_mjf_pto_vta');
	$nro = (int) $order->get_meta('_mjf_cbte_nro');

	return [
		'estado'    => '' !== $cae ? 'emitida' : ('' !== (string) $order->get_meta('_mjf_error') ? 'error' : 'pendiente'),
		'tipo'      => $letra ? "Factura {$letra}" : '',
		'numero'    => ('' !== $cae && $pto && $nro) ? sprintf('%05d-%08d', $pto, $nro) : '',
		'cae'       => $cae,
		'nombre'    => $nombre,
		'documento' => $documento,
		'condIva'   => $cond_map[$cond] ?? $cond_map['cf'],
		'url'       => ('' !== $cae && class_exists('MJF_Comprobante')) ? MJF_Comprobante::url($order->get_id()) : '',
		'pdfUrl'    => ('' !== $cae && class_exists('MJF_Comprobante')) ? MJF_Comprobante::pdf_url($order->get_id()) : '',
	];
}

/* GET /account/orders/{id} → detalle completo del pedido (productos, totales, direcciones). */
function mj_rest_order_detail(WP_REST_Request $request) {
	$order = mj_rest_own_order($request->get_param('id'));
	if (is_wp_error($order)) {
		return $order;
	}

	$items = [];
	foreach ($order->get_items() as $item) {
		$items[] = mj_order_line($item);
	}

	$created = $order->get_date_created();

	$data = [
		'id'             => $order->get_id(),
		'number'         => $order->get_order_number(),
		'date'           => $created ? $created->date('c') : null,
		'status'         => $order->get_status(),
		'statusLabel'    => wc_get_order_status_name($order->get_status()),
		'canCancel'      => mj_order_cancellable($order),
		'items'          => $items,
		'totals'         => [
			'subtotal' => (float) $order->get_subtotal(),
			'discount' => (float) $order->get_discount_total(),
			'shipping' => (float) $order->get_shipping_total(),
			'total'    => (float) $order->get_total(),
		],
		'paymentMethod'  => $order->get_payment_method_title(),
		'shippingMethod' => $order->get_shipping_method(),
		'billing'        => mj_order_address($order, 'billing'),
		'shippingAddr'   => mj_order_address($order, 'shipping'),
		'factura'        => mj_order_factura($order),
	];
	$payment = mj_order_payment_state($order->get_id());
	if ($payment) {
		$data['payment'] = $payment;
	}

	/* Otros plugins MJ agregan datos al detalle (p. ej. MJ Dólar → exchangeRate, usdUnit por línea). */
	return rest_ensure_response(apply_filters('mj_rest_order_detail', $data, $order));
}

/* POST /account/orders/{id}/cancel — el cliente cancela su propio pedido. */
function mj_rest_order_cancel(WP_REST_Request $request) {
	$order = mj_rest_own_order($request->get_param('id'));
	if (is_wp_error($order)) {
		return $order;
	}

	if (!mj_order_cancellable($order)) {
		return new WP_Error(
			'mj_order_not_cancellable',
			'Este pedido ya no se puede cancelar desde la web. Escribinos desde Contacto.',
			['status' => 400]
		);
	}

	/* update_status dispara los hooks de Woo: restaura stock y notifica. */
	$order->update_status('cancelled', 'Pedido cancelado por el cliente desde la tienda.');

	return rest_ensure_response([
		'ok'          => true,
		'status'      => 'cancelled',
		'statusLabel' => wc_get_order_status_name('cancelled'),
	]);
}

/* -------------------------------------------------------------------------
 * Contacto / newsletter
 * ---------------------------------------------------------------------- */

/* POST /contact {kind: 'contact'|'newsletter', name?, email, message?} */
function mj_rest_contact(WP_REST_Request $request) {
	/*
	 * Honeypot: la SPA manda un campo `website` oculto que una persona nunca
	 * completa. Si viene con algo, es un robot: se le contesta "ok" para que no
	 * insista con otra variante, y no se manda nada.
	 */
	if ('' !== trim((string) $request->get_param('website'))) {
		mj_rest_rate_hit('contact');
		return rest_ensure_response(['ok' => true]);
	}

	$limited = mj_rest_rate_limited('contact');
	if ($limited) {
		return $limited;
	}

	$kind    = 'newsletter' === $request->get_param('kind') ? 'newsletter' : 'contact';
	$name    = sanitize_text_field((string) $request->get_param('name'));
	$email   = sanitize_email((string) $request->get_param('email'));
	$message = sanitize_textarea_field((string) $request->get_param('message'));

	if (!is_email($email)) {
		return new WP_Error('mj_invalid_email', 'Ingresá un email válido.', ['status' => 400]);
	}

	/* Cuenta recién acá: un email mal escrito no gasta cupo, un envío sí. */
	mj_rest_rate_hit('contact');

	$options = mj_get_options();
	$to      = !empty($options['contact_email']) ? $options['contact_email'] : get_option('admin_email');
	$site    = get_bloginfo('name');

	if ('newsletter' === $kind) {
		$subject = 'Newsletter — nueva suscripción';
		$body    = "Nueva suscripción al newsletter de {$site}.\n\nEmail: {$email}\n";
		if ('' !== $name) {
			$body .= "Nombre: {$name}\n";
		}
	} else {
		$subject = 'Contacto web — ' . ('' !== $name ? $name : $email);
		$body    = "Mensaje desde el formulario de contacto de {$site}.\n\nNombre: {$name}\nEmail: {$email}\n\nMensaje:\n{$message}\n";
	}

	$headers = ['Reply-To: ' . ('' !== $name ? $name . ' <' . $email . '>' : $email)];

	if (!wp_mail($to, $subject, $body, $headers)) {
		return new WP_Error('mj_mail_failed', 'No se pudo enviar el mensaje. Intentá de nuevo más tarde.', ['status' => 500]);
	}

	return rest_ensure_response(['ok' => true]);
}

/* -------------------------------------------------------------------------
 * Stock y precios por variación
 *
 * La Store API no expone precio ni stock de cada variación (solo id +
 * atributos), así que la SPA lo consulta acá para mostrar el precio real de
 * la variación elegida y el stock disponible de cada una.
 * ---------------------------------------------------------------------- */

/* Shape común de stock para un producto (simple o variación). */
function mj_stock_data($product) {
	return [
		'sku'            => (string) $product->get_sku(),
		'manage_stock'   => (bool) $product->get_manage_stock(),
		'stock_status'   => $product->get_stock_status(),
		'stock_quantity' => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
		'backorders'     => $product->backorders_allowed(),
	];
}

/* GET /catalog-stock → [{id, sku, stock_quantity}] de todo el catálogo publicado.
 *
 * Lo consume la SPA al cargar el catálogo: la Store API expone el SKU pero no
 * la cantidad de stock. La cantidad viaja solo si el ajuste "Mostrar la
 * cantidad de stock en el buscador" está prendido: la Store API no la publica
 * a propósito, y sin ella el buscador dice "En stock" / "Sin stock", que es lo
 * que sabe por la Store API.
 *
 * Es público y lo pide cada visitante en su primera carga, así que no
 * instancia productos: lee la tabla de búsqueda de WooCommerce
 * (wc_product_meta_lookup) en una consulta, y el resultado queda en un
 * transient que se vacía cuando cambia un producto o su stock. */
function mj_rest_catalog_stock() {
	global $wpdb;

	if (!function_exists('wc_get_products')) {
		return new WP_Error('mj_no_woo', 'WooCommerce no está disponible.', ['status' => 404]);
	}

	$options  = mj_get_options();
	$with_qty = !empty($options['catalog_stock_quantity']);
	$minutes  = max(1, (int) $options['catalog_stock_cache']);
	$key      = 'mj_catalog_stock_' . ($with_qty ? 'qty' : 'sku');

	$out = get_transient($key);
	if (!is_array($out)) {
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombres de tabla de WordPress y WooCommerce, no del visitante.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT l.product_id, l.sku, l.stock_quantity
				FROM {$lookup} l INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
				WHERE p.post_type = %s AND p.post_status = %s
				ORDER BY l.product_id ASC",
				'product',
				'publish'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = [];
		foreach ((array) $rows as $row) {
			$item = [
				'id'  => (int) $row['product_id'],
				'sku' => (string) $row['sku'],
			];
			if ($with_qty) {
				$item['stock_quantity'] = null === $row['stock_quantity'] ? null : (int) $row['stock_quantity'];
			}
			$out[] = $item;
		}
		set_transient($key, $out, $minutes * MINUTE_IN_SECONDS);
	}

	$response = rest_ensure_response($out);
	$response->header('Cache-Control', 'public, max-age=' . ($minutes * MINUTE_IN_SECONDS));
	return $response;
}

/* El catálogo cacheado se vacía apenas cambia un producto o su stock. */
function mj_catalog_stock_flush() {
	delete_transient('mj_catalog_stock_qty');
	delete_transient('mj_catalog_stock_sku');
}
add_action('woocommerce_product_set_stock', 'mj_catalog_stock_flush');
add_action('woocommerce_variation_set_stock', 'mj_catalog_stock_flush');
add_action('woocommerce_product_set_stock_status', 'mj_catalog_stock_flush');
add_action('woocommerce_update_product', 'mj_catalog_stock_flush');
add_action('woocommerce_new_product', 'mj_catalog_stock_flush');
add_action('woocommerce_delete_product', 'mj_catalog_stock_flush');
add_action('woocommerce_trash_product', 'mj_catalog_stock_flush');
add_action('save_post_product', 'mj_catalog_stock_flush');

/* GET /product-stock/{id} → stock del producto + precio/stock de variaciones. */
function mj_rest_product_stock(WP_REST_Request $request) {
	if (!function_exists('wc_get_product')) {
		return new WP_Error('mj_no_woo', 'WooCommerce no está disponible.', ['status' => 404]);
	}

	$product = wc_get_product((int) $request->get_param('id'));
	if (!$product || 'publish' !== $product->get_status()) {
		return new WP_Error('mj_product_not_found', 'Producto no encontrado.', ['status' => 404]);
	}

	$data = array_merge(
		['id' => $product->get_id(), 'variations' => []],
		mj_stock_data($product)
	);

	if ($product->is_type('variable')) {
		foreach ($product->get_children() as $child_id) {
			$v = wc_get_product($child_id);
			if (!$v || 'private' === $v->get_status() || !$v->variation_is_visible()) {
				continue;
			}

			$attrs = [];
			foreach ($v->get_attributes() as $key => $value) {
				/* Atributos de taxonomía guardan el slug: mostrar el nombre. */
				if (taxonomy_exists($key)) {
					$term = get_term_by('slug', $value, $key);
					if ($term && !is_wp_error($term)) {
						$value = $term->name;
					}
				}
				$attrs[] = ['name' => wc_attribute_label($key, $product), 'value' => $value];
			}

			$image_id = $v->get_image_id();
			$sale     = $v->get_sale_price();

			$data['variations'][] = array_merge(
				[
					'id'            => $v->get_id(),
					'attributes'    => $attrs,
					'price'         => (float) $v->get_price(),
					'regular_price' => (float) $v->get_regular_price(),
					'sale_price'    => '' !== $sale ? (float) $sale : null,
					'image'         => $image_id ? wp_get_attachment_image_url($image_id, 'large') : null,
				],
				mj_stock_data($v)
			);
		}
	}

	/* Otros plugins MJ pueden agregar datos por variación. */
	return rest_ensure_response(apply_filters('mj_rest_product_stock', $data, $product));
}
