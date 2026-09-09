<?php
/**
 * Purchases repository: purchases, items, expenses, code generation, recalculation and stats.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Purchases_Repo
 */
class MJERP_Purchases_Repo {

	const STATUS_DRAFT     = 'borrador';
	const STATUS_TRANSIT   = 'en_transito';
	const STATUS_RECEIVED  = 'recibida';
	const STATUS_CANCELLED = 'cancelada';

	const PRORATION_METHODS = array( 'fob', 'qty', 'none' );

	const PURCHASE_FORMATS = array(
		'code'             => '%s',
		'status'           => '%s',
		'purchase_date'    => '%s',
		'supplier_id'      => '%d',
		'supplier_name'    => '%s',
		'destination'      => '%s',
		'base_currency'    => '%s',
		'default_rate'     => '%f',
		'proration_method' => '%s',
		'notes'            => '%s',
		'fob_total'        => '%f',
		'expenses_total'   => '%f',
		'total'            => '%f',
		'items_count'      => '%d',
		'calc_json'        => '%s',
		'received_at'      => '%s',
		'received_by'      => '%d',
		'created_by'       => '%d',
		'created_at'       => '%s',
		'updated_at'       => '%s',
	);

	const ITEM_FORMATS = array(
		'purchase_id'      => '%d',
		'product_id'       => '%d',
		'variation_id'     => '%d',
		'name'             => '%s',
		'sku'              => '%s',
		'quantity'         => '%f',
		'unit_cost'        => '%f',
		'currency'         => '%s',
		'rate'             => '%f',
		'fob_base'         => '%f',
		'share_base'       => '%f',
		'landed_unit_base' => '%f',
		'received_qty'     => '%f',
		'sort_order'       => '%d',
		'created_at'       => '%s',
		'updated_at'       => '%s',
	);

	const EXPENSE_FORMATS = array(
		'purchase_id'  => '%d',
		'type'         => '%s',
		'description'  => '%s',
		'amount'       => '%f',
		'currency'     => '%s',
		'rate'         => '%f',
		'amount_base'  => '%f',
		'is_prorated'  => '%d',
		'cashbox_id'   => '%d',
		'expense_date' => '%s',
		'sort_order'   => '%d',
		'created_at'   => '%s',
		'updated_at'   => '%s',
	);

	/**
	 * Per-request cache of product summaries keyed by "product_id:variation_id".
	 *
	 * @var array
	 */
	private static $product_cache = array();

	/* ------------------------------------------------------------------ */
	/* Tables                                                             */
	/* ------------------------------------------------------------------ */

	private static function table() : string {
		return MJERP_Install::table( 'purchases' );
	}

	private static function items_table() : string {
		return MJERP_Install::table( 'purchase_items' );
	}

	private static function expenses_table() : string {
		return MJERP_Install::table( 'purchase_expenses' );
	}

	/* ------------------------------------------------------------------ */
	/* Status helpers                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * List of status keys.
	 */
	public static function statuses() : array {
		$keys = array();
		if ( class_exists( 'MJERP_Settings' ) && method_exists( 'MJERP_Settings', 'statuses' ) ) {
			$keys = array_keys( (array) MJERP_Settings::statuses() );
		}
		if ( empty( $keys ) ) {
			$keys = array( self::STATUS_DRAFT, self::STATUS_TRANSIT, self::STATUS_RECEIVED, self::STATUS_CANCELLED );
		}
		return $keys;
	}

	/**
	 * Human label for a status key.
	 */
	public static function status_label( $status ) : string {
		$status = (string) $status;
		if ( class_exists( 'MJERP_Settings' ) && method_exists( 'MJERP_Settings', 'statuses' ) ) {
			$statuses = (array) MJERP_Settings::statuses();
			if ( isset( $statuses[ $status ]['label'] ) ) {
				return (string) $statuses[ $status ]['label'];
			}
		}
		$fallback = array(
			self::STATUS_DRAFT     => __( 'Borrador', 'mj-erp' ),
			self::STATUS_TRANSIT   => __( 'En tránsito', 'mj-erp' ),
			self::STATUS_RECEIVED  => __( 'Recibida', 'mj-erp' ),
			self::STATUS_CANCELLED => __( 'Cancelada', 'mj-erp' ),
		);
		return isset( $fallback[ $status ] ) ? $fallback[ $status ] : $status;
	}

	/**
	 * Whether the purchase header/items/expenses may be modified.
	 */
	public static function is_editable( array $purchase ) : bool {
		$status = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';
		return in_array( $status, array( self::STATUS_DRAFT, self::STATUS_TRANSIT ), true );
	}

