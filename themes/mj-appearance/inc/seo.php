<?php
/**
 * SEO de la tienda: lo que Google y WhatsApp ven antes de que corra React.
 *
 * La SPA se sirve siempre con el mismo index.php, así que si nadie lo hace,
 * todas las URLs se presentan igual: mismo título, sin descripción, sin foto
 * al compartir. Este archivo resuelve tres cosas:
 *
 *   1. Que /producto/{slug} y /categoria/{slug} sean para WordPress un
 *      producto y una categoría de verdad (rewrite rules + permalinks). Con
 *      eso el sitemap, el canonical y cualquier plugin de SEO trabajan sobre
 *      las URLs que la tienda usa, y no sobre las de WooCommerce.
 *   2. Emitir <title>, description, canonical, robots, Open Graph, Twitter
 *      y JSON-LD para cada ruta cuando NO hay un plugin de SEO. Producto con
 *      precio y disponibilidad; portada con WebSite + Organization.
 *   3. Si hay un plugin de SEO (Rank Math o Yoast), no duplicar nada: el
 *      plugin manda en productos, categorías y portada, y el tema le pasa
 *      título, descripción, canonical y robots de las rutas que solo existen
 *      en React (/contacto, /nosotros, /marca/…), que el plugin no conoce.
 *
 * Qué se indexa y qué no: las rutas privadas o transaccionales (carrito,
 * checkout, cuenta, login, buscador) van con noindex. Lista en
 * mj_seo_noindex_routes(), filtrable.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * 1. Rutas reales
 * ---------------------------------------------------------------------- */

/* Bases de URL de la tienda (espejo de react-app/src/App.jsx). */
function mj_seo_bases() {
	return apply_filters('mj_seo_bases', [
		'product'  => 'producto',
		'category' => 'categoria',
		'brand'    => 'marca',
	]);
}

/*
 * /producto/{slug} → el producto; /categoria/{slug} → la categoría. Se suman
 * a las reglas de WooCommerce (si su base ya es "producto", la regla se
 * repite y no molesta). Las marcas no: son un atributo de producto, que no
 * siempre tiene query var pública, así que se resuelven a mano más abajo.
 */
add_action('init', function () {
	$bases = mj_seo_bases();
	add_rewrite_rule('^' . $bases['product'] . '/([^/]+)/?$', 'index.php?post_type=product&name=$matches[1]', 'top');
	add_rewrite_rule('^' . $bases['category'] . '/([^/]+)/?$', 'index.php?product_cat=$matches[1]', 'top');

	/* Las reglas se guardan en la base: refrescar una vez por versión del tema. */
	if (get_option('mj_rewrite_version') !== MJ_VERSION) {
		flush_rewrite_rules(false);
		update_option('mj_rewrite_version', MJ_VERSION);
	}
}, 20);

add_action('after_switch_theme', function () {
	delete_option('mj_rewrite_version');
});

/* Los enlaces que genera WordPress (sitemap, canonical, plugins) usan las rutas de la tienda. */
add_filter('post_type_link', function ($link, $post) {
	if ($post instanceof WP_Post && 'product' === $post->post_type && 'publish' === $post->post_status) {
		return home_url('/' . mj_seo_bases()['product'] . '/' . $post->post_name);
	}
	return $link;
}, 20, 2);

add_filter('term_link', function ($link, $term, $taxonomy) {
	$bases = mj_seo_bases();
	if ('product_cat' === $taxonomy) {
		return home_url('/' . $bases['category'] . '/' . $term->slug);
	}
	$brand = mj_brand_taxonomy();
	if ('' !== $brand && $brand === $taxonomy) {
		return home_url('/' . $bases['brand'] . '/' . $term->slug);
	}
	return $link;
}, 20, 3);

/* -------------------------------------------------------------------------
 * 2. Qué es esta URL
 * ---------------------------------------------------------------------- */

/* Rutas que no tienen que aparecer en Google (privadas o transaccionales). */
function mj_seo_noindex_routes() {
	return apply_filters('mj_seo_noindex_routes', [
		'buscar', 'carrito', 'checkout', 'cuenta', 'mi-cuenta', 'login', 'registro', 'recuperar',
		'favoritos', 'wishlist', 'comparar', 'vistos-recientemente', 'mapa-del-sitio',
	]);
}

