<?php
/**
 * Jaz Beauty Art — tema hijo de MJ Appearance (pestañas y cejas).
 *
 * Como los demás hijos, no toca el padre: se DESCRIBE con el filtro `mj_skins`
 * (paleta, tipografías, textos, portada) y propone los defaults de los
 * plugins que usa la tienda (MJ Turnos Online y MJ Fidelidad) con sus filtros
 * `*_option_defaults`. Todo son propuestas: lo que Jaz guarde en los paneles
 * siempre gana.
 *
 * Además:
 *   - jazbeauty.css después del CSS del padre;
 *   - plantillas de bloques (portada, Sobre mí, Preguntas frecuentes,
 *     Políticas, Mis trabajos);
 *   - inc/contenido.php: un botón en Apariencia → Contenido inicial que carga
 *     los servicios, los productos y las páginas de arranque como BORRADOR
 *     (nada se publica solo).
 */

defined('ABSPATH') || exit;

define('MJ_JAZBEAUTY_VERSION', '1.0.0');

/* Versión mínima del tema padre (sin animaciones, botón fijo, galería, fidelidad en Mi cuenta). */
define('MJ_JAZBEAUTY_REQUIRES', '3.21.0');

require_once __DIR__ . '/inc/textos.php';    // Textos que se repiten (políticas, preguntas frecuentes, servicios).
require_once __DIR__ . '/inc/contenido.php'; // Carga del contenido inicial como borrador.

add_filter('mj_skin', fn () => 'jazbeauty');
add_filter('mj_skin_requires', fn () => MJ_JAZBEAUTY_REQUIRES);

/** ¿Está MJ Turnos Online? */
function mj_jazbeauty_turnos_active() {
	return defined('MJTO_VERSION') || function_exists('mjto_public_config');
}

/** Ruta de la reserva: '/' + slug de Turnos (default /turnos). Mismo cuidado que GlossyHair con la reentrada. */
function mj_jazbeauty_booking_path() {
	static $path = null;
	if (null !== $path) {
		return $path;
	}
	$path = '/turnos';
	if (function_exists('mjto_page_slug')) {
		$slug = sanitize_title((string) mjto_page_slug());
		if ('' !== $slug) {
			$path = '/' . $slug;
		}
	}
	return $path;
}

function mj_jazbeauty_shop_path() {
	return function_exists('mj_shop_path') ? mj_shop_path() : '/tienda';
}

/* -------------------------------------------------------------------------
 * La skin
 * ---------------------------------------------------------------------- */
