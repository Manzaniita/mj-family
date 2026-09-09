<?php
/**
 * Servicios: CPT mjto_service + metabox "Datos del servicio" + helpers.
 *
 * Cada servicio tiene duración, precio (visible o no), seña propia (0 = usa
 * el ajuste global), descanso propio ('' = global), color, y dos llaves:
 * activo (aparece en la web y en el panel) y reservable online (si no, solo
 * se carga desde el panel). El orden es el de "Atributos → Orden" y después
 * el título. Se administra desde Turnos → Servicios.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Registro del tipo de contenido
 * ---------------------------------------------------------------------- */
add_action('init', function () {
	register_post_type('mjto_service', [
		'labels' => [
			'name'                  => 'Servicios',
			'singular_name'         => 'Servicio',
			'menu_name'             => 'Servicios',
			'name_admin_bar'        => 'Servicio',
			'add_new'               => 'Agregar servicio',
			'add_new_item'          => 'Agregar servicio',
			'new_item'              => 'Nuevo servicio',
			'edit_item'             => 'Editar servicio',
			'view_item'             => 'Ver servicio',
			'all_items'             => 'Todos los servicios',
			'search_items'          => 'Buscar servicios',
			'not_found'             => 'Todavía no cargaste ningún servicio.',
			'not_found_in_trash'    => 'No hay servicios en la papelera.',
			'featured_image'        => 'Foto del servicio',
			'set_featured_image'    => 'Elegir foto',
			'remove_featured_image' => 'Quitar foto',
			'use_featured_image'    => 'Usar como foto',
			'archives'              => 'Servicios',
			'attributes'            => 'Orden',
			'item_published'        => 'Servicio publicado.',
			'item_updated'          => 'Servicio actualizado.',
		],
		'description'         => 'Servicios que se pueden reservar online.',
		'public'              => false,
		'publicly_queryable'  => false,
		'exclude_from_search' => true,
		'show_ui'             => true,
		'show_in_menu'        => false,
		'show_in_nav_menus'   => false,
		'show_in_admin_bar'   => true,
		'show_in_rest'        => false,
		'hierarchical'        => false,
		'supports'            => ['title', 'editor', 'thumbnail', 'excerpt', 'page-attributes'],
		'rewrite'             => false,
		'query_var'           => false,
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
		/*
		 * Solo administradores: los servicios salen en la reserva pública, y
		 * con las capacidades de 'post' cualquier autor/editor (o shop manager)
		 * podría publicar uno entrando por la URL del CPT.
		 *
		 * IMPORTANTE: acá van SOLO las capacidades primitivas (las que
		 * terminan en "_posts"). Las capacidades "meta" —edit_post, read_post
		 * y delete_post, en singular— NO se tocan: con map_meta_cap activo,
		 * WordPress las anota en $post_type_meta_caps usando el valor como
		 * clave, así que mapearlas a 'manage_options' convertía
		 * `manage_options` en una capacidad meta PARA TODO EL SITIO. A partir
		 * de ahí, cualquier current_user_can('manage_options') —el menú de
		 * ajustes del tema, la barra de administración, media pantalla de
		 * WooCommerce— se resolvía como delete_post sin un post concreto y
		 * devolvía false hasta para los administradores.
		 *
		 * Dejándolas afuera, WordPress las deriva solo de las primitivas de
		 * abajo, que es lo que se buscaba: solo administradores.
		 */
		'capabilities'        => [
			'edit_posts'             => 'manage_options',
			'edit_others_posts'      => 'manage_options',
			'publish_posts'          => 'manage_options',
			'read_private_posts'     => 'manage_options',
			'delete_posts'           => 'manage_options',
			'delete_others_posts'    => 'manage_options',
			'delete_private_posts'   => 'manage_options',
			'delete_published_posts' => 'manage_options',
			'edit_private_posts'     => 'manage_options',
			'edit_published_posts'   => 'manage_options',
			'create_posts'           => 'manage_options',
		],
		'menu_icon'           => 'dashicons-calendar-alt',
	]);
}, 5);

