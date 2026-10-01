<?php
/**
 * Constructor de Cabecera y Pie de Página (Header & Footer Builder).
 *
 * Sistema 100% modular y desacoplado basado en bloques y componentes:
 *   - Catálogo de bloques registrados y extensibles con filtros de WordPress.
 *   - Adaptador de migración automática desde las opciones legadas (legacy fallback).
 *   - Sanitización y validación recursiva del esquema JSON.
 *   - Endpoints REST para el guardado y consulta del layout.
 *
 * @package MJ_Appearance
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * 1. Catálogo de Bloques Registrados
 * ---------------------------------------------------------------------- */

/**
 * Bloques disponibles para la cabecera (Header).
 *
 * @return array
 */
function mj_registered_header_blocks() {
	static $blocks = null;
	if (null !== $blocks) {
		return $blocks;
	}

	$blocks = [
		'mj-logo' => [
			'id'          => 'mj-logo',
			'name'        => 'Logo de la tienda',
			'category'    => 'Identidad',
			'icon'        => 'format-image',
			'description' => 'Muestra el logo del sitio o la inicial con nombre.',
			'defaultSettings' => [
				'maxWidth'    => '',
				'maxHeight'   => '',
				'showSiteName'=> true,
				'alignment'   => 'left',
			],
		],
		'mj-nav' => [
			'id'          => 'mj-nav',
			'name'        => 'Menú de navegación',
			'category'    => 'Navegación',
			'icon'        => 'menu',
			'description' => 'Enlaces principales configurados en Navegación.',
			'defaultSettings' => [
				'style'       => 'inline', // inline | dropdown
				'fontSize'    => '',
				'gap'         => '',
			],
		],
		'mj-megamenu' => [
			'id'          => 'mj-megamenu',
			'name'        => 'Mega Menú',
			'category'    => 'Navegación',
			'icon'        => 'category',
			'description' => 'Barra desplegable con categorías del catálogo y enlaces.',
			'defaultSettings' => [
				'inline'      => false,
			],
		],
		'mj-search' => [
			'id'          => 'mj-search',
			'name'        => 'Barra de búsqueda',
			'category'    => 'Navegación',
			'icon'        => 'search',
			'description' => 'Buscador de productos en tiempo real.',
			'defaultSettings' => [
				'variant'     => 'input', // input | modal-trigger
				'placeholder' => '',
				'maxWidth'    => '360px',
			],
		],
		'mj-cart' => [
			'id'          => 'mj-cart',
			'name'        => 'Carrito de compras',
			'category'    => 'Comercio',
			'icon'        => 'cart',
			'description' => 'Ícono de carrito con badge de contador y apertura del drawer.',
			'defaultSettings' => [
				'showCount'   => true,
				'iconSize'    => '19',
			],
		],
		'mj-wishlist' => [
			'id'          => 'mj-wishlist',
			'name'        => 'Favoritos',
			'category'    => 'Comercio',
			'icon'        => 'heart',
			'description' => 'Acceso a la lista de favoritos con badge contador.',
			'defaultSettings' => [
				'showCount'   => true,
				'iconSize'    => '19',
			],
		],
		'mj-compare' => [
			'id'          => 'mj-compare',
			'name'        => 'Comparador de productos',
			'category'    => 'Comercio',
			'icon'        => 'randomize',
			'description' => 'Acceso a la tabla comparativa con badge contador.',
			'defaultSettings' => [
				'showCount'   => true,
				'iconSize'    => '19',
			],
		],
		'mj-account' => [
			'id'          => 'mj-account',
			'name'        => 'Mi cuenta / Acceso',
			'category'    => 'Usuarios',
			'icon'        => 'admin-users',
			'description' => 'Acceso al perfil de usuario o login/registro.',
			'defaultSettings' => [
				'iconSize'    => '19',
			],
		],
		'mj-theme-toggle' => [
			'id'          => 'mj-theme-toggle',
			'name'        => 'Switch Día / Noche',
			'category'    => 'Utilidades',
			'icon'        => 'visibility',
			'description' => 'Selector de tema claro y oscuro con animación circular.',
			'defaultSettings' => [
				'iconSize'    => '19',
			],
		],
		'mj-dolar' => [
			'id'          => 'mj-dolar',
			'name'        => 'Cotización MJ Dólar',
			'category'    => 'Widgets',
			'icon'        => 'chart-line',
			'description' => 'Cotización del dólar en vivo y selector de tipo de cambio.',
			'defaultSettings' => [
				'compact'     => true,
			],
		],
		'mj-payment-selector' => [
			'id'          => 'mj-payment-selector',
			'name'        => 'Selector de Medios de Pago',
			'category'    => 'Widgets',
			'icon'        => 'money-alt',
			'description' => 'Calculador de precios según método de pago (MJ Medios de Pago).',
			'defaultSettings' => [
				'variant'     => 'pill',
			],
		],
		'mj-cta-button' => [
			'id'          => 'mj-cta-button',
			'name'        => 'Botón personalizado (CTA)',
			'category'    => 'Acciones',
			'icon'        => 'button',
			'description' => 'Botón de llamada a la acción con enlace, ícono y estilo propio.',
			'defaultSettings' => [
				'label'       => 'Ver más',
				'url'         => '#',
				'variant'     => 'secondary', // primary | secondary | outline | ghost
				'size'        => 'sm',        // sm | md | lg
				'icon'        => '',
				'target'      => '_self',     // _self | _blank
			],
		],
		'mj-html' => [
			'id'          => 'mj-html',
			'name'        => 'Texto o HTML libre',
			'category'    => 'Utilidades',
			'icon'        => 'html',
			'description' => 'Bloque libre para avisos breves, teléfonos o código HTML seguro.',
			'defaultSettings' => [
				'content'     => '',
				'fontSize'    => '',
				'textColor'   => '',
			],
		],
		'mj-mobile-trigger' => [
			'id'          => 'mj-mobile-trigger',
			'name'        => 'Disparador Menú Móvil',
			'category'    => 'Navegación',
			'icon'        => 'menu-alt3',
			'description' => 'Botón de hamburguesa ☰ que despliega el menú lateral en móviles.',
			'defaultSettings' => [
				'iconSize'    => '20',
			],
		],
		'mj-topbar' => [
			'id'          => 'mj-topbar',
			'name'        => 'Barra de anuncios rotativa',
			'category'    => 'Avisos',
			'icon'        => 'megaphone',
			'description' => 'Muestra los anuncios configurados en rotación continua.',
			'defaultSettings' => [
				'speed'       => '5000',
			],
		],
		'mj-shipping-bar' => [
			'id'          => 'mj-shipping-bar',
			'name'        => 'Barra de envío gratis',
			'category'    => 'Avisos',
			'icon'        => 'car',
			'description' => 'Aviso interactivo de envío gratis y cálculo del monto faltante.',
			'defaultSettings' => [],
		],
	];

	/**
	 * Filtro para agregar o modificar bloques disponibles en la cabecera.
	 *
	 * @param array $blocks Bloques registrados.
	 */
	return apply_filters('mj_registered_header_blocks', $blocks);
}

