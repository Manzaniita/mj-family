<?php
defined( 'ABSPATH' ) || exit;

/**
 * /mjerp/v1/portal — the reseller's own endpoints.
 *
 * Separate from every other controller on purpose, because the permission model is the opposite
 * one: instead of "does this user run the ERP", the question is "which consignee is this user".
 *
 * The single rule this controller enforces: **no request may name a consignee.** The account is
 * always resolved from the session, and every row touched is checked to belong to it. A reseller
 * cannot read, sell against, or return someone else's stock even by guessing ids.
 *
 * @package MJ_ERP
 */
class MJERP_REST_Portal extends MJERP_REST_Controller {

	protected $rest_base = 'portal';

	/**
	 * Consignee of the current request, resolved once.
	 *
	 * @var array|null|false
	 */
	protected $consignee = false;

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/portal/me',
			array( $this->portal_endpoint( WP_REST_Server::READABLE, 'get_me' ) )
		);

		register_rest_route(
			$ns,
			'/portal/stock',
			array( $this->portal_endpoint( WP_REST_Server::READABLE, 'get_stock' ) )
		);

		register_rest_route(
			$ns,
			'/portal/sales',
			array(
				$this->portal_endpoint(
					WP_REST_Server::READABLE,
					'list_sales',
					array(
						'page'     => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'per_page' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'status'   => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
					)
				),
				$this->portal_endpoint(
					WP_REST_Server::CREATABLE,
					'create_sale',
					array(
						'consignment_item_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'qty'                 => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'sale_date'           => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'retail_amount'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'retail_currency'     => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
						'note'                => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/portal/sales/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->portal_endpoint( WP_REST_Server::DELETABLE, 'delete_sale' ),
			)
		);

		register_rest_route(
			$ns,
			'/portal/account',
			array(
				$this->portal_endpoint(
					WP_REST_Server::READABLE,
					'get_account',
					array( 'limit' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ) )
				),
			)
		);

		register_rest_route(
			$ns,
			'/portal/payments',
			array(
				$this->portal_endpoint(
					WP_REST_Server::READABLE,
					'list_payments',
					array(
						'page'     => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'per_page' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
				$this->portal_endpoint(
					WP_REST_Server::CREATABLE,
					'create_payment',
					array(
						'amount'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'currency'     => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
						'method'       => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'payment_date' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'reference'    => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'note'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/portal/payments/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del pago.', 'mj-erp' ) ) ),
				$this->portal_endpoint( WP_REST_Server::DELETABLE, 'delete_payment' ),
			)
		);

		register_rest_route(
			$ns,
			'/portal/returns',
			array(
				$this->portal_endpoint(
					WP_REST_Server::CREATABLE,
					'create_return',
					array(
						'consignment_item_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'qty'                 => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'note'                => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Permissions
	 * ---------------------------------------------------------------- */

	/**
	 * Endpoint definition using the portal's own permission callback.
	 *
	 * @param string $methods  WP_REST_Server constant.
	 * @param string $callback Method name.
	 * @param array  $args     Args schema.
	 * @return array
	 */
	protected function portal_endpoint( $methods, $callback, $args = array() ) {
		return array(
			'methods'             => $methods,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'portal_permission_check' ),
			'args'                => $args,
		);
	}

	/**
	 * Only a logged-in user holding the portal capability *and* an active consignee record.
	 *
	 * @return true|WP_Error
	 */
	public function portal_permission_check() {
		if ( ! MJERP_Portal::is_enabled() ) {
			return $this->fail( 'mjerp_portal_disabled', __( 'El acceso para revendedores está desactivado.', 'mj-erp' ), 403 );
		}
		if ( ! is_user_logged_in() ) {
			return $this->fail( 'mjerp_unauthorized', __( 'Tenés que iniciar sesión.', 'mj-erp' ), 401 );
		}
		if ( ! MJERP_Capabilities::current_user_can_portal() ) {
			return $this->fail( 'mjerp_forbidden', __( 'Tu usuario no tiene acceso de revendedor.', 'mj-erp' ), 403 );
		}
		if ( ! $this->current_consignee() ) {
			return $this->fail( 'mjerp_no_consignee', __( 'Tu usuario todavía no está dado de alta como revendedor.', 'mj-erp' ), 403 );
		}
		return true;
	}

	/**
	 * The consignee behind the session. Never comes from the request.
	 *
	 * @return array|null
	 */
	protected function current_consignee() {
		if ( false === $this->consignee ) {
			$this->consignee = MJERP_Consignees::current();
		}
		return $this->consignee;
	}

	/**
	 * Consignee id of the session (0 when none).
	 *
	 * @return int
	 */
	protected function consignee_id() : int {
		$consignee = $this->current_consignee();
		return $consignee ? (int) $consignee['id'] : 0;
	}

	/**
	 * Loads a consignment line and refuses it when it belongs to someone else.
	 *
	 * @param int $item_id Line id.
	 * @return array|WP_Error
	 */
	protected function own_item( $item_id ) {
		$item = MJERP_Consignments_Repo::find_item( $item_id );
		// Same answer for "does not exist" and "not yours": a 404 leaks nothing about other resellers.
		if ( ! $item || (int) $item['consignee_id'] !== $this->consignee_id() ) {
			return $this->fail( 'mjerp_not_found', __( 'Ese producto no está en tu cuenta.', 'mj-erp' ), 404 );
		}
		return $item;
	}

	/* ------------------------------------------------------------------
	 * Endpoints
	 * ---------------------------------------------------------------- */

	/**
	 * GET /portal/me — everything the first screen needs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_me( $request ) {
		$consignee = $this->current_consignee();
		$id        = (int) $consignee['id'];

		return $this->ok(
			array(
				'consignee' => $this->public_consignee( $consignee ),
				'account'   => $this->public_account( MJERP_Consignment_Account::summary( $id ) ),
				'stock'     => MJERP_Consignments_Repo::stock_on_hand( $id, true ),
				'sales'     => MJERP_Consignment_Sales_Repo::query(
					array(
						'consignee_id' => $id,
						'per_page'     => 30,
					)
				),
				'payments'   => $this->can( 'see_account' )
					? MJERP_Consignment_Payments_Repo::query(
						array(
							'consignee_id' => $id,
							'per_page'     => 30,
						)
					)
					: array( 'items' => array() ),
				'can'        => $this->abilities(),
				'payment_methods' => $this->allowed_methods(),
				// Kept for older portal builds that read these two names.
				'can_sell'   => $this->can( 'report_sale' ),
				'can_return' => $this->can( 'returns' ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Configurable abilities
	 * ---------------------------------------------------------------- */

	/**
	 * Whether the reseller holds a permission, with the global portal switches as a hard ceiling:
	 * turning a feature off for everyone can never be overridden per role or per user.
	 *
	 * @param string $permission Permission key.
	 * @return bool
	 */
	protected function can( string $permission ) : bool {
		$consignee = $this->current_consignee();
		if ( is_wp_error( $consignee ) || ! is_array( $consignee ) ) {
			return false;
		}

		if ( 'report_sale' === $permission && ! MJERP_Helpers::bool( MJERP_Settings::get( 'portal_allow_sales', true ) ) ) {
			return false;
		}
		if ( 'returns' === $permission && ! MJERP_Helpers::bool( MJERP_Settings::get( 'portal_allow_returns', false ) ) ) {
			return false;
		}

		return MJERP_Permissions::can( $permission, (int) $consignee['user_id'], $consignee );
	}

	/**
	 * Everything the portal UI needs to know about what this reseller may do.
	 *
	 * @return array
	 */
	protected function abilities() : array {
		$consignee = $this->current_consignee();
		$consignee = is_array( $consignee ) ? $consignee : null;
		$user_id   = $consignee ? (int) $consignee['user_id'] : 0;

		return array(
			'report_sale'            => $this->can( 'report_sale' ),
			'register_payment'       => $this->can( 'register_payment' ),
			'partial_payments'       => $this->can( 'partial_payments' ),
			'payment_needs_approval' => $this->can( 'payment_needs_approval' ),
			'returns'                => $this->can( 'returns' ),
			'see_account'            => $this->can( 'see_account' ),
			/* Qué comprobante se le va a pedir cuando declare un pago. */
			'payment_receipt'        => class_exists( 'MJERP_Receipts' ) && MJERP_Receipts::available()
				? (string) MJERP_Permissions::value( 'payment_receipt', $user_id, $consignee, 'heredar' )
				: 'no',
			'receipt_noun'           => class_exists( 'MJPM_Receipts' ) && MJERP_Receipts::available()
				? MJPM_Receipts::noun()
				: '',
		);
	}

	/**
	 * Payment methods this reseller is allowed to use.
	 *
	 * @return array
	 */
	protected function allowed_methods() : array {
		$consignee = $this->current_consignee();
		if ( is_wp_error( $consignee ) || ! is_array( $consignee ) ) {
			return array();
		}
		return MJERP_Permissions::payment_methods( (int) $consignee['user_id'], $consignee );
	}

	/**
	 * GET /portal/stock
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_stock( $request ) {
		$id = $this->consignee_id();
		return $this->ok(
			array(
				'items'  => MJERP_Consignments_Repo::stock_on_hand( $id, true ),
				'totals' => MJERP_Consignments_Repo::on_hand_totals( $id ),
			)
		);
	}

	/**
	 * GET /portal/sales
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_sales( $request ) {
		$p = $this->params( $request );

		return $this->ok(
			MJERP_Consignment_Sales_Repo::query(
				array(
					'consignee_id' => $this->consignee_id(),
					'status'       => $this->str( $p, 'status' ),
					'page'         => $this->int( $p, 'page', 1 ),
					'per_page'     => min( 100, max( 1, $this->int( $p, 'per_page', 30 ) ) ),
				)
			)
		);
	}

	/**
	 * POST /portal/sales — the reseller reports what they sold.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_sale( $request ) {
		if ( ! $this->can( 'report_sale' ) ) {
			return $this->fail( 'mjerp_portal_sales_disabled', __( 'Por ahora las ventas las carga el administrador.', 'mj-erp' ), 403 );
		}

		$p    = $this->params( $request );
		$item = $this->own_item( $this->int( $p, 'consignment_item_id', 0 ) );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		/*
		 * `unit_price_base` is deliberately not forwarded: the price is the one agreed at delivery
		 * and the reseller does not get to set what they owe. The repo enforces this too by only
		 * honouring an override for the admin source.
		 */
		$id = MJERP_Consignment_Sales_Repo::create(
			array(
				'consignment_item_id' => (int) $item['id'],
				'qty'                 => $this->str( $p, 'qty' ),
				'sale_date'           => $this->str( $p, 'sale_date' ),
				'retail_amount'       => $this->has( $p, 'retail_amount' ) ? $p['retail_amount'] : '',
				'retail_currency'     => $this->str( $p, 'retail_currency' ),
				'note'                => $this->str( $p, 'note' ),
			),
			MJERP_Consignment_Sales_Repo::SOURCE_PORTAL
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$sale = MJERP_Consignment_Sales_Repo::find( $id );

		return $this->ok(
			array(
				'sale'    => $sale,
				'account' => $this->public_account( MJERP_Consignment_Account::summary( $this->consignee_id() ) ),
				'stock'   => MJERP_Consignments_Repo::stock_on_hand( $this->consignee_id(), true ),
			),
			201
		);
	}

	/**
	 * DELETE /portal/sales/{id} — undo a report that is still waiting for approval.
	 *
	 * Only pending sales the reseller loaded themselves: once approved it is a debt, and a debt is
	 * not something the person who owes it gets to delete.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_sale( $request ) {
		$sale = MJERP_Consignment_Sales_Repo::find( absint( $request['id'] ) );
		if ( ! $sale || (int) $sale['consignee_id'] !== $this->consignee_id() ) {
			return $this->fail( 'mjerp_not_found', __( 'Esa venta no está en tu cuenta.', 'mj-erp' ), 404 );
		}
		if ( MJERP_Consignment_Sales_Repo::STATUS_PENDING !== $sale['status'] ) {
			return $this->fail(
				'mjerp_sale_not_pending',
				__( 'Esa venta ya fue confirmada: pedile al administrador que la corrija.', 'mj-erp' ),
				409
			);
		}
		if ( MJERP_Consignment_Sales_Repo::SOURCE_PORTAL !== $sale['source'] ) {
			return $this->fail( 'mjerp_sale_not_yours', __( 'Esa venta la cargó el administrador.', 'mj-erp' ), 409 );
		}

		$result = MJERP_Consignment_Sales_Repo::delete( (int) $sale['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok(
			array(
				'deleted' => true,
				'account' => $this->public_account( MJERP_Consignment_Account::summary( $this->consignee_id() ) ),
				'stock'   => MJERP_Consignments_Repo::stock_on_hand( $this->consignee_id(), true ),
			)
		);
	}

	/**
	 * GET /portal/account — the statement, so the reseller can see why they owe what they owe.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_account( $request ) {
		if ( ! $this->can( 'see_account' ) ) {
			return $this->fail( 'mjerp_portal_account_hidden', __( 'Tu cuenta corriente la lleva el administrador.', 'mj-erp' ), 403 );
		}

		$p  = $this->params( $request );
		$id = $this->consignee_id();

		return $this->ok(
			array(
				'account'   => $this->public_account( MJERP_Consignment_Account::summary( $id ) ),
				'statement' => MJERP_Consignment_Account::statement( $id, $this->int( $p, 'limit', 100 ) ),
				'payments'  => $this->with_receipts(
					MJERP_Consignment_Payments_Repo::query(
						array(
							'consignee_id' => $id,
							'per_page'     => min( 100, max( 1, $this->int( $p, 'limit', 100 ) ) ),
						)
					)
				),
			)
		);
	}

	/**
	 * Agrega a cada pago su bloque de comprobante, para que el portal pueda
	 * pintar el widget de subida y el estado sin pedir nada más.
	 *
	 * @param array $result Resultado de la consulta de pagos.
	 * @return array
	 */
	protected function with_receipts( array $result ) : array {
		if ( ! class_exists( 'MJERP_Receipts' ) || ! MJERP_Receipts::available() || empty( $result['items'] ) ) {
			return $result;
		}
		foreach ( $result['items'] as $index => $payment ) {
			$result['items'][ $index ]['receipt'] = MJERP_Receipts::payload( $payment );
		}
		return $result;
	}

	/**
	 * GET /portal/payments
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_payments( $request ) {
		if ( ! $this->can( 'see_account' ) ) {
			return $this->fail( 'mjerp_portal_account_hidden', __( 'Tu cuenta corriente la lleva el administrador.', 'mj-erp' ), 403 );
		}

		$p = $this->params( $request );

		return $this->ok(
			$this->with_receipts(
				MJERP_Consignment_Payments_Repo::query(
					array(
						'consignee_id' => $this->consignee_id(),
						'page'         => $this->int( $p, 'page', 1 ),
						'per_page'     => min( 100, max( 1, $this->int( $p, 'per_page', 30 ) ) ),
					)
				)
			)
		);
	}

	/**
	 * POST /portal/payments — the reseller records what they paid.
	 *
	 * Whether that money counts right away or waits for the shop owner is the
	 * `payment_needs_approval` permission, resolved per role and per user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_payment( $request ) {
		if ( ! $this->can( 'register_payment' ) ) {
			return $this->fail( 'mjerp_portal_payments_disabled', __( 'Los pagos los registra el administrador.', 'mj-erp' ), 403 );
		}

		$p         = $this->params( $request );
		$consignee = $this->current_consignee();
		$id        = (int) $consignee['id'];

		$account = MJERP_Consignment_Account::summary( $id );
		$owed    = (float) $account['balance_base'];

		$amount = $this->float( $p, 'amount' );
		if ( $amount <= 0 ) {
			return $this->fail( 'mjerp_invalid_amount', __( 'Poné cuánto pagaste.', 'mj-erp' ), 400, array( 'field' => 'amount' ) );
		}

		$currency = $this->currency( $p, 'currency', strtoupper( (string) MJERP_Settings::get( 'local_currency', 'ARS' ) ) );
		$rate     = MJERP_Helpers::to_float( MJERP_Settings::get( 'sale_rate', 1 ) );
		$base     = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );
		$in_base  = MJERP_Helpers::to_base( $amount, $currency, $rate, $base );

		if ( ! $this->can( 'partial_payments' ) && $owed > 0 && $in_base + 0.01 < $owed ) {
			return $this->fail(
				'mjerp_partial_not_allowed',
				__( 'Tenés que cancelar el total de tu cuenta; los pagos parciales no están habilitados.', 'mj-erp' ),
				403,
				array( 'field' => 'amount' )
			);
		}

		$method = $this->str( $p, 'method' );
		if ( '' !== $method && ! MJERP_Permissions::allows_method( $method, (int) $consignee['user_id'], $consignee ) ) {
			return $this->fail( 'mjerp_method_not_allowed', __( 'Ese medio de pago no está habilitado para vos.', 'mj-erp' ), 403, array( 'field' => 'method' ) );
		}
		if ( '' === $method ) {
			$methods = $this->allowed_methods();
			$method  = ! empty( $methods ) ? (string) $methods[0]['key'] : '';
		}

		$status = $this->can( 'payment_needs_approval' )
			? MJERP_Consignment_Payments_Repo::STATUS_DECLARED
			: MJERP_Consignment_Payments_Repo::STATUS_CONFIRMED;

		$payment_id = MJERP_Consignment_Payments_Repo::create(
			array(
				'consignee_id' => $id,
				'kind'         => MJERP_Consignment_Payments_Repo::KIND_PAYMENT,
				'amount'       => $amount,
				'currency'     => $currency,
				'rate'         => $rate,
				'method'       => $method,
				'payment_date' => $this->str( $p, 'payment_date' ),
				'reference'    => $this->str( $p, 'reference', '', 190 ),
				'note'         => $this->str( $p, 'note', '', 255 ),
				'status'       => $status,
				'source'       => MJERP_Consignment_Payments_Repo::SOURCE_PORTAL,
			)
		);
		if ( is_wp_error( $payment_id ) ) {
			return $payment_id;
		}

		/*
		 * El comprobante se sube DESPUÉS, contra el pago ya creado: el archivo
		 * necesita un id al que pegarse, y así el revendedor puede declarar el
		 * pago ahora y mandar la foto cuando la tenga a mano.
		 */
		$receipt = null;
		if ( class_exists( 'MJERP_Receipts' ) && MJERP_Receipts::available() ) {
			$saved   = MJERP_Consignment_Payments_Repo::find( (int) $payment_id );
			$receipt = $saved ? MJERP_Receipts::payload( $saved ) : null;
		}

		return $this->ok(
			array(
				'id'          => $payment_id,
				'status'      => $status,
				'needs_approval' => MJERP_Consignment_Payments_Repo::STATUS_DECLARED === $status,
				'receipt'     => $receipt,
				'account'     => $this->public_account( MJERP_Consignment_Account::summary( $id ) ),
			),
			201
		);
	}

	/**
	 * DELETE /portal/payments/{id} — a reseller may take back a payment they declared, and only
	 * while nobody confirmed it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_payment( $request ) {
		if ( ! $this->can( 'register_payment' ) ) {
			return $this->fail( 'mjerp_portal_payments_disabled', __( 'Los pagos los registra el administrador.', 'mj-erp' ), 403 );
		}

		$payment = MJERP_Consignment_Payments_Repo::find( $this->route_id( $request ) );
		if ( ! $payment || (int) $payment['consignee_id'] !== $this->consignee_id() ) {
			return $this->fail( 'mjerp_not_found', __( 'El pago no existe.', 'mj-erp' ), 404 );
		}
		if ( ! $payment['is_declared'] ) {
			return $this->fail( 'mjerp_payment_confirmed', __( 'Ese pago ya fue confirmado: hablalo con el administrador.', 'mj-erp' ), 409 );
		}

		/* El comprobante se va con el pago que deshace. */
		if ( class_exists( 'MJPM_Receipts' ) && class_exists( 'MJERP_Receipts' ) ) {
			MJPM_Receipts::delete_for_object( MJERP_Receipts::CONTEXT, (int) $payment['id'] );
		}

		$result = MJERP_Consignment_Payments_Repo::delete( (int) $payment['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * POST /portal/returns
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_return( $request ) {
		if ( ! $this->can( 'returns' ) ) {
			return $this->fail( 'mjerp_portal_returns_disabled', __( 'Las devoluciones las registra el administrador.', 'mj-erp' ), 403 );
		}

		$p    = $this->params( $request );
		$item = $this->own_item( $this->int( $p, 'consignment_item_id', 0 ) );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$id = MJERP_Consignments_Repo::add_return(
			array(
				'consignment_item_id' => (int) $item['id'],
				'qty'                 => $this->str( $p, 'qty' ),
				'note'                => $this->str( $p, 'note' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->ok(
			array(
				'id'    => $id,
				'stock' => MJERP_Consignments_Repo::stock_on_hand( $this->consignee_id(), true ),
			),
			201
		);
	}

	/* ------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	/**
	 * The consignee as the reseller may see it: no internal ids, no notes the shop owner wrote
	 * about them, no linked account details.
	 *
	 * @param array $consignee Consignee row.
	 * @return array
	 */
	protected function public_consignee( array $consignee ) : array {
		return array(
			'name'           => (string) $consignee['name'],
			'email'          => (string) $consignee['email'],
			'phone'          => (string) $consignee['phone'],
			'avatar'         => isset( $consignee['avatar'] ) ? $consignee['avatar'] : null,
			'price_list'     => isset( $consignee['price_list'] ) ? $consignee['price_list'] : null,
			'credit_limit_base' => (float) $consignee['credit_limit_base'],
		);
	}

	/**
	 * The account summary trimmed to what the reseller needs.
	 *
	 * @param array $account Summary.
	 * @return array
	 */
	protected function public_account( array $account ) : array {
		$keys = array(
			'currency',
			'balance_base',
			'charges_base',
			'credits_base',
			'unallocated_base',
			'pending_sales',
			'pending_base',
			'unpaid_sales',
			'on_hand_units',
			'on_hand_value_base',
			'on_hand_lines',
			'last_sale_date',
			'last_payment_date',
			'credit_limit_base',
			'credit_available_base',
			'over_limit',
			'declared_base',
			'declared_count',
		);

		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = isset( $account[ $key ] ) ? $account[ $key ] : null;
		}
		return $out;
	}
}