add_filter('mj_skins', function ($skins) {
	$turnos  = mj_jazbeauty_turnos_active();
	$booking = mj_jazbeauty_booking_path();
	$shop    = mj_jazbeauty_shop_path();

	$skins['jazbeauty'] = [
		'name'      => 'Jaz Beauty Art',

		/* Crema cálido de fondo, bordó protagonista, texto negro. De noche, un bordó muy oscuro. */
		'palette'   => [
			'color_accent'    => '#7b1e34', // bordó
			'color_bg_light'  => '#f6eee3', // crema cálido
			'color_ink_light' => '#1c1617', // negro cálido
			'color_bg_dark'   => '#1b1113',
			'color_ink_dark'  => '#f4eadf',
		],

		'fonts'     => [
			'font_display' => 'Cormorant Garamond',
			'font_sans'    => 'Jost',
		],
		'fonts_url' => 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&family=Jost:wght@400;500;600&display=swap',

		'options'   => [
			'hero_badge'              => 'Pestañas y cejas',
			'hero_title'              => 'Jaz Beauty Art',
			'hero_title_highlight'    => 'Detalles que hacen la diferencia.',
			'hero_cta_primary_text'   => $turnos ? 'Reservar turno' : 'Ver el Beauty Shop',
			'hero_cta_primary_url'    => $turnos ? $booking : $shop,
			'hero_cta_secondary_text' => '',
			'hero_cta_secondary_url'  => '',
			/* Botón fijo abajo: la reserva siempre a mano. */
			'sticky_cta_enabled'      => $turnos ? 1 : 0,
			'sticky_cta_text'         => 'Reservar turno',
			'sticky_cta_url'          => $turnos ? $booking : '',
			'sticky_cta_desktop'      => 0,
			/* Contacto: solo Instagram y la zona (sin formulario, sin dirección exacta, sin WhatsApp). */
			'social_instagram'        => 'https://instagram.com/jaz.beauty.art',
			'contact_show_instagram'  => 1,
			'contact_show_form'       => 0,
			'contact_address'         => '',
			'contact_whatsapp'        => '',
		],

		/* Día, sin animaciones, sin cortina; portada corta: presentación, reserva, trabajos y opiniones. */
		'home'      => [
			'design_theme'  => 'light',
			'motion_level'  => 'none',
			'intro_enabled' => 0,
			'order'         => 'about,booking,services,testimonials,instagram,featured,categories,latest,recommended,colegas,banners,brands',
			'hidden'        => 'featured,categories,latest,recommended,colegas,banners,brands',
		],

		'copy'      => [
			// Portada
			'home_about_eyebrow'        => '',
			'home_about_title'          => 'Hola, soy Jaz ♡',
			'home_about_text'           => '', // Vacío = primer párrafo de Contenido → Nosotros.
			'home_about_link'           => 'Conoceme',
			'home_testimonials_eyebrow' => 'Opiniones',
			'home_testimonials_title'   => 'Lo que dicen mis clientas',
			'home_instagram_title'      => 'Mis trabajos en Instagram',
			'home_instagram_link'       => '@jaz.beauty.art',
			// Menú
			'nav_home'                  => 'Inicio',
			'nav_shop'                  => 'Beauty Shop',
			'nav_about'                 => 'Sobre mí',
			'nav_contact'               => 'Contacto',
			'nav_account'               => 'Mi cuenta',
			'nav_home_mobile'           => 'Inicio',
			'nav_search'                => 'Buscar productos',
			// Tienda
			'shop_eyebrow'              => 'Beauty Shop',
			'shop_title'                => 'Beauty Shop',
			'shop_empty_title'          => 'No encontré nada con eso',
			'shop_empty_text'           => 'Mirá todos los productos del Beauty Shop.',
			// Mi cuenta
			'account_orders_title'      => 'Mis compras',
			'account_nav_orders'        => 'Mis compras',
			'account_orders_empty'      => 'Todavía no compraste nada en el Beauty Shop.',
			'account_orders_cta'        => 'Ver el Beauty Shop',
			// Pie
			'footer_tagline'            => 'Detalles que hacen la diferencia.',
			'footer_rights'             => 'Jaz Beauty Art. Todos los derechos reservados.',
			'footer_link_orders'        => 'Mis compras',
			// Páginas
			'about_eyebrow'             => 'Sobre mí',
			'about_title'               => 'Hola, soy Jaz ♡',
			'about_cta_text'            => $turnos ? 'Reservar turno' : '',
			'about_cta_url'             => $turnos ? $booking : '',
			'contact_eyebrow'           => 'Contacto',
			'contact_title'             => 'Escribime por Instagram',
			'contact_subtitle'          => 'Las consultas puntuales las respondo por Instagram. Los turnos, las compras y los cambios los hacés desde acá.',
			'contact_label_area'        => 'Dónde atiendo',
			'gallery_page_eyebrow'      => 'Galería',
			'gallery_page_title'        => 'Mis trabajos',
			'services_uncategorized'    => 'Otros servicios',
			'faq_subtitle'              => 'Lo que más me preguntan antes de un turno o de una compra.',
		],

		'presets'   => [
			'nude' => [
				'label'   => 'Nude — crema y rosa viejo',
				'palette' => [
					'color_accent'    => '#a4566a',
					'color_bg_light'  => '#f8f1ea',
					'color_ink_light' => '#1f1a1b',
					'color_bg_dark'   => '#1c1416',
					'color_ink_dark'  => '#f6ede6',
				],
				'fonts'   => ['font_display' => 'Cormorant Garamond', 'font_sans' => 'Jost'],
			],
		],

		'patterns'  => mj_jazbeauty_patterns($booking, $shop),
	];
	return $skins;
});

