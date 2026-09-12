<?php
/**
 * Los datos que se le piden al cliente para poder enviar.
 *
 * Hay un catálogo de campos conocidos (nombre, teléfono, DNI, calle, piso,
 * entre calles, localidad, provincia, CP, sucursal, quién recibe, horario,
 * aclaraciones) y los campos propios que agregues vos desde el panel. De cada
 * uno se configura si se pide, si es obligatorio, cómo se llama, qué ayuda
 * muestra y en qué orden aparece.
 *
 * Dos niveles, con la misma regla que el resto de la familia:
 *
 *   general   el ajuste de base, en la opción mje_fields.
 *   método    lo que cada método de envío pisa. Lo que quede en 'heredar' sigue
 *             al general, así cambiar la regla general alcanza a todos los
 *             métodos que nunca se tocaron a mano.
 *
 * Un método de retiro en el local no necesita calle ni CP, y uno a sucursal no
 * necesita calle: eso lo resuelve el destino del método, pero SOLO sobre los
 * campos que quedaron en 'heredar'. Si en un método decís explícitamente que
 * quieres pedir la calle, se pide.
 */

defined('ABSPATH') || exit;

class MJE_Fields {

	/** Cómo se exige un campo. */
	public static function modes($with_inherit = false) {
		$modes = [
			'no'          => 'No se pide',
			'opcional'    => 'Se pide, opcional',
			'obligatorio' => 'Se pide, obligatorio',
		];
		if ($with_inherit) {
			return ['heredar' => 'Heredar del ajuste general'] + $modes;
		}
		return $modes;
	}

	public static function types() {
		return [
			'text'     => 'Texto',
			'tel'      => 'Teléfono',
			'email'    => 'Email',
			'number'   => 'Número',
			'select'   => 'Lista de opciones',
			'checkbox' => 'Casilla (sí / no)',
			'textarea' => 'Texto largo',
		];
	}

	/**
	 * Campos conocidos.
	 *
	 * `target` dice a dónde va el dato del pedido:
	 *   shipping  dirección de envío de WooCommerce (viaja a los correos y al ERP)
	 *   billing   datos de contacto del pedido
	 *   meta      dato del envío, guardado en el pedido y visible en el admin
	 */
	public static function catalog() {
		$fields = [
			'first_name' => ['label' => 'Nombre', 'type' => 'text', 'target' => 'shipping', 'auto' => 'given-name', 'default' => 'obligatorio'],
			'last_name'  => ['label' => 'Apellido', 'type' => 'text', 'target' => 'shipping', 'auto' => 'family-name', 'default' => 'obligatorio'],
			'phone'      => ['label' => 'Teléfono', 'type' => 'tel', 'target' => 'billing', 'auto' => 'tel', 'default' => 'obligatorio', 'help' => 'Para avisarte cuando salga el envío.'],
			'doc'        => ['label' => 'DNI o CUIT de quien recibe', 'type' => 'text', 'target' => 'meta', 'default' => 'no', 'help' => 'Algunos correos lo piden para entregar.'],
			'company'    => ['label' => 'Empresa', 'type' => 'text', 'target' => 'shipping', 'auto' => 'organization', 'default' => 'no'],
			'postcode'   => ['label' => 'Código postal', 'type' => 'text', 'target' => 'shipping', 'auto' => 'postal-code', 'default' => 'obligatorio', 'geo' => 1],
			'state'      => ['label' => 'Provincia', 'type' => 'select', 'target' => 'shipping', 'auto' => 'address-level1', 'default' => 'obligatorio', 'geo' => 1],
			'city'       => ['label' => 'Localidad', 'type' => 'text', 'target' => 'shipping', 'auto' => 'address-level2', 'default' => 'obligatorio', 'geo' => 1],
			'address_1'  => ['label' => 'Calle y número', 'type' => 'text', 'target' => 'shipping', 'auto' => 'address-line1', 'default' => 'obligatorio', 'home' => 1],
			'address_2'  => ['label' => 'Piso, departamento o referencia', 'type' => 'text', 'target' => 'shipping', 'auto' => 'address-line2', 'default' => 'opcional', 'home' => 1],
			'between'    => ['label' => 'Entre calles', 'type' => 'text', 'target' => 'meta', 'default' => 'no', 'home' => 1],
			'branch'     => ['label' => 'Sucursal', 'type' => 'select', 'target' => 'meta', 'default' => 'obligatorio', 'branch' => 1],
			'receiver'   => ['label' => 'Quién recibe', 'type' => 'text', 'target' => 'meta', 'default' => 'no', 'help' => 'Si no estás vos, quién puede recibirlo.'],
			'schedule'   => ['label' => 'Horario preferido', 'type' => 'select', 'target' => 'meta', 'default' => 'no', 'options' => "Mañana\nTarde\nCualquier horario"],
			'notes'      => ['label' => 'Aclaraciones para la entrega', 'type' => 'textarea', 'target' => 'meta', 'default' => 'no'],
		];

		foreach (self::custom() as $key => $def) {
			$fields[$key] = [
				'label'   => (string) $def['label'],
				'type'    => (string) $def['type'],
				'target'  => 'meta',
				'default' => 'opcional',
				'options' => (string) $def['options'],
				'custom'  => 1,
			];
		}

		return (array) apply_filters('mje_fields', $fields);
	}

