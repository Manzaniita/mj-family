<?php
/**
 * Saldo a favor de los clientes: las reglas.
 *
 * El libro está en MJERP_Customer_Credit_Repo; acá se decide qué movimiento se puede hacer:
 *
 *   - **Moneda.** El saldo queda en la moneda en que el cliente pagó y no se convierte. Cuando se usa
 *     para pagar algo en otra moneda, se pasa al dólar de ese momento, y ese dólar queda anotado.
 *   - **Nunca en negativo.** Usar, restar o devolver la plata no puede pasar de lo que hay.
 *   - **Se deshace con otro movimiento.** Un cobro con saldo que se borra, una venta que se anula o un
 *     pedido que se cancela devuelven lo usado con un movimiento nuevo, una sola vez.
 *   - **Vinculación.** Una ficha con saldo no se une sola a una cuenta de la tienda: la confirmás vos.
 *
 * Pagar con saldo es un medio de pago más de la venta (clave `saldo_a_favor`), que no entra a ninguna
 * caja: la plata ya estaba adentro.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Credit
 */
class MJERP_Credit {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() : void {
		// Anular una venta devuelve el saldo que se usó para pagarla.
		add_action( 'mjerp_sale_voided', array( __CLASS__, 'on_sale_voided' ), 5, 1 );
	}

	/**
	 * Whether customer credit is on (it needs the customers book).
	 *
	 * @return bool
	 */
	public static function enabled() : bool {
		return MJERP_Settings::customers_enabled() && MJERP_Helpers::bool( MJERP_Settings::get( 'credit_enabled', true ) );
	}

	/**
	 * How the credit is called, in the ERP and in the shop.
	 *
	 * @return string
	 */
	public static function label() : string {
		$label = trim( (string) MJERP_Settings::get( 'credit_label', '' ) );
		return '' !== $label ? $label : __( 'Saldo a favor', 'mj-erp' );
	}

	/**
	 * Payment method key of a collection paid with credit.
	 *
	 * @return string
	 */
	public static function method_key() : string {
		/**
		 * Filters the payment method key used for collections paid with customer credit.
		 *
		 * @param string $key Method key.
		 */
		$key = sanitize_key( (string) apply_filters( 'mjerp_credit_method_key', 'saldo_a_favor' ) );
		return '' !== $key ? $key : 'saldo_a_favor';
	}

	/**
	 * Whether a payment method key is the credit one.
	 *
	 * @param mixed $key Method key.
	 * @return bool
	 */
	public static function is_method( $key ) : bool {
		$key = sanitize_key( (string) $key );
		return '' !== $key && self::method_key() === $key;
	}

	/* ------------------------------------------------------------------
	 * Dinero
	 * ---------------------------------------------------------------- */

	/**
	 * Units of a currency per base unit today (1 for the base currency).
	 *
	 * @param string $currency Currency.
	 * @return float
	 */
	public static function rate_of( string $currency ) : float {
		return MJERP_Sales_Repo::default_rate( strtoupper( $currency ) );
	}

	/**
	 * An amount in another currency, at today's dollar.
	 *
	 * @param float  $amount Amount.
	 * @param string $from   Currency of the amount.
	 * @param string $to     Target currency.
	 * @return float
	 */
	public static function convert( $amount, string $from, string $to ) : float {
		$amount = (float) $amount;
		$from   = strtoupper( $from );
		$to     = strtoupper( $to );
		if ( $from === $to ) {
			return round( $amount, 4 );
		}

		$base    = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
		$in_base = $from === $base ? $amount : $amount / max( 0.000001, self::rate_of( $from ) );
		$out     = $to === $base ? $in_base : $in_base * self::rate_of( $to );

		return round( $out, 4 );
	}

	/**
	 * The rate that turned one currency into the other (the non-base one's), or 0 when they match.
	 *
	 * @param string $from Currency.
	 * @param string $to   Currency.
	 * @return float
	 */
	public static function rate_between( string $from, string $to ) : float {
		$from = strtoupper( $from );
		$to   = strtoupper( $to );
		if ( $from === $to ) {
			return 0.0;
		}
		$base = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
		return self::rate_of( $from === $base ? $to : $from );
	}

