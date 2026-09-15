<?php
defined( 'ABSPATH' ) || exit;

/**
 * Devoluciones, cambios y garantías de ventas cobradas: el registro de cada una.
 *
 * Una devolución es un documento aparte de la venta: la venta queda con lo que el cliente se llevó
 * al final, y acá queda qué volvió, a dónde fue cada unidad, qué se llevó a cambio y qué se hizo con
 * la plata. Las reglas están en MJERP_Returns; esto solo guarda y lee.
 *
 * @package MJ_ERP
 */
class MJERP_Returns_Repo {

	/** Solo vuelve mercadería. */
	const KIND_RETURN = 'devolucion';

	/** Vuelve algo y se lleva otra cosa. */
	const KIND_EXCHANGE = 'cambio';

	/** Se le da el mismo producto sin cargo: lo fallado va a RMA. */
	const KIND_WARRANTY = 'garantia';

	/** Una venta cobrada que se anuló: qué se hizo con la plata. */
	const KIND_VOID = 'anulacion';

	const SETTLE_CREDIT = 'saldo';
	const SETTLE_REFUND = 'reintegro';
	const SETTLE_NONE   = 'ninguno';

	const ITEM_RETURNED = 'devuelto';
	const ITEM_NEW      = 'nuevo';

	const DEST_STOCK = 'stock';
	const DEST_RMA   = 'rma';

	const FORMATS = array(
		'code'              => '%s',
		'sale_id'           => '%d',
		'customer_id'       => '%d',
		'return_date'       => '%s',
		'kind'              => '%s',
		'currency'          => '%s',
		'returned_value'    => '%f',
		'new_value'         => '%f',
		'total_before'      => '%f',
		'total_after'       => '%f',
		'paid_before'       => '%f',
		'excess'            => '%f',
		'due_after'         => '%f',
		'settlement'        => '%s',
		'settlement_detail' => '%s',
		'refund_method'     => '%s',
		'refund_cashbox_id' => '%d',
		'refund_reference'  => '%s',
		'reason'            => '%s',
		'created_by'        => '%d',
		'created_at'        => '%s',
	);

	const ITEM_FORMATS = array(
		'return_id'      => '%d',
		'sale_id'        => '%d',
		'sale_item_id'   => '%d',
		'type'           => '%s',
		'replaced'       => '%d',
		'product_id'     => '%d',
		'variation_id'   => '%d',
		'name'           => '%s',
		'sku'            => '%s',
		'qty'            => '%f',
		'unit_value'     => '%f',
		'line_value'     => '%f',
		'unit_cost_base' => '%f',
		'destination'    => '%s',
		'problem'        => '%s',
		'rma_id'         => '%d',
	);

	private static function table() : string {
		return MJERP_Install::table( 'sale_returns' );
	}

	private static function items_table() : string {
		return MJERP_Install::table( 'sale_return_items' );
	}

	/* ------------------------------------------------------------------
	 * Write
	 * ---------------------------------------------------------------- */