	/** Config vacía de un campo (lo que se guarda por campo, general o por método). */
	public static function field_defaults() {
		return [
			'mode'        => 'heredar',
			'label'       => '',
			'placeholder' => '',
			'help'        => '',
			'options'     => '', // Una opción por línea (solo listas).
			'order'       => 0,
		];
	}

	/** Contenido inicial de la opción mje_fields. */
	public static function option_defaults() {
		$general = [];
		$order   = 10;
		foreach (self::catalog() as $key => $meta) {
			$general[$key] = wp_parse_args([
				'mode'  => isset($meta['default']) ? $meta['default'] : 'opcional',
				'order' => $order,
			], self::field_defaults());
			$order += 10;
		}
		return ['general' => $general, 'custom' => []];
	}

	private static $cache = null;

	private static function option() {
		if (null === self::$cache) {
			$saved       = get_option(MJE_Settings::FIELDS, []);
			$saved       = is_array($saved) ? $saved : [];
			self::$cache = [
				'general' => isset($saved['general']) && is_array($saved['general']) ? $saved['general'] : [],
				'custom'  => isset($saved['custom']) && is_array($saved['custom']) ? $saved['custom'] : [],
			];
		}
		return self::$cache;
	}

	public static function flush() {
		self::$cache = null;
	}

	/** Ajuste general de un campo (o de todos). */
	public static function general($key = null) {
		$all   = self::option()['general'];
		$clean = [];
		foreach (self::catalog() as $slug => $meta) {
			$saved        = isset($all[$slug]) && is_array($all[$slug]) ? $all[$slug] : [];
			$cfg          = wp_parse_args($saved, self::field_defaults());
			if ('heredar' === $cfg['mode']) {
				/* En el general no hay de quién heredar: el default del catálogo manda. */
				$cfg['mode'] = isset($meta['default']) ? $meta['default'] : 'opcional';
			}
			$clean[$slug] = $cfg;
		}
		if (null === $key) {
			return $clean;
		}
		return isset($clean[$key]) ? $clean[$key] : self::field_defaults();
	}

	public static function update_general(array $in) {
		$option            = self::option();
		$clean             = [];
		foreach (self::catalog() as $key => $meta) {
			$clean[$key] = self::sanitize_field(isset($in[$key]) ? $in[$key] : [], false);
		}
		$option['general'] = $clean;
		update_option(MJE_Settings::FIELDS, $option);
		self::$cache = null;
	}

	/* --------------------------- Campos propios -------------------------- */

	public static function custom() {
		$out = [];
		foreach (self::option()['custom'] as $key => $def) {
			$key = sanitize_key($key);
			if ('' === $key || !is_array($def)) {
				continue;
			}
			$out[$key] = wp_parse_args($def, ['label' => '', 'type' => 'text', 'options' => '']);
		}
		return $out;
	}