/* Que las pantallas del CPT resalten el menú "Turnos". */
add_filter('parent_file', function ($parent) {
	global $current_screen;
	if ($current_screen && 'mjto_service' === $current_screen->post_type) {
		return 'mjto';
	}
	return $parent;
});
add_filter('submenu_file', function ($submenu) {
	global $current_screen;
	if ($current_screen && 'mjto_service' === $current_screen->post_type) {
		return 'edit.php?post_type=mjto_service';
	}
	return $submenu;
});

/* Listado ordenado por "Orden" y después por título (salvo que el usuario elija otra columna). */
add_action('pre_get_posts', function ($query) {
	if (!is_admin() || !$query->is_main_query() || 'mjto_service' !== $query->get('post_type')) {
		return;
	}
	if ('' === (string) $query->get('orderby')) {
		$query->set('orderby', ['menu_order' => 'ASC', 'title' => 'ASC']);
	}
});

/* -------------------------------------------------------------------------
 * Metabox "Datos del servicio"
 * ---------------------------------------------------------------------- */

/** Claves de meta: key => [etiqueta, tipo, descripción]. */
function mjto_service_meta_keys() {
	return [
		'_mjto_duration'      => ['Duración', 'minutes', 'En minutos. Con esto se calculan los horarios libres.'],
		'_mjto_price'         => ['Precio', 'money', 'Precio de referencia. Se puede ocultar en la web.'],
		'_mjto_price_visible' => ['Mostrar el precio en la web', 'bool', ''],
		'_mjto_deposit'       => ['Seña propia', 'money', '0 = usa la seña general de Turnos → Ajustes → Reservas.'],
		'_mjto_buffer'        => ['Descanso después de este servicio', 'minutes_optional', 'En minutos. Vacío = el descanso general de la agenda.'],
		'_mjto_color'         => ['Color', 'color', 'Para identificarlo en el panel y en las tarjetas sin foto.'],
		'_mjto_active'        => ['Activo', 'bool', 'Desactivado no aparece en la web ni se ofrece en el panel (los turnos ya cargados no se tocan).'],
		'_mjto_bookable'      => ['Se puede reservar online', 'bool', 'Desmarcado: solo se puede cargar desde el panel.'],
	];
}

add_action('add_meta_boxes_mjto_service', function () {
	add_meta_box('mjto_service_data', 'Datos del servicio', 'mjto_render_service_metabox', 'mjto_service', 'normal', 'high');
});

/* Selector de color de WordPress en la ficha del servicio. */
add_action('admin_enqueue_scripts', function ($hook) {
	$screen = get_current_screen();
	if (!$screen || 'mjto_service' !== $screen->post_type || !in_array($hook, ['post.php', 'post-new.php'], true)) {
		return;
	}
	wp_enqueue_style('wp-color-picker');
	wp_enqueue_script('wp-color-picker');
	wp_add_inline_script('wp-color-picker', 'jQuery(function($){ $(".mjto-color").wpColorPicker(); });');
});

