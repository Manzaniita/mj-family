<?php
/**
 * Opciones del plugin (mjc_options), helpers y página Colegas → Ajustes.
 *
 * Nada está hardcodeado: nombres ("Colega/Colegas", "Hecho por"), el
 * proyecto (nombre, slug, textos, fotos), qué se muestra en la tienda y
 * cómo se calcula la comisión salen de acá. Un tema hijo puede proponer
 * otros defaults con el filtro `mjc_option_defaults`.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Defaults y lectura
 * ---------------------------------------------------------------------- */
function mjc_option_defaults() {
	return (array) apply_filters('mjc_option_defaults', [
		// Comisión
		'commission_default'       => 10,      // % que se queda la tienda (si el colega no tiene uno propio).
		'commission_base'          => 'total', // total (lo que pagó el cliente por la línea) | subtotal (antes de cupones).
		'order_statuses'           => 'processing,completed', // Estados que cuentan como venta.
		// Nombres
		'label_singular'           => 'Colega',
		'label_plural'             => 'Colegas',
		'label_made_by'            => 'Hecho por',
		// La tienda misma (productos sin colega)
		'house_name'               => '', // Vacío = nombre del sitio.
		'house_slug'               => '', // Vacío = slug del nombre del sitio.
		'house_description'        => '',
		'house_image'              => 0,
		'house_instagram'          => '',
		// Proyecto (página /{slug})
		'project_name'             => 'Colegas',
		'project_slug'             => 'colegas',
		'project_menu'             => 1,
		'project_menu_label'       => '', // Vacío = nombre del proyecto.
		'project_eyebrow'          => 'Proyecto',
		'project_title'            => '', // Vacío = nombre del proyecto.
		'project_subtitle'         => '',
		'project_description'      => '',
		'project_images'           => '', // IDs de adjuntos CSV (la primera es la portada).
		'project_all_label'        => 'Todo',
		'project_makers_eyebrow'   => '', // Vacío = plural.
		'project_makers_title'     => 'Quiénes lo hacen',
		'project_products_eyebrow' => 'Productos',
		'project_products_title'   => 'Todo lo que hacen',
		'project_shop_link'        => 'Ver en la tienda',
		'project_back_label'       => '', // Vacío = nombre del proyecto.
		// Sección en la portada
		'home_section'             => 1,
		'home_eyebrow'             => '', // Vacío = nombre del proyecto.
		'home_title'               => 'Hecho por colegas',
		'home_subtitle'            => '',
		'home_link'                => '', // Vacío = nombre del proyecto.
		// Qué se muestra en la tienda
		'show_badge'               => 1, // Chip sobre la foto en productos de colegas.
		'show_house_badge'         => 0, // Chip también en los productos propios.
		'show_filter'              => 1, // Filtro "¿quién lo hizo?" en la tienda.
		'show_product_block'       => 1, // Bloque "Hecho por" en la ficha.
	]);
}

function mjc_get_options() {
	$saved = get_option('mjc_options', []);
	if (!is_array($saved)) {
		$saved = [];
	}
	return wp_parse_args($saved, mjc_option_defaults());
}

function mjc_opt($key) {
	$o = mjc_get_options();
	return isset($o[$key]) ? $o[$key] : '';
}

/* Nombre y slug de la tienda misma (productos sin colega). */
function mjc_house_name() {
	$name = trim((string) mjc_opt('house_name'));
	return '' !== $name ? $name : (string) get_bloginfo('name');
}

function mjc_house_slug() {
	$slug = sanitize_title((string) mjc_opt('house_slug'));
	if ('' === $slug) {
		$slug = sanitize_title(mjc_house_name());
	}
	return '' !== $slug ? $slug : 'tienda';
}

function mjc_project_name() {
	$name = trim((string) mjc_opt('project_name'));
	return '' !== $name ? $name : mjc_opt('label_plural');
}

function mjc_project_slug() {
	$slug = sanitize_title((string) mjc_opt('project_slug'));
	return '' !== $slug ? $slug : 'colegas';
}

