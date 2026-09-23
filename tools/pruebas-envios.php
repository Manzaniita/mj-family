<?php
/** Regresión sin WordPress: php tools/pruebas-envios.php. Nunca consulta correos reales. */
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);

function comprobar($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}
function sanitize_key($value) { return $value; }
function wp_parse_args($value, $defaults) { return array_merge($defaults, $value); }
function get_transient($key) { return null; }
function set_transient($key, $value, $ttl) {}
function add_filter($name, $callback, $priority, $args) { $GLOBALS['filters'][$name] = $callback; }
function apply_filters($name, $value) { return 'mje_native_plugins' === $name ? ['Correo_Prueba'] : $value; }
function WC() { return $GLOBALS['wc']; }
class MJE_Settings {
	public static $enabled = true;
	public static function get($key) { return self::$enabled; }
}
class MJE_Geo {
	public static $logs = [];
	public static function log($area, $message) { self::$logs[] = $message; }
}
class MJE_Rates {
	public static function rate_id($slug) { return 'mje:' . $slug; }
}
class MJE_Methods {
	public static function get($slug) { return 'nuevo' === $slug ? ['slug' => $slug] : null; }
}
class WC_Shipping_Method {
	public $id = 'correo';
	public $instance_id = 7;
	public $enabled = 'yes';
	public $title = 'Correo';
	public $method_title = 'Correo';
	public $method_description = '';
	public $supports = ['shipping-zones'];
	public $error;
	public function supports($feature) { return in_array($feature, $this->supports, true); }
	public function get_rates_for_package($package) {
		if ($this->error) { throw $this->error; }
		return ['tarifa' => $package['destination']];
	}
}
require __DIR__ . '/../plugins/mj-envios/includes/nativos/class-mje-native-plugin.php';
require __DIR__ . '/../plugins/mj-envios/includes/nativos/class-mje-native.php';
require __DIR__ . '/../plugins/mj-envios/includes/class-mje-cart.php';
class Correo_Prueba extends MJE_Native_Plugin {
	public function id() { return 'prueba'; }
	public function label() { return 'Correo de prueba'; }
	public function method_id() { return 'correo'; }
	public function available() { return true; }
}
$GLOBALS['wc'] = new class {
	public $session;
	public $cart;
	public $shipping;
	public function shipping() { return $this->shipping; }
};
WC()->session = new class {
	private $data = [];
	public function get($key, $default = null) { return isset($this->data[$key]) ? $this->data[$key] : $default; }
	public function set($key, $value) { $this->data[$key] = $value; }
};
WC()->cart = new class {
	public function calculate_shipping() { throw new RuntimeException('Cálculo prematuro antes de elegir el método'); }
};
$bad = new WC_Shipping_Method();
$bad->error = new RuntimeException('access token no válido o indefinido');
$good = new WC_Shipping_Method();
$other = new WC_Shipping_Method();
$other->id = 'retiro';
WC()->shipping = (object) ['shipping_methods' => [$bad, $good, $other]];
MJE_Native::protect_methods([]);
comprobar(WC()->shipping->shipping_methods[0] === $bad, 'No modificar métodos sin un paquete');
$package = ['destination' => ['postcode' => '1000']];
MJE_Native::protect_methods($package);
$methods = WC()->shipping->shipping_methods;
comprobar($methods[2] === $other, 'No envolver métodos ajenos');
comprobar(7 === $methods[0]->instance_id && $methods[0]->supports('shipping-zones'), 'Conservar identidad y capacidades');
comprobar([] === $methods[0]->get_rates_for_package($package), 'La excepción no debe escapar');
comprobar(['tarifa' => $package['destination']] === $methods[1]->get_rates_for_package($package), 'Conservar la tarifa sana');
$bad->error = new TypeError('Respuesta inválida');
comprobar([] === $methods[0]->get_rates_for_package($package), 'También aislar errores de tipo');
MJE_Native::capture([]);
comprobar('' !== MJE_Native::excuse('prueba'), 'Conservar el aviso después de capturar tarifas');
comprobar(false === strpos(MJE_Native::excuse('prueba'), 'token'), 'No exponer detalles técnicos al cliente');
MJE_Cart::update_callback(['method' => 'nuevo']);
comprobar(['mje:nuevo'] === WC()->session->get('chosen_shipping_methods'), 'Elegir en la misma operación');
$choose = $GLOBALS['filters']['woocommerce_shipping_chosen_method'];
comprobar('mje:nuevo' === $choose('otro', ['mje:nuevo' => true]), 'Conservar la elección si cambió la lista');
comprobar('otro' === $choose('otro', ['otro' => true]), 'No forzar una tarifa que no existe');
echo "OK: error de token y TypeError aislados, tarifa sana conservada, elección atómica y fallback válido.\n";