	public static function update_custom(array $in) {
		$option = self::option();
		$clean  = [];
		foreach ($in as $key => $def) {
			$key   = sanitize_key(is_array($def) && !empty($def['key']) ? $def['key'] : $key);
			$label = is_array($def) && isset($def['label']) ? trim(sanitize_text_field($def['label'])) : '';
			if ('' === $key || '' === $label) {
				continue;
			}
			$type        = is_array($def) && isset($def['type']) ? sanitize_key($def['type']) : 'text';
			$clean[$key] = [
				'label'   => mb_substr($label, 0, 120),
				'type'    => array_key_exists($type, self::types()) ? $type : 'text',
				'options' => is_array($def) && isset($def['options']) ? sanitize_textarea_field($def['options']) : '',
			];
		}
		$option['custom'] = $clean;
		update_option(MJE_Settings::FIELDS, $option);
		self::$cache = null;
	}

	/* ------------------------------ Sanitizado --------------------------- */

	/** @param bool $allow_inherit Los campos por método sí pueden quedar en 'heredar'. */
	public static function sanitize_field($in, $allow_inherit = true) {
		$out = self::field_defaults();
		$in  = is_array($in) ? $in : [];

		$mode        = isset($in['mode']) ? sanitize_key($in['mode']) : 'heredar';
		$out['mode'] = array_key_exists($mode, self::modes($allow_inherit)) ? $mode : ($allow_inherit ? 'heredar' : 'opcional');

		foreach (['label', 'placeholder', 'help'] as $key) {
			$out[$key] = isset($in[$key]) ? mb_substr(trim(sanitize_text_field($in[$key])), 0, 190) : '';
		}
		$out['options'] = isset($in['options']) ? sanitize_textarea_field($in['options']) : '';
		$out['order']   = isset($in['order']) ? max(0, min(9999, (int) $in['order'])) : 0;

		return $out;
	}

	/** Los campos por método que vienen de un formulario. */
	public static function sanitize_method_fields($in) {
		$clean = [];
		$in    = is_array($in) ? $in : [];
		foreach (self::catalog() as $key => $meta) {
			if (!isset($in[$key])) {
				continue;
			}
			$cfg = self::sanitize_field($in[$key], true);
			/* Un campo que hereda y no cambia ningún texto no necesita guardarse. */
			if ('heredar' === $cfg['mode'] && '' === $cfg['label'] && '' === $cfg['placeholder'] && '' === $cfg['help'] && '' === $cfg['options'] && 0 === $cfg['order']) {
				continue;
			}
			$clean[$key] = $cfg;
		}
		return $clean;
	}

	/* ------------------------------- Resolver ---------------------------- */

	/**
	 * ¿El destino del método esconde este campo por su naturaleza?
	 *
	 * Solo se aplica a los campos que quedaron en 'heredar': un ajuste explícito
	 * del método siempre gana.
	 */
	private static function hidden_by_destination($meta, array $def) {
		$destination = isset($def['destination']) ? (string) $def['destination'] : 'domicilio';
		if (!empty($meta['home']) && 'domicilio' !== $destination) {
			return true; // Calle, piso y entre calles solo tienen sentido a domicilio.
		}
		if (!empty($meta['branch']) && 'sucursal' !== $destination) {
			return true; // La sucursal, solo cuando se retira en una.
		}
		if (!empty($meta['geo']) && 'local' === $destination) {
			return true; // Retiro en el local: no hace falta destino.
		}
		return false;
	}

