<?php
defined( 'ABSPATH' ) || exit;

/**
 * Saldo a favor de los clientes: el libro de movimientos.
 *
 * El saldo no se guarda en ningún lado: es la suma de los movimientos de cada moneda. Cada fila dice
 * cuánto entró o salió (con signo), en qué moneda y por qué, así el saldo de hoy se explica línea por
 * línea, y un error se corrige con otro movimiento en vez de pisar uno viejo.
 *
 * La moneda es la del pago que lo generó y no se convierte nunca: un saldo en pesos sigue en pesos
 * aunque el dólar se mueva. Es la regla de la casa para el saldo a favor, distinta de la de las deudas.
 *
 * @package MJ_ERP
 */
class MJERP_Customer_Credit_Repo {

	/** Lo que se le reconoce al cliente por una devolución o por anular una venta cobrada. */
	const KIND_RETURN = 'devolucion';

	/** Un movimiento a mano, con motivo: sumar o restar. */
	const KIND_ADJUST = 'ajuste';

	/** Pagó con saldo, en una venta del ERP o en un pedido de la tienda. */
	const KIND_USE = 'uso';

	/** Deshace otro movimiento: un cobro con saldo que se borró, un pedido que se canceló. */
	const KIND_REVERSAL = 'reverso';

	/** Se le devolvió la plata: sale de una caja. */
	const KIND_PAYOUT = 'reintegro';

	const EPSILON = 0.0001;

	const FORMATS = array(
		'customer_id' => '%d',
		'currency'    => '%s',
		'amount'      => '%f',
		'kind'        => '%s',
		'sale_id'     => '%d',
		'order_id'    => '%d',
		'payment_id'  => '%d',
		'return_id'   => '%d',
		'reverses_id' => '%d',
		'rate'        => '%f',
		'method'      => '%s',
		'cashbox_id'  => '%d',
		'reference'   => '%s',
		'reason'      => '%s',
		'note'        => '%s',
		'created_by'  => '%d',
		'created_at'  => '%s',
	);

	/**
	 * Movement kinds.
	 *
	 * @return string[]
	 */
	public static function kinds() : array {
		return array( self::KIND_RETURN, self::KIND_ADJUST, self::KIND_USE, self::KIND_REVERSAL, self::KIND_PAYOUT );
	}

	private static function table() : string {
		return MJERP_Install::table( 'customer_credit' );
	}

	/* ------------------------------------------------------------------
	 * Write
	 * ---------------------------------------------------------------- */

