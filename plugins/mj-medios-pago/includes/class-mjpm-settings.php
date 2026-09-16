<?php
/**
 * Ajustes: cinco opciones.
 *   mjpm_settings  ajustes generales (precio base, método por defecto, redondeo, visibilidad)
 *   mjpm_methods   ajuste por medio de pago, indexado por id de gateway
 *   mjpm_custom    métodos propios, indexados por slug
 *   mjpm_receipts  ajustes generales de comprobantes de pago
 *   mjpm_mapping   equivalencia gateway de WooCommerce → método del ERP
 *
 * Regla de toda la configuración por método: los valores "heredar" (o -1 en los
 * numéricos) significan "lo que diga el ajuste general". Así subir un default
 * después sigue alcanzando a todos los métodos que nunca se tocaron a mano.
 */

defined('ABSPATH') || exit;

class MJPM_Settings {

	const OPTION   = 'mjpm_settings';
	const METHODS  = 'mjpm_methods';
	const CUSTOM   = 'mjpm_custom';
	const RECEIPTS = 'mjpm_receipts';
	const MAPPING  = 'mjpm_mapping';

	/** Tope de datos para pagar por medio de pago. */
	const DETAILS_MAX = 20;

	private static $cache    = null;
	private static $methods  = null;
	private static $custom   = null;
	private static $receipts = null;

	/* ------------------------------ Generales ---------------------------- */

