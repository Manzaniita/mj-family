<?php
defined( 'ABSPATH' ) || exit;

/**
 * Historial de una venta: quién cambió qué y cuándo.
 *
 * Una venta no es un documento quieto: se cobra en partes, se entrega días después, se le cambian
 * los productos. Sin un registro, el día que un total no cierra no hay forma de saber si alguien lo
 * tocó. Cada cambio se anota acá solo, escuchando los mismos hooks que ya dispara el módulo de
 * ventas, así ningún camino que modifique una venta —el ERP, la tienda o el portal— se lo saltea.
 *
 * Nunca hace fallar la operación que lo llamó: si una anotación no se puede escribir, el cambio de
 * fondo igual queda guardado. Lo ve solo quien puede corregir ventas
 * (MJERP_Capabilities::can_manage_sales()).
 *
 * @package MJ_ERP
 */
class MJERP_Sale_Log {

	/** Qué pasó. */
	const ACTION_CREATED           = 'creada';
	const ACTION_ITEMS             = 'productos';
	const ACTION_UPDATED           = 'datos';
	const ACTION_DELIVERY          = 'entrega';
	const ACTION_PAYMENT           = 'cobro';
	const ACTION_PAYMENT_CONFIRMED = 'cobro_confirmado';
	const ACTION_PAYMENT_DELETED   = 'cobro_borrado';
	const ACTION_ORDER             = 'pedido';
	const ACTION_VOIDED            = 'anulada';
	const ACTION_RETURN            = 'devolucion';
	const ACTION_CREDIT            = 'saldo';

	/**
	 * Columnas de la venta que se actualizan sin que nadie las haya cambiado: las mueve el propio
	 * sistema, o se derivan de otra que sí se anota (la caja sigue al medio de pago; la ficha, al
	 * nombre del cliente).
	 */
	const IGNORED_COLUMNS = array( 'order_status', 'wants_order', 'updated_at', 'cashbox_id', 'customer_id', 'customer_user_id' );

	const FORMATS = array(
		'sale_id'    => '%d',
		'action'     => '%s',
		'summary'    => '%s',
		'detail'     => '%s',
		'reason'     => '%s',
		'actor_id'   => '%d',
		'actor_name' => '%s',
		'created_at' => '%s',
	);

	private static function table() : string {
		return MJERP_Install::table( 'sale_log' );
	}

	/**
	 * Whether the history is on (Configuración → Ventas → Corregir ventas).
	 *
	 * @return bool
	 */
	public static function enabled() : bool {
		return MJERP_Helpers::bool( MJERP_Settings::get( 'sales_log_enabled', true ) );
	}

	/**
	 * Hooks the listeners.
	 *
	 * @return void
	 */
	public static function init() : void {
		add_action( 'mjerp_sale_created', array( __CLASS__, 'on_created' ), 20, 2 );
		add_action( 'mjerp_sale_updated', array( __CLASS__, 'on_updated' ), 20, 3 );
		// Antes que maybe_commit (10): la entrega se anota antes que el pedido que dispara.
		add_action( 'mjerp_sale_delivery_changed', array( __CLASS__, 'on_delivery' ), 5, 2 );
		add_action( 'mjerp_sale_payment_created', array( __CLASS__, 'on_payment_created' ), 20, 3 );
		add_action( 'mjerp_sale_payment_confirmed', array( __CLASS__, 'on_payment_confirmed' ), 20, 2 );
		add_action( 'mjerp_sale_payment_deleted', array( __CLASS__, 'on_payment_deleted' ), 20, 3 );
		add_action( 'mjerp_sale_order_created', array( __CLASS__, 'on_order_created' ), 20, 2 );
		add_action( 'mjerp_sale_voided', array( __CLASS__, 'on_voided' ), 20, 3 );
		add_action( 'mjerp_sale_deleted', array( __CLASS__, 'on_deleted' ), 20, 1 );
	}

	/* ------------------------------------------------------------------
	 * Read / write
	 * ---------------------------------------------------------------- */

