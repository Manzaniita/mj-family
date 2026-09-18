<?php
/**
 * Caja: el libro de cada caja, los movimientos a mano y los cierres.
 *
 * La plata que entra y sale ya está anotada en otros lados con su caja: los cobros de ventas
 * (y las devoluciones, que son cobros negativos), las cobranzas de revendedores, los gastos de
 * las compras y los reintegros de saldo a favor. Este repositorio los lee de ahí y suma lo único
 * que no viene de otra pantalla: los movimientos cargados a mano (gastos sueltos, retiros,
 * aportes, ajustes) y las transferencias entre cajas. El saldo es la suma de todo y no se guarda
 * en ninguna columna, igual que el saldo a favor de un cliente.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- los nombres de tabla salen de MJERP_Install::table(), que los filtra a [a-z0-9_].
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- el WHERE de fechas se arma con date_where() y sus valores viajan en el mismo array.

/**
 * Class MJERP_Cash_Repo
 */
class MJERP_Cash_Repo {

	const DIR_IN  = 'ingreso';
	const DIR_OUT = 'egreso';

	/* Concepto que no sale de la configuración: lo pone el sistema. */
	const KIND_TRANSFER = 'transferencia';

	/* De dónde sale cada renglón del libro. */
	const SRC_SALE      = 'cobro';
	const SRC_RETURN    = 'devolucion';
	const SRC_CONSIGNEE = 'cobranza';
	const SRC_EXPENSE   = 'gasto_compra';
	const SRC_PAYOUT    = 'reintegro';
	const SRC_MANUAL    = 'manual';

	const EPSILON = 0.0001;

	const FORMATS = array(
		'cashbox_id'    => '%d',
		'movement_date' => '%s',
		'kind'          => '%s',
		'direction'     => '%s',
		'amount'        => '%f',
		'currency'      => '%s',
		'rate'          => '%f',
		'amount_base'   => '%f',
		'concept'       => '%s',
		'reference'     => '%s',
		'transfer_id'   => '%d',
		'created_by'    => '%d',
		'created_at'    => '%s',
	);

	const CLOSURE_FORMATS = array(
		'cashbox_id'       => '%d',
		'closure_date'     => '%s',
		'currency'         => '%s',
		'opening_balance'  => '%f',
		'income_total'     => '%f',
		'expense_total'    => '%f',
		'expected_balance' => '%f',
		'counted_balance'  => '%f',
		'difference'       => '%f',
		'movements_count'  => '%d',
		'note'             => '%s',
		'closed_by'        => '%d',
		'closed_at'        => '%s',
	);

	private static function table() : string {
		return MJERP_Install::table( 'cash_movements' );
	}

	private static function closures_table() : string {
		return MJERP_Install::table( 'cash_closures' );
	}

	/**
	 * Whether the module is on (Configuración → Caja y reportes).
	 *
	 * @return bool
	 */
	public static function enabled() : bool {
		return MJERP_Helpers::bool( MJERP_Settings::get( 'cash_enabled', true ) );
	}

	/**
	 * Whether day closures are on.
	 *
	 * @return bool
	 */
	public static function closures_enabled() : bool {
		return self::enabled() && MJERP_Helpers::bool( MJERP_Settings::get( 'cash_closure_enabled', true ) );
	}

	/**
	 * Manual movement kinds `[ {key,label}, ... ]`, always with «otro».
	 *
	 * @return array
	 */
	public static function kinds() : array {
		$list = MJERP_Settings::get( 'cash_kinds', array() );
		$out  = array();
		foreach ( (array) $list as $row ) {
			if ( is_array( $row ) && isset( $row['key'] ) && '' !== (string) $row['key'] ) {
				$out[] = array(
					'key'   => sanitize_key( (string) $row['key'] ),
					'label' => isset( $row['label'] ) && '' !== (string) $row['label'] ? (string) $row['label'] : ucfirst( (string) $row['key'] ),
				);
			}
		}
		if ( ! in_array( 'otro', wp_list_pluck( $out, 'key' ), true ) ) {
			$out[] = array(
				'key'   => 'otro',
				'label' => __( 'Otro', 'mj-erp' ),
			);
		}
		return $out;
	}

	/**
	 * Label of a movement kind.
	 *
	 * @param string $kind Kind key.
	 * @return string
	 */
	public static function kind_label( string $kind ) : string {
		if ( self::KIND_TRANSFER === $kind ) {
			return __( 'Transferencia entre cajas', 'mj-erp' );
		}
		foreach ( self::kinds() as $row ) {
			if ( $row['key'] === $kind ) {
				return $row['label'];
			}
		}
		return ucfirst( str_replace( array( '_', '-' ), ' ', $kind ) );
	}

	/* ------------------------------------------------------------------
	 * El libro
	 * ---------------------------------------------------------------- */

	/**
	 * Every money movement of a cashbox in a date range, newest first, from all the sources.
	 *
	 * Son cinco consultas chicas y no una unión gigante: cada fuente tiene sus columnas y su
	 * regla (un cobro cuenta solo confirmado, un gasto solo si la compra no se canceló), y así
	 * cada una se lee sola. El período acota el volumen; sin período se topea.
	 *
	 * @param int   $cashbox_id Cashbox id.
	 * @param array $args       date_from, date_to, source, page, per_page.
	 * @return array { items, total, page, pages, totals: { by_currency: {CUR: {income, expense, net}} } }
	 */
	public static function ledger( $cashbox_id, array $args = array() ) : array {
		$cashbox_id = absint( $cashbox_id );
		$args       = array_merge(
			array(
				'date_from' => '',
				'date_to'   => '',
				'source'    => '',
				'page'      => 1,
				'per_page'  => 50,
			),
			$args
		);

		$from   = MJERP_Helpers::sanitize_date( $args['date_from'] );
		$to     = MJERP_Helpers::sanitize_date( $args['date_to'] );
		$source = sanitize_key( (string) $args['source'] );

		$rows = self::rows( $cashbox_id, $from, $to );
		if ( '' !== $source ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $source ) {
						return $row['source'] === $source;
					}
				)
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				$cmp = strcmp( (string) $b['date'], (string) $a['date'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
			}
		);

