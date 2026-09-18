<?php
/**
 * Andreani, cuando está su plugin oficial.
 *
 * El plugin de Andreani (andreani-shipping) ya tiene la credencial, el origen,
 * los modos de entrega habilitados y su propia caché. Este adaptador no repite
 * nada de eso: lee lo que ya está configurado, usa las sucursales que trae su
 * API y le escribe al pedido lo que su plugin necesita para reconocerlo como
 * suyo y poder darle el alta desde su pantalla.
 *
 * Lo que se usa del plugin de Andreani, todo público y estable:
 *   ANDREANI_SHIPPING_METHOD_ID            id de su método de envío
 *   Andreani_Settings_Service              la configuración activa
 *   Andreani_Api_Manager::get_sucursales() sucursales por código postal
 *   Andreani_Client_Type / Andreani_Origen tipo de cuenta y desde dónde despacha
 *
 * Nada de esto es obligatorio: si el plugin no está instalado, `available()`
 * devuelve false y MJ Envíos no lo nombra en ningún lado.
 */

defined('ABSPATH') || exit;

class MJE_Native_Andreani extends MJE_Native_Plugin {

	/** Los prefijos con los que arma el id de sus tarifas, uno por tipo de cuenta. */
	const RATE_PREFIXES = ['andreani_pyme_', 'andreani_middlemarket_', 'andreani_corporativo_'];

	public function id() {
		return 'andreani';
	}

	public function label() {
		return 'Andreani';
	}

	public function method_id() {
		return defined('ANDREANI_SHIPPING_METHOD_ID') ? ANDREANI_SHIPPING_METHOD_ID : 'andreani_flexipaas';
	}

	public function available() {
		return defined('ANDREANI_PLUGIN_VERSION') && class_exists('Andreani_Settings_Service');
	}

	/** Conectado es tener credencial y código postal de origen cargados. */
	public function connected() {
		if (!$this->available()) {
			return false;
		}
		return (bool) Andreani_Settings_Service::has_valid_credentials();
	}

	public function settings_url() {
		foreach ($this->zone_instances() as $instance) {
			return $instance['url'];
		}
		return admin_url('admin.php?page=wc-settings&tab=shipping');
	}

	/* ------------------------------- Cuenta ------------------------------ */

	/**
	 * Los datos de la cuenta ya conectada, para confirmar de un vistazo que es
	 * la que corresponde. Salen de la respuesta que guardó su propio plugin.
	 */
	public function summary() {
		if (!$this->available()) {
			return [];
		}
		$rows = [];

		$type = $this->client_type_label();
		if ('' !== $type) {
			$rows[] = ['label' => 'Tipo de cuenta', 'value' => $type];
		}

		$info = $this->client_info();
		$map  = [
			'Titular'           => ['name', 'razonSocial', 'nombre'],
			'CUIT'              => ['cuit', 'cuil', 'documento'],
			'Número de cliente' => ['numeroDeCliente', 'cl', 'clientId'],
			'Teléfono'          => ['phoneNumber', 'telefono', 'phone'],
		];
		foreach ($map as $label => $keys) {
			foreach ($keys as $key) {
				if (!isset($info[$key]) || !is_scalar($info[$key])) {
					continue;
				}
				$value = trim((string) $info[$key]);
				if ('' !== $value && '-' !== $value) {
					$rows[] = ['label' => $label, 'value' => $value];
					break;
				}
			}
		}

		$cp = (string) Andreani_Settings_Service::get('cp_origen', '');
		if ('' !== $cp) {
			$rows[] = ['label' => 'Código postal de origen', 'value' => $cp];
		}

		$origin = $this->origin_label();
		if ('' !== $origin) {
			$rows[] = ['label' => 'Despacha desde', 'value' => $origin];
		}

		return $rows;
	}

