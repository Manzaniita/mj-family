<?php
defined( 'ABSPATH' ) || exit;

/**
 * Consignment sales: what the reseller sold, which is at once the stock movement and the debt.
 *
 * A sale always points at a consignment line, and takes its unit price from there — the price that
 * was agreed the day the goods were handed over, in base currency (USD). Nothing here reads a price
 * list at sale time: a debt that moves after the fact is not a debt anyone can argue about.
 *
 * Two things happen when a sale is *approved*, and only then:
 *   1. WooCommerce stock goes down (the unit is really gone) and a stock movement is logged.
 *   2. `amount_base` becomes a charge on the reseller's account.
 *
 * A sale reported from the portal starts as `pendiente`: the reseller states what they sold, the
 * shop owner confirms it. Rejecting or deleting an approved sale puts the stock back.
 *
 * @package MJ_ERP
 */
class MJERP_Consignment_Sales_Repo {

	const STATUS_PENDING  = 'pendiente';
	const STATUS_APPROVED = 'aprobada';
	const STATUS_REJECTED = 'rechazada';

	const SOURCE_ADMIN  = 'admin';
	const SOURCE_PORTAL = 'portal';

	const MOVEMENT_TYPE   = 'consignment_sale';
	const MOVEMENT_REVERT = 'consignment_sale_revert';
	const REF_TYPE        = 'consignment_sale';

	const FORMATS = array(
		'consignee_id'        => '%d',
		'consignment_id'      => '%d',
		'consignment_item_id' => '%d',
		'product_id'          => '%d',
		'variation_id'        => '%d',
		'name'                => '%s',
		'sku'                 => '%s',
		'qty'                 => '%f',
		'unit_price_base'     => '%f',
		'amount_base'         => '%f',
		'allocated_base'      => '%f',
		'retail_amount'       => '%f',
		'retail_currency'     => '%s',
		'sale_date'           => '%s',
		'status'              => '%s',
		'source'              => '%s',
		'stock_applied'       => '%d',
		'note'                => '%s',
		'created_by'          => '%d',
		'approved_by'         => '%d',
		'approved_at'         => '%s',
		'created_at'          => '%s',
		'updated_at'          => '%s',
	);

	public static function statuses() : array {
		return array( self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED );
	}

	private static function table() : string {
		return MJERP_Install::table( 'consignment_sales' );
	}

	/* ------------------------------------------------------------------
	 * Read
	 * ---------------------------------------------------------------- */

	/**
	 * Paginated list of sales.
	 *
	 * @param array $args consignee_id, status, consignment_id, unpaid, date_from, date_to, page, per_page, order.
	 * @return array { items, total, pages, page, per_page }
	 */
	public static function query( array $args ) : array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'consignee_id'   => 0,
				'consignment_id' => 0,
				'status'         => '',
				'unpaid'         => false,
				'date_from'      => '',
				'date_to'        => '',
				'page'           => 1,
				'per_page'       => 20,
				'order'          => 'DESC',
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

		$consignment_id = absint( $args['consignment_id'] );
		if ( $consignment_id > 0 ) {
			$where[] = 'consignment_id = %d';
			$prep[]  = $consignment_id;
		}

		$status = sanitize_key( (string) $args['status'] );
		if ( '' !== $status && in_array( $status, self::statuses(), true ) ) {
			$where[] = 'status = %s';
			$prep[]  = $status;
		}

		if ( MJERP_Helpers::bool( $args['unpaid'] ) ) {
			// 0.0001: the amounts are stored with 4 decimals, so anything below that is settled.
			$where[] = 'status = %s AND (amount_base - allocated_base) > 0.0001';
			$prep[]  = self::STATUS_APPROVED;
		}

		$from = MJERP_Helpers::sanitize_date( $args['date_from'] );
		if ( $from ) {
			$where[] = 'sale_date >= %s';
			$prep[]  = $from;
		}
		$to = MJERP_Helpers::sanitize_date( $args['date_to'] );
		if ( $to ) {
			$where[] = 'sale_date <= %s';
			$prep[]  = $to;
		}

