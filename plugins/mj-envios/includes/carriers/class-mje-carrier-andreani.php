<?php
/**
 * Andreani.
 *
 * Conexión con la API de clientes de Andreani (apis.andreani.com):
 *
 *   1. Se pide un token con usuario y clave (login con Basic). El token vuelve
 *      en la cabecera x-authorization-token y se guarda un rato.
 *   2. La cotización se pide con el número de cliente, el contrato del servicio
 *      (Andreani da un contrato distinto para domicilio y para sucursal), el CP
 *      de destino y los bultos con su peso, volumen y valor declarado.
 *
 * La URL, el usuario, la clave, el cliente y los contratos se cargan en
 * WooCommerce → Envíos → Correos. Nada de eso está fijo acá: si Andreani cambia
 * de endpoint o te dan otro contrato, se corrige desde el panel.
 *
 * Si la respuesta no tiene la forma esperada, se puede fijar de qué clave sale
 * el precio, o escribir directamente el cuerpo del pedido con la plantilla.
 */

defined('ABSPATH') || exit;

class MJE_Carrier_Andreani extends MJE_Carrier {

	public function id() {
		return 'andreani';
	}

	public function label() {
		return 'Andreani';
	}

	public function fields() {
		return [
			'base_url'        => [
				'label'    => 'URL de la API',
				'type'     => 'url',
				'default'  => 'https://apis.andreani.com',
				'required' => true,
				'help'     => 'La que figura en la documentación de tu cuenta. Sin barra final.',
			],
			'user'            => [
				'label'    => 'Usuario',
				'type'     => 'text',
				'required' => true,
			],
			'password'        => [
				'label'    => 'Clave',
				'type'     => 'password',
				'required' => true,
			],
			'client'          => [
				'label'    => 'Número de cliente',
				'type'     => 'text',
				'required' => true,
				'help'     => 'El que te dio Andreani para tu cuenta de empresa.',
			],
			'contract_home'   => [
				'label' => 'Contrato para envíos a domicilio',
				'type'  => 'text',
				'help'  => 'Andreani da un contrato por servicio. Sin esto no cotiza a domicilio.',
			],
			'contract_branch' => [
				'label' => 'Contrato para envíos a sucursal',
				'type'  => 'text',
			],
			'origin_branch'   => [
				'label' => 'Sucursal de origen',
				'type'  => 'text',
				'help'  => 'Opcional. Si despachás siempre desde la misma sucursal, poné su código.',
			],
			'tracking_url'    => [
				'label'   => 'Link de seguimiento',
				'type'    => 'text',
				'default' => 'https://www.andreani.com/#!/informacionEnvio/{codigo}',
				'help'    => 'Se le muestra al cliente con su número. Marca: {codigo}.',
			],
			'price_key'       => [
				'label' => 'Clave del precio en la respuesta',
				'type'  => 'text',
				'help'  => 'Opcional. Por defecto se buscan las habituales (tarifaConIva, total, precio).',
			],
			'payload'         => [
				'label' => 'Cuerpo del pedido (plantilla)',
				'type'  => 'text',
				'help'  => 'Opcional y para casos raros: si lo completás, se manda esto en lugar del pedido normal. Marcas: {cp_destino} {peso} {valor} {bultos} {largo} {ancho} {alto}.',
			],
		];
	}

	public function services() {
		return [
			'domicilio' => 'Envío a domicilio',
			'sucursal'  => 'Envío a sucursal Andreani',
		];
	}

	/* -------------------------------- Token ------------------------------ */

	/** Token de la sesión, pidiéndolo si hace falta. */
	private function token() {
		$key = 'mje_token_' . $this->id();
		$hit = get_transient($key);
		if (is_string($hit) && '' !== $hit) {
			return $hit;
		}

		$base     = untrailingslashit((string) $this->config('base_url'));
		$response = wp_remote_post($base . '/login', [
			'timeout' => (int) MJE_Settings::get('timeout'),
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode($this->config('user') . ':' . $this->config('password')),
				'Accept'        => 'application/json',
			],
		]);
		if (is_wp_error($response)) {
			MJE_Geo::log($this->id(), 'login: ' . $response->get_error_message());
			return new WP_Error('mje_login', sprintf('%s no respondió al pedir el token: %s', $this->label(), $response->get_error_message()));
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if (200 !== $code) {
			MJE_Geo::log($this->id(), 'login HTTP ' . $code);
			return new WP_Error('mje_login', sprintf('%s rechazó el usuario o la clave (HTTP %d).', $this->label(), $code));
		}

		/* Andreani lo devuelve en una cabecera; algunos entornos lo mandan en el cuerpo. */
		$token = (string) wp_remote_retrieve_header($response, 'x-authorization-token');
		if ('' === $token) {
			$body  = json_decode((string) wp_remote_retrieve_body($response), true);
			$token = is_array($body) ? (string) $this->deep_find($body, 'token') : '';
		}
		if ('' === $token) {
			return new WP_Error('mje_login', sprintf('%s no devolvió ningún token.', $this->label()));
		}
		set_transient($key, $token, 20 * MINUTE_IN_SECONDS);
		return $token;
	}

	/* ----------------------------- Cotización ---------------------------- */

