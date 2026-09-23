<?php
/**
 * MJ Appearance 3.5 — frontend React (headless dentro del tema).
 *
 * El frontend completo es una SPA compilada con Vite (react-app/ → dist/).
 * WordPress/WooCommerce quedan como backend: la app consume la Store API
 * (wp-json/wc/store/v1) para catálogo, carrito y checkout, y los endpoints
 * propios (wp-json/mj/v1) para auth, cuenta y contacto.
 *
 * Temas hijo ("skins"): declaran su slug con el filtro `mj_skin`, ajustan
 * defaults vía `option_mj_options`, y encolan su CSS después de 'mj-app'.
 * Ver mj-neon/ y mj-atelier/ como referencia.
 */

defined('ABSPATH') || exit;

define('MJ_VERSION', '3.31.1');

/*
 * Versión de la librería de íconos que usa el panel de ajustes. Se puede fijar
 * otra desde wp-config.php sin tocar el tema.
 */
if (!defined('MJ_ICONS_VERSION')) {
	define('MJ_ICONS_VERSION', '1.43.0');
}

/* -------------------------------------------------------------------------
 * Módulos del tema
 * ---------------------------------------------------------------------- */
require_once get_template_directory() . '/inc/settings.php'; // Opciones + página de ajustes (wp-admin).
require_once get_template_directory() . '/inc/nav.php';      // Barra de navegación configurable.
require_once get_template_directory() . '/inc/session.php';  // Sesión, nonces y barra de administración.
require_once get_template_directory() . '/inc/skins.php';    // API de temas hijo (skins) y sus presets.
require_once get_template_directory() . '/inc/bloques.php';  // Bloques MJ para el editor (Gutenberg).
require_once get_template_directory() . '/inc/paginas.php';  // Páginas de WordPress dentro de la tienda.
require_once get_template_directory() . '/inc/config.php';   // window.MJ (mj_app_config).
require_once get_template_directory() . '/inc/rest.php';     // Endpoints REST mj/v1.
require_once get_template_directory() . '/inc/seo.php';      // Título, meta, Open Graph, JSON-LD, sitemap y errores del navegador.

/* -------------------------------------------------------------------------
 * Migración por el renombre (Yamana Theme → MJ Appearance).
 *
 * Las opciones vivían en la opción `yamana_options`; el tema ahora lee
 * `mj_options`. Si existe la vieja y no la nueva, se copia una sola vez.
 * La opción vieja NO se borra (queda de respaldo).
 * ---------------------------------------------------------------------- */
add_action('after_setup_theme', function () {
	$old = get_option('yamana_options', null);
	if (null !== $old && false === get_option('mj_options')) {
		add_option('mj_options', $old, '', false);
	}
}, 1);

/* -------------------------------------------------------------------------
 * Soportes del tema
 * ---------------------------------------------------------------------- */
add_action('after_setup_theme', function () {
	add_theme_support('title-tag');
	add_theme_support('woocommerce');
	add_theme_support('post-thumbnails');
	/* Logo del Personalizador: fallback del logo elegido en Apariencia → MJ Appearance. */
	add_theme_support('custom-logo', ['flex-width' => true, 'flex-height' => true, 'unlink-homepage-logo' => true]);
});

/* -------------------------------------------------------------------------
 * Skin activa y tema por defecto
 * ---------------------------------------------------------------------- */

/*
 * Slug de la skin (tema hijo) activa. Un hijo la declara con
 * add_filter('mj_skin', fn () => 'atelier'); se expone como <html data-skin>
 * y window.MJ.skin. '' = MJ Appearance base.
 */
function mj_skin() {
	return sanitize_key((string) apply_filters('mj_skin', ''));
}

/* Tema por defecto: 'dark' | 'light' | 'system' (Apariencia → MJ Appearance). */
function mj_theme_default() {
	$theme = mj_get_options()['design_theme'];
	return in_array($theme, ['light', 'system'], true) ? $theme : 'dark';
}

