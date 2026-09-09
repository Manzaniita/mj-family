<?php
/**
 * Importador y exportador CSV nativos de WooCommerce (Productos → Importar /
 * Exportar): columnas "Precio USD" y "Precio oferta USD".
 *
 * Importar: si la columna viene mapeada, manda el USD (el precio en pesos se
 * calcula con el TC vigente, aunque el CSV traiga "Precio normal"). Celda
 * vacía = el producto vuelve a pesos fijos, igual que hace WooCommerce con
 * sus columnas. Si la columna NO está en el CSV, no se toca el USD existente.
 *
 * Acepta "1234.5", "1.234,50", "1234,50" y "USD 1.234,50" (ver MJD_Product::parse_decimal).
 */

defined('ABSPATH') || exit;

class MJD_CSV {

	const COL_REGULAR = 'mjd_usd_regular';
	const COL_SALE    = 'mjd_usd_sale';

	public static function init() {
		add_filter('woocommerce_csv_product_import_mapping_options', [__CLASS__, 'mapping_options'], 10, 2);
		add_filter('woocommerce_csv_product_import_mapping_default_columns', [__CLASS__, 'default_columns'], 10, 2);
		add_filter('woocommerce_product_import_pre_insert_product_object', [__CLASS__, 'pre_insert'], 10, 2);
		add_action('woocommerce_product_import_inserted_product_object', [__CLASS__, 'inserted'], 10, 2);

		add_filter('woocommerce_product_export_column_names', [__CLASS__, 'export_columns']);
		add_filter('woocommerce_product_export_product_default_columns', [__CLASS__, 'export_columns']);
		add_filter('woocommerce_product_export_product_column_' . self::COL_REGULAR, [__CLASS__, 'export_regular'], 10, 2);
		add_filter('woocommerce_product_export_product_column_' . self::COL_SALE, [__CLASS__, 'export_sale'], 10, 2);
	}

	/* ------------------------------ Importar ----------------------------- */

	/** Opciones del selector de mapeo (pantalla "Mapear columnas"). */
	public static function mapping_options($options, $item = null) {
		$options[self::COL_REGULAR] = 'Precio USD';
		$options[self::COL_SALE]    = 'Precio oferta USD';
		return $options;
	}

	/** Encabezados que se mapean solos (WooCommerce los compara en minúsculas). */
	public static function default_columns($columns, $raw_headers = []) {
		$regular = ['precio usd', 'precio en usd', 'precio dolar', 'precio dólar', 'precio en dolares', 'precio en dólares', 'usd', 'price usd', 'usd price', 'regular price usd', 'precio_usd', 'usd_regular', 'mjd_usd_regular'];
		$sale    = ['precio oferta usd', 'oferta usd', 'precio de oferta usd', 'sale price usd', 'usd sale', 'precio_oferta_usd', 'usd_sale', 'mjd_usd_sale'];
		foreach ($regular as $header) {
			$columns[$header] = self::COL_REGULAR;
		}
		foreach ($sale as $header) {
			$columns[$header] = self::COL_SALE;
		}
		return $columns;
	}

	/** Antes de guardar cada fila: fijar USD y calcular pesos. */
	public static function pre_insert($object, $data) {
		if (!$object instanceof WC_Product || !is_array($data)) {
			return $object;
		}
		$has_regular = array_key_exists(self::COL_REGULAR, $data);
		$has_sale    = array_key_exists(self::COL_SALE, $data);
		if (!$has_regular && !$has_sale) {
			return $object;
		}
		if ($object->is_type('variable') || $object->is_type('grouped')) {
			return $object;
		}

		$regular = $has_regular ? $data[self::COL_REGULAR] : $object->get_meta(MJD_Product::META_REGULAR);
		$sale    = $has_sale ? $data[self::COL_SALE] : $object->get_meta(MJD_Product::META_SALE);

		MJD_Product::set_usd($object, $regular, $sale);
		MJD_Product::apply($object, null, false); // El importador guarda el objeto a continuación.

		return $object;
	}

	/** Después de guardar: mantener el índice USD del padre variable. */
	public static function inserted($object, $data) {
		if ($object instanceof WC_Product && $object->is_type('variation') && is_array($data)
			&& (array_key_exists(self::COL_REGULAR, $data) || array_key_exists(self::COL_SALE, $data))) {
			MJD_Product::sync_parent_usd($object->get_parent_id());
		}
	}

	/* ------------------------------ Exportar ----------------------------- */

	public static function export_columns($columns) {
		$columns[self::COL_REGULAR] = 'Precio USD';
		$columns[self::COL_SALE]    = 'Precio oferta USD';
		return $columns;
	}

	public static function export_regular($value, $product) {
		return $product instanceof WC_Product ? wc_format_localized_price((string) $product->get_meta(MJD_Product::META_REGULAR)) : '';
	}

	public static function export_sale($value, $product) {
		return $product instanceof WC_Product ? wc_format_localized_price((string) $product->get_meta(MJD_Product::META_SALE)) : '';
	}
}
