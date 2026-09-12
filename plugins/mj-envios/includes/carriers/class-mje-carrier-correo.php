<?php
/**
 * Correo Argentino (Mi Correo / Paq.ar).
 *
 * Conexión con la API de e-commerce de Correo Argentino:
 *
 *   1. Login con usuario y clave (Basic) contra /login; vuelve un token.
 *   2. La cotización se pide con el número de cliente, el CP de origen, el de
 *      destino, el tipo de entrega (a domicilio o a sucursal) y las medidas del
 *      bulto. El peso va en gramos, que es lo que espera esta API.
 *   3. Las sucursales se traen de /agencies y quedan guardadas para que el
 *      checkout no dependa de la API en vivo.
 *
 * URL, usuario, clave y número de cliente se cargan en WooCommerce → Envíos →
 * Correos. Si la respuesta viene con otra forma, se puede fijar la clave del
 * precio o escribir el cuerpo del pedido con la plantilla.
 */

defined('ABSPATH') || exit;

class MJE_Carrier_Correo extends MJE_Carrier {

	public function id() {
		return 'correo';
	}

	public function label() {
		return 'Correo Argentino';
	}

	public function fields() {
		return [
			'base_url'    => [
				'label'    => 'URL de la API',
				'type'     => 'url',
				'default'  => 'https://api.correoargentino.com.ar/micorreo/v1',
				'required' => true,
				'help'     => 'La que figura en la documentación de tu cuenta de Mi Correo. Sin barra final.',
			],
			'user'        => [
				'label'    => 'Usuario',
				'type'     => 'text',
				'required' => true,
			],
			'password'    => [
				'label'    => 'Clave',
				'type'     => 'password',
				'required' => true,
			],
			'customer_id' => [
				'label'    => 'Número de cliente',
				'type'     => 'text',
				'required' => true,
				'help'     => 'El customerId de tu cuenta de empresa.',
			],
			'agency'      => [
				'label' => 'Sucursal de despacho',
				'type'  => 'text',
				'help'  => 'Opcional. Código de la sucursal desde donde despachás.',
			],
			'tracking_url' => [
				'label'   => 'Link de seguimiento',
				'type'    => 'text',
				'default' => 'https://www.correoargentino.com.ar/formularios/e-commerce?n={codigo}',
				'help'    => 'Se le muestra al cliente con su número. Marca: {codigo}.',
			],
			'price_key'   => [
				'label' => 'Clave del precio en la respuesta',
				'type'  => 'text',
				'help'  => 'Opcional. Por defecto se buscan las habituales (price, tarifa, total).',
			],
			'payload'     => [
				'label' => 'Cuerpo del pedido (plantilla)',
				'type'  => 'text',
				'help'  => 'Opcional. Si lo completás, se manda esto en lugar del pedido normal. Marcas: {cp_origen} {cp_destino} {peso_gramos} {largo} {ancho} {alto} {valor}.',
			],
		];
	}

	public function services() {
		return [
			'domicilio' => 'A domicilio (Paq.ar)',
			'sucursal'  => 'A sucursal del Correo',
		];
	}

