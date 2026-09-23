<?php
/** php tools/pruebas-documento-envios.php: sin pedidos ni servicios reales. */
define('ABSPATH', __DIR__);
function comprobar($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $value)); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function wp_unslash($value) { return $value; }
function wp_parse_args($value, $defaults) { return array_merge($defaults, $value); }
function get_option($key, $default = []) { return $default; }
function did_action($name) { return true; }
function get_current_user_id() { return 0; }
function apply_filters($name, $value, $id = '') { return $value; }
function WC() { return $GLOBALS['wc']; }
class MJE_Settings { const FIELDS = 'mje_fields'; public static function get($key) { return true; } }
class MJE_Native { public static function ask($scope, $callback, $default = []) { return $callback(); } }
class MJE_Cart {
	public static $fields = [];
	public static function session_data() { return ['fields' => self::$fields, 'branch' => '']; }
	public static function update_callback($data) { self::$fields = $data['fields']; }
	public static function chosen_method() {
		return ['slug' => 'prueba', 'type' => 'fijo', 'destination' => 'domicilio', 'carrier' => '', 'service' => '', 'instructions' => '', 'erp_delivery' => ''];
	}
}
class MJE_Methods {
	public static function name($def) { return 'Envío de prueba'; }
	public static function eta($def) { return ''; }
}
class WC_Order {
	public $meta = [];
	public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
	public function get_items($type) { return []; }
}
class WP_REST_Request {
	public $params;
	public function __construct($params) { $this->params = $params; }
	public function get_method() { return 'POST'; }
	public function get_route() { return '/wc/store/v1/checkout'; }
	public function get_param($key) { return isset($this->params[$key]) ? $this->params[$key] : null; }
	public function set_param($key, $value) { $this->params[$key] = $value; }
}
$GLOBALS['wc'] = (object) [
	'session' => null,
	'countries' => new class {
		public function get_default_address_fields() {
			return [
				'andreani/dni' => ['label' => 'DNI', 'required' => true],
				'correo-argentino/documento' => ['label' => 'Documento', 'required' => true],
				'otro/cuit' => ['label' => 'CUIT'],
				'otro/document_type' => ['label' => 'Tipo de documento', 'type' => 'select'],
				'otro/referencia' => ['label' => 'Referencia'],
			];
		}
	},
	'customer' => new class {
		public function get_meta($key) { return '_wc_shipping/andreani/dni' === $key ? '30123456' : ''; }
	},
];
require __DIR__ . '/../plugins/mj-envios/includes/class-mje-extras.php';
require __DIR__ . '/../plugins/mj-envios/includes/class-mje-fields.php';
require __DIR__ . '/../plugins/mj-envios/includes/class-mje-orders.php';
$catalog = MJE_Fields::catalog();
comprobar(isset($catalog['doc']) && !isset($catalog['x_andreani_dni']), 'Un solo documento visible');
comprobar(!isset($catalog['x_correo-argentino_documento']) && !isset($catalog['x_otro_cuit']), 'Unificar todos los documentos reconocidos');
comprobar(isset($catalog['x_otro_document_type'], $catalog['x_otro_referencia']), 'Conservar campos que no son números de documento');
$values = MJE_Extras::values(['doc' => '20301234567', 'x_andreani_dni' => '99999999']);
foreach (['andreani/dni', 'correo-argentino/documento', 'otro/cuit'] as $id) {
	comprobar('20301234567' === $values[$id], 'doc debe llegar a ' . $id);
}
comprobar(['doc' => '30123456'] === MJE_Extras::known(), 'Recuperar el documento guardado con la clave común');
comprobar(['doc' => '30123456'] === MJE_Extras::canonical_values(['x_andreani_dni' => '30123456']), 'Compatibilidad con sesiones anteriores');
comprobar([] === MJE_Extras::values(['doc' => '', 'x_andreani_dni' => '30123456']), 'No resucitar un documento borrado');
$request = new WP_REST_Request([
	'extensions' => ['mje' => ['fields' => ['doc' => '40123456']]],
	'billing_address' => ['andreani/dni' => '11111111'],
	'shipping_address' => [],
]);
MJE_Extras::fill_request(null, null, $request);
foreach (['billing_address', 'shipping_address'] as $address) {
	foreach (['andreani/dni', 'correo-argentino/documento', 'otro/cuit'] as $id) {
		comprobar('40123456' === $request->params[$address][$id], 'La Store API recibe doc actual, no el antiguo');
	}
}
$_POST = ['_wc_shipping/andreani/dni' => '11111111'];
MJE_Extras::fill_post();
comprobar('40123456' === $_POST['_wc_shipping/andreani/dni'], 'El checkout clásico usa el documento actual');
comprobar('40123456' === $_POST['_wc_billing/otro/cuit'], 'El checkout clásico completa otros correos');
$order = new WC_Order();
MJE_Orders::stamp($order);
foreach (['andreani/dni', 'correo-argentino/documento', 'otro/cuit'] as $id) {
	comprobar('40123456' === $order->meta['_wc_shipping/' . $id], 'Guardar el documento para ' . $id);
}
comprobar('40123456' === $order->meta['_mje_fields']['doc']['value'], 'Conservar el documento común en el pedido');
echo "OK: campo único, varios correos, sesiones anteriores, Store API, checkout clásico y metadatos del pedido.\n";