/*
 * Script anti-flash: decide el tema ANTES de que cargue el CSS, con el mismo
 * criterio que la SPA (react-app/src/lib/theme.js):
 *   elección guardada del visitante > ?theme= (preview) > tema por defecto
 *   del admin > si es "system", el dispositivo.
 * index.php lo imprime en <head> antes de wp_head().
 */
function mj_theme_boot_script() {
	$default = wp_json_encode(mj_theme_default());
	echo '<script>(function(){try{var d=document.documentElement,q=new URLSearchParams(location.search).get("theme"),s=localStorage.getItem("mj-theme"),f=q==="light"||q==="dark"||q==="system"?q:' . $default . ',t=s==="light"||s==="dark"?s:f==="system"?(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"):f;d.dataset.theme=t;d.style.colorScheme=t;}catch(e){}})();</script>' . "\n";
}

/* -------------------------------------------------------------------------
 * Diseño personalizable (Apariencia → MJ Appearance → Diseño y marca)
 *
 * Los colores/tipografías elegidos se inyectan como overrides de las CSS
 * variables del design system (globals.css) DESPUÉS de app.css. Solo se
 * emiten los valores efectivamente configurados; vacío = default del tema.
 * ---------------------------------------------------------------------- */

/* "#a1b2c3" → [r, g, b] | null si no es un hex válido. */
function mj_hex_rgb($hex) {
	$hex = ltrim((string) $hex, '#');
	if (3 === strlen($hex)) {
		$hex = preg_replace('/(.)/', '$1$1', $hex);
	}
	if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
		return null;
	}
	return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/* Mezcla hacia blanco (amount > 0) o negro (amount < 0). Devuelve hex. */
function mj_shade(array $rgb, $amount) {
	$out = [];
	foreach ($rgb as $channel) {
		$out[] = (int) round($amount >= 0 ? $channel + (255 - $channel) * $amount : $channel * (1 + $amount));
	}
	return sprintf('#%02x%02x%02x', $out[0], $out[1], $out[2]);
}

/*
 * Versión "de noche" de un color: la misma tonalidad con más luz.
 *
 * Un acento elegido pensando en el fondo claro casi siempre pierde contraste
 * sobre el fondo oscuro. En vez de fijar un segundo color a mano (que dejaría
 * de acompañar al primero en cuanto el admin lo cambie), se recalcula en HSL:
 * se respeta el tono, se garantiza una luz mínima y se refuerza un poco la
 * saturación. Las skins lo usan para su acento en modo noche.
 *
 * $min_lightness    — luz mínima resultante (0–1).
 * $saturation_boost — cuánta saturación sumarle (0–1).
 * Devuelve un hex, o null si $hex no era un color válido.
 */
function mj_lift_color($hex, $min_lightness = 0.62, $saturation_boost = 0.04) {
	$rgb = mj_hex_rgb($hex);
	if (!$rgb) {
		return null;
	}
	list($r, $g, $b) = array_map(fn ($channel) => $channel / 255, $rgb);
	$max = max($r, $g, $b);
	$min = min($r, $g, $b);
	$l   = ($max + $min) / 2;
	$d   = $max - $min;
	$s   = 0.0;
	$h   = 0.0;
	if ($d > 0) {
		$s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
		if ($max === $r) {
			$h = fmod(($g - $b) / $d, 6);
		} elseif ($max === $g) {
			$h = ($b - $r) / $d + 2;
		} else {
			$h = ($r - $g) / $d + 4;
		}
		$h = fmod($h * 60 + 360, 360);
	}
	$l = max($l, max(0.0, min(1.0, (float) $min_lightness)));
	$s = min($s + max(0.0, (float) $saturation_boost), 1.0);

	/* HSL → RGB. */
	$c     = (1 - abs(2 * $l - 1)) * $s;
	$x     = $c * (1 - abs(fmod($h / 60, 2) - 1));
	$m     = $l - $c / 2;
	$sixth = (int) floor($h / 60);
	$table = [[$c, $x, 0], [$x, $c, 0], [0, $c, $x], [0, $x, $c], [$x, 0, $c], [$c, 0, $x]];
	list($r, $g, $b) = $table[min($sixth, 5)];

	return sprintf(
		'#%02x%02x%02x',
		(int) round(($r + $m) * 255),
		(int) round(($g + $m) * 255),
		(int) round(($b + $m) * 255)
	);
}

