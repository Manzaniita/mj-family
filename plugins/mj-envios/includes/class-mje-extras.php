<?php
/**
 * Los datos que OTROS plugins le exigen a la dirección del pedido.
 *
 * WooCommerce deja que un plugin agregue campos propios a la dirección del
 * checkout (`woocommerce_register_additional_checkout_field`), y varios plugins
 * de correos lo usan: el de Andreani, por ejemplo, agrega un "DNI" obligatorio.
 * Esos campos NO son de MJ Envíos y no aparecen en su catálogo, pero WooCommerce
 * los valida igual al cerrar el pedido: si falta uno obligatorio, la compra se
 * cae con "parámetro no válido: billing_address" y el cliente no entiende nada.
 *
 * Acá se los adopta, en dos movimientos:
 *
 *   1. Se suman al catálogo de campos de MJ Envíos (MJE_Fields::catalog), así el
 *      paso de envío los pide como a cualquier otro dato, se configuran desde el
 *      panel y se guardan en el pedido. Vienen en el modo que pide su plugin:
 *      obligatorio si él los exige.
 *   2. Se completan en la llamada del checkout ANTES de que WooCommerce valide
 *      (fill_request). Así el pedido se cierra aunque la tienda no sepa nada de
 *      ese campo: el dato que cargó el cliente en el paso de envío es el que
 *      viaja a la dirección.
 *
 * Nada de esto nombra a ningún correo: lo que se adopta es lo que haya
 * registrado, del plugin que sea. Si no hay ninguno, esta clase no hace nada.
 */

defined('ABSPATH') || exit;

class MJE_Extras {

	/**
	 * Con qué prefijo se nombra acá un campo ajeno.
	 *
	 * El id real lleva barra ('andreani/dni') y las claves de MJ Envíos pasan por
	 * sanitize_key(), que se la come. Se guarda el id completo aparte y se
	 * traduce al mandarlo.
	 */
	const PREFIX = 'x_';

	/** Campos ya resueltos en esta carga (null = todavía no se miró). */
	private static $cache = null;

	/** Freno de recursión: mirar el locale dispara filtros que vuelven acá. */
	private static $looking = false;

	public static function init() {
		/*
		 * Antes de que la Store API valide el checkout.
		 *
		 * Tiene que ser `rest_pre_dispatch`: es el único punto que corre ANTES de
		 * has_valid_params(), que es donde WooCommerce rechaza la dirección a la
		 * que le falta un campo obligatorio. `rest_request_before_callbacks`, que
		 * suena parecido, ya llega tarde: para entonces el 400 está decidido.
		 */
		add_filter('rest_pre_dispatch', [__CLASS__, 'fill_request'], 10, 3);
		/* Checkout clásico: los campos van al POST con el mismo nombre. */
		add_action('woocommerce_checkout_process', [__CLASS__, 'fill_post'], 5);
	}

	/* ---------------------------- Qué exigen ----------------------------- */

