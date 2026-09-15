<?php
defined( 'ABSPATH' ) || exit;

/**
 * RMA: los productos fallados que volvieron de un cliente y esperan el reclamo al proveedor.
 *
 * Cada fila es un producto (con su cantidad) que no volvió al stock. Termina de una de dos formas:
 * **volvió al stock** (el proveedor lo cambió o se arregló) o **se dio de baja**. Recién ahí, si se dio
 * de baja, su costo pasa a ser pérdida por garantías; mientras está abierto no es pérdida de nadie.
 *
 * @package MJ_ERP
 */
class MJERP_RMA_Repo {

	const STATUS_OPEN        = 'abierto';
	const STATUS_RESTOCKED   = 'en_stock';
	const STATUS_WRITTEN_OFF = 'baja';

	const FORMATS = array(
		'code'            => '%s',
		'status'          => '%s',
		'sale_id'         => '%d',
		'return_id'       => '%d',
		'return_item_id'  => '%d',
		'customer_id'     => '%d',
		'product_id'      => '%d',
		'variation_id'    => '%d',
		'name'            => '%s',
		'sku'             => '%s',
		'qty'             => '%f',
		'unit_cost_base'  => '%f',
		'cost_base'       => '%f',
		'supplier_id'     => '%d',
		'supplier_name'   => '%s',
		'problem'         => '%s',
		'note'            => '%s',
		'resolution_note' => '%s',
		'resolved_at'     => '%s',
		'resolved_by'     => '%d',
		'created_by'      => '%d',
		'created_at'      => '%s',
		'updated_at'      => '%s',
	);

	private static function table() : string {
		return MJERP_Install::table( 'rma' );
	}

