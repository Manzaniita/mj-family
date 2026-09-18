<?php
/**
 * Cambios y cancelaciones con reglas (Turnos → Ajustes → Cambios y
 * cancelaciones), saldo a favor y la cancelación de la dueña con opciones.
 *
 * La regla se decide en un solo lugar para cada acción:
 *   mjto_cancel_terms()     ¿puede cancelar? ¿qué pasa con la seña?
 *   mjto_reschedule_terms() ¿puede reprogramar? ¿conserva la seña o abona otra?
 * La página del turno muestra el texto que devuelven antes de confirmar, y las
 * acciones (mjto_policy_client_cancel, mjto_client_reschedule) vuelven a
 * pedir la regla en el momento: lo que se ve es lo que se aplica.
 *
 * LA SEÑA Y EL SALDO
 *   deposit_paid    lo cubierto de la seña (transferencia + saldo usado)
 *   credit_applied  la parte que salió del saldo a favor
 *   deposit_forfeited  lo que se perdió (no vino, canceló o reprogramó tarde)
 * Si una reserva no llega a confirmarse (vence, cancela), el saldo usado
 * vuelve. Una vez confirmada, el saldo usado es seña pagada como cualquier otra.
 *
 * Todo apagado por defecto: sin tocar los ajustes el plugin se comporta como
 * antes (la clienta cancela con la anticipación de siempre, sin saldo).
 *
 * @package MJ_Turnos_Online
 */

defined('ABSPATH') || exit;

/** Horas que faltan para el turno (negativo si ya empezó). */
function mjto_hours_until(array $row) {
	$ts = mjto_local_ts($row['starts_at'] ?? '');
	if (null === $ts) {
		return -1.0;
	}
	return ($ts - current_datetime()->getTimestamp()) / HOUR_IN_SECONDS;
}

/**
 * Motivo de un movimiento del saldo a favor (lo ve la clienta en "Mis
 * turnos" y la dueña en la ficha). Texto de Cambios y cancelaciones con
 * {servicio} y {fecha}.
 */
function mjto_credit_note($key, array $row) {
	return strtr((string) mjto_opt($key), [
		'{servicio}' => (string) ($row['service_name'] ?? ''),
		'{fecha}'    => mjto_date_label($row['starts_at'] ?? ''),
	]);
}

/** Lo que falta transferir de la seña. */
function mjto_deposit_due(array $row) {
	return round(max(0.0, (float) ($row['deposit_required'] ?? 0) - (float) ($row['deposit_paid'] ?? 0)), 2);
}

/** ¿Está prendido el saldo a favor? */
function mjto_credit_enabled() {
	return (bool) (int) mjto_opt('credit_enabled');
}

/** Rellena {horas}, {sena}, {sena_pagada} y {monto} de un texto de las reglas. */
function mjto_policy_text($text, array $row, $hours = null, $amount = null) {
	$paid = (float) ($row['deposit_paid'] ?? 0);
	return strtr((string) $text, [
		'{horas}'       => null === $hours ? '' : (string) (int) $hours,
		'{sena}'        => mjto_format_price(mjto_deposit_due($row) > 0 ? mjto_deposit_due($row) : (float) ($row['deposit_required'] ?? 0)),
		'{seña}'        => mjto_format_price(mjto_deposit_due($row) > 0 ? mjto_deposit_due($row) : (float) ($row['deposit_required'] ?? 0)),
		'{sena_pagada}' => mjto_format_price(null === $amount ? $paid : (float) $amount),
		'{seña_pagada}' => mjto_format_price(null === $amount ? $paid : (float) $amount),
		'{monto}'       => mjto_format_price(null === $amount ? $paid : (float) $amount),
	]);
}

/**
 * Texto de {seña_resultado}: credit | lost | kept | new ('' = nada que decir).
 * $amount: el monto del que se habla (default: la seña pagada del turno).
 */
function mjto_deposit_result_text($kind, array $row, $amount = null) {
	$keys = ['credit' => 'deposit_result_credit', 'lost' => 'deposit_result_lost', 'kept' => 'deposit_result_kept', 'new' => 'deposit_result_new'];
	if (!isset($keys[$kind])) {
		return '';
	}
	return mjto_policy_text((string) mjto_opt($keys[$kind]), $row, null, $amount);
}