function mjto_render_service_metabox($post) {
	$is_new   = 'auto-draft' === $post->post_status;
	$defaults = mjto_option_defaults();
	$currency = mjto_currency();
	$get      = function ($key, $default = '') use ($post, $is_new) {
		if ($is_new) {
			return $default;
		}
		$v = get_post_meta($post->ID, $key, true);
		return ('' === $v || null === $v) ? $default : $v;
	};
	$duration      = $get('_mjto_duration', (int) mjto_opt('default_duration_min'));
	$price         = $get('_mjto_price', '');
	$price_visible = (int) $get('_mjto_price_visible', 1);
	$deposit       = $get('_mjto_deposit', '');
	$buffer        = $get('_mjto_buffer', '');
	$color         = $get('_mjto_color', '');
	$active        = (int) $get('_mjto_active', 1);
	$bookable      = (int) $get('_mjto_bookable', 1);
	$global_buffer = (int) mjto_opt('buffer_min');
	$global_dep    = 'percent' === mjto_opt('deposit_type')
		? mjto_opt('deposit_percent') . '% del precio'
		: $currency['symbol'] . ' ' . number_format((float) mjto_opt('deposit_amount'), (int) $currency['decimals'], $currency['decimalSep'], $currency['thousandSep']);
	wp_nonce_field('mjto_service_save', 'mjto_service_nonce');
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="mjto_duration">Duración</label></th>
			<td>
				<input type="number" id="mjto_duration" name="mjto_service[_mjto_duration]" value="<?php echo esc_attr($duration); ?>" min="5" max="1440" step="5" class="small-text" /> minutos
				<p class="description">Con esto se calculan los horarios libres. Vacío = <?php echo (int) $defaults['default_duration_min']; ?> minutos (el valor general de la agenda es <?php echo (int) mjto_opt('default_duration_min'); ?>).</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="mjto_price">Precio</label></th>
			<td>
				<?php echo esc_html($currency['symbol']); ?> <input type="number" id="mjto_price" name="mjto_service[_mjto_price]" value="<?php echo esc_attr($price); ?>" min="0" step="0.01" class="regular-text" style="max-width:160px;" placeholder="0" />
				<br><label style="display:inline-block; margin-top:6px;"><input type="checkbox" name="mjto_service[_mjto_price_visible]" value="1" <?php checked($price_visible, 1); ?> /> Mostrar el precio en la web</label>
				<p class="description">Si lo ocultás, en la web se ve solo la duración. En el panel siempre se ve.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="mjto_deposit">Seña propia</label></th>
			<td>
				<?php echo esc_html($currency['symbol']); ?> <input type="number" id="mjto_deposit" name="mjto_service[_mjto_deposit]" value="<?php echo esc_attr($deposit); ?>" min="0" step="0.01" class="regular-text" style="max-width:160px;" placeholder="0" />
				<p class="description">0 o vacío = usa la seña general (<?php echo esc_html($global_dep); ?>). Solo se pide si el modo de confirmación es "pendiente hasta recibir la seña".</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="mjto_buffer">Descanso después</label></th>
			<td>
				<input type="number" id="mjto_buffer" name="mjto_service[_mjto_buffer]" value="<?php echo esc_attr($buffer); ?>" min="0" max="600" step="5" class="small-text" placeholder="<?php echo esc_attr($global_buffer); ?>" /> minutos
				<p class="description">Tiempo que se reserva después de este servicio antes del siguiente turno. Vacío = el descanso general de la agenda (<?php echo (int) $global_buffer; ?> min).</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="mjto_color">Color</label></th>
			<td>
				<input type="text" id="mjto_color" class="mjto-color" name="mjto_service[_mjto_color]" value="<?php echo esc_attr($color); ?>" data-default-color="" />
				<p class="description">Para identificarlo en el panel y de fondo en la tarjeta si no tiene foto. Vacío = color de acento del sitio.</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Disponibilidad</th>
			<td>
				<label><input type="checkbox" name="mjto_service[_mjto_active]" value="1" <?php checked($active, 1); ?> /> Activo</label><br>
				<label><input type="checkbox" name="mjto_service[_mjto_bookable]" value="1" <?php checked($bookable, 1); ?> /> Se puede reservar online</label>
				<p class="description">Inactivo: no aparece en la web ni se ofrece en el panel (los turnos ya cargados no se tocan). Sin reserva online: solo lo cargás vos desde el panel.</p>
			</td>
		</tr>
	</table>
	<p class="description">La foto se elige en "Foto del servicio" (imagen destacada). El texto largo es la descripción de la página de servicios; el extracto, la frase corta de las tarjetas.</p>
	<?php
}

add_action('save_post_mjto_service', function ($post_id, $post) {
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	if (!isset($_POST['mjto_service_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mjto_service_nonce'])), 'mjto_service_save')) {
		return;
	}
	if (!current_user_can('edit_post', $post_id) || !isset($_POST['mjto_service']) || !is_array($_POST['mjto_service'])) {
		return;
	}
	$in = wp_unslash($_POST['mjto_service']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- se sanitiza campo por campo abajo.
	foreach (mjto_service_meta_keys() as $key => $def) {
		$raw = isset($in[$key]) ? $in[$key] : '';
		switch ($def[1]) {
			case 'minutes':
				$value = max(5, min(1440, (int) $raw));
				if ('' === trim((string) $raw)) {
					$value = (int) mjto_opt('default_duration_min');
				}
				break;
			case 'minutes_optional':
				$value = ('' === trim((string) $raw)) ? '' : (string) max(0, min(600, (int) $raw));
				break;
			case 'money':
				$raw   = str_replace(',', '.', trim((string) $raw));
				$value = ('' === $raw || !is_numeric($raw)) ? '0' : (string) max(0, round((float) $raw, 2));
				break;
			case 'color':
				$value = (string) sanitize_hex_color((string) $raw);
				break;
			case 'bool':
				$value = !empty($raw) ? '1' : '0';
				break;
			default:
				$value = sanitize_text_field((string) $raw);
		}
		update_post_meta($post_id, $key, $value);
	}
}, 10, 2);

