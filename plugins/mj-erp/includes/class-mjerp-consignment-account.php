<?php
defined( 'ABSPATH' ) || exit;

/**
 * The reseller's current account: what they hold, what they owe, what they paid.
 *
 * Read-only. Every number is derived from the sales, payments and allocations tables, never from a
 * running balance column — a stored balance is a number that can be wrong, and this one has to be
 * arguable in front of the person who owes it.
 *
 * All amounts are in base currency (USD), the currency the prices were agreed in.
 *
 *   deuda = Σ ventas aprobadas − Σ pagos y ajustes
 *
 * @package MJ_ERP
 */
class MJERP_Consignment_Account {

	/** Amounts carry 4 decimals; below this everything is settled. */
	const EPSILON = 0.0001;

	/**
	 * Registers the hooks that keep the account coherent by itself.
	 *
	 * @return void
	 */
	public static function init() {
		// Money that came in early stops looking unexplained as soon as there is a sale to pay.
		add_action( 'mjerp_consignment_sale_approved', array( __CLASS__, 'on_sale_approved' ) );
	}

	/**
	 * Applies any credit sitting on the account to the sale that was just approved.
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	public static function on_sale_approved( $sale_id ) {
		$sale = MJERP_Consignment_Sales_Repo::find( $sale_id );
		if ( $sale ) {
			MJERP_Consignment_Payments_Repo::apply_credits( (int) $sale['consignee_id'] );
		}
	}

	/**
	 * Full account summary of a consignee.
	 *
	 * @param int $consignee_id Consignee id.
	 * @return array
	 */
	public static function summary( $consignee_id ) : array {
		global $wpdb;

		$consignee_id = absint( $consignee_id );

		$empty = array(
			'consignee_id'      => $consignee_id,
			'currency'          => strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) ),
			'charges_base'      => 0.0,
			'payments_base'     => 0.0,
			'adjustments_base'  => 0.0,
			'credits_base'      => 0.0,
			'declared_base'     => 0.0,
			'declared_count'    => 0,
			'balance_base'      => 0.0,
			'unallocated_base'  => 0.0,
			'overdue_base'      => 0.0,
			'sales_count'       => 0,
			'unpaid_sales'      => 0,
			'pending_sales'     => 0,
			'pending_base'      => 0.0,
			'on_hand_units'     => 0.0,
			'on_hand_value_base' => 0.0,
			'on_hand_lines'     => 0,
			'last_sale_date'    => null,
			'last_payment_date' => null,
			'credit_limit_base' => 0.0,
			'credit_available_base' => null,
			'over_limit'        => false,
		);

		if ( $consignee_id <= 0 ) {
			return $empty;
		}

		$sales_table    = MJERP_Install::table( 'consignment_sales' );
		$payments_table = MJERP_Install::table( 'consignment_payments' );

		$sales = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN status = %s THEN amount_base ELSE 0 END), 0) AS charges,
					COALESCE(SUM(CASE WHEN status = %s THEN (amount_base - allocated_base) ELSE 0 END), 0) AS outstanding,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS approved_count,
					SUM(CASE WHEN status = %s AND (amount_base - allocated_base) > 0.0001 THEN 1 ELSE 0 END) AS unpaid_count,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS pending_count,
					COALESCE(SUM(CASE WHEN status = %s THEN amount_base ELSE 0 END), 0) AS pending_amount,
					MAX(CASE WHEN status = %s THEN sale_date ELSE NULL END) AS last_sale
				 FROM {$sales_table} WHERE consignee_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				MJERP_Consignment_Sales_Repo::STATUS_APPROVED,
				MJERP_Consignment_Sales_Repo::STATUS_APPROVED,
				MJERP_Consignment_Sales_Repo::STATUS_APPROVED,
				MJERP_Consignment_Sales_Repo::STATUS_APPROVED,
				MJERP_Consignment_Sales_Repo::STATUS_PENDING,
				MJERP_Consignment_Sales_Repo::STATUS_PENDING,
				MJERP_Consignment_Sales_Repo::STATUS_APPROVED,
				$consignee_id
			),
			ARRAY_A
		);

		/*
		 * A payment a reseller declared from their own screen is a claim, not money: it only counts
		 * once it is confirmed. It is still reported (`declared`) so the shop owner sees there is
		 * something waiting instead of wondering why the balance did not move.
		 */
		$payments = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN kind = %s AND status = %s THEN amount_base ELSE 0 END), 0) AS payments,
					COALESCE(SUM(CASE WHEN kind = %s AND status = %s THEN amount_base ELSE 0 END), 0) AS adjustments,
					COALESCE(SUM(CASE WHEN status = %s THEN amount_base ELSE 0 END), 0) AS credits,
					COALESCE(SUM(CASE WHEN status = %s THEN GREATEST(amount_base - allocated_base, 0) ELSE 0 END), 0) AS unallocated,
					COALESCE(SUM(CASE WHEN status = %s THEN amount_base ELSE 0 END), 0) AS declared,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS declared_count,
					MAX(CASE WHEN kind = %s AND status = %s THEN payment_date ELSE NULL END) AS last_payment
				 FROM {$payments_table} WHERE consignee_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				MJERP_Consignment_Payments_Repo::KIND_PAYMENT,
				MJERP_Consignment_Payments_Repo::STATUS_CONFIRMED,
				MJERP_Consignment_Payments_Repo::KIND_ADJUSTMENT,
				MJERP_Consignment_Payments_Repo::STATUS_CONFIRMED,
				MJERP_Consignment_Payments_Repo::STATUS_CONFIRMED,
				MJERP_Consignment_Payments_Repo::STATUS_CONFIRMED,
				MJERP_Consignment_Payments_Repo::STATUS_DECLARED,
				MJERP_Consignment_Payments_Repo::STATUS_DECLARED,
				MJERP_Consignment_Payments_Repo::KIND_PAYMENT,
				MJERP_Consignment_Payments_Repo::STATUS_CONFIRMED,
				$consignee_id
			),
			ARRAY_A
		);

		$charges     = round( (float) ( $sales ? $sales['charges'] : 0 ), 4 );
		$credits     = round( (float) ( $payments ? $payments['credits'] : 0 ), 4 );
		$outstanding = round( (float) ( $sales ? $sales['outstanding'] : 0 ), 4 );
		$unallocated = round( (float) ( $payments ? $payments['unallocated'] : 0 ), 4 );

		$on_hand = MJERP_Consignments_Repo::on_hand_totals( $consignee_id );

		$consignee    = MJERP_Consignees::find( $consignee_id );
		$credit_limit = $consignee ? (float) $consignee['credit_limit_base'] : 0.0;

		/*
		 * The debt is charges minus everything credited. It equals the sum of the unpaid sale
		 * balances minus any credit on account, which is the same number seen from the other side.
		 */
		$balance = round( $charges - $credits, 4 );

		$out = array(
			'consignee_id'      => $consignee_id,
			'currency'          => strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) ),
			'charges_base'      => $charges,
			'payments_base'     => round( (float) ( $payments ? $payments['payments'] : 0 ), 4 ),
			'adjustments_base'  => round( (float) ( $payments ? $payments['adjustments'] : 0 ), 4 ),
			'credits_base'      => $credits,
			'declared_base'     => round( (float) ( $payments ? $payments['declared'] : 0 ), 4 ),
			'declared_count'    => (int) ( $payments ? $payments['declared_count'] : 0 ),
			'balance_base'      => $balance,
			'unallocated_base'  => $unallocated,
			'overdue_base'      => $outstanding,
			'sales_count'       => (int) ( $sales ? $sales['approved_count'] : 0 ),
			'unpaid_sales'      => (int) ( $sales ? $sales['unpaid_count'] : 0 ),
			'pending_sales'     => (int) ( $sales ? $sales['pending_count'] : 0 ),
			'pending_base'      => round( (float) ( $sales ? $sales['pending_amount'] : 0 ), 4 ),
			'on_hand_units'     => (float) $on_hand['units'],
			'on_hand_value_base' => (float) $on_hand['value_base'],
			'on_hand_lines'     => (int) $on_hand['lines'],
			'last_sale_date'    => self::date_or_null( $sales ? $sales['last_sale'] : null ),
			'last_payment_date' => self::date_or_null( $payments ? $payments['last_payment'] : null ),
			'credit_limit_base' => round( $credit_limit, 4 ),
			'credit_available_base' => $credit_limit > 0 ? round( $credit_limit - $balance, 4 ) : null,
			'over_limit'        => $credit_limit > 0 && $balance > $credit_limit + self::EPSILON,
		);

		/**
		 * Filters the account summary of a consignee.
		 *
		 * @param array $out          Summary.
		 * @param int   $consignee_id Consignee id.
		 */
		$filtered = apply_filters( 'mjerp_consignment_account_summary', $out, $consignee_id );

		return is_array( $filtered ) ? array_merge( $out, $filtered ) : $out;
	}

	/**
	 * Summaries of every consignee, for the list screen.
	 *
	 * @param bool $include_inactive Include deactivated consignees.
	 * @return array [ consignee merged with its summary ]
	 */
	public static function overview( bool $include_inactive = false ) : array {
		$out = array();
		foreach ( MJERP_Consignees::all( $include_inactive ) as $consignee ) {
			$consignee['account'] = self::summary( (int) $consignee['id'] );
			$out[]                = $consignee;
		}
		return $out;
	}

	/**
	 * Totals across every consignee, for the dashboard.
	 *
	 * @return array
	 */
	public static function totals() : array {
		$totals = array(
			'consignees'         => 0,
			'balance_base'       => 0.0,
			'on_hand_units'      => 0.0,
			'on_hand_value_base' => 0.0,
			'pending_sales'      => 0,
			'declared_payments'  => 0,
			'declared_base'      => 0.0,
			'unallocated_base'   => 0.0,
			'over_limit'         => 0,
		);

		foreach ( self::overview( false ) as $consignee ) {
			$account = $consignee['account'];
			$totals['consignees']++;
			$totals['balance_base']       += (float) $account['balance_base'];
			$totals['on_hand_units']      += (float) $account['on_hand_units'];
			$totals['on_hand_value_base'] += (float) $account['on_hand_value_base'];
			$totals['pending_sales']      += (int) $account['pending_sales'];
			$totals['declared_payments']  += (int) $account['declared_count'];
			$totals['declared_base']      += (float) $account['declared_base'];
			$totals['unallocated_base']   += (float) $account['unallocated_base'];
			if ( ! empty( $account['over_limit'] ) ) {
				$totals['over_limit']++;
			}
		}

		$totals['balance_base']       = round( $totals['balance_base'], 4 );
		$totals['on_hand_units']      = round( $totals['on_hand_units'], 4 );
		$totals['on_hand_value_base'] = round( $totals['on_hand_value_base'], 4 );
		$totals['unallocated_base']   = round( $totals['unallocated_base'], 4 );
		$totals['declared_base']      = round( $totals['declared_base'], 4 );

		return $totals;
	}

	/**
	 * The account statement: sales and payments interleaved, oldest first, with a running balance.
	 *
	 * This is what gets shown to the reseller when they ask "why do I owe this".
	 *
	 * @param int $consignee_id Consignee id.
	 * @param int $limit        Max rows per source.
	 * @return array [ { date, type, description, charge_base, credit_base, balance_base, ref_id } ]
	 */
	public static function statement( $consignee_id, int $limit = 200 ) : array {
		$consignee_id = absint( $consignee_id );
		if ( $consignee_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 1000, $limit ) );
		$rows  = array();

		$sales = MJERP_Consignment_Sales_Repo::query(
			array(
				'consignee_id' => $consignee_id,
				'status'       => MJERP_Consignment_Sales_Repo::STATUS_APPROVED,
				'per_page'     => $limit,
				'order'        => 'ASC',
			)
		);
		foreach ( $sales['items'] as $sale ) {
			$rows[] = array(
				'date'        => $sale['sale_date'],
				'sort'        => (string) $sale['sale_date'] . '-1-' . str_pad( (string) $sale['id'], 10, '0', STR_PAD_LEFT ),
				'type'        => 'venta',
				'ref_id'      => (int) $sale['id'],
				'description' => sprintf(
					/* translators: 1: quantity, 2: product name */
					__( 'Venta de %1$s × %2$s', 'mj-erp' ),
					rtrim( rtrim( number_format( (float) $sale['qty'], 4, '.', '' ), '0' ), '.' ),
					$sale['name']
				),
				'charge_base' => (float) $sale['amount_base'],
				'credit_base' => 0.0,
				'paid'        => (bool) $sale['is_paid'],
			);
		}

		// Only confirmed payments belong in a running balance: a declared one has not moved it.
		$payments = MJERP_Consignment_Payments_Repo::query(
			array(
				'consignee_id' => $consignee_id,
				'status'       => MJERP_Consignment_Payments_Repo::STATUS_CONFIRMED,
				'per_page'     => $limit,
				'order'        => 'ASC',
			)
		);
		foreach ( $payments['items'] as $payment ) {
			$is_adjustment = MJERP_Consignment_Payments_Repo::KIND_ADJUSTMENT === $payment['kind'];
			$amount        = (float) $payment['amount_base'];

			if ( $is_adjustment ) {
				$description = '' !== $payment['reason']
					? sprintf( /* translators: %s: reason */ __( 'Ajuste manual: %s', 'mj-erp' ), $payment['reason'] )
					: __( 'Ajuste manual', 'mj-erp' );
			} else {
				$description = sprintf(
					/* translators: %s: payment method */
					__( 'Pago por %s', 'mj-erp' ),
					$payment['method_label']
				);
				if ( '' !== $payment['reference'] ) {
					$description .= ' · ' . $payment['reference'];
				}
			}

			$rows[] = array(
				'date'        => $payment['payment_date'],
				'sort'        => (string) $payment['payment_date'] . '-2-' . str_pad( (string) $payment['id'], 10, '0', STR_PAD_LEFT ),
				'type'        => $is_adjustment ? 'ajuste' : 'pago',
				'ref_id'      => (int) $payment['id'],
				'description' => $description,
				// A negative adjustment is a charge, so it lands on the other column.
				'charge_base' => $amount < 0 ? abs( $amount ) : 0.0,
				'credit_base' => $amount > 0 ? $amount : 0.0,
				'paid'        => true,
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( (string) $a['sort'], (string) $b['sort'] );
			}
		);

		$balance = 0.0;
		foreach ( $rows as $index => $row ) {
			$balance += (float) $row['charge_base'] - (float) $row['credit_base'];
			$rows[ $index ]['balance_base'] = round( $balance, 4 );
			unset( $rows[ $index ]['sort'] );
		}

		return $rows;
	}

	/**
	 * Everything the consignee detail screen needs, in one payload.
	 *
	 * @param int $consignee_id Consignee id.
	 * @return array|null
	 */
	public static function dashboard( $consignee_id ) : ?array {
		$consignee = MJERP_Consignees::find( $consignee_id );
		if ( ! $consignee ) {
			return null;
		}

		$id = (int) $consignee['id'];

		return array(
			'consignee'    => $consignee,
			'account'      => self::summary( $id ),
			'stock'        => MJERP_Consignments_Repo::stock_on_hand( $id, true ),
			'sales'        => MJERP_Consignment_Sales_Repo::query(
				array(
					'consignee_id' => $id,
					'per_page'     => 50,
				)
			),
			'payments'     => MJERP_Consignment_Payments_Repo::query(
				array(
					'consignee_id' => $id,
					'per_page'     => 50,
				)
			),
			'consignments' => MJERP_Consignments_Repo::query(
				array(
					'consignee_id' => $id,
					'per_page'     => 50,
				)
			),
			'returns'      => MJERP_Consignments_Repo::returns_for_consignee( $id, 50 ),
		);
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
}
