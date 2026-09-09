<?php
/**
 * Taxonomía "colega" (mj_colega) en productos.
 *
 * Cada producto tiene como máximo UNA colega (radio en la ficha del
 * producto). Sin colega = producto propio de la tienda. Cada colega tiene
 * ficha propia: comisión particular, foto, Instagram, sitio, y datos
 * privados (contacto, cómo pagarle, notas).
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Registro
 * ---------------------------------------------------------------------- */
add_action('init', function () {
	$singular = mjc_opt('label_singular');
	$plural   = mjc_opt('label_plural');

	register_taxonomy(MJC_TAX, ['product'], [
		'labels' => [
			'name'              => $plural,
			'singular_name'     => $singular,
			'menu_name'         => $plural,
			'all_items'         => "Todas las {$plural}",
			'edit_item'         => "Editar {$singular}",
			'view_item'         => "Ver {$singular}",
			'update_item'       => "Actualizar {$singular}",
			'add_new_item'      => "Agregar {$singular}",
			'new_item_name'     => "Nombre de la {$singular}",
			'search_items'      => "Buscar {$plural}",
			'not_found'         => "No hay {$plural} todavía.",
			'no_terms'          => "Sin {$singular}",
			'back_to_items'     => "← Volver a {$plural}",
			'name_field_description' => 'Nombre público: se ve en la tienda, en el chip del producto y en su página.',
			'slug_field_description' => 'Parte de la URL de su página: /' . mjc_project_slug() . '/slug',
			'desc_field_description' => 'Presentación pública: se muestra en su página del proyecto y en la ficha de sus productos.',
		],
		'public'             => false,
		'publicly_queryable' => false,
		'show_ui'            => true,
		'show_in_menu'       => false,
		'show_in_nav_menus'  => false,
		'show_tagcloud'      => false,
		'show_in_quick_edit' => false,
		'show_admin_column'  => true,
		'hierarchical'       => false,
		'rewrite'            => false,
		'query_var'          => false,
		'show_in_rest'       => true,
		'meta_box_cb'        => 'mjc_product_metabox',
		'capabilities'       => [
			'manage_terms' => 'manage_woocommerce',
			'edit_terms'   => 'manage_woocommerce',
			'delete_terms' => 'manage_woocommerce',
			'assign_terms' => 'edit_products',
		],
	]);
}, 5);

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/* Colega de un producto (o de su padre, en variaciones) → WP_Term|null. */
function mjc_product_colega($product_id) {
	$product_id = (int) $product_id;
	if (!$product_id) {
		return null;
	}
	$post = get_post($product_id);
	if ($post && 'product_variation' === $post->post_type && $post->post_parent) {
		$product_id = (int) $post->post_parent;
	}
	$terms = get_the_terms($product_id, MJC_TAX);
	if (!$terms || is_wp_error($terms)) {
		return null;
	}
	return $terms[0];
}

/* Comisión (%) de una colega: la propia o la de los ajustes. */
function mjc_colega_commission($term_id) {
	$own = get_term_meta((int) $term_id, 'mjc_commission', true);
	if ('' !== $own && null !== $own && is_numeric($own)) {
		return max(0, min(100, (float) $own));
	}
	return (float) mjc_opt('commission_default');
}

/* Lista de colegas (todas, incluidas las que todavía no tienen productos). */
function mjc_all_colegas() {
	$terms = get_terms(['taxonomy' => MJC_TAX, 'hide_empty' => false, 'orderby' => 'name']);
	return is_wp_error($terms) ? [] : $terms;
}

/* Campos de la ficha: key => [etiqueta, tipo, descripción, ¿público?]. */
function mjc_term_fields() {
	return [
		'mjc_commission' => ['Comisión (%)', 'number', 'Vacío = la comisión por defecto de los ajustes (' . mjc_opt('commission_default') . '%).', false],
		'mjc_image'      => ['Foto', 'image', 'Se ve en su página, en la sección de la portada y en la ficha de sus productos.', true],
		'mjc_instagram'  => ['Instagram', 'url', 'Enlace público.', true],
		'mjc_website'    => ['Sitio web', 'url', 'Enlace público (opcional).', true],
		'mjc_email'      => ['Email', 'email', 'Solo para vos.', false],
		'mjc_phone'      => ['Teléfono / WhatsApp', 'text', 'Solo para vos.', false],
		'mjc_payout'     => ['Cómo pagarle', 'textarea', 'CBU, alias, titular… Solo para vos; aparece en Liquidaciones.', false],
		'mjc_notes'      => ['Notas internas', 'textarea', 'Acuerdos, condiciones, lo que quieras recordar. Solo para vos.', false],
	];
}

