<?php
/**
 * Sales, their lines and the money collected against them.
 *
 * A sale row is the ERP's record of something that left the shop. When it has an `order_id` the
 * WooCommerce order is still the customer-facing document (that is what the customer sees, what the
 * app notifies about and what Woo reports on); this table adds what Woo does not keep: the landed
 * cost frozen at the time of the sale, which lines came out of a reseller's consignment, and what
 * was actually collected.
 *
 * Every write goes through here so the cached totals (`recalculate`) and the payment status
 * (`sync_payments`) can never drift from the lines.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Sales_Repo
 */
class MJERP_Sales_Repo {

	const STATUS_DRAFT     = 'borrador';
	const STATUS_CONFIRMED = 'confirmada';
	const STATUS_VOID      = 'anulada';

	const SOURCE_ERP    = 'erp';
	const SOURCE_SHOP   = 'tienda';
	const SOURCE_PORTAL = 'portal';

	const ORIGIN_STOCK       = 'deposito';
	const ORIGIN_CONSIGNMENT = 'consignacion';

	const PAY_UNPAID  = 'impaga';
	const PAY_PARTIAL = 'parcial';
	const PAY_PAID    = 'pagada';

	/*
	 * Entrega: lo vendido todavía tiene que llegar a manos del cliente. Es un circuito aparte del
	 * cobro — se puede haber cobrado un envío que no salió, y se puede haber entregado algo que se
	 * paga después — así que lleva su propio estado.
	 */
	const DELIVERY_PENDING   = 'pendiente';
	const DELIVERY_ON_ROUTE  = 'en_camino';
	const DELIVERY_DELIVERED = 'entregado';

	const EPSILON = 0.0001;

	const FORMATS = array(
		'code'             => '%s',
		'order_id'         => '%d',
		'order_number'     => '%s',
		'order_status'     => '%s',
		'source'           => '%s',
		'channel'          => '%s',
		'status'           => '%s',
		'sale_date'        => '%s',
		'customer_id'      => '%d',
		'customer_user_id' => '%d',
		'customer_name'    => '%s',
		'customer_email'   => '%s',
		'customer_phone'   => '%s',
		'consignee_id'     => '%d',
		'price_list_key'   => '%s',
		'currency'         => '%s',
		'rate'             => '%f',
		'items_count'      => '%d',
		'qty_total'        => '%f',
		'subtotal'         => '%f',
		'discount_total'   => '%f',
		'shipping_total'   => '%f',
		'tax_total'        => '%f',
		'total'            => '%f',
		'total_base'       => '%f',
		'cost_base'        => '%f',
		'profit_base'      => '%f',
		'consignment_base' => '%f',
		'consignment_total' => '%f',
		'consignment_qty'  => '%f',
		'paid_total'       => '%f',
		'paid_base'        => '%f',
		'payment_status'   => '%s',
		'payment_method'   => '%s',
		'cashbox_id'       => '%d',
		'wants_order'      => '%d',
		'delivery_method'  => '%s',
		'delivery_status'  => '%s',
		'delivery_note'    => '%s',
		'delivered_at'     => '%s',
		'delivered_by'     => '%d',
		'stock_applied'    => '%d',
		'note'             => '%s',
		'created_by'       => '%d',
		'voided_by'        => '%d',
		'voided_at'        => '%s',
		'created_at'       => '%s',
		'updated_at'       => '%s',
	);

	const ITEM_FORMATS = array(
		'sale_id'                => '%d',
		'order_item_id'          => '%d',
		'product_id'             => '%d',
		'variation_id'           => '%d',
		'name'                   => '%s',
		'sku'                    => '%s',
		'qty'                    => '%f',
		'unit_price'             => '%f',
		'discount'               => '%f',
		'line_total'             => '%f',
		'line_total_base'        => '%f',
		'unit_cost_base'         => '%f',
		'cost_base'              => '%f',
		'origin'                 => '%s',
		'consignee_id'           => '%d',
		'consignment_item_id'    => '%d',
		'consignment_sale_id'    => '%d',
		'consignment_price_base' => '%f',
		'sort_order'             => '%d',
		'created_at'             => '%s',
		'updated_at'             => '%s',
	);

	/* ------------------------------------------------------------------
	 * Catalogue
	 * ---------------------------------------------------------------- */

	/**
	 * Sale statuses.
	 *
	 * @return string[]
	 */
	public static function statuses() : array {
		return array( self::STATUS_DRAFT, self::STATUS_CONFIRMED, self::STATUS_VOID );
	}

	/**
	 * Delivery statuses.
	 *
	 * @return string[]
	 */
	public static function delivery_statuses() : array {
		return array( self::DELIVERY_PENDING, self::DELIVERY_ON_ROUTE, self::DELIVERY_DELIVERED );
	}

	/**
	 * Sale sources.
	 *
	 * @return string[]
	 */
	public static function sources() : array {
		return array( self::SOURCE_ERP, self::SOURCE_SHOP, self::SOURCE_PORTAL );
	}

	private static function table() : string {
		return MJERP_Install::table( 'sales' );
	}

	private static function items_table() : string {
		return MJERP_Install::table( 'sale_items' );
	}

	/* ------------------------------------------------------------------
	 * Read
	 * ---------------------------------------------------------------- */

	/**
	 * Paginated listing.
	 *
	 * @param array $args search, status, source, channel, consignee_id, customer_id, customer_user_id,
	 *                    payment_status, date_from, date_to, only_consignment, page, per_page,
	 *                    orderby, order.
	 * @return array { items, total, page, per_page, pages, totals }
	 */
	public static function query( array $args ) : array {
		global $wpdb;

		$defaults = array(
			'search'           => '',
			'status'           => '',
			'source'           => '',
			'channel'          => '',
			'consignee_id'     => 0,
			'customer_id'      => 0,
			'customer_user_id' => 0,
			'payment_status'   => '',
			'delivery_status'  => '',
			'delivery_method'  => '',
			'date_from'        => '',
			'date_to'          => '',
			'only_consignment' => false,
			'page'             => 1,
			'per_page'         => 20,
			'orderby'          => 'sale_date',
			'order'            => 'DESC',
		);
		$args = array_merge( $defaults, $args );

		$table = self::table();
		$where = array( '1=1' );
		$vals  = array();

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(code LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR order_number LIKE %s OR note LIKE %s)';
			array_push( $vals, $like, $like, $like, $like, $like );
		}

		foreach ( array( 'status', 'source', 'channel', 'payment_status', 'delivery_status', 'delivery_method' ) as $key ) {
			$value = sanitize_key( (string) $args[ $key ] );
			if ( '' !== $value ) {
				$where[] = "{$key} = %s";
				$vals[]  = $value;
			}
		}

		foreach ( array( 'consignee_id', 'customer_id', 'customer_user_id' ) as $key ) {
			$value = absint( $args[ $key ] );
			if ( $value > 0 ) {
				$where[] = "{$key} = %d";
				$vals[]  = $value;
			}
		}

