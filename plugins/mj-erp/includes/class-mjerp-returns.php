<?php
/**
 * Devoluciones, cambios y garantías de una venta cobrada, qué pasa con la plata al anular una, y el
 * cierre de cada RMA.
 *
 * Las reglas, en el orden en que se aplican:
 *
 *   - **Qué vuelve.** Por cada línea: cuántas unidades, a dónde va cada una (al stock o a RMA si vino
 *     fallada) y si se le entrega el mismo producto sin cargo (garantía).
 *   - **Cuánto se le reconoce: lo que pagó.** El precio de la línea con el descuento de la venta
 *     repartido: un descuento no es plata a favor. Lo que se reemplaza por garantía no mueve plata.
 *   - **La venta queda con lo que se llevó al final**: las unidades que vuelven salen de sus líneas
 *     (con su parte del descuento) y lo que se lleva a cambio entra a precio de hoy.
 *   - **La plata.** Si lo cobrado supera el total nuevo, el sobrante sale de la venta como un cobro
 *     negativo y queda a favor del cliente —repartido por moneda según cómo pagó— o se le devuelve de
 *     una caja. Si no alcanza, queda en «Falta cobrar».
 *   - **El stock lo mueve el ERP**, venga la venta de donde venga: lo que vuelve al stock entra, el
 *     reemplazo y lo nuevo salen, y lo fallado no entra hasta que su RMA vuelva al stock. Si la venta
 *     tiene pedido, sus líneas se reescriben sin volver a mover stock, así cancelarlo después devuelve
 *     exactamente lo que el cliente se quedó.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Returns
 */
class MJERP_Returns {

	const EPSILON = 0.0001;

	/** Movement types. */
	const MOVE_RETURN      = 'sale_return';
	const MOVE_EXCHANGE    = 'sale_exchange';
	const MOVE_REPLACEMENT = 'rma_replacement';
	const MOVE_RESTOCK     = 'rma_restock';

	/**
	 * Hooks the returns and the return state into every full sale the API returns.
	 *
	 * @return void
	 */
	public static function init() : void {
		add_filter( 'mjerp_sale_full', array( __CLASS__, 'attach' ), 20, 1 );
	}

	/**
	 * Whether returns are on.
	 *
	 * @return bool
	 */
	public static function enabled() : bool {
		return MJERP_Helpers::bool( MJERP_Settings::get( 'returns_enabled', true ) );
	}

	/**
	 * Whether faulty units go to an RMA list.
	 *
	 * @return bool
	 */
	public static function rma_enabled() : bool {
		return MJERP_Helpers::bool( MJERP_Settings::get( 'rma_enabled', true ) );
	}

	/**
	 * What happens by default with the money left over.
	 *
	 * @return string saldo | reintegro
	 */
	public static function default_settlement() : string {
		$value = sanitize_key( (string) MJERP_Settings::get( 'returns_default_settlement', MJERP_Returns_Repo::SETTLE_CREDIT ) );
		return MJERP_Returns_Repo::SETTLE_REFUND === $value ? $value : MJERP_Returns_Repo::SETTLE_CREDIT;
	}

	/**
	 * Adds `returns` and `return_state` to a full sale.
	 *
	 * @param mixed $sale Sale.
	 * @return mixed
	 */
	public static function attach( $sale ) {
		if ( is_array( $sale ) && isset( $sale['id'] ) ) {
			$sale['returns']      = MJERP_Returns_Repo::for_sale( (int) $sale['id'] );
			$sale['return_state'] = self::state( $sale );
		}
		return $sale;
	}

	/**
	 * What the current user can do with a paid sale.
	 *
	 * @param array $sale Sale (full, with payments).
	 * @return array
	 */
	public static function state( array $sale ) : array {
		$can     = MJERP_Capabilities::can_manage_sales();
		$blocker = self::blocker( $sale );
		$void    = MJERP_Sales_Repo::STATUS_VOID === (string) $sale['status'];
		$refund  = $void ? 0.0 : self::refundable_on_void( $sale );

		return array(
			'can_manage'         => $can,
			'can_return'         => $can && null === $blocker,
			'reason'             => $blocker ? $blocker->get_error_message() : '',
			'requires_reason'    => MJERP_Helpers::bool( MJERP_Settings::get( 'returns_require_reason', true ) ),
			'default_settlement' => self::default_settlement(),
			'rma_enabled'        => self::rma_enabled(),
			'credit_available'   => self::credit_available( $sale ),
			'refundable_on_void' => $refund,
			'void_split'         => ( $can && $refund > self::EPSILON ) ? self::proposed_split( $sale, $refund, true ) : array(),
		);
	}