	/**
	 * Anota un movimiento. No controla que alcance el saldo: eso lo decide MJERP_Credit, que sabe
	 * cuándo un movimiento puede dejarlo en negativo (nunca, salvo al deshacer otro).
	 *
	 * @param array $data customer_id, currency, amount (con signo), kind, sale_id, order_id, payment_id,
	 *                    return_id, reverses_id, rate, method, cashbox_id, reference, reason, note.
	 * @return int|WP_Error New id.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$customer_id = absint( MJERP_Helpers::array_get( $data, 'customer_id', 0 ) );
		if ( $customer_id <= 0 ) {
			return new WP_Error( 'mjerp_credit_no_customer', __( 'El saldo a favor es de la ficha de un cliente, y esta venta no tiene.', 'mj-erp' ), array( 'status' => 400, 'field' => 'customer_id' ) );
		}

		$currency = strtoupper( (string) MJERP_Helpers::array_get( $data, 'currency', '' ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return new WP_Error( 'mjerp_invalid_currency', __( 'La moneda del saldo es inválida.', 'mj-erp' ), array( 'status' => 400, 'field' => 'currency' ) );
		}

		$amount = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'amount', 0 ) ), 4 );
		if ( abs( $amount ) < self::EPSILON ) {
			return new WP_Error( 'mjerp_invalid_amount', __( 'El importe no puede ser cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'amount' ) );
		}

		$kind = sanitize_key( (string) MJERP_Helpers::array_get( $data, 'kind', '' ) );
		if ( ! in_array( $kind, self::kinds(), true ) ) {
			return new WP_Error( 'mjerp_invalid_kind', __( 'Tipo de movimiento del saldo inválido.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		$rate = MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'rate', 0 ) );

		$row = array(
			'customer_id' => $customer_id,
			'currency'    => $currency,
			'amount'      => $amount,
			'kind'        => $kind,
			'sale_id'     => absint( MJERP_Helpers::array_get( $data, 'sale_id', 0 ) ),
			'order_id'    => absint( MJERP_Helpers::array_get( $data, 'order_id', 0 ) ),
			'payment_id'  => absint( MJERP_Helpers::array_get( $data, 'payment_id', 0 ) ),
			'return_id'   => absint( MJERP_Helpers::array_get( $data, 'return_id', 0 ) ),
			'reverses_id' => absint( MJERP_Helpers::array_get( $data, 'reverses_id', 0 ) ),
			'rate'        => $rate > 0 ? round( $rate, 6 ) : 0,
			'method'      => sanitize_key( (string) MJERP_Helpers::array_get( $data, 'method', '' ) ),
			'cashbox_id'  => absint( MJERP_Helpers::array_get( $data, 'cashbox_id', 0 ) ),
			'reference'   => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'reference', '' ), 190 ),
			'reason'      => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'reason', '' ), 255 ),
			'note'        => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'note', '' ), 255 ),
			'created_by'  => get_current_user_id(),
			'created_at'  => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( self::table(), $row, self::formats( $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo anotar el movimiento del saldo a favor.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a customer credit movement was stored.
		 *
		 * @param int   $id  Movement id.
		 * @param array $row Stored row.
		 */
		do_action( 'mjerp_customer_credit_added', $id, $row );