/**
 * Bloques disponibles para el pie de página (Footer).
 *
 * @return array
 */
function mj_registered_footer_blocks() {
	static $blocks = null;
	if (null !== $blocks) {
		return $blocks;
	}

	$blocks = [
		'mj-footer-newsletter' => [
			'id'          => 'mj-footer-newsletter',
			'name'        => 'Suscripción Newsletter',
			'category'    => 'Marketing',
			'icon'        => 'email-alt',
			'description' => 'Caja de suscripción por correo con título, bajada y botón.',
			'defaultSettings' => [
				'title'       => 'Lo nuevo, antes que nadie.',
				'subtitle'    => 'Lanzamientos, ofertas exclusivas y novedades. Sin spam, prometido.',
				'buttonText'  => 'Suscribirme',
			],
		],
		'mj-footer-brand' => [
			'id'          => 'mj-footer-brand',
			'name'        => 'Identidad y Eslogan',
			'category'    => 'Identidad',
			'icon'        => 'id-alt',
			'description' => 'Logo del pie con la descripción o eslogan de la tienda.',
			'defaultSettings' => [
				'showLogo'    => true,
				'tagline'     => '',
				'maxWidth'    => '',
			],
		],
		'mj-footer-columns' => [
			'id'          => 'mj-footer-columns',
			'name'        => 'Columnas de enlaces',
			'category'    => 'Navegación',
			'icon'        => 'columns',
			'description' => 'Columnas de enlaces automáticas del catálogo o personalizadas.',
			'defaultSettings' => [
				'columnsCount'=> 4,
				'customColumns'=> '',
			],
		],
		'mj-footer-contact' => [
			'id'          => 'mj-footer-contact',
			'name'        => 'Datos de Contacto',
			'category'    => 'Información',
			'icon'        => 'phone',
			'description' => 'Dirección física, teléfono, WhatsApp y correo electrónico.',
			'defaultSettings' => [
				'showAddress' => true,
				'showPhone'   => true,
				'showEmail'   => true,
			],
		],
		'mj-footer-socials' => [
			'id'          => 'mj-footer-socials',
			'name'        => 'Redes Sociales',
			'category'    => 'Marketing',
			'icon'        => 'share',
			'description' => 'Botones con enlaces a Instagram, Facebook, Twitter, TikTok, YouTube.',
			'defaultSettings' => [
				'style'       => 'icons', // icons | pills
				'size'        => 'md',
			],
		],
		'mj-footer-legal' => [
			'id'          => 'mj-footer-legal',
			'name'        => 'Barra de Copyright y Legales',
			'category'    => 'Legal',
			'icon'        => 'lock',
			'description' => 'Texto de copyright con año dinámico y enlaces a términos y privacidad.',
			'defaultSettings' => [
				'copyright'   => '',
				'showLinks'   => true,
			],
		],
		'mj-footer-payments' => [
			'id'          => 'mj-footer-payments',
			'name'        => 'Sellos de Medios de Pago',
			'category'    => 'Comercio',
			'icon'        => 'cart',
			'description' => 'Sellos e íconos de tarjetas y pasarelas de pago habilitadas.',
			'defaultSettings' => [
				'alignment'   => 'right',
			],
		],
		'mj-cta-button' => [
			'id'          => 'mj-cta-button',
			'name'        => 'Botón personalizado (CTA)',
			'category'    => 'Acciones',
			'icon'        => 'button',
			'description' => 'Botón de llamada a la acción en el pie de página.',
			'defaultSettings' => [
				'label'       => 'Contactar asesor',
				'url'         => '/contacto',
				'variant'     => 'secondary',
				'size'        => 'md',
				'icon'        => '',
				'target'      => '_self',
			],
		],
		'mj-html' => [
			'id'          => 'mj-html',
			'name'        => 'Texto o HTML libre',
			'category'    => 'Utilidades',
			'icon'        => 'html',
			'description' => 'Texto, leyendas de AFIP/Data Fiscal o scripts seguros.',
			'defaultSettings' => [
				'content'     => '',
				'fontSize'    => '',
				'textColor'   => '',
			],
		],
	];

	/**
	 * Filtro para agregar o modificar bloques disponibles en el pie de página.
	 *
	 * @param array $blocks Bloques registrados.
	 */
	return apply_filters('mj_registered_footer_blocks', $blocks);
}