		$totals = array();
		foreach ( $rows as $row ) {
			$cur = $row['currency'];
			if ( ! isset( $totals[ $cur ] ) ) {
				$totals[ $cur ] = array(
					'income'  => 0.0,
					'expense' => 0.0,
					'net'     => 0.0,
				);
			}
			if ( $row['amount'] >= 0 ) {
				$totals[ $cur ]['income'] += $row['amount'];
			} else {
				$totals[ $cur ]['expense'] += -$row['amount'];
			}
			$totals[ $cur ]['net'] += $row['amount'];
		}
		foreach ( $totals as $cur => $t ) {
			$totals[ $cur ] = array_map(
				function ( $v ) {
					return round( (float) $v, 4 );
				},
				$t
			);
		}

		$total    = count( $rows );
		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$items    = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
			'totals'   => array( 'by_currency' => $totals ),
		);
	}

	/**
	 * Balance of a cashbox per currency, up to (and including) a date.
	 *
	 * @param int         $cashbox_id Cashbox id.
	 * @param string|null $until      Y-m-d, null = everything.
	 * @return array {CUR: balance}
	 */
	public static function balances( $cashbox_id, ?string $until = null ) : array {
		$out = array();
		foreach ( self::sums( absint( $cashbox_id ), null, $until ) as $cur => $sum ) {
			$out[ $cur ] = round( $sum['income'] - $sum['expense'], 4 );
		}
		return $out;
	}

	/**
	 * One card per active cashbox: balance in its currency, the rest apart, and today's flow.
	 *
	 * @return array
	 */
	public static function balances_summary() : array {
		$today = current_time( 'Y-m-d' );
		$out   = array();

		foreach ( MJERP_Cashboxes_Repo::all( false ) as $box ) {
			$balances  = self::balances( $box['id'] );
			$own       = $box['currency'];
			$today_in  = 0.0;
			$today_out = 0.0;
			$day       = self::sums( (int) $box['id'], $today, $today );
			if ( isset( $day[ $own ] ) ) {
				$today_in  = $day[ $own ]['income'];
				$today_out = $day[ $own ]['expense'];
			}
			$others = $balances;
			unset( $others[ $own ] );

			$out[] = array(
				'id'           => (int) $box['id'],
				'name'         => (string) $box['name'],
				'currency'     => $own,
				'balance'      => isset( $balances[ $own ] ) ? $balances[ $own ] : 0.0,
				'others'       => $others,
				'today_in'     => round( $today_in, 4 ),
				'today_out'    => round( $today_out, 4 ),
				'closed_today' => null !== self::find_closure_by_day( $box['id'], $today ),
			);
		}

		return $out;
	}

	/**
	 * Income and expense per currency of a cashbox between two dates (both inclusive, null = open).
	 *
	 * @param int         $cashbox_id Cashbox id.
	 * @param string|null $from       Y-m-d.
	 * @param string|null $to         Y-m-d.
	 * @return array {CUR: {income, expense}}
	 */
	public static function sums( int $cashbox_id, ?string $from, ?string $to ) : array {
		$out = array();
		foreach ( self::rows( $cashbox_id, $from, $to ) as $row ) {
			$cur = $row['currency'];
			if ( ! isset( $out[ $cur ] ) ) {
				$out[ $cur ] = array(
					'income'  => 0.0,
					'expense' => 0.0,
				);
			}
			if ( $row['amount'] >= 0 ) {
				$out[ $cur ]['income'] += $row['amount'];
			} else {
				$out[ $cur ]['expense'] += -$row['amount'];
			}
		}
		foreach ( $out as $cur => $sum ) {
			$out[ $cur ] = array(
				'income'  => round( $sum['income'], 4 ),
				'expense' => round( $sum['expense'], 4 ),
			);
		}
		return $out;
	}

	/**
	 * Raw rows of the ledger, all sources, unsorted.
	 *
	 * @param int         $cashbox_id Cashbox id.
	 * @param string|null $from       Y-m-d.
	 * @param string|null $to         Y-m-d.
	 * @return array
	 */
	private static function rows( int $cashbox_id, ?string $from, ?string $to ) : array {
		if ( $cashbox_id <= 0 ) {
			return array();
		}

		/**
		 * Filters how many rows the ledger reads per source. Un período acotado nunca llega; es el
		 * techo de «todo el historial».
		 *
		 * @param int $limit Rows per source.
		 */
		$limit = max( 100, (int) apply_filters( 'mjerp_cash_ledger_limit', 5000 ) );

		$rows = array_merge(
			self::rows_sale_payments( $cashbox_id, $from, $to, $limit ),
			self::rows_consignment_payments( $cashbox_id, $from, $to, $limit ),
			self::rows_purchase_expenses( $cashbox_id, $from, $to, $limit ),
			self::rows_payouts( $cashbox_id, $from, $to, $limit ),
			self::rows_manual( $cashbox_id, $from, $to, $limit )
		);

		/**
		 * Filters the ledger rows of a cashbox (other plugins can add their own money).
		 *
		 * @param array       $rows       Rows (see row()).
		 * @param int         $cashbox_id Cashbox id.
		 * @param string|null $from       From date.
		 * @param string|null $to         To date.
		 */
		return (array) apply_filters( 'mjerp_cash_ledger_rows', $rows, $cashbox_id, $from, $to );
	}

	/**
	 * Date condition for a column.
	 *
	 * @param string      $column Column (already safe).
	 * @param string|null $from   From.
	 * @param string|null $to     To.
	 * @param array       $vals   Values (by reference).
	 * @return string
	 */
	private static function date_where( string $column, ?string $from, ?string $to, array &$vals ) : string {
		$where = '';
		if ( $from ) {
			$where .= " AND {$column} >= %s";
			$vals[] = $from;
		}
		if ( $to ) {
			$where .= " AND {$column} <= %s";
			$vals[] = $to;
		}
		return $where;
	}

	/**
	 * Cobros de ventas (confirmados). Los negativos son devoluciones o reintegros de una anulación.
	 *
	 * @param int         $cashbox_id Cashbox id.
	 * @param string|null $from       From.
	 * @param string|null $to         To.
	 * @param int         $limit      Limit.
	 * @return array
	 */
	private static function rows_sale_payments( int $cashbox_id, ?string $from, ?string $to, int $limit ) : array {
		global $wpdb;

		$pays   = MJERP_Install::table( 'sale_payments' );
		$sales  = MJERP_Install::table( 'sales' );
		$vals   = array( $cashbox_id, MJERP_Sale_Payments_Repo::STATUS_CONFIRMED );
		$where  = self::date_where( 'p.payment_date', $from, $to, $vals );
		$vals[] = $limit;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.id, p.payment_date, p.amount, p.currency, p.rate, p.amount_base, p.method, p.reference, p.note, p.source,
					p.created_by, p.created_at, s.id AS sale_id, s.code AS sale_code, s.customer_name
				FROM {$pays} p LEFT JOIN {$sales} s ON s.id = p.sale_id
				WHERE p.cashbox_id = %d AND p.status = %s {$where}
				ORDER BY p.payment_date DESC, p.id DESC LIMIT %d",
				$vals
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $r ) {
			$amount = (float) $r['amount'];
			$return = $amount < 0 || MJERP_Sale_Payments_Repo::SOURCE_RETURN === (string) $r['source'];
			$out[]  = self::row(
				array(
					'uid'         => ( $return ? 'devolucion-' : 'cobro-' ) . (int) $r['id'],
					'source'      => $return ? self::SRC_RETURN : self::SRC_SALE,
					'ref_id'      => (int) $r['id'],
					'date'        => (string) $r['payment_date'],
					'created_at'  => (string) $r['created_at'],
					'amount'      => $amount,
					'currency'    => (string) $r['currency'],
					'rate'        => (float) $r['rate'],
					'amount_base' => (float) $r['amount_base'],
					'method'      => (string) $r['method'],
					'reference'   => (string) $r['reference'],
					'note'        => (string) $r['note'],
					'label'       => $return
						/* translators: %s: sale code */
						? sprintf( __( 'Devolución · %s', 'mj-erp' ), (string) $r['sale_code'] )
						/* translators: %s: sale code */
						: sprintf( __( 'Cobro · %s', 'mj-erp' ), (string) $r['sale_code'] ),
					'detail'      => (string) $r['customer_name'],
					'link'        => $r['sale_id'] ? '/ventas/' . (int) $r['sale_id'] : '',
					'created_by'  => (int) $r['created_by'],
				)
			);
		}
		return $out;
	}

	/**
	 * Cobranzas de revendedores (confirmadas).
	 *
	 * @param int         $cashbox_id Cashbox id.
	 * @param string|null $from       From.
	 * @param string|null $to         To.
	 * @param int         $limit      Limit.
	 * @return array
	 */
	private static function rows_consignment_payments( int $cashbox_id, ?string $from, ?string $to, int $limit ) : array {
		global $wpdb;

		$pays   = MJERP_Install::table( 'consignment_payments' );
		$cons   = MJERP_Install::table( 'consignees' );
		$vals   = array( $cashbox_id, MJERP_Consignment_Payments_Repo::STATUS_CONFIRMED, MJERP_Consignment_Payments_Repo::KIND_PAYMENT );
		$where  = self::date_where( 'p.payment_date', $from, $to, $vals );
		$vals[] = $limit;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.id, p.payment_date, p.amount, p.currency, p.rate, p.amount_base, p.method, p.reference, p.note,
					p.created_by, p.created_at, p.consignee_id, c.name AS consignee_name
				FROM {$pays} p LEFT JOIN {$cons} c ON c.id = p.consignee_id
				WHERE p.cashbox_id = %d AND p.status = %s AND p.kind = %s {$where}
				ORDER BY p.payment_date DESC, p.id DESC LIMIT %d",
				$vals
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = self::row(
				array(
					'uid'         => 'cobranza-' . (int) $r['id'],
					'source'      => self::SRC_CONSIGNEE,
					'ref_id'      => (int) $r['id'],
					'date'        => (string) $r['payment_date'],
					'created_at'  => (string) $r['created_at'],
					'amount'      => (float) $r['amount'],
					'currency'    => (string) $r['currency'],
					'rate'        => (float) $r['rate'],
					'amount_base' => (float) $r['amount_base'],
					'method'      => (string) $r['method'],
					'reference'   => (string) $r['reference'],
					'note'        => (string) $r['note'],
					/* translators: %s: reseller name */
					'label'       => sprintf( __( 'Cobranza · %s', 'mj-erp' ), (string) $r['consignee_name'] ),
					'detail'      => __( 'Cuenta corriente de reventa', 'mj-erp' ),
					'link'        => $r['consignee_id'] ? '/consignaciones/revendedor/' . (int) $r['consignee_id'] : '',
					'created_by'  => (int) $r['created_by'],
				)
			);
		}
		return $out;
	}

	/**
	 * Gastos de compras que salieron de una caja (la compra no cancelada).
	 *
	 * @param int         $cashbox_id Cashbox id.
	 * @param string|null $from       From.
	 * @param string|null $to         To.
	 * @param int         $limit      Limit.
	 * @return array
	 */
	private static function rows_purchase_expenses( int $cashbox_id, ?string $from, ?string $to, int $limit ) : array {
		global $wpdb;

		$exp    = MJERP_Install::table( 'purchase_expenses' );
		$pur    = MJERP_Install::table( 'purchases' );
		$vals   = array( $cashbox_id, 'cancelada' );
		$where  = self::date_where( 'COALESCE(e.expense_date, p.purchase_date, DATE(e.created_at))', $from, $to, $vals );
		$vals[] = $limit;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT e.id, COALESCE(e.expense_date, p.purchase_date, DATE(e.created_at)) AS d, e.amount, e.currency, e.rate, e.amount_base,
					e.type, e.description, e.created_at, p.id AS purchase_id, p.code AS purchase_code, p.supplier_name
				FROM {$exp} e INNER JOIN {$pur} p ON p.id = e.purchase_id
				WHERE e.cashbox_id = %d AND p.status <> %s {$where}
				ORDER BY d DESC, e.id DESC LIMIT %d",
				$vals
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $r ) {
			$type_label = is_callable( array( 'MJERP_Settings', 'expense_type_label' ) )
				? (string) MJERP_Settings::expense_type_label( (string) $r['type'] )
				: ucfirst( (string) $r['type'] );
			$out[]      = self::row(
				array(
					'uid'         => 'gasto-' . (int) $r['id'],
					'source'      => self::SRC_EXPENSE,
					'ref_id'      => (int) $r['id'],
					'date'        => (string) $r['d'],
					'created_at'  => (string) $r['created_at'],
					'amount'      => -abs( (float) $r['amount'] ),
					'currency'    => (string) $r['currency'],
					'rate'        => (float) $r['rate'],
					'amount_base' => -abs( (float) $r['amount_base'] ),
					'method'      => '',
					'reference'   => (string) $r['description'],
					'note'        => '',
					/* translators: 1: expense type, 2: purchase code */
					'label'       => sprintf( __( '%1$s · Compra %2$s', 'mj-erp' ), $type_label, (string) $r['purchase_code'] ),
					'detail'      => (string) $r['supplier_name'],
					'link'        => $r['purchase_id'] ? '/compras/' . (int) $r['purchase_id'] : '',
					'created_by'  => 0,
				)
			);
		}
		return $out;
	}

	/**
	 * Reintegros de saldo a favor («le di la plata»), salvo los que se revirtieron.
	 *
	 * @param int         $cashbox_id Cashbox id.
	 * @param string|null $from       From.
	 * @param string|null $to         To.
	 * @param int         $limit      Limit.
	 * @return array
	 */
	private static function rows_payouts( int $cashbox_id, ?string $from, ?string $to, int $limit ) : array {
		global $wpdb;

		$credit    = MJERP_Install::table( 'customer_credit' );
		$customers = MJERP_Install::table( 'customers' );
		$vals      = array( $cashbox_id, MJERP_Customer_Credit_Repo::KIND_PAYOUT );
		$where     = self::date_where( 'DATE(c.created_at)', $from, $to, $vals );
		$vals[]    = $limit;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT c.id, c.created_at, c.amount, c.currency, c.rate, c.method, c.reference, c.reason, c.note, c.created_by,
					c.customer_id, cu.name AS customer_name
				FROM {$credit} c LEFT JOIN {$customers} cu ON cu.id = c.customer_id
				WHERE c.cashbox_id = %d AND c.kind = %s
					AND NOT EXISTS (SELECT 1 FROM {$credit} r WHERE r.reverses_id = c.id) {$where}
				ORDER BY c.created_at DESC, c.id DESC LIMIT %d",
				$vals
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$base = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$amount   = -abs( (float) $r['amount'] );
			$currency = strtoupper( (string) $r['currency'] );
			$rate     = (float) $r['rate'] > 0 ? (float) $r['rate'] : self::default_rate( $currency );
			$out[]    = self::row(
				array(
					'uid'         => 'reintegro-' . (int) $r['id'],
					'source'      => self::SRC_PAYOUT,
					'ref_id'      => (int) $r['id'],
					'date'        => substr( (string) $r['created_at'], 0, 10 ),
					'created_at'  => (string) $r['created_at'],
					'amount'      => $amount,
					'currency'    => $currency,
					'rate'        => $rate,
					'amount_base' => MJERP_Helpers::to_base( $amount, $currency, $rate, $base ),
					'method'      => (string) $r['method'],
					'reference'   => (string) $r['reference'],
					'note'        => '' !== (string) $r['reason'] ? (string) $r['reason'] : (string) $r['note'],
					/* translators: %s: customer name */
					'label'       => sprintf( __( 'Reintegro de saldo · %s', 'mj-erp' ), (string) $r['customer_name'] ),
					'detail'      => __( 'Saldo a favor entregado en plata', 'mj-erp' ),
					'link'        => $r['customer_id'] ? '/clientes/' . (int) $r['customer_id'] : '',
					'created_by'  => (int) $r['created_by'],
				)
			);
		}
		return $out;
	}

	/**
	 * Movimientos cargados a mano y transferencias.
	 *
	 * @param int         $cashbox_id Cashbox id.
	 * @param string|null $from       From.
	 * @param string|null $to         To.
	 * @param int         $limit      Limit.
	 * @return array
	 */
	private static function rows_manual( int $cashbox_id, ?string $from, ?string $to, int $limit ) : array {
		global $wpdb;

		$table  = self::table();
		$vals   = array( $cashbox_id );
		$where  = self::date_where( 'movement_date', $from, $to, $vals );
		$vals[] = $limit;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE cashbox_id = %d {$where} ORDER BY movement_date DESC, id DESC LIMIT %d",
				$vals
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $r ) {
			$m     = self::cast( $r );
			$out[] = self::row(
				array(
					'uid'         => 'manual-' . $m['id'],
					'source'      => self::SRC_MANUAL,
					'ref_id'      => $m['id'],
					'date'        => (string) $m['movement_date'],
					'created_at'  => (string) $m['created_at'],
					'amount'      => $m['signed_amount'],
					'currency'    => $m['currency'],
					'rate'        => $m['rate'],
					'amount_base' => $m['signed_amount_base'],
					'method'      => '',
					'reference'   => $m['reference'],
					'note'        => $m['concept'],
					'label'       => $m['kind_label'],
					'detail'      => $m['concept'],
					'link'        => '',
					'created_by'  => $m['created_by'],
					'transfer_id' => $m['transfer_id'],
					'deletable'   => true,
				)
			);
		}
		return $out;
	}

	/**
	 * Shape of one ledger row.
	 *
	 * @param array $data Row data.
	 * @return array
	 */
	private static function row( array $data ) : array {
		$amount     = round( (float) $data['amount'], 4 );
		$created_by = (int) $data['created_by'];
		return array(
			'uid'             => (string) $data['uid'],
			'source'          => (string) $data['source'],
			'source_label'    => self::source_label( (string) $data['source'] ),
			'ref_id'          => (int) $data['ref_id'],
			'date'            => (string) $data['date'],
			'created_at'      => (string) $data['created_at'],
			'direction'       => $amount >= 0 ? self::DIR_IN : self::DIR_OUT,
			'amount'          => $amount,
			'currency'        => strtoupper( (string) $data['currency'] ),
			'rate'            => round( (float) $data['rate'], 6 ),
			'amount_base'     => round( (float) $data['amount_base'], 4 ),
			'method'          => (string) $data['method'],
			'method_label'    => '' !== (string) $data['method'] ? MJERP_Settings::sale_payment_method_label( (string) $data['method'] ) : '',
			'reference'       => (string) $data['reference'],
			'note'            => (string) $data['note'],
			'label'           => (string) $data['label'],
			'detail'          => (string) $data['detail'],
			'link'            => (string) $data['link'],
			'created_by'      => $created_by,
			'created_by_name' => $created_by ? MJERP_Helpers::user_display_name( $created_by ) : '',
			'transfer_id'     => isset( $data['transfer_id'] ) ? (int) $data['transfer_id'] : 0,
			'deletable'       => ! empty( $data['deletable'] ),
		);
	}

	/**
	 * Human label of a ledger source.
	 *
	 * @param string $source Source key.
	 * @return string
	 */
	public static function source_label( string $source ) : string {
		$labels = self::sources();
		return isset( $labels[ $source ] ) ? $labels[ $source ] : ucfirst( $source );
	}

	/**
	 * Sources `{key: label}` for the filter of the screen.
	 *
	 * @return array
	 */
	public static function sources() : array {
		return array(
			self::SRC_SALE      => __( 'Cobros de ventas', 'mj-erp' ),
			self::SRC_RETURN    => __( 'Devoluciones', 'mj-erp' ),
			self::SRC_CONSIGNEE => __( 'Cobranzas de revendedores', 'mj-erp' ),
			self::SRC_EXPENSE   => __( 'Gastos de compras', 'mj-erp' ),
			self::SRC_PAYOUT    => __( 'Reintegros de saldo', 'mj-erp' ),
			self::SRC_MANUAL    => __( 'Cargados a mano', 'mj-erp' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Movimientos a mano
	 * ---------------------------------------------------------------- */

	/**
	 * Records a manual movement.
	 *
	 * @param array $data cashbox_id, direction, amount, currency, rate, kind, concept, reference, movement_date.
	 * @return int|WP_Error
	 */
	public static function create_movement( array $data ) {
		global $wpdb;

		$row = self::prepare( $data );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$blocked = self::closed_day_blocker( $row['cashbox_id'], $row['movement_date'] );
		if ( $blocked ) {
			return $blocked;
		}

		$row['created_by'] = get_current_user_id();
		$row['created_at'] = current_time( 'mysql' );

		$result = $wpdb->insert( self::table(), $row, self::formats( $row, self::FORMATS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo anotar el movimiento.', 'mj-erp' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a manual cash movement was recorded.
		 *
		 * @param int   $id  Movement id.
		 * @param array $row Row as saved.
		 */
		do_action( 'mjerp_cash_movement_created', $id, $row );

		return $id;
	}

	/**
	 * Moves money from one cashbox to another: two legs tied by `transfer_id`.
	 *
	 * Si las cajas tienen monedas distintas, hace falta decir cuánto entra en la de destino
	 * (`amount_to`): la caja no inventa un tipo de cambio.
	 *
	 * @param array $data from_id, to_id, amount, amount_to, movement_date, concept, reference.
	 * @return array|WP_Error { transfer_id, out_id, in_id }
	 */
	public static function transfer( array $data ) {
		global $wpdb;

		$from = MJERP_Cashboxes_Repo::find( MJERP_Helpers::array_get( $data, 'from_id', 0 ) );
		$to   = MJERP_Cashboxes_Repo::find( MJERP_Helpers::array_get( $data, 'to_id', 0 ) );
		if ( ! $from || ! $to ) {
			return new WP_Error( 'mjerp_cashbox_not_found', __( 'Elegí de qué caja sale y a cuál entra.', 'mj-erp' ), array( 'status' => 400 ) );
		}
		if ( $from['id'] === $to['id'] ) {
			return new WP_Error(
				'mjerp_invalid_transfer',
				__( 'La caja de origen y la de destino tienen que ser distintas.', 'mj-erp' ),
				array(
					'status' => 400,
					'field'  => 'to_id',
				)
			);
		}

		$amount = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'amount', 0 ) ), 4 );
		if ( $amount <= self::EPSILON ) {
			return new WP_Error(
				'mjerp_invalid_amount',
				__( 'El importe tiene que ser mayor a cero.', 'mj-erp' ),
				array(
					'status' => 400,
					'field'  => 'amount',
				)
			);
		}

		$amount_to = $amount;
		if ( $from['currency'] !== $to['currency'] ) {
			$amount_to = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'amount_to', 0 ) ), 4 );
			if ( $amount_to <= self::EPSILON ) {
				return new WP_Error(
					'mjerp_invalid_amount',
					__( 'Las cajas tienen monedas distintas: escribí cuánto entra en la de destino.', 'mj-erp' ),
					array(
						'status' => 400,
						'field'  => 'amount_to',
					)
				);
			}
		}

		$concept = MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'concept', '' ), 255 );
		$common  = array(
			'movement_date' => MJERP_Helpers::array_get( $data, 'movement_date', '' ),
			'reference'     => MJERP_Helpers::array_get( $data, 'reference', '' ),
		);

		$out_row = self::prepare(
			array_merge(
				$common,
				array(
					'cashbox_id' => $from['id'],
					'direction'  => self::DIR_OUT,
					'amount'     => $amount,
					'currency'   => $from['currency'],
					/* translators: %s: cashbox name */
					'concept'    => '' !== $concept ? $concept : sprintf( __( 'A %s', 'mj-erp' ), $to['name'] ),
				)
			),
			true
		);
		if ( is_wp_error( $out_row ) ) {
			return $out_row;
		}
		$in_row = self::prepare(
			array_merge(
				$common,
				array(
					'cashbox_id' => $to['id'],
					'direction'  => self::DIR_IN,
					'amount'     => $amount_to,
					'currency'   => $to['currency'],
					/* translators: %s: cashbox name */
					'concept'    => '' !== $concept ? $concept : sprintf( __( 'De %s', 'mj-erp' ), $from['name'] ),
				)
			),
			true
		);
		if ( is_wp_error( $in_row ) ) {
			return $in_row;
		}

		foreach ( array( $out_row, $in_row ) as $leg ) {
			$blocked = self::closed_day_blocker( $leg['cashbox_id'], $leg['movement_date'] );
			if ( $blocked ) {
				return $blocked;
			}
		}

		$now  = current_time( 'mysql' );
		$user = get_current_user_id();
		$ids  = array();
		foreach ( array( $out_row, $in_row ) as $leg ) {
			$leg['created_by'] = $user;
			$leg['created_at'] = $now;
			$result            = $wpdb->insert( self::table(), $leg, self::formats( $leg, self::FORMATS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $result ) {
				// La primera pata quedó sola: se borra para no dejar plata que salió y no llegó.
				foreach ( $ids as $done ) {
					$wpdb->delete( self::table(), array( 'id' => $done ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				return new WP_Error( 'mjerp_db_error', __( 'No se pudo anotar la transferencia.', 'mj-erp' ), array( 'status' => 500 ) );
			}
			$ids[] = (int) $wpdb->insert_id;
		}

		$transfer_id = $ids[0];
		foreach ( $ids as $id ) {
			$wpdb->update( self::table(), array( 'transfer_id' => $transfer_id ), array( 'id' => $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		/**
		 * Fires after money was moved between cashboxes.
		 *
		 * @param int $transfer_id Transfer id (= id of the outgoing leg).
		 * @param int $out_id      Outgoing movement id.
		 * @param int $in_id       Incoming movement id.
		 */
		do_action( 'mjerp_cash_transferred', $transfer_id, $ids[0], $ids[1] );

		return array(
			'transfer_id' => $transfer_id,
			'out_id'      => $ids[0],
			'in_id'       => $ids[1],
		);
	}

	/**
	 * One manual movement.
	 *
	 * @param int $id Movement id.
	 * @return array|null
	 */
	public static function find_movement( $id ) : ?array {
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
	 * Deletes a manual movement (both legs when it is a transfer).
	 *
	 * @param int $id Movement id.
	 * @return true|WP_Error
	 */
	public static function delete_movement( $id ) {
		global $wpdb;

		$movement = self::find_movement( $id );
		if ( ! $movement ) {
			return new WP_Error( 'mjerp_not_found', __( 'El movimiento no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$legs = array( $movement );
		if ( $movement['transfer_id'] > 0 ) {
			$table = self::table();
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE transfer_id = %d", $movement['transfer_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$legs  = array_map( array( __CLASS__, 'cast' ), (array) $rows );
		}

		foreach ( $legs as $leg ) {
			$blocked = self::closed_day_blocker( $leg['cashbox_id'], $leg['movement_date'] );
			if ( $blocked ) {
				return $blocked;
			}
		}

		foreach ( $legs as $leg ) {
			$wpdb->delete( self::table(), array( 'id' => $leg['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		/**
		 * Fires after a manual cash movement (or a whole transfer) was deleted.
		 *
		 * @param int   $id   Movement id asked to delete.
		 * @param array $legs Movements actually deleted.
		 */
		do_action( 'mjerp_cash_movement_deleted', (int) $movement['id'], $legs );

		return true;
	}

	/**
	 * Sanitizes and validates a manual movement.
	 *
	 * @param array $data     Input.
	 * @param bool  $transfer Whether it is a leg of a transfer (kind fixed, concept optional).
	 * @return array|WP_Error
	 */
	private static function prepare( array $data, bool $transfer = false ) {
		$box = MJERP_Cashboxes_Repo::find( MJERP_Helpers::array_get( $data, 'cashbox_id', 0 ) );
		if ( ! $box ) {
			return new WP_Error(
				'mjerp_cashbox_not_found',
				__( 'La caja no existe.', 'mj-erp' ),
				array(
					'status' => 400,
					'field'  => 'cashbox_id',
				)
			);
		}
		if ( empty( $box['is_active'] ) ) {
			return new WP_Error(
				'mjerp_cashbox_inactive',
				__( 'La caja está desactivada: activala para anotarle movimientos.', 'mj-erp' ),
				array(
					'status' => 400,
					'field'  => 'cashbox_id',
				)
			);
		}

		$direction = sanitize_key( (string) MJERP_Helpers::array_get( $data, 'direction', self::DIR_IN ) );
		if ( ! in_array( $direction, array( self::DIR_IN, self::DIR_OUT ), true ) ) {
			return new WP_Error(
				'mjerp_invalid_direction',
				__( 'Decí si la plata entra o sale.', 'mj-erp' ),
				array(
					'status' => 400,
					'field'  => 'direction',
				)
			);
		}

		$amount = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'amount', 0 ) ), 4 );
		if ( $amount <= self::EPSILON ) {
			return new WP_Error(
				'mjerp_invalid_amount',
				__( 'El importe tiene que ser mayor a cero.', 'mj-erp' ),
				array(
					'status' => 400,
					'field'  => 'amount',
				)
			);
		}

		$currency = MJERP_Helpers::sanitize_currency( MJERP_Helpers::array_get( $data, 'currency', $box['currency'] ) );
		if ( '' === $currency ) {
			$currency = $box['currency'];
		}

		$rate = MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'rate', 0 ) );
		if ( $rate <= 0 ) {
			$rate = self::default_rate( $currency );
		}

		$kind = $transfer ? self::KIND_TRANSFER : sanitize_key( (string) MJERP_Helpers::array_get( $data, 'kind', 'otro' ) );
		if ( ! $transfer && ! in_array( $kind, wp_list_pluck( self::kinds(), 'key' ), true ) ) {
			$kind = 'otro';
		}

		$concept = MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'concept', '' ), 255 );
		if ( ! $transfer && '' === $concept && MJERP_Helpers::bool( MJERP_Settings::get( 'cash_require_reason', true ) ) ) {
			return new WP_Error(
				'mjerp_concept_required',
				__( 'Escribí de qué se trató el movimiento.', 'mj-erp' ),
				array(
					'status' => 400,
					'field'  => 'concept',
				)
			);
		}

		$date = MJERP_Helpers::sanitize_date( MJERP_Helpers::array_get( $data, 'movement_date', '' ) );
		if ( ! $date ) {
			$date = current_time( 'Y-m-d' );
		}

		$base = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );

		return array(
			'cashbox_id'    => (int) $box['id'],
			'movement_date' => $date,
			'kind'          => $kind,
			'direction'     => $direction,
			'amount'        => $amount,
			'currency'      => $currency,
			'rate'          => round( $rate, 6 ),
			'amount_base'   => round( MJERP_Helpers::to_base( $amount, $currency, $rate, $base ), 4 ),
			'concept'       => $concept,
			'reference'     => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'reference', '' ), 190 ),
			'transfer_id'   => 0,
		);
	}

	/**
	 * Exchange rate to express a currency in the base one: the one the ERP sells with.
	 *
	 * @param string $currency Currency.
	 * @return float
	 */
	public static function default_rate( string $currency ) : float {
		$base = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
		if ( strtoupper( $currency ) === $base ) {
			return 1.0;
		}
		$rate = class_exists( 'MJERP_Dolar' ) ? (float) MJERP_Dolar::rate() : 0.0;
		if ( $rate <= 0 ) {
			$rate = MJERP_Helpers::to_float( MJERP_Settings::get( 'sale_rate', 0 ) );
		}
		return $rate > 0 ? $rate : 1.0;
	}

	/* ------------------------------------------------------------------
	 * Cierres
	 * ---------------------------------------------------------------- */

	/**
	 * Closures of a cashbox, newest first.
	 *
	 * @param int $cashbox_id Cashbox id.
	 * @param int $limit      Limit.
	 * @return array
	 */
	public static function closures( $cashbox_id, int $limit = 60 ) : array {
		global $wpdb;
		$cashbox_id = absint( $cashbox_id );
		if ( ! $cashbox_id ) {
			return array();
		}
		$table = self::closures_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE cashbox_id = %d ORDER BY closure_date DESC, id DESC LIMIT %d", $cashbox_id, max( 1, min( 500, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_map( array( __CLASS__, 'cast_closure' ), (array) $rows );
	}

	/**
	 * The closure of a cashbox on a day, if any.
	 *
	 * @param int    $cashbox_id Cashbox id.
	 * @param string $date       Y-m-d.
	 * @return array|null
	 */
	public static function find_closure_by_day( $cashbox_id, string $date ) : ?array {
		global $wpdb;
		$table = self::closures_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cashbox_id = %d AND closure_date = %s", absint( $cashbox_id ), $date ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? self::cast_closure( $row ) : null;
	}

	/**
	 * One closure.
	 *
	 * @param int $id Closure id.
	 * @return array|null
	 */
	public static function find_closure( $id ) : ?array {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		$table = self::closures_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? self::cast_closure( $row ) : null;
	}

	/**
	 * What a closure of a day would look like, in the cashbox's own currency.
	 *
	 * @param int    $cashbox_id Cashbox id.
	 * @param string $date       Y-m-d.
	 * @return array|WP_Error
	 */
	public static function preview_closure( $cashbox_id, string $date ) {
		$box = MJERP_Cashboxes_Repo::find( $cashbox_id );
		if ( ! $box ) {
			return new WP_Error( 'mjerp_cashbox_not_found', __( 'La caja no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		$date = MJERP_Helpers::sanitize_date( $date );
		if ( ! $date ) {
			$date = current_time( 'Y-m-d' );
		}

		$cur      = $box['currency'];
		$until    = self::balances( $box['id'], $date );
		$expected = isset( $until[ $cur ] ) ? $until[ $cur ] : 0.0;
		$day      = self::sums( (int) $box['id'], $date, $date );
		$income   = isset( $day[ $cur ] ) ? $day[ $cur ]['income'] : 0.0;
		$expense  = isset( $day[ $cur ] ) ? $day[ $cur ]['expense'] : 0.0;
		$count    = 0;
		foreach ( self::rows( (int) $box['id'], $date, $date ) as $row ) {
			if ( $row['currency'] === $cur ) {
				$count++;
			}
		}

		return array(
			'cashbox_id'       => (int) $box['id'],
			'closure_date'     => $date,
			'currency'         => $cur,
			'opening_balance'  => round( $expected - $income + $expense, 4 ),
			'income_total'     => round( $income, 4 ),
			'expense_total'    => round( $expense, 4 ),
			'expected_balance' => round( $expected, 4 ),
			'movements_count'  => $count,
			'existing'         => self::find_closure_by_day( $box['id'], $date ),
		);
	}

	/**
	 * Closes a day: counts what there is and stores the difference against the ledger.
	 *
	 * @param array $data cashbox_id, closure_date, counted_balance, note.
	 * @return int|WP_Error
	 */
	public static function close_day( array $data ) {
		global $wpdb;

		if ( ! self::closures_enabled() ) {
			return new WP_Error( 'mjerp_closure_disabled', __( 'El cierre de caja está apagado en Configuración → Caja y reportes.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		$date = MJERP_Helpers::sanitize_date( MJERP_Helpers::array_get( $data, 'closure_date', '' ) );
		if ( ! $date ) {
			$date = current_time( 'Y-m-d' );
		}
		if ( $date > current_time( 'Y-m-d' ) ) {
			return new WP_Error(
				'mjerp_invalid_date',
				__( 'No se puede cerrar un día que todavía no pasó.', 'mj-erp' ),
				array(
					'status' => 400,
					'field'  => 'closure_date',
				)
			);
		}

		$preview = self::preview_closure( MJERP_Helpers::array_get( $data, 'cashbox_id', 0 ), $date );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		if ( $preview['existing'] ) {
			return new WP_Error( 'mjerp_closure_exists', __( 'Ese día ya está cerrado. Para rehacerlo, deshacé el cierre primero.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		if ( ! array_key_exists( 'counted_balance', $data ) || '' === (string) $data['counted_balance'] ) {
			return new WP_Error(
				'mjerp_counted_required',
				__( 'Escribí cuánto contaste en la caja.', 'mj-erp' ),
				array(
					'status' => 400,
					'field'  => 'counted_balance',
				)
			);
		}
		$counted = round( MJERP_Helpers::to_float( $data['counted_balance'] ), 4 );

		$row = array(
			'cashbox_id'       => $preview['cashbox_id'],
			'closure_date'     => $date,
			'currency'         => $preview['currency'],
			'opening_balance'  => $preview['opening_balance'],
			'income_total'     => $preview['income_total'],
			'expense_total'    => $preview['expense_total'],
			'expected_balance' => $preview['expected_balance'],
			'counted_balance'  => $counted,
			'difference'       => round( $counted - $preview['expected_balance'], 4 ),
			'movements_count'  => $preview['movements_count'],
			'note'             => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'note', '' ), 255 ),
			'closed_by'        => get_current_user_id(),
			'closed_at'        => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( self::closures_table(), $row, self::formats( $row, self::CLOSURE_FORMATS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo guardar el cierre.', 'mj-erp' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a cashbox day was closed.
		 *
		 * @param int   $id  Closure id.
		 * @param array $row Closure as saved.
		 */
		do_action( 'mjerp_cash_day_closed', $id, $row );

		return $id;
	}

	/**
	 * Undoes a closure.
	 *
	 * @param int $id Closure id.
	 * @return true|WP_Error
	 */
	public static function delete_closure( $id ) {
		global $wpdb;
		$closure = self::find_closure( $id );
		if ( ! $closure ) {
			return new WP_Error( 'mjerp_not_found', __( 'El cierre no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		$result = $wpdb->delete( self::closures_table(), array( 'id' => $closure['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo deshacer el cierre.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after a cashbox closure was undone.
		 *
		 * @param int   $id      Closure id.
		 * @param array $closure Closure that was removed.
		 */
		do_action( 'mjerp_cash_closure_deleted', (int) $closure['id'], $closure );

		return true;
	}

	/**
	 * A closed day does not accept manual movements: the count would stop meaning anything.
	 *
	 * @param int    $cashbox_id Cashbox id.
	 * @param string $date       Y-m-d.
	 * @return WP_Error|null
	 */
	private static function closed_day_blocker( int $cashbox_id, string $date ) : ?WP_Error {
		if ( ! self::closures_enabled() ) {
			return null;
		}
		$closure = self::find_closure_by_day( $cashbox_id, $date );
		if ( ! $closure ) {
			return null;
		}
		return new WP_Error(
			'mjerp_day_closed',
			/* translators: %s: date */
			sprintf( __( 'La caja ya se cerró el %s: deshacé ese cierre para tocar sus movimientos.', 'mj-erp' ), mysql2date( 'd/m/Y', $date . ' 00:00:00' ) ),
			array( 'status' => 409 )
		);
	}

	/* ------------------------------------------------------------------
	 * Shapes
	 * ---------------------------------------------------------------- */

	/**
	 * Row → manual movement shape.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private static function cast( array $row ) : array {
		$amount    = round( (float) self::v( $row, 'amount', 0 ), 4 );
		$base      = round( (float) self::v( $row, 'amount_base', 0 ), 4 );
		$direction = (string) self::v( $row, 'direction', self::DIR_IN );
		$sign      = self::DIR_OUT === $direction ? -1 : 1;
		$kind      = (string) self::v( $row, 'kind', 'otro' );
		return array(
			'id'                 => (int) $row['id'],
			'cashbox_id'         => (int) self::v( $row, 'cashbox_id', 0 ),
			'movement_date'      => (string) self::v( $row, 'movement_date', '' ),
			'kind'               => $kind,
			'kind_label'         => self::kind_label( $kind ),
			'direction'          => $direction,
			'amount'             => $amount,
			'signed_amount'      => $sign * $amount,
			'currency'           => strtoupper( (string) self::v( $row, 'currency', 'ARS' ) ),
			'rate'               => round( (float) self::v( $row, 'rate', 1 ), 6 ),
			'amount_base'        => $base,
			'signed_amount_base' => $sign * $base,
			'concept'            => (string) self::v( $row, 'concept', '' ),
			'reference'          => (string) self::v( $row, 'reference', '' ),
			'transfer_id'        => (int) self::v( $row, 'transfer_id', 0 ),
			'created_by'         => (int) self::v( $row, 'created_by', 0 ),
			'created_at'         => (string) self::v( $row, 'created_at', '' ),
		);
	}

	/**
	 * Row → closure shape.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private static function cast_closure( array $row ) : array {
		$closed_by = (int) self::v( $row, 'closed_by', 0 );
		return array(
			'id'               => (int) $row['id'],
			'cashbox_id'       => (int) self::v( $row, 'cashbox_id', 0 ),
			'closure_date'     => (string) self::v( $row, 'closure_date', '' ),
			'currency'         => strtoupper( (string) self::v( $row, 'currency', 'ARS' ) ),
			'opening_balance'  => round( (float) self::v( $row, 'opening_balance', 0 ), 4 ),
			'income_total'     => round( (float) self::v( $row, 'income_total', 0 ), 4 ),
			'expense_total'    => round( (float) self::v( $row, 'expense_total', 0 ), 4 ),
			'expected_balance' => round( (float) self::v( $row, 'expected_balance', 0 ), 4 ),
			'counted_balance'  => round( (float) self::v( $row, 'counted_balance', 0 ), 4 ),
			'difference'       => round( (float) self::v( $row, 'difference', 0 ), 4 ),
			'movements_count'  => (int) self::v( $row, 'movements_count', 0 ),
			'note'             => (string) self::v( $row, 'note', '' ),
			'closed_by'        => $closed_by,
			'closed_by_name'   => $closed_by ? MJERP_Helpers::user_display_name( $closed_by ) : '',
			'closed_at'        => (string) self::v( $row, 'closed_at', '' ),
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
