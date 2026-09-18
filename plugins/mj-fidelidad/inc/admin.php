<?php
/**
 * Fidelidad → Clientas: la lista de tarjetas, el detalle de cada una, sellos
 * a mano (sumar o descontar, con motivo) y premios canjeados.
 *
 * Las acciones van por admin-post.php con nonce y el permiso de
 * mjfid_capability() (por defecto, administradores).
 *
 * @package MJ_Fidelidad
 */

defined('ABSPATH') || exit;

/** "user:12" / "client:5" → titular o null. */
function mjfid_parse_holder($value) {
	$parts = explode(':', sanitize_text_field((string) $value), 2);
	return 2 === count($parts) ? mjfid_holder($parts[0], (int) $parts[1]) : null;
}

/** Titular → "user:12". */
function mjfid_holder_key(array $holder) {
	return $holder[0] . ':' . (int) $holder[1];
}

/** URL de la pantalla (con el detalle de una persona, si se pasa). */
function mjfid_admin_url(?array $holder = null, array $args = []) {
	$url = admin_url('admin.php?page=mjfid');
	if ($holder) {
		$args['persona'] = mjfid_holder_key($holder);
	}
	return add_query_arg($args, $url);
}

/** Busca a quién sumarle por email: una cuenta, o una ficha de MJ Turnos. */
function mjfid_find_by_email($email) {
	$email = sanitize_email((string) $email);
	if (!is_email($email)) {
		return null;
	}
	$user = get_user_by('email', $email);
	if ($user) {
		return ['user', (int) $user->ID];
	}
	if (function_exists('mjto_table')) {
		global $wpdb;
		$id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . mjto_table('clients') . ' WHERE email = %s ORDER BY id ASC LIMIT 1', $email)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla de MJ Turnos; el valor va por prepare.
		if ($id > 0) {
			return mjfid_people(['client', $id])[0];
		}
	}
	return null;
}

/* -------------------------------------------------------------------------
 * Acciones
 * ---------------------------------------------------------------------- */

add_action('admin_post_mjfid_stamps', function () {
	if (!current_user_can(mjfid_capability())) {
		wp_die('Sin permiso.');
	}
	check_admin_referer('mjfid_stamps');
	$holder = isset($_POST['persona']) ? mjfid_parse_holder(wp_unslash($_POST['persona'])) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- se sanitiza en mjfid_parse_holder.
	if (!$holder && isset($_POST['email'])) {
		$holder = mjfid_find_by_email(sanitize_email(wp_unslash($_POST['email'])));
	}
	$stamps = isset($_POST['stamps']) ? (int) $_POST['stamps'] : 0;
	$note   = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
	if (!$holder) {
		wp_safe_redirect(mjfid_admin_url(null, ['mjfid_msg' => 'no_encontrada']));
		exit;
	}
	/* Descontar nunca deja la tarjeta en negativo. */
	if ($stamps < 0) {
		$stamps = -min(abs($stamps), mjfid_total_stamps($holder));
	}
	mjfid_add_stamps(mjfid_people($holder)[0], $stamps, 'manual', null, $note);
	wp_safe_redirect(mjfid_admin_url(mjfid_people($holder)[0], ['mjfid_msg' => 'sellos']));
	exit;
});

add_action('admin_post_mjfid_reward', function () {
	if (!current_user_can(mjfid_capability())) {
		wp_die('Sin permiso.');
	}
	check_admin_referer('mjfid_reward');
	$id     = isset($_POST['reward']) ? (int) $_POST['reward'] : 0;
	$status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
	$reward = mjfid_get_reward($id);
	if ($reward) {
		mjfid_set_reward_status($id, $status);
	}
	$holder = $reward ? mjfid_holder($reward['holder_type'], $reward['holder_id']) : null;
	wp_safe_redirect(mjfid_admin_url($holder, ['mjfid_msg' => 'premio']));
	exit;
});

/* -------------------------------------------------------------------------
 * Pantalla
 * ---------------------------------------------------------------------- */

