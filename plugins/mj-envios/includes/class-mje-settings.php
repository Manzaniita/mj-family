<?php
/**
 * Ajustes: cuatro opciones.
 *   mje_settings  ajustes generales (origen, peso, precio, textos, CP, avisos)
 *   mje_methods   los métodos de envío, indexados por slug  (ver MJE_Methods)
 *   mje_fields    catálogo de datos que se le piden al cliente (ver MJE_Fields)
 *   mje_carriers  credenciales y URLs de cada correo          (ver MJE_Carrier)
 *
 * Regla de toda la configuración por método: 'heredar' (o -1 en los numéricos)
 * significa "lo que diga el ajuste general". Así subir un default después sigue
 * alcanzando a todos los métodos que nunca se tocaron a mano.
 *
 * Acá no hay ni un valor de negocio fijo: origen, pesos, redondeo, textos,
 * plazos, proveedores de código postal y URLs salen todos de esta opción.
 */

defined('ABSPATH') || exit;

class MJE_Settings {

	const OPTION   = 'mje_settings';
	const METHODS  = 'mje_methods';
	const FIELDS   = 'mje_fields';
	const CARRIERS = 'mje_carriers';

	private static $cache = null;

	/* ------------------------------ Generales ---------------------------- */

	public static function defaults() {
		return [
			/* Interruptor maestro ---------------------------------------- */
			'enabled'         => 1,
			'replace_native'  => 1,   // Cuando hay métodos MJ, se ocultan las tarifas de las zonas de WooCommerce.

			/* Correos con plugin propio (Andreani, Correo Argentino…) ---- */
			'native_adopt'       => 1, // Cada servicio suyo que cotiza queda como método propio, editable.
			'native_allow_zero'  => 0, // Aceptar $0 como precio real del correo (casi siempre es "no pude cotizar").

			/* Carteles de la lista del checkout -------------------------- */
			'badges_enabled'  => 1,
			'badge_cheapest'  => 'MÁS BARATO',
			'badge_fastest'   => 'MÁS RÁPIDO',

			/* Cotizador en la ficha del producto -------------------------- */
			'quote_product'   => 1,
			'quote_title'     => '¿Cuánto sale el envío?',
			'quote_button'    => 'Calcular',
			'quote_help'      => 'Poné tu código postal y te decimos cuánto sale y cuándo llega.',

			/* Sucursales ------------------------------------------------- */
			'branch_sort'     => 'cercania', // cercania | nombre
			'branch_max'      => 25,         // Cuántas se le ofrecen al cliente (0 = todas).

			/* Desde dónde sale el paquete -------------------------------- */
			'origin_name'     => '',
			'origin_address'  => '',
			'origin_extra'    => '', // Piso, depto, referencia.
			'origin_city'     => '',
			'origin_state'    => '',
			'origin_postcode' => '',
			'origin_country'  => '', // '' = el país base de la tienda.
			'origin_phone'    => '',
			'origin_email'    => '',
			'origin_branch'   => '', // Sucursal de despacho del correo, si la pide.

			/* Peso y volumen --------------------------------------------- */
			'weight_default'  => 0.5,  // Peso de un producto sin peso cargado (en la unidad de WooCommerce).
			'weight_extra'    => 0,    // Se suma una vez por paquete (embalaje).
			'weight_min'      => 0,    // Piso del paquete (0 = sin piso).
			'weight_max'      => 0,    // Techo por bulto (0 = sin techo). Arriba de esto se parte en bultos.
			'dim_length'      => 0,
			'dim_width'       => 0,
			'dim_height'      => 0,
			'dim_stack'       => 'alto', // Cómo se acumulan las dimensiones de varios ítems: alto | mayor.
			'volumetric'      => 0,      // Divisor de peso volumétrico (0 = no se usa).

			/* Precio ------------------------------------------------------ */
			'markup_pct'      => 0,      // Se suma al costo del correo (manipuleo, embalaje).
			'markup_fixed'    => 0,
			'insurance_pct'   => 0,      // Seguro sobre el valor declarado.
			'declared_value'  => 'subtotal', // subtotal | none | percent
			'declared_pct'    => 100,
			'min_cost'        => 0,      // Piso del precio final (0 = sin piso).
			'rounding'        => class_exists('MJD_Settings') ? (int) MJD_Settings::get('rounding') : 100,
			'rounding_mode'   => class_exists('MJD_Settings') ? (string) MJD_Settings::get('rounding_mode') : 'nearest',

			/* Envío gratis ------------------------------------------------ */
			'free_enabled'    => 0,
			'free_min'        => 0,      // Subtotal desde el que el envío es gratis (0 = no por monto).
			'free_methods'    => '',     // Slugs CSV que pueden quedar gratis ('' = todos los que lo permitan).
			'free_states'     => '',     // Provincias donde vale el envío gratis ('' = todas).
			'free_label'      => 'GRATIS',

			/* Cotización -------------------------------------------------- */
			'cache_hours'     => 12,     // Cuánto se guarda una cotización del correo.
			'timeout'         => 8,      // Segundos de espera a la API del correo.
			'on_error'        => 'tabla', // tabla | coordinar | ocultar
			'log_errors'      => 1,

			/* Código postal, provincia y localidad ------------------------ */
			'geo_providers'   => 'tabla,georef', // Orden de consulta. Los que estén acá, y en ese orden.
			'geo_url'         => 'https://apis.datos.gob.ar/georef/api', // API pública del Estado.
			'geo_cache_days'  => 30,
			'cp_min_len'      => 4,
			'cp_max_len'      => 8,
			'cp_required'     => 1,      // El CP es obligatorio para cotizar.
			'cp_strict'       => 0,      // Además tiene que resolver a una localidad conocida.
			'geo_learn'       => 1,      // Lo que se carga a mano queda guardado para la próxima.

			/* Textos de la tienda ---------------------------------------- */
			'step_title'      => 'Envío',
			'step_intro'      => 'Elegí cómo querés recibir tu compra.',
			'cp_label'        => 'Código postal',
			'cp_help'         => 'Con el código postal calculamos el envío.',
			'cp_invalid'      => 'No reconocemos ese código postal. Revisalo o elegí la localidad a mano.',
			'cp_ask'          => 'Poné tu código postal para ver las formas de envío y su costo.',
			'none_text'       => 'Todavía no tenemos envío para ese destino. Escribinos y lo resolvemos.',
			'coordinate_text' => 'El costo del envío se acuerda después de la compra.',
			'error_text'      => 'No pudimos cotizar en este momento. Elegí otra forma o escribinos.',
			'eta_text'        => 'Llega en {min} a {max} días hábiles',
			'eta_text_one'    => 'Llega en {max} días hábiles',
			'eta_text_day'    => 'Llega en 1 día hábil', // Cuando es exactamente un día: el plural queda mal.
			'branch_label'    => 'Sucursal donde lo retirás',
			'branch_help'     => 'Elegí la sucursal que te quede más cómoda.',
			'pickup_label'    => 'Retiro en el local',

			/* Pantalla de envíos ----------------------------------------- */
			'ship_statuses'   => 'wc-processing', // Estados que cuentan como "listo para despachar".
			'ship_late_days'  => 3,               // Días sin despachar para marcarlo atrasado (0 = nunca).

			/* Seguimiento y avisos --------------------------------------- */
			'track_account'   => 1,   // El seguimiento se ve en Mi cuenta.
			'track_mail'      => 1,   // Aviso al cliente cuando se carga el seguimiento.
			'track_subject'   => 'Tu pedido {numero} ya está en camino',
			'track_body'      => "Hola {cliente},\n\nDespachamos tu pedido {numero} por {metodo}.\n\nSeguimiento: {codigo}\n{link}\n\n{tienda}",
			'track_whatsapp'  => 'Hola {cliente}! Despachamos tu pedido {numero} por {metodo}. Seguimiento: {codigo} {link}',

			/* Listo para retirar (estado de pedido propio) --------------- */
			'ready_enabled'   => 0,   // Apagado: la tienda no cambia hasta que se prenda.
			'ready_label'     => 'Listo para retirar',
			'ready_mail'      => 1,
			'ready_subject'   => 'Tu pedido {numero} está listo para retirar',
			'ready_body'      => "Hola {cliente},\n\nTu pedido {numero} ya está listo.\n\n{instrucciones}\n\n{tienda}",
			'ready_whatsapp'  => 'Hola {cliente}! Tu pedido {numero} ya está listo para retirar. {instrucciones}',

			/* ERP -------------------------------------------------------- */
			'erp_cost'        => 1,   // Guardar el costo real del correo en el pedido y en la nota.
		];
	}