	/**
	 * Los campos de dirección que registró otro plugin.
	 *
	 * Salen de los campos de dirección por defecto de WooCommerce: ahí es donde
	 * terminan todos los campos adicionales, porque WooCommerce los suma con el
	 * filtro `woocommerce_get_country_locale_default`. Se leen así y no de las
	 * clases internas de WooCommerce a propósito: esto es API pública y no cambia
	 * de lugar entre versiones.
	 *
	 * Se aplica el filtro a mano en vez de pedir `get_country_locale()`: esa
	 * llamada le deja el locale armado y guardado a WooCommerce, y armárselo
	 * antes de tiempo le cambiaría qué campos exige después.
	 *
	 * → [ 'x_andreani_dni' => ['id','label','type','required','choices'] ]
	 */
	public static function fields() {
		if (null !== self::$cache) {
			return self::$cache;
		}
		if (self::$looking || !function_exists('WC') || !WC()->countries) {
			return [];
		}

		self::$looking = true;
		$defaults      = MJE_Native::ask('campos de otros plugins', function () {
			/** Filtro de WooCommerce: es donde se suman los campos de otros plugins. */
			return apply_filters('woocommerce_get_country_locale_default', WC()->countries->get_default_address_fields());
		}, []);
		self::$looking = false;

		$defaults = is_array($defaults) ? $defaults : [];
		$out      = [];
		foreach ($defaults as $id => $meta) {
			$id = (string) $id;
			/* Los campos adicionales se registran siempre como "plugin/campo". */
			if (false === strpos($id, '/') || !is_array($meta)) {
				continue;
			}
			$key = self::key_of($id);
			if ('' === $key) {
				continue;
			}
			$choices = [];
			foreach ((array) (isset($meta['options']) ? $meta['options'] : []) as $value => $label) {
				/* La Store API las publica como [['value'=>..,'label'=>..]]; el locale, como value => label. */
				if (is_array($label)) {
					$value = isset($label['value']) ? $label['value'] : $value;
					$label = isset($label['label']) ? $label['label'] : $value;
				}
				$choices[(string) $value] = trim((string) $label);
			}
			$out[$key] = [
				'id'       => $id,
				'label'    => trim((string) (isset($meta['label']) ? $meta['label'] : $id)),
				'type'     => self::type_of(isset($meta['type']) ? (string) $meta['type'] : 'text'),
				'required' => !empty($meta['required']),
				'choices'  => $choices,
				'plugin'   => self::plugin_of($id),
			];
		}

		/* Todavía no está todo registrado: no se cachea una lista a medias. */
		if (did_action('wp_loaded')) {
			self::$cache = $out;
		}
		return $out;
	}

	public static function flush() {
		self::$cache = null;
	}

	/** 'andreani/dni' → 'x_andreani_dni'. */
	public static function key_of($id) {
		return self::PREFIX . sanitize_key(str_replace('/', '_', (string) $id));
	}

	/** Quién lo pide, para poder decirlo en el panel: 'andreani/dni' → 'andreani'. */
	private static function plugin_of($id) {
		$parts = explode('/', (string) $id);
		return ucfirst(str_replace(['-', '_'], ' ', (string) $parts[0]));
	}

	/** Los tipos de campo de WooCommerce, en los de MJ Envíos. */
	private static function type_of($type) {
		$map = ['text' => 'text', 'select' => 'select', 'checkbox' => 'checkbox'];
		return isset($map[$type]) ? $map[$type] : 'text';
	}

	/** ¿Hay alguno? (para no trabajar de gusto en cada cálculo) */
	public static function any() {
		return (bool) self::fields();
	}

	/* --------------------------- Lo que se manda ------------------------- */

	/**
	 * Lo que cargó el cliente, con el nombre que espera el plugin que lo pide.
	 *
	 * → ['andreani/dni' => '30123456']
	 *
	 * @param array $values Los datos del envío guardados (MJE_Cart::session_data()['fields']).
	 */
	public static function values($values) {
		$values = is_array($values) ? $values : [];
		$out    = [];
		foreach (self::fields() as $key => $field) {
			$value = isset($values[$key]) ? trim((string) $values[$key]) : '';
			if ('' === $value) {
				continue;
			}
			/*
			 * En las listas el cliente elige el texto y el plugin espera su valor
			 * interno. Si no se reconoce, viaja tal cual: es mejor que nada.
			 */
			if ('select' === $field['type'] && $field['choices']) {
				$found = array_search($value, $field['choices'], true);
				if (false !== $found) {
					$value = (string) $found;
				}
			}
			$out[$field['id']] = $value;
		}
		return $out;
	}