/*
 * Reemplaza las variables de acento del modo noche por la versión aclarada
 * del acento configurado. Es exactamente lo que necesita cualquier skin
 * oscura, así que vive acá y no repetido en cada tema hijo.
 *
 * $vars — el array ['dark' => [...], 'light' => [...]] del filtro mj_design_vars.
 */
function mj_dark_accent_vars(array $vars, $accent, $soft = 0.14, $glow = 0.30, $min_lightness = 0.62, $saturation_boost = 0.04) {
	$lifted = mj_lift_color($accent, $min_lightness, $saturation_boost);
	if (null === $lifted) {
		return $vars;
	}
	$rgb  = mj_hex_rgb($lifted);
	$base = "{$rgb[0]} {$rgb[1]} {$rgb[2]}";

	$dark = array_values(array_filter(
		isset($vars['dark']) ? (array) $vars['dark'] : [],
		fn ($line) => 0 !== strpos($line, '--mj-accent')
	));
	$dark[] = "--mj-accent: {$lifted}";
	$dark[] = '--mj-accent-soft: rgb(' . $base . ' / ' . $soft . ')';
	$dark[] = '--mj-accent-glow: rgb(' . $base . ' / ' . $glow . ')';
	$vars['dark'] = $dark;

	return $vars;
}

/* Familias serif del selector: definen el fallback correcto de la pila. */
function mj_serif_fonts() {
	return ['Playfair Display', 'Fraunces', 'Instrument Serif', 'Cormorant Garamond', 'DM Serif Display', 'Libre Caslon Text', 'Lora'];
}

/* Pila CSS completa para una familia elegida en el admin. */
function mj_font_stack($family) {
	$fallback = in_array($family, mj_serif_fonts(), true)
		? "Georgia, 'Times New Roman', serif"
		: 'ui-sans-serif, system-ui, sans-serif';
	return "'{$family}', {$fallback}";
}

/* URL de Google Fonts con las familias elegidas (o las default del tema). */
function mj_fonts_url() {
	$options  = mj_get_options();
	$families = array_values(array_unique(array_filter([
		'' !== $options['font_sans'] ? $options['font_sans'] : 'Inter',
		'' !== $options['font_display'] ? $options['font_display'] : 'Space Grotesk',
	])));
	$parts = [];
	foreach ($families as $family) {
		$parts[] = 'family=' . str_replace(' ', '+', $family) . ':wght@400;500;600;700';
	}
	$url = 'https://fonts.googleapis.com/css2?' . implode('&', $parts) . '&display=swap';

	/* Un tema hijo puede pedir ejes extra (itálicas, tamaño óptico) para sus familias. */
	return apply_filters('mj_fonts_url', $url, $families);
}

/*
 * Variables de diseño según los ajustes guardados, separadas por tema:
 * ['fonts' => [...], 'dark' => [...], 'light' => [...]], cada línea como
 * "--mj-accent: #hex". Las usa la tienda (mj_design_css) y el editor de
 * bloques (mj_editor_theme_css), que pinta el lienzo con los mismos valores.
 */
