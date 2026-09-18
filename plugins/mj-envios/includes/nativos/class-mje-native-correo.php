<?php
/**
 * Correo Argentino, cuando está su plugin oficial.
 *
 * El plugin de Correo Argentino (correo-argentino-for-woocommerce) se conecta
 * con MiCorreo o con Paq.Ar y cotiza por instancia: cada instancia que agregás a
 * una zona ya trae elegido su producto (clásico o expreso) y su forma de entrega
 * (a domicilio o a sucursal). MJ Envíos lee esa configuración y se queda con la
 * tarifa que calcula, sin volver a pedir usuario ni clave.
 *
 * Lo que se usa de su plugin, todo público:
 *   CORRARFO_PLUGIN_ID            id de su método de envío
 *   CORRARFO_IS_CONNECTED         si la cuenta está conectada
 *   Corrarfo\Lib\Utils            provincias y estado de la conexión
 *   ServiceFactory + getBranches  las sucursales por provincia
 *
 * Sus sucursales se piden por PROVINCIA, no por código postal: el código postal
 * del cliente se traduce a provincia con la tabla de MJ Envíos antes de
 * preguntar.
 */

defined('ABSPATH') || exit;

class MJE_Native_Correo extends MJE_Native_Plugin {

	public function id() {
		return 'correo';
	}

	public function label() {
		return 'Correo Argentino';
	}

	public function method_id() {
		return defined('CORRARFO_PLUGIN_ID') ? CORRARFO_PLUGIN_ID : 'correoargentino_shipping_method';
	}

	public function available() {
		return defined('CORRARFO_PLUGIN_ID') && class_exists('Corrarfo\Lib\Utils');
	}

	public function connected() {
		if (!$this->available() || !defined('CORRARFO_IS_CONNECTED')) {
			return false;
		}
		return 1 === (int) get_option(CORRARFO_IS_CONNECTED);
	}

	public function settings_url() {
		return admin_url('admin.php?page=wc-settings&tab=shipping&section=' . $this->method_id());
	}

	/* ------------------------------- Cuenta ------------------------------ */

	public function summary() {
		if (!$this->available() || !defined('CORRARFO_SETTINGS')) {
			return [];
		}
		$settings = maybe_unserialize(get_option(CORRARFO_SETTINGS));
		$settings = is_array($settings) ? $settings : [];
		$rows     = [];

		$service = defined('CORRARFO_SERVICE_TYPE') ? (string) get_option(CORRARFO_SERVICE_TYPE) : '';
		if ('' !== $service) {
			$rows[] = ['label' => 'Servicio', 'value' => $this->service_type_label($service)];
		}

		$map = [
			'Comercio'  => ['business_name'],
			'Email'     => ['email'],
			'Localidad' => ['city_name'],
			'Provincia' => ['state'],
			'Código postal de origen' => ['zip_code'],
		];
		foreach ($map as $label => $keys) {
			foreach ($keys as $key) {
				if (!empty($settings[$key]) && is_scalar($settings[$key])) {
					$rows[] = ['label' => $label, 'value' => trim((string) $settings[$key])];
					break;
				}
			}
		}
		return $rows;
	}

	private function service_type_label($service) {
		if (defined('CORRARFO_MI_CORREO') && CORRARFO_MI_CORREO === $service) {
			return 'MiCorreo';
		}
		if (defined('CORRARFO_PAQ_AR') && CORRARFO_PAQ_AR === $service) {
			return 'Paq.Ar';
		}
		return (string) $service;
	}

	/* ------------------------------ Servicios ---------------------------- */

	/**
	 * El servicio de una tarifa suya.
	 *
	 * Sus ids son "<método>_<instancia>", y lo que define el servicio está en
	 * los ajustes de esa instancia: `metodo_envio` (D domicilio, S sucursal) y
	 * `tipo_servicio` (el producto contratado). Así, dos instancias del mismo
	 * correo en la misma zona se distinguen bien.
	 */
	public function service_of($rate_id) {
		$instance = $this->instance_of($rate_id);
		if (!$instance) {
			return parent::service_of($rate_id);
		}
		$settings = $this->instance_settings($instance);
		$delivery = isset($settings['metodo_envio']) ? strtoupper((string) $settings['metodo_envio']) : '';
		$product  = isset($settings['tipo_servicio']) ? strtoupper((string) $settings['tipo_servicio']) : '';

		$parts = ['S' === $delivery ? 'sucursal' : 'domicilio'];
		if ('' !== $product) {
			$parts[] = strtolower($product);
		}
		return implode('-', $parts);
	}

