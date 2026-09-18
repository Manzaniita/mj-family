<?php
/**
 * Bloques MJ para el editor de WordPress (Gutenberg).
 *
 * Cada sección de la portada (hero, banners, productos, categorías, marcas,
 * opiniones, Instagram, "Nosotros") y las piezas de las páginas de texto
 * (encabezado, formulario de contacto, preguntas frecuentes, galería de
 * trabajos) existen también como bloque `mj/*`. Con MJ Turnos Online y MJ Colegas activos se suman los
 * suyos (servicios, reserva, colegas).
 *
 * Cómo funciona:
 *
 *   - El ESQUEMA de cada bloque (campos, tipos, defaults, textos del panel)
 *     vive acá, en `mj_blocks()`, una sola vez. El editor lo recibe como
 *     `window.MJBloques` y registra los bloques con sus controles
 *     (bloques/editor.js, sin build). PHP registra los mismos atributos con
 *     `register_block_type`, así el servidor los conoce y los sanea.
 *   - En la tienda un bloque MJ se renderiza como un marcador
 *     `<div data-mj-block="…" data-mj-props="{…}">`; la SPA (React) monta
 *     adentro el mismo componente que ya usa la portada. Nada se dibuja dos
 *     veces ni con otro CSS.
 *   - Un campo vacío usa el valor del panel (Apariencia → MJ Appearance) o el
 *     texto de la skin, igual que la portada clásica: el bloque solo pisa lo
 *     que el admin escribió en él.
 *
 * Un plugin o tema hijo suma o retoca bloques con el filtro `mj_blocks`.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Esquema
 * ---------------------------------------------------------------------- */

/* Tipos de campo del panel y el tipo de atributo de Gutenberg que les corresponde. */
function mj_block_field_types() {
	return [
		'text'       => 'string',
		'textarea'   => 'string',
		'url'        => 'string',
		'select'     => 'string',
		'category'   => 'string',
		'brand'      => 'string',
		'number'     => 'number',
		'toggle'     => 'boolean',
		'image'      => 'object',
		'images'     => 'array',
		'products'   => 'array',
		'categories' => 'array',
		'repeater'   => 'array',
		'gallery'    => 'array', // Fotos con leyenda: [{id, url, caption}] (caption vacío = la leyenda o el texto alternativo de la biblioteca).
	];
}

/* Valor "vacío" de un tipo (el default cuando el esquema no dice otro). */
function mj_block_field_empty($type) {
	switch ($type) {
		case 'number':
			return 0;
		case 'toggle':
			return false;
		case 'image':
			return null;
		case 'images':
		case 'products':
		case 'categories':
		case 'repeater':
		case 'gallery':
			return [];
		default:
			return '';
	}
}

/* Opciones de disposición compartidas por los bloques con grilla. */
function mj_block_layout_options() {
	return ['grid' => 'Grilla', 'carousel' => 'Carrusel'];
}

/*
 * Bloques disponibles: key => definición.
 *
 *   title, description, icon (dashicon), keywords
 *   fields: key => [label, type, default, help, placeholder, options, min, max, step, fields (repeater)]
 *   variations: [[name, title, description, attributes, isDefault]]
 *   summary: campos que se listan en la tarjeta del editor
 *
 * Los que dependen de un plugin (turnos, colegas) solo existen con el plugin
 * activo: sin él no aparecen en el insertador y, si quedaron en una página,
 * la tienda no dibuja nada en su lugar.
 */