/* Estados de pedido que cuentan como venta (sin prefijo wc-). */
function mjc_sale_statuses() {
	$list = array_filter(array_map('sanitize_key', explode(',', (string) mjc_opt('order_statuses'))));
	$list = array_map(fn ($s) => preg_replace('/^wc-/', '', $s), $list);
	return $list ? array_values(array_unique($list)) : ['processing', 'completed'];
}

/* -------------------------------------------------------------------------
 * Settings API
 * ---------------------------------------------------------------------- */
add_action('admin_init', function () {
	register_setting('mjc_options_group', 'mjc_options', [
		'sanitize_callback' => 'mjc_sanitize_options',
		'default'           => mjc_option_defaults(),
	]);
});

function mjc_sanitize_options($input) {
	$input = is_array($input) ? $input : [];
	$out   = [];

	$pct = isset($input['commission_default']) ? (float) str_replace(',', '.', (string) $input['commission_default']) : 10;
	$out['commission_default'] = max(0, min(100, round($pct, 2)));

	$base = isset($input['commission_base']) ? (string) $input['commission_base'] : 'total';
	$out['commission_base'] = in_array($base, ['total', 'subtotal'], true) ? $base : 'total';

	$statuses = isset($input['order_statuses']) ? (array) $input['order_statuses'] : [];
	$statuses = array_filter(array_map('sanitize_key', $statuses));
	$out['order_statuses'] = implode(',', $statuses ? $statuses : ['processing', 'completed']);

	foreach ([
		'label_singular', 'label_plural', 'label_made_by',
		'house_name', 'house_slug',
		'project_name', 'project_slug', 'project_menu_label', 'project_eyebrow', 'project_title', 'project_subtitle',
		'project_all_label', 'project_makers_eyebrow', 'project_makers_title', 'project_products_eyebrow',
		'project_products_title', 'project_shop_link', 'project_back_label',
		'home_eyebrow', 'home_title', 'home_subtitle', 'home_link',
	] as $key) {
		$out[$key] = isset($input[$key]) ? sanitize_text_field((string) $input[$key]) : '';
	}
	$out['house_slug']   = sanitize_title($out['house_slug']);
	$out['project_slug'] = sanitize_title($out['project_slug']);

	foreach (['house_description', 'project_description'] as $key) {
		$out[$key] = isset($input[$key]) ? sanitize_textarea_field((string) $input[$key]) : '';
	}

	$out['house_instagram'] = isset($input['house_instagram']) ? esc_url_raw((string) $input['house_instagram']) : '';

	$out['house_image']    = isset($input['house_image']) ? absint(strtok((string) $input['house_image'], ',')) : 0;
	$out['project_images'] = isset($input['project_images'])
		? implode(',', array_filter(array_map('absint', explode(',', (string) $input['project_images']))))
		: '';

	foreach (['project_menu', 'home_section', 'show_badge', 'show_house_badge', 'show_filter', 'show_product_block'] as $flag) {
		$out[$flag] = !empty($input[$flag]) ? 1 : 0;
	}

	return $out;
}

/* -------------------------------------------------------------------------
 * Menú: MJ → Colegas | Colegas · Lista | Colegas · Liquidaciones
 * ---------------------------------------------------------------------- */
/*
 * Quién ve y guarda los ajustes. Es la misma capacidad que el resto del menú
 * (quien administra la tienda): antes el menú pedía manage_woocommerce y la
 * pantalla de ajustes manage_options, así que un shop manager veía el menú y
 * al entrar le decía que no tenía permiso. Se cambia con el filtro.
 */
function mjc_settings_capability() {
	return (string) apply_filters('mjc_settings_capability', 'manage_woocommerce');
}

