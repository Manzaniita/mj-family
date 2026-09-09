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
				'can_sell'   => MJERP_Helpers::bool( MJERP_Settings::get( 'portal_allow_sales', true ) ),
				'can_return' => MJERP_Helpers::bool( MJERP_Settings::get( 'portal_allow_returns', false ) ),
			)
		);
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
		if ( ! MJERP_Helpers::bool( MJERP_Settings::get( 'portal_allow_sales', true ) ) ) {
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
		$p  = $this->params( $request );
		$id = $this->consignee_id();

		return $this->ok(
			array(
				'account'   => $this->public_account( MJERP_Consignment_Account::summary( $id ) ),
				'statement' => MJERP_Consignment_Account::statement( $id, $this->int( $p, 'limit', 100 ) ),
			)
		);
	}

	/**
	 * POST /portal/returns
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_return( $request ) {
		if ( ! MJERP_Helpers::bool( MJERP_Settings::get( 'portal_allow_returns', false ) ) ) {
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
		);

		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = isset( $account[ $key ] ) ? $account[ $key ] : null;
		}
		return $out;
	}
}