		return $id;
	}

	/**
	 * Points a movement at the sale payment that carries it (a shop order paid with credit gets its
	 * sale after the credit was already taken).
	 *
	 * @param int $id         Movement id.
	 * @param int $payment_id Sale payment id.
	 * @param int $sale_id    Sale id.
	 * @return void
	 */
	public static function set_payment( $id, $payment_id, $sale_id ) : void {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'payment_id' => absint( $payment_id ),
				'sale_id'    => absint( $sale_id ),
			),
			array( 'id' => $id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	/* ------------------------------------------------------------------
	 * Read
	 * ---------------------------------------------------------------- */

	/**
	 * One movement.
	 *
	 * @param int $id Movement id.
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
	 * Balance of a customer, per currency (currencies at zero are left out).
	 *
	 * @param int $customer_id Customer id.
	 * @return array currency => amount
	 */
	public static function balances( $customer_id ) : array {
		global $wpdb;

		$customer_id = absint( $customer_id );
		if ( ! $customer_id ) {
			return array();
		}

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT currency, SUM(amount) AS amount FROM {$table} WHERE customer_id = %d GROUP BY currency", $customer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$out = array();
		foreach ( (array) $rows as $row ) {
			$amount = round( (float) $row['amount'], 4 );
			if ( abs( $amount ) >= 0.005 ) {
				$out[ strtoupper( (string) $row['currency'] ) ] = $amount;
			}
		}
		return $out;
	}

	/**
	 * Balance of a customer in one currency.
	 *
	 * @param int    $customer_id Customer id.
	 * @param string $currency    Currency.
	 * @return float
	 */
	public static function balance( $customer_id, string $currency ) : float {
		$balances = self::balances( $customer_id );
		$currency = strtoupper( $currency );
		return isset( $balances[ $currency ] ) ? (float) $balances[ $currency ] : 0.0;
	}

	/**
	 * Balances of several customers in one query (for the customers listing).
	 *
	 * @param int[] $customer_ids Customer ids.
	 * @return array customer_id => [ currency => amount ]
	 */
	public static function balances_for( array $customer_ids ) : array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $customer_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT customer_id, currency, SUM(amount) AS amount FROM {$table} WHERE customer_id IN ({$placeholders}) GROUP BY customer_id, currency", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Un %d por id.
				$ids
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$amount = round( (float) $row['amount'], 4 );
			if ( abs( $amount ) >= 0.005 ) {
				$out[ (int) $row['customer_id'] ][ strtoupper( (string) $row['currency'] ) ] = $amount;
			}
		}
		return $out;
	}

	/**
	 * Movements of a customer, newest first, each with the balance its currency had right after it.
	 *
	 * @param int $customer_id Customer id.
	 * @param int $limit       Max rows.
	 * @return array
	 */
	public static function movements( $customer_id, int $limit = 200 ) : array {
		global $wpdb;

		$customer_id = absint( $customer_id );
		if ( ! $customer_id ) {
			return array();
		}

		$table = self::table();
		$sales = MJERP_Install::table( 'sales' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT c.*, s.code AS sale_code FROM {$table} c LEFT JOIN {$sales} s ON s.id = c.sale_id WHERE c.customer_id = %d ORDER BY c.created_at ASC, c.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$customer_id
			),
			ARRAY_A
		);

		$running = array();
		$out     = array();
		foreach ( (array) $rows as $row ) {
			$item     = self::cast( $row );
			$currency = $item['currency'];
			$running[ $currency ] = ( isset( $running[ $currency ] ) ? $running[ $currency ] : 0.0 ) + $item['amount'];
			$item['balance']      = round( $running[ $currency ], 4 );
			$out[]                = $item;
		}

		/**
		 * Filters how many credit movements of a customer are returned.
		 *
		 * @param int $limit       Max rows.
		 * @param int $customer_id Customer id.
		 */
		$limit = max( 1, (int) apply_filters( 'mjerp_customer_credit_movements_limit', $limit, $customer_id ) );

		return array_slice( array_reverse( $out ), 0, $limit );
	}

	/**
	 * Uses of credit that were not undone yet, by payment, sale or order.
	 *
	 * @param string $column payment_id | sale_id | order_id.
	 * @param int    $value  Id.
	 * @return array
	 */
	public static function open_uses( string $column, $value ) : array {
		global $wpdb;

		$value = absint( $value );
		if ( $value <= 0 || ! in_array( $column, array( 'payment_id', 'sale_id', 'order_id' ), true ) ) {
			return array();
		}

		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE kind = %s AND {$column} = %d AND id NOT IN (SELECT reverses_id FROM {$table} WHERE reverses_id > 0) ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::KIND_USE,
				$value
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row );
		}
		return $out;
	}

	/**
	 * Movements tied to a sale return.
	 *
	 * @param int $return_id Return id.
	 * @return array
	 */
	public static function for_return( $return_id ) : array {
		global $wpdb;

		$return_id = absint( $return_id );
		if ( ! $return_id ) {
			return array();
		}

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE return_id = %d ORDER BY id ASC", $return_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row );
		}
		return $out;
	}

	/**
	 * Whether a movement was already undone.
	 *
	 * @param int $id Movement id.
	 * @return bool
	 */
	public static function is_reversed( $id ) : bool {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE reverses_id = %d", $id ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/* ------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	/**
	 * Label of a movement kind.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function kind_label( string $kind ) : string {
		$labels = array(
			self::KIND_RETURN   => __( 'Devolución', 'mj-erp' ),
			self::KIND_ADJUST   => __( 'Ajuste', 'mj-erp' ),
			self::KIND_USE      => __( 'Uso', 'mj-erp' ),
			self::KIND_REVERSAL => __( 'Deshecho', 'mj-erp' ),
			self::KIND_PAYOUT   => __( 'Se le devolvió la plata', 'mj-erp' ),
		);
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind;
	}

	/**
	 * The movement in words.
	 *
	 * Con `$public` queda afuera lo interno —el motivo de un ajuste, el medio con que se devolvió la
	 * plata—: es lo que ve el cliente en «Mi cuenta».
	 *
	 * @param array $entry  Cast movement.
	 * @param bool  $public Whether the customer reads it.
	 * @return string
	 */
	public static function describe( array $entry, bool $public = false ) : string {
		$sale  = '' !== (string) $entry['sale_code'] ? (string) $entry['sale_code'] : ( $entry['sale_id'] ? '#' . $entry['sale_id'] : '' );
		$order = $entry['order_id'] ? '#' . $entry['order_id'] : '';

		switch ( $entry['kind'] ) {
			case self::KIND_RETURN:
				return '' !== $sale
					/* translators: %s: sale code */
					? sprintf( __( 'Devolución de la compra %s', 'mj-erp' ), $sale )
					: __( 'Devolución', 'mj-erp' );

			case self::KIND_ADJUST:
				return ( $public || '' === (string) $entry['reason'] )
					? __( 'Ajuste de saldo', 'mj-erp' )
					/* translators: %s: reason */
					: sprintf( __( 'Ajuste: %s', 'mj-erp' ), $entry['reason'] );

			case self::KIND_USE:
				if ( '' !== $order ) {
					/* translators: %s: order number */
					return sprintf( __( 'Usado en el pedido %s', 'mj-erp' ), $order );
				}
				return '' !== $sale
					/* translators: %s: sale code */
					? sprintf( __( 'Usado en la compra %s', 'mj-erp' ), $sale )
					: __( 'Usado en una compra', 'mj-erp' );

			case self::KIND_REVERSAL:
				if ( $entry['return_id'] ) {
					return __( 'Se deshizo una devolución', 'mj-erp' );
				}
				if ( '' !== $order ) {
					/* translators: %s: order number */
					return sprintf( __( 'Vuelve al saldo: se canceló el pedido %s', 'mj-erp' ), $order );
				}
				return '' !== $sale
					/* translators: %s: sale code */
					? sprintf( __( 'Vuelve al saldo: se deshizo el cobro de la compra %s', 'mj-erp' ), $sale )
					: __( 'Vuelve al saldo', 'mj-erp' );

			case self::KIND_PAYOUT:
				if ( $public ) {
					return __( 'Te devolvimos la plata', 'mj-erp' );
				}
				return '' !== (string) $entry['method_label']
					/* translators: %s: payment method */
					? sprintf( __( 'Se le devolvió la plata (%s)', 'mj-erp' ), $entry['method_label'] )
					: __( 'Se le devolvió la plata', 'mj-erp' );
		}

		return self::kind_label( (string) $entry['kind'] );
	}

	/**
	 * Row → API shape.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	public static function cast( array $row ) : array {
		$method = (string) self::v( $row, 'method' );
		$rate   = (float) self::v( $row, 'rate', 0 );

		$out = array(
			'id'              => (int) $row['id'],
			'customer_id'     => (int) self::v( $row, 'customer_id', 0 ),
			'currency'        => strtoupper( (string) self::v( $row, 'currency' ) ),
			'amount'          => round( (float) self::v( $row, 'amount', 0 ), 4 ),
			'kind'            => (string) self::v( $row, 'kind' ),
			'kind_label'      => self::kind_label( (string) self::v( $row, 'kind' ) ),
			'sale_id'         => (int) self::v( $row, 'sale_id', 0 ),
			'sale_code'       => (string) self::v( $row, 'sale_code' ),
			'order_id'        => (int) self::v( $row, 'order_id', 0 ),
			'payment_id'      => (int) self::v( $row, 'payment_id', 0 ),
			'return_id'       => (int) self::v( $row, 'return_id', 0 ),
			'reverses_id'     => (int) self::v( $row, 'reverses_id', 0 ),
			'rate'            => $rate > 0 ? round( $rate, 6 ) : null,
			'method'          => $method,
			'method_label'    => '' !== $method ? MJERP_Settings::sale_payment_method_label( $method ) : '',
			'cashbox_id'      => (int) self::v( $row, 'cashbox_id', 0 ),
			'reference'       => (string) self::v( $row, 'reference' ),
			'reason'          => (string) self::v( $row, 'reason' ),
			'note'            => (string) self::v( $row, 'note' ),
			'created_by'      => (int) self::v( $row, 'created_by', 0 ),
			'created_by_name' => MJERP_Helpers::user_display_name( self::v( $row, 'created_by', 0 ) ),
			'created_at'      => (string) self::v( $row, 'created_at' ),
		);

		$out['description'] = self::describe( $out );

		return $out;
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
}