add_action('admin_menu', function () {
	$plural = mjc_opt('label_plural');
	$cap    = mjc_settings_capability();
	/*
	 * Todo cuelga del menú MJ. La pantalla principal del plugin es la de
	 * ajustes, así que ya no hace falta la entrada "Ajustes" aparte: era la
	 * misma página con otro nombre, y acá adentro quedaría repetida.
	 */
	add_submenu_page(MJ_Menu::slug(), "MJ {$plural}", $plural, $cap, 'mjc', 'mjc_render_settings_page');
	add_submenu_page(MJ_Menu::slug(), "Lista de {$plural}", "{$plural} · Lista", 'manage_woocommerce', 'edit-tags.php?taxonomy=' . MJC_TAX . '&post_type=product');
	add_submenu_page(MJ_Menu::slug(), 'Liquidaciones', "{$plural} · Liquidaciones", 'manage_woocommerce', 'mjc-liquidaciones', 'mjc_render_report_page');
});

/* options.php exige manage_options por defecto para guardar: se alinea con la capacidad de arriba. */
add_filter('option_page_capability_mjc_options_group', 'mjc_settings_capability');

/* Que la pantalla de la taxonomía resalte el menú MJ y no "Productos". */
add_filter('parent_file', function ($parent) {
	global $current_screen;
	if ($current_screen && MJC_TAX === $current_screen->taxonomy) {
		return MJ_Menu::slug();
	}
	return $parent;
});
add_filter('submenu_file', function ($submenu) {
	global $current_screen;
	if ($current_screen && MJC_TAX === $current_screen->taxonomy) {
		return 'edit-tags.php?taxonomy=' . MJC_TAX . '&post_type=product';
	}
	return $submenu;
});

/* Selector de medios en Ajustes y en la ficha de cada colega. */
add_action('admin_enqueue_scripts', function ($hook) {
	$screen = get_current_screen();
	$is_tax = $screen && MJC_TAX === $screen->taxonomy;
	/* El sufijo del hook lo arma WordPress con el padre: se lo preguntamos. */
	if ($hook === get_plugin_page_hookname('mjc', MJ_Menu::slug()) || $is_tax) {
		wp_enqueue_media();
	}
});

