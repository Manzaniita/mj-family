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
		'color'      => 'string',
		'select'     => 'string',
		'category'   => 'string',
		'brand'        => 'string',
		'width_slider' => 'string',
		'number'       => 'number',
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
	$color = function ($label, $placeholder = '', $help = '') {
		return ['label' => $label, 'type' => 'color', 'default' => '', 'placeholder' => $placeholder, 'help' => $help];
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
				'images'            => ['label' => 'Fotos de fondo', 'type' => 'images', 'focal' => true, 'default' => [], 'help' => 'Rotan en fundido. Vacío = las del panel.'],
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
				'images'   => ['label' => 'Imágenes', 'type' => 'images', 'focal' => true, 'default' => [], 'help' => 'Ideal 1600 × 600 px o más anchas.'],
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
				'image'     => ['label' => 'Foto', 'type' => 'image', 'focal' => true, 'default' => null, 'help' => 'Ideal vertical (1000 × 1200 px). Vacío = la de Contenido → Nosotros.'],
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

		'header' => [
			'title'       => 'Cabecera clásica (Header)',
			'description' => 'La barra de navegación principal. Podés configurar si queda fija (sticky), colores, posiciones del logo y buscador, y qué botones y accesos se muestran.',
			'icon'        => 'editor-table',
			'keywords'    => ['cabecera', 'header', 'menu', 'navegacion', 'sticky', 'logo', 'botones'],
			'fields'      => [
				'sticky'              => $select('Comportamiento (sticky)', [
					'sticky'         => 'Fijo siempre (arriba al scrollear)',
					'static'         => 'Estático (se va con el scroll)',
					'sticky-desktop' => 'Fijo solo en la compu',
					'sticky-mobile'  => 'Fijo solo en el celular',
				], 'sticky'),
				'headerStyle'         => $select('Fondo de la barra', [
					'glass'       => 'Vidrio translúcido (con desenfoque)',
					'solid'       => 'Sólido',
					'transparent' => 'Transparente',
				], 'glass'),
				'border'              => $select('Borde inferior', [
					'line'   => 'Línea sutil',
					'none'   => 'Sin borde',
					'shadow' => 'Sombra suave',
				], 'line'),
				'logoPosition'        => $select('Posición del logo', [
					'left'   => 'A la izquierda',
					'center' => 'Al centro',
				], 'left'),
				'searchPosition'      => $select('Buscador', [
					'center' => 'Al centro (computadora)',
					'right'  => 'A la derecha (junto a los botones)',
					'hidden' => 'Oculto',
				], 'center'),
				'menuPosition'        => $select('Menú de navegación', [
					'below'  => 'Debajo de la barra (Mega menú)',
					'inline' => 'En la misma fila (al lado del logo)',
					'drawer' => 'Solo menú lateral (botón ☰ siempre)',
					'hidden' => 'Sin menú',
				], 'below'),
				'showTopBar'          => $toggle('Mostrar barra de anuncios', true),
				'showFreeShipping'    => $toggle('Mostrar barra de envío gratis', true),
				'showCart'            => $toggle('Mostrar botón de carrito', true),
				'showWishlist'        => $toggle('Mostrar botón de favoritos', true),
				'showCompare'         => $toggle('Mostrar botón de comparar', true),
				'showAccount'         => $toggle('Mostrar botón de mi cuenta / acceso', true),
				'showThemeToggle'     => $toggle('Mostrar selector día/noche (sol/luna)', true),
				'showExchangeRate'    => $toggle('Mostrar cotización del dólar (MJ Dólar)', true),
				'showPaymentSelector' => $toggle('Mostrar selector de medios de pago (MJ Medios de Pago)', true),
				'showTurnosCta'       => $toggle('Mostrar botón de reserva (MJ Turnos Online)', true),
				'ctaText'             => $text('Texto del botón de reserva', 'Reservar turno'),
				'ctaUrl'              => ['label' => 'Destino del botón de reserva', 'type' => 'url', 'default' => '', 'placeholder' => '/turnos'],
				'customButtonText'    => $text('Botón adicional: texto', 'Ej: Contacto o WhatsApp'),
				'customButtonUrl'     => ['label' => 'Botón adicional: destino', 'type' => 'url', 'default' => '', 'placeholder' => 'https://...'],
				'customButtonIcon'    => $text('Botón adicional: ícono de Lucide', 'phone, arrow-up-right, message-circle...'),
				'customButtonTarget'  => $select('Botón adicional: abrir en', ['_self' => 'Misma pestaña', '_blank' => 'Nueva pestaña'], '_self'),
				'bgColor'             => $color('Color de fondo personalizado', '#101014 o vacío'),
				'inkColor'            => $color('Color de texto e íconos', '#ffffff o vacío'),
				'accentColor'         => $color('Color de acento', '#6e6bff o vacío'),
			],
			'summary'     => ['sticky', 'logoPosition', 'headerStyle'],
		],

		'footer' => [
			'title'       => 'Pie de página clásico (Footer)',
			'description' => 'El pie de página con newsletter, columnas de enlaces configurables, logo, datos de contacto, redes sociales, copyright y sellos de medios de pago.',
			'icon'        => 'editor-insertmore',
			'keywords'    => ['pie', 'footer', 'columnas', 'newsletter', 'redes', 'legal', 'contacto', 'links'],
			'fields'      => [
				'showNewsletter'   => $toggle('Mostrar sección de newsletter', true),
				'newsletterTitle'  => $text('Newsletter: título', 'Lo nuevo, antes que nadie.'),
				'newsletterText'   => $textarea('Newsletter: texto', 'Lanzamientos, ofertas exclusivas y novedades. Sin spam, prometido.'),
				'newsletterButton' => $text('Newsletter: texto del botón', 'Suscribirme'),
				'columnsCount'     => $select('Cantidad de columnas (computadora)', ['2' => '2 columnas', '3' => '3 columnas', '4' => '4 columnas', '5' => '5 columnas'], '4'),
				'showLogo'         => $toggle('Mostrar columna de logo y presentación', true),
				'tagline'          => $textarea('Frase o descripción bajo el logo', 'Vacío = la descripción corta del sitio.'),
				'showContact'      => $toggle('Mostrar datos de contacto (dirección, teléfono, email)', true),
				'showSocials'      => $toggle('Mostrar botones de redes sociales', true),
				'columns'          => [
					'label'   => 'Columnas de enlaces personalizadas',
					'type'    => 'repeater',
					'default' => [],
					'help'    => 'Vacío = usa las columnas automáticas de la tienda (tienda, ayuda, cuenta). Cada fila es una columna.',
					'fields'  => [
						'title' => ['label' => 'Título de columna', 'type' => 'text', 'default' => ''],
						'links' => ['label' => 'Enlaces ("Texto | /ruta | _blank" por línea)', 'type' => 'textarea', 'default' => ''],
					],
				],
				'showLegalBar'     => $toggle('Mostrar barra inferior legal', true),
				'copyright'        => $text('Texto de copyright', 'Vacío = automático con año y nombre del sitio.'),
				'showPayments'     => $toggle('Mostrar sellos de medios de pago', true),
				'border'           => $select('Borde superior', ['line' => 'Línea sutil', 'none' => 'Sin borde'], 'line'),
				'bgColor'          => $color('Color de fondo personalizado', '#0a0a0c o vacío'),
				'inkColor'         => $color('Color de texto', '#f5f5f7 o vacío'),
				'accentColor'      => $color('Color de títulos y destacados', '#6e6bff o vacío'),
			],
			'summary'     => ['columnsCount', 'showNewsletter', 'showLogo'],
		],

		/* -----------------------------------------------------------------
		 * Bloques modulares nativos para Partes de Plantilla (Header y Footer FSE)
		 * --------------------------------------------------------------- */
		'logo' => [
			'title'       => 'Logo de la tienda',
			'description' => 'El logo de la tienda o sitio con enlace a la portada y dimensiones personalizables.',
			'icon'        => 'store',
			'keywords'    => ['logo', 'marca', 'cabecera', 'tienda'],
			'fields'      => [
				'customLogo'     => ['label' => 'Logo personalizado (opcional)', 'type' => 'image', 'default' => null, 'help' => 'Dejá vacío para usar el logo configurado en Apariencia → MJ Appearance o en el Personalizador.'],
				'showSiteName'   => $toggle('Mostrar nombre de la tienda junto al logo', true, 'Si no hay imagen de logo, el nombre se muestra siempre.'),
				'logoWidth'      => $select('Ancho del logo', [
					'100px' => 'Chico (100px)',
					'130px' => 'Estándar (130px)',
					'160px' => 'Mediano (160px)',
					'200px' => 'Grande (200px)',
					'250px' => 'Muy grande (250px)',
					'auto'  => 'Automático',
				], '130px'),
				'logoHeight'     => $select('Alto máximo del logo', [
					'32px' => 'Bajo (32px)',
					'40px' => 'Medio (40px)',
					'50px' => 'Alto (50px)',
					'64px' => 'Muy alto (64px)',
					'none' => 'Sin límite',
				], '40px'),
				'siteNameSize'   => $select('Tamaño de letra del nombre', [
					'14px' => 'Chico (14px)',
					'16px' => 'Normal (16px)',
					'18px' => 'Mediano (18px)',
					'20px' => 'Grande (20px)',
					'24px' => 'Muy grande (24px)',
				], '18px'),
				'siteNameColor'  => $color('Color de texto del nombre', '#ffffff o vacío'),
			],
			'summary'     => ['logoWidth'],
		],

		'nav' => [
			'title'       => 'Menú de navegación',
			'description' => 'El menú de categorías, páginas y enlaces personalizados de la tienda (en línea o mega menú). Podés personalizar contenido, colores, hover, tamaños y tipografía.',
			'icon'        => 'menu',
			'keywords'    => ['menu', 'navegacion', 'categorias', 'paginas', 'links', 'megamenu'],
			'fields'      => [
				'style'              => $select('Disposición', [
					'inline'   => 'En línea (junto a otros bloques)',
					'megamenu' => 'Barra completa (Mega menú desplegable)',
				], 'inline'),
				'source'             => $select('Origen de los enlaces', [
					'auto'   => 'Automático (categorías y páginas de la tienda)',
					'custom' => 'Personalizado (definir enlaces propios)',
				], 'auto'),
				'customLinks'        => $textarea('Enlaces personalizados ("Texto | /ruta | _blank" por línea)', "Inicio | /\nTienda | /tienda\nOfertas | /tienda?filtro=ofertas\nContacto | /contacto"),
				'showCategories'     => $toggle('Incluir categorías de productos', true),
				'justify'            => $select('Alineación horizontal', [
					'center'        => 'Al centro',
					'flex-start'    => 'A la izquierda',
					'flex-end'      => 'A la derecha',
					'space-between' => 'Distribuido',
				], 'center'),
				'fontSize'           => $select('Tamaño de letra', [
					'12px' => 'Muy chica (12px)',
					'13px' => 'Chica (13px - estándar)',
					'14px' => 'Normal (14px)',
					'15px' => 'Mediana (15px)',
					'16px' => 'Grande (16px)',
					'18px' => 'Muy grande (18px)',
				], '13px'),
				'fontWeight'         => $select('Grosor de letra', [
					'400' => 'Normal (400)',
					'500' => 'Medio (500)',
					'600' => 'Seminegrita (600)',
					'700' => 'Negrita (700)',
				], '500'),
				'textTransform'      => $select('Mayúsculas / Minúsculas', [
					'none'       => 'Normal (tal como se escribe)',
					'uppercase'  => 'MAYÚSCULAS',
					'capitalize' => 'Primera En Mayúscula',
					'lowercase'  => 'minúsculas',
				], 'none'),
				'letterSpacing'      => $select('Espaciado entre letras', [
					'normal' => 'Normal',
					'0.05em' => 'Espaciado sutil (0.05em)',
					'0.1em'  => 'Espaciado amplio (0.1em)',
					'0.15em' => 'Espaciado muy amplio (0.15em)',
				], 'normal'),
				'itemGap'            => $select('Separación entre enlaces', [
					'4px'  => 'Compacta (4px)',
					'8px'  => 'Normal (8px)',
					'12px' => 'Media (12px)',
					'16px' => 'Separada (16px)',
					'24px' => 'Muy separada (24px)',
				], '8px'),
				'itemPadding'        => $select('Relleno (padding) de cada enlace', [
					'4px 8px'   => 'Mínimo (4px 8px)',
					'6px 12px'  => 'Compacto (6px 12px)',
					'8px 16px'  => 'Normal (8px 16px)',
					'10px 20px' => 'Amplio (10px 20px)',
					'12px 24px' => 'Grande (12px 24px)',
				], '8px 16px'),
				'borderRadius'       => $select('Redondez de esquinas al pasar el mouse', [
					'9999px' => 'Píldora redondeada completa',
					'12px'   => 'Esquinas redondeadas (12px)',
					'8px'    => 'Esquinas suaves (8px)',
					'4px'    => 'Esquinas sutiles (4px)',
					'0px'    => 'Sin redondez (recto)',
				], '9999px'),
				'textColor'          => $color('Color del texto de los enlaces', '#ffffff o vacío'),
				'hoverColor'         => $color('Color del texto en hover (al pasar el mouse)', '#6e6bff o vacío'),
				'bgHoverColor'       => $color('Color de fondo en hover', 'rgba(255,255,255,0.08) o vacío'),
				'activeColor'        => $color('Color del enlace activo / página actual', '#6e6bff o vacío'),
				'dropdownBg'         => $color('Fondo del panel desplegable (Mega Menú)', '#121216 o vacío'),
				'dropdownTextColor'  => $color('Color del texto en el desplegable', '#ffffff o vacío'),
				'dropdownHoverColor' => $color('Color hover en el desplegable', '#6e6bff o vacío'),
			],
			'summary'     => ['style', 'source', 'fontSize'],
		],

		'search' => [
			'title'       => 'Buscador de productos',
			'description' => 'Barra de búsqueda en tiempo real con atajos y resultados inmediatos.',
			'icon'        => 'search',
			'keywords'    => ['buscador', 'buscar', 'productos', 'search'],
			'fields'      => [
				'maxWidth'         => [
					'label'   => 'Ancho del buscador',
					'type'    => 'width_slider',
					'default' => '360px',
					'min'     => 180,
					'max'     => 1200,
					'step'    => 10,
					'help'    => 'Movelo libremente con el deslizador o tildá el 100% para ocupar todo el ancho disponible.',
				],
				'height'           => $select('Altura del buscador', [
					'36px' => 'Compacto (36px)',
					'40px' => 'Estándar (40px)',
					'44px' => 'Cómodo (44px)',
					'48px' => 'Grande (48px)',
				], '40px'),
				'placeholder'      => $text('Texto sugerido (placeholder)', 'Buscar productos…'),
				'style'            => $select('Estilo de bordes', [
					'pill'    => 'Píldora redondeada',
					'rounded' => 'Esquinas suaves (8px)',
					'square'  => 'Esquinas rectas (0px)',
				], 'pill'),
				'bgColor'          => $color('Color de fondo', '#1c1c22 o vacío'),
				'textColor'        => $color('Color del texto ingresado', '#ffffff o vacío'),
				'placeholderColor' => $color('Color del texto sugerido', '#888888 o vacío'),
				'borderColor'      => $color('Color del borde', '#2e2e38 o vacío'),
				'iconColor'        => $color('Color del ícono de lupa', '#888888 o vacío'),
				'fontSize'         => $select('Tamaño de letra', [
					'12px' => '12px',
					'13px' => '13px',
					'14px' => '14px (estándar)',
					'15px' => '15px',
				], '14px'),
			],
			'summary'     => ['maxWidth', 'placeholder'],
		],

		'carrito' => [
			'title'       => 'Botón de carrito',
			'description' => 'Acceso al carrito de WooCommerce con badge de cantidad y apertura de panel lateral.',
			'icon'        => 'cart',
			'keywords'    => ['carrito', 'cart', 'compras', 'bolsa'],
			'fields'      => [
				'showCount'   => $toggle('Mostrar contador de productos', true),
				'showLabel'   => $toggle('Mostrar texto al lado del ícono', false),
				'label'       => $text('Texto del botón', 'Carrito'),
				'style'       => $select('Estilo del botón', [
					'icon'   => 'Solo ícono',
					'pill'   => 'Píldora con fondo y borde',
					'button' => 'Botón sólido',
				], 'icon'),
				'iconSize'    => $select('Tamaño del ícono', [
					'16px' => 'Chico (16px)',
					'18px' => 'Medio chico (18px)',
					'20px' => 'Estándar (20px)',
					'24px' => 'Grande (24px)',
				], '20px'),
				'iconColor'   => $color('Color del ícono / texto', '#ffffff o vacío'),
				'hoverColor'  => $color('Color en hover', '#6e6bff o vacío'),
				'bgColor'     => $color('Color de fondo (estilo píldora o botón)', 'rgba(255,255,255,0.06) o vacío'),
				'badgeBg'     => $color('Color de fondo del globo contador', '#6e6bff o vacío'),
				'badgeColor'  => $color('Color del número del contador', '#ffffff o vacío'),
			],
			'summary'     => ['showCount', 'showLabel'],
		],

		'favoritos' => [
			'title'       => 'Botón de favoritos',
			'description' => 'Acceso a la lista de deseos (wishlist) con contador.',
			'icon'        => 'heart',
			'keywords'    => ['favoritos', 'wishlist', 'deseos', 'corazon'],
			'fields'      => [
				'showCount'   => $toggle('Mostrar contador', true),
				'showLabel'   => $toggle('Mostrar texto al lado del ícono', false),
				'label'       => $text('Texto del botón', 'Favoritos'),
				'iconSize'    => $select('Tamaño del ícono', ['16px' => '16px', '18px' => '18px', '20px' => '20px', '24px' => '24px'], '20px'),
				'iconColor'   => $color('Color del ícono / texto', '#ffffff o vacío'),
				'hoverColor'  => $color('Color en hover', '#6e6bff o vacío'),
				'badgeBg'     => $color('Color de fondo del globo contador', '#6e6bff o vacío'),
				'badgeColor'  => $color('Color del número', '#ffffff o vacío'),
			],
			'summary'     => ['showCount'],
		],

		'comparador' => [
			'title'       => 'Botón de comparador',
			'description' => 'Acceso al comparador de productos con contador.',
			'icon'        => 'randomize',
			'keywords'    => ['comparar', 'comparador', 'productos'],
			'fields'      => [
				'showCount'   => $toggle('Mostrar contador', true),
				'showLabel'   => $toggle('Mostrar texto al lado del ícono', false),
				'label'       => $text('Texto del botón', 'Comparar'),
				'iconSize'    => $select('Tamaño del ícono', ['16px' => '16px', '18px' => '18px', '20px' => '20px', '24px' => '24px'], '20px'),
				'iconColor'   => $color('Color del ícono / texto', '#ffffff o vacío'),
				'hoverColor'  => $color('Color en hover', '#6e6bff o vacío'),
				'badgeBg'     => $color('Color de fondo del globo contador', '#6e6bff o vacío'),
				'badgeColor'  => $color('Color del número', '#ffffff o vacío'),
			],
			'summary'     => ['showCount'],
		],

		'cuenta' => [
			'title'       => 'Botón de mi cuenta',
			'description' => 'Acceso a Mi Cuenta / Iniciar sesión del cliente.',
			'icon'        => 'admin-users',
			'keywords'    => ['cuenta', 'login', 'usuario', 'perfil'],
			'fields'      => [
				'showIcon'   => $toggle('Mostrar ícono de usuario', true),
				'showLabel'  => $toggle('Mostrar texto al lado del ícono', false),
				'label'      => $text('Texto del botón', 'Mi cuenta'),
				'iconSize'   => $select('Tamaño del ícono', ['16px' => '16px', '18px' => '18px', '20px' => '20px', '24px' => '24px'], '20px'),
				'iconColor'  => $color('Color del ícono / texto', '#ffffff o vacío'),
				'hoverColor' => $color('Color en hover', '#6e6bff o vacío'),
			],
			'summary'     => ['showLabel'],
		],

		'tema-toggle' => [
			'title'       => 'Selector día / noche',
			'description' => 'Botón para alternar entre modo claro y modo oscuro.',
			'icon'        => 'lightbulb',
			'keywords'    => ['tema', 'modo', 'oscuro', 'claro', 'sol', 'luna'],
			'fields'      => [
				'showIcon'   => $toggle('Mostrar ícono', true),
				'showLabel'  => $toggle('Mostrar texto al lado del ícono', false),
				'label'      => $text('Texto del botón', 'Modo'),
				'iconSize'   => $select('Tamaño del ícono', ['16px' => '16px', '18px' => '18px', '20px' => '20px', '24px' => '24px'], '20px'),
				'iconColor'  => $color('Color del ícono', '#ffffff o vacío'),
				'hoverColor' => $color('Color en hover', '#6e6bff o vacío'),
			],
			'summary'     => ['showLabel'],
		],

		'dolar' => [
			'title'       => 'Cotización del dólar',
			'description' => 'Píldora con la cotización en vivo de la tienda (plugin MJ Dólar).',
			'icon'        => 'money-alt',
			'keywords'    => ['dolar', 'cotizacion', 'divisa', 'usd'],
			'fields'      => [
				'compact'     => $toggle('Formato compacto (solo USD y precio)', true),
				'bgColor'     => $color('Color de fondo', '#1c1c22 o vacío'),
				'textColor'   => $color('Color del texto', '#ffffff o vacío'),
				'borderColor' => $color('Color del borde', '#2e2e38 o vacío'),
				'fontSize'    => $select('Tamaño de letra', ['12px' => '12px', '13px' => '13px', '14px' => '14px'], '13px'),
			],
			'summary'     => ['compact'],
		],

		'medios-pago' => [
			'title'       => 'Medios de pago',
			'description' => 'Selector de medios de pago o sellos de tarjetas aceptadas.',
			'icon'        => 'money',
			'keywords'    => ['pagos', 'medios', 'tarjetas', 'cuotas'],
			'fields'      => [
				'variant'     => $select('Variante', ['selector' => 'Selector de medios de pago', 'badges' => 'Sellos de tarjetas y medios'], 'selector'),
				'bgColor'     => $color('Color de fondo', '#1c1c22 o vacío'),
				'textColor'   => $color('Color del texto', '#ffffff o vacío'),
				'borderColor' => $color('Color del borde', '#2e2e38 o vacío'),
				'fontSize'    => $select('Tamaño de letra', ['12px' => '12px', '13px' => '13px', '14px' => '14px'], '13px'),
			],
			'summary'     => ['variant'],
		],

		'anuncios' => [
			'title'       => 'Barra de anuncios (TopBar)',
			'description' => 'Cinta superior con frases y promociones rotativas configuradas en el tema.',
			'icon'        => 'megaphone',
			'keywords'    => ['anuncios', 'topbar', 'promo', 'frases', 'cinta'],
			'fields'      => [
				'autoplay'       => $toggle('Rotación automática', true),
				'customMessages' => $textarea('Frases personalizadas (una por línea; vacío = las del panel)', '⚡ Envíos a todo el país · 3 y 6 cuotas sin interés'),
				'bgColor'        => $color('Color de fondo de la barra', '#000000 o vacío'),
				'textColor'      => $color('Color del texto', '#ffffff o vacío'),
				'fontSize'       => $select('Tamaño de letra', ['11px' => '11px', '12px' => '12px', '13px' => '13px'], '12px'),
				'textAlign'      => $select('Alineación del texto', ['center' => 'Centrado', 'left' => 'A la izquierda', 'right' => 'A la derecha'], 'center'),
			],
			'summary'     => ['autoplay'],
		],

		'envio-gratis' => [
			'title'       => 'Barra de envío gratis',
			'description' => 'Barra que calcula dinámicamente cuánto falta para obtener envío sin cargo.',
			'icon'        => 'car',
			'keywords'    => ['envio', 'gratis', 'shipping', 'progreso'],
			'fields'      => [
				'showProgress' => $toggle('Mostrar barra de progreso', true),
				'message'      => $text('Mensaje personalizado (vacío = automático)', 'Envío gratis superando el mínimo'),
				'bgColor'      => $color('Color de fondo', '#18181c o vacío'),
				'textColor'    => $color('Color de texto', '#ffffff o vacío'),
				'barColor'     => $color('Color de la barra de progreso', '#22c55e o vacío'),
				'fontSize'     => $select('Tamaño de letra', ['12px' => '12px', '13px' => '13px', '14px' => '14px'], '13px'),
			],
			'summary'     => ['showProgress'],
		],

		'acciones-header' => [
			'title'       => 'Acciones de cabecera',
			'description' => 'Grupo de accesos rápidos: carrito, favoritos, comparador, cuenta, día/noche y cotización.',
			'icon'        => 'align-right',
			'keywords'    => ['acciones', 'header', 'botones', 'accesos'],
			'fields'      => [
				'showCart'          => $toggle('Mostrar carrito', true),
				'showWishlist'      => $toggle('Mostrar favoritos', true),
				'showCompare'       => $toggle('Mostrar comparador', true),
				'showAccount'       => $toggle('Mostrar mi cuenta', true),
				'showThemeToggle'   => $toggle('Mostrar selector día/noche', true),
				'showDollarRate'    => $toggle('Mostrar cotización dólar', true),
				'showPaymentSelect' => $toggle('Mostrar selector de pagos', true),
				'iconSize'          => $select('Tamaño de los íconos', ['16px' => '16px', '18px' => '18px', '20px' => '20px', '24px' => '24px'], '20px'),
				'iconColor'         => $color('Color de los íconos', '#ffffff o vacío'),
				'hoverColor'        => $color('Color en hover', '#6e6bff o vacío'),
			],
			'summary'     => ['showCart', 'showAccount'],
		],

		'cta-button' => [
			'title'       => 'Botón de llamada a la acción (CTA)',
			'description' => 'Botón personalizado con enlace, texto, variante e ícono.',
			'icon'        => 'button',
			'keywords'    => ['boton', 'cta', 'enlace', 'accion'],
			'fields'      => [
				'label'        => $text('Texto del botón', 'Ver más'),
				'url'          => ['label' => 'Destino del enlace', 'type' => 'url', 'default' => '/tienda', 'placeholder' => '/tienda'],
				'variant'      => $select('Estilo', ['primary' => 'Principal', 'secondary' => 'Secundario', 'outline' => 'Borde', 'custom' => 'Colores propios'], 'secondary'),
				'bgColor'      => $color('Color de fondo (si estilo es propio)', '#6e6bff'),
				'textColor'    => $color('Color de texto (si estilo es propio)', '#ffffff'),
				'hoverBg'      => $color('Color de fondo al pasar el mouse', '#5653e0'),
				'hoverColor'   => $color('Color de texto al pasar el mouse', '#ffffff'),
				'fontSize'     => $select('Tamaño de letra', ['12px' => '12px', '13px' => '13px', '14px' => '14px', '16px' => '16px'], '14px'),
				'borderRadius' => $select('Redondez', ['9999px' => 'Píldora redondeada', '8px' => 'Suave (8px)', '4px' => 'Sutil (4px)', '0px' => 'Recto'], '9999px'),
				'icon'         => $text('Ícono Lucide (opcional)', 'arrow-up-right'),
				'target'       => $select('Abrir en', ['_self' => 'Misma pestaña', '_blank' => 'Nueva pestaña'], '_self'),
			],
			'summary'     => ['label', 'url'],
		],

		'newsletter' => [
			'title'       => 'Suscripción a novedades (Newsletter)',
			'description' => 'Formulario de captura de email para novedades y ofertas.',
			'icon'        => 'email-alt',
			'keywords'    => ['newsletter', 'suscripcion', 'email', 'novedades'],
			'fields'      => [
				'title'           => $text('Título', 'Lo nuevo, antes que nadie.'),
				'subtitle'        => $textarea('Bajada', 'Lanzamientos, ofertas exclusivas y novedades.'),
				'buttonText'      => $text('Texto del botón', 'Suscribirme'),
				'bgColor'         => $color('Color de fondo', '#121216 o vacío'),
				'textColor'       => $color('Color del texto', '#ffffff o vacío'),
				'buttonBg'        => $color('Color del botón', '#6e6bff o vacío'),
				'buttonTextColor' => $color('Color del texto del botón', '#ffffff o vacío'),
			],
			'summary'     => ['title'],
		],

		'footer-brand' => [
			'title'       => 'Marca del pie de página',
			'description' => 'Logo de la tienda, eslogan o descripción breve y redes sociales.',
			'icon'        => 'id',
			'keywords'    => ['marca', 'footer', 'logo', 'eslogan', 'pie'],
			'fields'      => [
				'tagline'      => $textarea('Frase o descripción', 'Vacío = la descripción corta del sitio.'),
				'showSocials'  => $toggle('Mostrar botones de redes sociales', true),
				'textColor'    => $color('Color del nombre / logo', '#ffffff o vacío'),
				'taglineColor' => $color('Color de la descripción', '#888888 o vacío'),
			],
			'summary'     => ['showSocials'],
		],

		'footer-links' => [
			'title'       => 'Columna de enlaces (Footer)',
			'description' => 'Columna con título y lista de enlaces personalizados.',
			'icon'        => 'admin-links',
			'keywords'    => ['enlaces', 'links', 'columna', 'footer'],
			'fields'      => [
				'title'      => $text('Título de columna', 'Enlaces'),
				'links'      => $textarea('Enlaces ("Texto | /ruta | _blank" por línea)'),
				'titleColor' => $color('Color del título', '#ffffff o vacío'),
				'linksColor' => $color('Color de los enlaces', '#888888 o vacío'),
				'hoverColor' => $color('Color al pasar el mouse', '#6e6bff o vacío'),
				'fontSize'   => $select('Tamaño de letra', ['12px' => '12px', '13px' => '13px', '14px' => '14px'], '13px'),
			],
			'summary'     => ['title'],
		],

		'redes' => [
			'title'       => 'Redes sociales',
			'description' => 'Botones con enlaces a los perfiles de redes configurados en Contacto y redes.',
			'icon'        => 'share',
			'keywords'    => ['redes', 'social', 'instagram', 'facebook', 'whatsapp', 'tiktok'],
			'fields'      => [
				'showInstagram' => $toggle('Mostrar Instagram', true),
				'showFacebook'  => $toggle('Mostrar Facebook', true),
				'showWhatsapp'  => $toggle('Mostrar WhatsApp', true),
				'showTiktok'    => $toggle('Mostrar TikTok', true),
				'iconSize'      => $select('Tamaño de íconos', ['18px' => '18px', '20px' => '20px', '24px' => '24px', '28px' => '28px'], '20px'),
				'iconColor'     => $color('Color de los íconos', '#888888 o vacío'),
				'hoverColor'    => $color('Color al pasar el mouse', '#6e6bff o vacío'),
				'gap'           => $select('Separación', ['8px' => '8px', '12px' => '12px', '16px' => '16px', '20px' => '20px'], '12px'),
			],
			'summary'     => [],
		],

		'footer-legal' => [
			'title'       => 'Barra legal y copyright',
			'description' => 'Texto de copyright con año y sellos de medios de pago.',
			'icon'        => 'shield',
			'keywords'    => ['legal', 'copyright', 'derechos', 'pagos'],
			'fields'      => [
				'copyright'    => $text('Texto de copyright', 'Vacío = automático con año y nombre.'),
				'showPayments' => $toggle('Mostrar sellos de medios de pago', true),
				'textColor'    => $color('Color del texto', '#888888 o vacío'),
				'fontSize'     => $select('Tamaño de letra', ['11px' => '11px', '12px' => '12px', '13px' => '13px'], '12px'),
			],
			'summary'     => ['showPayments'],
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
		if ('' === $key || !is_array($def) || !isset($def['fields']) || !is_array($def['fields'])) {
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
			'supports'        => [
				'html'            => false,
				'align'           => ['left', 'center', 'right', 'wide', 'full'],
				'customClassName' => true,
				'color'           => [
					'text'       => true,
					'background' => true,
					'link'       => true,
				],
				'typography'      => [
					'fontSize'   => true,
					'lineHeight' => true,
				],
				'spacing'         => [
					'margin'  => true,
					'padding' => true,
				],
			],
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
		return is_array($value) && !empty($value['url']) ? esc_url_raw($value['url']) : (is_string($value) ? esc_url_raw($value) : '');
	}
	$url = wp_get_attachment_image_url($id, 'large');
	return $url ? (string) $url : '';
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
		case 'color':
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
			$url = mj_block_image_url($value);
			if ('' === $url) {
				return '';
			}
			$focal = is_array($value) && isset($value['focal']) && function_exists('mj_sanitize_focal_point') ? mj_sanitize_focal_point($value['focal']) : null;
			if (!$focal && is_array($value) && isset($value['id']) && function_exists('mj_get_image_focal_point')) {
				$focal = mj_get_image_focal_point(absint($value['id']));
			}
			return $focal ? ['url' => $url, 'focal' => $focal] : $url;
		case 'images':
			$urls = [];
			foreach ((array) $value as $item) {
				$url = mj_block_image_url($item);
				if ('' !== $url) {
					$focal = is_array($item) && isset($item['focal']) && function_exists('mj_sanitize_focal_point') ? mj_sanitize_focal_point($item['focal']) : null;
					if (!$focal && is_array($item) && isset($item['id']) && function_exists('mj_get_image_focal_point')) {
						$focal = mj_get_image_focal_point(absint($item['id']));
					}
					$urls[] = $focal ? ['url' => $url, 'focal' => $focal] : $url;
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

/* En el bloque footer, parsea las líneas de enlaces de cada columna a arrays [texto, url, target]. */
add_filter('mj_block_props', function ($props, $key) {
	if ('footer' === $key && !empty($props['columns']) && is_array($props['columns'])) {
		foreach ($props['columns'] as &$col) {
			if (isset($col['links']) && is_string($col['links'])) {
				$lines = preg_split('/\r\n|\r|\n/', trim($col['links']));
				$parsed = [];
				foreach ($lines as $line) {
					$line = trim($line);
					if ('' === $line) {
						continue;
					}
					$parts = array_map('trim', explode('|', $line));
					if (count($parts) >= 2 && '' !== $parts[0] && '' !== $parts[1]) {
						$target = isset($parts[2]) && in_array(trim($parts[2]), ['_blank', 'blank'], true) ? '_blank' : '_self';
						$url = $parts[1];
						$parsed[] = [
							$parts[0],
							0 === strpos($url, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) ? esc_url_raw($url) : '/' . ltrim(sanitize_text_field($url), '/'),
							$target,
						];
					} elseif (count($parts) === 1 && '' !== $parts[0]) {
						$parsed[] = [$parts[0], '#', '_self'];
					}
				}
				$col['links'] = $parsed;
			}
		}
	}
	return $props;
}, 10, 2);

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

/**
 * Obtiene el HTML renderizado de una parte de plantilla de bloques (Full Site Editing).
 *
 * Consulta primero si WordPress tiene una versión personalizada en base de datos
 * (post type wp_template_part) o vía block_template_part(). Si no hay personalización,
 * recurre al archivo parts/{slug}.html del tema y ejecuta do_blocks().
 *
 * @param string $slug 'header' | 'footer'
 * @return string HTML de la parte de plantilla
 */
function mj_get_block_template_part_html($slug) {
	$slug = sanitize_file_name((string) $slug);
	if ('' === $slug) {
		return '';
	}

	// 1. Si existe la función nativa de WordPress y estamos en un contexto con soporte:
	if (function_exists('block_template_part')) {
		ob_start();
		block_template_part($slug);
		$html = trim((string) ob_get_clean());
		if ('' !== $html) {
			return $html;
		}
	}

	// 2. Si el usuario guardó una versión personalizada en el Editor de Sitios (wp_template_part):
	if (post_type_exists('wp_template_part')) {
		$theme_slug = get_stylesheet();
		$custom = get_posts([
			'post_type'      => 'wp_template_part',
			'post_status'    => ['publish', 'auto-draft'],
			'name'           => $slug,
			'tax_query'      => [
				[
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => $theme_slug,
				],
			],
			'posts_per_page' => 1,
		]);
		if (!empty($custom) && !empty($custom[0]->post_content)) {
			return function_exists('do_blocks') ? do_blocks($custom[0]->post_content) : $custom[0]->post_content;
		}
	}

	// 3. Fallback al archivo parts/{slug}.html del tema:
	$file = get_template_directory() . '/parts/' . $slug . '.html';
	if (file_exists($file)) {
		$content = file_get_contents($file);
		if (false !== $content && '' !== trim($content)) {
			return function_exists('do_blocks') ? do_blocks($content) : $content;
		}
	}

	return '';
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
			'--mj-ink-soft'    => 'rgb(245 245 247 / 0.78)',
			'--mj-ink-faint'   => 'rgb(245 245 247 / 0.58)',
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
			'--mj-ink-soft'    => 'rgb(10 10 12 / 0.78)',
			'--mj-ink-faint'   => 'rgb(10 10 12 / 0.58)',
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
		'logo'        => function_exists('mj_logo_config') ? mj_logo_config() : null,
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
