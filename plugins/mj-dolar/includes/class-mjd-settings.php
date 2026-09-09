<?php
/**
 * Ajustes del plugin: valores por defecto, lectura, guardado y saneado.
 * Todo vive en UNA opción array: mjd_settings.
 */

defined('ABSPATH') || exit;

class MJD_Settings {

	const OPTION  = 'mjd_settings';
	const HISTORY = 'mjd_rate_history';

	/** @var array|null Caché por petición. */
	private static $cache = null;

	public static function defaults() {
		return [
			'rate'              => '',        // TC vigente (pesos por dólar, string decimal). '' = sin configurar.
			'rate_updated_at'   => 0,         // Timestamp del último cambio.
			'rate_updated_by'   => 0,         // ID del usuario que lo cambió.
			'rounding'          => 100,       // Múltiplo de redondeo del precio en pesos: 0 (centavos) | 1 | 10 | 100 | 500 | 1000.
			'rounding_mode'     => 'nearest', // nearest (al más cercano) | up (siempre para arriba).
			'show_header'       => 1,         // Mostrar el TC en el header de la tienda.
			'header_label'      => 'Dólar',   // Texto que acompaña al valor en el header.
			'show_usd_price'    => 'product', // off | product (solo en la ficha) | all (ficha y listados).
			'suggest_enabled'   => 0,         // Traer cotización sugerida de dolarapi.com.
			'suggest_source'    => 'blue',    // Ver suggest_sources().
			'suggest_side'      => 'venta',   // compra | venta.
			'suggest_alert_pct' => 5,         // Avisar si el TC cargado se desvía más de X % del sugerido (0 = nunca).
		];
	}

	/** Múltiplos de redondeo disponibles (valor => etiqueta). */
	public static function rounding_choices() {
		return [
			0    => 'Sin redondeo (con centavos)',
			1    => 'Al peso',
			10   => 'A los $10',
			100  => 'A los $100',
			500  => 'A los $500',
			1000 => 'A los $1.000',
		];
	}

	/** Cotizaciones disponibles del proveedor (slug => etiqueta). Filtrable. */
	public static function suggest_sources() {
		$sources = [
			'oficial'         => 'Oficial',
			'blue'            => 'Blue',
			'bolsa'           => 'MEP (Bolsa)',
			'contadoconliqui' => 'Contado con liqui',
			'tarjeta'         => 'Tarjeta',
			'mayorista'       => 'Mayorista',
			'cripto'          => 'Cripto',
		];
		/* Con otro proveedor (MJD_SUGGEST_ENDPOINT) las fuentes pueden ser otras. */
		$sources = (array) apply_filters('mjd_suggest_sources', $sources);
		return $sources ? $sources : ['oficial' => 'Oficial'];
	}

	/**
	 * Devuelve todos los ajustes o uno concreto.
	 *
	 * @param string|null $key
	 * @return mixed
	 */
	public static function get($key = null) {
		if (null === self::$cache) {
			$saved       = get_option(self::OPTION, []);
			self::$cache = wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
		}
		if (null === $key) {
			return self::$cache;
		}
		return isset(self::$cache[$key]) ? self::$cache[$key] : null;
	}

	/** Guarda ajustes parciales (saneados) y limpia la caché. */
	public static function update(array $values) {
		$clean = self::sanitize(wp_parse_args($values, self::get()));
		update_option(self::OPTION, $clean);
		self::$cache = null;
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanea el array recibido (formulario de ajustes o update()).
	 * El TC y su metadata no vienen en el formulario de ajustes: se conservan
	 * salvo que lleguen explícitos (MJD_Rate::update()).
	 */
	public static function sanitize($input) {
		$d   = self::defaults();
		$in  = is_array($input) ? $input : [];
		$cur = self::get();
		$out = [];

		$out['rate']            = array_key_exists('rate', $in) ? MJD_Rate::normalize($in['rate']) : (string) $cur['rate'];
		$out['rate_updated_at'] = isset($in['rate_updated_at']) ? absint($in['rate_updated_at']) : (int) $cur['rate_updated_at'];
		$out['rate_updated_by'] = isset($in['rate_updated_by']) ? absint($in['rate_updated_by']) : (int) $cur['rate_updated_by'];

		$rounding        = isset($in['rounding']) ? absint($in['rounding']) : $d['rounding'];
		$out['rounding'] = array_key_exists($rounding, self::rounding_choices()) ? $rounding : $d['rounding'];

		$mode                 = isset($in['rounding_mode']) ? sanitize_key($in['rounding_mode']) : $d['rounding_mode'];
		$out['rounding_mode'] = in_array($mode, ['nearest', 'up'], true) ? $mode : $d['rounding_mode'];

		$out['show_header']  = empty($in['show_header']) ? 0 : 1;
		$label               = isset($in['header_label']) ? trim(sanitize_text_field($in['header_label'])) : $d['header_label'];
		$out['header_label'] = '' !== $label ? $label : $d['header_label'];

		$show                  = isset($in['show_usd_price']) ? sanitize_key($in['show_usd_price']) : $d['show_usd_price'];
		$out['show_usd_price'] = in_array($show, ['off', 'product', 'all'], true) ? $show : $d['show_usd_price'];

		$out['suggest_enabled']   = empty($in['suggest_enabled']) ? 0 : 1;
		$source                   = isset($in['suggest_source']) ? sanitize_key($in['suggest_source']) : $d['suggest_source'];
		$out['suggest_source']    = array_key_exists($source, self::suggest_sources()) ? $source : $d['suggest_source'];
		$side                     = isset($in['suggest_side']) ? sanitize_key($in['suggest_side']) : $d['suggest_side'];
		$out['suggest_side']      = in_array($side, ['compra', 'venta'], true) ? $side : $d['suggest_side'];
		$pct                      = isset($in['suggest_alert_pct']) ? absint($in['suggest_alert_pct']) : $d['suggest_alert_pct'];
		$out['suggest_alert_pct'] = min(100, $pct);

		self::$cache = null;
		return $out;
	}
}