	public static function defaults() {
		return [
			'base_method'     => '',        // id del gateway cuyo precio es el cargado en WooCommerce.
			'display_method'  => '',        // id del gateway con el que se muestran los precios por defecto ('' = el base).
			'rounding'        => class_exists('MJD_Settings') ? (int) MJD_Settings::get('rounding') : 100,
			'rounding_mode'   => class_exists('MJD_Settings') ? (string) MJD_Settings::get('rounding_mode') : 'nearest',
			'apply_on_sale'   => 1,         // Los descuentos también aplican a productos en oferta.
			'stack_coupons'   => 1,         // Los descuentos se acumulan con cupones.
			'show_selector'   => 1,         // Selector "Ver precios con" en la tienda.
			'selector_label'  => 'Ver precios con',
			'show_badge'      => 1,         // Badge "10% OFF con transferencia" en tarjetas.
			'show_panel'      => 1,         // Panel de precios por medio de pago en la ficha.
			'show_list_price' => 1,         // Precio base tachado cuando el método mostrado tiene descuento.
			'details_title'   => 'Datos para pagar', // Encabezado de los datos de cada medio ('' en el medio = este).
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
		update_option(self::OPTION, self::sanitize_settings(wp_parse_args($values, self::get())));
		self::$cache = null;
	}

	public static function flush() {
		self::$cache    = null;
		self::$methods  = null;
		self::$custom   = null;
		self::$receipts = null;
	}

	public static function sanitize_settings($in) {
		$d   = self::defaults();
		$in  = is_array($in) ? $in : [];
		$out = [];

		$out['base_method']    = isset($in['base_method']) ? sanitize_key($in['base_method']) : '';
		$out['display_method'] = isset($in['display_method']) ? sanitize_key($in['display_method']) : '';

		$rounding        = isset($in['rounding']) ? absint($in['rounding']) : $d['rounding'];
		$out['rounding'] = array_key_exists($rounding, self::rounding_choices()) ? $rounding : $d['rounding'];
		$mode                 = isset($in['rounding_mode']) ? sanitize_key($in['rounding_mode']) : $d['rounding_mode'];
		$out['rounding_mode'] = in_array($mode, ['nearest', 'up'], true) ? $mode : 'nearest';

		foreach (['apply_on_sale', 'stack_coupons', 'show_selector', 'show_badge', 'show_panel', 'show_list_price'] as $flag) {
			$out[$flag] = empty($in[$flag]) ? 0 : 1;
		}
		$label                 = isset($in['selector_label']) ? trim(sanitize_text_field($in['selector_label'])) : $d['selector_label'];
		$out['selector_label'] = '' !== $label ? $label : $d['selector_label'];
		$title                 = isset($in['details_title']) ? trim(sanitize_text_field($in['details_title'])) : $d['details_title'];
		$out['details_title']  = '' !== $title ? mb_substr($title, 0, 80) : $d['details_title'];

		return $out;
	}

	/* ------------------------- Ajuste por método ------------------------- */

	public static function method_defaults() {
		return [
			'kind'       => 'none',    // none | discount | surcharge  (base se deriva de base_method)
			'mode'       => 'percent', // percent (por unidad, se ve en tarjetas) | fixed (monto fijo por pedido)
			'value'      => 0,
			'label'      => '',        // Justificación en el carrito. Placeholders: {metodo} {porcentaje} {monto}
			'badge'      => '',        // Texto en tarjetas (solo descuentos). Mismos placeholders.
			'short'      => '',        // Nombre corto para selector y panel ('' = título del gateway).
			'show'       => 1,         // Aparece en selector, panel y tarjetas.
			'min_amount' => 0,         // Subtotal mínimo (elegible) para aplicar el ajuste.

			/*
			 * Comprobante de pago. 'heredar' y -1 = lo que diga el ajuste general
			 * (Comprobantes), así cambiar la regla general alcanza a todos los
			 * métodos que no la pisaron a mano.
			 */
			'receipt'       => 'heredar', // heredar | no | opcional | obligatorio
			'receipt_text'  => '',        // Instrucción para el cliente ('' = el texto general).
			'receipt_files' => 0,         // Máximo de archivos (0 = el general).
			'receipt_hours' => -1,        // Plazo en horas para subirlo (-1 general, 0 sin plazo).
			'receipt_on_ok' => 'heredar', // Estado del pedido al aprobar ('heredar' general, '' no tocar).
			'receipt_attach' => -1,       // Adjuntar al email del pedido (-1 general, 0 no, 1 sí).

			/*
			 * Datos para pagar: alias, CBU, titular, lo que haga falta. Es una lista
			 * libre porque cada medio pide otra cosa y ningún nombre está fijo.
			 * Se muestran después de comprar (gracias, Mi cuenta, email, link del
			 * comprobante) y, si se marca, también en el checkout.
			 */
			'details'          => [],     // Filas con label (Alias), value (mi.tienda) y copy (1 = botón de copiar).
			'details_title'    => '',     // Encabezado ('' = el de los ajustes generales).
			'details_checkout' => 0,      // Mostrarlos también en el checkout, al elegir el medio (van en la config pública).
		];
	}

	/**
	 * Limpia la lista de datos para pagar: descarta las filas vacías y corta
	 * largos, así lo que llega a la tienda y al email es siempre prolijo.
	 *
	 * @param mixed $rows Filas como vienen del formulario o de la base.
	 * @return array
	 */
	public static function sanitize_details($rows) {
		$out = [];
		if (!is_array($rows)) {
			return $out;
		}
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$label = isset($row['label']) ? trim(sanitize_text_field($row['label'])) : '';
			$value = isset($row['value']) ? trim(sanitize_text_field($row['value'])) : '';
			if ('' === $label && '' === $value) {
				continue;
			}
			$out[] = [
				'label' => mb_substr($label, 0, 60),
				'value' => mb_substr($value, 0, 200),
				'copy'  => empty($row['copy']) ? 0 : 1,
			];
			if (count($out) >= self::DETAILS_MAX) {
				break;
			}
		}
		return $out;
	}

	/** Modos de exigencia del comprobante. */
	public static function receipt_modes($with_inherit = false) {
		$modes = [
			'no'          => 'No pide comprobante',
			'opcional'    => 'Puede subirlo (opcional)',
			'obligatorio' => 'Tiene que subirlo',
		];
		if ($with_inherit) {
			return ['heredar' => 'Heredar del ajuste general'] + $modes;
		}
		return $modes;
	}

	/** Todos los ajustes guardados: id de gateway => config completa. */
	public static function methods() {
		if (null === self::$methods) {
			$saved         = get_option(self::METHODS, []);
			self::$methods = [];
			if (is_array($saved)) {
				foreach ($saved as $id => $cfg) {
					self::$methods[(string) $id] = wp_parse_args(is_array($cfg) ? $cfg : [], self::method_defaults());
				}
			}
		}
		return self::$methods;
	}

