<?php
/**
 * API de skins (temas hijo).
 *
 * Antes cada tema hijo repetía en su functions.php la misma mecánica: una
 * tabla de colores, otra de tipografías, otra de textos, y un filtro sobre
 * `option_mj_options` que decidía cuándo pisar el default del padre. Eso hacía
 * que la identidad de cada skin viviera en el código y no se pudiera tocar
 * desde el admin.
 *
 * Ahora un hijo sólo se DESCRIBE con el filtro `mj_skins`:
 *
 *     add_filter('mj_skins', function ($skins) {
 *         $skins['glossyhair'] = [
 *             'name'      => 'GlossyHair',
 *             'palette'   => ['color_accent' => '#e11d84', …],
 *             'fonts'     => ['font_display' => 'Urbanist', 'font_sans' => 'Manrope'],
 *             'fonts_url' => 'https://fonts.googleapis.com/…',   // opcional
 *             'options'   => ['hero_title' => '…', …],           // otras propuestas
 *             'copy'      => ['nav_shop' => 'Productos', …],     // textos
 *             'home'      => ['order' => '…', 'hidden' => '…'],  // portada inicial
 *             'presets'   => [                                    // paletas alternativas
 *                 'noche' => ['label' => 'Noche', 'palette' => […], 'fonts' => […]],
 *             ],
 *         ];
 *         return $skins;
 *     });
 *
 * El padre se encarga del resto: propone esos valores donde el admin no
 * eligió nada, los muestra en Apariencia → MJ Appearance → Skin, y deja
 * aplicar cualquier preset con un botón (que ESCRIBE los valores en los
 * ajustes, así quedan editables como cualquier otro campo).
 *
 * Nada es obligatorio: una skin puede describir sólo la paleta, sólo los
 * textos, o nada y limitarse a su CSS.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Registro
 * ---------------------------------------------------------------------- */

/* Todas las skins registradas: slug => descripción (array). */
function mj_skins() {
	static $skins = null;
	if (null !== $skins) {
		return $skins;
	}
	/*
	 * Se cachea ANTES de aplicar el filtro: si una skin consultara las
	 * opciones al describirse, el filtro `option_mj_options` volvería a
	 * entrar acá y se colgaría en un bucle.
	 */
	$skins = [];
	$skins = (array) apply_filters('mj_skins', $skins);

	$clean = [];
	foreach ($skins as $slug => $skin) {
		$slug = sanitize_key((string) $slug);
		if ('' === $slug || !is_array($skin)) {
			continue;
		}
		$clean[$slug] = wp_parse_args($skin, [
			'name'      => $slug,
			'palette'   => [],
			'fonts'     => [],
			'fonts_url' => '',
			'options'   => [],
			'copy'      => [],
			'home'      => [],
			'presets'   => [],
		]);
	}
	$skins = $clean;
	return $skins;
}

/* Descripción de la skin activa, o [] si el tema base está solo. */
function mj_active_skin() {
	$slug = mj_skin();
	$all  = mj_skins();
	return '' !== $slug && isset($all[$slug]) ? $all[$slug] : [];
}

/*
 * Opciones tal cual están guardadas, SIN las propuestas de la skin.
 *
 * Leer la opción desde adentro del propio filtro `option_mj_options` sería
 * recursión infinita, así que el filtro se desengancha mientras se lee.
 */
function mj_raw_options() {
	remove_filter('option_mj_options', 'mj_skin_apply_proposals');
	$raw = get_option('mj_options', []);
	add_filter('option_mj_options', 'mj_skin_apply_proposals');
	return is_array($raw) ? $raw : [];
}

/*
 * ¿El admin quiere que la skin proponga sus valores?
 *
 * $raw permite pasar las opciones ya leídas (el filtro las recibe como
 * argumento) y ahorrarse la lectura.
 */
function mj_skin_defaults_enabled($raw = null) {
	if (!is_array($raw)) {
		$raw = mj_raw_options();
	}
	$on = !isset($raw['skin_defaults']) ? 1 : (int) $raw['skin_defaults'];
	return (bool) apply_filters('mj_skin_defaults_enabled', 1 === $on);
}

