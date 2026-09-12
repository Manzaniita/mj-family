<?php
/**
 * Base de todos los correos.
 *
 * Un correo es una clase que sabe tres cosas: qué datos necesita para
 * conectarse (los declara él mismo, y el panel los dibuja solos), cómo pedir una
 * cotización y cómo leer la respuesta. Todo lo demás — el HTTP, la caché, el
 * registro de errores, el margen, el redondeo — es común y vive acá.
 *
 * Nada de la conexión está fijo en el código: las URLs, las credenciales, el
 * número de cliente, el contrato y los servicios se cargan desde
 * WooCommerce → Envíos → Correos. Si un correo cambia de endpoint, se corrige
 * desde el panel. Un correo sin credenciales queda apagado y sus métodos
 * cotizan con la tabla propia.
 *
 * El paquete que recibe `quote()` siempre tiene esta forma:
 *
 *   [
 *     'origin'   => ['postcode','city','state','country','branch'],
 *     'dest'     => ['postcode','city','state','country','branch'],
 *     'weight'   => 2.5,        // en la unidad de WooCommerce, ya con el embalaje
 *     'packages' => 1,          // bultos
 *     'length','width','height' // cm, del bulto
 *     'value'    => 180000.0,   // valor declarado
 *     'service'  => 'domicilio',
 *     'items'    => 3,
 *   ]
 */

defined('ABSPATH') || exit;

abstract class MJE_Carrier {

	/* ------------------------------ Identidad ---------------------------- */

	/** Clave corta del correo ('andreani'). */
	abstract public function id();

	/** Cómo se llama en el panel. */
	abstract public function label();

	/**
	 * Los datos de conexión que pide este correo.
	 *
	 * → [ clave => ['label','type','default','help','options'] ]
	 *   type: text | password | url | select | checkbox
	 *   Las claves marcadas 'required' son las que deciden si está conectado.
	 */
	abstract public function fields();

	/** Servicios que ofrece: clave => nombre. Se usan en el método de envío. */
	public function services() {
		return [];
	}

	/* ------------------------------- Config ------------------------------ */

	/** Todo lo guardado de todos los correos. */
	private static function all_config() {
		$saved = get_option(MJE_Settings::CARRIERS, []);
		return is_array($saved) ? $saved : [];
	}

	/** Config de este correo, con los defaults de sus propios campos. */
	public function config($key = null) {
		$all    = self::all_config();
		$saved  = isset($all[$this->id()]) && is_array($all[$this->id()]) ? $all[$this->id()] : [];
		$values = [];
		foreach ($this->fields() as $slug => $field) {
			$default        = isset($field['default']) ? $field['default'] : '';
			$values[$slug]  = array_key_exists($slug, $saved) ? $saved[$slug] : $default;
		}
		if (null === $key) {
			return $values;
		}
		return isset($values[$key]) ? $values[$key] : '';
	}

	/** Guarda la config de este correo (sanitizada según sus propios campos). */
	public function update_config($in) {
		$in    = is_array($in) ? $in : [];
		$clean = [];
		foreach ($this->fields() as $slug => $field) {
			$type = isset($field['type']) ? $field['type'] : 'text';
			$raw  = isset($in[$slug]) ? $in[$slug] : '';
			if ('checkbox' === $type) {
				$clean[$slug] = empty($raw) ? 0 : 1;
			} elseif ('url' === $type) {
				$clean[$slug] = '' !== trim((string) $raw) ? esc_url_raw(untrailingslashit(trim((string) $raw))) : '';
			} elseif ('select' === $type) {
				$options      = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
				$value        = sanitize_key($raw);
				$clean[$slug] = array_key_exists($value, $options) ? $value : (isset($field['default']) ? $field['default'] : '');
			} else {
				$clean[$slug] = mb_substr(trim(sanitize_text_field((string) $raw)), 0, 190);
			}
		}
		$all              = self::all_config();
		$all[$this->id()] = $clean;
		update_option(MJE_Settings::CARRIERS, $all);
		MJE_Settings::flush_caches();
	}

	/** ¿Están cargados todos los datos que este correo marcó como necesarios? */
	public function connected() {
		foreach ($this->fields() as $slug => $field) {
			if (!empty($field['required']) && '' === trim((string) $this->config($slug))) {
				return false;
			}
		}
		return true;
	}

	/* ----------------------------- Cotización ---------------------------- */

	/**
	 * Cotiza un envío.
	 *
	 * @param array $shipment Ver la cabecera de este archivo.
	 * @return array|WP_Error ['cost','days_min','days_max','service','raw']
	 */
	abstract public function quote(array $shipment);

	/** Sucursales del correo para un CP (las que sepa; [] si no las publica). */
	public function branches($postcode = '') {
		return [];
	}

