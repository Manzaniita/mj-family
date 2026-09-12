<?php
/**
 * La sesión del envío: qué método está elegido, a qué sucursal va y qué datos
 * cargó el cliente.
 *
 * La tienda React manda todo esto por la Store API con
 * POST /wc/store/v1/cart/extensions {namespace: "mje", data: {...}}, igual que
 * MJ Medios de Pago con su medio de pago. El checkout clásico lo recibe por sus
 * propios campos. En los dos casos termina en el mismo lugar: la sesión de
 * WooCommerce, que es lo que lee el cálculo de tarifas y lo que se copia al
 * pedido cuando se confirma.
 */

defined('ABSPATH') || exit;

class MJE_Cart {

	const SESSION_KEY = 'mje_data';

	private static $cache = null;

	public static function init() {
		/* Store API: el paso de envío de la tienda escribe acá. */
		if (did_action('woocommerce_blocks_loaded')) {
			self::register_update_callback();
		} else {
			add_action('woocommerce_blocks_loaded', [__CLASS__, 'register_update_callback']);
		}

		/* Checkout clásico: los campos del paso de envío llegan en el POST. */
		add_action('woocommerce_checkout_update_order_review', [__CLASS__, 'capture_classic_review'], 10, 1);
		add_action('woocommerce_checkout_process', [__CLASS__, 'capture_classic_post'], 10);
		add_action('woocommerce_after_checkout_validation', [__CLASS__, 'validate_classic'], 20, 2);

		/* Store API: validar antes de que el pedido quede hecho. */
		add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'validate_store_api'], 5, 2);

		/* La localidad que eligió el cliente entra a la dirección del carrito. */
		add_action('woocommerce_customer_save_address', [__CLASS__, 'forget'], 10, 1);
	}

	public static function register_update_callback() {
		if (!function_exists('woocommerce_store_api_register_update_callback')) {
			return;
		}
		woocommerce_store_api_register_update_callback([
			'namespace' => 'mje',
			'callback'  => [__CLASS__, 'update_callback'],
		]);
	}

	/**
	 * Lo que manda la tienda: el método elegido, la sucursal y los datos del
	 * formulario. Se guarda todo junto y el carrito se recalcula solo.
	 */
	public static function update_callback($data) {
		$data = is_array($data) ? $data : [];
		$in   = [];
		if (isset($data['method'])) {
			$in['method'] = sanitize_key($data['method']);
		}
		if (isset($data['branch'])) {
			$in['branch'] = mb_substr(sanitize_text_field((string) $data['branch']), 0, 60);
		}
		if (isset($data['city'])) {
			$in['city'] = mb_substr(sanitize_text_field((string) $data['city']), 0, 160);
		}
		if (isset($data['fields']) && is_array($data['fields'])) {
			$in['fields'] = $data['fields'];
		}
		self::set_data($in);

		/* Elegir método acá es lo mismo que elegirlo en el resumen del carrito. */
		if (!empty($in['method'])) {
			self::choose($in['method']);
		}
	}

	/* ------------------------------- Sesión ------------------------------ */

	public static function session_data() {
		if (null !== self::$cache) {
			return self::$cache;
		}
		$saved = [];
		if (function_exists('WC') && WC()->session) {
			$saved = WC()->session->get(self::SESSION_KEY);
		}
		self::$cache = wp_parse_args(is_array($saved) ? $saved : [], [
			'method' => '',
			'branch' => '',
			'city'   => '',
			'fields' => [],
		]);
		return self::$cache;
	}

	/** Guarda (parcialmente) los datos del envío en la sesión. */
	public static function set_data(array $in) {
		if (!function_exists('WC') || !WC()->session) {
			return;
		}
		$data = self::session_data();
		foreach (['method', 'branch', 'city'] as $key) {
			if (array_key_exists($key, $in)) {
				$data[$key] = (string) $in[$key];
			}
		}
		if (array_key_exists('fields', $in) && is_array($in['fields'])) {
			$def    = self::method_def(isset($data['method']) ? $data['method'] : '');
			$result = $def ? MJE_Fields::validate($def, $in['fields']) : ['clean' => []];
			/* Lo que no valida igual se guarda como texto: la validación final es al confirmar. */
			$clean = $result['clean'];
			foreach ($in['fields'] as $key => $value) {
				$key = sanitize_key($key);
				if ('' !== $key && !isset($clean[$key])) {
					$clean[$key] = mb_substr(trim(sanitize_text_field((string) $value)), 0, 500);
				}
			}
			$data['fields'] = $clean;
			/* La localidad del formulario es la del envío. */
			if (isset($clean['city']) && '' !== $clean['city']) {
				$data['city'] = $clean['city'];
			}
		}
		WC()->session->set(self::SESSION_KEY, $data);
		self::$cache = $data;
		self::invalidate_rates();
	}

	public static function forget() {
		self::$cache = null;
	}

	/** Las tarifas guardadas dejan de valer cuando cambia lo que las define. */
	private static function invalidate_rates() {
		if (class_exists('WC_Cache_Helper')) {
			WC_Cache_Helper::get_transient_version('shipping', true);
		}
		if (function_exists('WC') && WC()->session) {
			WC()->session->set('shipping_for_package_0', null);
		}
		if (function_exists('WC') && WC()->cart) {
			WC()->cart->calculate_shipping();
		}
	}

	/* ------------------------------ Elección ----------------------------- */

	/** Marca un método como el elegido, con el id de tarifa que espera WooCommerce. */
	public static function choose($slug) {
		$slug = sanitize_key($slug);
		$def  = self::method_def($slug);
		if (!$def || !function_exists('WC') || !WC()->session) {
			return false;
		}
		$chosen    = (array) WC()->session->get('chosen_shipping_methods', []);
		$chosen[0] = MJE_Rates::rate_id($slug);
		WC()->session->set('chosen_shipping_methods', $chosen);
		return true;
	}

	/** El medio de pago vigente en la sesión ('' si todavía no eligió). */
	public static function chosen_gateway() {
		if (class_exists('MJPM_Cart') && is_callable(['MJPM_Cart', 'effective_method_id'])) {
			/* Con MJ Medios de Pago, el medio "vigente" ya considera el default del admin. */
			return (string) MJPM_Cart::effective_method_id();
		}
		if (function_exists('WC') && WC()->session) {
			return sanitize_key((string) WC()->session->get('chosen_payment_method'));
		}
		return '';
	}

	/**
	 * Definición del método elegido (o null).
	 *
	 * Manda la tarifa elegida en el carrito. Si hay una elegida y NO es de este
	 * plugin (una zona de WooCommerce, por ejemplo), devuelve null a propósito:
	 * de lo contrario un método que quedó viejo en la sesión seguiría pidiendo
	 * datos que ya no corresponden.
	 */
	public static function chosen_method() {
		$slug     = '';
		$has_rate = false;
		if (function_exists('WC') && WC()->session) {
			$chosen = array_filter((array) WC()->session->get('chosen_shipping_methods', []));
			foreach ($chosen as $rate_id) {
				$has_rate = true;
				$found    = MJE_Rates::slug_from_rate($rate_id);
				if ('' !== $found) {
					$slug = $found;
					break;
				}
			}
		}
		if ('' === $slug && $has_rate) {
			return null;
		}
		if ('' === $slug) {
			$data = self::session_data();
			$slug = (string) $data['method'];
		}
		return self::method_def($slug);
	}

	private static function method_def($slug) {
		$slug = sanitize_key((string) $slug);
		return '' !== $slug ? MJE_Methods::get($slug) : null;
	}

	/* ----------------------------- Validación ---------------------------- */

	/**
	 * ¿Falta algo para poder cerrar el pedido con este método?
	 *
	 * → lista de textos para mostrar. Vacía = todo en orden.
	 */
	public static function problems() {
		if (!MJE_Settings::get('enabled')) {
			return [];
		}
		if (function_exists('WC') && WC()->cart && !WC()->cart->needs_shipping()) {
			return [];
		}
		$def = self::chosen_method();
		if (!$def) {
			return []; // Sin método propio elegido no hay nada nuestro que exigir.
		}
		$data   = self::session_data();
		$result = MJE_Fields::validate($def, isset($data['fields']) ? $data['fields'] : []);
		$errors = $result['errors'];

		if ('sucursal' === (string) $def['destination'] && '' === trim((string) $data['branch']) && !isset($result['clean']['branch'])) {
			$errors[] = sprintf('Elegí la %s.', strtolower((string) MJE_Settings::get('branch_label')));
		}
		return $errors;
	}

	/** Checkout clásico: los campos viajan serializados en el review del pedido. */
	public static function capture_classic_review($posted) {
		$parsed = [];
		parse_str((string) $posted, $parsed);
		self::capture_from($parsed);
	}

	public static function capture_classic_post() {
		self::capture_from($_POST); // phpcs:ignore WordPress.Security.NonceVerification
	}

	private static function capture_from($source) {
		if (!is_array($source)) {
			return;
		}
		$in = [];
		if (isset($source['mje_branch'])) {
			$in['branch'] = (string) $source['mje_branch'];
		}
		if (isset($source['mje_fields']) && is_array($source['mje_fields'])) {
			$in['fields'] = wp_unslash($source['mje_fields']);
		}
		if ($in) {
			self::set_data($in);
		}
	}

	public static function validate_classic($data, $errors) {
		if (!$errors instanceof WP_Error) {
			return;
		}
		foreach (self::problems() as $problem) {
			$errors->add('mje_shipping', $problem);
		}
	}

	/**
	 * Store API: última red antes de crear el pedido.
	 *
	 * La tienda ya valida con los mismos campos que publica el plugin; esto es
	 * para que un pedido armado a mano contra la API no pueda saltearse un dato
	 * obligatorio.
	 */
	public static function validate_store_api($order, $request) {
		if ($request instanceof WP_REST_Request) {
			$extensions = $request->get_param('extensions');
			if (is_array($extensions) && isset($extensions['mje']) && is_array($extensions['mje'])) {
				self::update_callback($extensions['mje']);
			}
		}
		$problems = self::problems();
		if (!$problems) {
			return;
		}
		$message = implode(' ', $problems);
		if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('mje_shipping_incomplete', $message, 400);
		}
		throw new \Exception($message);
	}
}