/* -------------------------------------------------------------------------
 * Columnas del listado
 * ---------------------------------------------------------------------- */
add_filter('manage_mjto_service_posts_columns', function ($columns) {
	$out = [];
	foreach ($columns as $key => $label) {
		$out[$key] = $label;
		if ('title' === $key) {
			$out['mjto_duration'] = 'Duración';
			$out['mjto_price']    = 'Precio';
			$out['mjto_active']   = 'Activo';
			$out['mjto_order']    = 'Orden';
		}
	}
	return $out;
});

add_action('manage_mjto_service_posts_custom_column', function ($column, $post_id) {
	$service = mjto_service_public($post_id);
	if (!$service) {
		return;
	}
	switch ($column) {
		case 'mjto_duration':
			echo esc_html(mjto_format_minutes($service['duration']));
			break;
		case 'mjto_price':
			echo esc_html(mjto_format_price($service['price']));
			if (!$service['priceVisible']) {
				echo ' <span style="color:#787c82;">(oculto)</span>';
			}
			if ($service['deposit'] > 0) {
				echo '<br><span style="color:#787c82;">seña ' . esc_html(mjto_format_price($service['deposit'])) . '</span>';
			}
			break;
		case 'mjto_active':
			if ($service['active']) {
				echo '<span style="color:#00a32a;">Sí</span>';
			} else {
				echo '<span style="color:#787c82;">No</span>';
			}
			if (!$service['bookable']) {
				echo '<br><span style="color:#787c82;">solo desde el panel</span>';
			}
			break;
		case 'mjto_order':
			echo (int) $service['order'];
			break;
	}
}, 10, 2);

add_filter('manage_edit-mjto_service_sortable_columns', function ($columns) {
	$columns['mjto_order'] = 'menu_order';
	return $columns;
});

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** "2 h 30 min" según las etiquetas configuradas. */
function mjto_format_minutes($minutes) {
	$minutes = max(0, (int) $minutes);
	$h       = intdiv($minutes, 60);
	$m       = $minutes % 60;
	$lh      = (string) mjto_opt('label_hours');
	$lm      = (string) mjto_opt('label_minutes');
	if ($h && $m) {
		return "{$h} {$lh} {$m} {$lm}";
	}
	if ($h) {
		return "{$h} {$lh}";
	}
	return "{$m} {$lm}";
}

/** Precio formateado con la moneda efectiva (Woo o la del plugin). */
function mjto_format_price($amount) {
	$c      = mjto_currency();
	$number = number_format((float) $amount, (int) $c['decimals'], (string) $c['decimalSep'], (string) $c['thousandSep']);
	switch ((string) $c['position']) {
		case 'right':
			return $number . $c['symbol'];
		case 'right_space':
			return $number . ' ' . $c['symbol'];
		case 'left_space':
			return $c['symbol'] . ' ' . $number;
		default:
			return $c['symbol'] . $number;
	}
}

/** post_content → texto plano con párrafos separados por línea en blanco. */
function mjto_plain_paragraphs($html) {
	$text = (string) $html;
	$text = strip_shortcodes($text);
	$text = preg_replace('/<!--.*?-->/s', '', $text);
	$text = preg_replace('#</(p|div|h[1-6]|li|blockquote|figure|tr)>#i', "\n\n", $text);
	$text = preg_replace('#<br\s*/?>#i', "\n", $text);
	$text = wp_strip_all_tags($text);
	$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = str_replace(["\r\n", "\r"], "\n", $text);
	$text = preg_replace("/[ \t]+\n/", "\n", $text);
	$text = preg_replace("/\n{3,}/", "\n\n", $text);
	return trim($text);
}