	/**
	 * Los campos efectivos de un método, ordenados y listos para mostrar.
	 *
	 * → [ ['key','label','type','required','placeholder','help','options','target','auto'] ]
	 */
	public static function resolve(array $def) {
		$general  = self::general();
		$catalog  = self::catalog();
		$per      = isset($def['fields']) && is_array($def['fields']) ? $def['fields'] : [];
		$out      = [];

		foreach ($catalog as $key => $meta) {
			$base = isset($general[$key]) ? $general[$key] : self::field_defaults();
			$own  = isset($per[$key]) && is_array($per[$key]) ? wp_parse_args($per[$key], self::field_defaults()) : self::field_defaults();

			$mode = 'heredar' === $own['mode'] ? $base['mode'] : $own['mode'];
			if ('heredar' === $own['mode'] && self::hidden_by_destination($meta, $def)) {
				$mode = 'no';
			}
			if ('no' === $mode) {
				continue;
			}

			$label = '' !== $own['label'] ? $own['label'] : ('' !== $base['label'] ? $base['label'] : (string) $meta['label']);
			$help  = '' !== $own['help'] ? $own['help'] : ('' !== $base['help'] ? $base['help'] : (isset($meta['help']) ? (string) $meta['help'] : ''));
			$ph    = '' !== $own['placeholder'] ? $own['placeholder'] : $base['placeholder'];
			$opts  = '' !== $own['options'] ? $own['options'] : ('' !== $base['options'] ? $base['options'] : (isset($meta['options']) ? (string) $meta['options'] : ''));
			$order = $own['order'] > 0 ? $own['order'] : $base['order'];

			$out[] = [
				'key'         => $key,
				'label'       => $label,
				'type'        => (string) $meta['type'],
				'required'    => 'obligatorio' === $mode,
				'placeholder' => $ph,
				'help'        => $help,
				'options'     => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $opts)))),
				'target'      => (string) $meta['target'],
				'auto'        => isset($meta['auto']) ? (string) $meta['auto'] : '',
				'custom'      => !empty($meta['custom']),
				'order'       => (int) $order,
			];
		}

		usort($out, function ($a, $b) {
			return $a['order'] === $b['order'] ? strcmp($a['key'], $b['key']) : ($a['order'] < $b['order'] ? -1 : 1);
		});

		return $out;
	}

	/**
	 * Valida lo que llegó para un método.
	 *
	 * @return array ['clean' => [key => valor], 'errors' => [texto, ...]]
	 */
	public static function validate(array $def, $values) {
		$values = is_array($values) ? $values : [];
		$clean  = [];
		$errors = [];

		foreach (self::resolve($def) as $field) {
			$key = $field['key'];
			$raw = isset($values[$key]) ? $values[$key] : '';
			if ('checkbox' === $field['type']) {
				$value = empty($raw) ? '' : '1';
			} elseif ('textarea' === $field['type']) {
				$value = trim(sanitize_textarea_field((string) $raw));
			} else {
				$value = trim(sanitize_text_field((string) $raw));
			}

			if ('' === $value) {
				if ($field['required']) {
					$errors[] = sprintf('Falta completar "%s".', $field['label']);
				}
				continue;
			}

			if ('email' === $field['type'] && !is_email($value)) {
				$errors[] = sprintf('El email de "%s" no es válido.', $field['label']);
				continue;
			}
			if ('select' === $field['type'] && $field['options'] && !in_array($value, $field['options'], true) && 'branch' !== $key && 'state' !== $key) {
				$errors[] = sprintf('Elegí una opción válida en "%s".', $field['label']);
				continue;
			}
			if ('postcode' === $key) {
				$value = MJE_Geo::normalize_cp($value);
				if (!MJE_Geo::cp_valid($value)) {
					$errors[] = (string) MJE_Settings::get('cp_invalid');
					continue;
				}
			}
			if ('doc' === $key) {
				$digits = preg_replace('/\D+/', '', $value);
				if (strlen((string) $digits) < 7) {
					$errors[] = sprintf('El número de "%s" parece incompleto.', $field['label']);
					continue;
				}
				$value = (string) $digits;
			}

			$clean[$key] = mb_substr($value, 0, 'textarea' === $field['type'] ? 500 : 190);
		}

		return ['clean' => $clean, 'errors' => $errors];
	}

	/** Lo que ve la tienda: los campos de cada método, listos para dibujar. */
	public static function public_payload(array $def) {
		$out = [];
		foreach (self::resolve($def) as $field) {
			unset($field['order']);
			$out[] = $field;
		}
		return $out;
	}

	/** Etiqueta de un campo para el admin y los emails. */
	public static function label($key, array $def = []) {
		foreach (self::resolve($def ? $def : ['destination' => 'domicilio']) as $field) {
			if ($field['key'] === $key) {
				return $field['label'];
			}
		}
		$catalog = self::catalog();
		return isset($catalog[$key]['label']) ? (string) $catalog[$key]['label'] : $key;
	}
}
