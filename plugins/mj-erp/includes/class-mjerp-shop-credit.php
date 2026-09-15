<?php
/**
 * Saldo a favor en la tienda: el cliente lo ve en «Mi cuenta» y lo usa al pagar.
 *
 * De punta a punta:
 *
 *   - **En el carrito** el saldo es una línea de descuento con el nombre del saldo, que nunca pasa del
 *     total (productos, envío y ajustes). El cliente elige usarlo o no, y cuánto; la tienda lo manda por
 *     la Store API con POST /cart/extensions {namespace: "mjerp_credit", data: {use, amount}}.
 *   - **Al confirmar** se vuelve a controlar que alcance y se toma del saldo: primero de la moneda del
 *     pedido y después de las otras, al dólar de hoy. Queda anotado en el pedido.
 *   - **En el ERP** lo tomado entra a la venta del pedido como cobros con saldo a favor, antes que el de
 *     la pasarela: así la pasarela cobra solo lo que falta.
 *   - **Si el pedido se cancela** (o se anula su venta) el saldo vuelve, una sola vez.
 *
 * Solo para quien tiene cuenta y ficha vinculada: el saldo es de la ficha del cliente.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Shop_Credit
 */
class MJERP_Shop_Credit {

	const NAMESPACE_KEY  = 'mjerp_credit';
	const SESSION_KEY    = 'mjerp_credit';
	const FEE_ID         = 'mjerp-credit';
	const ORDER_AMOUNT   = '_mjerp_credit_amount';
	const ORDER_CUSTOMER = '_mjerp_credit_customer';
	const ORDER_USES     = '_mjerp_credit_uses';
	const ORDER_TAKEN    = '_mjerp_credit_taken';
	const EPSILON        = 0.005;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() : void {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fee' ), 99, 1 );

		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'stamp_order' ), 20, 1 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'stamp_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_checkout' ), 20, 2 );

		// Antes que MJERP_Orders (prioridad 20), que con el pedido arma la venta.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'take_for_order' ), 5, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_classic_checkout' ), 5, 3 );

		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ), 10, 1 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'on_cancelled' ), 10, 1 );

		// «Mi cuenta» del tema MJ Appearance.
		add_filter( 'mj_rest_account', array( __CLASS__, 'account_data' ), 10, 2 );

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api' ) );
		}
	}

	/**
	 * Whether customers see and use their credit in the shop.
	 *
	 * @return bool
	 */
	public static function enabled() : bool {
		return function_exists( 'WC' )
			&& MJERP_Credit::enabled()
			&& MJERP_Helpers::bool( MJERP_Settings::get( 'credit_shop_enabled', true ) );
	}

	/**
	 * The customer card linked to an account (0 when there is none).
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function customer_for_user( $user_id ) : int {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 0;
		}
		$card = MJERP_Customers_Repo::find_by_user( $user_id );
		return $card ? (int) $card['id'] : 0;
	}

	/* ------------------------------------------------------------------
	 * Store API
	 * ---------------------------------------------------------------- */

	/**
	 * Registers the cart extension: the data the shop reads and the callback it writes to.
	 *
	 * @return void
	 */
	public static function register_store_api() : void {
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NAMESPACE_KEY,
					'callback'  => array( __CLASS__, 'update_callback' ),
				)
			);
		}
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => 'cart',
					'namespace'       => self::NAMESPACE_KEY,
					'data_callback'   => array( __CLASS__, 'cart_data' ),
					'schema_callback' => array( __CLASS__, 'cart_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}
	}

	/**
	 * What the shop sends: whether to use the credit and how much (empty = all it takes).
	 *
	 * @param mixed $data Data.
	 * @return void
	 */
	public static function update_callback( $data ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$data   = is_array( $data ) ? $data : array();
		$amount = null;
		if ( isset( $data['amount'] ) && '' !== $data['amount'] ) {
			$amount = round( max( 0, MJERP_Helpers::to_float( $data['amount'] ) ), 2 );
		}
		WC()->session->set(
			self::SESSION_KEY,
			array(
				'use'    => MJERP_Helpers::bool( isset( $data['use'] ) ? $data['use'] : false ),
				'amount' => $amount > 0 ? $amount : null,
			)
		);
	}

	/**
	 * The credit as the cart shows it.
	 *
	 * @return array
	 */
	public static function cart_data() {
		$shop = MJERP_Sales_Repo::shop_currency();
		$out  = array(
			'enabled'   => false,
			'label'     => MJERP_Credit::label(),
			'currency'  => $shop,
			'available' => 0.0,
			'balances'  => array(),
			'use'       => false,
			'applied'   => 0.0,
			'max'       => 0.0,
		);
		if ( ! self::enabled() || ! is_user_logged_in() || ! WC()->cart ) {
			return $out;
		}
		$customer = self::customer_for_user( get_current_user_id() );
		if ( ! $customer ) {
			return $out;
		}
		$available = MJERP_Credit::total_in( $customer, $shop );
		if ( $available <= self::EPSILON ) {
			return $out;
		}

		$balances = array();
		foreach ( MJERP_Credit::balances( $customer ) as $row ) {
			if ( (float) $row['amount'] > self::EPSILON ) {
				$balances[] = array(
					'currency' => (string) $row['currency'],
					'amount'   => round( (float) $row['amount'], 2 ),
				);
			}
		}

		$applied = self::fee_amount( WC()->cart );
		$choice  = self::choice();

		$out['enabled']   = true;
		$out['available'] = round( $available, 2 );
		$out['balances']  = $balances;
		$out['use']       = $choice['use'];
		$out['applied']   = $applied;
		$out['max']       = round( min( $available, (float) WC()->cart->get_total( 'edit' ) + $applied ), 2 );
		return $out;
	}

	/**
	 * Schema of the cart extension.
	 *
	 * @return array
	 */
	public static function cart_schema() {
		$ro = array(
			'readonly' => true,
			'context'  => array( 'view', 'edit' ),
		);
		return array(
			'enabled'   => array_merge( $ro, array( 'type' => 'boolean', 'description' => 'Si el cliente tiene saldo a favor para usar.' ) ),
			'label'     => array_merge( $ro, array( 'type' => 'string' ) ),
			'currency'  => array_merge( $ro, array( 'type' => 'string' ) ),
			'available' => array_merge( $ro, array( 'type' => 'number', 'description' => 'Todo el saldo, en la moneda de la tienda al dólar de hoy.' ) ),
			'balances'  => array_merge( $ro, array( 'type' => 'array' ) ),
			'use'       => array_merge( $ro, array( 'type' => 'boolean' ) ),
			'applied'   => array_merge( $ro, array( 'type' => 'number', 'description' => 'Lo que se descuenta en este carrito.' ) ),
			'max'       => array_merge( $ro, array( 'type' => 'number' ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Carrito
	 * ---------------------------------------------------------------- */

	/**
	 * The discount line.
	 *
	 * @param mixed $cart Cart.
	 * @return void
	 */
	public static function add_fee( $cart ) {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		$amount = self::amount_for_cart( $cart );
		if ( $amount <= self::EPSILON ) {
			return;
		}
		$cart->fees_api()->add_fee(
			array(
				'id'      => self::FEE_ID,
				'name'    => MJERP_Credit::label(),
				'amount'  => -1 * $amount,
				'taxable' => false,
			)
		);
	}

	/**
	 * What the customer chose in this session.
	 *
	 * @return array { use, amount|null }
	 */
	private static function choice() : array {
		$saved = ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( self::SESSION_KEY ) : null;
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			// Sin elección todavía, manda el ajuste: el saldo viene aplicado o hay que marcarlo.
			'use'    => array_key_exists( 'use', $saved ) ? (bool) $saved['use'] : MJERP_Helpers::bool( MJERP_Settings::get( 'credit_shop_auto', true ) ),
			'amount' => isset( $saved['amount'] ) ? max( 0, (float) $saved['amount'] ) : null,
		);
	}

	/**
	 * How much credit this cart takes: what was asked, never more than there is or than the total.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return float
	 */
	private static function amount_for_cart( WC_Cart $cart ) : float {
		if ( ! self::enabled() || ! is_user_logged_in() ) {
			return 0.0;
		}
		$choice = self::choice();
		if ( ! $choice['use'] ) {
			return 0.0;
		}
		$customer = self::customer_for_user( get_current_user_id() );
		if ( ! $customer ) {
			return 0.0;
		}

		$limit = min( MJERP_Credit::total_in( $customer, MJERP_Sales_Repo::shop_currency() ), self::cart_total( $cart ) );
		if ( null !== $choice['amount'] ) {
			$limit = min( $limit, (float) $choice['amount'] );
		}
		// Hacia abajo, al centavo: redondear para arriba podría pasar del saldo.
		return max( 0.0, floor( $limit * 100 ) / 100 );
	}

	/**
	 * The cart total before the credit line (products, shipping, taxes and the other fees).
	 *
	 * @param WC_Cart $cart Cart.
	 * @return float
	 */
	private static function cart_total( WC_Cart $cart ) : float {
		$total = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax()
			+ (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
		foreach ( $cart->get_fees() as $fee ) {
			if ( isset( $fee->id ) && self::FEE_ID === $fee->id ) {
				continue;
			}
			$total += isset( $fee->amount ) ? (float) $fee->amount : 0.0;
		}
		return max( 0.0, round( $total, 2 ) );
	}

	/**
	 * The credit line already in a calculated cart.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return float
	 */
	private static function fee_amount( WC_Cart $cart ) : float {
		foreach ( $cart->get_fees() as $fee ) {
			if ( isset( $fee->id ) && self::FEE_ID === $fee->id ) {
				return round( abs( (float) $fee->amount ), 2 );
			}
		}
		return 0.0;
	}

	/* ------------------------------------------------------------------
	 * Checkout
	 * ---------------------------------------------------------------- */

	/**
	 * Notes on the order how much credit it uses and whose.
	 *
	 * @param mixed $order Order.
	 * @return void
	 */
	public static function stamp_order( $order ) {
		if ( ! $order instanceof WC_Order || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$amount = self::fee_amount( WC()->cart );
		if ( $amount > self::EPSILON ) {
			$order->update_meta_data( self::ORDER_AMOUNT, (string) $amount );
			$order->update_meta_data( self::ORDER_CUSTOMER, (string) self::customer_for_user( get_current_user_id() ) );
		} else {
			$order->delete_meta_data( self::ORDER_AMOUNT );
			$order->delete_meta_data( self::ORDER_CUSTOMER );
		}
	}

	/**
	 * Refuses confirming an order whose credit no longer covers what it uses.
	 *
	 * @param mixed $order   Order.
	 * @param mixed $request Request (unused).
	 * @return void
	 * @throws Exception When the credit does not cover it.
	 */
	public static function validate_checkout( $order, $request = null ) {
		unset( $request );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$amount = MJERP_Helpers::to_float( $order->get_meta( self::ORDER_AMOUNT, true ) );
		if ( $amount <= self::EPSILON ) {
			return;
		}

		$customer = absint( $order->get_meta( self::ORDER_CUSTOMER, true ) );
		$plan     = $customer ? self::plan( $customer, $amount, strtoupper( (string) $order->get_currency() ), (int) $order->get_id() ) : null;
		if ( is_array( $plan ) ) {
			return;
		}

		$message = __( 'Tu saldo a favor cambió y ya no alcanza para lo que ibas a usar. Revisá el total y volvé a confirmar.', 'mj-erp' );
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'mjerp_credit_changed', $message, 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		throw new Exception( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Classic checkout.
	 *
	 * @param int      $order_id Order id.
	 * @param array    $posted   Posted data (unused).
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public static function on_classic_checkout( $order_id, $posted = array(), $order = null ) {
		unset( $posted );
		self::take_for_order( $order instanceof WC_Order ? $order : $order_id );
	}

	/**
	 * Takes from the balance the credit an order uses.
	 *
	 * Un pedido que se vuelve a confirmar (el pago falló y lo intenta de nuevo) no toma dos veces: si
	 * lo usado cambió, lo de antes vuelve y se toma de nuevo.
	 *
	 * @param WC_Order|int $order Order.
	 * @return void
	 */
	public static function take_for_order( $order ) {
		if ( ! $order instanceof WC_Order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order_id = (int) $order->get_id();
		$amount   = round( MJERP_Helpers::to_float( $order->get_meta( self::ORDER_AMOUNT, true ) ), 2 );
		$taken    = round( MJERP_Helpers::to_float( $order->get_meta( self::ORDER_TAKEN, true ) ), 2 );
		$open     = MJERP_Customer_Credit_Repo::open_uses( 'order_id', $order_id );

		if ( ! empty( $open ) && abs( $amount - $taken ) <= self::EPSILON ) {
			return;
		}
		if ( empty( $open ) && $amount <= self::EPSILON ) {
			return;
		}

		self::give_back( $open, __( 'Se volvió a confirmar el pedido', 'mj-erp' ) );
		$order->delete_meta_data( self::ORDER_USES );
		$order->delete_meta_data( self::ORDER_TAKEN );

		if ( $amount <= self::EPSILON ) {
			$order->save();
			return;
		}

		$customer = absint( $order->get_meta( self::ORDER_CUSTOMER, true ) );
		$plan     = $customer
			? self::plan( $customer, $amount, strtoupper( (string) $order->get_currency() ), 0 )
			: new WP_Error( 'mjerp_credit_no_customer', __( 'el pedido no tiene la ficha del cliente', 'mj-erp' ) );
		if ( is_wp_error( $plan ) ) {
			self::fail( $order, $plan );
			return;
		}

		$uses = array();
		foreach ( $plan as $part ) {
			$entry = MJERP_Credit::record_use(
				$customer,
				$part,
				array(
					'order_id' => $order_id,
					/* translators: %s: order number */
					'note'     => sprintf( __( 'Pedido #%s', 'mj-erp' ), $order->get_order_number() ),
				)
			);
			if ( is_wp_error( $entry ) ) {
				self::give_back( MJERP_Customer_Credit_Repo::open_uses( 'order_id', $order_id ), '' );
				self::fail( $order, $entry );
				return;
			}
			$uses[] = array(
				'entry_id' => (int) $entry,
				'currency' => (string) $part['currency'],
				'amount'   => (float) $part['amount'],
				'value'    => (float) $part['value'],
			);
		}

		$order->update_meta_data( self::ORDER_USES, $uses );
		$order->update_meta_data( self::ORDER_TAKEN, (string) $amount );
		$order->add_order_note(
			/* translators: 1: credit name, 2: amounts per currency */
			sprintf( __( '%1$s usado en este pedido: %2$s.', 'mj-erp' ), MJERP_Credit::label(), self::uses_text( $uses ) )
		);
		$order->save();

		// Si la venta del pedido ya existe, lo tomado entra ya como cobro.
		$sale = MJERP_Sales_Repo::find_by_order( $order_id );
		if ( $sale ) {
			self::attach_to_sale( (int) $sale['id'], $order );
		}
	}

	/**
	 * Registers the credit an order used as collections of its sale (before the gateway's).
	 *
	 * @param int      $sale_id Sale id.
	 * @param WC_Order $order   Order.
	 * @return void
	 */
	public static function attach_to_sale( $sale_id, $order ) : void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$uses = $order->get_meta( self::ORDER_USES, true );
		if ( ! is_array( $uses ) || empty( $uses ) ) {
			return;
		}
		$sale = MJERP_Sales_Repo::find( $sale_id );
		if ( ! $sale || MJERP_Sales_Repo::STATUS_VOID === (string) $sale['status'] ) {
			return;
		}

		foreach ( $uses as $use ) {
			$entry = MJERP_Customer_Credit_Repo::find( isset( $use['entry_id'] ) ? (int) $use['entry_id'] : 0 );
			if ( ! $entry || ! empty( $entry['payment_id'] ) || MJERP_Customer_Credit_Repo::is_reversed( (int) $entry['id'] ) ) {
				continue;
			}
			MJERP_Sale_Payments_Repo::create(
				array(
					'sale_id'         => (int) $sale['id'],
					'amount'          => round( (float) $use['value'], 4 ),
					'currency'        => $sale['currency'],
					'rate'            => $sale['rate'],
					'method'          => MJERP_Credit::method_key(),
					'payment_date'    => $sale['sale_date'],
					'status'          => MJERP_Sale_Payments_Repo::STATUS_CONFIRMED,
					'source'          => MJERP_Sale_Payments_Repo::SOURCE_SHOP,
					'credit_entry_id' => (int) $entry['id'],
					/* translators: %s: order number */
					'note'            => sprintf( __( 'Pedido #%s', 'mj-erp' ), $order->get_order_number() ),
				)
			);
		}
	}

	/**
	 * A cancelled or trashed order gives back the credit it used.
	 *
	 * La venta del pedido se anula aparte (MJERP_Orders): acá solo vuelve el saldo, una sola vez.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function on_cancelled( $order_id ) : void {
		MJERP_Credit::reverse_order( absint( $order_id ), __( 'Se canceló el pedido', 'mj-erp' ) );
	}

	/* ------------------------------------------------------------------
	 * Mi cuenta
	 * ---------------------------------------------------------------- */

	/**
	 * Adds the credit to «Mi cuenta» (GET /mj/v1/account of the theme).
	 *
	 * @param mixed $data    Account data.
	 * @param int   $user_id User id.
	 * @return mixed
	 */
	public static function account_data( $data, $user_id = 0 ) {
		if ( ! is_array( $data ) || ! self::enabled() ) {
			return $data;
		}
		$customer = self::customer_for_user( $user_id ? $user_id : get_current_user_id() );
		if ( ! $customer ) {
			return $data;
		}

		$balances = array();
		foreach ( MJERP_Credit::balances( $customer ) as $row ) {
			if ( (float) $row['amount'] > self::EPSILON ) {
				$balances[] = array(
					'currency' => (string) $row['currency'],
					'amount'   => round( (float) $row['amount'], 2 ),
				);
			}
		}

		/**
		 * Filters how many credit movements the customer sees in «Mi cuenta».
		 *
		 * @param int $limit       Max rows.
		 * @param int $customer_id Customer id.
		 */
		$limit     = max( 1, (int) apply_filters( 'mjerp_shop_credit_movements_limit', 20, $customer ) );
		$movements = array();
		foreach ( MJERP_Customer_Credit_Repo::movements( $customer, $limit ) as $entry ) {
			$movements[] = array(
				'id'          => (int) $entry['id'],
				'date'        => str_replace( ' ', 'T', (string) $entry['created_at'] ),
				'description' => MJERP_Customer_Credit_Repo::describe( $entry, true ),
				'amount'      => round( (float) $entry['amount'], 2 ),
				'currency'    => (string) $entry['currency'],
			);
		}

		if ( empty( $balances ) && empty( $movements ) ) {
			return $data;
		}

		$shop           = MJERP_Sales_Repo::shop_currency();
		$data['credit'] = array(
			'label'     => MJERP_Credit::label(),
			'currency'  => $shop,
			'balances'  => $balances,
			'total'     => round( MJERP_Credit::total_in( $customer, $shop ), 2 ),
			'movements' => $movements,
		);
		return $data;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * How an amount comes out of the balances: the order's currency first, the others at today's
	 * dollar.
	 *
	 * @param int    $customer_id Customer id.
	 * @param float  $amount      Amount in the order currency.
	 * @param string $currency    Order currency.
	 * @param int    $order_id    Order whose uses count as still available (0 = none).
	 * @return array|WP_Error [ { currency, amount, value, rate } ]
	 */
	private static function plan( int $customer_id, float $amount, string $currency, int $order_id ) {
		$balances = MJERP_Customer_Credit_Repo::balances( $customer_id );
		if ( $order_id ) {
			foreach ( MJERP_Customer_Credit_Repo::open_uses( 'order_id', $order_id ) as $entry ) {
				$cur              = (string) $entry['currency'];
				$balances[ $cur ] = ( isset( $balances[ $cur ] ) ? (float) $balances[ $cur ] : 0.0 ) - (float) $entry['amount'];
			}
		}

		uksort(
			$balances,
			static function ( $a, $b ) use ( $currency ) {
				if ( (string) $a === $currency ) {
					return -1;
				}
				if ( (string) $b === $currency ) {
					return 1;
				}
				return strcmp( (string) $a, (string) $b );
			}
		);

		$left  = round( $amount, 4 );
		$parts = array();
		foreach ( $balances as $cur => $balance ) {
			$cur     = (string) $cur;
			$balance = (float) $balance;
			if ( $left <= self::EPSILON ) {
				break;
			}
			if ( $balance <= self::EPSILON ) {
				continue;
			}
			$worth   = MJERP_Credit::convert( $balance, $cur, $currency );
			$value   = min( $left, $worth );
			$take    = $cur === $currency ? $value : min( $balance, MJERP_Credit::convert( $value, $currency, $cur ) );
			$parts[] = array(
				'currency' => $cur,
				'amount'   => round( $take, 4 ),
				'value'    => round( $value, 4 ),
				'rate'     => MJERP_Credit::rate_between( $currency, $cur ),
			);
			$left    = round( $left - $value, 4 );
		}

		if ( $left > 0.01 ) {
			return new WP_Error( 'mjerp_credit_insufficient', __( 'no alcanza el saldo a favor', 'mj-erp' ), array( 'status' => 409 ) );
		}
		return $parts;
	}

	/**
	 * Gives back credit uses: deleting their sale collection when they have one (that returns the
	 * credit), or with an opposite movement.
	 *
	 * @param array  $entries Cast uses.
	 * @param string $note    Note.
	 * @return void
	 */
	private static function give_back( array $entries, string $note ) : void {
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['payment_id'] ) && MJERP_Sale_Payments_Repo::find( (int) $entry['payment_id'] ) ) {
				$deleted = MJERP_Sale_Payments_Repo::delete( (int) $entry['payment_id'] );
				if ( ! is_wp_error( $deleted ) ) {
					continue;
				}
			}
			MJERP_Credit::reverse( $entry, $note );
		}
	}

	/**
	 * "$ 5.000 + US$ 20".
	 *
	 * @param array $uses Uses.
	 * @return string
	 */
	private static function uses_text( array $uses ) : string {
		$parts = array();
		foreach ( $uses as $use ) {
			$parts[] = MJERP_Sale_Log::money( $use['amount'], $use['currency'] );
		}
		return implode( ' + ', $parts );
	}

	/**
	 * Leaves word on the order that the credit could not be taken.
	 *
	 * @param WC_Order $order Order.
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private static function fail( WC_Order $order, WP_Error $error ) : void {
		$order->add_order_note(
			/* translators: %s: reason */
			sprintf( __( 'No se pudo descontar el saldo a favor (%s): el total del pedido ya lo descontaba. Revisá el cobro.', 'mj-erp' ), $error->get_error_message() )
		);
		$order->save();

		if ( class_exists( 'MJ_Log' ) ) {
			MJ_Log::error(
				'erp',
				'No se pudo descontar el saldo a favor de un pedido',
				array(
					'pedido' => $order->get_id(),
					'motivo' => $error->get_error_message(),
				)
			);
		}
	}
}
