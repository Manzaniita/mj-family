<?php
/**
 * Cashboxes repository (foundation for the future "Caja" module).
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Cashboxes_Repo
 */
class MJERP_Cashboxes_Repo {

	const FORMATS = array(
		'name'       => '%s',
		'currency'   => '%s',
		'is_active'  => '%d',
		'created_at' => '%s',
		'updated_at' => '%s',
	);

	private static function table() : string {
		return MJERP_Install::table( 'cashboxes' );
	}

	/**
	 * All cashboxes ordered by name.
	 */
	public static function all( bool $include_inactive = false ) : array {
		global $wpdb;
		$table = self::table();
		$sql   = "SELECT * FROM {$table}";
		if ( ! $include_inactive ) {
			$sql .= ' WHERE is_active = 1';
		}
		$sql .= ' ORDER BY name ASC, id ASC';
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row );
		}
		return $out;
	}

	/**
	 * Find one cashbox.
	 */
	public static function find( $id ) : ?array {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Search by name / currency.
	 */
	public static function search( $term, bool $include_inactive = false, int $limit = 50 ) : array {
		global $wpdb;
		$term = trim( sanitize_text_field( (string) $term ) );
		if ( '' === $term ) {
			return self::all( $include_inactive );
		}
		$table = self::table();
		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$limit = max( 1, min( 200, $limit ) );
		$sql   = "SELECT * FROM {$table} WHERE (name LIKE %s OR currency LIKE %s)";
		if ( ! $include_inactive ) {
			$sql .= ' AND is_active = 1';
		}
		$sql .= ' ORDER BY name ASC, id ASC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $like, $like, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row );
		}
		return $out;
	}

	/**
	 * Create a cashbox.
	 *
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$row = self::prepare( $data, null );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( ! array_key_exists( 'currency', $row ) ) {
			$row['currency'] = self::default_currency();
		}
		if ( ! array_key_exists( 'is_active', $row ) ) {
			$row['is_active'] = 1;
		}

		$now               = current_time( 'mysql' );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$result = $wpdb->insert( self::table(), $row, self::formats( $row ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo guardar la caja.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a cashbox.
	 *
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'La caja no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$row = self::prepare( $data, $existing );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( empty( $row ) ) {
			return true;
		}

		$row['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::table(), $row, array( 'id' => $existing['id'] ), self::formats( $row ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar la caja.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Delete a cashbox: soft delete when referenced by expenses, hard delete otherwise.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'La caja no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		if ( self::is_referenced( $existing['id'] ) ) {
			$result = $wpdb->update(
				self::table(),
				array(
					'is_active'  => 0,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $existing['id'] ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$result = $wpdb->delete( self::table(), array( 'id' => $existing['id'] ), array( '%d' ) );
		}

		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar la caja.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Whether purchase expenses reference this cashbox.
	 */
	public static function is_referenced( $id ) : bool {
		return self::expenses_count( $id ) > 0;
	}

	/**
	 * Number of purchase expenses paid from this cashbox.
	 */
	public static function expenses_count( $id ) : int {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 ) {
			return 0;
		}
		$expenses = MJERP_Install::table( 'purchase_expenses' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$expenses} WHERE cashbox_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Sanitize + validate provided fields.
	 *
	 * @return array|WP_Error
	 */
	private static function prepare( array $data, ?array $existing ) {
		$row = array();

		if ( array_key_exists( 'name', $data ) || ! $existing ) {
			$name = self::text( isset( $data['name'] ) ? $data['name'] : '', 190 );
			if ( '' === $name ) {
				return new WP_Error( 'mjerp_invalid_cashbox', __( 'El nombre de la caja es obligatorio.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['name'] = $name;
		}

		if ( array_key_exists( 'currency', $data ) ) {
			$raw = trim( (string) $data['currency'] );
			if ( '' === $raw ) {
				if ( ! $existing ) {
					$row['currency'] = self::default_currency();
				}
			} else {
				$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', $raw ) );
				if ( 3 !== strlen( $currency ) ) {
					return new WP_Error( 'mjerp_invalid_currency', __( 'La moneda de la caja es inválida.', 'mj-erp' ), array( 'status' => 400 ) );
				}
				$row['currency'] = $currency;
			}
		}

		if ( array_key_exists( 'is_active', $data ) ) {
			$row['is_active'] = self::to_bool( $data['is_active'] ) ? 1 : 0;
		}

		return $row;
	}

	private static function default_currency() : string {
		$currency = '';
		if ( class_exists( 'MJERP_Settings' ) && method_exists( 'MJERP_Settings', 'get' ) ) {
			$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) MJERP_Settings::get( 'local_currency', 'ARS' ) ) );
		}
		return 3 === strlen( $currency ) ? $currency : 'ARS';
	}

	private static function cast( array $row ) : array {
		return array(
			'id'         => (int) $row['id'],
			'name'       => (string) self::v( $row, 'name' ),
			'currency'   => strtoupper( (string) self::v( $row, 'currency', 'ARS' ) ),
			'is_active'  => (bool) (int) self::v( $row, 'is_active', 1 ),
			'created_at' => self::datetime_or_null( self::v( $row, 'created_at' ) ),
			'updated_at' => self::datetime_or_null( self::v( $row, 'updated_at' ) ),
		);
	}

	private static function v( array $row, string $key, $default = '' ) {
		return ( isset( $row[ $key ] ) && null !== $row[ $key ] ) ? $row[ $key ] : $default;
	}

	private static function formats( array $row ) : array {
		$formats = array();
		foreach ( array_keys( $row ) as $key ) {
			$formats[] = isset( self::FORMATS[ $key ] ) ? self::FORMATS[ $key ] : '%s';
		}
		return $formats;
	}

	private static function text( $value, int $max ) : string {
		$value = trim( sanitize_text_field( (string) $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	private static function to_bool( $value ) : bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return 0 !== (int) $value;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on', 'si', 'sí' ), true );
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