/* -------------------------------------------------------------------------
 * Ficha de la colega (alta y edición)
 * ---------------------------------------------------------------------- */
function mjc_render_term_field($key, $def, $value, $edit) {
	[$label, $type, $desc] = $def;
	$id = 'mjc_field_' . $key;
	$control = '';
	switch ($type) {
		case 'image':
			ob_start();
			mjc_render_media_picker($key, $value, 'Elegir foto…', '');
			$control = ob_get_clean();
			break;
		case 'textarea':
			$control = sprintf('<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>', esc_attr($id), esc_attr($key), esc_textarea($value));
			break;
		case 'number':
			$control = sprintf('<input type="number" id="%s" name="%s" value="%s" min="0" max="100" step="0.01" class="small-text" />', esc_attr($id), esc_attr($key), esc_attr($value));
			break;
		default:
			$control = sprintf('<input type="%s" id="%s" name="%s" value="%s" class="regular-text" />', esc_attr($type), esc_attr($id), esc_attr($key), esc_attr($value));
	}

	if ($edit) {
		printf(
			'<tr class="form-field"><th scope="row"><label for="%s">%s</label></th><td>%s<p class="description">%s</p></td></tr>',
			esc_attr($id),
			esc_html($label),
			$control, // phpcs:ignore WordPress.Security.EscapeOutput -- armado arriba con escapes.
			esc_html($desc)
		);
	} else {
		printf(
			'<div class="form-field"><label for="%s">%s</label>%s<p>%s</p></div>',
			esc_attr($id),
			esc_html($label),
			$control, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html($desc)
		);
	}
}

add_action(MJC_TAX . '_add_form_fields', function () {
	wp_nonce_field('mjc_term_save', 'mjc_term_nonce');
	foreach (mjc_term_fields() as $key => $def) {
		mjc_render_term_field($key, $def, '', false);
	}
	mjc_media_picker_script();
});

add_action(MJC_TAX . '_edit_form_fields', function ($term) {
	wp_nonce_field('mjc_term_save', 'mjc_term_nonce');
	foreach (mjc_term_fields() as $key => $def) {
		mjc_render_term_field($key, $def, (string) get_term_meta($term->term_id, $key, true), true);
	}
	mjc_media_picker_script();
});

function mjc_save_term_fields($term_id) {
	if (!isset($_POST['mjc_term_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mjc_term_nonce'])), 'mjc_term_save')) {
		return;
	}
	if (!current_user_can('manage_woocommerce')) {
		return;
	}
	foreach (mjc_term_fields() as $key => $def) {
		if (!isset($_POST[$key])) {
			continue;
		}
		$raw = wp_unslash($_POST[$key]);
		switch ($def[1]) {
			case 'number':
				$raw   = str_replace(',', '.', (string) $raw);
				$value = ('' === trim($raw) || !is_numeric($raw)) ? '' : (string) max(0, min(100, round((float) $raw, 2)));
				break;
			case 'image':
				$value = (string) absint(strtok((string) $raw, ','));
				if ('0' === $value) {
					$value = '';
				}
				break;
			case 'url':
				$value = esc_url_raw((string) $raw);
				break;
			case 'email':
				$value = sanitize_email((string) $raw);
				break;
			case 'textarea':
				$value = sanitize_textarea_field((string) $raw);
				break;
			default:
				$value = sanitize_text_field((string) $raw);
		}
		if ('' === $value) {
			delete_term_meta($term_id, $key);
		} else {
			update_term_meta($term_id, $key, $value);
		}
	}
}
add_action('created_' . MJC_TAX, 'mjc_save_term_fields');
add_action('edited_' . MJC_TAX, 'mjc_save_term_fields');

/* Columnas en la lista de colegas: foto, comisión. */
add_filter('manage_edit-' . MJC_TAX . '_columns', function ($columns) {
	$out = [];
	foreach ($columns as $key => $label) {
		if ('name' === $key) {
			$out['mjc_image'] = '';
		}
		$out[$key] = $label;
		if ('description' === $key) {
			$out['mjc_commission'] = 'Comisión';
		}
	}
	if (!isset($out['mjc_commission'])) {
		$out['mjc_commission'] = 'Comisión';
	}
	unset($out['slug']);
	return $out;
});
add_filter('manage_' . MJC_TAX . '_custom_column', function ($content, $column, $term_id) {
	if ('mjc_commission' === $column) {
		$own = get_term_meta($term_id, 'mjc_commission', true);
		return esc_html(mjc_colega_commission($term_id) . '%') . ('' === $own ? ' <span style="color:#787c82;">(por defecto)</span>' : '');
	}
	if ('mjc_image' === $column) {
		$id = (int) get_term_meta($term_id, 'mjc_image', true);
		$url = $id ? wp_get_attachment_image_url($id, 'thumbnail') : '';
		return $url ? '<img src="' . esc_url($url) . '" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;display:block;" />' : '';
	}
	return $content;
}, 10, 3);