function mj_blocks() {
	static $blocks = null;
	if (null !== $blocks) {
		return $blocks;
	}

	$copy   = function_exists('mj_copy') ? mj_copy() : [];
	$ph     = function ($key) use ($copy) {
		return isset($copy[$key]) ? (string) $copy[$key] : '';
	};
	$layout = mj_block_layout_options();

	$text = function ($label, $placeholder = '', $help = '') {
		return ['label' => $label, 'type' => 'text', 'default' => '', 'placeholder' => $placeholder, 'help' => $help];
	};
	$textarea = function ($label, $placeholder = '', $help = '') {
		return ['label' => $label, 'type' => 'textarea', 'default' => '', 'placeholder' => $placeholder, 'help' => $help];
	};
	$number = function ($label, $default, $min, $max, $help = '') {
		return ['label' => $label, 'type' => 'number', 'default' => $default, 'min' => $min, 'max' => $max, 'step' => 1, 'help' => $help];
	};
	$toggle = function ($label, $default = true, $help = '') {
		return ['label' => $label, 'type' => 'toggle', 'default' => $default, 'help' => $help];
	};
	$select = function ($label, $options, $default, $help = '') {
		return ['label' => $label, 'type' => 'select', 'options' => $options, 'default' => $default, 'help' => $help];
	};

	$cols = $select('Columnas', ['2' => '2', '3' => '3', '4' => '4'], '4');

	$all = [
		'hero' => [
			'title'       => 'Hero de la portada',
			'description' => 'La cabecera grande con título, subtítulo, botones y fotos de fondo. Vacío = lo configurado en Apariencia → MJ Appearance → Portada (hero).',
			'icon'        => 'cover-image',
			'keywords'    => ['portada', 'cabecera', 'hero', 'inicio'],
			'fields'      => [
				'badge'             => $text('Etiqueta chica', '', 'La píldora arriba del título.'),
				'title'             => $text('Título'),
				'titleHighlight'    => $text('Título destacado', '', 'La segunda línea, con el degradado de la marca.'),
				'subtitle'          => $textarea('Subtítulo'),
				'ctaPrimaryText'    => $text('Botón principal'),
				'ctaPrimaryUrl'     => ['label' => 'Destino del botón principal', 'type' => 'url', 'default' => '', 'placeholder' => '/tienda'],
				'ctaSecondaryText'  => $text('Botón secundario'),
				'ctaSecondaryUrl'   => ['label' => 'Destino del botón secundario', 'type' => 'url', 'default' => '', 'placeholder' => '/tienda'],
				'images'            => ['label' => 'Fotos de fondo', 'type' => 'images', 'default' => [], 'help' => 'Rotan en fundido. Vacío = las del panel.'],
				'showStats'         => $toggle('Mostrar las cifras (productos, envío gratis, valoración)', true),
			],
			'summary'     => ['title', 'titleHighlight'],
		],

		'encabezado' => [
			'title'       => 'Encabezado de página',
			'description' => 'Antetítulo, título grande y bajada, con la tipografía de la tienda. Para arrancar una página de texto.',
			'icon'        => 'heading',
			'keywords'    => ['título', 'encabezado', 'página'],
			'fields'      => [
				'eyebrow'  => $text('Antetítulo'),
				'title'    => $text('Título', '', 'Vacío = el título de la página.'),
				'subtitle' => $textarea('Bajada'),
			],
			'summary'     => ['title'],
		],

		'banners' => [
			'title'       => 'Slider de banners',
			'description' => 'Carrusel de imágenes anchas con flechas. Vacío = los banners de Apariencia → MJ Appearance.',
			'icon'        => 'images-alt2',
			'keywords'    => ['banner', 'slider', 'carrusel', 'promo'],
			'fields'      => [
				'images'   => ['label' => 'Imágenes', 'type' => 'images', 'default' => [], 'help' => 'Ideal 1600 × 600 px o más anchas.'],
				'autoplay' => $toggle('Pasar solo', true),
				'interval' => array_merge($number('Segundos entre imágenes', 6, 2, 20), ['showIf' => ['autoplay' => true]]),
				'height'   => $select('Alto', ['sm' => 'Bajo', 'md' => 'Medio', 'lg' => 'Alto'], 'md'),
			],
			'summary'     => [],
		],

		'productos' => [
			'title'       => 'Productos',
			'description' => 'Una selección de productos del catálogo: destacados, novedades, los mejor valorados, en oferta, de una categoría o marca, o elegidos uno por uno. En grilla o en carrusel.',
			'icon'        => 'grid-view',
			'keywords'    => ['productos', 'destacados', 'novedades', 'carrusel', 'catálogo'],
			'fields'      => [
				'eyebrow'   => $text('Antetítulo', $ph('home_featured_eyebrow')),
				'title'     => $text('Título', $ph('home_featured_title')),
				'subtitle'  => $textarea('Bajada', $ph('home_featured_subtitle')),
				'source'    => $select('Qué productos', [
					'featured' => 'Destacados (etiqueta "destacado")',
					'new'      => 'Últimos ingresos (etiqueta "nuevo")',
					'rating'   => 'Mejor valorados',
					'sale'     => 'En oferta',
					'category' => 'De una categoría',
					'brand'    => 'De una marca',
					'manual'   => 'Elegidos a mano',
					'all'      => 'Todo el catálogo',
				], 'featured'),
				'category'  => ['label' => 'Categoría', 'type' => 'category', 'default' => '', 'showIf' => ['source' => 'category']],
				'brand'     => ['label' => 'Marca', 'type' => 'brand', 'default' => '', 'showIf' => ['source' => 'brand']],
				'products'  => ['label' => 'Productos elegidos', 'type' => 'products', 'default' => [], 'help' => 'Se muestran en este orden.', 'showIf' => ['source' => 'manual']],
				'orderby'   => $select('Orden', [
					'relevance'  => 'Como vienen',
					'newest'     => 'Nuevos primero',
					'rating'     => 'Mejor valorados',
					'price-asc'  => 'Precio: menor a mayor',
					'price-desc' => 'Precio: mayor a menor',
				], 'relevance'),
				'limit'     => $number('Cuántos', 4, 1, 24),
				'inStock'   => $toggle('Solo con stock', false),
				'layout'    => $select('Disposición', $layout, 'grid'),
				'cols'      => $cols,
				'linkTo'    => ['label' => 'Enlace "Ver todo"', 'type' => 'url', 'default' => '', 'placeholder' => '/tienda', 'help' => 'Vacío = sin enlace.'],
				'linkLabel' => $text('Texto del enlace', 'Ver todo'),
			],
			'variations'  => [
				['name' => 'destacados', 'title' => 'Destacados', 'description' => 'Los productos con la etiqueta "destacado".', 'attributes' => ['source' => 'featured'], 'isDefault' => true],
				['name' => 'novedades', 'title' => 'Últimos ingresos', 'description' => 'Los productos con la etiqueta "nuevo".', 'attributes' => ['source' => 'new']],
				['name' => 'recomendados', 'title' => 'Recomendados', 'description' => 'Los mejor valorados.', 'attributes' => ['source' => 'rating']],
				['name' => 'ofertas', 'title' => 'Ofertas', 'description' => 'Los productos en oferta.', 'attributes' => ['source' => 'sale']],
				['name' => 'categoria', 'title' => 'Productos de una categoría', 'description' => 'Elegís la categoría.', 'attributes' => ['source' => 'category']],
				['name' => 'manual', 'title' => 'Productos elegidos a mano', 'description' => 'Buscás y elegís cada producto.', 'attributes' => ['source' => 'manual']],
			],
			'summary'     => ['title', 'source', 'limit', 'layout'],
		],

		'categorias' => [
			'title'       => 'Categorías',
			'description' => 'Tarjetas grandes con foto por categoría. Vacío = las categorías con más productos.',
			'icon'        => 'category',
			'keywords'    => ['categorías', 'colecciones'],
			'fields'      => [
				'eyebrow'    => $text('Antetítulo', $ph('home_categories_eyebrow')),
				'title'      => $text('Título', $ph('home_categories_title')),
				'categories' => ['label' => 'Categorías', 'type' => 'categories', 'default' => [], 'help' => 'Vacío = automático.'],
				'limit'      => $number('Cuántas (si es automático)', 4, 1, 12),
				'layout'     => $select('Disposición', $layout, 'grid'),
				'linkTo'     => ['label' => 'Enlace', 'type' => 'url', 'default' => '', 'placeholder' => '/tienda'],
				'linkLabel'  => $text('Texto del enlace', $ph('home_categories_link')),
			],
			'summary'     => ['title', 'limit'],
		],

		'marcas' => [
			'title'       => 'Cinta de marcas',
			'description' => 'Las marcas del catálogo pasando en loop.',
			'icon'        => 'tag',
			'keywords'    => ['marcas', 'logos', 'marquee'],
			'fields'      => [
				'label' => $text('Frase arriba de la cinta', $ph('home_brands_label')),
			],
			'summary'     => ['label'],
		],

		'opiniones' => [
			'title'       => 'Opiniones',
			'description' => 'Las últimas valoraciones aprobadas de WooCommerce o las opiniones de los turnos (MJ Turnos Online). Sin opiniones, el bloque no se muestra.',
			'icon'        => 'star-filled',
			'keywords'    => ['opiniones', 'reseñas', 'testimonios'],
			'fields'      => [
				'eyebrow' => $text('Antetítulo', $ph('home_testimonials_eyebrow')),
				'title'   => $text('Título', $ph('home_testimonials_title')),
				'limit'   => $number('Cuántas', 4, 1, 12),
				'source'  => $select('De dónde salen', [
					'auto'   => 'Automático (WooCommerce; si no hay, las de los turnos)',
					'woo'    => 'Valoraciones de productos (WooCommerce)',
					'turnos' => 'Opiniones de los turnos (MJ Turnos Online)',
				], 'auto', 'Las de los turnos necesitan el plugin MJ Turnos Online con las opiniones prendidas.'),
			],
			'summary'     => ['title', 'limit', 'source'],
		],

		'galeria' => [
			'title'       => 'Galería de trabajos',
			'description' => 'Fotos con su nombre debajo (ej. "Lash Lifting Tradicional"). Al tocar una se abre grande. Con varias leyendas distintas, un filtro opcional arriba.',
			'icon'        => 'format-gallery',
			'keywords'    => ['galería', 'trabajos', 'fotos', 'portfolio', 'antes y después'],
			'fields'      => [
				'eyebrow'      => $text('Antetítulo'),
				'title'        => $text('Título', '', 'Vacío = sin encabezado.'),
				'images'       => ['label' => 'Fotos', 'type' => 'gallery', 'default' => [], 'help' => 'La leyenda de cada foto es el texto que escribas debajo; vacío = la leyenda (o el texto alternativo) de la foto en la biblioteca de medios.'],
				'showCaptions' => $toggle('Mostrar la leyenda debajo de cada foto', true),
				'filter'       => $toggle('Filtro por leyenda (botones arriba)', false, 'Solo aparece si hay al menos dos leyendas distintas.'),
				'cols'         => $select('Columnas en la computadora', ['2' => '2', '3' => '3', '4' => '4', '5' => '5'], '3'),
				'colsMobile'   => $select('Columnas en el celular', ['1' => '1', '2' => '2'], '2'),
				'ratio'        => $select('Forma de las fotos', ['square' => 'Cuadradas', 'portrait' => 'Verticales (4:5)', 'landscape' => 'Horizontales (4:3)', 'original' => 'Como vienen'], 'square'),
			],
			'summary'     => ['title'],
		],

		'instagram' => [
			'title'       => 'Instagram',
			'description' => 'Mosaico de fotos con enlace a la cuenta configurada en Redes sociales.',
			'icon'        => 'instagram',
			'keywords'    => ['instagram', 'redes', 'fotos'],
			'fields'      => [
				'title'     => $text('Título', $ph('home_instagram_title')),
				'linkLabel' => $text('Texto del enlace', $ph('home_instagram_link')),
				'limit'     => $select('Cuántas fotos', ['3' => '3', '6' => '6', '12' => '12'], '6'),
			],
			'summary'     => ['title'],
		],

		'nosotros' => [
			'title'       => 'Nosotros',
			'description' => 'Foto y texto de "Nosotros". Como adelanto (portada) o como página completa con puntos destacados y cifras.',
			'icon'        => 'groups',
			'keywords'    => ['nosotros', 'sobre', 'historia', 'quiénes somos'],
			'fields'      => [
				'layout'    => $select('Mostrar', ['teaser' => 'Adelanto (foto, texto corto y enlace)', 'full' => 'Página completa (texto, puntos destacados, cifras y botón)'], 'teaser'),
				'eyebrow'   => $text('Antetítulo', $ph('home_about_eyebrow')),
				'title'     => $text('Título', $ph('home_about_title')),
				'text'      => $textarea('Texto', '', 'Vacío = el texto de Contenido → Nosotros.'),
				'image'     => ['label' => 'Foto', 'type' => 'image', 'default' => null, 'help' => 'Vacío = la foto de Contenido → Nosotros.'],
				'linkLabel' => array_merge($text('Texto del enlace', $ph('home_about_link')), ['showIf' => ['layout' => 'teaser']]),
			],
			'summary'     => ['title'],
		],

		'contacto' => [
			'title'       => 'Contacto',
			'description' => 'El formulario de contacto y los canales (dirección o zona de atención, teléfono, WhatsApp, mail, Instagram, mapa) configurados en Contacto.',
			'icon'        => 'email',
			'keywords'    => ['contacto', 'formulario', 'mapa', 'whatsapp'],
			'fields'      => [
				'showForm'     => $toggle('Mostrar el formulario', true),
				'showChannels' => $toggle('Mostrar los canales de contacto', true),
				'showMap'      => $toggle('Mostrar el mapa', true),
			],
			'summary'     => [],
		],

		'faq' => [
			'title'       => 'Preguntas frecuentes',
			'description' => 'Acordeón de preguntas y respuestas. Vacío = las de Contenido → Preguntas frecuentes.',
			'icon'        => 'editor-help',
			'keywords'    => ['faq', 'preguntas', 'ayuda', 'acordeón'],
			'fields'      => [
				'items'           => [
					'label'   => 'Preguntas',
					'type'    => 'repeater',
					'default' => [],
					'fields'  => [
						'q' => ['label' => 'Pregunta', 'type' => 'text', 'default' => ''],
						'a' => ['label' => 'Respuesta', 'type' => 'textarea', 'default' => ''],
					],
				],
				'showContactCard' => $toggle('Mostrar "¿No encontraste tu respuesta?" con el botón a Contacto', true),
			],
			'summary'     => [],
		],
	];

	/* Plugin MJ Turnos Online. */
	if (function_exists('mjto_public_config')) {
		$all['servicios'] = [
			'title'       => 'Servicios (Turnos)',
			'description' => 'Los servicios destacados del plugin MJ Turnos Online. Textos vacíos = los de Turnos → Ajustes.',
			'icon'        => 'calendar-alt',
			'keywords'    => ['servicios', 'turnos', 'tratamientos'],
			'fields'      => [
				'eyebrow'   => $text('Antetítulo'),
				'title'     => $text('Título'),
				'subtitle'  => $textarea('Bajada'),
				'limit'     => $number('Cuántos', 0, 0, 12, '0 = la cantidad de Turnos → Ajustes.'),
				'linkLabel' => $text('Texto del enlace'),
			],
			'summary'     => ['title', 'limit'],
		];
		$all['reserva'] = [
			'title'       => 'Reservá tu turno',
			'description' => 'El banner con botón a la reserva online. Textos vacíos = los de Turnos → Ajustes.',
			'icon'        => 'clock',
			'keywords'    => ['reserva', 'turno', 'cta'],
			'fields'      => [
				'eyebrow'  => $text('Antetítulo'),
				'title'    => $text('Título'),
				'subtitle' => $textarea('Bajada'),
				'button'   => $text('Botón'),
				'image'    => ['label' => 'Foto', 'type' => 'image', 'default' => null],
			],
			'summary'     => ['title'],
		];
	}

	/* Plugin MJ Colegas. */
	if (function_exists('mjc_get_options')) {
		$all['colegas'] = [
			'title'       => 'Colegas',
			'description' => 'Las colegas del proyecto colectivo (plugin MJ Colegas). Textos vacíos = los de Colegas → Ajustes.',
			'icon'        => 'admin-users',
			'keywords'    => ['colegas', 'proyecto', 'entre hilos'],
			'fields'      => [
				'eyebrow'   => $text('Antetítulo'),
				'title'     => $text('Título'),
				'subtitle'  => $textarea('Bajada'),
				'limit'     => $number('Cuántas', 4, 1, 12),
				'linkLabel' => $text('Texto del enlace'),
			],
			'summary'     => ['title', 'limit'],
		];
	}

	/**
	 * Bloques MJ disponibles en el editor.
	 *
	 * @param array $all key => definición (ver mj_blocks()).
	 */
	$filtered = apply_filters('mj_blocks', $all);
	$blocks   = [];
	$types    = mj_block_field_types();
	foreach ((array) $filtered as $key => $def) {
		$key = sanitize_key((string) $key);
		if ('' === $key || !is_array($def) || empty($def['fields']) || !is_array($def['fields'])) {
			continue;
		}
		$def = wp_parse_args($def, [
			'title'       => $key,
			'description' => '',
			'icon'        => 'block-default',
			'keywords'    => [],
			'variations'  => [],
			'summary'     => [],
		]);
		$fields = [];
		foreach ($def['fields'] as $fkey => $field) {
			$fkey = preg_replace('/[^A-Za-z0-9_]/', '', (string) $fkey);
			if ('' === $fkey || !is_array($field)) {
				continue;
			}
			$type = isset($field['type']) && isset($types[$field['type']]) ? $field['type'] : 'text';
			$fields[$fkey] = wp_parse_args($field, [
				'label'       => $fkey,
				'type'        => $type,
				'default'     => mj_block_field_empty($type),
				'help'        => '',
				'placeholder' => '',
			]);
			$fields[$fkey]['type'] = $type;
		}
		$def['fields'] = $fields;
		$blocks[$key]  = $def;
	}
	return $blocks;
}

