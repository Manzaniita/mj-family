<?php
/**
 * Reportes: rentabilidad real de lo vendido.
 *
 * Todo sale de lo que ya guarda cada venta: el precio en moneda base y el costo landed congelado
 * al día de la venta (`total_base`, `cost_base`, `profit_base` en la venta y `line_total_base`,
 * `cost_base` en cada línea). Acá no se calcula nada nuevo: se agrupa por producto, canal, mes y
 * cliente, y se marca lo que se vendió por debajo del costo.
 *
 * Las ventas anuladas no cuentan. El margen es ganancia sobre venta (no sobre costo).
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- los nombres de tabla salen de MJERP_Install::table(), que los filtra a [a-z0-9_]; columna y orden vienen de listas blancas.

/**
 * Class MJERP_Reports_Repo
 */
class MJERP_Reports_Repo {

	const EPSILON = 0.0001;

	/**
	 * Whether the module is on (Configuración → Caja y reportes).
	 *
	 * @return bool
	 */
	public static function enabled() : bool {
		return MJERP_Helpers::bool( MJERP_Settings::get( 'reports_enabled', true ) );
	}

	/**
	 * Minimum margin (%) under which a product is flagged.
	 *
	 * @return float
	 */
	public static function min_margin_pct() : float {
		return max( 0.0, min( 100.0, MJERP_Helpers::to_float( MJERP_Settings::get( 'reports_min_margin_pct', 0 ) ) ) );
	}

	/**
	 * Date range normalised to Y-m-d (defaults: this month).
	 *
	 * @param mixed $from From.
	 * @param mixed $to   To.
	 * @return array {from, to}
	 */
	public static function range( $from, $to ) : array {
		$from = MJERP_Helpers::sanitize_date( $from );
		$to   = MJERP_Helpers::sanitize_date( $to );
		if ( ! $from ) {
			$from = current_time( 'Y-m-01' );
		}
		if ( ! $to ) {
			$to = current_time( 'Y-m-d' );
		}
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		return array(
			'from' => $from,
			'to'   => $to,
		);
	}

	/* ------------------------------------------------------------------
	 * Resumen
	 * ---------------------------------------------------------------- */