/* -------------------------------------------------------------------------
 * Propuestas de la skin sobre las opciones
 *
 * Una clave toma el valor de la skin cuando el admin no eligió nada, es decir
 * cuando está vacía o cuando tiene EXACTAMENTE el default del tema base (los
 * selectores de color del admin vienen prellenados con esos valores y se
 * guardan aunque nadie los toque). Un valor distinto siempre gana.
 * ---------------------------------------------------------------------- */

/* Propuestas de la skin activa: clave => valor. */
function mj_skin_option_proposals() {
	$skin = mj_active_skin();
	if (!$skin) {
		return [];
	}
	$proposals = array_merge(
		(array) $skin['options'],
		(array) $skin['palette'],
		(array) $skin['fonts']
	);
	return (array) apply_filters('mj_skin_option_proposals', $proposals, $skin);
}

/* Valor "sin configurar" de una clave para el tema base. */
function mj_base_default($key) {
	$colors = mj_color_defaults();
	if (isset($colors[$key])) {
		return (string) $colors[$key];
	}
	$defaults = mj_option_defaults();
	return isset($defaults[$key]) && is_scalar($defaults[$key]) ? (string) $defaults[$key] : '';
}

/* ¿La clave sigue "sin configurar" por el admin? */
function mj_option_untouched($saved, $key) {
	$value = isset($saved[$key]) && is_scalar($saved[$key]) ? trim((string) $saved[$key]) : '';
	if ('' === $value) {
		return true;
	}
	$base = trim(mj_base_default($key));
	return '' !== $base && 0 === strcasecmp($value, $base);
}

/*
 * Función con nombre (no closure) a propósito: mj_raw_options() necesita
 * poder desengancharla para leer la opción sin volver a entrar acá.
 */
function mj_skin_apply_proposals($options) {
	if (!is_array($options)) {
		$options = [];
	}
	/* Cinturón extra: si algo reentrara igual, se devuelve lo leído sin tocar. */
	static $busy = false;
	if ($busy || !mj_skin_defaults_enabled($options)) {
		return $options;
	}
	$busy = true;
	foreach (mj_skin_option_proposals() as $key => $value) {
		if (is_scalar($value) && mj_option_untouched($options, $key)) {
			$options[$key] = $value;
		}
	}
	$busy = false;
	return $options;
}
add_filter('option_mj_options', 'mj_skin_apply_proposals');

/* Textos propuestos por la skin (el admin los pisa desde "Textos de la tienda"). */
add_filter('mj_copy_defaults', function ($copy) {
	$skin = mj_active_skin();
	if (!$skin || !mj_skin_defaults_enabled()) {
		return $copy;
	}
	$skin_copy = array_filter((array) $skin['copy'], 'is_scalar');
	return array_merge((array) $copy, $skin_copy);
});

/* URL de Google Fonts propia de la skin, sólo si sus familias siguen vigentes. */
add_filter('mj_fonts_url', function ($url, $families) {
	$skin = mj_active_skin();
	if (!$skin || '' === (string) $skin['fonts_url']) {
		return $url;
	}
	$own = array_values(array_filter((array) $skin['fonts']));
	sort($own);
	$in_use = array_values($families);
	sort($in_use);
	return $own === $in_use ? (string) $skin['fonts_url'] : $url;
}, 10, 2);

/* -------------------------------------------------------------------------
 * Primera activación
 *
 * Al activar el hijo se escriben UNA vez las claves que no son "propuestas"
 * sino arranque: el orden de la portada y las preferencias de presentación.
 * Después el admin manda desde los ajustes.
 * ---------------------------------------------------------------------- */
add_action('after_switch_theme', function () {
	$skin = mj_active_skin();
	if (!$skin) {
		return;
	}
	$saved = mj_raw_options();

	/* Preferencias de arranque que la skin declare en 'home'. */
	$home = (array) $skin['home'];
	foreach (['design_theme', 'motion_level', 'intro_enabled'] as $key) {
		if (isset($home[$key])) {
			$saved[$key] = $home[$key];
		}
	}

	/* Orden y secciones ocultas: sólo si nadie ordenó la portada todavía. */
	if (empty($saved['home_order'])) {
		if (isset($home['order'])) {
			$saved['home_order'] = (string) $home['order'];
		}
		if (isset($home['hidden'])) {
			$saved['home_hidden'] = (string) $home['hidden'];
		}
	}

	update_option('mj_options', $saved);
});