	/**
	 * Status catalogue.
	 *
	 * @return array key => { label, color }
	 */
	public static function statuses() : array {
		return array(
			self::STATUS_OPEN        => array(
				'label' => __( 'Abierto', 'mj-erp' ),
				'color' => 'warning',
			),
			self::STATUS_RESTOCKED   => array(
				'label' => __( 'Volvió al stock', 'mj-erp' ),
				'color' => 'success',
			),
			self::STATUS_WRITTEN_OFF => array(
				'label' => __( 'Dado de baja', 'mj-erp' ),
				'color' => 'danger',
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Write
	 * ---------------------------------------------------------------- */

	/**
	 * Opens an RMA.
	 *
	 * @param array $data sale_id, return_id, return_item_id, customer_id, product_id, variation_id,
	 *                    name, sku, qty, unit_cost_base, supplier_id, supplier_name, problem, note.
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$qty = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'qty', 0 ) ), 4 );
		if ( $qty <= 0 ) {
			return new WP_Error( 'mjerp_invalid_qty', __( 'La cantidad del RMA tiene que ser mayor a cero.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		$unit_cost = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'unit_cost_base', 0 ) ), 6 );
		$now       = current_time( 'mysql' );

		$row = array(
			'code'           => self::next_code(),
			'status'         => self::STATUS_OPEN,
			'sale_id'        => absint( MJERP_Helpers::array_get( $data, 'sale_id', 0 ) ),
			'return_id'      => absint( MJERP_Helpers::array_get( $data, 'return_id', 0 ) ),
			'return_item_id' => absint( MJERP_Helpers::array_get( $data, 'return_item_id', 0 ) ),
			'customer_id'    => absint( MJERP_Helpers::array_get( $data, 'customer_id', 0 ) ),
			'product_id'     => absint( MJERP_Helpers::array_get( $data, 'product_id', 0 ) ),
			'variation_id'   => absint( MJERP_Helpers::array_get( $data, 'variation_id', 0 ) ),
			'name'           => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'name', '' ), 255 ),
			'sku'            => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'sku', '' ), 100 ),
			'qty'            => $qty,
			'unit_cost_base' => $unit_cost,
			'cost_base'      => round( $unit_cost * $qty, 4 ),
			'supplier_id'    => absint( MJERP_Helpers::array_get( $data, 'supplier_id', 0 ) ),
			'supplier_name'  => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'supplier_name', '' ), 190 ),
			'problem'        => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'problem', '' ), 255 ),
			'note'           => sanitize_textarea_field( (string) MJERP_Helpers::array_get( $data, 'note', '' ) ),
			'created_by'     => get_current_user_id(),
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		$result = $wpdb->insert( self::table(), $row, self::formats( $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo abrir el RMA.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates what can be corrected on an RMA: the supplier, the problem and the note.
	 *
	 * @param int   $id   RMA id.
	 * @param array $data supplier_id, problem, note.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$rma = self::find( $id );
		if ( ! $rma ) {
			return new WP_Error( 'mjerp_not_found', __( 'El RMA no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$row = array();
		if ( array_key_exists( 'supplier_id', $data ) ) {
			$supplier_id = absint( $data['supplier_id'] );
			$supplier    = $supplier_id && class_exists( 'MJERP_Suppliers_Repo' ) ? MJERP_Suppliers_Repo::find( $supplier_id ) : null;
			if ( $supplier_id && ! $supplier ) {
				return new WP_Error( 'mjerp_invalid_supplier', __( 'Ese proveedor no existe.', 'mj-erp' ), array( 'status' => 400, 'field' => 'supplier_id' ) );
			}
			$row['supplier_id']   = $supplier ? (int) $supplier['id'] : 0;
			$row['supplier_name'] = $supplier ? MJERP_Helpers::text( $supplier['name'], 190 ) : '';
		}
		if ( array_key_exists( 'problem', $data ) ) {
			$row['problem'] = MJERP_Helpers::text( $data['problem'], 255 );
		}
		if ( array_key_exists( 'note', $data ) ) {
			$row['note'] = sanitize_textarea_field( (string) $data['note'] );
		}
		if ( empty( $row ) ) {
			return true;
		}

		$row['updated_at'] = current_time( 'mysql' );
		$result            = $wpdb->update( self::table(), $row, array( 'id' => $rma['id'] ), self::formats( $row ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false === $result
			? new WP_Error( 'mjerp_db_error', __( 'No se pudo guardar el RMA.', 'mj-erp' ), array( 'status' => 500 ) )
			: true;
	}

	/**
	 * Closes an RMA. The stock (if it goes back) is moved by MJERP_Returns before calling this.
	 *
	 * @param int    $id     RMA id.
	 * @param string $status en_stock | baja.
	 * @param string $note   Resolution note.
	 * @return true|WP_Error
	 */
	public static function mark_resolved( $id, string $status, string $note = '' ) {
		global $wpdb;

		$row = array(
			'status'          => $status,
			'resolution_note' => MJERP_Helpers::text( $note, 255 ),
			'resolved_at'     => current_time( 'mysql' ),
			'resolved_by'     => get_current_user_id(),
			'updated_at'      => current_time( 'mysql' ),
		);

		$result = $wpdb->update( self::table(), $row, array( 'id' => absint( $id ) ), self::formats( $row ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false === $result
			? new WP_Error( 'mjerp_db_error', __( 'No se pudo cerrar el RMA.', 'mj-erp' ), array( 'status' => 500 ) )
			: true;
	}

	/* ------------------------------------------------------------------
	 * Read
	 * ---------------------------------------------------------------- */

	/**
	 * One RMA.
	 *
	 * @param int $id RMA id.
	 * @return array|null
	 */
	public static function find( $id ) : ?array {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		$table = self::table();
		$sales = MJERP_Install::table( 'sales' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT r.*, s.code AS sale_code FROM {$table} r LEFT JOIN {$sales} s ON s.id = r.sale_id WHERE r.id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Paginated listing.
	 *
	 * @param array $args status, supplier_id, search, page, per_page.
	 * @return array { items, total, page, per_page, pages }
	 */
	public static function query( array $args ) : array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'      => '',
				'supplier_id' => 0,
				'search'      => '',
				'page'        => 1,
				'per_page'    => 30,
			)
		);

		$table  = self::table();
		$sales  = MJERP_Install::table( 'sales' );
		$where  = array( '1=1' );
		$params = array();

		$status = sanitize_key( (string) $args['status'] );
		if ( '' !== $status && isset( self::statuses()[ $status ] ) ) {
			$where[]  = 'r.status = %s';
			$params[] = $status;
		}

		$supplier_id = absint( $args['supplier_id'] );
		if ( $supplier_id > 0 ) {
			$where[]  = 'r.supplier_id = %d';
			$params[] = $supplier_id;
		}

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(r.code LIKE %s OR r.name LIKE %s OR r.sku LIKE %s OR r.supplier_name LIKE %s OR s.code LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 200, (int) $args['per_page'] ) );
		$page      = max( 1, (int) $args['page'] );
		$offset    = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} r LEFT JOIN {$sales} s ON s.id = r.sale_id WHERE {$where_sql}";
		$total     = (int) ( empty( $params )
			? $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			: $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$list_sql = "SELECT r.*, s.code AS sale_code FROM {$table} r LEFT JOIN {$sales} s ON s.id = r.sale_id WHERE {$where_sql} ORDER BY r.created_at DESC, r.id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::cast( $row );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Headline numbers: what is open and what was lost to warranties.
	 *
	 * @return array { open_count, open_qty, open_cost_base, loss_month_base, loss_year_base, loss_total_base }
	 */
	public static function totals() : array {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN status = %s THEN 1 ELSE 0 END),0) AS open_count,
					COALESCE(SUM(CASE WHEN status = %s THEN qty ELSE 0 END),0) AS open_qty,
					COALESCE(SUM(CASE WHEN status = %s THEN cost_base ELSE 0 END),0) AS open_cost_base,
					COALESCE(SUM(CASE WHEN status = %s AND DATE_FORMAT(resolved_at,'%%Y-%%m') = %s THEN cost_base ELSE 0 END),0) AS loss_month_base,
					COALESCE(SUM(CASE WHEN status = %s AND YEAR(resolved_at) = %s THEN cost_base ELSE 0 END),0) AS loss_year_base,
					COALESCE(SUM(CASE WHEN status = %s THEN cost_base ELSE 0 END),0) AS loss_total_base
				FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_OPEN,
				self::STATUS_OPEN,
				self::STATUS_OPEN,
				self::STATUS_WRITTEN_OFF,
				current_time( 'Y-m' ),
				self::STATUS_WRITTEN_OFF,
				current_time( 'Y' ),
				self::STATUS_WRITTEN_OFF
			),
			ARRAY_A
		);

		return array(
			'open_count'      => (int) MJERP_Helpers::array_get( $row, 'open_count', 0 ),
			'open_qty'        => round( (float) MJERP_Helpers::array_get( $row, 'open_qty', 0 ), 4 ),
			'open_cost_base'  => round( (float) MJERP_Helpers::array_get( $row, 'open_cost_base', 0 ), 4 ),
			'loss_month_base' => round( (float) MJERP_Helpers::array_get( $row, 'loss_month_base', 0 ), 4 ),
			'loss_year_base'  => round( (float) MJERP_Helpers::array_get( $row, 'loss_year_base', 0 ), 4 ),
			'loss_total_base' => round( (float) MJERP_Helpers::array_get( $row, 'loss_total_base', 0 ), 4 ),
		);
	}