/** Servicio → shape público (o null si no es un servicio publicado). */
function mjto_service_public($post) {
	$post = get_post($post);
	if (!$post || 'mjto_service' !== $post->post_type || 'publish' !== $post->post_status) {
		return null;
	}
	$meta = function ($key, $default = '') use ($post) {
		$v = get_post_meta($post->ID, $key, true);
		return ('' === $v || null === $v) ? $default : $v;
	};
	$duration = (int) $meta('_mjto_duration', 0);
	if ($duration <= 0) {
		$duration = (int) mjto_opt('default_duration_min');
	}
	$buffer   = $meta('_mjto_buffer', '');
	$thumb_id = (int) get_post_thumbnail_id($post->ID);
	$image    = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : '';
	$color    = sanitize_hex_color((string) $meta('_mjto_color', ''));

	return [
		'id'           => (int) $post->ID,
		'slug'         => (string) $post->post_name,
		'name'         => (string) get_the_title($post),
		'description'  => mjto_plain_paragraphs($post->post_content),
		'excerpt'      => trim(wp_strip_all_tags((string) $post->post_excerpt)),
		'duration'     => $duration,
		'price'        => round((float) $meta('_mjto_price', 0), 2),
		'priceVisible' => (bool) (int) $meta('_mjto_price_visible', 1),
		'deposit'      => round((float) $meta('_mjto_deposit', 0), 2),
		'buffer'       => ('' === $buffer) ? null : (int) $buffer,
		'color'        => $color ? $color : '',
		'image'        => $image ? $image : null,
		'order'        => (int) $post->menu_order,
		'active'       => (bool) (int) $meta('_mjto_active', 1),
		'bookable'     => (bool) (int) $meta('_mjto_bookable', 1),
	];
}

/**
 * Lista de servicios publicados (orden + título).
 * $only_active: solo activos. $only_bookable: además, solo los reservables online.
 */
function mjto_services($only_active = true, $only_bookable = false) {
	$posts = get_posts([
		'post_type'        => 'mjto_service',
		'post_status'      => 'publish',
		'numberposts'      => -1,
		'orderby'          => ['menu_order' => 'ASC', 'title' => 'ASC'],
		'suppress_filters' => false,
		'no_found_rows'    => true,
	]);
	$out = [];
	foreach ($posts as $post) {
		$service = mjto_service_public($post);
		if (!$service) {
			continue;
		}
		if ($only_active && !$service['active']) {
			continue;
		}
		if ($only_bookable && !$service['bookable']) {
			continue;
		}
		$out[] = $service;
	}
	return $out;
}

/** Servicio por id → shape público o null. */
function mjto_get_service($id) {
	$id = (int) $id;
	if ($id <= 0) {
		return null;
	}
	return mjto_service_public($id);
}

/** Servicio por slug (para ?servicio=slug) → shape público o null. */
function mjto_service_by_slug($slug) {
	$slug = sanitize_title((string) $slug);
	if ('' === $slug) {
		return null;
	}
	$posts = get_posts([
		'post_type'   => 'mjto_service',
		'post_status' => 'publish',
		'name'        => $slug,
		'numberposts' => 1,
	]);
	return $posts ? mjto_service_public($posts[0]) : null;
}

/**
 * Seña efectiva de un servicio (shape público, id o WP_Post): la propia si
 * es > 0; si no, la general (monto fijo o porcentaje del precio), redondeada
 * a los decimales de la moneda.
 */
function mjto_service_deposit($service) {
	if (!is_array($service)) {
		$service = mjto_service_public($service);
	}
	$decimals = (int) mjto_currency()['decimals'];
	if (is_array($service) && (float) ($service['deposit'] ?? 0) > 0) {
		return round((float) $service['deposit'], $decimals);
	}
	$price = is_array($service) ? (float) ($service['price'] ?? 0) : 0.0;
	if ('percent' === mjto_opt('deposit_type')) {
		$pct = max(0, min(100, (float) mjto_opt('deposit_percent')));
		return round($price * $pct / 100, $decimals);
	}
	return round(max(0, (float) mjto_opt('deposit_amount')), $decimals);
}
