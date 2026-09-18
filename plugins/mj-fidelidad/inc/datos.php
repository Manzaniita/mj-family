<?php
/**
 * Tablas, sellos, premios y la cuenta de cada tarjeta.
 *
 * QUIÉN TIENE LA TARJETA
 *   Un "titular" es una cuenta de WordPress ('user', id) o, para las clientas
 *   de MJ Turnos que reservaron sin cuenta, su ficha ('client', id). Si esa
 *   ficha después se vincula a una cuenta, sus sellos se suman a los de la
 *   cuenta solos: la tarjeta de una persona junta a los dos titulares
 *   (mjfid_people()). Nada se migra ni se pisa.
 *
 * LOS SELLOS SON MOVIMIENTOS
 *   Cada sello (o descuento) es una fila con su origen: un turno, un pedido o
 *   la dueña a mano. El índice único (source, source_id) hace que marcar dos
 *   veces el mismo turno como atendido no sume dos veces. La tarjeta se
 *   calcula: sellos válidos / sellos por tarjeta.
 *
 * LOS PREMIOS
 *   Cada tarjeta completada es una fila en rewards, con el número de tarjeta.
 *   Se crean al sumar (idempotente por titular + número) y los pendientes que
 *   dejan de corresponder (se descontó un sello) se borran; los canjeados, no.
 *
 * @package MJ_Fidelidad
 */

defined('ABSPATH') || exit;

function mjfid_table($name) {
	global $wpdb;
	return $wpdb->prefix . 'mjfid_' . sanitize_key($name);
}

/** Crea o actualiza las tablas (activación y cambio de MJFID_DB_VERSION). */
function mjfid_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$stamps  = mjfid_table('stamps');
	$rewards = mjfid_table('rewards');

	dbDelta("CREATE TABLE {$stamps} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  holder_type VARCHAR(10) NOT NULL DEFAULT 'user',
  holder_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  source_id BIGINT UNSIGNED NULL,
  stamps INT NOT NULL DEFAULT 1,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY holder (holder_type, holder_id),
  UNIQUE KEY source_ref (source, source_id)
) {$charset};");

	dbDelta("CREATE TABLE {$rewards} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  holder_type VARCHAR(10) NOT NULL DEFAULT 'user',
  holder_id BIGINT UNSIGNED NOT NULL,
  card_number INT UNSIGNED NOT NULL,
  reward_text VARCHAR(255) NOT NULL DEFAULT '',
  credit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  redeemed_at DATETIME NULL,
  redeemed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY card (holder_type, holder_id, card_number),
  KEY status (status)
) {$charset};");

	update_option('mjfid_db_version', MJFID_DB_VERSION, false);
}

/** Ahora en la zona del sitio. */
function mjfid_now() {
	return current_datetime()->format('Y-m-d H:i:s');
}

/* -------------------------------------------------------------------------
 * Titulares y personas
 * ---------------------------------------------------------------------- */

/** ['user'|'client', id] normalizado, o null. */
function mjfid_holder($type, $id) {
	$type = 'client' === $type ? 'client' : ('user' === $type ? 'user' : '');
	$id   = (int) $id;
	return ('' !== $type && $id > 0) ? [$type, $id] : null;
}

/** Id del usuario vinculado a una ficha de MJ Turnos (0 si no tiene o no está el plugin). */
function mjfid_client_user($client_id) {
	if (!function_exists('mjto_get_client')) {
		return 0;
	}
	$client = mjto_get_client((int) $client_id);
	return $client ? (int) ($client['user_id'] ?? 0) : 0;
}

/** Fichas de MJ Turnos vinculadas a una cuenta (ids). */
function mjfid_user_clients($user_id) {
	global $wpdb;
	if (!function_exists('mjto_table')) {
		return [];
	}
	$ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . mjto_table('clients') . ' WHERE user_id = %d', (int) $user_id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla de MJ Turnos; el valor va por prepare.
	return array_map('intval', (array) $ids);
}

/**
 * Todos los titulares que son la misma persona: la cuenta y sus fichas.
 * El primero es el "canónico" (donde se anotan los premios nuevos).
 */
function mjfid_people(array $holder) {
	[$type, $id] = $holder;
	$user = 'user' === $type ? $id : mjfid_client_user($id);
	if ($user > 0) {
		$out = [['user', $user]];
		foreach (mjfid_user_clients($user) as $client_id) {
			$out[] = ['client', $client_id];
		}
		return $out;
	}
	return [['client', $id]];
}