		$order     = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $prep ? $wpdb->get_var( $wpdb->prepare( $count_sql, $prep ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY sale_date {$order}, id {$order} LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $prep, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

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
	 * Single sale.
	 *
	 * @param int $id Sale id.
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
	 * Approved sales with an outstanding balance, oldest first.
	 *
	 * This is the queue a payment is applied against, and the order matters: oldest debt first.
	 *
	 * @param int $consignee_id Consignee id.
	 * @return array
	 */
	public static function outstanding( $consignee_id ) : array {
		global $wpdb;

		$consignee_id = absint( $consignee_id );
		if ( $consignee_id <= 0 ) {
			return array();
		}

		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE consignee_id = %d AND status = %s AND (amount_base - allocated_base) > 0.0001
				 ORDER BY sale_date ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$consignee_id,
				self::STATUS_APPROVED
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
	 * How many sales are waiting for approval (badge in the UI).
	 *
	 * @param int $consignee_id 0 = every consignee.
	 * @return int
	 */
	public static function pending_count( $consignee_id = 0 ) : int {
		global $wpdb;
		$table = self::table();
		$consignee_id = absint( $consignee_id );

		if ( $consignee_id > 0 ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND consignee_id = %d", self::STATUS_PENDING, $consignee_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_PENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------
	 * Write
	 * ---------------------------------------------------------------- */

	/**
	 * Registers a sale reported by the reseller (or loaded by the shop owner).
	 *
	 * @param array  $data   consignment_item_id (required), qty, sale_date, retail_amount,
	 *                       retail_currency, note, unit_price_base (admin override).
	 * @param string $source admin | portal.
	 * @return int|WP_Error New sale id.
	 */
	public static function create( array $data, string $source = self::SOURCE_ADMIN ) {
		global $wpdb;

		$source = self::SOURCE_PORTAL === $source ? self::SOURCE_PORTAL : self::SOURCE_ADMIN;

		$item = MJERP_Consignments_Repo::find_item( isset( $data['consignment_item_id'] ) ? $data['consignment_item_id'] : 0 );
		if ( ! $item ) {
			return new WP_Error( 'mjerp_not_found', __( 'El producto entregado no existe.', 'mj-erp' ), array( 'status' => 404, 'field' => 'consignment_item_id' ) );
		}

		$consignment = MJERP_Consignments_Repo::find( $item['consignment_id'] );
		if ( ! $consignment || MJERP_Consignments_Repo::STATUS_DELIVERED !== $consignment['status'] ) {
			return new WP_Error(
				'mjerp_not_delivered',
				__( 'Solo se pueden registrar ventas sobre una entrega en estado entregada.', 'mj-erp' ),
				array( 'status' => 409 )
			);
		}

		$qty = round( MJERP_Helpers::to_float( isset( $data['qty'] ) ? $data['qty'] : 0 ), 4 );
		if ( $qty <= 0 ) {
			return new WP_Error( 'mjerp_invalid_qty', __( 'La cantidad vendida tiene que ser mayor a cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'qty' ) );
		}

		$allow_oversell = MJERP_Helpers::bool( MJERP_Settings::get( 'consignment_allow_oversell', false ) );
		if ( ! $allow_oversell && $qty > $item['qty_on_hand'] + 0.0001 ) {
			return new WP_Error(
				'mjerp_qty_exceeds',
				/* translators: %s: units still held */
				sprintf( __( 'Solo quedan %s unidades de ese producto en poder del revendedor.', 'mj-erp' ), rtrim( rtrim( number_format( (float) $item['qty_on_hand'], 4, '.', '' ), '0' ), '.' ) ),
				array( 'status' => 400, 'field' => 'qty', 'qty_on_hand' => $item['qty_on_hand'] )
			);
		}

		// The agreed price wins. Only the shop owner may override it, never the portal.
		$unit_price = (float) $item['unit_price_base'];
		if ( self::SOURCE_ADMIN === $source && array_key_exists( 'unit_price_base', $data ) && '' !== $data['unit_price_base'] && null !== $data['unit_price_base'] ) {
			$override = round( MJERP_Helpers::to_float( $data['unit_price_base'] ), 6 );
			if ( $override < 0 ) {
				return new WP_Error( 'mjerp_invalid_price', __( 'El precio no puede ser negativo.', 'mj-erp' ), array( 'status' => 400, 'field' => 'unit_price_base' ) );
			}
			$unit_price = $override;
		}

		$date = isset( $data['sale_date'] ) ? MJERP_Helpers::sanitize_date( $data['sale_date'] ) : null;
		if ( null === $date ) {
			$date = current_time( 'Y-m-d' );
		}

		$retail_amount   = null;
		$retail_currency = null;
		if ( isset( $data['retail_amount'] ) && '' !== $data['retail_amount'] && null !== $data['retail_amount'] ) {
			$retail_amount = round( MJERP_Helpers::to_float( $data['retail_amount'] ), 4 );
			if ( $retail_amount < 0 ) {
				$retail_amount = null;
			} else {
				$retail_currency = MJERP_Helpers::sanitize_currency( isset( $data['retail_currency'] ) ? $data['retail_currency'] : MJERP_Settings::get( 'local_currency', 'ARS' ) );
			}
		}

		$status = self::initial_status( $source );
		$now    = current_time( 'mysql' );

		$row = array(
			'consignee_id'        => (int) $item['consignee_id'],
			'consignment_id'      => (int) $item['consignment_id'],
			'consignment_item_id' => (int) $item['id'],
			'product_id'          => (int) $item['product_id'],
			'variation_id'        => (int) $item['variation_id'],
			'name'                => (string) $item['name'],
			'sku'                 => (string) $item['sku'],
			'qty'                 => $qty,
			'unit_price_base'     => $unit_price,
			'amount_base'         => round( $qty * $unit_price, 4 ),
			'allocated_base'      => 0,
			'retail_amount'       => $retail_amount,
			'retail_currency'     => $retail_currency,
			'sale_date'           => $date,
			'status'              => $status,
			'source'              => $source,
			'stock_applied'       => 0,
			'note'                => isset( $data['note'] ) ? MJERP_Helpers::text( $data['note'], 255 ) : '',
			'created_by'          => get_current_user_id(),
			'created_at'          => $now,
			'updated_at'          => $now,
		);

		$result = $wpdb->insert( self::table(), $row, self::formats( $row ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo registrar la venta.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;

		if ( self::STATUS_APPROVED === $status ) {
			self::apply_approval( $id );
		}

		MJERP_Consignments_Repo::sync_item_quantities( (int) $item['id'] );

		/**
		 * Fires after a consignment sale was registered.
		 *
		 * @param int    $id     Sale id.
		 * @param string $status Initial status.
		 * @param string $source admin | portal.
		 */
		do_action( 'mjerp_consignment_sale_created', $id, $status, $source );

		return $id;
	}

	/**
	 * Status a new sale starts in, per the `consignment_approval` setting.
	 *
	 * @param string $source admin | portal.
	 * @return string
	 */
	public static function initial_status( string $source ) : string {
		$mode = (string) MJERP_Settings::get( 'consignment_approval', 'portal' );

		if ( 'always' === $mode ) {
			return self::STATUS_PENDING;
		}
		if ( 'never' === $mode ) {
			return self::STATUS_APPROVED;
		}
		// 'portal': only what the reseller loads needs a confirmation.
		return self::SOURCE_PORTAL === $source ? self::STATUS_PENDING : self::STATUS_APPROVED;
	}

	/**
	 * Updates a sale. An approved sale can only be edited while nothing has been paid against it.
	 *
	 * @param int   $id   Sale id.
	 * @param array $data qty, unit_price_base, sale_date, retail_amount, retail_currency, note.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$sale = self::find( $id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		if ( $sale['allocated_base'] > 0.0001 ) {
			return new WP_Error(
				'mjerp_sale_paid',
				__( 'La venta ya tiene pagos aplicados: quitá el pago antes de modificarla.', 'mj-erp' ),
				array( 'status' => 409 )
			);
		}

		$item = MJERP_Consignments_Repo::find_item( $sale['consignment_item_id'] );
		$row  = array();

		$qty = null;
		if ( array_key_exists( 'qty', $data ) ) {
			$qty = round( MJERP_Helpers::to_float( $data['qty'] ), 4 );
			if ( $qty <= 0 ) {
				return new WP_Error( 'mjerp_invalid_qty', __( 'La cantidad vendida tiene que ser mayor a cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'qty' ) );
			}
			$allow_oversell = MJERP_Helpers::bool( MJERP_Settings::get( 'consignment_allow_oversell', false ) );
			if ( ! $allow_oversell && $item ) {
				// The line's on-hand already counts this sale when it is approved, so add it back.
				$available = (float) $item['qty_on_hand'] + ( self::STATUS_APPROVED === $sale['status'] ? (float) $sale['qty'] : 0.0 );
				if ( $qty > $available + 0.0001 ) {
					return new WP_Error(
						'mjerp_qty_exceeds',
						/* translators: %s: units available */
						sprintf( __( 'Solo hay %s unidades disponibles de ese producto.', 'mj-erp' ), rtrim( rtrim( number_format( $available, 4, '.', '' ), '0' ), '.' ) ),
						array( 'status' => 400, 'field' => 'qty' )
					);
				}
			}
			$row['qty'] = $qty;
		}

		if ( array_key_exists( 'unit_price_base', $data ) ) {
			$price = round( MJERP_Helpers::to_float( $data['unit_price_base'] ), 6 );
			if ( $price < 0 ) {
				return new WP_Error( 'mjerp_invalid_price', __( 'El precio no puede ser negativo.', 'mj-erp' ), array( 'status' => 400, 'field' => 'unit_price_base' ) );
			}
			$row['unit_price_base'] = $price;
		}

		if ( isset( $row['qty'] ) || isset( $row['unit_price_base'] ) ) {
			$new_qty            = isset( $row['qty'] ) ? $row['qty'] : (float) $sale['qty'];
			$new_price          = isset( $row['unit_price_base'] ) ? $row['unit_price_base'] : (float) $sale['unit_price_base'];
			$row['amount_base'] = round( $new_qty * $new_price, 4 );
		}

		if ( array_key_exists( 'sale_date', $data ) ) {
			$date = MJERP_Helpers::sanitize_date( $data['sale_date'] );
			if ( null === $date ) {
				return new WP_Error( 'mjerp_invalid_date', __( 'La fecha de la venta es inválida.', 'mj-erp' ), array( 'status' => 400, 'field' => 'sale_date' ) );
			}
			$row['sale_date'] = $date;
		}

		if ( array_key_exists( 'retail_amount', $data ) ) {
			if ( '' === $data['retail_amount'] || null === $data['retail_amount'] ) {
				$row['retail_amount']   = null;
				$row['retail_currency'] = null;
			} else {
				$amount = round( MJERP_Helpers::to_float( $data['retail_amount'] ), 4 );
				$row['retail_amount']   = $amount >= 0 ? $amount : null;
				$row['retail_currency'] = MJERP_Helpers::sanitize_currency( isset( $data['retail_currency'] ) ? $data['retail_currency'] : MJERP_Settings::get( 'local_currency', 'ARS' ) );
			}
		}

		if ( array_key_exists( 'note', $data ) ) {
			$row['note'] = MJERP_Helpers::text( $data['note'], 255 );
		}

		if ( empty( $row ) ) {
			return true;
		}

		// The stock already applied was for the old quantity: put it back and re-apply after saving.
		$reapply = self::STATUS_APPROVED === $sale['status'] && isset( $row['qty'] ) && $sale['stock_applied'];
		if ( $reapply ) {
			self::revert_stock( $sale, __( 'Corrección de la venta', 'mj-erp' ) );
		}

		$row['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::table(), $row, array( 'id' => $sale['id'] ), self::formats( $row ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar la venta.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		if ( $reapply ) {
			self::apply_stock( self::find( $sale['id'] ) );
		}

		MJERP_Consignments_Repo::sync_item_quantities( (int) $sale['consignment_item_id'] );

		return true;
	}

	/**
	 * Approves a pending sale: the stock leaves and the charge becomes real.
	 *
	 * @param int $id Sale id.
	 * @return true|WP_Error
	 */
	public static function approve( $id ) {
		global $wpdb;

		$sale = self::find( $id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( self::STATUS_APPROVED === $sale['status'] ) {
			return true;
		}

		$item = MJERP_Consignments_Repo::find_item( $sale['consignment_item_id'] );
		if ( $item && ! MJERP_Helpers::bool( MJERP_Settings::get( 'consignment_allow_oversell', false ) ) && $sale['qty'] > $item['qty_on_hand'] + 0.0001 ) {
			return new WP_Error(
				'mjerp_qty_exceeds',
				/* translators: %s: units still held */
				sprintf( __( 'Ya no quedan tantas unidades en poder del revendedor (%s disponibles).', 'mj-erp' ), rtrim( rtrim( number_format( (float) $item['qty_on_hand'], 4, '.', '' ), '0' ), '.' ) ),
				array( 'status' => 409, 'qty_on_hand' => $item['qty_on_hand'] )
			);
		}

		$result = $wpdb->update(
			self::table(),
			array(
				'status'      => self::STATUS_APPROVED,
				'approved_by' => get_current_user_id(),
				'approved_at' => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $sale['id'] ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo aprobar la venta.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		self::apply_approval( (int) $sale['id'] );
		MJERP_Consignments_Repo::sync_item_quantities( (int) $sale['consignment_item_id'] );

		/**
		 * Fires after a consignment sale was approved.
		 *
		 * @param int $id Sale id.
		 */
		do_action( 'mjerp_consignment_sale_approved', (int) $sale['id'] );

		return true;
	}

	/**
	 * Rejects a sale (or un-approves it): the stock goes back and the charge disappears.
	 *
	 * @param int    $id   Sale id.
	 * @param string $note Reason, kept on the sale.
	 * @return true|WP_Error
	 */
	public static function reject( $id, string $note = '' ) {
		global $wpdb;

		$sale = self::find( $id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( $sale['allocated_base'] > 0.0001 ) {
			return new WP_Error(
				'mjerp_sale_paid',
				__( 'La venta tiene pagos aplicados: quitá el pago antes de rechazarla.', 'mj-erp' ),
				array( 'status' => 409 )
			);
		}

		if ( $sale['stock_applied'] ) {
			self::revert_stock( $sale, '' !== $note ? $note : __( 'Venta rechazada', 'mj-erp' ) );
		}

		$row = array(
			'status'        => self::STATUS_REJECTED,
			'stock_applied' => 0,
			'updated_at'    => current_time( 'mysql' ),
		);
		if ( '' !== $note ) {
			$row['note'] = MJERP_Helpers::text( $note, 255 );
		}

		$result = $wpdb->update( self::table(), $row, array( 'id' => $sale['id'] ), self::formats( $row ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo rechazar la venta.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		MJERP_Consignments_Repo::sync_item_quantities( (int) $sale['consignment_item_id'] );

		return true;
	}

	/**
	 * Deletes a sale. Refused when a payment was applied to it: money would be left pointing at
	 * nothing, which is exactly what this module exists to prevent.
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
		if ( $sale['allocated_base'] > 0.0001 ) {
			return new WP_Error(
				'mjerp_sale_paid',
				__( 'La venta tiene pagos aplicados: quitá o reasigná el pago antes de borrarla.', 'mj-erp' ),
				array( 'status' => 409 )
			);
		}

		if ( $sale['stock_applied'] ) {
			self::revert_stock( $sale, __( 'Venta eliminada', 'mj-erp' ) );
		}

		$result = $wpdb->delete( self::table(), array( 'id' => $sale['id'] ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar la venta.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		MJERP_Consignments_Repo::sync_item_quantities( (int) $sale['consignment_item_id'] );

		return true;
	}

	/**
	 * Recomputes `allocated_base` from the allocations pointing at a sale.
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	public static function sync_allocated( $sale_id ) : void {
		global $wpdb;

		$sale_id = absint( $sale_id );
		if ( $sale_id <= 0 ) {
			return;
		}

		$allocations = MJERP_Install::table( 'consignment_allocations' );
		$total       = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount_base), 0) FROM {$allocations} WHERE sale_id = %d", $sale_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$wpdb->update(
			self::table(),
			array(
				'allocated_base' => round( $total, 4 ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $sale_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);
	}

	/* ------------------------------------------------------------------
	 * Stock
	 * ---------------------------------------------------------------- */

	/**
	 * Runs the stock side of an approval once, and records that it ran.
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	private static function apply_approval( $sale_id ) : void {
		$sale = self::find( $sale_id );
		if ( $sale && ! $sale['stock_applied'] ) {
			self::apply_stock( $sale );
		}
	}

	/**
	 * Takes the sold units out of WooCommerce and logs the movement.
	 *
	 * Failure is not fatal: the debt is the important half and must survive a product that was
	 * deleted or does not manage stock. `stock_applied` records what actually happened.
	 *
	 * @param array|null $sale Sale row.
	 * @return bool Whether stock actually moved.
	 */
	private static function apply_stock( ?array $sale ) : bool {
		global $wpdb;

		if ( ! $sale ) {
			return false;
		}
		if ( 'decrease' !== (string) MJERP_Settings::get( 'consignment_stock_on_sale', 'decrease' ) ) {
			return false;
		}
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_update_product_stock' ) ) {
			return false;
		}

		$target  = $sale['variation_id'] > 0 ? $sale['variation_id'] : $sale['product_id'];
		$product = $target ? wc_get_product( $target ) : false;
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
			return false;
		}

		$qty = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $sale['qty'] ) : (int) $sale['qty'];
		if ( $qty <= 0 ) {
			return false;
		}

		try {
			$holder = class_exists( 'MJERP_Products' ) ? MJERP_Products::stock_holder( $product ) : $product;
			$before = $holder->get_stock_quantity();
			$before = ( null === $before || '' === $before ) ? 0 : (float) $before;

			$after = wc_update_product_stock( $product, $qty, 'decrease' );
			if ( false === $after || null === $after ) {
				return false;
			}

			MJERP_Movements_Repo::insert(
				array(
					'product_id'     => (int) $sale['product_id'],
					'variation_id'   => (int) $sale['variation_id'],
					'qty'            => -1 * (float) $qty,
					'type'           => self::MOVEMENT_TYPE,
					'ref_type'       => self::REF_TYPE,
					'ref_id'         => (int) $sale['id'],
					'stock_before'   => $before,
					'stock_after'    => (float) $after,
					'note'           => sprintf(
						/* translators: %s: consignee name */
						__( 'Venta en consignación de %s', 'mj-erp' ),
						self::consignee_name( (int) $sale['consignee_id'] )
					),
					'user_id'        => get_current_user_id(),
				)
			);
		} catch ( Throwable $e ) {
			return false;
		}

		$wpdb->update( self::table(), array( 'stock_applied' => 1 ), array( 'id' => $sale['id'] ), array( '%d' ), array( '%d' ) );

		return true;
	}

	/**
	 * Puts back the stock a sale had taken out.
	 *
	 * @param array  $sale Sale row.
	 * @param string $note Movement note.
	 * @return void
	 */
	private static function revert_stock( array $sale, string $note = '' ) : void {
		global $wpdb;

		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_update_product_stock' ) ) {
			return;
		}

		$target  = $sale['variation_id'] > 0 ? $sale['variation_id'] : $sale['product_id'];
		$product = $target ? wc_get_product( $target ) : false;
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
			$wpdb->update( self::table(), array( 'stock_applied' => 0 ), array( 'id' => $sale['id'] ), array( '%d' ), array( '%d' ) );
			return;
		}

		$qty = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $sale['qty'] ) : (int) $sale['qty'];
		if ( $qty <= 0 ) {
			return;
		}

		try {
			$holder = class_exists( 'MJERP_Products' ) ? MJERP_Products::stock_holder( $product ) : $product;
			$before = $holder->get_stock_quantity();
			$before = ( null === $before || '' === $before ) ? 0 : (float) $before;

			$after = wc_update_product_stock( $product, $qty, 'increase' );
			if ( false === $after || null === $after ) {
				return;
			}

			MJERP_Movements_Repo::insert(
				array(
					'product_id'   => (int) $sale['product_id'],
					'variation_id' => (int) $sale['variation_id'],
					'qty'          => (float) $qty,
					'type'         => self::MOVEMENT_REVERT,
					'ref_type'     => self::REF_TYPE,
					'ref_id'       => (int) $sale['id'],
					'stock_before' => $before,
					'stock_after'  => (float) $after,
					'note'         => '' !== $note ? $note : __( 'Anulación de venta en consignación', 'mj-erp' ),
					'user_id'      => get_current_user_id(),
				)
			);
		} catch ( Throwable $e ) {
			return;
		}

		$wpdb->update( self::table(), array( 'stock_applied' => 0 ), array( 'id' => $sale['id'] ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Consignee name for movement notes ('' when gone).
	 *
	 * @param int $consignee_id Consignee id.
	 * @return string
	 */
	private static function consignee_name( $consignee_id ) : string {
		$consignee = MJERP_Consignees::find( $consignee_id );
		return $consignee ? (string) $consignee['name'] : '';
	}

	/* ------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	private static function cast( array $row ) : array {
		$amount    = round( (float) self::v( $row, 'amount_base', 0 ), 4 );
		$allocated = round( (float) self::v( $row, 'allocated_base', 0 ), 4 );
		$status    = (string) self::v( $row, 'status', self::STATUS_APPROVED );
		$retail    = self::v( $row, 'retail_amount', null );

		return array(
			'id'                  => (int) $row['id'],
			'consignee_id'        => (int) self::v( $row, 'consignee_id', 0 ),
			'consignment_id'      => (int) self::v( $row, 'consignment_id', 0 ),
			'consignment_item_id' => (int) self::v( $row, 'consignment_item_id', 0 ),
			'product_id'          => (int) self::v( $row, 'product_id', 0 ),
			'variation_id'        => (int) self::v( $row, 'variation_id', 0 ),
			'name'                => (string) self::v( $row, 'name' ),
			'sku'                 => (string) self::v( $row, 'sku' ),
			'qty'                 => round( (float) self::v( $row, 'qty', 0 ), 4 ),
			'unit_price_base'     => round( (float) self::v( $row, 'unit_price_base', 0 ), 6 ),
			'amount_base'         => $amount,
			'allocated_base'      => $allocated,
			'balance_base'        => round( self::STATUS_APPROVED === $status ? $amount - $allocated : 0, 4 ),
			'is_paid'             => self::STATUS_APPROVED === $status && ( $amount - $allocated ) <= 0.0001,
			'retail_amount'       => ( null === $retail || '' === $retail ) ? null : round( (float) $retail, 4 ),
			'retail_currency'     => (string) self::v( $row, 'retail_currency', '' ),
			'sale_date'           => self::date_or_null( self::v( $row, 'sale_date', null ) ),
			'status'              => $status,
			'source'              => (string) self::v( $row, 'source', self::SOURCE_ADMIN ),
			'stock_applied'       => (bool) (int) self::v( $row, 'stock_applied', 0 ),
			'note'                => (string) self::v( $row, 'note' ),
			'created_by'          => (int) self::v( $row, 'created_by', 0 ),
			'created_by_name'     => MJERP_Helpers::user_display_name( self::v( $row, 'created_by', 0 ) ),
			'approved_by'         => (int) self::v( $row, 'approved_by', 0 ),
			'approved_by_name'    => MJERP_Helpers::user_display_name( self::v( $row, 'approved_by', 0 ) ),
			'approved_at'         => self::datetime_or_null( self::v( $row, 'approved_at' ) ),
			'created_at'          => self::datetime_or_null( self::v( $row, 'created_at' ) ),
			'updated_at'          => self::datetime_or_null( self::v( $row, 'updated_at' ) ),
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