	/**
	 * How many RMAs are waiting (menu badge).
	 *
	 * @return int
	 */
	public static function open_count() : int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_OPEN ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Next free code: `{prefijo}-{año}-{secuencia}`.
	 *
	 * @return string
	 */
	public static function next_code() : string {
		global $wpdb;

		$prefix = trim( (string) MJERP_Settings::get( 'rma_code_prefix', 'RMA' ) );
		$base   = ( '' !== $prefix ? $prefix : 'RMA' ) . '-' . current_time( 'Y' ) . '-';
		$table  = self::table();

		$last = (string) $wpdb->get_var( $wpdb->prepare( "SELECT code FROM {$table} WHERE code LIKE %s ORDER BY id DESC LIMIT 1", $wpdb->esc_like( $base ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$seq  = '' !== $last ? (int) substr( $last, strlen( $base ) ) + 1 : 1;

		for ( $i = 0; $i < 1000; $i++, $seq++ ) {
			$code  = $base . str_pad( (string) $seq, 4, '0', STR_PAD_LEFT );
			$taken = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s LIMIT 1", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( ! $taken ) {
				return $code;
			}
		}

		return $base . time();
	}

	/* ------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	private static function cast( array $row ) : array {
		$status   = (string) self::v( $row, 'status', self::STATUS_OPEN );
		$statuses = self::statuses();

		return array(
			'id'               => (int) $row['id'],
			'code'             => (string) self::v( $row, 'code' ),
			'status'           => $status,
			'status_label'     => isset( $statuses[ $status ] ) ? $statuses[ $status ]['label'] : $status,
			'status_color'     => isset( $statuses[ $status ] ) ? $statuses[ $status ]['color'] : 'neutral',
			'sale_id'          => (int) self::v( $row, 'sale_id', 0 ),
			'sale_code'        => (string) self::v( $row, 'sale_code' ),
			'return_id'        => (int) self::v( $row, 'return_id', 0 ),
			'return_item_id'   => (int) self::v( $row, 'return_item_id', 0 ),
			'customer_id'      => (int) self::v( $row, 'customer_id', 0 ),
			'product_id'       => (int) self::v( $row, 'product_id', 0 ),
			'variation_id'     => (int) self::v( $row, 'variation_id', 0 ),
			'name'             => (string) self::v( $row, 'name' ),
			'sku'              => (string) self::v( $row, 'sku' ),
			'qty'              => round( (float) self::v( $row, 'qty', 0 ), 4 ),
			'unit_cost_base'   => round( (float) self::v( $row, 'unit_cost_base', 0 ), 6 ),
			'cost_base'        => round( (float) self::v( $row, 'cost_base', 0 ), 4 ),
			'supplier_id'      => (int) self::v( $row, 'supplier_id', 0 ),
			'supplier_name'    => (string) self::v( $row, 'supplier_name' ),
			'problem'          => (string) self::v( $row, 'problem' ),
			'note'             => (string) self::v( $row, 'note' ),
			'resolution_note'  => (string) self::v( $row, 'resolution_note' ),
			'resolved_at'      => self::v( $row, 'resolved_at', null ),
			'resolved_by_name' => MJERP_Helpers::user_display_name( self::v( $row, 'resolved_by', 0 ) ),
			'created_by_name'  => MJERP_Helpers::user_display_name( self::v( $row, 'created_by', 0 ) ),
			'created_at'       => (string) self::v( $row, 'created_at' ),
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
}
