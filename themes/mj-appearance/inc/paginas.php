<?php
/**
 * Páginas de WordPress dentro de la tienda.
 *
 * Hasta acá la tienda ignoraba el contenido de WordPress: todas las pantallas
 * eran de la SPA y sus textos salían del panel. Con este módulo, las páginas
 * que se publican en Páginas → Añadir (con el editor de bloques) se muestran
 * en la tienda, dentro de su cabecera y pie, con el diseño del tema y con los
 * bloques MJ (inc/bloques.php) para las secciones vivas: productos,
 * categorías, hero, contacto…
 *
 * Reglas:
 *
 *   - Se sirve toda página PUBLICADA, salvo las que maneja la tienda (carrito,
 *     checkout, mi cuenta, tienda de WooCommerce) y las que tienen la misma
 *     dirección que una pantalla propia de la SPA (/tienda, /cuenta, /login…).
 *     Esas se ignoran y el editor avisa.
 *   - Cinco pantallas del tema SÍ se pueden reemplazar con una página del
 *     mismo slug: contacto, nosotros, faq, privacidad y terminos. Se borra la
 *     página y vuelve la pantalla del tema.
 *   - Si en Ajustes → Lectura se elige una página como portada, la portada de
 *     la tienda se arma con los bloques de esa página en vez de con las
 *     secciones del panel.
 *   - Todo esto se apaga con un solo interruptor (Apariencia → MJ Appearance →
 *     Páginas), y la tienda vuelve a ser exactamente la de antes.
 *
 * La SPA pide cada página a wp-json/mj/v1/page?path=… y monta el HTML; los
 * marcadores de los bloques MJ se hidratan con los componentes de React.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Ajustes
 * ---------------------------------------------------------------------- */

/* ¿Las páginas de WordPress se muestran en la tienda? (interruptor general) */
function mj_pages_enabled() {
	$options = mj_get_options();
	return (bool) apply_filters('mj_pages_enabled', !empty($options['pages_enabled']));
}

/* ¿Una página con el mismo slug reemplaza a la pantalla del tema? */
function mj_pages_override_enabled() {
	$options = mj_get_options();
	return (bool) apply_filters('mj_pages_override_enabled', !empty($options['pages_override']));
}

/* Pantallas del tema que una página puede reemplazar (por slug). */
function mj_pages_overridable() {
	$paths = (array) apply_filters('mj_pages_overridable', ['contacto', 'nosotros', 'faq', 'privacidad', 'terminos']);
	return array_values(array_unique(array_filter(array_map(function ($path) {
		return trim((string) $path, '/');
	}, $paths))));
}

/*
 * Direcciones que una página NO puede ocupar: las rutas de la SPA (menos las
 * reemplazables), las bases de producto/categoría/marca y la raíz de cada una
 * (una página "cuenta/ayuda" también choca con /cuenta).
 */
function mj_pages_reserved_paths() {
	$routes = function_exists('mj_spa_routes') ? mj_spa_routes() : [];
	$bases  = function_exists('mj_seo_bases') ? array_values(mj_seo_bases()) : ['producto', 'categoria', 'marca'];
	$paths  = array_diff(array_merge($routes, $bases), mj_pages_overridable());
	$roots  = [];
	foreach ($paths as $path) {
		$parts   = explode('/', trim((string) $path, '/'));
		$roots[] = $parts[0];
	}
	$all = (array) apply_filters('mj_pages_reserved_paths', array_merge($paths, $roots));
	return array_values(array_unique(array_filter(array_map(function ($path) {
		return trim((string) $path, '/');
	}, $all))));
}

/* ¿Esta dirección choca con una pantalla de la tienda? */
function mj_pages_path_reserved($path) {
	$path     = trim((string) $path, '/');
	$reserved = mj_pages_reserved_paths();
	if ('' === $path || in_array($path, $reserved, true)) {
		return true;
	}
	$parts = explode('/', $path);
	return in_array($parts[0], $reserved, true);
}