/* Atributos de Gutenberg de un bloque, derivados de sus campos. */
function mj_block_attributes(array $def) {
	$types = mj_block_field_types();
	$attrs = [];
	foreach ($def['fields'] as $key => $field) {
		$attrs[$key] = [
			'type'    => $types[$field['type']],
			'default' => $field['default'],
		];
	}
	return $attrs;
}

/* -------------------------------------------------------------------------
 * Registro en WordPress
 * ---------------------------------------------------------------------- */
add_action('init', function () {
	if (!function_exists('register_block_type')) {
		return;
	}
	foreach (mj_blocks() as $key => $def) {
		register_block_type('mj/' . $key, [
			'api_version'     => 2,
			'title'           => (string) $def['title'],
			'description'     => (string) $def['description'],
			'category'        => 'mj',
			'icon'            => (string) $def['icon'],
			'keywords'        => (array) $def['keywords'],
			'attributes'      => mj_block_attributes($def),
			'supports'        => ['html' => false, 'align' => false, 'customClassName' => false],
			'render_callback' => 'mj_block_render',
		]);
	}
}, 15);

/* Categoría "MJ Appearance" en el insertador, arriba de todo. */
add_filter('block_categories_all', function ($categories) {
	array_unshift($categories, [
		'slug'  => 'mj',
		'title' => function_exists('mj_settings_menu_title') ? mj_settings_menu_title() : 'MJ Appearance',
		'icon'  => null,
	]);
	return $categories;
});