/* -------------------------------------------------------------------------
 * MJ Turnos Online: las reglas y los textos de Jaz (propuestas)
 * ---------------------------------------------------------------------- */
add_filter('mjto_option_defaults', function ($defaults) {
	$yes_no = [['value' => 'si', 'label' => 'Sí'], ['value' => 'no', 'label' => 'No']];
	$field  = function ($key, $label, $type, $options = [], $required = true) {
		return ['key' => $key, 'label' => $label, 'placeholder' => '', 'type' => $type, 'required' => $required, 'enabled' => true, 'options' => $options, 'system' => false];
	};

	return array_merge($defaults, [
		// Reserva y seña: 50 % por transferencia, reserva provisoria de 1 hora, comprobante obligatorio.
		'confirm_mode'            => 'deposit',
		'deposit_type'            => 'percent',
		'deposit_percent'         => 50,
		'deposit_hours'           => 1,
		'deposit_receipt'         => 'obligatorio',
		'deposit_receipt_text'    => 'Subí la captura de la transferencia a Mercado Pago y te confirmo el turno.',
		'deposit_receipt_confirm' => 1,
		// Cambios y cancelaciones: 48 h.
		'client_can_cancel'       => 1,
		'cancel_hours_before'     => 48,
		'deposit_on_cancel'       => 'credit',
		'cancel_late_allowed'     => 1,
		'client_can_reschedule'   => 1,
		'reschedule_hours_before' => 48,
		'reschedule_late'         => 'new_deposit',
		'credit_enabled'          => 1,
		'owner_cancel_offer'      => 1,
		// Cuenta obligatoria (con el link de invitado de cada turno como respaldo).
		'account_mode'            => 'required',
		// Avisos.
		'reminder_enabled'        => 1,
		'reminder_hours_before'   => 24,
		'reminder2_enabled'       => 1,
		'reminder2_hours_before'  => 1,
		'maintenance_enabled'     => 1,
		'maintenance_days_before' => 2,
		'reviews_enabled'         => 1,
		'reviews_auto_publish'    => 1,
		'reviews_delay_hours'     => 3,
		// Servicios por categoría (Pestañas, Cejas, Combos).
		'services_group_by_category' => 1,
		// Panel: la agenda del día con los horarios libres; freno holgado para robots.
		'panel_free_gaps'         => 1,
		'rate_limit_reads'        => 300,
		// Negocio: solo Instagram, sin dirección exacta (la zona va en Contacto).
		'business_instagram'      => 'https://instagram.com/jaz.beauty.art',
		'business_address'        => '',
		'business_whatsapp'       => '',
		// Menú y portada.
		'menu_label'              => 'Reservar turno',
		'header_cta'              => 'Reservar turno',
		'services_menu_label'     => 'Servicios',
		'home_services_title'     => 'Servicios',
		'home_services_eyebrow'   => 'Pestañas y cejas',
		'home_booking_eyebrow'    => 'Turnos',
		'home_booking_title'      => 'Reservá tu turno',
		'home_booking_subtitle'   => 'Elegí el servicio, el día y el horario. Ves la seña al instante.',
		'home_booking_button'     => 'Reservar turno',
		// Textos de la reserva.
		'booking_title'           => 'Reservá tu turno',
		'booking_subtitle'        => 'Elegí el servicio, el día y el horario. La seña es del 50 % y se transfiere a Mercado Pago.',
		'booking_success_deposit' => 'Tu turno queda reservado durante 1 hora: transferí la seña y subí el comprobante acá abajo.',
		'booking_policy_title'    => 'Antes de reservar',
		'booking_policy'          => mj_jazbeauty_text('booking_policy'),
		'services_subtitle'       => 'Todo lo que hago, con su precio, su duración y cómo cuidarlo.',
		// Mails a la clienta.
		'tpl_client_confirmed_subject' => '✨ ¡Tu turno está confirmado!',
		'tpl_client_confirmed_body'    => "✨ ¡Tu turno está confirmado!\n\nTe espero el {fecha} a las {hora} para tu {servicio}. 🤎\n\nMirá tu turno (y cómo reprogramarlo si lo necesitás): {link_turno}\n\nJaz Beauty Art",
		'tpl_client_reminder_body'     => "¡Hola {nombre}! 🤎\n\nTe recuerdo tu turno de mañana:\n\nDía: {fecha}\nHorario: {hora}\nServicio: {servicio}\n\nVení sin maquillaje en los ojos y, si usás lentes de contacto, traé su estuche. Tenés 15 minutos de tolerancia.\n\nTu turno: {link_turno}\n\nJaz Beauty Art",
		'tpl_client_reminder2_body'    => "¡Hola {nombre}! En una hora te espero para tu {servicio} ({hora}). 🤎\n\nJaz Beauty Art",
		'tpl_client_maintenance_subject' => 'Se acerca tu próximo mantenimiento 🤎',
		'tpl_client_maintenance_body'    => "¡Hola {nombre}! 🤎✨\n\nYa se acerca el momento recomendado para tu próximo mantenimiento.\nSi querés, podés reservar tu próximo turno desde acá:\n\n{link_reserva}\n\nJaz Beauty Art",
		'tpl_client_review_body'       => "¡Hola {nombre}! 🤎\n\nGracias por venir. Si tenés un minuto, contame cómo te fue con tu {servicio}:\n\n{link_opinion}\n\nJaz Beauty Art",
		// El formulario: datos y ficha de salud.
		'form_fields'             => [
			['key' => 'first_name', 'label' => 'Nombre', 'placeholder' => '', 'type' => 'text', 'required' => true, 'enabled' => true, 'options' => [], 'system' => true],
			['key' => 'last_name', 'label' => 'Apellido', 'placeholder' => '', 'type' => 'text', 'required' => true, 'enabled' => true, 'options' => [], 'system' => true],
			['key' => 'birthdate', 'label' => 'Fecha de nacimiento', 'placeholder' => '', 'type' => 'date', 'required' => true, 'enabled' => true, 'options' => [], 'system' => true],
			['key' => 'phone', 'label' => 'Teléfono', 'placeholder' => 'Ej: 11 5555 1234', 'type' => 'tel', 'required' => true, 'enabled' => true, 'options' => [], 'system' => true],
			['key' => 'email', 'label' => 'Email', 'placeholder' => 'Para mandarte la confirmación y los recordatorios', 'type' => 'email', 'required' => true, 'enabled' => true, 'options' => [], 'system' => true],
			$field('instagram', 'Instagram', 'text', [], false),
			$field('genero', 'Género', 'radio', [['value' => 'femenino', 'label' => 'Femenino'], ['value' => 'masculino', 'label' => 'Masculino'], ['value' => 'otro', 'label' => 'Otro']]),
			$field('alergias', '¿Sos alérgica/o a algún producto o sustancia?', 'radio', $yes_no),
			$field('anteojos', '¿Usás anteojos o lentes de contacto?', 'radio', [['value' => 'anteojos', 'label' => 'Anteojos'], ['value' => 'lentes', 'label' => 'Lentes de contacto'], ['value' => 'ninguno', 'label' => 'Ninguno']]),
			$field('primera_vez', '¿Es la primera vez que te realizás este procedimiento?', 'radio', $yes_no),
			$field('sensibilidad', '¿Se te lagrimean frecuentemente los ojos o tenés sensibilidad ocular?', 'radio', $yes_no),
			$field('latex', '¿Sos alérgica/o al látex?', 'radio', $yes_no),
			$field('cirugia', '¿Te realizaste alguna cirugía en la vista en los últimos 6 meses?', 'radio', $yes_no),
			['key' => 'locality', 'label' => 'Localidad', 'placeholder' => '', 'type' => 'text', 'required' => false, 'enabled' => false, 'options' => [], 'system' => true],
			['key' => 'notes', 'label' => '¿Algo que quieras contarme?', 'placeholder' => '', 'type' => 'textarea', 'required' => false, 'enabled' => true, 'options' => [], 'system' => true],
		],
		// La ficha de cada visita: pestañas y cejas.
		'ficha_blocks'            => mj_jazbeauty_ficha(),
		'payment_methods'         => "Transferencia Mercado Pago\nEfectivo\nSaldo a favor",
	]);
});