/* -------------------------------------------------------------------------
 * 2. Adaptador de Compatibilidad y Esquemas por Defecto
 * ---------------------------------------------------------------------- */

/**
 * Genera el esquema de cabecera predeterminado a partir de las opciones clásicas.
 *
 * @param array $options Opciones de mj_options.
 * @return array
 */
function mj_legacy_header_to_layout($options = []) {
	if (empty($options)) {
		$options = mj_get_options();
	}

	$logo_pos    = !empty($options['header_logo_pos']) ? (string) $options['header_logo_pos'] : 'left';
	$search_pos  = !empty($options['header_search_pos']) ? (string) $options['header_search_pos'] : 'center';
	$menu_pos    = !empty($options['header_menu_pos']) ? (string) $options['header_menu_pos'] : 'below';
	$sticky_mode = !empty($options['header_sticky']) ? (string) $options['header_sticky'] : 'sticky';
	$style_mode  = !empty($options['header_style']) ? (string) $options['header_style'] : 'glass';
	$border_mode = !empty($options['header_border']) ? (string) $options['header_border'] : 'line';

	$show_topbar   = !isset($options['header_show_topbar']) || !empty($options['header_show_topbar']);
	$show_shipping = !isset($options['header_show_shipping']) || !empty($options['header_show_shipping']);

	$rows_desktop = [];

	// 1. Fila de Anuncios / TopBar si están habilitadas
	if ($show_topbar || $show_shipping) {
		$top_blocks = [];
		if ($show_topbar) {
			$top_blocks[] = [
				'id'       => 'top-announcements',
				'type'     => 'mj-topbar',
				'settings' => [],
			];
		}
		if ($show_shipping) {
			$top_blocks[] = [
				'id'       => 'top-shipping',
				'type'     => 'mj-shipping-bar',
				'settings' => [],
			];
		}

		$rows_desktop[] = [
			'id'      => 'row-announcements',
			'name'    => 'Barra de Anuncios',
			'enabled' => true,
			'sticky'  => false,
			'style'   => [
				'minHeight'    => '36px',
				'bgColor'      => '',
				'textColor'    => '',
				'borderBottom' => 'none',
				'paddingX'     => '1rem',
				'paddingY'     => '0',
			],
			'zones'   => [
				'left'   => [],
				'center' => $top_blocks,
				'right'  => [],
			],
		];
	}

	// 2. Fila Principal (Logo, Buscador, Acciones)
	$main_left   = [];
	$main_center = [];
	$main_right  = [];

	// Logo
	$logo_block = [
		'id'       => 'blk-header-logo',
		'type'     => 'mj-logo',
		'settings' => [
			'maxWidth'     => '180px',
			'showSiteName' => true,
		],
	];

	if ('left' === $logo_pos) {
		$main_left[] = $logo_block;
	} else {
		$main_center[] = $logo_block;
	}

	// Buscador
	if ('hidden' !== $search_pos) {
		$search_block = [
			'id'       => 'blk-header-search',
			'type'     => 'mj-search',
			'settings' => [
				'variant'  => 'input',
				'maxWidth' => '360px',
			],
		];
		if ('center' === $search_pos) {
			if ('left' === $logo_pos) {
				$main_center[] = $search_block;
			} else {
				$main_left[] = $search_block;
			}
		} elseif ('right' === $search_pos) {
			$main_right[] = $search_block;
		}
	}

	// Menú si es inline
	if ('inline' === $menu_pos) {
		$nav_block = [
			'id'       => 'blk-header-nav-inline',
			'type'     => 'mj-nav',
			'settings' => [
				'style' => 'inline',
			],
		];
		if ('left' === $logo_pos && empty($main_center)) {
			$main_center[] = $nav_block;
		} else {
			$main_left[] = $nav_block;
		}
	}

	// Botones de acción a la derecha
	if (!isset($options['header_show_exchange_rate']) || !empty($options['header_show_exchange_rate'])) {
		$main_right[] = ['id' => 'blk-dolar', 'type' => 'mj-dolar', 'settings' => ['compact' => true]];
	}
	if (!isset($options['header_show_payment_selector']) || !empty($options['header_show_payment_selector'])) {
		$main_right[] = ['id' => 'blk-payment', 'type' => 'mj-payment-selector', 'settings' => ['variant' => 'pill']];
	}
	if (!isset($options['header_show_theme_toggle']) || !empty($options['header_show_theme_toggle'])) {
		$main_right[] = ['id' => 'blk-theme', 'type' => 'mj-theme-toggle', 'settings' => []];
	}
	if (!isset($options['header_show_account']) || !empty($options['header_show_account'])) {
		$main_right[] = ['id' => 'blk-account', 'type' => 'mj-account', 'settings' => []];
	}
	if (!isset($options['header_show_wishlist']) || !empty($options['header_show_wishlist'])) {
		$main_right[] = ['id' => 'blk-wishlist', 'type' => 'mj-wishlist', 'settings' => ['showCount' => true]];
	}
	if (!isset($options['header_show_compare']) || !empty($options['header_show_compare'])) {
		$main_right[] = ['id' => 'blk-compare', 'type' => 'mj-compare', 'settings' => ['showCount' => true]];
	}
	if (!isset($options['header_show_cart']) || !empty($options['header_show_cart'])) {
		$main_right[] = ['id' => 'blk-cart', 'type' => 'mj-cart', 'settings' => ['showCount' => true]];
	}

	// Botón CTA de turnos o personalizado
	if (!empty($options['header_custom_btn_text'])) {
		$main_right[] = [
			'id'       => 'blk-custom-cta',
			'type'     => 'mj-cta-button',
			'settings' => [
				'label'   => (string) $options['header_custom_btn_text'],
				'url'     => !empty($options['header_custom_btn_url']) ? (string) $options['header_custom_btn_url'] : '#',
				'icon'    => !empty($options['header_custom_btn_icon']) ? (string) $options['header_custom_btn_icon'] : '',
				'target'  => !empty($options['header_custom_btn_target']) ? (string) $options['header_custom_btn_target'] : '_self',
				'variant' => 'secondary',
			],
		];
	} elseif (!empty($options['header_show_turnos_cta'])) {
		$main_right[] = [
			'id'       => 'blk-turnos-cta',
			'type'     => 'mj-cta-button',
			'settings' => [
				'label'   => !empty($options['header_cta_text']) ? (string) $options['header_cta_text'] : 'Reservar turno',
				'url'     => !empty($options['header_cta_url']) ? (string) $options['header_cta_url'] : '/turnos',
				'variant' => 'primary',
			],
		];
	}

	$rows_desktop[] = [
		'id'      => 'row-header-main',
		'name'    => 'Barra Principal',
		'enabled' => true,
		'sticky'  => in_array($sticky_mode, ['sticky', 'sticky-desktop'], true),
		'style'   => [
			'minHeight'    => '64px',
			'headerStyle'  => $style_mode,
			'borderBottom' => 'none' === $border_mode ? 'none' : '1px solid var(--mj-line-subtle)',
			'bgColor'      => !empty($options['header_bg_color']) ? (string) $options['header_bg_color'] : '',
			'textColor'    => !empty($options['header_ink_color']) ? (string) $options['header_ink_color'] : '',
			'paddingX'     => '1.5rem',
			'paddingY'     => '0',
		],
		'zones'   => [
			'left'   => $main_left,
			'center' => $main_center,
			'right'  => $main_right,
		],
	];

	// 3. Fila de Navegación debajo (Mega Menú) si aplica
	if ('below' === $menu_pos) {
		$rows_desktop[] = [
			'id'      => 'row-header-nav',
			'name'    => 'Barra de Navegación',
			'enabled' => true,
			'sticky'  => false,
			'style'   => [
				'minHeight'    => '44px',
				'headerStyle'  => $style_mode,
				'borderBottom' => '1px solid var(--mj-line-subtle)',
				'bgColor'      => '',
				'textColor'    => '',
				'paddingX'     => '1.5rem',
				'paddingY'     => '0',
			],
			'zones'   => [
				'left'   => [
					['id' => 'blk-megamenu', 'type' => 'mj-megamenu', 'settings' => []],
				],
				'center' => [],
				'right'  => [],
			],
		];
	}

	// 4. Esquema Móvil (Mobile)
	$rows_mobile = [];
	if ($show_topbar || $show_shipping) {
		$rows_mobile[] = $rows_desktop[0];
	}

	$rows_mobile[] = [
		'id'      => 'row-mobile-main',
		'name'    => 'Barra Móvil',
		'enabled' => true,
		'sticky'  => in_array($sticky_mode, ['sticky', 'sticky-mobile'], true),
		'style'   => [
			'minHeight'    => '56px',
			'headerStyle'  => $style_mode,
			'borderBottom' => '1px solid var(--mj-line-subtle)',
			'bgColor'      => !empty($options['header_bg_color']) ? (string) $options['header_bg_color'] : '',
			'textColor'    => !empty($options['header_ink_color']) ? (string) $options['header_ink_color'] : '',
			'paddingX'     => '1rem',
			'paddingY'     => '0',
		],
		'zones'   => [
			'left'   => [
				['id' => 'blk-mob-trigger', 'type' => 'mj-mobile-trigger', 'settings' => []],
			],
			'center' => [
				['id' => 'blk-mob-logo', 'type' => 'mj-logo', 'settings' => ['maxWidth' => '130px', 'showSiteName' => false]],
			],
			'right'  => [
				['id' => 'blk-mob-theme', 'type' => 'mj-theme-toggle', 'settings' => []],
				['id' => 'blk-mob-cart', 'type' => 'mj-cart', 'settings' => ['showCount' => true]],
			],
		],
	];

	return [
		'version' => 2,
		'desktop' => ['rows' => $rows_desktop],
		'mobile'  => ['rows' => $rows_mobile],
	];
}

