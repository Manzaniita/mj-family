<?php
defined( 'ABSPATH' ) || exit;

/**
 * Consignment payments and what they pay for.
 *
 * The rule this module is built around: money never floats. Every payment is broken down into
 * allocations against concrete sales, so any peso that came in can be traced to the thing it paid.
 * A payment is stored with its own currency and exchange rate, but it is applied in base currency
 * (USD), which is the currency the debt was agreed in.
 *
 *   payment  US$ 50  →  allocation  US$ 36  →  sale #12
 *                    →  allocation  US$ 14  →  sale #18   (partial)
 *
 * Two deliberate exceptions, both explicit and both leaving a trace:
 *   - Leftover money (the reseller paid more than they owed) stays as `unallocated_base`, a credit
 *     on account that later payments planning will not touch — it is visible, not lost.
 *   - `kind = ajuste` is the manual escape hatch (writing off a debt, charging a broken unit). It
 *     requires a reason, always.
 *
 * @package MJ_ERP
 */
class MJERP_Consignment_Payments_Repo {

	const KIND_PAYMENT    = 'pago';
	const KIND_ADJUSTMENT = 'ajuste';

	/**
	 * A payment loaded by the reseller is a claim until the shop owner confirms it: it shows up in
	 * the ERP right away, but it does not move the balance and it is not allocated to any sale.
	 */
	const STATUS_DECLARED  = 'declarado';
	const STATUS_CONFIRMED = 'confirmado';

	const SOURCE_ADMIN  = 'admin';
	const SOURCE_PORTAL = 'portal';

	/** Amounts are stored with 4 decimals; anything under this is a rounding artifact. */
	const EPSILON = 0.0001;

	const FORMATS = array(
		'consignee_id'   => '%d',
		'kind'           => '%s',
		'payment_date'   => '%s',
		'amount'         => '%f',
		'currency'       => '%s',
		'rate'           => '%f',
		'amount_base'    => '%f',
		'allocated_base' => '%f',
		'method'         => '%s',
		'cashbox_id'     => '%d',
		'reference'      => '%s',
		'reason'         => '%s',
		'note'           => '%s',
		'status'         => '%s',
		'source'         => '%s',
		'sale_id'        => '%d',
		'created_by'     => '%d',
		'confirmed_by'   => '%d',
		'confirmed_at'   => '%s',
		'created_at'     => '%s',
		'updated_at'     => '%s',
	);

	const ALLOC_FORMATS = array(
		'payment_id'   => '%d',
		'sale_id'      => '%d',
		'consignee_id' => '%d',
		'amount_base'  => '%f',
		'is_manual'    => '%d',
		'reason'       => '%s',
		'created_at'   => '%s',
	);

	public static function kinds() : array {
		return array( self::KIND_PAYMENT, self::KIND_ADJUSTMENT );
	}

	private static function table() : string {
		return MJERP_Install::table( 'consignment_payments' );
	}

	private static function allocations_table() : string {
		return MJERP_Install::table( 'consignment_allocations' );
	}

	/* ------------------------------------------------------------------
	 * Read
	 * ---------------------------------------------------------------- */

