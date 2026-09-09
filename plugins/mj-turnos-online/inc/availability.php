<?php
/**
 * Motor de disponibilidad: franjas del día, ocupación y horarios libres.
 *
 * Todo se calcula en la zona horaria del sitio (wp_timezone()). "Ahora" es
 * current_datetime(); "hoy" es mjto_today(). Nunca date()/time() a secas
 * para la agenda (time() solo se usa para la edad del lock, que no es
 * lógica de agenda).
 *
 * Reglas (spec A.5):
 *  - franjas del día según weekly_hours (día ISO 1..7) y closed_dates;
 *  - ocupado = turnos no cancelados/no vino (fin + descanso) y bloqueos;
 *  - candidatos cada slot_step desde el inicio de cada franja mientras
 *    inicio + duración <= fin de la franja;
 *  - se descartan los candidatos antes de ahora + min_notice_hours, los
 *    días pasados y los días más allá de max_days_ahead;
 *  - un candidato se descarta si [t, t + duración + descanso) toca un
 *    intervalo ocupado (t < finOcupado && t + duración + descanso > inicioOcupado).
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Horas y franjas
 * ---------------------------------------------------------------------- */

/** 'HH:MM' → minutos desde las 00:00 (o -1 si no es una hora válida; '24:00' = 1440). */
function mjto_hm_to_minutes($hm) {
	if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $hm), $m)) {
		return -1;
	}
	$h = (int) $m[1];
	$i = (int) $m[2];
	if ($h > 24 || $i > 59 || ($h === 24 && $i > 0)) {
		return -1;
	}
	return $h * 60 + $i;
}

/** Minutos desde las 00:00 → 'HH:MM'. */
function mjto_minutes_to_hm($minutes) {
	$minutes = max(0, (int) $minutes);
	return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

/** 'H:MM', 'HH:MM' o 'HH:MM:SS' → 'HH:MM' (o '' si no es una hora válida del día). */
function mjto_normalize_time($time) {
	$time = trim((string) $time);
	if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
		return '';
	}
	$h = (int) $m[1];
	$i = (int) $m[2];
	if ($h > 23 || $i > 59) {
		return '';
	}
	return sprintf('%02d:%02d', $h, $i);
}

/**
 * "09:00-13:00, 15:00-20:00" → [['09:00', '13:00'], ['15:00', '20:00']].
 * Usa las mismas reglas que el guardado (mjto_sanitize_ranges): franjas
 * válidas, ordenadas y sin solaparse; las inválidas se descartan.
 */
function mjto_parse_ranges($string) {
	$clean = mjto_sanitize_ranges((string) $string);
	$out   = [];
	foreach (explode(',', $clean) as $part) {
		$part = trim($part);
		if (preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $part, $m)) {
			$out[] = [$m[1], $m[2]];
		}
	}
	return $out;
}

/** Franjas por día de la semana: [1 => [['09:00','18:00']], …, 7 => []] (1 = lunes). */
function mjto_open_days() {
	$out = [];
	foreach (mjto_weekly_hours() as $dow => $ranges) {
		$out[(int) $dow] = mjto_parse_ranges($ranges);
	}
	return $out;
}

/** Motivo del cierre de una fecha (string, puede ser '') o null si no está cerrada. */
function mjto_closed_date_reason($date) {
	$closed = mjto_closed_dates();
	$date   = (string) $date;
	return array_key_exists($date, $closed) ? (string) $closed[$date] : null;
}

/** Día ISO (1 = lunes … 7 = domingo) de una fecha, en la zona del sitio. */
function mjto_iso_dow($date) {
	try {
		return (int) (new DateTimeImmutable((string) $date, wp_timezone()))->format('N');
	} catch (Exception $e) {
		return 0;
	}
}

/**
 * Franjas de atención de un día concreto ('YYYY-MM-DD') → [['09:00','13:00'], …].
 * Vacío si la fecha no es válida, si el día no abre o si está en closed_dates.
 */