/**
 * Genera el esquema de pie de página predeterminado a partir de las opciones clásicas.
 *
 * @param array $options Opciones de mj_options.
 * @return array
 */
function mj_legacy_footer_to_layout($options = []) {
	if (empty($options)) {
		$options = mj_get_options();
	}

	$show_newsletter = !isset($options['footer_show_newsletter']) || !empty($options['footer_show_newsletter']);
	$show_logo       = !isset($options['footer_show_logo']) || !empty($options['footer_show_logo']);
	$show_contact    = !isset($options['footer_show_contact']) || !empty($options['footer_show_contact']);
	$show_socials    = !isset($options['footer_show_socials']) || !empty($options['footer_show_socials']);
	$show_legal      = !isset($options['footer_show_legal']) || !empty($options['footer_show_legal']);
	$show_payments   = !isset($options['footer_show_payments']) || !empty($options['footer_show_payments']);

	$cols_count   = !empty($options['footer_columns_count']) ? absint($options['footer_columns_count']) : 4;
	$custom_cols  = !empty($options['footer_custom_columns']) ? (string) $options['footer_custom_columns'] : '';
	$tagline      = !empty($options['footer_tagline']) ? (string) $options['footer_tagline'] : '';
	$copyright    = !empty($options['footer_copyright']) ? (string) $options['footer_copyright'] : '';
	$border       = !empty($options['footer_border']) ? (string) $options['footer_border'] : 'line';
	$bg_color     = !empty($options['footer_bg_color']) ? (string) $options['footer_bg_color'] : '';
	$ink_color    = !empty($options['footer_ink_color']) ? (string) $options['footer_ink_color'] : '';

	$rows = [];

	// 1. Nivel 1: Newsletter
	if ($show_newsletter) {
		$rows[] = [
			'id'      => 'row-footer-newsletter',
			'name'    => 'Nivel 1: Newsletter',
			'enabled' => true,
			'style'   => [
				'minHeight'    => 'auto',
				'bgColor'      => '',
				'borderBottom' => '1px solid var(--mj-line-subtle)',
				'paddingX'     => '1.5rem',
				'paddingY'     => '3rem',
			],
			'zones'   => [
				'left'   => [],
				'center' => [
					[
						'id'       => 'blk-footer-newsletter',
						'type'     => 'mj-footer-newsletter',
						'settings' => [
							'title'      => 'Lo nuevo, antes que nadie.',
							'subtitle'   => 'Lanzamientos, ofertas exclusivas y novedades. Sin spam, prometido.',
							'buttonText' => 'Suscribirme',
						],
					],
				],
				'right'  => [],
			],
		];
	}

	// 2. Nivel 2: Marca, Enlaces, Contacto y Redes
	$main_left = [];
	if ($show_logo) {
		$main_left[] = [
			'id'       => 'blk-footer-brand',
			'type'     => 'mj-footer-brand',
			'settings' => [
				'showLogo' => true,
				'tagline'  => $tagline,
			],
		];
	}
	if ($show_contact) {
		$main_left[] = [
			'id'       => 'blk-footer-contact',
			'type'     => 'mj-footer-contact',
			'settings' => [
				'showAddress' => true,
				'showPhone'   => true,
				'showEmail'   => true,
			],
		];
	}
	if ($show_socials) {
		$main_left[] = [
			'id'       => 'blk-footer-socials',
			'type'     => 'mj-footer-socials',
			'settings' => ['style' => 'icons'],
		];
	}

	$main_center = [
		[
			'id'       => 'blk-footer-columns',
			'type'     => 'mj-footer-columns',
			'settings' => [
				'columnsCount'  => $cols_count,
				'customColumns' => $custom_cols,
			],
		],
	];

	$rows[] = [
		'id'      => 'row-footer-main',
		'name'    => 'Nivel 2: Contenido y Enlaces',
		'enabled' => true,
		'style'   => [
			'minHeight'    => 'auto',
			'bgColor'      => $bg_color,
			'textColor'    => $ink_color,
			'borderTop'    => 'none' === $border ? 'none' : '1px solid var(--mj-line-subtle)',
			'paddingX'     => '1.5rem',
			'paddingY'     => '4rem',
		],
		'zones'   => [
			'left'   => $main_left,
			'center' => $main_center,
			'right'  => [],
		],
	];

	// 3. Nivel 3: Barra Legal y Medios de Pago
	if ($show_legal || $show_payments) {
		$legal_left  = [];
		$legal_right = [];

		if ($show_legal) {
			$legal_left[] = [
				'id'       => 'blk-footer-legal',
				'type'     => 'mj-footer-legal',
				'settings' => [
					'copyright' => $copyright,
					'showLinks' => true,
				],
			];
		}
		if ($show_payments) {
			$legal_right[] = [
				'id'       => 'blk-footer-payments',
				'type'     => 'mj-footer-payments',
				'settings' => ['alignment' => 'right'],
			];
		}

		$rows[] = [
			'id'      => 'row-footer-legal',
			'name'    => 'Nivel 3: Barra Legal',
			'enabled' => true,
			'style'   => [
				'minHeight'    => '56px',
				'bgColor'      => '',
				'borderTop'    => '1px solid var(--mj-line-subtle)',
				'paddingX'     => '1.5rem',
				'paddingY'     => '1.25rem',
			],
			'zones'   => [
				'left'   => $legal_left,
				'center' => [],
				'right'  => $legal_right,
			],
		];
	}

	return [
		'version' => 2,
		'desktop' => ['rows' => $rows],
		'mobile'  => ['rows' => $rows],
	];
}

