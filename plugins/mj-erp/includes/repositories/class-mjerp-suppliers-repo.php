<?php
/**
 * Suppliers repository.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Suppliers_Repo
 */
class MJERP_Suppliers_Repo {

	const FORMATS = array(
		'name'             => '%s',
		'contact_name'     => '%s',
		'email'            => '%s',
		'phone'            => '%s',
		'country'          => '%s',
		'default_currency' => '%s',
		'notes'            => '%s',
		'is_active'        => '%d',
		'created_at'       => '%s',
		'updated_at'       => '%s',
	);

	private static function table() : string {
		return MJERP_Install::table( 'suppliers' );
	}

	/**
	 * All suppliers ordered by name.
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
	 * Find one supplier.
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
	 * Search by name / contact / email / country.
	 */
	public static function search( $term, bool $include_inactive = false, int $limit = 50 ) : array {
		global $wpdb;
		$term = trim( sanitize_text_field( (string) $term ) );
		if ( '' === $term ) {
			return $include_inactive ? self::all( true ) : self::all( false );
		}
		$table = self::table();
		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$limit = max( 1, min( 200, $limit ) );
		$sql   = "SELECT * FROM {$table} WHERE (name LIKE %s OR contact_name LIKE %s OR email LIKE %s OR country LIKE %s)";
		if ( ! $include_inactive ) {
			$sql .= ' AND is_active = 1';
		}
		$sql .= ' ORDER BY name ASC, id ASC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $like, $like, $like, $like, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row );
		}
		return $out;
	}

	/**
	 * Create a supplier.
	 *
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$row = self::prepare( $data, null );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$defaults = array(
			'contact_name'     => '',
			'email'            => '',
			'phone'            => '',
			'country'          => '',
			'default_currency' => null,
			'notes'            => '',
			'is_active'        => 1,
		);
		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $row ) ) {
				$row[ $key ] = $value;
			}
		}

		$now               = current_time( 'mysql' );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$result = $wpdb->insert( self::table(), $row, self::formats( $row ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo guardar el proveedor.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a supplier.
	 *
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'El proveedor no existe.', 'mj-erp' ), array( 'status' => 404 ) );
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
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar el proveedor.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		// Refresh the supplier_name snapshot on purchases when the name changed.
		if ( isset( $row['name'] ) && $row['name'] !== $existing['name'] && class_exists( 'MJERP_Install' ) ) {
			$purchases = MJERP_Install::table( 'purchases' );
			$wpdb->update( $purchases, array( 'supplier_name' => $row['name'] ), array( 'supplier_id' => $existing['id'] ), array( '%s' ), array( '%d' ) );
		}

		return true;
	}

	/**
	 * Delete a supplier: soft delete (is_active = 0) when referenced by purchases, hard delete otherwise.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'El proveedor no existe.', 'mj-erp' ), array( 'status' => 404 ) );
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
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar el proveedor.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Whether purchases reference this supplier (delete becomes a soft delete).
	 */
	public static function is_referenced( $id ) : bool {
		return self::purchases_count( $id ) > 0;
	}

	/**
	 * Number of purchases linked to the supplier.
	 */
	public static function purchases_count( $id ) : int {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 ) {
			return 0;
		}
		$purchases = MJERP_Install::table( 'purchases' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$purchases} WHERE supplier_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
				return new WP_Error( 'mjerp_invalid_supplier', __( 'El nombre del proveedor es obligatorio.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['name'] = $name;
		}

		if ( array_key_exists( 'contact_name', $data ) ) {
			$row['contact_name'] = self::text( $data['contact_name'], 190 );
		}

		if ( array_key_exists( 'email', $data ) ) {
			$email = trim( sanitize_email( (string) $data['email'] ) );
			if ( '' !== trim( (string) $data['email'] ) && ( '' === $email || ! is_email( $email ) ) ) {
				return new WP_Error( 'mjerp_invalid_email', __( 'El email del proveedor es inválido.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['email'] = $email;
		}

		if ( array_key_exists( 'phone', $data ) ) {
			$row['phone'] = self::text( $data['phone'], 60 );
		}

		if ( array_key_exists( 'country', $data ) ) {
			$row['country'] = self::text( $data['country'], 100 );
		}

		if ( array_key_exists( 'default_currency', $data ) ) {
			$raw = trim( (string) $data['default_currency'] );
			if ( '' === $raw ) {
				$row['default_currency'] = null;
			} else {
				$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', $raw ) );
				if ( 3 !== strlen( $currency ) ) {
					return new WP_Error( 'mjerp_invalid_currency', __( 'La moneda por defecto es inválida.', 'mj-erp' ), array( 'status' => 400 ) );
				}
				$row['default_currency'] = $currency;
			}
		}

		if ( array_key_exists( 'notes', $data ) ) {
			$row['notes'] = sanitize_textarea_field( (string) $data['notes'] );
		}

		if ( array_key_exists( 'is_active', $data ) ) {
			$row['is_active'] = self::to_bool( $data['is_active'] ) ? 1 : 0;
		}

		return $row;
	}

	private static function cast( array $row ) : array {
		$currency = isset( $row['default_currency'] ) && null !== $row['default_currency'] ? strtoupper( (string) $row['default_currency'] ) : '';
		return array(
			'id'               => (int) $row['id'],
			'name'             => (string) self::v( $row, 'name' ),
			'contact_name'     => (string) self::v( $row, 'contact_name' ),
			'email'            => (string) self::v( $row, 'email' ),
			'phone'            => (string) self::v( $row, 'phone' ),
			'country'          => (string) self::v( $row, 'country' ),
			'default_currency' => $currency,
			'notes'            => (string) self::v( $row, 'notes' ),
			'is_active'        => (bool) (int) self::v( $row, 'is_active', 1 ),
			'created_at'       => self::datetime_or_null( self::v( $row, 'created_at' ) ),
			'updated_at'       => self::datetime_or_null( self::v( $row, 'updated_at' ) ),
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