	/**
	 * Stores a return with its lines.
	 *
	 * @param array $data  Return fields.
	 * @param array $items Lines (same order the ids come back in).
	 * @return array|WP_Error { id, item_ids[] }
	 */
	public static function create( array $data, array $items ) {
		global $wpdb;

		$row = array(
			'code'              => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'code', '' ), 60 ),
			'sale_id'           => absint( MJERP_Helpers::array_get( $data, 'sale_id', 0 ) ),
			'customer_id'       => absint( MJERP_Helpers::array_get( $data, 'customer_id', 0 ) ),
			'return_date'       => MJERP_Helpers::sanitize_date( MJERP_Helpers::array_get( $data, 'return_date', '' ) ) ? MJERP_Helpers::sanitize_date( MJERP_Helpers::array_get( $data, 'return_date', '' ) ) : current_time( 'Y-m-d' ),
			'kind'              => sanitize_key( (string) MJERP_Helpers::array_get( $data, 'kind', self::KIND_RETURN ) ),
			'currency'          => strtoupper( (string) MJERP_Helpers::array_get( $data, 'currency', '' ) ),
			'returned_value'    => round( (float) MJERP_Helpers::array_get( $data, 'returned_value', 0 ), 4 ),
			'new_value'         => round( (float) MJERP_Helpers::array_get( $data, 'new_value', 0 ), 4 ),
			'total_before'      => round( (float) MJERP_Helpers::array_get( $data, 'total_before', 0 ), 4 ),
			'total_after'       => round( (float) MJERP_Helpers::array_get( $data, 'total_after', 0 ), 4 ),
			'paid_before'       => round( (float) MJERP_Helpers::array_get( $data, 'paid_before', 0 ), 4 ),
			'excess'            => round( (float) MJERP_Helpers::array_get( $data, 'excess', 0 ), 4 ),
			'due_after'         => round( (float) MJERP_Helpers::array_get( $data, 'due_after', 0 ), 4 ),
			'settlement'        => sanitize_key( (string) MJERP_Helpers::array_get( $data, 'settlement', self::SETTLE_NONE ) ),
			'settlement_detail' => wp_json_encode( (array) MJERP_Helpers::array_get( $data, 'settlement_detail', array() ) ),
			'refund_method'     => sanitize_key( (string) MJERP_Helpers::array_get( $data, 'refund_method', '' ) ),
			'refund_cashbox_id' => absint( MJERP_Helpers::array_get( $data, 'refund_cashbox_id', 0 ) ),
			'refund_reference'  => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'refund_reference', '' ), 190 ),
			'reason'            => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'reason', '' ), 255 ),
			'created_by'        => get_current_user_id(),
			'created_at'        => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( self::table(), $row, self::formats( $row, self::FORMATS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo registrar la devolución.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id       = (int) $wpdb->insert_id;
		$item_ids = array();

		foreach ( $items as $item ) {
			$line = array(
				'return_id'      => $id,
				'sale_id'        => $row['sale_id'],
				'sale_item_id'   => absint( MJERP_Helpers::array_get( $item, 'sale_item_id', 0 ) ),
				'type'           => sanitize_key( (string) MJERP_Helpers::array_get( $item, 'type', self::ITEM_RETURNED ) ),
				'replaced'       => MJERP_Helpers::bool( MJERP_Helpers::array_get( $item, 'replaced', false ) ) ? 1 : 0,
				'product_id'     => absint( MJERP_Helpers::array_get( $item, 'product_id', 0 ) ),
				'variation_id'   => absint( MJERP_Helpers::array_get( $item, 'variation_id', 0 ) ),
				'name'           => MJERP_Helpers::text( MJERP_Helpers::array_get( $item, 'name', '' ), 255 ),
				'sku'            => MJERP_Helpers::text( MJERP_Helpers::array_get( $item, 'sku', '' ), 100 ),
				'qty'            => round( (float) MJERP_Helpers::array_get( $item, 'qty', 0 ), 4 ),
				'unit_value'     => round( (float) MJERP_Helpers::array_get( $item, 'unit_value', 0 ), 6 ),
				'line_value'     => round( (float) MJERP_Helpers::array_get( $item, 'line_value', 0 ), 4 ),
				'unit_cost_base' => round( (float) MJERP_Helpers::array_get( $item, 'unit_cost_base', 0 ), 6 ),
				'destination'    => sanitize_key( (string) MJERP_Helpers::array_get( $item, 'destination', '' ) ),
				'problem'        => MJERP_Helpers::text( MJERP_Helpers::array_get( $item, 'problem', '' ), 255 ),
				'rma_id'         => 0,
			);
			$wpdb->insert( self::items_table(), $line, self::formats( $line, self::ITEM_FORMATS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$item_ids[] = (int) $wpdb->insert_id;
		}

		return array(
			'id'       => $id,
			'item_ids' => $item_ids,
		);
	}

	/**
	 * Points a returned line at the RMA it opened.
	 *
	 * @param int $item_id Line id.
	 * @param int $rma_id  RMA id.
	 * @return void
	 */
	public static function set_item_rma( $item_id, $rma_id ) : void {
		global $wpdb;
		$wpdb->update( self::items_table(), array( 'rma_id' => absint( $rma_id ) ), array( 'id' => absint( $item_id ) ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ------------------------------------------------------------------
	 * Read
	 * ---------------------------------------------------------------- */

	/**
	 * One return with its lines.
	 *
	 * @param int $id Return id.
	 * @return array|null
	 */
	public static function find( $id ) : ?array {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) {
			return null;
		}
		$return          = self::cast( $row );
		$return['items'] = self::items( $return['id'] );
		return $return;
	}

	/**
	 * Returns of a sale, oldest first, with their lines.
	 *
	 * @param int $sale_id Sale id.
	 * @return array
	 */
	public static function for_sale( $sale_id ) : array {
		global $wpdb;

		$sale_id = absint( $sale_id );
		if ( ! $sale_id ) {
			return array();
		}

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE sale_id = %d ORDER BY id ASC", $sale_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( empty( $rows ) ) {
			return array();
		}

		$items_table = self::items_table();
		$item_rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$items_table} WHERE sale_id = %d ORDER BY id ASC", $sale_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$by_return = array();
		foreach ( (array) $item_rows as $item ) {
			$by_return[ (int) $item['return_id'] ][] = self::cast_item( $item );
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$return          = self::cast( $row );
			$return['items'] = isset( $by_return[ $return['id'] ] ) ? $by_return[ $return['id'] ] : array();
			$out[]           = $return;
		}
		return $out;
	}

	/**
	 * Lines of a return.
	 *
	 * @param int $return_id Return id.
	 * @return array
	 */
	public static function items( $return_id ) : array {
		global $wpdb;
		$table = self::items_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE return_id = %d ORDER BY id ASC", absint( $return_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast_item( $row );
		}
		return $out;
	}

	/**
	 * Next free code: `{prefijo}-{año}-{secuencia}`.
	 *
	 * @return string
	 */
	public static function next_code() : string {
		global $wpdb;

		$prefix = trim( (string) MJERP_Settings::get( 'returns_code_prefix', 'DEV' ) );
		$base   = ( '' !== $prefix ? $prefix : 'DEV' ) . '-' . current_time( 'Y' ) . '-';
		$table  = self::table();

		$last = (string) $wpdb->get_var( $wpdb->prepare( "SELECT code FROM {$table} WHERE code LIKE %s ORDER BY id DESC LIMIT 1", $wpdb->esc_like( $base ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$seq  = '' !== $last ? (int) substr( $last, strlen( $base ) ) + 1 : 1;

		for ( $i = 0; $i < 1000; $i++, $seq++ ) {
			$code  = $base . str_pad( (string) $seq, 4, '0', STR_PAD_LEFT );
			$taken = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s LIMIT 1", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( ! $taken ) {
				return $code;
			}
		}

		return $base . time();
	}

	/* ------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	/**
	 * Label of a return kind.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function kind_label( string $kind ) : string {
		$labels = array(
			self::KIND_RETURN   => __( 'Devolución', 'mj-erp' ),
			self::KIND_EXCHANGE => __( 'Cambio', 'mj-erp' ),
			self::KIND_WARRANTY => __( 'Garantía', 'mj-erp' ),
			self::KIND_VOID     => __( 'Anulación', 'mj-erp' ),
		);
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind;
	}

	private static function cast( array $row ) : array {
		$detail = json_decode( (string) self::v( $row, 'settlement_detail', '' ), true );
		$method = (string) self::v( $row, 'refund_method' );

		return array(
			'id'                  => (int) $row['id'],
			'code'                => (string) self::v( $row, 'code' ),
			'sale_id'             => (int) self::v( $row, 'sale_id', 0 ),
			'customer_id'         => (int) self::v( $row, 'customer_id', 0 ),
			'return_date'         => (string) self::v( $row, 'return_date' ),
			'kind'                => (string) self::v( $row, 'kind' ),
			'kind_label'          => self::kind_label( (string) self::v( $row, 'kind' ) ),
			'currency'            => (string) self::v( $row, 'currency' ),
			'returned_value'      => round( (float) self::v( $row, 'returned_value', 0 ), 4 ),
			'new_value'           => round( (float) self::v( $row, 'new_value', 0 ), 4 ),
			'total_before'        => round( (float) self::v( $row, 'total_before', 0 ), 4 ),
			'total_after'         => round( (float) self::v( $row, 'total_after', 0 ), 4 ),
			'paid_before'         => round( (float) self::v( $row, 'paid_before', 0 ), 4 ),
			'excess'              => round( (float) self::v( $row, 'excess', 0 ), 4 ),
			'due_after'           => round( (float) self::v( $row, 'due_after', 0 ), 4 ),
			'settlement'          => (string) self::v( $row, 'settlement' ),
			'settlement_detail'   => is_array( $detail ) ? $detail : array(),
			'refund_method'       => $method,
			'refund_method_label' => '' !== $method ? MJERP_Settings::sale_payment_method_label( $method ) : '',
			'refund_cashbox_id'   => (int) self::v( $row, 'refund_cashbox_id', 0 ),
			'refund_reference'    => (string) self::v( $row, 'refund_reference' ),
			'reason'              => (string) self::v( $row, 'reason' ),
			'created_by'          => (int) self::v( $row, 'created_by', 0 ),
			'created_by_name'     => MJERP_Helpers::user_display_name( self::v( $row, 'created_by', 0 ) ),
			'created_at'          => (string) self::v( $row, 'created_at' ),
		);
	}

	private static function cast_item( array $row ) : array {
		return array(
			'id'             => (int) $row['id'],
			'return_id'      => (int) self::v( $row, 'return_id', 0 ),
			'sale_item_id'   => (int) self::v( $row, 'sale_item_id', 0 ),
			'type'           => (string) self::v( $row, 'type' ),
			'replaced'       => (bool) (int) self::v( $row, 'replaced', 0 ),
			'product_id'     => (int) self::v( $row, 'product_id', 0 ),
			'variation_id'   => (int) self::v( $row, 'variation_id', 0 ),
			'name'           => (string) self::v( $row, 'name' ),
			'sku'            => (string) self::v( $row, 'sku' ),
			'qty'            => round( (float) self::v( $row, 'qty', 0 ), 4 ),
			'unit_value'     => round( (float) self::v( $row, 'unit_value', 0 ), 6 ),
			'line_value'     => round( (float) self::v( $row, 'line_value', 0 ), 4 ),
			'unit_cost_base' => round( (float) self::v( $row, 'unit_cost_base', 0 ), 6 ),
			'destination'    => (string) self::v( $row, 'destination' ),
			'problem'        => (string) self::v( $row, 'problem' ),
			'rma_id'         => (int) self::v( $row, 'rma_id', 0 ),
		);
	}

	private static function v( array $row, string $key, $default = '' ) {
		return ( isset( $row[ $key ] ) && null !== $row[ $key ] ) ? $row[ $key ] : $default;
	}

	private static function formats( array $row, array $map ) : array {
		$formats = array();
		foreach ( array_keys( $row ) as $key ) {
			$formats[] = isset( $map[ $key ] ) ? $map[ $key ] : '%s';
		}
		return $formats;
	}
}