function mj_design_var_sets() {
	$o     = mj_get_options();
	$dark  = [];
	$light = [];

	/*
	 * Los dos escalones del texto secundario, en tanto por uno del color de
	 * texto. Salen de un ajuste y no de un número acá: una paleta de mucho
	 * contraste (blanco sobre negro) aguanta valores bajos, y una delicada
	 * (marfil sobre papel) necesita subirlos o las palabras se camuflan.
	 */
	$soft  = round(max(40, min(100, (int) $o['ink_soft'])) / 100, 2);
	$faint = round(max(30, min(100, (int) $o['ink_faint'])) / 100, 2);

	/*
	 * El color de texto, aunque nadie haya elegido uno: el ajuste de fuerza
	 * tiene que valer también en una tienda que dejó la paleta como vino. Con
	 * un tema hijo activo, el que "vino" ya es el de la skin.
	 */
	$base_colors = mj_color_defaults();
	$ink_dark    = '' !== $o['color_ink_dark'] ? $o['color_ink_dark'] : $base_colors['color_ink_dark'];
	$ink_light   = '' !== $o['color_ink_light'] ? $o['color_ink_light'] : $base_colors['color_ink_light'];

	if ($rgb = mj_hex_rgb($o['color_accent'])) {
		$base    = "{$rgb[0]} {$rgb[1]} {$rgb[2]}";
		$dark[]  = "--mj-accent: {$o['color_accent']}";
		$dark[]  = "--mj-accent-soft: rgb({$base} / 0.14)";
		$dark[]  = "--mj-accent-glow: rgb({$base} / 0.35)";
		$light[] = "--mj-accent: {$o['color_accent']}";
		$light[] = "--mj-accent-soft: rgb({$base} / 0.10)";
		$light[] = "--mj-accent-glow: rgb({$base} / 0.25)";
	}
	if ($rgb = mj_hex_rgb($o['color_bg_dark'])) {
		$dark[] = "--mj-bg: {$o['color_bg_dark']}";
		$dark[] = '--mj-bg-soft: ' . mj_shade($rgb, 0.04);
	}
	if ($rgb = mj_hex_rgb($ink_dark)) {
		$base   = "{$rgb[0]} {$rgb[1]} {$rgb[2]}";
		$dark[] = "--mj-ink: {$ink_dark}";
		$dark[] = "--mj-ink-soft: rgb({$base} / {$soft})";
		$dark[] = "--mj-ink-faint: rgb({$base} / {$faint})";
	}
	if ($rgb = mj_hex_rgb($o['color_bg_light'])) {
		$light[] = "--mj-bg: {$o['color_bg_light']}";
		$light[] = '--mj-bg-soft: ' . mj_shade($rgb, -0.05);
	}
	if ($rgb = mj_hex_rgb($ink_light)) {
		$base    = "{$rgb[0]} {$rgb[1]} {$rgb[2]}";
		$light[] = "--mj-ink: {$ink_light}";
		$light[] = "--mj-ink-soft: rgb({$base} / {$soft})";
		$light[] = "--mj-ink-faint: rgb({$base} / {$faint})";
	}

	/*
	 * Un tema hijo puede retocar las variables por tema antes de emitirlas
	 * (p. ej. aclarar el acento para el modo noche).
	 */
	$vars  = apply_filters('mj_design_vars', ['dark' => $dark, 'light' => $light], $o);
	$dark  = isset($vars['dark']) ? (array) $vars['dark'] : [];
	$light = isset($vars['light']) ? (array) $vars['light'] : [];

	$fonts = [];
	if ('' !== $o['font_display']) {
		$fonts[] = '--font-display: ' . mj_font_stack($o['font_display']);
	}
	if ('' !== $o['font_sans']) {
		$fonts[] = '--font-sans: ' . mj_font_stack($o['font_sans']);
	}

	return ['fonts' => $fonts, 'dark' => $dark, 'light' => $light];
}

/* CSS con los overrides de variables según los ajustes guardados. */
function mj_design_css() {
	$sets  = mj_design_var_sets();
	$fonts = $sets['fonts'];
	$dark  = $sets['dark'];
	$light = $sets['light'];

	// index.php siempre setea data-theme, así que alcanza con los selectores por atributo.
	$css = '';
	if ($fonts) {
		$css .= ':root{' . implode(';', $fonts) . ';}';
	}
	if ($dark) {
		$css .= "[data-theme='dark']{" . implode(';', $dark) . ';}';
	}
	if ($light) {
		$css .= "[data-theme='light']{" . implode(';', $light) . ';}';
	}
	return $css;
}

/* -------------------------------------------------------------------------
 * Assets de la SPA
 * ---------------------------------------------------------------------- */
/*
 * Archivo compilado de la SPA: dist/assets/app-<hash>.js (o .css).
 *
 * El nombre lleva el hash del contenido, así ninguna caché intermedia (proxy
 * del hosting, CDN que ignora el ?ver=) puede servir un archivo viejo después
 * de una actualización. El 18/9/2026 pasó justamente eso: app.js viejo con
 * los chunks nuevos, y la tienda quedó en "Algo salió mal". Si hay más de uno
 * (no debería), gana el más nuevo; el nombre fijo app.js/app.css de builds
 * anteriores se acepta como último recurso.
 *
 * Devuelve ['path' => ruta en disco, 'uri' => URL] o null si no hay build.
 */