	/**
	 * Why a sale does not take a return, or null when it does.
	 *
	 * @param array $sale Sale.
	 * @return WP_Error|null
	 */
	public static function blocker( array $sale ) : ?WP_Error {
		$error = null;

		if ( ! self::enabled() ) {
			$error = new WP_Error( 'mjerp_returns_disabled', __( 'Las devoluciones están apagadas en Configuración → Ventas.', 'mj-erp' ), array( 'status' => 409 ) );
		} elseif ( MJERP_Sales_Repo::STATUS_VOID === (string) $sale['status'] ) {
			$error = new WP_Error( 'mjerp_sale_void', __( 'La venta está anulada.', 'mj-erp' ), array( 'status' => 409 ) );
		} elseif ( (float) $sale['paid_total'] <= self::EPSILON ) {
			$error = new WP_Error( 'mjerp_return_unpaid', __( 'La venta no tiene cobros: para cambiarla usá «Editar productos».', 'mj-erp' ), array( 'status' => 409 ) );
		} elseif ( (float) $sale['consignment_qty'] > self::EPSILON ) {
			$error = new WP_Error( 'mjerp_sale_consignment', __( 'Tiene productos de la consignación de un revendedor: la devolución se registra en su cuenta.', 'mj-erp' ), array( 'status' => 409 ) );
		} elseif ( ! empty( $sale['order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $sale['order_id'] );
			if ( $order instanceof WC_Order && (float) $order->get_total_refunded() > 0 ) {
				$error = new WP_Error( 'mjerp_order_refunded', __( 'El pedido de WooCommerce tiene reembolsos: corregilo desde el pedido.', 'mj-erp' ), array( 'status' => 409 ) );
			}
		}

		/**
		 * Filters why a sale does not take a return.
		 *
		 * @param WP_Error|null $error Error, or null when it does.
		 * @param array         $sale  Sale.
		 */
		$error = apply_filters( 'mjerp_sale_return_blocker', $error, $sale );

		return $error instanceof WP_Error ? $error : null;
	}

	/* ------------------------------------------------------------------
	 * Devolución o cambio
	 * ---------------------------------------------------------------- */

	/**
	 * What a return would do, without storing anything.
	 *
	 * @param int   $sale_id Sale id.
	 * @param array $data    items, new_items, settlement, credit_split, refund.
	 * @return array|WP_Error
	 */
	public static function preview( $sale_id, array $data ) {
		$sale = self::returnable_sale( $sale_id );
		if ( is_wp_error( $sale ) ) {
			return $sale;
		}

		$plan = self::prepare( $sale, $data, false );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		return self::public_plan( $plan );
	}

	/**
	 * Registers a return, an exchange or a warranty replacement.
	 *
	 * @param int   $sale_id Sale id.
	 * @param array $data    items, new_items, settlement, credit_split, refund, reason, return_date.
	 * @return array|WP_Error The stored sale.
	 */
	public static function apply( $sale_id, array $data ) {
		$sale = self::returnable_sale( $sale_id );
		if ( is_wp_error( $sale ) ) {
			return $sale;
		}

		$reason = MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'reason', '' ), 255 );
		if ( '' === $reason && MJERP_Helpers::bool( MJERP_Settings::get( 'returns_require_reason', true ) ) ) {
			return new WP_Error( 'mjerp_reason_required', __( 'Contá el motivo de la devolución: queda en el historial.', 'mj-erp' ), array( 'status' => 400, 'field' => 'reason' ) );
		}

		$plan = self::prepare( $sale, $data, true );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$sale_id = (int) $sale['id'];
		$code    = MJERP_Returns_Repo::next_code();

		// 1. La venta queda con lo que se llevó al final.
		$stored = MJERP_Sales_Repo::replace_items( $sale_id, $plan['lines'], array( 'discount_total' => $plan['discount_after'] ) );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		// 2. El registro de la devolución.
		$record_items = array();
		foreach ( $plan['returned'] as $row ) {
			$record_items[] = array(
				'sale_item_id'   => (int) $row['item']['id'],
				'type'           => MJERP_Returns_Repo::ITEM_RETURNED,
				'replaced'       => $row['replace'],
				'product_id'     => (int) $row['item']['product_id'],
				'variation_id'   => (int) $row['item']['variation_id'],
				'name'           => (string) $row['item']['name'],
				'sku'            => (string) $row['item']['sku'],
				'qty'            => $row['qty'],
				'unit_value'     => $row['unit_value'],
				'line_value'     => $row['value'],
				'unit_cost_base' => (float) $row['item']['unit_cost_base'],
				'destination'    => $row['destination'],
				'problem'        => $row['problem'],
			);
		}
		foreach ( $plan['new_lines'] as $line ) {
			$record_items[] = array(
				'type'           => MJERP_Returns_Repo::ITEM_NEW,
				'product_id'     => (int) $line['product_id'],
				'variation_id'   => (int) $line['variation_id'],
				'name'           => (string) $line['name'],
				'sku'            => (string) $line['sku'],
				'qty'            => (float) $line['qty'],
				'unit_value'     => (float) $line['unit_price'],
				'line_value'     => (float) $line['line_total'],
				'unit_cost_base' => (float) $line['unit_cost_base'],
			);
		}

		$record = MJERP_Returns_Repo::create(
			array(
				'code'              => $code,
				'sale_id'           => $sale_id,
				'customer_id'       => (int) $sale['customer_id'],
				'return_date'       => MJERP_Helpers::array_get( $data, 'return_date', '' ),
				'kind'              => $plan['kind'],
				'currency'          => (string) $sale['currency'],
				'returned_value'    => $plan['returned_value'],
				'new_value'         => $plan['new_value'],
				'total_before'      => $plan['total_before'],
				'total_after'       => $plan['total_after'],
				'paid_before'       => $plan['paid'],
				'excess'            => $plan['excess'],
				'due_after'         => $plan['due_after'],
				'settlement'        => $plan['settlement'],
				'settlement_detail' => array( 'credit_split' => $plan['credit_split'] ),
				'refund_method'     => isset( $plan['refund']['method'] ) ? $plan['refund']['method'] : '',
				'refund_cashbox_id' => isset( $plan['refund']['cashbox_id'] ) ? $plan['refund']['cashbox_id'] : 0,
				'refund_reference'  => isset( $plan['refund']['reference'] ) ? $plan['refund']['reference'] : '',
				'reason'            => $reason,
			),
			$record_items
		);
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$return_id = (int) $record['id'];

		// 3. La plata que sobra sale de la venta.
		self::settle( MJERP_Sales_Repo::find( $sale_id ), $return_id, $code, $plan['excess'], $plan['settlement'], $plan['credit_split'], $plan['refund'] );

		// 4. El stock, si la venta ya lo había sacado.
		/* translators: %s: return code */
		$note = sprintf( __( 'Devolución %s', 'mj-erp' ), $code );
		foreach ( $plan['moves'] as $move ) {
			self::move_stock( (int) $move['product_id'], (int) $move['variation_id'], (float) $move['qty'], (string) $move['type'], 'sale', $sale_id, $note );
		}

		// 5. El pedido de WooCommerce dice lo mismo que la venta (sin volver a mover stock).
		if ( 'order' === $plan['stock_mode'] && MJERP_Helpers::bool( MJERP_Settings::get( 'returns_update_order', true ) ) ) {
			/* translators: 1: return code, 2: reason */
			$order_note = sprintf( __( '%1$s registrada desde MJ ERP. %2$s', 'mj-erp' ), $code, $reason );
			$synced     = MJERP_Orders::sync_sale_items( $sale_id, $reason, false, trim( $order_note ) );
			if ( is_wp_error( $synced ) ) {
				MJ_Log::error(
					'erp',
					'Se registró una devolución pero no se pudo actualizar el pedido de WooCommerce',
					array(
						'venta'  => $sale_id,
						'motivo' => $synced->get_error_message(),
					)
				);
			}
		}

		// 6. Lo fallado queda en RMA, esperando el reclamo al proveedor.
		foreach ( $plan['returned'] as $index => $row ) {
			if ( MJERP_Returns_Repo::DEST_RMA !== $row['destination'] ) {
				continue;
			}
			$supplier = self::supplier_for( (int) $row['item']['product_id'], (int) $row['item']['variation_id'] );
			$rma_id   = MJERP_RMA_Repo::create(
				array(
					'sale_id'        => $sale_id,
					'return_id'      => $return_id,
					'return_item_id' => isset( $record['item_ids'][ $index ] ) ? $record['item_ids'][ $index ] : 0,
					'customer_id'    => (int) $sale['customer_id'],
					'product_id'     => (int) $row['item']['product_id'],
					'variation_id'   => (int) $row['item']['variation_id'],
					'name'           => (string) $row['item']['name'],
					'sku'            => (string) $row['item']['sku'],
					'qty'            => $row['qty'],
					'unit_cost_base' => (float) $row['item']['unit_cost_base'],
					'supplier_id'    => $supplier['supplier_id'],
					'supplier_name'  => $supplier['supplier_name'],
					'problem'        => $row['problem'],
				)
			);
			if ( ! is_wp_error( $rma_id ) && isset( $record['item_ids'][ $index ] ) ) {
				MJERP_Returns_Repo::set_item_rma( $record['item_ids'][ $index ], $rma_id );
			}
		}

		// 7. Lo que se lleva a cambio todavía no se lo llevó.
		if ( $plan['redeliver'] ) {
			MJERP_Sales_Repo::set_delivery( $sale_id, array( 'status' => MJERP_Sales_Repo::DELIVERY_PENDING ) );
		}

		// 8. El historial.
		MJERP_Sale_Log::add(
			array(
				'sale_id' => $sale_id,
				'action'  => MJERP_Sale_Log::ACTION_RETURN,
				'summary' => self::summary( $code, $plan, (string) $sale['currency'] ),
				'reason'  => $reason,
				'detail'  => array( 'changes' => self::changes( $plan, (string) $sale['currency'] ) ),
			)
		);

		/**
		 * Fires after a return, exchange or warranty replacement was registered.
		 *
		 * @param int   $return_id Return id.
		 * @param int   $sale_id   Sale id.
		 * @param array $plan      What was done.
		 */
		do_action( 'mjerp_sale_returned', $return_id, $sale_id, $plan );

		return MJERP_Sales_Repo::get_full( $sale_id );
	}