/* -------------------------------------------------------------------------
 * Render: el marcador que la SPA hidrata
 * ---------------------------------------------------------------------- */

/* URL (tamaño large) de un adjunto guardado como {id, url}; '' si ya no existe. */
function mj_block_image_url($value) {
	$id = is_array($value) && isset($value['id']) ? absint($value['id']) : absint($value);
	if (!$id) {
		return '';
	}
	$url = wp_get_attachment_image_url($id, 'large');
	return $url ? $url : '';
}

/*
 * Fotos de la galería → [{src, full, caption, alt, width, height}].
 *
 * La leyenda es el texto propio de la foto en el bloque; vacío = la leyenda
 * de la biblioteca de medios; vacía también = su texto alternativo. Así, si
 * se corrige la leyenda en la biblioteca, la galería se entera sola.
 */
function mj_block_gallery_items($value) {
	$items = [];
	foreach ((array) $value as $item) {
		$id = is_array($item) && isset($item['id']) ? absint($item['id']) : absint($item);
		if (!$id) {
			continue;
		}
		$large = wp_get_attachment_image_src($id, 'large');
		if (!$large) {
			continue;
		}
		$full    = wp_get_attachment_image_src($id, 'full');
		$alt     = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
		$own     = is_array($item) && isset($item['caption']) ? sanitize_text_field((string) $item['caption']) : '';
		$library = function_exists('wp_get_attachment_caption') ? trim(wp_strip_all_tags((string) wp_get_attachment_caption($id))) : '';
		$caption = '' !== trim($own) ? trim($own) : ('' !== $library ? $library : $alt);
		$items[] = [
			'src'     => (string) $large[0],
			'full'    => $full ? (string) $full[0] : (string) $large[0],
			'caption' => $caption,
			'alt'     => '' !== $alt ? $alt : $caption,
			'width'   => (int) $large[1],
			'height'  => (int) $large[2],
		];
	}
	return $items;
}

