<?php
/**
 * Comprobante de la seña, sobre la bóveda de MJ Medios de Pago.
 *
 * Hasta acá el circuito de la seña terminaba en un botón "ya transferí" y un
 * link de WhatsApp: la dueña tenía que ir a buscar el comprobante a otra app.
 * Esto cierra ese hueco sin cambiar el circuito: registra el contexto 'turno'
 * en MJ Medios de Pago y conecta las dos decisiones.
 *
 *   aprobar el comprobante  →  seña marcada como pagada y turno confirmado
 *                              (si la opción "Al aprobar" está prendida)
 *   rechazar el comprobante →  el turno sigue esperando la seña y el motivo
 *                              queda en la nota interna
 *
 * CÓMO SE AUTORIZA A LA CLIENTA
 *   La clienta no tiene cuenta: llega a su turno con el token de la reserva. Así
 *   que el servidor le entrega, junto al estado del turno, un token de subida
 *   firmado por MJ Medios de Pago. Solo lo recibe quien ya demostró tener el
 *   token del turno, y vence solo.
 *
 * Sin MJ Medios de Pago activo nada de esto existe y el plugin funciona igual
 * que antes.
 *
 * @package MJ_Turnos_Online
 */

defined('ABSPATH') || exit;

/** Clave del contexto dentro de MJ Medios de Pago. */
const MJTO_RECEIPT_CONTEXT = 'turno';

/** ¿Están disponibles los comprobantes? */
function mjto_receipts_available() {
	return class_exists('MJPM_Receipts')
		&& is_callable(['MJPM_Receipts', 'enabled'])
		&& MJPM_Receipts::enabled();
}

/** Modo configurado: no | opcional | obligatorio. */
function mjto_receipt_mode() {
	if (!mjto_receipts_available()) {
		return 'no';
	}
	$mode = (string) mjto_opt('deposit_receipt');
	return in_array($mode, ['no', 'opcional', 'obligatorio'], true) ? $mode : 'no';
}

/** ¿Se le pide comprobante de la seña? */
function mjto_receipt_asks() {
	return 'no' !== mjto_receipt_mode();
}

/*
 * El filtro se engancha al cargar el archivo, no en un hook: MJ Medios de Pago
 * cachea el registro de contextos la primera vez que alguien lo pide, y si eso
 * pasa antes de 'init' este contexto se quedaría afuera de esa carga.
 */
add_filter('mjpm_receipt_contexts', 'mjto_register_receipt_context');

/**
 * Contexto 'turno'.
 *
 * @param array $contexts Registro de MJ Medios de Pago.
 * @return array
 */
function mjto_register_receipt_context($contexts) {
	if (!is_array($contexts)) {
		return $contexts;
	}
	$contexts[MJTO_RECEIPT_CONTEXT] = [
		'label'       => 'Señas de turnos',
		'singular'    => 'seña',
		'femenino'    => true,
		'object'      => 'mjto_receipt_object',
		'requirement' => 'mjto_receipt_requirement',
		'can_upload'  => 'mjto_receipt_can_upload',
		'can_review'  => 'mjto_receipt_can_review',
		'state'       => 'mjto_receipt_state',
		'set_state'   => 'mjto_receipt_set_state',
		'link'        => 'mjto_receipt_link',
	];
	return $contexts;
}

/**
 * Ficha del turno para la bandeja y los mails.
 *
 * @param int $appointment_id Id del turno.
 * @return array|null
 */
