<?php
/**
 * Opciones (mjfid_options), helpers y la página Fidelidad → Ajustes.
 *
 * Nada está hardcodeado: cuántos sellos tiene la tarjeta, cuántos da cada
 * turno o compra, el premio, si vence y todos los textos salen de acá. Un
 * tema hijo puede proponer otros defaults con el filtro
 * `mjfid_option_defaults`; lo que guarde la dueña siempre gana.
 *
 * @package MJ_Fidelidad
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Defaults y lectura
 * ---------------------------------------------------------------------- */

function mjfid_option_defaults() {
	return (array) apply_filters('mjfid_option_defaults', [
		'enabled'              => 1,
		// La tarjeta
		'stamps_per_card'      => 10,
		'expire_months'        => 0,         // 0 = los sellos no vencen.
		// Premio
		'reward_mode'          => 'text',    // text (lo canjeás a mano) | credit (saldo a favor en MJ Turnos)
		'reward_text'          => 'Un servicio de regalo',
		'reward_credit_amount' => 0,
		// De dónde salen los sellos
		'turnos_enabled'       => 1,         // Turno marcado como atendido.
		'turnos_default'       => 1,         // Sellos por turno si el servicio no dice otra cosa.
		'orders_enabled'       => 0,         // Compras de la tienda (pedido completado).
		'orders_mode'          => 'order',   // order (por compra) | amount (por cada X gastado)
		'orders_stamps'        => 1,         // Sellos por compra (modo order).
		'orders_amount'        => 0,         // Cada cuánto gastado, un sello (modo amount).
		// Textos de la tarjeta
		'card_title'           => 'Tu tarjeta de fidelidad',
		'card_progress'        => '{actuales} de {total} sellos',
		'card_reward_label'    => 'Al completarla:',
		'card_rewards_title'   => 'Tus premios',
		'card_reward_pending'  => 'Para canjear',
		'card_reward_redeemed' => 'Canjeado',
		'card_reward_credited' => 'Sumado a tu saldo a favor',
		'card_empty'           => 'Todavía no tenés sellos. ¡El primero llega con tu próximo turno!',
		'card_login'           => 'Ingresá a tu cuenta para ver tu tarjeta.',
		'card_login_link'      => 'Ingresar',
		'card_history_title'   => 'Movimientos',
		// Mails
		'owner_email'          => '',        // Vacío = el email del administrador.
		'mail_client'          => 1,
		'mail_owner'           => 1,
		'tpl_client_subject'   => '¡Completaste tu tarjeta de {negocio}!',
		'tpl_client_body'      => "¡Hola {nombre}!\n\nCompletaste tu tarjeta de fidelidad: {total} sellos. Tu premio: {premio}.\n\n¡Gracias por elegirnos!\n{negocio}",
		'tpl_owner_subject'    => '{nombre_completo} completó su tarjeta de fidelidad',
		'tpl_owner_body'       => "Hola:\n\n{nombre_completo} completó su tarjeta número {tarjeta} ({total} sellos).\nPremio: {premio}\n\nLo marcás como canjeado desde Fidelidad → Clientas.\n\n— {negocio}",
		// Motivos que quedan en cada movimiento
		'note_turno'           => 'Turno atendido: {servicio}',
		'note_order'           => 'Compra #{pedido}',
		'note_reward'          => 'Tarjeta de fidelidad n.º {tarjeta} completa', // Motivo en el saldo a favor de MJ Turnos.
		// Al BORRAR el plugin (no al desactivarlo): 1 = se borran los sellos, los premios y estos ajustes.
		'delete_on_uninstall'  => 0,
	]);
}

/** Lo guardado tal cual. */
function mjfid_get_options_raw() {
	$saved = get_option('mjfid_options', []);
	return is_array($saved) ? $saved : [];
}