	/**
	 * Anota un movimiento.
	 *
	 * @param array $data sale_id, action, summary, detail, reason, actor_name.
	 * @return int Id de la anotación (0 si no se pudo o el historial está apagado).
	 */
	public static function add( array $data ) : int {
		global $wpdb;

		$sale_id = absint( MJERP_Helpers::array_get( $data, 'sale_id', 0 ) );
		if ( $sale_id <= 0 || ! self::enabled() ) {
			return 0;
		}

		$detail = MJERP_Helpers::array_get( $data, 'detail', null );
		if ( is_array( $detail ) ) {
			$detail = wp_json_encode( $detail );
		}

		$actor_name = MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'actor_name', '' ), 190 );

		$row = array(
			'sale_id'    => $sale_id,
			'action'     => sanitize_key( (string) MJERP_Helpers::array_get( $data, 'action', '' ) ),
			'summary'    => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'summary', '' ), 255 ),
			'detail'     => is_string( $detail ) ? $detail : '',
			'reason'     => MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'reason', '' ), 255 ),
			'actor_id'   => get_current_user_id(),
			'actor_name' => '' !== $actor_name ? $actor_name : self::actor_name(),
			'created_at' => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( self::table(), $row, self::formats( $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Historial de una venta, del más nuevo al más viejo.
	 *
	 * @param int $sale_id Sale id.
	 * @param int $limit   Max rows.
	 * @return array
	 */
	public static function for_sale( $sale_id, int $limit = 200 ) : array {
		global $wpdb;

		$sale_id = absint( $sale_id );
		if ( $sale_id <= 0 ) {
			return array();
		}

		/**
		 * Filters how many entries of a sale's history are returned.
		 *
		 * @param int $limit   Max rows.
		 * @param int $sale_id Sale id.
		 */
		$limit = max( 1, (int) apply_filters( 'mjerp_sale_log_limit', $limit, $sale_id ) );

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE sale_id = %d ORDER BY created_at DESC, id DESC LIMIT %d", $sale_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row );
		}
		return $out;
	}

	/**
	 * Borra el historial de una venta (solo cuando la venta se borra de verdad).
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	public static function delete_for_sale( $sale_id ) : void {
		global $wpdb;
		$sale_id = absint( $sale_id );
		if ( $sale_id > 0 ) {
			$wpdb->delete( self::table(), array( 'sale_id' => $sale_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/* ------------------------------------------------------------------
	 * Listeners
	 * ---------------------------------------------------------------- */

	/**
	 * La venta nace.
	 *
	 * @param int   $sale_id Sale id.
	 * @param array $data    Input data (unused).
	 * @return void
	 */
	public static function on_created( $sale_id, $data = array() ) : void {
		unset( $data );

		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale ) {
			return;
		}

		$total = self::money( $sale['total'], $sale['currency'] );
		$actor = '';

		switch ( (string) $sale['source'] ) {
			case MJERP_Sales_Repo::SOURCE_SHOP:
				/* translators: 1: order number, 2: sale total */
				$summary = sprintf( __( 'Entró desde la tienda con el pedido #%1$s, por %2$s.', 'mj-erp' ), $sale['order_number'], $total );
				$actor   = __( 'La tienda', 'mj-erp' );
				break;
			case MJERP_Sales_Repo::SOURCE_PORTAL:
				/* translators: %s: sale total */
				$summary = sprintf( __( 'La cargó el revendedor, por %s.', 'mj-erp' ), $total );
				break;
			default:
				/* translators: %s: sale total */
				$summary = sprintf( __( 'Se cargó la venta por %s.', 'mj-erp' ), $total );
		}

		self::add(
			array(
				'sale_id'    => (int) $sale['id'],
				'action'     => self::ACTION_CREATED,
				'summary'    => $summary,
				'actor_name' => $actor,
				'detail'     => array( 'items' => self::items_snapshot( MJERP_Sales_Repo::items( (int) $sale['id'] ) ) ),
			)
		);
	}

	/**
	 * Cambió algún dato de la venta (canal, fecha, nota, cliente, medio de pago…).
	 *
	 * @param int        $sale_id Sale id.
	 * @param array      $row     Changed columns.
	 * @param array|null $before  Sale before the change.
	 * @return void
	 */
	public static function on_updated( $sale_id, $row, $before = null ) : void {
		if ( ! is_array( $row ) || ! is_array( $before ) ) {
			return;
		}

		$changes = array();
		foreach ( $row as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, self::IGNORED_COLUMNS, true ) ) {
				continue;
			}
			$old = array_key_exists( $key, $before ) ? $before[ $key ] : null;
			if ( (string) $old === (string) $value ) {
				continue;
			}
			$line = self::describe_change( $key, $old, $value );
			if ( '' !== $line ) {
				$changes[] = $line;
			}
		}

		if ( empty( $changes ) ) {
			return;
		}

		self::add(
			array(
				'sale_id' => (int) $sale_id,
				'action'  => self::ACTION_UPDATED,
				'summary' => 1 === count( $changes ) ? $changes[0] : __( 'Se cambiaron datos de la venta.', 'mj-erp' ),
				'detail'  => count( $changes ) > 1 ? array( 'changes' => $changes ) : null,
			)
		);
	}

	/**
	 * Cambió la entrega.
	 *
	 * @param int   $sale_id Sale id.
	 * @param array $row     Changed columns.
	 * @return void
	 */
	public static function on_delivery( $sale_id, $row ) : void {
		if ( ! is_array( $row ) ) {
			return;
		}

		$parts = array();
		if ( isset( $row['delivery_status'] ) ) {
			$statuses = MJERP_Settings::sale_delivery_statuses();
			$status   = (string) $row['delivery_status'];
			$parts[]  = isset( $statuses[ $status ]['label'] ) ? (string) $statuses[ $status ]['label'] : $status;
		}
		if ( array_key_exists( 'delivery_method', $row ) ) {
			$label = MJERP_Settings::sale_delivery_method_label( $row['delivery_method'] );
			if ( '' !== $label ) {
				$parts[] = $label;
			}
		}
		if ( array_key_exists( 'delivery_note', $row ) && '' !== (string) $row['delivery_note'] ) {
			$parts[] = (string) $row['delivery_note'];
		}
		if ( empty( $parts ) ) {
			return;
		}

		self::add(
			array(
				'sale_id' => (int) $sale_id,
				'action'  => self::ACTION_DELIVERY,
				/* translators: %s: delivery status, method and note */
				'summary' => sprintf( __( 'Entrega: %s', 'mj-erp' ), implode( ' · ', $parts ) ),
			)
		);
	}

	/**
	 * Entró un cobro.
	 *
	 * @param int    $payment_id Payment id.
	 * @param int    $sale_id    Sale id.
	 * @param string $status     declarado | confirmado.
	 * @return void
	 */
	public static function on_payment_created( $payment_id, $sale_id, $status = '' ) : void {
		unset( $status );

		$payment = MJERP_Sale_Payments_Repo::find( $payment_id );
		if ( ! $payment ) {
			return;
		}

		$summary = $payment['is_declared']
			/* translators: 1: amount, 2: payment method */
			? sprintf( __( 'Se declaró un cobro de %1$s (%2$s), a confirmar.', 'mj-erp' ), self::money( $payment['amount'], $payment['currency'] ), $payment['method_label'] )
			/* translators: 1: amount, 2: payment method */
			: sprintf( __( 'Cobro de %1$s (%2$s).', 'mj-erp' ), self::money( $payment['amount'], $payment['currency'] ), $payment['method_label'] );

		self::add(
			array(
				'sale_id'    => (int) $sale_id,
				'action'     => self::ACTION_PAYMENT,
				'summary'    => $summary,
				'actor_name' => MJERP_Sale_Payments_Repo::SOURCE_SHOP === (string) $payment['source'] ? __( 'La tienda', 'mj-erp' ) : '',
				'detail'     => self::payment_snapshot( $payment ),
			)
		);
	}

	/**
	 * Se confirmó un cobro declarado.
	 *
	 * @param int $payment_id Payment id.
	 * @param int $sale_id    Sale id.
	 * @return void
	 */
	public static function on_payment_confirmed( $payment_id, $sale_id ) : void {
		$payment = MJERP_Sale_Payments_Repo::find( $payment_id );
		if ( ! $payment ) {
			return;
		}

		self::add(
			array(
				'sale_id' => (int) $sale_id,
				'action'  => self::ACTION_PAYMENT_CONFIRMED,
				/* translators: 1: amount, 2: payment method */
				'summary' => sprintf( __( 'Se confirmó el cobro de %1$s (%2$s).', 'mj-erp' ), self::money( $payment['amount'], $payment['currency'] ), $payment['method_label'] ),
				'detail'  => self::payment_snapshot( $payment ),
			)
		);
	}

	/**
	 * Se borró un cobro.
	 *
	 * @param int        $payment_id Payment id.
	 * @param int        $sale_id    Sale id.
	 * @param array|null $payment    Payment before deleting.
	 * @return void
	 */
	public static function on_payment_deleted( $payment_id, $sale_id, $payment = null ) : void {
		unset( $payment_id );
		if ( ! is_array( $payment ) ) {
			return;
		}

		self::add(
			array(
				'sale_id' => (int) $sale_id,
				'action'  => self::ACTION_PAYMENT_DELETED,
				/* translators: 1: amount, 2: payment method */
				'summary' => sprintf( __( 'Se borró el cobro de %1$s (%2$s).', 'mj-erp' ), self::money( $payment['amount'], $payment['currency'] ), $payment['method_label'] ),
				'detail'  => self::payment_snapshot( $payment ),
			)
		);
	}

	/**
	 * Se emitió el pedido en WooCommerce.
	 *
	 * @param WC_Order|mixed $order   Order.
	 * @param int            $sale_id Sale id.
	 * @return void
	 */
	public static function on_order_created( $order, $sale_id ) : void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		self::add(
			array(
				'sale_id' => (int) $sale_id,
				'action'  => self::ACTION_ORDER,
				/* translators: %s: order number */
				'summary' => sprintf( __( 'Se emitió el pedido #%s en WooCommerce.', 'mj-erp' ), $order->get_order_number() ),
			)
		);
	}

	/**
	 * Se anuló la venta.
	 *
	 * @param int    $sale_id Sale id.
	 * @param array  $sale    Sale before voiding (unused).
	 * @param string $reason  Reason.
	 * @return void
	 */
	public static function on_voided( $sale_id, $sale = array(), $reason = '' ) : void {
		unset( $sale );

		self::add(
			array(
				'sale_id' => (int) $sale_id,
				'action'  => self::ACTION_VOIDED,
				'summary' => __( 'Se anuló la venta.', 'mj-erp' ),
				'reason'  => (string) $reason,
			)
		);
	}

	/**
	 * La venta se borró: su historial ya no tiene dónde verse.
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	public static function on_deleted( $sale_id ) : void {
		self::delete_for_sale( $sale_id );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Lo que se vendió, tal como estaba en ese momento.
	 *
	 * @param array $items Sale lines.
	 * @return array
	 */
	public static function items_snapshot( array $items ) : array {
		$out = array();
		foreach ( $items as $item ) {
			$out[] = array(
				'name'       => (string) $item['name'],
				'sku'        => (string) $item['sku'],
				'qty'        => (float) $item['qty'],
				'unit_price' => (float) $item['unit_price'],
				'discount'   => (float) $item['discount'],
				'line_total' => (float) $item['line_total'],
			);
		}
		return $out;
	}

	/**
	 * Importe con el símbolo de su moneda (sin centavos cuando es redondo).
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function money( $amount, $currency ) : string {
		$amount   = (float) $amount;
		$decimals = abs( $amount - round( $amount ) ) < 0.005 ? 0 : 2;
		return MJERP_Settings::symbol( $currency ) . ' ' . number_format_i18n( $amount, $decimals );
	}

	/**
	 * Un cambio de dato, en una línea.
	 *
	 * @param string $key Column.
	 * @param mixed  $old Previous value.
	 * @param mixed  $new New value.
	 * @return string '' when the column is not worth telling.
	 */
	private static function describe_change( string $key, $old, $new ) : string {
		switch ( $key ) {
			case 'channel':
				/* translators: 1: previous channel, 2: new channel */
				return sprintf( __( 'Canal: %1$s → %2$s', 'mj-erp' ), MJERP_Settings::sale_channel_label( $old ), MJERP_Settings::sale_channel_label( $new ) );
			case 'sale_date':
				/* translators: 1: previous date, 2: new date */
				return sprintf( __( 'Fecha: %1$s → %2$s', 'mj-erp' ), self::date_label( $old ), self::date_label( $new ) );
			case 'note':
				return __( 'Se cambió la nota.', 'mj-erp' );
			case 'customer_name':
				/* translators: 1: previous customer, 2: new customer */
				return sprintf( __( 'Cliente: %1$s → %2$s', 'mj-erp' ), self::or_dash( $old ), self::or_dash( $new ) );
			case 'customer_email':
				/* translators: 1: previous email, 2: new email */
				return sprintf( __( 'Email del cliente: %1$s → %2$s', 'mj-erp' ), self::or_dash( $old ), self::or_dash( $new ) );
			case 'customer_phone':
				/* translators: 1: previous phone, 2: new phone */
				return sprintf( __( 'Teléfono del cliente: %1$s → %2$s', 'mj-erp' ), self::or_dash( $old ), self::or_dash( $new ) );
			case 'payment_method':
				/* translators: 1: previous method, 2: new method */
				return sprintf( __( 'Medio de pago: %1$s → %2$s', 'mj-erp' ), MJERP_Settings::sale_payment_method_label( $old ), MJERP_Settings::sale_payment_method_label( $new ) );
			case 'delivery_method':
				/* translators: 1: previous delivery method, 2: new delivery method */
				return sprintf( __( 'Forma de entrega: %1$s → %2$s', 'mj-erp' ), self::or_dash( MJERP_Settings::sale_delivery_method_label( $old ) ), self::or_dash( MJERP_Settings::sale_delivery_method_label( $new ) ) );
			case 'delivery_note':
				/* translators: 1: previous place, 2: new place */
				return sprintf( __( 'Dónde se entrega: %1$s → %2$s', 'mj-erp' ), self::or_dash( $old ), self::or_dash( $new ) );
			case 'status':
				$statuses = MJERP_Settings::sale_statuses();
				return sprintf(
					/* translators: 1: previous status, 2: new status */
					__( 'Estado: %1$s → %2$s', 'mj-erp' ),
					isset( $statuses[ (string) $old ]['label'] ) ? $statuses[ (string) $old ]['label'] : self::or_dash( $old ),
					isset( $statuses[ (string) $new ]['label'] ) ? $statuses[ (string) $new ]['label'] : self::or_dash( $new )
				);
		}
		return '';
	}

	/**
	 * What of a payment is worth keeping next to its entry.
	 *
	 * @param array $payment Payment.
	 * @return array
	 */
	private static function payment_snapshot( array $payment ) : array {
		return array(
			'payment_id' => (int) $payment['id'],
			'amount'     => (float) $payment['amount'],
			'currency'   => (string) $payment['currency'],
			'method'     => (string) $payment['method'],
			'reference'  => (string) $payment['reference'],
		);
	}

	private static function date_label( $value ) : string {
		$value = trim( (string) $value );
		return '' === $value ? '—' : (string) mysql2date( (string) get_option( 'date_format' ), $value );
	}

	private static function or_dash( $value ) : string {
		$value = trim( (string) $value );
		return '' === $value ? '—' : $value;
	}

	/**
	 * Nombre de quien hace el cambio.
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
			'id'         => (int) $row['id'],
			'sale_id'    => (int) self::v( $row, 'sale_id', 0 ),
			'action'     => (string) self::v( $row, 'action' ),
			'summary'    => (string) self::v( $row, 'summary' ),
			'detail'     => is_array( $detail ) ? $detail : null,
			'reason'     => (string) self::v( $row, 'reason' ),
			'actor_name' => (string) self::v( $row, 'actor_name' ),
			'created_at' => self::datetime_or_null( self::v( $row, 'created_at' ) ),
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