function mjto_day_ranges($date) {
	$date = (string) $date;
	if (!mjto_valid_date($date)) {
		return [];
	}
	if (null !== mjto_closed_date_reason($date)) {
		return [];
	}
	$dow = mjto_iso_dow($date);
	if ($dow < 1) {
		return [];
	}
	$hours = mjto_weekly_hours();
	$ranges = mjto_parse_ranges(isset($hours[$dow]) ? $hours[$dow] : '');
	return (array) apply_filters('mjto_day_ranges', $ranges, $date, $dow);
}

/* -------------------------------------------------------------------------
 * Servicio → duración y descanso efectivos
 * ---------------------------------------------------------------------- */

/**
 * Resuelve lo que necesita el motor de un "servicio": puede ser el shape
 * público (mjto_service_public), un id, null (turno libre) o un array suelto
 * con 'duration' y/o 'buffer' (p. ej. un turno "Otro" desde el panel).
 * → ['id', 'name', 'duration', 'buffer', 'price', 'service' => shape|null].
 * duration = servicio ?: default_duration_min; buffer = servicio ?? buffer_min.
 */
function mjto_service_spec($service) {
	$spec = [
		'id'       => 0,
		'name'     => '',
		'duration' => (int) mjto_opt('default_duration_min'),
		'buffer'   => (int) mjto_opt('buffer_min'),
		'price'    => 0.0,
		'service'  => null,
	];
	if (is_numeric($service)) {
		$service = (int) $service > 0 ? mjto_get_service((int) $service) : null;
	}
	if (is_array($service)) {
		if (!empty($service['id']) && isset($service['name'])) {
			$spec['id']      = (int) $service['id'];
			$spec['name']    = (string) $service['name'];
			$spec['price']   = (float) ($service['price'] ?? 0);
			$spec['service'] = $service;
		}
		$duration = 0;
		if (isset($service['duration']) && '' !== $service['duration'] && null !== $service['duration']) {
			$duration = (int) $service['duration'];
		} elseif (isset($service['duration_min'])) {
			$duration = (int) $service['duration_min'];
		}
		if ($duration > 0) {
			$spec['duration'] = $duration;
		}
		if (array_key_exists('buffer', $service) && null !== $service['buffer'] && '' !== $service['buffer']) {
			$spec['buffer'] = (int) $service['buffer'];
		}
	}
	$spec['duration'] = max(5, (int) $spec['duration']);
	$spec['buffer']   = max(0, (int) $spec['buffer']);
	return $spec;
}

/** Descanso después de un turno según su servicio (propio si lo tiene, si no el global). Con caché por pedido. */
function mjto_service_buffer_by_id($service_id) {
	static $cache = [];
	$service_id = (int) $service_id;
	$global     = max(0, (int) mjto_opt('buffer_min'));
	if ($service_id <= 0) {
		return $global;
	}
	if (!array_key_exists($service_id, $cache)) {
		$service            = mjto_get_service($service_id);
		$cache[$service_id] = ($service && null !== $service['buffer']) ? max(0, (int) $service['buffer']) : null;
	}
	return null === $cache[$service_id] ? $global : $cache[$service_id];
}

/* -------------------------------------------------------------------------
 * Ocupación
 * ---------------------------------------------------------------------- */

/** Estados que ocupan lugar en la agenda (todo menos cancelado y "no vino"). */
function mjto_busy_statuses() {
	return ['pending', 'awaiting_deposit', 'confirmed', 'completed'];
}

/**
 * Intervalos ocupados por día en [from, to] (fechas inclusive):
 * ['YYYY-MM-DD' => [[startTs, endTs], …]]. Turnos (fin + descanso) y
 * bloqueos. Un intervalo que cruza la medianoche se anota en los dos días.
 * $exclude_id: turno a ignorar (reprogramación).
 */
