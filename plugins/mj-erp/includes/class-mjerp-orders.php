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
				'cashbox_id'   => MJERP_Settings::get( 'sales_default_cashbox', 0 ),
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
			}

			$order->add_item( $line );
		}
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
	 * Status the emitted order starts in.
	 *
	 * @param array $sale Sale.
	 * @return string
	 */
	protected static function status_for_sale( array $sale ) : string {
		$due = round( MJERP_Sales_Repo::amount_due( $sale ) - (float) $sale['paid_total'], 4 );

		$status = $due <= 0.0001
			? (string) MJERP_Settings::get( 'sales_order_status_paid', 'completed' )
			: (string) MJERP_Settings::get( 'sales_order_status_unpaid', 'on-hold' );

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
