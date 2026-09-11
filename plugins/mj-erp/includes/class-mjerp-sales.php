<?php
/**
 * Sales orchestration: turning a cart, a counter sale or a WooCommerce order into an ERP sale.
 *
 * The rule the whole module hangs from:
 *
 *   Every line is checked against what the buyer is holding on consignment. Whatever they already
 *   have goes to their current account at the price that was agreed when it was handed over; the
 *   rest is an ordinary sale out of the default stock. A line splits between the two when the
 *   consignment does not cover the whole quantity.
 *
 * WooCommerce owns the stock from end to end. A consignment sale born from an order is recorded
 * with `stock_already_applied`, because the order already took the units out (and puts them back on
 * its own if it is cancelled) — discounting them again here is the one bug this module could not
 * recover from.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Sales
 */
class MJERP_Sales {

	/**
	 * Meta key linking a WooCommerce order back to its ERP sale.
	 */
	const ORDER_META_SALE = '_mjerp_sale_id';

	/**
	 * Meta key marking an order the ERP itself created.
	 */
	const ORDER_META_SOURCE = '_mjerp_sale_source';

	/**
	 * Order item meta holding the units of that line that came out of a consignment.
	 */
	const ITEM_META_CONSIGNMENT = '_mjerp_consignment_qty';

	/**
	 * Whether the sales module is on.
	 *
	 * @return bool
	 */
	public static function is_enabled() : bool {
		return MJERP_Helpers::bool( MJERP_Settings::get( 'sales_enabled', true ) );
	}

	/**
	 * Hooks the two moments that can commit a sale: cobrarla y entregarla.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'mjerp_sale_payments_synced', array( __CLASS__, 'maybe_commit' ), 10, 1 );
		add_action( 'mjerp_sale_delivery_changed', array( __CLASS__, 'maybe_commit' ), 10, 1 );
	}

	/* ------------------------------------------------------------------
	 * Cuándo una venta se vuelve firme
	 * ---------------------------------------------------------------- */

	/**
	 * ¿La venta ya compromete la mercadería?
	 *
	 * Anotar una venta no es lo mismo que cerrarla. Mientras nadie pagó ni se llevó nada, el
	 * producto sigue estando disponible para quien lo compre primero: bajarle el stock ahí sería
	 * esconder mercadería que todavía se puede vender. Lo que la vuelve firme es una de dos cosas,
	 * y cuál de ellas lo decide Configuración → Ventas:
	 *
	 *   - **Cobrada**: el cliente puso la plata, el producto es suyo aunque todavía no lo tenga.
	 *   - **Entregada**: se lo llevó, aunque todavía no haya pagado.
	 *
	 * Por defecto vale cualquiera de las dos. Un pago parcial no alcanza: la venta figura «Pago
	 * parcial», no «Pagada», y con una seña la mercadería se reserva marcando la entrega o
	 * emitiendo el pedido a mano.
	 *
	 * @param array $sale Sale record.
	 * @return bool
	 */
	public static function is_committed( array $sale ) : bool {
		$when = (string) MJERP_Settings::get( 'sales_commit_when', 'pago_o_entrega' );

		$paid      = MJERP_Sales_Repo::PAY_PAID === (string) MJERP_Helpers::array_get( $sale, 'payment_status', '' );
		$delivered = MJERP_Sales_Repo::DELIVERY_DELIVERED === (string) MJERP_Helpers::array_get( $sale, 'delivery_status', '' );

		switch ( $when ) {
			case 'registrar':
				$committed = true;
				break;
			case 'pago':
				$committed = $paid;
				break;
			case 'entrega':
				$committed = $delivered;
				break;
			case 'manual':
				$committed = false;
				break;
			default:
				$committed = $paid || $delivered;
		}

		/**
		 * Filters whether a sale already commits the goods.
		 *
		 * Sirve, por ejemplo, para que una seña cuente como reserva.
		 *
		 * @param bool  $committed Whether the sale is firm.
		 * @param array $sale      Sale record.
		 * @param string $when     Configured rule.
		 */
		return (bool) apply_filters( 'mjerp_sale_is_committed', $committed, $sale, $when );
	}