/* -------------------------------------------------------------------------
 * Saldo a favor en la seña
 * ---------------------------------------------------------------------- */

/**
 * Usa el saldo a favor de la clienta en la seña de una reserva que la espera.
 * Si alcanza, el turno queda confirmado sin transferir nada. → estado final.
 */
function mjto_apply_credit_to_booking($appointment_id) {
	$row = mjto_get_appointment($appointment_id);
	if (!$row) {
		return '';
	}
	$status = (string) $row['status'];
	if ('awaiting_deposit' !== $status || !mjto_credit_enabled()) {
		return $status;
	}
	$need    = mjto_deposit_due($row);
	$balance = mjto_credit_balance((int) $row['client_id']);
	if ($need <= 0 || $balance <= 0) {
		return $status;
	}
	$use  = round(min($need, $balance), 2);
	$note = mjto_credit_note('credit_note_applied', $row);
	if (!mjto_add_credit((int) $row['client_id'], -$use, 'applied', $note, (int) $row['id'])) {
		return $status;
	}
	$paid = round((float) $row['deposit_paid'] + $use, 2);
	mjto_update_appointment((int) $row['id'], [
		'credit_applied' => round((float) $row['credit_applied'] + $use, 2),
		'deposit_paid'   => $paid,
	]);
	if ($paid + 0.004 >= (float) $row['deposit_required']) {
		mjto_update_appointment((int) $row['id'], ['deposit_deadline' => null]);
		$fresh = mjto_set_status((int) $row['id'], 'confirmed', [
			'notify'         => false,
			'by'             => 'system',
			'deposit_paid'   => $paid,
			'deposit_method' => (string) mjto_opt('label_credit'),
		]);
		return is_wp_error($fresh) ? $status : 'confirmed';
	}
	return $status;
}

/**
 * Devuelve al saldo lo que una reserva no confirmada había usado (vence,
 * cancela antes de pagar el resto). → monto devuelto.
 */
function mjto_return_applied_credit(array $row, $reason = '') {
	$applied = round((float) ($row['credit_applied'] ?? 0), 2);
	if ($applied <= 0) {
		return 0.0;
	}
	$reason = '' !== $reason ? $reason : mjto_credit_note('credit_note_returned', $row);
	mjto_add_credit((int) $row['client_id'], $applied, 'returned', $reason, (int) $row['id']);
	mjto_update_appointment((int) $row['id'], [
		'credit_applied' => 0,
		'deposit_paid'   => max(0, round((float) $row['deposit_paid'] - $applied, 2)),
	]);
	return $applied;
}

/* -------------------------------------------------------------------------
 * Reglas
 * ---------------------------------------------------------------------- */

/**
 * ¿La clienta puede cancelar? → ['allowed', 'late', 'keepsDeposit', 'loses',
 * 'hours' (umbral), 'text'].
 */
function mjto_cancel_terms(array $row) {
	$terms = ['allowed' => false, 'late' => false, 'keepsDeposit' => false, 'loses' => false, 'hours' => (int) mjto_opt('cancel_hours_before'), 'text' => ''];
	if (!(int) mjto_opt('client_can_cancel') || !in_array((string) $row['status'], ['pending', 'awaiting_deposit', 'confirmed'], true)) {
		return $terms;
	}
	$until = mjto_hours_until($row);
	if ($until <= 0) {
		return $terms;
	}
	$paid = max(0.0, (float) $row['deposit_paid'] - ('confirmed' === (string) $row['status'] ? 0.0 : (float) $row['credit_applied']));
	if ($until >= $terms['hours']) {
		$terms['allowed']      = true;
		$terms['keepsDeposit'] = $paid > 0 && 'credit' === (string) mjto_opt('deposit_on_cancel') && mjto_credit_enabled();
		$terms['text']         = $terms['keepsDeposit'] ? mjto_policy_text(mjto_opt('booking_cancel_keep'), $row, $terms['hours'], $paid) : '';
	} else {
		$terms['late']    = true;
		$terms['allowed'] = (bool) (int) mjto_opt('cancel_late_allowed');
		$terms['loses']   = $terms['allowed'] && $paid > 0;
		$terms['text']    = $terms['loses'] ? mjto_policy_text(mjto_opt('booking_cancel_lose'), $row, $terms['hours'], $paid) : '';
	}
	return (array) apply_filters('mjto_cancel_terms', $terms, $row);
}

