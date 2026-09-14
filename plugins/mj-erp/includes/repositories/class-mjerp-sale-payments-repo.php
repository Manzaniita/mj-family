<?php
/**
 * Money collected against a sale.
 *
 * This is the direct-sale counterpart of the consignment current account: a customer who buys at
 * the counter can pay in parts, in different currencies and through different means, and each of
 * those is a row here. What a reseller owes never lands here — that lives in
 * `MJERP_Consignment_Payments_Repo`, allocated against their outstanding sales.
 *
 * A payment can be `declarado` (someone without the shop owner's trust loaded it and it does not
 * count yet) or `confirmado` (it counts). Which one a given person produces is decided by the
 * configurable permissions, never here.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Sale_Payments_Repo
 */
class MJERP_Sale_Payments_Repo {

	const STATUS_DECLARED  = 'declarado';
	const STATUS_CONFIRMED = 'confirmado';

	const SOURCE_ADMIN  = 'admin';
	const SOURCE_PORTAL = 'portal';
	const SOURCE_SHOP   = 'tienda';

	const FORMATS = array(
		'sale_id'      => '%d',
		'payment_date' => '%s',
		'amount'       => '%f',
		'currency'     => '%s',
		'rate'         => '%f',
		'amount_base'  => '%f',
		'method'       => '%s',
		'cashbox_id'   => '%d',
		'reference'    => '%s',
		'note'         => '%s',
		'status'       => '%s',
		'source'       => '%s',
		'created_by'   => '%d',
		'confirmed_by' => '%d',
		'confirmed_at' => '%s',
		'created_at'   => '%s',
		'updated_at'   => '%s',
	);

	private static function table() : string {
		return MJERP_Install::table( 'sale_payments' );
	}

	/* ------------------------------------------------------------------
	 * Read
	 * ---------------------------------------------------------------- */

