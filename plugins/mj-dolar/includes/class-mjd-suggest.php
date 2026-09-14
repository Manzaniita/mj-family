<?php
/**
 * Cotización sugerida (dolarapi.com). Opcional y apagada por defecto: el TC
 * lo decide siempre el administrador; esto solo muestra una referencia y
 * avisa si el valor cargado se aleja demasiado.
 */

defined('ABSPATH') || exit;

class MJD_Suggest {

	const TRANSIENT = 'mjd_suggest';
	const TTL       = 10 * MINUTE_IN_SECONDS;

	/*
	 * Proveedor de la cotización sugerida. {source} se reemplaza por la fuente
	 * elegida en los ajustes (oficial, blue, …). Se puede apuntar a otro
	 * servicio —o a un endpoint propio— sin tocar el plugin, con la constante
	 * MJD_SUGGEST_ENDPOINT (wp-config.php) o el filtro `mjd_suggest_endpoint`.
	 * La respuesta tiene que traer los campos "compra" y "venta".
	 */
	const ENDPOINT = 'https://dolarapi.com/v1/dolares/{source}';

	/** URL a consultar para una fuente. */
	public static function endpoint($source) {
		$template = defined('MJD_SUGGEST_ENDPOINT') && is_string(MJD_SUGGEST_ENDPOINT) && '' !== MJD_SUGGEST_ENDPOINT
			? MJD_SUGGEST_ENDPOINT
			: self::ENDPOINT;
		$url = false !== strpos($template, '{source}')
			? str_replace('{source}', rawurlencode($source), $template)
			: rtrim($template, '/') . '/' . rawurlencode($source);
		return esc_url_raw((string) apply_filters('mjd_suggest_endpoint', $url, $source));
	}

	/** Host del proveedor: se muestra en el panel y en los mensajes de error. */
	public static function provider($source = '') {
		$source = '' !== $source ? $source : (string) MJD_Settings::get('suggest_source');
		$host   = wp_parse_url(self::endpoint($source), PHP_URL_HOST);
		return is_string($host) && '' !== $host ? $host : 'el proveedor';
	}

	public static function enabled() {
		return (bool) MJD_Settings::get('suggest_enabled');
	}

	/**
	 * Cotización de la fuente configurada.
	 *
	 * @return array|WP_Error ['source','label','compra','venta','value','side','fecha','fetched_at']
	 */
	public static function fetch($force = false) {
		$source = (string) MJD_Settings::get('suggest_source');
		$side   = 'compra' === MJD_Settings::get('suggest_side') ? 'compra' : 'venta';
		$key    = self::TRANSIENT . '_' . $source;

		if (!$force) {
			$cached = get_transient($key);
			if (is_array($cached) && isset($cached[$side])) {
				$cached['value'] = (float) $cached[$side];
				$cached['side']  = $side;
				return $cached;
			}
		}

		$response = wp_remote_get(self::endpoint($source), [
			'timeout'    => (int) apply_filters('mjd_suggest_timeout', 8),
			'headers'    => ['Accept' => 'application/json'],
			'user-agent' => 'MJ-Dolar/' . MJD_VERSION . ' (' . home_url() . ')',
		]);
		if (is_wp_error($response)) {
			MJ_Log::error('dolar', 'No se pudo consultar la cotización sugerida', [
				'fuente' => self::provider($source),
				'motivo' => $response->get_error_message(),
			]);
			return new WP_Error('mjd_suggest_http', 'No se pudo consultar ' . self::provider($source) . ': ' . $response->get_error_message());
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (200 !== $code || !is_array($body) || !isset($body['compra'], $body['venta']) || !is_numeric($body['compra']) || !is_numeric($body['venta'])) {
			MJ_Log::error('dolar', 'La cotización sugerida llegó con un formato inesperado', [
				'fuente' => self::provider($source),
				'http'   => $code,
			]);
			return new WP_Error('mjd_suggest_bad', self::provider($source) . ' devolvió una respuesta inesperada (HTTP ' . $code . ').');
		}

		$sources = MJD_Settings::suggest_sources();
		$data    = [
			'source'     => $source,
			'label'      => isset($sources[$source]) ? $sources[$source] : $source,
			'compra'     => (float) $body['compra'],
			'venta'      => (float) $body['venta'],
			'fecha'      => isset($body['fechaActualizacion']) ? sanitize_text_field((string) $body['fechaActualizacion']) : '',
			'fetched_at' => time(),
		];
		set_transient($key, $data, (int) apply_filters('mjd_suggest_ttl', self::TTL));

		$data['value'] = (float) $data[$side];
		$data['side']  = $side;
		return $data;
	}

	/** Desvío porcentual del TC cargado respecto del sugerido (positivo = el cargado es mayor). */
	public static function deviation($rate, $suggested) {
		$suggested = (float) $suggested;
		if ($suggested <= 0) {
			return 0.0;
		}
		return ((float) $rate - $suggested) / $suggested * 100;
	}

	/** true si el desvío supera el umbral configurado. */
	public static function should_alert($rate, $suggested) {
		$pct = (int) MJD_Settings::get('suggest_alert_pct');
		return $pct > 0 && (float) $rate > 0 && abs(self::deviation($rate, $suggested)) > $pct;
	}
}