/** Opciones que la dueña ofrece cuando cancela ella: reschedule | refund | credit. */
function mjto_offer_choices() {
	$out = [];
	if ((int) mjto_opt('owner_offer_reschedule')) {
		$out[] = 'reschedule';
	}
	if ((int) mjto_opt('owner_offer_refund')) {
		$out[] = 'refund';
	}
	if ((int) mjto_opt('owner_offer_credit') && mjto_credit_enabled()) {
		$out[] = 'credit';
	}
	return $out;
}

/** ¿El turno lo canceló la dueña y la clienta todavía tiene que elegir? */
function mjto_offer_open(array $row) {
	return 'cancelled' === (string) $row['status'] && 'open' === (string) ($row['owner_offer'] ?? '');
}

/**
 * ¿La clienta puede reprogramar? → ['allowed', 'mode' (move | new), 'late',
 * 'keepsDeposit', 'hours', 'text'].
 *   move  el turno se mueve y conserva la seña.
 *   new   el turno se cancela (la seña se pierde) y el nuevo espera su seña.
 */
function mjto_reschedule_terms(array $row) {
	$terms = ['allowed' => false, 'mode' => 'move', 'late' => false, 'keepsDeposit' => false, 'hours' => (int) mjto_opt('reschedule_hours_before'), 'text' => ''];

	/* La cancelación de la dueña con "otro día" abierto: siempre se mueve y conserva la seña. */
	if (mjto_offer_open($row)) {
		$terms['allowed']      = in_array('reschedule', mjto_offer_choices(), true);
		$terms['keepsDeposit'] = true;
		return (array) apply_filters('mjto_reschedule_terms', $terms, $row);
	}

	if (!(int) mjto_opt('client_can_reschedule') || !in_array((string) $row['status'], ['pending', 'awaiting_deposit', 'confirmed'], true)) {
		return $terms;
	}
	$until = mjto_hours_until($row);
	if ($until <= 0) {
		return $terms;
	}
	$paid = (float) $row['deposit_paid'];
	if ($until >= $terms['hours'] || $paid <= 0) {
		/* A tiempo, o sin seña pagada que perder: se mueve. */
		$terms['allowed']      = true;
		$terms['keepsDeposit'] = $paid > 0;
		$terms['text']         = $paid > 0 ? mjto_policy_text(mjto_opt('booking_reschedule_keep'), $row, $terms['hours']) : '';
	} elseif ('new_deposit' === (string) mjto_opt('reschedule_late')) {
		$terms['allowed'] = true;
		$terms['mode']    = 'new';
		$terms['late']    = true;
		$terms['text']    = mjto_policy_text(mjto_opt('booking_reschedule_lose'), $row, $terms['hours']);
	} else {
		$terms['late'] = true;
		$terms['text'] = mjto_policy_text(mjto_opt('booking_reschedule_blocked'), $row, $terms['hours']);
	}
	return (array) apply_filters('mjto_reschedule_terms', $terms, $row);
}

/* -------------------------------------------------------------------------
 * Acciones de la clienta
 * ---------------------------------------------------------------------- */

/**
 * Cancela según las reglas y resuelve la seña: el saldo usado en una reserva
 * sin confirmar vuelve; lo pagado queda como saldo (a tiempo, si está
 * configurado), se pierde (tarde) o queda para que lo resuelva la dueña.
 * → fila del turno o WP_Error.
 */
function mjto_policy_client_cancel(array $row) {
	return mjto_with_appointment_lock((int) $row['id'], 'mjto_policy_client_cancel_locked');
}

