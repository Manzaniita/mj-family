<?php
/**
 * De dónde salen los sellos.
 *
 *   Turnos (MJ Turnos Online): un turno que pasa a "Atendida" suma los sellos
 *   de su servicio (cuadro "Fidelidad" en la ficha del servicio; vacío = el
 *   general de los ajustes, 0 = no suma). Si deja de estar atendido, se
 *   descuentan. El titular es la cuenta de la clienta o, sin cuenta, su ficha.
 *
 *   Compras (WooCommerce): un pedido completado de una cuenta suma una
 *   cantidad fija o un sello por cada monto gastado. Cancelado o
 *   reembolsado, se descuenta.
 *
 * Cada integración se engancha solo si el otro plugin está: MJ Fidelidad
 * funciona solo (con sellos a mano) y ninguno depende de él.
 *
 * @package MJ_Fidelidad
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * MJ Turnos Online
 * ---------------------------------------------------------------------- */

/** Sellos que da un servicio de MJ Turnos ('' en la ficha = el general). */
function mjfid_service_stamps($service_id) {
	$own = (string) get_post_meta((int) $service_id, '_mjfid_stamps', true);
	if ('' === $own) {
		return max(0, (int) mjfid_opt('turnos_default'));
	}
	return max(0, (int) $own);
}

/* Turno atendido → suma; deja de estarlo → descuenta. */
add_action('mjto_status_changed', function ($appointment, $old, $new) {
	if (!is_array($appointment) || empty($appointment['id'])) {
		return;
	}
	if ('completed' === $new && 'completed' !== $old) {
		if (!mjfid_enabled() || !(int) mjfid_opt('turnos_enabled')) {
			return;
		}
		$stamps = mjfid_service_stamps((int) ($appointment['service_id'] ?? 0));
		if ($stamps <= 0) {
			return;
		}
		$user   = (int) ($appointment['client_user_id'] ?? 0);
		$holder = $user > 0 ? mjfid_holder('user', $user) : mjfid_holder('client', (int) $appointment['client_id']);
		if (!$holder) {
			return;
		}
		$note = mjfid_fill((string) mjfid_opt('note_turno'), ['servicio' => (string) ($appointment['service_name'] ?? '')]);
		mjfid_add_stamps($holder, $stamps, 'turno', (int) $appointment['id'], $note);
	} elseif ('completed' === $old && 'completed' !== $new) {
		mjfid_remove_source('turno', (int) $appointment['id']);
	}
}, 10, 3);

/* Dos fichas de MJ Turnos que se unen (la clienta confirmó que eran suyas): los sellos pasan a la que queda. */
add_action('mjto_clients_merged', function ($keep_id, $drop_id) {
	global $wpdb;
	$wpdb->update(mjfid_table('stamps'), ['holder_id' => (int) $keep_id], ['holder_type' => 'client', 'holder_id' => (int) $drop_id], ['%d'], ['%s', '%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->update(mjfid_table('rewards'), ['holder_id' => (int) $keep_id], ['holder_type' => 'client', 'holder_id' => (int) $drop_id], ['%d'], ['%s', '%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}, 10, 2);

/* Borrar un turno atendido desde el panel: se descuentan sus sellos. */
add_action('mjto_appointment_deleted', function ($appointment_id) {
	mjfid_remove_source('turno', (int) $appointment_id);
});

/* Cuadro "Fidelidad" en la ficha de cada servicio de MJ Turnos. */
add_action('add_meta_boxes_mjto_service', function () {
	add_meta_box('mjfid_service', 'Fidelidad', 'mjfid_render_service_box', 'mjto_service', 'side', 'default');
});

function mjfid_render_service_box($post) {
	$value = (string) get_post_meta($post->ID, '_mjfid_stamps', true);
	wp_nonce_field('mjfid_service', 'mjfid_service_nonce');
	printf(
		'<p><label for="mjfid_stamps">Sellos que suma cuando se atiende</label><br><input type="number" id="mjfid_stamps" name="mjfid_stamps" value="%1$s" min="0" max="100" step="1" class="small-text" placeholder="%2$d" /></p><p class="description">Vacío = %2$d (Fidelidad → Ajustes). 0 = este servicio no suma.</p>',
		esc_attr($value),
		(int) mjfid_opt('turnos_default')
	);
}

add_action('save_post_mjto_service', function ($post_id) {
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	if (!isset($_POST['mjfid_service_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mjfid_service_nonce'])), 'mjfid_service')) {
		return;
	}
	if (!current_user_can('edit_post', $post_id) || !isset($_POST['mjfid_stamps'])) {
		return;
	}
	$raw = trim(sanitize_text_field(wp_unslash($_POST['mjfid_stamps'])));
	update_post_meta($post_id, '_mjfid_stamps', '' === $raw ? '' : (string) max(0, min(100, (int) $raw)));
});

/* -------------------------------------------------------------------------
 * WooCommerce
 * ---------------------------------------------------------------------- */

/** Sellos que da un pedido según los ajustes. */
function mjfid_order_stamps($order) {
	if ('amount' === (string) mjfid_opt('orders_mode')) {
		$step = (float) mjfid_opt('orders_amount');
		return $step > 0 ? (int) floor((float) $order->get_total() / $step) : 0;
	}
	return max(0, (int) mjfid_opt('orders_stamps'));
}

add_action('woocommerce_order_status_completed', function ($order_id) {
	if (!mjfid_enabled() || !(int) mjfid_opt('orders_enabled') || !function_exists('wc_get_order')) {
		return;
	}
	$order = wc_get_order($order_id);
	if (!$order || (int) $order->get_customer_id() <= 0) {
		return; // Sin cuenta no hay a quién sumarle.
	}
	$stamps = (int) apply_filters('mjfid_order_stamps', mjfid_order_stamps($order), $order);
	if ($stamps <= 0) {
		return;
	}
	$note = mjfid_fill((string) mjfid_opt('note_order'), ['pedido' => $order->get_order_number()]);
	mjfid_add_stamps(['user', (int) $order->get_customer_id()], $stamps, 'pedido', (int) $order->get_id(), $note);
});

foreach (['cancelled', 'refunded'] as $mjfid_status) {
	add_action('woocommerce_order_status_' . $mjfid_status, function ($order_id) {
		mjfid_remove_source('pedido', (int) $order_id);
	});
}