/** Ficha técnica de pestañas y cejas (se edita en Turnos → Ajustes → Ficha técnica). */
function mj_jazbeauty_ficha() {
	$f = function ($key, $label, $type, $extra = []) {
		return array_merge(['key' => $key, 'label' => $label, 'type' => $type, 'options' => [], 'required' => false, 'carry' => false, 'role' => '', 'offset_days' => 0, 'offset_months' => 0, 'help' => '', 'placeholder' => '', 'width' => 'full'], $extra);
	};
	$o = function ($value, $label, $warn = false, $text = '') {
		return ['value' => $value, 'label' => $label, 'warn' => $warn, 'warn_text' => $text];
	};
	return [
		[
			'key'    => 'salud',
			'title'  => 'Salud',
			'fields' => [
				$f('alergias_detalle', 'Alergias o sensibilidad (detalle)', 'textarea', ['carry' => true]),
				$f('reaccion', 'Reacción en la visita', 'radio', ['options' => [$o('ninguna', 'Ninguna'), $o('leve', 'Leve'), $o('fuerte', 'Fuerte', true, 'Tuvo una reacción fuerte: revisá los productos antes del próximo turno.')]]),
			],
		],
		[
			'key'    => 'trabajo',
			'title'  => 'Trabajo',
			'fields' => [
				$f('curvatura', 'Curvatura / molde', 'text', ['carry' => true, 'placeholder' => 'Ej: M, L, 3D…']),
				$f('tiempos', 'Tiempos de exposición', 'text', ['carry' => true]),
				$f('producto', 'Productos usados', 'text', ['role' => 'product', 'carry' => true]),
				$f('medidas', 'Medidas y grosor (extensiones)', 'text', ['carry' => true]),
				$f('color', 'Color (tinte / henna)', 'text', ['carry' => true]),
				$f('observaciones', 'Observaciones', 'textarea'),
			],
		],
		[
			'key'    => 'caja',
			'title'  => 'Caja y seguimiento',
			'fields' => [
				$f('dejo_sena', '¿Dejó seña?', 'toggle', ['role' => 'deposit_flag', 'width' => 'half']),
				$f('sena_monto', 'Monto de la seña', 'money', ['role' => 'deposit', 'width' => 'half']),
				$f('metodo_pago', 'Método de pago', 'radio', ['role' => 'payment_method']),
				$f('total', 'Total cobrado', 'money', ['role' => 'total']),
				$f('mantenimiento', 'Próximo mantenimiento', 'date', ['role' => 'retouch_date', 'offset_days' => 28, 'help' => 'Sale solo del período del servicio. Cambiala si esta clienta tiene que volver antes o después.']),
			],
		],
	];
}