/* Páginas que maneja la tienda por su cuenta: las de WooCommerce y las de Lectura. */
function mj_pages_excluded_ids() {
	$ids = [];
	if (function_exists('wc_get_page_id')) {
		foreach (['shop', 'cart', 'checkout', 'myaccount'] as $key) {
			$id = (int) wc_get_page_id($key);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
	}
	if ('page' === get_option('show_on_front')) {
		$ids[] = (int) get_option('page_on_front');
		$ids[] = (int) get_option('page_for_posts');
	}
	$ids = (array) apply_filters('mj_pages_excluded_ids', $ids);
	return array_values(array_unique(array_filter(array_map('absint', $ids))));
}

/* -------------------------------------------------------------------------
 * Qué páginas se muestran
 * ---------------------------------------------------------------------- */

/* Dirección de una página relativa a la home, sin barras ("ayuda/envios"). */
function mj_page_path(WP_Post $post) {
	$uri = get_page_uri($post);
	return is_string($uri) ? trim($uri, '/') : '';
}

/* Título de una página como texto plano (sin entidades). */
function mj_page_title(WP_Post $post) {
	return html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES, 'UTF-8');
}

/* Todas las páginas publicadas que no maneja la tienda (para servir y para avisar). */
function mj_pages_published() {
	static $posts = null;
	if (null !== $posts) {
		return $posts;
	}
	/* Hasta 100 páginas: ninguna tienda se acerca, y la lista viaja entera a la SPA en cada carga. */
	$posts = get_posts([
		'post_type'              => 'page',
		'post_status'            => 'publish',
		'posts_per_page'         => 100,
		'orderby'                => 'menu_order title',
		'order'                  => 'ASC',
		'post__not_in'           => mj_pages_excluded_ids(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
		'has_password'           => false,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	]);
	return is_array($posts) ? $posts : [];
}

/*
 * Páginas que la tienda sirve: path => [id, path, title].
 * Se resuelve una vez por pedido.
 */
function mj_pages_served() {
	static $served = null;
	if (null !== $served) {
		return $served;
	}
	$served = [];
	if (!mj_pages_enabled()) {
		return $served;
	}
	$override    = mj_pages_override_enabled();
	$overridable = mj_pages_overridable();
	foreach (mj_pages_published() as $post) {
		$path = mj_page_path($post);
		if ('' === $path || mj_pages_path_reserved($path)) {
			continue;
		}
		if (!$override && in_array($path, $overridable, true)) {
			continue;
		}
		$served[$path] = ['id' => $post->ID, 'path' => $path, 'title' => mj_page_title($post)];
	}
	return $served;
}

/* Páginas publicadas que NO se muestran, con el motivo (para el panel). */
function mj_pages_ignored() {
	$out = [];
	if (!mj_pages_enabled()) {
		return $out;
	}
	$override    = mj_pages_override_enabled();
	$overridable = mj_pages_overridable();
	foreach (mj_pages_published() as $post) {
		$path = mj_page_path($post);
		if ('' !== $path && mj_pages_path_reserved($path)) {
			$out[] = ['id' => $post->ID, 'path' => $path, 'title' => mj_page_title($post), 'reason' => 'la dirección es una pantalla propia de la tienda'];
		} elseif (!$override && in_array($path, $overridable, true)) {
			$out[] = ['id' => $post->ID, 'path' => $path, 'title' => mj_page_title($post), 'reason' => '"Reemplazar pantallas del tema" está desmarcado'];
		}
	}
	return $out;
}

/* ID de la página elegida como portada (Ajustes → Lectura), o 0. */
function mj_pages_home_id() {
	if (!mj_pages_enabled() || 'page' !== get_option('show_on_front')) {
		return 0;
	}
	$id   = (int) get_option('page_on_front');
	$post = $id > 0 ? get_post($id) : null;
	return $post instanceof WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status ? $id : 0;
}

/* ¿Hay al menos una página (o la portada) que la tienda muestre? */
function mj_pages_active() {
	return mj_pages_enabled() && (mj_pages_home_id() > 0 || count(mj_pages_served()) > 0);
}

/* La página que responde a una dirección ('' = la portada), o null. */
function mj_page_lookup($path) {
	$path = trim((string) $path, '/');
	if ('' === $path) {
		$id = mj_pages_home_id();
		return $id > 0 ? get_post($id) : null;
	}
	$served = mj_pages_served();
	return isset($served[$path]) ? get_post($served[$path]['id']) : null;
}

/* Lo que la SPA necesita saber al arrancar (window.MJ.pages). */
add_filter('mj_app_config', function ($config) {
	$config['pages'] = [
		'enabled'  => mj_pages_enabled(),
		'home'     => mj_pages_home_id() > 0,
		'override' => mj_pages_override_enabled(),
		'routes'   => array_keys(mj_pages_served()),
	];
	return $config;
}, 5);

/* -------------------------------------------------------------------------
 * Render
 * ---------------------------------------------------------------------- */

/*
 * HTML de una página para la SPA.
 *
 * Los bloques comunes (párrafos, títulos, imágenes, columnas…) se agrupan en
 * contenedores `.mj-page__prose`, que la tienda muestra con el ancho de
 * lectura y su tipografía. Los bloques MJ quedan sueltos, a todo el ancho,
 * como marcadores que React reemplaza por sus componentes.
 */
function mj_page_render(WP_Post $page) {
	global $post;
	$previous = $post;
	$post     = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata($post);

	$out   = '';
	$prose = '';
	$flush = function () use (&$out, &$prose) {
		if ('' !== trim($prose)) {
			$out .= '<div class="mj-page__prose">' . $prose . '</div>';
		}
		$prose = '';
	};

	foreach (parse_blocks($page->post_content) as $block) {
		$name = isset($block['blockName']) ? (string) $block['blockName'] : '';
		if ('' === $name) {
			/* Contenido sin bloques (editor clásico o HTML suelto). */
			$html = trim((string) $block['innerHTML']);
			if ('' !== $html) {
				$prose .= wpautop($html);
			}
			continue;
		}
		if (0 === strpos($name, 'mj/')) {
			$flush();
			$out .= render_block($block);
			continue;
		}
		$prose .= render_block($block);
	}
	$flush();
	$out = do_shortcode($out);

	wp_reset_postdata();
	$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

	/**
	 * HTML final de una página tal como lo recibe la tienda.
	 *
	 * @param string  $out
	 * @param WP_Post $page
	 */
	return (string) apply_filters('mj_page_content', $out, $page);
}

/* Nombre del primer bloque con contenido ('' si la página está vacía). */
function mj_page_first_block(WP_Post $page) {
	foreach (parse_blocks($page->post_content) as $block) {
		$name = isset($block['blockName']) ? (string) $block['blockName'] : '';
		if ('' === $name && '' === trim((string) $block['innerHTML'])) {
			continue;
		}
		return $name;
	}
	return '';
}

/*
 * Lo que responde el endpoint. $source es la versión a mostrar (la publicada,
 * o el autoguardado en una vista previa); $page, la página real.
 */
function mj_page_payload(WP_Post $source, WP_Post $page) {
	$thumb   = (int) get_post_thumbnail_id($page->ID);
	$excerpt = '' !== trim((string) $source->post_excerpt) ? $source->post_excerpt : $source->post_content;
	$payload = [
		'id'         => $page->ID,
		'slug'       => $page->post_name,
		'path'       => mj_pages_home_id() === $page->ID ? '' : mj_page_path($page),
		'title'      => mj_page_title($source),
		'hideTitle'  => (bool) get_post_meta($page->ID, 'mj_hide_title', true),
		'firstBlock' => mj_page_first_block($source),
		'content'    => mj_page_render($source),
		'excerpt'    => function_exists('mj_seo_excerpt') ? mj_seo_excerpt($excerpt) : wp_trim_words(wp_strip_all_tags($excerpt), 30),
		'image'      => $thumb ? (string) wp_get_attachment_image_url($thumb, 'large') : '',
		'modified'   => get_post_modified_time('c', true, $page),
	];
	/**
	 * Datos de una página tal como los recibe la tienda.
	 *
	 * @param array   $payload
	 * @param WP_Post $page
	 */
	return (array) apply_filters('mj_page_payload', $payload, $page);
}

/* -------------------------------------------------------------------------
 * REST: wp-json/mj/v1/page
 * ---------------------------------------------------------------------- */
add_action('rest_api_init', function () {
	register_rest_route('mj/v1', '/page', [
		'methods'             => 'GET',
		'callback'            => 'mj_rest_page',
		'permission_callback' => '__return_true',
		'args'                => [
			'path'    => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
			'id'      => ['type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
			'preview' => ['type' => 'boolean', 'default' => false],
		],
	]);
});

/*
 * Público: cualquiera lee una página publicada que la tienda sirve. Con
 * `id` (borradores, vista previa) hace falta poder editar esa página; con
 * `preview` se devuelve el último autoguardado, que es lo que Gutenberg
 * muestra al apretar "Vista previa".
 */
function mj_rest_page(WP_REST_Request $request) {
	if (!mj_pages_enabled()) {
		return new WP_Error('mj_pages_off', 'Las páginas de WordPress no se muestran en la tienda.', ['status' => 404]);
	}
	$id      = (int) $request['id'];
	$path    = trim((string) $request['path'], '/');
	$preview = rest_sanitize_boolean($request['preview']);
	$page    = null;

	if ($id > 0) {
		$candidate = get_post($id);
		if ($candidate instanceof WP_Post && 'page' === $candidate->post_type) {
			$served = mj_pages_served();
			$public = 'publish' === $candidate->post_status
				&& (mj_pages_home_id() === $id || isset($served[mj_page_path($candidate)]));
			if ($public || current_user_can('edit_post', $id)) {
				$page = $candidate;
			}
		}
	} else {
		$page = mj_page_lookup($path);
	}

	if (!$page instanceof WP_Post) {
		return new WP_Error('mj_page_not_found', 'Esa página no existe o no se muestra en la tienda.', ['status' => 404]);
	}

	$source = $page;
	if ($preview && current_user_can('edit_post', $page->ID)) {
		$autosave = wp_get_post_autosave($page->ID, get_current_user_id());
		if ($autosave instanceof WP_Post && strtotime($autosave->post_modified_gmt) >= strtotime($page->post_modified_gmt)) {
			$source = $autosave;
		}
	}

	return rest_ensure_response(mj_page_payload($source, $page));
}

/* -------------------------------------------------------------------------
 * Meta de la página: ocultar el título
 * ---------------------------------------------------------------------- */
add_action('init', function () {
	register_post_meta('page', 'mj_hide_title', [
		'type'              => 'boolean',
		'single'            => true,
		'default'           => false,
		'show_in_rest'      => true,
		'sanitize_callback' => 'rest_sanitize_boolean',
		'auth_callback'     => function () {
			return current_user_can('edit_pages');
		},
	]);
});

/* -------------------------------------------------------------------------
 * WordPress y las direcciones de las páginas
 * ---------------------------------------------------------------------- */

/*
 * WordPress quiere redirigir /contacto a /contacto/ (o al revés, según los
 * enlaces permanentes). La tienda navega sin barra final y no hace falta
 * ninguna redirección: se deja pasar cuando la única diferencia es esa.
 */
add_filter('redirect_canonical', function ($redirect_url, $requested_url) {
	if (is_page() && is_string($redirect_url) && untrailingslashit($redirect_url) === untrailingslashit((string) $requested_url)) {
		return false;
	}
	return $redirect_url;
}, 10, 2);

/*
 * Una página publicada que la tienda NO sirve (dirección reservada, páginas
 * apagadas) no puede responder 200 con un "no encontrado" adentro: se marca
 * como 404 real. Las rutas de la SPA (carrito, cuenta…) siguen en 200 aunque
 * WordPress las resuelva como página de WooCommerce.
 */
add_action('template_redirect', function () {
	if (is_admin() || wp_doing_ajax() || !is_page() || is_front_page() || is_preview()) {
		return;
	}
	$page = get_queried_object();
	if (!$page instanceof WP_Post) {
		return;
	}
	$served = mj_pages_served();
	if (isset($served[mj_page_path($page)])) {
		return;
	}
	if (function_exists('mj_spa_handles_path') && function_exists('mj_request_path') && mj_spa_handles_path(mj_request_path())) {
		return;
	}
	global $wp_query;
	$wp_query->set_404();
	status_header(404);
	nocache_headers();
}, 15);

/* Las páginas que se muestran van al sitemap (sin plugin de SEO). */
function mj_pages_sitemap_ids() {
	$ids = array_map(function ($page) {
		return (int) $page['id'];
	}, array_values(mj_pages_served()));
	if (mj_pages_home_id() > 0) {
		$ids[] = mj_pages_home_id();
	}
	return $ids ? array_values(array_unique($ids)) : [0];
}

/* -------------------------------------------------------------------------
 * Menú: cada página es una fuente más de la barra de navegación
 * ---------------------------------------------------------------------- */
add_filter('mj_nav_sources', function ($sources) {
	$overridable = mj_pages_overridable();
	foreach (mj_pages_served() as $path => $page) {
		/* Las que reemplazan una pantalla del tema ya tienen su ítem (Contacto, Nosotros…). */
		if (in_array($path, $overridable, true)) {
			continue;
		}
		$sources['page:' . $page['id']] = [
			'label'   => $page['title'],
			'href'    => '/' . $path,
			'group'   => 'Páginas',
			'default' => false,
			'note'    => 'Página de WordPress.',
		];
	}
	return $sources;
}, 5);

/* -------------------------------------------------------------------------
 * Plantillas de bloques (patrones)
 *
 * Para no arrancar de una página en blanco: la portada tal como está
 * configurada hoy, y una plantilla por pantalla reemplazable. Una skin suma
 * las suyas con la clave 'patterns' de su descripción; un plugin, con el
 * filtro `mj_block_patterns`.
 * ---------------------------------------------------------------------- */

/* Un bloque en el formato que guarda Gutenberg. */
function mj_block_comment($name, array $attrs = []) {
	$json = $attrs ? ' ' . wp_json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
	return '<!-- wp:' . $name . $json . ' /-->' . "\n\n";
}

/* Un bloque común con contenido (párrafo, título). */
function mj_block_html($name, $html, array $attrs = []) {
	$json = $attrs ? ' ' . wp_json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
	return '<!-- wp:' . $name . $json . ' -->' . "\n" . $html . "\n" . '<!-- /wp:' . $name . ' -->' . "\n\n";
}

/* Qué bloque corresponde a cada sección de la portada clásica. */
function mj_home_section_blocks() {
	return (array) apply_filters('mj_home_section_blocks', [
		'banners'      => ['mj/banners', []],
		'featured'     => ['mj/productos', ['source' => 'featured']],
		'categories'   => ['mj/categorias', []],
		'latest'       => ['mj/productos', ['source' => 'new']],
		'colegas'      => ['mj/colegas', []],
		'recommended'  => ['mj/productos', ['source' => 'rating']],
		'brands'       => ['mj/marcas', []],
		'testimonials' => ['mj/opiniones', []],
		'instagram'    => ['mj/instagram', []],
		'services'     => ['mj/servicios', []],
		'booking'      => ['mj/reserva', []],
		'about'        => ['mj/nosotros', []],
	]);
}

/* La portada actual, sección por sección, como bloques. */
function mj_pattern_home() {
	$blocks = function_exists('mj_blocks') ? mj_blocks() : [];
	$map    = mj_home_section_blocks();
	$hidden = function_exists('mj_home_hidden') ? mj_home_hidden() : [];
	$order  = function_exists('mj_home_order') ? mj_home_order() : array_keys($map);
	$out    = mj_block_comment('mj/hero');
	foreach ($order as $key) {
		if (in_array($key, $hidden, true) || !isset($map[$key]) || !is_array($map[$key])) {
			continue;
		}
		list($name, $attrs) = $map[$key];
		if (!isset($blocks[substr((string) $name, 3)])) {
			continue;
		}
		$out .= mj_block_comment($name, (array) $attrs);
	}
	return $out;
}

function mj_block_patterns() {
	$copy = function_exists('mj_copy') ? mj_copy() : [];
	$c    = function ($key, $fallback = '') use ($copy) {
		return isset($copy[$key]) && '' !== trim((string) $copy[$key]) ? (string) $copy[$key] : $fallback;
	};

	$patterns = [
		'portada'  => [
			'title'       => 'Portada de la tienda (como está hoy)',
			'description' => 'El hero y las secciones de la portada en el orden de Apariencia → MJ Appearance. Después movés, sacás o agregás lo que quieras.',
			'content'     => mj_pattern_home(),
			'blockTypes'  => ['core/post-content'],
		],
		'contacto' => [
			'title'       => 'Página de contacto',
			'description' => 'Encabezado, formulario y canales de contacto.',
			'content'     => mj_block_comment('mj/encabezado', [
				'eyebrow'  => $c('contact_eyebrow'),
				'title'    => $c('contact_title'),
				'subtitle' => $c('contact_subtitle'),
			]) . mj_block_comment('mj/contacto'),
		],
		'nosotros' => [
			'title'       => 'Página "Nosotros"',
			'description' => 'Foto, texto, puntos destacados, cifras y botón final.',
			'content'     => mj_block_comment('mj/nosotros', [
				'layout'  => 'full',
				'eyebrow' => $c('about_eyebrow'),
				'title'   => $c('about_title'),
			]),
		],
		'faq'      => [
			'title'       => 'Preguntas frecuentes',
			'description' => 'Encabezado y acordeón de preguntas.',
			'content'     => mj_block_comment('mj/encabezado', [
				'eyebrow'  => 'Ayuda',
				'title'    => $c('seo_title_faq', 'Preguntas frecuentes'),
				'subtitle' => $c('faq_subtitle'),
			]) . mj_block_comment('mj/faq'),
		],
		'galeria'  => [
			'title'       => 'Página de galería',
			'description' => 'Encabezado y galería de trabajos con leyenda y filtro. Elegís las fotos desde la biblioteca de medios.',
			'content'     => mj_block_comment('mj/encabezado', [
				'eyebrow'  => $c('gallery_page_eyebrow', 'Trabajos'),
				'title'    => $c('gallery_page_title', 'Galería'),
				'subtitle' => $c('gallery_page_subtitle'),
			]) . mj_block_comment('mj/galeria', ['filter' => true]),
		],
		'legales'  => [
			'title'       => 'Página legal',
			'description' => 'Encabezado y secciones numeradas para términos, privacidad o devoluciones.',
			'content'     => mj_block_comment('mj/encabezado', ['eyebrow' => 'Legales', 'title' => ''])
				. mj_block_html('core/heading', '<h2 class="wp-block-heading">1. Primera sección</h2>')
				. mj_block_html('core/paragraph', '<p>Escribí acá el texto de la primera sección.</p>')
				. mj_block_html('core/heading', '<h2 class="wp-block-heading">2. Segunda sección</h2>')
				. mj_block_html('core/paragraph', '<p>Y acá el de la segunda.</p>'),
		],
	];

	/* Plantillas propias de la skin ('patterns' en su descripción). */
	$skin = function_exists('mj_active_skin') ? mj_active_skin() : [];
	if (!empty($skin['patterns']) && is_array($skin['patterns'])) {
		foreach ($skin['patterns'] as $slug => $pattern) {
			if (is_array($pattern)) {
				$patterns[$slug] = $pattern;
			}
		}
	}

	/**
	 * Plantillas de bloques que ofrece el tema.
	 *
	 * @param array $patterns slug => [title, description, content, blockTypes]
	 */
	return (array) apply_filters('mj_block_patterns', $patterns);
}

add_action('init', function () {
	if (!function_exists('register_block_pattern')) {
		return;
	}
	register_block_pattern_category('mj', [
		'label' => function_exists('mj_settings_menu_title') ? mj_settings_menu_title() : 'MJ Appearance',
	]);
	foreach (mj_block_patterns() as $slug => $pattern) {
		$slug = sanitize_key((string) $slug);
		if ('' === $slug || !is_array($pattern) || empty($pattern['content'])) {
			continue;
		}
		register_block_pattern('mj/' . $slug, wp_parse_args($pattern, [
			'title'       => $slug,
			'description' => '',
			'categories'  => ['mj'],
			'postTypes'   => ['page'],
		]));
	}
}, 20);

/* -------------------------------------------------------------------------
 * Avisos en el editor de una página
 * ---------------------------------------------------------------------- */
add_action('admin_notices', function () {
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$screen || 'post' !== $screen->base || 'page' !== $screen->post_type || !current_user_can('edit_pages')) {
		return;
	}
	$page = get_post();
	if (!$page instanceof WP_Post) {
		return;
	}
	$settings = sprintf('<a href="%s">%s → Páginas</a>', esc_url(mj_settings_page_url()), esc_html(mj_settings_menu_title()));
	$notice   = function ($type, $html) {
		printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($type), wp_kses_post($html));
	};

	if (!mj_pages_enabled()) {
		$notice('info', 'Las páginas de WordPress no se muestran en la tienda: está desmarcado en ' . $settings . '.');
		return;
	}
	if ('page' === get_option('show_on_front') && (int) get_option('page_on_front') === $page->ID) {
		$notice('info', '<strong>Esta página es la portada de la tienda.</strong> Sus bloques reemplazan a las secciones de la portada del panel. Probá la plantilla "Portada de la tienda" del insertador para arrancar con lo que hay hoy.');
		return;
	}
	if (in_array($page->ID, mj_pages_excluded_ids(), true)) {
		$notice('warning', 'Esta página la maneja la tienda (tienda, carrito, finalizar compra o mi cuenta): lo que escribas acá no se muestra.');
		return;
	}
	$path = mj_page_path($page);
	if ('' !== $path && mj_pages_path_reserved($path)) {
		$notice('warning', sprintf('La dirección <code>/%s</code> es una pantalla propia de la tienda, así que <strong>esta página no se va a mostrar</strong>. Cambiale el enlace permanente (slug).', esc_html($path)));
		return;
	}
	if ('' !== $path && in_array($path, mj_pages_overridable(), true)) {
		$notice(
			'info',
			mj_pages_override_enabled()
				? sprintf('Esta página <strong>reemplaza</strong> a la pantalla <code>/%s</code> del tema. Si la borrás, vuelve la pantalla del tema.', esc_html($path))
				: sprintf('Esta página tiene la dirección de la pantalla <code>/%s</code> del tema, pero "Reemplazar pantallas del tema" está desmarcado en %s: se muestra la del tema.', esc_html($path), $settings)
		);
	}
});