	/**
	 * Totals of a period, plus the split by channel and by source.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	public static function summary( string $from, string $to ) : array {
		global $wpdb;

		$sales = MJERP_Install::table( 'sales' );
		$items = MJERP_Install::table( 'sale_items' );
		$void  = MJERP_Sales_Repo::STATUS_VOID;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) AS sales_count,
					COALESCE(SUM(total_base),0) AS revenue_base,
					COALESCE(SUM(cost_base),0) AS cost_base,
					COALESCE(SUM(profit_base),0) AS profit_base,
					COALESCE(SUM(paid_base),0) AS paid_base,
					COALESCE(SUM(qty_total),0) AS qty_total,
					COALESCE(SUM(CASE WHEN cost_base <= 0 THEN 1 ELSE 0 END),0) AS no_cost_count
				FROM {$sales}
				WHERE status <> %s AND sale_date >= %s AND sale_date <= %s",
				$void,
				$from,
				$to
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$revenue = round( (float) MJERP_Helpers::array_get( $row, 'revenue_base', 0 ), 4 );
		$cost    = round( (float) MJERP_Helpers::array_get( $row, 'cost_base', 0 ), 4 );
		$profit  = round( (float) MJERP_Helpers::array_get( $row, 'profit_base', 0 ), 4 );

		$below = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) AS lines_count, COALESCE(SUM(i.cost_base - i.line_total_base),0) AS loss_base
				FROM {$items} i INNER JOIN {$sales} s ON s.id = i.sale_id
				WHERE s.status <> %s AND s.sale_date >= %s AND s.sale_date <= %s
					AND i.cost_base > 0 AND i.line_total_base < i.cost_base - 0.0001",
				$void,
				$from,
				$to
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'from'          => $from,
			'to'            => $to,
			'sales_count'   => (int) MJERP_Helpers::array_get( $row, 'sales_count', 0 ),
			'qty_total'     => round( (float) MJERP_Helpers::array_get( $row, 'qty_total', 0 ), 4 ),
			'revenue_base'  => $revenue,
			'cost_base'     => $cost,
			'profit_base'   => $profit,
			'paid_base'     => round( (float) MJERP_Helpers::array_get( $row, 'paid_base', 0 ), 4 ),
			'margin_pct'    => self::pct( $profit, $revenue ),
			'markup_pct'    => self::pct( $profit, $cost ),
			'no_cost_count' => (int) MJERP_Helpers::array_get( $row, 'no_cost_count', 0 ),
			'below_cost'    => array(
				'lines_count' => (int) MJERP_Helpers::array_get( $below, 'lines_count', 0 ),
				'loss_base'   => round( (float) MJERP_Helpers::array_get( $below, 'loss_base', 0 ), 4 ),
			),
			'by_channel'    => self::grouped( 'channel', $from, $to ),
			'by_source'     => self::grouped( 'source', $from, $to ),
		);
	}

	/**
	 * Sales grouped by a column of the sales table.
	 *
	 * @param string $column channel | source.
	 * @param string $from   From.
	 * @param string $to     To.
	 * @return array
	 */
	private static function grouped( string $column, string $from, string $to ) : array {
		global $wpdb;

		$column = 'source' === $column ? 'source' : 'channel';
		$sales  = MJERP_Install::table( 'sales' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT {$column} AS k, COUNT(*) AS sales_count,
					COALESCE(SUM(total_base),0) AS revenue_base,
					COALESCE(SUM(cost_base),0) AS cost_base,
					COALESCE(SUM(profit_base),0) AS profit_base
				FROM {$sales}
				WHERE status <> %s AND sale_date >= %s AND sale_date <= %s
				GROUP BY {$column}
				ORDER BY profit_base DESC",
				MJERP_Sales_Repo::STATUS_VOID,
				$from,
				$to
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $r ) {
			$key     = (string) $r['k'];
			$revenue = round( (float) $r['revenue_base'], 4 );
			$profit  = round( (float) $r['profit_base'], 4 );
			$label   = 'source' === $column
				? ( 'tienda' === $key ? __( 'Tienda online', 'mj-erp' ) : ( 'erp' === $key ? __( 'Cargadas en el ERP', 'mj-erp' ) : ucfirst( $key ) ) )
				: MJERP_Settings::sale_channel_label( $key );
			$out[]   = array(
				'key'          => $key,
				'label'        => $label,
				'sales_count'  => (int) $r['sales_count'],
				'revenue_base' => $revenue,
				'cost_base'    => round( (float) $r['cost_base'], 4 ),
				'profit_base'  => $profit,
				'margin_pct'   => self::pct( $profit, $revenue ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Por producto
	 * ---------------------------------------------------------------- */

	/**
	 * Sold products of a period with quantity, revenue, cost, profit and margin.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @param array  $args orderby, order, search, page, per_page, only_flagged.
	 * @return array { items, total, page, pages }
	 */
	public static function products( string $from, string $to, array $args = array() ) : array {
		global $wpdb;

		$args = array_merge(
			array(
				'orderby'      => 'profit_base',
				'order'        => 'DESC',
				'search'       => '',
				'page'         => 1,
				'per_page'     => 50,
				'only_flagged' => false,
			),
			$args
		);

		$sales = MJERP_Install::table( 'sales' );
		$items = MJERP_Install::table( 'sale_items' );

		$where = 's.status <> %s AND s.sale_date >= %s AND s.sale_date <= %s';
		$vals  = array( MJERP_Sales_Repo::STATUS_VOID, $from, $to );

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (i.name LIKE %s OR i.sku LIKE %s)';
			array_push( $vals, $like, $like );
		}

		$allowed = array( 'profit_base', 'revenue_base', 'cost_base', 'qty', 'margin_pct', 'name', 'sales_count' );
		$orderby = in_array( (string) $args['orderby'], $allowed, true ) ? (string) $args['orderby'] : 'profit_base';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$having = '';
		if ( MJERP_Helpers::bool( $args['only_flagged'] ) ) {
			// Bajo costo, o con un margen menor al mínimo configurado.
			$min    = self::min_margin_pct();
			$having = ' HAVING (cost_base > 0 AND (revenue_base < cost_base - 0.0001 OR margin_pct < %f))';
			$vals[] = $min;
		}

		$sql = "SELECT i.product_id, i.variation_id, MAX(i.name) AS name, MAX(i.sku) AS sku,
				COUNT(DISTINCT i.sale_id) AS sales_count,
				COALESCE(SUM(i.qty),0) AS qty,
				COALESCE(SUM(i.line_total_base),0) AS revenue_base,
				COALESCE(SUM(i.cost_base),0) AS cost_base,
				COALESCE(SUM(i.line_total_base - i.cost_base),0) AS profit_base,
				CASE WHEN COALESCE(SUM(i.line_total_base),0) > 0
					THEN 100 * COALESCE(SUM(i.line_total_base - i.cost_base),0) / SUM(i.line_total_base) ELSE 0 END AS margin_pct,
				COALESCE(SUM(CASE WHEN i.cost_base > 0 AND i.line_total_base < i.cost_base - 0.0001 THEN 1 ELSE 0 END),0) AS below_cost_lines
			FROM {$items} i INNER JOIN {$sales} s ON s.id = i.sale_id
			WHERE {$where}
			GROUP BY i.product_id, i.variation_id{$having}";

		$count_sql = "SELECT COUNT(*) FROM ({$sql}) t";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$list_sql = "SELECT * FROM ({$sql}) t ORDER BY {$orderby} {$order}, name ASC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $vals, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$min = self::min_margin_pct();
		$out = array();
		foreach ( (array) $rows as $r ) {
			$revenue = round( (float) $r['revenue_base'], 4 );
			$cost    = round( (float) $r['cost_base'], 4 );
			$profit  = round( (float) $r['profit_base'], 4 );
			$qty     = round( (float) $r['qty'], 4 );
			$margin  = self::pct( $profit, $revenue );
			$out[]   = array(
				'product_id'       => (int) $r['product_id'],
				'variation_id'     => (int) $r['variation_id'],
				'name'             => (string) $r['name'],
				'sku'              => (string) $r['sku'],
				'sales_count'      => (int) $r['sales_count'],
				'qty'              => $qty,
				'revenue_base'     => $revenue,
				'cost_base'        => $cost,
				'profit_base'      => $profit,
				'margin_pct'       => $margin,
				'unit_price_base'  => $qty > 0 ? round( $revenue / $qty, 4 ) : 0.0,
				'unit_cost_base'   => $qty > 0 ? round( $cost / $qty, 4 ) : 0.0,
				'no_cost'          => $cost <= self::EPSILON,
				'below_cost'       => $cost > self::EPSILON && $revenue < $cost - self::EPSILON,
				'below_cost_lines' => (int) $r['below_cost_lines'],
				'low_margin'       => $cost > self::EPSILON && $min > 0 && $margin < $min,
			);
		}

		return array(
			'items'    => $out,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		);
	}

	/* ------------------------------------------------------------------
	 * Bajo costo
	 * ---------------------------------------------------------------- */

	/**
	 * Sale lines sold under their landed cost, newest first.
	 *
	 * @param string $from  Y-m-d.
	 * @param string $to    Y-m-d.
	 * @param int    $limit Limit.
	 * @return array
	 */
	public static function below_cost( string $from, string $to, int $limit = 200 ) : array {
		global $wpdb;

		$sales = MJERP_Install::table( 'sales' );
		$items = MJERP_Install::table( 'sale_items' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT i.id, i.sale_id, i.product_id, i.variation_id, i.name, i.sku, i.qty, i.line_total_base, i.cost_base, i.unit_cost_base, i.origin,
					s.code AS sale_code, s.sale_date, s.customer_name, s.channel, s.source
				FROM {$items} i INNER JOIN {$sales} s ON s.id = i.sale_id
				WHERE s.status <> %s AND s.sale_date >= %s AND s.sale_date <= %s
					AND i.cost_base > 0 AND i.line_total_base < i.cost_base - 0.0001
				ORDER BY s.sale_date DESC, i.id DESC LIMIT %d",
				MJERP_Sales_Repo::STATUS_VOID,
				$from,
				$to,
				max( 1, min( 1000, $limit ) )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $r ) {
			$qty     = round( (float) $r['qty'], 4 );
			$revenue = round( (float) $r['line_total_base'], 4 );
			$cost    = round( (float) $r['cost_base'], 4 );
			$out[]   = array(
				'item_id'         => (int) $r['id'],
				'sale_id'         => (int) $r['sale_id'],
				'sale_code'       => (string) $r['sale_code'],
				'sale_date'       => (string) $r['sale_date'],
				'customer_name'   => (string) $r['customer_name'],
				'channel_label'   => MJERP_Settings::sale_channel_label( (string) $r['channel'] ),
				'source'          => (string) $r['source'],
				'product_id'      => (int) $r['product_id'],
				'variation_id'    => (int) $r['variation_id'],
				'name'            => (string) $r['name'],
				'sku'             => (string) $r['sku'],
				'origin'          => (string) $r['origin'],
				'qty'             => $qty,
				'revenue_base'    => $revenue,
				'cost_base'       => $cost,
				'unit_price_base' => $qty > 0 ? round( $revenue / $qty, 4 ) : 0.0,
				'unit_cost_base'  => round( (float) $r['unit_cost_base'], 4 ),
				'loss_base'       => round( $cost - $revenue, 4 ),
			);
		}
		return $out;
	}

	/**
	 * How many lines were sold under cost in a period (for the badge).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int
	 */
	public static function below_cost_count( string $from, string $to ) : int {
		global $wpdb;

		$sales = MJERP_Install::table( 'sales' );
		$items = MJERP_Install::table( 'sale_items' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$items} i INNER JOIN {$sales} s ON s.id = i.sale_id
				WHERE s.status <> %s AND s.sale_date >= %s AND s.sale_date <= %s
					AND i.cost_base > 0 AND i.line_total_base < i.cost_base - 0.0001",
				MJERP_Sales_Repo::STATUS_VOID,
				$from,
				$to
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------
	 * Por mes y por cliente
	 * ---------------------------------------------------------------- */

	/**
	 * Last N months, oldest first, with sales, revenue, cost and profit.
	 *
	 * @param int $months How many months back (including the current one).
	 * @return array
	 */
	public static function monthly( int $months = 12 ) : array {
		global $wpdb;

		$months = max( 1, min( 36, $months ) );
		$sales  = MJERP_Install::table( 'sales' );
		$start  = gmdate( 'Y-m-01', strtotime( current_time( 'Y-m-01' ) . ' -' . ( $months - 1 ) . ' months' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DATE_FORMAT(sale_date, '%%Y-%%m') AS ym, COUNT(*) AS sales_count,
					COALESCE(SUM(total_base),0) AS revenue_base,
					COALESCE(SUM(cost_base),0) AS cost_base,
					COALESCE(SUM(profit_base),0) AS profit_base
				FROM {$sales}
				WHERE status <> %s AND sale_date >= %s
				GROUP BY ym ORDER BY ym ASC",
				MJERP_Sales_Repo::STATUS_VOID,
				$start
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$by_month = array();
		foreach ( (array) $rows as $r ) {
			$by_month[ (string) $r['ym'] ] = $r;
		}

		// Todos los meses del rango, también los que no vendieron nada: la curva tiene que ser continua.
		$out = array();
		for ( $i = 0; $i < $months; $i++ ) {
			$ym      = gmdate( 'Y-m', strtotime( $start . ' +' . $i . ' months' ) );
			$r       = isset( $by_month[ $ym ] ) ? $by_month[ $ym ] : array();
			$revenue = round( (float) MJERP_Helpers::array_get( $r, 'revenue_base', 0 ), 4 );
			$profit  = round( (float) MJERP_Helpers::array_get( $r, 'profit_base', 0 ), 4 );
			$out[]   = array(
				'month'        => $ym,
				'sales_count'  => (int) MJERP_Helpers::array_get( $r, 'sales_count', 0 ),
				'revenue_base' => $revenue,
				'cost_base'    => round( (float) MJERP_Helpers::array_get( $r, 'cost_base', 0 ), 4 ),
				'profit_base'  => $profit,
				'margin_pct'   => self::pct( $profit, $revenue ),
			);
		}
		return $out;
	}

	/**
	 * Customers ranked by profit in a period.
	 *
	 * @param string $from  Y-m-d.
	 * @param string $to    Y-m-d.
	 * @param int    $limit Limit.
	 * @return array
	 */
	public static function customers( string $from, string $to, int $limit = 20 ) : array {
		global $wpdb;

		$sales = MJERP_Install::table( 'sales' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT customer_id, MAX(customer_name) AS customer_name, COUNT(*) AS sales_count,
					COALESCE(SUM(total_base),0) AS revenue_base,
					COALESCE(SUM(profit_base),0) AS profit_base
				FROM {$sales}
				WHERE status <> %s AND sale_date >= %s AND sale_date <= %s
				GROUP BY customer_id, CASE WHEN customer_id = 0 THEN customer_name ELSE '' END
				ORDER BY profit_base DESC LIMIT %d",
				MJERP_Sales_Repo::STATUS_VOID,
				$from,
				$to,
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $r ) {
			$revenue = round( (float) $r['revenue_base'], 4 );
			$profit  = round( (float) $r['profit_base'], 4 );
			$name    = trim( (string) $r['customer_name'] );
			$out[]   = array(
				'customer_id'   => (int) $r['customer_id'],
				'customer_name' => '' !== $name ? $name : __( 'Sin cliente', 'mj-erp' ),
				'sales_count'   => (int) $r['sales_count'],
				'revenue_base'  => $revenue,
				'profit_base'   => $profit,
				'margin_pct'    => self::pct( $profit, $revenue ),
			);
		}
		return $out;
	}

	/**
	 * Percentage, 0 when the base is empty.
	 *
	 * @param float $part  Part.
	 * @param float $total Total.
	 * @return float
	 */
	private static function pct( float $part, float $total ) : float {
		return abs( $total ) > self::EPSILON ? round( 100 * $part / $total, 2 ) : 0.0;
	}
}
