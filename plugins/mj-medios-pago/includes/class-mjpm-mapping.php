<?php
/**
 * Equivalencia entre los medios de pago de la tienda y los del ERP.
 *
 * WooCommerce identifica los medios de pago con el id del gateway ('bacs',
 * 'mjpm_transferencia'); MJ ERP tiene catálogos propios de claves cortas
 * ('transferencia', 'efectivo'). Sin un puente, la misma transferencia es dos
 * cosas distintas y hay que configurarla dos veces.
 *
 * Acá se guarda ese puente, una sola vez, desde wp-admin:
 *
 *     mjpm_mapping = [ 'bacs' => 'transferencia', 'mjpm_dolares' => 'dolares' ]
 *
 * Con eso:
 *   - la regla de comprobante que configurás por gateway vale también para los
 *     pagos del ERP (ventas y reventas), y
 *   - una venta capturada de la tienda deja de caer en el método "otro" cuando
 *     el id del gateway no coincide por nombre con una clave del ERP (filtro
 *     mjerp_sale_method_from_gateway).
 */

defined('ABSPATH') || exit;

class MJPM_Mapping {

	private static $cache = null;

	public static function init() {
		add_filter('mjerp_sale_method_from_gateway', [__CLASS__, 'filter_erp_method'], 10, 3);
	}

	/** ¿Está MJ ERP instalado y con sus ajustes disponibles? */
	public static function erp_available() {
		return class_exists('MJERP_Settings') && is_callable(['MJERP_Settings', 'sale_payment_methods']);
	}

	/** Todo el mapeo guardado: id de gateway => clave del ERP. */
	public static function all() {
		if (null === self::$cache) {
			$saved       = get_option(MJPM_Settings::MAPPING, []);
			self::$cache = [];
			if (is_array($saved)) {
				foreach ($saved as $gateway => $key) {
					$gateway = sanitize_key((string) $gateway);
					$key     = sanitize_key((string) $key);
					if ('' !== $gateway && '' !== $key) {
						self::$cache[$gateway] = $key;
					}
				}
			}
		}
		return self::$cache;
	}

	public static function update(array $map) {
		$clean = [];
		$keys  = wp_list_pluck(self::erp_methods(), 'key');
		foreach ($map as $gateway => $key) {
			$gateway = sanitize_key((string) $gateway);
			$key     = sanitize_key((string) $key);
			if ('' === $gateway || '' === $key) {
				continue;
			}
			if ($keys && !in_array($key, $keys, true)) {
				continue; // Se borró del ERP: no guardamos una equivalencia muerta.
			}
			$clean[$gateway] = $key;
		}
		update_option(MJPM_Settings::MAPPING, $clean);
		self::$cache = null;
	}

	public static function flush() {
		self::$cache = null;
	}

	/** Clave del ERP para un gateway, '' si no está mapeado. */
	public static function erp_key($gateway_id) {
		$all     = self::all();
		$gateway = sanitize_key((string) $gateway_id);
		return isset($all[$gateway]) ? $all[$gateway] : '';
	}

	/** Gateway mapeado a una clave del ERP, '' si ninguno. */
	public static function gateway_for($erp_key) {
		$erp_key = sanitize_key((string) $erp_key);
		foreach (self::all() as $gateway => $key) {
			if ($key === $erp_key) {
				return $gateway;
			}
		}
		return '';
	}

	/**
	 * Catálogo de métodos del ERP (ventas + consignaciones, sin repetir).
	 *
	 * @return array [ { key, label, scope } ]
	 */
	public static function erp_methods() {
		if (!self::erp_available()) {
			return [];
		}
		$out = [];
		$add = function ($list, $scope) use (&$out) {
			foreach (is_array($list) ? $list : [] as $row) {
				$key = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
				if ('' === $key) {
					continue;
				}
				if (isset($out[$key])) {
					$out[$key]['scope'] = 'ambos';
					continue;
				}
				$out[$key] = [
					'key'   => $key,
					'label' => isset($row['label']) && '' !== (string) $row['label'] ? (string) $row['label'] : ucfirst($key),
					'scope' => $scope,
				];
			}
		};
		$add(MJERP_Settings::sale_payment_methods(), 'ventas');
		if (is_callable(['MJERP_Settings', 'payment_methods'])) {
			$add(MJERP_Settings::payment_methods(), 'consignaciones');
		}
		return array_values($out);
	}

	public static function erp_label($key) {
		foreach (self::erp_methods() as $row) {
			if ($row['key'] === sanitize_key((string) $key)) {
				return $row['label'];
			}
		}
		return ucfirst(str_replace(['_', '-'], ' ', (string) $key));
	}

	/**
	 * Sugerencia automática para un gateway que todavía no se mapeó: si la clave
	 * del ERP coincide con el id del gateway o con el final de un método propio
	 * ('mjpm_transferencia' → 'transferencia'), esa es la obvia.
	 */
	public static function suggest($gateway_id) {
		$gateway = sanitize_key((string) $gateway_id);
		$short   = 0 === strpos($gateway, 'mjpm_') ? substr($gateway, 5) : $gateway;
		$keys    = wp_list_pluck(self::erp_methods(), 'key');

		foreach ([$gateway, $short] as $candidate) {
			if (in_array($candidate, $keys, true)) {
				return $candidate;
			}
		}
		/* 'bacs' es la transferencia bancaria de WooCommerce. */
		if ('bacs' === $gateway && in_array('transferencia', $keys, true)) {
			return 'transferencia';
		}
		if ('cod' === $gateway && in_array('efectivo', $keys, true)) {
			return 'efectivo';
		}
		return '';
	}

	/**
	 * Filtro del ERP: qué método le toca a un pedido de la tienda.
	 *
	 * @param string   $method  Lo que venía resuelto ('' = sin resolver).
	 * @param string   $gateway Id del gateway.
	 * @param WC_Order $order   Pedido.
	 * @return string
	 */
	public static function filter_erp_method($method, $gateway, $order = null) {
		unset($order);
		if ('' !== (string) $method) {
			return $method;
		}
		return self::erp_key($gateway);
	}

	/**
	 * Regla de comprobante para una clave del ERP.
	 *
	 * Si esa clave está mapeada a un gateway, se usa la configuración de ese
	 * gateway (una sola fuente de verdad). Si no, los ajustes generales.
	 *
	 * @param string $erp_key Clave del método del ERP.
	 * @return array
	 */
	public static function requirement_for_erp($erp_key) {
		$gateway = self::gateway_for($erp_key);
		if ('' !== $gateway) {
			return MJPM_Receipts::method_requirement($gateway);
		}
		return MJPM_Receipts::fill_requirement([]);
	}
}