/* -------------------------------------------------------------------------
 * MJ Fidelidad: propuestas
 * ---------------------------------------------------------------------- */
add_filter('mjfid_option_defaults', function ($defaults) {
	return array_merge($defaults, [
		'stamps_per_card' => 10,
		'reward_text'     => 'Un servicio de regalo',
		'card_title'      => 'Tu tarjeta de fidelidad 🤎',
		'card_empty'      => 'Todavía no tenés sellos. ¡El primero llega con tu próximo turno!',
	]);
});

/* -------------------------------------------------------------------------
 * Plantillas de bloques
 * ---------------------------------------------------------------------- */

/** Bloque MJ con atributos (formato de Gutenberg). */
function mj_jazbeauty_block($name, array $attrs = []) {
	return '<!-- wp:mj/' . $name . ($attrs ? ' ' . wp_json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '') . ' /-->';
}

/** Botón común de Gutenberg (los estiliza el tema). */
function mj_jazbeauty_buttons(array $links) {
	$out = '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons">';
	foreach ($links as [$text, $url]) {
		$out .= '<!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="' . esc_url($url) . '">' . esc_html($text) . '</a></div><!-- /wp:button -->';
	}
	return $out . '</div><!-- /wp:buttons -->';
}

/** Párrafos y títulos de Gutenberg a partir de un texto con "## Título" y párrafos separados por línea en blanco. */
function mj_jazbeauty_prose($text) {
	$out = '';
	foreach (preg_split("/\n\s*\n/", trim((string) $text)) as $chunk) {
		$chunk = trim($chunk);
		if (0 === strpos($chunk, '## ')) {
			$out .= '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html(substr($chunk, 3)) . '</h2><!-- /wp:heading -->';
		} elseif ('' !== $chunk) {
			$out .= '<!-- wp:paragraph --><p>' . nl2br(esc_html($chunk)) . '</p><!-- /wp:paragraph -->';
		}
	}
	return $out;
}