/** WHERE (preparado) que abarca a los titulares de una persona. */
function mjfid_people_where(array $people) {
	global $wpdb;
	$parts = [];
	foreach ($people as [$type, $id]) {
		$parts[] = $wpdb->prepare('(holder_type = %s AND holder_id = %d)', $type, (int) $id);
	}
	return $parts ? '(' . implode(' OR ', $parts) . ')' : '(1=0)';
}

/**
 * Datos de contacto de una persona: ['name', 'first', 'email'].
 * Cuenta primero; si no, la ficha de MJ Turnos.
 */
function mjfid_person_contact(array $holder) {
	$people = mjfid_people($holder);
	[$type, $id] = $people[0];
	if ('user' === $type) {
		$user = get_userdata($id);
		if ($user) {
			$first = (string) $user->first_name;
			$name  = trim($first . ' ' . $user->last_name);
			return ['name' => '' !== $name ? $name : (string) $user->display_name, 'first' => '' !== $first ? $first : (string) $user->display_name, 'email' => (string) $user->user_email];
		}
	}
	foreach ($people as [$t, $cid]) {
		if ('client' === $t && function_exists('mjto_get_client')) {
			$client = mjto_get_client($cid);
			if ($client) {
				return ['name' => trim($client['first_name'] . ' ' . $client['last_name']), 'first' => (string) $client['first_name'], 'email' => (string) $client['email']];
			}
		}
	}
	return ['name' => '', 'first' => '', 'email' => ''];
}

/* -------------------------------------------------------------------------
 * Sellos
 * ---------------------------------------------------------------------- */

/**
 * Anota un movimiento de sellos. Con origen (turno, pedido) es idempotente:
 * el mismo origen no suma dos veces. → id del movimiento o false.
 */
function mjfid_add_stamps(array $holder, $stamps, $source = 'manual', $source_id = null, $note = '') {
	global $wpdb;
	$stamps = (int) $stamps;
	if (0 === $stamps) {
		return false;
	}
	$source = sanitize_key((string) $source);
	$ok     = $wpdb->insert(mjfid_table('stamps'), [
		'holder_type' => $holder[0],
		'holder_id'   => (int) $holder[1],
		'source'      => '' !== $source ? $source : 'manual',
		'source_id'   => null === $source_id ? null : (int) $source_id,
		'stamps'      => $stamps,
		'note'        => mb_substr(sanitize_text_field((string) $note), 0, 255),
		'created_by'  => (int) get_current_user_id(),
		'created_at'  => mjfid_now(),
	], ['%s', '%d', '%s', '%d', '%d', '%s', '%d', '%s']);
	if (!$ok) {
		return false; // Casi siempre: ese origen ya había sumado.
	}
	$id = (int) $wpdb->insert_id;
	do_action('mjfid_stamps_added', $id, $holder, $stamps, $source, $source_id);
	mjfid_sync_rewards($holder);
	return $id;
}

/** Saca el movimiento de un origen (el turno dejó de estar atendido, el pedido se canceló). → bool. */
function mjfid_remove_source($source, $source_id) {
	global $wpdb;
	$table = mjfid_table('stamps');
	$row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source = %s AND source_id = %d", sanitize_key((string) $source), (int) $source_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla propia.
	if (!$row) {
		return false;
	}
	$wpdb->delete($table, ['id' => (int) $row['id']], ['%d']);
	do_action('mjfid_stamps_removed', $row);
	mjfid_sync_rewards([$row['holder_type'], (int) $row['holder_id']]);
	return true;
}

/** Fecha desde la que un sello vale (null = no vencen). */
function mjfid_valid_since() {
	$months = (int) mjfid_opt('expire_months');
	if ($months <= 0) {
		return null;
	}
	return current_datetime()->modify('-' . $months . ' months')->format('Y-m-d H:i:s');
}

/** Sellos válidos de una persona (nunca negativo). */
function mjfid_total_stamps(array $holder) {
	global $wpdb;
	$where = mjfid_people_where(mjfid_people($holder));
	$since = mjfid_valid_since();
	if ($since) {
		$where .= $wpdb->prepare(' AND created_at >= %s', $since);
	}
	$sum = $wpdb->get_var('SELECT SUM(stamps) FROM ' . mjfid_table('stamps') . " WHERE {$where}"); // phpcs:ignore WordPress.DB.PreparedSQL -- las partes variables ya están preparadas.
	return max(0, (int) $sum);
}