/* Un valor de campo saneado según su tipo, listo para viajar a la SPA. */
function mj_block_sanitize_value(array $field, $value) {
	switch ($field['type']) {
		case 'text':
		case 'select':
		case 'category':
		case 'brand':
			$value = sanitize_text_field((string) (is_scalar($value) ? $value : ''));
			if ('select' === $field['type'] && !empty($field['options']) && !isset($field['options'][$value])) {
				$value = (string) $field['default'];
			}
			return $value;
		case 'textarea':
			return sanitize_textarea_field((string) (is_scalar($value) ? $value : ''));
		case 'url':
			$value = trim((string) (is_scalar($value) ? $value : ''));
			if ('' === $value) {
				return '';
			}
			/* Rutas internas ("/tienda", "tienda") o URL completas. */
			return 0 === strpos($value, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $value) ? esc_url_raw($value) : '/' . ltrim(sanitize_text_field($value), '/');
		case 'number':
			$n = is_numeric($value) ? (float) $value : (float) $field['default'];
			if (isset($field['min'])) {
				$n = max((float) $field['min'], $n);
			}
			if (isset($field['max'])) {
				$n = min((float) $field['max'], $n);
			}
			return (float) (int) $n === $n ? (int) $n : $n;
		case 'toggle':
			return (bool) $value;
		case 'image':
			return mj_block_image_url($value);
		case 'images':
			$urls = [];
			foreach ((array) $value as $item) {
				$url = mj_block_image_url($item);
				if ('' !== $url) {
					$urls[] = $url;
				}
			}
			return $urls;
		case 'products':
			$ids = [];
			foreach ((array) $value as $item) {
				$id = is_array($item) && isset($item['id']) ? absint($item['id']) : absint($item);
				if ($id) {
					$ids[] = $id;
				}
			}
			return array_values(array_unique($ids));
		case 'categories':
			$slugs = [];
			foreach ((array) $value as $item) {
				$slug = sanitize_title((string) (is_scalar($item) ? $item : ''));
				if ('' !== $slug) {
					$slugs[] = $slug;
				}
			}
			return array_values(array_unique($slugs));
		case 'gallery':
			return mj_block_gallery_items($value);
		case 'repeater':
			$rows = [];
			$sub  = isset($field['fields']) && is_array($field['fields']) ? $field['fields'] : [];
			foreach ((array) $value as $row) {
				if (!is_array($row)) {
					continue;
				}
				$clean = [];
				foreach ($sub as $skey => $sfield) {
					$sfield       = wp_parse_args($sfield, ['type' => 'text', 'default' => '']);
					$clean[$skey] = mj_block_sanitize_value($sfield, isset($row[$skey]) ? $row[$skey] : $sfield['default']);
				}
				$rows[] = $clean;
			}
			return $rows;
	}
	return '';
}

