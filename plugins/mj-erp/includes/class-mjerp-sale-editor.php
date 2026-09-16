<?php
/**
 * Corregir los productos de una venta que todavía no se cobró.
 *
 * Al cliente se le ocurre llevarse tres en vez de dos, o cambiar uno por otro, antes de pagar. Anular
 * y cargar de nuevo pierde el código, la fecha y el pedido que ya le llegó al celular; esto cambia la
 * misma venta y deja todo lo que cuelga de ella en regla:
 *
 *   - **Las líneas** se reescriben. Lo que ya estaba conserva el costo con que se vendió; lo que se
 *     agrega toma el costo de hoy y, si no se escribe un precio, el sugerido con el dólar de la venta.
 *   - **El stock** se mueve solo por la diferencia, y lo mueve quien lo movió la primera vez: el
 *     pedido de WooCommerce si lo hay, el ERP si no. Si la venta todavía no bajó stock, no se toca.
 *   - **El pedido** de WooCommerce se reescribe para que diga lo mismo que la venta.
 *   - **La entrega**: si estaba entregada y suma productos, vuelve a «A entregar» (configurable).
 *   - **El historial** anota qué cambió, quién y por qué.
 *
 * Lo que no se edita, a propósito: una venta con cobros (eso es una devolución, con su plata), una
 * con productos de la consignación de un revendedor (toca su cuenta corriente) y una anulada.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Sale_Editor
 */
class MJERP_Sale_Editor {

	/**
	 * Stock movement type of a correction.
	 */
	const MOVEMENT_TYPE = 'sale_edit';

	/**
	 * Hooks the edit state into every full sale the API returns.
	 *
	 * @return void
	 */
	public static function init() : void {
		add_filter( 'mjerp_sale_full', array( __CLASS__, 'attach_state' ), 10, 1 );
	}

	/**
	 * Whether correcting sales is on (Configuración → Ventas → Corregir ventas).
	 *
	 * @return bool
	 */
	public static function enabled() : bool {
		return MJERP_Helpers::bool( MJERP_Settings::get( 'sales_edit_enabled', true ) );
	}

	/**
	 * Adds `edit` to a full sale, so every screen that repaints a sale knows whether it can be edited.
	 *
	 * @param mixed $sale Sale.
	 * @return mixed
	 */
	public static function attach_state( $sale ) {
		if ( is_array( $sale ) && isset( $sale['id'] ) ) {
			$sale['edit'] = self::state( $sale );
		}
		return $sale;
	}

	/**
	 * What the current user can do with the lines of a sale.
	 *
	 * @param array $sale Sale.
	 * @return array { can_manage, can_edit, reason, requires_reason }
	 */
	public static function state( array $sale ) : array {
		$can_manage = MJERP_Capabilities::can_manage_sales();
		$blocker    = self::blocker( $sale );

		return array(
			'can_manage'      => $can_manage,
			'can_edit'        => $can_manage && null === $blocker,
			'reason'          => null === $blocker ? '' : $blocker->get_error_message(),
			'requires_reason' => MJERP_Helpers::bool( MJERP_Settings::get( 'sales_edit_require_reason', false ) ),
		);
	}