/** Opciones efectivas: guardado + defaults (un texto guardado vacío usa el default). */
function mjfid_get_options() {
	$defaults = mjfid_option_defaults();
	$o        = wp_parse_args(mjfid_get_options_raw(), $defaults);
	foreach ($defaults as $key => $default) {
		if (is_string($default) && '' !== $default && isset($o[$key]) && is_string($o[$key]) && '' === trim($o[$key])) {
			$o[$key] = $default;
		}
	}
	return $o;
}

function mjfid_opt($key) {
	$o = mjfid_get_options();
	return isset($o[$key]) ? $o[$key] : '';
}

/** ¿Está prendido? */
function mjfid_enabled() {
	return (bool) (int) mjfid_opt('enabled');
}

/** Sellos por tarjeta (mínimo 1). */
function mjfid_per_card() {
	return max(1, min(100, (int) mjfid_opt('stamps_per_card')));
}

/** Quién administra la fidelidad (ver y cargar sellos, marcar premios, ajustes). */
function mjfid_capability() {
	return (string) apply_filters('mjfid_capability', 'manage_options');
}

/** Nombre del negocio: el del sitio. */
function mjfid_business_name() {
	return (string) apply_filters('mjfid_business_name', (string) get_bloginfo('name'));
}

/** Email de la dueña (siempre uno válido). */
function mjfid_owner_email() {
	$email = sanitize_email((string) mjfid_opt('owner_email'));
	return is_email($email) ? $email : (string) get_option('admin_email');
}

/** ¿El premio puede ir como saldo a favor? (MJ Turnos con el saldo prendido). */
function mjfid_credit_available() {
	return function_exists('mjto_add_credit') && function_exists('mjto_opt') && (int) mjto_opt('credit_enabled');
}

/* -------------------------------------------------------------------------
 * Guardado
 * ---------------------------------------------------------------------- */

/** Esquema: clave => [tipo, extra]. Es la whitelist de la sanitización. */
function mjfid_option_schema() {
	$s = [
		'enabled'              => ['bool'],
		'stamps_per_card'      => ['int', [1, 100]],
		'expire_months'        => ['int', [0, 120]],
		'reward_mode'          => ['enum', ['text', 'credit']],
		'reward_credit_amount' => ['float', [0, 99999999]],
		'turnos_enabled'       => ['bool'],
		'turnos_default'       => ['int', [0, 100]],
		'orders_enabled'       => ['bool'],
		'orders_mode'          => ['enum', ['order', 'amount']],
		'orders_stamps'        => ['int', [0, 100]],
		'orders_amount'        => ['float', [0, 99999999]],
		'owner_email'          => ['email'],
		'mail_client'          => ['bool'],
		'mail_owner'           => ['bool'],
		'delete_on_uninstall'  => ['bool'],
	];
	foreach (array_keys(mjfid_option_defaults()) as $key) {
		if (!isset($s[$key])) {
			$s[$key] = [0 === strpos($key, 'tpl_') && '_body' === substr($key, -5) ? 'textarea' : 'text'];
		}
	}
	return $s;
}

add_action('admin_init', function () {
	register_setting('mjfid_options_group', 'mjfid_options', [
		'sanitize_callback' => 'mjfid_sanitize_options',
		'default'           => [],
	]);
});

/** Sanitiza lo que llega del formulario (todo en una sola página: los checkbox que no llegan son 0). */
function mjfid_sanitize_options($input) {
	$input = is_array($input) ? $input : [];
	$out   = [];
	foreach (mjfid_option_schema() as $key => $def) {
		$type    = $def[0];
		$extra   = $def[1] ?? null;
		$present = array_key_exists($key, $input);
		if ('bool' === $type) {
			$out[$key] = ($present && !empty($input[$key])) ? 1 : 0;
			continue;
		}
		if (!$present) {
			continue;
		}
		$raw = $input[$key];
		switch ($type) {
			case 'int':
				$out[$key] = max((int) $extra[0], min((int) $extra[1], (int) $raw));
				break;
			case 'float':
				$out[$key] = max((float) $extra[0], min((float) $extra[1], round((float) str_replace(',', '.', (string) $raw), 2)));
				break;
			case 'enum':
				$out[$key] = in_array((string) $raw, $extra, true) ? (string) $raw : (string) $extra[0];
				break;
			case 'email':
				$out[$key] = sanitize_email((string) $raw);
				break;
			case 'textarea':
				$out[$key] = sanitize_textarea_field((string) $raw);
				break;
			default:
				$out[$key] = sanitize_text_field((string) $raw);
		}
	}
	return array_merge(mjfid_get_options_raw(), $out);
}