/* -------------------------------------------------------------------------
 * Apariencia → MJ Appearance → Páginas
 * ---------------------------------------------------------------------- */
function mj_render_pages_section($o) {
	$served  = mj_pages_served();
	$ignored = mj_pages_ignored();
	$home_id = mj_pages_home_id();
	$choices = ['auto' => 'Como la tienda (el tema por defecto)', 'light' => 'Claro (día)', 'dark' => 'Oscuro (noche)'];
	?>
	<h2>Páginas (editor de bloques)</h2>
	<p>Las páginas que publiques en <a href="<?php echo esc_url(admin_url('edit.php?post_type=page')); ?>">Páginas</a> se muestran en la tienda, con su cabecera, su pie y su diseño. En el editor tenés los bloques de <?php echo esc_html(mj_settings_menu_title()); ?> (hero, productos, categorías, banners, contacto, preguntas frecuentes…) y plantillas para arrancar.</p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Mostrar las páginas</th>
			<td>
				<label for="mj_pages_enabled">
					<input type="checkbox" id="mj_pages_enabled" name="mj_options[pages_enabled]" value="1" <?php checked(!empty($o['pages_enabled'])); ?> />
					Mostrar en la tienda las páginas publicadas de WordPress.
				</label>
				<p class="description">Desmarcado, la tienda funciona exactamente como antes: solo sus pantallas propias. Las páginas quedan guardadas.</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Reemplazar pantallas del tema</th>
			<td>
				<label for="mj_pages_override">
					<input type="checkbox" id="mj_pages_override" name="mj_options[pages_override]" value="1" <?php checked(!empty($o['pages_override'])); ?> />
					Una página con el mismo slug reemplaza a la pantalla del tema:
					<?php foreach (mj_pages_overridable() as $overridable_path) : ?>
						<code>/<?php echo esc_html($overridable_path); ?></code>
					<?php endforeach; ?>
				</label>
				<p class="description">Así armás tu propio Contacto, Nosotros, FAQ o legales con bloques. Borrá la página (o desmarcá esto) y vuelve la pantalla del tema. El resto de las pantallas (tienda, carrito, cuenta…) no se pueden reemplazar.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="mj_editor_theme">Vista del editor</label></th>
			<td>
				<select id="mj_editor_theme" name="mj_options[editor_theme]">
					<?php foreach ($choices as $value => $label) : ?>
						<option value="<?php echo esc_attr($value); ?>" <?php selected((string) $o['editor_theme'], $value); ?>><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">Con qué tema se pinta el editor de bloques: los colores, las tipografías y el fondo son los de la tienda, en día o en noche.</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Portada</th>
			<td>
				<?php if ($home_id > 0) : ?>
					<p style="margin:0 0 6px;">La portada la arma la página <a href="<?php echo esc_url(get_edit_post_link($home_id)); ?>"><strong><?php echo esc_html(mj_page_title(get_post($home_id))); ?></strong></a> con sus bloques.</p>
					<p class="description">El orden de "Secciones de la portada" no se usa mientras haya una página elegida en <a href="<?php echo esc_url(admin_url('options-reading.php')); ?>">Ajustes → Lectura</a>. Para volver a la portada del panel, elegí "Tus últimas entradas" ahí.</p>
				<?php else : ?>
					<p style="margin:0 0 6px;">La portada se arma con las secciones de arriba.</p>
					<p class="description">Para diseñarla con bloques: creá una página, insertá la plantilla <strong>"Portada de la tienda (como está hoy)"</strong>, publicala y elegila en <a href="<?php echo esc_url(admin_url('options-reading.php')); ?>">Ajustes → Lectura → Una página estática</a>.</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row">Páginas que se muestran</th>
			<td>
				<?php if ($served) : ?>
					<ul style="margin:0;">
						<?php foreach ($served as $path => $page) : ?>
							<li>
								<code>/<?php echo esc_html($path); ?></code>
								<a href="<?php echo esc_url(get_edit_post_link($page['id'])); ?>"><?php echo esc_html($page['title']); ?></a>
								<?php if (in_array($path, mj_pages_overridable(), true)) : ?>
									<em style="color:#787c82;">— reemplaza a la pantalla del tema</em>
								<?php endif; ?>
								· <a href="<?php echo esc_url(home_url('/' . $path)); ?>" target="_blank" rel="noopener">ver</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php elseif (mj_pages_enabled()) : ?>
					<p style="margin:0;">Todavía no hay páginas publicadas para mostrar.</p>
				<?php else : ?>
					<p style="margin:0;"><em>Las páginas están desmarcadas.</em></p>
				<?php endif; ?>
				<?php if ($ignored) : ?>
					<p style="margin:12px 0 4px;"><strong>No se muestran:</strong></p>
					<ul style="margin:0;">
						<?php foreach ($ignored as $page) : ?>
							<li><code>/<?php echo esc_html($page['path']); ?></code> <a href="<?php echo esc_url(get_edit_post_link($page['id'])); ?>"><?php echo esc_html($page['title']); ?></a> <em style="color:#787c82;">— <?php echo esc_html($page['reason']); ?></em></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="description">Cada página aparece también como fuente en "Barra de navegación", apagada: tildala para sumarla al menú. Los bloques MJ conviene ponerlos sueltos (no adentro de grupos o columnas) para que ocupen todo el ancho.</p>
			</td>
		</tr>
	</table>
	<?php
}