/** Cancelación de la clienta, con el candado del turno ya tomado. */
function mjto_policy_client_cancel_locked(array $row) {
	if ('cancelled' === (string) $row['status']) {
		return mjto_booking_error('already_cancelled', 400);
	}
	$terms = mjto_cancel_terms($row);
	if (!$terms['allowed']) {
		return mjto_booking_error('cannot_cancel', 400);
	}
	$confirmed = 'confirmed' === (string) $row['status'];
	$reason    = (string) apply_filters('mjto_client_cancel_reason', (string) mjto_opt('cancel_reason_client'), $row);
	$fresh     = mjto_set_status($row['id'], 'cancelled', ['by' => 'client', 'reason' => $reason, 'notify' => false]);
	if (is_wp_error($fresh)) {
		return $fresh;
	}

	$result = '';
	$amount = 0.0;
	$paid   = (float) $row['deposit_paid'];
	if (!$confirmed && (float) $row['credit_applied'] > 0) {
		$amount = mjto_return_applied_credit($fresh);
		$paid  -= $amount;
		$result = 'credit';
	}
	if ($paid > 0) {
		if ($terms['keepsDeposit']) {
			mjto_add_credit((int) $row['client_id'], $paid, 'deposit', mjto_credit_note('credit_note_cancel', $row), (int) $row['id']);
			$amount += $paid;
			$result  = 'credit';
		} elseif ($terms['late']) {
			mjto_update_appointment((int) $row['id'], ['deposit_forfeited' => $paid]);
			$amount = $paid;
			$result = 'lost';
		}
	}

	$fresh = mjto_get_appointment((int) $row['id']);
	if ($fresh && function_exists('mjto_notify_client')) {
		mjto_notify_client($fresh, 'cancelled', ['seña_resultado' => mjto_deposit_result_text($result, $fresh, $amount)]);
	}
	do_action('mjto_client_cancelled', $fresh, $result, $amount);
	return $fresh;
}

/**
 * Reprograma desde la web con las reglas. Con el horario nuevo libre (mismas
 * reglas que al reservar: anticipación, días abiertos, lo ocupado):
 *   move  mueve el turno (conserva la seña). Si venía de una cancelación de
 *         la dueña con opciones, además lo vuelve a confirmar.
 *   new   reserva un turno nuevo que espera su seña y cancela el viejo (la
 *         seña queda perdida).
 * → fila del turno resultante (el mismo o el nuevo) o WP_Error.
 */
function mjto_client_reschedule($token, $date, $time) {
	$row = mjto_get_appointment_by_token($token);
	if (!$row) {
		return mjto_booking_error('not_found', 404);
	}
	return mjto_with_appointment_lock((int) $row['id'], fn ($fresh) => mjto_client_reschedule_locked($fresh, $date, $time));
}

