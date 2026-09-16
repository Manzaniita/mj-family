<?php
/**
 * REST de comprobantes (mjpm/v1).
 *
 *   GET    /comprobantes/config   qué se pide y qué ya subió  (?context=&objeto=&token=)
 *   POST   /comprobantes          sube un archivo (multipart: archivo, context, objeto, token…)
 *   DELETE /comprobantes/{id}     el cliente se arrepiente, mientras nadie lo revisó
 *   GET    /comprobantes/archivo  manda el archivo (?id=&descargar=1)
 *   POST   /comprobantes/{id}/revisar   decisión sobre un archivo (quien revisa)
 *   POST   /comprobantes/revisar-compra decisión sobre la compra (quien revisa)
 *                                       decision=aprobado|parcial|rechazado, motivo, importe
 *
 * QUIÉN PUEDE QUÉ
 *   Las rutas son públicas a propósito y el permiso se resuelve adentro, porque
 *   hay tres formas legítimas de llegar: quien revisa (capability), el cliente
 *   logueado dueño de la compra, y el cliente sin cuenta con el link firmado.
 *   Nada se sirve ni se guarda sin pasar por ese chequeo.
 */

defined('ABSPATH') || exit;

class MJPM_Receipts_REST {

	const NS = 'mjpm/v1';

	public static function init() {
		add_action('rest_api_init', [__CLASS__, 'routes']);
	}

	public static function routes() {
		$base = '/comprobantes';

		register_rest_route(self::NS, $base . '/config', [
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'config'],
			'permission_callback' => '__return_true',
			'args'                => self::target_args(),
		]);

		register_rest_route(self::NS, $base, [
			'methods'             => 'POST',
			'callback'            => [__CLASS__, 'upload'],
			'permission_callback' => '__return_true',
			'args'                => self::target_args(),
		]);