	public function service_label($service) {
		$service = (string) $service;
		$label   = 0 === strpos($service, 'sucursal') ? 'Correo Argentino a sucursal' : 'Correo Argentino a domicilio';
		if (false !== strpos($service, '-e')) {
			$label .= ' (expreso)';
		} elseif (false !== strpos($service, '-c')) {
			$label .= ' (clásico)';
		}
		return $label;
	}

	/**
	 * ¿Entrega en sucursal?
	 *
	 * La tarifa lo trae dicho en su propia meta, que es más confiable que
	 * deducirlo del nombre.
	 */
	public function is_branch_service($service, $rate = null) {
		if ($rate instanceof WC_Shipping_Rate && defined('CORRARFO_IS_BRANCH')) {
			$meta = $rate->get_meta_data();
			if (isset($meta[CORRARFO_IS_BRANCH])) {
				return (bool) $meta[CORRARFO_IS_BRANCH];
			}
		}
		return 0 === strpos((string) $service, 'sucursal');
	}

	/**
	 * Las tarifas de relleno que emite cuando no puede cotizar.
	 *
	 * Su plugin, en lugar de no ofrecer nada, agrega una tarifa de $0 con el
	 * motivo escrito en el nombre. Son tres, y todas se reconocen por el final
	 * de su id (ver Classes/correoargentino-shipping-method.php).
	 */
	public function placeholder_reason($rate_id, $rate = null) {
		$rate_id = (string) $rate_id;
		$motivos = [
			'_direccion_incompleta' => 'falta la dirección del cliente para poder cotizar',
			'_excess_dimensions'    => 'el paquete supera el peso o las medidas que acepta',
			'_error'                => 'no tiene tarifas para ese destino',
		];
		foreach ($motivos as $sufijo => $motivo) {
			if (substr($rate_id, -strlen($sufijo)) === $sufijo) {
				return $motivo;
			}
		}
		return '';
	}

	/** El número de instancia que hay al final del id de una tarifa suya. */
	private function instance_of($rate_id) {
		if (preg_match('/_(\d+)(?:_|$)/', (string) $rate_id, $m)) {
			return (int) $m[1];
		}
		return 0;
	}

	private function instance_settings($instance_id) {
		$settings = get_option('woocommerce_' . $this->method_id() . '_' . (int) $instance_id . '_settings', []);
		$settings = maybe_unserialize($settings);
		return is_array($settings) ? $settings : [];
	}

	/* ------------------------------ Sucursales --------------------------- */

	/**
	 * Las sucursales de la provincia a la que pertenece ese código postal.
	 *
	 * Si el CP todavía no resuelve a ninguna provincia, no se pregunta: traer
	 * las de todo el país no le sirve a nadie en el checkout.
	 */
	public function branches($postcode = '') {
		if (!$this->connected() || !class_exists('Corrarfo\Service\ServiceFactory')) {
			return [];
		}
		$province = $this->province_of($postcode);
		if ('' === $province) {
			return [];
		}

		try {
			$factory = new \Corrarfo\Service\ServiceFactory();
			$service = $factory->get();
		} catch (Exception $e) {
			MJE_Geo::log($this->id(), 'sucursales: ' . $e->getMessage());
			return [];
		}
		if (!is_object($service) || !method_exists($service, 'getBranches')) {
			return [];
		}

		$query = ['provinceCode' => $province, 'stateId' => $province];
		if (class_exists('Corrarfo\Lib\Utils') && method_exists('Corrarfo\Lib\Utils', 'getCustomerId')) {
			$query['customerId'] = \Corrarfo\Lib\Utils::getCustomerId();
		}
		$agencies = $service->getBranches($query);
		if (!is_array($agencies)) {
			return [];
		}

		$out = [];
		foreach ($agencies as $agency) {
			$branch = $this->map_branch($agency);
			if ($branch) {
				$out[] = $branch;
			}
		}
		return $out;
	}