/* Título y descripción de las rutas fijas: copy keys (Textos de la tienda → SEO). */
function mj_seo_static_routes() {
	return apply_filters('mj_seo_static_routes', [
		'tienda'               => ['seo_title_shop', 'seo_desc_shop'],
		'marcas'               => ['seo_title_brands', 'seo_desc_brands'],
		'contacto'             => ['seo_title_contact', 'seo_desc_contact'],
		'nosotros'             => ['seo_title_about', 'seo_desc_about'],
		'faq'                  => ['seo_title_faq', 'seo_desc_faq'],
		'terminos'             => ['seo_title_terms', ''],
		'privacidad'           => ['seo_title_privacy', ''],
		'mapa-del-sitio'       => ['seo_title_sitemap', ''],
		'buscar'               => ['seo_title_search', ''],
		'carrito'              => ['seo_title_cart', ''],
		'checkout'             => ['seo_title_checkout', ''],
		'cuenta'               => ['seo_title_account', ''],
		'mi-cuenta'            => ['seo_title_account', ''],
		'login'                => ['seo_title_login', ''],
		'registro'             => ['seo_title_register', ''],
		'recuperar'            => ['seo_title_recover', ''],
		'favoritos'            => ['seo_title_wishlist', ''],
		'wishlist'             => ['seo_title_wishlist', ''],
		'comparar'             => ['seo_title_compare', ''],
		'vistos-recientemente' => ['seo_title_recent', ''],
	]);
}

/* Texto plano, sin etiquetas ni saltos, recortado para una descripción. */
function mj_seo_excerpt($html, $max = 160) {
	$text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(html_entity_decode((string) $html, ENT_QUOTES, 'UTF-8'))));
	if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
		$cut  = mb_substr($text, 0, $max - 1);
		$last = mb_strrpos($cut, ' ');
		$text = ($last ? mb_substr($cut, 0, $last) : $cut) . '…';
	} elseif (strlen($text) > $max) {
		$text = substr($text, 0, $max - 1) . '…';
	}
	return $text;
}

/* Foto para compartir: [url, ancho, alto] de un adjunto, o null. */
function mj_seo_image($attachment_id, $size = 'large') {
	$img = $attachment_id ? wp_get_attachment_image_src((int) $attachment_id, $size) : false;
	return $img ? ['url' => $img[0], 'width' => (int) $img[1], 'height' => (int) $img[2]] : null;
}

/* Foto por defecto para compartir (Apariencia → MJ Appearance → SEO); si no, el logo. */
function mj_seo_default_image() {
	$options = mj_get_options();
	$img     = mj_seo_image(absint($options['seo_share_image']), 'large');
	if ($img) {
		return $img;
	}
	$logo_id = absint($options['logo_id']) > 0 ? absint($options['logo_id']) : (int) get_theme_mod('custom_logo');
	return mj_seo_image($logo_id, 'large');
}

/* Descripción por defecto del sitio: ajuste SEO, o la descripción corta de WordPress. */
function mj_seo_site_description() {
	$options = mj_get_options();
	$custom  = trim((string) $options['seo_description']);
	return '' !== $custom ? $custom : (string) get_bloginfo('description');
}

/*
 * El contexto SEO del pedido actual, resuelto una sola vez:
 *
 *   type        home | product | category | brand | static | plugin | notfound
 *   title       título de la página (sin el nombre del sitio)
 *   description texto para Google y para la tarjeta al compartir
 *   url         canonical
 *   image       ['url','width','height'] o null
 *   noindex     bool
 *   product     WC_Product cuando type = product
 *   term        WP_Term cuando type = category | brand
 *   breadcrumbs [[nombre, url], …]
 */