/** Reprogramado de la clienta, con el candado del turno ya tomado. */
function mjto_client_reschedule_locked(array $row, $date, $time) {
	$terms = mjto_reschedule_terms($row);
	if (!$terms['allowed']) {
		return mjto_booking_error('cannot_reschedule', 400);
	}
	$date = trim((string) $date);
	$time = mjto_normalize_time($time);
	if (!mjto_valid_date($date) || '' === $time) {
		return mjto_booking_error('invalid_date', 400);
	}
	if ($date . ' ' . $time . ':00' === (string) $row['starts_at']) {
		return mjto_booking_error('reschedule_same', 400);
	}

	$service = (int) $row['service_id'] > 0 ? mjto_get_service((int) $row['service_id']) : null;
	$spec    = mjto_service_spec(['duration' => (int) $row['duration_min'], 'buffer' => $service ? $service['buffer'] : null]);
	$old     = (string) $row['starts_at'];

	if ('new' === $terms['mode']) {
		/* El viejo se cancela primero (así su horario cuenta como libre); si el nuevo no sale, vuelve como estaba. */
		$paid      = (float) $row['deposit_paid'];
		$cancelled = mjto_set_status((int) $row['id'], 'cancelled', [
			'by'           => 'client',
			'reason'       => mjto_policy_text(mjto_opt('cancel_reason_late_reschedule'), $row, $terms['hours']),
			'notify'       => false,
			'notify_owner' => false,
		]);
		if (is_wp_error($cancelled)) {
			return $cancelled;
		}
		$returned = 0.0;
		if ('confirmed' !== (string) $row['status'] && (float) $row['credit_applied'] > 0) {
			$returned = mjto_return_applied_credit($cancelled); // Sin confirmar, el saldo usado no es seña perdida.
			$paid    -= $returned;
		}
		$new = mjto_create_booking(
			['service_id' => (int) $row['service_id'], 'date' => $date, 'time' => $time],
			'web',
			['client_id' => (int) $row['client_id'], 'rescheduled_from' => (int) $row['id'], 'notify_owner' => false]
		);
		if (is_wp_error($new)) {
			mjto_set_status((int) $row['id'], (string) $row['status'], ['notify' => false, 'by' => 'client']);
			return $new;
		}
		mjto_update_appointment((int) $row['id'], ['deposit_forfeited' => max(0, $paid)]);
		mjto_update_appointment((int) $new['id'], ['reschedule_count' => (int) $row['reschedule_count'] + 1]);
		$new = mjto_get_appointment((int) $new['id']);
		if ($new && function_exists('mjto_notify_owner')) {
			mjto_notify_owner($new, 'reschedule', [
				'fecha_anterior' => mjto_long_datetime($old),
				'seña_resultado' => mjto_deposit_result_text('lost', $row, $paid),
			]);
		}
		do_action('mjto_client_rescheduled', $new, $row, 'new');
		return $new;
	}

	/*
	 * move: el horario nuevo tiene que estar libre con las reglas de la web (sin
	 * contar este mismo turno). El candado del día nuevo queda tomado hasta que
	 * el turno está movido y, si venía de una cancelación de la dueña, otra vez
	 * confirmado: nadie puede reservar ese horario en el medio (toda reserva
	 * pide el mismo candado antes de mirar si está libre).
	 */
	if (!mjto_acquire_lock($date)) {
		return mjto_booking_error('busy', 503);
	}
	try {
		if (!mjto_slot_is_free($spec, $date, $time, (int) $row['id'], false)) {
			return mjto_booking_error('slot_taken', 409);
		}
		$was_offer = mjto_offer_open($row);
		$fresh     = mjto_reschedule((int) $row['id'], $date, $time, null, null, true, false); // Ya chequeado, con el candado.
		if (is_wp_error($fresh)) {
			return $fresh;
		}
		mjto_update_appointment((int) $row['id'], ['reschedule_count' => (int) $row['reschedule_count'] + 1]);
		if ($was_offer) {
			mjto_set_status((int) $row['id'], 'confirmed', ['notify' => false, 'by' => 'client']);
			mjto_update_appointment((int) $row['id'], ['owner_offer' => 'rescheduled']);
		}
	} finally {
		mjto_release_lock($date);
	}
	$fresh = mjto_get_appointment((int) $row['id']);
	$extra = [
		'fecha_anterior' => mjto_long_datetime($old),
		'seña_resultado' => (float) $fresh['deposit_paid'] > 0 ? mjto_deposit_result_text('kept', $fresh) : '',
	];
	if (function_exists('mjto_notify_client')) {
		mjto_notify_client($fresh, 'rescheduled', $extra);
	}
	if (function_exists('mjto_notify_owner')) {
		mjto_notify_owner($fresh, 'reschedule', $extra);
	}
	do_action('mjto_client_rescheduled', $fresh, $row, 'move');
	return $fresh;
}

/**
 * La clienta elige qué hacer con la seña de un turno que canceló la dueña:
 * refund (pide la devolución) o credit (saldo a favor). "Otro día" va por
 * mjto_client_reschedule. → fila del turno o WP_Error.
 */
function mjto_client_offer_choice($token, $choice) {
	$row = mjto_get_appointment_by_token($token);
	if (!$row) {
		return mjto_booking_error('not_found', 404);
	}
	return mjto_with_appointment_lock((int) $row['id'], fn ($fresh) => mjto_client_offer_choice_locked($fresh, $choice));
}

/** Elección de la clienta, con el candado del turno ya tomado. */
function mjto_client_offer_choice_locked(array $row, $choice) {
	$choice = sanitize_key((string) $choice);
	if (!mjto_offer_open($row) || !in_array($choice, mjto_offer_choices(), true) || 'reschedule' === $choice) {
		return mjto_booking_error('offer_closed', 400);
	}
	$paid = (float) $row['deposit_paid'];
	if ('credit' === $choice) {
		mjto_add_credit((int) $row['client_id'], $paid, 'deposit', mjto_credit_note('credit_note_offer', $row), (int) $row['id']);
		mjto_update_appointment((int) $row['id'], ['owner_offer' => 'credited']);
	} else {
		mjto_update_appointment((int) $row['id'], ['owner_offer' => 'refund_requested']);
		if (function_exists('mjto_notify_owner')) {
			mjto_notify_owner(mjto_get_appointment((int) $row['id']), 'refund');
		}
	}
	$fresh = mjto_get_appointment((int) $row['id']);
	do_action('mjto_offer_chosen', $fresh, $choice);
	return $fresh;
}

