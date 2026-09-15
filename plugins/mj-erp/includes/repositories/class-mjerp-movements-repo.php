<?php
/**
 * Stock movements repository (audit log of stock changes made by the ERP).
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Movements_Repo
 */
class MJERP_Movements_Repo {

	const TYPE_RECEIVE = 'purchase_receive';
	const TYPE_REVERT  = 'purchase_revert';

	const FORMATS = array(
		'product_id'     => '%d',
		'variation_id'   => '%d',
		'qty'            => '%f',
		'type'           => '%s',
		'ref_type'       => '%s',
		'ref_id'         => '%d',
		'stock_before'   => '%f',
		'stock_after'    => '%f',
		'unit_cost_base' => '%f',
		'note'           => '%s',
		'user_id'        => '%d',
		'created_at'     => '%s',
		'updated_at'     => '%s',
	);

	private static function table() : string {
		return MJERP_Install::table( 'stock_movements' );
	}

	/**
	 * Insert a movement. Returns the new id (0 on failure).
	 *
	 * Data: product_id (required), variation_id, qty (required; positive in, negative out), type,
	 * ref_type, ref_id, stock_before, stock_after, unit_cost_base, note, user_id.
	 */
	public static function insert( array $data ) : int {
		global $wpdb;

		$product_id = isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0;
		if ( $product_id <= 0 ) {
			return 0;
		}

		$type = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : '';
		if ( '' === $type ) {
			$type = self::TYPE_RECEIVE;
		}

		$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : get_current_user_id();
		$now     = current_time( 'mysql' );

		$row = array(
			'product_id'     => $product_id,
			'variation_id'   => isset( $data['variation_id'] ) ? absint( $data['variation_id'] ) : 0,
			'qty'            => isset( $data['qty'] ) ? self::to_float( $data['qty'] ) : 0,
			'type'           => substr( $type, 0, 40 ),
			'ref_type'       => isset( $data['ref_type'] ) && '' !== trim( (string) $data['ref_type'] ) ? substr( sanitize_key( (string) $data['ref_type'] ), 0, 40 ) : null,
			'ref_id'         => isset( $data['ref_id'] ) && absint( $data['ref_id'] ) > 0 ? absint( $data['ref_id'] ) : null,
			'stock_before'   => self::float_or_null( isset( $data['stock_before'] ) ? $data['stock_before'] : null ),
			'stock_after'    => self::float_or_null( isset( $data['stock_after'] ) ? $data['stock_after'] : null ),
			'unit_cost_base' => self::float_or_null( isset( $data['unit_cost_base'] ) ? $data['unit_cost_base'] : null ),
			'note'           => isset( $data['note'] ) ? self::text( $data['note'], 255 ) : '',
			'user_id'        => $user_id > 0 ? $user_id : null,
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		$result = $wpdb->insert( self::table(), $row, self::formats( $row ) );
		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Single movement.
	 */
	public static function find( int $id ) : ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		$rows = self::decorate( array( self::cast( $row ) ) );
		return $rows[0];
	}

	/**
	 * Movements for a reference (e.g. purchase), oldest first.
	 */
	public static function for_ref( string $ref_type, int $ref_id, ?string $type = null ) : array {
		global $wpdb;

		$ref_type = sanitize_key( $ref_type );
		if ( '' === $ref_type || $ref_id <= 0 ) {
			return array();
		}

		$table = self::table();
		$type  = null === $type ? '' : sanitize_key( $type );

		if ( '' !== $type ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE ref_type = %s AND ref_id = %d AND type = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ref_type,
				$ref_id,
				$type
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE ref_type = %s AND ref_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ref_type,
				$ref_id
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row );
		}
		return self::decorate( $out );
	}