	/** Localidad y provincia de un CP, si la API del correo lo resuelve. */
	public function resolve_cp($postcode) {
		return [];
	}

	/** Localidades que coinciden con un texto, si la API lo resuelve. */
	public function cities($query, $state = '') {
		return [];
	}

	/** Estado de un envío por su número de seguimiento. */
	public function track($code) {
		return [];
	}

	/* -------------------------------- HTTP ------------------------------- */

	/**
	 * Llamada a la API del correo.
	 *
	 * Devuelve el cuerpo ya decodificado, o WP_Error con un mensaje que se pueda
	 * leer en el panel. Los errores quedan en el registro (ajuste log_errors).
	 */
	protected function request($url, array $args = []) {
		if ('' === trim((string) $url)) {
			return new WP_Error('mje_no_url', sprintf('%s: falta la URL del servicio en los ajustes.', $this->label()));
		}
		$args = wp_parse_args($args, [
			'method'      => 'GET',
			'headers'     => [],
			'body'        => null,
			'timeout'     => (int) MJE_Settings::get('timeout'),
			'redirection' => 2,
		]);
		$args['headers'] = wp_parse_args($args['headers'], [
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		]);
		if (is_array($args['body'])) {
			$args['body'] = wp_json_encode($args['body']);
		}

		$response = wp_remote_request($url, $args);
		if (is_wp_error($response)) {
			MJE_Geo::log($this->id(), $response->get_error_message());
			return new WP_Error('mje_http', sprintf('%s no respondió: %s', $this->label(), $response->get_error_message()));
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		if ($code < 200 || $code > 299) {
			$message = is_array($data) ? $this->first_message($data) : '';
			MJE_Geo::log($this->id(), 'HTTP ' . $code . ' ' . mb_substr('' !== $message ? $message : $body, 0, 200));
			return new WP_Error('mje_http_' . $code, sprintf('%s devolvió un error (HTTP %d)%s', $this->label(), $code, '' !== $message ? ': ' . $message : '.'));
		}
		return is_array($data) ? $data : [];
	}

	/** El primer texto que parezca un mensaje de error dentro de una respuesta. */
	protected function first_message($data) {
		foreach (['message', 'mensaje', 'error', 'detail', 'descripcion', 'Message'] as $key) {
			if (isset($data[$key]) && is_string($data[$key])) {
				return $data[$key];
			}
		}
		foreach ((array) $data as $value) {
			if (is_string($value) && strlen($value) > 3) {
				return $value;
			}
			if (is_array($value)) {
				$found = $this->first_message($value);
				if ('' !== $found) {
					return $found;
				}
			}
		}
		return '';
	}

	/**
	 * Busca un número de precio dentro de una respuesta, sin importar cómo se
	 * llame la clave ni cuán anidada esté.
	 *
	 * Las APIs de los correos cambian los nombres entre versiones ("tarifa",
	 * "precio", "total", "costoEnvio"). Antes que romperse por un nombre nuevo,
	 * se buscan los habituales en cualquier nivel; y el nombre exacto se puede
	 * fijar desde el panel (campo "clave del precio") cuando hace falta.
	 */
	protected function find_amount($data, $preferred = '') {
		$keys = array_filter(array_merge(
			'' !== $preferred ? array_map('trim', explode(',', $preferred)) : [],
			['tarifa', 'tarifaConIva', 'precio', 'precioFinal', 'total', 'totalConIva', 'importe', 'costo', 'costoEnvio', 'valor', 'amount', 'price']
		));
		foreach ($keys as $key) {
			$found = $this->deep_find($data, $key);
			if (null !== $found) {
				$amount = MJE_Settings::decimal($found);
				if ($amount > 0) {
					return $amount;
				}
			}
		}
		return null;
	}

	/** Primer valor escalar con esa clave, a cualquier profundidad. */
	protected function deep_find($data, $key) {
		if (!is_array($data)) {
			return null;
		}
		foreach ($data as $k => $value) {
			if (is_string($k) && strcasecmp((string) $k, (string) $key) === 0 && (is_scalar($value) || null === $value)) {
				return $value;
			}
		}
		foreach ($data as $value) {
			if (is_array($value)) {
				$found = $this->deep_find($value, $key);
				if (null !== $found) {
					return $found;
				}
			}
		}
		return null;
	}

	/** Plazo en días que informe la respuesta, si informa alguno. */
	protected function find_days($data) {
		$min = null;
		$max = null;
		foreach (['plazoMinimo', 'diasMinimo', 'dias_min', 'minDays'] as $key) {
			$value = $this->deep_find($data, $key);
			if (null !== $value && '' !== $value) {
				$min = (int) $value;
				break;
			}
		}
		foreach (['plazoMaximo', 'diasMaximo', 'dias_max', 'maxDays', 'plazoEntrega', 'plazo', 'dias'] as $key) {
			$value = $this->deep_find($data, $key);
			if (null !== $value && '' !== $value) {
				$max = (int) $value;
				break;
			}
		}
		return ['min' => null === $min ? 0 : max(0, $min), 'max' => null === $max ? 0 : max(0, $max)];
	}

	/* ----------------------------- Plantillas ---------------------------- */

	/**
	 * Los datos del envío como marcas reemplazables.
	 *
	 * Sirven para dos cosas: armar la URL de una cotización y, cuando el correo
	 * pide un cuerpo que no es el que este plugin conoce, dejar que la plantilla
	 * se escriba desde el panel (campo "Cuerpo del pedido") sin tocar código.
	 */
	protected function placeholders(array $shipment) {
		$origin = isset($shipment['origin']) ? (array) $shipment['origin'] : [];
		$dest   = isset($shipment['dest']) ? (array) $shipment['dest'] : [];
		$value  = function ($array, $key) {
			return isset($array[$key]) ? (string) $array[$key] : '';
		};
		return [
			'{cp_origen}'          => $value($origin, 'postcode'),
			'{cp_destino}'         => $value($dest, 'postcode'),
			'{localidad_origen}'   => $value($origin, 'city'),
			'{localidad_destino}'  => $value($dest, 'city'),
			'{provincia_origen}'   => $value($origin, 'state'),
			'{provincia_destino}'  => $value($dest, 'state'),
			'{pais_origen}'        => $value($origin, 'country'),
			'{pais_destino}'       => $value($dest, 'country'),
			'{sucursal_origen}'    => $value($origin, 'branch'),
			'{sucursal_destino}'   => $value($dest, 'branch'),
			'{peso}'               => (string) round((float) (isset($shipment['weight']) ? $shipment['weight'] : 0), 3),
			'{peso_gramos}'        => (string) (int) round((float) (isset($shipment['weight']) ? $shipment['weight'] : 0) * 1000),
			'{bultos}'             => (string) max(1, (int) (isset($shipment['packages']) ? $shipment['packages'] : 1)),
			'{largo}'              => (string) (int) (isset($shipment['length']) ? $shipment['length'] : 0),
			'{ancho}'              => (string) (int) (isset($shipment['width']) ? $shipment['width'] : 0),
			'{alto}'               => (string) (int) (isset($shipment['height']) ? $shipment['height'] : 0),
			'{valor}'              => (string) round((float) (isset($shipment['value']) ? $shipment['value'] : 0), 2),
			'{items}'              => (string) (int) (isset($shipment['items']) ? $shipment['items'] : 0),
			'{servicio}'           => (string) (isset($shipment['service']) ? $shipment['service'] : ''),
		];
	}

	/** Reemplaza las marcas en un texto (URL, cuerpo o cabecera). */
	protected function fill($text, array $shipment) {
		return strtr((string) $text, $this->placeholders($shipment));
	}

	/**
	 * Cotización con la plantilla cargada en el panel.
	 *
	 * Es la salida cuando la API del correo no responde con la forma que este
	 * plugin conoce: se escribe el cuerpo (o la URL) con las marcas de
	 * placeholders() y se dice de qué clave sacar el precio. Devuelve null si no
	 * hay plantilla configurada, así el correo sigue con su camino normal.
	 */
	protected function template_quote(array $shipment, $url, $method = 'POST', array $headers = []) {
		$template = trim((string) $this->config('payload'));
		if ('' === $template) {
			return null;
		}
		$body = $this->fill($template, $shipment);
		$json = json_decode($body, true);
		$data = $this->request($this->fill($url, $shipment), [
			'method'  => 'GET' === strtoupper($method) ? 'GET' : strtoupper($method),
			'headers' => $headers,
			'body'    => 'GET' === strtoupper($method) ? null : (is_array($json) ? $json : $body),
		]);
		if (is_wp_error($data)) {
			return $data;
		}
		$cost = $this->find_amount($data, (string) $this->config('price_key'));
		if (null === $cost) {
			return new WP_Error('mje_no_price', sprintf('%s respondió sin un precio reconocible. Revisá la clave del precio en los ajustes.', $this->label()));
		}
		return $this->result($cost, $this->find_days($data), $data, (string) (isset($shipment['service']) ? $shipment['service'] : ''));
	}

	/** Respuesta armada para MJE_Rates (la misma forma para todos los correos). */
	protected function result($cost, $days = [], $raw = [], $service = '') {
		return [
			'cost'      => (float) $cost,
			'days_min'  => isset($days['min']) ? (int) $days['min'] : 0,
			'days_max'  => isset($days['max']) ? (int) $days['max'] : 0,
			'service'   => (string) $service,
			'raw'       => $raw,
		];
	}
}