function mjto_receipt_object($appointment_id) {
	$raw = mjto_get_appointment((int) $appointment_id);
	if (!$raw) {
		return null;
	}
	$row      = mjto_appointment_public($raw);
	$currency = function_exists('mjto_currency') ? mjto_currency() : [];
	$client = isset($row['client']) && is_array($row['client']) ? $row['client'] : [];
	$name   = trim((string) ($client['name'] ?? ''));
	$starts = (string) ($row['startsAt'] ?? '');

	return [
		'id'           => (int) $row['id'],
		'title'        => trim(
			(string) ($row['serviceName'] ?? 'Turno') .
			('' !== $starts ? ' · ' . date_i18n('j M H:i', strtotime($starts)) : '')
		),
		'url'          => mjto_receipt_link((int) $row['id']),
		'admin_url'    => mjto_receipt_panel_url((int) $row['id']),
		'customer'     => '' !== $name ? $name : 'Clienta',
		'email'        => (string) ($client['email'] ?? ''),
		'phone'        => (string) ($client['phone'] ?? ''),
		/* Lo que tenía que transferir: la seña menos el saldo a favor que ya usó. */
		'total'        => max(0, (float) ($row['depositRequired'] ?? 0) - (float) ($row['creditApplied'] ?? 0)),
		'currency'     => strtoupper((string) ($currency['code'] ?? 'ARS')),
		'status_label' => (string) ($row['statusLabel'] ?? ''),
		'method'       => (string) ($row['depositMethod'] ?? ''),
		'method_label' => (string) ($row['depositMethod'] ?? ''),
		'date'         => (string) ($raw['created_at'] ?? $starts),
	];
}

/**
 * Qué se le pide: el modo sale de los ajustes de turnos, el resto (tipos,
 * tamaño, cuántos, qué campos) de los ajustes generales de comprobantes.
 *
 * @param int $appointment_id Id del turno.
 * @return array
 */
function mjto_receipt_requirement($appointment_id) {
	$row = mjto_get_appointment((int) $appointment_id);
	if (!$row) {
		return ['mode' => 'no'];
	}
	/* Sin seña que pagar no hay nada que comprobar. */
	if ((float) ($row['deposit_required'] ?? 0) <= 0) {
		return ['mode' => 'no'];
	}

	$req = [
		'mode'      => mjto_receipt_mode(),
		'text'      => trim((string) mjto_opt('deposit_receipt_text')),
		'hours'     => (int) mjto_opt('deposit_hours'),
		'on_ok'     => '',
		'on_reject' => '',
		'attach'    => 0,
	];

	/**
	 * Regla del comprobante de una seña.
	 *
	 * @param array $req Regla.
	 * @param array $row Turno.
	 */
	return (array) apply_filters('mjto_receipt_requirement', $req, $row);
}

/**
 * La clienta no tiene cuenta: sube con el token firmado que le damos junto al
 * estado del turno, así que por sesión no autoriza a nadie más que a la dueña.
 *
 * @param int $appointment_id Id del turno.
 * @param int $user_id        Id de usuario.
 * @return bool
 */
function mjto_receipt_can_upload($appointment_id, $user_id) {
	unset($appointment_id);
	return $user_id > 0 && user_can($user_id, 'mjto_manage');
}

/** Quién revisa: quien maneja la agenda. */
function mjto_receipt_can_review() {
	if (current_user_can('mjto_manage')) {
		return true;
	}
	return class_exists('MJPM_Receipts') && MJPM_Receipts::can_review();
}

/**
 * Estado, derivado de lo que ya sabe el turno.
 *
 * @param int $appointment_id Id del turno.
 * @return string
 */
function mjto_receipt_state($appointment_id) {
	$row = mjto_get_appointment((int) $appointment_id);
	if (!$row) {
		return '';
	}
	/* La seña ya cobrada entera es un comprobante aprobado (una parte cubierta con saldo a favor no alcanza). */
	if ((float) ($row['deposit_paid'] ?? 0) > 0 && (float) $row['deposit_paid'] + 0.004 >= (float) ($row['deposit_required'] ?? 0)) {
		return MJPM_Receipts::STATUS_OK;
	}

	$rejected = false;
	foreach (MJPM_Receipts::for_object(MJTO_RECEIPT_CONTEXT, (int) $appointment_id) as $file) {
		if (MJPM_Receipts::STATUS_PENDING === $file['status']) {
			return MJPM_Receipts::STATUS_PENDING;
		}
		if (MJPM_Receipts::STATUS_NO === $file['status']) {
			$rejected = true;
		}
	}
	return $rejected ? MJPM_Receipts::STATUS_NO : MJPM_Receipts::STATUS_PENDING;
}