	/**
	 * Permission-like flags for the UI.
	 *
	 * @param array    $purchase    Purchase row.
	 * @param int|null $items_count Items count override (defaults to cached items_count).
	 */
	public static function can( array $purchase, $items_count = null ) : array {
		$status   = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';
		$count    = null === $items_count ? (int) ( isset( $purchase['items_count'] ) ? $purchase['items_count'] : 0 ) : (int) $items_count;
		$editable = self::is_editable( $purchase );

		return array(
			'edit'      => $editable,
			'delete'    => self::STATUS_RECEIVED !== $status,
			'receive'   => $editable && $count > 0,
			'unreceive' => self::STATUS_RECEIVED === $status && self::to_bool( self::setting( 'allow_unreceive', true ) ),
			'cancel'    => $editable,
			'duplicate' => true,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Purchases                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Find a purchase row (cast).
	 */
	public static function find( int $id ) : ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::cast_purchase( $row ) : null;
	}

	/**
	 * Find a purchase by its code.
	 */
	public static function find_by_code( string $code ) : ?array {
		global $wpdb;
		$code = self::sanitize_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::cast_purchase( $row ) : null;
	}

	/**
	 * Full purchase JSON shape (§9).
	 */
	public static function get_full( int $id ) : ?array {
		$purchase = self::find( $id );
		if ( ! $purchase ) {
			return null;
		}

		$settings = self::settings();
		$items    = self::items( $id );
		$expenses = self::expenses( $id );
		$calc     = self::calculate( $purchase, $items, $expenses, $settings );

		$full_items = array();
		foreach ( $items as $index => $item ) {
			$item['product']   = self::product_summary( $item['product_id'], $item['variation_id'] );
			$item['is_linked'] = ( $item['variation_id'] > 0 || $item['product_id'] > 0 );
			$item['calc']      = isset( $calc['items'][ $index ] ) && is_array( $calc['items'][ $index ] )
				? $calc['items'][ $index ]
				: self::empty_item_calc( $item, $settings );
			$full_items[]      = $item;
		}

		$cashbox_names = self::cashbox_names();
		$full_expenses = array();
		foreach ( $expenses as $expense ) {
			$expense['type_label']   = self::expense_type_label( $expense['type'] );
			$expense['cashbox_name'] = ( $expense['cashbox_id'] > 0 && isset( $cashbox_names[ $expense['cashbox_id'] ] ) )
				? $cashbox_names[ $expense['cashbox_id'] ]
				: '';
			$full_expenses[]         = $expense;
		}

		$purchase['status_label']     = self::status_label( $purchase['status'] );
		$purchase['local_currency']   = self::local_currency( $settings );
		$purchase['supplier']         = null;
		if ( $purchase['supplier_id'] > 0 && class_exists( 'MJERP_Suppliers_Repo' ) ) {
			$purchase['supplier'] = MJERP_Suppliers_Repo::find( $purchase['supplier_id'] );
		}
		$purchase['received_by_name'] = $purchase['received_by'] > 0 ? self::user_name( $purchase['received_by'] ) : null;
		$purchase['created_by_name']  = $purchase['created_by'] > 0 ? self::user_name( $purchase['created_by'] ) : null;
		$purchase['items']            = $full_items;
		$purchase['expenses']         = $full_expenses;
		$purchase['calc']             = isset( $calc['totals'] ) && is_array( $calc['totals'] ) ? $calc['totals'] : array();
		$purchase['can']              = self::can( $purchase, count( $full_items ) );

		return $purchase;
	}

	/**
	 * Paginated list of purchases.
	 */
	public static function query( array $args ) : array {
		global $wpdb;

		$defaults = array(
			'status'      => '',
			'supplier_id' => 0,
			'search'      => '',
			'date_from'   => '',
			'date_to'     => '',
			'page'        => 1,
			'per_page'    => 20,
			'orderby'     => 'purchase_date',
			'order'       => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		$status = sanitize_key( (string) $args['status'] );
		if ( '' !== $status && in_array( $status, self::statuses(), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$supplier_id = absint( $args['supplier_id'] );
		if ( $supplier_id > 0 ) {
			$where[]  = 'supplier_id = %d';
			$params[] = $supplier_id;
		}

		$search = trim( sanitize_text_field( (string) $args['search'] ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(code LIKE %s OR supplier_name LIKE %s OR notes LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$date_from = self::normalize_date( $args['date_from'] );
		if ( is_string( $date_from ) ) {
			$where[]  = 'purchase_date >= %s';
			$params[] = $date_from;
		}
		$date_to = self::normalize_date( $args['date_to'] );
		if ( is_string( $date_to ) ) {
			$where[]  = 'purchase_date <= %s';
			$params[] = $date_to;
		}

		$page     = max( 1, (int) $args['page'] );
		$per_page = (int) $args['per_page'];
		if ( $per_page <= 0 ) {
			$per_page = 20;
		}
		$per_page = min( 100, $per_page );

		$orderby_map = array(
			'purchase_date' => 'purchase_date',
			'code'          => 'code',
			'total'         => 'total',
			'created_at'    => 'created_at',
			'status'        => 'status',
			'supplier_name' => 'supplier_name',
			'fob_total'     => 'fob_total',
		);
		$orderby_key = sanitize_key( (string) $args['orderby'] );
		$orderby     = isset( $orderby_map[ $orderby_key ] ) ? $orderby_map[ $orderby_key ] : 'purchase_date';
		$order       = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( ! empty( $params ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $page > $pages ) {
			$page = $pages;
		}
		$offset = ( $page - 1 ) * $per_page;

		$sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::summary_row( $row );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'pages'    => $pages,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Create a purchase (header only).
	 *
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$settings = self::settings();
		$row      = self::prepare_header( $data, null, $settings );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$code = isset( $data['code'] ) ? self::sanitize_code( $data['code'] ) : '';
		if ( '' === $code ) {
			$code = self::next_code();
		} elseif ( self::code_exists( $code ) ) {
			return new WP_Error( 'mjerp_code_exists', __( 'Ya existe una compra con ese código.', 'mj-erp' ), array( 'status' => 409 ) );
		}
		$row['code'] = $code;

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : self::STATUS_DRAFT;
		if ( ! in_array( $status, self::statuses(), true ) ) {
			$status = self::STATUS_DRAFT;
		}
		$row['status'] = $status;

		if ( ! array_key_exists( 'purchase_date', $row ) || null === $row['purchase_date'] ) {
			$row['purchase_date'] = current_time( 'Y-m-d' );
		}
		if ( ! array_key_exists( 'supplier_id', $row ) ) {
			$row['supplier_id'] = null;
		}
		if ( ! array_key_exists( 'supplier_name', $row ) ) {
			$row['supplier_name'] = '';
		}
		if ( ! array_key_exists( 'destination', $row ) ) {
			$row['destination'] = '';
		}
		if ( ! array_key_exists( 'base_currency', $row ) ) {
			$row['base_currency'] = self::base_currency( $settings );
		}
		if ( ! array_key_exists( 'default_rate', $row ) ) {
			$default_rate        = self::to_float( self::setting( 'default_rate', 1, $settings ) );
			$row['default_rate'] = $default_rate > 0 ? $default_rate : 1;
		}
		if ( ! array_key_exists( 'proration_method', $row ) ) {
			$method                  = sanitize_key( (string) self::setting( 'proration_method', 'fob', $settings ) );
			$row['proration_method'] = in_array( $method, self::PRORATION_METHODS, true ) ? $method : 'fob';
		}
		if ( ! array_key_exists( 'notes', $row ) ) {
			$row['notes'] = '';
		}

		$now                   = current_time( 'mysql' );
		$row['fob_total']      = 0;
		$row['expenses_total'] = 0;
		$row['total']          = 0;
		$row['items_count']    = 0;
		$row['calc_json']      = null;
		$row['received_at']    = null;
		$row['received_by']    = null;
		$row['created_by']     = get_current_user_id() ? get_current_user_id() : null;
		$row['created_at']     = $now;
		$row['updated_at']     = $now;

		$result = $wpdb->insert( self::table(), $row, self::formats( $row, self::PURCHASE_FORMATS ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo guardar la compra.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;
		self::recalculate( $id );

		return $id;
	}

	/**
	 * Update header fields.
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return self::not_found_error();
		}

		$settings = self::settings();
		$row      = self::prepare_header( $data, $existing, $settings );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( array_key_exists( 'code', $data ) ) {
			$code = self::sanitize_code( $data['code'] );
			if ( '' === $code ) {
				return new WP_Error( 'mjerp_invalid_code', __( 'El código es obligatorio.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			if ( $code !== $existing['code'] && self::code_exists( $code, $id ) ) {
				return new WP_Error( 'mjerp_code_exists', __( 'Ya existe una compra con ese código.', 'mj-erp' ), array( 'status' => 409 ) );
			}
			$row['code'] = $code;
		}

		if ( empty( $row ) ) {
			return true;
		}

		$row['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::table(), $row, array( 'id' => $id ), self::formats( $row, self::PURCHASE_FORMATS ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar la compra.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		self::recalculate( $id );

		return true;
	}

	/**
	 * Delete a purchase with its items and expenses. Refuses received purchases.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$purchase = self::find( $id );
		if ( ! $purchase ) {
			return self::not_found_error();
		}
		if ( self::STATUS_RECEIVED === $purchase['status'] ) {
			return new WP_Error(
				'mjerp_locked',
				__( 'No se puede eliminar una compra recibida. Deshacé la recepción primero.', 'mj-erp' ),
				array( 'status' => 409 )
			);
		}

		$wpdb->delete( self::items_table(), array( 'purchase_id' => $id ), array( '%d' ) );
		$wpdb->delete( self::expenses_table(), array( 'purchase_id' => $id ), array( '%d' ) );
		$result = $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar la compra.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Duplicate a purchase (new code, status borrador, items and expenses copied).
	 *
	 * @return int|WP_Error
	 */
	public static function duplicate( int $id ) {
		global $wpdb;

		$source = self::find( $id );
		if ( ! $source ) {
			return self::not_found_error();
		}

		$new_id = self::create(
			array(
				'purchase_date'    => current_time( 'Y-m-d' ),
				'supplier_id'      => $source['supplier_id'],
				'supplier_name'    => $source['supplier_name'],
				'destination'      => $source['destination'],
				'base_currency'    => $source['base_currency'],
				'default_rate'     => $source['default_rate'],
				'proration_method' => $source['proration_method'],
				'notes'            => $source['notes'],
				'status'           => self::STATUS_DRAFT,
			)
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$now = current_time( 'mysql' );

		foreach ( self::items( $id ) as $item ) {
			$row = array(
				'purchase_id'      => $new_id,
				'product_id'       => $item['product_id'],
				'variation_id'     => $item['variation_id'],
				'name'             => $item['name'],
				'sku'              => $item['sku'],
				'quantity'         => $item['quantity'],
				'unit_cost'        => $item['unit_cost'],
				'currency'         => $item['currency'],
				'rate'             => $item['rate'],
				'fob_base'         => 0,
				'share_base'       => 0,
				'landed_unit_base' => 0,
				'received_qty'     => null,
				'sort_order'       => $item['sort_order'],
				'created_at'       => $now,
				'updated_at'       => $now,
			);
			$wpdb->insert( self::items_table(), $row, self::formats( $row, self::ITEM_FORMATS ) );
		}

		foreach ( self::expenses( $id ) as $expense ) {
			$row = array(
				'purchase_id'  => $new_id,
				'type'         => $expense['type'],
				'description'  => $expense['description'],
				'amount'       => $expense['amount'],
				'currency'     => $expense['currency'],
				'rate'         => $expense['rate'],
				'amount_base'  => 0,
				'is_prorated'  => $expense['is_prorated'] ? 1 : 0,
				'cashbox_id'   => $expense['cashbox_id'],
				'expense_date' => $expense['expense_date'],
				'sort_order'   => $expense['sort_order'],
				'created_at'   => $now,
				'updated_at'   => $now,
			);
			$wpdb->insert( self::expenses_table(), $row, self::formats( $row, self::EXPENSE_FORMATS ) );
		}

		self::recalculate( $new_id );

		return $new_id;
	}

	/**
	 * Whether a code is already used (optionally excluding one purchase).
	 */
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
	 * Next available code from settings (code_format, code_prefix, code_seq_digits).
	 */
	public static function next_code() : string {
		global $wpdb;

		$settings = self::settings();
		$format   = trim( (string) self::setting( 'code_format', '{prefix}-{year}-{seq}', $settings ) );
		if ( '' === $format ) {
			$format = '{prefix}-{year}-{seq}';
		}
		if ( false === strpos( $format, '{seq}' ) ) {
			$format .= '-{seq}';
		}
		$prefix = trim( (string) self::setting( 'code_prefix', 'IMP', $settings ) );
		$digits = (int) self::setting( 'code_seq_digits', 4, $settings );
		$digits = max( 1, min( 10, $digits ) );

		$resolved = strtr(
			$format,
			array(
				'{prefix}' => $prefix,
				'{year}'   => current_time( 'Y' ),
				'{month}'  => current_time( 'm' ),
			)
		);

		$pos    = strpos( $resolved, '{seq}' );
		$before = substr( $resolved, 0, $pos );
		$after  = substr( $resolved, $pos + 5 );
		$before = false === $before ? '' : $before;
		$after  = false === $after ? '' : $after;

		$table = self::table();
		if ( '' !== $before ) {
			$like  = $wpdb->esc_like( $before ) . '%';
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE code LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$seq      = $count + 1;
		$attempts = 0;
		do {
			$code = $before . str_pad( (string) $seq, $digits, '0', STR_PAD_LEFT ) . $after;
			if ( ! self::code_exists( $code ) ) {
				return $code;
			}
			$seq++;
			$attempts++;
		} while ( $attempts < 10000 );

		return $before . str_pad( (string) $seq, $digits, '0', STR_PAD_LEFT ) . $after . '-' . strtoupper( wp_generate_password( 4, false, false ) );
	}

	/**
	 * Change status.
	 *
	 * @param int    $id     Purchase id.
	 * @param string $status Status key.
	 * @param array  $extra  Optional: received_at (datetime|null), received_by (int|null).
	 * @return true|WP_Error
	 */
	public static function set_status( int $id, string $status, array $extra = array() ) {
		global $wpdb;

		$purchase = self::find( $id );
		if ( ! $purchase ) {
			return self::not_found_error();
		}

		$status = sanitize_key( $status );
		if ( ! in_array( $status, self::statuses(), true ) ) {
			return new WP_Error( 'mjerp_invalid_status', __( 'Estado inválido.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		$row = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( array_key_exists( 'received_at', $extra ) ) {
			$received_at = self::normalize_datetime( $extra['received_at'] );
			if ( false === $received_at ) {
				return new WP_Error( 'mjerp_invalid_date', __( 'Fecha de recepción inválida.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['received_at'] = $received_at;
		} elseif ( self::STATUS_RECEIVED === $status ) {
			$row['received_at'] = current_time( 'mysql' );
		} else {
			$row['received_at'] = null;
		}

		if ( array_key_exists( 'received_by', $extra ) ) {
			$row['received_by'] = absint( $extra['received_by'] ) > 0 ? absint( $extra['received_by'] ) : null;
		} elseif ( self::STATUS_RECEIVED === $status ) {
			$row['received_by'] = get_current_user_id() ? get_current_user_id() : null;
		} else {
			$row['received_by'] = null;
		}

		$result = $wpdb->update( self::table(), $row, array( 'id' => $id ), self::formats( $row, self::PURCHASE_FORMATS ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo cambiar el estado.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Dashboard stats.
	 */
	public static function stats() : array {
		global $wpdb;

		$table  = self::table();
		$counts = array_fill_keys( self::statuses(), 0 );
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['c'];
		}

		$year       = current_time( 'Y' );
		$month_from = current_time( 'Y-m-01' );
		$month_to   = current_time( 'Y-m-t' );
		$year_from  = $year . '-01-01';
		$year_to    = $year . '-12-31';

		$month = self::period_stats( $month_from, $month_to );
		$yearp = self::period_stats( $year_from, $year_to );

		$in_transit_total = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM {$table} WHERE status = %s", self::STATUS_TRANSIT ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$received_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS c, COALESCE(SUM(total),0) AS t FROM {$table} WHERE status = %s AND received_at >= %s AND received_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_RECEIVED,
				$month_from . ' 00:00:00',
				$month_to . ' 23:59:59'
			),
			ARRAY_A
		);

		return array(
			'counts'           => $counts,
			'total_count'      => (int) array_sum( $counts ),
			'month'            => $month,
			'year'             => $yearp,
			'in_transit_total' => $in_transit_total,
			'in_transit_count' => isset( $counts[ self::STATUS_TRANSIT ] ) ? (int) $counts[ self::STATUS_TRANSIT ] : 0,
			'received_month'   => array(
				'count' => $received_row ? (int) $received_row['c'] : 0,
				'total' => $received_row ? (float) $received_row['t'] : 0.0,
			),
			'base_currency'    => self::base_currency( self::settings() ),
		);
	}

	/**
	 * Most recent purchases (summary rows).
	 */
	public static function recent( int $limit = 5 ) : array {
		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::summary_row( $row );
		}
		return $items;
	}

	/**
	 * Number of purchases linked to a supplier.
	 */
	public static function count_by_supplier( int $supplier_id ) : int {
		global $wpdb;
		if ( $supplier_id <= 0 ) {
			return 0;
		}
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE supplier_id = %d", $supplier_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Number of expenses paid from a cashbox.
	 */
	public static function count_expenses_by_cashbox( int $cashbox_id ) : int {
		global $wpdb;
		if ( $cashbox_id <= 0 ) {
			return 0;
		}
		$table = self::expenses_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE cashbox_id = %d", $cashbox_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------ */
	/* Items                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Number of items of a purchase (cheap COUNT, no casting).
	 *
	 * @param int $purchase_id Purchase id.
	 * @return int
	 */
	public static function count_items( $purchase_id ) : int {
		global $wpdb;
		$purchase_id = absint( $purchase_id );
		if ( $purchase_id <= 0 ) {
			return 0;
		}
		$table = self::items_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE purchase_id = %d", $purchase_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Number of expenses of a purchase (cheap COUNT, no casting).
	 *
	 * @param int $purchase_id Purchase id.
	 * @return int
	 */
	public static function count_expenses( $purchase_id ) : int {
		global $wpdb;
		$purchase_id = absint( $purchase_id );
		if ( $purchase_id <= 0 ) {
			return 0;
		}
		$table = self::expenses_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE purchase_id = %d", $purchase_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Items of a purchase ordered by sort_order, id.
	 */
	public static function items( $purchase_id ) : array {
		global $wpdb;
		$purchase_id = absint( $purchase_id );
		if ( $purchase_id <= 0 ) {
			return array();
		}
		$table = self::items_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE purchase_id = %d ORDER BY sort_order ASC, id ASC", $purchase_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::cast_item( $row );
		}
		return $items;
	}

	/**
	 * Single item.
	 */
	public static function get_item( $item_id ) : ?array {
		global $wpdb;
		$item_id = absint( $item_id );
		if ( $item_id <= 0 ) {
			return null;
		}
		$table = self::items_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::cast_item( $row ) : null;
	}

	/**
	 * Add an item.
	 *
	 * @param int   $purchase_id Purchase id.
	 * @param array $data        Item data.
	 * @param bool  $recalculate Run recalculate() after insert (bulk operations defer it).
	 * @return int|WP_Error
	 */
	public static function add_item( $purchase_id, array $data, bool $recalculate = true ) {
		global $wpdb;

		$purchase_id = absint( $purchase_id );
		$purchase    = self::find( $purchase_id );
		if ( ! $purchase ) {
			return self::not_found_error();
		}

		$settings = self::settings();
		$row      = self::prepare_item( $data, null, $purchase, $settings );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$now = current_time( 'mysql' );

		$row['purchase_id'] = $purchase_id;
		if ( ! isset( $row['product_id'] ) ) {
			$row['product_id'] = 0;
		}
		if ( ! isset( $row['variation_id'] ) ) {
			$row['variation_id'] = 0;
		}
		if ( ! isset( $row['sku'] ) ) {
			$row['sku'] = '';
		}
		if ( ! array_key_exists( 'received_qty', $row ) ) {
			$row['received_qty'] = null;
		}
		if ( ! isset( $row['sort_order'] ) ) {
			$row['sort_order'] = self::next_sort_order( self::items_table(), $purchase_id );
		}
		$row['fob_base']         = 0;
		$row['share_base']       = 0;
		$row['landed_unit_base'] = 0;
		$row['created_at']       = $now;
		$row['updated_at']       = $now;

		$result = $wpdb->insert( self::items_table(), $row, self::formats( $row, self::ITEM_FORMATS ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo agregar el producto.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;
		if ( $recalculate ) {
			self::recalculate( $purchase_id );
		}

		return $id;
	}

	/**
	 * Update an item (any field).
	 *
	 * @return true|WP_Error
	 */
	public static function update_item( $item_id, array $data, bool $recalculate = true ) {
		global $wpdb;

		$item = self::get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'mjerp_not_found', __( 'El producto de la compra no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		$purchase = self::find( $item['purchase_id'] );
		if ( ! $purchase ) {
			return self::not_found_error();
		}

		$settings = self::settings();
		$row      = self::prepare_item( $data, $item, $purchase, $settings );
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

		if ( $recalculate ) {
			self::recalculate( $item['purchase_id'] );
		}

		return true;
	}

	/**
	 * Delete an item.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_item( $item_id ) {
		global $wpdb;

		$item = self::get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'mjerp_not_found', __( 'El producto de la compra no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$result = $wpdb->delete( self::items_table(), array( 'id' => $item['id'] ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar el producto.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		self::recalculate( $item['purchase_id'] );

		return true;
	}

	/**
	 * Link an existing item to a WooCommerce product (fills name/sku when empty).
	 *
	 * @return true|WP_Error
	 */
	public static function link_item( $item_id, $product_id, $variation_id = 0 ) {
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		if ( $product_id <= 0 && $variation_id <= 0 ) {
			return new WP_Error( 'mjerp_invalid_product', __( 'Indicá un producto para vincular.', 'mj-erp' ), array( 'status' => 400 ) );
		}
		return self::update_item(
			$item_id,
			array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
			)
		);
	}

	/**
	 * Bulk add items from a spreadsheet import or from the product picker.
	 *
	 * A row may identify the WooCommerce product directly with product_id (and
	 * optionally variation_id). When it does, the link is resolved by id and the
	 * SKU/name matching is skipped completely.
	 *
	 * @param int   $purchase_id Purchase id.
	 * @param array $rows        Rows: product_id?, variation_id?, sku?, name?, quantity, unit_cost, currency?, rate?.
	 * @param array $opts        match_by (sku|name|both), create_missing (bool), currency?, rate? (row defaults).
	 * @return array|WP_Error Report: added, linked, linked_by_id, created, unlinked, errors[{row,message}], total, item_ids.
	 */
	public static function add_items_bulk( $purchase_id, array $rows, array $opts = array() ) {
		$purchase_id = absint( $purchase_id );
		$purchase    = self::find( $purchase_id );
		if ( ! $purchase ) {
			return self::not_found_error();
		}

		$match_by = isset( $opts['match_by'] ) ? sanitize_key( (string) $opts['match_by'] ) : 'both';
		if ( ! in_array( $match_by, array( 'sku', 'name', 'both' ), true ) ) {
			$match_by = 'both';
		}
		$create_missing   = isset( $opts['create_missing'] ) ? self::to_bool( $opts['create_missing'] ) : false;
		$default_currency = isset( $opts['currency'] ) ? self::normalize_currency( $opts['currency'] ) : '';
		$default_rate     = isset( $opts['rate'] ) && '' !== $opts['rate'] && null !== $opts['rate'] ? self::to_float( $opts['rate'] ) : 0;
		$settings         = self::settings();
		$new_status       = sanitize_key( (string) self::setting( 'new_product_status', 'draft', $settings ) );
		if ( ! in_array( $new_status, array( 'draft', 'publish' ), true ) ) {
			$new_status = 'draft';
		}

		$report = array(
			'added'        => 0,
			'linked'       => 0,
			'linked_by_id' => 0,
			'created'      => 0,
			'unlinked'     => 0,
			'errors'       => array(),
			'total'        => count( $rows ),
			'item_ids'     => array(),
		);

		$sort_order = self::next_sort_order( self::items_table(), $purchase_id );
		$row_number = 0;

		foreach ( $rows as $raw ) {
			$row_number++;
			if ( ! is_array( $raw ) ) {
				$report['errors'][] = array(
					'row'     => $row_number,
					'message' => __( 'Fila inválida.', 'mj-erp' ),
				);
				continue;
			}

			$sku  = isset( $raw['sku'] ) ? trim( sanitize_text_field( (string) $raw['sku'] ) ) : '';
			$name = isset( $raw['name'] ) ? trim( sanitize_text_field( (string) $raw['name'] ) ) : '';

			$given_product_id   = isset( $raw['product_id'] ) ? absint( $raw['product_id'] ) : 0;
			$given_variation_id = isset( $raw['variation_id'] ) ? absint( $raw['variation_id'] ) : 0;
			$has_given_id       = ( $given_product_id > 0 || $given_variation_id > 0 );

			// A row without an explicit product id still needs a SKU or a name.
			if ( ! $has_given_id && '' === $sku && '' === $name ) {
				$report['errors'][] = array(
					'row'     => $row_number,
					'message' => __( 'La fila no tiene SKU ni nombre.', 'mj-erp' ),
				);
				continue;
			}

			$product_id   = 0;
			$variation_id = 0;
			$summary      = null;
			$link_status  = 'unlinked';
			$link_error   = '';
			$linked_by_id = false;

			// 0) Explicit WooCommerce id (product picker): never fall back to fuzzy matching.
			if ( $has_given_id ) {
				$given_label = $given_variation_id > 0 ? $given_variation_id : $given_product_id;
				$resolved    = self::resolve_product_link( $given_product_id, $given_variation_id );

				if ( is_wp_error( $resolved ) || ! is_array( $resolved ) || (int) $resolved[0] <= 0 ) {
					$detail = is_wp_error( $resolved )
						? $resolved->get_error_message()
						: __( 'El producto de WooCommerce no existe.', 'mj-erp' );
					$report['errors'][] = array(
						'row'     => $row_number,
						'message' => sprintf(
							/* translators: 1: WooCommerce product id, 2: error detail. */
							__( 'No se pudo vincular el producto #%1$d: %2$s', 'mj-erp' ),
							(int) $given_label,
							$detail
						),
					);
					continue;
				}

				$found_product_id   = (int) $resolved[0];
				$found_variation_id = (int) $resolved[1];
				$found_summary      = isset( $resolved[2] ) && is_array( $resolved[2] ) ? $resolved[2] : null;

				// A variable parent holds no stock/cost: the picker must send a variation.
				if ( $found_variation_id <= 0 && self::is_variable_product( $found_product_id, $found_summary ) ) {
					$report['errors'][] = array(
						'row'     => $row_number,
						'message' => sprintf(
							/* translators: %d: WooCommerce product id. */
							__( 'El producto #%d es variable: elegí una variación.', 'mj-erp' ),
							$found_product_id
						),
					);
					continue;
				}

				$product_id   = $found_product_id;
				$variation_id = $found_variation_id;
				$summary      = $found_summary;
				$link_status  = 'linked';
				$linked_by_id = true;
			}

			// 1) Match by SKU.
			if ( 'linked' !== $link_status && '' !== $sku && in_array( $match_by, array( 'sku', 'both' ), true ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$found_id = (int) wc_get_product_id_by_sku( $sku );
				if ( $found_id > 0 ) {
					$resolved = self::resolve_product_link( $found_id, 0 );
					if ( ! is_wp_error( $resolved ) && $resolved[0] > 0 ) {
						$product_id   = $resolved[0];
						$variation_id = $resolved[1];
						$summary      = $resolved[2];
						$link_status  = 'linked';
					}
				}
			}

			// 2) Match by exact name.
			if ( 'linked' !== $link_status && '' !== $name && in_array( $match_by, array( 'name', 'both' ), true )
				&& class_exists( 'MJERP_Products' ) && method_exists( 'MJERP_Products', 'find_by_name' ) ) {
				$found = MJERP_Products::find_by_name( $name );
				if ( is_array( $found ) && ! empty( $found['id'] ) ) {
					$fp = isset( $found['product_id'] ) ? (int) $found['product_id'] : (int) $found['id'];
					$fv = isset( $found['variation_id'] ) ? (int) $found['variation_id'] : 0;
					$resolved = self::resolve_product_link( $fp, $fv );
					if ( ! is_wp_error( $resolved ) && $resolved[0] > 0 ) {
						$product_id   = $resolved[0];
						$variation_id = $resolved[1];
						$summary      = $resolved[2] ? $resolved[2] : $found;
						$link_status  = 'linked';
					}
				}
			}

			// 3) Create when missing.
			if ( 'linked' !== $link_status && $create_missing ) {
				if ( '' === $name ) {
					$link_error = __( 'No se creó el producto: falta el nombre.', 'mj-erp' );
				} elseif ( ! class_exists( 'MJERP_Products' ) || ! method_exists( 'MJERP_Products', 'create' ) ) {
					$link_error = __( 'No se creó el producto: módulo de productos no disponible.', 'mj-erp' );
				} else {
					$created = MJERP_Products::create(
						array(
							'name'           => $name,
							'sku'            => $sku,
							'status'         => $new_status,
							'manage_stock'   => true,
							'stock_quantity' => 0,
						)
					);
					if ( is_wp_error( $created ) ) {
						$link_error = sprintf(
							/* translators: %s: error message */
							__( 'No se creó el producto: %s', 'mj-erp' ),
							$created->get_error_message()
						);
					} else {
						$created_id = 0;
						if ( is_array( $created ) ) {
							$created_id = isset( $created['product_id'] ) ? (int) $created['product_id'] : ( isset( $created['id'] ) ? (int) $created['id'] : 0 );
						} elseif ( is_numeric( $created ) ) {
							$created_id = (int) $created;
						} elseif ( is_object( $created ) && method_exists( $created, 'get_id' ) ) {
							$created_id = (int) $created->get_id();
						}
						if ( $created_id > 0 ) {
							$resolved = self::resolve_product_link( $created_id, 0 );
							if ( ! is_wp_error( $resolved ) && $resolved[0] > 0 ) {
								$product_id   = $resolved[0];
								$variation_id = $resolved[1];
								$summary      = $resolved[2] ? $resolved[2] : ( is_array( $created ) ? $created : null );
								$link_status  = 'created';
							} else {
								$link_error = __( 'No se pudo cargar el producto creado.', 'mj-erp' );
							}
						} else {
							$link_error = __( 'No se pudo crear el producto.', 'mj-erp' );
						}
					}
				}
			}

			$item_name = $name;
			if ( '' === $item_name && is_array( $summary ) && ! empty( $summary['name'] ) ) {
				$item_name = (string) $summary['name'];
			}
			if ( '' === $item_name ) {
				$item_name = $sku;
			}
			$item_sku = $sku;
			if ( '' === $item_sku && is_array( $summary ) && ! empty( $summary['sku'] ) ) {
				$item_sku = (string) $summary['sku'];
			}

			$currency = isset( $raw['currency'] ) ? self::normalize_currency( $raw['currency'] ) : '';
			if ( '' === $currency ) {
				$currency = '' !== $default_currency ? $default_currency : $purchase['base_currency'];
			}
			$rate = isset( $raw['rate'] ) && '' !== $raw['rate'] && null !== $raw['rate'] ? self::to_float( $raw['rate'] ) : 0;
			if ( $rate <= 0 ) {
				$rate = $default_rate > 0 ? $default_rate : $purchase['default_rate'];
			}

			$item_data = array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'name'         => $item_name,
				'sku'          => $item_sku,
				'quantity'     => isset( $raw['quantity'] ) ? $raw['quantity'] : 1,
				'unit_cost'    => isset( $raw['unit_cost'] ) ? $raw['unit_cost'] : 0,
				'currency'     => $currency,
				'rate'         => $rate,
				'sort_order'   => $sort_order,
			);

			$item_id = self::add_item( $purchase_id, $item_data, false );
			if ( is_wp_error( $item_id ) ) {
				$report['errors'][] = array(
					'row'     => $row_number,
					'message' => $item_id->get_error_message(),
				);
				continue;
			}

			$sort_order++;
			$report['added']++;
			$report['item_ids'][] = $item_id;
			if ( 'linked' === $link_status ) {
				$report['linked']++;
				if ( $linked_by_id ) {
					$report['linked_by_id']++;
				}
			} elseif ( 'created' === $link_status ) {
				$report['created']++;
				$report['linked']++;
			} else {
				$report['unlinked']++;
				if ( '' !== $link_error ) {
					$report['errors'][] = array(
						'row'     => $row_number,
						'message' => $link_error,
					);
				}
			}
		}

		self::recalculate( $purchase_id );

		return $report;
	}

	/* ------------------------------------------------------------------ */
	/* Expenses                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Expenses of a purchase ordered by sort_order, id.
	 */
	public static function expenses( $purchase_id ) : array {
		global $wpdb;
		$purchase_id = absint( $purchase_id );
		if ( $purchase_id <= 0 ) {
			return array();
		}
		$table = self::expenses_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE purchase_id = %d ORDER BY sort_order ASC, id ASC", $purchase_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast_expense( $row );
		}
		return $out;
	}

	/**
	 * Single expense.
	 */
	public static function get_expense( $expense_id ) : ?array {
		global $wpdb;
		$expense_id = absint( $expense_id );
		if ( $expense_id <= 0 ) {
			return null;
		}
		$table = self::expenses_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $expense_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::cast_expense( $row ) : null;
	}

	/**
	 * Add an expense.
	 *
	 * @return int|WP_Error
	 */
	public static function add_expense( $purchase_id, array $data, bool $recalculate = true ) {
		global $wpdb;

		$purchase_id = absint( $purchase_id );
		$purchase    = self::find( $purchase_id );
		if ( ! $purchase ) {
			return self::not_found_error();
		}

		$settings = self::settings();
		$row      = self::prepare_expense( $data, null, $purchase, $settings );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$now                = current_time( 'mysql' );
		$row['purchase_id'] = $purchase_id;
		if ( ! isset( $row['sort_order'] ) ) {
			$row['sort_order'] = self::next_sort_order( self::expenses_table(), $purchase_id );
		}
		if ( ! array_key_exists( 'expense_date', $row ) ) {
			$row['expense_date'] = null;
		}
		$row['amount_base'] = 0;
		$row['created_at']  = $now;
		$row['updated_at']  = $now;

		$result = $wpdb->insert( self::expenses_table(), $row, self::formats( $row, self::EXPENSE_FORMATS ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo agregar el gasto.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;
		if ( $recalculate ) {
			self::recalculate( $purchase_id );
		}

		return $id;
	}

	/**
	 * Update an expense.
	 *
	 * @return true|WP_Error
	 */
	public static function update_expense( $expense_id, array $data, bool $recalculate = true ) {
		global $wpdb;

		$expense = self::get_expense( $expense_id );
		if ( ! $expense ) {
			return new WP_Error( 'mjerp_not_found', __( 'El gasto no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		$purchase = self::find( $expense['purchase_id'] );
		if ( ! $purchase ) {
			return self::not_found_error();
		}

		$settings = self::settings();
		$row      = self::prepare_expense( $data, $expense, $purchase, $settings );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( empty( $row ) ) {
			return true;
		}

		$row['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::expenses_table(), $row, array( 'id' => $expense['id'] ), self::formats( $row, self::EXPENSE_FORMATS ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar el gasto.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		if ( $recalculate ) {
			self::recalculate( $expense['purchase_id'] );
		}

		return true;
	}

	/**
	 * Delete an expense.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_expense( $expense_id ) {
		global $wpdb;

		$expense = self::get_expense( $expense_id );
		if ( ! $expense ) {
			return new WP_Error( 'mjerp_not_found', __( 'El gasto no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$result = $wpdb->delete( self::expenses_table(), array( 'id' => $expense['id'] ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar el gasto.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		self::recalculate( $expense['purchase_id'] );

		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Recalculation                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Run the costing engine and persist cached columns.
	 *
	 * @return array Engine result: [ 'items' => [...], 'totals' => [...] ].
	 */
	public static function recalculate( int $purchase_id ) : array {
		global $wpdb;

		$purchase = self::find( $purchase_id );
		if ( ! $purchase ) {
			return array(
				'items'  => array(),
				'totals' => array(),
			);
		}

		$settings = self::settings();
		$items    = self::items( $purchase_id );
		$expenses = self::expenses( $purchase_id );
		$calc     = self::calculate( $purchase, $items, $expenses, $settings );
		$base     = $purchase['base_currency'];

		foreach ( $items as $index => $item ) {
			$c      = isset( $calc['items'][ $index ] ) && is_array( $calc['items'][ $index ] ) ? $calc['items'][ $index ] : array();
			$fob    = round( isset( $c['fob_base'] ) ? (float) $c['fob_base'] : 0.0, 6 );
			$share  = round( isset( $c['share_base'] ) ? (float) $c['share_base'] : 0.0, 6 );
			$landed = round( isset( $c['landed_unit_base'] ) ? (float) $c['landed_unit_base'] : 0.0, 6 );

			if ( ! self::same( $fob, $item['fob_base'] ) || ! self::same( $share, $item['share_base'] ) || ! self::same( $landed, $item['landed_unit_base'] ) ) {
				$wpdb->update(
					self::items_table(),
					array(
						'fob_base'         => $fob,
						'share_base'       => $share,
						'landed_unit_base' => $landed,
					),
					array( 'id' => $item['id'] ),
					array( '%f', '%f', '%f' ),
					array( '%d' )
				);
			}
		}

		foreach ( $expenses as $expense ) {
			$amount_base = round( self::to_base( $expense['amount'], $expense['currency'], $expense['rate'], $base ), 6 );
			if ( ! self::same( $amount_base, $expense['amount_base'] ) ) {
				$wpdb->update(
					self::expenses_table(),
					array( 'amount_base' => $amount_base ),
					array( 'id' => $expense['id'] ),
					array( '%f' ),
					array( '%d' )
				);
			}
		}

		$totals = isset( $calc['totals'] ) && is_array( $calc['totals'] ) ? $calc['totals'] : array();
		$row    = array(
			'fob_total'      => round( isset( $totals['fob_total'] ) ? (float) $totals['fob_total'] : 0.0, 4 ),
			'expenses_total' => round( isset( $totals['expenses_total'] ) ? (float) $totals['expenses_total'] : 0.0, 4 ),
			'total'          => round( isset( $totals['total'] ) ? (float) $totals['total'] : 0.0, 4 ),
			'items_count'    => count( $items ),
			'calc_json'      => wp_json_encode( $totals ),
			'updated_at'     => current_time( 'mysql' ),
		);
		$wpdb->update( self::table(), $row, array( 'id' => $purchase_id ), self::formats( $row, self::PURCHASE_FORMATS ), array( '%d' ) );

		return $calc;
	}

	/* ------------------------------------------------------------------ */
	/* Preparation / validation                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Sanitize + validate header fields present in $data.
	 *
	 * @return array|WP_Error
	 */
	private static function prepare_header( array $data, ?array $existing, array $settings ) {
		$row = array();

		if ( array_key_exists( 'purchase_date', $data ) ) {
			$date = self::normalize_date( $data['purchase_date'] );
			if ( false === $date ) {
				return new WP_Error( 'mjerp_invalid_date', __( 'La fecha de la compra es inválida.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			if ( null === $date ) {
				if ( $existing ) {
					return new WP_Error( 'mjerp_invalid_date', __( 'La fecha de la compra es obligatoria.', 'mj-erp' ), array( 'status' => 400 ) );
				}
				$date = current_time( 'Y-m-d' );
			}
			$row['purchase_date'] = $date;
		}

		if ( array_key_exists( 'supplier_id', $data ) ) {
			$supplier_id = absint( $data['supplier_id'] );
			if ( $supplier_id > 0 ) {
				$supplier = class_exists( 'MJERP_Suppliers_Repo' ) ? MJERP_Suppliers_Repo::find( $supplier_id ) : null;
				if ( ! $supplier ) {
					return new WP_Error( 'mjerp_invalid_supplier', __( 'El proveedor seleccionado no existe.', 'mj-erp' ), array( 'status' => 400 ) );
				}
				$row['supplier_id']   = $supplier_id;
				$row['supplier_name'] = self::text( $supplier['name'], 190 );
			} else {
				$row['supplier_id']   = null;
				$row['supplier_name'] = array_key_exists( 'supplier_name', $data ) ? self::text( $data['supplier_name'], 190 ) : '';
			}
		} elseif ( array_key_exists( 'supplier_name', $data ) ) {
			$row['supplier_name'] = self::text( $data['supplier_name'], 190 );
		}

		if ( array_key_exists( 'destination', $data ) ) {
			$row['destination'] = self::text( $data['destination'], 100 );
		}

		if ( array_key_exists( 'base_currency', $data ) && '' !== trim( (string) $data['base_currency'] ) ) {
			$currency = self::normalize_currency( $data['base_currency'] );
			if ( '' === $currency ) {
				return new WP_Error( 'mjerp_invalid_currency', __( 'La moneda base es inválida.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			if ( ! in_array( $currency, self::allowed_currencies( $settings ), true ) ) {
				return new WP_Error( 'mjerp_invalid_currency', __( 'La moneda base no está entre las monedas permitidas.', 'mj-erp' ), array( 'status' => 400 ) );
			}

			// Every item/expense rate is expressed against the CURRENT base currency, so swapping the
			// base on a purchase that already has lines would silently reinterpret all of them (a USD
			// line priced at 1583 ARS/USD would be read as if 1583 USD/ARS). Refuse instead.
			if ( $existing && isset( $existing['base_currency'] ) && $currency !== strtoupper( (string) $existing['base_currency'] ) ) {
				$purchase_id = isset( $existing['id'] ) ? (int) $existing['id'] : 0;
				if ( $purchase_id && ( self::count_items( $purchase_id ) > 0 || self::count_expenses( $purchase_id ) > 0 ) ) {
					return new WP_Error(
						'mjerp_base_currency_locked',
						__( 'No se puede cambiar la moneda base de una compra que ya tiene productos o gastos: cada tipo de cambio está expresado contra la moneda base actual. Borrá las líneas o duplicá la compra para empezar con otra moneda.', 'mj-erp' ),
						array( 'status' => 409 )
					);
				}
			}

			$row['base_currency'] = $currency;
		}

		if ( array_key_exists( 'default_rate', $data ) && '' !== $data['default_rate'] && null !== $data['default_rate'] ) {
			$rate = self::to_float( $data['default_rate'] );
			if ( $rate <= 0 ) {
				return new WP_Error( 'mjerp_invalid_rate', __( 'El tipo de cambio por defecto debe ser mayor a 0.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['default_rate'] = $rate;
		}

		if ( array_key_exists( 'proration_method', $data ) && '' !== trim( (string) $data['proration_method'] ) ) {
			$method = sanitize_key( (string) $data['proration_method'] );
			if ( ! in_array( $method, self::PRORATION_METHODS, true ) ) {
				return new WP_Error( 'mjerp_invalid_proration', __( 'El método de prorrateo es inválido.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['proration_method'] = $method;
		}

		if ( array_key_exists( 'notes', $data ) ) {
			$row['notes'] = sanitize_textarea_field( (string) $data['notes'] );
		}

		return $row;
	}

	/**
	 * Sanitize + validate item fields present in $data.
	 *
	 * @return array|WP_Error
	 */
	private static function prepare_item( array $data, ?array $existing, array $purchase, array $settings ) {
		$row     = array();
		$summary = null;

		$link_changed = array_key_exists( 'product_id', $data ) || array_key_exists( 'variation_id', $data );
		if ( $link_changed ) {
			$product_id   = array_key_exists( 'product_id', $data ) ? absint( $data['product_id'] ) : 0;
			$variation_id = array_key_exists( 'variation_id', $data ) ? absint( $data['variation_id'] ) : 0;
			$resolved     = self::resolve_product_link( $product_id, $variation_id );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$row['product_id']   = $resolved[0];
			$row['variation_id'] = $resolved[1];
			$summary             = $resolved[2];
		}

		if ( array_key_exists( 'name', $data ) ) {
			$row['name'] = self::text( $data['name'], 255 );
		}
		$name_final = isset( $row['name'] ) ? $row['name'] : ( $existing ? (string) $existing['name'] : '' );
		if ( '' === $name_final && is_array( $summary ) && ! empty( $summary['name'] ) ) {
			$row['name'] = self::text( $summary['name'], 255 );
			$name_final  = $row['name'];
		}
		if ( '' === $name_final ) {
			return new WP_Error( 'mjerp_invalid_item', __( 'El nombre del producto es obligatorio.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'sku', $data ) ) {
			$row['sku'] = self::text( $data['sku'], 100 );
		}
		$sku_final = isset( $row['sku'] ) ? $row['sku'] : ( $existing ? (string) $existing['sku'] : '' );
		if ( '' === $sku_final && is_array( $summary ) && ! empty( $summary['sku'] ) ) {
			$row['sku'] = self::text( $summary['sku'], 100 );
		}

		if ( array_key_exists( 'quantity', $data ) && '' !== $data['quantity'] && null !== $data['quantity'] ) {
			$quantity = self::to_float( $data['quantity'] );
			if ( $quantity <= 0 ) {
				return new WP_Error( 'mjerp_invalid_item', __( 'La cantidad debe ser mayor a 0.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['quantity'] = $quantity;
		} elseif ( ! $existing ) {
			$row['quantity'] = 1;
		}

		if ( array_key_exists( 'unit_cost', $data ) && '' !== $data['unit_cost'] && null !== $data['unit_cost'] ) {
			$unit_cost = self::to_float( $data['unit_cost'] );
			if ( $unit_cost < 0 ) {
				return new WP_Error( 'mjerp_invalid_item', __( 'El costo unitario no puede ser negativo.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['unit_cost'] = $unit_cost;
		} elseif ( ! $existing ) {
			$row['unit_cost'] = 0;
		}

		if ( array_key_exists( 'currency', $data ) && '' !== trim( (string) $data['currency'] ) ) {
			$currency = self::normalize_currency( $data['currency'] );
			if ( '' === $currency || ! in_array( $currency, self::allowed_currencies( $settings ), true ) ) {
				return new WP_Error( 'mjerp_invalid_currency', __( 'La moneda del producto no está permitida.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['currency'] = $currency;
		} elseif ( ! $existing ) {
			$row['currency'] = $purchase['base_currency'];
		}

		if ( array_key_exists( 'rate', $data ) && '' !== $data['rate'] && null !== $data['rate'] ) {
			$rate = self::to_float( $data['rate'] );
			if ( $rate <= 0 ) {
				return new WP_Error( 'mjerp_invalid_rate', __( 'El tipo de cambio del producto debe ser mayor a 0.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['rate'] = $rate;
		} elseif ( ! $existing ) {
			$row['rate'] = $purchase['default_rate'] > 0 ? $purchase['default_rate'] : 1;
		}

		if ( array_key_exists( 'received_qty', $data ) ) {
			if ( null === $data['received_qty'] || '' === $data['received_qty'] ) {
				$row['received_qty'] = null;
			} else {
				$received = self::to_float( $data['received_qty'] );
				if ( $received < 0 ) {
					return new WP_Error( 'mjerp_invalid_item', __( 'La cantidad recibida no puede ser negativa.', 'mj-erp' ), array( 'status' => 400 ) );
				}
				$row['received_qty'] = $received;
			}
		}

		if ( array_key_exists( 'sort_order', $data ) && null !== $data['sort_order'] && '' !== $data['sort_order'] ) {
			$row['sort_order'] = (int) $data['sort_order'];
		}

		return $row;
	}

	/**
	 * Sanitize + validate expense fields present in $data.
	 *
	 * @return array|WP_Error
	 */
	private static function prepare_expense( array $data, ?array $existing, array $purchase, array $settings ) {
		$row = array();

		if ( array_key_exists( 'type', $data ) ) {
			$type = sanitize_key( (string) $data['type'] );
			if ( '' === $type || ! in_array( $type, self::expense_type_keys( $settings ), true ) ) {
				$type = 'otro';
			}
			$row['type'] = $type;
		} elseif ( ! $existing ) {
			$row['type'] = 'otro';
		}

		if ( array_key_exists( 'description', $data ) ) {
			$row['description'] = self::text( $data['description'], 255 );
		} elseif ( ! $existing ) {
			$row['description'] = '';
		}

		if ( array_key_exists( 'amount', $data ) && '' !== $data['amount'] && null !== $data['amount'] ) {
			$amount = self::to_float( $data['amount'] );
			if ( $amount < 0 ) {
				return new WP_Error( 'mjerp_invalid_expense', __( 'El monto del gasto no puede ser negativo.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['amount'] = $amount;
		} elseif ( ! $existing ) {
			$row['amount'] = 0;
		}

		if ( array_key_exists( 'currency', $data ) && '' !== trim( (string) $data['currency'] ) ) {
			$currency = self::normalize_currency( $data['currency'] );
			if ( '' === $currency || ! in_array( $currency, self::allowed_currencies( $settings ), true ) ) {
				return new WP_Error( 'mjerp_invalid_currency', __( 'La moneda del gasto no está permitida.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['currency'] = $currency;
		} elseif ( ! $existing ) {
			$local           = self::local_currency( $settings );
			$row['currency'] = in_array( $local, self::allowed_currencies( $settings ), true ) ? $local : $purchase['base_currency'];
		}

		if ( array_key_exists( 'rate', $data ) && '' !== $data['rate'] && null !== $data['rate'] ) {
			$rate = self::to_float( $data['rate'] );
			if ( $rate <= 0 ) {
				return new WP_Error( 'mjerp_invalid_rate', __( 'El tipo de cambio del gasto debe ser mayor a 0.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['rate'] = $rate;
		} elseif ( ! $existing ) {
			$row['rate'] = $purchase['default_rate'] > 0 ? $purchase['default_rate'] : 1;
		}

		if ( array_key_exists( 'is_prorated', $data ) ) {
			$row['is_prorated'] = self::to_bool( $data['is_prorated'] ) ? 1 : 0;
		} elseif ( ! $existing ) {
			$row['is_prorated'] = 1;
		}

		if ( array_key_exists( 'cashbox_id', $data ) ) {
			$cashbox_id = absint( $data['cashbox_id'] );
			if ( $cashbox_id > 0 ) {
				$cashbox = class_exists( 'MJERP_Cashboxes_Repo' ) ? MJERP_Cashboxes_Repo::find( $cashbox_id ) : null;
				if ( ! $cashbox ) {
					return new WP_Error( 'mjerp_invalid_cashbox', __( 'La caja seleccionada no existe.', 'mj-erp' ), array( 'status' => 400 ) );
				}
			}
			$row['cashbox_id'] = $cashbox_id;
		} elseif ( ! $existing ) {
			$row['cashbox_id'] = 0;
		}

		if ( array_key_exists( 'expense_date', $data ) ) {
			$date = self::normalize_date( $data['expense_date'] );
			if ( false === $date ) {
				return new WP_Error( 'mjerp_invalid_date', __( 'La fecha del gasto es inválida.', 'mj-erp' ), array( 'status' => 400 ) );
			}
			$row['expense_date'] = $date;
		}

		if ( array_key_exists( 'sort_order', $data ) && null !== $data['sort_order'] && '' !== $data['sort_order'] ) {
			$row['sort_order'] = (int) $data['sort_order'];
		}

		return $row;
	}

	/**
	 * Resolve product/variation ids against WooCommerce.
	 *
	 * @return array|WP_Error [ product_id, variation_id, summary|null ]
	 */
	private static function resolve_product_link( int $product_id, int $variation_id ) {
		if ( $product_id <= 0 && $variation_id <= 0 ) {
			return array( 0, 0, null );
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'mjerp_wc_missing', __( 'WooCommerce no está disponible.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$lookup  = $variation_id > 0 ? $variation_id : $product_id;
		$product = wc_get_product( $lookup );
		if ( ! $product ) {
			return new WP_Error( 'mjerp_product_not_found', __( 'El producto de WooCommerce no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		if ( $product->is_type( 'variation' ) ) {
			$variation_id = (int) $product->get_id();
			$product_id   = (int) $product->get_parent_id();
		} else {
			$product_id   = (int) $product->get_id();
			$variation_id = 0;
		}

		return array( $product_id, $variation_id, self::product_summary( $product_id, $variation_id ) );
	}

	/**
	 * Whether a product id belongs to a variable parent (never linkable as item).
	 *
	 * @param int        $product_id Product id.
	 * @param array|null $summary    Product summary when already resolved.
	 * @return bool
	 */
	private static function is_variable_product( int $product_id, ?array $summary = null ) : bool {
		if ( is_array( $summary ) && isset( $summary['type'] ) ) {
			return 'variable' === (string) $summary['type'];
		}
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		return $product ? (bool) $product->is_type( 'variable' ) : false;
	}

	/* ------------------------------------------------------------------ */
	/* Casting                                                            */
	/* ------------------------------------------------------------------ */

	private static function cast_purchase( array $row ) : array {
		return array(
			'id'               => (int) self::v( $row, 'id' ),
			'code'             => (string) self::v( $row, 'code' ),
			'status'           => (string) self::v( $row, 'status', self::STATUS_DRAFT ),
			'purchase_date'    => self::date_or_null( self::v( $row, 'purchase_date' ) ),
			'supplier_id'      => (int) self::v( $row, 'supplier_id' ),
			'supplier_name'    => (string) self::v( $row, 'supplier_name' ),
			'destination'      => (string) self::v( $row, 'destination' ),
			'base_currency'    => strtoupper( (string) self::v( $row, 'base_currency', 'USD' ) ),
			'default_rate'     => (float) self::v( $row, 'default_rate', 1 ),
			'proration_method' => (string) self::v( $row, 'proration_method', 'fob' ),
			'notes'            => (string) self::v( $row, 'notes' ),
			'fob_total'        => (float) self::v( $row, 'fob_total' ),
			'expenses_total'   => (float) self::v( $row, 'expenses_total' ),
			'total'            => (float) self::v( $row, 'total' ),
			'items_count'      => (int) self::v( $row, 'items_count' ),
			'received_at'      => self::datetime_or_null( self::v( $row, 'received_at' ) ),
			'received_by'      => (int) self::v( $row, 'received_by' ),
			'created_by'       => (int) self::v( $row, 'created_by' ),
			'created_at'       => self::datetime_or_null( self::v( $row, 'created_at' ) ),
			'updated_at'       => self::datetime_or_null( self::v( $row, 'updated_at' ) ),
		);
	}

	private static function cast_item( array $row ) : array {
		$received = self::v( $row, 'received_qty' );
		return array(
			'id'               => (int) self::v( $row, 'id' ),
			'purchase_id'      => (int) self::v( $row, 'purchase_id' ),
			'product_id'       => (int) self::v( $row, 'product_id' ),
			'variation_id'     => (int) self::v( $row, 'variation_id' ),
			'name'             => (string) self::v( $row, 'name' ),
			'sku'              => (string) self::v( $row, 'sku' ),
			'quantity'         => (float) self::v( $row, 'quantity', 1 ),
			'unit_cost'        => (float) self::v( $row, 'unit_cost' ),
			'currency'         => strtoupper( (string) self::v( $row, 'currency', 'USD' ) ),
			'rate'             => (float) self::v( $row, 'rate', 1 ),
			'fob_base'         => (float) self::v( $row, 'fob_base' ),
			'share_base'       => (float) self::v( $row, 'share_base' ),
			'landed_unit_base' => (float) self::v( $row, 'landed_unit_base' ),
			'received_qty'     => ( null === $received || '' === $received ) ? null : (float) $received,
			'sort_order'       => (int) self::v( $row, 'sort_order' ),
			'created_at'       => self::datetime_or_null( self::v( $row, 'created_at' ) ),
			'updated_at'       => self::datetime_or_null( self::v( $row, 'updated_at' ) ),
		);
	}

	private static function cast_expense( array $row ) : array {
		return array(
			'id'           => (int) self::v( $row, 'id' ),
			'purchase_id'  => (int) self::v( $row, 'purchase_id' ),
			'type'         => (string) self::v( $row, 'type', 'otro' ),
			'description'  => (string) self::v( $row, 'description' ),
			'amount'       => (float) self::v( $row, 'amount' ),
			'currency'     => strtoupper( (string) self::v( $row, 'currency', 'ARS' ) ),
			'rate'         => (float) self::v( $row, 'rate', 1 ),
			'amount_base'  => (float) self::v( $row, 'amount_base' ),
			'is_prorated'  => (bool) (int) self::v( $row, 'is_prorated', 1 ),
			'cashbox_id'   => (int) self::v( $row, 'cashbox_id' ),
			'expense_date' => self::date_or_null( self::v( $row, 'expense_date' ) ),
			'sort_order'   => (int) self::v( $row, 'sort_order' ),
			'created_at'   => self::datetime_or_null( self::v( $row, 'created_at' ) ),
			'updated_at'   => self::datetime_or_null( self::v( $row, 'updated_at' ) ),
		);
	}

	/**
	 * Summary row for lists: cast row + status_label + can flags.
	 */
	private static function summary_row( array $row ) : array {
		$purchase                 = self::cast_purchase( $row );
		$purchase['status_label'] = self::status_label( $purchase['status'] );
		$purchase['can']          = self::can( $purchase );
		return $purchase;
	}

	/* ------------------------------------------------------------------ */
	/* Internal helpers                                                   */
	/* ------------------------------------------------------------------ */

	private static function v( array $row, string $key, $default = null ) {
		return ( isset( $row[ $key ] ) && null !== $row[ $key ] ) ? $row[ $key ] : $default;
	}

	private static function not_found_error() : WP_Error {
		return new WP_Error( 'mjerp_not_found', __( 'La compra no existe.', 'mj-erp' ), array( 'status' => 404 ) );
	}

	private static function formats( array $row, array $map ) : array {
		$formats = array();
		foreach ( array_keys( $row ) as $key ) {
			$formats[] = isset( $map[ $key ] ) ? $map[ $key ] : '%s';
		}
		return $formats;
	}

	private static function same( float $a, float $b ) : bool {
		return abs( $a - $b ) < 0.0000005;
	}

	private static function next_sort_order( string $table, int $purchase_id ) : int {
		global $wpdb;
		$max = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$table} WHERE purchase_id = %d", $purchase_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $max ? 1 : ( (int) $max + 1 );
	}

	private static function period_stats( string $from, string $to ) : array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS c, COALESCE(SUM(total),0) AS t FROM {$table} WHERE purchase_date >= %s AND purchase_date <= %s AND status <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to,
				self::STATUS_CANCELLED
			),
			ARRAY_A
		);
		return array(
			'count' => $row ? (int) $row['c'] : 0,
			'total' => $row ? (float) $row['t'] : 0.0,
		);
	}

	private static function sanitize_code( $code ) : string {
		$code = trim( sanitize_text_field( (string) $code ) );
		return self::text( $code, 60 );
	}

	private static function text( $value, int $max ) : string {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	private static function normalize_currency( $value ) : string {
		$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
		return 3 === strlen( $value ) ? $value : '';
	}

	private static function to_bool( $value ) : bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return 0 !== (int) $value;
		}
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( '1', 'true', 'yes', 'on', 'si', 'sí', 's', 'y' ), true );
	}

	private static function to_float( $value ) : float {
		if ( class_exists( 'MJERP_Helpers' ) && method_exists( 'MJERP_Helpers', 'to_float' ) ) {
			return (float) MJERP_Helpers::to_float( $value );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		$s = trim( (string) $value );
		if ( '' === $s ) {
			return 0.0;
		}
		$has_comma = false !== strpos( $s, ',' );
		$has_dot   = false !== strpos( $s, '.' );
		if ( $has_comma && $has_dot ) {
			if ( strrpos( $s, ',' ) > strrpos( $s, '.' ) ) {
				$s = str_replace( '.', '', $s );
				$s = str_replace( ',', '.', $s );
			} else {
				$s = str_replace( ',', '', $s );
			}
		} elseif ( $has_comma ) {
			$s = str_replace( ',', '.', $s );
		}
		$s = preg_replace( '/[^0-9.\-]/', '', $s );
		return is_numeric( $s ) ? (float) $s : 0.0;
	}

	private static function to_base( $amount, $currency, $rate, $base_currency ) : float {
		if ( class_exists( 'MJERP_Helpers' ) && method_exists( 'MJERP_Helpers', 'to_base' ) ) {
			return (float) MJERP_Helpers::to_base( (float) $amount, (string) $currency, (float) $rate, (string) $base_currency );
		}
		$amount = (float) $amount;
		$rate   = (float) $rate;
		if ( strtoupper( (string) $currency ) === strtoupper( (string) $base_currency ) ) {
			return $amount;
		}
		return $rate > 0 ? $amount / $rate : 0.0;
	}

	/**
	 * Accepts Y-m-d, Y-m-d H:i:s, d/m/Y, d-m-Y, Y/m/d. Returns 'Y-m-d', null (empty) or false (invalid).
	 */
	private static function normalize_date( $value ) {
		if ( null === $value || is_bool( $value ) || is_array( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value || '0000-00-00' === $value ) {
			return null;
		}
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m ) ) {
			$y = (int) $m[1];
			$mo = (int) $m[2];
			$d = (int) $m[3];
			return checkdate( $mo, $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : false;
		}
		if ( preg_match( '/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $m ) ) {
			$d = (int) $m[1];
			$mo = (int) $m[2];
			$y = (int) $m[3];
			return checkdate( $mo, $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : false;
		}
		if ( preg_match( '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $value, $m ) ) {
			$y = (int) $m[1];
			$mo = (int) $m[2];
			$d = (int) $m[3];
			return checkdate( $mo, $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : false;
		}
		return false;
	}

	/**
	 * Returns 'Y-m-d H:i:s', null (empty) or false (invalid). Accepts 'now'.
	 */
	private static function normalize_datetime( $value ) {
		if ( null === $value || is_bool( $value ) || is_array( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value || '0000-00-00 00:00:00' === $value || '0000-00-00' === $value ) {
			return null;
		}
		if ( 'now' === strtolower( $value ) ) {
			return current_time( 'mysql' );
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/', $value, $m ) ) {
			$date = self::normalize_date( $m[1] );
			if ( ! is_string( $date ) ) {
				return false;
			}
			$h = (int) $m[2];
			$i = (int) $m[3];
			$s = isset( $m[4] ) ? (int) $m[4] : 0;
			if ( $h > 23 || $i > 59 || $s > 59 ) {
				return false;
			}
			return sprintf( '%s %02d:%02d:%02d', $date, $h, $i, $s );
		}
		$date = self::normalize_date( $value );
		if ( is_string( $date ) ) {
			return $date . ' 00:00:00';
		}
		return false;
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

	private static function settings() : array {
		if ( class_exists( 'MJERP_Settings' ) && method_exists( 'MJERP_Settings', 'all' ) ) {
			return (array) MJERP_Settings::all();
		}
		return array();
	}

	private static function setting( string $key, $default = null, ?array $settings = null ) {
		if ( null === $settings ) {
			$settings = self::settings();
		}
		return ( isset( $settings[ $key ] ) && null !== $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	private static function base_currency( array $settings ) : string {
		$c = self::normalize_currency( self::setting( 'base_currency', 'USD', $settings ) );
		return '' === $c ? 'USD' : $c;
	}

	private static function local_currency( array $settings ) : string {
		$c = self::normalize_currency( self::setting( 'local_currency', 'ARS', $settings ) );
		return '' === $c ? 'ARS' : $c;
	}

	private static function allowed_currencies( array $settings ) : array {
		$out  = array();
		$list = self::setting( 'currencies', array(), $settings );
		if ( is_string( $list ) ) {
			$list = preg_split( '/[\s,]+/', $list );
		}
		foreach ( (array) $list as $c ) {
			$c = self::normalize_currency( $c );
			if ( '' !== $c ) {
				$out[] = $c;
			}
		}
		$out[] = self::base_currency( $settings );
		$out[] = self::local_currency( $settings );
		return array_values( array_unique( $out ) );
	}

	private static function expense_type_keys( array $settings ) : array {
		$keys  = array();
		$types = self::setting( 'expense_types', array(), $settings );
		foreach ( (array) $types as $key => $type ) {
			if ( is_array( $type ) && isset( $type['key'] ) ) {
				$keys[] = sanitize_key( (string) $type['key'] );
			} elseif ( is_string( $type ) && ! is_int( $key ) ) {
				$keys[] = sanitize_key( (string) $key );
			} elseif ( is_string( $type ) ) {
				$keys[] = sanitize_key( $type );
			}
		}
		if ( ! in_array( 'otro', $keys, true ) ) {
			$keys[] = 'otro';
		}
		return array_values( array_unique( array_filter( $keys ) ) );
	}

	private static function expense_type_label( string $key ) : string {
		if ( class_exists( 'MJERP_Settings' ) && method_exists( 'MJERP_Settings', 'expense_type_label' ) ) {
			$label = MJERP_Settings::expense_type_label( $key );
			if ( is_string( $label ) && '' !== $label ) {
				return $label;
			}
		}
		$types = self::setting( 'expense_types', array() );
		foreach ( (array) $types as $type ) {
			if ( is_array( $type ) && isset( $type['key'] ) && $type['key'] === $key && isset( $type['label'] ) ) {
				return (string) $type['label'];
			}
		}
		return 'otro' === $key ? __( 'Otro', 'mj-erp' ) : ucfirst( $key );
	}

	private static function cashbox_names() : array {
		$names = array();
		if ( class_exists( 'MJERP_Cashboxes_Repo' ) && method_exists( 'MJERP_Cashboxes_Repo', 'all' ) ) {
			foreach ( (array) MJERP_Cashboxes_Repo::all( true ) as $cashbox ) {
				if ( isset( $cashbox['id'] ) ) {
					$names[ (int) $cashbox['id'] ] = (string) $cashbox['name'];
				}
			}
		}
		return $names;
	}

	private static function user_name( int $user_id ) : ?string {
		if ( $user_id <= 0 ) {
			return null;
		}
		if ( class_exists( 'MJERP_Helpers' ) && method_exists( 'MJERP_Helpers', 'user_display_name' ) ) {
			$name = MJERP_Helpers::user_display_name( $user_id );
			return ( is_string( $name ) && '' !== $name ) ? $name : null;
		}
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : null;
	}

	/**
	 * Product summary for an item link (null when not linked or missing).
	 */
	private static function product_summary( int $product_id, int $variation_id ) : ?array {
		$lookup = $variation_id > 0 ? $variation_id : $product_id;
		if ( $lookup <= 0 ) {
			return null;
		}
		$key = $product_id . ':' . $variation_id;
		if ( array_key_exists( $key, self::$product_cache ) ) {
			return self::$product_cache[ $key ];
		}

		$summary = null;
		if ( class_exists( 'MJERP_Products' ) && method_exists( 'MJERP_Products', 'summary_by_ids' ) ) {
			$summary = MJERP_Products::summary_by_ids( $product_id, $variation_id );
		} elseif ( function_exists( 'wc_get_product' ) && class_exists( 'MJERP_Products' ) && method_exists( 'MJERP_Products', 'summary' ) ) {
			$product = wc_get_product( $lookup );
			if ( $product ) {
				$summary = MJERP_Products::summary( $product );
			}
		}

		self::$product_cache[ $key ] = is_array( $summary ) ? $summary : null;
		return self::$product_cache[ $key ];
	}

	/**
	 * Forget cached product summaries (e.g. after receive updates cost meta).
	 */
	public static function flush_product_cache() : void {
		self::$product_cache = array();
	}

	private static function calculate( array $purchase, array $items, array $expenses, array $settings ) : array {
		if ( class_exists( 'MJERP_Costing' ) && method_exists( 'MJERP_Costing', 'calculate' ) ) {
			$calc = MJERP_Costing::calculate( $purchase, $items, $expenses, $settings );
			if ( is_array( $calc ) ) {
				if ( ! isset( $calc['items'] ) || ! is_array( $calc['items'] ) ) {
					$calc['items'] = array();
				}
				if ( ! isset( $calc['totals'] ) || ! is_array( $calc['totals'] ) ) {
					$calc['totals'] = array();
				}
				return $calc;
			}
		}
		return array(
			'items'  => array(),
			'totals' => array(),
		);
	}

	private static function empty_item_calc( array $item, array $settings ) : array {
		$prices = array();
		foreach ( (array) self::setting( 'price_lists', array(), $settings ) as $list ) {
			if ( is_array( $list ) && isset( $list['key'] ) ) {
				$prices[ sanitize_key( (string) $list['key'] ) ] = array(
					'base'  => 0.0,
					'local' => 0.0,
				);
			}
		}
		return array(
			'item_id'           => isset( $item['id'] ) ? (int) $item['id'] : null,
			'fob_base'          => 0.0,
			'fob_local'         => 0.0,
			'unit_cost_base'    => 0.0,
			'share_base'        => 0.0,
			'share_pct'         => 0.0,
			'landed_total_base' => 0.0,
			'landed_unit_base'  => 0.0,
			'landed_unit_local' => 0.0,
			'prices'            => $prices,
		);
	}
}