function mj_dist_asset($ext) {
	static $found = [];
	if (array_key_exists($ext, $found)) {
		return $found[$ext];
	}
	$dir   = get_template_directory() . '/dist/assets';
	$files = glob($dir . '/app-*.' . $ext);
	$files = is_array($files) ? $files : [];
	if (file_exists($dir . '/app.' . $ext)) {
		$files[] = $dir . '/app.' . $ext;
	}
	usort($files, function ($a, $b) {
		return filemtime($b) <=> filemtime($a);
	});
	$found[$ext] = $files
		? ['path' => $files[0], 'uri' => get_template_directory_uri() . '/dist/assets/' . basename($files[0])]
		: null;
	return $found[$ext];
}

add_action('wp_enqueue_scripts', function () {
	$css = mj_dist_asset('css');
	$js  = mj_dist_asset('js');

	wp_enqueue_style('mj-fonts', mj_fonts_url(), [], null);

	/*
	 * SIN ?ver=. Los chunks que la SPA baja bajo demanda (checkout, cuenta,
	 * páginas…) importan `./app-<hash>.js` a secas, y para el navegador un
	 * módulo ES se identifica por su URL completa, query incluida: con
	 * `app-<hash>.js?ver=123` de entrada y `app-<hash>.js` desde el chunk, React
	 * se carga DOS veces y cualquier hook del chunk revienta con el error 321
	 * ("Invalid hook call"). El hash del nombre ya invalida la caché, así que
	 * la versión sobra. El CSS va igual, por coherencia.
	 */
	if ($css) {
		wp_enqueue_style('mj-app', $css['uri'], [], null); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- el hash del nombre versiona el archivo.
		$design_css = mj_design_css();
		if ('' !== $design_css) {
			wp_add_inline_style('mj-app', $design_css);
		}
	}

	if ($js) {
		wp_enqueue_script('mj-app', $js['uri'], [], null, true); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- sin ?ver a propósito: ver el comentario de arriba.
		wp_add_inline_script('mj-app', 'window.MJ = ' . wp_json_encode(mj_app_config()) . ';', 'before');
	}
}, 20);

/* Los bundles de Vite son ES modules. */
add_filter('script_loader_tag', function ($tag, $handle) {
	if ('mj-app' === $handle) {
		$tag = str_replace('<script ', '<script type="module" ', $tag);
	}
	return $tag;
}, 10, 2);

/* -------------------------------------------------------------------------
 * Routing: la SPA maneja TODAS las rutas del frontend (React Router).
 * wp-admin, login y la REST API no se tocan.
 * ---------------------------------------------------------------------- */
add_filter('template_include', function () {
	return get_template_directory() . '/index.php';
}, 999);

/* React Router usa sus propias rutas: evitar redirecciones canónicas raras. */
add_filter('redirect_canonical', function ($redirect_url) {
	return is_404() ? false : $redirect_url;
});

/* -------------------------------------------------------------------------
 * Status HTTP de las rutas de la SPA.
 *
 * template_include manda TODO a index.php, pero WordPress ya resolvió la
 * consulta antes de llegar ahí: las rutas que sólo existen en React Router
 * (/checkout, /cuenta, /buscar, /nosotros…) no matchean ninguna entrada de
 * WP y se sirven con 404 aunque la página se vea perfecta. Google no las
 * indexa y varios hostings/CDN cortan o cachean mal los 404.
 *
 * Acá se marcan como 200 las rutas que la SPA realmente maneja. Lo que no
 * está en la lista sigue dando 404 real y la SPA muestra su <NotFound />,
 * que es el comportamiento correcto.
 *
 * Espejo de react-app/src/App.jsx: si agregás una ruta allá, agregala acá
 * (o enganchate al filtro `mj_spa_routes`).
 * ---------------------------------------------------------------------- */