/**
 * La decisión del comprobante es la decisión de la seña.
 *
 * @param int    $appointment_id Id del turno.
 * @param string $decision       aprobado | rechazado | pendiente.
 * @param string $reason         Motivo.
 * @return void
 */
function mjto_receipt_set_state($appointment_id, $decision, $reason = '') {
	$row = mjto_get_appointment((int) $appointment_id);
	if (!$row) {
		return;
	}

	if (MJPM_Receipts::STATUS_OK === $decision) {
		if (!mjto_opt('deposit_receipt_confirm')) {
			return;
		}
		/* Ya está confirmado y cobrado: no hay nada que hacer. */
		if ('confirmed' === (string) $row['status'] && (float) ($row['deposit_paid'] ?? 0) + 0.004 >= (float) ($row['deposit_required'] ?? 0)) {
			return;
		}
		mjto_set_status(
			(int) $appointment_id,
			'confirmed',
			[
				'notify'       => true,
				'by'           => 'owner',
				'deposit_paid' => (float) ($row['deposit_required'] ?? 0),
			]
		);
		return;
	}

	if (MJPM_Receipts::STATUS_NO === $decision) {
		if ('' !== trim((string) $reason)) {
			mjto_receipt_add_note((int) $appointment_id, sprintf('Comprobante de la seña rechazado: %s', trim((string) $reason)));
		}
		/*
		 * Rechazado: vuelve a correr el plazo para mandar otro. Sin esto el turno
		 * quedaba retenido para siempre (el aviso de "ya transferí" lo frena).
		 */
		$hours = max(0, (int) mjto_opt('deposit_hours'));
		if ('awaiting_deposit' === (string) $row['status'] && $hours > 0) {
			mjto_update_appointment((int) $appointment_id, [
				'deposit_notified_at' => null,
				'deposit_deadline'    => current_datetime()->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s'),
			]);
		}
	}
}

/*
 * Subir el comprobante es avisar que transfirió: el turno deja de poder
 * vencer (la dueña lo está revisando) y ella recibe el aviso, igual que con
 * "Ya transferí la seña".
 */
add_action('mjpm_receipt_created', function ($id, $row) {
	if (!is_array($row) || MJTO_RECEIPT_CONTEXT !== (string) ($row['context'] ?? '')) {
		return;
	}
	$appointment = mjto_get_appointment((int) ($row['object_id'] ?? 0));
	if (!$appointment || !in_array((string) $appointment['status'], ['awaiting_deposit', 'pending'], true)) {
		return;
	}
	/* Solo la marca: el aviso a la dueña ya lo manda MJ Medios de Pago (si no, le llegarían dos mails). */
	if (empty(mjto_dt_or_null($appointment['deposit_notified_at'] ?? ''))) {
		mjto_update_appointment((int) $appointment['id'], ['deposit_notified_at' => mjto_now()]);
		do_action('mjto_deposit_notified', mjto_get_appointment((int) $appointment['id']), '', true);
	}
}, 10, 2);

/**
 * Deja el motivo del rechazo en la nota interna del turno, que es donde la dueña
 * lo va a leer cuando la clienta escriba preguntando.
 *
 * @param int    $appointment_id Id del turno.
 * @param string $text           Texto.
 * @return void
 */
function mjto_receipt_add_note($appointment_id, $text) {
	$row = mjto_appointment_row((int) $appointment_id);
	if (!$row) {
		return;
	}
	$note = trim((string) ($row['internal_notes'] ?? ''));
	if (false !== strpos($note, $text)) {
		return;
	}
	$note = '' !== $note ? $note . "\n" . $text : $text;
	mjto_update_appointment((int) $appointment_id, ['internal_notes' => mb_substr($note, 0, 2000)]);
}

/**
 * Dónde lo sube: su propia página de estado del turno.
 *
 * @param int $appointment_id Id del turno.
 * @return string
 */
function mjto_receipt_link($appointment_id) {
	$row = mjto_appointment_row((int) $appointment_id);
	if (!$row || empty($row['token']) || !function_exists('mjto_booking_status_url')) {
		return '';
	}
	return (string) mjto_booking_status_url((string) $row['token']);
}