	/* ------------------------------------------------------------------
	 * Lectura
	 * ---------------------------------------------------------------- */

	/**
	 * Balance and movements of a customer, as the ERP shows them.
	 *
	 * @param int $customer_id Customer id.
	 * @return array { enabled, label, method, currency, balances[], total_equivalent, movements[] }
	 */
	public static function statement( $customer_id ) : array {
		$shop = MJERP_Sales_Repo::shop_currency();

		return array(
			'enabled'          => self::enabled(),
			'label'            => self::label(),
			'method'           => self::method_key(),
			'currency'         => $shop,
			'balances'         => self::balances( $customer_id ),
			'total_equivalent' => self::total_in( $customer_id, $shop ),
			'movements'        => MJERP_Customer_Credit_Repo::movements( $customer_id ),
		);
	}

	/**
	 * Balances of a customer, the shop currency first, each with its value in the shop currency today.
	 *
	 * @param int $customer_id Customer id.
	 * @return array [ { currency, amount, equivalent } ]
	 */
	public static function balances( $customer_id ) : array {
		$shop = MJERP_Sales_Repo::shop_currency();
		$out  = array();

		foreach ( MJERP_Customer_Credit_Repo::balances( $customer_id ) as $currency => $amount ) {
			$out[] = array(
				'currency'   => $currency,
				'amount'     => $amount,
				'equivalent' => self::convert( $amount, $currency, $shop ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) use ( $shop ) {
				if ( $a['currency'] === $shop ) {
					return -1;
				}
				if ( $b['currency'] === $shop ) {
					return 1;
				}
				return strcmp( $a['currency'], $b['currency'] );
			}
		);

		return $out;
	}

	/**
	 * Everything a customer has in favour, expressed in one currency at today's dollar.
	 *
	 * @param int    $customer_id Customer id.
	 * @param string $currency    Currency.
	 * @return float
	 */
	public static function total_in( $customer_id, string $currency ) : float {
		$total = 0.0;
		foreach ( MJERP_Customer_Credit_Repo::balances( $customer_id ) as $cur => $amount ) {
			if ( $amount > 0 ) {
				$total += self::convert( $amount, $cur, $currency );
			}
		}
		return round( $total, 4 );
	}

	/**
	 * Whether a customer has anything in favour.
	 *
	 * @param int $customer_id Customer id.
	 * @return bool
	 */
	public static function has_balance( $customer_id ) : bool {
		foreach ( MJERP_Customer_Credit_Repo::balances( $customer_id ) as $amount ) {
			if ( $amount > 0.004 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether linking this card to a shop account has to wait for the owner's confirmation.
	 *
	 * Un saldo a favor es plata: unir la ficha con la cuenta equivocada se lo daría a otra persona.
	 *
	 * @param int $customer_id Customer id.
	 * @return bool
	 */
	public static function requires_link_confirmation( $customer_id ) : bool {
		return self::enabled()
			&& MJERP_Helpers::bool( MJERP_Settings::get( 'credit_link_confirm', true ) )
			&& self::has_balance( $customer_id );
	}

	/* ------------------------------------------------------------------
	 * Movimientos
	 * ---------------------------------------------------------------- */

	/**
	 * Adds credit to a customer.
	 *
	 * @param int    $customer_id Customer id.
	 * @param string $currency    Currency.
	 * @param float  $amount      Amount (> 0).
	 * @param string $kind        Movement kind.
	 * @param array  $context     sale_id, order_id, payment_id, return_id, rate, reason, note…
	 * @return int|WP_Error
	 */
	public static function credit( $customer_id, string $currency, $amount, string $kind, array $context = array() ) {
		$amount = round( (float) $amount, 4 );
		if ( $amount <= MJERP_Customer_Credit_Repo::EPSILON ) {
			return new WP_Error( 'mjerp_invalid_amount', __( 'El importe tiene que ser mayor a cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'amount' ) );
		}

		return MJERP_Customer_Credit_Repo::insert(
			array_merge(
				$context,
				array(
					'customer_id' => $customer_id,
					'currency'    => $currency,
					'amount'      => $amount,
					'kind'        => $kind,
				)
			)
		);
	}

	/**
	 * Takes credit from a customer, never below zero.
	 *
	 * @param int    $customer_id Customer id.
	 * @param string $currency    Currency.
	 * @param float  $amount      Amount (> 0).
	 * @param string $kind        Movement kind.
	 * @param array  $context     sale_id, order_id, payment_id, rate, method, cashbox_id, reason, note…
	 * @return int|WP_Error
	 */
	public static function debit( $customer_id, string $currency, $amount, string $kind, array $context = array() ) {
		$amount = round( (float) $amount, 4 );
		if ( $amount <= MJERP_Customer_Credit_Repo::EPSILON ) {
			return new WP_Error( 'mjerp_invalid_amount', __( 'El importe tiene que ser mayor a cero.', 'mj-erp' ), array( 'status' => 400, 'field' => 'amount' ) );
		}

		$available = MJERP_Customer_Credit_Repo::balance( $customer_id, $currency );
		if ( $amount > $available + MJERP_Customer_Credit_Repo::EPSILON ) {
			return self::insufficient( $available, $amount, $currency );
		}

		return MJERP_Customer_Credit_Repo::insert(
			array_merge(
				$context,
				array(
					'customer_id' => $customer_id,
					'currency'    => $currency,
					'amount'      => -1 * $amount,
					'kind'        => $kind,
				)
			)
		);
	}

	/**
	 * A manual adjustment, with its reason.
	 *
	 * @param int    $customer_id Customer id.
	 * @param float  $amount      Signed amount (+ suma, − resta).
	 * @param string $currency    Currency.
	 * @param string $reason      Reason (required).
	 * @return int|WP_Error
	 */
	public static function adjust( $customer_id, $amount, $currency, $reason ) {
		$check = self::check_customer( $customer_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$currency = self::currency( $currency );
		if ( is_wp_error( $currency ) ) {
			return $currency;
		}

		$amount = round( MJERP_Helpers::to_float( $amount ), 4 );
		if ( abs( $amount ) <= MJERP_Customer_Credit_Repo::EPSILON ) {
			return new WP_Error( 'mjerp_invalid_amount', __( 'Escribí cuánto sumar o restar.', 'mj-erp' ), array( 'status' => 400, 'field' => 'amount' ) );
		}

		$reason = MJERP_Helpers::text( $reason, 255 );
		if ( '' === $reason ) {
			return new WP_Error( 'mjerp_reason_required', __( 'Contá el motivo del ajuste: queda en los movimientos del saldo.', 'mj-erp' ), array( 'status' => 400, 'field' => 'reason' ) );
		}

		$context = array( 'reason' => $reason );
		$id      = $amount > 0
			? self::credit( $customer_id, $currency, $amount, MJERP_Customer_Credit_Repo::KIND_ADJUST, $context )
			: self::debit( $customer_id, $currency, -1 * $amount, MJERP_Customer_Credit_Repo::KIND_ADJUST, $context );

		if ( ! is_wp_error( $id ) ) {
			/**
			 * Fires after a manual adjustment of a customer's credit.
			 *
			 * @param int $id          Movement id.
			 * @param int $customer_id Customer id.
			 */
			do_action( 'mjerp_customer_credit_adjusted', $id, (int) $customer_id );
		}

		return $id;
	}

	/**
	 * «Le di la plata»: the credit leaves as money, out of a cashbox.
	 *
	 * @param int    $customer_id Customer id.
	 * @param float  $amount      Amount (> 0).
	 * @param string $currency    Currency.
	 * @param string $method      Payment method it was handed over with.
	 * @param int    $cashbox_id  Cashbox it came out of (0 = the method's).
	 * @param string $reference   Reference.
	 * @param string $reason      Reason.
	 * @return int|WP_Error
	 */
	public static function payout( $customer_id, $amount, $currency, $method, $cashbox_id = 0, $reference = '', $reason = '' ) {
		$check = self::check_customer( $customer_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$currency = self::currency( $currency );
		if ( is_wp_error( $currency ) ) {
			return $currency;
		}

		if ( self::is_method( $method ) ) {
			return new WP_Error( 'mjerp_invalid_method', __( 'Elegí cómo le diste la plata: efectivo, transferencia…', 'mj-erp' ), array( 'status' => 400, 'field' => 'method' ) );
		}
		$method = MJERP_Sale_Payments_Repo::sanitize_method( $method );

		$id = self::debit(
			$customer_id,
			$currency,
			MJERP_Helpers::to_float( $amount ),
			MJERP_Customer_Credit_Repo::KIND_PAYOUT,
			array(
				'method'     => $method,
				'cashbox_id' => MJERP_Sale_Payments_Repo::resolve_cashbox( $cashbox_id, $method ),
				'reference'  => $reference,
				'reason'     => $reason,
			)
		);

		if ( ! is_wp_error( $id ) ) {
			/**
			 * Fires after a customer's credit was handed over as money.
			 *
			 * @param int $id          Movement id.
			 * @param int $customer_id Customer id.
			 */
			do_action( 'mjerp_customer_credit_paid_out', $id, (int) $customer_id );
		}

		return $id;
	}

	/**
	 * Works out what paying `$amount` with credit takes from the balance, without taking it.
	 *
	 * @param int    $customer_id     Customer id.
	 * @param float  $amount          Amount to pay.
	 * @param string $amount_currency Currency of that amount.
	 * @param string $credit_currency Balance to use ('' = the payment's own, or the first that covers it).
	 * @return array|WP_Error { currency, amount, rate }
	 */
	public static function plan_use( $customer_id, $amount, $amount_currency, $credit_currency = '' ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'mjerp_credit_disabled', __( 'El saldo a favor está apagado en Configuración → Clientes.', 'mj-erp' ), array( 'status' => 409 ) );
		}

		$customer_id = absint( $customer_id );
		if ( ! $customer_id ) {
			return new WP_Error( 'mjerp_credit_no_customer', __( 'La venta no tiene cliente: el saldo a favor es de la ficha de un cliente.', 'mj-erp' ), array( 'status' => 400, 'field' => 'method' ) );
		}

		$amount          = round( (float) $amount, 4 );
		$amount_currency = strtoupper( (string) $amount_currency );
		$credit_currency = strtoupper( trim( (string) $credit_currency ) );

		if ( '' === $credit_currency ) {
			$balances        = MJERP_Customer_Credit_Repo::balances( $customer_id );
			$credit_currency = $amount_currency;
			$own             = isset( $balances[ $amount_currency ] ) ? (float) $balances[ $amount_currency ] : 0.0;
			if ( $own + MJERP_Customer_Credit_Repo::EPSILON < $amount ) {
				foreach ( $balances as $currency => $balance ) {
					if ( $balance + MJERP_Customer_Credit_Repo::EPSILON >= self::convert( $amount, $amount_currency, $currency ) ) {
						$credit_currency = $currency;
						break;
					}
				}
			}
		}

		$needed    = self::convert( $amount, $amount_currency, $credit_currency );
		$available = MJERP_Customer_Credit_Repo::balance( $customer_id, $credit_currency );
		if ( $needed > $available + MJERP_Customer_Credit_Repo::EPSILON ) {
			return self::insufficient( $available, $needed, $credit_currency );
		}

		return array(
			'currency' => $credit_currency,
			'amount'   => $needed,
			'rate'     => self::rate_between( $amount_currency, $credit_currency ),
		);
	}

	/**
	 * Takes the planned credit (a collection of a sale, or an order of the shop).
	 *
	 * @param int   $customer_id Customer id.
	 * @param array $plan        From plan_use().
	 * @param array $context     sale_id, payment_id, order_id, note.
	 * @return int|WP_Error
	 */
	public static function record_use( $customer_id, array $plan, array $context = array() ) {
		return self::debit(
			$customer_id,
			(string) $plan['currency'],
			(float) $plan['amount'],
			MJERP_Customer_Credit_Repo::KIND_USE,
			array_merge( $context, array( 'rate' => isset( $plan['rate'] ) ? (float) $plan['rate'] : 0 ) )
		);
	}

	/**
	 * Undoes a movement once, with the opposite one.
	 *
	 * @param array  $entry Cast movement.
	 * @param string $note  Note.
	 * @return int|WP_Error 0 when it was already undone.
	 */
	public static function reverse( array $entry, string $note = '' ) {
		if ( MJERP_Customer_Credit_Repo::is_reversed( (int) $entry['id'] ) ) {
			return 0;
		}

		return MJERP_Customer_Credit_Repo::insert(
			array(
				'customer_id' => (int) $entry['customer_id'],
				'currency'    => (string) $entry['currency'],
				'amount'      => -1 * (float) $entry['amount'],
				'kind'        => MJERP_Customer_Credit_Repo::KIND_REVERSAL,
				'sale_id'     => (int) $entry['sale_id'],
				'order_id'    => (int) $entry['order_id'],
				'payment_id'  => (int) $entry['payment_id'],
				'return_id'   => (int) $entry['return_id'],
				'reverses_id' => (int) $entry['id'],
				'rate'        => (float) $entry['rate'],
				'note'        => $note,
			)
		);
	}

	/**
	 * Gives back the credit used by a sale collection.
	 *
	 * @param int $payment_id Sale payment id.
	 * @return void
	 */
	public static function reverse_payment( $payment_id ) : void {
		foreach ( MJERP_Customer_Credit_Repo::open_uses( 'payment_id', $payment_id ) as $entry ) {
			self::reverse( $entry, __( 'Se borró el cobro', 'mj-erp' ) );
		}
	}

	/**
	 * Gives back every credit used to pay a sale.
	 *
	 * @param int    $sale_id Sale id.
	 * @param string $note    Note.
	 * @return void
	 */
	public static function reverse_sale( $sale_id, string $note = '' ) : void {
		foreach ( MJERP_Customer_Credit_Repo::open_uses( 'sale_id', $sale_id ) as $entry ) {
			self::reverse( $entry, $note );
		}
	}

	/**
	 * Gives back the credit a shop order used.
	 *
	 * @param int    $order_id Order id.
	 * @param string $note     Note.
	 * @return void
	 */
	public static function reverse_order( $order_id, string $note = '' ) : void {
		foreach ( MJERP_Customer_Credit_Repo::open_uses( 'order_id', $order_id ) as $entry ) {
			self::reverse( $entry, $note );
		}
	}

	/**
	 * A voided sale gives back the credit it was paid with.
	 *
	 * @param int $sale_id Sale id.
	 * @return void
	 */
	public static function on_sale_voided( $sale_id ) : void {
		self::reverse_sale( absint( $sale_id ), __( 'Anulación de la venta', 'mj-erp' ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * The customer exists and credit is on.
	 *
	 * @param int $customer_id Customer id.
	 * @return true|WP_Error
	 */
	private static function check_customer( $customer_id ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'mjerp_credit_disabled', __( 'El saldo a favor está apagado en Configuración → Clientes.', 'mj-erp' ), array( 'status' => 409 ) );
		}
		if ( ! MJERP_Customers_Repo::find( $customer_id, false ) ) {
			return new WP_Error( 'mjerp_not_found', __( 'El cliente no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * A currency the ERP works with.
	 *
	 * @param mixed $currency Currency.
	 * @return string|WP_Error
	 */
	private static function currency( $currency ) {
		$currency = strtoupper( trim( (string) $currency ) );
		if ( ! in_array( $currency, MJERP_Settings::currencies(), true ) ) {
			return new WP_Error( 'mjerp_invalid_currency', __( 'Elegí una de las monedas que usa el ERP.', 'mj-erp' ), array( 'status' => 400, 'field' => 'currency' ) );
		}
		return $currency;
	}

	/**
	 * The error of a balance that does not cover a movement.
	 *
	 * @param float  $available What there is.
	 * @param float  $needed    What it takes.
	 * @param string $currency  Currency.
	 * @return WP_Error
	 */
	private static function insufficient( $available, $needed, string $currency ) : WP_Error {
		return new WP_Error(
			'mjerp_credit_insufficient',
			sprintf(
				/* translators: 1: available balance, 2: amount needed */
				__( 'No alcanza el saldo a favor: hay %1$s y hacen falta %2$s.', 'mj-erp' ),
				MJERP_Sale_Log::money( max( 0, (float) $available ), $currency ),
				MJERP_Sale_Log::money( (float) $needed, $currency )
			),
			array(
				'status'    => 409,
				'field'     => 'amount',
				'available' => round( max( 0, (float) $available ), 4 ),
			)
		);
	}
}