	public static function method($id) {
		$all = self::methods();
		return isset($all[$id]) ? $all[$id] : self::method_defaults();
	}

	public static function update_methods(array $all) {
		$clean = [];
		foreach ($all as $id => $cfg) {
			$id = sanitize_key($id);
			if ('' !== $id) {
				$clean[$id] = self::sanitize_method($cfg);
			}
		}
		update_option(self::METHODS, $clean);
		self::$methods = null;
	}

	public static function sanitize_method($in) {
		$d   = self::method_defaults();
		$in  = is_array($in) ? $in : [];
		$out = [];

		$kind        = isset($in['kind']) ? sanitize_key($in['kind']) : $d['kind'];
		$out['kind'] = in_array($kind, ['none', 'discount', 'surcharge'], true) ? $kind : 'none';
		$mode        = isset($in['mode']) ? sanitize_key($in['mode']) : $d['mode'];
		$out['mode'] = in_array($mode, ['percent', 'fixed'], true) ? $mode : 'percent';

		$value = isset($in['value']) ? self::decimal($in['value']) : 0;
		if ('percent' === $out['mode']) {
			$value = min(100, $value);
		}
		$out['value'] = max(0, $value);
		if ($out['value'] <= 0) {
			$out['kind'] = 'none';
		}

		$out['label']      = isset($in['label']) ? sanitize_text_field($in['label']) : '';
		$out['badge']      = isset($in['badge']) ? sanitize_text_field($in['badge']) : '';
		$out['short']      = isset($in['short']) ? sanitize_text_field($in['short']) : '';
		$out['show']       = empty($in['show']) ? 0 : 1;
		$out['min_amount'] = isset($in['min_amount']) ? max(0, self::decimal($in['min_amount'])) : 0;

		$mode           = isset($in['receipt']) ? sanitize_key($in['receipt']) : $d['receipt'];
		$out['receipt'] = array_key_exists($mode, self::receipt_modes(true)) ? $mode : $d['receipt'];

		$out['receipt_text']  = isset($in['receipt_text']) ? sanitize_textarea_field($in['receipt_text']) : '';
		$out['receipt_files'] = isset($in['receipt_files']) ? max(0, min(20, absint($in['receipt_files']))) : 0;

		$hours                = isset($in['receipt_hours']) && '' !== trim((string) $in['receipt_hours']) ? (int) $in['receipt_hours'] : -1;
		$out['receipt_hours'] = $hours < 0 ? -1 : min(8760, $hours);

		$on_ok = isset($in['receipt_on_ok']) ? sanitize_key($in['receipt_on_ok']) : $d['receipt_on_ok'];
		$out['receipt_on_ok'] = 'heredar' === $on_ok || '' === $on_ok || array_key_exists($on_ok, self::order_statuses()) ? $on_ok : 'heredar';

		$attach                = isset($in['receipt_attach']) && '' !== (string) $in['receipt_attach'] ? (int) $in['receipt_attach'] : -1;
		$out['receipt_attach'] = in_array($attach, [0, 1], true) ? $attach : -1;

		$out['details']          = isset($in['details']) ? self::sanitize_details($in['details']) : [];
		$out['details_title']    = isset($in['details_title']) ? mb_substr(trim(sanitize_text_field($in['details_title'])), 0, 80) : '';
		$out['details_checkout'] = empty($in['details_checkout']) ? 0 : 1;

		return $out;
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

	/* ---------------------------- Métodos propios ------------------------ */

	public static function custom_defaults() {
		return [
			'id'           => '',        // id de gateway: mjpm_{slug}
			'title'        => '',
			'description'  => '',        // Texto bajo el título en el checkout.
			'instructions' => '',        // Página de gracias y email al cliente.
			'status'       => 'on-hold', // Estado del pedido al confirmar.
			'currency'     => 'ARS',     // ARS | USD (USD: muestra el total en dólares con MJ Dólar).
		];
	}

	public static function statuses() {
		return [
			'on-hold'    => 'En espera (hasta que confirmes el pago)',
			'processing' => 'Procesando (el pago se da por confirmado)',
			'completed'  => 'Completado',
			'pending'    => 'Pendiente de pago',
		];
	}

	/** Métodos propios: slug => definición completa. */
	public static function custom() {
		if (null === self::$custom) {
			$saved        = get_option(self::CUSTOM, []);
			self::$custom = [];
			if (is_array($saved)) {
				foreach ($saved as $slug => $def) {
					$slug = sanitize_key($slug);
					if ('' === $slug || !is_array($def)) {
						continue;
					}
					$def = wp_parse_args($def, self::custom_defaults());
					if ('' === $def['id']) {
						$def['id'] = self::gateway_id($slug);
					}
					self::$custom[$slug] = $def;
				}
			}
		}
		return self::$custom;
	}

	public static function gateway_id($slug) {
		return 'mjpm_' . str_replace('-', '_', sanitize_key($slug));
	}

	public static function custom_by_gateway($gateway_id) {
		foreach (self::custom() as $def) {
			if ($def['id'] === $gateway_id) {
				return $def;
			}
		}
		return null;
	}

	public static function update_custom(array $all) {
		$clean = [];
		foreach ($all as $slug => $def) {
			$slug = sanitize_key($slug);
			if ('' === $slug) {
				continue;
			}
			$def       = self::sanitize_custom($def);
			$def['id'] = self::gateway_id($slug);
			$clean[$slug] = $def;
		}
		update_option(self::CUSTOM, $clean);
		self::$custom = null;
	}

	public static function sanitize_custom($in) {
		$d   = self::custom_defaults();
		$in  = is_array($in) ? $in : [];
		$out = [];

		$out['id']           = isset($in['id']) ? sanitize_key($in['id']) : '';
		$out['title']        = isset($in['title']) ? trim(sanitize_text_field($in['title'])) : '';
		$out['description']  = isset($in['description']) ? sanitize_textarea_field($in['description']) : '';
		$out['instructions'] = isset($in['instructions']) ? sanitize_textarea_field($in['instructions']) : '';
		$status              = isset($in['status']) ? sanitize_key($in['status']) : $d['status'];
		$out['status']       = array_key_exists($status, self::statuses()) ? $status : $d['status'];
		$currency            = isset($in['currency']) ? strtoupper(sanitize_key($in['currency'])) : $d['currency'];
		$out['currency']     = 'USD' === $currency ? 'USD' : 'ARS';

		return $out;
	}

	/* --------------------------- Comprobantes ---------------------------- */

	/**
	 * Ajustes generales de comprobantes de pago.
	 *
	 * Son el default de todo lo que cada medio de pago puede pisar, más lo que
	 * solo tiene sentido una vez (cómo se llaman, cuánto se guardan, quién los
	 * mira, qué mails salen). Nada de esto está fijo en el código.
	 */
	public static function receipt_defaults() {
		return [
			/* Qué se pide ------------------------------------------------ */
			'enabled'      => 1,                                   // Interruptor maestro de la función.
			'noun'         => 'comprobante',                       // Cómo se lo llama en toda la interfaz.
			'noun_plural'  => 'comprobantes',
			'mode'         => 'no',                                // Default para los métodos en "heredar".
			'text'         => 'Subí una foto o el PDF del comprobante de la transferencia. Lo revisamos y te confirmamos el pago.',
			'ask_amount'   => 1,                                   // Pedirle el importe que pagó.
			'ask_reference' => 1,                                  // Pedirle el número de operación.
			'ask_note'     => 1,                                   // Dejarle escribir una aclaración.

			/* Archivos --------------------------------------------------- */
			'types'        => 'jpg,jpeg,png,webp,pdf,heic',
			'max_mb'       => 8,
			'max_files'    => 3,
			'optimize'     => 0,                                   // Pasar las imágenes por MJ Optimizador de Imágenes.
			'keep_days'    => 365,                                 // Borrado automático (0 = guardar para siempre).

			/* Plazos ----------------------------------------------------- */
			'hours'        => 0,                                   // Plazo para subirlo (0 = sin plazo).
			'remind_hours' => 0,                                   // Recordatorio al cliente (0 = sin recordatorio).

			/* Qué pasa cuando decidís ------------------------------------ */
			'on_ok'        => 'processing',                         // Estado del pedido al aprobar ('' = no tocar).
			'on_reject'    => '',                                  // Estado del pedido al rechazar ('' = no tocar).
			'on_partial'   => '',                                  // Estado del pedido con un pago parcial ('' = no tocar).
			'attach'       => 0,                                   // Adjuntar el archivo al email del pedido.

			/* Dónde lo ve el cliente ------------------------------------- */
			'show_account' => 1,                                   // Bloque en Mi cuenta → detalle del pedido.
			'guest_links'  => 1,                                   // Subida por link firmado (compras sin cuenta).
			'link_days'    => 30,                                  // Días que vive ese link.

			/* Quién puede revisarlos ------------------------------------- */
			'cap_shop'     => 1,                                   // Quien administra la tienda (manage_woocommerce).
			'cap_erp'      => 0,                                   // Quien administra el ERP (manage_mjerp).

			/* Avisos ----------------------------------------------------- */
			'mail_new'        => 1,                                // Aviso a la tienda cuando entra uno nuevo.
			'mail_new_to'     => '',                               // '' = el email de administración del sitio.
			'mail_ok'         => 1,
			'mail_ok_subject' => 'Confirmamos el pago de tu pedido {numero}',
			'mail_ok_body'    => "Hola {cliente},\n\nRecibimos y confirmamos el {comprobante} de tu pedido {numero}. ¡Gracias!\n\n{tienda}",
			'mail_no'         => 1,
			'mail_no_subject' => 'Necesitamos otro {comprobante} de tu pedido {numero}',
			'mail_no_body'    => "Hola {cliente},\n\nNo pudimos validar el {comprobante} de tu pedido {numero}.\n\nMotivo: {motivo}\n\nPodés subir otro desde acá: {link}\n\n{tienda}",
			'whatsapp'        => 1,                                // Botón de WhatsApp en la bandeja.
			'whatsapp_ok'     => 'Hola {cliente}! Recibimos el {comprobante} de tu pedido {numero} y confirmamos el pago. ¡Gracias!',
			'whatsapp_no'     => 'Hola {cliente}! No pudimos validar el {comprobante} del pedido {numero}: {motivo}. ¿Me lo podés mandar de nuevo? {link}',
			'mail_partial'         => 1,                           // Mail al cliente cuando entra una parte del pago.
			'mail_partial_subject' => 'Recibimos un pago de tu pedido {numero}: falta abonar {falta}',
			'mail_partial_body'    => "Hola {cliente},\n\nRecibimos {importe} para tu pedido {numero}. Llevás pagado {pagado} de {total}: falta abonar {falta}.\n\nCuando pagues el resto, subí el {comprobante} acá: {link}\n\n{tienda}",
			'whatsapp_partial'     => 'Hola {cliente}! Recibimos {importe} de tu pedido {numero}. Falta abonar {falta}. Cuando lo pagues, mandame el {comprobante}: {link}',
			'mail_summary'         => 1,                           // Sumar al mail el resumen: importe, pagado, falta y mensaje.
		];
	}

	/** Opciones de retención para el selector. */
	public static function keep_choices() {
		return [
			0    => 'Para siempre',
			30   => '30 días',
			90   => '90 días',
			180  => '6 meses',
			365  => '1 año',
			730  => '2 años',
			1825 => '5 años',
		];
	}

	public static function receipts($key = null) {
		if (null === self::$receipts) {
			$saved          = get_option(self::RECEIPTS, []);
			self::$receipts = wp_parse_args(is_array($saved) ? $saved : [], self::receipt_defaults());
		}
		if (null === $key) {
			return self::$receipts;
		}
		return isset(self::$receipts[$key]) ? self::$receipts[$key] : null;
	}

	/** Guarda ajustes de comprobantes (parciales: lo ausente conserva su valor). */
	public static function update_receipts(array $values) {
		update_option(self::RECEIPTS, self::sanitize_receipts(wp_parse_args($values, self::receipts())));
		self::$receipts = null;
	}

	public static function sanitize_receipts($in) {
		$d   = self::receipt_defaults();
		$in  = is_array($in) ? $in : [];
		$out = [];

		foreach (['enabled', 'ask_amount', 'ask_reference', 'ask_note', 'optimize', 'attach', 'show_account', 'guest_links', 'cap_shop', 'cap_erp', 'mail_new', 'mail_ok', 'mail_no', 'whatsapp', 'mail_partial', 'mail_summary'] as $flag) {
			$out[$flag] = empty($in[$flag]) ? 0 : 1;
		}

		foreach (['noun' => 40, 'noun_plural' => 40, 'mail_ok_subject' => 190, 'mail_no_subject' => 190, 'mail_partial_subject' => 190] as $field => $max) {
			$value        = isset($in[$field]) ? trim(sanitize_text_field($in[$field])) : '';
			$out[$field]  = '' !== $value ? mb_substr($value, 0, $max) : $d[$field];
		}

		foreach (['text', 'mail_ok_body', 'mail_no_body', 'whatsapp_ok', 'whatsapp_no', 'mail_partial_body', 'whatsapp_partial'] as $field) {
			$value       = isset($in[$field]) ? trim(sanitize_textarea_field($in[$field])) : '';
			$out[$field] = '' !== $value ? $value : $d[$field];
		}

		$mode        = isset($in['mode']) ? sanitize_key($in['mode']) : $d['mode'];
		$out['mode'] = array_key_exists($mode, self::receipt_modes()) ? $mode : $d['mode'];

		$types = isset($in['types']) ? strtolower((string) $in['types']) : $d['types'];
		$clean = [];
		foreach (preg_split('/[^a-z0-9]+/', $types) as $ext) {
			if ('' !== $ext && array_key_exists($ext, MJPM_Vault::known_types())) {
				$clean[$ext] = true;
			}
		}
		$out['types'] = $clean ? implode(',', array_keys($clean)) : $d['types'];

		$out['max_mb']    = max(1, min(64, absint(isset($in['max_mb']) ? $in['max_mb'] : $d['max_mb'])));
		$out['max_files'] = max(1, min(20, absint(isset($in['max_files']) ? $in['max_files'] : $d['max_files'])));
		$out['link_days'] = max(1, min(365, absint(isset($in['link_days']) ? $in['link_days'] : $d['link_days'])));

		$keep             = isset($in['keep_days']) ? absint($in['keep_days']) : $d['keep_days'];
		$out['keep_days'] = array_key_exists($keep, self::keep_choices()) ? $keep : $d['keep_days'];

		$out['hours']        = max(0, min(8760, absint(isset($in['hours']) ? $in['hours'] : 0)));
		$out['remind_hours'] = max(0, min(8760, absint(isset($in['remind_hours']) ? $in['remind_hours'] : 0)));

		$statuses = self::order_statuses();
		foreach (['on_ok', 'on_reject', 'on_partial'] as $field) {
			$status        = isset($in[$field]) ? sanitize_key($in[$field]) : $d[$field];
			$out[$field]   = '' !== $status && (array_key_exists($status, $statuses) || !$statuses) ? $status : '';
		}

		$to               = isset($in['mail_new_to']) ? trim((string) $in['mail_new_to']) : '';
		$out['mail_new_to'] = is_email($to) ? sanitize_email($to) : '';

		return $out;
	}

	/* ------------------------------- Helpers ----------------------------- */

	/** "10", "10,5", "1.500" → float. */
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

	/** Cachés de página conocidas: el header y las tarjetas llevan los precios impresos. */
	public static function flush_caches() {
		if (function_exists('wc_delete_product_transients')) {
			wc_delete_product_transients();
		}
		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
		}
		if (function_exists('w3tc_flush_all')) {
			w3tc_flush_all();
		}
		if (function_exists('wp_cache_clear_cache')) {
			wp_cache_clear_cache();
		}
		if (function_exists('sg_cachepress_purge_cache')) {
			sg_cachepress_purge_cache();
		}
		do_action('litespeed_purge_all');
		do_action('mjpm_flush_caches');
	}
}
