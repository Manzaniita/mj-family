<?php
/**
 * Tipo de cambio: lectura, conversión, redondeo, actualización e historial.
 *
 * Es la API pública para otros plugins:
 *   MJD_Rate::get()                → float (0 si no está cargado)
 *   MJD_Rate::convert($usd)        → pesos redondeados
 *   MJD_Rate::update($rate, $nota) → guarda, loguea, limpia cachés y dispara mjd_rate_updated
 */

defined('ABSPATH') || exit;

class MJD_Rate {

	/** TC vigente (pesos por dólar). 0 si no está configurado. */
	public static function get() {
		$rate = (float) MJD_Settings::get('rate');
		$rate = $rate > 0 ? $rate : 0.0;
		/* Otros plugins pueden ajustar el TC leído (p. ej. un recargo global). */
		return (float) apply_filters('mjd_rate', $rate);
	}

	public static function is_set() {
		return self::get() > 0;
	}

	/**
	 * Normaliza un TC ingresado ("1.450,50", "1450.5", "1450") a string decimal
	 * con punto y sin ceros sobrantes. '' si no es un número mayor a cero.
	 */
	public static function normalize($value) {
		$value = MJD_Product::parse_decimal($value);
		return '' !== $value && (float) $value > 0 ? $value : '';
	}

	/** Redondea un importe en pesos según el múltiplo y el modo configurados. */
	public static function round($amount) {
		$amount = (float) $amount;
		$step   = (int) MJD_Settings::get('rounding');
		if ($step <= 0) {
			return round($amount, (int) wc_get_price_decimals());
		}
		$q = $amount / $step;
		// 1e-9: evita que 1450.0000000001 (error de coma flotante) suba al múltiplo siguiente.
		$q = 'up' === MJD_Settings::get('rounding_mode') ? ceil($q - 1e-9) : round($q);
		return (float) ($q * $step);
	}

	/**
	 * USD → pesos.
	 *
	 * @param float           $usd
	 * @param float|null      $rate    TC a usar (null = vigente).
	 * @param WC_Product|null $product Contexto para filtros (recargos por categoría, etc.).
	 * @return float Pesos ya redondeados. 0 si no hay TC.
	 */
	public static function convert($usd, $rate = null, $product = null) {
		$rate = null === $rate ? self::get() : (float) $rate;
		$usd  = (float) $usd;
		if ($rate <= 0) {
			return 0.0;
		}
		$raw = (float) apply_filters('mjd_convert_raw', $usd * $rate, $usd, $rate, $product);
		$ars = self::round($raw);
		return (float) apply_filters('mjd_convert', $ars, $usd, $rate, $product);
	}

	/**
	 * Guarda un TC nuevo.
	 *
	 * @return float|WP_Error El TC guardado.
	 */
	public static function update($rate, $note = '', $user_id = null) {
		$new = self::normalize($rate);
		if ('' === $new) {
			return new WP_Error('mjd_invalid_rate', 'El tipo de cambio tiene que ser un número mayor a cero.');
		}

		$old     = (string) MJD_Settings::get('rate');
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

		MJD_Settings::update([
			'rate'            => $new,
			'rate_updated_at' => time(),
			'rate_updated_by' => $user_id,
		]);
		self::log($new, $old, $note, $user_id);
		self::flush_caches();

		do_action('mjd_rate_updated', (float) $new, (float) $old, (string) $note);

		return (float) $new;
	}

	/** Agrega una entrada al historial (se guardan las últimas 200). */
	public static function log($new, $old, $note, $user_id) {
		$history = get_option(MJD_Settings::HISTORY, []);
		if (!is_array($history)) {
			$history = [];
		}
		array_unshift($history, [
			'rate' => (string) $new,
			'old'  => (string) $old,
			'at'   => time(),
			'by'   => (int) $user_id,
			'note' => sanitize_text_field((string) $note),
		]);
		update_option(MJD_Settings::HISTORY, array_slice($history, 0, 200), false);
	}

	/** Últimas $limit entradas del historial (la más nueva primero). */
	public static function history($limit = 30) {
		$history = get_option(MJD_Settings::HISTORY, []);
		return is_array($history) ? array_slice($history, 0, max(1, (int) $limit)) : [];
	}

	public static function updated_at() {
		return (int) MJD_Settings::get('rate_updated_at');
	}

	public static function updated_by_name() {
		$id   = (int) MJD_Settings::get('rate_updated_by');
		$user = $id ? get_userdata($id) : null;
		return $user ? $user->display_name : '';
	}

	/**
	 * Limpia cachés que guardan precios o el header con el TC impreso:
	 * transients de WooCommerce (rangos de variables, ofertas) y las cachés de
	 * página más comunes. Otros plugins pueden engancharse a mjd_flush_caches.
	 */
	public static function flush_caches() {
		if (function_exists('wc_delete_product_transients')) {
			wc_delete_product_transients();
		}
		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
		}
		if (function_exists('w3tc_flush_all')) {
			w3tc_flush_all();
		}
		if (function_exists('wp_cache_clear_cache')) {
			wp_cache_clear_cache();
		}
		if (function_exists('sg_cachepress_purge_cache')) {
			sg_cachepress_purge_cache();
		}
		do_action('litespeed_purge_all');
		do_action('mjd_flush_caches');
	}

	/* ---------------------------------------------------------------------
	 * Formato
	 * ------------------------------------------------------------------ */

	/** "$ 1.450" o "$ 1.450,50" (según la moneda de la tienda). */
	public static function format_rate($rate = null, $html = false) {
		$rate     = null === $rate ? self::get() : (float) $rate;
		$decimals = abs($rate - round($rate)) < 0.005 ? 0 : 2;
		$out      = wc_price($rate, ['decimals' => $decimals]);
		return $html ? $out : self::plain($out);
	}

	/** Importe en la moneda de la tienda. */
	public static function format_ars($amount, $html = false) {
		$out = wc_price((float) $amount);
		return $html ? $out : self::plain($out);
	}

	/** "USD 120" o "USD 120,50". */
	public static function format_usd($amount) {
		$amount   = (float) $amount;
		$decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;
		return 'USD ' . number_format($amount, $decimals, wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
	}

	/** Datos del TC para el frontend y la REST. */
	public static function payload() {
		$rate = self::get();
		$at   = self::updated_at();
		return [
			'rate'          => $rate,
			'formatted'     => $rate > 0 ? self::format_rate($rate) : '',
			'currency'      => 'USD',
			'storeCurrency' => get_woocommerce_currency(),
			'label'         => (string) MJD_Settings::get('header_label'),
			'updatedAt'     => $at ? gmdate('c', $at) : null,
			'rounding'      => (int) MJD_Settings::get('rounding'),
			'roundingMode'  => (string) MJD_Settings::get('rounding_mode'),
		];
	}

	private static function plain($html) {
		return trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8'));
	}
}
