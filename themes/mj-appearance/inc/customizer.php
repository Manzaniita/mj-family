<?php
/**
 * Integración con el Personalizador de WordPress (Apariencia → Personalizar → MJ).
 *
 * Mueve toda la configuración visual y de diseño (cabecera, pie de página,
 * paleta de colores, logos, botones, tipografías y hero de portada) al panel
 * nativo de WordPress con previsualización interactiva en tiempo real.
 *
 * @package MJ_Appearance
 */

defined('ABSPATH') || exit;

/**
 * Registra el panel "MJ" y sus secciones en el Personalizador de WordPress.
 *
 * @param WP_Customize_Manager $wp_customize Instancia del Personalizador.
 */
function mj_customize_register($wp_customize) {
	$defaults = mj_option_defaults();

	/* Helper para registrar ajustes en mj_options[key] */
	$add_setting = function ($key, $sanitize_callback = 'sanitize_text_field') use ($wp_customize, $defaults) {
		$default = isset($defaults[$key]) ? $defaults[$key] : '';
		$wp_customize->add_setting("mj_options[{$key}]", [
			'type'              => 'option',
			'capability'        => 'edit_theme_options',
			'default'           => $default,
			'sanitize_callback' => $sanitize_callback,
			'transport'         => 'refresh',
		]);
	};

	/* Helper para checkboxes (0 o 1) */
	$sanitize_checkbox = function ($checked) {
		return !empty($checked) ? 1 : 0;
	};

	/* Helper para colores hexadecimales */
	$sanitize_color = function ($color) {
		$clean = sanitize_hex_color((string) $color);
		return !empty($clean) ? $clean : '';
	};

	/* Helper para enteros positivos */
	$sanitize_int = function ($val) {
		return absint($val);
	};

	/* -------------------------------------------------------------------------
	 * Panel principal "MJ"
	 * ---------------------------------------------------------------------- */
	$wp_customize->add_panel('mj_customizer', [
		'title'       => 'MJ',
		'description' => 'Personalizá el diseño, colores, cabecera, pie de página, logos y botones de la tienda en tiempo real.',
		'priority'    => 25,
	]);

	/* -------------------------------------------------------------------------
	 * 1. Cabecera (Header)
	 * ---------------------------------------------------------------------- */
	$wp_customize->add_section('mj_header_section', [
		'title'       => 'Cabecera (Header)',
		'panel'       => 'mj_customizer',
		'priority'    => 10,
		'description' => 'Ajustes de la barra superior, navegación, botones de acción y comportamiento fijo.',
	]);

	$add_setting('header_sticky');
	$wp_customize->add_control('mj_header_sticky', [
		'label'    => 'Comportamiento (sticky)',
		'section'  => 'mj_header_section',
		'settings' => 'mj_options[header_sticky]',
		'type'     => 'select',
		'choices'  => mj_header_sticky_choices(),
	]);

	$add_setting('header_style');
	$wp_customize->add_control('mj_header_style', [
		'label'    => 'Estilo de fondo',
		'section'  => 'mj_header_section',
		'settings' => 'mj_options[header_style]',
		'type'     => 'select',
		'choices'  => mj_header_style_choices(),
	]);

	$add_setting('header_border');
	$wp_customize->add_control('mj_header_border', [
		'label'    => 'Borde inferior',
		'section'  => 'mj_header_section',
		'settings' => 'mj_options[header_border]',
		'type'     => 'select',
		'choices'  => mj_header_border_choices(),
	]);

	$add_setting('header_logo_pos');
	$wp_customize->add_control('mj_header_logo_pos', [
		'label'    => 'Posición del logo',
		'section'  => 'mj_header_section',
		'settings' => 'mj_options[header_logo_pos]',
		'type'     => 'select',
		'choices'  => mj_header_logo_choices(),
	]);

	$add_setting('header_search_pos');
	$wp_customize->add_control('mj_header_search_pos', [
		'label'    => 'Posición del buscador',
		'section'  => 'mj_header_section',
		'settings' => 'mj_options[header_search_pos]',
		'type'     => 'select',
		'choices'  => mj_header_search_choices(),
	]);

	$add_setting('header_menu_pos');
	$wp_customize->add_control('mj_header_menu_pos', [
		'label'    => 'Disposición del menú de navegación',
		'section'  => 'mj_header_section',
		'settings' => 'mj_options[header_menu_pos]',
		'type'     => 'select',
		'choices'  => mj_header_menu_choices(),
	]);

	$header_toggles = [
		'header_show_topbar'           => 'Mostrar barra superior de anuncios',
		'header_show_shipping'         => 'Mostrar barra de aviso de envío gratis',
		'header_show_cart'             => 'Mostrar botón de carrito de compras',
		'header_show_wishlist'         => 'Mostrar botón de favoritos',
		'header_show_compare'          => 'Mostrar botón de comparador de productos',
		'header_show_account'          => 'Mostrar botón de mi cuenta / acceso',
		'header_show_theme_toggle'     => 'Mostrar switch día/noche (sol/luna)',
		'header_show_exchange_rate'    => 'Mostrar cotización del dólar (MJ Dólar)',
		'header_show_payment_selector' => 'Mostrar selector de medio de pago (MJ Medios de Pago)',
		'header_show_turnos_cta'       => 'Mostrar botón de reserva (MJ Turnos Online)',
	];
	foreach ($header_toggles as $key => $label) {
		$add_setting($key, $sanitize_checkbox);
		$wp_customize->add_control("mj_{$key}", [
			'label'    => $label,
			'section'  => 'mj_header_section',
			'settings' => "mj_options[{$key}]",
			'type'     => 'checkbox',
		]);
	}

	$add_setting('header_cta_text');
	$wp_customize->add_control('mj_header_cta_text', [
		'label'       => 'Texto botón Turnos',
		'section'     => 'mj_header_section',
		'settings'    => 'mj_options[header_cta_text]',
		'type'        => 'text',
		'description' => 'Vacío = usa el configurado en Turnos.',
	]);

	$add_setting('header_cta_url', 'esc_url_raw');
	$wp_customize->add_control('mj_header_cta_url', [
		'label'    => 'Enlace botón Turnos',
		'section'  => 'mj_header_section',
		'settings' => 'mj_options[header_cta_url]',
		'type'     => 'text',
	]);

	$add_setting('header_custom_btn_text');
	$wp_customize->add_control('mj_header_custom_btn_text', [
		'label'       => 'Botón adicional: Texto',
		'section'     => 'mj_header_section',
		'settings'    => 'mj_options[header_custom_btn_text]',
		'type'        => 'text',
		'description' => 'Ej. WhatsApp, Asesor o Promociones.',
	]);

	$add_setting('header_custom_btn_url', 'esc_url_raw');
	$wp_customize->add_control('mj_header_custom_btn_url', [
		'label'    => 'Botón adicional: Enlace',
		'section'  => 'mj_header_section',
		'settings' => 'mj_options[header_custom_btn_url]',
		'type'     => 'text',
	]);

	$add_setting('header_custom_btn_icon');
	$wp_customize->add_control('mj_header_custom_btn_icon', [
		'label'       => 'Botón adicional: Ícono',
		'section'     => 'mj_header_section',
		'settings'    => 'mj_options[header_custom_btn_icon]',
		'type'        => 'text',
		'description' => 'Emoji o nombre del ícono Lucide (ej. MessageCircle).',
	]);

	$add_setting('header_custom_btn_target');
	$wp_customize->add_control('mj_header_custom_btn_target', [
		'label'    => 'Botón adicional: Apertura',
		'section'  => 'mj_header_section',
		'settings' => 'mj_options[header_custom_btn_target]',
		'type'     => 'select',
		'choices'  => [
			'_self'  => 'En la misma pestaña',
			'_blank' => 'En una pestaña nueva',
		],
	]);

	$header_colors = [
		'header_bg_color'     => 'Fondo personalizado de cabecera',
		'header_ink_color'    => 'Color de texto e íconos de cabecera',
		'header_accent_color' => 'Color de acento propio de cabecera',
	];
	foreach ($header_colors as $key => $label) {
		$add_setting($key, $sanitize_color);
		$wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, "mj_{$key}", [
			'label'    => $label,
			'section'  => 'mj_header_section',
			'settings' => "mj_options[{$key}]",
		]));
	}

	/* -------------------------------------------------------------------------
	 * 2. Pie de página (Footer)
	 * ---------------------------------------------------------------------- */
	$wp_customize->add_section('mj_footer_section', [
		'title'       => 'Pie de página (Footer)',
		'panel'       => 'mj_customizer',
		'priority'    => 20,
		'description' => 'Ajustes del pie de página, newsletter, columnas, información de marca y barra legal.',
	]);

	$add_setting('footer_show_newsletter', $sanitize_checkbox);
	$wp_customize->add_control('mj_footer_show_newsletter', [
		'label'    => 'Mostrar sección de suscripción a newsletter',
		'section'  => 'mj_footer_section',
		'settings' => 'mj_options[footer_show_newsletter]',
		'type'     => 'checkbox',
	]);

	$add_setting('footer_columns_count', $sanitize_int);
	$wp_customize->add_control('mj_footer_columns_count', [
		'label'    => 'Cantidad de columnas',
		'section'  => 'mj_footer_section',
		'settings' => 'mj_options[footer_columns_count]',
		'type'     => 'select',
		'choices'  => mj_footer_columns_choices(),
	]);

	$add_setting('footer_show_logo', $sanitize_checkbox);
	$wp_customize->add_control('mj_footer_show_logo', [
		'label'    => 'Mostrar logo en el pie',
		'section'  => 'mj_footer_section',
		'settings' => 'mj_options[footer_show_logo]',
		'type'     => 'checkbox',
	]);

	$add_setting('footer_tagline', 'sanitize_textarea_field');
	$wp_customize->add_control('mj_footer_tagline', [
		'label'       => 'Eslogan o descripción bajo el logo',
		'section'     => 'mj_footer_section',
		'settings'    => 'mj_options[footer_tagline]',
		'type'        => 'textarea',
		'description' => 'Vacío = usa la descripción corta del sitio.',
	]);

	$add_setting('footer_show_contact', $sanitize_checkbox);
	$wp_customize->add_control('mj_footer_show_contact', [
		'label'    => 'Mostrar datos de contacto (WhatsApp, teléfono, dirección, mail)',
		'section'  => 'mj_footer_section',
		'settings' => 'mj_options[footer_show_contact]',
		'type'     => 'checkbox',
	]);

	$add_setting('footer_show_socials', $sanitize_checkbox);
	$wp_customize->add_control('mj_footer_show_socials', [
		'label'    => 'Mostrar iconos de redes sociales',
		'section'  => 'mj_footer_section',
		'settings' => 'mj_options[footer_show_socials]',
		'type'     => 'checkbox',
	]);

	$add_setting('footer_custom_columns', 'sanitize_textarea_field');
	$wp_customize->add_control('mj_footer_custom_columns', [
		'label'       => 'Columnas personalizadas de enlaces',
		'section'     => 'mj_footer_section',
		'settings'    => 'mj_options[footer_custom_columns]',
		'type'        => 'textarea',
		'description' => "Definí títulos con '# Título' y enlaces con 'Texto | /ruta | _blank'. Si se deja vacío, arma las columnas automáticas de la tienda.",
	]);

	$add_setting('footer_show_legal', $sanitize_checkbox);
	$wp_customize->add_control('mj_footer_show_legal', [
		'label'    => 'Mostrar barra legal inferior',
		'section'  => 'mj_footer_section',
		'settings' => 'mj_options[footer_show_legal]',
		'type'     => 'checkbox',
	]);

	$add_setting('footer_copyright');
	$wp_customize->add_control('mj_footer_copyright', [
		'label'       => 'Texto de copyright',
		'section'     => 'mj_footer_section',
		'settings'    => 'mj_options[footer_copyright]',
		'type'        => 'text',
		'description' => 'Vacío = © [año] [nombre de tienda]. Todos los derechos reservados.',
	]);

	$add_setting('footer_show_payments', $sanitize_checkbox);
	$wp_customize->add_control('mj_footer_show_payments', [
		'label'    => 'Mostrar iconos de medios de pago en la barra legal',
		'section'  => 'mj_footer_section',
		'settings' => 'mj_options[footer_show_payments]',
		'type'     => 'checkbox',
	]);

	$add_setting('footer_border');
	$wp_customize->add_control('mj_footer_border', [
		'label'    => 'Borde superior',
		'section'  => 'mj_footer_section',
		'settings' => 'mj_options[footer_border]',
		'type'     => 'select',
		'choices'  => [
			'line' => 'Línea sutil',
			'none' => 'Sin borde',
		],
	]);

	$footer_colors = [
		'footer_bg_color'     => 'Fondo personalizado de pie de página',
		'footer_ink_color'    => 'Color de texto de pie de página',
		'footer_accent_color' => 'Color de acento propio de pie de página',
	];
	foreach ($footer_colors as $key => $label) {
		$add_setting($key, $sanitize_color);
		$wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, "mj_{$key}", [
			'label'    => $label,
			'section'  => 'mj_footer_section',
			'settings' => "mj_options[{$key}]",
		]));
	}

	/* -------------------------------------------------------------------------
	 * 3. Colores y paleta
	 * ---------------------------------------------------------------------- */
	$wp_customize->add_section('mj_colors_section', [
		'title'       => 'Colores y paleta',
		'panel'       => 'mj_customizer',
		'priority'    => 30,
		'description' => 'Colores principales de la tienda. Los tonos suaves y bordes se calculan armónicamente a partir de estos.',
	]);

	$colors = [
		'color_accent'    => 'Color de acento (botones, links, resplandores)',
		'color_bg_dark'   => 'Fondo — tema oscuro (noche)',
		'color_ink_dark'  => 'Texto — tema oscuro (noche)',
		'color_bg_light'  => 'Fondo — tema claro (día)',
		'color_ink_light' => 'Texto — tema claro (día)',
	];
	foreach ($colors as $key => $label) {
		$add_setting($key, $sanitize_color);
		$wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, "mj_{$key}", [
			'label'    => $label,
			'section'  => 'mj_colors_section',
			'settings' => "mj_options[{$key}]",
		]));
	}

	$add_setting('ink_soft', $sanitize_int);
	$wp_customize->add_control('mj_ink_soft', [
		'label'       => 'Fuerza del texto secundario (%)',
		'section'     => 'mj_colors_section',
		'settings'    => 'mj_options[ink_soft]',
		'type'        => 'number',
		'input_attrs' => ['min' => 40, 'max' => 100, 'step' => 5],
	]);

	$add_setting('ink_faint', $sanitize_int);
	$wp_customize->add_control('mj_ink_faint', [
		'label'       => 'Fuerza del texto atenuado (%)',
		'section'     => 'mj_colors_section',
		'settings'    => 'mj_options[ink_faint]',
		'type'        => 'number',
		'input_attrs' => ['min' => 30, 'max' => 100, 'step' => 5],
	]);

	$add_setting('design_theme');
	$wp_customize->add_control('mj_design_theme', [
		'label'    => 'Tema por defecto al ingresar',
		'section'  => 'mj_colors_section',
		'settings' => 'mj_options[design_theme]',
		'type'     => 'select',
		'choices'  => mj_theme_choices(),
	]);

	$add_setting('theme_switch');
	$wp_customize->add_control('mj_theme_switch', [
		'label'    => 'Efecto al cambiar de tema (día/noche)',
		'section'  => 'mj_colors_section',
		'settings' => 'mj_options[theme_switch]',
		'type'     => 'select',
		'choices'  => mj_theme_switch_choices(),
	]);

	$add_setting('motion_level');
	$wp_customize->add_control('mj_motion_level', [
		'label'    => 'Nivel de animación de la interfaz',
		'section'  => 'mj_colors_section',
		'settings' => 'mj_options[motion_level]',
		'type'     => 'select',
		'choices'  => mj_motion_choices(),
	]);

	$add_setting('intro_enabled', $sanitize_checkbox);
	$wp_customize->add_control('mj_intro_enabled', [
		'label'    => 'Mostrar cortina de intro animada al entrar',
		'section'  => 'mj_colors_section',
		'settings' => 'mj_options[intro_enabled]',
		'type'     => 'checkbox',
	]);

	/* -------------------------------------------------------------------------
	 * 4. Logo e identidad
	 * ---------------------------------------------------------------------- */
	$wp_customize->add_section('mj_logo_section', [
		'title'       => 'Logo e identidad',
		'panel'       => 'mj_customizer',
		'priority'    => 40,
		'description' => 'Logotipo principal y variante para fondos oscuros.',
	]);

	$add_setting('logo_id', $sanitize_int);
	$wp_customize->add_control(new WP_Customize_Media_Control($wp_customize, 'mj_logo_id', [
		'label'     => 'Logo principal',
		'section'   => 'mj_logo_section',
		'settings'  => 'mj_options[logo_id]',
		'mime_type' => 'image',
	]));

	$add_setting('logo_dark_id', $sanitize_int);
	$wp_customize->add_control(new WP_Customize_Media_Control($wp_customize, 'mj_logo_dark_id', [
		'label'       => 'Logo para modo noche (opcional)',
		'section'     => 'mj_logo_section',
		'settings'    => 'mj_options[logo_dark_id]',
		'mime_type'   => 'image',
		'description' => 'Versión clara del logo para fondos oscuros. Vacío = usa el principal.',
	]));

	$add_setting('logo_show_name', $sanitize_checkbox);
	$wp_customize->add_control('mj_logo_show_name', [
		'label'       => 'Mostrar nombre del sitio junto al logo',
		'section'     => 'mj_logo_section',
		'settings'    => 'mj_options[logo_show_name]',
		'type'        => 'checkbox',
		'description' => 'Desmarcalo si el diseño de tu logo ya incluye el nombre tipográfico.',
	]);

	/* -------------------------------------------------------------------------
	 * 5. Diseño de botones
	 * ---------------------------------------------------------------------- */
	$wp_customize->add_section('mj_buttons_section', [
		'title'       => 'Diseño de botones',
		'panel'       => 'mj_customizer',
		'priority'    => 50,
		'description' => 'Forma, bordes redondeados y efectos de los botones de la tienda.',
	]);

	$add_setting('button_radius');
	$wp_customize->add_control('mj_button_radius', [
		'label'    => 'Forma de los botones (radio de borde)',
		'section'  => 'mj_buttons_section',
		'settings' => 'mj_options[button_radius]',
		'type'     => 'select',
		'choices'  => mj_button_radius_choices(),
	]);

	$add_setting('button_glow', $sanitize_checkbox);
	$wp_customize->add_control('mj_button_glow', [
		'label'       => 'Efecto resplandor (glow) en botones principales',
		'section'     => 'mj_buttons_section',
		'settings'    => 'mj_options[button_glow]',
		'type'        => 'checkbox',
		'description' => 'Agrega una sombra brillante con el color de acento alrededor del botón.',
	]);

	/* -------------------------------------------------------------------------
	 * 6. Tipografías
	 * ---------------------------------------------------------------------- */
	$wp_customize->add_section('mj_fonts_section', [
		'title'       => 'Tipografías',
		'panel'       => 'mj_customizer',
		'priority'    => 60,
		'description' => 'Fuentes tipográficas de Google Fonts optimizadas para la tienda.',
	]);

	$font_choices = [];
	foreach (mj_font_choices() as $font) {
		$font_choices[$font] = $font;
	}

	$add_setting('font_display');
	$wp_customize->add_control('mj_font_display', [
		'label'    => 'Tipografía para títulos y números destacados',
		'section'  => 'mj_fonts_section',
		'settings' => 'mj_options[font_display]',
		'type'     => 'select',
		'choices'  => array_merge(['' => '— Tipografía de la skin / por defecto —'], $font_choices),
	]);

	$add_setting('font_sans');
	$wp_customize->add_control('mj_font_sans', [
		'label'    => 'Tipografía para párrafos y texto general',
		'section'  => 'mj_fonts_section',
		'settings' => 'mj_options[font_sans]',
		'type'     => 'select',
		'choices'  => array_merge(['' => '— Tipografía de la skin / por defecto —'], $font_choices),
	]);

	/* -------------------------------------------------------------------------
	 * 7. Portada (Hero) y Banners
	 * ---------------------------------------------------------------------- */
	$wp_customize->add_section('mj_hero_section', [
		'title'       => 'Portada (Hero) y Banners',
		'panel'       => 'mj_customizer',
		'priority'    => 70,
		'description' => 'Textos, llamadas a la acción e imagen de fondo de la portada clásica.',
	]);

	$add_setting('hero_badge');
	$wp_customize->add_control('mj_hero_badge', [
		'label'    => 'Etiqueta superior (badge)',
		'section'  => 'mj_hero_section',
		'settings' => 'mj_options[hero_badge]',
		'type'     => 'text',
	]);

	$add_setting('hero_title');
	$wp_customize->add_control('mj_hero_title', [
		'label'    => 'Título principal',
		'section'  => 'mj_hero_section',
		'settings' => 'mj_options[hero_title]',
		'type'     => 'text',
	]);

	$add_setting('hero_title_highlight');
	$wp_customize->add_control('mj_hero_title_highlight', [
		'label'       => 'Texto destacado (con degradado)',
		'section'     => 'mj_hero_section',
		'settings'    => 'mj_options[hero_title_highlight]',
		'type'        => 'text',
		'description' => 'Aparece a continuación del título con un efecto degradado.',
	]);

	$add_setting('hero_subtitle', 'sanitize_textarea_field');
	$wp_customize->add_control('mj_hero_subtitle', [
		'label'    => 'Bajada / Subtítulo',
		'section'  => 'mj_hero_section',
		'settings' => 'mj_options[hero_subtitle]',
		'type'     => 'textarea',
	]);

	$add_setting('hero_cta_primary_text');
	$wp_customize->add_control('mj_hero_cta_primary_text', [
		'label'    => 'Botón principal: Texto',
		'section'  => 'mj_hero_section',
		'settings' => 'mj_options[hero_cta_primary_text]',
		'type'     => 'text',
	]);

	$add_setting('hero_cta_primary_url', 'esc_url_raw');
	$wp_customize->add_control('mj_hero_cta_primary_url', [
		'label'    => 'Botón principal: Enlace',
		'section'  => 'mj_hero_section',
		'settings' => 'mj_options[hero_cta_primary_url]',
		'type'     => 'text',
	]);

	$add_setting('hero_cta_secondary_text');
	$wp_customize->add_control('mj_hero_cta_secondary_text', [
		'label'    => 'Botón secundario: Texto',
		'section'  => 'mj_hero_section',
		'settings' => 'mj_options[hero_cta_secondary_text]',
		'type'     => 'text',
	]);

	$add_setting('hero_cta_secondary_url', 'esc_url_raw');
	$wp_customize->add_control('mj_hero_cta_secondary_url', [
		'label'    => 'Botón secundario: Enlace',
		'section'  => 'mj_hero_section',
		'settings' => 'mj_options[hero_cta_secondary_url]',
		'type'     => 'text',
	]);

	$add_setting('hero_image_id', $sanitize_int);
	$wp_customize->add_control(new WP_Customize_Media_Control($wp_customize, 'mj_hero_image_id', [
		'label'       => 'Foto de fondo del hero',
		'section'     => 'mj_hero_section',
		'settings'    => 'mj_options[hero_image_id]',
		'mime_type'   => 'image',
		'description' => 'Foto de portada en alta definición.',
	]));

	$add_setting('hero_show_stats', $sanitize_checkbox);
	$wp_customize->add_control('mj_hero_show_stats', [
		'label'    => 'Mostrar cifras automáticas del catálogo',
		'section'  => 'mj_hero_section',
		'settings' => 'mj_options[hero_show_stats]',
		'type'     => 'checkbox',
	]);

	$add_setting('banners_autoplay', $sanitize_checkbox);
	$wp_customize->add_control('mj_banners_autoplay', [
		'label'    => 'Slider de banners: pasar automáticamente',
		'section'  => 'mj_hero_section',
		'settings' => 'mj_options[banners_autoplay]',
		'type'     => 'checkbox',
	]);

	$add_setting('banners_interval', $sanitize_int);
	$wp_customize->add_control('mj_banners_interval', [
		'label'       => 'Segundos por cada banner',
		'section'     => 'mj_hero_section',
		'settings'    => 'mj_options[banners_interval]',
		'type'        => 'number',
		'input_attrs' => ['min' => 2, 'max' => 20, 'step' => 1],
	]);
}
add_action('customize_register', 'mj_customize_register');