	/**
	 * Payments of a sale, oldest first.
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
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE sale_id = %d ORDER BY payment_date ASC, id ASC", $sale_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row );
		}
		return $out;
	}

	/**
	 * One payment.
	 *
	 * @param int $id Payment id.
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
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Confirmed totals collected against a sale.
	 *
	 * Declared payments are deliberately not counted: until they are confirmed they are a claim,
	 * not money.
	 *
	 * @param int $sale_id Sale id.
	 * @return array { amount, amount_base, declared, declared_base, count }
	 */
	public static function totals( $sale_id ) : array {
		global $wpdb;

		$sale_id = absint( $sale_id );
		$empty   = array(
			'amount'        => 0.0,
			'amount_base'   => 0.0,
			'declared'      => 0.0,
			'declared_base' => 0.0,
			'count'         => 0,
		);
		if ( ! $sale_id ) {
			return $empty;
		}

		$table = self::table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN status = %s THEN amount ELSE 0 END),0) AS amount,
					COALESCE(SUM(CASE WHEN status = %s THEN amount_base ELSE 0 END),0) AS amount_base,
					COALESCE(SUM(CASE WHEN status = %s THEN amount ELSE 0 END),0) AS declared,
					COALESCE(SUM(CASE WHEN status = %s THEN amount_base ELSE 0 END),0) AS declared_base,
					COUNT(*) AS cnt
				FROM {$table} WHERE sale_id = %d",
				self::STATUS_CONFIRMED,
				self::STATUS_CONFIRMED,
				self::STATUS_DECLARED,
				self::STATUS_DECLARED,
				$sale_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'amount'        => round( (float) MJERP_Helpers::array_get( $row, 'amount', 0 ), 4 ),
			'amount_base'   => round( (float) MJERP_Helpers::array_get( $row, 'amount_base', 0 ), 4 ),
			'declared'      => round( (float) MJERP_Helpers::array_get( $row, 'declared', 0 ), 4 ),
			'declared_base' => round( (float) MJERP_Helpers::array_get( $row, 'declared_base', 0 ), 4 ),
			'count'         => (int) MJERP_Helpers::array_get( $row, 'cnt', 0 ),
		);
	}

	/**
	 * How many payments are waiting to be confirmed.
	 *
	 * @return int
	 */
	public static function declared_count() : int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_DECLARED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/* ------------------------------------------------------------------
	 * Write
	 * ---------------------------------------------------------------- */

	/**
	 * Registers a payment against a sale.
	 *
	 * @param array $data sale_id, amount, currency, rate, method, cashbox_id, payment_date,
	 *                    reference, note, status, source.
	 * @return int|WP_Error New payment id.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$sale_id = absint( MJERP_Helpers::array_get( $data, 'sale_id', 0 ) );
		$sale    = $sale_id && class_exists( 'MJERP_Sales_Repo' ) ? MJERP_Sales_Repo::find( $sale_id ) : null;
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404, 'field' => 'sale_id' ) );
		}
		if ( MJERP_Sales_Repo::STATUS_VOID === $sale['status'] ) {
			return new WP_Error( 'mjerp_sale_void', __( 'No se pueden registrar cobros sobre una venta anulada.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		$amount = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'amount', 0 ) ), 4 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'mjerp_invalid_amount', __( 'El importe del cobro tiene que ser mayor a cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'amount' ) );
		}

		$currency = MJERP_Helpers::sanitize_currency( MJERP_Helpers::array_get( $data, 'currency', $sale['currency'] ) );
		$rate     = MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'rate', 0 ) );
		$sale_cur = isset( $sale['currency'] ) ? strtoupper( (string) $sale['currency'] ) : '';
		if ( $rate <= 0 && $currency === $sale_cur && isset( $sale['rate'] ) && (float) $sale['rate'] > 0 ) {
			// Cobrado en la moneda de la venta: vale el TC con el que se calculó su total. Sin esto,
			// un cobro de la tienda sin TC caía en 1 y sumaba pesos como si fueran dólares.
			$rate = (float) $sale['rate'];
		}
		if ( $rate <= 0 ) {
			$rate = MJERP_Sales_Repo::default_rate( $currency );
		}

		$base_currency = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
		$amount_base   = round( MJERP_Helpers::to_base( $amount, $currency, $rate, $base_currency ), 4 );

		$date = MJERP_Helpers::sanitize_date( MJERP_Helpers::array_get( $data, 'payment_date', null ) );
		if ( null === $date ) {
			$date = current_time( 'Y-m-d' );
		}

		$status = self::sanitize_status( MJERP_Helpers::array_get( $data, 'status', self::STATUS_CONFIRMED ) );
		$now    = current_time( 'mysql' );
		$method = self::sanitize_method( MJERP_Helpers::array_get( $data, 'method', '' ) );

		$row = array(
			'sale_id'      => $sale_id,
			'payment_date' => $date,
			'amount'       => $amount,
			'currency'     => $currency,
			'rate'         => round( $rate, 6 ),
			'amount_base'  => $amount_base,
			'method'       => $method,
			'cashbox_id'   => self::resolve_cashbox( MJERP_Helpers::array_get( $data, 'cashbox_id', 0 ), $method ),
			'reference'    => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'reference', '' ), 190 ),
			'note'         => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'note', '' ), 255 ),
			'status'       => $status,
			'source'       => self::sanitize_source( MJERP_Helpers::array_get( $data, 'source', self::SOURCE_ADMIN ) ),
			'created_by'   => get_current_user_id(),
			'confirmed_by' => self::STATUS_CONFIRMED === $status ? get_current_user_id() : 0,
			'confirmed_at' => self::STATUS_CONFIRMED === $status ? $now : null,
			'created_at'   => $now,
			'updated_at'   => $now,
		);

		$result = $wpdb->insert( self::table(), $row, self::formats( $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo registrar el cobro.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;

		MJERP_Sales_Repo::sync_payments( $sale_id );

		/**
		 * Fires after a payment was registered against a sale.
		 *
		 * @param int    $id      Payment id.
		 * @param int    $sale_id Sale id.
		 * @param string $status  declarado | confirmado.
		 */
		do_action( 'mjerp_sale_payment_created', $id, $sale_id, $status );

		return $id;
	}

	/**
	 * Updates a payment.
	 *
	 * @param int   $id   Payment id.
	 * @param array $data Editable fields.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$payment = self::find( $id );
		if ( ! $payment ) {
			return new WP_Error( 'mjerp_not_found', __( 'El cobro no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$row = array();

		if ( array_key_exists( 'amount', $data ) ) {
			$amount = round( MJERP_Helpers::to_float( $data['amount'] ), 4 );
			if ( $amount <= 0 ) {
				return new WP_Error( 'mjerp_invalid_amount', __( 'El importe del cobro tiene que ser mayor a cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'amount' ) );
			}
			$row['amount'] = $amount;
		}
		if ( array_key_exists( 'currency', $data ) ) {
			$row['currency'] = MJERP_Helpers::sanitize_currency( $data['currency'] );
		}
		if ( array_key_exists( 'rate', $data ) ) {
			$rate = MJERP_Helpers::to_float( $data['rate'] );
			if ( $rate > 0 ) {
				$row['rate'] = round( $rate, 6 );
			}
		}
		if ( array_key_exists( 'payment_date', $data ) ) {
			$date = MJERP_Helpers::sanitize_date( $data['payment_date'] );
			if ( $date ) {
				$row['payment_date'] = $date;
			}
		}
		if ( array_key_exists( 'method', $data ) ) {
			$row['method'] = self::sanitize_method( $data['method'] );
		}
		if ( array_key_exists( 'cashbox_id', $data ) ) {
			$method            = isset( $row['method'] ) ? $row['method'] : (string) $payment['method'];
			$row['cashbox_id'] = self::resolve_cashbox( $data['cashbox_id'], $method );
		} elseif ( isset( $row['method'] ) && $row['method'] !== (string) $payment['method'] ) {
			// The method moved and no box was named: the money follows the new method's box.
			$row['cashbox_id'] = self::resolve_cashbox( 0, $row['method'] );
		}
		if ( array_key_exists( 'reference', $data ) ) {
			$row['reference'] = MJERP_Helpers::text( $data['reference'], 190 );
		}
		if ( array_key_exists( 'note', $data ) ) {
			$row['note'] = MJERP_Helpers::text( $data['note'], 255 );
		}

		if ( empty( $row ) ) {
			return true;
		}

		// The base amount is derived, so any change to amount/currency/rate has to redo it.
		$amount   = array_key_exists( 'amount', $row ) ? $row['amount'] : $payment['amount'];
		$currency = array_key_exists( 'currency', $row ) ? $row['currency'] : $payment['currency'];
		$rate     = array_key_exists( 'rate', $row ) ? $row['rate'] : $payment['rate'];
		$row['amount_base'] = round(
			MJERP_Helpers::to_base( $amount, $currency, $rate, strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) ) ),
			4
		);
		$row['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::table(), $row, array( 'id' => $payment['id'] ), self::formats( $row ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar el cobro.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		MJERP_Sales_Repo::sync_payments( (int) $payment['sale_id'] );

		return true;
	}

	/**
	 * Confirms a declared payment (this is when it starts counting).
	 *
	 * @param int $id Payment id.
	 * @return true|WP_Error
	 */
	public static function confirm( $id ) {
		global $wpdb;

		$payment = self::find( $id );
		if ( ! $payment ) {
			return new WP_Error( 'mjerp_not_found', __( 'El cobro no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( self::STATUS_CONFIRMED === $payment['status'] ) {
			return true;
		}

		$now = current_time( 'mysql' );
		$row = array(
			'status'       => self::STATUS_CONFIRMED,
			'confirmed_by' => get_current_user_id(),
			'confirmed_at' => $now,
			'updated_at'   => $now,
		);

		$result = $wpdb->update( self::table(), $row, array( 'id' => $payment['id'] ), self::formats( $row ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo confirmar el cobro.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		MJERP_Sales_Repo::sync_payments( (int) $payment['sale_id'] );

		/**
		 * Fires after a declared payment was confirmed.
		 *
		 * @param int $id      Payment id.
		 * @param int $sale_id Sale id.
		 */
		do_action( 'mjerp_sale_payment_confirmed', (int) $payment['id'], (int) $payment['sale_id'] );

		return true;
	}

	/**
	 * Deletes a payment.
	 *
	 * @param int $id Payment id.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$payment = self::find( $id );
		if ( ! $payment ) {
			return new WP_Error( 'mjerp_not_found', __( 'El cobro no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$result = $wpdb->delete( self::table(), array( 'id' => $payment['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo borrar el cobro.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		MJERP_Sales_Repo::sync_payments( (int) $payment['sale_id'] );

		return true;
	}

	/**
	 * Removes every payment of a sale (used when the sale itself is deleted).
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	public static function delete_for_sale( $sale_id ) : void {
		global $wpdb;
		$sale_id = absint( $sale_id );
		if ( ! $sale_id ) {
			return;
		}
		$wpdb->delete( self::table(), array( 'sale_id' => $sale_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	/**
	 * Status sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_status( $value ) : string {
		return self::STATUS_DECLARED === sanitize_key( (string) $value ) ? self::STATUS_DECLARED : self::STATUS_CONFIRMED;
	}

	/**
	 * Source sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_source( $value ) : string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( self::SOURCE_ADMIN, self::SOURCE_PORTAL, self::SOURCE_SHOP ), true ) ? $value : self::SOURCE_ADMIN;
	}

	/**
	 * Payment method sanitizer: unknown keys fall back to the configured default.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_method( $value ) : string {
		$value = sanitize_key( (string) $value );
		$keys  = wp_list_pluck( MJERP_Settings::sale_payment_methods(), 'key' );
		if ( in_array( $value, $keys, true ) ) {
			return $value;
		}
		$default = (string) MJERP_Settings::get( 'sales_default_payment_method', 'efectivo' );
		return in_array( $default, $keys, true ) ? $default : (string) reset( $keys );
	}

	/**
	 * Cashbox of a collection: the one asked for, or the one the payment method points at.
	 *
	 * Every path that records a collection goes through here — the sales screen, the REST API and
	 * the WooCommerce mirror — so "transfers land in the transfer box" is decided once, in the
	 * settings, and holds everywhere without any caller having to remember it.
	 *
	 * @param mixed  $requested Explicit cashbox id (0/empty = decide from the method).
	 * @param string $method    Payment method key.
	 * @return int
	 */
	public static function resolve_cashbox( $requested, $method ) : int {
		$requested = absint( $requested );
		if ( $requested > 0 ) {
			return $requested;
		}
		if ( ! is_callable( array( 'MJERP_Settings', 'cashbox_for_method' ) ) ) {
			return 0;
		}
		return absint( MJERP_Settings::cashbox_for_method( $method, 'sales' ) );
	}

	/**
	 * Row → API shape.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private static function cast( array $row ) : array {
		$status = (string) self::v( $row, 'status', self::STATUS_CONFIRMED );

		return array(
			'id'            => (int) $row['id'],
			'sale_id'       => (int) self::v( $row, 'sale_id', 0 ),
			'payment_date'  => self::date_or_null( self::v( $row, 'payment_date', null ) ),
			'amount'        => round( (float) self::v( $row, 'amount', 0 ), 4 ),
			'currency'      => strtoupper( (string) self::v( $row, 'currency', 'ARS' ) ),
			'rate'          => round( (float) self::v( $row, 'rate', 1 ), 6 ),
			'amount_base'   => round( (float) self::v( $row, 'amount_base', 0 ), 4 ),
			'method'        => (string) self::v( $row, 'method' ),
			'method_label'  => MJERP_Settings::sale_payment_method_label( self::v( $row, 'method' ) ),
			'cashbox_id'    => (int) self::v( $row, 'cashbox_id', 0 ),
			'reference'     => (string) self::v( $row, 'reference' ),
			'note'          => (string) self::v( $row, 'note' ),
			'status'        => $status,
			'status_label'  => self::STATUS_DECLARED === $status ? __( 'A confirmar', 'mj-erp' ) : __( 'Confirmado', 'mj-erp' ),
			'is_declared'   => self::STATUS_DECLARED === $status,
			'source'        => (string) self::v( $row, 'source', self::SOURCE_ADMIN ),
			'created_by'    => (int) self::v( $row, 'created_by', 0 ),
			'created_by_name' => MJERP_Helpers::user_display_name( self::v( $row, 'created_by', 0 ) ),
			'confirmed_by'  => (int) self::v( $row, 'confirmed_by', 0 ),
			'confirmed_at'  => self::datetime_or_null( self::v( $row, 'confirmed_at' ) ),
			'created_at'    => self::datetime_or_null( self::v( $row, 'created_at' ) ),
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

	private static function date_or_null( $value ) : ?string {
		if ( null === $value ) {
			return null;
		}
		$date = MJERP_Helpers::sanitize_date( $value );
		return $date ? $date : null;
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