		if ( MJERP_Helpers::bool( $args['only_consignment'] ) ) {
			$where[] = 'consignment_qty > 0';
		}

		$from = MJERP_Helpers::sanitize_date( $args['date_from'] );
		if ( $from ) {
			$where[] = 'sale_date >= %s';
			$vals[]  = $from;
		}
		$to = MJERP_Helpers::sanitize_date( $args['date_to'] );
		if ( $to ) {
			$where[] = 'sale_date <= %s';
			$vals[]  = $to;
		}

		$where_sql = implode( ' AND ', $where );

		$allowed_orderby = array( 'sale_date', 'code', 'total', 'total_base', 'profit_base', 'created_at', 'id', 'customer_name' );
		$orderby         = in_array( (string) $args['orderby'], $allowed_orderby, true ) ? (string) $args['orderby'] : 'sale_date';
		$order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( empty( $vals )
			? $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			: $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$sums_sql = "SELECT COALESCE(SUM(total_base),0) AS total_base, COALESCE(SUM(profit_base),0) AS profit_base,
			COALESCE(SUM(consignment_base),0) AS consignment_base, COALESCE(SUM(paid_base),0) AS paid_base
			FROM {$table} WHERE {$where_sql} AND status <> '" . self::STATUS_VOID . "'";
		$sums     = empty( $vals )
			? $wpdb->get_row( $sums_sql, ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			: $wpdb->get_row( $wpdb->prepare( $sums_sql, $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$list_val = array_merge( $vals, array( $per_page, $offset ) );
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_val ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::cast( $row );
		}
		$items = self::attach_items_preview( $items );

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
			'totals'   => array(
				'total_base'       => round( (float) MJERP_Helpers::array_get( $sums, 'total_base', 0 ), 4 ),
				'profit_base'      => round( (float) MJERP_Helpers::array_get( $sums, 'profit_base', 0 ), 4 ),
				'consignment_base' => round( (float) MJERP_Helpers::array_get( $sums, 'consignment_base', 0 ), 4 ),
				'paid_base'        => round( (float) MJERP_Helpers::array_get( $sums, 'paid_base', 0 ), 4 ),
			),
		);
	}

	/**
	 * One sale.
	 *
	 * @param int $id Sale id.
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
	 * The sale mirroring a WooCommerce order, if any.
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public static function find_by_order( $order_id ) : ?array {
		global $wpdb;
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC LIMIT 1", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * A sale with its lines and payments.
	 *
	 * @param int $id Sale id.
	 * @return array|null
	 */
	public static function get_full( $id ) : ?array {
		$sale = self::find( $id );
		if ( ! $sale ) {
			return null;
		}
		$sale['items']    = self::items( $sale['id'] );
		$sale['payments'] = class_exists( 'MJERP_Sale_Payments_Repo' ) ? MJERP_Sale_Payments_Repo::for_sale( $sale['id'] ) : array();
		return $sale;
	}

	/**
	 * Lines of a sale.
	 *
	 * @param int $sale_id Sale id.
	 * @return array
	 */
	public static function items( $sale_id ) : array {
		global $wpdb;
		$sale_id = absint( $sale_id );
		if ( ! $sale_id ) {
			return array();
		}
		$table = self::items_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE sale_id = %d ORDER BY sort_order ASC, id ASC", $sale_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast_item( $row );
		}
		return $out;
	}