	/**
	 * Why the lines of a sale cannot be edited, or null when they can.
	 *
	 * @param array $sale Sale.
	 * @return WP_Error|null
	 */
	public static function blocker( array $sale ) : ?WP_Error {
		$error = null;

		if ( ! self::enabled() ) {
			$error = new WP_Error( 'mjerp_edit_disabled', __( 'Editar ventas está apagado en Configuración → Ventas → Corregir ventas.', 'mj-erp' ), array( 'status' => 409 ) );
		} elseif ( MJERP_Sales_Repo::STATUS_VOID === (string) $sale['status'] ) {
			$error = new WP_Error( 'mjerp_sale_void', __( 'La venta está anulada: no hay nada que editar.', 'mj-erp' ), array( 'status' => 409 ) );
		} elseif ( (float) $sale['paid_total'] > MJERP_Sales_Repo::EPSILON ) {
			$error = new WP_Error( 'mjerp_sale_paid', __( 'La venta ya tiene cobros: para cambiar lo que se llevó, registrá una devolución o cambio.', 'mj-erp' ), array( 'status' => 409 ) );
		} elseif ( (float) $sale['consignment_qty'] > MJERP_Sales_Repo::EPSILON ) {
			$error = new WP_Error( 'mjerp_sale_consignment', __( 'Tiene productos de la consignación de un revendedor: para cambiarla, anulala y cargala de nuevo.', 'mj-erp' ), array( 'status' => 409 ) );
		} elseif ( ! empty( $sale['order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $sale['order_id'] );
			if ( $order instanceof WC_Order && (float) $order->get_total_refunded() > 0 ) {
				$error = new WP_Error( 'mjerp_order_refunded', __( 'El pedido de WooCommerce tiene reembolsos: corregilo desde el pedido.', 'mj-erp' ), array( 'status' => 409 ) );
			}
		}

		/**
		 * Filters why the lines of a sale cannot be edited.
		 *
		 * @param WP_Error|null $error Error, or null when the sale can be edited.
		 * @param array         $sale  Sale.
		 */
		$error = apply_filters( 'mjerp_sale_edit_blocker', $error, $sale );

		return $error instanceof WP_Error ? $error : null;
	}

	/* ------------------------------------------------------------------
	 * Preview and apply
	 * ---------------------------------------------------------------- */

	/**
	 * What the sale would look like, without storing anything.
	 *
	 * @param int   $sale_id Sale id.
	 * @param array $data    items, discount_total, shipping_total.
	 * @return array|WP_Error
	 */
	public static function preview( $sale_id, array $data ) {
		$sale = self::editable_sale( $sale_id );
		if ( is_wp_error( $sale ) ) {
			return $sale;
		}

		$plan = self::prepare( $sale, $data );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		return array(
			'currency'  => (string) $sale['currency'],
			'lines'     => $plan['lines'],
			'totals'    => $plan['totals'],
			'changes'   => $plan['changes'],
			'stock'     => $plan['stock'],
			'redeliver' => $plan['redeliver'],
			'order'     => $sale['order_id'] ? (string) ( '' !== (string) $sale['order_number'] ? $sale['order_number'] : $sale['order_id'] ) : '',
		);
	}

	/**
	 * Rewrites the lines of a sale and everything that hangs from them.
	 *
	 * @param int   $sale_id Sale id.
	 * @param array $data    items, discount_total, shipping_total, reason.
	 * @return array|WP_Error The stored sale.
	 */
	public static function apply( $sale_id, array $data ) {
		$sale = self::editable_sale( $sale_id );
		if ( is_wp_error( $sale ) ) {
			return $sale;
		}

		$reason = MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'reason', '' ), 255 );
		if ( '' === $reason && MJERP_Helpers::bool( MJERP_Settings::get( 'sales_edit_require_reason', false ) ) ) {
			return new WP_Error( 'mjerp_reason_required', __( 'Contá por qué se corrige la venta: queda en el historial.', 'mj-erp' ), array( 'status' => 400, 'field' => 'reason' ) );
		}

		$plan = self::prepare( $sale, $data );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( empty( $plan['changes'] ) ) {
			return new WP_Error( 'mjerp_no_changes', __( 'No cambiaste nada de la venta.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		$sale_id   = (int) $sale['id'];
		$old_items = MJERP_Sales_Repo::items( $sale_id );
		// Quién movió el stock se decide antes de tocar nada: después de reescribir las líneas ya no se sabe.
		$mode = $plan['stock']['mode'];

		$stored = MJERP_Sales_Repo::replace_items( $sale_id, $plan['lines'], $plan['amounts'] );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		if ( 'order' === $mode ) {
			$synced = MJERP_Orders::sync_sale_items( $sale_id, $reason );
			if ( is_wp_error( $synced ) ) {
				MJ_Log::error(
					'erp',
					'Se corrigió una venta pero no se pudo actualizar su pedido de WooCommerce',
					array(
						'venta'  => $sale_id,
						'motivo' => $synced->get_error_message(),
					)
				);
			}
		} elseif ( 'erp' === $mode ) {
			self::adjust_stock( $sale, $plan['stock']['rows'] );
		}

		// Lo que se agregó queda por entregar (si así está configurado); lo entregado sigue entregado.
		MJERP_Sales_Repo::settle_delivery( $sale_id, (bool) $plan['redeliver'] );

		$after     = MJERP_Sales_Repo::find( $sale_id );
		$new_items = MJERP_Sales_Repo::items( $sale_id );
		$total_was = (float) $sale['total'];
		$total_now = $after ? (float) $after['total'] : $total_was;

		MJERP_Sale_Log::add(
			array(
				'sale_id' => $sale_id,
				'action'  => MJERP_Sale_Log::ACTION_ITEMS,
				'summary' => abs( $total_now - $total_was ) > 0.004
					/* translators: 1: previous total, 2: new total */
					? sprintf( __( 'Se corrigieron los productos: el total pasó de %1$s a %2$s.', 'mj-erp' ), MJERP_Sale_Log::money( $total_was, $sale['currency'] ), MJERP_Sale_Log::money( $total_now, $sale['currency'] ) )
					: __( 'Se corrigieron los productos de la venta.', 'mj-erp' ),
				'reason'  => $reason,
				'detail'  => array(
					'changes' => $plan['changes'],
					'before'  => MJERP_Sale_Log::items_snapshot( $old_items ),
					'after'   => MJERP_Sale_Log::items_snapshot( $new_items ),
				),
			)
		);

		/**
		 * Fires after the lines of a sale were corrected.
		 *
		 * @param int    $sale_id   Sale id.
		 * @param array  $old_items Lines before the correction.
		 * @param array  $new_items Lines after the correction.
		 * @param string $reason    Reason.
		 */
		do_action( 'mjerp_sale_items_edited', $sale_id, $old_items, $new_items, $reason );

		return MJERP_Sales_Repo::get_full( $sale_id );
	}

	/**
	 * The sale, when it exists, the user may correct it and nothing blocks it.
	 *
	 * @param int $sale_id Sale id.
	 * @return array|WP_Error
	 */
	private static function editable_sale( $sale_id ) {
		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( ! MJERP_Capabilities::can_manage_sales() ) {
			return new WP_Error( 'mjerp_forbidden', __( 'No tenés permiso para corregir ventas.', 'mj-erp' ), array( 'status' => 403 ) );
		}
		$blocker = self::blocker( $sale );
		return $blocker ? $blocker : $sale;
	}

	/* ------------------------------------------------------------------
	 * The plan
	 * ---------------------------------------------------------------- */

	/**
	 * Works out the new lines, totals, stock moves and the list of changes.
	 *
	 * @param array $sale Sale.
	 * @param array $data items, discount_total, shipping_total.
	 * @return array|WP_Error { lines, amounts, totals, changes, stock, redeliver }
	 */
	private static function prepare( array $sale, array $data ) {
		$old_items = MJERP_Sales_Repo::items( (int) $sale['id'] );
		$by_id     = array();
		foreach ( $old_items as $item ) {
			$by_id[ (int) $item['id'] ] = $item;
		}

		$allow_discount = MJERP_Helpers::bool( MJERP_Settings::get( 'sales_allow_discount', true ) );
		$track_cost     = MJERP_Helpers::bool( MJERP_Settings::get( 'sales_track_cost', true ) );
		$rate           = (float) $sale['rate'];
		$price_list     = '' !== (string) $sale['price_list_key'] ? (string) $sale['price_list_key'] : (string) MJERP_Settings::get( 'sales_price_list', '' );
		$consignee      = MJERP_Sales::resolve_consignee( (int) $sale['customer_user_id'], (int) $sale['consignee_id'] );
		$used           = array();

		$raw_items = isset( $data['items'] ) && is_array( $data['items'] ) ? array_values( $data['items'] ) : array();
		$lines     = array();

		foreach ( $raw_items as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$qty = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $raw, 'qty', 0 ) ), 4 );
			if ( $qty <= 0 ) {
				continue;
			}

			$item_id = absint( MJERP_Helpers::array_get( $raw, 'id', 0 ) );
			$old     = $item_id && isset( $by_id[ $item_id ] ) ? $by_id[ $item_id ] : null;

			$product_id   = $old ? (int) $old['product_id'] : absint( MJERP_Helpers::array_get( $raw, 'product_id', 0 ) );
			$variation_id = $old ? (int) $old['variation_id'] : absint( MJERP_Helpers::array_get( $raw, 'variation_id', 0 ) );
			$product      = self::product( $product_id, $variation_id );

			if ( ! $old && ! $product ) {
				return new WP_Error( 'mjerp_invalid_product', __( 'Uno de los productos que agregaste no existe.', 'mj-erp' ), array( 'status' => 400, 'field' => 'items' ) );
			}

			$name = $old ? (string) $old['name'] : (string) $product->get_name();

			// Lo que un revendedor tiene en consignación no se agrega editando: va a su cuenta corriente.
			if ( ! $old && $consignee ) {
				foreach ( MJERP_Sales::split_line( $consignee, $product_id, $variation_id, $qty, $used ) as $part ) {
					if ( MJERP_Sales_Repo::ORIGIN_CONSIGNMENT === $part['origin'] ) {
						return new WP_Error(
							'mjerp_edit_consignment',
							sprintf(
								/* translators: 1: product, 2: reseller */
								__( '%1$s lo tiene %2$s en consignación: eso no se agrega editando. Cargalo como una venta nueva.', 'mj-erp' ),
								$name,
								(string) $consignee['name']
							),
							array( 'status' => 409, 'field' => 'items' )
						);
					}
				}
			}

			$unit_price = MJERP_Helpers::array_get( $raw, 'unit_price', null );
			if ( null === $unit_price || '' === $unit_price ) {
				$unit_price = $old
					? (float) $old['unit_price']
					: MJERP_Sales::suggested_unit_price( $product, $product_id, $variation_id, $price_list, $rate, true );
			}
			$unit_price = round( MJERP_Helpers::to_float( $unit_price ), 6 );
			if ( $unit_price < 0 ) {
				return new WP_Error( 'mjerp_invalid_price', __( 'El precio no puede ser negativo.', 'mj-erp' ), array( 'status' => 400, 'field' => 'unit_price' ) );
			}

			if ( $allow_discount && array_key_exists( 'discount', $raw ) ) {
				$discount = round( max( 0, MJERP_Helpers::to_float( $raw['discount'] ) ), 4 );
			} else {
				$discount = $old ? (float) $old['discount'] : 0.0;
			}
			$gross    = round( $qty * $unit_price, 4 );
			$discount = min( $discount, $gross );

			if ( $old ) {
				$unit_cost = (float) $old['unit_cost_base'];
			} else {
				$unit_cost = $track_cost ? MJERP_Sales::unit_cost_base( $product_id, $variation_id ) : 0.0;
			}

			$lines[] = array(
				'id'             => $old ? (int) $old['id'] : 0,
				'order_item_id'  => $old ? (int) $old['order_item_id'] : 0,
				'product_id'     => $product_id,
				'variation_id'   => $variation_id,
				'name'           => $name,
				'sku'            => $old ? (string) $old['sku'] : ( $product instanceof WC_Product ? (string) $product->get_sku() : '' ),
				'qty'            => $qty,
				'unit_price'     => $unit_price,
				'discount'       => $discount,
				'line_total'     => round( $gross - $discount, 4 ),
				'unit_cost_base' => round( $unit_cost, 6 ),
				'origin'         => MJERP_Sales_Repo::ORIGIN_STOCK,
				'is_new'         => ! $old,
			);
		}

		if ( empty( $lines ) ) {
			return new WP_Error( 'mjerp_no_items', __( 'La venta tiene que quedar con al menos un producto. Si no se lleva nada, anulala.', 'mj-erp' ), array( 'status' => 400, 'field' => 'items' ) );
		}

		$subtotal  = 0.0;
		$cost_base = 0.0;
		foreach ( $lines as $line ) {
			$subtotal  += (float) $line['line_total'];
			$cost_base += round( (float) $line['unit_cost_base'] * (float) $line['qty'], 4 );
		}

		$amounts = array(
			'discount_total' => ( $allow_discount && array_key_exists( 'discount_total', $data ) )
				? round( max( 0, MJERP_Helpers::to_float( $data['discount_total'] ) ), 4 )
				: (float) $sale['discount_total'],
			'shipping_total' => array_key_exists( 'shipping_total', $data )
				? round( max( 0, MJERP_Helpers::to_float( $data['shipping_total'] ) ), 4 )
				: (float) $sale['shipping_total'],
		);

		if ( $amounts['discount_total'] > round( $subtotal + $amounts['shipping_total'], 4 ) + MJERP_Sales_Repo::EPSILON ) {
			return new WP_Error( 'mjerp_invalid_discount', __( 'El descuento no puede ser mayor que lo que suma la venta.', 'mj-erp' ), array( 'status' => 400, 'field' => 'discount_total' ) );
		}

		$total      = round( $subtotal - $amounts['discount_total'] + $amounts['shipping_total'] + (float) $sale['tax_total'], 4 );
		$total_base = $rate > 0 ? round( $total / $rate, 4 ) : $total;

		$stock = self::stock_plan( $sale, $old_items, $lines );
		if ( is_wp_error( $stock ) ) {
			return $stock;
		}

		$grows = false;
		foreach ( $stock['rows'] as $row ) {
			if ( $row['diff'] > 0.0001 ) {
				$grows = true;
				break;
			}
		}

		return array(
			'lines'     => $lines,
			'amounts'   => $amounts,
			'totals'    => array(
				'subtotal'       => round( $subtotal, 4 ),
				'discount_total' => $amounts['discount_total'],
				'shipping_total' => $amounts['shipping_total'],
				'total'          => $total,
				'total_before'   => round( (float) $sale['total'], 4 ),
				'total_base'     => $total_base,
				'cost_base'      => round( $cost_base, 4 ),
				'profit_base'    => round( $total_base - $cost_base, 4 ),
			),
			'changes'   => self::changes( $sale, $old_items, $lines, $amounts ),
			'stock'     => $stock,
			'redeliver' => $grows
				&& MJERP_Sales_Repo::DELIVERY_DELIVERED === (string) $sale['delivery_status']
				&& MJERP_Settings::delivery_enabled()
				&& MJERP_Helpers::bool( MJERP_Settings::get( 'sales_edit_redeliver', true ) ),
		);
	}