/* Rutas estáticas de la SPA (sin barra inicial ni final). */
function mj_spa_routes() {
	return apply_filters('mj_spa_routes', [
		'tienda',
		'buscar',
		'carrito',
		'checkout',
		'cuenta',
		'cuenta/pedidos',
		'cuenta/direcciones',
		'cuenta/favoritos',
		'cuenta/descargas',
		'cuenta/configuracion',
		'mi-cuenta',
		'login',
		'registro',
		'recuperar',
		'favoritos',
		'wishlist',
		'comparar',
		'vistos-recientemente',
		'marcas',
		'contacto',
		'nosotros',
		'faq',
		'privacidad',
		'terminos',
		'mapa-del-sitio',
	]);
}

/*
 * Taxonomía que la SPA usa como "marca". No es una taxonomía propia: el
 * frontend la deriva del atributo de producto cuyo nombre matchea
 * /marca|brand/i (ver react-app/src/lib/wc.js). Devuelve '' si no hay.
 */
function mj_brand_taxonomy() {
	if (!function_exists('wc_get_attribute_taxonomies')) {
		return '';
	}
	foreach (wc_get_attribute_taxonomies() as $attribute) {
		$haystack = $attribute->attribute_name . ' ' . $attribute->attribute_label;
		if (preg_match('/marca|brand/i', $haystack)) {
			return wc_attribute_taxonomy_name($attribute->attribute_name);
		}
	}
	return '';
}

/*
 * ¿La SPA puede resolver este path?
 *
 * Las rutas de detalle (/producto, /categoria, /marca) se validan contra la
 * base: si el producto o el término no existe, se deja el 404 real en vez de
 * devolver un soft-404 con 200. Los detalles de pedido (/cuenta/pedidos/123)
 * se resuelven en la SPA con la sesión del usuario.
 */
function mj_spa_handles_path($path) {
	if (in_array($path, mj_spa_routes(), true)) {
		return true;
	}

	if (preg_match('#^cuenta/pedidos/\d+$#', $path)) {
		return true;
	}

	$parts = explode('/', $path);

	/* Detalles propios del tema: /producto/{slug}, /categoria/{slug}, /marca/{slug}. */
	if (2 === count($parts) && '' !== $parts[1]) {
		list($base, $slug) = $parts;

		if ('producto' === $base) {
			$product = get_page_by_path($slug, OBJECT, 'product');
			return $product instanceof WP_Post && 'publish' === $product->post_status;
		}
		if ('categoria' === $base) {
			return (bool) get_term_by('slug', $slug, 'product_cat');
		}
		if ('marca' === $base) {
			$taxonomy = mj_brand_taxonomy();
			return '' !== $taxonomy && (bool) get_term_by('slug', $slug, $taxonomy);
		}
	}

	/*
	 * Rutas de detalle que agregan otros plugins MJ, de cualquier profundidad
	 * (p. ej. MJ Colegas: /entre-hilos/{colega}; MJ Turnos: /turnos/t/{token}).
	 * El plugin valida contra su propia data.
	 */
	return (bool) apply_filters('mj_spa_handles_path', false, $path, $parts);
}

/*
 * MJ Turnos Online: "Mis turnos" vive en Mi cuenta (/cuenta/turnos). Los mails
 * y la página de cada turno del plugin llevan ahí (filtro mjto_my_bookings_url;
 * el ajuste "Dirección de Mis turnos" del plugin, si está cargado, manda igual).
 * La ruta responde 200 solo con las cuentas de clientas prendidas, igual que la
 * SPA (App.jsx); el noindex ya lo pone la raíz "cuenta" (inc/seo.php).
 * Sin el plugin, nada de esto se dispara.
 */
/**
 * Ruta de "Mis turnos" dentro de Mi cuenta. Es la del router de la SPA
 * (App.jsx monta la cuenta en /cuenta), no mj_account_path(): ese ajuste es
 * adónde se llega después de ingresar y puede ser una subpantalla.
 */
function mj_turnos_account_route() {
	return 'cuenta/turnos';
}

add_filter('mjto_my_bookings_url', function () {
	return home_url('/' . mj_turnos_account_route());
});

