<?php
/**
 * La prueba de cotización: preguntarle a los correos delante de uno.
 *
 * "¿Está cotizando bien?" no se contesta mirando el checkout, porque ahí el
 * precio ya viene con el margen, el seguro, el mínimo y el redondeo encima. Esta
 * pantalla pide una cotización de verdad para un destino y un producto, y
 * muestra las dos cifras separadas: **lo que cobra el correo** y **lo que paga
 * el cliente**. Con eso se ve de una si el correo varía por destino, si el
 * margen está haciendo lo que se espera, y si el redondeo está aplanando todo.
 *
 * No toca nada: arma un paquete al vuelo, cotiza y tira el resultado. El único
 * efecto es el que ya tienen las cotizaciones normales (los correos guardan la
 * suya en su propia caché).
 */

defined('ABSPATH') || exit;

class MJE_Probe {

	/** Lo último que se probó, para poder mostrarlo después del redirect. */
	const TRANSIENT = 'mje_probe_';

	/**
	 * Cotiza un destino y devuelve lo que contestó cada método.
	 *
	 * → [['metodo','titulo','correo','base','final','plazo','error'], ...]
	 *
	 * `base` es lo que cobra el correo antes de que MJ Envíos le toque nada;
	 * `final` es lo que vería el cliente. Cuando son iguales, no hay margen ni
	 * redondeo de por medio.
	 */
	public static function run($postcode, $state = '', $product_id = 0, $qty = 1) {
		$package = self::package($postcode, $state, $product_id, $qty);
		if (!$package) {
			return [];
		}

		/* Los correos con plugin cotizan cuando WooCommerce calcula un paquete. */
		MJE_Native::capture_for_package($package, 'mje_probe');

		$out = [];
		foreach (MJE_Methods::all() as $slug => $def) {
			$quote   = MJE_Rates::quote($def, $package);
			$carrier = MJE_Methods::carrier_of($def);
			$out[]   = [
				'metodo'  => $slug,
				'titulo'  => MJE_Methods::name($def),
				'correo'  => $carrier['label'],
				'base'    => null !== $quote['base'] ? (float) $quote['base'] : null,
				'final'   => null !== $quote['cost'] ? (float) $quote['cost'] : null,
				'plazo'   => MJE_Methods::eta(array_merge($def, ['days_min' => $quote['days_min'], 'days_max' => $quote['days_max']])),
				'origen'  => (string) $quote['source'],
				'error'   => (string) $quote['error'],
				'apagado' => empty($def['enabled']),
			];
		}
		return $out;
	}

	/** El paquete de la prueba: un producto real, para que los correos puedan pesarlo. */
	private static function package($postcode, $state, $product_id, $qty) {
		if (!function_exists('wc_get_product')) {
			return [];
		}
		$postcode = MJE_Geo::normalize_cp($postcode);
		if ('' === $postcode) {
			return [];
		}
		$qty      = max(1, (int) $qty);
		$product  = $product_id ? wc_get_product((int) $product_id) : null;
		$contents = [];
		$cost     = 0.0;

		if ($product instanceof WC_Product) {
			$cost     = (float) $product->get_price() * $qty;
			$contents = [
				'mje_probe' => [
					'key'        => 'mje_probe',
					'data'       => $product,
					'product_id' => $product->get_id(),
					'quantity'   => $qty,
					'line_total' => $cost,
				],
			];
		}

		return [
			'contents'      => $contents,
			'contents_cost' => $cost,
			'destination'   => [
				'country'  => MJE_Geo::country(),
				'state'    => '' !== $state ? MJE_Geo::state_code($state) : '',
				'city'     => '',
				'postcode' => $postcode,
				'address'  => '',
			],
		];
	}

	/**
	 * Productos para elegir en la prueba, los que tienen peso primero.
	 *
	 * Un correo no cotiza un paquete sin peso, así que arrancar por uno que lo
	 * tenga evita la confusión de "no cotizó" cuando el problema es el producto.
	 */
	public static function products($limit = 40) {
		if (!function_exists('wc_get_products')) {
			return [];
		}
		$out = [];
		foreach (wc_get_products(['status' => 'publish', 'limit' => (int) $limit, 'orderby' => 'date', 'order' => 'DESC']) as $product) {
			if (!$product instanceof WC_Product || !$product->needs_shipping()) {
				continue;
			}
			$weight = (float) MJE_Settings::decimal($product->get_weight());
			$out[]  = [
				'id'     => $product->get_id(),
				'label'  => sprintf('%s (%s)', $product->get_name(), $weight > 0 ? $weight . ' ' . get_option('woocommerce_weight_unit', 'kg') : 'sin peso'),
				'weight' => $weight,
			];
		}
		/* Los que tienen peso arriba: son los que de verdad prueban al correo. */
		usort($out, function ($a, $b) {
			if (($a['weight'] > 0) === ($b['weight'] > 0)) {
				return 0;
			}
			return $a['weight'] > 0 ? -1 : 1;
		});
		return $out;
	}

	/* ------------------------------ Guardado ----------------------------- */

	public static function remember(array $result, array $asked) {
		set_transient(self::TRANSIENT . get_current_user_id(), ['result' => $result, 'asked' => $asked], 10 * MINUTE_IN_SECONDS);
	}

	public static function last() {
		$saved = get_transient(self::TRANSIENT . get_current_user_id());
		return is_array($saved) ? $saved : [];
	}

	public static function forget() {
		delete_transient(self::TRANSIENT . get_current_user_id());
	}
}