function mj_seo_context() {
	static $ctx = null;
	if (null !== $ctx) {
		return $ctx;
	}

	$path  = mj_request_path();
	$parts = '' === $path ? [] : explode('/', $path);
	$bases = mj_seo_bases();
	$copy  = mj_copy();
	$site  = (string) get_bloginfo('name');
	$home  = home_url('/');

	$ctx = [
		'type'        => 'plugin',
		'path'        => $path,
		'title'       => '',
		'description' => mj_seo_site_description(),
		'url'         => home_url('/' . $path),
		'image'       => mj_seo_default_image(),
		'noindex'     => false,
		'product'     => null,
		'term'        => null,
		'breadcrumbs' => [[$site, $home]],
	];

	if ('' === $path) {
		$ctx['type']  = 'home';
		$ctx['url']   = $home;
		$ctx['title'] = $site;
		$ctx = apply_filters('mj_seo_context', $ctx, $path, $parts);
		return $ctx;
	}

	/* Producto. */
	if (2 === count($parts) && $bases['product'] === $parts[0] && function_exists('wc_get_product')) {
		$post = get_page_by_path($parts[1], OBJECT, 'product');
		$prod = $post instanceof WP_Post && 'publish' === $post->post_status ? wc_get_product($post) : null;
		if ($prod) {
			$img  = mj_seo_image($prod->get_image_id(), 'large');
			$img  = $img ? $img : $ctx['image'];
			$desc = mj_seo_excerpt('' !== trim((string) $prod->get_short_description()) ? $prod->get_short_description() : $prod->get_description());
			$cats = wp_get_post_terms($prod->get_id(), 'product_cat');
			$cat  = is_array($cats) && $cats && !is_wp_error($cats) ? $cats[0] : null;
			$ctx  = array_merge($ctx, [
				'type'        => 'product',
				'title'       => $prod->get_name(),
				'description' => '' !== $desc ? $desc : sprintf('%s en %s.', $prod->get_name(), $site),
				'url'         => get_permalink($prod->get_id()),
				'image'       => $img,
				'product'     => $prod,
				'breadcrumbs' => array_merge(
					$ctx['breadcrumbs'],
					$cat ? [[$cat->name, get_term_link($cat)]] : [],
					[[$prod->get_name(), get_permalink($prod->get_id())]]
				),
			]);
			$ctx = apply_filters('mj_seo_context', $ctx, $path, $parts);
		return $ctx;
		}
	}

	/* Categoría o marca. */
	if (2 === count($parts) && in_array($parts[0], [$bases['category'], $bases['brand']], true)) {
		$is_cat   = $bases['category'] === $parts[0];
		$taxonomy = $is_cat ? 'product_cat' : mj_brand_taxonomy();
		$term     = '' !== $taxonomy ? get_term_by('slug', $parts[1], $taxonomy) : false;
		if ($term instanceof WP_Term) {
			$thumb = $is_cat ? (int) get_term_meta($term->term_id, 'thumbnail_id', true) : 0;
			$desc  = mj_seo_excerpt($term->description);
			$tpl   = $is_cat ? (string) $copy['seo_desc_category'] : (string) $copy['seo_desc_brand'];
			$ctx   = array_merge($ctx, [
				'type'        => $is_cat ? 'category' : 'brand',
				'title'       => $term->name,
				'description' => '' !== $desc ? $desc : str_replace(['{nombre}', '{sitio}'], [$term->name, $site], $tpl),
				'url'         => get_term_link($term),
				'image'       => mj_seo_image($thumb, 'large') ? mj_seo_image($thumb, 'large') : $ctx['image'],
				'term'        => $term,
				'breadcrumbs' => array_merge($ctx['breadcrumbs'], [[$term->name, get_term_link($term)]]),
			]);
			$ctx = apply_filters('mj_seo_context', $ctx, $path, $parts);
		return $ctx;
		}
	}

	/* Rutas fijas de la SPA (la raíz de la ruta decide: /cuenta/pedidos → cuenta). */
	$static = mj_seo_static_routes();
	$root   = $parts[0];
	$key    = isset($static[$path]) ? $path : (isset($static[$root]) ? $root : '');
	if ('' !== $key) {
		list($title_key, $desc_key) = $static[$key];
		$title = '' !== $title_key && !empty($copy[$title_key]) ? (string) $copy[$title_key] : '';
		/* La tienda con ?filtro=ofertas|novedades usa el título de esa vista. */
		if ('tienda' === $key && isset($_GET['filtro'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filtro = sanitize_key(wp_unslash($_GET['filtro'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ('ofertas' === $filtro && !empty($copy['shop_sale_title'])) {
				$title = (string) $copy['shop_sale_title'];
			} elseif ('novedades' === $filtro && !empty($copy['shop_new_title'])) {
				$title = (string) $copy['shop_new_title'];
			}
		}
		if ('tienda' === $key && '' === $title) {
			$title = (string) $copy['shop_title'];
		}
		if ('marcas' === $key && '' === $title && function_exists('mj_brand_labels')) {
			$labels = mj_brand_labels();
			$title  = isset($labels['plural']) ? (string) $labels['plural'] : 'Marcas';
		}
		$ctx = array_merge($ctx, [
			'type'        => 'static',
			'title'       => $title,
			'description' => '' !== $desc_key && !empty($copy[$desc_key]) ? (string) $copy[$desc_key] : $ctx['description'],
			'url'         => home_url('/' . $path),
			'noindex'     => in_array($root, mj_seo_noindex_routes(), true),
			'breadcrumbs' => array_merge($ctx['breadcrumbs'], '' !== $title ? [[$title, home_url('/' . $path)]] : []),
		]);
		$ctx = apply_filters('mj_seo_context', $ctx, $path, $parts);
		return $ctx;
	}

	/* Lo que no existe: título de "no encontrado" y fuera del índice. */
	if (!mj_spa_handles_path($path)) {
		$ctx['type']    = 'notfound';
		$ctx['title']   = (string) $copy['seo_title_notfound'];
		$ctx['noindex'] = true;
	}

	/* Rutas de plugins (colegas, turnos): el plugin completa con el filtro. */
	$ctx = apply_filters('mj_seo_context', $ctx, $path, $parts);
		return $ctx;
}

/* Título completo de la pestaña: "Página — Sitio", y en la portada "Sitio — Descripción". */
function mj_seo_full_title($ctx = null) {
	$ctx  = $ctx ? $ctx : mj_seo_context();
	$site = (string) get_bloginfo('name');
	$sep  = ' ' . mj_seo_separator() . ' ';
	if ('home' === $ctx['type']) {
		$tag = (string) get_bloginfo('description');
		return '' !== $tag ? $site . $sep . $tag : $site;
	}
	return '' !== $ctx['title'] ? $ctx['title'] . $sep . $site : $site;
}

function mj_seo_separator() {
	return (string) apply_filters('mj_seo_separator', '—');
}

/* ¿Qué plugin de SEO está activo? rankmath | yoast | aioseo | seopress | '' */
function mj_seo_plugin() {
	if (defined('RANK_MATH_VERSION')) {
		return 'rankmath';
	}
	if (defined('WPSEO_VERSION')) {
		return 'yoast';
	}
	if (defined('AIOSEO_VERSION')) {
		return 'aioseo';
	}
	if (defined('SEOPRESS_VERSION')) {
		return 'seopress';
	}
	return '';
}

/* ¿Esta ruta la maneja el plugin de SEO por su cuenta? (tiene objeto de WP detrás). */
function mj_seo_plugin_owns($ctx) {
	return '' !== mj_seo_plugin() && in_array($ctx['type'], ['home', 'product', 'category'], true);
}

/* -------------------------------------------------------------------------
 * 3. Salida sin plugin de SEO
 * ---------------------------------------------------------------------- */

add_filter('document_title_separator', 'mj_seo_separator', 20);

add_filter('pre_get_document_title', function ($title) {
	if (is_admin() || is_feed()) {
		return $title;
	}
	$ctx = mj_seo_context();
	return mj_seo_plugin_owns($ctx) ? $title : mj_seo_full_title($ctx);
}, 99);

add_filter('wp_robots', function ($robots) {
	if (is_admin()) {
		return $robots;
	}
	$ctx = mj_seo_context();
	if ($ctx['noindex']) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset($robots['max-image-preview']);
	}
	return $robots;
}, 20);

add_action('wp_head', function () {
	$ctx = mj_seo_context();
	if ('' !== mj_seo_plugin()) {
		/* Con plugin: él imprime los tags; las rutas de React los reciben por los adaptadores de abajo. */
		mj_seo_print_jsonld($ctx);
		return;
	}

	$title = mj_seo_full_title($ctx);
	$desc  = (string) $ctx['description'];
	$image = $ctx['image'];
	$type  = 'product' === $ctx['type'] ? 'product' : 'website';

	echo "\n<!-- MJ Appearance SEO -->\n";
	if ('' !== $desc) {
		echo '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
	}
	echo '<link rel="canonical" href="' . esc_url($ctx['url']) . '" />' . "\n";
	echo '<meta property="og:locale" content="' . esc_attr(get_locale()) . '" />' . "\n";
	echo '<meta property="og:type" content="' . esc_attr($type) . '" />' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '" />' . "\n";
	echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
	if ('' !== $desc) {
		echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
	}
	echo '<meta property="og:url" content="' . esc_url($ctx['url']) . '" />' . "\n";
	if ($image) {
		echo '<meta property="og:image" content="' . esc_url($image['url']) . '" />' . "\n";
		if ($image['width'] && $image['height']) {
			echo '<meta property="og:image:width" content="' . (int) $image['width'] . '" />' . "\n";
			echo '<meta property="og:image:height" content="' . (int) $image['height'] . '" />' . "\n";
		}
	}
	if ('product' === $ctx['type'] && $ctx['product'] instanceof WC_Product) {
		$prod = $ctx['product'];
		echo '<meta property="product:price:amount" content="' . esc_attr(wc_format_decimal($prod->get_price(), 2)) . '" />' . "\n";
		echo '<meta property="product:price:currency" content="' . esc_attr(get_woocommerce_currency()) . '" />' . "\n";
		echo '<meta property="product:availability" content="' . ($prod->is_in_stock() ? 'in stock' : 'out of stock') . '" />' . "\n";
	}
	echo '<meta name="twitter:card" content="' . ($image ? 'summary_large_image' : 'summary') . '" />' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
	if ('' !== $desc) {
		echo '<meta name="twitter:description" content="' . esc_attr($desc) . '" />' . "\n";
	}
	if ($image) {
		echo '<meta name="twitter:image" content="' . esc_url($image['url']) . '" />' . "\n";
	}
	mj_seo_print_jsonld($ctx);
	echo "<!-- /MJ Appearance SEO -->\n";
}, 1);

/*
 * Datos estructurados. Sin plugin: todo. Con Rank Math: nada (trae WebSite,
 * Organization, Product y migas con su módulo de WooCommerce). Con Yoast u
 * otro: solo el Product, que la versión gratis no emite.
 */
function mj_seo_print_jsonld($ctx) {
	$plugin = mj_seo_plugin();
	if ('rankmath' === $plugin) {
		return;
	}
	$graphs = [];
	$site   = (string) get_bloginfo('name');
	$home   = home_url('/');

	if ('' === $plugin) {
		if ('home' === $ctx['type']) {
			$graphs[] = [
				'@type'           => 'WebSite',
				'name'            => $site,
				'url'             => $home,
				'potentialAction' => [
					'@type'       => 'SearchAction',
					'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => home_url('/buscar?q={search_term_string}')],
					'query-input' => 'required name=search_term_string',
				],
			];
			$org = ['@type' => 'Organization', 'name' => $site, 'url' => $home];
			if ($ctx['image']) {
				$org['logo'] = $ctx['image']['url'];
			}
			$options = mj_get_options();
			$social  = [];
			foreach (['social_instagram', 'social_facebook', 'social_twitter', 'social_youtube', 'social_tiktok'] as $key) {
				if (!empty($options[$key])) {
					$social[] = trim((string) $options[$key]);
				}
			}
			if ($social) {
				$org['sameAs'] = $social;
			}
			$graphs[] = $org;
		}
		if (count($ctx['breadcrumbs']) > 1 && !$ctx['noindex']) {
			$items = [];
			foreach ($ctx['breadcrumbs'] as $i => $crumb) {
				$items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb[0], 'item' => $crumb[1]];
			}
			$graphs[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
		}
	}

	if ('product' === $ctx['type'] && $ctx['product'] instanceof WC_Product) {
		$prod  = $ctx['product'];
		$offer = [
			'@type'         => 'Offer',
			'url'           => $ctx['url'],
			'priceCurrency' => get_woocommerce_currency(),
			'price'         => wc_format_decimal($prod->get_price(), 2),
			'availability'  => $prod->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
			'itemCondition' => 'https://schema.org/NewCondition',
		];
		if ($prod->is_type('variable')) {
			$offer = [
				'@type'         => 'AggregateOffer',
				'url'           => $ctx['url'],
				'priceCurrency' => get_woocommerce_currency(),
				'lowPrice'      => wc_format_decimal($prod->get_variation_price('min'), 2),
				'highPrice'     => wc_format_decimal($prod->get_variation_price('max'), 2),
				'availability'  => $prod->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
			];
		}
		$schema = [
			'@type'       => 'Product',
			'name'        => $prod->get_name(),
			'description' => $ctx['description'],
			'url'         => $ctx['url'],
			'offers'      => $offer,
		];
		if ($ctx['image']) {
			$schema['image'] = $ctx['image']['url'];
		}
		if ($prod->get_sku()) {
			$schema['sku'] = $prod->get_sku();
		}
		$brand_tax = mj_brand_taxonomy();
		$brands    = '' !== $brand_tax ? wp_get_post_terms($prod->get_id(), $brand_tax) : [];
		if (is_array($brands) && $brands && !is_wp_error($brands)) {
			$schema['brand'] = ['@type' => 'Brand', 'name' => $brands[0]->name];
		}
		if ($prod->get_review_count() > 0 && (float) $prod->get_average_rating() > 0) {
			$schema['aggregateRating'] = [
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) $prod->get_average_rating(),
				'reviewCount' => (int) $prod->get_review_count(),
			];
		}
		$graphs[] = $schema;
	}

	$graphs = apply_filters('mj_seo_jsonld', $graphs, $ctx);
	if (!$graphs) {
		return;
	}
	echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graphs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}

/* -------------------------------------------------------------------------
 * 4. Adaptadores: Rank Math y Yoast
 *
 * En productos, categorías y portada el plugin ya sabe qué mostrar. En las
 * rutas que solo existen en React no tiene objeto y mostraría algo genérico,
 * así que se le dan los valores del tema por sus propios filtros.
 * ---------------------------------------------------------------------- */

/* Valor del tema para una ruta que el plugin no conoce; null = que decida el plugin. */
function mj_seo_override($what) {
	$ctx = mj_seo_context();
	if (mj_seo_plugin_owns($ctx)) {
		return null;
	}
	switch ($what) {
		case 'title':
			return mj_seo_full_title($ctx);
		case 'description':
			return (string) $ctx['description'];
		case 'canonical':
			return (string) $ctx['url'];
		case 'image':
			return $ctx['image'] ? $ctx['image']['url'] : null;
		case 'noindex':
			return (bool) $ctx['noindex'];
	}
	return null;
}

/* Devuelve un callback que reemplaza el valor del plugin por el del tema. */
function mj_seo_adapter($what) {
	return function ($value) use ($what) {
		$mine = mj_seo_override($what);
		return null === $mine ? $value : $mine;
	};
}

add_action('init', function () {
	$plugin = mj_seo_plugin();
	if ('rankmath' === $plugin) {
		add_filter('rank_math/frontend/title', mj_seo_adapter('title'));
		add_filter('rank_math/frontend/description', mj_seo_adapter('description'));
		add_filter('rank_math/frontend/canonical', mj_seo_adapter('canonical'));
		add_filter('rank_math/frontend/robots', function ($robots) {
			$noindex = mj_seo_override('noindex');
			if (true === $noindex) {
				return ['index' => 'noindex', 'follow' => 'nofollow'];
			}
			if (false === $noindex) {
				/* Ruta de React que WP veía como 404: que el plugin no la marque noindex. */
				$robots['index'] = 'index';
				unset($robots['noindex']);
			}
			return $robots;
		});
		add_filter('rank_math/opengraph/facebook/og_title', mj_seo_adapter('title'));
		add_filter('rank_math/opengraph/facebook/og_description', mj_seo_adapter('description'));
		add_filter('rank_math/opengraph/facebook/og_url', mj_seo_adapter('canonical'));
		add_filter('rank_math/opengraph/twitter/twitter_title', mj_seo_adapter('title'));
		add_filter('rank_math/opengraph/twitter/twitter_description', mj_seo_adapter('description'));
		add_filter('rank_math/opengraph/url', mj_seo_adapter('canonical'));
		return;
	}

	if ('yoast' === $plugin) {
		add_filter('wpseo_title', mj_seo_adapter('title'));
		add_filter('wpseo_metadesc', mj_seo_adapter('description'));
		add_filter('wpseo_canonical', mj_seo_adapter('canonical'));
		add_filter('wpseo_robots', function ($robots) {
			$noindex = mj_seo_override('noindex');
			if (true === $noindex) {
				return 'noindex, nofollow';
			}
			if (false === $noindex) {
				return 'index, follow';
			}
			return $robots;
		});
		add_filter('wpseo_opengraph_title', mj_seo_adapter('title'));
		add_filter('wpseo_opengraph_desc', mj_seo_adapter('description'));
		add_filter('wpseo_opengraph_url', mj_seo_adapter('canonical'));
		add_filter('wpseo_twitter_title', mj_seo_adapter('title'));
		add_filter('wpseo_twitter_description', mj_seo_adapter('description'));
	}
}, 30);

/* -------------------------------------------------------------------------
 * 5. Sitemap de WordPress (sin plugin de SEO; con plugin, manda el suyo)
 * ---------------------------------------------------------------------- */

/* Solo lo que la tienda muestra: productos y páginas; nada de autores ni etiquetas. */
add_filter('wp_sitemaps_post_types', function ($post_types) {
	if ('' !== mj_seo_plugin()) {
		return $post_types;
	}
	return array_intersect_key($post_types, array_flip(['product', 'page', 'post']));
});

add_filter('wp_sitemaps_taxonomies', function ($taxonomies) {
	if ('' !== mj_seo_plugin()) {
		return $taxonomies;
	}
	$keep  = ['product_cat'];
	$brand = mj_brand_taxonomy();
	if ('' !== $brand) {
		$keep[] = $brand;
	}
	return array_intersect_key($taxonomies, array_flip($keep));
});

add_filter('wp_sitemaps_add_provider', function ($provider, $name) {
	return 'users' === $name && '' === mj_seo_plugin() ? false : $provider;
}, 10, 2);

/* Las páginas de WordPress que la SPA no muestra tampoco van al sitemap. */
add_filter('wp_sitemaps_posts_query_args', function ($args, $post_type) {
	if ('page' === $post_type && '' === mj_seo_plugin()) {
		$args['post__in'] = [0];
	}
	return $args;
}, 10, 2);

/* -------------------------------------------------------------------------
 * 6. Errores del navegador → registro del servidor
 *
 * La SPA avisa a wp-json/mj/v1/client-error cuando algo revienta en el
 * navegador de un cliente (ErrorBoundary, window.onerror). Queda en
 * WooCommerce → Estado → Registros (mj-appearance), o en el registro de PHP
 * si no hay WooCommerce. Público a propósito: el error puede pasar antes de
 * tener sesión o nonce. Se limita por IP para que un bucle no llene el disco.
 * ---------------------------------------------------------------------- */
add_action('rest_api_init', function () {
	register_rest_route('mj/v1', '/client-error', [
		'methods'             => 'POST',
		'callback'            => 'mj_rest_client_error',
		'permission_callback' => '__return_true',
	]);
});

function mj_rest_client_error(WP_REST_Request $request) {
	$ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
	$key = 'mj_cerr_' . md5($ip);
	$n   = (int) get_transient($key);
	if ($n >= 10) {
		return new WP_REST_Response(['ok' => false], 429);
	}
	set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);

	$field = function ($name, $max) use ($request) {
		$v = (string) $request->get_param($name);
		return function_exists('mb_substr') ? mb_substr(wp_strip_all_tags($v), 0, $max) : substr(wp_strip_all_tags($v), 0, $max);
	};
	$message = $field('message', 500);
	if ('' === trim($message)) {
		return new WP_REST_Response(['ok' => false], 400);
	}
	$context = [
		'url'       => $field('url', 500),
		'scope'     => $field('scope', 40),
		'stack'     => $field('stack', 4000),
		'component' => $field('componentStack', 2000),
		'agent'     => $field('userAgent', 300),
		'version'   => MJ_VERSION,
	];

	if (class_exists('MJ_Log')) {
		MJ_Log::error('appearance', 'Error en el navegador: ' . $message, $context);
	} elseif (function_exists('wc_get_logger')) {
		wc_get_logger()->error('Error en el navegador: ' . $message . ' ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['source' => 'mj-appearance']);
	} else {
		error_log('[mj-appearance] Error en el navegador: ' . $message . ' ' . wp_json_encode($context)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	return new WP_REST_Response(['ok' => true], 200);
}