	/** La respuesta de la cuenta que guardó el plugin, según el tipo de cliente. */
	private function client_info() {
		if (!class_exists('Andreani_Client_Type') || !class_exists('Andreani_Api_Manager')) {
			return [];
		}
		$type = Andreani_Client_Type::from_id(Andreani_Api_Manager::get_client_type());
		if (!$type || !method_exists($type, 'info_option_name')) {
			return [];
		}
		$info = get_option($type->info_option_name(), []);
		return is_array($info) ? $info : [];
	}

	private function client_type_label() {
		if (!class_exists('Andreani_Client_Type') || !class_exists('Andreani_Api_Manager')) {
			return '';
		}
		$type = Andreani_Client_Type::from_id(Andreani_Api_Manager::get_client_type());
		return $type && method_exists($type, 'label') ? (string) $type->label() : '';
	}

	/** Desde dónde despacha: la sucursal fijada o la dirección cargada. */
	private function origin_label() {
		if (!class_exists('Andreani_Origen')) {
			return '';
		}
		$origen = Andreani_Origen::get();
		if (!empty($origen['sucursal_nombre'])) {
			return (string) $origen['sucursal_nombre'];
		}
		$parts = array_filter([
			trim((string) $origen['calle'] . ' ' . (string) $origen['numero']),
			(string) $origen['localidad'],
			(string) $origen['provincia'],
		]);
		return implode(', ', $parts);
	}

	/* ------------------------------ Servicios ---------------------------- */

	/**
	 * El modo de entrega de una tarifa suya.
	 *
	 * Sus ids son "andreani_<tipo de cuenta>_<modo>", y el modo es el nombre que
	 * devuelve la API ("Envío a Domicilio", "Envío a Sucursal"): no hay una
	 * lista fija, así que se toma lo que venga.
	 */
	public function service_of($rate_id) {
		$rate_id = (string) $rate_id;
		foreach (self::RATE_PREFIXES as $prefix) {
			if (0 === strpos($rate_id, $prefix)) {
				return substr($rate_id, strlen($prefix));
			}
		}
		return parent::service_of($rate_id);
	}

	/**
	 * El nombre del modo de entrega, presentable.
	 *
	 * Andreani devuelve el suyo como venga ("estándar", "sucursal"): acá solo se
	 * le pone la mayúscula inicial. No se traduce ni se reemplaza por una lista
	 * propia, porque el que manda es el nombre que figura en tu contrato.
	 */
	public function service_label($service) {
		$service = trim((string) $service);
		if ('' === $service) {
			return 'Andreani';
		}
		return function_exists('mb_convert_case')
			? mb_convert_case(mb_substr($service, 0, 1), MB_CASE_UPPER) . mb_substr($service, 1)
			: ucfirst($service);
	}

	/* ------------------------------ Sucursales --------------------------- */

	/**
	 * Las sucursales que Andreani tiene para un código postal.
	 *
	 * Su API las pide siempre con CP: sin código postal no hay lista, así que se
	 * devuelve vacío en lugar de traer el país entero.
	 */
	public function branches($postcode = '', $state = '') { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Andreani las pide por código postal.
		$postcode = MJE_Geo::normalize_cp($postcode);
		if ('' === $postcode || !$this->connected() || !class_exists('Andreani_Api_Manager')) {
			return [];
		}
		$data = Andreani_Api_Manager::get_sucursales($postcode);
		if (is_wp_error($data) || !is_array($data) || empty($data['options'])) {
			return [];
		}
		$details = isset($data['details']) && is_array($data['details']) ? $data['details'] : [];

		$out = [];
		foreach ((array) $data['options'] as $code => $name) {
			$detail                 = isset($details[$code]) && is_array($details[$code]) ? $details[$code] : [];
			list($lat, $lon)        = $this->coordinates($detail);
			$address = isset($detail['direccion']) ? (string) $detail['direccion'] : '';
			$out[]   = [
				'code'     => (string) $code,
				'name'     => (string) $name,
				'address'  => $address,
				'city'     => $this->city_from_address($address, $postcode),
				'state'    => '',
				'postcode' => $postcode,
				'hours'    => isset($detail['horario']) ? (string) $detail['horario'] : '',
				'lat'      => $lat,
				'lon'      => $lon,
			];
		}
		return $out;
	}