	/**
	 * Lo que el cliente YA tiene cargado de antes, con la clave de MJ Envíos.
	 *
	 * WooCommerce guarda estos campos en la cuenta del cliente cuando compró una
	 * vez. Traerlos evita pedirle el mismo DNI en cada compra, y evita que el
	 * pedido se caiga por un dato que el servidor ya conocía.
	 *
	 * → ['x_andreani_dni' => '30123456']
	 */
	public static function known() {
		$fields = self::fields();
		if (!$fields || !function_exists('WC') || !WC()->customer) {
			return [];
		}
		$user = get_current_user_id();
		$out  = [];
		foreach ($fields as $key => $field) {
			$value = '';
			/* Así los guarda WooCommerce en la cuenta: el del envío antes que el de la factura. */
			foreach (['_wc_shipping/', '_wc_billing/'] as $group) {
				$saved = WC()->customer->get_meta($group . $field['id']);
				if ((!is_scalar($saved) || '' === trim((string) $saved)) && $user) {
					$saved = get_user_meta($user, $group . $field['id'], true);
				}
				if (is_scalar($saved) && '' !== trim((string) $saved)) {
					$value = trim((string) $saved);
					break;
				}
			}
			if ('' === $value) {
				continue;
			}
			/* En las listas se guarda el valor interno y el cliente ve el texto. */
			if ('select' === $field['type'] && isset($field['choices'][$value])) {
				$value = (string) $field['choices'][$value];
			}
			$out[$key] = $value;
		}
		return $out;
	}

	/**
	 * Completa los campos ajenos en la llamada del checkout de la Store API.
	 *
	 * Solo rellena lo que falta: si la tienda ya los mandó, manda la tienda.
	 *
	 * @param mixed           $result  Respuesta anticipada (se devuelve intacta).
	 * @param mixed           $server  El servidor REST.
	 * @param WP_REST_Request $request La llamada.
	 */
	public static function fill_request($result, $server, $request) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Es la firma del filtro de WordPress.
		if (null !== $result || !$request instanceof WP_REST_Request || 'POST' !== $request->get_method()) {
			return $result;
		}
		$route = (string) $request->get_route();
		if (false === strpos($route, '/wc/store/') || false === strpos($route, '/checkout')) {
			return $result;
		}
		if (!MJE_Settings::get('enabled') || !self::any()) {
			return $result;
		}

		/* Lo que la tienda manda en esta misma llamada es lo más nuevo que hay. */
		$extensions = $request->get_param('extensions');
		if (is_array($extensions) && isset($extensions['mje']) && is_array($extensions['mje'])) {
			MJE_Cart::update_callback($extensions['mje']);
		}

		$data   = MJE_Cart::session_data();
		$fields = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [];
		/* Lo que cargó ahora el cliente gana; lo guardado de antes completa el resto. */
		$values = self::values(array_merge(self::known(), array_filter($fields, function ($value) {
			return '' !== trim((string) $value);
		})));
		if (!$values) {
			return $result;
		}

		foreach (['billing_address', 'shipping_address'] as $param) {
			$address = $request->get_param($param);
			if (!is_array($address)) {
				continue;
			}
			$changed = false;
			foreach ($values as $id => $value) {
				if (isset($address[$id]) && '' !== trim((string) $address[$id])) {
					continue;
				}
				$address[$id] = $value;
				$changed      = true;
			}
			if ($changed) {
				$request->set_param($param, $address);
			}
		}

		return $result;
	}

	/** Lo mismo en el checkout clásico, donde los campos viajan en el POST. */
	public static function fill_post() {
		if (!MJE_Settings::get('enabled') || !self::any()) {
			return;
		}
		$data   = MJE_Cart::session_data();
		$fields = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [];
		$values = self::values(array_merge(self::known(), array_filter($fields, function ($value) {
			return '' !== trim((string) $value);
		})));
		foreach ($values as $id => $value) {
			/* Así los nombra WooCommerce en el formulario clásico. */
			foreach (['_wc_billing/', '_wc_shipping/'] as $group) {
				$name = $group . $id;
				// phpcs:disable WordPress.Security.NonceVerification.Missing -- El nonce del checkout ya lo validó WooCommerce antes de este hook.
				$cargado = isset($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';
				if ('' === trim($cargado)) {
					$_POST[$name] = $value;
				}
				// phpcs:enable WordPress.Security.NonceVerification.Missing
			}
		}
	}
}