	/**
	 * Emite el pedido y descuenta el stock en cuanto la venta se vuelve firme.
	 *
	 * Se llama sola cuando se registra un cobro y cuando se marca la entrega, así que el circuito no
	 * depende de que nadie se acuerde de apretar nada. Es idempotente por diseño: una venta que ya
	 * tiene pedido —o que ya descontó su stock— sale por la primera puerta.
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	public static function maybe_commit( $sale_id ) : void {
		$sale_id = absint( $sale_id );
		if ( ! $sale_id ) {
			return;
		}

		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale || MJERP_Sales_Repo::STATUS_VOID === $sale['status'] ) {
			return;
		}
		// Ya pasó por acá: el pedido existe o el stock salió de la estantería.
		if ( $sale['order_id'] || $sale['stock_applied'] ) {
			return;
		}
		if ( ! self::is_committed( $sale ) ) {
			return;
		}

		if ( ! empty( $sale['wants_order'] ) && MJERP_Helpers::bool( MJERP_Settings::get( 'sales_create_order', true ) ) ) {
			$order = MJERP_Orders::create_for_sale( $sale_id );
			if ( ! is_wp_error( $order ) ) {
				return;
			}
			// Si el pedido falla, la mercadería igual salió: el stock lo baja el ERP.
			self::note_order_error( $sale_id, $sale, $order );
		}

		// Sin pedido no hay nadie más que mueva el stock, así que lo mueve el ERP.
		self::apply_stock( $sale_id );
	}

	/**
	 * Deja escrito en la venta por qué no se pudo emitir el pedido.
	 *
	 * @param int      $sale_id Sale id.
	 * @param array    $sale    Sale record.
	 * @param WP_Error $error   Error.
	 * @return void
	 */
	protected static function note_order_error( $sale_id, array $sale, $error ) : void {
		MJERP_Sales_Repo::update(
			$sale_id,
			array(
				'note' => trim(
					(string) MJERP_Helpers::array_get( $sale, 'note', '' ) . "\n" . sprintf(
						/* translators: %s: error message */
						__( 'No se pudo crear el pedido en WooCommerce: %s', 'mj-erp' ),
						$error->get_error_message()
					)
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * From the ERP screen
	 * ---------------------------------------------------------------- */

	/**
	 * Registers a sale loaded in the ERP and, when configured to, emits the WooCommerce order that
	 * makes it visible in the shop (and notifies the app).
	 *
	 * @param array $data items, customer_*, consignee_id, channel, sale_date, note, discount_total,
	 *                    shipping_total, price_list_key, payment, create_order, status.
	 * @return array|WP_Error The stored sale.
	 */
	public static function create_from_erp( array $data ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'mjerp_sales_disabled', __( 'El módulo de ventas está desactivado.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		$raw_items = isset( $data['items'] ) && is_array( $data['items'] ) ? array_values( $data['items'] ) : array();
		if ( empty( $raw_items ) ) {
			return new WP_Error( 'mjerp_no_items', __( 'Agregá al menos un producto a la venta.', 'mj-erp' ), array( 'status' => 400, 'field' => 'items' ) );
		}

		$currency = MJERP_Sales_Repo::shop_currency();
		$rate     = MJERP_Sales_Repo::default_rate( $currency );

		$customer = self::resolve_customer( $data );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$consignee = self::resolve_consignee( $customer['user_id'], MJERP_Helpers::array_get( $data, 'consignee_id', 0 ) );

		$price_list = sanitize_key( (string) MJERP_Helpers::array_get( $data, 'price_list_key', '' ) );
		if ( '' === $price_list ) {
			// La lista propia del cliente manda sobre la general: es el precio que se le prometió.
			$price_list = (string) $customer['price_list_key'];
		}
		if ( '' === $price_list ) {
			$price_list = (string) MJERP_Settings::get( 'sales_price_list', '' );
		}

		$prepared = self::prepare_lines( $raw_items, $consignee, $currency, $rate, $price_list );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( empty( $prepared ) ) {
			return new WP_Error( 'mjerp_no_items', __( 'Ninguno de los productos elegidos es válido.', 'mj-erp' ), array( 'status' => 400, 'field' => 'items' ) );
		}

		/*
		 * No hay deuda sin cliente.
		 *
		 * Una venta que no queda saldada deja plata a cobrar, y esa plata tiene que tener a quién
		 * reclamársela: sin ficha, el saldo queda flotando en un listado y no aparece en la cuenta
		 * de nadie. Un nombre suelto no alcanza — no abre historial ni cuenta corriente.
		 */
		$debt = self::debt_check( $data, $prepared, $customer );
		if ( is_wp_error( $debt ) ) {
			return $debt;
		}

		$create = array(
			'source'           => MJERP_Sales_Repo::SOURCE_ERP,
			'status'           => MJERP_Sales_Repo::sanitize_status( MJERP_Helpers::array_get( $data, 'status', MJERP_Sales_Repo::STATUS_CONFIRMED ) ),
			'channel'          => MJERP_Helpers::array_get( $data, 'channel', '' ),
			'sale_date'        => MJERP_Helpers::array_get( $data, 'sale_date', null ),
			'customer_id'      => $customer['id'],
			'customer_user_id' => $customer['user_id'],
			'customer_name'    => $customer['name'],
			'customer_email'   => $customer['email'],
			'customer_phone'   => $customer['phone'],
			'consignee_id'     => $consignee ? (int) $consignee['id'] : 0,
			'price_list_key'   => $price_list,
			'currency'         => $currency,
			'rate'             => $rate,
			'discount_total'   => MJERP_Helpers::array_get( $data, 'discount_total', 0 ),
			'shipping_total'   => MJERP_Helpers::array_get( $data, 'shipping_total', 0 ),
			'note'             => MJERP_Helpers::array_get( $data, 'note', '' ),
			/*
			 * Entrega: si no llega ninguna, se usa la configurada por defecto. El estado
			 * (entregado o pendiente) lo resuelve el repositorio mirando el método.
			 */
			'delivery_method'  => array_key_exists( 'delivery_method', $data )
				? $data['delivery_method']
				: MJERP_Settings::get( 'sales_default_delivery_method', '' ),
			'delivery_note'    => MJERP_Helpers::array_get( $data, 'delivery_note', '' ),
			'payment_method'   => MJERP_Helpers::array_get( $data, 'payment_method', '' ),
			'cashbox_id'       => MJERP_Sale_Payments_Repo::resolve_cashbox(
				MJERP_Helpers::array_get( $data, 'cashbox_id', 0 ),
				MJERP_Helpers::array_get( $data, 'payment_method', '' )
			),
			'items'            => $prepared,
		);

		// Solo viaja si el formulario lo decidió: ausente = lo que diga el método de entrega.
		if ( array_key_exists( 'delivered', $data ) ) {
			$create['delivered'] = MJERP_Helpers::bool( $data['delivered'] );
		}

		/*
		 * Si esta venta tiene que terminar en un pedido de WooCommerce se guarda en la venta, no se
		 * decide acá: el pedido puede emitirse días después —cuando se cobre o se entregue— y para
		 * entonces la casilla del formulario ya no existe.
		 */
		$create['wants_order'] = array_key_exists( 'create_order', $data )
			? MJERP_Helpers::bool( $data['create_order'] )
			: MJERP_Helpers::bool( MJERP_Settings::get( 'sales_create_order', true ) );

		$sale_id = MJERP_Sales_Repo::create( $create );
		if ( is_wp_error( $sale_id ) ) {
			return $sale_id;
		}

		// The consignment side is written after the sale exists so every reseller debt points at the
		// sale that produced it, and a failure there never leaves a sale half-written.
		self::write_consignment_sales( $sale_id, $prepared, $consignee, array( 'source' => MJERP_Consignment_Sales_Repo::SOURCE_ADMIN ) );

		// El cobro va antes de emitir el pedido: el estado con el que nace la orden depende de si la
		// venta quedó saldada, y registrarlo después la dejaría marcada como impaga para siempre.
		self::register_initial_payment( $sale_id, $data );

		/*
		 * Pedido y stock: solo si la venta ya es firme (cobrada o entregada, según lo configurado).
		 * Un pedido anotado que nadie pagó ni se llevó no esconde mercadería que todavía se puede
		 * vender; cuando se cobre o se entregue, esto mismo corre solo.
		 */
		self::maybe_commit( $sale_id );

		$sale = MJERP_Sales_Repo::get_full( $sale_id );

		/**
		 * Fires after a sale was fully registered from the ERP.
		 *
		 * @param int   $sale_id Sale id.
		 * @param array $sale    Stored sale.
		 */
		do_action( 'mjerp_sale_registered', $sale_id, $sale );

		return $sale;
	}

	/**
	 * Rechaza una venta que va a quedar con saldo y no tiene ficha de cliente.
	 *
	 * @param array $data     Request data.
	 * @param array $lines    Prepared lines.
	 * @param array $customer Resolved customer.
	 * @return true|WP_Error
	 */
	protected static function debt_check( array $data, array $lines, array $customer ) {
		if ( ! empty( $customer['id'] ) ) {
			return true;
		}
		if ( ! MJERP_Settings::customers_enabled() ) {
			// Sin libreta de clientes no hay ficha que pedir: la tienda eligió no llevarla.
			return true;
		}

		$collectable = self::collectable_of( $data, $lines );
		$paying      = self::initial_payment_amount( $data, $collectable );

		if ( $collectable - $paying <= MJERP_Sales_Repo::EPSILON ) {
			return true;
		}

		$error = new WP_Error(
			'mjerp_debt_needs_customer',
			__( 'La venta queda con saldo: elegí (o creá) el cliente que va a quedar debiendo.', 'mj-erp' ),
			array(
				'status'      => 400,
				'field'       => 'customer_id',
				'due'         => round( $collectable - $paying, 4 ),
				'collectable' => $collectable,
			)
		);

		/**
		 * Filters the "no debt without a customer" rule.
		 *
		 * @param WP_Error|true $error    Error, or true to allow the sale.
		 * @param array         $data     Request data.
		 * @param array         $customer Resolved customer.
		 */
		return apply_filters( 'mjerp_sale_debt_check', $error, $data, $customer );
	}

	/**
	 * Lo que hay que cobrar de una venta que todavía no existe.
	 *
	 * Misma cuenta que `preview()`: el total menos lo que sale de la consignación de un revendedor,
	 * que no se cobra acá sino en su cuenta corriente.
	 *
	 * @param array $data  Request data.
	 * @param array $lines Prepared lines.
	 * @return float
	 */
	protected static function collectable_of( array $data, array $lines ) : float {
		$subtotal    = 0.0;
		$consignment = 0.0;

		foreach ( $lines as $line ) {
			$line_total = (float) MJERP_Helpers::array_get( $line, 'line_total', 0 );
			$subtotal  += $line_total;
			if ( MJERP_Sales_Repo::ORIGIN_CONSIGNMENT === MJERP_Helpers::array_get( $line, 'origin', '' ) ) {
				$consignment += $line_total;
			}
		}

		$discount = round( max( 0, MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'discount_total', 0 ) ) ), 4 );
		$shipping = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'shipping_total', 0 ) ), 4 );
		$total    = round( $subtotal - $discount + $shipping, 4 );

		return round( max( 0, $total - $consignment ), 4 );
	}

	/**
	 * Cuánto se va a cobrar al registrar la venta.
	 *
	 * Espejo de `register_initial_payment()`: sin bloque de cobro no se cobra nada, y un cobro sin
	 * importe es "todo lo que hay que cobrar".
	 *
	 * @param array $data        Request data.
	 * @param float $collectable Collectable amount.
	 * @return float
	 */
	protected static function initial_payment_amount( array $data, float $collectable ) : float {
		$payment = MJERP_Helpers::array_get( $data, 'payment', null );
		if ( ! is_array( $payment ) ) {
			return 0.0;
		}
		$amount = MJERP_Helpers::array_get( $payment, 'amount', null );
		if ( null === $amount || '' === $amount ) {
			return $collectable;
		}
		return round( max( 0, MJERP_Helpers::to_float( $amount ) ), 4 );
	}