	/**
	 * La localidad, leída de la dirección que arma su plugin.
	 *
	 * Su API no devuelve la localidad como dato aparte: la mete adentro de un
	 * texto que su plugin arma como "Calle Número, Localidad, CP 9400" (ver
	 * Andreani_Api_Response::format_branch_address). Se lee solo si ese texto
	 * termina en el mismo código postal que pedimos; si no coincide, se devuelve
	 * vacío en lugar de adivinar.
	 */
	private function city_from_address($address, $postcode) {
		$parts = array_map('trim', explode(',', (string) $address));
		if (count($parts) < 3) {
			return '';
		}
		$last = array_pop($parts);
		if (!preg_match('/^CP\s*(.+)$/i', $last, $m)) {
			return '';
		}
		if (MJE_Geo::normalize_cp($m[1]) !== MJE_Geo::normalize_cp($postcode)) {
			return '';
		}
		return (string) array_pop($parts);
	}

	/** Las coordenadas de una sucursal, que vienen con nombres distintos según el endpoint. */
	private function coordinates(array $detail) {
		$coords = isset($detail['coordenadas']) && is_array($detail['coordenadas']) ? $detail['coordenadas'] : [];
		$lat    = '';
		$lon    = '';
		foreach (['latitud', 'latitude', 'lat', 'Latitud'] as $key) {
			if (isset($coords[$key]) && is_scalar($coords[$key])) {
				$lat = (string) $coords[$key];
				break;
			}
		}
		foreach (['longitud', 'longitude', 'lng', 'lon', 'Longitud'] as $key) {
			if (isset($coords[$key]) && is_scalar($coords[$key])) {
				$lon = (string) $coords[$key];
				break;
			}
		}
		return [$lat, $lon];
	}

	/** Andreani cotiza la sucursal que tenga anotada en su propia sesión. */
	public function remember_branch($code) {
		if (!class_exists('Andreani_Utils')) {
			return;
		}
		Andreani_Utils::set_session_data('codigo_sucursal', (string) $code);
	}

	/* ------------------------------- Pedido ------------------------------ */

	/**
	 * Le deja al pedido lo que su plugin busca.
	 *
	 * `_chosen_shipping` es lo que mira Andreani para saber con qué modo se
	 * despacha; las tres metas de la sucursal son las que muestra en su pantalla
	 * de envíos y las que manda al dar el alta.
	 */
	public function stamp_order($order, $rate_id, $service, array $branch = []) {
		if (!$order instanceof WC_Order) {
			return;
		}
		$rate_id = (string) $rate_id;
		if ('' !== $rate_id) {
			$order->update_meta_data('_chosen_shipping', $rate_id);
		}
		if (empty($branch['code'])) {
			return;
		}
		$order->update_meta_data('_shipping_branch_code', (string) $branch['code']);
		$order->update_meta_data('sucursal_andreani', (string) $branch['code']);
		if (!empty($branch['name'])) {
			$order->update_meta_data('_andreani_sucursal_nombre', (string) $branch['name']);
		}
		if (!empty($branch['address'])) {
			$order->update_meta_data('_andreani_sucursal_direccion', (string) $branch['address']);
		}
	}

	/** La línea de envío guarda el id de la tarifa de Andreani, como lo hace su checkout. */
	public function stamp_item($item, $rate_id) {
		if (!$item instanceof WC_Order_Item_Shipping || '' === (string) $rate_id) {
			return;
		}
		$item->add_meta_data('_andreani_rate_id', (string) $rate_id, true);
	}

	public function tracking_url($code) {
		$code = trim((string) $code);
		if ('' === $code) {
			return '';
		}
		return 'https://www.andreani.com/#!/informacionEnvio/' . rawurlencode($code);
	}
}