/* Props saneadas de un bloque (atributos guardados → lo que recibe React). */
function mj_block_props($key, array $attributes) {
	$blocks = mj_blocks();
	if (!isset($blocks[$key])) {
		return [];
	}
	$props = [];
	foreach ($blocks[$key]['fields'] as $fkey => $field) {
		$props[$fkey] = mj_block_sanitize_value($field, array_key_exists($fkey, $attributes) ? $attributes[$fkey] : $field['default']);
	}
	/**
	 * Props que un bloque MJ le manda a la SPA.
	 *
	 * @param array  $props
	 * @param string $key        Bloque (sin el prefijo mj/).
	 * @param array  $attributes Atributos tal cual se guardaron.
	 */
	return (array) apply_filters('mj_block_props', $props, $key, $attributes);
}

/* Marcador que la SPA reemplaza por el componente de React. */
function mj_block_render($attributes, $content = '', $block = null) {
	$name = $block instanceof WP_Block ? $block->name : '';
	$key  = 0 === strpos($name, 'mj/') ? substr($name, 3) : '';
	if ('' === $key || !isset(mj_blocks()[$key])) {
		return '';
	}
	$props = mj_block_props($key, is_array($attributes) ? $attributes : []);
	return sprintf(
		'<div class="mj-block mj-block--%1$s" data-mj-block="%1$s" data-mj-props="%2$s"></div>',
		esc_attr($key),
		esc_attr(wp_json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
	);
}

/* -------------------------------------------------------------------------
 * Editor: soportes, estilos y el script de los bloques
 * ---------------------------------------------------------------------- */
add_action('after_setup_theme', function () {
	add_theme_support('editor-styles');
	add_theme_support('responsive-embeds');
	add_theme_support('align-wide');
	/* La hoja del editor + las tipografías elegidas en Diseño y marca. */
	$styles = ['bloques/editor.css'];
	if (function_exists('mj_fonts_url')) {
		$styles[] = mj_fonts_url();
	}
	add_editor_style($styles);
}, 20);

/* Paletas base del tema (espejo de react-app/src/styles/globals.css) para pintar el editor. */
function mj_editor_palettes() {
	return [
		'dark'  => [
			'--mj-bg'          => '#0a0a0c',
			'--mj-bg-soft'     => '#101014',
			'--mj-surface'     => 'rgb(255 255 255 / 0.04)',
			'--mj-surface-2'   => 'rgb(255 255 255 / 0.07)',
			'--mj-line'        => 'rgb(255 255 255 / 0.08)',
			'--mj-line-strong' => 'rgb(255 255 255 / 0.16)',
			'--mj-ink'         => '#f5f5f7',
			'--mj-ink-soft'    => 'rgb(245 245 247 / 0.64)',
			'--mj-ink-faint'   => 'rgb(245 245 247 / 0.38)',
			'--mj-accent'      => '#6e6bff',
			'--mj-accent-soft' => 'rgb(110 107 255 / 0.14)',
			'--mj-accent-glow' => 'rgb(110 107 255 / 0.35)',
			'--mj-star'        => '#fbbf24',
			'color-scheme'     => 'dark',
		],
		'light' => [
			'--mj-bg'          => '#fafafa',
			'--mj-bg-soft'     => '#f1f1f4',
			'--mj-surface'     => 'rgb(10 10 12 / 0.03)',
			'--mj-surface-2'   => 'rgb(10 10 12 / 0.06)',
			'--mj-line'        => 'rgb(10 10 12 / 0.08)',
			'--mj-line-strong' => 'rgb(10 10 12 / 0.16)',
			'--mj-ink'         => '#0a0a0c',
			'--mj-ink-soft'    => 'rgb(10 10 12 / 0.62)',
			'--mj-ink-faint'   => 'rgb(10 10 12 / 0.38)',
			'--mj-accent'      => '#5a57e8',
			'--mj-accent-soft' => 'rgb(90 87 232 / 0.1)',
			'--mj-accent-glow' => 'rgb(90 87 232 / 0.25)',
			'--mj-star'        => '#f59e0b',
			'color-scheme'     => 'light',
		],
	];
}

/* 'dark' | 'light': con qué tema se pinta el editor (ajuste "Vista del editor"). */
function mj_editor_theme() {
	$options = mj_get_options();
	$choice  = isset($options['editor_theme']) ? (string) $options['editor_theme'] : 'auto';
	if ('light' === $choice || 'dark' === $choice) {
		return $choice;
	}
	$default = function_exists('mj_theme_default') ? mj_theme_default() : 'dark';
	return 'light' === $default ? 'light' : 'dark';
}

/*
 * CSS del editor: la paleta del tema elegido, los colores y tipografías de
 * Diseño y marca, y las variables que la skin ajuste. Es lo que hace que el
 * editor se parezca a la tienda: los bloques se ven con el fondo, la tinta,
 * el acento y las fuentes reales.
 */
function mj_editor_theme_css() {
	$theme   = mj_editor_theme();
	$palette = mj_editor_palettes()[$theme];
	$vars    = function_exists('mj_design_var_sets') ? mj_design_var_sets() : ['fonts' => [], 'dark' => [], 'light' => []];
	$lines   = [];
	foreach ($palette as $prop => $value) {
		$lines[] = $prop . ': ' . $value;
	}
	$lines = array_merge($lines, (array) $vars['fonts'], (array) $vars[$theme]);
	return '.editor-styles-wrapper{' . implode(';', $lines) . ';}';
}

/* Fuentes, colores y variables del tema, dentro del lienzo del editor. */
add_action('enqueue_block_assets', function () {
	if (!is_admin()) {
		return;
	}
	wp_register_style('mj-editor-theme', false, [], MJ_VERSION);
	wp_enqueue_style('mj-editor-theme');
	wp_add_inline_style('mj-editor-theme', mj_editor_theme_css());
});

/* Categorías de producto para los selectores del editor. */
function mj_blocks_editor_categories() {
	if (!taxonomy_exists('product_cat')) {
		return [];
	}
	$terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 200]);
	$out   = [];
	if (is_array($terms)) {
		foreach ($terms as $term) {
			if (in_array($term->slug, ['uncategorized', 'sin-categorizar'], true)) {
				continue;
			}
			$out[] = ['slug' => $term->slug, 'name' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'), 'count' => (int) $term->count];
		}
	}
	return $out;
}