		register_rest_route(self::NS, $base . '/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [__CLASS__, 'remove'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(self::NS, $base . '/token', [
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'token'],
			'permission_callback' => '__return_true',
			'args'                => [
				'pedido' => ['required' => true],
				'clave'  => ['required' => true],
			],
		]);

		register_rest_route(self::NS, $base . '/archivo', [
			'methods'             => 'GET',
			'callback'            => [__CLASS__, 'file'],
			'permission_callback' => '__return_true',
			'args'                => [
				'id'        => ['required' => true],
				'token'     => [],
				'descargar' => [],
			],
		]);

		register_rest_route(self::NS, $base . '/(?P<id>\d+)/revisar', [
			'methods'             => 'POST',
			'callback'            => [__CLASS__, 'review'],
			'permission_callback' => [__CLASS__, 'can_review'],
		]);

		register_rest_route(self::NS, $base . '/revisar-compra', [
			'methods'             => 'POST',
			'callback'            => [__CLASS__, 'review_object'],
			'permission_callback' => [__CLASS__, 'can_review'],
			'args'                => self::target_args(),
		]);
	}

	private static function target_args() {
		return [
			'context' => ['default' => 'order'],
			'objeto'  => [],
			'token'   => [],
		];
	}

	public static function can_review() {
		return MJPM_Receipts::can_review();
	}

	/* ------------------------------ Permisos ------------------------------ */

	/**
	 * Cómo llegó quien pide: 'review' | 'owner' | 'token' | '' (no puede).
	 *
	 * @param string $context   Contexto.
	 * @param int    $object_id Objeto.
	 * @param string $token     Token del link firmado.
	 * @return string
	 */
	private static function access($context, $object_id, $token = '') {
		if (!MJPM_Receipts::context($context)) {
			return '';
		}
		if (MJPM_Receipts::can_review_context($context)) {
			return 'review';
		}
		if (is_user_logged_in() && MJPM_Receipts::can_upload($context, $object_id)) {
			return 'owner';
		}
		if ('' !== (string) $token && MJPM_Settings::receipts('guest_links')) {
			$payload = MJPM_Receipts::verify_token($token);
			if ($payload && $payload['context'] === sanitize_key($context) && (int) $payload['object_id'] === (int) $object_id) {
				return 'token';
			}
		}
		return '';
	}

	private static function target(WP_REST_Request $request) {
		$context = sanitize_key((string) $request->get_param('context'));
		$object  = (int) $request->get_param('objeto');
		$token   = (string) $request->get_param('token');

		/* El token ya dice a qué compra apunta: alcanza con mandarlo solo. */
		if ($object <= 0 && '' !== $token) {
			$payload = MJPM_Receipts::verify_token($token);
			if ($payload) {
				$context = $payload['context'];
				$object  = $payload['object_id'];
			}
		}
		return ['context' => '' !== $context ? $context : 'order', 'object_id' => $object, 'token' => $token];
	}

	private static function deny($message = 'No tenés permiso para esto.', $code = 'mjpm_forbidden', $status = 403) {
		return new WP_Error($code, $message, ['status' => $status]);
	}

	/* ------------------------------- Config ------------------------------- */

	/** Todo lo que el widget de subida necesita para dibujarse. */
	public static function config(WP_REST_Request $request) {
		$t      = self::target($request);
		$access = self::access($t['context'], $t['object_id'], $t['token']);
		if ('' === $access) {
			return self::deny('No encontramos esa compra.', 'mjpm_not_found', 404);
		}
		return rest_ensure_response(self::payload($t['context'], $t['object_id'], $access, $t['token']));
	}

	/** Respuesta estándar: regla, lo ya subido y el estado de la compra. */
	public static function payload($context, $object_id, $access = 'owner', $token = '') {
		$req    = MJPM_Receipts::requirement($context, $object_id);
		$object = MJPM_Receipts::object($context, $object_id);
		$rows   = MJPM_Receipts::for_object($context, $object_id);
		$files  = [];

		foreach ($rows as $row) {
			$files[] = [
				'id'        => $row['id'],
				'name'      => $row['file_name'],
				'size'      => $row['size_text'],
				'isImage'   => $row['is_image'],
				'status'    => $row['status'],
				'statusLabel' => $row['status_label'],
				'reason'    => $row['reason'],
				'amount'    => $row['amount'],
				'amountText' => $row['amount_text'],
				'reference' => $row['reference'],
				'note'      => $row['note'],
				'date'      => $row['created_at'],
				'available' => $row['available'],
				'url'       => $row['available'] ? MJPM_Receipts::file_url($row, false, 'token' === $access ? $token : '') : '',
				'canDelete' => MJPM_Receipts::STATUS_PENDING === $row['status'] && 'review' !== $access,
			];
		}

		$state     = MJPM_Receipts::state($context, $object_id);
		$remaining = max(0, (int) $req['files'] - count($rows));
		$balance   = MJPM_Receipts::balance($context, $object_id);

		return [
			'context'     => $context,
			'objectId'    => (int) $object_id,
			'noun'        => MJPM_Receipts::noun(),
			'nounPlural'  => MJPM_Receipts::noun(true),
			'mode'        => $req['mode'],
			'asks'        => (bool) $req['asks'],
			'required'    => (bool) $req['required'],
			'text'        => $req['text'],
			'maxFiles'    => (int) $req['files'],
			'remaining'   => $remaining,
			'maxBytes'    => (int) $req['max_bytes'],
			'maxSizeText' => MJPM_Vault::size_text((int) $req['max_bytes']),
			'accept'      => $req['accept'],
			'typesText'   => MJPM_Vault::types_text(),
			'askAmount'   => (bool) $req['ask_amount'],
			'askReference' => (bool) $req['ask_reference'],
			'askNote'     => (bool) $req['ask_note'],
			'deadline'    => MJPM_Receipts::deadline($context, $object_id),
			'state'       => $state,
			'stateLabel'  => '' !== $state ? MJPM_Receipts::status_label($state) : '',
			/* Lo que se le dijo con la última decisión (motivo del rechazo o mensaje al aprobar). */
			'message'     => '' !== $state && MJPM_Receipts::STATUS_PENDING !== $state ? MJPM_Receipts::state_reason($context, $object_id) : '',
			/* Cuánto pagó y cuánto falta (null donde no se lleva saldo). */
			'balance'     => $balance,
			'currency'    => $object ? (string) $object['currency'] : '',
			'total'       => $object ? (float) $object['total'] : 0,
			'title'       => $object ? (string) $object['title'] : '',
			'files'       => $files,
			/*
			 * Quien revisa también puede subir: pasa seguido que el cliente manda el
			 * comprobante por WhatsApp y hay que adjuntarlo a mano para que quede
			 * junto a la compra.
			 */
			'canUpload'   => $remaining > 0 && (bool) $req['asks'],
		];
	}

	/* -------------------------------- Token -------------------------------- */

	/**
	 * Token de subida de un pedido, a cambio de su clave.
	 *
	 * Es para la tienda React: quien compró sin cuenta termina el checkout con el
	 * id y la clave del pedido en la mano (los devuelve la Store API), y con eso
	 * puede subir el comprobante ahí mismo sin registrarse. Es la misma prueba de
	 * propiedad que usa la página de gracias de WooCommerce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function token(WP_REST_Request $request) {
		if (!MJPM_Receipts::enabled() || !MJPM_Settings::receipts('guest_links')) {
			return self::deny('No se están pidiendo comprobantes.', 'mjpm_disabled', 400);
		}
		if (!function_exists('wc_get_order')) {
			return self::deny('No encontramos ese pedido.', 'mjpm_not_found', 404);
		}

		$order = wc_get_order((int) $request->get_param('pedido'));
		$key   = (string) $request->get_param('clave');
		if (!$order instanceof WC_Order || '' === $key || !hash_equals((string) $order->get_order_key(), $key)) {
			return self::deny('No encontramos ese pedido.', 'mjpm_not_found', 404);
		}

		$req = MJPM_Receipts::requirement('order', $order->get_id());
		if (!$req['asks']) {
			return rest_ensure_response(['token' => '', 'asks' => false]);
		}

		$token = MJPM_Receipts::upload_token('order', $order->get_id());
		return rest_ensure_response([
			'token'    => $token,
			'asks'     => true,
			'context'  => 'order',
			'objectId' => $order->get_id(),
		] + self::payload('order', $order->get_id(), 'token', $token));
	}

	/* -------------------------------- Subir -------------------------------- */

	public static function upload(WP_REST_Request $request) {
		if (!MJPM_Receipts::enabled()) {
			return self::deny('Los comprobantes están desactivados.', 'mjpm_disabled', 400);
		}

		$t      = self::target($request);
		$access = self::access($t['context'], $t['object_id'], $t['token']);
		if ('' === $access) {
			return self::deny('No encontramos esa compra.', 'mjpm_not_found', 404);
		}

		$req = MJPM_Receipts::requirement($t['context'], $t['object_id']);
		if (!$req['asks']) {
			return self::deny('Esa compra no pide comprobante.', 'mjpm_not_asked', 400);
		}

		$already = MJPM_Receipts::for_object($t['context'], $t['object_id']);
		if (count($already) >= (int) $req['files']) {
			return self::deny(
				sprintf('Ya subiste %d %s para esa compra.', count($already), MJPM_Receipts::noun(count($already) > 1)),
				'mjpm_too_many',
				409
			);
		}

		$limited = self::rate_limited();
		if ($limited) {
			return $limited;
		}

		$files = $request->get_file_params();
		$file  = isset($files['archivo']) ? $files['archivo'] : null;
		if (is_array($file) && isset($file['name']) && is_array($file['name'])) {
			return self::deny('Subí los archivos de a uno.', 'mjpm_one_at_a_time', 400);
		}
		if (!$file) {
			return self::deny('No llegó ningún archivo.', 'mjpm_no_file', 400);
		}

		$stored = MJPM_Vault::store($file);
		if (is_wp_error($stored)) {
			$stored->add_data(['status' => 400]);
			return $stored;
		}

		/* El mismo archivo dos veces no se guarda dos veces. */
		foreach ($already as $row) {
			if ('' !== $stored['hash'] && $row['file_hash'] === $stored['hash'] && !$row['purged']) {
				MJPM_Vault::delete($stored['path']);
				return self::deny('Ese archivo ya lo habías subido.', 'mjpm_duplicate', 409);
			}
		}

		$object = MJPM_Receipts::object($t['context'], $t['object_id']);

		$id = MJPM_Receipts::create([
			'context'     => $t['context'],
			'object_id'   => $t['object_id'],
			'method'      => $object ? (string) $object['method'] : '',
			'amount'      => $req['ask_amount'] ? MJPM_Settings::decimal($request->get_param('importe')) : 0,
			'currency'    => $object ? (string) $object['currency'] : '',
			'reference'   => $req['ask_reference'] ? (string) $request->get_param('referencia') : '',
			'note'        => $req['ask_note'] ? (string) $request->get_param('nota') : '',
			'name'        => $stored['name'],
			'path'        => $stored['path'],
			'mime'        => $stored['mime'],
			'size'        => $stored['size'],
			'hash'        => $stored['hash'],
			'uploaded_by' => get_current_user_id(),
		]);
		if (is_wp_error($id)) {
			MJPM_Vault::delete($stored['path']);
			$id->add_data(['status' => 500]);
			return $id;
		}

		/* La compra vuelve a "a revisar" en cuanto entra algo nuevo. */
		MJPM_Receipts::mark_pending($t['context'], $t['object_id']);

		$response = rest_ensure_response(self::payload($t['context'], $t['object_id'], $access, $t['token']));
		$response->set_status(201);
		return $response;
	}

	/** Tope de subidas por IP y por hora: el endpoint del link firmado es público. */
	private static function rate_limited() {
		/**
		 * Cuántos comprobantes puede subir una misma IP por hora.
		 *
		 * @param int $max Tope.
		 */
		$max = (int) apply_filters('mjpm_receipt_rate_limit', 20);
		if ($max <= 0) {
			return null;
		}
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		if ('' === $ip) {
			return null;
		}
		$key   = 'mjpm_rl_' . md5($ip);
		$count = (int) get_transient($key);
		if ($count >= $max) {
			return new WP_Error('mjpm_rate_limited', 'Demasiadas subidas seguidas. Esperá un rato y probá de nuevo.', ['status' => 429]);
		}
		set_transient($key, $count + 1, HOUR_IN_SECONDS);
		return null;
	}

	/* -------------------------------- Borrar ------------------------------- */

	public static function remove(WP_REST_Request $request) {
		$row = MJPM_Receipts::find((int) $request->get_param('id'));
		if (!$row) {
			return self::deny('Ese comprobante no existe.', 'mjpm_not_found', 404);
		}
		$token  = (string) $request->get_param('token');
		$access = self::access($row['context'], $row['object_id'], $token);
		if ('' === $access) {
			return self::deny();
		}
		/* El cliente solo puede quitar lo que todavía nadie revisó. */
		if ('review' !== $access && MJPM_Receipts::STATUS_PENDING !== $row['status']) {
			return self::deny('Ese comprobante ya fue revisado: escribinos si hay un error.', 'mjpm_already_reviewed', 409);
		}

		$result = MJPM_Receipts::delete($row['id']);
		if (is_wp_error($result)) {
			return $result;
		}
		return rest_ensure_response(self::payload($row['context'], $row['object_id'], $access, $token));
	}

	/* ------------------------------- Archivo ------------------------------- */

	public static function file(WP_REST_Request $request) {
		$row = MJPM_Receipts::find((int) $request->get_param('id'));
		if (!$row) {
			return self::deny('Ese archivo no existe.', 'mjpm_not_found', 404);
		}
		$access = self::access($row['context'], $row['object_id'], (string) $request->get_param('token'));
		if ('' === $access) {
			return self::deny();
		}
		if ($row['purged'] || !$row['available']) {
			return self::deny('Ese archivo ya no está guardado.', 'mjpm_purged', 410);
		}
		MJPM_Vault::stream($row['file_path'], $row['file_name'], $row['file_type'], (bool) $request->get_param('descargar'));
		exit;
	}

	/* ------------------------------ Revisión ------------------------------- */

	public static function review(WP_REST_Request $request) {
		$result = MJPM_Receipts::review(
			(int) $request->get_param('id'),
			sanitize_key((string) $request->get_param('decision')),
			(string) $request->get_param('motivo')
		);
		if (is_wp_error($result)) {
			return $result;
		}
		return rest_ensure_response(['ok' => true, 'comprobante' => $result]);
	}

	public static function review_object(WP_REST_Request $request) {
		$t      = self::target($request);
		$result = MJPM_Receipts::review_object(
			$t['context'],
			$t['object_id'],
			sanitize_key((string) $request->get_param('decision')),
			(string) $request->get_param('motivo'),
			null === $request->get_param('cascada') ? true : (bool) $request->get_param('cascada'),
			null === $request->get_param('importe') || '' === (string) $request->get_param('importe') ? null : MJPM_Settings::decimal($request->get_param('importe'))
		);
		if (is_wp_error($result)) {
			return $result;
		}
		return rest_ensure_response(['ok' => true] + self::payload($t['context'], $t['object_id'], 'review'));
	}
}