	/**
	 * One line.
	 *
	 * @param int $item_id Line id.
	 * @return array|null
	 */
	/**
	 * Adds a short list of what each sale carried, for the listing.
	 *
	 * Sin esto hay que abrir una venta para saber qué se vendió, y el listado no contesta la
	 * pregunta más frecuente de todas. Va en UNA consulta para todas las ventas de la página: una
	 * por fila convertiría cada listado en veinte consultas.
	 *
	 * @param array $sales Casted sales.
	 * @return array
	 */
	private static function attach_items_preview( array $sales ) : array {
		global $wpdb;

		if ( empty( $sales ) ) {
			return $sales;
		}

		$ids = array();
		foreach ( $sales as $sale ) {
			$ids[] = (int) $sale['id'];
		}

		/**
		 * Cuántas líneas se traen por venta para el resumen.
		 *
		 * @param int $limit Lines per sale.
		 */
		$limit = max( 1, (int) apply_filters( 'mjerp_sale_items_preview_limit', 20 ) );

		$table        = self::items_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT sale_id, name, sku, qty, origin FROM {$table} WHERE sale_id IN ({$placeholders}) ORDER BY sale_id ASC, sort_order ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids
			),
			ARRAY_A
		);

		$by_sale = array();
		foreach ( (array) $rows as $row ) {
			$sale_id = (int) $row['sale_id'];
			if ( isset( $by_sale[ $sale_id ] ) && count( $by_sale[ $sale_id ] ) >= $limit ) {
				continue;
			}
			$by_sale[ $sale_id ][] = array(
				'name'   => (string) $row['name'],
				'sku'    => (string) $row['sku'],
				'qty'    => round( (float) $row['qty'], 4 ),
				'origin' => (string) $row['origin'],
			);
		}

		foreach ( $sales as $index => $sale ) {
			$sales[ $index ]['items_preview'] = isset( $by_sale[ (int) $sale['id'] ] ) ? $by_sale[ (int) $sale['id'] ] : array();
		}

		return $sales;
	}

	public static function find_item( $item_id ) : ?array {
		global $wpdb;
		$item_id = absint( $item_id );
		if ( ! $item_id ) {
			return null;
		}
		$table = self::items_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? self::cast_item( $row ) : null;
	}

	/**
	 * Sales of one reseller (their consignment lines).
	 *
	 * @param int $consignee_id Consignee id.
	 * @param int $limit        Max rows.
	 * @return array
	 */
	public static function for_consignee( $consignee_id, int $limit = 100 ) : array {
		$result = self::query(
			array(
				'consignee_id' => $consignee_id,
				'per_page'     => max( 1, min( 200, $limit ) ),
				'orderby'      => 'sale_date',
				'order'        => 'DESC',
			)
		);
		return $result['items'];
	}

	/* ------------------------------------------------------------------
	 * Write
	 * ---------------------------------------------------------------- */

	/**
	 * Inserts a sale with its lines.
	 *
	 * Deliberately dumb: it validates shapes and writes rows. Deciding what a line costs, whether it
	 * comes out of a consignment and whether a WooCommerce order has to be emitted is MJERP_Sales'
	 * job, so the same storage serves the ERP screen, the shop bridge and the portal.
	 *
	 * @param array $data  Sale fields plus `items` (list of line arrays).
	 * @return int|WP_Error New sale id.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? array_values( $data['items'] ) : array();
		if ( empty( $items ) ) {
			return new WP_Error( 'mjerp_no_items', __( 'La venta necesita al menos un producto.', 'mj-erp' ), array( 'status' => 400, 'field' => 'items' ) );
		}

		$now  = current_time( 'mysql' );
		$code = isset( $data['code'] ) ? self::sanitize_code( $data['code'] ) : '';
		if ( '' === $code || self::code_exists( $code ) ) {
			$code = self::next_code();
		}

		$status = self::sanitize_status( isset( $data['status'] ) ? $data['status'] : self::STATUS_CONFIRMED );
		$source = self::sanitize_source( isset( $data['source'] ) ? $data['source'] : self::SOURCE_ERP );

		$currency = MJERP_Helpers::sanitize_currency(
			isset( $data['currency'] ) ? $data['currency'] : self::shop_currency(),
			null
		);
		$rate = isset( $data['rate'] ) ? MJERP_Helpers::to_float( $data['rate'] ) : 0.0;
		if ( $rate <= 0 ) {
			$rate = self::default_rate( $currency );
		}

		$date = isset( $data['sale_date'] ) ? MJERP_Helpers::sanitize_date( $data['sale_date'] ) : null;
		if ( null === $date ) {
			$date = current_time( 'Y-m-d' );
		}

		$delivery = self::initial_delivery( $data, $now );

		$row = array(
			'code'             => $code,
			'order_id'         => isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0,
			'order_number'     => isset( $data['order_number'] ) ? MJERP_Helpers::text( $data['order_number'], 60 ) : '',
			'order_status'     => isset( $data['order_status'] ) ? sanitize_key( (string) $data['order_status'] ) : '',
			'source'           => $source,
			'channel'          => self::sanitize_channel( isset( $data['channel'] ) ? $data['channel'] : '' ),
			'status'           => $status,
			'sale_date'        => $date,
			'customer_id'      => isset( $data['customer_id'] ) ? absint( $data['customer_id'] ) : 0,
			'customer_user_id' => isset( $data['customer_user_id'] ) ? absint( $data['customer_user_id'] ) : 0,
			'customer_name'    => isset( $data['customer_name'] ) ? MJERP_Helpers::text( $data['customer_name'], 190 ) : '',
			'customer_email'   => isset( $data['customer_email'] ) ? sanitize_email( (string) $data['customer_email'] ) : '',
			'customer_phone'   => isset( $data['customer_phone'] ) ? MJERP_Helpers::text( $data['customer_phone'], 60 ) : '',
			'consignee_id'     => isset( $data['consignee_id'] ) ? absint( $data['consignee_id'] ) : 0,
			'price_list_key'   => isset( $data['price_list_key'] ) ? sanitize_key( (string) $data['price_list_key'] ) : '',
			'currency'         => $currency,
			'rate'             => round( $rate, 6 ),
			'items_count'      => 0,
			'qty_total'        => 0,
			'subtotal'         => 0,
			'discount_total'   => isset( $data['discount_total'] ) ? round( max( 0, MJERP_Helpers::to_float( $data['discount_total'] ) ), 4 ) : 0,
			'shipping_total'   => isset( $data['shipping_total'] ) ? round( MJERP_Helpers::to_float( $data['shipping_total'] ), 4 ) : 0,
			'tax_total'        => isset( $data['tax_total'] ) ? round( MJERP_Helpers::to_float( $data['tax_total'] ), 4 ) : 0,
			'total'            => 0,
			'total_base'       => 0,
			'cost_base'        => 0,
			'profit_base'      => 0,
			'consignment_base' => 0,
			'consignment_total' => 0,
			'consignment_qty'  => 0,
			'paid_total'       => 0,
			'paid_base'        => 0,
			'payment_status'   => self::PAY_UNPAID,
			'payment_method'   => isset( $data['payment_method'] ) ? sanitize_key( (string) $data['payment_method'] ) : '',
			'cashbox_id'       => isset( $data['cashbox_id'] ) ? absint( $data['cashbox_id'] ) : 0,
			/*
			 * ¿Esta venta tiene que terminar en un pedido de WooCommerce? Se decide al cargarla y
			 * se guarda, porque el pedido puede emitirse mucho después —cuando se cobre o se
			 * entregue— y para entonces ya nadie se acuerda de la casilla que se destildó.
			 */
			'wants_order'      => array_key_exists( 'wants_order', $data ) ? ( MJERP_Helpers::bool( $data['wants_order'] ) ? 1 : 0 ) : 1,
			'delivery_method'  => $delivery['method'],
			'delivery_status'  => $delivery['status'],
			'delivery_note'    => $delivery['note'],
			'delivered_at'     => $delivery['delivered_at'],
			'delivered_by'     => $delivery['delivered_by'],
			'stock_applied'    => ! empty( $data['stock_applied'] ) ? 1 : 0,
			'note'             => isset( $data['note'] ) ? sanitize_textarea_field( (string) $data['note'] ) : '',
			'created_by'       => isset( $data['created_by'] ) ? absint( $data['created_by'] ) : get_current_user_id(),
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		$result = $wpdb->insert( self::table(), $row, self::formats( $row, self::FORMATS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo registrar la venta.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$sale_id = (int) $wpdb->insert_id;

		$sort = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item['sort_order'] = isset( $item['sort_order'] ) ? (int) $item['sort_order'] : $sort;
			self::add_item( $sale_id, $item );
			$sort++;
		}

		self::recalculate( $sale_id );

		/**
		 * Fires after a sale was stored.
		 *
		 * @param int   $sale_id Sale id.
		 * @param array $data    Input data.
		 */
		do_action( 'mjerp_sale_created', $sale_id, $data );

		return $sale_id;
	}

	/**
	 * Adds one line to a sale.
	 *
	 * @param int   $sale_id Sale id.
	 * @param array $data    Line fields.
	 * @return int|WP_Error New line id.
	 */
	public static function add_item( $sale_id, array $data ) {
		global $wpdb;

		$sale_id = absint( $sale_id );
		if ( ! $sale_id ) {
			return new WP_Error( 'mjerp_invalid', __( 'Venta inválida.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		$qty = round( MJERP_Helpers::to_float( isset( $data['qty'] ) ? $data['qty'] : 0 ), 4 );
		if ( $qty <= 0 ) {
			return new WP_Error( 'mjerp_invalid_qty', __( 'La cantidad tiene que ser mayor a cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'qty' ) );
		}

		$unit_price = round( MJERP_Helpers::to_float( isset( $data['unit_price'] ) ? $data['unit_price'] : 0 ), 6 );
		$discount   = round( max( 0, MJERP_Helpers::to_float( isset( $data['discount'] ) ? $data['discount'] : 0 ) ), 4 );

		$line_total = isset( $data['line_total'] ) && '' !== $data['line_total'] && null !== $data['line_total']
			? round( MJERP_Helpers::to_float( $data['line_total'] ), 4 )
			: round( ( $qty * $unit_price ) - $discount, 4 );

		$sale = self::find( $sale_id );
		$rate = $sale ? (float) $sale['rate'] : 1.0;

		$unit_cost_base = round( MJERP_Helpers::to_float( isset( $data['unit_cost_base'] ) ? $data['unit_cost_base'] : 0 ), 6 );

		$now = current_time( 'mysql' );
		$row = array(
			'sale_id'                => $sale_id,
			'order_item_id'          => isset( $data['order_item_id'] ) ? absint( $data['order_item_id'] ) : 0,
			'product_id'             => isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0,
			'variation_id'           => isset( $data['variation_id'] ) ? absint( $data['variation_id'] ) : 0,
			'name'                   => MJERP_Helpers::text( isset( $data['name'] ) ? $data['name'] : '', 255 ),
			'sku'                    => MJERP_Helpers::text( isset( $data['sku'] ) ? $data['sku'] : '', 100 ),
			'qty'                    => $qty,
			'unit_price'             => $unit_price,
			'discount'               => $discount,
			'line_total'             => $line_total,
			'line_total_base'        => round( self::to_base( $line_total, $rate ), 4 ),
			'unit_cost_base'         => $unit_cost_base,
			'cost_base'              => round( $unit_cost_base * $qty, 4 ),
			'origin'                 => self::sanitize_origin( isset( $data['origin'] ) ? $data['origin'] : self::ORIGIN_STOCK ),
			'consignee_id'           => isset( $data['consignee_id'] ) ? absint( $data['consignee_id'] ) : 0,
			'consignment_item_id'    => isset( $data['consignment_item_id'] ) ? absint( $data['consignment_item_id'] ) : 0,
			'consignment_sale_id'    => isset( $data['consignment_sale_id'] ) ? absint( $data['consignment_sale_id'] ) : 0,
			'consignment_price_base' => round( MJERP_Helpers::to_float( isset( $data['consignment_price_base'] ) ? $data['consignment_price_base'] : 0 ), 6 ),
			'sort_order'             => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'created_at'             => $now,
			'updated_at'             => $now,
		);

		if ( '' === $row['name'] ) {
			$row['name'] = __( 'Producto', 'mj-erp' );
		}

		$result = $wpdb->insert( self::items_table(), $row, self::formats( $row, self::ITEM_FORMATS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo guardar el producto de la venta.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Links a line to the consignment sale it generated.
	 *
	 * @param int $item_id             Line id.
	 * @param int $consignment_sale_id Consignment sale id.
	 * @return void
	 */
	public static function link_consignment_sale( $item_id, $consignment_sale_id ) : void {
		global $wpdb;
		$item_id = absint( $item_id );
		if ( ! $item_id ) {
			return;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::items_table(),
			array(
				'consignment_sale_id' => absint( $consignment_sale_id ),
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => $item_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delivery a sale is born with.
	 *
	 * El método elegido decide el estado inicial: lo que se entrega en el acto nace entregado y lo
	 * que hay que llevar nace pendiente. Sin eso, cada venta de mostrador habría que marcarla a
	 * mano y la lista de pendientes dejaría de significar «esto falta entregar».
	 *
	 * @param array  $data Input data.
	 * @param string $now  Current mysql datetime.
	 * @return array { method, status, note, delivered_at, delivered_by }
	 */
	private static function initial_delivery( array $data, string $now ) : array {
		$method = self::sanitize_delivery_method( MJERP_Helpers::array_get( $data, 'delivery_method', '' ) );
		$note   = MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'delivery_note', '' ), 190 );

		/*
		 * Con el paso apagado no hay nada que seguir: la venta nace entregada y sin método. Si
		 * mañana se enciende, lo viejo no aparece como una montaña de entregas pendientes que
		 * nunca existieron.
		 */
		if ( ! MJERP_Settings::delivery_enabled() ) {
			return array(
				'method'       => '',
				'status'       => self::DELIVERY_DELIVERED,
				'note'         => '',
				'delivered_at' => $now,
				'delivered_by' => get_current_user_id(),
			);
		}

		if ( array_key_exists( 'delivery_status', $data ) && '' !== (string) $data['delivery_status'] ) {
			$status = self::sanitize_delivery_status( $data['delivery_status'] );
		} elseif ( array_key_exists( 'delivered', $data ) ) {
			$status = MJERP_Helpers::bool( $data['delivered'] ) ? self::DELIVERY_DELIVERED : self::DELIVERY_PENDING;
		} else {
			$status = MJERP_Settings::delivery_method_is_instant( $method )
				? self::DELIVERY_DELIVERED
				: self::DELIVERY_PENDING;
		}

		$delivered = self::DELIVERY_DELIVERED === $status;

		return array(
			'method'       => $method,
			'status'       => $status,
			'note'         => $note,
			'delivered_at' => $delivered ? $now : null,
			'delivered_by' => $delivered ? get_current_user_id() : 0,
		);
	}

	/**
	 * Records what happened with the delivery of a sale.
	 *
	 * Es la operación de un toque: desde la lista de ventas se marca entregado sin abrir nada. La
	 * fecha se escribe sola al pasar a «entregado» y se borra si se vuelve atrás, así el dato nunca
	 * dice que algo se entregó un día en que todavía no había salido.
	 *
	 * @param int   $id   Sale id.
	 * @param array $data { status, method, note }.
	 * @return true|WP_Error
	 */
	public static function set_delivery( $id, array $data ) {
		global $wpdb;

		$sale = self::find( $id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( self::STATUS_VOID === $sale['status'] ) {
			return new WP_Error( 'mjerp_sale_void', __( 'La venta está anulada: no hay nada que entregar.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		$row = array();

		if ( array_key_exists( 'method', $data ) ) {
			$row['delivery_method'] = self::sanitize_delivery_method( $data['method'] );
		}
		if ( array_key_exists( 'note', $data ) ) {
			$row['delivery_note'] = MJERP_Helpers::text( $data['note'], 190 );
		}

		if ( array_key_exists( 'status', $data ) ) {
			$status                 = self::sanitize_delivery_status( $data['status'] );
			$row['delivery_status'] = $status;

			if ( self::DELIVERY_DELIVERED === $status ) {
				$row['delivered_at'] = ! empty( $sale['delivered_at'] ) ? $sale['delivered_at'] : current_time( 'mysql' );
				$row['delivered_by'] = ! empty( $sale['delivered_by'] ) ? (int) $sale['delivered_by'] : get_current_user_id();
			} else {
				$row['delivered_at'] = null;
				$row['delivered_by'] = 0;
			}
		}

		if ( empty( $row ) ) {
			return true;
		}

		$row['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::table(), $row, array( 'id' => $sale['id'] ), self::formats( $row, self::FORMATS ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo registrar la entrega.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after the delivery of a sale changed.
		 *
		 * @param int   $id  Sale id.
		 * @param array $row Changed columns.
		 */
		do_action( 'mjerp_sale_delivery_changed', (int) $sale['id'], $row );

		return true;
	}

	/**
	 * Updates the editable fields of a sale (never the lines: those come from the order).
	 *
	 * @param int   $id   Sale id.
	 * @param array $data channel, sale_date, note, customer_*, payment_method, cashbox_id, status.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$sale = self::find( $id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$row = array();

		if ( array_key_exists( 'channel', $data ) ) {
			$row['channel'] = self::sanitize_channel( $data['channel'] );
		}
		if ( array_key_exists( 'sale_date', $data ) ) {
			$date = MJERP_Helpers::sanitize_date( $data['sale_date'] );
			if ( $date ) {
				$row['sale_date'] = $date;
			}
		}
		if ( array_key_exists( 'note', $data ) ) {
			$row['note'] = sanitize_textarea_field( (string) $data['note'] );
		}
		if ( array_key_exists( 'customer_id', $data ) ) {
			$row['customer_id'] = absint( $data['customer_id'] );
		}
		if ( array_key_exists( 'customer_user_id', $data ) ) {
			$row['customer_user_id'] = absint( $data['customer_user_id'] );
		}
		if ( array_key_exists( 'customer_name', $data ) ) {
			$row['customer_name'] = MJERP_Helpers::text( $data['customer_name'], 190 );
		}
		if ( array_key_exists( 'customer_email', $data ) ) {
			$row['customer_email'] = sanitize_email( (string) $data['customer_email'] );
		}
		if ( array_key_exists( 'customer_phone', $data ) ) {
			$row['customer_phone'] = MJERP_Helpers::text( $data['customer_phone'], 60 );
		}
		if ( array_key_exists( 'payment_method', $data ) ) {
			$row['payment_method'] = sanitize_key( (string) $data['payment_method'] );
		}
		if ( array_key_exists( 'cashbox_id', $data ) ) {
			$row['cashbox_id'] = absint( $data['cashbox_id'] );
		}
		if ( array_key_exists( 'order_status', $data ) ) {
			$row['order_status'] = sanitize_key( (string) $data['order_status'] );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$row['status'] = self::sanitize_status( $data['status'] );
		}
		if ( array_key_exists( 'wants_order', $data ) ) {
			$row['wants_order'] = MJERP_Helpers::bool( $data['wants_order'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'delivery_method', $data ) ) {
			$row['delivery_method'] = self::sanitize_delivery_method( $data['delivery_method'] );
		}
		if ( array_key_exists( 'delivery_note', $data ) ) {
			$row['delivery_note'] = MJERP_Helpers::text( $data['delivery_note'], 190 );
		}

		if ( empty( $row ) ) {
			return true;
		}

		$row['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::table(), $row, array( 'id' => $sale['id'] ), self::formats( $row, self::FORMATS ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar la venta.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after a sale was updated.
		 *
		 * @param int   $id  Sale id.
		 * @param array $row Changed columns.
		 */
		do_action( 'mjerp_sale_updated', (int) $sale['id'], $row );

		return true;
	}

	/**
	 * Marks a sale as void.
	 *
	 * The row survives on purpose: a voided sale still has to be visible next to the order it came
	 * from, otherwise a cancelled order silently disappears from the ERP's history.
	 *
	 * @param int    $id   Sale id.
	 * @param string $note Reason appended to the note.
	 * @return true|WP_Error
	 */
	public static function void( $id, string $note = '' ) {
		global $wpdb;

		$sale = self::find( $id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( self::STATUS_VOID === $sale['status'] ) {
			return true;
		}

		$row = array(
			'status'     => self::STATUS_VOID,
			'voided_by'  => get_current_user_id(),
			'voided_at'  => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);
		if ( '' !== $note ) {
			$row['note'] = trim( $sale['note'] . "\n" . sanitize_textarea_field( $note ) );
		}

		$result = $wpdb->update( self::table(), $row, array( 'id' => $sale['id'] ), self::formats( $row, self::FORMATS ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo anular la venta.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after a sale was voided.
		 *
		 * @param int   $id   Sale id.
		 * @param array $sale Sale before voiding.
		 */
		do_action( 'mjerp_sale_voided', (int) $sale['id'], $sale );

		return true;
	}

	/**
	 * Deletes a sale, its lines and its payments.
	 *
	 * @param int $id Sale id.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$sale = self::find( $id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$wpdb->delete( self::items_table(), array( 'sale_id' => $sale['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( class_exists( 'MJERP_Sale_Payments_Repo' ) ) {
			MJERP_Sale_Payments_Repo::delete_for_sale( $sale['id'] );
		}

		$result = $wpdb->delete( self::table(), array( 'id' => $sale['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo borrar la venta.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after a sale was deleted.
		 *
		 * @param int   $id   Sale id.
		 * @param array $sale Deleted sale.
		 */
		do_action( 'mjerp_sale_deleted', (int) $sale['id'], $sale );

		return true;
	}

	/* ------------------------------------------------------------------
	 * Derived values
	 * ---------------------------------------------------------------- */

	/**
	 * Rebuilds the cached totals of a sale from its lines and payments.
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	public static function recalculate( $sale_id ) : void {
		global $wpdb;

		$sale_id = absint( $sale_id );
		if ( ! $sale_id ) {
			return;
		}
		$sale = self::find( $sale_id );
		if ( ! $sale ) {
			return;
		}

		$items = self::items( $sale_id );

		$qty               = 0.0;
		$subtotal          = 0.0;
		$cost_base         = 0.0;
		$consignment_base  = 0.0;
		$consignment_total = 0.0;
		$consignment_qty   = 0.0;

		foreach ( $items as $item ) {
			$qty      += (float) $item['qty'];
			$subtotal += (float) $item['line_total'];
			$cost_base += (float) $item['cost_base'];
			if ( self::ORIGIN_CONSIGNMENT === $item['origin'] ) {
				$consignment_qty   += (float) $item['qty'];
				$consignment_base  += round( (float) $item['qty'] * (float) $item['consignment_price_base'], 4 );
				$consignment_total += (float) $item['line_total'];
			}
		}

		$total      = round( $subtotal - (float) $sale['discount_total'] + (float) $sale['shipping_total'] + (float) $sale['tax_total'], 4 );
		$total_base = round( self::to_base( $total, (float) $sale['rate'] ), 4 );

		$row = array(
			'items_count'      => count( $items ),
			'qty_total'        => round( $qty, 4 ),
			'subtotal'         => round( $subtotal, 4 ),
			'total'            => $total,
			'total_base'       => $total_base,
			'cost_base'        => round( $cost_base, 4 ),
			'profit_base'      => round( $total_base - $cost_base, 4 ),
			'consignment_base' => round( $consignment_base, 4 ),
			'consignment_total' => round( $consignment_total, 4 ),
			'consignment_qty'  => round( $consignment_qty, 4 ),
			'updated_at'       => current_time( 'mysql' ),
		);

		$wpdb->update( self::table(), $row, array( 'id' => $sale_id ), self::formats( $row, self::FORMATS ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		self::sync_payments( $sale_id );
	}

	/**
	 * Recomputes what was collected against a sale and its payment status.
	 *
	 * Lines that came out of a consignment are not collected here: that money is the reseller's
	 * current account, and counting it twice would show a sale as paid that nobody paid for.
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	public static function sync_payments( $sale_id ) : void {
		global $wpdb;

		$sale_id = absint( $sale_id );
		if ( ! $sale_id ) {
			return;
		}
		$sale = self::find( $sale_id );
		if ( ! $sale ) {
			return;
		}

		$paid      = 0.0;
		$paid_base = 0.0;
		if ( class_exists( 'MJERP_Sale_Payments_Repo' ) ) {
			$totals    = MJERP_Sale_Payments_Repo::totals( $sale_id );
			$paid      = (float) $totals['amount'];
			$paid_base = (float) $totals['amount_base'];
		}

		$due = self::amount_due( $sale );

		if ( $due <= self::EPSILON ) {
			// Nothing to collect (everything went to a reseller's account, or the sale is empty).
			$status = self::PAY_PAID;
		} elseif ( $paid <= self::EPSILON ) {
			$status = self::PAY_UNPAID;
		} elseif ( $paid + self::EPSILON >= $due ) {
			$status = self::PAY_PAID;
		} else {
			$status = self::PAY_PARTIAL;
		}

		$row = array(
			'paid_total'     => round( $paid, 4 ),
			'paid_base'      => round( $paid_base, 4 ),
			'payment_status' => $status,
			'updated_at'     => current_time( 'mysql' ),
		);

		$wpdb->update( self::table(), $row, array( 'id' => $sale_id ), self::formats( $row, self::FORMATS ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		/**
		 * Fires after the collected totals of a sale were recalculated.
		 *
		 * Es lo que despierta al pedido: una venta que se termina de cobrar puede tener que emitirse
		 * en WooCommerce y descontar stock, y eso pasa acá y no en cada lugar que registra un cobro.
		 *
		 * @param int    $sale_id Sale id.
		 * @param string $status  New payment status.
		 */
		do_action( 'mjerp_sale_payments_synced', $sale_id, $status );
	}

	/**
	 * What the sale is worth to collect, in the sale's currency.
	 *
	 * Consignment lines are excluded: that money is settled through the reseller's current account,
	 * and counting it here would show a sale as paid that nobody actually paid for. Reads the cached
	 * `consignment_total` instead of the lines so a listing never queries once per row.
	 *
	 * @param array $sale Sale record.
	 * @return float
	 */
	public static function amount_due( array $sale ) : float {
		$total       = (float) MJERP_Helpers::array_get( $sale, 'total', 0 );
		$consignment = (float) MJERP_Helpers::array_get( $sale, 'consignment_total', 0 );

		return round( max( 0, $total - $consignment ), 4 );
	}

	/**
	 * Headline numbers for the dashboard.
	 *
	 * @return array
	 */
	public static function stats() : array {
		global $wpdb;

		$table = self::table();
		$month = current_time( 'Y-m' );
		$year  = current_time( 'Y' );
		$today = current_time( 'Y-m-d' );

		$void = self::STATUS_VOID;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_count,
					COALESCE(SUM(CASE WHEN sale_date = %s THEN 1 ELSE 0 END),0) AS today_count,
					COALESCE(SUM(CASE WHEN sale_date = %s THEN total_base ELSE 0 END),0) AS today_total,
					COALESCE(SUM(CASE WHEN DATE_FORMAT(sale_date,'%%Y-%%m') = %s THEN 1 ELSE 0 END),0) AS month_count,
					COALESCE(SUM(CASE WHEN DATE_FORMAT(sale_date,'%%Y-%%m') = %s THEN total_base ELSE 0 END),0) AS month_total,
					COALESCE(SUM(CASE WHEN DATE_FORMAT(sale_date,'%%Y-%%m') = %s THEN profit_base ELSE 0 END),0) AS month_profit,
					COALESCE(SUM(CASE WHEN YEAR(sale_date) = %s THEN 1 ELSE 0 END),0) AS year_count,
					COALESCE(SUM(CASE WHEN YEAR(sale_date) = %s THEN total_base ELSE 0 END),0) AS year_total,
					COALESCE(SUM(CASE WHEN payment_status <> %s THEN 1 ELSE 0 END),0) AS unpaid_count
				FROM {$table}
				WHERE status <> %s",
				$today,
				$today,
				$month,
				$month,
				$month,
				$year,
				$year,
				self::PAY_PAID,
				$void
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'count'        => (int) MJERP_Helpers::array_get( $row, 'total_count', 0 ),
			'today'        => array(
				'count' => (int) MJERP_Helpers::array_get( $row, 'today_count', 0 ),
				'total' => round( (float) MJERP_Helpers::array_get( $row, 'today_total', 0 ), 4 ),
			),
			'month'        => array(
				'count'  => (int) MJERP_Helpers::array_get( $row, 'month_count', 0 ),
				'total'  => round( (float) MJERP_Helpers::array_get( $row, 'month_total', 0 ), 4 ),
				'profit' => round( (float) MJERP_Helpers::array_get( $row, 'month_profit', 0 ), 4 ),
			),
			'year'         => array(
				'count' => (int) MJERP_Helpers::array_get( $row, 'year_count', 0 ),
				'total' => round( (float) MJERP_Helpers::array_get( $row, 'year_total', 0 ), 4 ),
			),
			'unpaid_count' => (int) MJERP_Helpers::array_get( $row, 'unpaid_count', 0 ),
		);
	}

	/**
	 * How many sales still have money to collect.
	 *
	 * @return int
	 */
	public static function unpaid_count() : int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status <> %s AND payment_status <> %s",
				self::STATUS_VOID,
				self::PAY_PAID
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Sales that are still waiting to be handed over.
	 *
	 * Es el número del globo del menú: lo que hay que hacer hoy. Una venta anulada no cuenta, y una
	 * que se entregó en el mostrador nunca llegó a estar pendiente.
	 *
	 * @return int
	 */
	public static function pending_delivery_count() : int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status <> %s AND delivery_status <> %s",
				self::STATUS_VOID,
				self::DELIVERY_DELIVERED
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------
	 * Codes
	 * ---------------------------------------------------------------- */

	/**
	 * Trims a code to the stored length.
	 *
	 * @param mixed $code Raw code.
	 * @return string
	 */
	public static function sanitize_code( $code ) : string {
		$code = trim( sanitize_text_field( (string) $code ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $code, 0, 60 ) : substr( $code, 0, 60 );
	}

	/**
	 * Whether a code is already taken.
	 *
	 * @param string $code       Code.
	 * @param int    $exclude_id Sale to ignore.
	 * @return bool
	 */
	public static function code_exists( string $code, int $exclude_id = 0 ) : bool {
		global $wpdb;
		$code = self::sanitize_code( $code );
		if ( '' === $code ) {
			return false;
		}
		$table = self::table();
		if ( $exclude_id > 0 ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s AND id <> %d LIMIT 1", $code, $exclude_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		} else {
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s LIMIT 1", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		return ! empty( $found );
	}

	/**
	 * Next free code from the sale code settings.
	 *
	 * @return string
	 */
	public static function next_code() : string {
		$format = trim( (string) MJERP_Settings::get( 'sales_code_format', '{prefix}-{year}-{seq}' ) );
		if ( '' === $format ) {
			$format = '{prefix}-{year}-{seq}';
		}
		if ( false === strpos( $format, '{seq}' ) ) {
			$format .= '-{seq}';
		}

		$prefix = (string) MJERP_Settings::get( 'sales_code_prefix', 'VTA' );
		$digits = max( 1, min( 10, (int) MJERP_Settings::get( 'sales_code_seq_digits', 4 ) ) );

		$base = str_replace(
			array( '{prefix}', '{year}', '{month}' ),
			array( $prefix, current_time( 'Y' ), current_time( 'm' ) ),
			$format
		);

		for ( $seq = 1; $seq <= 100000; $seq++ ) {
			$code = str_replace( '{seq}', str_pad( (string) $seq, $digits, '0', STR_PAD_LEFT ), $base );
			if ( ! self::code_exists( $code ) ) {
				return $code;
			}
		}

		return str_replace( '{seq}', (string) time(), $base );
	}

	/* ------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	/**
	 * Currency sales are rung up in: WooCommerce's, because that is what the customer pays.
	 *
	 * @return string
	 */
	public static function shop_currency() : string {
		$currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$currency = strtoupper( (string) MJERP_Settings::get( 'local_currency', 'ARS' ) );
		}
		return $currency;
	}

	/**
	 * Exchange rate used to express a currency in the base currency.
	 *
	 * @param string $currency Currency code.
	 * @return float
	 */
	public static function default_rate( string $currency ) : float {
		$base = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
		if ( strtoupper( $currency ) === $base ) {
			return 1.0;
		}
		// MJ Dólar primero: es el dólar con el que la tienda publica hoy. El TC de venta de la
		// configuración queda para las tiendas sin MJ Dólar.
		$rate = class_exists( 'MJERP_Dolar' ) ? (float) MJERP_Dolar::rate() : 0.0;
		if ( $rate <= 0 ) {
			$rate = MJERP_Helpers::to_float( MJERP_Settings::get( 'sale_rate', 0 ) );
		}
		return $rate > 0 ? $rate : 1.0;
	}

	/**
	 * Amount converted into the base currency.
	 *
	 * @param float $amount Amount in the sale currency.
	 * @param float $rate   Rate (units of sale currency per base unit).
	 * @return float
	 */
	private static function to_base( $amount, $rate ) : float {
		$rate = (float) $rate;
		return $rate > 0 ? ( (float) $amount ) / $rate : (float) $amount;
	}

	/**
	 * Status sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_status( $value ) : string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, self::statuses(), true ) ? $value : self::STATUS_CONFIRMED;
	}

	/**
	 * Source sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_source( $value ) : string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, self::sources(), true ) ? $value : self::SOURCE_ERP;
	}

	/**
	 * Line origin sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_origin( $value ) : string {
		$value = sanitize_key( (string) $value );
		return self::ORIGIN_CONSIGNMENT === $value ? self::ORIGIN_CONSIGNMENT : self::ORIGIN_STOCK;
	}

	/**
	 * Channel sanitizer: unknown keys fall back to the configured default.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	/**
	 * Delivery status, restricted to the three known ones.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_delivery_status( $value ) : string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, self::delivery_statuses(), true ) ? $value : self::DELIVERY_PENDING;
	}

	/**
	 * Delivery method key.
	 *
	 * A method that is not in the settings any more is kept as an empty string: pretending it still
	 * exists would print a label nobody configured.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_delivery_method( $value ) : string {
		$key = sanitize_key( (string) $value );
		if ( '' === $key || ! class_exists( 'MJERP_Settings' ) ) {
			return $key;
		}
		$keys = wp_list_pluck( MJERP_Settings::sale_delivery_methods(), 'key' );
		return in_array( $key, $keys, true ) ? $key : '';
	}

	public static function sanitize_channel( $value ) : string {
		$value = sanitize_key( (string) $value );
		$keys  = wp_list_pluck( MJERP_Settings::sale_channels(), 'key' );
		if ( in_array( $value, $keys, true ) ) {
			return $value;
		}
		$default = (string) MJERP_Settings::get( 'sales_default_channel', 'mostrador' );
		return in_array( $default, $keys, true ) ? $default : (string) reset( $keys );
	}

	/**
	 * Row → API shape.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private static function cast( array $row ) : array {
		$total      = round( (float) self::v( $row, 'total', 0 ), 4 );
		$paid       = round( (float) self::v( $row, 'paid_total', 0 ), 4 );
		$status     = (string) self::v( $row, 'status', self::STATUS_CONFIRMED );
		$statuses   = MJERP_Settings::sale_statuses();
		$pay_status = (string) self::v( $row, 'payment_status', self::PAY_UNPAID );
		$pay_map    = MJERP_Settings::sale_payment_statuses();
		$order_id   = (int) self::v( $row, 'order_id', 0 );

		$delivery_status = (string) self::v( $row, 'delivery_status', self::DELIVERY_PENDING );
		$delivery_map    = MJERP_Settings::sale_delivery_statuses();
		$delivery_method = (string) self::v( $row, 'delivery_method' );

		$out = array(
			'id'                 => (int) $row['id'],
			'code'               => (string) self::v( $row, 'code' ),
			'order_id'           => $order_id,
			'order_number'       => (string) self::v( $row, 'order_number' ),
			'order_status'       => (string) self::v( $row, 'order_status' ),
			'order_edit_link'    => $order_id && current_user_can( 'edit_shop_orders' ) ? self::order_edit_link( $order_id ) : '',
			'source'             => (string) self::v( $row, 'source', self::SOURCE_ERP ),
			'channel'            => (string) self::v( $row, 'channel' ),
			'channel_label'      => MJERP_Settings::sale_channel_label( self::v( $row, 'channel' ) ),
			'status'             => $status,
			'status_label'       => isset( $statuses[ $status ]['label'] ) ? (string) $statuses[ $status ]['label'] : $status,
			'status_color'       => isset( $statuses[ $status ]['color'] ) ? (string) $statuses[ $status ]['color'] : 'neutral',
			'sale_date'          => self::date_or_null( self::v( $row, 'sale_date', null ) ),
			'customer_id'        => (int) self::v( $row, 'customer_id', 0 ),
			'customer_user_id'   => (int) self::v( $row, 'customer_user_id', 0 ),
			'customer_name'      => (string) self::v( $row, 'customer_name' ),
			'customer_email'     => (string) self::v( $row, 'customer_email' ),
			'customer_phone'     => (string) self::v( $row, 'customer_phone' ),
			'consignee_id'       => (int) self::v( $row, 'consignee_id', 0 ),
			'consignee_name'     => '',
			'price_list_key'     => (string) self::v( $row, 'price_list_key' ),
			'currency'           => strtoupper( (string) self::v( $row, 'currency', 'ARS' ) ),
			'rate'               => round( (float) self::v( $row, 'rate', 1 ), 6 ),
			'items_count'        => (int) self::v( $row, 'items_count', 0 ),
			'qty_total'          => round( (float) self::v( $row, 'qty_total', 0 ), 4 ),
			'subtotal'           => round( (float) self::v( $row, 'subtotal', 0 ), 4 ),
			'discount_total'     => round( (float) self::v( $row, 'discount_total', 0 ), 4 ),
			'shipping_total'     => round( (float) self::v( $row, 'shipping_total', 0 ), 4 ),
			'tax_total'          => round( (float) self::v( $row, 'tax_total', 0 ), 4 ),
			'total'              => $total,
			'total_base'         => round( (float) self::v( $row, 'total_base', 0 ), 4 ),
			'cost_base'          => round( (float) self::v( $row, 'cost_base', 0 ), 4 ),
			'profit_base'        => round( (float) self::v( $row, 'profit_base', 0 ), 4 ),
			'consignment_base'   => round( (float) self::v( $row, 'consignment_base', 0 ), 4 ),
			'consignment_total'  => round( (float) self::v( $row, 'consignment_total', 0 ), 4 ),
			'consignment_qty'    => round( (float) self::v( $row, 'consignment_qty', 0 ), 4 ),
			'paid_total'         => $paid,
			'paid_base'          => round( (float) self::v( $row, 'paid_base', 0 ), 4 ),
			'payment_status'     => $pay_status,
			'payment_status_label' => isset( $pay_map[ $pay_status ]['label'] ) ? (string) $pay_map[ $pay_status ]['label'] : $pay_status,
			'payment_status_color' => isset( $pay_map[ $pay_status ]['color'] ) ? (string) $pay_map[ $pay_status ]['color'] : 'neutral',
			'payment_method'     => (string) self::v( $row, 'payment_method' ),
			'payment_method_label' => MJERP_Settings::sale_payment_method_label( self::v( $row, 'payment_method' ) ),
			'cashbox_id'         => (int) self::v( $row, 'cashbox_id', 0 ),
			'wants_order'        => (bool) (int) self::v( $row, 'wants_order', 1 ),
			'delivery_method'    => $delivery_method,
			'delivery_method_label' => MJERP_Settings::sale_delivery_method_label( $delivery_method ),
			'delivery_status'    => $delivery_status,
			'delivery_status_label' => isset( $delivery_map[ $delivery_status ]['label'] ) ? (string) $delivery_map[ $delivery_status ]['label'] : $delivery_status,
			'delivery_status_color' => isset( $delivery_map[ $delivery_status ]['color'] ) ? (string) $delivery_map[ $delivery_status ]['color'] : 'neutral',
			'delivery_note'      => (string) self::v( $row, 'delivery_note' ),
			'delivered'          => self::DELIVERY_DELIVERED === $delivery_status,
			'delivered_at'       => self::datetime_or_null( self::v( $row, 'delivered_at' ) ),
			'delivered_by'       => (int) self::v( $row, 'delivered_by', 0 ),
			'delivered_by_name'  => MJERP_Helpers::user_display_name( self::v( $row, 'delivered_by', 0 ) ),
			'stock_applied'      => (bool) (int) self::v( $row, 'stock_applied', 0 ),
			'note'               => (string) self::v( $row, 'note' ),
			'created_by'         => (int) self::v( $row, 'created_by', 0 ),
			'created_by_name'    => MJERP_Helpers::user_display_name( self::v( $row, 'created_by', 0 ) ),
			'voided_by'          => (int) self::v( $row, 'voided_by', 0 ),
			'voided_at'          => self::datetime_or_null( self::v( $row, 'voided_at' ) ),
			'created_at'         => self::datetime_or_null( self::v( $row, 'created_at' ) ),
			'updated_at'         => self::datetime_or_null( self::v( $row, 'updated_at' ) ),
		);

		$out['collectable'] = self::amount_due( $out );
		$out['due']         = self::STATUS_VOID === $status ? 0.0 : round( max( 0, $out['collectable'] - $paid ), 4 );

		$out['consignee_name'] = self::consignee_name( $out['consignee_id'] );

		return $out;
	}

	/**
	 * Line row → API shape.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private static function cast_item( array $row ) : array {
		$origin = (string) self::v( $row, 'origin', self::ORIGIN_STOCK );
		$qty    = round( (float) self::v( $row, 'qty', 0 ), 4 );

		return array(
			'id'                     => (int) $row['id'],
			'sale_id'                => (int) self::v( $row, 'sale_id', 0 ),
			'order_item_id'          => (int) self::v( $row, 'order_item_id', 0 ),
			'product_id'             => (int) self::v( $row, 'product_id', 0 ),
			'variation_id'           => (int) self::v( $row, 'variation_id', 0 ),
			'name'                   => (string) self::v( $row, 'name' ),
			'sku'                    => (string) self::v( $row, 'sku' ),
			'qty'                    => $qty,
			'unit_price'             => round( (float) self::v( $row, 'unit_price', 0 ), 6 ),
			'discount'               => round( (float) self::v( $row, 'discount', 0 ), 4 ),
			'line_total'             => round( (float) self::v( $row, 'line_total', 0 ), 4 ),
			'line_total_base'        => round( (float) self::v( $row, 'line_total_base', 0 ), 4 ),
			'unit_cost_base'         => round( (float) self::v( $row, 'unit_cost_base', 0 ), 6 ),
			'cost_base'              => round( (float) self::v( $row, 'cost_base', 0 ), 4 ),
			'origin'                 => $origin,
			'origin_label'           => self::ORIGIN_CONSIGNMENT === $origin
				? __( 'Consignación', 'mj-erp' )
				: __( 'Depósito', 'mj-erp' ),
			'consignee_id'           => (int) self::v( $row, 'consignee_id', 0 ),
			'consignment_item_id'    => (int) self::v( $row, 'consignment_item_id', 0 ),
			'consignment_sale_id'    => (int) self::v( $row, 'consignment_sale_id', 0 ),
			'consignment_price_base' => round( (float) self::v( $row, 'consignment_price_base', 0 ), 6 ),
			'consignment_total_base' => round( $qty * (float) self::v( $row, 'consignment_price_base', 0 ), 4 ),
			'sort_order'             => (int) self::v( $row, 'sort_order', 0 ),
		);
	}

	/**
	 * Admin link of a WooCommerce order (HPOS aware).
	 *
	 * Built from the id alone: a listing renders dozens of these, and loading every order just to
	 * ask it for its own URL would put a query per row on the page.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function order_edit_link( $order_id ) : string {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return '';
		}

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& is_callable( array( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		return $hpos
			? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
			: admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	/**
	 * Consignee name, memoised so a listing does not query once per row.
	 *
	 * @param int $consignee_id Consignee id.
	 * @return string
	 */
	private static function consignee_name( $consignee_id ) : string {
		static $names = array();

		$consignee_id = absint( $consignee_id );
		if ( ! $consignee_id || ! class_exists( 'MJERP_Consignees' ) ) {
			return '';
		}
		if ( ! array_key_exists( $consignee_id, $names ) ) {
			$consignee              = MJERP_Consignees::find( $consignee_id );
			$names[ $consignee_id ] = $consignee ? (string) $consignee['name'] : '';
		}
		return $names[ $consignee_id ];
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