/* -------------------------------------------------------------------------
 * Cancelación de la dueña
 * ---------------------------------------------------------------------- */

/**
 * La dueña cancela desde el panel. Con $offer (y la opción prendida, y una
 * seña pagada), la clienta recibe el mail con opciones en vez del de
 * cancelación y elige desde su turno. El saldo usado en una reserva sin
 * confirmar vuelve siempre. → fila del turno o WP_Error.
 */
function mjto_owner_cancel($id, $reason = '', $offer = false, $notify = false) {
	return mjto_with_appointment_lock((int) $id, fn ($row) => mjto_owner_cancel_locked($row, $reason, $offer, $notify));
}

/** Cancelación de la dueña, con el candado del turno ya tomado. */
function mjto_owner_cancel_locked(array $row, $reason, $offer, $notify) {
	if ('cancelled' === (string) $row['status']) {
		return $row;
	}
	$fresh = mjto_set_status((int) $row['id'], 'cancelled', ['by' => 'owner', 'reason' => $reason, 'notify' => false]);
	if (is_wp_error($fresh)) {
		return $fresh;
	}
	/*
	 * Lo que había salido del saldo a favor vuelve al saldo (confirmado o no):
	 * lo que se le ofrece a la clienta (devolución, saldo, otro día) es solo lo
	 * que transfirió. Así nunca se devuelve en plata algo que era saldo.
	 */
	if ((float) $row['credit_applied'] > 0) {
		mjto_return_applied_credit($fresh);
		$fresh = mjto_get_appointment((int) $row['id']);
	}
	$can_offer = $offer && (int) mjto_opt('owner_cancel_offer') && (float) $fresh['deposit_paid'] > 0 && mjto_offer_choices();
	if ($can_offer) {
		mjto_update_appointment((int) $row['id'], ['owner_offer' => 'open']);
		$fresh = mjto_get_appointment((int) $row['id']);
		if (function_exists('mjto_notify_client')) {
			mjto_notify_client($fresh, 'owner_cancel');
		}
	} elseif ($notify && function_exists('mjto_notify_client')) {
		mjto_notify_client($fresh, 'cancelled');
	}
	return $fresh;
}

/*
 * La dueña vuelve atrás una cancelación o un "no vino": la seña que había
 * pasado a saldo a favor por ese turno sale del saldo (lo que quede), y la
 * seña perdida deja de estarlo. Así nadie queda con el saldo y el turno a la vez.
 */
add_action('mjto_status_changed', function ($fresh, $old, $new) {
	if (!is_array($fresh) || !in_array($old, ['cancelled', 'no_show'], true) || !in_array($new, ['pending', 'awaiting_deposit', 'confirmed', 'completed'], true)) {
		return;
	}
	global $wpdb;
	$given = (float) $wpdb->get_var($wpdb->prepare(
		'SELECT SUM(amount) FROM ' . mjto_table('credits') . " WHERE appointment_id = %d AND kind = 'deposit'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
		(int) $fresh['id']
	));
	if ($given > 0) {
		mjto_add_credit((int) $fresh['client_id'], -$given, 'manual', mjto_credit_note('credit_note_reactivated', $fresh), (int) $fresh['id']);
	}
	$patch = ['deposit_forfeited' => 0];
	if ('cancelled' === $old && in_array((string) ($fresh['owner_offer'] ?? ''), ['open', 'credited', 'refund_requested'], true)) {
		$patch['owner_offer'] = '';
	}
	mjto_update_appointment((int) $fresh['id'], $patch);
}, 20, 3);

/** La dueña marca como devuelta la seña que la clienta pidió. */
function mjto_mark_refunded($id) {
	$row = mjto_get_appointment($id);
	if (!$row || !in_array((string) $row['owner_offer'], ['open', 'refund_requested'], true)) {
		return mjto_booking_error('offer_closed', 400);
	}
	mjto_update_appointment((int) $row['id'], ['owner_offer' => 'refunded']);
	$fresh = mjto_get_appointment((int) $row['id']);
	do_action('mjto_deposit_refunded', $fresh);
	return $fresh;
}
