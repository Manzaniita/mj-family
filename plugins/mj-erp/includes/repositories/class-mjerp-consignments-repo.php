<?php
defined( 'ABSPATH' ) || exit;

/**
 * Consignments repository: the delivery document, its lines and the returns against them.
 *
 * A consignment is what leaves the warehouse towards a reseller. It carries the *agreed* unit price
 * in base currency (USD), frozen the day the goods change hands: a debt that was already agreed must
 * not move when the exchange rate does.
 *
 * WooCommerce stock is NOT touched here. It leaves when the reseller reports the sale
 * (MJERP_Consignment_Sales), which is what the shop owner asked for. What the ERP tracks instead is
 * where each unit physically is:
 *
 *     en poder del revendedor = qty - qty_sold - qty_returned
 *
 * `qty_sold` and `qty_returned` are caches recomputed from the sales / returns tables, never
 * incremented blindly, so they cannot drift away from the rows that back them.
 *
 * @package MJ_ERP
 */
class MJERP_Consignments_Repo {

	const STATUS_DRAFT     = 'borrador';
	const STATUS_DELIVERED = 'entregada';
	const STATUS_CLOSED    = 'cerrada';
	const STATUS_CANCELLED = 'cancelada';

	const FORMATS = array(
		'code'             => '%s',
		'consignee_id'     => '%d',
		'status'           => '%s',
		'delivered_at'     => '%s',
		'base_currency'    => '%s',
		'notes'            => '%s',
		'items_count'      => '%d',
		'qty_total'        => '%f',
		'value_total_base' => '%f',
		'created_by'       => '%d',
		'delivered_by'     => '%d',
		'created_at'       => '%s',
		'updated_at'       => '%s',
	);

	const ITEM_FORMATS = array(
		'consignment_id'  => '%d',
		'consignee_id'    => '%d',
		'product_id'      => '%d',
		'variation_id'    => '%d',
		'name'            => '%s',
		'sku'             => '%s',
		'qty'             => '%f',
		'unit_price_base' => '%f',
		'price_list_key'  => '%s',
		'qty_sold'        => '%f',
		'qty_returned'    => '%f',
		'sort_order'      => '%d',
		'created_at'      => '%s',
		'updated_at'      => '%s',
	);

	const RETURN_FORMATS = array(
		'consignee_id'        => '%d',
		'consignment_id'      => '%d',
		'consignment_item_id' => '%d',
		'product_id'          => '%d',
		'variation_id'        => '%d',
		'qty'                 => '%f',
		'return_date'         => '%s',
		'note'                => '%s',
		'created_by'          => '%d',
		'created_at'          => '%s',
		'updated_at'          => '%s',
	);

	public static function statuses() : array {
		return array( self::STATUS_DRAFT, self::STATUS_DELIVERED, self::STATUS_CLOSED, self::STATUS_CANCELLED );
	}

	private static function table() : string {
		return MJERP_Install::table( 'consignments' );
	}

	private static function items_table() : string {
		return MJERP_Install::table( 'consignment_items' );
	}

	private static function returns_table() : string {
		return MJERP_Install::table( 'consignment_returns' );
	}

	/* ------------------------------------------------------------------
	 * Read
	 * ---------------------------------------------------------------- */

