<?php
/**
 * Apariencia → Contenido inicial: carga de una sola vez lo que Jaz Beauty Art
 * necesita para arrancar, TODO COMO BORRADOR:
 *   - las categorías de servicios (Pestañas, Cejas, Combos),
 *   - los servicios, sin precio (con MJ Turnos Online),
 *   - los productos del Beauty Shop, sin precio (con WooCommerce),
 *   - las páginas Inicio, Sobre mí, Preguntas frecuentes, Políticas y Mis
 *     trabajos, armadas con las plantillas del tema.
 *
 * Se puede apretar más de una vez: lo que ya existe (mismo nombre o misma
 * dirección) no se toca ni se duplica. Nada queda público hasta que Jaz lo
 * revise, le cargue precio y foto, y lo publique.
 */

defined('ABSPATH') || exit;

/*
 * Va abajo del menú MJ si hay algún plugin de la familia instalado; si no, en
 * Apariencia, que es donde WordPress espera lo de un tema.
 */
add_action('admin_menu', function () {
	if (class_exists('MJ_Menu')) {
		add_submenu_page(MJ_Menu::slug(), 'Contenido inicial', 'Jaz Beauty · Contenido inicial', 'manage_options', 'mj-jazbeauty-contenido', 'mj_jazbeauty_render_content_page');
		return;
	}
	add_theme_page('Contenido inicial', 'Contenido inicial', 'manage_options', 'mj-jazbeauty-contenido', 'mj_jazbeauty_render_content_page');
});

function mj_jazbeauty_render_content_page() {
	if (!current_user_can('manage_options')) {
		return;
	}
	$done = isset($_GET['cargado']) ? sanitize_text_field(wp_unslash($_GET['cargado'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- solo muestra el resultado.
	?>
	<div class="wrap">
		<h1>Contenido inicial</h1>
		<p>Carga los servicios, los productos y las páginas de arranque de Jaz Beauty Art <strong>como borrador</strong>. Después completás precio, duración, foto y mantenimiento de cada servicio (Turnos → Servicios), precio y stock de cada producto (Productos), revisás los textos de cada página y publicás.</p>
		<p>Se puede usar más de una vez: lo que ya existe no se toca ni se duplica.</p>
		<?php if ('' !== $done) : ?>
			<div class="notice notice-success"><p><?php echo esc_html('Listo: ' . $done . '.'); ?></p></div>
		<?php endif; ?>
		<ul style="list-style:disc; padding-left:20px;">
			<li>Servicios: <?php echo mj_jazbeauty_turnos_active() ? 'sí (MJ Turnos Online está activo)' : 'no: activá MJ Turnos Online primero'; ?>.</li>
			<li>Productos: <?php echo class_exists('WC_Product_Simple') ? 'sí (WooCommerce está activo)' : 'no: activá WooCommerce primero'; ?>.</li>
			<li>Páginas: Inicio, Sobre mí, Preguntas frecuentes, Políticas y Mis trabajos.</li>
		</ul>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="mj_jazbeauty_cargar" />
			<?php wp_nonce_field('mj_jazbeauty_cargar'); ?>
			<?php submit_button('Cargar el contenido inicial'); ?>
		</form>
	</div>
	<?php
}

/** ¿Hay un post de ese tipo con ese título (en cualquier estado menos papelera)? */
function mj_jazbeauty_exists($type, $title) {
	$found = get_posts([
		'post_type'   => $type,
		'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
		'title'       => $title,
		'numberposts' => 1,
		'fields'      => 'ids',
	]);
	return !empty($found);
}

add_action('admin_post_mj_jazbeauty_cargar', function () {
	if (!current_user_can('manage_options')) {
		wp_die('Sin permiso.');
	}
	check_admin_referer('mj_jazbeauty_cargar');
	$counts = ['servicios' => 0, 'productos' => 0, 'páginas' => 0];

	/* Categorías y servicios (MJ Turnos Online). */
	if (taxonomy_exists('mjto_service_cat') && post_type_exists('mjto_service')) {
		$terms = [];
		foreach (mj_jazbeauty_text('categories') as [$name, $order]) {
			$term = term_exists($name, 'mjto_service_cat');
			if (!$term) {
				$term = wp_insert_term($name, 'mjto_service_cat');
				if (!is_wp_error($term)) {
					update_term_meta((int) $term['term_id'], '_mjto_order', (int) $order);
				}
			}
			if (is_array($term)) {
				$terms[$name] = (int) $term['term_id'];
			}
		}
		foreach (mj_jazbeauty_text('services') as $i => [$name, $category, $excerpt]) {
			if (mj_jazbeauty_exists('mjto_service', $name)) {
				continue;
			}
			$id = wp_insert_post([
				'post_type'    => 'mjto_service',
				'post_status'  => 'draft',
				'post_title'   => $name,
				'post_excerpt' => $excerpt,
				'post_content' => $excerpt,
				'menu_order'   => $i + 1,
			]);
			if (!$id || is_wp_error($id)) {
				continue;
			}
			update_post_meta($id, '_mjto_active', '1');
			update_post_meta($id, '_mjto_bookable', '1');
			update_post_meta($id, '_mjto_price_visible', '1');
			if (isset($terms[$category])) {
				wp_set_object_terms($id, [$terms[$category]], 'mjto_service_cat');
			}
			$counts['servicios']++;
		}
	}

	/* Productos del Beauty Shop (WooCommerce). */
	if (class_exists('WC_Product_Simple')) {
		foreach (mj_jazbeauty_text('products') as [$name, $short]) {
			if (mj_jazbeauty_exists('product', $name)) {
				continue;
			}
			$product = new WC_Product_Simple();
			$product->set_name($name);
			$product->set_status('draft');
			$product->set_short_description($short);
			$product->set_manage_stock(true);
			$product->set_stock_quantity(0);
			$product->save();
			$counts['productos']++;
		}
	}

	/* Páginas con las plantillas del tema. */
	$patterns = mj_jazbeauty_patterns(mj_jazbeauty_booking_path(), mj_jazbeauty_shop_path());
	$pages    = [
		['Inicio', 'inicio', 'jaz-portada'],
		['Sobre mí', 'nosotros', 'jaz-sobre-mi'],
		['Preguntas frecuentes', 'faq', 'jaz-faq'],
		['Políticas', 'politicas', 'jaz-politicas'],
		['Mis trabajos', 'mis-trabajos', 'jaz-trabajos'],
	];
	foreach ($pages as [$title, $slug, $pattern]) {
		if (get_page_by_path($slug) || mj_jazbeauty_exists('page', $title)) {
			continue;
		}
		$id = wp_insert_post([
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => (string) ($patterns[$pattern]['content'] ?? ''),
		]);
		if ($id && !is_wp_error($id)) {
			$counts['páginas']++;
		}
	}

	$summary = [];
	foreach ($counts as $what => $n) {
		$summary[] = $n . ' ' . $what;
	}
	wp_safe_redirect(add_query_arg('cargado', rawurlencode(implode(', ', $summary) . ' nuevos en borrador'), admin_url('themes.php?page=mj-jazbeauty-contenido')));
	exit;
});