add_filter('mj_spa_routes', function ($routes) {
	if (function_exists('mjto_account_mode') && 'off' !== mjto_account_mode()) {
		$routes[] = mj_turnos_account_route();
	}
	return $routes;
});

/*
 * Favicon: el ícono del sitio (Ajustes → Generales / Personalizador) si está
 * cargado; si no, un SVG con el color de acento y la inicial del sitio.
 */
function mj_favicon_tag() {
	$icon = function_exists('get_site_icon_url') ? get_site_icon_url(64) : '';
	if ($icon) {
		return '<link rel="icon" href="' . esc_url($icon) . '" />' . "\n";
	}
	$options = mj_get_options();
	$accent  = mj_hex_rgb($options['color_accent']) ? $options['color_accent'] : '#6e6bff';
	$name    = trim((string) get_bloginfo('name'));
	$initial = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
	$initial = function_exists('mb_strtoupper') ? mb_strtoupper($initial) : strtoupper($initial);
	if ('' === $initial || !preg_match('/^[\p{L}\p{N}]$/u', $initial)) {
		$initial = 'M';
	}
	$svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='9' fill='{$accent}'/><text x='16' y='22' text-anchor='middle' font-family='Georgia,serif' font-size='18' font-weight='600' fill='white'>{$initial}</text></svg>";
	return '<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,' . rawurlencode($svg) . '" />' . "\n";
}

/* Path pedido, relativo a la home (soporta WordPress instalado en subcarpeta). */
function mj_request_path() {
	$uri  = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
	$path = (string) wp_parse_url($uri, PHP_URL_PATH);
	$home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
	if ('' !== $home && '/' !== $home && 0 === strpos($path, $home)) {
		$path = substr($path, strlen($home));
	}
	return trim(rawurldecode($path), '/');
}

/*
 * Prioridad 20: corre DESPUÉS de redirect_canonical (10), que el filtro de
 * arriba desactiva mientras is_404() siga siendo true. Si diéramos vuelta el
 * 404 antes, WP intentaría adivinar un permalink parecido y terminaría
 * redirigiendo la ruta de la SPA a otro lado.
 */
add_action('template_redirect', function () {
	if (is_admin() || wp_doing_ajax() || !is_404()) {
		return;
	}
	if (!mj_spa_handles_path(mj_request_path())) {
		return;
	}

	global $wp_query;
	$wp_query->is_404 = false;
	status_header(200);
}, 20);

/* -------------------------------------------------------------------------
 * Limpieza de <head>: la SPA no necesita nada de esto.
 * ---------------------------------------------------------------------- */
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');

/*
 * La SPA trae su propio CSS: fuera estilos de WooCommerce y Gutenberg.
 *
 * Excepción: si la tienda muestra páginas hechas con el editor de bloques
 * (inc/paginas.php), hace falta la hoja base de los bloques comunes
 * (columnas, galerías, botones, cover…). Se carga entera y siempre, porque
 * la tienda navega sin recargar y una página puede abrirse en cualquier
 * momento; la tipografía y los colores los pone globals.css encima.
 */
add_filter('woocommerce_enqueue_styles', '__return_empty_array');
add_action('wp_enqueue_scripts', function () {
	wp_dequeue_style('wc-blocks-style');
	if (!function_exists('mj_pages_active') || !mj_pages_active()) {
		wp_dequeue_style('wp-block-library');
	}
	wp_dequeue_style('wp-block-library-theme');
	wp_dequeue_style('classic-theme-styles');
	wp_dequeue_style('global-styles');
}, 100);

/*
 * La visibilidad de la barra de administración se decide en inc/session.php
 * (ajuste "Barra de administración", con "según el perfil de cada usuario"
 * como default). Acá sólo se agrega el desplazamiento del header sticky para
 * que la barra no lo tape.
 */
add_action('wp_enqueue_scripts', function () {
	if (!mj_admin_bar_visible() || !wp_style_is('mj-app', 'enqueued')) {
		return;
	}
	wp_add_inline_style('mj-app', mj_admin_bar_css());
}, 25);