/* -------------------------------------------------------------------------
 * Ficha del producto: radio "¿quién lo hizo?" (una sola colega)
 * ---------------------------------------------------------------------- */
function mjc_product_metabox($post) {
	$current = mjc_product_colega($post->ID);
	$current_id = $current ? (int) $current->term_id : 0;
	$colegas = mjc_all_colegas();
	wp_nonce_field('mjc_product_save', 'mjc_product_nonce');
	?>
	<div class="mjc-product-box" style="max-height:220px; overflow:auto;">
		<label style="display:block; padding:4px 0;">
			<input type="radio" name="mjc_colega" value="0" <?php checked($current_id, 0); ?> />
			<strong><?php echo esc_html(mjc_house_name()); ?></strong> <span style="color:#787c82;">(propio, sin comisión)</span>
		</label>
		<?php foreach ($colegas as $term) : ?>
			<label style="display:block; padding:4px 0;">
				<input type="radio" name="mjc_colega" value="<?php echo esc_attr($term->term_id); ?>" <?php checked($current_id, (int) $term->term_id); ?> />
				<?php echo esc_html($term->name); ?> <span style="color:#787c82;">(<?php echo esc_html(mjc_colega_commission($term->term_id)); ?>%)</span>
			</label>
		<?php endforeach; ?>
	</div>
	<p style="margin:8px 0 0;">
		<a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=' . MJC_TAX . '&post_type=product')); ?>" target="_blank">Administrar <?php echo esc_html(strtolower(mjc_opt('label_plural'))); ?> ↗</a>
	</p>
	<?php
}

add_action('save_post_product', function ($post_id, $post) {
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	if (!isset($_POST['mjc_product_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mjc_product_nonce'])), 'mjc_product_save')) {
		return;
	}
	if (!current_user_can('edit_post', $post_id) || !isset($_POST['mjc_colega'])) {
		return;
	}
	$term_id = absint(wp_unslash($_POST['mjc_colega']));
	wp_set_object_terms($post_id, $term_id ? [$term_id] : [], MJC_TAX, false);
}, 10, 2);

/* -------------------------------------------------------------------------
 * Lista de productos: filtro por colega
 * ---------------------------------------------------------------------- */
add_action('restrict_manage_posts', function ($post_type) {
	if ('product' !== $post_type) {
		return;
	}
	$current = isset($_GET['mjc_filter']) ? sanitize_text_field(wp_unslash($_GET['mjc_filter'])) : '';
	?>
	<select name="mjc_filter">
		<option value="">Todas: <?php echo esc_html(mjc_opt('label_plural')); ?> y propios</option>
		<option value="house" <?php selected($current, 'house'); ?>>Solo propios (<?php echo esc_html(mjc_house_name()); ?>)</option>
		<option value="colegas" <?php selected($current, 'colegas'); ?>>Solo de <?php echo esc_html(strtolower(mjc_opt('label_plural'))); ?></option>
		<?php foreach (mjc_all_colegas() as $term) : ?>
			<option value="<?php echo esc_attr($term->slug); ?>" <?php selected($current, $term->slug); ?>><?php echo esc_html($term->name); ?> (<?php echo (int) $term->count; ?>)</option>
		<?php endforeach; ?>
	</select>
	<?php
});

add_action('pre_get_posts', function ($query) {
	if (!is_admin() || !$query->is_main_query() || 'product' !== $query->get('post_type')) {
		return;
	}
	$filter = isset($_GET['mjc_filter']) ? sanitize_text_field(wp_unslash($_GET['mjc_filter'])) : '';
	if ('' === $filter) {
		return;
	}
	$tax_query = (array) $query->get('tax_query');
	if ('house' === $filter) {
		$ids = get_terms(['taxonomy' => MJC_TAX, 'fields' => 'ids', 'hide_empty' => false]);
		if (!is_wp_error($ids) && $ids) {
			$tax_query[] = ['taxonomy' => MJC_TAX, 'field' => 'term_id', 'terms' => $ids, 'operator' => 'NOT IN'];
		}
	} elseif ('colegas' === $filter) {
		$tax_query[] = ['taxonomy' => MJC_TAX, 'operator' => 'EXISTS'];
	} else {
		$tax_query[] = ['taxonomy' => MJC_TAX, 'field' => 'slug', 'terms' => $filter];
	}
	$query->set('tax_query', $tax_query);
});