function mjto_busy_map($from_date, $to_date, $exclude_id = 0) {
	$from_date = (string) $from_date;
	$to_date   = (string) $to_date;
	if (!mjto_valid_date($from_date) || !mjto_valid_date($to_date) || $to_date < $from_date) {
		return [];
	}
	$tz         = wp_timezone();
	$exclude_id = (int) $exclude_id;
	$start      = new DateTimeImmutable($from_date . ' 00:00:00', $tz);
	$end        = (new DateTimeImmutable($to_date . ' 00:00:00', $tz))->modify('+1 day');
	$map        = [];

	$assign = function ($s, $e) use (&$map, $from_date, $to_date, $tz) {
		$s = (int) $s;
		$e = (int) $e;
		if ($e <= $s) {
			return;
		}
		$day   = (new DateTimeImmutable('@' . $s))->setTimezone($tz)->setTime(0, 0, 0);
		$last  = (new DateTimeImmutable('@' . ($e - 1)))->setTimezone($tz)->format('Y-m-d');
		$guard = 0;
		while ($guard++ < 400) {
			$key = $day->format('Y-m-d');
			if ($key > $last) {
				break;
			}
			if ($key >= $from_date && $key <= $to_date) {
				$map[$key][] = [$s, $e];
			}
			$day = $day->modify('+1 day');
		}
	};

	/* Un día de margen hacia atrás: un turno que termina tarde + descanso puede pisar la madrugada siguiente. */
	$appointments = mjto_appointments_between(
		$start->modify('-1 day')->format('Y-m-d H:i:s'),
		$end->format('Y-m-d H:i:s'),
		mjto_busy_statuses()
	);
	foreach ($appointments as $row) {
		if ($exclude_id > 0 && (int) $row['id'] === $exclude_id) {
			continue;
		}
		$s = mjto_local_ts($row['starts_at']);
		$e = mjto_local_ts($row['ends_at']);
		if (null === $s || null === $e) {
			continue;
		}
		$assign($s, $e + mjto_service_buffer_by_id($row['service_id']) * 60);
	}

	foreach (mjto_get_blocks_between($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')) as $block) {
		$s = mjto_local_ts($block['starts_at']);
		$e = mjto_local_ts($block['ends_at']);
		if (null === $s || null === $e) {
			continue;
		}
		$assign($s, $e);
	}

	foreach ($map as &$intervals) {
		usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
	}
	unset($intervals);
	return $map;
}

/** Intervalos ocupados de un día: [[startTs, endTs], …] (turnos no cancelados/no vino + descanso, y bloqueos). */
function mjto_busy_intervals($date, $exclude_id = 0) {
	$date = (string) $date;
	if (!mjto_valid_date($date)) {
		return [];
	}
	$map = mjto_busy_map($date, $date, $exclude_id);
	return isset($map[$date]) ? $map[$date] : [];
}

/**
 * ¿La franja [from, to) (fecha/hora local 'YYYY-MM-DD HH:MM:SS') no pisa
 * ningún turno ni bloqueo? Sin exigir grilla ni anticipación: sirve para
 * alargar un turno sin moverlo. $exclude_id: turno a ignorar (el propio).
 */
function mjto_interval_is_free($from_datetime, $to_datetime, $exclude_id = 0) {
	$from = mjto_local_ts($from_datetime);
	$to   = mjto_local_ts($to_datetime);
	if (null === $from || null === $to || $to <= $from) {
		return true;
	}
	$from_date = substr(trim((string) $from_datetime), 0, 10);
	$to_date   = substr(trim((string) $to_datetime), 0, 10);
	$map       = mjto_busy_map($from_date, max($from_date, $to_date), $exclude_id);
	foreach ($map as $intervals) {
		foreach ($intervals as $b) {
			if ($from < $b[1] && $to > $b[0]) {
				return false;
			}
		}
	}
	return true;
}

/* -------------------------------------------------------------------------
 * Horarios libres
 * ---------------------------------------------------------------------- */

/** Ventana reservable: ['today' => 'YYYY-MM-DD', 'max' => 'YYYY-MM-DD' (hoy + max_days_ahead)]. */
function mjto_booking_window() {
	$today = current_datetime()->setTime(0, 0, 0);
	$days  = max(0, (int) mjto_opt('max_days_ahead'));
	return [
		'today' => $today->format('Y-m-d'),
		'max'   => $today->modify('+' . $days . ' days')->format('Y-m-d'),
	];
}

/**
 * ¿La fecha se puede reservar? Nunca en el pasado; hasta max_days_ahead
 * salvo que $ignore_limit (panel), que solo exige que no sea pasada.
 */
function mjto_date_in_booking_range($date, $ignore_limit = false) {
	$date = (string) $date;
	if (!mjto_valid_date($date)) {
		return false;
	}
	$window = mjto_booking_window();
	if ($date < $window['today']) {
		return false;
	}
	return $ignore_limit || $date <= $window['max'];
}

/** Contexto común del cálculo: ahora, mínimo permitido (ahora + anticipación), paso y zona. */
function mjto_slot_context($ignore_notice = false) {
	$now    = current_datetime();
	$notice = $ignore_notice ? 0 : max(0, (float) mjto_opt('min_notice_hours'));
	return [
		'tz'     => wp_timezone(),
		'now_ts' => $now->getTimestamp(),
		'min_ts' => $now->getTimestamp() + (int) round($notice * 3600),
		'step'   => max(5, (int) mjto_opt('slot_step')),
	];
}

/**
 * Horarios libres de un día ya resuelto (franjas, ocupación y contexto
 * calculados por quien llama) → ['09:00', '09:30', …].
 */
function mjto_slots_for_day(array $spec, $date, array $ranges, array $busy, array $ctx) {
	if (!$ranges) {
		return [];
	}
	$duration = max(5, (int) $spec['duration']);
	$buffer   = max(0, (int) $spec['buffer']);
	$need     = ($duration + $buffer) * 60; // segundos que bloquea el candidato
	$step     = max(5, (int) $ctx['step']);
	try {
		$day = new DateTimeImmutable((string) $date . ' 00:00:00', $ctx['tz']);
	} catch (Exception $e) {
		return [];
	}
	$out = [];
	foreach ($ranges as $range) {
		$start = mjto_hm_to_minutes($range[0] ?? '');
		$end   = mjto_hm_to_minutes($range[1] ?? '');
		if ($start < 0 || $end < 0 || $end <= $start) {
			continue;
		}
		for ($t = $start; $t + $duration <= $end; $t += $step) {
			$ts = $day->setTime(intdiv($t, 60), $t % 60, 0)->getTimestamp();
			if ($ts < $ctx['min_ts']) {
				continue; // Ya pasó o no respeta la anticipación mínima.
			}
			$free = true;
			foreach ($busy as $b) {
				if ($ts < $b[1] && $ts + $need > $b[0]) {
					$free = false;
					break;
				}
			}
			if ($free) {
				$out[] = mjto_minutes_to_hm($t);
			}
		}
	}
	$out = array_values(array_unique($out));
	sort($out);
	return $out;
}

/**
 * Horarios libres de un día para un servicio → ['09:00', '09:30', …].
 * $service: shape público, id, null (turno libre con la duración por defecto)
 * o array con 'duration'/'buffer'. $exclude_id: turno a ignorar (reprogramar).
 * $ignore_notice (panel): no aplica min_notice_hours ni max_days_ahead, pero
 * sigue sin ofrecer horarios pasados.
 */
function mjto_available_slots($service, $date, $exclude_id = 0, $ignore_notice = false) {
	$date = (string) $date;
	if (!mjto_date_in_booking_range($date, (bool) $ignore_notice)) {
		return [];
	}
	$ranges = mjto_day_ranges($date);
	if (!$ranges) {
		return [];
	}
	$spec  = mjto_service_spec($service);
	$ctx   = mjto_slot_context((bool) $ignore_notice);
	$busy  = mjto_busy_intervals($date, $exclude_id);
	$slots = mjto_slots_for_day($spec, $date, $ranges, $busy, $ctx);
	return (array) apply_filters('mjto_available_slots', $slots, $spec, $date, (int) $exclude_id, (bool) $ignore_notice);
}

/**
 * Disponibilidad pública de un rango de fechas (inclusive), recortado a
 * [hoy, hoy + max_days_ahead] y a 62 días como máximo:
 * ['days' => ['YYYY-MM-DD' => ['09:00', …]], 'timezone' => …, 'now' => 'YYYY-MM-DD HH:MM', 'from', 'to'].
 * Cada día del rango aparece (con [] si está cerrado o lleno).
 */
function mjto_availability($service, $from, $to) {
	$window = mjto_booking_window();
	$tz     = wp_timezone();
	$from   = mjto_valid_date($from) ? (string) $from : $window['today'];
	if ($from < $window['today']) {
		$from = $window['today'];
	}
	$limit = (new DateTimeImmutable($from . ' 00:00:00', $tz))->modify('+61 days')->format('Y-m-d');
	$to    = mjto_valid_date($to) ? (string) $to : $limit;
	if ($to > $limit) {
		$to = $limit;
	}
	if ($to > $window['max']) {
		$to = $window['max'];
	}

	$days = [];
	if ($to >= $from) {
		$spec  = mjto_service_spec($service);
		$ctx   = mjto_slot_context(false);
		$busy  = mjto_busy_map($from, $to, 0);
		$day   = new DateTimeImmutable($from . ' 00:00:00', $tz);
		$guard = 0;
		while ($guard++ < 70) {
			$date = $day->format('Y-m-d');
			if ($date > $to) {
				break;
			}
			$ranges      = mjto_day_ranges($date);
			$days[$date] = $ranges ? mjto_slots_for_day($spec, $date, $ranges, isset($busy[$date]) ? $busy[$date] : [], $ctx) : [];
			$day         = $day->modify('+1 day');
		}
	}

	return [
		'days'     => $days,
		'timezone' => wp_timezone_string(),
		'now'      => current_datetime()->format('Y-m-d H:i'),
		'from'     => $from,
		'to'       => $to,
	];
}

/**
 * ¿El horario está libre para ese servicio? Se apoya en mjto_available_slots
 * (o sea, el horario tiene que caer en la grilla de slot_step). Desde el
 * panel se llama con $ignore_notice = true.
 */
function mjto_slot_is_free($service, $date, $time, $exclude_id = 0, $ignore_notice = false) {
	$time = mjto_normalize_time($time);
	if ('' === $time) {
		return false;
	}
	return in_array($time, mjto_available_slots($service, $date, $exclude_id, $ignore_notice), true);
}

/* -------------------------------------------------------------------------
 * Lock anti-carrera (por fecha) al crear o mover un turno
 * ---------------------------------------------------------------------- */

/** Nombre de la opción-lock de una fecha. */
function mjto_lock_name($date) {
	return 'mjto_lock_' . preg_replace('/[^0-9\-]/', '', (string) $date);
}

/**
 * Toma el lock de una fecha. Es una fila en wp_options (autoload no): el
 * INSERT falla si ya existe gracias al índice único de option_name, así que
 * es atómico aunque haya dos reservas al mismo tiempo. Si el lock existente
 * tiene más de 10 s se considera colgado y se lo pisa. Espera hasta
 * $wait_seconds a que se libere antes de rendirse. → bool.
 */
function mjto_acquire_lock($date, $wait_seconds = 2) {
	global $wpdb;
	$name     = mjto_lock_name($date);
	$deadline = microtime(true) + max(0, (float) $wait_seconds);
	do {
		$now      = time(); // Solo para la edad del lock, no es lógica de agenda.
		$suppress = $wpdb->suppress_errors(true);
		$inserted = $wpdb->query($wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$name,
			(string) $now
		)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors($suppress);
		if ($inserted) {
			wp_cache_delete($name, 'options');
			wp_cache_delete('notoptions', 'options');
			return true;
		}
		$existing = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ($existing > 0 && $existing < $now - 10) {
			/* Lock viejo: lo toma quien logre cambiar el valor (UPDATE condicional = atómico). */
			$wpdb->query($wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $now,
				$name,
				(string) $existing
			)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ($wpdb->rows_affected > 0) {
				wp_cache_delete($name, 'options');
				return true;
			}
		} elseif (0 === $existing) {
			continue; // Se liberó entre el INSERT y el SELECT: reintentar ya.
		}
		usleep(150000);
	} while (microtime(true) < $deadline);
	return false;
}

/** Libera el lock de la fecha (borra la fila directamente: get_option podría tener cacheado que no existe). */
function mjto_release_lock($date) {
	global $wpdb;
	$name = mjto_lock_name($date);
	$wpdb->delete($wpdb->options, ['option_name' => $name], ['%s']);
	wp_cache_delete($name, 'options');
	wp_cache_delete('notoptions', 'options');
	return true;
}