	/**
	 * Paginated list of payments.
	 *
	 * @param array $args consignee_id, kind, date_from, date_to, page, per_page, order.
	 * @return array { items, total, pages, page, per_page }
	 */
	public static function query( array $args ) : array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'consignee_id' => 0,
				'kind'         => '',
				'status'       => '',
				'date_from'    => '',
				'date_to'      => '',
				'page'         => 1,
				'per_page'     => 20,
				'order'        => 'DESC',
			)
		);

		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		$table = self::table();
		$where = array( '1=1' );
		$prep  = array();

		$consignee_id = absint( $args['consignee_id'] );
		if ( $consignee_id > 0 ) {
			$where[] = 'consignee_id = %d';
			$prep[]  = $consignee_id;
		}

		$status = sanitize_key( (string) $args['status'] );
		if ( in_array( $status, array( self::STATUS_DECLARED, self::STATUS_CONFIRMED ), true ) ) {
			$where[] = 'status = %s';
			$prep[]  = $status;
		}

		$kind = sanitize_key( (string) $args['kind'] );
		if ( '' !== $kind && in_array( $kind, self::kinds(), true ) ) {
			$where[] = 'kind = %s';
			$prep[]  = $kind;
		}

		$from = MJERP_Helpers::sanitize_date( $args['date_from'] );
		if ( $from ) {
			$where[] = 'payment_date >= %s';
			$prep[]  = $from;
		}
		$to = MJERP_Helpers::sanitize_date( $args['date_to'] );
		if ( $to ) {
			$where[] = 'payment_date <= %s';
			$prep[]  = $to;
		}

		$order     = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $prep ? $wpdb->get_var( $wpdb->prepare( $count_sql, $prep ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY payment_date {$order}, id {$order} LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $prep, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$items = array();
		foreach ( (array) $rows as $row ) {
			$payment                = self::cast( $row );
			$payment['allocations'] = self::allocations( (int) $payment['id'] );
			$items[]                = $payment;
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'pages'    => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Single payment with its allocations.
	 *
	 * @param int $id Payment id.
	 * @return array|null
	 */
	public static function find( $id ) : ?array {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		$payment                = self::cast( $row );
		$payment['allocations'] = self::allocations( (int) $payment['id'] );
		return $payment;
	}

	/**
	 * Allocations of a payment, decorated with the sale they point at.
	 *
	 * @param int $payment_id Payment id.
	 * @return array
	 */
	public static function allocations( $payment_id ) : array {
		global $wpdb;

		$payment_id = absint( $payment_id );
		if ( $payment_id <= 0 ) {
			return array();
		}

		$allocations = self::allocations_table();
		$sales       = MJERP_Install::table( 'consignment_sales' );

		$sql = $wpdb->prepare(
			"SELECT a.*, s.name AS sale_name, s.sku AS sale_sku, s.sale_date, s.qty AS sale_qty, s.amount_base AS sale_amount_base
			 FROM {$allocations} a
			 LEFT JOIN {$sales} s ON s.id = a.sale_id
			 WHERE a.payment_id = %d
			 ORDER BY s.sale_date ASC, a.id ASC",
			$payment_id
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'               => (int) $row['id'],
				'payment_id'       => (int) $row['payment_id'],
				'sale_id'          => (int) $row['sale_id'],
				'amount_base'      => round( (float) $row['amount_base'], 4 ),
				'is_manual'        => (bool) (int) $row['is_manual'],
				'reason'           => (string) ( isset( $row['reason'] ) ? $row['reason'] : '' ),
				'sale_name'        => (string) ( isset( $row['sale_name'] ) ? $row['sale_name'] : '' ),
				'sale_sku'         => (string) ( isset( $row['sale_sku'] ) ? $row['sale_sku'] : '' ),
				'sale_date'        => isset( $row['sale_date'] ) && $row['sale_date'] ? substr( (string) $row['sale_date'], 0, 10 ) : null,
				'sale_qty'         => isset( $row['sale_qty'] ) ? round( (float) $row['sale_qty'], 4 ) : null,
				'sale_amount_base' => isset( $row['sale_amount_base'] ) ? round( (float) $row['sale_amount_base'], 4 ) : null,
			);
		}

		return $out;
	}

	/**
	 * Allocations pointing at a sale (so the sale can show who paid it and when).
	 *
	 * @param int $sale_id Sale id.
	 * @return array
	 */
	public static function allocations_for_sale( $sale_id ) : array {
		global $wpdb;

		$sale_id = absint( $sale_id );
		if ( $sale_id <= 0 ) {
			return array();
		}

		$allocations = self::allocations_table();
		$payments    = self::table();

		$sql = $wpdb->prepare(
			"SELECT a.*, p.payment_date, p.kind, p.method, p.currency, p.amount, p.reference
			 FROM {$allocations} a
			 INNER JOIN {$payments} p ON p.id = a.payment_id
			 WHERE a.sale_id = %d
			 ORDER BY p.payment_date ASC, a.id ASC",
			$sale_id
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'           => (int) $row['id'],
				'payment_id'   => (int) $row['payment_id'],
				'amount_base'  => round( (float) $row['amount_base'], 4 ),
				'is_manual'    => (bool) (int) $row['is_manual'],
				'reason'       => (string) ( isset( $row['reason'] ) ? $row['reason'] : '' ),
				'payment_date' => isset( $row['payment_date'] ) && $row['payment_date'] ? substr( (string) $row['payment_date'], 0, 10 ) : null,
				'kind'         => (string) ( isset( $row['kind'] ) ? $row['kind'] : self::KIND_PAYMENT ),
				'method'       => (string) ( isset( $row['method'] ) ? $row['method'] : '' ),
				'method_label' => MJERP_Settings::payment_method_label( isset( $row['method'] ) ? $row['method'] : '' ),
				'currency'     => (string) ( isset( $row['currency'] ) ? $row['currency'] : '' ),
				'amount'       => isset( $row['amount'] ) ? round( (float) $row['amount'], 4 ) : null,
				'reference'    => (string) ( isset( $row['reference'] ) ? $row['reference'] : '' ),
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Allocation planning
	 * ---------------------------------------------------------------- */

	/**
	 * Plans how an amount would be applied: oldest outstanding sale first.
	 *
	 * Side-effect free, so the UI can preview exactly what the server will do before confirming.
	 * The mirror of this lives in assets/js/page-consignee.js.
	 *
	 * @param int   $consignee_id Consignee id.
	 * @param float $amount_base  Amount in base currency.
	 * @param array $skip_sales   sale_id => amount already covered elsewhere in this operation.
	 * @return array { lines: [ {sale_id, amount_base, sale} ], allocated: float, unallocated: float }
	 */
	public static function plan_fifo( $consignee_id, $amount_base, array $skip_sales = array() ) : array {
		return self::allocate( MJERP_Consignment_Sales_Repo::outstanding( $consignee_id ), $amount_base, $skip_sales );
	}

	/**
	 * The allocation algorithm itself: walk the sales in order, take as much as each still needs
	 * until the money runs out.
	 *
	 * Pure — no database, no WordPress — so it can be exercised from the CLI and mirrored exactly in
	 * assets/js/page-consignee.js, which is what lets the UI preview what the server will do.
	 *
	 * @param array $sales       Sales, already in the order they should be paid (oldest first).
	 *                           Each row needs `id` and `balance_base`.
	 * @param mixed $amount_base Amount available, in base currency.
	 * @param array $skip_sales  sale_id => amount already covered elsewhere in this operation.
	 * @return array { lines: [ {sale_id, amount_base, sale} ], allocated: float, unallocated: float }
	 */
	public static function allocate( array $sales, $amount_base, array $skip_sales = array() ) : array {
		$remaining = round( MJERP_Helpers::to_float( $amount_base ), 4 );
		$lines     = array();
		$allocated = 0.0;

		if ( $remaining > self::EPSILON ) {
			foreach ( $sales as $sale ) {
				if ( $remaining <= self::EPSILON ) {
					break;
				}
				$sale_id = isset( $sale['id'] ) ? (int) $sale['id'] : 0;
				$balance = isset( $sale['balance_base'] ) ? (float) $sale['balance_base'] : 0.0;

				if ( isset( $skip_sales[ $sale_id ] ) ) {
					$balance -= (float) $skip_sales[ $sale_id ];
				}
				if ( $sale_id <= 0 || $balance <= self::EPSILON ) {
					continue;
				}

				$take = round( min( $remaining, $balance ), 4 );
				if ( $take <= self::EPSILON ) {
					continue;
				}

				$lines[] = array(
					'sale_id'     => $sale_id,
					'amount_base' => $take,
					'sale'        => $sale,
				);
				$allocated += $take;
				$remaining  = round( $remaining - $take, 4 );
			}
		}

		return array(
			'lines'       => $lines,
			'allocated'   => round( $allocated, 4 ),
			'unallocated' => round( max( 0, $remaining ), 4 ),
		);
	}

	/* ------------------------------------------------------------------
	 * Write
	 * ---------------------------------------------------------------- */

	/**
	 * Registers a payment (or a manual adjustment) and applies it to sales.
	 *
	 * @param array $data consignee_id, kind, amount, currency, rate, payment_date, method,
	 *                    cashbox_id, reference, reason, note, allocations (explicit override).
	 * @return int|WP_Error New payment id.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$consignee = MJERP_Consignees::find( isset( $data['consignee_id'] ) ? $data['consignee_id'] : 0 );
		if ( ! $consignee ) {
			return new WP_Error( 'mjerp_invalid_consignee', __( 'Elegí un revendedor válido.', 'mj-erp' ), array( 'status' => 400, 'field' => 'consignee_id' ) );
		}

		$row = self::prepare( $data, null );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$status = self::sanitize_status( isset( $data['status'] ) ? $data['status'] : self::STATUS_CONFIRMED );
		$now    = current_time( 'mysql' );

		$row['consignee_id'] = (int) $consignee['id'];
		$row['allocated_base'] = 0;
		$row['status']       = $status;
		$row['source']       = self::sanitize_source( isset( $data['source'] ) ? $data['source'] : self::SOURCE_ADMIN );
		$row['sale_id']      = isset( $data['sale_id'] ) ? absint( $data['sale_id'] ) : 0;
		$row['created_by']   = get_current_user_id();
		$row['confirmed_by'] = self::STATUS_CONFIRMED === $status ? get_current_user_id() : 0;
		$row['confirmed_at'] = self::STATUS_CONFIRMED === $status ? $now : null;
		$row['created_at']   = $now;
		$row['updated_at']   = $now;

		$result = $wpdb->insert( self::table(), $row, self::formats( $row, self::FORMATS ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo registrar el pago.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;

		// A declared payment is not allocated to anything yet: it would be cancelling debt with
		// money nobody has confirmed receiving.
		$applied = array();
		if ( self::STATUS_CONFIRMED === $status ) {
			$applied = self::apply_allocations( $id, isset( $data['allocations'] ) ? $data['allocations'] : null );
			if ( is_wp_error( $applied ) ) {
				// The payment row would otherwise survive with no explanation of what it paid.
				self::clear_allocations( $id );
				$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
				return $applied;
			}
		}

		/**
		 * Fires after a consignment payment was registered.
		 *
		 * @param int   $id      Payment id.
		 * @param array $applied Allocation summary.
		 */
		do_action( 'mjerp_consignment_payment_created', $id, $applied );

		return $id;
	}

	/**
	 * Confirms a payment a reseller declared: this is the moment it becomes money and gets applied
	 * to their outstanding sales.
	 *
	 * @param int        $id          Payment id.
	 * @param array|null $allocations Explicit allocations, or null for the configured strategy.
	 * @return true|WP_Error
	 */
	public static function confirm( $id, $allocations = null ) {
		global $wpdb;

		$payment = self::find( $id );
		if ( ! $payment ) {
			return new WP_Error( 'mjerp_not_found', __( 'El pago no existe.', 'mj-erp' ), array( 'status' => 404 ) );
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

		$result = $wpdb->update( self::table(), $row, array( 'id' => $payment['id'] ), self::formats( $row, self::FORMATS ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo confirmar el pago.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$applied = self::apply_allocations( (int) $payment['id'], $allocations );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		/**
		 * Fires after a declared payment was confirmed.
		 *
		 * @param int   $id      Payment id.
		 * @param array $applied Allocation summary.
		 */
		do_action( 'mjerp_consignment_payment_confirmed', (int) $payment['id'], $applied );

		return true;
	}

	/**
	 * How many payments are waiting to be confirmed.
	 *
	 * @param int $consignee_id Consignee id (0 = every reseller).
	 * @return int
	 */
	public static function declared_count( $consignee_id = 0 ) : int {
		global $wpdb;

		$table        = self::table();
		$consignee_id = absint( $consignee_id );

		if ( $consignee_id > 0 ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND consignee_id = %d", self::STATUS_DECLARED, $consignee_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_DECLARED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

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
		return self::SOURCE_PORTAL === sanitize_key( (string) $value ) ? self::SOURCE_PORTAL : self::SOURCE_ADMIN;
	}

	/**
	 * Updates a payment and re-applies it.
	 *
	 * @param int   $id   Payment id.
	 * @param array $data Fields (plus optional `allocations`).
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'El pago no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$row = self::prepare( $data, $existing );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$reallocate = array_key_exists( 'allocations', $data ) || isset( $row['amount_base'] );

		if ( ! empty( $row ) ) {
			$row['updated_at'] = current_time( 'mysql' );
			$result            = $wpdb->update( self::table(), $row, array( 'id' => $existing['id'] ), self::formats( $row, self::FORMATS ), array( '%d' ) );
			if ( false === $result ) {
				return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar el pago.', 'mj-erp' ), array( 'status' => 500 ) );
			}
		}

		if ( $reallocate ) {
			$applied = self::apply_allocations( (int) $existing['id'], isset( $data['allocations'] ) ? $data['allocations'] : null );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
		}

		return true;
	}

	/**
	 * Deletes a payment and releases everything it was paying for.
	 *
	 * @param int $id Payment id.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'El pago no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		self::clear_allocations( (int) $existing['id'] );

		$result = $wpdb->delete( self::table(), array( 'id' => $existing['id'] ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar el pago.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Allocations
	 * ---------------------------------------------------------------- */

	/**
	 * Rewrites the allocations of a payment: drops the old ones, writes the new set, refreshes the
	 * cached totals on both sides. Always the whole set, never a partial edit, so the sum of the
	 * allocations can never drift away from the payment.
	 *
	 * @param int        $payment_id Payment id.
	 * @param array|null $explicit   Explicit [ {sale_id, amount_base, reason} ]; null = plan FIFO.
	 * @return array|WP_Error { allocated, unallocated, lines }
	 */
	public static function apply_allocations( $payment_id, $explicit = null ) {
		global $wpdb;

		$payment = self::find( $payment_id );
		if ( ! $payment ) {
			return new WP_Error( 'mjerp_not_found', __( 'El pago no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		// Release first: the sale balances used for planning must not count this payment's old rows.
		self::clear_allocations( (int) $payment['id'] );

		$amount_base = round( (float) $payment['amount_base'], 4 );

		// A negative adjustment is a charge, not money coming in: it has nothing to apply.
		if ( $amount_base <= self::EPSILON ) {
			self::sync_payment_allocated( (int) $payment['id'] );
			return array(
				'allocated'   => 0.0,
				'unallocated' => 0.0,
				'lines'       => array(),
			);
		}

		$lines = array();

		if ( is_array( $explicit ) ) {
			$prepared = self::prepare_explicit( $explicit, $payment, $amount_base );
			if ( is_wp_error( $prepared ) ) {
				// Put back what was there so a rejected edit does not silently un-apply the payment.
				self::restore_allocations( $payment );
				return $prepared;
			}
			$lines = $prepared;
		} else {
			$mode = (string) MJERP_Settings::get( 'consignment_allocation', 'fifo' );
			if ( 'fifo' === $mode ) {
				$plan = self::plan_fifo( (int) $payment['consignee_id'], $amount_base );
				foreach ( $plan['lines'] as $line ) {
					$lines[] = array(
						'sale_id'     => (int) $line['sale_id'],
						'amount_base' => (float) $line['amount_base'],
						'is_manual'   => 0,
						'reason'      => '',
					);
				}
			}
			// 'manual' with no explicit list: nothing is applied, the money waits as a credit.
		}

		$now       = current_time( 'mysql' );
		$allocated = 0.0;

		foreach ( $lines as $line ) {
			$alloc = array(
				'payment_id'   => (int) $payment['id'],
				'sale_id'      => (int) $line['sale_id'],
				'consignee_id' => (int) $payment['consignee_id'],
				'amount_base'  => round( (float) $line['amount_base'], 4 ),
				'is_manual'    => ! empty( $line['is_manual'] ) ? 1 : 0,
				'reason'       => isset( $line['reason'] ) ? MJERP_Helpers::text( $line['reason'], 255 ) : '',
				'created_at'   => $now,
			);
			$wpdb->insert( self::allocations_table(), $alloc, self::formats( $alloc, self::ALLOC_FORMATS ) );
			$allocated += $alloc['amount_base'];

			MJERP_Consignment_Sales_Repo::sync_allocated( $alloc['sale_id'] );
		}

		self::sync_payment_allocated( (int) $payment['id'] );

		return array(
			'allocated'   => round( $allocated, 4 ),
			'unallocated' => round( max( 0, $amount_base - $allocated ), 4 ),
			'lines'       => $lines,
		);
	}

	/**
	 * Validates an explicit allocation list.
	 *
	 * @param array $explicit    Raw list.
	 * @param array $payment     Payment row.
	 * @param float $amount_base Payment amount in base currency.
	 * @return array|WP_Error
	 */
	private static function prepare_explicit( array $explicit, array $payment, float $amount_base ) {
		$lines = array();
		$total = 0.0;
		$seen  = array();

		foreach ( $explicit as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$sale_id = isset( $raw['sale_id'] ) ? absint( $raw['sale_id'] ) : 0;
			$amount  = round( MJERP_Helpers::to_float( isset( $raw['amount_base'] ) ? $raw['amount_base'] : 0 ), 4 );

			if ( $amount <= self::EPSILON ) {
				continue;
			}
			if ( $sale_id <= 0 ) {
				return new WP_Error( 'mjerp_invalid_allocation', __( 'Cada imputación tiene que apuntar a una venta.', 'mj-erp' ), array( 'status' => 400, 'field' => 'allocations' ) );
			}
			if ( isset( $seen[ $sale_id ] ) ) {
				return new WP_Error( 'mjerp_duplicate_allocation', __( 'Hay una venta repetida en la imputación.', 'mj-erp' ), array( 'status' => 400, 'field' => 'allocations' ) );
			}
			$seen[ $sale_id ] = true;

			$sale = MJERP_Consignment_Sales_Repo::find( $sale_id );
			if ( ! $sale ) {
				return new WP_Error( 'mjerp_sale_not_found', __( 'Una de las ventas imputadas no existe.', 'mj-erp' ), array( 'status' => 404, 'field' => 'allocations' ) );
			}
			if ( (int) $sale['consignee_id'] !== (int) $payment['consignee_id'] ) {
				return new WP_Error( 'mjerp_sale_other_consignee', __( 'No se puede imputar un pago a la venta de otro revendedor.', 'mj-erp' ), array( 'status' => 400, 'field' => 'allocations' ) );
			}
			if ( MJERP_Consignment_Sales_Repo::STATUS_APPROVED !== $sale['status'] ) {
				return new WP_Error( 'mjerp_sale_not_approved', __( 'Solo se pueden imputar pagos a ventas aprobadas.', 'mj-erp' ), array( 'status' => 400, 'field' => 'allocations' ) );
			}
			if ( $amount > (float) $sale['balance_base'] + self::EPSILON ) {
				return new WP_Error(
					'mjerp_allocation_exceeds_sale',
					/* translators: %s: outstanding amount */
					sprintf( __( 'A esa venta le faltan cobrar US$ %s: no se le puede imputar más que eso.', 'mj-erp' ), number_format( (float) $sale['balance_base'], 2, ',', '.' ) ),
					array( 'status' => 400, 'field' => 'allocations', 'sale_id' => $sale_id )
				);
			}

			$total  += $amount;
			$lines[] = array(
				'sale_id'     => $sale_id,
				'amount_base' => $amount,
				'is_manual'   => 1,
				'reason'      => isset( $raw['reason'] ) ? $raw['reason'] : '',
			);
		}

		if ( $total > $amount_base + self::EPSILON ) {
			return new WP_Error(
				'mjerp_allocation_exceeds_payment',
				/* translators: 1: allocated amount, 2: payment amount */
				sprintf(
					__( 'Estás imputando US$ %1$s pero el pago es de US$ %2$s.', 'mj-erp' ),
					number_format( $total, 2, ',', '.' ),
					number_format( $amount_base, 2, ',', '.' )
				),
				array( 'status' => 400, 'field' => 'allocations' )
			);
		}

		return $lines;
	}

	/**
	 * Re-inserts the allocations a payment had, after a rejected re-allocation.
	 *
	 * @param array $payment Payment row as loaded before clearing (carries `allocations`).
	 * @return void
	 */
	private static function restore_allocations( array $payment ) : void {
		global $wpdb;

		$now = current_time( 'mysql' );
		foreach ( (array) ( isset( $payment['allocations'] ) ? $payment['allocations'] : array() ) as $old ) {
			$alloc = array(
				'payment_id'   => (int) $payment['id'],
				'sale_id'      => (int) $old['sale_id'],
				'consignee_id' => (int) $payment['consignee_id'],
				'amount_base'  => round( (float) $old['amount_base'], 4 ),
				'is_manual'    => ! empty( $old['is_manual'] ) ? 1 : 0,
				'reason'       => (string) ( isset( $old['reason'] ) ? $old['reason'] : '' ),
				'created_at'   => $now,
			);
			$wpdb->insert( self::allocations_table(), $alloc, self::formats( $alloc, self::ALLOC_FORMATS ) );
			MJERP_Consignment_Sales_Repo::sync_allocated( $alloc['sale_id'] );
		}
		self::sync_payment_allocated( (int) $payment['id'] );
	}

	/**
	 * Drops every allocation of a payment and refreshes the sales it touched.
	 *
	 * @param int $payment_id Payment id.
	 * @return void
	 */
	public static function clear_allocations( $payment_id ) : void {
		global $wpdb;

		$payment_id = absint( $payment_id );
		if ( $payment_id <= 0 ) {
			return;
		}

		$table    = self::allocations_table();
		$sale_ids = $wpdb->get_col( $wpdb->prepare( "SELECT sale_id FROM {$table} WHERE payment_id = %d", $payment_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->delete( $table, array( 'payment_id' => $payment_id ), array( '%d' ) );

		foreach ( array_unique( array_map( 'absint', (array) $sale_ids ) ) as $sale_id ) {
			if ( $sale_id > 0 ) {
				MJERP_Consignment_Sales_Repo::sync_allocated( $sale_id );
			}
		}

		self::sync_payment_allocated( $payment_id );
	}

	/**
	 * Recomputes `allocated_base` of a payment from its allocations.
	 *
	 * @param int $payment_id Payment id.
	 * @return void
	 */
	public static function sync_payment_allocated( $payment_id ) : void {
		global $wpdb;

		$payment_id = absint( $payment_id );
		if ( $payment_id <= 0 ) {
			return;
		}

		$table = self::allocations_table();
		$total = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount_base), 0) FROM {$table} WHERE payment_id = %d", $payment_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$wpdb->update(
			self::table(),
			array(
				'allocated_base' => round( $total, 4 ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $payment_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Applies any credit a consignee is sitting on to their outstanding sales.
	 *
	 * Called after a sale is approved, so money that came in early stops looking unexplained the
	 * moment there is something for it to pay.
	 *
	 * @param int $consignee_id Consignee id.
	 * @return int Number of payments that moved.
	 */
	public static function apply_credits( $consignee_id ) : int {
		global $wpdb;

		$consignee_id = absint( $consignee_id );
		if ( $consignee_id <= 0 ) {
			return 0;
		}
		if ( 'fifo' !== (string) MJERP_Settings::get( 'consignment_allocation', 'fifo' ) ) {
			return 0;
		}

		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE consignee_id = %d AND kind = %s AND (amount_base - allocated_base) > 0.0001
				 ORDER BY payment_date ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$consignee_id,
				self::KIND_PAYMENT
			),
			ARRAY_A
		);

		$moved = 0;

		foreach ( (array) $rows as $row ) {
			$payment   = self::cast( $row );
			$available = round( (float) $payment['amount_base'] - (float) $payment['allocated_base'], 4 );
			if ( $available <= self::EPSILON ) {
				continue;
			}

			$plan = self::plan_fifo( $consignee_id, $available );
			if ( empty( $plan['lines'] ) ) {
				continue;
			}

			$now = current_time( 'mysql' );
			foreach ( $plan['lines'] as $line ) {
				$alloc = array(
					'payment_id'   => (int) $payment['id'],
					'sale_id'      => (int) $line['sale_id'],
					'consignee_id' => $consignee_id,
					'amount_base'  => round( (float) $line['amount_base'], 4 ),
					'is_manual'    => 0,
					'reason'       => '',
					'created_at'   => $now,
				);
				$wpdb->insert( self::allocations_table(), $alloc, self::formats( $alloc, self::ALLOC_FORMATS ) );
				MJERP_Consignment_Sales_Repo::sync_allocated( $alloc['sale_id'] );
			}
			self::sync_payment_allocated( (int) $payment['id'] );
			$moved++;
		}

		return $moved;
	}

	/* ------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	/**
	 * Sanitize + validate the provided fields.
	 *
	 * @param array      $data     Input.
	 * @param array|null $existing Existing row on update.
	 * @return array|WP_Error
	 */
	private static function prepare( array $data, ?array $existing ) {
		$row = array();

		$kind = $existing ? (string) $existing['kind'] : self::KIND_PAYMENT;
		if ( array_key_exists( 'kind', $data ) ) {
			$candidate = sanitize_key( (string) $data['kind'] );
			$kind      = in_array( $candidate, self::kinds(), true ) ? $candidate : self::KIND_PAYMENT;
			$row['kind'] = $kind;
		}

		$amount = $existing ? (float) $existing['amount'] : null;
		if ( array_key_exists( 'amount', $data ) || ! $existing ) {
			$amount = round( MJERP_Helpers::to_float( isset( $data['amount'] ) ? $data['amount'] : 0 ), 4 );
			if ( self::KIND_PAYMENT === $kind && $amount <= 0 ) {
				return new WP_Error( 'mjerp_invalid_amount', __( 'El importe del pago tiene que ser mayor a cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'amount' ) );
			}
			if ( self::KIND_ADJUSTMENT === $kind && 0.0 === $amount ) {
				return new WP_Error( 'mjerp_invalid_amount', __( 'El importe del ajuste no puede ser cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'amount' ) );
			}
			$row['amount'] = $amount;
		}

		$currency = $existing ? (string) $existing['currency'] : '';
		if ( array_key_exists( 'currency', $data ) || ! $existing ) {
			$currency         = MJERP_Helpers::sanitize_currency( isset( $data['currency'] ) ? $data['currency'] : MJERP_Settings::get( 'local_currency', 'ARS' ) );
			$row['currency']  = $currency;
		}

		$rate = $existing ? (float) $existing['rate'] : 0.0;
		if ( array_key_exists( 'rate', $data ) || ! $existing ) {
			$rate = round( MJERP_Helpers::to_float( isset( $data['rate'] ) ? $data['rate'] : 0 ), 6 );
			if ( $rate <= 0 ) {
				// MJ Dólar first: the rate the shop is actually using today.
				$rate = class_exists( 'MJERP_Dolar' ) ? MJERP_Dolar::rate() : 0.0;
			}
			if ( $rate <= 0 ) {
				$rate = MJERP_Helpers::to_float( MJERP_Settings::get( 'sale_rate', 1 ) );
			}
			if ( $rate <= 0 ) {
				$rate = 1.0;
			}
			$row['rate'] = $rate;
		}

		if ( isset( $row['amount'] ) || isset( $row['currency'] ) || isset( $row['rate'] ) ) {
			$base_currency      = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
			$row['amount_base'] = round( MJERP_Helpers::to_base( $amount, $currency, $rate, $base_currency ), 4 );
		}

		if ( array_key_exists( 'payment_date', $data ) || ! $existing ) {
			$date = MJERP_Helpers::sanitize_date( isset( $data['payment_date'] ) ? $data['payment_date'] : null );
			$row['payment_date'] = ( null === $date ) ? current_time( 'Y-m-d' ) : $date;
		}

		if ( array_key_exists( 'method', $data ) || ! $existing ) {
			$method = sanitize_key( (string) ( isset( $data['method'] ) ? $data['method'] : '' ) );
			if ( '' === $method ) {
				$method = 'otro';
			}
			$row['method'] = $method;
		}

		if ( array_key_exists( 'cashbox_id', $data ) ) {
			$row['cashbox_id'] = absint( $data['cashbox_id'] );
		}

		if ( array_key_exists( 'reference', $data ) ) {
			$row['reference'] = MJERP_Helpers::text( $data['reference'], 190 );
		}

		if ( array_key_exists( 'note', $data ) ) {
			$row['note'] = MJERP_Helpers::text( $data['note'], 255 );
		}

		// An adjustment is the manual exception, so it has to say why it exists.
		$reason = $existing ? (string) $existing['reason'] : '';
		if ( array_key_exists( 'reason', $data ) ) {
			$reason         = MJERP_Helpers::text( $data['reason'], 255 );
			$row['reason']  = $reason;
		}
		if ( self::KIND_ADJUSTMENT === $kind && '' === trim( $reason ) ) {
			return new WP_Error(
				'mjerp_reason_required',
				__( 'Un ajuste manual necesita un motivo: es la única forma de que quede claro por qué se movió el saldo.', 'mj-erp' ),
				array( 'status' => 400, 'field' => 'reason' )
			);
		}

		return $row;
	}

	private static function cast( array $row ) : array {
		$amount_base = round( (float) self::v( $row, 'amount_base', 0 ), 4 );
		$allocated   = round( (float) self::v( $row, 'allocated_base', 0 ), 4 );
		$method      = (string) self::v( $row, 'method', '' );

		return array(
			'id'              => (int) $row['id'],
			'consignee_id'    => (int) self::v( $row, 'consignee_id', 0 ),
			'kind'            => (string) self::v( $row, 'kind', self::KIND_PAYMENT ),
			'payment_date'    => self::date_or_null( self::v( $row, 'payment_date', null ) ),
			'amount'          => round( (float) self::v( $row, 'amount', 0 ), 4 ),
			'currency'        => strtoupper( (string) self::v( $row, 'currency', 'ARS' ) ),
			'rate'            => round( (float) self::v( $row, 'rate', 1 ), 6 ),
			'amount_base'     => $amount_base,
			'allocated_base'  => $allocated,
			'unallocated_base' => round( max( 0, $amount_base - $allocated ), 4 ),
			'method'          => $method,
			'method_label'    => MJERP_Settings::payment_method_label( $method ),
			'cashbox_id'      => (int) self::v( $row, 'cashbox_id', 0 ),
			'reference'       => (string) self::v( $row, 'reference' ),
			'reason'          => (string) self::v( $row, 'reason' ),
			'note'            => (string) self::v( $row, 'note' ),
			'status'          => (string) self::v( $row, 'status', self::STATUS_CONFIRMED ),
			'status_label'    => self::STATUS_DECLARED === (string) self::v( $row, 'status', self::STATUS_CONFIRMED )
				? __( 'A confirmar', 'mj-erp' )
				: __( 'Confirmado', 'mj-erp' ),
			'is_declared'     => self::STATUS_DECLARED === (string) self::v( $row, 'status', self::STATUS_CONFIRMED ),
			'source'          => (string) self::v( $row, 'source', self::SOURCE_ADMIN ),
			'sale_id'         => (int) self::v( $row, 'sale_id', 0 ),
			'confirmed_by'    => (int) self::v( $row, 'confirmed_by', 0 ),
			'confirmed_at'    => self::datetime_or_null( self::v( $row, 'confirmed_at' ) ),
			'created_by'      => (int) self::v( $row, 'created_by', 0 ),
			'created_by_name' => MJERP_Helpers::user_display_name( self::v( $row, 'created_by', 0 ) ),
			'created_at'      => self::datetime_or_null( self::v( $row, 'created_at' ) ),
			'updated_at'      => self::datetime_or_null( self::v( $row, 'updated_at' ) ),
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

	private static function date_or_null( $value ) : ?string {
		if ( null === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}
		return substr( $value, 0, 10 );
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