/* -------------------------------------------------------------------------
 * Presets: escribir la paleta de la skin en los ajustes
 *
 * Aplicar un preset no "activa un modo": copia sus valores a las opciones,
 * que quedan editables campo por campo como cualquier otro ajuste.
 * ---------------------------------------------------------------------- */

/* Presets de la skin activa, con el suyo propio primero. */
function mj_skin_presets() {
	$skin = mj_active_skin();
	if (!$skin) {
		return [];
	}
	$presets = [];
	if ($skin['palette'] || $skin['fonts']) {
		$presets['default'] = [
			'label'   => sprintf('Paleta original de %s', (string) $skin['name']),
			'palette' => (array) $skin['palette'],
			'fonts'   => (array) $skin['fonts'],
		];
	}
	foreach ((array) $skin['presets'] as $key => $preset) {
		$key = sanitize_key((string) $key);
		if ('' === $key || !is_array($preset)) {
			continue;
		}
		$presets[$key] = wp_parse_args($preset, [
			'label'   => $key,
			'palette' => [],
			'fonts'   => [],
		]);
	}
	return (array) apply_filters('mj_skin_presets', $presets, $skin);
}

add_action('admin_post_mj_apply_skin_preset', function () {
	if (!current_user_can(mj_settings_capability())) {
		wp_die(esc_html__('No tenés permisos para cambiar los ajustes del tema.', 'mj-appearance'));
	}
	check_admin_referer('mj_apply_skin_preset');

	$key     = isset($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '';
	$presets = mj_skin_presets();
	if ('' === $key || !isset($presets[$key])) {
		wp_safe_redirect(add_query_arg('mj-skin', 'error', mj_settings_page_url()));
		exit;
	}

	$saved  = mj_raw_options();
	$values = array_merge(
		(array) $presets[$key]['palette'],
		(array) $presets[$key]['fonts']
	);
	foreach ($values as $option_key => $value) {
		if (is_scalar($value)) {
			$saved[$option_key] = (string) $value;
		}
	}
	update_option('mj_options', $saved);

	wp_safe_redirect(add_query_arg('mj-skin', 'applied', mj_settings_page_url()));
	exit;
});

/* -------------------------------------------------------------------------
 * Aviso: tema hijo sin el padre esperado
 *
 * Un hijo declara la versión mínima del padre que necesita con el filtro
 * `mj_skin_requires`. Sirve para detectar el caso real de subir el hijo nuevo
 * sobre un padre viejo: el sitio anda, pero faltan ajustes.
 * ---------------------------------------------------------------------- */
add_action('admin_notices', function () {
	if (!is_child_theme() || !current_user_can(mj_settings_capability())) {
		return;
	}
	$required = (string) apply_filters('mj_skin_requires', '');
	if ('' === $required || version_compare(MJ_VERSION, $required, '>=')) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>%s:</strong> %s</p></div>',
		esc_html(wp_get_theme()->get('Name')),
		sprintf(
			/* translators: 1: versión requerida del padre, 2: versión instalada. */
			esc_html__('necesita %1$s o superior del tema padre y hay instalada la %2$s. Actualizá el tema padre para tener todos los ajustes.', 'mj-appearance'),
			'<code>' . esc_html($required) . '</code>',
			'<code>' . esc_html(MJ_VERSION) . '</code>'
		)
	);
});

/* -------------------------------------------------------------------------
 * Interfaz: Apariencia → MJ Appearance → Skin
 * ---------------------------------------------------------------------- */
