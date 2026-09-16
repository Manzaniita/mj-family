<?php
defined( 'ABSPATH' ) || exit;

/**
 * /mjerp/v1 — el saldo a favor de un cliente, visto desde el ERP.
 *
 * Verlo lo puede cualquiera que use el ERP: hace falta para cobrar una venta con saldo. Moverlo a
 * mano —un ajuste o «le di la plata»— solo quien puede corregir ventas.
 *
 * @package MJ_ERP
 */
class MJERP_REST_Credit extends MJERP_REST_Controller {

	protected $rest_base = 'customers';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns = $this->namespace;
		$id = array( 'id' => $this->id_arg( __( 'ID del cliente.', 'mj-erp' ) ) );

		register_rest_route(
			$ns,
			'/customers/(?P<id>\d+)/credit',
			array(
				'args' => $id,
				$this->endpoint( WP_REST_Server::READABLE, 'get_credit' ),
			)
		);

		register_rest_route(
			$ns,
			'/customers/(?P<id>\d+)/credit/adjust',
			array(
				'args' => $id,
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'adjust_credit',
					array(
						'amount'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'currency' => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
						'reason'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'sale_id'  => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/customers/(?P<id>\d+)/credit/payout',
			array(
				'args' => $id,
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'payout_credit',
					array(
						'amount'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'currency'   => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
						'method'     => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'cashbox_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'reference'  => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'reason'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);
	}

	/**
	 * GET /customers/{id}/credit
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_credit( $request ) {
		$id = $this->route_id( $request );
		if ( ! MJERP_Customers_Repo::find( $id, false ) ) {
			return $this->fail( 'mjerp_not_found', __( 'El cliente no existe.', 'mj-erp' ), 404 );
		}
		return $this->ok( $this->statement( $id ) );
	}

	/**
	 * POST /customers/{id}/credit/adjust
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function adjust_credit( $request ) {
		if ( ! MJERP_Capabilities::can_manage_sales() ) {
			return $this->forbidden();
		}

		$id     = $this->route_id( $request );
		$params = $this->params( $request );

		$result = MJERP_Credit::adjust(
			$id,
			$this->float( $params, 'amount' ),
			$this->currency( $params, 'currency', MJERP_Sales_Repo::shop_currency() ),
			$this->str( $params, 'reason', '', 255 ),
			$this->int( $params, 'sale_id' )
		);

		return is_wp_error( $result ) ? $result : $this->ok( $this->statement( $id ), 201 );
	}

	/**
	 * POST /customers/{id}/credit/payout — «le di la plata».
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function payout_credit( $request ) {
		if ( ! MJERP_Capabilities::can_manage_sales() ) {
			return $this->forbidden();
		}

		$id     = $this->route_id( $request );
		$params = $this->params( $request );

		$result = MJERP_Credit::payout(
			$id,
			$this->float( $params, 'amount' ),
			$this->currency( $params, 'currency', MJERP_Sales_Repo::shop_currency() ),
			$this->str( $params, 'method' ),
			$this->int( $params, 'cashbox_id' ),
			$this->str( $params, 'reference', '', 190 ),
			$this->str( $params, 'reason', '', 255 )
		);

		return is_wp_error( $result ) ? $result : $this->ok( $this->statement( $id ), 201 );
	}

	/**
	 * Balance, movements and what the current user may do.
	 *
	 * @param int $customer_id Customer id.
	 * @return array
	 */
	protected function statement( int $customer_id ) : array {
		$data               = MJERP_Credit::statement( $customer_id );
		$data['can_manage'] = MJERP_Capabilities::can_manage_sales();
		return $data;
	}

	/**
	 * 403 for someone who may not move credit by hand.
	 *
	 * @return WP_Error
	 */
	protected function forbidden() {
		return $this->fail( 'mjerp_forbidden', __( 'Mover el saldo a favor a mano lo hace solo quien puede corregir ventas.', 'mj-erp' ), 403 );
	}
}
