<?php
/**
 * WooCommerce bridge: catching what the shop sells and emitting the orders the ERP creates.
 *
 * Two directions, one rule each:
 *
 *   Shop → ERP. Every order that reaches a configured status is mirrored as a sale exactly once,
 *   with the consignment rule applied line by line. Cancelling, refunding or trashing it unwinds
 *   the sale and the reseller debt (never the stock: WooCommerce restocks on its own).
 *
 *   ERP → shop. A sale loaded in the ERP emits a real order so it shows up in WooCommerce, in its
 *   reports and — the reason this exists — as a notification on the phone.
 *
 * Both are idempotent and guarded against re-entry: emitting an order fires the very hooks that
 * capture orders, and without the guard a sale would mirror itself.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Orders
 */
class MJERP_Orders {

	/**
	 * Whether the ERP is in the middle of creating an order (stops the capture hooks re-entering).
	 *
	 * @var bool
	 */
	private static $emitting = false;

	/**
	 * Orders already handled in this request, so several hooks firing for the same order are free.
	 *
	 * @var array
	 */
	private static $handled = array();

	/**
	 * Option que pide pasar una vez por todos los pedidos con el estado atrasado.
	 */
	const OPTION_RESYNC = 'mjerp_order_status_resync';

	/**
	 * Hooks everything.
	 *
	 * @return void
	 */
	public static function init() {
		// Classic checkout, the Store API (the React storefront uses this one) and the REST API.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_checkout' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_store_api_checkout' ), 20, 1 );

		// Orders typed by hand in wp-admin, or created through the REST API.
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 20, 2 );

		// Status transitions: confirm, or unwind.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );

		// Trashing an order takes the sale with it.
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'on_trashed' ), 20, 1 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trashed_post' ), 20, 1 );

		// Consignment units the ERP already took out are not discounted again by the order.
		add_filter( 'woocommerce_order_item_quantity', array( __CLASS__, 'stock_quantity_of_item' ), 10, 3 );

		// El pedido sigue a la venta: después de maybe_commit (10), que es el que lo emite.
		add_action( 'mjerp_sale_payments_synced', array( __CLASS__, 'sync_status' ), 20, 1 );
		add_action( 'mjerp_sale_delivery_changed', array( __CLASS__, 'sync_status' ), 20, 1 );

		// Los pedidos que quedaron atrasados antes de que el pedido siguiera a la venta (base 1.14.0).
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_resync_statuses' ), 20 );
	}

	/* ------------------------------------------------------------------
	 * ERP → estado del pedido
	 * ---------------------------------------------------------------- */

	/**
	 * Lleva el estado de la venta a su pedido: cobrarla y entregarla lo completan.
	 *
	 * Solo para adelante: a «cobrada y falta entregar» o a «cobrada y entregada» (y entre esas dos, si
	 * la entrega se desmarca). Nunca lo baja a «a cobrar»: un pedido de la tienda esperando la
	 * pasarela, o uno que WooCommerce cobró y el ERP no anotó, lo sabe mejor WooCommerce.
	 *
	 * Tampoco toca un pedido cancelado, reembolsado o con un estado propio: ahí decidió alguien a mano.
	 *
	 * @param int $sale_id Sale id.
	 * @return bool Whether the order changed.
	 */
	public static function sync_status( $sale_id ) : bool {
		if ( self::$emitting || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		if ( ! MJERP_Helpers::bool( MJERP_Settings::get( 'sales_order_sync_status', true ) ) ) {
			return false;
		}

		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale || MJERP_Sales_Repo::STATUS_VOID === (string) $sale['status'] || empty( $sale['order_id'] ) ) {
			return false;
		}

		$order = wc_get_order( (int) $sale['order_id'] );
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$current = self::short_status( $order->get_status() );
		$target  = self::status_for_sale( $sale );
		$unpaid  = self::short_status( (string) MJERP_Settings::get( 'sales_order_status_unpaid', 'on-hold' ) );
		if ( $current === $target || $target === $unpaid || in_array( $target, self::void_statuses(), true ) ) {
			return false;
		}
		if ( ! in_array( $current, self::managed_statuses(), true ) ) {
			return false;
		}

		self::$emitting = true;
		try {
			$order->update_status(
				$target,
				sprintf(
					/* translators: %s: sale code */
					__( 'Estado actualizado desde MJ ERP: así está la venta %s.', 'mj-erp' ),
					$sale['code']
				),
				true
			);
		} catch ( Throwable $e ) {
			self::$emitting = false;
			MJ_Log::error(
				'erp',
				'No se pudo actualizar el estado del pedido de una venta',
				array(
					'venta'  => (int) $sale['id'],
					'pedido' => (int) $sale['order_id'],
					'motivo' => $e->getMessage(),
				)
			);
			return false;
		}
		self::$emitting = false;

		MJERP_Sales_Repo::update( (int) $sale['id'], array( 'order_status' => $order->get_status() ) );

		return true;
	}

	/**
	 * Estados de pedido que el ERP puede mover.
	 *
	 * @return string[]
	 */
	public static function managed_statuses() : array {
		$statuses = array(
			'pending',
			(string) MJERP_Settings::get( 'sales_order_status_unpaid', 'on-hold' ),
			(string) MJERP_Settings::get( 'sales_order_status_undelivered', 'processing' ),
			(string) MJERP_Settings::get( 'sales_order_status_paid', 'completed' ),
		);

		/**
		 * Filters the order statuses the ERP may move to follow its sale.
		 *
		 * @param string[] $statuses Short statuses (no `wc-` prefix).
		 */
		$statuses = (array) apply_filters( 'mjerp_order_sync_statuses', $statuses );

		return array_values( array_unique( array_filter( array_map( array( __CLASS__, 'short_status' ), $statuses ) ) ) );
	}

	/**
	 * Pone al día, una sola vez, los pedidos que quedaron con el estado atrasado.
	 *
	 * Sin mails a los clientes: son ventas de otros días, y un «tu pedido está completo» de una compra
	 * que ya retiraron confunde más de lo que avisa.
	 *
	 * @return void
	 */
	public static function maybe_resync_statuses() : void {
		if ( ! get_option( self::OPTION_RESYNC ) || ! function_exists( 'wc_get_order' ) || ! MJERP_Sales::is_enabled() ) {
			return;
		}
		// Se borra antes de empezar: dos pedidos a la vez no lo corren dos veces.
		delete_option( self::OPTION_RESYNC );

		global $wpdb;

		/**
		 * Cuántas ventas con pedido se revisan al poner al día los estados.
		 *
		 * @param int $limit Sales.
		 */
		$limit = max( 1, (int) apply_filters( 'mjerp_order_status_resync_limit', 500 ) );
		$table = MJERP_Install::table( 'sales' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id > 0 AND status <> %s ORDER BY id DESC LIMIT %d", MJERP_Sales_Repo::STATUS_VOID, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$quiet = array( 'new_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order' );
		foreach ( $quiet as $email ) {
			add_filter( 'woocommerce_email_enabled_' . $email, '__return_false', 999 );
		}

		$changed = 0;
		foreach ( (array) $ids as $sale_id ) {
			if ( self::sync_status( (int) $sale_id ) ) {
				$changed++;
			}
		}

		foreach ( $quiet as $email ) {
			remove_filter( 'woocommerce_email_enabled_' . $email, '__return_false', 999 );
		}

		if ( $changed ) {
			MJ_Log::info( 'erp', 'Pedidos puestos al día con el estado de su venta', array( 'pedidos' => $changed ) );
		}
	}

	/**
	 * Units of an order line that WooCommerce still has to move.
	 *
	 * Una venta cargada en el ERP que sale de la consignación de un revendedor descuenta esas
	 * unidades al registrarse: lo hace su venta en consignación, que es también la que las devuelve
	 * si se anula. Cuando después se emite el pedido, las mismas unidades viajan en la línea para que
	 * el pedido cuente la venta entera, y WooCommerce las descontaba por segunda vez. La línea lleva
	 * anotado cuántas ya salieron, y eso es lo que se le resta.
	 *
	 * @param float|int $qty   Quantity WooCommerce is about to move.
	 * @param mixed     $order Order.
	 * @param mixed     $item  Order line.
	 * @return float|int
	 */
	public static function stock_quantity_of_item( $qty, $order = null, $item = null ) {
		unset( $order );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return $qty;
		}
		$taken = MJERP_Helpers::to_float( $item->get_meta( MJERP_Sales::ITEM_META_CONSIGNMENT_STOCK, true ) );
		if ( $taken <= 0 ) {
			return $qty;
		}
		return max( 0, MJERP_Helpers::to_float( $qty ) - $taken );
	}

	/* ------------------------------------------------------------------
	 * Shop → ERP
	 * ---------------------------------------------------------------- */

	/**
	 * Classic checkout.
	 *
	 * @param int      $order_id Order id.
	 * @param array    $posted   Posted data (unused).
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public static function on_checkout( $order_id, $posted = array(), $order = null ) {
		unset( $posted );
		self::maybe_capture( $order instanceof WC_Order ? $order : $order_id, 'creada' );
	}

	/**
	 * Store API checkout (the React storefront).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function on_store_api_checkout( $order ) {
		self::maybe_capture( $order, 'creada' );
	}

	/**
	 * Order created outside the checkout (wp-admin, REST, another plugin).
	 *
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public static function on_new_order( $order_id, $order = null ) {
		if ( ! MJERP_Helpers::bool( MJERP_Settings::get( 'sales_capture_manual_orders', true ) ) ) {
			return;
		}
		self::maybe_capture( $order instanceof WC_Order ? $order : $order_id, 'creada' );
	}

	/**
	 * Status transition: capture on the configured statuses, void on the configured ones.
	 *
	 * @param int      $order_id   Order id.
	 * @param string   $old_status Previous status.
	 * @param string   $new_status New status.
	 * @param WC_Order $order      Order.
	 * @return void
	 */
	public static function on_status_changed( $order_id, $old_status, $new_status, $order = null ) {
		unset( $old_status );

		// Emitir o cancelar un pedido desde el ERP mueve su estado: sin esta guarda, el ERP se
		// escucharía a sí mismo y volvería a anular la venta que está anulando.
		if ( self::$emitting || ! MJERP_Sales::is_enabled() ) {
			return;
		}

		$new_status = self::short_status( $new_status );

		if ( in_array( $new_status, self::void_statuses(), true ) ) {
			MJERP_Sales::void_for_order(
				$order_id,
				sprintf(
					/* translators: %s: order status */
					__( 'El pedido pasó a «%s» en WooCommerce', 'mj-erp' ),
					self::status_label( $new_status )
				)
			);
			return;
		}

		if ( in_array( $new_status, self::capture_statuses(), true ) ) {
			self::maybe_capture( $order instanceof WC_Order ? $order : $order_id, 'estado' );
		}

		MJERP_Sales::sync_order_status( $order_id, $new_status );
		self::maybe_capture_payment( $order instanceof WC_Order ? $order : $order_id );
	}

	/**
	 * Order sent to the trash.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function on_trashed( $order_id ) {
		MJERP_Sales::void_for_order( $order_id, __( 'El pedido se mandó a la papelera', 'mj-erp' ) );
	}

	/**
	 * Order trashed through the posts table (installations without HPOS).
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function on_trashed_post( $post_id ) {
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return;
		}
		self::on_trashed( $post_id );
	}

	/**
	 * Mirrors an order when the configuration says this moment is the right one.
	 *
	 * @param WC_Order|int $order  Order or id.
	 * @param string       $moment creada | estado.
	 * @return void
	 */
	protected static function maybe_capture( $order, string $moment ) : void {
		if ( self::$emitting || ! MJERP_Sales::is_enabled() ) {
			return;
		}
		if ( ! MJERP_Helpers::bool( MJERP_Settings::get( 'sales_capture_shop_orders', true ) ) ) {
			return;
		}

		$capture_on = (string) MJERP_Settings::get( 'sales_capture_on', 'creada' );
		if ( $capture_on !== $moment ) {
			return;
		}

		$order = self::order( $order );
		if ( ! $order ) {
			return;
		}

		$order_id = $order->get_id();
		if ( isset( self::$handled[ $order_id ] ) ) {
			return;
		}

		// An order the ERP itself emitted is already a sale; mirroring it would duplicate it.
		if ( $order->get_meta( MJERP_Sales::ORDER_META_SALE ) ) {
			self::$handled[ $order_id ] = true;
			return;
		}

		// `woocommerce_new_order` can fire before the lines exist. Leaving the order unmarked lets
		// the next hook of the same request pick it up instead of losing the sale.
		if ( ! $order->get_item_count() ) {
			return;
		}

		self::$handled[ $order_id ] = true;

		$result = MJERP_Sales::record_order( $order );
		if ( is_wp_error( $result ) ) {
			self::log( $order_id, $result );
		}
	}

	/**
	 * Registers the collection of an order that just became paid.
	 *
	 * @param WC_Order|int $order Order or id.
	 * @return void
	 */
	protected static function maybe_capture_payment( $order ) : void {
		if ( ! MJERP_Helpers::bool( MJERP_Settings::get( 'sales_capture_payment', true ) ) ) {
			return;
		}

		$order = self::order( $order );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}

		$sale = MJERP_Sales_Repo::find_by_order( $order->get_id() );
		if ( ! $sale || MJERP_Sales_Repo::PAY_PAID === $sale['payment_status'] || MJERP_Sales_Repo::STATUS_VOID === $sale['status'] ) {
			return;
		}

		$due = round( MJERP_Sales_Repo::amount_due( $sale ) - (float) $sale['paid_total'], 4 );
		if ( $due <= 0.0001 ) {
			return;
		}

		MJERP_Sale_Payments_Repo::create(
			array(
				'sale_id'      => (int) $sale['id'],
				'amount'       => $due,
				'currency'     => $sale['currency'],
				'rate'         => $sale['rate'],
				'method'       => $sale['payment_method'],
				// cashbox_id omitted on purpose: the repo puts it in the box its method points at.
				'reference'    => (string) $order->get_transaction_id(),
				'note'         => (string) $order->get_payment_method_title(),
				'payment_date' => $sale['sale_date'],
				'status'       => MJERP_Sale_Payments_Repo::STATUS_CONFIRMED,
				'source'       => MJERP_Sale_Payments_Repo::SOURCE_SHOP,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * ERP → shop
	 * ---------------------------------------------------------------- */

	/**
	 * Emits the WooCommerce order of a sale registered in the ERP.
	 *
	 * @param int $sale_id Sale id.
	 * @return WC_Order|WP_Error
	 */
	public static function create_for_sale( $sale_id ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'mjerp_wc_missing', __( 'WooCommerce no está disponible.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( $sale['order_id'] ) {
			$existing = wc_get_order( $sale['order_id'] );
			if ( $existing instanceof WC_Order ) {
				return $existing;
			}
		}

		$items = MJERP_Sales_Repo::items( $sale_id );
		if ( empty( $items ) ) {
			return new WP_Error( 'mjerp_no_items', __( 'La venta no tiene productos.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		self::$emitting = true;

		try {
			$order = wc_create_order(
				array(
					'customer_id' => (int) $sale['customer_user_id'],
					'created_via' => (string) MJERP_Settings::get( 'sales_order_created_via', 'mj-erp' ),
				)
			);

			if ( is_wp_error( $order ) ) {
				self::$emitting = false;
				return $order;
			}

			// Written before anything else so the capture hooks recognise it as ours from the start.
			$order->update_meta_data( MJERP_Sales::ORDER_META_SALE, (int) $sale_id );
			$order->update_meta_data( MJERP_Sales::ORDER_META_SOURCE, (string) $sale['source'] );

			self::add_items( $order, $items, $sale );
			self::fill_customer( $order, $sale );

			if ( (float) $sale['shipping_total'] > 0 ) {
				$shipping = new WC_Order_Item_Shipping();
				$shipping->set_method_title( __( 'Envío', 'mj-erp' ) );
				$shipping->set_total( (float) $sale['shipping_total'] );
				$order->add_item( $shipping );
			}

			$order->set_currency( $sale['currency'] );
			$order->set_payment_method( (string) $sale['payment_method'] );
			$order->set_payment_method_title( MJERP_Settings::sale_payment_method_label( $sale['payment_method'] ) );

			$order->calculate_totals( false );

			// The ERP total is the authority: it already carries the discounts and the agreed prices.
			$order->set_total( (float) $sale['total'] );

			$note = (string) MJERP_Settings::get( 'sales_order_note', '' );
			if ( '' !== $note ) {
				$order->add_order_note( sprintf( '%s (%s)', $note, $sale['code'] ) );
			}

			$status = self::status_for_sale( $sale );
			$order->set_status( $status, '', true );
			$order->save();

			self::maybe_reduce_stock( $order );

			MJERP_Sales_Repo::update(
				$sale_id,
				array(
					'order_status' => $order->get_status(),
				)
			);
			self::link_order( $sale_id, $order );
		} catch ( Throwable $e ) {
			self::$emitting = false;
			// La venta quedó cobrada y el pedido no salió: el stock no bajó y
			// la tienda sigue ofreciendo algo que ya no está.
			MJ_Log::error(
				'erp',
				'No se pudo emitir el pedido de WooCommerce de una venta',
				array(
					'venta'   => $sale_id,
					'motivo'  => $e->getMessage(),
					'archivo' => $e->getFile() . ':' . $e->getLine(),
				)
			);
			return new WP_Error( 'mjerp_order_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		self::$emitting = false;

		// The order already exists as a sale; make sure no later hook mirrors it again.
		self::$handled[ $order->get_id() ] = true;

		/**
		 * Fires after the ERP emitted the WooCommerce order of a sale.
		 *
		 * @param WC_Order $order   Order.
		 * @param int      $sale_id Sale id.
		 */
		do_action( 'mjerp_sale_order_created', $order, $sale_id );

		return $order;
	}

	/**
	 * Rewrites the lines of an order so it tells the same story as its corrected sale.
	 *
	 * Una línea por producto, como el pedido que emitió el ERP. Si WooCommerce ya había descontado
	 * el stock del pedido, cada línea se ajusta por la diferencia contra lo que ella misma descontó
	 * (`_reduced_stock`), así cancelar el pedido después sigue devolviendo exactamente lo que salió.
	 * Si todavía no lo descontó, solo se cambian las líneas: lo va a hacer WooCommerce cuando le toque.
	 *
	 * Con `$move_stock` en false las líneas se reescriben pero el stock no se toca: lo movió el ERP
	 * (una devolución), y acá solo se deja anotado cuánto descontó cada línea para que cancelar el
	 * pedido después devuelva lo justo.
	 *
	 * @param int    $sale_id    Sale id (already corrected).
	 * @param string $reason     Reason, for the order note.
	 * @param bool   $move_stock Whether the order moves the stock difference itself.
	 * @param string $note       Order note ('' = the one of a correction).
	 * @return true|WP_Error
	 */
	public static function sync_sale_items( $sale_id, string $reason = '', bool $move_stock = true, string $note = '' ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'mjerp_wc_missing', __( 'WooCommerce no está disponible.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$sale  = MJERP_Sales_Repo::find( $sale_id );
		$order = $sale && $sale['order_id'] ? wc_get_order( (int) $sale['order_id'] ) : false;
		if ( ! $order instanceof WC_Order ) {
			return true;
		}

		$reduced = (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() );

		// Lo que tiene que decir el pedido, por producto.
		$targets = array();
		foreach ( MJERP_Sales_Repo::items( $sale_id ) as $item ) {
			$key = (int) $item['product_id'] . ':' . (int) $item['variation_id'];
			if ( ! isset( $targets[ $key ] ) ) {
				$targets[ $key ] = array(
					'item'     => $item,
					'qty'      => 0.0,
					'subtotal' => 0.0,
					'total'    => 0.0,
					'lines'    => array(),
				);
			}
			$targets[ $key ]['qty']      += (float) $item['qty'];
			$targets[ $key ]['subtotal'] += round( (float) $item['qty'] * (float) $item['unit_price'], 4 );
			$targets[ $key ]['total']    += (float) $item['line_total'];
			$targets[ $key ]['lines'][]   = (int) $item['id'];
		}

		self::$emitting = true;

		try {
			$kept = array();

			foreach ( $order->get_items( 'line_item' ) as $order_item ) {
				if ( ! $order_item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$key = (int) $order_item->get_product_id() . ':' . (int) $order_item->get_variation_id();

				if ( isset( $targets[ $key ] ) && ! isset( $kept[ $key ] ) ) {
					$kept[ $key ] = $order_item;
					if ( $reduced && $move_stock ) {
						self::move_item_stock( $order_item, $targets[ $key ]['qty'], $sale );
					} elseif ( $reduced && '' !== (string) $order_item->get_meta( '_reduced_stock', true ) ) {
						self::set_item_reduced( $order_item, $targets[ $key ]['qty'] );
					}
					$order_item->set_quantity( $targets[ $key ]['qty'] );
					$order_item->set_subtotal( round( $targets[ $key ]['subtotal'], 4 ) );
					$order_item->set_total( round( $targets[ $key ]['total'], 4 ) );
					$order_item->save();
					continue;
				}

				// Ya no está en la venta (o el pedido lo tenía repetido): vuelve lo que había salido.
				if ( $reduced && $move_stock ) {
					self::move_item_stock( $order_item, 0, $sale );
				}
				$order->remove_item( $order_item->get_id() );
			}

			foreach ( $targets as $key => $target ) {
				if ( isset( $kept[ $key ] ) ) {
					continue;
				}
				$item    = $target['item'];
				$lookup  = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
				$product = $lookup ? wc_get_product( $lookup ) : false;

				$line = new WC_Order_Item_Product();
				if ( $product instanceof WC_Product ) {
					$line->set_product( $product );
				} else {
					$line->set_name( (string) $item['name'] );
				}
				$line->set_quantity( $target['qty'] );
				$line->set_subtotal( round( $target['subtotal'], 4 ) );
				$line->set_total( round( $target['total'], 4 ) );
				$order->add_item( $line );
				$line->set_order_id( $order->get_id() );
				$line->save();

				if ( $reduced && $move_stock ) {
					self::move_item_stock( $line, $target['qty'], $sale );
				} elseif ( $reduced ) {
					self::set_item_reduced( $line, $target['qty'] );
				}
				$kept[ $key ] = $line;
			}

			foreach ( $targets as $key => $target ) {
				foreach ( $target['lines'] as $sale_item_id ) {
					MJERP_Sales_Repo::set_order_item( $sale_item_id, $kept[ $key ]->get_id() );
				}
			}

			$shipping_total = (float) $sale['shipping_total'];
			$shipping_items = $order->get_items( 'shipping' );
			if ( ! empty( $shipping_items ) ) {
				$shipping = reset( $shipping_items );
				if ( abs( (float) $shipping->get_total() - $shipping_total ) > 0.004 ) {
					$shipping->set_total( $shipping_total );
					$shipping->save();
				}
			} elseif ( $shipping_total > 0 ) {
				$shipping = new WC_Order_Item_Shipping();
				$shipping->set_method_title( __( 'Envío', 'mj-erp' ) );
				$shipping->set_total( $shipping_total );
				$order->add_item( $shipping );
			}

			$order->calculate_totals( false );
			// Como al emitirlo: el total del ERP manda, porque ya trae el descuento de la venta.
			$order->set_total( (float) $sale['total'] );
			if ( '' === $note ) {
				$note = '' !== $reason
					/* translators: 1: sale code, 2: reason */
					? sprintf( __( 'Productos corregidos desde MJ ERP (%1$s). Motivo: %2$s', 'mj-erp' ), $sale['code'], $reason )
					/* translators: %s: sale code */
					: sprintf( __( 'Productos corregidos desde MJ ERP (%s).', 'mj-erp' ), $sale['code'] );
			}
			$order->add_order_note( $note );
			$order->save();
		} catch ( Throwable $e ) {
			self::$emitting = false;
			return new WP_Error( 'mjerp_order_sync_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		self::$emitting = false;

		return true;
	}

	/**
	 * Takes out or puts back the units of one order line up to `$target`.
	 *
	 * @param WC_Order_Item_Product $order_item Order line.
	 * @param float                 $target     Units the line has to have discounted.
	 * @param array                 $sale       Sale.
	 * @return void
	 */
	protected static function move_item_stock( WC_Order_Item_Product $order_item, $target, array $sale ) : void {
		$product = $order_item->get_product();
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() || ! function_exists( 'wc_update_product_stock' ) ) {
			return;
		}

		$already = MJERP_Helpers::to_float( $order_item->get_meta( '_reduced_stock', true ) );
		$target  = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $target ) : (float) $target;
		$diff    = $target - $already;
		if ( abs( $diff ) < 0.0001 ) {
			return;
		}

		try {
			$holder = MJERP_Products::stock_holder( $product );
			$before = MJERP_Helpers::to_float( $holder->get_stock_quantity() );
			$after  = wc_update_product_stock( $product, abs( $diff ), $diff > 0 ? 'decrease' : 'increase' );
			if ( false === $after || null === $after ) {
				return;
			}

			if ( $target > 0 ) {
				$order_item->update_meta_data( '_reduced_stock', $target );
			} else {
				$order_item->delete_meta_data( '_reduced_stock' );
			}
			$order_item->save();

			MJERP_Movements_Repo::insert(
				array(
					'product_id'   => (int) $order_item->get_product_id(),
					'variation_id' => (int) $order_item->get_variation_id(),
					'qty'          => -1 * (float) $diff,
					'type'         => 'sale_edit',
					'ref_type'     => 'sale',
					'ref_id'       => (int) $sale['id'],
					'stock_before' => $before,
					'stock_after'  => (float) $after,
					'note'         => sprintf(
						/* translators: 1: sale code, 2: order number */
						__( 'Corrección de la venta %1$s (pedido #%2$s)', 'mj-erp' ),
						$sale['code'],
						$sale['order_number']
					),
					'user_id'      => get_current_user_id(),
				)
			);
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Notes how many units of a line are out of the stock, without moving any.
	 *
	 * @param WC_Order_Item_Product $order_item Order line.
	 * @param float                 $qty        Units the line has discounted.
	 * @return void
	 */
	protected static function set_item_reduced( WC_Order_Item_Product $order_item, $qty ) : void {
		$product = $order_item->get_product();
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
			return;
		}
		$qty = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $qty ) : (float) $qty;
		if ( $qty > 0 ) {
			$order_item->update_meta_data( '_reduced_stock', $qty );
		} else {
			$order_item->delete_meta_data( '_reduced_stock' );
		}
		$order_item->save();
	}

	/**
	 * Cancels the order behind a voided sale.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function cancel_order_for_sale( $order_id ) : void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || $order->has_status( 'cancelled' ) ) {
			return;
		}

		self::$emitting = true;
		$order->update_status( 'cancelled', __( 'Venta anulada desde MJ ERP', 'mj-erp' ), true );
		self::$emitting = false;
	}

	/**
	 * Adds the sale lines to an order.
	 *
	 * Consignment lines go in too — the customer did buy them — with a meta marking how many units
	 * came out of the reseller's own stock, so the order tells the same story as the ERP.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $items Sale lines.
	 * @param array    $sale  Sale.
	 * @return void
	 */
	protected static function add_items( WC_Order $order, array $items, array $sale ) : void {
		unset( $sale );

		foreach ( $items as $item ) {
			$target  = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
			$product = $target && function_exists( 'wc_get_product' ) ? wc_get_product( $target ) : false;

			$line = new WC_Order_Item_Product();
			if ( $product instanceof WC_Product ) {
				$line->set_product( $product );
			} else {
				$line->set_name( (string) $item['name'] );
			}

			$line->set_quantity( (float) $item['qty'] );
			$line->set_subtotal( round( (float) $item['qty'] * (float) $item['unit_price'], 4 ) );
			$line->set_total( (float) $item['line_total'] );

			if ( MJERP_Sales_Repo::ORIGIN_CONSIGNMENT === $item['origin'] ) {
				$line->add_meta_data( MJERP_Sales::ITEM_META_CONSIGNMENT, (float) $item['qty'], true );
				if ( self::consignment_stock_taken( (int) $item['consignment_sale_id'] ) ) {
					$line->add_meta_data( MJERP_Sales::ITEM_META_CONSIGNMENT_STOCK, (float) $item['qty'], true );
				}
			}

			$order->add_item( $line );
		}
	}

	/**
	 * Whether the consignment sale behind a line already took its units out of the stock.
	 *
	 * Only a sale loaded in the ERP does: a shop order's consignment part rides on the order's own
	 * stock, and that one WooCommerce has to move as usual.
	 *
	 * @param int $consignment_sale_id Consignment sale id.
	 * @return bool
	 */
	protected static function consignment_stock_taken( $consignment_sale_id ) : bool {
		if ( $consignment_sale_id <= 0 || ! class_exists( 'MJERP_Consignment_Sales_Repo' ) ) {
			return false;
		}
		$consignment_sale = MJERP_Consignment_Sales_Repo::find( $consignment_sale_id );
		return $consignment_sale
			&& ! empty( $consignment_sale['stock_applied'] )
			&& MJERP_Consignment_Sales_Repo::SOURCE_SHOP !== (string) $consignment_sale['source'];
	}

	/**
	 * Copies the customer data onto the order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $sale  Sale.
	 * @return void
	 */
	protected static function fill_customer( WC_Order $order, array $sale ) : void {
		$user_id = (int) $sale['customer_user_id'];

		if ( $user_id ) {
			$customer = new WC_Customer( $user_id );
			$address  = $customer->get_billing();
			if ( is_array( $address ) && ! empty( $address ) ) {
				$order->set_address( $address, 'billing' );
			}
			$shipping = $customer->get_shipping();
			if ( is_array( $shipping ) && ! empty( $shipping ) ) {
				$order->set_address( $shipping, 'shipping' );
			}
		}

		// Whatever was typed on the sale wins over the stored address.
		$name = trim( (string) $sale['customer_name'] );
		if ( '' !== $name && '' === trim( (string) $order->get_billing_first_name() ) ) {
			$parts = preg_split( '/\s+/', $name, 2 );
			$order->set_billing_first_name( (string) $parts[0] );
			if ( isset( $parts[1] ) ) {
				$order->set_billing_last_name( (string) $parts[1] );
			}
		}
		if ( '' !== (string) $sale['customer_email'] && '' === trim( (string) $order->get_billing_email() ) ) {
			$order->set_billing_email( (string) $sale['customer_email'] );
		}
		if ( '' !== (string) $sale['customer_phone'] && '' === trim( (string) $order->get_billing_phone() ) ) {
			$order->set_billing_phone( (string) $sale['customer_phone'] );
		}
	}

	/**
	 * The status the order of a sale has to be in: when it is emitted and every time it changes.
	 *
	 * Queda a cobrar → el de «a cobrar». Cobrada y falta entregar → el de «falta entregar». Cobrada
	 * y entregada (o sin el paso de entrega) → el de «cobrada».
	 *
	 * @param array $sale Sale.
	 * @return string
	 */
	public static function status_for_sale( array $sale ) : string {
		$due = round( MJERP_Sales_Repo::amount_due( $sale ) - (float) $sale['paid_total'], 4 );

		if ( $due > 0.0001 ) {
			$status = (string) MJERP_Settings::get( 'sales_order_status_unpaid', 'on-hold' );
		} elseif ( MJERP_Settings::delivery_enabled() && MJERP_Sales_Repo::DELIVERY_DELIVERED !== (string) $sale['delivery_status'] ) {
			$status = (string) MJERP_Settings::get( 'sales_order_status_undelivered', 'processing' );
		} else {
			$status = (string) MJERP_Settings::get( 'sales_order_status_paid', 'completed' );
		}

		/**
		 * Filters the status an ERP-emitted order starts in.
		 *
		 * @param string $status Status (short form, no `wc-` prefix).
		 * @param array  $sale   Sale.
		 */
		$status = sanitize_key( (string) apply_filters( 'mjerp_sale_order_status', $status, $sale ) );

		return '' === $status ? 'on-hold' : self::short_status( $status );
	}

	/**
	 * Reduces the stock of an emitted order when the configuration asks for it.
	 *
	 * With `auto` nothing happens here: WooCommerce reduces stock on its own status transitions,
	 * and doing it twice is the failure mode this whole class is careful about.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	protected static function maybe_reduce_stock( WC_Order $order ) : void {
		if ( 'siempre' !== (string) MJERP_Settings::get( 'sales_reduce_stock', 'auto' ) ) {
			return;
		}
		if ( function_exists( 'wc_maybe_reduce_stock_levels' ) ) {
			wc_maybe_reduce_stock_levels( $order->get_id() );
		}
	}

	/**
	 * Writes the order reference onto the sale.
	 *
	 * @param int      $sale_id Sale id.
	 * @param WC_Order $order   Order.
	 * @return void
	 */
	protected static function link_order( $sale_id, WC_Order $order ) : void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MJERP_Install::table( 'sales' ),
			array(
				'order_id'      => $order->get_id(),
				'order_number'  => (string) $order->get_order_number(),
				'order_status'  => (string) $order->get_status(),
				'stock_applied' => 1,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => absint( $sale_id ) ),
			array( '%d', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		// The consignment sales of this sale point at the order too, so cancelling it finds them.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MJERP_Install::table( 'consignment_sales' ),
			array( 'order_id' => $order->get_id() ),
			array( 'sale_id' => absint( $sale_id ) ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Statuses that confirm a sale.
	 *
	 * @return string[]
	 */
	public static function capture_statuses() : array {
		$list = MJERP_Settings::get( 'sales_capture_statuses', array() );
		return array_map( array( __CLASS__, 'short_status' ), is_array( $list ) ? $list : array() );
	}

	/**
	 * Statuses that void a sale.
	 *
	 * @return string[]
	 */
	public static function void_statuses() : array {
		$list = MJERP_Settings::get( 'sales_void_statuses', array() );
		return array_map( array( __CLASS__, 'short_status' ), is_array( $list ) ? $list : array() );
	}

	/**
	 * Drops the `wc-` prefix of a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function short_status( $status ) : string {
		$status = sanitize_key( (string) $status );
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Human label of an order status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) : string {
		$status = self::short_status( $status );
		if ( function_exists( 'wc_get_order_status_name' ) ) {
			return (string) wc_get_order_status_name( $status );
		}
		return ucfirst( str_replace( '-', ' ', $status ) );
	}

	/**
	 * Order statuses as `[ {key,label} ]` for the settings screen.
	 *
	 * @return array
	 */
	public static function statuses() : array {
		$out = array();
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return $out;
		}
		foreach ( (array) wc_get_order_statuses() as $key => $label ) {
			$out[] = array(
				'key'   => self::short_status( $key ),
				'label' => (string) $label,
			);
		}
		return $out;
	}

	/**
	 * Resolves an order argument into an order object.
	 *
	 * @param WC_Order|int $order Order or id.
	 * @return WC_Order|null
	 */
	protected static function order( $order ) : ?WC_Order {
		if ( $order instanceof WC_Order ) {
			return $order;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( absint( $order ) );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Records a capture failure on the order itself, where it can actually be seen.
	 *
	 * @param int      $order_id Order id.
	 * @param WP_Error $error    Error.
	 * @return void
	 */
	protected static function log( $order_id, $error ) : void {
		MJ_Log::error(
			'erp',
			'No se pudo registrar un pedido de la tienda como venta',
			array(
				'pedido' => (int) $order_id,
				'motivo' => $error->get_error_message(),
			)
		);

		$order = self::order( $order_id );
		if ( ! $order ) {
			return;
		}
		$order->add_order_note(
			sprintf(
				/* translators: %s: error message */
				__( 'MJ ERP no pudo registrar este pedido como venta: %s', 'mj-erp' ),
				$error->get_error_message()
			)
		);
	}
}