/**
 * Obtiene el esquema del Header actual (persistido o adaptado).
 *
 * @return array
 */
function mj_get_header_layout() {
	$options = mj_get_options();
	if (!empty($options['header_builder']) && is_array($options['header_builder']) && !empty($options['header_builder']['desktop'])) {
		return apply_filters('mj_header_layout', $options['header_builder']);
	}
	return apply_filters('mj_header_layout', mj_legacy_header_to_layout($options));
}

/**
 * Obtiene el esquema del Footer actual (persistido o adaptado).
 *
 * @return array
 */
function mj_get_footer_layout() {
	$options = mj_get_options();
	if (!empty($options['footer_builder']) && is_array($options['footer_builder']) && !empty($options['footer_builder']['desktop'])) {
		return apply_filters('mj_footer_layout', $options['footer_builder']);
	}
	return apply_filters('mj_footer_layout', mj_legacy_footer_to_layout($options));
}

/* -------------------------------------------------------------------------
 * 3. Sanitización y Validación Recursiva
 * ---------------------------------------------------------------------- */

/**
 * Sanitiza recursivamente un árbol de layout (rows -> zones -> blocks).
 *
 * @param mixed $raw Árbol de datos enviado.
 * @param string $scope 'header' o 'footer'.
 * @return array
 */
