<?php
/**
 * Opiniones de las clientas atendidas.
 *
 * Cuando la dueña marca un turno como atendido (completed_at), la tarea
 * frecuente espera reviews_delay_hours y le manda a la clienta el mail con el
 * link a su turno, donde deja puntaje (1 a 5), texto y cómo quiere que se vea
 * su nombre. Una opinión por turno. Se publica sola si reviews_auto_publish
 * está prendido y el puntaje llega a reviews_publish_min; si no, espera que la
 * dueña la revise (panel → Más → Opiniones).
 *
 * Solo opina quien fue atendida: la opinión se carga con el token del turno y
 * el turno tiene que estar "Atendida".
 *
 * @package MJ_Turnos_Online
 */

defined('ABSPATH') || exit;

/** ¿Se piden opiniones? */
function mjto_reviews_enabled() {
	return (bool) (int) mjto_opt('reviews_enabled');
}

/** Nombre como se muestra en la web según reviews_name_format ("Ana G.", "Ana" o completo). */
function mjto_review_display_name($name) {
	$name  = trim(preg_replace('/\s+/', ' ', (string) $name));
	$parts = '' !== $name ? explode(' ', $name) : [];
	switch ((string) mjto_opt('reviews_name_format')) {
		case 'full':
			return $name;
		case 'first':
			return $parts ? $parts[0] : '';
		default:
			if (count($parts) < 2) {
				return $name;
			}
			$initial = function_exists('mb_substr') ? mb_substr(end($parts), 0, 1) : substr(end($parts), 0, 1);
			return $parts[0] . ' ' . mb_strtoupper($initial) . '.';
	}
}

/** Opinión → shape público (lo que ve cualquiera). */
function mjto_review_public($row) {
	if (!is_array($row)) {
		return null;
	}
	return [
		'id'          => (int) $row['id'],
		'name'        => mjto_review_display_name($row['author_name']),
		'rating'      => (int) $row['rating'],
		'body'        => (string) $row['body'],
		'serviceId'   => (int) $row['service_id'],
		'serviceName' => (string) $row['service_name'],
		'date'        => substr((string) $row['created_at'], 0, 10),
		'dateLabel'   => mjto_date_label($row['created_at']),
	];
}

/** Opinión → shape del panel (con estado, nombre tal cual y la clienta). */
function mjto_review_panel($row) {
	if (!is_array($row)) {
		return null;
	}
	$client = mjto_get_client((int) $row['client_id']);
	return array_merge(mjto_review_public($row), [
		'authorName'    => (string) $row['author_name'],
		'status'        => (string) $row['status'],
		'appointmentId' => (int) $row['appointment_id'],
		'clientId'      => (int) $row['client_id'],
		'clientName'    => $client ? mjto_full_name($client['first_name'], $client['last_name']) : '',
		'createdAt'     => (string) $row['created_at'],
	]);
}

/**
 * Resumen público: promedio, cantidad y las últimas N publicadas.
 * $service_id: solo las de ese servicio (0 = todas).
 */
function mjto_reviews_summary($limit = 12, $service_id = 0) {
	global $wpdb;
	$table = mjto_table('reviews');
	$where = "status = 'approved'" . ((int) $service_id > 0 ? $wpdb->prepare(' AND service_id = %d', (int) $service_id) : '');
	$stats = $wpdb->get_row("SELECT COUNT(*) AS n, AVG(rating) AS avg FROM {$table} WHERE {$where}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
	$items = mjto_query_reviews(['status' => 'approved', 'service_id' => (int) $service_id, 'per_page' => max(1, (int) $limit)])['items'];
	return [
		'count'   => (int) ($stats['n'] ?? 0),
		'average' => $stats && $stats['avg'] ? round((float) $stats['avg'], 1) : 0.0,
		'items'   => array_values(array_filter(array_map('mjto_review_public', $items))),
	];
}

/**
 * Estado de la opinión para la página del turno:
 * ['can' => bool, 'done' => bool, 'status' => …, 'rating', 'body'].
 */
function mjto_review_state(array $row) {
	$state = ['can' => false, 'done' => false, 'status' => '', 'rating' => 0, 'body' => '', 'name' => ''];
	if (!mjto_reviews_enabled() || 'completed' !== (string) $row['status']) {
		return $state;
	}
	$review = mjto_review_of_appointment((int) $row['id']);
	if ($review) {
		$state['done']   = true;
		$state['status'] = (string) $review['status'];
		$state['rating'] = (int) $review['rating'];
		$state['body']   = (string) $review['body'];
		return $state;
	}
	$state['can']  = true;
	$state['name'] = mjto_full_name($row['client_first_name'] ?? '', $row['client_last_name'] ?? '');
	return $state;
}

/**
 * Guarda la opinión de un turno atendido (por token). → fila de la opinión o WP_Error.
 */
function mjto_submit_review($token, $rating, $body = '', $name = '') {
	$row = mjto_get_appointment_by_token($token);
	if (!$row) {
		return mjto_booking_error('not_found', 404);
	}
	if (!mjto_review_state($row)['can']) {
		return mjto_booking_error('review_closed', 400);
	}
	$rating = (int) $rating;
	if ($rating < 1 || $rating > 5) {
		return mjto_booking_error('review_invalid', 400);
	}
	$name = trim(sanitize_text_field((string) $name));
	if ('' === $name) {
		$name = mjto_full_name($row['client_first_name'] ?? '', $row['client_last_name'] ?? '');
	}
	$publish = (int) mjto_opt('reviews_auto_publish') && $rating >= (int) mjto_opt('reviews_publish_min');
	$id      = mjto_insert_review([
		'appointment_id' => (int) $row['id'],
		'client_id'      => (int) $row['client_id'],
		'service_id'     => (int) $row['service_id'],
		'service_name'   => (string) $row['service_name'],
		'author_name'    => $name,
		'rating'         => $rating,
		'body'           => (string) $body,
		'status'         => $publish ? 'approved' : 'pending',
	]);
	if (!$id) {
		return mjto_booking_error('review_closed', 400); // El índice único frena la segunda.
	}
	$review = mjto_get_review($id);
	if (function_exists('mjto_notify_owner')) {
		mjto_notify_owner($row, 'review', [
			'puntaje'        => (string) $rating,
			'opinion'        => (string) $review['body'],
			'estado_opinion' => mjto_status_label_review((string) $review['status']),
		]);
	}
	do_action('mjto_review_submitted', $review, $row);
	return $review;
}

/** "publicada" / "esperando revisión" / "oculta" (para el mail de la dueña). */
function mjto_status_label_review($status) {
	$labels = (array) apply_filters('mjto_review_status_labels', [
		'approved' => (string) mjto_opt('review_label_approved'),
		'pending'  => (string) mjto_opt('review_label_pending'),
		'hidden'   => (string) mjto_opt('review_label_hidden'),
	]);
	return isset($labels[$status]) ? (string) $labels[$status] : (string) $status;
}

/** URL para opinar: la página del turno con ?opinar=1 (y ?turno= sin el tema). */
function mjto_review_url($token) {
	return add_query_arg('opinar', '1', mjto_booking_status_url($token)) . '#opinion';
}