	public static function rounding_choices() {
		return [
			0    => 'Sin redondeo (con centavos)',
			1    => 'Al peso',
			10   => 'A los $10',
			100  => 'A los $100',
			500  => 'A los $500',
			1000 => 'A los $1.000',
		];
	}

	public static function branch_sort_choices() {
		return [
			'cercania' => 'Las más cercanas primero',
			'nombre'   => 'Por nombre',
		];
	}

	public static function error_choices() {
		return [
			'tabla'     => 'Cotizar con la tabla propia del método',
			'coordinar' => 'Ofrecerlo sin precio, a coordinar',
			'ocultar'   => 'No ofrecer ese método',
		];
	}

	public static function declared_choices() {
		return [
			'subtotal' => 'El subtotal del carrito',
			'percent'  => 'Un porcentaje del subtotal',
			'none'     => 'No declarar valor',
		];
	}

	public static function get($key = null) {
		if (null === self::$cache) {
			$saved       = get_option(self::OPTION, []);
			self::$cache = wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
		}
		if (null === $key) {
			return self::$cache;
		}
		return isset(self::$cache[$key]) ? self::$cache[$key] : null;
	}

	/** Guarda ajustes generales (parciales). Las casillas ausentes conservan su valor: mandá 0 explícito. */
	public static function update(array $values) {
		update_option(self::OPTION, self::sanitize(wp_parse_args($values, self::get())));
		self::$cache = null;
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function sanitize($in) {
		$d   = self::defaults();
		$in  = is_array($in) ? $in : [];
		$out = [];

		foreach (['enabled', 'replace_native', 'native_adopt', 'native_allow_zero', 'badges_enabled', 'quote_product', 'free_enabled', 'cp_required', 'cp_strict', 'geo_learn', 'log_errors', 'track_account', 'track_mail', 'erp_cost', 'ready_enabled', 'ready_mail'] as $flag) {
			$out[$flag] = empty($in[$flag]) ? 0 : 1;
		}

		/* Origen: direcciones y datos de contacto del despacho. */
		foreach (['origin_name', 'origin_address', 'origin_extra', 'origin_city', 'origin_branch'] as $key) {
			$out[$key] = isset($in[$key]) ? trim(sanitize_text_field($in[$key])) : '';
		}
		$out['origin_state']    = isset($in['origin_state']) ? trim(sanitize_text_field($in['origin_state'])) : '';
		$out['origin_postcode'] = isset($in['origin_postcode']) ? self::postcode($in['origin_postcode']) : '';
		$out['origin_country']  = isset($in['origin_country']) ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $in['origin_country']), 0, 2)) : '';
		$out['origin_phone']    = isset($in['origin_phone']) ? trim(sanitize_text_field($in['origin_phone'])) : '';
		$email                  = isset($in['origin_email']) ? trim((string) $in['origin_email']) : '';
		$out['origin_email']    = is_email($email) ? sanitize_email($email) : '';

		/* Peso, volumen y precio: todos números, con techos sanos. */
		foreach (['weight_default', 'weight_extra', 'weight_min', 'weight_max', 'dim_length', 'dim_width', 'dim_height', 'volumetric', 'markup_fixed', 'min_cost', 'free_min'] as $key) {
			$out[$key] = max(0, self::decimal(isset($in[$key]) ? $in[$key] : $d[$key]));
		}
		foreach (['markup_pct', 'insurance_pct'] as $key) {
			$out[$key] = max(0, min(1000, self::decimal(isset($in[$key]) ? $in[$key] : $d[$key])));
		}
		$out['declared_pct'] = max(0, min(1000, self::decimal(isset($in['declared_pct']) ? $in['declared_pct'] : $d['declared_pct'])));

		$stack             = isset($in['dim_stack']) ? sanitize_key($in['dim_stack']) : $d['dim_stack'];
		$out['dim_stack']  = in_array($stack, ['alto', 'mayor'], true) ? $stack : $d['dim_stack'];

		$declared              = isset($in['declared_value']) ? sanitize_key($in['declared_value']) : $d['declared_value'];
		$out['declared_value'] = array_key_exists($declared, self::declared_choices()) ? $declared : $d['declared_value'];

		$rounding        = isset($in['rounding']) ? absint($in['rounding']) : $d['rounding'];
		$out['rounding'] = array_key_exists($rounding, self::rounding_choices()) ? $rounding : $d['rounding'];
		$mode                 = isset($in['rounding_mode']) ? sanitize_key($in['rounding_mode']) : $d['rounding_mode'];
		$out['rounding_mode'] = in_array($mode, ['nearest', 'up'], true) ? $mode : 'nearest';

		$error             = isset($in['on_error']) ? sanitize_key($in['on_error']) : $d['on_error'];
		$out['on_error']   = array_key_exists($error, self::error_choices()) ? $error : $d['on_error'];

		/* Sucursales: cómo se ordenan y cuántas se le ofrecen al cliente. */
		$sort               = isset($in['branch_sort']) ? sanitize_key($in['branch_sort']) : $d['branch_sort'];
		$out['branch_sort'] = array_key_exists($sort, self::branch_sort_choices()) ? $sort : $d['branch_sort'];
		$out['branch_max']  = max(0, min(300, absint(isset($in['branch_max']) ? $in['branch_max'] : $d['branch_max'])));

		/* Envíos: qué estados cuentan y a los cuántos días se marca atrasado. */
		$out['ship_statuses']  = isset($in['ship_statuses']) ? self::slug_list(is_array($in['ship_statuses']) ? implode(',', $in['ship_statuses']) : $in['ship_statuses']) : $d['ship_statuses'];
		$out['ship_late_days'] = max(0, min(365, absint(isset($in['ship_late_days']) ? $in['ship_late_days'] : $d['ship_late_days'])));

		$out['cache_hours']    = max(0, min(720, absint(isset($in['cache_hours']) ? $in['cache_hours'] : $d['cache_hours'])));
		$out['timeout']        = max(2, min(30, absint(isset($in['timeout']) ? $in['timeout'] : $d['timeout'])));
		$out['geo_cache_days'] = max(1, min(3650, absint(isset($in['geo_cache_days']) ? $in['geo_cache_days'] : $d['geo_cache_days'])));
		$out['cp_min_len']     = max(1, min(12, absint(isset($in['cp_min_len']) ? $in['cp_min_len'] : $d['cp_min_len'])));
		$out['cp_max_len']     = max($out['cp_min_len'], min(12, absint(isset($in['cp_max_len']) ? $in['cp_max_len'] : $d['cp_max_len'])));

		/* Proveedores de CP: solo los conocidos, en el orden que vinieron, sin repetir. */
		$known = array_keys(MJE_Geo::providers());
		$list  = [];
		foreach (preg_split('/[^a-z0-9_]+/', strtolower((string) (isset($in['geo_providers']) ? $in['geo_providers'] : $d['geo_providers']))) as $slug) {
			if ('' !== $slug && in_array($slug, $known, true) && !in_array($slug, $list, true)) {
				$list[] = $slug;
			}
		}
		$out['geo_providers'] = implode(',', $list);

		$url             = isset($in['geo_url']) ? trim((string) $in['geo_url']) : $d['geo_url'];
		$out['geo_url']  = '' !== $url ? esc_url_raw(untrailingslashit($url)) : '';

		$out['free_methods'] = self::slug_list(isset($in['free_methods']) ? $in['free_methods'] : '');
		$out['free_states']  = isset($in['free_states']) ? self::text_list($in['free_states']) : '';

		/* Textos: si los vacían, vuelve el default (nunca queda una pantalla muda). */
		foreach (['badge_cheapest', 'badge_fastest', 'quote_title', 'quote_button', 'quote_help', 'free_label', 'step_title', 'step_intro', 'cp_label', 'cp_help', 'cp_ask', 'cp_invalid', 'eta_text', 'eta_text_one', 'eta_text_day', 'branch_label', 'branch_help', 'pickup_label', 'track_subject', 'ready_label', 'ready_subject'] as $key) {
			$value     = isset($in[$key]) ? trim(sanitize_text_field($in[$key])) : '';
			$out[$key] = '' !== $value ? mb_substr($value, 0, 190) : $d[$key];
		}
		foreach (['none_text', 'coordinate_text', 'error_text', 'track_body', 'track_whatsapp', 'ready_body', 'ready_whatsapp'] as $key) {
			$value     = isset($in[$key]) ? trim(sanitize_textarea_field($in[$key])) : '';
			$out[$key] = '' !== $value ? $value : $d[$key];
		}

		return $out;
	}

	/* ------------------------------- Origen ------------------------------ */

	/** Dirección de despacho, con el país base de la tienda cuando no se cargó otro. */
	public static function origin() {
		$s       = self::get();
		$country = (string) $s['origin_country'];
		if ('' === $country && function_exists('WC') && WC()->countries) {
			$country = (string) WC()->countries->get_base_country();
		}
		return [
			'name'      => (string) $s['origin_name'],
			'address_1' => (string) $s['origin_address'],
			'address_2' => (string) $s['origin_extra'],
			'city'      => (string) $s['origin_city'],
			'state'     => (string) $s['origin_state'],
			'postcode'  => (string) $s['origin_postcode'],
			'country'   => $country,
			'phone'     => (string) $s['origin_phone'],
			'email'     => (string) $s['origin_email'],
			'branch'    => (string) $s['origin_branch'],
		];
	}

	/** ¿Está cargado lo mínimo para que un correo pueda cotizar desde acá? */
	public static function origin_ready() {
		$o = self::origin();
		return '' !== $o['postcode'] && '' !== $o['country'];
	}

	/* ------------------------------- Helpers ----------------------------- */

	/** "10", "10,5", "1.500" → float. Comparte la regla de MJ Dólar cuando está activo. */
	public static function decimal($raw) {
		if (class_exists('MJD_Product')) {
			$v = MJD_Product::parse_decimal($raw);
			return '' === $v ? 0.0 : (float) $v;
		}
		$raw = trim((string) $raw);
		$raw = str_replace([' ', '$', '%'], '', $raw);
		if (false !== strpos($raw, ',') && false !== strpos($raw, '.')) {
			$raw = strrpos($raw, ',') > strrpos($raw, '.') ? str_replace(',', '.', str_replace('.', '', $raw)) : str_replace(',', '', $raw);
		} elseif (false !== strpos($raw, ',')) {
			$raw = str_replace(',', '.', $raw);
		}
		return is_numeric($raw) ? (float) $raw : 0.0;
	}

	/** Código postal tal como lo guarda WooCommerce: sin espacios ni guiones. */
	public static function postcode($raw) {
		$raw = strtoupper(trim((string) $raw));
		$raw = preg_replace('/[^A-Z0-9]/', '', $raw);
		return (string) mb_substr((string) $raw, 0, 12);
	}

	/** Redondeo del precio final, con la misma regla que el resto de la familia. */
	public static function round_price($amount) {
		$amount = (float) $amount;
		$step   = (int) self::get('rounding');
		if ($step <= 0) {
			return round($amount, function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2);
		}
		$q = $amount / $step;
		$q = 'up' === self::get('rounding_mode') ? ceil($q - 1e-9) : round($q);
		return (float) ($q * $step);
	}

	public static function money($n) {
		if (!function_exists('wc_price')) {
			return number_format((float) $n, 2);
		}
		return trim(html_entity_decode(wp_strip_all_tags(wc_price((float) $n)), ENT_QUOTES, 'UTF-8'));
	}

	/** "a, b , b" → "a,b" (slugs, sin repetidos ni vacíos). */
	public static function slug_list($raw) {
		$out = [];
		if (is_array($raw)) {
			$raw = implode(',', $raw);
		}
		foreach (explode(',', (string) $raw) as $part) {
			$slug = sanitize_key($part);
			if ('' !== $slug && !in_array($slug, $out, true)) {
				$out[] = $slug;
			}
		}
		return implode(',', $out);
	}

	/** Igual que slug_list pero conservando el texto (nombres de provincia o localidad). */
	public static function text_list($raw) {
		$out = [];
		if (is_array($raw)) {
			$raw = implode(',', $raw);
		}
		foreach (explode(',', (string) $raw) as $part) {
			$text = trim(sanitize_text_field($part));
			if ('' !== $text && !in_array($text, $out, true)) {
				$out[] = $text;
			}
		}
		return implode(',', $out);
	}

	/** "a,b" → ['a','b'] (y [] si está vacío). */
	public static function to_array($csv) {
		if (is_array($csv)) {
			$csv = implode(',', $csv);
		}
		$out = [];
		foreach (explode(',', (string) $csv) as $part) {
			$part = trim($part);
			if ('' !== $part) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/** Comparación tolerante de nombres: sin acentos, sin mayúsculas, sin puntuación. */
	public static function key_of($text) {
		$text = (string) $text;
		if (function_exists('remove_accents')) {
			$text = remove_accents($text);
		}
		$text = strtolower($text);
		$text = preg_replace('/[^a-z0-9]+/', ' ', $text);
		return trim((string) $text);
	}

	/** Estados de pedido de WooCommerce, sin el prefijo wc-. */
	public static function order_statuses() {
		if (!function_exists('wc_get_order_statuses')) {
			return [];
		}
		$out = [];
		foreach (wc_get_order_statuses() as $slug => $label) {
			$out[substr($slug, 3)] = $label;
		}
		return $out;
	}

	/** Caches de página conocidas: las tarifas viajan impresas en el carrito. */
	public static function flush_caches() {
		if (class_exists('WC_Cache_Helper')) {
			WC_Cache_Helper::get_transient_version('shipping', true);
		}
		if (function_exists('wc_delete_product_transients')) {
			wc_delete_product_transients();
		}
		do_action('mje_flush_caches');
	}
}