function mj_sanitize_builder_layout($raw, $scope = 'header') {
	if (!is_array($raw)) {
		return $scope === 'header' ? mj_legacy_header_to_layout() : mj_legacy_footer_to_layout();
	}

	$allowed_blocks = 'header' === $scope ? mj_registered_header_blocks() : mj_registered_footer_blocks();
	$sanitized      = [
		'version' => 2,
		'desktop' => ['rows' => []],
		'mobile'  => ['rows' => []],
	];

	foreach (['desktop', 'mobile'] as $device) {
		if (empty($raw[$device]['rows']) || !is_array($raw[$device]['rows'])) {
			continue;
		}

		foreach ($raw[$device]['rows'] as $r_idx => $raw_row) {
			if (!is_array($raw_row)) {
				continue;
			}

			$row_id   = !empty($raw_row['id']) ? sanitize_key($raw_row['id']) : 'row-' . $r_idx;
			$row_name = !empty($raw_row['name']) ? sanitize_text_field($raw_row['name']) : 'Fila ' . ($r_idx + 1);
			$enabled  = !isset($raw_row['enabled']) || !empty($raw_row['enabled']);
			$sticky   = !empty($raw_row['sticky']);

			$raw_style = !empty($raw_row['style']) && is_array($raw_row['style']) ? $raw_row['style'] : [];
			$style     = [
				'minHeight'    => !empty($raw_style['minHeight']) ? sanitize_text_field($raw_style['minHeight']) : '',
				'headerStyle'  => !empty($raw_style['headerStyle']) ? sanitize_key($raw_style['headerStyle']) : 'glass',
				'bgColor'      => !empty($raw_style['bgColor']) ? sanitize_hex_color($raw_style['bgColor']) : '',
				'textColor'    => !empty($raw_style['textColor']) ? sanitize_hex_color($raw_style['textColor']) : '',
				'borderBottom' => !empty($raw_style['borderBottom']) ? sanitize_text_field($raw_style['borderBottom']) : '',
				'borderTop'    => !empty($raw_style['borderTop']) ? sanitize_text_field($raw_style['borderTop']) : '',
				'paddingX'     => !empty($raw_style['paddingX']) ? sanitize_text_field($raw_style['paddingX']) : '',
				'paddingY'     => !empty($raw_style['paddingY']) ? sanitize_text_field($raw_style['paddingY']) : '',
			];

			$zones = ['left' => [], 'center' => [], 'right' => []];
			if (!empty($raw_row['zones']) && is_array($raw_row['zones'])) {
				foreach (['left', 'center', 'right'] as $z_key) {
					if (empty($raw_row['zones'][$z_key]) || !is_array($raw_row['zones'][$z_key])) {
						continue;
					}

					foreach ($raw_row['zones'][$z_key] as $b_idx => $raw_block) {
						if (!is_array($raw_block) || empty($raw_block['type'])) {
							continue;
						}

						$type = sanitize_key($raw_block['type']);
						if (!isset($allowed_blocks[$type])) {
							continue; // Bloque no reconocido en la lista blanca
						}

						$block_id = !empty($raw_block['id']) ? sanitize_key($raw_block['id']) : 'blk-' . $type . '-' . $b_idx;

						// Sanitización profunda de settings según el tipo de bloque
						$raw_settings = !empty($raw_block['settings']) && is_array($raw_block['settings']) ? $raw_block['settings'] : [];
						$clean_settings = [];

						foreach ($raw_settings as $s_k => $s_v) {
							$clean_key = sanitize_key($s_k);
							if (is_bool($s_v)) {
								$clean_settings[$clean_key] = (bool) $s_v;
							} elseif (is_numeric($s_v)) {
								$clean_settings[$clean_key] = $s_v;
							} elseif (is_string($s_v)) {
								if (in_array($clean_key, ['url', 'href'], true)) {
									$clean_settings[$clean_key] = esc_url_raw($s_v);
								} elseif (in_array($clean_key, ['content', 'html', 'customColumns'], true)) {
									$clean_settings[$clean_key] = wp_kses_post($s_v);
								} else {
									$clean_settings[$clean_key] = sanitize_text_field($s_v);
								}
							} elseif (is_array($s_v)) {
								$clean_settings[$clean_key] = array_map('sanitize_text_field', $s_v);
							}
						}

						// Dimensiones y estilos de bloque
						$raw_dims = !empty($raw_block['dimensions']) && is_array($raw_block['dimensions']) ? $raw_block['dimensions'] : [];
						$clean_dims = [];
						foreach ($raw_dims as $d_k => $d_v) {
							$clean_dims[sanitize_key($d_k)] = sanitize_text_field((string) $d_v);
						}

						$zones[$z_key][] = [
							'id'         => $block_id,
							'type'       => $type,
							'settings'   => $clean_settings,
							'dimensions' => $clean_dims,
						];
					}
				}
			}

			$sanitized[$device]['rows'][] = [
				'id'      => $row_id,
				'name'    => $row_name,
				'enabled' => $enabled,
				'sticky'  => $sticky,
				'style'   => array_filter($style),
				'zones'   => $zones,
			];
		}
	}

	return $sanitized;
}