/**
 * Link al panel, en la lista de turnos.
 *
 * El panel enruta por path (BrowserRouter) y no tiene una pantalla por turno
 * suelto: la lista es el lugar donde se lo busca.
 *
 * @param int $appointment_id Id del turno.
 * @return string
 */
function mjto_receipt_panel_url($appointment_id) {
	unset($appointment_id);
	if (!function_exists('mjto_panel_url')) {
		return '';
	}
	return trailingslashit((string) mjto_panel_url()) . 'turnos';
}

/**
 * Bloque de comprobante para la respuesta pública del turno.
 *
 * Incluye el token de subida firmado: se lo damos a quien ya tiene el token de
 * la reserva, y con eso el widget de MJ Medios de Pago puede subir sin sesión.
 *
 * @param array $public Datos públicos del turno.
 * @return array|null null cuando no se pide comprobante.
 */
function mjto_receipt_public_payload(array $public) {
	if (!mjto_receipts_available() || empty($public['id'])) {
		return null;
	}
	$id  = (int) $public['id'];
	$req = MJPM_Receipts::requirement(MJTO_RECEIPT_CONTEXT, $id);
	if (empty($req['asks'])) {
		return null;
	}
	$state = mjto_receipt_state($id);
	$files = MJPM_Receipts::for_object(MJTO_RECEIPT_CONTEXT, $id);

	$reason = '';
	foreach ($files as $file) {
		if (MJPM_Receipts::STATUS_NO === $file['status'] && '' !== (string) $file['reason']) {
			$reason = (string) $file['reason'];
		}
	}

	return [
		'context'    => MJTO_RECEIPT_CONTEXT,
		'objectId'   => $id,
		'token'      => MJPM_Receipts::upload_token(MJTO_RECEIPT_CONTEXT, $id),
		'noun'       => MJPM_Receipts::noun(),
		'nounTitle'  => MJPM_Receipts::noun(false, true),
		'required'   => !empty($req['required']),
		'text'       => (string) $req['text'],
		'count'      => count($files),
		'state'      => $state,
		'stateLabel' => '' !== $state ? MJPM_Receipts::status_label($state) : '',
		'reason'     => $reason,
	];
}

/**
 * Bloque de comprobante para el panel (sin token: ahí hay sesión).
 *
 * @param int $appointment_id Id del turno.
 * @return array|null
 */
function mjto_receipt_panel_payload($appointment_id) {
	if (!mjto_receipts_available()) {
		return null;
	}
	$id  = (int) $appointment_id;
	$req = MJPM_Receipts::requirement(MJTO_RECEIPT_CONTEXT, $id);
	if (empty($req['asks'])) {
		return null;
	}
	$files = MJPM_Receipts::for_object(MJTO_RECEIPT_CONTEXT, $id);
	$state = mjto_receipt_state($id);
	$list  = [];

	foreach ($files as $file) {
		$list[] = [
			'id'          => $file['id'],
			'name'        => $file['file_name'],
			'status'      => $file['status'],
			'statusLabel' => $file['status_label'],
			'isImage'     => $file['is_image'],
			'amountText'  => $file['amount_text'],
			'reference'   => $file['reference'],
			'url'         => $file['available'] ? MJPM_Receipts::file_url($file) : '',
			'date'        => $file['created_at'],
		];
	}

	return [
		'context'    => MJTO_RECEIPT_CONTEXT,
		'objectId'   => $id,
		'noun'       => MJPM_Receipts::noun(),
		'nounTitle'  => MJPM_Receipts::noun(false, true),
		'required'   => !empty($req['required']),
		'state'      => $state,
		'stateLabel' => '' !== $state ? MJPM_Receipts::status_label($state) : '',
		'files'      => $list,
		'reviewUrl'  => class_exists('MJPM_Receipts_Admin')
			? MJPM_Receipts_Admin::page_url([
				'estado'   => 'todos',
				'contexto' => MJTO_RECEIPT_CONTEXT,
				's'        => $id,
			])
			: '',
	];
}