function mj_jazbeauty_patterns($booking, $shop) {
	$faq = [];
	foreach (mj_jazbeauty_text('faq') as [$q, $a]) {
		$faq[] = ['q' => $q, 'a' => $a];
	}
	return [
		'jaz-portada'   => [
			'title'       => 'Portada de Jaz Beauty Art',
			'description' => 'Logo y frase, presentación corta, reservar turno y accesos a lo demás.',
			'content'     => mj_jazbeauty_block('hero', ['badge' => 'Pestañas y cejas', 'title' => 'Jaz Beauty Art', 'titleHighlight' => 'Detalles que hacen la diferencia.', 'ctaPrimaryText' => 'Reservar turno', 'ctaPrimaryUrl' => $booking, 'showStats' => false])
				. mj_jazbeauty_block('nosotros', ['layout' => 'teaser', 'title' => 'Hola, soy Jaz ♡', 'linkLabel' => 'Conoceme'])
				. mj_jazbeauty_buttons([['Servicios', '/servicios'], ['Mis trabajos', '/mis-trabajos'], ['Beauty Shop', $shop], ['Sobre mí', '/nosotros'], ['Preguntas frecuentes', '/faq']])
				. mj_jazbeauty_block('galeria', ['title' => 'Mis trabajos'])
				. mj_jazbeauty_block('opiniones', ['source' => 'turnos']),
		],
		'jaz-sobre-mi'  => [
			'title'       => 'Sobre mí (Jaz)',
			'description' => 'Presentación completa: mi historia, mi emprendimiento y mi formación.',
			'content'     => mj_jazbeauty_block('nosotros', ['layout' => 'full', 'title' => 'Hola, soy Jaz ♡']) . mj_jazbeauty_prose(mj_jazbeauty_text('about')),
		],
		'jaz-faq'       => [
			'title'       => 'Preguntas frecuentes (Jaz)',
			'description' => 'Las preguntas de siempre, con sus respuestas editables.',
			'content'     => mj_jazbeauty_block('encabezado', ['eyebrow' => 'Ayuda', 'title' => 'Preguntas frecuentes']) . mj_jazbeauty_block('faq', ['items' => $faq, 'showContactCard' => true]),
		],
		'jaz-politicas' => [
			'title'       => 'Políticas (Jaz)',
			'description' => 'Seña, plazos, cambios, tolerancia, inasistencias, pagos y compras, en tono cálido.',
			'content'     => mj_jazbeauty_block('encabezado', ['eyebrow' => 'Jaz Beauty Art', 'title' => 'Políticas', 'subtitle' => 'Para cuidar tu turno y el de todas.']) . mj_jazbeauty_prose(mj_jazbeauty_text('policies')),
		],
		'jaz-trabajos'  => [
			'title'       => 'Mis trabajos (Jaz)',
			'description' => 'La galería de trabajos con el nombre de cada tratamiento.',
			'content'     => mj_jazbeauty_block('encabezado', ['eyebrow' => 'Galería', 'title' => 'Mis trabajos']) . mj_jazbeauty_block('galeria', ['showCaptions' => true, 'filter' => true]),
		],
	];
}

/* -------------------------------------------------------------------------
 * Estilos de la skin (después del CSS del padre y de su inline de diseño)
 * ---------------------------------------------------------------------- */
add_action('wp_enqueue_scripts', function () {
	$path = get_stylesheet_directory() . '/jazbeauty.css';
	if (!file_exists($path)) {
		return;
	}
	wp_enqueue_style('mj-jazbeauty', get_stylesheet_directory_uri() . '/jazbeauty.css', ['mj-app'], (string) filemtime($path));
}, 30);