/* -------------------------------------------------------------------------
 * 4. Endpoints REST API
 * ---------------------------------------------------------------------- */

add_action('rest_api_init', function () {
	$ns = 'mj/v1';

	// Consulta del estado completo del constructor (layouts + catálogo de bloques)
	register_rest_route($ns, '/builder/layout', [
		'methods'             => 'GET',
		'callback'            => function () {
			return new WP_REST_Response([
				'header' => mj_get_header_layout(),
				'footer' => mj_get_footer_layout(),
				'blocks' => [
					'header' => array_values(mj_registered_header_blocks()),
					'footer' => array_values(mj_registered_footer_blocks()),
				],
			], 200);
		},
		'permission_callback' => function () {
			return current_user_can('edit_theme_options');
		},
	]);

	// Guardado del layout de Header y/o Footer
	register_rest_route($ns, '/builder/layout', [
		'methods'             => 'POST',
		'callback'            => function (WP_REST_Request $request) {
			$params = $request->get_json_params();
			if (!is_array($params)) {
				return new WP_Error('invalid_payload', 'Estructura JSON inválida', ['status' => 400]);
			}

			$options = mj_get_options();
			$updated = false;

			if (isset($params['header'])) {
				$clean_header = mj_sanitize_builder_layout($params['header'], 'header');
				$options['header_builder'] = $clean_header;
				$updated = true;
			}

			if (isset($params['footer'])) {
				$clean_footer = mj_sanitize_builder_layout($params['footer'], 'footer');
				$options['footer_builder'] = $clean_footer;
				$updated = true;
			}

			if ($updated) {
				update_option('mj_options', $options);
			}

			return new WP_REST_Response([
				'success' => true,
				'header'  => mj_get_header_layout(),
				'footer'  => mj_get_footer_layout(),
			], 200);
		},
		'permission_callback' => function () {
			return current_user_can('edit_theme_options');
		},
	]);

	// Restablecer al diseño predeterminado
	register_rest_route($ns, '/builder/reset', [
		'methods'             => 'POST',
		'callback'            => function (WP_REST_Request $request) {
			$scope = sanitize_key($request->get_param('scope'));
			$options = mj_get_options();

			if ('header' === $scope || 'all' === $scope) {
				$options['header_builder'] = null;
			}
			if ('footer' === $scope || 'all' === $scope) {
				$options['footer_builder'] = null;
			}

			update_option('mj_options', $options);

			return new WP_REST_Response([
				'success' => true,
				'header'  => mj_get_header_layout(),
				'footer'  => mj_get_footer_layout(),
			], 200);
		},
		'permission_callback' => function () {
			return current_user_can('edit_theme_options');
		},
	]);
});

