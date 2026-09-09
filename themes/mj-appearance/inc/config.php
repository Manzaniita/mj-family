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

	/* Medios de pago disponibles (gateways habilitados en WooCommerce). */
	$gateways = [];
	if ($woo && function_exists('WC') && WC()->payment_gateways()) {
		foreach (WC()->payment_gateways()->get_available_payment_gateways() as $id => $gw) {
			$gateways[] = [
				'id'          => $id,
				'title'       => wp_strip_all_tags($gw->get_title()),
				'description' => wp_strip_all_tags($gw->get_description() ?: ''),
			];
		}
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

	/* Usuario logueado (si hay sesión). */
	$user = null;
	if (is_user_logged_in()) {
		$current = wp_get_current_user();
		$user    = [
			'id'    => $current->ID,
			'name'  => $current->display_name,
			'email' => $current->user_email,
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
		/* Presentación (react-app/src/lib/config.js): tema por defecto, skin, animación, intro. */
		'theme'                 => mj_theme_default(),
		'skin'                  => mj_skin(),
		'motion'                => $options['motion_level'],
		'intro'                 => (bool) $options['intro_enabled'],
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
	];

	/*
	 * Otros plugins MJ agregan sus datos acá (p. ej. MJ Dólar → exchangeRate).
	 * La SPA trata cada clave extra como opcional: sin plugin, no muestra nada.
	 */
	return apply_filters('mj_app_config', $config);
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