	/* ------------------------------------------------------------------
	 * Anular una venta cobrada
	 * ---------------------------------------------------------------- */

	/**
	 * Voids a sale and decides what happens with the money that was collected.
	 *
	 * Lo que se pagó con saldo a favor vuelve solo a ese saldo (MJERP_Credit::on_sale_voided). El
	 * resto se deja a favor, se devuelve de una caja, o queda como estaba («ninguno»).
	 *
	 * @param int    $sale_id Sale id.
	 * @param string $reason  Reason.
	 * @param array  $data    settlement, credit_split, refund.
	 * @return true|WP_Error
	 */
	public static function void_sale( $sale_id, string $reason, array $data ) {
		$sale = MJERP_Sales_Repo::get_full( $sale_id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$settlement = sanitize_key( (string) MJERP_Helpers::array_get( $data, 'settlement', MJERP_Returns_Repo::SETTLE_NONE ) );
		$amount     = MJERP_Sales_Repo::STATUS_VOID === (string) $sale['status'] ? 0.0 : self::refundable_on_void( $sale );
		$split      = array();
		$refund     = array();

		if ( $amount > self::EPSILON && MJERP_Returns_Repo::SETTLE_NONE !== $settlement ) {
			if ( ! MJERP_Capabilities::can_manage_sales() ) {
				return new WP_Error( 'mjerp_forbidden', __( 'Devolver la plata de una venta anulada lo hace solo quien puede corregir ventas.', 'mj-erp' ), array( 'status' => 403 ) );
			}
			$checked = self::check_settlement( $sale, $amount, $settlement, $data, true );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			$split  = $checked['credit_split'];
			$refund = $checked['refund'];
		}

		$voided = MJERP_Sales::void_sale( (int) $sale['id'], $reason );
		if ( is_wp_error( $voided ) ) {
			return $voided;
		}

		if ( $amount <= self::EPSILON || MJERP_Returns_Repo::SETTLE_NONE === $settlement ) {
			return true;
		}

		$code   = MJERP_Returns_Repo::next_code();
		$record = MJERP_Returns_Repo::create(
			array(
				'code'              => $code,
				'sale_id'           => (int) $sale['id'],
				'customer_id'       => (int) $sale['customer_id'],
				'kind'              => MJERP_Returns_Repo::KIND_VOID,
				'currency'          => (string) $sale['currency'],
				'returned_value'    => $amount,
				'total_before'      => (float) $sale['total'],
				'total_after'       => 0,
				'paid_before'       => (float) $sale['paid_total'],
				'excess'            => $amount,
				'settlement'        => $settlement,
				'settlement_detail' => array( 'credit_split' => $split ),
				'refund_method'     => isset( $refund['method'] ) ? $refund['method'] : '',
				'refund_cashbox_id' => isset( $refund['cashbox_id'] ) ? $refund['cashbox_id'] : 0,
				'refund_reference'  => isset( $refund['reference'] ) ? $refund['reference'] : '',
				'reason'            => $reason,
			),
			array()
		);
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		self::settle( MJERP_Sales_Repo::find( (int) $sale['id'] ), (int) $record['id'], $code, $amount, $settlement, $split, $refund );

		MJERP_Sale_Log::add(
			array(
				'sale_id' => (int) $sale['id'],
				'action'  => MJERP_Sale_Log::ACTION_RETURN,
				'summary' => MJERP_Returns_Repo::SETTLE_CREDIT === $settlement
					/* translators: 1: code, 2: amount */
					? sprintf( __( '%1$s: lo cobrado queda a favor del cliente (%2$s).', 'mj-erp' ), $code, self::split_text( $split ) )
					/* translators: 1: code, 2: amount, 3: payment method */
					: sprintf( __( '%1$s: se le devolvió la plata, %2$s (%3$s).', 'mj-erp' ), $code, MJERP_Sale_Log::money( $amount, $sale['currency'] ), MJERP_Settings::sale_payment_method_label( $refund['method'] ) ),
				'reason'  => $reason,
			)
		);

		return true;
	}

	/**
	 * Money collected that would still be in the sale after voiding it (what was paid with credit
	 * goes back to the credit on its own).
	 *
	 * @param array $sale Sale (with payments when available).
	 * @return float
	 */
	public static function refundable_on_void( array $sale ) : float {
		$payments = isset( $sale['payments'] ) && is_array( $sale['payments'] ) ? $sale['payments'] : MJERP_Sale_Payments_Repo::for_sale( (int) $sale['id'] );

		$credit = 0.0;
		foreach ( $payments as $payment ) {
			if ( empty( $payment['is_declared'] ) && (float) $payment['amount'] > 0 && MJERP_Credit::is_method( $payment['method'] ) ) {
				$credit += (float) $payment['amount'];
			}
		}

		return round( max( 0, (float) $sale['paid_total'] - $credit ), 4 );
	}

	/* ------------------------------------------------------------------
	 * RMA
	 * ---------------------------------------------------------------- */

	/**
	 * Closes an RMA: back to the stock, or written off (a warranty loss).
	 *
	 * @param int    $id     RMA id.
	 * @param string $status en_stock | baja.
	 * @param string $note   Note.
	 * @return array|WP_Error The RMA.
	 */
	public static function resolve_rma( $id, string $status, string $note = '' ) {
		$rma = MJERP_RMA_Repo::find( $id );
		if ( ! $rma ) {
			return new WP_Error( 'mjerp_not_found', __( 'El RMA no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( MJERP_RMA_Repo::STATUS_OPEN !== $rma['status'] ) {
			return new WP_Error( 'mjerp_rma_closed', __( 'Ese RMA ya está resuelto.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		$status = sanitize_key( $status );
		if ( ! in_array( $status, array( MJERP_RMA_Repo::STATUS_RESTOCKED, MJERP_RMA_Repo::STATUS_WRITTEN_OFF ), true ) ) {
			return new WP_Error( 'mjerp_invalid_status', __( 'Elegí si volvió al stock o se da de baja.', 'mj-erp' ), array( 'status' => 400, 'field' => 'status' ) );
		}

		if ( MJERP_RMA_Repo::STATUS_RESTOCKED === $status ) {
			self::move_stock(
				(int) $rma['product_id'],
				(int) $rma['variation_id'],
				(float) $rma['qty'],
				self::MOVE_RESTOCK,
				'rma',
				(int) $rma['id'],
				/* translators: %s: RMA code */
				sprintf( __( '%s volvió al stock', 'mj-erp' ), $rma['code'] )
			);
		}

		$result = MJERP_RMA_Repo::mark_resolved( (int) $rma['id'], $status, $note );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $rma['sale_id'] ) {
			MJERP_Sale_Log::add(
				array(
					'sale_id' => (int) $rma['sale_id'],
					'action'  => MJERP_Sale_Log::ACTION_RETURN,
					'summary' => MJERP_RMA_Repo::STATUS_RESTOCKED === $status
						/* translators: 1: RMA code, 2: quantity, 3: product */
						? sprintf( __( '%1$s: %2$s × %3$s volvió al stock.', 'mj-erp' ), $rma['code'], MJERP_Helpers::trim_number( $rma['qty'] ), $rma['name'] )
						/* translators: 1: RMA code, 2: quantity, 3: product, 4: cost */
						: sprintf( __( '%1$s: %2$s × %3$s se dio de baja (pérdida por garantía de %4$s).', 'mj-erp' ), $rma['code'], MJERP_Helpers::trim_number( $rma['qty'] ), $rma['name'], MJERP_Helpers::money_base( $rma['cost_base'] ) ),
					'reason'  => $note,
				)
			);
		}

		/**
		 * Fires after an RMA was closed.
		 *
		 * @param int    $id     RMA id.
		 * @param string $status en_stock | baja.
		 */
		do_action( 'mjerp_rma_resolved', (int) $rma['id'], $status );

		return MJERP_RMA_Repo::find( (int) $rma['id'] );
	}

	/* ------------------------------------------------------------------
	 * El plan
	 * ---------------------------------------------------------------- */

	/**
	 * The sale, when it exists, the user may correct sales and it takes a return.
	 *
	 * @param int $sale_id Sale id.
	 * @return array|WP_Error
	 */
	private static function returnable_sale( $sale_id ) {
		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( ! MJERP_Capabilities::can_manage_sales() ) {
			return new WP_Error( 'mjerp_forbidden', __( 'Las devoluciones las registra solo quien puede corregir ventas.', 'mj-erp' ), array( 'status' => 403 ) );
		}
		$blocker = self::blocker( $sale );
		return $blocker ? $blocker : $sale;
	}

	/**
	 * Works out a return from the request.
	 *
	 * @param array $sale   Sale.
	 * @param array $data   Request.
	 * @param bool  $strict Whether the settlement has to be complete (on save).
	 * @return array|WP_Error
	 */
	private static function prepare( array $sale, array $data, bool $strict ) {
		$sale_id  = (int) $sale['id'];
		$items    = MJERP_Sales_Repo::items( $sale_id );
		$currency = (string) $sale['currency'];
		$by_id    = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item['id'] ] = $item;
		}

		$subtotal = (float) $sale['subtotal'];
		$discount = (float) $sale['discount_total'];
		// Lo que pagó por cada peso de lista: el descuento de la venta no es plata a favor.
		$factor = $subtotal > self::EPSILON ? max( 0, ( $subtotal - $discount ) / $subtotal ) : 1.0;

		$stock = self::stock_mode( $sale );
		$rma   = self::rma_enabled();

		/* --- Lo que vuelve --- */

		$returned       = array();
		$seen           = array();
		$qty_out        = array();
		$returned_value = 0.0;

		foreach ( (array) MJERP_Helpers::array_get( $data, 'items', array() ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$item_id = absint( MJERP_Helpers::array_get( $raw, 'sale_item_id', 0 ) );
			$qty     = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $raw, 'qty', 0 ) ), 4 );
			if ( $qty <= 0 ) {
				continue;
			}
			if ( ! isset( $by_id[ $item_id ] ) ) {
				return new WP_Error( 'mjerp_invalid_item', __( 'Uno de los productos no es de esta venta.', 'mj-erp' ), array( 'status' => 400, 'field' => 'items' ) );
			}

			$item                = $by_id[ $item_id ];
			$seen[ $item_id ]    = ( isset( $seen[ $item_id ] ) ? $seen[ $item_id ] : 0.0 ) + $qty;
			if ( $seen[ $item_id ] > (float) $item['qty'] + self::EPSILON ) {
				return new WP_Error(
					'mjerp_return_qty',
					/* translators: 1: product, 2: quantity sold */
					sprintf( __( 'De %1$s se llevó %2$s: no puede volver más que eso.', 'mj-erp' ), $item['name'], MJERP_Helpers::trim_number( $item['qty'] ) ),
					array( 'status' => 400, 'field' => 'items' )
				);
			}

			$replace     = MJERP_Helpers::bool( MJERP_Helpers::array_get( $raw, 'replace', false ) );
			$destination = sanitize_key( (string) MJERP_Helpers::array_get( $raw, 'destination', MJERP_Returns_Repo::DEST_STOCK ) );
			if ( MJERP_Returns_Repo::DEST_RMA !== $destination || ! $rma ) {
				$destination = MJERP_Returns_Repo::DEST_STOCK;
			}

			$line_unit  = (float) $item['qty'] > 0 ? (float) $item['line_total'] / (float) $item['qty'] : 0.0;
			$unit_value = $replace ? 0.0 : round( $line_unit * $factor, 4 );
			$value      = round( $unit_value * $qty, 4 );

			$returned[] = array(
				'item'        => $item,
				'qty'         => $qty,
				'unit_value'  => $unit_value,
				'value'       => $value,
				'destination' => $destination,
				'replace'     => $replace,
				'problem'     => MJERP_Helpers::text( MJERP_Helpers::array_get( $raw, 'problem', '' ), 255 ),
			);

			if ( ! $replace ) {
				$qty_out[ $item_id ] = ( isset( $qty_out[ $item_id ] ) ? $qty_out[ $item_id ] : 0.0 ) + $qty;
				$returned_value     += $value;
			}
		}

		/* --- Lo que se lleva a cambio --- */

		$new_lines = self::new_lines( $sale, (array) MJERP_Helpers::array_get( $data, 'new_items', array() ) );
		if ( is_wp_error( $new_lines ) ) {
			return $new_lines;
		}
		$new_value = 0.0;
		foreach ( $new_lines as $line ) {
			$new_value += (float) $line['line_total'];
		}

		if ( empty( $returned ) && empty( $new_lines ) ) {
			return new WP_Error( 'mjerp_return_empty', __( 'Marcá qué vuelve o qué se lleva a cambio.', 'mj-erp' ), array( 'status' => 400, 'field' => 'items' ) );
		}

		/* --- La venta al final --- */

		$lines              = array();
		$remaining_subtotal = 0.0;
		foreach ( $items as $item ) {
			$out  = isset( $qty_out[ (int) $item['id'] ] ) ? $qty_out[ (int) $item['id'] ] : 0.0;
			$keep = round( (float) $item['qty'] - $out, 4 );
			if ( $keep <= self::EPSILON ) {
				continue;
			}
			$share              = (float) $item['qty'] > 0 ? $keep / (float) $item['qty'] : 0.0;
			$line               = $item;
			$line['qty']        = $keep;
			$line['discount']   = round( (float) $item['discount'] * $share, 4 );
			$line['line_total'] = round( (float) $item['line_total'] * $share, 4 );
			$remaining_subtotal += (float) $line['line_total'];
			$lines[]            = $line;
		}

		/*
		 * Puede volver todo sin llevarse nada: la venta queda sin productos y la plata sale como en
		 * cualquier devolución, a favor o devuelta. Anular no es lo mismo: devolvería al stock lo que
		 * vino fallado y no abriría su RMA.
		 */

		$discount_after = $subtotal > self::EPSILON ? round( $discount * $remaining_subtotal / $subtotal, 4 ) : 0.0;
		$lines          = array_merge( $lines, $new_lines );
		$total_after    = round( $remaining_subtotal - $discount_after + $new_value + (float) $sale['shipping_total'] + (float) $sale['tax_total'], 4 );
		$paid           = round( (float) $sale['paid_total'], 4 );
		$excess         = round( max( 0, $paid - $total_after ), 4 );
		$due_after      = round( max( 0, $total_after - $paid ), 4 );

		/* --- Qué pasa con la plata que sobra --- */

		$settlement = sanitize_key( (string) MJERP_Helpers::array_get( $data, 'settlement', self::default_settlement() ) );
		$split      = array();
		$refund     = array();
		if ( $excess <= self::EPSILON ) {
			$settlement = MJERP_Returns_Repo::SETTLE_NONE;
		} elseif ( $strict ) {
			$checked = self::check_settlement( $sale, $excess, $settlement, $data, false );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			$split  = $checked['credit_split'];
			$refund = $checked['refund'];
		}

		/* --- El stock --- */

		$moves = array();
		if ( $stock['taken'] ) {
			foreach ( $returned as $row ) {
				if ( MJERP_Returns_Repo::DEST_STOCK === $row['destination'] ) {
					$moves[] = self::move( $row['item'], $row['qty'], self::MOVE_RETURN );
				}
				if ( $row['replace'] ) {
					$moves[] = self::move( $row['item'], -1 * $row['qty'], self::MOVE_REPLACEMENT );
				}
			}
			foreach ( $new_lines as $line ) {
				$moves[] = self::move( $line, -1 * (float) $line['qty'], self::MOVE_EXCHANGE );
			}

			if ( ! MJERP_Helpers::bool( MJERP_Settings::get( 'sales_allow_oversell', false ) ) ) {
				$check = self::stock_check( $moves );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}
		}

		$has_replace = false;
		$has_money   = false;
		foreach ( $returned as $row ) {
			if ( $row['replace'] ) {
				$has_replace = true;
			} else {
				$has_money = true;
			}
		}
		if ( ! empty( $new_lines ) ) {
			$kind = MJERP_Returns_Repo::KIND_EXCHANGE;
		} elseif ( $has_money ) {
			$kind = MJERP_Returns_Repo::KIND_RETURN;
		} else {
			$kind = MJERP_Returns_Repo::KIND_WARRANTY;
		}

		return array(
			'sale'           => $sale,
			'kind'           => $kind,
			'currency'       => $currency,
			'returned'       => $returned,
			'new_lines'      => $new_lines,
			'lines'          => $lines,
			'discount_after' => $discount_after,
			'returned_value' => round( $returned_value, 4 ),
			'new_value'      => round( $new_value, 4 ),
			'total_before'   => round( (float) $sale['total'], 4 ),
			'total_after'    => $total_after,
			'paid'           => $paid,
			'excess'         => $excess,
			'due_after'      => $due_after,
			'settlement'     => $settlement,
			'credit_split'   => $split,
			'refund'         => $refund,
			'proposed_split' => $excess > self::EPSILON ? self::proposed_split( $sale, $excess, false ) : array(),
			'stock_mode'     => $stock['mode'],
			'stock_taken'    => $stock['taken'],
			'moves'          => $moves,
			'redeliver'      => ( ! empty( $new_lines ) || $has_replace )
				&& MJERP_Sales_Repo::DELIVERY_DELIVERED === (string) $sale['delivery_status']
				&& MJERP_Settings::delivery_enabled()
				&& MJERP_Helpers::bool( MJERP_Settings::get( 'sales_edit_redeliver', true ) ),
		);
	}

	/**
	 * What of a plan the screen needs.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	private static function public_plan( array $plan ) : array {
		$returned = array();
		foreach ( $plan['returned'] as $row ) {
			$returned[] = array(
				'sale_item_id' => (int) $row['item']['id'],
				'name'         => (string) $row['item']['name'],
				'qty'          => $row['qty'],
				'unit_value'   => $row['unit_value'],
				'value'        => $row['value'],
				'destination'  => $row['destination'],
				'replace'      => $row['replace'],
			);
		}

		$new_lines = array();
		foreach ( $plan['new_lines'] as $line ) {
			$new_lines[] = array(
				'product_id'   => (int) $line['product_id'],
				'variation_id' => (int) $line['variation_id'],
				'name'         => (string) $line['name'],
				'qty'          => (float) $line['qty'],
				'unit_price'   => (float) $line['unit_price'],
				'line_total'   => (float) $line['line_total'],
			);
		}

		$moves = array();
		foreach ( $plan['moves'] as $move ) {
			$moves[] = array(
				'name' => $move['name'],
				'qty'  => $move['qty'],
				'type' => $move['type'],
			);
		}

		$rma = 0;
		foreach ( $plan['returned'] as $row ) {
			if ( MJERP_Returns_Repo::DEST_RMA === $row['destination'] ) {
				$rma++;
			}
		}

		return array(
			'kind'             => $plan['kind'],
			'kind_label'       => MJERP_Returns_Repo::kind_label( $plan['kind'] ),
			'currency'         => $plan['currency'],
			'returned'         => $returned,
			'new_lines'        => $new_lines,
			'returned_value'   => $plan['returned_value'],
			'new_value'        => $plan['new_value'],
			'total_before'     => $plan['total_before'],
			'total_after'      => $plan['total_after'],
			'paid'             => $plan['paid'],
			'excess'           => $plan['excess'],
			'due_after'        => $plan['due_after'],
			'settlement'       => $plan['settlement'],
			'proposed_split'   => $plan['proposed_split'],
			'credit_available' => self::credit_available( $plan['sale'] ),
			'stock_taken'      => $plan['stock_taken'],
			'moves'            => $moves,
			'rma_count'        => $rma,
			'redeliver'        => $plan['redeliver'],
			'has_order'        => 'order' === $plan['stock_mode'],
			'changes'          => self::changes( $plan, $plan['currency'] ),
		);
	}

	/**
	 * The products taken in exchange, priced today with the sale's dollar.
	 *
	 * @param array $sale Sale.
	 * @param array $raw  Request lines.
	 * @return array|WP_Error
	 */
	private static function new_lines( array $sale, array $raw ) {
		$price_list = '' !== (string) $sale['price_list_key'] ? (string) $sale['price_list_key'] : (string) MJERP_Settings::get( 'sales_price_list', '' );
		$track_cost = MJERP_Helpers::bool( MJERP_Settings::get( 'sales_track_cost', true ) );
		$consignee  = MJERP_Sales::resolve_consignee( (int) $sale['customer_user_id'], (int) $sale['consignee_id'] );
		$used       = array();
		$lines      = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$qty = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $row, 'qty', 0 ) ), 4 );
			if ( $qty <= 0 ) {
				continue;
			}

			$product_id   = absint( MJERP_Helpers::array_get( $row, 'product_id', 0 ) );
			$variation_id = absint( MJERP_Helpers::array_get( $row, 'variation_id', 0 ) );
			$product      = self::product( $product_id, $variation_id );
			if ( ! $product ) {
				return new WP_Error( 'mjerp_invalid_product', __( 'Uno de los productos que se lleva no existe.', 'mj-erp' ), array( 'status' => 400, 'field' => 'new_items' ) );
			}

			if ( $consignee ) {
				foreach ( MJERP_Sales::split_line( $consignee, $product_id, $variation_id, $qty, $used ) as $part ) {
					if ( MJERP_Sales_Repo::ORIGIN_CONSIGNMENT === $part['origin'] ) {
						return new WP_Error(
							'mjerp_edit_consignment',
							/* translators: 1: product, 2: reseller */
							sprintf( __( '%1$s lo tiene %2$s en consignación: eso se carga como una venta nueva.', 'mj-erp' ), $product->get_name(), (string) $consignee['name'] ),
							array( 'status' => 409, 'field' => 'new_items' )
						);
					}
				}
			}

			$unit_price = MJERP_Helpers::array_get( $row, 'unit_price', null );
			if ( null === $unit_price || '' === $unit_price ) {
				$unit_price = MJERP_Sales::suggested_unit_price( $product, $product_id, $variation_id, $price_list, (float) $sale['rate'], true );
			}
			$unit_price = round( max( 0, MJERP_Helpers::to_float( $unit_price ) ), 6 );

			$lines[] = array(
				'id'             => 0,
				'order_item_id'  => 0,
				'product_id'     => $product_id,
				'variation_id'   => $variation_id,
				'name'           => (string) $product->get_name(),
				'sku'            => (string) $product->get_sku(),
				'qty'            => $qty,
				'unit_price'     => $unit_price,
				'discount'       => 0,
				'line_total'     => round( $qty * $unit_price, 4 ),
				'unit_cost_base' => $track_cost ? MJERP_Sales::unit_cost_base( $product_id, $variation_id ) : 0.0,
				'origin'         => MJERP_Sales_Repo::ORIGIN_STOCK,
			);
		}

		return $lines;
	}

	/* ------------------------------------------------------------------
	 * La plata
	 * ---------------------------------------------------------------- */

	/**
	 * Whether the money can be left in favour of this sale's customer.
	 *
	 * @param array $sale Sale.
	 * @return bool
	 */
	private static function credit_available( array $sale ) : bool {
		return MJERP_Credit::enabled() && (int) $sale['customer_id'] > 0;
	}

	/**
	 * Validates what to do with the money and returns it ready to use.
	 *
	 * @param array  $sale           Sale.
	 * @param float  $amount         Money in the sale's currency.
	 * @param string $settlement     saldo | reintegro.
	 * @param array  $data           Request (credit_split, refund).
	 * @param bool   $exclude_credit Leave out what was paid with credit (voiding).
	 * @return array|WP_Error { credit_split, refund }
	 */
	private static function check_settlement( array $sale, float $amount, string $settlement, array $data, bool $exclude_credit ) {
		if ( MJERP_Returns_Repo::SETTLE_CREDIT === $settlement ) {
			if ( ! MJERP_Credit::enabled() ) {
				return new WP_Error( 'mjerp_credit_disabled', __( 'El saldo a favor está apagado: elegí «Le di la plata».', 'mj-erp' ), array( 'status' => 409, 'field' => 'settlement' ) );
			}
			if ( (int) $sale['customer_id'] <= 0 ) {
				return new WP_Error( 'mjerp_credit_no_customer', __( 'La venta no tiene cliente, y el saldo a favor es de un cliente: elegí «Le di la plata» o cargale el cliente a la venta.', 'mj-erp' ), array( 'status' => 409, 'field' => 'settlement' ) );
			}
			$split = self::credit_split( $sale, $amount, MJERP_Helpers::array_get( $data, 'credit_split', null ), $exclude_credit );
			if ( is_wp_error( $split ) ) {
				return $split;
			}
			return array(
				'credit_split' => $split,
				'refund'       => array(),
			);
		}

		if ( MJERP_Returns_Repo::SETTLE_REFUND === $settlement ) {
			$raw    = (array) MJERP_Helpers::array_get( $data, 'refund', array() );
			$method = (string) MJERP_Helpers::array_get( $raw, 'method', '' );
			if ( '' === sanitize_key( $method ) || MJERP_Credit::is_method( $method ) ) {
				return new WP_Error( 'mjerp_invalid_method', __( 'Elegí cómo le diste la plata.', 'mj-erp' ), array( 'status' => 400, 'field' => 'refund_method' ) );
			}
			$method = MJERP_Sale_Payments_Repo::sanitize_method( $method );
			return array(
				'credit_split' => array(),
				'refund'       => array(
					'method'     => $method,
					'cashbox_id' => MJERP_Sale_Payments_Repo::resolve_cashbox( absint( MJERP_Helpers::array_get( $raw, 'cashbox_id', 0 ) ), $method ),
					'reference'  => MJERP_Helpers::text( MJERP_Helpers::array_get( $raw, 'reference', '' ), 190 ),
				),
			);
		}

		return new WP_Error( 'mjerp_invalid_settlement', __( 'Elegí qué pasa con la plata: queda a favor o se la devolviste.', 'mj-erp' ), array( 'status' => 400, 'field' => 'settlement' ) );
	}

	/**
	 * How the money left in favour splits between currencies: in proportion to how the sale was paid,
	 * unless the owner wrote it by hand.
	 *
	 * @param array      $sale           Sale.
	 * @param float      $amount         Money in the sale's currency.
	 * @param mixed      $raw            Split written by hand ([{currency, amount}]).
	 * @param bool       $exclude_credit Leave out what was paid with credit.
	 * @return array|WP_Error
	 */
	private static function credit_split( array $sale, float $amount, $raw, bool $exclude_credit ) {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return self::proposed_split( $sale, $amount, $exclude_credit );
		}

		$allowed = MJERP_Settings::currencies();
		$out     = array();
		$sum     = 0.0;
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$currency = strtoupper( (string) MJERP_Helpers::array_get( $row, 'currency', '' ) );
			$value    = round( max( 0, MJERP_Helpers::to_float( MJERP_Helpers::array_get( $row, 'amount', 0 ) ) ), 2 );
			if ( $value <= 0 ) {
				continue;
			}
			if ( ! in_array( $currency, $allowed, true ) ) {
				return new WP_Error( 'mjerp_invalid_currency', __( 'Una de las monedas del saldo es inválida.', 'mj-erp' ), array( 'status' => 400, 'field' => 'credit_split' ) );
			}
			$out[] = array(
				'currency' => $currency,
				'amount'   => $value,
			);
			$sum  += self::to_sale_currency( $value, $sale, $currency );
		}

		if ( empty( $out ) || abs( $sum - $amount ) > max( 1.0, $amount * 0.005 ) ) {
			return new WP_Error(
				'mjerp_credit_split',
				/* translators: %s: amount */
				sprintf( __( 'Lo que queda a favor tiene que sumar %s (al dólar de la venta).', 'mj-erp' ), MJERP_Sale_Log::money( $amount, $sale['currency'] ) ),
				array( 'status' => 400, 'field' => 'credit_split' )
			);
		}

		return $out;
	}

	/**
	 * The split the screen proposes: in proportion to what was paid in each currency.
	 *
	 * Lo pagado con saldo a favor cuenta en la moneda de ese saldo, así lo que vuelve vuelve adonde
	 * estaba.
	 *
	 * @param array $sale           Sale.
	 * @param float $amount         Money in the sale's currency.
	 * @param bool  $exclude_credit Leave out what was paid with credit.
	 * @return array [ { currency, amount } ]
	 */
	public static function proposed_split( array $sale, float $amount, bool $exclude_credit = false ) : array {
		$sale_currency = strtoupper( (string) $sale['currency'] );
		$payments      = isset( $sale['payments'] ) && is_array( $sale['payments'] ) ? $sale['payments'] : MJERP_Sale_Payments_Repo::for_sale( (int) $sale['id'] );
		$weights       = array();

		foreach ( $payments as $payment ) {
			if ( ! empty( $payment['is_declared'] ) || (float) $payment['amount'] <= 0 ) {
				continue;
			}
			$paid_currency = strtoupper( (string) $payment['currency'] );
			$currency      = $paid_currency;
			if ( MJERP_Credit::is_method( $payment['method'] ) ) {
				if ( $exclude_credit ) {
					continue;
				}
				$uses = MJERP_Customer_Credit_Repo::open_uses( 'payment_id', (int) $payment['id'] );
				if ( ! empty( $uses ) ) {
					$currency = (string) $uses[0]['currency'];
				}
			}
			$value                = $paid_currency === $sale_currency ? (float) $payment['amount'] : self::to_sale_currency( (float) $payment['amount'], $sale, $paid_currency );
			$weights[ $currency ] = ( isset( $weights[ $currency ] ) ? $weights[ $currency ] : 0.0 ) + $value;
		}

		$total = array_sum( $weights );
		if ( $total <= self::EPSILON ) {
			return array(
				array(
					'currency' => $sale_currency,
					'amount'   => round( $amount, 2 ),
				),
			);
		}

		$out = array();
		foreach ( $weights as $currency => $weight ) {
			$out[] = array(
				'currency' => $currency,
				'amount'   => round( self::from_sale_currency( $amount * $weight / $total, $sale, $currency ), 2 ),
			);
		}
		return $out;
	}

	/**
	 * Takes the money out of the sale and leaves it in favour or hands it over.
	 *
	 * @param array|null $sale       Sale (fresh).
	 * @param int        $return_id  Return id.
	 * @param string     $code       Return code.
	 * @param float      $amount     Money in the sale's currency.
	 * @param string     $settlement saldo | reintegro | ninguno.
	 * @param array      $split      Credit split.
	 * @param array      $refund     Refund method, cashbox, reference.
	 * @return void
	 */
	private static function settle( ?array $sale, int $return_id, string $code, float $amount, string $settlement, array $split, array $refund ) : void {
		if ( ! $sale || $amount <= self::EPSILON || MJERP_Returns_Repo::SETTLE_NONE === $settlement ) {
			return;
		}

		if ( MJERP_Returns_Repo::SETTLE_CREDIT === $settlement ) {
			MJERP_Sale_Payments_Repo::create_reversal(
				array(
					'sale_id' => (int) $sale['id'],
					'amount'  => $amount,
					'method'  => MJERP_Credit::method_key(),
					/* translators: %s: return code */
					'note'    => sprintf( __( '%s: queda a favor del cliente.', 'mj-erp' ), $code ),
				)
			);
			foreach ( $split as $row ) {
				MJERP_Credit::credit(
					(int) $sale['customer_id'],
					(string) $row['currency'],
					(float) $row['amount'],
					MJERP_Customer_Credit_Repo::KIND_RETURN,
					array(
						'sale_id'   => (int) $sale['id'],
						'return_id' => $return_id,
						'rate'      => strtoupper( (string) $row['currency'] ) === strtoupper( (string) $sale['currency'] ) ? 0 : (float) $sale['rate'],
						'note'      => $code,
					)
				);
			}
			return;
		}

		MJERP_Sale_Payments_Repo::create_reversal(
			array(
				'sale_id'    => (int) $sale['id'],
				'amount'     => $amount,
				'method'     => isset( $refund['method'] ) ? $refund['method'] : '',
				'cashbox_id' => isset( $refund['cashbox_id'] ) ? $refund['cashbox_id'] : 0,
				'reference'  => isset( $refund['reference'] ) ? $refund['reference'] : '',
				/* translators: %s: return code */
				'note'       => sprintf( __( '%s: se le devolvió la plata.', 'mj-erp' ), $code ),
			)
		);
	}

	/**
	 * An amount in the sale's currency, at the sale's dollar.
	 *
	 * @param float  $amount Amount.
	 * @param array  $sale   Sale.
	 * @param string $from   Currency of the amount.
	 * @return float
	 */
	private static function to_sale_currency( float $amount, array $sale, string $from ) : float {
		$sale_currency = strtoupper( (string) $sale['currency'] );
		$from          = strtoupper( $from );
		if ( $from === $sale_currency ) {
			return round( $amount, 4 );
		}
		$base    = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
		$rate    = (float) $sale['rate'] > 0 ? (float) $sale['rate'] : 1.0;
		$in_base = $from === $base ? $amount : $amount / $rate;
		return round( $sale_currency === $base ? $in_base : $in_base * $rate, 4 );
	}

	/**
	 * An amount of the sale's currency in another one, at the sale's dollar.
	 *
	 * @param float  $amount Amount in the sale's currency.
	 * @param array  $sale   Sale.
	 * @param string $to     Target currency.
	 * @return float
	 */
	private static function from_sale_currency( float $amount, array $sale, string $to ) : float {
		$sale_currency = strtoupper( (string) $sale['currency'] );
		$to            = strtoupper( $to );
		if ( $to === $sale_currency ) {
			return round( $amount, 4 );
		}
		$base    = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
		$rate    = (float) $sale['rate'] > 0 ? (float) $sale['rate'] : 1.0;
		$in_base = $sale_currency === $base ? $amount : $amount / $rate;
		return round( $to === $base ? $in_base : $in_base * $rate, 4 );
	}

	/* ------------------------------------------------------------------
	 * El stock
	 * ---------------------------------------------------------------- */

	/**
	 * Who took the units of a sale out of the stock, and whether they are out.
	 *
	 * @param array $sale Sale.
	 * @return array { mode: order|erp|none, taken }
	 */
	public static function stock_mode( array $sale ) : array {
		if ( ! empty( $sale['order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $sale['order_id'] );
			if ( $order instanceof WC_Order ) {
				return array(
					'mode'  => 'order',
					'taken' => (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() ),
				);
			}
		}
		return array(
			'mode'  => ! empty( $sale['stock_applied'] ) ? 'erp' : 'none',
			'taken' => ! empty( $sale['stock_applied'] ),
		);
	}

	/**
	 * Moves units of a product in or out, and logs the movement.
	 *
	 * @param int    $product_id   Product id.
	 * @param int    $variation_id Variation id.
	 * @param float  $qty          Units (+ entran, − salen).
	 * @param string $type         Movement type.
	 * @param string $ref_type     Reference type.
	 * @param int    $ref_id       Reference id.
	 * @param string $note         Note.
	 * @return void
	 */
	public static function move_stock( int $product_id, int $variation_id, float $qty, string $type, string $ref_type, int $ref_id, string $note ) : void {
		if ( abs( $qty ) <= self::EPSILON || ! function_exists( 'wc_update_product_stock' ) ) {
			return;
		}
		$product = self::product( $product_id, $variation_id );
		if ( ! $product ) {
			return;
		}
		$holder = MJERP_Products::stock_holder( $product );
		if ( ! $holder->managing_stock() ) {
			return;
		}

		$units = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( abs( $qty ) ) : abs( $qty );
		if ( $units <= 0 ) {
			return;
		}

		try {
			$before = MJERP_Helpers::to_float( $holder->get_stock_quantity() );
			$after  = wc_update_product_stock( $product, $units, $qty > 0 ? 'increase' : 'decrease' );
			if ( false === $after || null === $after ) {
				return;
			}
			MJERP_Movements_Repo::insert(
				array(
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'qty'          => $qty > 0 ? (float) $units : -1 * (float) $units,
					'type'         => $type,
					'ref_type'     => $ref_type,
					'ref_id'       => $ref_id,
					'stock_before' => $before,
					'stock_after'  => (float) $after,
					'note'         => $note,
					'user_id'      => get_current_user_id(),
				)
			);
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * A stock move of a line.
	 *
	 * @param array  $line Line.
	 * @param float  $qty  Units (+/−).
	 * @param string $type Movement type.
	 * @return array
	 */
	private static function move( array $line, float $qty, string $type ) : array {
		return array(
			'product_id'   => (int) $line['product_id'],
			'variation_id' => (int) $line['variation_id'],
			'name'         => (string) $line['name'],
			'qty'          => round( $qty, 4 ),
			'type'         => $type,
		);
	}

	/**
	 * Refuses taking out units the shop does not have (net of what comes back of the same product).
	 *
	 * @param array $moves Stock moves.
	 * @return true|WP_Error
	 */
	private static function stock_check( array $moves ) {
		$net = array();
		foreach ( $moves as $move ) {
			$key = $move['product_id'] . ':' . $move['variation_id'];
			if ( ! isset( $net[ $key ] ) ) {
				$net[ $key ] = $move;
				$net[ $key ]['qty'] = 0.0;
			}
			$net[ $key ]['qty'] += (float) $move['qty'];
		}

		foreach ( $net as $row ) {
			if ( $row['qty'] >= -1 * self::EPSILON ) {
				continue;
			}
			$product = self::product( $row['product_id'], $row['variation_id'] );
			if ( ! $product ) {
				continue;
			}
			$holder = MJERP_Products::stock_holder( $product );
			if ( ! $holder->managing_stock() || $holder->backorders_allowed() ) {
				continue;
			}
			$available = MJERP_Helpers::to_float( $holder->get_stock_quantity() );
			if ( $available + self::EPSILON < -1 * $row['qty'] ) {
				return new WP_Error(
					'mjerp_no_stock',
					/* translators: 1: product, 2: available units */
					sprintf( __( 'No hay stock suficiente de %1$s: quedan %2$s unidades.', 'mj-erp' ), $row['name'], MJERP_Helpers::trim_number( $available ) ),
					array( 'status' => 409, 'field' => 'items' )
				);
			}
		}

		return true;
	}

	/**
	 * The supplier a product came from last (the one to claim a faulty unit to).
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id.
	 * @return array { supplier_id, supplier_name }
	 */
	public static function supplier_for( int $product_id, int $variation_id ) : array {
		$out = array(
			'supplier_id'   => 0,
			'supplier_name' => '',
		);

		foreach ( array_filter( array( $variation_id, $product_id ) ) as $target ) {
			$fields      = MJERP_Products::cost_fields( (int) $target );
			$purchase_id = isset( $fields['last_purchase_id'] ) ? (int) $fields['last_purchase_id'] : 0;
			if ( $purchase_id <= 0 ) {
				continue;
			}
			$purchase = MJERP_Purchases_Repo::find( $purchase_id );
			if ( $purchase ) {
				$out['supplier_id']   = (int) $purchase['supplier_id'];
				$out['supplier_name'] = (string) $purchase['supplier_name'];
				break;
			}
		}

		/**
		 * Filters the supplier a faulty unit is claimed to.
		 *
		 * @param array $out          { supplier_id, supplier_name }.
		 * @param int   $product_id   Product id.
		 * @param int   $variation_id Variation id.
		 */
		return (array) apply_filters( 'mjerp_rma_supplier', $out, $product_id, $variation_id );
	}

	/* ------------------------------------------------------------------
	 * En palabras
	 * ---------------------------------------------------------------- */

	/**
	 * One line for the history.
	 *
	 * @param string $code     Return code.
	 * @param array  $plan     Plan.
	 * @param string $currency Sale currency.
	 * @return string
	 */
	private static function summary( string $code, array $plan, string $currency ) : string {
		$label = MJERP_Returns_Repo::kind_label( $plan['kind'] );

		if ( $plan['excess'] > self::EPSILON ) {
			$money = MJERP_Returns_Repo::SETTLE_CREDIT === $plan['settlement']
				/* translators: %s: amounts per currency */
				? sprintf( __( 'queda a favor %s', 'mj-erp' ), self::split_text( $plan['credit_split'] ) )
				/* translators: %s: amount */
				: sprintf( __( 'se le devolvió %s', 'mj-erp' ), MJERP_Sale_Log::money( $plan['excess'], $currency ) );
		} elseif ( $plan['due_after'] > self::EPSILON ) {
			/* translators: %s: amount */
			$money = sprintf( __( 'falta cobrar %s', 'mj-erp' ), MJERP_Sale_Log::money( $plan['due_after'], $currency ) );
		} else {
			$money = __( 'la plata no cambia', 'mj-erp' );
		}

		/* translators: 1: kind, 2: code, 3: what happened with the money */
		return sprintf( __( '%1$s %2$s: %3$s.', 'mj-erp' ), $label, $code, $money );
	}

	/**
	 * What happened, one line each.
	 *
	 * @param array  $plan     Plan.
	 * @param string $currency Sale currency.
	 * @return string[]
	 */
	private static function changes( array $plan, string $currency ) : array {
		$out = array();
		foreach ( $plan['returned'] as $row ) {
			$qty  = MJERP_Helpers::trim_number( $row['qty'] );
			$name = (string) $row['item']['name'];
			if ( $row['replace'] ) {
				$out[] = MJERP_Returns_Repo::DEST_RMA === $row['destination']
					/* translators: 1: quantity, 2: product */
					? sprintf( __( 'Se reemplaza %1$s × %2$s por garantía; lo fallado va a RMA.', 'mj-erp' ), $qty, $name )
					/* translators: 1: quantity, 2: product */
					: sprintf( __( 'Se reemplaza %1$s × %2$s por otro igual; vuelve al stock.', 'mj-erp' ), $qty, $name );
				continue;
			}
			$out[] = MJERP_Returns_Repo::DEST_RMA === $row['destination']
				/* translators: 1: quantity, 2: product, 3: value */
				? sprintf( __( 'Vuelve %1$s × %2$s fallado (%3$s): va a RMA.', 'mj-erp' ), $qty, $name, MJERP_Sale_Log::money( $row['value'], $currency ) )
				/* translators: 1: quantity, 2: product, 3: value */
				: sprintf( __( 'Vuelve %1$s × %2$s (%3$s): al stock.', 'mj-erp' ), $qty, $name, MJERP_Sale_Log::money( $row['value'], $currency ) );
		}
		foreach ( $plan['new_lines'] as $line ) {
			/* translators: 1: quantity, 2: product, 3: price */
			$out[] = sprintf( __( 'Se lleva %1$s × %2$s a %3$s.', 'mj-erp' ), MJERP_Helpers::trim_number( $line['qty'] ), $line['name'], MJERP_Sale_Log::money( $line['unit_price'], $currency ) );
		}
		/* translators: 1: previous total, 2: new total */
		$out[] = sprintf( __( 'Total: %1$s → %2$s.', 'mj-erp' ), MJERP_Sale_Log::money( $plan['total_before'], $currency ), MJERP_Sale_Log::money( $plan['total_after'], $currency ) );
		return $out;
	}

	/**
	 * "US$ 20 y $ 5.000".
	 *
	 * @param array $split Credit split.
	 * @return string
	 */
	private static function split_text( array $split ) : string {
		$parts = array();
		foreach ( $split as $row ) {
			$parts[] = MJERP_Sale_Log::money( $row['amount'], $row['currency'] );
		}
		return implode( ' + ', $parts );
	}

	/**
	 * Loads a product/variation.
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id.
	 * @return WC_Product|false
	 */
	private static function product( $product_id, $variation_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$target = absint( $variation_id ) ? absint( $variation_id ) : absint( $product_id );
		if ( ! $target ) {
			return false;
		}
		$product = wc_get_product( $target );
		return $product instanceof WC_Product ? $product : false;
	}
}