function mjfid_render_clients_page() {
	if (!current_user_can(mjfid_capability())) {
		return;
	}
	$msg     = isset($_GET['mjfid_msg']) ? sanitize_key(wp_unslash($_GET['mjfid_msg'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- solo elige qué aviso mostrar.
	$persona = isset($_GET['persona']) ? mjfid_parse_holder(wp_unslash($_GET['persona'])) : null; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- solo elige qué ficha mostrar; se sanitiza en mjfid_parse_holder.
	$notices = [
		'sellos'       => ['success', 'Listo: la tarjeta quedó actualizada.'],
		'premio'       => ['success', 'Listo: el premio quedó actualizado.'],
		'no_encontrada' => ['error', 'No hay ninguna cuenta ni clienta con ese email.'],
	];
	echo '<div class="wrap"><h1>Fidelidad</h1>';
	if (!mjfid_enabled()) {
		echo '<div class="notice notice-warning inline"><p>La fidelidad está apagada (Fidelidad → Ajustes): no se suman sellos nuevos.</p></div>';
	}
	if (isset($notices[$msg])) {
		printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notices[$msg][0]), esc_html($notices[$msg][1]));
	}
	if ($persona) {
		mjfid_render_person($persona);
	} else {
		mjfid_render_list();
	}
	echo '</div>';
}

/** Formulario de sellos a mano (para una persona, o por email si no se pasa). */
function mjfid_stamps_form(?array $holder = null) {
	?>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin:12px 0;">
		<input type="hidden" name="action" value="mjfid_stamps" />
		<?php wp_nonce_field('mjfid_stamps'); ?>
		<?php if ($holder) : ?>
			<input type="hidden" name="persona" value="<?php echo esc_attr(mjfid_holder_key($holder)); ?>" />
		<?php else : ?>
			<label>Email de la clienta<br><input type="email" name="email" class="regular-text" required /></label>
		<?php endif; ?>
		<label>Sellos (negativo = descontar)<br><input type="number" name="stamps" value="1" step="1" min="-100" max="100" class="small-text" required /></label>
		<label>Motivo<br><input type="text" name="note" class="regular-text" placeholder="Opcional" /></label>
		<?php submit_button('Guardar', 'secondary', 'submit', false); ?>
	</form>
	<?php
}

function mjfid_render_list() {
	$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- solo filtra la lista.
	$people = mjfid_people_list($search);
	$per    = mjfid_per_card();
	?>
	<p>Cada clienta con sellos, la más reciente primero. Tocá una para ver su tarjeta, sus premios y sus movimientos.</p>
	<form method="get" style="margin:12px 0;">
		<input type="hidden" name="page" value="mjfid" />
		<input type="search" name="s" value="<?php echo esc_attr($search); ?>" class="regular-text" placeholder="Buscar por nombre o email" />
		<?php submit_button('Buscar', 'secondary', '', false); ?>
	</form>
	<h2>Sumar sellos a alguien</h2>
	<?php mjfid_stamps_form(); ?>
	<table class="widefat striped" style="max-width:900px;">
		<thead><tr><th>Clienta</th><th>Tarjeta</th><th>Tarjetas completas</th><th>Premios por canjear</th><th>Último movimiento</th></tr></thead>
		<tbody>
		<?php if (!$people) : ?>
			<tr><td colspan="5">Todavía nadie tiene sellos.</td></tr>
		<?php endif; ?>
		<?php foreach ($people as $person) : ?>
			<?php
			$total   = mjfid_total_stamps($person['holder']);
			$pending = count(array_filter(mjfid_rewards($person['holder']), fn ($r) => 'pending' === $r['status']));
			?>
			<tr>
				<td><a href="<?php echo esc_url(mjfid_admin_url($person['holder'])); ?>"><strong><?php echo esc_html('' !== $person['contact']['name'] ? $person['contact']['name'] : $person['contact']['email']); ?></strong></a><br><span class="description"><?php echo esc_html($person['contact']['email']); ?></span></td>
				<td><?php echo esc_html(($total % $per) . ' / ' . $per); ?></td>
				<td><?php echo (int) intdiv($total, $per); ?></td>
				<td><?php echo $pending ? '<strong>' . (int) $pending . '</strong>' : '0'; ?></td>
				<td><?php echo esc_html(mysql2date('j M Y H:i', $person['lastAt'])); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function mjfid_render_person(array $holder) {
	$holder  = mjfid_people($holder)[0];
	$contact = mjfid_person_contact($holder);
	$card    = mjfid_card($holder);
	?>
	<p><a href="<?php echo esc_url(mjfid_admin_url()); ?>">&larr; Todas las clientas</a></p>
	<h2><?php echo esc_html('' !== $contact['name'] ? $contact['name'] : $contact['email']); ?></h2>
	<p class="description"><?php echo esc_html($contact['email']); ?></p>
	<p style="font-size:16px;"><strong><?php echo esc_html($card['progressText']); ?></strong> &nbsp;·&nbsp; <?php echo (int) $card['completed']; ?> tarjeta(s) completa(s) &nbsp;·&nbsp; <?php echo (int) $card['total']; ?> sellos válidos en total</p>

	<h3>Sumar o descontar sellos</h3>
	<?php mjfid_stamps_form($holder); ?>

	<h3>Premios</h3>
	<table class="widefat striped" style="max-width:900px;">
		<thead><tr><th>Tarjeta</th><th>Premio</th><th>Estado</th><th></th></tr></thead>
		<tbody>
		<?php if (!$card['rewards']) : ?>
			<tr><td colspan="4">Todavía no completó ninguna tarjeta.</td></tr>
		<?php endif; ?>
		<?php foreach ($card['rewards'] as $reward) : ?>
			<tr>
				<td>n.º <?php echo (int) $reward['card']; ?></td>
				<td><?php echo esc_html($reward['text']); ?><?php echo $reward['credit'] > 0 ? ' <span class="description">(' . esc_html(number_format_i18n($reward['credit'], 2)) . ' de saldo)</span>' : ''; ?></td>
				<td><?php echo esc_html($reward['statusLabel']); ?><?php echo $reward['redeemedAt'] ? '<br><span class="description">' . esc_html(mysql2date('j M Y', $reward['redeemedAt'])) . '</span>' : ''; ?></td>
				<td>
					<?php
					$next = 'pending' === $reward['status'] ? 'redeemed' : ('redeemed' === $reward['status'] ? 'pending' : '');
					if ('' !== $next) :
						?>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
							<input type="hidden" name="action" value="mjfid_reward" />
							<input type="hidden" name="reward" value="<?php echo (int) $reward['id']; ?>" />
							<input type="hidden" name="status" value="<?php echo esc_attr($next); ?>" />
							<?php wp_nonce_field('mjfid_reward'); ?>
							<?php submit_button('pending' === $next ? 'Deshacer el canje' : 'Marcar canjeado', 'pending' === $next ? 'link' : 'primary', 'submit', false); ?>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h3>Movimientos</h3>
	<table class="widefat striped" style="max-width:900px;">
		<thead><tr><th>Fecha</th><th>Sellos</th><th>Motivo</th></tr></thead>
		<tbody>
		<?php foreach ($card['movements'] as $move) : ?>
			<tr>
				<td><?php echo esc_html(mysql2date('j M Y H:i', $move['createdAt'])); ?></td>
				<td><?php echo esc_html(($move['stamps'] > 0 ? '+' : '') . $move['stamps']); ?></td>
				<td><?php echo esc_html($move['note']); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
