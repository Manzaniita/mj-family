<?php
/**
 * Vía Cargo.
 *
 * Vía Cargo no publica su API: el endpoint, el formato del pedido y la forma de
 * autenticarse salen del acuerdo con ellos y cambian de cuenta en cuenta. Por
 * eso este correo es el más configurable de los tres:
 *
 *   - La URL y la ruta de la cotización se cargan en el panel.
 *   - La autenticación se elige: nada, token Bearer, una clave en una cabecera
 *     que vos nombrás, o usuario y clave (Basic).
 *   - El cuerpo del pedido se puede escribir como plantilla con marcas
 *     ({cp_origen}, {peso}, {valor}…), así el día que te pasen el contrato de la
 *     API se completa desde el panel sin tocar una línea de código.
 *   - De qué clave sale el precio también se configura.
 *
 * Sin plantilla manda un cuerpo genérico con los nombres más habituales en las
 * APIs de transporte argentinas, que sirve para probar la conexión.
 */

defined('ABSPATH') || exit;

class MJE_Carrier_ViaCargo extends MJE_Carrier {

	public function id() {
		return 'viacargo';
	}

	public function label() {
		return 'Vía Cargo';
	}

	public function fields() {
		return [
			'base_url'    => [
				'label'    => 'URL de la API',
				'type'     => 'url',
				'required' => true,
				'help'     => 'La que te den en Vía Cargo, sin barra final.',
			],
			'quote_path'  => [
				'label'   => 'Ruta de la cotización',
				'type'    => 'text',
				'default' => '/cotizar',
				'help'    => 'Lo que va después de la URL. Admite marcas: /cotizar?cp={cp_destino}',
			],
			'method'      => [
				'label'   => 'Cómo se pide',
				'type'    => 'select',
				'default' => 'post',
				'options' => ['post' => 'POST (con cuerpo)', 'get' => 'GET (todo en la URL)'],
			],
			'auth'        => [
				'label'   => 'Autenticación',
				'type'    => 'select',
				'default' => 'none',
				'options' => [
					'none'   => 'Ninguna',
					'bearer' => 'Token Bearer',
					'header' => 'Clave en una cabecera',
					'basic'  => 'Usuario y clave (Basic)',
				],
			],
			'token'       => [
				'label' => 'Token o clave de API',
				'type'  => 'password',
			],
			'header_name' => [
				'label'   => 'Nombre de la cabecera',
				'type'    => 'text',
				'default' => 'X-API-Key',
				'help'    => 'Solo si elegiste "Clave en una cabecera".',
			],
			'user'        => [
				'label' => 'Usuario',
				'type'  => 'text',
			],
			'password'    => [
				'label' => 'Clave',
				'type'  => 'password',
			],
			'client'      => [
				'label' => 'Número de cliente o cuenta',
				'type'  => 'text',
			],
			'tracking_url' => [
				'label' => 'Link de seguimiento',
				'type'  => 'text',
				'help'  => 'Se le muestra al cliente con su número. Marca: {codigo}.',
			],
			'price_key'   => [
				'label' => 'Clave del precio en la respuesta',
				'type'  => 'text',
				'help'  => 'Por ejemplo: total, tarifa, precioFinal. Si lo dejás vacío se buscan las habituales.',
			],
			'payload'     => [
				'label' => 'Cuerpo del pedido (plantilla)',
				'type'  => 'text',
				'help'  => 'JSON con marcas: {cp_origen} {cp_destino} {localidad_destino} {provincia_destino} {peso} {peso_gramos} {bultos} {largo} {ancho} {alto} {valor} {servicio}.',
			],
		];
	}

	public function services() {
		return [
			'domicilio' => 'Envío a domicilio',
			'sucursal'  => 'Envío a sucursal Vía Cargo',
		];
	}

	/** Cabeceras según el modo de autenticación elegido. */
	private function auth_headers() {
		$mode = (string) $this->config('auth');
		if ('bearer' === $mode) {
			return ['Authorization' => 'Bearer ' . (string) $this->config('token')];
		}
		if ('header' === $mode) {
			$name = trim((string) $this->config('header_name'));
			return '' !== $name ? [$name => (string) $this->config('token')] : [];
		}
		if ('basic' === $mode) {
			return ['Authorization' => 'Basic ' . base64_encode($this->config('user') . ':' . $this->config('password'))];
		}
		return [];
	}

	public function quote(array $shipment) {
		if (!$this->connected()) {
			return new WP_Error('mje_not_connected', sprintf('%s todavía no tiene su URL cargada.', $this->label()));
		}

		$base    = untrailingslashit((string) $this->config('base_url'));
		$path    = (string) $this->config('quote_path');
		$url     = $base . '/' . ltrim($this->fill($path, $shipment), '/');
		$method  = 'get' === (string) $this->config('method') ? 'GET' : 'POST';
		$headers = $this->auth_headers();

		/* Con la plantilla cargada, manda exactamente eso. */
		$templated = $this->template_quote($shipment, $url, $method, $headers);
		if (null !== $templated) {
			return $templated;
		}

		$origin = isset($shipment['origin']) ? (array) $shipment['origin'] : [];
		$dest   = isset($shipment['dest']) ? (array) $shipment['dest'] : [];
		$body   = [
			'cliente'           => (string) $this->config('client'),
			'servicio'          => (string) (isset($shipment['service']) ? $shipment['service'] : ''),
			'codigoPostalOrigen'  => isset($origin['postcode']) ? (string) $origin['postcode'] : '',
			'codigoPostalDestino' => isset($dest['postcode']) ? (string) $dest['postcode'] : '',
			'localidadOrigen'     => isset($origin['city']) ? (string) $origin['city'] : '',
			'localidadDestino'    => isset($dest['city']) ? (string) $dest['city'] : '',
			'provinciaDestino'    => isset($dest['state']) ? (string) $dest['state'] : '',
			'peso'                => round((float) (isset($shipment['weight']) ? $shipment['weight'] : 0), 3),
			'bultos'              => max(1, (int) (isset($shipment['packages']) ? $shipment['packages'] : 1)),
			'largo'               => (int) (isset($shipment['length']) ? $shipment['length'] : 0),
			'ancho'               => (int) (isset($shipment['width']) ? $shipment['width'] : 0),
			'alto'                => (int) (isset($shipment['height']) ? $shipment['height'] : 0),
			'valorDeclarado'      => round((float) (isset($shipment['value']) ? $shipment['value'] : 0), 2),
		];

		$data = $this->request(
			'GET' === $method ? add_query_arg(array_map('strval', $body), $url) : $url,
			['method' => $method, 'headers' => $headers, 'body' => 'GET' === $method ? null : $body]
		);
		if (is_wp_error($data)) {
			return $data;
		}
		$cost = $this->find_amount($data, (string) $this->config('price_key'));
		if (null === $cost) {
			return new WP_Error('mje_no_price', sprintf('%s respondió sin un precio reconocible. Cargá la clave del precio o la plantilla del pedido en los ajustes.', $this->label()));
		}
		return $this->result($cost, $this->find_days($data), $data, (string) (isset($shipment['service']) ? $shipment['service'] : ''));
	}
}
