<?php
/**
 * Configuración inyectada a la SPA como window.MJ.
 *
 * Nada hardcodeado en el frontend: moneda, país base y medios de pago salen
 * de WooCommerce; anuncios, contacto, redes, "Nosotros" y FAQs salen de los
 * ajustes del tema (Apariencia → MJ Appearance, ver inc/settings.php).
 *
 * Todo está guardado con class_exists('WooCommerce'): sin WooCommerce el
 * config devuelve defaults razonables en vez de fatal-error.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Helpers de parseo (textareas de ajustes → arrays)
 * ---------------------------------------------------------------------- */

/* Textarea con "un ítem por línea" → array de líneas no vacías. */
function mj_lines_to_array($text) {
	$lines = preg_split('/\r\n|\r|\n/', (string) $text);
	$lines = array_map('trim', $lines);
	return array_values(array_filter($lines, function ($line) {
		return '' !== $line;
	}));
}

/* Textarea "Pregunta | Respuesta" por línea → [{q, a}]. */
function mj_parse_faqs($text) {
	$faqs = [];
	foreach (mj_lines_to_array($text) as $line) {
		$parts = array_map('trim', explode('|', $line, 2));
		if (2 === count($parts) && '' !== $parts[0] && '' !== $parts[1]) {
			$faqs[] = ['q' => $parts[0], 'a' => $parts[1]];
		}
	}
	return $faqs;
}

/* Textarea "Texto | #color (opcional) | ícono (opcional)" por línea → [{text, color, icon}]. */
function mj_parse_announcements($text) {
	$out = [];
	foreach (mj_lines_to_array($text) as $line) {
		$parts = array_map('trim', explode('|', $line));
		$item  = ['text' => (string) array_shift($parts), 'color' => null, 'icon' => null];
		foreach ($parts as $part) {
			if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $part)) {
				$item['color'] = $part;
			} elseif (preg_match('/^[a-z0-9-]+$/', $part)) {
				$item['icon'] = $part;
			}
		}
		if ('' !== $item['text']) {
			$out[] = $item;
		}
	}
	return $out;
}

/* Textarea "Texto | ícono (opcional)" por línea → [{text, icon}]. */
function mj_parse_badges($text) {
	$out = [];
	foreach (mj_lines_to_array($text) as $line) {
		$parts = array_map('trim', explode('|', $line, 2));
		$item  = ['text' => $parts[0], 'icon' => null];
		if (2 === count($parts) && preg_match('/^[a-z0-9-]+$/', $parts[1])) {
			$item['icon'] = $parts[1];
		}
		if ('' !== $item['text']) {
			$out[] = $item;
		}
	}
	return $out;
}

/*
 * Textarea "Número | Etiqueta" por línea → [{n, label}] (cifras de "Nosotros").
 * El "número" se conserva como texto: acepta "+500", "10 años", "98%", etc.
 */
function mj_parse_stats($text) {
	$out = [];
	foreach (mj_lines_to_array($text) as $line) {
		$parts = array_map('trim', explode('|', $line, 2));
		if (2 === count($parts) && '' !== $parts[0] && '' !== $parts[1]) {
			$out[] = ['n' => $parts[0], 'label' => $parts[1]];
		}
	}
	return $out;
}

/* Textarea "Nombre | Número" por línea → [{name, number}] (number: solo dígitos). */
function mj_parse_whatsapp($text) {
	$out = [];
	foreach (mj_lines_to_array($text) as $line) {
		$parts  = array_map('trim', explode('|', $line, 2));
		$number = preg_replace('/\D+/', '', end($parts));
		if ('' !== $number) {
			$out[] = [
				'name'   => 2 === count($parts) ? $parts[0] : '',
				'number' => $number,
			];
		}
	}
	return $out;
}