/* -------------------------------------------------------------------------
 * 5. Menú de Administración del Constructor Visual
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
	$cap = 'edit_theme_options';

	if (class_exists('MJ_Menu')) {
		add_submenu_page(
			MJ_Menu::slug(),
			'Constructor de Cabecera y Pie',
			'Constructor Header & Footer',
			$cap,
			'mj-header-footer-builder',
			'mj_render_builder_admin_page'
		);
		return;
	}

	add_theme_page(
		'Constructor de Cabecera y Pie',
		'Constructor Header & Footer',
		$cap,
		'mj-header-footer-builder',
		'mj_render_builder_admin_page'
	);
}, 20);

/**
 * Render de la página de administración del constructor visual.
 */
function mj_render_builder_admin_page() {
	if (!current_user_can('edit_theme_options')) {
		return;
	}

	$header_layout = mj_get_header_layout();
	$footer_layout = mj_get_footer_layout();
	$header_blocks = array_values(mj_registered_header_blocks());
	$footer_blocks = array_values(mj_registered_footer_blocks());
	?>
	<div class="wrap mj-builder-wrap">
		<h1 style="display:flex; align-items:center; gap:10px; margin-bottom:15px;">
			<span class="dashicons dashicons-layout" style="font-size:28px; width:28px; height:28px; color:#6e6bff;"></span>
			Constructor de Cabecera y Pie de Página
		</h1>
		<p style="color:#555; max-width:850px; font-size:14px; margin-bottom:20px;">
			Diseñá la cabecera y el pie de tu tienda de forma 100% modular y dinámica. Arrastrá bloques a las zonas deseadas, creá filas a medida y ajustá estilos y dimensiones de forma independiente para Computadora y Celular.
		</p>

		<!-- Contenedor del constructor montado con JavaScript -->
		<div id="mj-builder-app" style="background:#fff; border:1px solid #dcdcde; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.05); min-height:500px; padding:20px;">
			<div style="display:flex; align-items:center; justify-content:center; height:300px; color:#888;">
				<span class="spinner is-active" style="float:none; margin-right:8px;"></span>
				Cargando constructor visual...
			</div>
		</div>
	</div>

	<script>
		window.MJ_BUILDER_DATA = {
			headerLayout: <?php echo wp_json_encode($header_layout); ?>,
			footerLayout: <?php echo wp_json_encode($footer_layout); ?>,
			headerBlocks: <?php echo wp_json_encode($header_blocks); ?>,
			footerBlocks: <?php echo wp_json_encode($footer_blocks); ?>,
			restUrl: <?php echo wp_json_encode(esc_url_raw(rest_url('mj/v1/builder'))); ?>,
			nonce: <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>,
		};
	</script>
	<?php
	// Encolar assets de administración del builder
	$builder_js = get_template_directory_uri() . '/bloques/builder-admin.js';
	$builder_css = get_template_directory_uri() . '/bloques/builder-admin.css';
	$ver = defined('MJ_VERSION') ? MJ_VERSION : '1.0.0';

	if (file_exists(get_template_directory() . '/bloques/builder-admin.css')) {
		wp_enqueue_style('mj-builder-admin-css', $builder_css, [], $ver);
	}
	if (file_exists(get_template_directory() . '/bloques/builder-admin.js')) {
		wp_enqueue_script('mj-builder-admin-js', $builder_js, ['wp-element', 'wp-components'], $ver, true);
	}
}