	/**
	 * Works out what a sale would look like without storing anything.
	 *
	 * The new-sale screen calls this on every change so the shop owner sees which units are going to
	 * come out of the reseller's consignment *before* confirming, instead of finding out afterwards.
	 *
	 * @param array $data items, customer_user_id, consignee_id, price_list_key, discount_total,
	 *                    shipping_total.
	 * @return array|WP_Error { lines, totals, consignee, currency, rate }
	 */
	public static function preview( array $data ) {
		$currency = MJERP_Sales_Repo::shop_currency();
		$rate     = MJERP_Sales_Repo::default_rate( $currency );

		$customer = self::resolve_customer( $data, false );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$consignee = self::resolve_consignee( $customer['user_id'], MJERP_Helpers::array_get( $data, 'consignee_id', 0 ) );

		$price_list = sanitize_key( (string) MJERP_Helpers::array_get( $data, 'price_list_key', '' ) );
		if ( '' === $price_list ) {
			$price_list = (string) $customer['price_list_key'];
		}
		if ( '' === $price_list ) {
			$price_list = (string) MJERP_Settings::get( 'sales_price_list', '' );
		}

		$raw_items = isset( $data['items'] ) && is_array( $data['items'] ) ? array_values( $data['items'] ) : array();
		$lines     = self::prepare_lines( $raw_items, $consignee, $currency, $rate, $price_list );
		if ( is_wp_error( $lines ) ) {
			return $lines;
		}

		$subtotal          = 0.0;
		$cost_base         = 0.0;
		$consignment_total = 0.0;
		$consignment_base  = 0.0;
		$qty               = 0.0;

		foreach ( $lines as $index => $line ) {
			$line_total = (float) $line['line_total'];
			$subtotal  += $line_total;
			$qty       += (float) $line['qty'];
			$cost_base += round( (float) $line['unit_cost_base'] * (float) $line['qty'], 4 );

			if ( MJERP_Sales_Repo::ORIGIN_CONSIGNMENT === $line['origin'] ) {
				$consignment_total += $line_total;
				$consignment_base  += round( (float) $line['qty'] * (float) $line['consignment_price_base'], 4 );
			}

			$lines[ $index ]['origin_label'] = MJERP_Sales_Repo::ORIGIN_CONSIGNMENT === $line['origin']
				? __( 'Consignación', 'mj-erp' )
				: __( 'Depósito', 'mj-erp' );
		}

		$discount   = round( max( 0, MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'discount_total', 0 ) ) ), 4 );
		$shipping   = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $data, 'shipping_total', 0 ) ), 4 );
		$total      = round( $subtotal - $discount + $shipping, 4 );
		$total_base = $rate > 0 ? round( $total / $rate, 4 ) : $total;

		return array(
			'lines'     => $lines,
			'currency'  => $currency,
			'rate'      => $rate,
			'consignee' => $consignee ? array(
				'id'             => (int) $consignee['id'],
				'name'           => (string) $consignee['name'],
				'price_list_key' => (string) $consignee['price_list_key'],
			) : null,
			'totals'    => array(
				'qty'               => round( $qty, 4 ),
				'subtotal'          => round( $subtotal, 4 ),
				'discount_total'    => $discount,
				'shipping_total'    => $shipping,
				'total'             => $total,
				'total_base'        => $total_base,
				'cost_base'         => round( $cost_base, 4 ),
				'profit_base'       => round( $total_base - $cost_base, 4 ),
				'consignment_total' => round( $consignment_total, 4 ),
				'consignment_base'  => round( $consignment_base, 4 ),
				'collectable'       => round( max( 0, $total - $consignment_total ), 4 ),
			),
		);
	}

	/* ------------------------------------------------------------------
	 * From a WooCommerce order
	 * ---------------------------------------------------------------- */

	/**
	 * Mirrors a WooCommerce order as an ERP sale, applying the consignment rule line by line.
	 *
	 * Idempotent: an order that already has a sale only gets its status refreshed, so it does not
	 * matter how many hooks end up calling this.
	 *
	 * @param WC_Order $order Order.
	 * @return int|WP_Error Sale id.
	 */
	public static function record_order( $order ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'mjerp_sales_disabled', __( 'El módulo de ventas está desactivado.', 'mj-erp' ), array( 'status' => 409 ) );
		}
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mjerp_invalid_order', __( 'Pedido inválido.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		$order_id = $order->get_id();

		$existing = MJERP_Sales_Repo::find_by_order( $order_id );
		if ( $existing ) {
			MJERP_Sales_Repo::update( $existing['id'], array( 'order_status' => $order->get_status() ) );
			return (int) $existing['id'];
		}

		$currency = strtoupper( (string) $order->get_currency() );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$currency = MJERP_Sales_Repo::shop_currency();
		}
		$rate = MJERP_Sales_Repo::default_rate( $currency );

		$customer_id = absint( $order->get_customer_id() );
		$consignee   = self::resolve_consignee( $customer_id, 0 );

		$lines = array();
		$sort  = 0;
		$used  = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$qty = round( MJERP_Helpers::to_float( $item->get_quantity() ), 4 );
			if ( $qty <= 0 ) {
				continue;
			}

			$product_id   = absint( $item->get_product_id() );
			$variation_id = absint( $item->get_variation_id() );
			$line_total   = round( MJERP_Helpers::to_float( $item->get_total() ) + MJERP_Helpers::to_float( $item->get_total_tax() ), 4 );
			$subtotal     = round( MJERP_Helpers::to_float( $item->get_subtotal() ), 4 );
			$discount     = round( max( 0, $subtotal - MJERP_Helpers::to_float( $item->get_total() ) ), 4 );
			$unit_price   = $qty > 0 ? round( $line_total / $qty, 6 ) : 0.0;

			$product = $item->get_product();
			$sku     = $product instanceof WC_Product ? (string) $product->get_sku() : '';

			$split = self::split_line( $consignee, $product_id, $variation_id, $qty, $used );

			foreach ( $split as $part ) {
				$part_qty   = (float) $part['qty'];
				$part_total = $qty > 0 ? round( $line_total * ( $part_qty / $qty ), 4 ) : 0.0;

				$lines[] = self::build_line(
					array(
						'order_item_id'          => (int) $item_id,
						'product_id'             => $product_id,
						'variation_id'           => $variation_id,
						'name'                   => (string) $item->get_name(),
						'sku'                    => $sku,
						'qty'                    => $part_qty,
						'unit_price'             => $unit_price,
						'discount'               => $qty > 0 ? round( $discount * ( $part_qty / $qty ), 4 ) : 0.0,
						'line_total'             => $part_total,
						'origin'                 => $part['origin'],
						'consignee_id'           => $part['consignee_id'],
						'consignment_item_id'    => $part['consignment_item_id'],
						'consignment_price_base' => $part['unit_price_base'],
						'sort_order'             => $sort,
					)
				);
				$sort++;
			}
		}

		if ( empty( $lines ) ) {
			return new WP_Error( 'mjerp_no_items', __( 'El pedido no tiene productos para registrar.', 'mj-erp' ), array( 'status' => 400 ) );
		}

		$name = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $name ) {
			$name = trim( (string) $order->get_billing_company() );
		}
		if ( '' === $name && $customer_id ) {
			$name = MJERP_Helpers::user_display_name( $customer_id );
		}

		// La ficha del cliente: si la cuenta ya tiene una, se reusa; si no, se abre con lo que trae
		// el pedido, para que la venta de la tienda quede en el mismo historial que la del mostrador.
		$card = self::customer_card_for_order( $order, $customer_id, $name );

		// El medio de pago del pedido decide, además, en qué caja entra esa plata.
		$order_method = self::method_from_order( $order );

		$sale_id = MJERP_Sales_Repo::create(
			array(
				'order_id'         => $order_id,
				'order_number'     => (string) $order->get_order_number(),
				'order_status'     => (string) $order->get_status(),
				'source'           => MJERP_Sales_Repo::SOURCE_SHOP,
				'channel'          => MJERP_Settings::get( 'sales_shop_channel', 'tienda' ),
				'status'           => MJERP_Sales_Repo::STATUS_CONFIRMED,
				// Un pedido de la tienda todavía no salió de acá: nace con el método configurado
				// para la tienda (normalmente Envío) y su estado de entrega.
				'delivery_method'  => MJERP_Settings::get( 'sales_shop_delivery_method', '' ),
				'sale_date'        => self::order_date( $order ),
				'customer_id'      => $card ? (int) $card['id'] : 0,
				'customer_user_id' => $customer_id,
				'customer_name'    => $name,
				'customer_email'   => (string) $order->get_billing_email(),
				'customer_phone'   => (string) $order->get_billing_phone(),
				'consignee_id'     => $consignee ? (int) $consignee['id'] : 0,
				'currency'         => $currency,
				'rate'             => $rate,
				'shipping_total'   => round( MJERP_Helpers::to_float( $order->get_shipping_total() ) + MJERP_Helpers::to_float( $order->get_shipping_tax() ), 4 ),
				'payment_method'   => $order_method,
				'cashbox_id'       => MJERP_Sale_Payments_Repo::resolve_cashbox( 0, $order_method ),
				'stock_applied'    => 1,
				'items'            => $lines,
			)
		);
		if ( is_wp_error( $sale_id ) ) {
			return $sale_id;
		}

		$order->update_meta_data( self::ORDER_META_SALE, $sale_id );
		$order->save();

		self::write_consignment_sales(
			$sale_id,
			$lines,
			$consignee,
			array(
				'source'   => MJERP_Consignment_Sales_Repo::SOURCE_SHOP,
				'order_id' => $order_id,
				// WooCommerce owns the stock of an order from end to end.
				'stock_already_applied' => true,
			)
		);

		if ( self::should_capture_payment() && $order->is_paid() ) {
			self::register_order_payment( $sale_id, $order );
		}

		$sale = MJERP_Sales_Repo::find( $sale_id );

		/**
		 * Fires after a WooCommerce order was mirrored as a sale.
		 *
		 * @param int      $sale_id Sale id.
		 * @param WC_Order $order   Order.
		 * @param array    $sale    Stored sale.
		 */
		do_action( 'mjerp_order_recorded', $sale_id, $order, $sale );

		return $sale_id;
	}

	/**
	 * Voids the sale of an order (cancelled, refunded, trashed) and unwinds the reseller debt it
	 * created.
	 *
	 * The stock is deliberately not touched: WooCommerce restocks a cancelled order itself.
	 *
	 * @param int    $order_id Order id.
	 * @param string $reason   Reason recorded on the sale.
	 * @return bool Whether something was voided.
	 */
	public static function void_for_order( $order_id, string $reason = '' ) : bool {
		$sale = MJERP_Sales_Repo::find_by_order( $order_id );
		if ( ! $sale || MJERP_Sales_Repo::STATUS_VOID === $sale['status'] ) {
			return false;
		}

		foreach ( MJERP_Consignment_Sales_Repo::for_order( $order_id ) as $consignment_sale ) {
			MJERP_Consignment_Sales_Repo::delete(
				(int) $consignment_sale['id'],
				array(
					// WooCommerce puts the units back on its own, and the reseller's debt has to go
					// even if a payment had already been allocated to it.
					'skip_stock' => true,
					'force'      => true,
				)
			);
		}

		MJERP_Sales_Repo::void( (int) $sale['id'], '' !== $reason ? $reason : __( 'Pedido anulado en WooCommerce', 'mj-erp' ) );
		MJERP_Sales_Repo::recalculate( (int) $sale['id'] );

		return true;
	}

	/**
	 * Keeps the mirrored order status in sync.
	 *
	 * @param int    $order_id Order id.
	 * @param string $status   New status.
	 * @return void
	 */
	public static function sync_order_status( $order_id, string $status ) : void {
		$sale = MJERP_Sales_Repo::find_by_order( $order_id );
		if ( $sale ) {
			MJERP_Sales_Repo::update( (int) $sale['id'], array( 'order_status' => $status ) );
		}
	}

	/* ------------------------------------------------------------------
	 * The consignment rule
	 * ---------------------------------------------------------------- */

	/**
	 * The consignee behind a customer, when the rule applies to them.
	 *
	 * @param int $user_id      Customer user id.
	 * @param int $consignee_id Explicit consignee (ERP screen).
	 * @return array|null
	 */
	public static function resolve_consignee( $user_id, $consignee_id = 0 ) {
		if ( ! MJERP_Helpers::bool( MJERP_Settings::get( 'sales_consignment_enabled', true ) ) ) {
			return null;
		}

		$consignee_id = absint( $consignee_id );
		$consignee    = $consignee_id ? MJERP_Consignees::find( $consignee_id ) : null;

		if ( ! $consignee ) {
			$user_id = absint( $user_id );
			if ( ! $user_id ) {
				return null;
			}
			$consignee = MJERP_Consignees::find_by_user( $user_id );
		}

		if ( ! $consignee || empty( $consignee['is_active'] ) ) {
			return null;
		}

		// The per-user / per-role switch has the last word.
		if ( ! MJERP_Permissions::can( 'shop_consignment', (int) $consignee['user_id'], $consignee ) ) {
			return null;
		}

		return $consignee;
	}

	/**
	 * Splits a quantity between what the reseller is holding and the default stock.
	 *
	 * @param array|null $consignee    Consignee row (null = everything comes from stock).
	 * @param int        $product_id   Product id.
	 * @param int        $variation_id Variation id.
	 * @param float      $qty          Quantity to cover.
	 * @param array      $used         Units already taken in this same cart, by consignment item id.
	 * @return array List of parts: { qty, origin, consignee_id, consignment_item_id, unit_price_base }.
	 */
	public static function split_line( $consignee, $product_id, $variation_id, $qty, array &$used ) : array {
		$qty = round( (float) $qty, 4 );
		if ( $qty <= 0 ) {
			return array();
		}

		$stock_part = array(
			array(
				'qty'                 => $qty,
				'origin'              => MJERP_Sales_Repo::ORIGIN_STOCK,
				'consignee_id'        => 0,
				'consignment_item_id' => 0,
				'unit_price_base'     => 0.0,
			),
		);

		if ( ! is_array( $consignee ) ) {
			return $stock_part;
		}

		$available = self::consignment_stock( (int) $consignee['id'], $product_id, $variation_id, $used );
		if ( empty( $available ) ) {
			return $stock_part;
		}

		$total_available = 0.0;
		foreach ( $available as $row ) {
			$total_available += (float) $row['qty_free'];
		}

		$partial_ok = MJERP_Helpers::bool( MJERP_Settings::get( 'sales_consignment_partial_lines', true ) );
		if ( ! $partial_ok && $total_available + 0.0001 < $qty ) {
			// Configured not to split a line: the whole quantity comes out of stock.
			return $stock_part;
		}

		$parts     = array();
		$remaining = $qty;

		foreach ( $available as $row ) {
			if ( $remaining <= 0.0001 ) {
				break;
			}
			$take = min( $remaining, (float) $row['qty_free'] );
			if ( $take <= 0.0001 ) {
				continue;
			}

			$item_id = (int) $row['id'];
			$used[ $item_id ] = ( isset( $used[ $item_id ] ) ? (float) $used[ $item_id ] : 0.0 ) + $take;

			$parts[] = array(
				'qty'                 => round( $take, 4 ),
				'origin'              => MJERP_Sales_Repo::ORIGIN_CONSIGNMENT,
				'consignee_id'        => (int) $consignee['id'],
				'consignment_item_id' => $item_id,
				'unit_price_base'     => round( (float) $row['unit_price_base'], 6 ),
			);

			$remaining = round( $remaining - $take, 4 );
		}

		if ( $remaining > 0.0001 ) {
			$parts[] = array(
				'qty'                 => $remaining,
				'origin'              => MJERP_Sales_Repo::ORIGIN_STOCK,
				'consignee_id'        => 0,
				'consignment_item_id' => 0,
				'unit_price_base'     => 0.0,
			);
		}

		return empty( $parts ) ? $stock_part : $parts;
	}

	/**
	 * Consignment lines of a product the reseller can still sell, in the configured order.
	 *
	 * @param int   $consignee_id Consignee id.
	 * @param int   $product_id   Product id.
	 * @param int   $variation_id Variation id.
	 * @param array $used         Units already taken in this same cart.
	 * @return array Rows with `qty_free` on top of the consignment item.
	 */
	private static function consignment_stock( $consignee_id, $product_id, $variation_id, array $used ) : array {
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		$rows = array();
		foreach ( MJERP_Consignments_Repo::stock_on_hand( $consignee_id, true ) as $item ) {
			if ( (int) $item['product_id'] !== $product_id ) {
				continue;
			}
			if ( (int) $item['variation_id'] !== $variation_id ) {
				continue;
			}

			$taken = isset( $used[ (int) $item['id'] ] ) ? (float) $used[ (int) $item['id'] ] : 0.0;
			$free  = round( (float) $item['qty_on_hand'] - $taken, 4 );
			if ( $free <= 0.0001 ) {
				continue;
			}

			$item['qty_free'] = $free;
			$rows[]           = $item;
		}

		// `stock_on_hand` comes back oldest delivery first, which is the FIFO order.
		if ( 'lifo' === (string) MJERP_Settings::get( 'sales_consignment_allocation', 'fifo' ) ) {
			$rows = array_reverse( $rows );
		}

		return $rows;
	}

	/**
	 * Writes the consignment sale behind every consignment line of a sale.
	 *
	 * @param int        $sale_id   Sale id.
	 * @param array      $lines     Prepared lines.
	 * @param array|null $consignee Consignee row.
	 * @param array      $context   source, order_id, stock_already_applied.
	 * @return void
	 */
	private static function write_consignment_sales( $sale_id, array $lines, $consignee, array $context ) : void {
		if ( ! is_array( $consignee ) ) {
			return;
		}

		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale ) {
			return;
		}

		$source = isset( $context['source'] ) ? (string) $context['source'] : MJERP_Consignment_Sales_Repo::SOURCE_ADMIN;

		foreach ( MJERP_Sales_Repo::items( $sale_id ) as $item ) {
			if ( MJERP_Sales_Repo::ORIGIN_CONSIGNMENT !== $item['origin'] || ! $item['consignment_item_id'] ) {
				continue;
			}

			$consignment_sale_id = MJERP_Consignment_Sales_Repo::create(
				array(
					'consignment_item_id' => $item['consignment_item_id'],
					'qty'                 => $item['qty'],
					'sale_date'           => $sale['sale_date'],
					'retail_amount'       => $item['line_total'],
					'retail_currency'     => $sale['currency'],
					'note'                => sprintf(
						/* translators: %s: sale code */
						__( 'Venta %s', 'mj-erp' ),
						$sale['code']
					),
				),
				$source,
				array(
					'order_id'              => isset( $context['order_id'] ) ? absint( $context['order_id'] ) : (int) $sale['order_id'],
					'order_item_id'         => (int) $item['order_item_id'],
					'sale_id'               => (int) $sale_id,
					'stock_already_applied' => ! empty( $context['stock_already_applied'] ),
				)
			);

			if ( ! is_wp_error( $consignment_sale_id ) ) {
				MJERP_Sales_Repo::link_consignment_sale( (int) $item['id'], (int) $consignment_sale_id );
			}
		}

		MJERP_Sales_Repo::recalculate( $sale_id );
	}

	/* ------------------------------------------------------------------
	 * Lines
	 * ---------------------------------------------------------------- */

	/**
	 * Turns the raw items of an ERP sale into stored lines, splitting each against the consignment.
	 *
	 * @param array      $raw_items  Items from the request.
	 * @param array|null $consignee  Consignee row.
	 * @param string     $currency   Sale currency.
	 * @param float      $rate       Exchange rate.
	 * @param string     $price_list Price list used for the suggested price.
	 * @return array|WP_Error
	 */
	private static function prepare_lines( array $raw_items, $consignee, string $currency, float $rate, string $price_list ) {
		$allow_oversell = MJERP_Helpers::bool( MJERP_Settings::get( 'sales_allow_oversell', false ) );
		$allow_discount = MJERP_Helpers::bool( MJERP_Settings::get( 'sales_allow_discount', true ) );

		$lines = array();
		$used  = array();
		$sort  = 0;

		foreach ( $raw_items as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$product_id   = absint( MJERP_Helpers::array_get( $raw, 'product_id', 0 ) );
			$variation_id = absint( MJERP_Helpers::array_get( $raw, 'variation_id', 0 ) );
			$qty          = round( MJERP_Helpers::to_float( MJERP_Helpers::array_get( $raw, 'qty', 0 ) ), 4 );

			if ( $qty <= 0 ) {
				continue;
			}

			$product = self::product( $product_id, $variation_id );
			if ( ! $product && ! $product_id ) {
				continue;
			}

			if ( ! $allow_oversell && $product instanceof WC_Product ) {
				$check = self::stock_check( $product, $qty );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}

			$name = MJERP_Helpers::text( MJERP_Helpers::array_get( $raw, 'name', '' ), 255 );
			if ( '' === $name && $product instanceof WC_Product ) {
				$name = (string) $product->get_name();
			}
			$sku = $product instanceof WC_Product ? (string) $product->get_sku() : '';

			$unit_price = MJERP_Helpers::array_get( $raw, 'unit_price', null );
			if ( null === $unit_price || '' === $unit_price ) {
				$unit_price = self::suggested_unit_price( $product, $product_id, $variation_id, $price_list, $rate );
			}
			$unit_price = round( MJERP_Helpers::to_float( $unit_price ), 6 );
			if ( $unit_price < 0 ) {
				return new WP_Error( 'mjerp_invalid_price', __( 'El precio no puede ser negativo.', 'mj-erp' ), array( 'status' => 400, 'field' => 'unit_price' ) );
			}

			$discount = $allow_discount ? round( max( 0, MJERP_Helpers::to_float( MJERP_Helpers::array_get( $raw, 'discount', 0 ) ) ), 4 ) : 0.0;
			$gross    = round( $qty * $unit_price, 4 );
			if ( $discount > $gross ) {
				$discount = $gross;
			}

			$split = self::split_line( $consignee, $product_id, $variation_id, $qty, $used );

			foreach ( $split as $part ) {
				$part_qty = (float) $part['qty'];
				$share    = $qty > 0 ? $part_qty / $qty : 0.0;

				$lines[] = self::build_line(
					array(
						'product_id'             => $product_id,
						'variation_id'           => $variation_id,
						'name'                   => $name,
						'sku'                    => $sku,
						'qty'                    => $part_qty,
						'unit_price'             => $unit_price,
						'discount'               => round( $discount * $share, 4 ),
						'line_total'             => round( ( $part_qty * $unit_price ) - ( $discount * $share ), 4 ),
						'origin'                 => $part['origin'],
						'consignee_id'           => $part['consignee_id'],
						'consignment_item_id'    => $part['consignment_item_id'],
						'consignment_price_base' => $part['unit_price_base'],
						'sort_order'             => $sort,
					)
				);
				$sort++;
			}
		}

		return $lines;
	}

	/**
	 * Completes a line with its frozen landed cost.
	 *
	 * @param array $line Line fields.
	 * @return array
	 */
	private static function build_line( array $line ) : array {
		$line['unit_cost_base'] = MJERP_Helpers::bool( MJERP_Settings::get( 'sales_track_cost', true ) )
			? self::unit_cost_base( (int) $line['product_id'], (int) $line['variation_id'] )
			: 0.0;

		return $line;
	}

	/**
	 * Landed cost of a product in the base currency (0 when it was never costed).
	 *
	 * Memoised: a cart with the same product on several lines would otherwise load it once per line.
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id.
	 * @return float
	 */
	public static function unit_cost_base( $product_id, $variation_id = 0 ) : float {
		static $costs = array();

		$target = absint( $variation_id ) ? absint( $variation_id ) : absint( $product_id );
		if ( ! $target ) {
			return 0.0;
		}
		if ( array_key_exists( $target, $costs ) ) {
			return $costs[ $target ];
		}

		$fields = MJERP_Products::cost_fields( $target );
		$cost   = isset( $fields['cost_base'] ) && null !== $fields['cost_base'] ? (float) $fields['cost_base'] : 0.0;

		$costs[ $target ] = round( max( 0, $cost ), 6 );
		return $costs[ $target ];
	}

	/**
	 * Price suggested for a line in the sale currency.
	 *
	 * With a price list configured the ERP price wins (that is the point of having lists); without
	 * one it is whatever the shop is charging, so a counter sale matches the shelf.
	 *
	 * @param WC_Product|false $product      Product.
	 * @param int              $product_id   Product id.
	 * @param int              $variation_id Variation id.
	 * @param string           $price_list   Price list key.
	 * @param float            $rate         Exchange rate.
	 * @return float
	 */
	public static function suggested_unit_price( $product, $product_id, $variation_id, string $price_list, float $rate ) : float {
		$target = absint( $variation_id ) ? absint( $variation_id ) : absint( $product_id );

		if ( '' !== $price_list && $target ) {
			$suggested = MJERP_Consignees::suggested_price( $target, null, $price_list );
			$base      = (float) $suggested['unit_price_base'];
			if ( $base > 0 ) {
				return self::round_sale_price( self::base_to_sale_currency( $base, $rate ) );
			}
		}

		if ( $product instanceof WC_Product ) {
			return self::round_sale_price( MJERP_Helpers::to_float( $product->get_price() ) );
		}

		return 0.0;
	}

	/**
	 * Converts a base-currency price into the sale currency the way the shop converts it.
	 *
	 * La cotización que manda es la de MJ Dólar, que es la que la tienda usó para publicar; el tipo
	 * de cambio de la venta queda como red de contención para cuando MJ Dólar no está.
	 *
	 * @param float $base Price in base currency.
	 * @param float $rate Sale exchange rate (local units per base unit).
	 * @return float
	 */
	public static function base_to_sale_currency( float $base, float $rate ) : float {
		$base_currency = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
		$sale_currency = MJERP_Sales_Repo::shop_currency();

		if ( $sale_currency === $base_currency ) {
			return round( $base, 6 );
		}

		$cfg        = self::store_price_config();
		$store_rate = $cfg['store_rate'] > 0 ? $cfg['store_rate'] : ( $rate > 0 ? $rate : 1.0 );

		return round( $base * $store_rate, 6 );
	}

	/**
	 * Rounds a suggested price the way `sales_price_rounding_mode` says.
	 *
	 * El default (`tienda`) usa la misma escalera con la que MJ Dólar publica en la vidriera, así
	 * lo que el mostrador ve escrito es lo que paga quien compra online. Cambiarlo es configuración,
	 * no código: `erp` usa el paso de `price_rounding`, `personalizado` el de
	 * `sales_price_rounding`, y `ninguno` deja los centavos.
	 *
	 * Solo toca el precio que el ERP *sugiere*: un precio escrito a mano se respeta tal cual.
	 *
	 * @param float $value Price in the sale currency.
	 * @return float
	 */
	public static function round_sale_price( float $value ) : float {
		if ( $value <= 0 ) {
			return 0.0;
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$mode     = sanitize_key( (string) MJERP_Settings::get( 'sales_price_rounding_mode', 'tienda' ) );

		switch ( $mode ) {
			case 'ninguno':
				$rounded = round( $value, $decimals );
				break;

			case 'erp':
				$rounded = MJERP_Costing::round_price( $value, (int) MJERP_Settings::get( 'price_rounding', 0 ) );
				break;

			case 'personalizado':
				$rounded = MJERP_Costing::round_price( $value, (int) MJERP_Settings::get( 'sales_price_rounding', 0 ) );
				break;

			case 'tienda':
			default:
				$cfg           = self::store_price_config();
				$base_currency = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
				$rounded       = MJERP_Sales_Repo::shop_currency() === $base_currency
					? MJERP_Costing::round_usd( $value, $cfg['rounding'], $cfg['mode'] )
					: MJERP_Costing::round_ars( $value, $cfg['store_rounding'], $cfg['store_mode'] );
				break;
		}

		/**
		 * Filters the rounded price the ERP suggests for a sale line.
		 *
		 * @param float  $rounded Rounded price.
		 * @param float  $value   Price before rounding.
		 * @param string $mode    Rounding mode.
		 */
		$rounded = (float) apply_filters( 'mjerp_sale_price_rounded', $rounded, $value, $mode );

		return round( $rounded, $decimals );
	}

	/**
	 * The rounding ladder the storefront publishes with.
	 *
	 * @return array { rounding, mode, store_rate, store_rounding, store_mode }
	 */
	public static function store_price_config() : array {
		if ( class_exists( 'MJERP_Dolar' ) ) {
			return MJERP_Costing::usd_config( MJERP_Dolar::usd_config() );
		}

		// Sin MJ Dólar la escalera es la que el propio ERP tiene configurada.
		return MJERP_Costing::usd_config(
			array(
				'rounding'       => MJERP_Settings::get( 'usd_rounding', 0 ),
				'mode'           => MJERP_Settings::get( 'usd_rounding_mode', 'nearest' ),
				'store_rate'     => MJERP_Settings::get( 'sale_rate', 0 ),
				'store_rounding' => MJERP_Settings::get( 'price_rounding', 0 ),
				'store_mode'     => 'nearest',
			)
		);
	}

	/**
	 * Refuses a line that would leave the shop with negative stock.
	 *
	 * Units the buyer is holding on consignment do not count against the shop's stock here: those
	 * are already in their hands, and the order will discount them from the same pool anyway.
	 *
	 * @param WC_Product $product Product.
	 * @param float      $qty     Quantity.
	 * @return true|WP_Error
	 */
	private static function stock_check( WC_Product $product, float $qty ) {
		$holder = MJERP_Products::stock_holder( $product );
		if ( ! $holder->managing_stock() ) {
			return true;
		}

		$available = MJERP_Helpers::to_float( $holder->get_stock_quantity() );
		if ( $available + 0.0001 >= $qty ) {
			return true;
		}

		if ( $holder->backorders_allowed() ) {
			return true;
		}

		return new WP_Error(
			'mjerp_no_stock',
			sprintf(
				/* translators: 1: product name, 2: available units */
				__( 'No hay stock suficiente de %1$s: quedan %2$s unidades.', 'mj-erp' ),
				$product->get_name(),
				rtrim( rtrim( number_format( $available, 4, '.', '' ), '0' ), '.' )
			),
			array(
				'status'     => 409,
				'field'      => 'items',
				'product_id' => $product->get_id(),
				'available'  => $available,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Stock (only when no order carries it)
	 * ---------------------------------------------------------------- */

	/**
	 * Takes the sold units out of WooCommerce for a sale that has no order behind it.
	 *
	 * Consignment lines are skipped: those units already left the shop's shelf as far as the
	 * reseller's account is concerned, and the consignment sale handles them.
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	public static function apply_stock( $sale_id ) : void {
		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale || $sale['stock_applied'] ) {
			return;
		}
		if ( 'nunca' === (string) MJERP_Settings::get( 'sales_reduce_stock', 'auto' ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_update_product_stock' ) ) {
			return;
		}

		$moved = false;

		foreach ( MJERP_Sales_Repo::items( $sale_id ) as $item ) {
			if ( MJERP_Sales_Repo::ORIGIN_CONSIGNMENT === $item['origin'] ) {
				continue;
			}

			$target  = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
			$product = $target ? wc_get_product( $target ) : false;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$holder = MJERP_Products::stock_holder( $product );
			if ( ! $holder->managing_stock() ) {
				continue;
			}

			$qty = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $item['qty'] ) : (int) $item['qty'];
			if ( $qty <= 0 ) {
				continue;
			}

			try {
				$before = MJERP_Helpers::to_float( $holder->get_stock_quantity() );
				$after  = wc_update_product_stock( $product, $qty, 'decrease' );
				if ( false === $after || null === $after ) {
					continue;
				}

				MJERP_Movements_Repo::insert(
					array(
						'product_id'   => (int) $item['product_id'],
						'variation_id' => (int) $item['variation_id'],
						'qty'          => -1 * (float) $qty,
						'type'         => 'sale',
						'ref_type'     => 'sale',
						'ref_id'       => (int) $sale_id,
						'stock_before' => $before,
						'stock_after'  => (float) $after,
						'note'         => sprintf(
							/* translators: %s: sale code */
							__( 'Venta %s', 'mj-erp' ),
							$sale['code']
						),
						'user_id'      => get_current_user_id(),
					)
				);
				$moved = true;
			} catch ( Throwable $e ) {
				continue;
			}
		}

		if ( $moved ) {
			self::mark_stock_applied( $sale_id, true );
		}
	}

	/**
	 * Puts back the stock a sale without an order had taken out.
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	public static function revert_stock( $sale_id ) : void {
		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale || ! $sale['stock_applied'] || $sale['order_id'] ) {
			// An order's stock belongs to WooCommerce; it restocks on its own.
			return;
		}
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_update_product_stock' ) ) {
			return;
		}

		foreach ( MJERP_Sales_Repo::items( $sale['id'] ) as $item ) {
			if ( MJERP_Sales_Repo::ORIGIN_CONSIGNMENT === $item['origin'] ) {
				continue;
			}

			$target  = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
			$product = $target ? wc_get_product( $target ) : false;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$holder = MJERP_Products::stock_holder( $product );
			if ( ! $holder->managing_stock() ) {
				continue;
			}

			$qty = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $item['qty'] ) : (int) $item['qty'];
			if ( $qty <= 0 ) {
				continue;
			}

			try {
				$before = MJERP_Helpers::to_float( $holder->get_stock_quantity() );
				$after  = wc_update_product_stock( $product, $qty, 'increase' );
				if ( false === $after || null === $after ) {
					continue;
				}

				MJERP_Movements_Repo::insert(
					array(
						'product_id'   => (int) $item['product_id'],
						'variation_id' => (int) $item['variation_id'],
						'qty'          => (float) $qty,
						'type'         => 'sale_revert',
						'ref_type'     => 'sale',
						'ref_id'       => (int) $sale['id'],
						'stock_before' => $before,
						'stock_after'  => (float) $after,
						'note'         => sprintf(
							/* translators: %s: sale code */
							__( 'Anulación de la venta %s', 'mj-erp' ),
							$sale['code']
						),
						'user_id'      => get_current_user_id(),
					)
				);
			} catch ( Throwable $e ) {
				continue;
			}
		}

		self::mark_stock_applied( (int) $sale['id'], false );
	}

	/**
	 * Voids a sale registered in the ERP, unwinding its stock and reseller debt.
	 *
	 * @param int    $sale_id Sale id.
	 * @param string $reason  Reason.
	 * @return true|WP_Error
	 */
	public static function void_sale( $sale_id, string $reason = '' ) {
		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale ) {
			return new WP_Error( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		if ( MJERP_Sales_Repo::STATUS_VOID === $sale['status'] ) {
			return true;
		}

		foreach ( MJERP_Sales_Repo::items( $sale_id ) as $item ) {
			if ( ! $item['consignment_sale_id'] ) {
				continue;
			}
			MJERP_Consignment_Sales_Repo::delete(
				(int) $item['consignment_sale_id'],
				array(
					// The units go back through the sale's own stock reversal (or through the order).
					'skip_stock' => true,
					'force'      => true,
				)
			);
		}

		self::revert_stock( $sale_id );

		if ( $sale['order_id'] ) {
			MJERP_Orders::cancel_order_for_sale( (int) $sale['order_id'] );
		}

		return MJERP_Sales_Repo::void( $sale_id, $reason );
	}

	/**
	 * Flips the `stock_applied` flag of a sale.
	 *
	 * @param int  $sale_id Sale id.
	 * @param bool $applied Whether stock is out.
	 * @return void
	 */
	private static function mark_stock_applied( $sale_id, bool $applied ) : void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MJERP_Install::table( 'sales' ),
			array(
				'stock_applied' => $applied ? 1 : 0,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => absint( $sale_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/* ------------------------------------------------------------------
	 * Payments
	 * ---------------------------------------------------------------- */

	/**
	 * Whether an order that is already paid should register its payment in the ERP.
	 *
	 * @return bool
	 */
	private static function should_capture_payment() : bool {
		return MJERP_Helpers::bool( MJERP_Settings::get( 'sales_capture_payment', true ) );
	}

	/**
	 * Records the collection of a paid order.
	 *
	 * @param int      $sale_id Sale id.
	 * @param WC_Order $order   Order.
	 * @return void
	 */
	private static function register_order_payment( $sale_id, WC_Order $order ) : void {
		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale ) {
			return;
		}

		$due = MJERP_Sales_Repo::amount_due( $sale );
		if ( $due <= 0.0001 ) {
			return;
		}

		MJERP_Sale_Payments_Repo::create(
			array(
				'sale_id'      => $sale_id,
				'amount'       => $due,
				'currency'     => $sale['currency'],
				'rate'         => $sale['rate'],
				'method'       => self::method_from_order( $order ),
				// cashbox_id omitted on purpose: the repo puts it in the box its method points at.
				'reference'    => (string) $order->get_transaction_id(),
				'payment_date' => $sale['sale_date'],
				'status'       => MJERP_Sale_Payments_Repo::STATUS_CONFIRMED,
				'source'       => MJERP_Sale_Payments_Repo::SOURCE_SHOP,
				'note'         => (string) $order->get_payment_method_title(),
			)
		);
	}

	/**
	 * Registers the collection that came with an ERP sale, when there was one.
	 *
	 * @param int   $sale_id Sale id.
	 * @param array $data    Request data.
	 * @return void
	 */
	private static function register_initial_payment( $sale_id, array $data ) : void {
		$payment = MJERP_Helpers::array_get( $data, 'payment', null );
		if ( ! is_array( $payment ) ) {
			return;
		}

		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale ) {
			return;
		}

		$amount = MJERP_Helpers::array_get( $payment, 'amount', null );
		if ( null === $amount || '' === $amount ) {
			// "Cobrado" without an amount means the whole collectable part of the sale.
			$amount = MJERP_Sales_Repo::amount_due( $sale );
		}
		$amount = round( MJERP_Helpers::to_float( $amount ), 4 );
		if ( $amount <= 0 ) {
			return;
		}

		$method = (string) MJERP_Helpers::array_get( $payment, 'method', $sale['payment_method'] );

		/*
		 * The collection inherits the box chosen on the sale only while it is the same method. Pay
		 * a sale marked "transferencia" in physical dollars and the money belongs to the dollar
		 * box, not to the one the sale header happened to carry.
		 */
		$cashbox = MJERP_Helpers::array_get( $payment, 'cashbox_id', 0 );
		if ( ! absint( $cashbox ) && sanitize_key( $method ) === sanitize_key( (string) $sale['payment_method'] ) ) {
			$cashbox = (int) $sale['cashbox_id'];
		}

		MJERP_Sale_Payments_Repo::create(
			array(
				'sale_id'      => $sale_id,
				'amount'       => $amount,
				'currency'     => MJERP_Helpers::array_get( $payment, 'currency', $sale['currency'] ),
				'rate'         => MJERP_Helpers::array_get( $payment, 'rate', $sale['rate'] ),
				'method'       => $method,
				'cashbox_id'   => $cashbox,
				'reference'    => MJERP_Helpers::array_get( $payment, 'reference', '' ),
				'note'         => MJERP_Helpers::array_get( $payment, 'note', '' ),
				'payment_date' => MJERP_Helpers::array_get( $payment, 'payment_date', $sale['sale_date'] ),
				'status'       => MJERP_Sale_Payments_Repo::STATUS_CONFIRMED,
				'source'       => MJERP_Sale_Payments_Repo::SOURCE_ADMIN,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

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

	/**
	 * Resolves who the sale is for.
	 *
	 * Hay dos identidades y no son la misma cosa: la **ficha** (`customer_id`), que es la libreta
	 * del ERP y existe aunque la persona nunca haya entrado a la tienda, y la **cuenta**
	 * (`customer_user_id`), que es el usuario de WooCommerce. Una venta de mostrador tiene ficha y
	 * no tiene cuenta; el día que esa persona se registra y alguien las vincula, la ficha empieza a
	 * traer la cuenta consigo y el historial pasa a ser uno solo.
	 *
	 * Cuando llega una cuenta sin ficha se le abre una (si la libreta está activa), así ninguna
	 * venta queda sin dueño y el revendedor sigue resolviéndose por la cuenta, como siempre.
	 *
	 * @param array $data   Request data (customer_id, customer_user_id, customer_name…).
	 * @param bool  $create Whether a missing card may be created (false en la vista previa, que no
	 *                      tiene que escribir nada).
	 * @return array|WP_Error { id, user_id, name, email, phone, price_list_key, card }
	 */
	public static function resolve_customer( array $data, bool $create = true ) {
		$card_id = absint( MJERP_Helpers::array_get( $data, 'customer_id', 0 ) );
		$user_id = absint( MJERP_Helpers::array_get( $data, 'customer_user_id', 0 ) );
		$enabled = MJERP_Settings::customers_enabled() && class_exists( 'MJERP_Customers_Repo' );

		$card = null;

		if ( $enabled && $card_id ) {
			$card = MJERP_Customers_Repo::find( $card_id, false );
			if ( ! $card ) {
				return new WP_Error( 'mjerp_invalid_customer', __( 'El cliente elegido no existe.', 'mj-erp' ), array( 'status' => 400, 'field' => 'customer_id' ) );
			}
			// La cuenta sale de la ficha: es la vinculación la que decide, no lo que mande la pantalla.
			if ( ! empty( $card['user_id'] ) ) {
				$user_id = (int) $card['user_id'];
			}
		} elseif ( $enabled && $user_id ) {
			$card = MJERP_Customers_Repo::find_by_user( $user_id, false );
			if ( ! $card && $create ) {
				$card = MJERP_Customers_Repo::ensure_for_user( $user_id, self::customer_fields( $user_id, $data ) );
			}
		}

		if ( $enabled && ! $card && ! $user_id && $create && MJERP_Helpers::bool( MJERP_Settings::get( 'customers_require_on_sale', false ) ) ) {
			return new WP_Error( 'mjerp_customer_required', __( 'Elegí un cliente para la venta.', 'mj-erp' ), array( 'status' => 400, 'field' => 'customer_id' ) );
		}

		$fields = self::customer_fields( $user_id, $data );

		// Lo que se escribió en la venta gana; lo que falte lo completa la ficha.
		if ( $card ) {
			foreach ( array( 'name', 'email', 'phone' ) as $key ) {
				if ( '' === (string) $fields[ $key ] ) {
					$fields[ $key ] = (string) MJERP_Helpers::array_get( $card, $key, '' );
				}
			}
		}

		return array(
			'id'             => $card ? (int) $card['id'] : 0,
			'user_id'        => $user_id,
			'name'           => $fields['name'],
			'email'          => $fields['email'],
			'phone'          => $fields['phone'],
			'price_list_key' => $card ? sanitize_key( (string) MJERP_Helpers::array_get( $card, 'price_list_key', '' ) ) : '',
			'card'           => $card,
		);
	}

	/**
	 * The customer card behind a WooCommerce order.
	 *
	 * Un pedido de invitado no tiene cuenta, así que tampoco tiene con qué reconocer a nadie: se
	 * abre una ficha con los datos de facturación solo si la libreta puede reconocer a esa persona
	 * (`customers_link_match`) o, si no la reconoce, una nueva. Un pedido sin nombre ni contacto no
	 * genera ficha: sería una ficha vacía por cada compra anónima.
	 *
	 * @param WC_Order $order    Order.
	 * @param int      $user_id  Customer account id (0 = invitado).
	 * @param string   $name     Billing name already resolved by the caller.
	 * @return array|null
	 */
	protected static function customer_card_for_order( WC_Order $order, int $user_id, string $name ) : ?array {
		if ( ! MJERP_Settings::customers_enabled() || ! class_exists( 'MJERP_Customers_Repo' ) ) {
			return null;
		}

		$fields = array(
			'name'     => MJERP_Helpers::text( $name, 190 ),
			'company'  => MJERP_Helpers::text( $order->get_billing_company(), 190 ),
			'email'    => sanitize_email( (string) $order->get_billing_email() ),
			'phone'    => MJERP_Helpers::text( $order->get_billing_phone(), 60 ),
			'address'  => MJERP_Helpers::text( $order->get_billing_address_1(), 255 ),
			'city'     => MJERP_Helpers::text( $order->get_billing_city(), 120 ),
			'province' => MJERP_Helpers::text( $order->get_billing_state(), 120 ),
			'postcode' => MJERP_Helpers::text( $order->get_billing_postcode(), 30 ),
			'channel'  => sanitize_key( (string) MJERP_Settings::get( 'sales_shop_channel', 'tienda' ) ),
		);

		if ( $user_id ) {
			return MJERP_Customers_Repo::ensure_for_user( $user_id, $fields );
		}

		if ( '' === $fields['name'] || ( '' === $fields['email'] && '' === $fields['phone'] ) ) {
			return null;
		}

		$candidates = MJERP_Customers_Repo::find_candidates( $fields, 1 );
		if ( ! empty( $candidates ) ) {
			return $candidates[0];
		}

		$id = MJERP_Customers_Repo::create( $fields );

		return is_wp_error( $id ) ? null : MJERP_Customers_Repo::find( $id, false );
	}

	/**
	 * Customer name/email/phone, filled in from the account when not supplied.
	 *
	 * @param int   $user_id User id.
	 * @param array $data    Request data.
	 * @return array
	 */
	private static function customer_fields( $user_id, array $data ) : array {
		$name  = MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'customer_name', '' ), 190 );
		$email = sanitize_email( (string) MJERP_Helpers::array_get( $data, 'customer_email', '' ) );
		$phone = MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'customer_phone', '' ), 60 );

		$user_id = absint( $user_id );
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof WP_User ) {
				if ( '' === $name ) {
					$name = MJERP_Helpers::user_display_name( $user_id );
				}
				if ( '' === $email ) {
					$email = (string) $user->user_email;
				}
				if ( '' === $phone ) {
					$phone = MJERP_Helpers::text( get_user_meta( $user_id, 'billing_phone', true ), 60 );
				}
			}
		}

		return array(
			'name'  => $name,
			'email' => $email,
			'phone' => $phone,
		);
	}

	/**
	 * Payment method key of an order, mapped onto the configured methods.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function method_from_order( WC_Order $order ) : string {
		$gateway = sanitize_key( (string) $order->get_payment_method() );
		$keys    = wp_list_pluck( MJERP_Settings::sale_payment_methods(), 'key' );

		if ( in_array( $gateway, $keys, true ) ) {
			return $gateway;
		}

		/**
		 * Filters how a WooCommerce gateway maps to an ERP payment method.
		 *
		 * @param string   $method  Method key ('' = fall back to the default).
		 * @param string   $gateway Gateway id.
		 * @param WC_Order $order   Order.
		 */
		$mapped = sanitize_key( (string) apply_filters( 'mjerp_sale_method_from_gateway', '', $gateway, $order ) );
		if ( '' !== $mapped && in_array( $mapped, $keys, true ) ) {
			return $mapped;
		}

		return MJERP_Sale_Payments_Repo::sanitize_method( '' );
	}

	/**
	 * Date of an order in the site timezone.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function order_date( WC_Order $order ) : string {
		$created = $order->get_date_created();
		if ( $created && is_callable( array( $created, 'date' ) ) ) {
			return $created->date( 'Y-m-d' );
		}
		return current_time( 'Y-m-d' );
	}
}