	/**
	 * Movements for a product (matches product_id or variation_id), newest first.
	 */
	public static function for_product( int $product_id, int $limit = 50 ) : array {
		global $wpdb;

		if ( $product_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 500, $limit ) );
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d OR variation_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product_id,
				$product_id,
				$limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row );
		}
		return self::decorate( $out );
	}

	/**
	 * Delete every movement of a reference (returns rows deleted).
	 */
	public static function delete_for_ref( string $ref_type, int $ref_id ) : int {
		global $wpdb;
		$ref_type = sanitize_key( $ref_type );
		if ( '' === $ref_type || $ref_id <= 0 ) {
			return 0;
		}
		$result = $wpdb->delete(
			self::table(),
			array(
				'ref_type' => $ref_type,
				'ref_id'   => $ref_id,
			),
			array( '%s', '%d' )
		);
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Human label for a movement type.
	 */
	public static function type_label( string $type ) : string {
		$labels = array(
			self::TYPE_RECEIVE => __( 'Recepción de compra', 'mj-erp' ),
			self::TYPE_REVERT  => __( 'Reversión de recepción', 'mj-erp' ),
			'adjustment'       => __( 'Ajuste manual', 'mj-erp' ),
			'sale'             => __( 'Venta', 'mj-erp' ),
			'sale_revert'      => __( 'Anulación de venta', 'mj-erp' ),
			'sale_edit'        => __( 'Corrección de venta', 'mj-erp' ),
			'sale_return'      => __( 'Devolución', 'mj-erp' ),
			'sale_exchange'    => __( 'Cambio', 'mj-erp' ),
			'rma_replacement'  => __( 'Reemplazo por garantía', 'mj-erp' ),
			'rma_restock'      => __( 'RMA: volvió al stock', 'mj-erp' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( str_replace( '_', ' ', $type ) );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Adds type_label, user_name and ref_code (purchase code) to cast rows.
	 */
	private static function decorate( array $rows ) : array {
		global $wpdb;

		if ( empty( $rows ) ) {
			return $rows;
		}

		$purchase_ids = array();
		$user_ids     = array();
		foreach ( $rows as $row ) {
			if ( 'purchase' === $row['ref_type'] && $row['ref_id'] > 0 ) {
				$purchase_ids[ $row['ref_id'] ] = true;
			}
			if ( $row['user_id'] > 0 ) {
				$user_ids[ $row['user_id'] ] = true;
			}
		}

		$codes = array();
		if ( ! empty( $purchase_ids ) ) {
			$ids          = array_map( 'intval', array_keys( $purchase_ids ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$purchases    = MJERP_Install::table( 'purchases' );
			$found        = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, code FROM {$purchases} WHERE id IN ({$placeholders})", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			foreach ( (array) $found as $p ) {
				$codes[ (int) $p['id'] ] = (string) $p['code'];
			}
		}

		$names = array();
		foreach ( array_keys( $user_ids ) as $uid ) {
			$names[ $uid ] = self::user_name( (int) $uid );
		}

		foreach ( $rows as &$row ) {
			$row['type_label'] = self::type_label( $row['type'] );
			$row['user_name']  = ( $row['user_id'] > 0 && isset( $names[ $row['user_id'] ] ) ) ? $names[ $row['user_id'] ] : '';
			$row['ref_code']   = ( 'purchase' === $row['ref_type'] && isset( $codes[ $row['ref_id'] ] ) ) ? $codes[ $row['ref_id'] ] : '';
		}
		unset( $row );

		return $rows;
	}

	private static function cast( array $row ) : array {
		return array(
			'id'             => (int) $row['id'],
			'product_id'     => (int) self::v( $row, 'product_id', 0 ),
			'variation_id'   => (int) self::v( $row, 'variation_id', 0 ),
			'qty'            => (float) self::v( $row, 'qty', 0 ),
			'type'           => (string) self::v( $row, 'type', '' ),
			'ref_type'       => (string) self::v( $row, 'ref_type', '' ),
			'ref_id'         => (int) self::v( $row, 'ref_id', 0 ),
			'stock_before'   => self::float_or_null( self::v( $row, 'stock_before', null ) ),
			'stock_after'    => self::float_or_null( self::v( $row, 'stock_after', null ) ),
			'unit_cost_base' => self::float_or_null( self::v( $row, 'unit_cost_base', null ) ),
			'note'           => (string) self::v( $row, 'note', '' ),
			'user_id'        => (int) self::v( $row, 'user_id', 0 ),
			'created_at'     => self::datetime_or_null( self::v( $row, 'created_at', null ) ),
		);
	}

	private static function v( array $row, string $key, $default ) {
		return ( isset( $row[ $key ] ) && null !== $row[ $key ] ) ? $row[ $key ] : $default;
	}

	private static function formats( array $row ) : array {
		$formats = array();
		foreach ( array_keys( $row ) as $key ) {
			$formats[] = isset( self::FORMATS[ $key ] ) ? self::FORMATS[ $key ] : '%s';
		}
		return $formats;
	}

	private static function float_or_null( $value ) : ?float {
		if ( null === $value || '' === $value || is_bool( $value ) ) {
			return null;
		}
		return self::to_float( $value );
	}

	private static function to_float( $value ) : float {
		if ( class_exists( 'MJERP_Helpers' ) && method_exists( 'MJERP_Helpers', 'to_float' ) ) {
			return (float) MJERP_Helpers::to_float( $value );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		$s = trim( (string) $value );
		if ( '' === $s ) {
			return 0.0;
		}
		$has_comma = false !== strpos( $s, ',' );
		$has_dot   = false !== strpos( $s, '.' );
		if ( $has_comma && $has_dot ) {
			if ( strrpos( $s, ',' ) > strrpos( $s, '.' ) ) {
				$s = str_replace( '.', '', $s );
				$s = str_replace( ',', '.', $s );
			} else {
				$s = str_replace( ',', '', $s );
			}
		} elseif ( $has_comma ) {
			$s = str_replace( ',', '.', $s );
		}
		$s = preg_replace( '/[^0-9.\-]/', '', $s );
		return is_numeric( $s ) ? (float) $s : 0.0;
	}

	private static function text( $value, int $max ) : string {
		$value = trim( sanitize_text_field( (string) $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	private static function user_name( int $user_id ) : string {
		if ( $user_id <= 0 ) {
			return '';
		}
		if ( class_exists( 'MJERP_Helpers' ) && method_exists( 'MJERP_Helpers', 'user_display_name' ) ) {
			$name = MJERP_Helpers::user_display_name( $user_id );
			return is_string( $name ) ? $name : '';
		}
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '';
	}

	private static function datetime_or_null( $value ) : ?string {
		if ( null === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}
		return $value;
	}
}