function mj_render_skin_section($options) {
	$skin    = mj_active_skin();
	$presets = mj_skin_presets();
	$slug    = mj_skin();
	?>
	<h2>Skin</h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Skin activa</th>
			<td>
				<?php if ($skin) : ?>
					<p style="margin:0 0 6px;"><strong><?php echo esc_html((string) $skin['name']); ?></strong> <code><?php echo esc_html($slug); ?></code></p>
					<p class="description">La aporta el tema hijo activo. Propone paleta, tipografías y textos; todo lo que cambies a mano en esta página le gana a la propuesta.</p>
				<?php else : ?>
					<p style="margin:0 0 6px;"><em>Ninguna</em> — está activo el tema base.</p>
					<p class="description">Activá un tema hijo (Apariencia → Temas) para usar una skin: cambia la paleta, las tipografías y los textos de toda la tienda de una vez.</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php if ($skin) : ?>
			<tr>
				<th scope="row">Propuestas de la skin</th>
				<td>
					<label for="mj_skin_defaults">
						<input type="checkbox" id="mj_skin_defaults" name="mj_options[skin_defaults]" value="1" <?php checked((int) $options['skin_defaults'], 1); ?> />
						Usar la paleta, las tipografías y los textos que propone la skin donde no configuraste nada.
					</label>
					<p class="description">Si lo desmarcás, la tienda vuelve a los valores del tema base salvo en los campos que hayas completado. El CSS de la skin se sigue aplicando.</p>
				</td>
			</tr>
			<?php if ($presets) : ?>
				<tr>
					<th scope="row">Paletas de la skin</th>
					<td>
						<?php foreach ($presets as $key => $preset) : ?>
							<div style="display:flex; align-items:center; gap:10px; margin:0 0 10px; flex-wrap:wrap;">
								<span style="display:inline-flex; gap:4px;">
									<?php foreach ((array) $preset['palette'] as $color) : ?>
										<?php if (is_string($color) && mj_hex_rgb($color)) : ?>
											<span title="<?php echo esc_attr($color); ?>" style="width:22px; height:22px; border-radius:5px; border:1px solid rgba(0,0,0,.15); background:<?php echo esc_attr($color); ?>;"></span>
										<?php endif; ?>
									<?php endforeach; ?>
								</span>
								<span><?php echo esc_html((string) $preset['label']); ?></span>
								<?php if (!empty($preset['fonts'])) : ?>
									<code style="font-size:11px;"><?php echo esc_html(implode(' + ', array_filter((array) $preset['fonts']))); ?></code>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
						<p class="description">
							Aplicar una paleta <strong>escribe</strong> esos colores y tipografías en los campos de "Diseño y marca", donde después podés retocarlos uno por uno.
							Se aplica al instante: guardá los demás cambios de la página antes de usar el botón.
						</p>
						<p style="margin-top:10px;">
							<?php foreach ($presets as $key => $preset) : ?>
								<button type="submit" form="mj-skin-preset-form" name="preset" value="<?php echo esc_attr($key); ?>" class="button">
									Aplicar: <?php echo esc_html((string) $preset['label']); ?>
								</button>
							<?php endforeach; ?>
						</p>
					</td>
				</tr>
			<?php endif; ?>
			<?php if (!empty($skin['copy'])) : ?>
				<tr>
					<th scope="row">Textos de la skin</th>
					<td>
						<p style="margin:0 0 6px;"><?php echo esc_html(sprintf('%d textos propuestos.', count((array) $skin['copy']))); ?></p>
						<p class="description">Se ven en gris como referencia en "Textos de la tienda". Escribí encima de cualquiera para cambiarlo.</p>
					</td>
				</tr>
			<?php endif; ?>
		<?php endif; ?>
	</table>
	<?php
}

/*
 * El formulario de los presets va SEPARADO del de los ajustes (que postea a
 * options.php) pero se imprime fuera de él para no anidar formularios: los
 * botones lo referencian con el atributo form=.
 */
function mj_render_skin_preset_form() {
	if (!mj_skin_presets()) {
		return;
	}
	?>
	<form id="mj-skin-preset-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="mj_apply_skin_preset" />
		<?php wp_nonce_field('mj_apply_skin_preset'); ?>
	</form>
	<?php
}

/* Resultado de aplicar un preset. */
add_action('admin_notices', function () {
	if (!isset($_GET['mj-skin']) || !current_user_can(mj_settings_capability())) {
		return;
	}
	$result = sanitize_key(wp_unslash($_GET['mj-skin']));
	if ('applied' === $result) {
		echo '<div class="notice notice-success is-dismissible"><p>Paleta de la skin aplicada. Ya podés retocar cada color en "Diseño y marca".</p></div>';
	} elseif ('error' === $result) {
		echo '<div class="notice notice-error is-dismissible"><p>No se pudo aplicar esa paleta: ya no existe en la skin activa.</p></div>';
	}
});