/* -------------------------------------------------------------------------
 * Menú y página de ajustes
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
	$cap = mjfid_capability();
	/*
	 * Las dos pantallas cuelgan del menú único MJ. Antes Fidelidad era un menú
	 * de primer nivel y la entrada "Clientas" solo estaba para renombrar la
	 * primera fila (WordPress repite ahí el título del padre); acá esa entrada
	 * duplicada sobra, porque el mismo slug 'mjfid' ya es la pantalla de las
	 * clientas.
	 */
	add_submenu_page(MJ_Menu::slug(), 'MJ Fidelidad', 'Fidelidad', $cap, 'mjfid', 'mjfid_render_clients_page');
	add_submenu_page(MJ_Menu::slug(), 'Ajustes', 'Fidelidad · Ajustes', $cap, 'mjfid-ajustes', 'mjfid_render_settings_page');
});

/** Una fila de la tabla de ajustes. */
function mjfid_row($label, $html, $desc = '') {
	echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- el HTML lo arman los helpers de abajo, ya escapado.
	if ('' !== $desc) {
		echo '<p class="description">' . wp_kses($desc, ['code' => [], 'strong' => [], 'a' => ['href' => []]]) . '</p>';
	}
	echo '</td></tr>';
}

/** Valor para mostrar en un campo de texto: lo guardado (vacío = se ve el default en gris). */
function mjfid_field_value($key) {
	$raw = mjfid_get_options_raw();
	if (array_key_exists($key, $raw) && is_scalar($raw[$key])) {
		return (string) $raw[$key];
	}
	$d = mjfid_option_defaults();
	return is_string($d[$key] ?? null) ? '' : (string) ($d[$key] ?? '');
}

function mjfid_input($key, $type = 'text', $class = 'regular-text', $attrs = '') {
	$d = mjfid_option_defaults();
	return sprintf(
		'<input type="%1$s" name="mjfid_options[%2$s]" id="mjfid_%2$s" value="%3$s" class="%4$s" placeholder="%5$s" %6$s />',
		esc_attr($type),
		esc_attr($key),
		esc_attr('number' === $type ? (string) mjfid_opt($key) : mjfid_field_value($key)),
		esc_attr($class),
		esc_attr(is_scalar($d[$key] ?? '') ? (string) $d[$key] : ''),
		$attrs // Solo atributos fijos que pasa este archivo (min, max, step).
	);
}

function mjfid_textarea($key, $rows = 5) {
	$d = mjfid_option_defaults();
	return sprintf(
		'<textarea name="mjfid_options[%1$s]" id="mjfid_%1$s" rows="%2$d" class="large-text" placeholder="%3$s">%4$s</textarea>',
		esc_attr($key),
		(int) $rows,
		esc_attr((string) ($d[$key] ?? '')),
		esc_textarea(mjfid_field_value($key))
	);
}

function mjfid_check($key, $label) {
	return sprintf(
		'<label><input type="checkbox" name="mjfid_options[%1$s]" value="1" %2$s /> %3$s</label>',
		esc_attr($key),
		checked((int) mjfid_opt($key), 1, false),
		esc_html($label)
	);
}