	/**
	 * How much of each product leaves or comes back, and whether that can be done.
	 *
	 * @param array $sale      Sale.
	 * @param array $old_items Lines before.
	 * @param array $lines     Lines after.
	 * @return array|WP_Error { mode: order|erp|none, taken: bool, rows: [ {product_id, variation_id, name, before, after, diff} ] }
	 */
	private static function stock_plan( array $sale, array $old_items, array $lines ) {
		$mode  = 'none';
		$taken = false;

		if ( ! empty( $sale['order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $sale['order_id'] );
			if ( $order instanceof WC_Order ) {
				$mode  = 'order';
				$taken = (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() );
			}
		}
		if ( 'none' === $mode && ! empty( $sale['stock_applied'] ) ) {
			$mode  = 'erp';
			$taken = true;
		}

		$before = self::group( $old_items );
		$after  = self::group( $lines );
		$rows   = array();

		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			$row    = isset( $after[ $key ] ) ? $after[ $key ] : $before[ $key ];
			$qty_b  = isset( $before[ $key ] ) ? $before[ $key ]['qty'] : 0.0;
			$qty_a  = isset( $after[ $key ] ) ? $after[ $key ]['qty'] : 0.0;
			$rows[] = array(
				'product_id'   => $row['product_id'],
				'variation_id' => $row['variation_id'],
				'name'         => $row['name'],
				'before'       => round( $qty_b, 4 ),
				'after'        => round( $qty_a, 4 ),
				'diff'         => round( $qty_a - $qty_b, 4 ),
			);
		}

		if ( ! MJERP_Helpers::bool( MJERP_Settings::get( 'sales_allow_oversell', false ) ) ) {
			foreach ( $rows as $row ) {
				if ( $row['diff'] <= 0.0001 ) {
					continue;
				}
				// Si la venta ya sacó sus unidades, falta solo la diferencia; si no, todas.
				$needed = $taken ? $row['diff'] : $row['after'];
				$check  = self::stock_check( $row, $needed );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}
		}

		return array(
			'mode'  => $mode,
			'taken' => $taken,
			'rows'  => $rows,
		);
	}

	/**
	 * Refuses a quantity the shop does not have.
	 *
	 * @param array $row    Stock row.
	 * @param float $needed Units that have to leave.
	 * @return true|WP_Error
	 */
	private static function stock_check( array $row, float $needed ) {
		$product = self::product( $row['product_id'], $row['variation_id'] );
		if ( ! $product ) {
			return true;
		}
		$holder = MJERP_Products::stock_holder( $product );
		if ( ! $holder->managing_stock() || $holder->backorders_allowed() ) {
			return true;
		}

		$available = MJERP_Helpers::to_float( $holder->get_stock_quantity() );
		if ( $available + 0.0001 >= $needed ) {
			return true;
		}

		return new WP_Error(
			'mjerp_no_stock',
			sprintf(
				/* translators: 1: product name, 2: available units */
				__( 'No hay stock suficiente de %1$s: quedan %2$s unidades.', 'mj-erp' ),
				$row['name'],
				MJERP_Helpers::trim_number( $available )
			),
			array(
				'status'     => 409,
				'field'      => 'items',
				'product_id' => (int) $product->get_id(),
				'available'  => $available,
			)
		);
	}

	/**
	 * Moves the stock of a sale without an order by the difference of each product.
	 *
	 * @param array $sale Sale (before the correction).
	 * @param array $rows Stock rows.
	 * @return void
	 */
	private static function adjust_stock( array $sale, array $rows ) : void {
		if ( ! function_exists( 'wc_update_product_stock' ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( abs( $row['diff'] ) <= 0.0001 ) {
				continue;
			}
			$product = self::product( $row['product_id'], $row['variation_id'] );
			if ( ! $product ) {
				continue;
			}
			$holder = MJERP_Products::stock_holder( $product );
			if ( ! $holder->managing_stock() ) {
				continue;
			}

			$qty = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( abs( $row['diff'] ) ) : abs( $row['diff'] );
			if ( $qty <= 0 ) {
				continue;
			}

			try {
				$before = MJERP_Helpers::to_float( $holder->get_stock_quantity() );
				$after  = wc_update_product_stock( $product, $qty, $row['diff'] > 0 ? 'decrease' : 'increase' );
				if ( false === $after || null === $after ) {
					continue;
				}

				MJERP_Movements_Repo::insert(
					array(
						'product_id'   => (int) $row['product_id'],
						'variation_id' => (int) $row['variation_id'],
						'qty'          => $row['diff'] > 0 ? -1 * (float) $qty : (float) $qty,
						'type'         => self::MOVEMENT_TYPE,
						'ref_type'     => 'sale',
						'ref_id'       => (int) $sale['id'],
						'stock_before' => $before,
						'stock_after'  => (float) $after,
						'note'         => sprintf(
							/* translators: %s: sale code */
							__( 'Corrección de la venta %s', 'mj-erp' ),
							$sale['code']
						),
						'user_id'      => get_current_user_id(),
					)
				);
			} catch ( Throwable $e ) {
				continue;
			}
		}
	}

	/**
	 * What changed, one line each, in words.
	 *
	 * @param array $sale      Sale.
	 * @param array $old_items Lines before.
	 * @param array $lines     Lines after.
	 * @param array $amounts   discount_total, shipping_total.
	 * @return string[]
	 */
	private static function changes( array $sale, array $old_items, array $lines, array $amounts ) : array {
		$currency = (string) $sale['currency'];
		$before   = self::group( $old_items );
		$after    = self::group( $lines );
		$changes  = array();

		foreach ( $after as $key => $row ) {
			if ( ! isset( $before[ $key ] ) ) {
				$changes[] = sprintf(
					/* translators: 1: quantity, 2: product, 3: unit price */
					__( 'Se agregó %1$s × %2$s a %3$s.', 'mj-erp' ),
					MJERP_Helpers::trim_number( $row['qty'] ),
					$row['name'],
					MJERP_Sale_Log::money( $row['unit_price'], $currency )
				);
				continue;
			}

			$old = $before[ $key ];
			if ( abs( $row['qty'] - $old['qty'] ) > 0.0001 ) {
				/* translators: 1: product, 2: previous quantity, 3: new quantity */
				$changes[] = sprintf( __( '%1$s: cantidad %2$s → %3$s.', 'mj-erp' ), $row['name'], MJERP_Helpers::trim_number( $old['qty'] ), MJERP_Helpers::trim_number( $row['qty'] ) );
			}
			if ( abs( $row['unit_price'] - $old['unit_price'] ) > 0.004 ) {
				/* translators: 1: product, 2: previous price, 3: new price */
				$changes[] = sprintf( __( '%1$s: precio %2$s → %3$s.', 'mj-erp' ), $row['name'], MJERP_Sale_Log::money( $old['unit_price'], $currency ), MJERP_Sale_Log::money( $row['unit_price'], $currency ) );
			}
			if ( abs( $row['discount'] - $old['discount'] ) > 0.004 ) {
				/* translators: 1: product, 2: previous discount, 3: new discount */
				$changes[] = sprintf( __( '%1$s: descuento %2$s → %3$s.', 'mj-erp' ), $row['name'], MJERP_Sale_Log::money( $old['discount'], $currency ), MJERP_Sale_Log::money( $row['discount'], $currency ) );
			}
		}

		foreach ( $before as $key => $row ) {
			if ( ! isset( $after[ $key ] ) ) {
				/* translators: 1: quantity, 2: product */
				$changes[] = sprintf( __( 'Se sacó %1$s × %2$s.', 'mj-erp' ), MJERP_Helpers::trim_number( $row['qty'] ), $row['name'] );
			}
		}

		if ( abs( (float) $amounts['discount_total'] - (float) $sale['discount_total'] ) > 0.004 ) {
			/* translators: 1: previous discount, 2: new discount */
			$changes[] = sprintf( __( 'Descuento de la venta: %1$s → %2$s.', 'mj-erp' ), MJERP_Sale_Log::money( $sale['discount_total'], $currency ), MJERP_Sale_Log::money( $amounts['discount_total'], $currency ) );
		}
		if ( abs( (float) $amounts['shipping_total'] - (float) $sale['shipping_total'] ) > 0.004 ) {
			/* translators: 1: previous shipping, 2: new shipping */
			$changes[] = sprintf( __( 'Envío: %1$s → %2$s.', 'mj-erp' ), MJERP_Sale_Log::money( $sale['shipping_total'], $currency ), MJERP_Sale_Log::money( $amounts['shipping_total'], $currency ) );
		}

		return $changes;
	}

	/**
	 * Lines grouped by product: the same product on two lines is one thing for the stock.
	 *
	 * @param array $lines Lines.
	 * @return array key => { product_id, variation_id, name, qty, unit_price, discount }
	 */
	private static function group( array $lines ) : array {
		$out = array();
		foreach ( $lines as $line ) {
			$product_id   = (int) $line['product_id'];
			$variation_id = (int) $line['variation_id'];
			$key          = ( $product_id || $variation_id ) ? $product_id . ':' . $variation_id : 'name:' . (string) $line['name'];

			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'name'         => (string) $line['name'],
					'qty'          => 0.0,
					'unit_price'   => (float) $line['unit_price'],
					'discount'     => 0.0,
				);
			}
			$out[ $key ]['qty']      += (float) $line['qty'];
			$out[ $key ]['discount'] += (float) $line['discount'];
		}
		return $out;
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
