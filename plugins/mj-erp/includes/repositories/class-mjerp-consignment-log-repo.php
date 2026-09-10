<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bitácora de la cuenta de un revendedor.
 *
 * Existe por una razón concreta: desde el ERP se pueden corregir precios acordados, ventas y
 * pagos. Sin un registro visible, el revendedor abre su panel un día y su deuda es otra sin que
 * nadie le haya dicho nada. Cada corrección se anota acá y él la ve en su propia pantalla.
 *
 * No reemplaza al estado de cuenta (eso son los movimientos que suman y restan): esto cuenta
 * *quién cambió qué y por qué*.
 *
 * @package MJ_ERP
 */
class MJERP_Consignment_Log {

	/** Qué se tocó. */
	const KIND_PRICE     = 'precio';
	const KIND_SALE      = 'venta';
	const KIND_PAYMENT   = 'pago';
	const KIND_DELIVERY  = 'entrega';
	const KIND_RETURN    = 'devolucion';

	/** Qué se le hizo. */
	const ACTION_CREATED   = 'creado';
	const ACTION_UPDATED   = 'editado';
	const ACTION_DELETED   = 'eliminado';
	const ACTION_CONFIRMED = 'confirmado';
	const ACTION_REJECTED  = 'rechazado';

	const FORMATS = array(
		'consignee_id'         => '%d',
		'kind'                 => '%s',
		'action'               => '%s',
		'object_id'            => '%d',
		'summary'              => '%s',
		'detail'               => '%s',
		'amount_base'          => '%f',
		'reason'               => '%s',
		'visible_to_consignee' => '%d',
		'actor_id'             => '%d',
		'actor_name'           => '%s',
		'source'               => '%s',
		'created_at'           => '%s',
	);

	private static function table() : string {
		return MJERP_Install::table( 'consignment_log' );
	}

	/**
	 * Anota un movimiento.
	 *
	 * Nunca hace fallar la operación que la llamó: si la bitácora no se puede escribir, el
	 * cambio de fondo (la venta, el pago) igual tiene que quedar guardado.
	 *
	 * @param array $data consignee_id, kind, action, object_id, summary, detail, amount_base,
	 *                    reason, visible_to_consignee, source.
	 * @return int Id de la anotación (0 si no se pudo).
	 */
	public static function add( array $data ) : int {
		global $wpdb;

		$consignee_id = absint( MJERP_Helpers::array_get( $data, 'consignee_id', 0 ) );
		if ( $consignee_id <= 0 ) {
			return 0;
		}

		$detail = MJERP_Helpers::array_get( $data, 'detail', null );
		if ( is_array( $detail ) ) {
			$detail = wp_json_encode( $detail );
		}

		$amount = MJERP_Helpers::array_get( $data, 'amount_base', null );

		$row = array(
			'consignee_id'         => $consignee_id,
			'kind'                 => sanitize_key( (string) MJERP_Helpers::array_get( $data, 'kind', '' ) ),
			'action'               => sanitize_key( (string) MJERP_Helpers::array_get( $data, 'action', '' ) ),
			'object_id'            => absint( MJERP_Helpers::array_get( $data, 'object_id', 0 ) ),
			'summary'              => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'summary', '' ), 255 ),
			'detail'               => is_string( $detail ) ? $detail : '',
			'amount_base'          => ( null === $amount || '' === $amount ) ? null : round( MJERP_Helpers::to_float( $amount ), 4 ),
			'reason'               => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'reason', '' ), 255 ),
			'visible_to_consignee' => MJERP_Helpers::bool( MJERP_Helpers::array_get( $data, 'visible_to_consignee', true ) ) ? 1 : 0,
			'actor_id'             => get_current_user_id(),
			'actor_name'           => self::actor_name(),
			'source'               => sanitize_key( (string) MJERP_Helpers::array_get( $data, 'source', 'admin' ) ),
			'created_at'           => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( self::table(), $row, self::formats( $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Movimientos de un revendedor, del más nuevo al más viejo.
	 *
	 * @param array $args consignee_id, only_visible, page, per_page.
	 * @return array { items, total, pages, page, per_page }
	 */
	public static function query( array $args ) : array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'consignee_id' => 0,
				'only_visible' => false,
				'page'         => 1,
				'per_page'     => 30,
			)
		);

		$consignee_id = absint( $args['consignee_id'] );
		if ( $consignee_id <= 0 ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'pages'    => 1,
				'page'     => 1,
				'per_page' => (int) $args['per_page'],
			);
		}

		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		$table = self::table();
		$where = 'consignee_id = %d';
		$prep  = array( $consignee_id );

		if ( ! empty( $args['only_visible'] ) ) {
			$where .= ' AND visible_to_consignee = 1';
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $prep ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				array_merge( $prep, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::cast( $row );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per_page ),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Borra la bitácora de un revendedor (solo al eliminarlo de verdad).
	 *
	 * @param int $consignee_id Consignee id.
	 * @return void
	 */
	public static function delete_for_consignee( $consignee_id ) : void {
		global $wpdb;
		$consignee_id = absint( $consignee_id );
		if ( $consignee_id > 0 ) {
			$wpdb->delete( self::table(), array( 'consignee_id' => $consignee_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Nombre de quien hace el cambio, para mostrarlo en la bitácora.
	 *
	 * @return string
	 */
	private static function actor_name() : string {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return __( 'El sistema', 'mj-erp' );
		}
		return MJERP_Helpers::user_display_name( $user_id );
	}

	private static function cast( array $row ) : array {
		$detail = isset( $row['detail'] ) && '' !== (string) $row['detail'] ? json_decode( (string) $row['detail'], true ) : null;

		return array(
			'id'          => (int) $row['id'],
			'kind'        => (string) self::v( $row, 'kind' ),
			'action'      => (string) self::v( $row, 'action' ),
			'object_id'   => (int) self::v( $row, 'object_id', 0 ),
			'summary'     => (string) self::v( $row, 'summary' ),
			'detail'      => is_array( $detail ) ? $detail : null,
			'amount_base' => ( null === self::v( $row, 'amount_base', null ) ) ? null : round( (float) $row['amount_base'], 4 ),
			'reason'      => (string) self::v( $row, 'reason' ),
			'actor_name'  => (string) self::v( $row, 'actor_name' ),
			'source'      => (string) self::v( $row, 'source', 'admin' ),
			'created_at'  => self::datetime_or_null( self::v( $row, 'created_at' ) ),
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