/** Selector de imágenes reutilizable (una o varias). */
function mjc_render_media_picker($name, $ids_csv, $button_label, $description, $multiple = false) {
	$ids = array_filter(array_map('absint', explode(',', (string) $ids_csv)));
	if (!$multiple) {
		$ids = array_slice($ids, 0, 1);
	}
	$field = sanitize_key(str_replace(['[', ']'], ['_', ''], $name));
	?>
	<div class="mjc-media" data-field="<?php echo esc_attr($field); ?>" data-multiple="<?php echo $multiple ? '1' : '0'; ?>">
		<div class="mjc-media__preview" style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:8px;">
			<?php foreach ($ids as $id) :
				$thumb = wp_get_attachment_image_url($id, 'medium');
				if (!$thumb) {
					continue;
				}
				?>
				<span class="mjc-media__item" data-id="<?php echo esc_attr($id); ?>" style="position:relative; display:inline-block;">
					<img src="<?php echo esc_url($thumb); ?>" alt="" style="width:80px; height:80px; object-fit:cover; border-radius:6px; display:block; background:#f0f0f1;" />
					<button type="button" class="mjc-media__remove" aria-label="Quitar" style="position:absolute; top:-6px; right:-6px; width:20px; height:20px; border-radius:50%; background:#d63638; color:#fff; border:0; cursor:pointer; line-height:1; padding:0;">&times;</button>
				</span>
			<?php endforeach; ?>
		</div>
		<input type="hidden" class="mjc-media__value" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr(implode(',', $ids)); ?>" />
		<button type="button" class="button mjc-media__add"><?php echo esc_html($button_label); ?></button>
		<?php if ($description) : ?>
			<p class="description"><?php echo esc_html($description); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/** Script del selector de medios (se imprime una vez por pantalla). */
function mjc_media_picker_script() {
	static $printed = false;
	if ($printed) {
		return;
	}
	$printed = true;
	?>
	<script>
	jQuery(function ($) {
		function item(id, url) {
			return $('<span class="mjc-media__item" style="position:relative; display:inline-block;" />')
				.attr('data-id', id)
				.append($('<img alt="" style="width:80px; height:80px; object-fit:cover; border-radius:6px; display:block; background:#f0f0f1;" />').attr('src', url))
				.append($('<button type="button" class="mjc-media__remove" aria-label="Quitar" style="position:absolute; top:-6px; right:-6px; width:20px; height:20px; border-radius:50%; background:#d63638; color:#fff; border:0; cursor:pointer; line-height:1; padding:0;">&times;</button>'));
		}
		$(document).on('click', '.mjc-media__add', function (e) {
			e.preventDefault();
			var box = $(this).closest('.mjc-media');
			var multiple = box.data('multiple') == 1;
			var input = box.find('.mjc-media__value');
			var preview = box.find('.mjc-media__preview');
			var frame = wp.media({
				title: multiple ? 'Elegir imágenes' : 'Elegir imagen',
				button: { text: 'Usar' },
				library: { type: 'image' },
				multiple: multiple
			});
			frame.on('select', function () {
				var list = multiple && input.val() ? input.val().split(',').filter(Boolean) : [];
				if (!multiple) { preview.empty(); }
				frame.state().get('selection').each(function (att) {
					var a = att.toJSON();
					if (list.indexOf(String(a.id)) !== -1) { return; }
					if (!multiple) { list = []; }
					list.push(String(a.id));
					preview.append(item(a.id, (a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url));
				});
				input.val(list.join(','));
			});
			frame.open();
		});
		$(document).on('click', '.mjc-media__remove', function () {
			var it = $(this).closest('.mjc-media__item');
			var box = it.closest('.mjc-media');
			var input = box.find('.mjc-media__value');
			var id = String(it.data('id'));
			input.val((input.val() ? input.val().split(',') : []).filter(function (x) { return x && x !== id; }).join(','));
			it.remove();
		});
	});
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * Página Colegas → Ajustes
 * ---------------------------------------------------------------------- */
function mjc_render_settings_page() {
	if (!current_user_can(mjc_settings_capability())) {
		return;
	}
	$o        = mjc_get_options();
	$plural   = $o['label_plural'];
	$singular = $o['label_singular'];
	$statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
	$selected = mjc_sale_statuses();
	$text     = function ($key, $placeholder = '', $class = 'regular-text') use ($o) {
		printf(
			'<input type="text" name="mjc_options[%1$s]" value="%2$s" class="%3$s" placeholder="%4$s" />',
			esc_attr($key),
			esc_attr($o[$key]),
			esc_attr($class),
			esc_attr($placeholder)
		);
	};
	$check = function ($key, $label) use ($o) {
		printf(
			'<label><input type="checkbox" name="mjc_options[%1$s]" value="1" %2$s /> %3$s</label>',
			esc_attr($key),
			checked((int) $o[$key], 1, false),
			esc_html($label)
		);
	};
	?>
	<div class="wrap">
		<h1>MJ <?php echo esc_html($plural); ?> — Ajustes</h1>
		<p>Cada producto puede llevar quién lo hizo (una <?php echo esc_html(strtolower($singular)); ?>). El cliente compra en la tienda como siempre; vos cobrás y después liquidás a cada <?php echo esc_html(strtolower($singular)); ?> descontando la comisión. Todo lo que ve el visitante se configura acá; lo que dejes vacío usa el valor entre paréntesis o en gris.</p>

		<form method="post" action="options.php">
			<?php settings_fields('mjc_options_group'); ?>

			<h2>Comisión</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mjc_commission_default">Comisión por defecto</label></th>
					<td>
						<input type="number" id="mjc_commission_default" name="mjc_options[commission_default]" value="<?php echo esc_attr($o['commission_default']); ?>" min="0" max="100" step="0.01" class="small-text" /> %
						<p class="description">Porcentaje que se queda la tienda de cada venta de <?php echo esc_html(strtolower($plural)); ?>. Se puede cambiar por <?php echo esc_html(strtolower($singular)); ?> en su ficha. Los productos propios no llevan comisión.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Sobre qué se calcula</th>
					<td>
						<label><input type="radio" name="mjc_options[commission_base]" value="total" <?php checked($o['commission_base'], 'total'); ?> /> Sobre lo que pagó el cliente por el producto (después de cupones y descuentos)</label><br>
						<label><input type="radio" name="mjc_options[commission_base]" value="subtotal" <?php checked($o['commission_base'], 'subtotal'); ?> /> Sobre el precio de lista del producto (antes de cupones)</label>
						<p class="description">El envío nunca entra en el cálculo. La comisión se fija en el momento de la compra: si después cambiás el porcentaje, los pedidos viejos no se tocan.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Estados que cuentan como venta</th>
					<td>
						<fieldset style="display:flex; flex-wrap:wrap; gap:6px 18px; max-width:640px;">
							<?php foreach ($statuses as $key => $label) :
								$slug = preg_replace('/^wc-/', '', $key);
								?>
								<label style="min-width:180px;"><input type="checkbox" name="mjc_options[order_statuses][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $selected, true)); ?> /> <?php echo esc_html($label); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description">Solo los pedidos en estos estados aparecen en Liquidaciones. Lo normal: Procesando y Completado.</p>
					</td>
				</tr>
			</table>

			<h2>Nombres</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Cómo llamar a quien hace los productos</th>
					<td>
						<?php $text('label_singular', 'Colega'); ?>
						<?php $text('label_plural', 'Colegas'); ?>
						<p class="description">Singular y plural. Ejemplos: "Colega / Colegas", "Tejedora / Tejedoras", "Artesano / Artesanos". Se usa en el menú del admin, en la tienda, en los filtros y en las liquidaciones.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Texto "Hecho por"</th>
					<td>
						<?php $text('label_made_by', 'Hecho por'); ?>
						<p class="description">Va delante del nombre en la ficha del producto y en la vista rápida (ej. "Tejido por", "Hecho a mano por").</p>
					</td>
				</tr>
			</table>

			<h2>La tienda misma</h2>
			<p>Los productos sin <?php echo esc_html(strtolower($singular)); ?> asignada son propios. Así se presentan en el proyecto y en el filtro.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Nombre</th>
					<td>
						<?php $text('house_name', get_bloginfo('name')); ?>
						<p class="description">Vacío = el nombre del sitio.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Slug (URL)</th>
					<td>
						<?php $text('house_slug', sanitize_title(get_bloginfo('name'))); ?>
						<p class="description">Su página dentro del proyecto: /<?php echo esc_html(mjc_project_slug()); ?>/<strong><?php echo esc_html(mjc_house_slug()); ?></strong></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjc_house_description">Presentación</label></th>
					<td><textarea id="mjc_house_description" name="mjc_options[house_description]" rows="4" class="large-text"><?php echo esc_textarea($o['house_description']); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row">Foto</th>
					<td><?php mjc_render_media_picker('mjc_options[house_image]', $o['house_image'], 'Elegir foto…', 'Se muestra en su página dentro del proyecto y en la ficha de sus productos (si activás el chip para productos propios).'); ?></td>
				</tr>
				<tr>
					<th scope="row">Instagram</th>
					<td><input type="url" name="mjc_options[house_instagram]" value="<?php echo esc_attr($o['house_instagram']); ?>" class="regular-text" placeholder="https://instagram.com/…" /></td>
				</tr>
			</table>

			<h2>El proyecto</h2>
			<p>La página que reúne a todas las <?php echo esc_html(strtolower($plural)); ?>, con un selector para ver todo, solo lo propio o solo lo de ellas.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Nombre del proyecto</th>
					<td><?php $text('project_name', 'Colegas'); ?></td>
				</tr>
				<tr>
					<th scope="row">Slug (URL)</th>
					<td>
						<?php $text('project_slug', 'colegas'); ?>
						<p class="description">La página vive en /<strong><?php echo esc_html(mjc_project_slug()); ?></strong> y cada <?php echo esc_html(strtolower($singular)); ?> en /<?php echo esc_html(mjc_project_slug()); ?>/su-nombre.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Menú</th>
					<td>
						<?php $check('project_menu', 'Mostrar el proyecto en el menú principal y en el pie de página'); ?><br>
						<?php $text('project_menu_label', mjc_project_name()); ?>
						<p class="description">Texto del enlace. Vacío = nombre del proyecto.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Cabecera</th>
					<td>
						<?php $text('project_eyebrow', 'Proyecto'); ?> <span class="description">antetítulo</span><br>
						<?php $text('project_title', mjc_project_name(), 'large-text'); ?> <span class="description">título (vacío = nombre del proyecto)</span><br>
						<?php $text('project_subtitle', '', 'large-text'); ?> <span class="description">subtítulo</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjc_project_description">Presentación</label></th>
					<td>
						<textarea id="mjc_project_description" name="mjc_options[project_description]" rows="5" class="large-text"><?php echo esc_textarea($o['project_description']); ?></textarea>
						<p class="description">Separá los párrafos con una línea en blanco.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Fotos</th>
					<td><?php mjc_render_media_picker('mjc_options[project_images]', $o['project_images'], 'Elegir fotos…', 'La primera se usa de fondo en la cabecera del proyecto. Vacío = cabecera sobre el fondo de la tienda.', true); ?></td>
				</tr>
				<tr>
					<th scope="row">Textos de la página</th>
					<td>
						<?php $text('project_all_label', 'Todo'); ?> <span class="description">opción "todo" del selector</span><br>
						<?php $text('project_makers_eyebrow', $plural); ?> <span class="description">antetítulo del bloque de <?php echo esc_html(strtolower($plural)); ?></span><br>
						<?php $text('project_makers_title', 'Quiénes lo hacen'); ?> <span class="description">título del bloque de <?php echo esc_html(strtolower($plural)); ?></span><br>
						<?php $text('project_products_eyebrow', 'Productos'); ?> <span class="description">antetítulo del bloque de productos</span><br>
						<?php $text('project_products_title', 'Todo lo que hacen'); ?> <span class="description">título del bloque de productos (cuando se ve todo)</span><br>
						<?php $text('project_shop_link', 'Ver en la tienda'); ?> <span class="description">enlace a la tienda con el filtro aplicado</span><br>
						<?php $text('project_back_label', mjc_project_name()); ?> <span class="description">enlace "volver" en la página de cada <?php echo esc_html(strtolower($singular)); ?></span>
					</td>
				</tr>
			</table>

			<h2>Sección en la portada</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Mostrar</th>
					<td>
						<?php $check('home_section', "Mostrar en la portada una sección con las {$plural} (hasta 4, con enlace al proyecto)"); ?>
						<p class="description">También se puede ocultar desde Apariencia → MJ Appearance → Secciones de la portada.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Textos</th>
					<td>
						<?php $text('home_eyebrow', mjc_project_name()); ?> <span class="description">antetítulo</span><br>
						<?php $text('home_title', 'Hecho por colegas', 'large-text'); ?> <span class="description">título</span><br>
						<?php $text('home_subtitle', '', 'large-text'); ?> <span class="description">subtítulo</span><br>
						<?php $text('home_link', mjc_project_name()); ?> <span class="description">texto del enlace al proyecto</span>
					</td>
				</tr>
			</table>

			<h2>Qué se muestra en la tienda</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Productos</th>
					<td>
						<?php $check('show_badge', "Chip con el nombre sobre la foto en los productos de {$plural}"); ?><br>
						<?php $check('show_house_badge', 'Chip también en los productos propios (con el nombre de la tienda)'); ?><br>
						<?php $check('show_product_block', 'Bloque "' . $o['label_made_by'] . '…" con foto y enlace en la ficha del producto'); ?><br>
						<?php $check('show_filter', 'Filtro "¿quién lo hizo?" en la tienda (todo / la tienda / ' . strtolower($plural) . ' / cada una)'); ?>
					</td>
				</tr>
			</table>

			<?php submit_button('Guardar ajustes'); ?>
		</form>
	</div>
	<?php
	mjc_media_picker_script();
}