/** Movimientos de una persona, el más nuevo primero. */
function mjfid_movements(array $holder, $limit = 50) {
	global $wpdb;
	$where = mjfid_people_where(mjfid_people($holder));
	$rows  = $wpdb->get_results('SELECT * FROM ' . mjfid_table('stamps') . " WHERE {$where} ORDER BY created_at DESC, id DESC " . $wpdb->prepare('LIMIT %d', max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL -- las partes variables ya están preparadas.
	return is_array($rows) ? $rows : [];
}

/* -------------------------------------------------------------------------
 * Premios
 * ---------------------------------------------------------------------- */

/** Premios de una persona, el más nuevo primero. */
function mjfid_rewards(array $holder) {
	global $wpdb;
	$where = mjfid_people_where(mjfid_people($holder));
	$rows  = $wpdb->get_results('SELECT * FROM ' . mjfid_table('rewards') . " WHERE {$where} ORDER BY card_number DESC, id DESC", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL -- las partes variables ya están preparadas.
	return is_array($rows) ? $rows : [];
}

function mjfid_get_reward($id) {
	global $wpdb;
	$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . mjfid_table('rewards') . ' WHERE id = %d', (int) $id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
	return $row ? $row : null;
}

/**
 * Pone los premios al día con los sellos: crea los de las tarjetas nuevas y
 * borra los pendientes de tarjetas que ya no se completan. Cada premio nuevo
 * dispara el aviso y, con "saldo a favor", se acredita solo.
 */
function mjfid_sync_rewards(array $holder) {
	global $wpdb;
	$people    = mjfid_people($holder);
	$canonical = $people[0];
	$earned    = intdiv(mjfid_total_stamps($holder), mjfid_per_card());
	$rewards   = mjfid_rewards($holder);
	$have      = count($rewards);

	/* Tarjetas que ya no se completan (se descontaron sellos): solo se borran las pendientes, de la última para atrás. */
	if ($have > $earned) {
		foreach ($rewards as $reward) {
			if ($have <= $earned) {
				break;
			}
			if ('pending' === $reward['status']) {
				$wpdb->delete(mjfid_table('rewards'), ['id' => (int) $reward['id']], ['%d']);
				$have--;
			}
		}
		return;
	}

	$mode = (string) mjfid_opt('reward_mode');
	for ($number = $have + 1; $number <= $earned; $number++) {
		$credit = ('credit' === $mode && mjfid_credit_available()) ? round(max(0, (float) mjfid_opt('reward_credit_amount')), 2) : 0.0;
		$ok     = $wpdb->insert(mjfid_table('rewards'), [
			'holder_type'   => $canonical[0],
			'holder_id'     => (int) $canonical[1],
			'card_number'   => $number,
			'reward_text'   => mb_substr((string) mjfid_opt('reward_text'), 0, 255),
			'credit_amount' => $credit,
			'status'        => 'pending',
			'created_at'    => mjfid_now(),
		], ['%s', '%d', '%d', '%s', '%f', '%s', '%s']);
		if (!$ok) {
			continue;
		}
		$reward = mjfid_get_reward((int) $wpdb->insert_id);
		if ($credit > 0) {
			mjfid_credit_reward($reward, $people);
			$reward = mjfid_get_reward((int) $reward['id']);
		}
		do_action('mjfid_card_completed', $reward, $holder);
	}
}

/** Premio como saldo a favor en MJ Turnos (en la ficha de la persona). */
function mjfid_credit_reward(array $reward, array $people) {
	$client_id = 0;
	foreach ($people as [$type, $id]) {
		if ('client' === $type) {
			$client_id = $id;
			break;
		}
	}
	if ($client_id <= 0 || !mjfid_credit_available()) {
		return false; // Sin ficha en Turnos: queda pendiente y la dueña lo resuelve.
	}
	$reason = sprintf('Tarjeta de fidelidad n.º %d completa', (int) $reward['card_number']);
	if (!mjto_add_credit($client_id, (float) $reward['credit_amount'], 'manual', $reason)) {
		return false;
	}
	return mjfid_set_reward_status((int) $reward['id'], 'credited');
}

/** Cambia el estado de un premio (pending | redeemed | credited). */
function mjfid_set_reward_status($id, $status, $note = '') {
	global $wpdb;
	if (!in_array($status, ['pending', 'redeemed', 'credited'], true)) {
		return false;
	}
	$data = ['status' => $status, 'redeemed_at' => 'pending' === $status ? null : mjfid_now(), 'redeemed_by' => (int) get_current_user_id()];
	$fmt  = ['%s', '%s', '%d'];
	if ('' !== $note) {
		$data['note'] = mb_substr(sanitize_text_field((string) $note), 0, 255);
		$fmt[]        = '%s';
	}
	return false !== $wpdb->update(mjfid_table('rewards'), $data, ['id' => (int) $id], $fmt, ['%d']);
}

/* -------------------------------------------------------------------------
 * La tarjeta
 * ---------------------------------------------------------------------- */

/** Rellena {actuales} y {total} de un texto. */
function mjfid_fill($text, array $map) {
	$pairs = [];
	foreach ($map as $key => $value) {
		$pairs['{' . $key . '}'] = (string) $value;
	}
	return strtr((string) $text, $pairs);
}

/**
 * La tarjeta de una persona, lista para mostrar:
 * {perCard, total, current, completed, progressText, rewardText, rewards: [...], movements: [...], texts: {...}}
 */
function mjfid_card(array $holder, $with_movements = true) {
	$per     = mjfid_per_card();
	$total   = mjfid_total_stamps($holder);
	$current = $total % $per;
	$o       = mjfid_get_options();
	$labels  = ['pending' => $o['card_reward_pending'], 'redeemed' => $o['card_reward_redeemed'], 'credited' => $o['card_reward_credited']];
	$rewards = [];
	foreach (mjfid_rewards($holder) as $row) {
		$rewards[] = [
			'id'          => (int) $row['id'],
			'card'        => (int) $row['card_number'],
			'text'        => (string) $row['reward_text'],
			'credit'      => (float) $row['credit_amount'],
			'status'      => (string) $row['status'],
			'statusLabel' => (string) ($labels[$row['status']] ?? $row['status']),
			'createdAt'   => (string) $row['created_at'],
			'redeemedAt'  => $row['redeemed_at'] ? (string) $row['redeemed_at'] : null,
		];
	}
	$movements = [];
	if ($with_movements) {
		foreach (mjfid_movements($holder, 30) as $row) {
			$movements[] = [
				'id'        => (int) $row['id'],
				'stamps'    => (int) $row['stamps'],
				'source'    => (string) $row['source'],
				'note'      => (string) $row['note'],
				'createdAt' => (string) $row['created_at'],
			];
		}
	}
	return [
		'enabled'      => mjfid_enabled(),
		'perCard'      => $per,
		'total'        => $total,
		'current'      => $current,
		'completed'    => intdiv($total, $per),
		'progressText' => mjfid_fill($o['card_progress'], ['actuales' => $current, 'total' => $per]),
		'rewardText'   => (string) $o['reward_text'],
		'rewards'      => $rewards,
		'movements'    => $movements,
		'texts'        => [
			'title'        => (string) $o['card_title'],
			'rewardLabel'  => (string) $o['card_reward_label'],
			'rewardsTitle' => (string) $o['card_rewards_title'],
			'empty'        => (string) $o['card_empty'],
			'historyTitle' => (string) $o['card_history_title'],
		],
	];
}

/**
 * Personas con movimientos (para Fidelidad → Clientas): una fila por titular
 * canónico, con su nombre. $search filtra por nombre o email.
 */
function mjfid_people_list($search = '') {
	global $wpdb;
	$rows   = $wpdb->get_results('SELECT holder_type, holder_id, MAX(created_at) AS last_at FROM ' . mjfid_table('stamps') . ' GROUP BY holder_type, holder_id ORDER BY last_at DESC', ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia, sin valores.
	$seen   = [];
	$out    = [];
	$search = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $search)) : strtolower(trim((string) $search));
	foreach ((array) $rows as $row) {
		$holder = mjfid_holder($row['holder_type'], $row['holder_id']);
		if (!$holder) {
			continue;
		}
		$canonical = mjfid_people($holder)[0];
		$key       = $canonical[0] . ':' . $canonical[1];
		if (isset($seen[$key])) {
			continue;
		}
		$seen[$key] = true;
		$contact    = mjfid_person_contact($canonical);
		$haystack   = strtolower($contact['name'] . ' ' . $contact['email']);
		if ('' !== $search && false === strpos($haystack, $search)) {
			continue;
		}
		$out[] = ['holder' => $canonical, 'contact' => $contact, 'lastAt' => (string) $row['last_at']];
	}
	return $out;
}