/* Parsea columnas del pie con títulos y enlaces "Texto | /ruta | _blank" a arrays. */
function mj_parse_footer_columns($text) {
	if (empty($text) || !is_string($text)) {
		return null;
	}
	$lines       = preg_split('/\r\n|\r|\n/', trim($text));
	$columns     = [];
	$current_col = null;

	foreach ($lines as $raw_line) {
		$line = trim($raw_line);
		if ('' === $line) {
			continue;
		}
		if (0 === strpos($line, '#')) {
			if ($current_col && (!empty($current_col['title']) || !empty($current_col['links']))) {
				$columns[] = $current_col;
			}
			$current_col = [
				'title' => trim(ltrim($line, '#')),
				'links' => [],
			];
			continue;
		}
		if (!$current_col) {
			$current_col = [
				'title' => '',
				'links' => [],
			];
		}
		$parts = array_map('trim', explode('|', $line));
		if (count($parts) >= 2 && '' !== $parts[0] && '' !== $parts[1]) {
			$target = isset($parts[2]) && in_array(trim($parts[2]), ['_blank', 'blank'], true) ? '_blank' : '_self';
			$url    = $parts[1];
			$clean_url = 0 === strpos($url, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) ? esc_url_raw($url) : '/' . ltrim(sanitize_text_field($url), '/');
			$current_col['links'][] = [$parts[0], $clean_url, $target];
		} elseif (count($parts) === 1 && '' !== $parts[0]) {
			$current_col['links'][] = [$parts[0], '#', '_self'];
		}
	}
	if ($current_col && (!empty($current_col['title']) || !empty($current_col['links']))) {
		$columns[] = $current_col;
	}
	return !empty($columns) ? $columns : null;
}

/* Lista de IDs de adjuntos "1,2,3" → URLs (tamaño large) de las que existan. */
function mj_attachment_urls($ids_csv) {
	$urls = [];
	foreach (array_filter(array_map('absint', explode(',', (string) $ids_csv))) as $id) {
		$url = wp_get_attachment_image_url($id, 'large');
		if ($url) {
			$urls[] = $url;
		}
	}
	return $urls;
}

/* -------------------------------------------------------------------------
 * Umbral de envío gratis
 *
 * Prioridad: ajuste del tema (free_shipping_override > 0). Si es 0, AUTO:
 * se recorren todas las zonas de envío (incluida la zona 0 "Resto del mundo")
 * buscando métodos free_shipping habilitados y se toma el MENOR min_amount
 * numérico. Si no hay ninguno → 0 (la SPA oculta la barra de progreso).
 * ---------------------------------------------------------------------- */
function mj_free_shipping_threshold(array $options) {
	$override = isset($options['free_shipping_override']) ? absint($options['free_shipping_override']) : 0;
	if ($override > 0) {
		return (float) $override;
	}

	$min = null;

	if (class_exists('WooCommerce') && class_exists('WC_Shipping_Zones') && class_exists('WC_Shipping_Zone')) {
		$zones = [];

		foreach (WC_Shipping_Zones::get_zones() as $zone) {
			$zones[] = new WC_Shipping_Zone($zone['zone_id']);
		}
		$zones[] = new WC_Shipping_Zone(0); // Zona por defecto: "Resto del mundo".

		foreach ($zones as $zone_obj) {
			foreach ($zone_obj->get_shipping_methods() as $method) {
				if ('free_shipping' !== $method->id || 'yes' !== $method->enabled) {
					continue;
				}
				$amount = $method->get_option('min_amount');
				if (is_numeric($amount)) {
					$amount = (float) $amount;
					if (null === $min || $amount < $min) {
						$min = $amount;
					}
				}
			}
		}
	}

	return (float) apply_filters('mj_free_shipping_threshold', null === $min ? 0 : $min);
}

/* -------------------------------------------------------------------------
 * Config completa (contrato con la SPA — nombres exactos)
 * ---------------------------------------------------------------------- */