	/* -------------------------------- Token ------------------------------ */

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
				'Content-Type'  => 'application/json',
			],
		]);
		if (is_wp_error($response)) {
			MJE_Geo::log($this->id(), 'login: ' . $response->get_error_message());
			return new WP_Error('mje_login', sprintf('%s no respondió al pedir el token: %s', $this->label(), $response->get_error_message()));
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code > 299) {
			MJE_Geo::log($this->id(), 'login HTTP ' . $code);
			return new WP_Error('mje_login', sprintf('%s rechazó el usuario o la clave (HTTP %d).', $this->label(), $code));
		}
		$body  = json_decode((string) wp_remote_retrieve_body($response), true);
		$token = is_array($body) ? (string) $this->deep_find($body, 'token') : '';
		if ('' === $token) {
			$token = (string) wp_remote_retrieve_header($response, 'authorization');
		}
		if ('' === $token) {
			return new WP_Error('mje_login', sprintf('%s no devolvió ningún token.', $this->label()));
		}
		set_transient($key, $token, 20 * MINUTE_IN_SECONDS);
		return $token;
	}

	private function auth_headers() {
		$token = $this->token();
		if (is_wp_error($token)) {
			return $token;
		}
		return ['Authorization' => 0 === strpos($token, 'Bearer') ? $token : 'Bearer ' . $token];
	}

	/* ----------------------------- Cotización ---------------------------- */

	public function quote(array $shipment) {
		if (!$this->connected()) {
			return new WP_Error('mje_not_connected', sprintf('%s todavía no tiene sus credenciales cargadas.', $this->label()));
		}
		$headers = $this->auth_headers();
		if (is_wp_error($headers)) {
			return $headers;
		}
		$base = untrailingslashit((string) $this->config('base_url'));

		$templated = $this->template_quote($shipment, $base . '/rates', 'POST', $headers);
		if (null !== $templated) {
			return $templated;
		}

		$origin  = isset($shipment['origin']) ? (array) $shipment['origin'] : [];
		$dest    = isset($shipment['dest']) ? (array) $shipment['dest'] : [];
		$service = (string) (isset($shipment['service']) ? $shipment['service'] : 'domicilio');
		$type    = 'sucursal' === $service ? 'S' : 'D';

		$body = [
			'customerId'              => (string) $this->config('customer_id'),
			'postalCodeOrigin'        => isset($origin['postcode']) ? (string) $origin['postcode'] : '',
			'postalCodeDestination'   => isset($dest['postcode']) ? (string) $dest['postcode'] : '',
			'deliveredType'           => $type,
			'dimensions'              => [
				/* Esta API espera gramos y centímetros. */
				'weight' => max(1, (int) round((float) (isset($shipment['weight']) ? $shipment['weight'] : 0) * 1000)),
				'height' => max(1, (int) (isset($shipment['height']) ? $shipment['height'] : 0)),
				'width'  => max(1, (int) (isset($shipment['width']) ? $shipment['width'] : 0)),
				'length' => max(1, (int) (isset($shipment['length']) ? $shipment['length'] : 0)),
			],
		];

		$data = $this->request($base . '/rates', ['method' => 'POST', 'headers' => $headers, 'body' => $body]);
		if (is_wp_error($data)) {
			return $data;
		}

		/* La respuesta trae una tarifa por tipo de entrega: se toma la que pedimos. */
		$rows = isset($data['rates']) && is_array($data['rates']) ? $data['rates'] : [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row_type = strtoupper((string) $this->deep_find($row, 'deliveredType'));
			if ('' !== $row_type && $row_type !== $type) {
				continue;
			}
			$cost = $this->find_amount($row, (string) $this->config('price_key'));
			if (null !== $cost) {
				return $this->result($cost, $this->find_days($row), $row, $service);
			}
		}

		$cost = $this->find_amount($data, (string) $this->config('price_key'));
		if (null === $cost) {
			return new WP_Error('mje_no_price', sprintf('%s respondió sin un precio para ese tipo de entrega.', $this->label()));
		}
		return $this->result($cost, $this->find_days($data), $data, $service);
	}

	/* ----------------------------- Sucursales ---------------------------- */

	public function branches($postcode = '') {
		if (!$this->connected()) {
			return [];
		}
		$headers = $this->auth_headers();
		if (is_wp_error($headers)) {
			return [];
		}
		$base = untrailingslashit((string) $this->config('base_url'));
		$url  = $base . '/agencies';
		if ('' !== $postcode) {
			$url = add_query_arg('postalCode', rawurlencode($postcode), $url);
		}
		$data = $this->request($url, ['method' => 'GET', 'headers' => $headers]);
		if (is_wp_error($data)) {
			return [];
		}
		$rows = isset($data['agencies']) && is_array($data['agencies']) ? $data['agencies'] : $data;
		$out  = [];
		foreach ((array) $rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$code = (string) $this->deep_find($row, 'code');
			$name = (string) $this->deep_find($row, 'name');
			if ('' === $code && '' === $name) {
				continue;
			}
			$out[] = [
				'code'     => '' !== $code ? $code : sanitize_title($name),
				'name'     => '' !== $name ? $name : $code,
				'address'  => trim((string) $this->deep_find($row, 'street') . ' ' . (string) $this->deep_find($row, 'streetNumber')),
				'city'     => (string) $this->deep_find($row, 'location'),
				'state'    => (string) $this->deep_find($row, 'province'),
				'postcode' => (string) $this->deep_find($row, 'postalCode'),
				'hours'    => (string) $this->deep_find($row, 'hours'),
			];
		}
		return $out;
	}
}