	/**
	 * Paginated list of consignments.
	 *
	 * @param array $args consignee_id, status, search, page, per_page, orderby, order.
	 * @return array { items, total, pages, page, per_page }
	 */
	public static function query( array $args ) : array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'consignee_id' => 0,
				'status'       => '',
				'search'       => '',
				'page'         => 1,
				'per_page'     => 20,
				'orderby'      => 'delivered_at',
				'order'        => 'DESC',
			)
		);

		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
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
		if ( '' !== $status && in_array( $status, self::statuses(), true ) ) {
			$where[] = 'status = %s';
			$prep[]  = $status;
		}

		$search = trim( sanitize_text_field( (string) $args['search'] ) );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(code LIKE %s OR notes LIKE %s)';
			$prep[]  = $like;
			$prep[]  = $like;
		}

		$allowed_orderby = array( 'delivered_at', 'code', 'id', 'value_total_base', 'created_at' );
		$orderby         = in_array( (string) $args['orderby'], $allowed_orderby, true ) ? (string) $args['orderby'] : 'delivered_at';
		$order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $prep ? $wpdb->get_var( $wpdb->prepare( $count_sql, $prep ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$list_prep = array_merge( $prep, array( $per_page, $offset ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_prep ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::cast( $row );
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
	 * Single consignment (no lines).
	 *
	 * @param int $id Consignment id.
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
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Consignment with its lines, the consignee and the permission flags of the current user.
	 *
	 * @param int $id Consignment id.
	 * @return array|null
	 */
	public static function get_full( $id ) : ?array {
		$row = self::find( $id );
		if ( ! $row ) {
			return null;
		}

		$row['items']     = self::items( $row['id'] );
		$row['consignee'] = MJERP_Consignees::find( $row['consignee_id'] );
		$row['can']       = self::permissions( $row );

		return $row;
	}

	/**
	 * Lines of a consignment, with the derived on-hand quantity.
	 *
	 * @param int $consignment_id Consignment id.
	 * @return array
	 */
	public static function items( $consignment_id ) : array {
		global $wpdb;
		$consignment_id = absint( $consignment_id );
		if ( $consignment_id <= 0 ) {
			return array();
		}
		$table = self::items_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE consignment_id = %d ORDER BY sort_order ASC, id ASC", $consignment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast_item( $row );
		}
		return $out;
	}

	/**
	 * Single line.
	 *
	 * @param int $id Item id.
	 * @return array|null
	 */
	public static function find_item( $id ) : ?array {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::items_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::cast_item( $row ) : null;
	}

	/**
	 * Everything a consignee still holds, one row per product, across every delivered consignment.
	 *
	 * This is the answer to "what does this reseller have right now", and the list the portal shows
	 * when they report a sale.
	 *
	 * @param int  $consignee_id  Consignee id.
	 * @param bool $only_on_hand  Drop rows already fully sold or returned.
	 * @return array [ { consignment_item_id, consignment_id, code, product_id, variation_id, name, sku,
	 *                   qty, qty_sold, qty_returned, qty_on_hand, unit_price_base, value_on_hand_base } ]
	 */
	public static function stock_on_hand( $consignee_id, bool $only_on_hand = true ) : array {
		global $wpdb;

		$consignee_id = absint( $consignee_id );
		if ( $consignee_id <= 0 ) {
			return array();
		}

		$items        = self::items_table();
		$consignments = self::table();

		$sql = $wpdb->prepare(
			"SELECT i.*, c.code AS consignment_code, c.delivered_at
			 FROM {$items} i
			 INNER JOIN {$consignments} c ON c.id = i.consignment_id
			 WHERE i.consignee_id = %d AND c.status = %s
			 ORDER BY c.delivered_at ASC, i.id ASC",
			$consignee_id,
			self::STATUS_DELIVERED
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$item = self::cast_item( $row );
			$item['consignment_code'] = (string) ( isset( $row['consignment_code'] ) ? $row['consignment_code'] : '' );
			$item['delivered_at']     = self::date_or_null( isset( $row['delivered_at'] ) ? $row['delivered_at'] : null );
			$item['value_on_hand_base'] = round( $item['qty_on_hand'] * $item['unit_price_base'], 4 );

			if ( $only_on_hand && $item['qty_on_hand'] <= 0 ) {
				continue;
			}
			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Totals of what a consignee holds: units and value at the agreed prices.
	 *
	 * @param int $consignee_id Consignee id.
	 * @return array { units, value_base, lines }
	 */
	public static function on_hand_totals( $consignee_id ) : array {
		$units = 0.0;
		$value = 0.0;
		$lines = 0;

		foreach ( self::stock_on_hand( $consignee_id, true ) as $row ) {
			$units += (float) $row['qty_on_hand'];
			$value += (float) $row['value_on_hand_base'];
			$lines++;
		}

		return array(
			'units'      => round( $units, 4 ),
			'value_base' => round( $value, 4 ),
			'lines'      => $lines,
		);
	}

	/**
	 * Per-request cache of on_hand_by_product().
	 *
	 * @var array|null
	 */
	private static $on_hand_map = null;

	/**
	 * Units of a product held across every consignee.
	 *
	 * The products page needs this on every row: WooCommerce stock still counts goods that are
	 * physically at a reseller's (they only leave on sale), so without this the shop owner cannot
	 * tell what is really available to sell online. Memoised because the listing asks for it once
	 * per product.
	 *
	 * @param bool $fresh Skip the per-request cache.
	 * @return array product/variation id => units still held
	 */
	public static function on_hand_by_product( bool $fresh = false ) : array {
		global $wpdb;

		if ( ! $fresh && null !== self::$on_hand_map ) {
			return self::$on_hand_map;
		}

		$items        = self::items_table();
		$consignments = self::table();

		$sql = $wpdb->prepare(
			"SELECT i.product_id, i.variation_id, SUM(i.qty - i.qty_sold - i.qty_returned) AS on_hand
			 FROM {$items} i
			 INNER JOIN {$consignments} c ON c.id = i.consignment_id
			 WHERE c.status = %s
			 GROUP BY i.product_id, i.variation_id
			 HAVING on_hand > 0",
			self::STATUS_DELIVERED
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['variation_id'] > 0 ? (int) $row['variation_id'] : (int) $row['product_id'];
			if ( $id <= 0 ) {
				continue;
			}
			$out[ $id ] = round( (float) $row['on_hand'], 4 );
		}

		self::$on_hand_map = $out;

		return $out;
	}

	/**
	 * Units of one product still held by resellers.
	 *
	 * @param int $product_id Product or variation id.
	 * @return float
	 */
	public static function on_hand_for_product( $product_id ) : float {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return 0.0;
		}
		$map = self::on_hand_by_product();
		return isset( $map[ $product_id ] ) ? (float) $map[ $product_id ] : 0.0;
	}

	/**
	 * Drops the memoised map (after a sale, return or delivery moved something).
	 *
	 * @return void
	 */
	public static function flush_on_hand_cache() : void {
		self::$on_hand_map = null;
	}

	/* ------------------------------------------------------------------
	 * Write — document
	 * ---------------------------------------------------------------- */

	/**
	 * Creates a consignment in draft.
	 *
	 * @param array $data consignee_id (required), code, delivered_at, notes.
	 * @return int|WP_Error New id.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$consignee_id = isset( $data['consignee_id'] ) ? absint( $data['consignee_id'] ) : 0;
		$consignee    = MJERP_Consignees::find( $consignee_id );
		if ( ! $consignee ) {
			return new WP_Error( 'mjerp_invalid_consignee', __( 'Elegí un revendedor válido.', 'mj-erp' ), array( 'status' => 400, 'field' => 'consignee_id' ) );
		}
		if ( ! $consignee['is_active'] ) {
			return new WP_Error( 'mjerp_inactive_consignee', __( 'El revendedor está desactivado.', 'mj-erp' ), array( 'status' => 400, 'field' => 'consignee_id' ) );
		}

		$code = isset( $data['code'] ) ? self::sanitize_code( $data['code'] ) : '';
		if ( '' === $code ) {
			$code = self::next_code();
		} elseif ( self::code_exists( $code ) ) {
			return new WP_Error( 'mjerp_code_exists', __( 'Ya existe una entrega con ese código.', 'mj-erp' ), array( 'status' => 409, 'field' => 'code' ) );
		}

		$now = current_time( 'mysql' );

		$row = array(
			'code'             => $code,
			'consignee_id'     => (int) $consignee['id'],
			'status'           => self::STATUS_DRAFT,
			'delivered_at'     => isset( $data['delivered_at'] ) ? MJERP_Helpers::sanitize_date( $data['delivered_at'] ) : current_time( 'Y-m-d' ),
			'base_currency'    => strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) ),
			'notes'            => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : '',
			'items_count'      => 0,
			'qty_total'        => 0,
			'value_total_base' => 0,
			'created_by'       => get_current_user_id(),
			'created_at'       => $now,
			'updated_at'       => $now,
		);
		if ( null === $row['delivered_at'] ) {
			$row['delivered_at'] = current_time( 'Y-m-d' );
		}

		$result = $wpdb->insert( self::table(), $row, self::formats( $row, self::FORMATS ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo crear la entrega.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a consignment. Lines and the consignee can only change while it is a draft.
	 *
	 * @param int   $id   Consignment id.
	 * @param array $data Fields.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'La entrega no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$row    = array();
		$locked = ! self::is_editable( $existing );

		if ( array_key_exists( 'code', $data ) ) {
			$code = self::sanitize_code( $data['code'] );
			if ( '' === $code ) {
				return new WP_Error( 'mjerp_invalid_code', __( 'El código de la entrega no puede estar vacío.', 'mj-erp' ), array( 'status' => 400, 'field' => 'code' ) );
			}
			if ( self::code_exists( $code, (int) $existing['id'] ) ) {
				return new WP_Error( 'mjerp_code_exists', __( 'Ya existe una entrega con ese código.', 'mj-erp' ), array( 'status' => 409, 'field' => 'code' ) );
			}
			$row['code'] = $code;
		}

		if ( array_key_exists( 'consignee_id', $data ) ) {
			if ( $locked ) {
				return new WP_Error( 'mjerp_locked', __( 'No se puede cambiar el revendedor de una entrega que ya salió.', 'mj-erp' ), array( 'status' => 409, 'field' => 'consignee_id' ) );
			}
			$consignee = MJERP_Consignees::find( $data['consignee_id'] );
			if ( ! $consignee ) {
				return new WP_Error( 'mjerp_invalid_consignee', __( 'Elegí un revendedor válido.', 'mj-erp' ), array( 'status' => 400, 'field' => 'consignee_id' ) );
			}
			$row['consignee_id'] = (int) $consignee['id'];
		}

		if ( array_key_exists( 'delivered_at', $data ) ) {
			$date = MJERP_Helpers::sanitize_date( $data['delivered_at'] );
			if ( null === $date ) {
				return new WP_Error( 'mjerp_invalid_date', __( 'La fecha de la entrega es inválida.', 'mj-erp' ), array( 'status' => 400, 'field' => 'delivered_at' ) );
			}
			$row['delivered_at'] = $date;
		}

		if ( array_key_exists( 'notes', $data ) ) {
			$row['notes'] = sanitize_textarea_field( (string) $data['notes'] );
		}

		if ( empty( $row ) ) {
			return true;
		}

		$row['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::table(), $row, array( 'id' => $existing['id'] ), self::formats( $row, self::FORMATS ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar la entrega.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		// The lines carry a denormalised consignee_id so the "what does X hold" query stays cheap.
		if ( isset( $row['consignee_id'] ) ) {
			$wpdb->update( self::items_table(), array( 'consignee_id' => $row['consignee_id'] ), array( 'consignment_id' => $existing['id'] ), array( '%d' ), array( '%d' ) );
		}

		return true;
	}

	/**
	 * Deletes a consignment and its lines. Refused once anything was sold against it: those sales
	 * are someone's debt and the line they point at has to keep existing.
	 *
	 * @param int $id Consignment id.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'La entrega no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		if ( self::has_sales( (int) $existing['id'] ) ) {
			return new WP_Error(
				'mjerp_has_sales',
				__( 'La entrega tiene ventas registradas: cancelala en vez de borrarla.', 'mj-erp' ),
				array( 'status' => 409 )
			);
		}

		$wpdb->delete( self::returns_table(), array( 'consignment_id' => $existing['id'] ), array( '%d' ) );
		$wpdb->delete( self::items_table(), array( 'consignment_id' => $existing['id'] ), array( '%d' ) );

		$result = $wpdb->delete( self::table(), array( 'id' => $existing['id'] ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar la entrega.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Moves a consignment to a status.
	 *
	 * `entregada` is the point of no return for the prices: from there the lines are read-only and
	 * the reseller can start reporting sales against them.
	 *
	 * @param int    $id     Consignment id.
	 * @param string $status Target status.
	 * @return true|WP_Error
	 */
	public static function set_status( $id, string $status ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'La entrega no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$status = sanitize_key( $status );
		if ( ! in_array( $status, self::statuses(), true ) ) {
			return new WP_Error( 'mjerp_invalid_status', __( 'Estado inválido.', 'mj-erp' ), array( 'status' => 400 ) );
		}
		if ( $status === $existing['status'] ) {
			return true;
		}

		if ( self::STATUS_DELIVERED === $status ) {
			$items = self::items( (int) $existing['id'] );
			if ( empty( $items ) ) {
				return new WP_Error( 'mjerp_no_items', __( 'Agregá al menos un producto antes de entregar.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			foreach ( $items as $item ) {
				if ( $item['qty'] <= 0 ) {
					return new WP_Error(
						'mjerp_invalid_qty',
						/* translators: %s: product name */
						sprintf( __( 'El producto «%s» tiene cantidad cero.', 'mj-erp' ), $item['name'] ),
						array( 'status' => 400 )
					);
				}
			}
		}

		if ( self::STATUS_CANCELLED === $status && self::has_sales( (int) $existing['id'] ) ) {
			return new WP_Error(
				'mjerp_has_sales',
				__( 'No se puede cancelar una entrega con ventas registradas. Anulá primero esas ventas.', 'mj-erp' ),
				array( 'status' => 409 )
			);
		}

		$row = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);
		if ( self::STATUS_DELIVERED === $status ) {
			$row['delivered_by'] = get_current_user_id();
			if ( empty( $existing['delivered_at'] ) ) {
				$row['delivered_at'] = current_time( 'Y-m-d' );
			}
		}

		$result = $wpdb->update( self::table(), $row, array( 'id' => $existing['id'] ), self::formats( $row, self::FORMATS ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo cambiar el estado de la entrega.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after a consignment changed status.
		 *
		 * @param int    $id   Consignment id.
		 * @param string $to   New status.
		 * @param string $from Previous status.
		 */
		do_action( 'mjerp_consignment_status_changed', (int) $existing['id'], $status, (string) $existing['status'] );

		return true;
	}

	/* ------------------------------------------------------------------
	 * Write — lines
	 * ---------------------------------------------------------------- */

	/**
	 * Adds a line to a draft consignment.
	 *
	 * @param int   $consignment_id Consignment id.
	 * @param array $data           product_id, variation_id, qty, unit_price_base, price_list_key, name, sku.
	 * @return int|WP_Error New line id.
	 */
	public static function add_item( $consignment_id, array $data ) {
		global $wpdb;

		$consignment = self::find( $consignment_id );
		if ( ! $consignment ) {
			return new WP_Error( 'mjerp_not_found', __( 'La entrega no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_editable( $consignment ) ) {
			return new WP_Error( 'mjerp_locked', __( 'La entrega ya salió: no se pueden agregar productos.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		$row = self::prepare_item( $data, null, $consignment );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$now = current_time( 'mysql' );

		$row['consignment_id'] = (int) $consignment['id'];
		$row['consignee_id']   = (int) $consignment['consignee_id'];
		$row['qty_sold']       = 0;
		$row['qty_returned']   = 0;
		$row['created_at']     = $now;
		$row['updated_at']     = $now;

		if ( ! isset( $row['sort_order'] ) ) {
			$table              = self::items_table();
			$max                = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$table} WHERE consignment_id = %d", $consignment['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row['sort_order']  = $max + 1;
		}

		$result = $wpdb->insert( self::items_table(), $row, self::formats( $row, self::ITEM_FORMATS ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo agregar el producto.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;
		self::recalculate( (int) $consignment['id'] );

		return $id;
	}

	/**
	 * Updates a line of a draft consignment.
	 *
	 * @param int   $item_id Line id.
	 * @param array $data    Fields.
	 * @return true|WP_Error
	 */
	public static function update_item( $item_id, array $data ) {
		global $wpdb;

		$item = self::find_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'mjerp_not_found', __( 'El producto de la entrega no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		$consignment = self::find( $item['consignment_id'] );
		if ( ! $consignment ) {
			return new WP_Error( 'mjerp_not_found', __( 'La entrega no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_editable( $consignment ) ) {
			return new WP_Error( 'mjerp_locked', __( 'La entrega ya salió: no se pueden cambiar los productos.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		$row = self::prepare_item( $data, $item, $consignment );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( empty( $row ) ) {
			return true;
		}

		$row['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::items_table(), $row, array( 'id' => $item['id'] ), self::formats( $row, self::ITEM_FORMATS ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar el producto.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		self::recalculate( (int) $consignment['id'] );

		return true;
	}

	/**
	 * Deletes a line of a draft consignment.
	 *
	 * @param int $item_id Line id.
	 * @return true|WP_Error
	 */
	public static function delete_item( $item_id ) {
		global $wpdb;

		$item = self::find_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'mjerp_not_found', __( 'El producto de la entrega no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		$consignment = self::find( $item['consignment_id'] );
		if ( $consignment && ! self::is_editable( $consignment ) ) {
			return new WP_Error( 'mjerp_locked', __( 'La entrega ya salió: no se pueden quitar productos.', 'mj-erp' ), array( 'status' => 409 ) );
		}
		if ( $item['qty_sold'] > 0 || $item['qty_returned'] > 0 ) {
			return new WP_Error( 'mjerp_item_has_movements', __( 'Ese producto ya tiene ventas o devoluciones.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		$result = $wpdb->delete( self::items_table(), array( 'id' => $item['id'] ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar el producto.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		if ( $consignment ) {
			self::recalculate( (int) $consignment['id'] );
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Returns
	 * ---------------------------------------------------------------- */

	/**
	 * Registers a return: the reseller gives unsold units back.
	 *
	 * No WooCommerce movement is needed — the stock never left, it was only committed to this
	 * reseller. What the return does is release that commitment.
	 *
	 * @param array $data consignment_item_id, qty, return_date, note.
	 * @return int|WP_Error New return id.
	 */
	public static function add_return( array $data ) {
		global $wpdb;

		$item = self::find_item( isset( $data['consignment_item_id'] ) ? $data['consignment_item_id'] : 0 );
		if ( ! $item ) {
			return new WP_Error( 'mjerp_not_found', __( 'El producto de la entrega no existe.', 'mj-erp' ), array( 'status' => 404, 'field' => 'consignment_item_id' ) );
		}

		$qty = MJERP_Helpers::to_float( isset( $data['qty'] ) ? $data['qty'] : 0 );
		$qty = round( $qty, 4 );
		if ( $qty <= 0 ) {
			return new WP_Error( 'mjerp_invalid_qty', __( 'La cantidad devuelta tiene que ser mayor a cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'qty' ) );
		}
		if ( $qty > $item['qty_on_hand'] + 0.0001 ) {
			return new WP_Error(
				'mjerp_qty_exceeds',
				/* translators: %s: units still held */
				sprintf( __( 'El revendedor solo tiene %s unidades de ese producto.', 'mj-erp' ), MJERP_Helpers::to_float( $item['qty_on_hand'] ) ),
				array( 'status' => 400, 'field' => 'qty', 'qty_on_hand' => $item['qty_on_hand'] )
			);
		}

		$date = isset( $data['return_date'] ) ? MJERP_Helpers::sanitize_date( $data['return_date'] ) : null;
		if ( null === $date ) {
			$date = current_time( 'Y-m-d' );
		}

		$now = current_time( 'mysql' );
		$row = array(
			'consignee_id'        => (int) $item['consignee_id'],
			'consignment_id'      => (int) $item['consignment_id'],
			'consignment_item_id' => (int) $item['id'],
			'product_id'          => (int) $item['product_id'],
			'variation_id'        => (int) $item['variation_id'],
			'qty'                 => $qty,
			'return_date'         => $date,
			'note'                => isset( $data['note'] ) ? MJERP_Helpers::text( $data['note'], 255 ) : '',
			'created_by'          => get_current_user_id(),
			'created_at'          => $now,
			'updated_at'          => $now,
		);

		$result = $wpdb->insert( self::returns_table(), $row, self::formats( $row, self::RETURN_FORMATS ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo registrar la devolución.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;
		self::sync_item_quantities( (int) $item['id'] );

		return $id;
	}

	/**
	 * Deletes a return.
	 *
	 * @param int $id Return id.
	 * @return true|WP_Error
	 */
	public static function delete_return( $id ) {
		global $wpdb;

		$id  = absint( $id );
		$row = $id > 0 ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::returns_table() . ' WHERE id = %d', $id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return new WP_Error( 'mjerp_not_found', __( 'La devolución no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$result = $wpdb->delete( self::returns_table(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar la devolución.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		self::sync_item_quantities( (int) $row['consignment_item_id'] );

		return true;
	}

	/**
	 * Returns registered for a consignee (newest first).
	 *
	 * @param int $consignee_id Consignee id.
	 * @param int $limit        Max rows.
	 * @return array
	 */
	public static function returns_for_consignee( $consignee_id, int $limit = 100 ) : array {
		global $wpdb;

		$consignee_id = absint( $consignee_id );
		if ( $consignee_id <= 0 ) {
			return array();
		}

		$returns = self::returns_table();
		$items   = self::items_table();
		$limit   = max( 1, min( 500, $limit ) );

		$sql = $wpdb->prepare(
			"SELECT r.*, i.name, i.sku
			 FROM {$returns} r
			 LEFT JOIN {$items} i ON i.id = r.consignment_item_id
			 WHERE r.consignee_id = %d
			 ORDER BY r.return_date DESC, r.id DESC
			 LIMIT %d",
			$consignee_id,
			$limit
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'                  => (int) $row['id'],
				'consignee_id'        => (int) $row['consignee_id'],
				'consignment_id'      => (int) $row['consignment_id'],
				'consignment_item_id' => (int) $row['consignment_item_id'],
				'product_id'          => (int) $row['product_id'],
				'variation_id'        => (int) $row['variation_id'],
				'name'                => (string) ( isset( $row['name'] ) ? $row['name'] : '' ),
				'sku'                 => (string) ( isset( $row['sku'] ) ? $row['sku'] : '' ),
				'qty'                 => round( (float) $row['qty'], 4 ),
				'return_date'         => self::date_or_null( $row['return_date'] ),
				'note'                => (string) ( isset( $row['note'] ) ? $row['note'] : '' ),
				'created_at'          => self::datetime_or_null( $row['created_at'] ),
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Caches
	 * ---------------------------------------------------------------- */

	/**
	 * Recomputes `qty_sold` / `qty_returned` of a line from the rows that back them.
	 *
	 * Only approved sales count: a sale still waiting for the shop owner's approval has not moved
	 * anything yet.
	 *
	 * @param int $item_id Line id.
	 * @return void
	 */
	public static function sync_item_quantities( $item_id ) : void {
		global $wpdb;

		$item_id = absint( $item_id );
		if ( $item_id <= 0 ) {
			return;
		}

		$sales   = MJERP_Install::table( 'consignment_sales' );
		$returns = self::returns_table();

		$sold = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(qty), 0) FROM {$sales} WHERE consignment_item_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$item_id,
				'aprobada'
			)
		);

		$returned = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(qty), 0) FROM {$returns} WHERE consignment_item_id = %d", $item_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$wpdb->update(
			self::items_table(),
			array(
				'qty_sold'     => round( $sold, 4 ),
				'qty_returned' => round( $returned, 4 ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $item_id ),
			array( '%f', '%f', '%s' ),
			array( '%d' )
		);

		self::flush_on_hand_cache();
	}

	/**
	 * Recomputes the document totals from its lines.
	 *
	 * @param int $consignment_id Consignment id.
	 * @return void
	 */
	public static function recalculate( $consignment_id ) : void {
		global $wpdb;

		$consignment_id = absint( $consignment_id );
		if ( $consignment_id <= 0 ) {
			return;
		}

		$table = self::items_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS lines_count, COALESCE(SUM(qty), 0) AS qty_total, COALESCE(SUM(qty * unit_price_base), 0) AS value_total
				 FROM {$table} WHERE consignment_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$consignment_id
			),
			ARRAY_A
		);

		$wpdb->update(
			self::table(),
			array(
				'items_count'      => (int) ( $row ? $row['lines_count'] : 0 ),
				'qty_total'        => round( (float) ( $row ? $row['qty_total'] : 0 ), 4 ),
				'value_total_base' => round( (float) ( $row ? $row['value_total'] : 0 ), 4 ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $consignment_id ),
			array( '%d', '%f', '%f', '%s' ),
			array( '%d' )
		);
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Whether the document still accepts line edits.
	 *
	 * @param array $consignment Consignment row.
	 * @return bool
	 */
	public static function is_editable( array $consignment ) : bool {
		return self::STATUS_DRAFT === (string) ( isset( $consignment['status'] ) ? $consignment['status'] : '' );
	}

	/**
	 * Whether any sale points at this consignment.
	 *
	 * @param int $consignment_id Consignment id.
	 * @return bool
	 */
	public static function has_sales( $consignment_id ) : bool {
		global $wpdb;
		$consignment_id = absint( $consignment_id );
		if ( $consignment_id <= 0 ) {
			return false;
		}
		$sales = MJERP_Install::table( 'consignment_sales' );
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$sales} WHERE consignment_id = %d LIMIT 1", $consignment_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $found > 0;
	}

	/**
	 * What the current user may do with this consignment.
	 *
	 * @param array $consignment Consignment row.
	 * @return array
	 */
	public static function permissions( array $consignment ) : array {
		$status   = (string) ( isset( $consignment['status'] ) ? $consignment['status'] : '' );
		$editable = self::is_editable( $consignment );
		$sales    = self::has_sales( (int) $consignment['id'] );

		return array(
			'edit'     => $editable,
			'deliver'  => self::STATUS_DRAFT === $status,
			'close'    => self::STATUS_DELIVERED === $status,
			'reopen'   => self::STATUS_CLOSED === $status,
			'cancel'   => in_array( $status, array( self::STATUS_DRAFT, self::STATUS_DELIVERED ), true ) && ! $sales,
			'delete'   => ! $sales,
			'sell'     => self::STATUS_DELIVERED === $status,
			'return'   => self::STATUS_DELIVERED === $status,
		);
	}

	/**
	 * Sanitize + validate a line.
	 *
	 * @param array      $data        Input.
	 * @param array|null $existing    Existing line on update.
	 * @param array      $consignment Parent document.
	 * @return array|WP_Error
	 */
	private static function prepare_item( array $data, ?array $existing, array $consignment ) {
		$row = array();

		$product_id   = null;
		$variation_id = null;

		if ( array_key_exists( 'product_id', $data ) || array_key_exists( 'variation_id', $data ) || ! $existing ) {
			$product_id   = isset( $data['product_id'] ) ? absint( $data['product_id'] ) : ( $existing ? (int) $existing['product_id'] : 0 );
			$variation_id = isset( $data['variation_id'] ) ? absint( $data['variation_id'] ) : ( $existing ? (int) $existing['variation_id'] : 0 );

			$target = $variation_id > 0 ? $variation_id : $product_id;
			if ( $target <= 0 ) {
				return new WP_Error( 'mjerp_invalid_product', __( 'Elegí un producto de WooCommerce.', 'mj-erp' ), array( 'status' => 400, 'field' => 'product_id' ) );
			}

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $target ) : false;
			if ( ! $product instanceof WC_Product ) {
				return new WP_Error( 'mjerp_invalid_product', __( 'El producto no existe en WooCommerce.', 'mj-erp' ), array( 'status' => 400, 'field' => 'product_id' ) );
			}
			if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
				return new WP_Error(
					'mjerp_invalid_product',
					__( 'Elegí una variación concreta: un producto variable no se puede entregar.', 'mj-erp' ),
					array( 'status' => 400, 'field' => 'product_id' )
				);
			}

			if ( $product->is_type( 'variation' ) ) {
				$variation_id = (int) $product->get_id();
				$product_id   = (int) $product->get_parent_id();
			} else {
				$product_id   = (int) $product->get_id();
				$variation_id = 0;
			}

			$row['product_id']   = $product_id;
			$row['variation_id'] = $variation_id;
			$row['name']         = MJERP_Helpers::text( isset( $data['name'] ) && '' !== trim( (string) $data['name'] ) ? $data['name'] : $product->get_name(), 255 );
			$row['sku']          = MJERP_Helpers::text( isset( $data['sku'] ) && '' !== trim( (string) $data['sku'] ) ? $data['sku'] : $product->get_sku(), 100 );
		}

		if ( array_key_exists( 'name', $data ) && ! isset( $row['name'] ) ) {
			$name = MJERP_Helpers::text( $data['name'], 255 );
			if ( '' !== $name ) {
				$row['name'] = $name;
			}
		}
		if ( array_key_exists( 'sku', $data ) && ! isset( $row['sku'] ) ) {
			$row['sku'] = MJERP_Helpers::text( $data['sku'], 100 );
		}

		if ( array_key_exists( 'qty', $data ) || ! $existing ) {
			$qty = round( MJERP_Helpers::to_float( isset( $data['qty'] ) ? $data['qty'] : 0 ), 4 );
			if ( $qty <= 0 ) {
				return new WP_Error( 'mjerp_invalid_qty', __( 'La cantidad tiene que ser mayor a cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'qty' ) );
			}
			$row['qty'] = $qty;
		}

		if ( array_key_exists( 'price_list_key', $data ) ) {
			$key = sanitize_key( (string) $data['price_list_key'] );
			if ( '' !== $key && ! MJERP_Settings::price_list( $key ) ) {
				return new WP_Error( 'mjerp_invalid_price_list', __( 'La lista de precio elegida no existe.', 'mj-erp' ), array( 'status' => 400, 'field' => 'price_list_key' ) );
			}
			$row['price_list_key'] = $key;
		}

		if ( array_key_exists( 'unit_price_base', $data ) ) {
			$price = round( MJERP_Helpers::to_float( $data['unit_price_base'] ), 6 );
			if ( $price < 0 ) {
				return new WP_Error( 'mjerp_invalid_price', __( 'El precio acordado no puede ser negativo.', 'mj-erp' ), array( 'status' => 400, 'field' => 'unit_price_base' ) );
			}
			$row['unit_price_base'] = $price;
		}

		// New line without an explicit price: suggest one from the consignee's list.
		if ( ! $existing && ! isset( $row['unit_price_base'] ) ) {
			$target     = ( isset( $row['variation_id'] ) && $row['variation_id'] > 0 ) ? $row['variation_id'] : ( isset( $row['product_id'] ) ? $row['product_id'] : 0 );
			$list_key   = isset( $row['price_list_key'] ) ? $row['price_list_key'] : null;
			$suggestion = MJERP_Consignees::suggested_price( $target, (int) $consignment['consignee_id'], $list_key );

			$row['unit_price_base'] = (float) $suggestion['unit_price_base'];
			if ( ! isset( $row['price_list_key'] ) ) {
				$row['price_list_key'] = (string) $suggestion['price_list_key'];
			}
		}

		if ( array_key_exists( 'sort_order', $data ) ) {
			$row['sort_order'] = max( 0, (int) MJERP_Helpers::to_int( $data['sort_order'] ) );
		}

		return $row;
	}

	/* ------------------------------------------------------------------
	 * Codes
	 * ---------------------------------------------------------------- */

	public static function sanitize_code( $code ) : string {
		$code = trim( sanitize_text_field( (string) $code ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $code, 0, 60 ) : substr( $code, 0, 60 );
	}

	public static function code_exists( string $code, int $exclude_id = 0 ) : bool {
		global $wpdb;
		$code = self::sanitize_code( $code );
		if ( '' === $code ) {
			return false;
		}
		$table = self::table();
		if ( $exclude_id > 0 ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s AND id <> %d LIMIT 1", $code, $exclude_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s LIMIT 1", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return ! empty( $found );
	}

	/**
	 * Next free code from the consignment code settings.
	 *
	 * @return string
	 */
	public static function next_code() : string {
		$format = trim( (string) MJERP_Settings::get( 'consignment_code_format', '{prefix}-{year}-{seq}' ) );
		if ( '' === $format ) {
			$format = '{prefix}-{year}-{seq}';
		}
		if ( false === strpos( $format, '{seq}' ) ) {
			$format .= '-{seq}';
		}

		$prefix = (string) MJERP_Settings::get( 'consignment_code_prefix', 'CON' );
		$digits = max( 1, min( 10, (int) MJERP_Settings::get( 'consignment_code_seq_digits', 4 ) ) );

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

	private static function cast( array $row ) : array {
		return array(
			'id'               => (int) $row['id'],
			'code'             => (string) self::v( $row, 'code' ),
			'consignee_id'     => (int) self::v( $row, 'consignee_id', 0 ),
			'status'           => (string) self::v( $row, 'status', self::STATUS_DRAFT ),
			'delivered_at'     => self::date_or_null( self::v( $row, 'delivered_at', null ) ),
			'base_currency'    => strtoupper( (string) self::v( $row, 'base_currency', 'USD' ) ),
			'notes'            => (string) self::v( $row, 'notes' ),
			'items_count'      => (int) self::v( $row, 'items_count', 0 ),
			'qty_total'        => round( (float) self::v( $row, 'qty_total', 0 ), 4 ),
			'value_total_base' => round( (float) self::v( $row, 'value_total_base', 0 ), 4 ),
			'created_by'       => (int) self::v( $row, 'created_by', 0 ),
			'created_by_name'  => MJERP_Helpers::user_display_name( self::v( $row, 'created_by', 0 ) ),
			'delivered_by'     => (int) self::v( $row, 'delivered_by', 0 ),
			'delivered_by_name' => MJERP_Helpers::user_display_name( self::v( $row, 'delivered_by', 0 ) ),
			'created_at'       => self::datetime_or_null( self::v( $row, 'created_at' ) ),
			'updated_at'       => self::datetime_or_null( self::v( $row, 'updated_at' ) ),
		);
	}

	private static function cast_item( array $row ) : array {
		$qty      = round( (float) self::v( $row, 'qty', 0 ), 4 );
		$sold     = round( (float) self::v( $row, 'qty_sold', 0 ), 4 );
		$returned = round( (float) self::v( $row, 'qty_returned', 0 ), 4 );
		$price    = round( (float) self::v( $row, 'unit_price_base', 0 ), 6 );

		return array(
			'id'              => (int) $row['id'],
			'consignment_id'  => (int) self::v( $row, 'consignment_id', 0 ),
			'consignee_id'    => (int) self::v( $row, 'consignee_id', 0 ),
			'product_id'      => (int) self::v( $row, 'product_id', 0 ),
			'variation_id'    => (int) self::v( $row, 'variation_id', 0 ),
			'name'            => (string) self::v( $row, 'name' ),
			'sku'             => (string) self::v( $row, 'sku' ),
			'qty'             => $qty,
			'unit_price_base' => $price,
			'price_list_key'  => (string) self::v( $row, 'price_list_key' ),
			'qty_sold'        => $sold,
			'qty_returned'    => $returned,
			'qty_on_hand'     => round( $qty - $sold - $returned, 4 ),
			'value_base'      => round( $qty * $price, 4 ),
			'sort_order'      => (int) self::v( $row, 'sort_order', 0 ),
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