	/**
	 * Una sucursal como la devuelve su API, en el formato de MJ Envíos.
	 *
	 * MiCorreo y Paq.Ar nombran los campos distinto, así que se prueban los dos
	 * y se toma lo que haya.
	 */
	private function map_branch($agency) {
		if (!is_array($agency)) {
			return [];
		}
		$location = isset($agency['location']) && is_array($agency['location']) ? $agency['location'] : [];
		$address  = isset($location['address']) && is_array($location['address']) ? $location['address'] : $location;

		$code = $this->pick($agency, ['code', 'agency_id', 'id']);
		$name = $this->pick($agency, ['name', 'agency_name']);
		if ('' === $code || '' === $name) {
			return [];
		}

		$street = trim($this->pick($address, ['streetName', 'street_name']) . ' ' . $this->pick($address, ['streetNumber', 'street_number']));

		return [
			'code'     => $code,
			'name'     => $name,
			'address'  => $street,
			'city'     => $this->pick($address, ['city', 'city_name']),
			'state'    => $this->pick($address, ['province', 'state_name']),
			'postcode' => MJE_Geo::normalize_cp($this->pick($address, ['postalCode', 'zip_code'])),
			'hours'    => $this->pick($agency, ['hours', 'serviceHours']),
			'lat'      => $this->pick($location, ['latitude', 'lat']),
			'lon'      => $this->pick($location, ['longitude', 'lng', 'lon']),
		];
	}

	private function pick($data, array $keys) {
		if (!is_array($data)) {
			return '';
		}
		foreach ($keys as $key) {
			if (isset($data[$key]) && is_scalar($data[$key])) {
				$value = trim((string) $data[$key]);
				if ('' !== $value) {
					return $value;
				}
			}
		}
		return '';
	}

	/** La provincia de un código postal, con la tabla propia de MJ Envíos. */
	private function province_of($postcode) {
		$resolved = MJE_Geo::resolve_cp($postcode);
		if (!empty($resolved['state_code'])) {
			return (string) $resolved['state_code'];
		}
		return '';
	}

	/* ------------------------------- Sesión ------------------------------ */

	/** Su cotización mira la sucursal elegida en su propia sesión. */
	public function remember_branch($code) {
		if (!function_exists('WC') || !WC()->session || !defined('CORRARFO_CHOSEN_BRANCH')) {
			return;
		}
		$code   = (string) $code;
		$branch = MJE_Native::branch($this->id(), $code);

		WC()->session->set(CORRARFO_CHOSEN_BRANCH, $code);
		if (defined('CORRARFO_CHOSEN_BRANCH_NAME') && !empty($branch['name'])) {
			WC()->session->set(CORRARFO_CHOSEN_BRANCH_NAME, (string) $branch['name']);
		}
		if (defined('CORRARFO_CHOSEN_BRANCH_ZIP_CODE') && !empty($branch['postcode'])) {
			WC()->session->set(CORRARFO_CHOSEN_BRANCH_ZIP_CODE, (string) $branch['postcode']);
		}
		if (defined('CORRARFO_CHOSEN_PROVINCE_CODE') && !empty($branch['state'])) {
			WC()->session->set(CORRARFO_CHOSEN_PROVINCE_CODE, (string) $branch['state']);
		}
	}

	/* ------------------------------- Pedido ------------------------------ */

	public function stamp_order($order, $rate_id, $service, array $branch = []) {
		if (!$order instanceof WC_Order || empty($branch['code'])) {
			return;
		}
		$map = [
			'CORRARFO_CHOSEN_BRANCH'              => 'code',
			'CORRARFO_CHOSEN_BRANCH_NAME'         => 'name',
			'CORRARFO_CHOSEN_BRANCH_ZIP_CODE'     => 'postcode',
			'CORRARFO_CHOSEN_BRANCH_CITY'         => 'city',
			'CORRARFO_CHOSEN_BRANCH_STATE'        => 'state',
			'CORRARFO_CHOSEN_BRANCH_STREET_NAME'  => 'address',
		];
		foreach ($map as $constant => $key) {
			if (defined($constant) && !empty($branch[$key])) {
				$order->update_meta_data(constant($constant), (string) $branch[$key]);
			}
		}
	}

	public function tracking_url($code) {
		$code = trim((string) $code);
		if ('' === $code) {
			return '';
		}
		$base = defined('CORRARFO_TRACKING_URL') ? CORRARFO_TRACKING_URL : 'https://www.correoargentino.com.ar/formularios/e-commerce?id=';
		return $base . rawurlencode($code);
	}
}