function mj_app_config() {
	$woo     = class_exists('WooCommerce');
	$options = mj_get_options();

	/*
	 * Medios de pago disponibles (gateways habilitados en WooCommerce).
	 *
	 * La lista se arma una sola vez, al cargar la página, y después la tienda
	 * cambia de envío sin recargar. Por eso se pide ENTERA: MJ Envíos recorta
	 * los medios según el envío que haya en la sesión, y con la lista recortada
	 * los medios que se fueron no volverían nunca. El recorte lo hace el
	 * checkout, que sí sabe qué envío está elegido ahora (ver el filtro
	 * mje_filter_gateways). Sin el plugin, el filtro no existe y no cambia nada.
	 */
	$gateways = [];
	if ($woo && function_exists('WC') && WC()->payment_gateways()) {
		add_filter('mje_filter_gateways', '__return_false');
		foreach (WC()->payment_gateways()->get_available_payment_gateways() as $id => $gw) {
			$gateways[] = [
				'id'          => $id,
				'title'       => wp_strip_all_tags($gw->get_title()),
				'description' => wp_strip_all_tags($gw->get_description() ?: ''),
			];
		}
		remove_filter('mje_filter_gateways', '__return_false');
	}

	/* Moneda de la tienda (defaults ARS si WooCommerce está inactivo). */
	$currency = [
		'code'        => 'ARS',
		'symbol'      => '$',
		'decimals'    => 2,
		'thousandSep' => '.',
		'decimalSep'  => ',',
		'position'    => 'left_space',
	];
	if ($woo) {
		$code     = get_woocommerce_currency();
		$currency = [
			'code'        => $code,
			// Woo devuelve el símbolo como entidad HTML (&#36;): html_entity_decode,
			// wp_specialchars_decode no cubre entidades numéricas.
			'symbol'      => html_entity_decode(get_woocommerce_currency_symbol($code), ENT_QUOTES, 'UTF-8'),
			'decimals'    => (int) wc_get_price_decimals(),
			'thousandSep' => wc_get_price_thousand_separator(),
			'decimalSep'  => wc_get_price_decimal_separator(),
			'position'    => get_option('woocommerce_currency_pos', 'left_space'),
		];
	}

	/* País base de la tienda. */
	$base_country = 'AR';
	if ($woo && WC()->countries) {
		$base_country = WC()->countries->get_base_country();
	} else {
		$default = get_option('woocommerce_default_country');
		if (is_string($default) && '' !== $default) {
			$parts        = explode(':', $default); // Formato "AR:SF" → "AR".
			$base_country = '' !== $parts[0] ? $parts[0] : 'AR';
		}
	}

	$user = null;
	if (is_user_logged_in()) {
		$current = wp_get_current_user();
		$user    = [
			'id'                 => $current->ID,
			'name'               => $current->display_name,
			'email'              => $current->user_email,
			'mustChangePassword' => (bool) get_user_meta($current->ID, 'mj_must_change_password', true),
		];
	}

	$config = [
		'restUrl'               => esc_url_raw(rest_url()),
		/* Nonces del carrito (Store API) y de mj/v1 — ver inc/session.php. */
		'nonce'                 => mj_session_nonces()['nonce'],
		'wpNonce'               => mj_session_nonces()['wpNonce'],
		'siteName'              => get_bloginfo('name'),
		'tagline'               => get_bloginfo('description'),
		'locale'                => str_replace('_', '-', get_locale()),
		'currency'              => $currency,
		'baseCountry'           => $base_country,
		'freeShippingThreshold' => mj_free_shipping_threshold($options),
		'menuCategories'        => array_values(array_filter(explode(',', (string) $options['menu_categories']))),
		'announcements'         => mj_parse_announcements($options['announcements']),
		'productBadges'         => mj_parse_badges($options['product_badges']),
		'promoBanners'          => mj_attachment_urls($options['promo_banners']),
		'hero'                  => [
			'badge'         => $options['hero_badge'],
			'title'         => $options['hero_title'],
			'titleHighlight'=> $options['hero_title_highlight'],
			'subtitle'      => $options['hero_subtitle'],
			'ctaPrimary'    => ['text' => $options['hero_cta_primary_text'], 'url' => $options['hero_cta_primary_url']],
			'ctaSecondary'  => ['text' => $options['hero_cta_secondary_text'], 'url' => $options['hero_cta_secondary_url']],
			'images'        => mj_attachment_urls($options['hero_bg_images']),
		],
		'reviewsEnabled'        => !$woo || 'yes' === get_option('woocommerce_enable_reviews', 'yes'),
		'paymentMethods'        => $gateways,
		'contact'               => [
			'email'    => $options['contact_email'],
			'phone'    => $options['contact_phone'],
			'whatsapp' => $options['contact_whatsapp'],
			'address'  => $options['contact_address'],
			'map'      => $options['contact_map'],
			/* Zona de atención y qué se muestra (Contacto): los defaults dejan todo como siempre. */
			'area'          => (string) $options['contact_area'],
			'showInstagram' => !empty($options['contact_show_instagram']),
			'showForm'      => !empty($options['contact_show_form']),
		],
		'social'                => [
			'instagram' => $options['social_instagram'],
			'facebook'  => $options['social_facebook'],
			'twitter'   => $options['social_twitter'],
			'youtube'   => $options['social_youtube'],
			'tiktok'    => $options['social_tiktok'],
		],
		'aboutText'             => $options['about_text'],
		'faqs'                  => mj_parse_faqs($options['faqs']),
		'whatsappNumbers'       => mj_parse_whatsapp($options['whatsapp_numbers']),
		'quoteInfo'             => $options['quote_info'],
		'user'                  => $user,
		'registrationEnabled'   => 'yes' === get_option('woocommerce_enable_myaccount_registration'),
		/*
		 * Sesión y acceso (Apariencia → MJ Appearance → Sesión y acceso).
		 * La SPA los usa para el formulario de acceso y para decidir si recarga
		 * al entrar (única forma de que WordPress pinte la barra de admin).
		 */
		'session'               => [
			'rememberDefault' => (bool) $options['login_remember_default'],
			'reloadOnLogin'   => (bool) $options['login_reload'],
			'accountPath'     => mj_account_path(),
			'adminBar'        => mj_admin_bar_visible(),
		],
		/*
		 * Compra rápida (Apariencia → MJ Appearance → Sesión y acceso). Solo dice si está
		 * habilitada: si el cliente califica lo decide la SPA cuando ve sus datos guardados.
		 */
		'express'               => [
			'enabled' => (bool) $options['express_checkout'],
			'auto'    => (bool) $options['express_auto'],
			'label'   => '' !== trim((string) $options['express_label']) ? trim((string) $options['express_label']) : 'Comprar ahora',
		],
		/* Presentación (react-app/src/lib/config.js): tema por defecto, skin, animación, intro, efecto del cambio de tema. */
		'theme'                 => mj_theme_default(),
		'themeSwitch'           => $options['theme_switch'],
		'skin'                  => mj_skin(),
		'motion'                => $options['motion_level'],
		'intro'                 => (bool) $options['intro_enabled'],
		/* Botón fijo abajo (Anuncios y avisos). Apagado por defecto. */
		'stickyCta'             => mj_sticky_cta_config($options),
		/* 3.4 — logo, etiquetas de marca, textos, secciones de la portada y legales. */
		'logo'                  => mj_logo_config(),
		'brandLabel'            => mj_brand_labels(),
		'copy'                  => mj_copy(),
		'homeHidden'            => mj_home_hidden(),
		'legal'                 => [
			'terms'   => mj_parse_legal($options['legal_terms']),
			'privacy' => mj_parse_legal($options['legal_privacy']),
		],
		/* 3.5 — orden de las secciones de la portada y página "Nosotros". */
		'homeOrder'             => mj_home_order(),
		'about'                 => [
			'image'      => absint($options['about_image']) ? (string) (wp_get_attachment_image_url(absint($options['about_image']), 'large') ?: '') : '',
			'highlights' => mj_parse_badges($options['about_highlights']),
			'stats'      => mj_parse_stats($options['about_stats']),
		],
		/* Cabecera y pie configurables en todos los temas y Gutenberg. */
		'header'                => apply_filters('mj_header_config', [
			'sticky'              => !empty($options['header_sticky']) ? (string) $options['header_sticky'] : 'sticky',
			'style'               => !empty($options['header_style']) ? (string) $options['header_style'] : 'glass',
			'border'              => !empty($options['header_border']) ? (string) $options['header_border'] : 'line',
			'logoPosition'        => !empty($options['header_logo_pos']) ? (string) $options['header_logo_pos'] : 'left',
			'searchPosition'      => !empty($options['header_search_pos']) ? (string) $options['header_search_pos'] : 'center',
			'menuPosition'        => !empty($options['header_menu_pos']) ? (string) $options['header_menu_pos'] : 'below',
			'showTopBar'          => !isset($options['header_show_topbar']) || !empty($options['header_show_topbar']),
			'showFreeShipping'    => !isset($options['header_show_shipping']) || !empty($options['header_show_shipping']),
			'showCart'            => !isset($options['header_show_cart']) || !empty($options['header_show_cart']),
			'showWishlist'        => !isset($options['header_show_wishlist']) || !empty($options['header_show_wishlist']),
			'showCompare'         => !isset($options['header_show_compare']) || !empty($options['header_show_compare']),
			'showAccount'         => !isset($options['header_show_account']) || !empty($options['header_show_account']),
			'showThemeToggle'     => !isset($options['header_show_theme_toggle']) || !empty($options['header_show_theme_toggle']),
			'showExchangeRate'    => !isset($options['header_show_exchange_rate']) || !empty($options['header_show_exchange_rate']),
			'showPaymentSelector' => !isset($options['header_show_payment_selector']) || !empty($options['header_show_payment_selector']),
			'showTurnosCta'       => !isset($options['header_show_turnos_cta']) || !empty($options['header_show_turnos_cta']),
			'ctaText'             => isset($options['header_cta_text']) ? (string) $options['header_cta_text'] : '',
			'ctaUrl'              => isset($options['header_cta_url']) ? (string) $options['header_cta_url'] : '',
			'customButtonText'    => isset($options['header_custom_btn_text']) ? (string) $options['header_custom_btn_text'] : '',
			'customButtonUrl'     => isset($options['header_custom_btn_url']) ? (string) $options['header_custom_btn_url'] : '',
			'customButtonIcon'    => isset($options['header_custom_btn_icon']) ? (string) $options['header_custom_btn_icon'] : '',
			'customButtonTarget'  => !empty($options['header_custom_btn_target']) ? (string) $options['header_custom_btn_target'] : '_self',
			'bgColor'             => isset($options['header_bg_color']) ? (string) $options['header_bg_color'] : '',
			'inkColor'            => isset($options['header_ink_color']) ? (string) $options['header_ink_color'] : '',
			'accentColor'         => isset($options['header_accent_color']) ? (string) $options['header_accent_color'] : '',
		], $options),
		'footer'                => apply_filters('mj_footer_config', [
			'showNewsletter'   => !isset($options['footer_show_newsletter']) || !empty($options['footer_show_newsletter']),
			'columnsCount'     => !empty($options['footer_columns_count']) ? (int) $options['footer_columns_count'] : 4,
			'showLogo'         => !isset($options['footer_show_logo']) || !empty($options['footer_show_logo']),
			'showContact'      => !isset($options['footer_show_contact']) || !empty($options['footer_show_contact']),
			'showSocials'      => !isset($options['footer_show_socials']) || !empty($options['footer_show_socials']),
			'showPayments'     => !isset($options['footer_show_payments']) || !empty($options['footer_show_payments']),
			'showLegalBar'     => !isset($options['footer_show_legal']) || !empty($options['footer_show_legal']),
			'copyright'        => isset($options['footer_copyright']) ? (string) $options['footer_copyright'] : '',
			'columns'          => mj_parse_footer_columns(isset($options['footer_custom_columns']) ? $options['footer_custom_columns'] : ''),
			'border'           => !empty($options['footer_border']) ? (string) $options['footer_border'] : 'line',
			'bgColor'          => isset($options['footer_bg_color']) ? (string) $options['footer_bg_color'] : '',
			'inkColor'         => isset($options['footer_ink_color']) ? (string) $options['footer_ink_color'] : '',
			'accentColor'      => isset($options['footer_accent_color']) ? (string) $options['footer_accent_color'] : '',
		], $options),
		'templateParts'         => [
			'header' => function_exists('mj_get_block_template_part_html') ? mj_get_block_template_part_html('header') : '',
			'footer' => function_exists('mj_get_block_template_part_html') ? mj_get_block_template_part_html('footer') : '',
		],
	];

	/*
	 * Accesos extra en «Mi cuenta». Un cliente puede ser además otra cosa —revendedor,
	 * por ejemplo— y esa pantalla tiene que poder encontrarse desde su cuenta. El tema no
	 * sabe qué plugins hay: solo dibuja lo que le pasen por acá.
	 *
	 * Cada entrada: [ 'key' => '', 'label' => '', 'href' => '', 'description' => '' ].
	 */
	$config['accountLinks'] = mj_account_links();

	/*
	 * Otros plugins MJ agregan sus datos acá (p. ej. MJ Dólar → exchangeRate).
	 * La SPA trata cada clave extra como opcional: sin plugin, no muestra nada.
	 */
	$config = apply_filters('mj_app_config', $config);

	/*
	 * Barra de navegación (inc/nav.php). Va DESPUÉS del filtro a propósito: las
	 * fuentes del menú salen de lo que publica cada plugin (Turnos, Colegas), así
	 * que hasta acá no se sabe qué hay para poner. Lo que llega a la SPA ya está
	 * filtrado por audiencia: un acceso que es solo para el dueño no se le manda
	 * al navegador del cliente.
	 */
	mj_nav_prime($config);
	$config['nav'] = mj_nav_payload();

	return $config;
}