	public function quote(array $shipment) {
		if (!$this->connected()) {
			return new WP_Error('mje_not_connected', sprintf('%s todavía no tiene sus credenciales cargadas.', $this->label()));
		}

		$token = $this->token();
		if (is_wp_error($token)) {
			return $token;
		}
		$headers = ['x-authorization-token' => $token];
		$base    = untrailingslashit((string) $this->config('base_url'));

		$templated = $this->template_quote($shipment, $base . '/v1/tarifas', 'POST', $headers);
		if (null !== $templated) {
			return $templated;
		}

		$service  = (string) (isset($shipment['service']) ? $shipment['service'] : 'domicilio');
		$contract = 'sucursal' === $service ? (string) $this->config('contract_branch') : (string) $this->config('contract_home');
		if ('' === $contract) {
			return new WP_Error('mje_no_contract', sprintf('%s: falta el contrato para %s en los ajustes.', $this->label(), 'sucursal' === $service ? 'envíos a sucursal' : 'envíos a domicilio'));
		}

		$dest   = isset($shipment['dest']) ? (array) $shipment['dest'] : [];
		$weight = max(0.1, (float) (isset($shipment['weight']) ? $shipment['weight'] : 0));
		$volume = max(1, (int) (isset($shipment['length']) ? $shipment['length'] : 0) * (int) (isset($shipment['width']) ? $shipment['width'] : 0) * (int) (isset($shipment['height']) ? $shipment['height'] : 0));

		$args = [
			'cpDestino' => isset($dest['postcode']) ? (string) $dest['postcode'] : '',
			'contrato'  => $contract,
			'cliente'   => (string) $this->config('client'),
		];
		$origin_branch = trim((string) $this->config('origin_branch'));
		if ('' !== $origin_branch) {
			$args['sucursalOrigen'] = $origin_branch;
		}
		if ('sucursal' === $service && !empty($dest['branch'])) {
			$args['sucursalDestino'] = (string) $dest['branch'];
		}
		/* Un bulto por paquete, todos con el mismo peso y volumen declarados. */
		$packages = max(1, (int) (isset($shipment['packages']) ? $shipment['packages'] : 1));
		$value    = (float) (isset($shipment['value']) ? $shipment['value'] : 0);
		for ($i = 0; $i < $packages; $i++) {
			$args[sprintf('bultos[%d][valorDeclarado]', $i)] = round($value / $packages, 2);
			$args[sprintf('bultos[%d][volumen]', $i)]        = $volume;
			$args[sprintf('bultos[%d][kilos]', $i)]          = round($weight / $packages, 3);
		}

		$data = $this->request($base . '/v1/tarifas?' . http_build_query($args), ['method' => 'GET', 'headers' => $headers]);
		if (is_wp_error($data)) {
			return $data;
		}
		$cost = $this->find_amount($data, (string) $this->config('price_key'));
		if (null === $cost) {
			return new WP_Error('mje_no_price', sprintf('%s respondió sin un precio reconocible.', $this->label()));
		}
		return $this->result($cost, $this->find_days($data), $data, $service);
	}

	/* ----------------------------- Sucursales ---------------------------- */

	public function branches($postcode = '') {
		if (!$this->connected()) {
			return [];
		}
		$token = $this->token();
		if (is_wp_error($token)) {
			return [];
		}
		$base = untrailingslashit((string) $this->config('base_url'));
		$url  = $base . '/v2/sucursales';
		if ('' !== $postcode) {
			$url = add_query_arg('codigoPostal', rawurlencode($postcode), $url);
		}
		$data = $this->request($url, ['method' => 'GET', 'headers' => ['x-authorization-token' => $token]]);
		if (is_wp_error($data)) {
			return [];
		}
		$rows = isset($data['sucursales']) && is_array($data['sucursales']) ? $data['sucursales'] : $data;
		$out  = [];
		foreach ((array) $rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$code = (string) $this->deep_find($row, 'codigo');
			$name = (string) $this->deep_find($row, 'descripcion');
			if ('' === $name) {
				$name = (string) $this->deep_find($row, 'nombre');
			}
			if ('' === $code && '' === $name) {
				continue;
			}
			$out[] = [
				'code'     => '' !== $code ? $code : sanitize_title($name),
				'name'     => '' !== $name ? $name : $code,
				'address'  => (string) $this->deep_find($row, 'calle') . ' ' . (string) $this->deep_find($row, 'numero'),
				'city'     => (string) $this->deep_find($row, 'localidad'),
				'state'    => (string) $this->deep_find($row, 'provincia'),
				'postcode' => (string) $this->deep_find($row, 'codigoPostal'),
				'hours'    => (string) $this->deep_find($row, 'horarioDeAtencion'),
			];
		}
		return $out;
	}

	/* ----------------------------- Seguimiento --------------------------- */

	public function track($code) {
		if (!$this->connected() || '' === trim((string) $code)) {
			return [];
		}
		$token = $this->token();
		if (is_wp_error($token)) {
			return [];
		}
		$base = untrailingslashit((string) $this->config('base_url'));
		$data = $this->request($base . '/v2/envios/' . rawurlencode($code), ['method' => 'GET', 'headers' => ['x-authorization-token' => $token]]);
		if (is_wp_error($data)) {
			return [];
		}
		return [
			'status' => (string) $this->deep_find($data, 'estado'),
			'raw'    => $data,
		];
	}
}
