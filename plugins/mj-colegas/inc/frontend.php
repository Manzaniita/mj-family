<?php
/**
 * Integración con el tema MJ Appearance (SPA React):
 *   - Store API: cada producto/ítem del carrito lleva extensions.mjc {id, slug, name}.
 *   - window.MJ.colegas (filtro mj_app_config): nombres, la tienda misma, el
 *     proyecto, la sección de la portada, qué mostrar y la lista de colegas.
 *   - Rutas de la SPA: /{proyecto} y /{proyecto}/{colega} responden 200.
 *
 * Sin el tema MJ Appearance nada de esto molesta: los filtros no se disparan.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Store API: extensions.mjc en productos e ítems del carrito
 * ---------------------------------------------------------------------- */
function mjc_public_term_data($term) {
	if (!$term) {
		return null;
	}
	return [
		'id'   => (int) $term->term_id,
		'slug' => $term->slug,
		'name' => $term->name,
	];
}

add_action('woocommerce_blocks_loaded', function () {
	if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
		return;
	}
	$schema = [
		'id'   => ['description' => 'ID de la colega', 'type' => ['integer', 'null'], 'readonly' => true],
		'slug' => ['description' => 'Slug de la colega', 'type' => ['string', 'null'], 'readonly' => true],
		'name' => ['description' => 'Nombre de la colega', 'type' => ['string', 'null'], 'readonly' => true],
	];

	woocommerce_store_api_register_endpoint_data([
		'endpoint'        => 'product',
		'namespace'       => 'mjc',
		'schema_callback' => fn () => $schema,
		'schema_type'     => ARRAY_A,
		'data_callback'   => function ($product) {
			$term = mjc_product_colega($product->get_id());
			return $term ? mjc_public_term_data($term) : ['id' => null, 'slug' => null, 'name' => null];
		},
	]);

	woocommerce_store_api_register_endpoint_data([
		'endpoint'        => 'cart_item',
		'namespace'       => 'mjc',
		'schema_callback' => fn () => $schema,
		'schema_type'     => ARRAY_A,
		'data_callback'   => function ($cart_item) {
			$product = isset($cart_item['data']) ? $cart_item['data'] : null;
			$term    = $product ? mjc_product_colega($product->get_id()) : null;
			return $term ? mjc_public_term_data($term) : ['id' => null, 'slug' => null, 'name' => null];
		},
	]);
});

/* -------------------------------------------------------------------------
 * window.MJ.colegas
 * ---------------------------------------------------------------------- */
function mjc_image_url($id) {
	$id = (int) $id;
	if (!$id) {
		return null;
	}
	$url = wp_get_attachment_image_url($id, 'large');
	return $url ? $url : null;
}

function mjc_colega_public($term) {
	return [
		'id'          => (int) $term->term_id,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'description' => (string) $term->description,
		'image'       => mjc_image_url(get_term_meta($term->term_id, 'mjc_image', true)),
		'instagram'   => (string) get_term_meta($term->term_id, 'mjc_instagram', true),
		'website'     => (string) get_term_meta($term->term_id, 'mjc_website', true),
		'count'       => (int) $term->count,
	];
}

add_filter('mj_app_config', function ($config) {
	$o    = mjc_get_options();
	$name = mjc_project_name();

	$images = [];
	foreach (array_filter(array_map('absint', explode(',', (string) $o['project_images']))) as $id) {
		if ($url = mjc_image_url($id)) {
			$images[] = $url;
		}
	}

	$config['colegas'] = [
		'enabled'          => true,
		'labels'           => [
			'singular' => $o['label_singular'],
			'plural'   => $o['label_plural'],
			'madeBy'   => $o['label_made_by'],
		],
		'house'            => [
			'name'        => mjc_house_name(),
			'slug'        => mjc_house_slug(),
			'description' => (string) $o['house_description'],
			'image'       => mjc_image_url($o['house_image']),
			'instagram'   => (string) $o['house_instagram'],
		],
		'project'          => [
			'name'            => $name,
			'slug'            => mjc_project_slug(),
			'eyebrow'         => (string) $o['project_eyebrow'],
			'title'           => '' !== trim((string) $o['project_title']) ? $o['project_title'] : $name,
			'subtitle'        => (string) $o['project_subtitle'],
			'description'     => (string) $o['project_description'],
			'images'          => $images,
			'menuLabel'       => '' !== trim((string) $o['project_menu_label']) ? $o['project_menu_label'] : $name,
			'showInMenu'      => (bool) $o['project_menu'],
			'allLabel'        => (string) $o['project_all_label'],
			'makersEyebrow'   => '' !== trim((string) $o['project_makers_eyebrow']) ? $o['project_makers_eyebrow'] : $o['label_plural'],
			'makersTitle'     => (string) $o['project_makers_title'],
			'productsEyebrow' => (string) $o['project_products_eyebrow'],
			'productsTitle'   => (string) $o['project_products_title'],
			'shopLink'        => (string) $o['project_shop_link'],
			'backLabel'       => '' !== trim((string) $o['project_back_label']) ? $o['project_back_label'] : $name,
		],
		'home'             => [
			'enabled'  => (bool) $o['home_section'],
			'eyebrow'  => '' !== trim((string) $o['home_eyebrow']) ? $o['home_eyebrow'] : $name,
			'title'    => (string) $o['home_title'],
			'subtitle' => (string) $o['home_subtitle'],
			'link'     => '' !== trim((string) $o['home_link']) ? $o['home_link'] : $name,
		],
		'showBadge'        => (bool) $o['show_badge'],
		'showHouseBadge'   => (bool) $o['show_house_badge'],
		'showFilter'       => (bool) $o['show_filter'],
		'showProductBlock' => (bool) $o['show_product_block'],
		'list'             => array_map('mjc_colega_public', mjc_all_colegas()),
	];

	return $config;
});

/* -------------------------------------------------------------------------
 * Rutas de la SPA (status 200 en vez de 404)
 * ---------------------------------------------------------------------- */
add_filter('mj_spa_routes', function ($routes) {
	$routes[] = mjc_project_slug();
	return $routes;
});

add_filter('mj_spa_handles_path', function ($handles, $path, $parts) {
	if ($handles || 2 !== count($parts) || mjc_project_slug() !== $parts[0]) {
		return $handles;
	}
	if (mjc_house_slug() === $parts[1]) {
		return true;
	}
	return (bool) get_term_by('slug', $parts[1], MJC_TAX);
}, 10, 3);