/* Marcas (el atributo de producto que la tienda usa como marca). */
function mj_blocks_editor_brands() {
	$taxonomy = function_exists('mj_brand_taxonomy') ? mj_brand_taxonomy() : '';
	if ('' === $taxonomy || !taxonomy_exists($taxonomy)) {
		return [];
	}
	$terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true, 'number' => 200]);
	$out   = [];
	if (is_array($terms)) {
		foreach ($terms as $term) {
			$out[] = ['slug' => $term->slug, 'name' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8')];
		}
	}
	return $out;
}

/* Todo lo que el script del editor necesita saber (window.MJBloques). */
function mj_blocks_editor_config() {
	$options = mj_get_options();
	$config  = [
		'blocks'      => mj_blocks(),
		'categories'  => mj_blocks_editor_categories(),
		'brands'      => mj_blocks_editor_brands(),
		'copy'        => function_exists('mj_copy') ? mj_copy() : [],
		/* Valores del panel que las vistas previas muestran cuando el bloque no dice otra cosa. */
		'defaults'    => [
			'hero'       => [
				'badge'          => (string) $options['hero_badge'],
				'title'          => (string) $options['hero_title'],
				'titleHighlight' => (string) $options['hero_title_highlight'],
				'subtitle'       => (string) $options['hero_subtitle'],
				'tagline'        => (string) get_bloginfo('description'),
				'ctaPrimary'     => (string) $options['hero_cta_primary_text'],
				'ctaSecondary'   => (string) $options['hero_cta_secondary_text'],
				'image'          => function_exists('mj_attachment_urls') ? (string) (mj_attachment_urls($options['hero_bg_images'])[0] ?? '') : '',
			],
			'aboutImage' => absint($options['about_image']) ? (string) wp_get_attachment_image_url(absint($options['about_image']), 'medium') : '',
			'aboutText'  => wp_trim_words(wp_strip_all_tags((string) $options['about_text']), 40),
		],
		'siteName'    => (string) get_bloginfo('name'),
		'theme'       => mj_editor_theme(),
		'reserved'    => function_exists('mj_pages_reserved_paths') ? mj_pages_reserved_paths() : [],
		'overridable' => function_exists('mj_pages_overridable') ? mj_pages_overridable() : [],
		'pagesOn'     => function_exists('mj_pages_enabled') ? mj_pages_enabled() : false,
		'homeId'      => function_exists('mj_pages_home_id') ? mj_pages_home_id() : 0,
		'homeUrl'     => home_url('/'),
		'settingsUrl' => function_exists('mj_settings_page_url') ? mj_settings_page_url() : admin_url('themes.php'),
		'readingUrl'  => admin_url('options-reading.php'),
		'storeApi'    => esc_url_raw(rest_url('wc/store/v1/')),
	];
	/**
	 * Configuración que recibe el script del editor de bloques.
	 *
	 * @param array $config
	 */
	return apply_filters('mj_blocks_editor_config', $config);
}

add_action('enqueue_block_editor_assets', function () {
	$path = get_template_directory() . '/bloques/editor.js';
	if (!file_exists($path)) {
		return;
	}
	wp_enqueue_script(
		'mj-bloques',
		get_template_directory_uri() . '/bloques/editor.js',
		['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-i18n', 'wp-plugins', 'wp-edit-post', 'wp-api-fetch', 'wp-core-data', 'wp-compose'],
		(string) filemtime($path),
		true
	);
	wp_add_inline_script(
		'mj-bloques',
		'window.MJBloques = ' . wp_json_encode(mj_blocks_editor_config(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
		'before'
	);
	/* Controles de la barra lateral (fuera del lienzo, así que no van por add_editor_style). */
	$ui = get_template_directory() . '/bloques/editor-ui.css';
	if (file_exists($ui)) {
		wp_enqueue_style('mj-bloques-ui', get_template_directory_uri() . '/bloques/editor-ui.css', ['wp-edit-blocks'], (string) filemtime($ui));
	}
});