/**
 * Accesos extra del menú de «Mi cuenta», declarados por los plugins MJ.
 *
 * Solo para quien inició sesión: son atajos a pantallas propias de ese usuario.
 *
 * @return array
 */
function mj_account_links() {
	if (!is_user_logged_in()) {
		return [];
	}

	/**
	 * Filtra los accesos extra de «Mi cuenta».
	 *
	 * @param array $links [ { key, label, href, description } ]
	 */
	$links = apply_filters('mj_account_links', []);
	if (!is_array($links)) {
		return [];
	}

	$out = [];
	foreach ($links as $link) {
		if (!is_array($link)) {
			continue;
		}
		$label = isset($link['label']) ? sanitize_text_field((string) $link['label']) : '';
		$href  = isset($link['href']) ? esc_url_raw((string) $link['href']) : '';
		if ('' === $label || '' === $href) {
			continue;
		}
		$out[] = [
			'key'         => isset($link['key']) ? sanitize_key((string) $link['key']) : sanitize_key($label),
			'label'       => $label,
			'href'        => $href,
			'description' => isset($link['description']) ? sanitize_text_field((string) $link['description']) : '',
		];
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Aviso en wp-admin si WooCommerce está inactivo.
 * ---------------------------------------------------------------------- */
add_action('admin_notices', function () {
	if (class_exists('WooCommerce') || !current_user_can('activate_plugins')) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>MJ Appearance:</strong> WooCommerce está inactivo. El tema lo requiere para catálogo, carrito, checkout y medios de pago.</p></div>';
});