function mjfid_radios($key, array $choices) {
	$html = '';
	foreach ($choices as $value => $label) {
		$html .= sprintf(
			'<label style="display:block; margin:4px 0;"><input type="radio" name="mjfid_options[%1$s]" value="%2$s" %3$s /> %4$s</label>',
			esc_attr($key),
			esc_attr($value),
			checked((string) mjfid_opt($key), (string) $value, false),
			esc_html($label)
		);
	}
	return $html;
}

function mjfid_render_settings_page() {
	if (!current_user_can(mjfid_capability())) {
		return;
	}
	$turnos = function_exists('mjto_services');
	$woo    = function_exists('wc_get_order');
	?>
	<div class="wrap">
		<h1>MJ Fidelidad — Ajustes</h1>
		<p>Una tarjeta de sellos por clienta. Los sellos se suman solos; también los podés sumar o descontar a mano en <a href="<?php echo esc_url(admin_url('admin.php?page=mjfid')); ?>">Fidelidad → Clientas</a>. Lo que dejes vacío usa el texto en gris.</p>
		<?php settings_errors('mjfid_options'); ?>
		<form method="post" action="options.php">
			<?php settings_fields('mjfid_options_group'); ?>

			<h2>La tarjeta</h2>
			<table class="form-table" role="presentation">
				<?php
				mjfid_row('Fidelidad', mjfid_check('enabled', 'Sumar sellos y mostrar la tarjeta'), 'Apagado, no se suman sellos nuevos; lo acumulado queda guardado.');
				mjfid_row('Sellos por tarjeta', mjfid_input('stamps_per_card', 'number', 'small-text', 'min="1" max="100" step="1"'), 'Al llegar a este número la tarjeta se completa, se gana el premio y arranca una nueva (los sellos de más pasan a la siguiente).');
				mjfid_row('Vencimiento', mjfid_input('expire_months', 'number', 'small-text', 'min="0" max="120" step="1"') . ' meses', '0 = los sellos no vencen nunca. Con un número, un sello más viejo que eso deja de contar (los premios ya ganados no se tocan).');
				?>
			</table>

			<h2>El premio</h2>
			<table class="form-table" role="presentation">
				<?php
				$modes = ['text' => 'Un premio que entregás vos (lo marcás como canjeado)'];
				$modes['credit'] = 'Saldo a favor para el próximo turno' . ($turnos ? '' : ' (necesita MJ Turnos Online)');
				mjfid_row('Qué se gana', mjfid_radios('reward_mode', $modes), $turnos && !mjfid_credit_available() ? 'Para el saldo a favor, prendelo en Turnos → Ajustes → Cambios y cancelaciones.' : '');
				mjfid_row('Premio', mjfid_input('reward_text', 'text', 'large-text'), 'Lo que ve la clienta en su tarjeta y en el mail ({premio}). Ej: "Un lash lifting de regalo" o "50% en tu próximo turno".');
				mjfid_row('Monto del saldo a favor', mjfid_input('reward_credit_amount', 'number', 'regular-text', 'min="0" step="0.01"'), 'Solo con "Saldo a favor": se suma solo a su saldo al completar la tarjeta.');
				?>
			</table>

			<h2>De dónde salen los sellos</h2>
			<table class="form-table" role="presentation">
				<?php
				mjfid_row('Turnos', mjfid_check('turnos_enabled', 'Cada turno marcado como atendido suma sellos') . ($turnos ? '' : '<p class="description"><strong>MJ Turnos Online no está activo.</strong></p>'), 'Si después le sacás el "atendido", el sello se descuenta.');
				mjfid_row('Sellos por turno', mjfid_input('turnos_default', 'number', 'small-text', 'min="0" max="100" step="1"'), 'Cada servicio puede dar otra cantidad (o ninguno) desde su ficha en Turnos → Servicios, cuadro "Fidelidad". Por ejemplo, un combo puede dar 2.');
				mjfid_row('Compras de la tienda', mjfid_check('orders_enabled', 'Cada compra completada suma sellos') . ($woo ? '' : '<p class="description"><strong>WooCommerce no está activo.</strong></p>'), 'Solo compras con cuenta (sin cuenta no hay a quién sumarle). Si el pedido se cancela o se reembolsa, se descuentan.');
				mjfid_row('Cómo cuentan las compras', mjfid_radios('orders_mode', [
					'order'  => 'Una cantidad fija por compra',
					'amount' => 'Un sello por cada monto gastado',
				]) . '<p>' . mjfid_input('orders_stamps', 'number', 'small-text', 'min="0" max="100" step="1"') . ' sellos por compra &nbsp; · &nbsp; un sello cada ' . mjfid_input('orders_amount', 'number', 'regular-text', 'min="0" step="0.01"') . '</p>', '');
				mjfid_row('Motivo de un turno', mjfid_input('note_turno', 'text', 'large-text'), 'Queda en los movimientos. <code>{servicio}</code> = el servicio.');
				mjfid_row('Motivo de una compra', mjfid_input('note_order', 'text', 'large-text'), '<code>{pedido}</code> = número de pedido.');
				mjfid_row('Motivo del premio en el saldo', mjfid_input('note_reward', 'text', 'large-text'), 'Con "Saldo a favor": lo que queda escrito en el saldo de la clienta. <code>{tarjeta}</code> = número de tarjeta.');
				?>
			</table>

			<h2>Textos de la tarjeta</h2>
			<table class="form-table" role="presentation">
				<?php
				$texts = [
					'card_title'           => ['Título', ''],
					'card_progress'        => ['Progreso', '<code>{actuales}</code> y <code>{total}</code>.'],
					'card_reward_label'    => ['Antes del premio', ''],
					'card_rewards_title'   => ['Título de los premios', ''],
					'card_reward_pending'  => ['Premio para canjear', ''],
					'card_reward_redeemed' => ['Premio canjeado', ''],
					'card_reward_credited' => ['Premio como saldo', ''],
					'card_empty'           => ['Sin sellos', ''],
					'card_login'           => ['Sin sesión', ''],
					'card_login_link'      => ['Enlace para ingresar', ''],
					'card_history_title'   => ['Título de los movimientos', ''],
				];
				foreach ($texts as $key => [$label, $desc]) {
					mjfid_row($label, mjfid_input($key, 'text', 'large-text'), $desc);
				}
				?>
			</table>

			<h2>Mails</h2>
			<p>Comodines: <code>{nombre}</code>, <code>{nombre_completo}</code>, <code>{premio}</code>, <code>{total}</code> (sellos por tarjeta), <code>{tarjeta}</code> (número de tarjeta completada), <code>{negocio}</code>.</p>
			<table class="form-table" role="presentation">
				<?php
				mjfid_row('Avisos', mjfid_check('mail_client', 'A la clienta, cuando completa la tarjeta') . '<br>' . mjfid_check('mail_owner', 'A vos, cuando alguien completa la tarjeta'), '');
				mjfid_row('Tu email', mjfid_input('owner_email', 'email', 'regular-text'), 'Vacío = el email del administrador.');
				mjfid_row('Mail a la clienta', mjfid_input('tpl_client_subject', 'text', 'large-text') . '<p class="description">asunto</p>' . mjfid_textarea('tpl_client_body', 7), '');
				mjfid_row('Mail para vos', mjfid_input('tpl_owner_subject', 'text', 'large-text') . '<p class="description">asunto</p>' . mjfid_textarea('tpl_owner_body', 7), '');
				?>
			</table>

			<h2>Al borrar el plugin</h2>
			<table class="form-table" role="presentation">
				<?php mjfid_row('Datos', mjfid_check('delete_on_uninstall', 'Borrar también los sellos, los premios y estos ajustes'), 'Solo al <strong>borrar</strong> el plugin desde Plugins, nunca al desactivarlo. Desmarcado, todo queda guardado por si lo volvés a instalar.'); ?>
			</table>

			<?php submit_button('Guardar ajustes'); ?>
		</form>
	</div>
	<?php
}
