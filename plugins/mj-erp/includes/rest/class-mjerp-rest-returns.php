<?php
defined( 'ABSPATH' ) || exit;

/**
 * /mjerp/v1 — devoluciones, cambios y garantías de ventas cobradas, y la lista de RMA.
 *
 * Todo esto lo hace solo quien puede corregir ventas (MJERP_Capabilities::can_manage_sales()).
 *
 * @package MJ_ERP
 */
class MJERP_REST_Returns extends MJERP_REST_Controller {

	protected $rest_base = 'returns';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns   = $this->namespace;
		$sale = array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) );
		$args = array(
			'items'        => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
			'new_items'    => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
			'settlement'   => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
			'credit_split' => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
			'refund'       => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
			'reason'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'return_date'  => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
		);

		register_rest_route(
			$ns,
			'/sales/(?P<id>\d+)/returns',
			array(
				'args' => $sale,
				$this->endpoint( WP_REST_Server::CREATABLE, 'create_return', $args ),
			)
		);

		register_rest_route(
			$ns,
			'/sales/(?P<id>\d+)/returns/preview',
			array(
				'args' => $sale,
				$this->endpoint( WP_REST_Server::CREATABLE, 'preview_return', $args ),
			)
		);

		register_rest_route(
			$ns,
			'/rma',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_rma',
					array(
						'status'      => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'supplier_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'search'      => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'page'        => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'per_page'    => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
			)
		);

		$rma = array( 'id' => $this->id_arg( __( 'ID del RMA.', 'mj-erp' ) ) );

		register_rest_route(
			$ns,
			'/rma/(?P<id>\d+)',
			array(
				'args' => $rma,
				$this->endpoint( WP_REST_Server::READABLE, 'get_rma' ),
				$this->endpoint(
					WP_REST_Server::EDITABLE,
					'update_rma',
					array(
						'supplier_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'problem'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'note'        => array( 'sanitize_callback' => array( $this, 'arg_textarea' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/rma/(?P<id>\d+)/resolve',
			array(
				'args' => $rma,
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'resolve_rma',
					array(
						'status' => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'note'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Devoluciones
	 * ---------------------------------------------------------------- */

	/**
	 * POST /sales/{id}/returns/preview
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_return( $request ) {
		$result = MJERP_Returns::preview( $this->route_id( $request ), $this->return_input( $this->params( $request ) ) );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * POST /sales/{id}/returns
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_return( $request ) {
		$result = MJERP_Returns::apply( $this->route_id( $request ), $this->return_input( $this->params( $request ) ) );
		return is_wp_error( $result ) ? $result : $this->ok( $result, 201 );
	}

	/**
	 * Sanitizes the body of a return.
	 *
	 * @param array $params Params.
	 * @return array
	 */
	protected function return_input( array $params ) : array {
		$items = array();
		foreach ( (array) ( isset( $params['items'] ) ? $params['items'] : array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$items[] = array(
				'sale_item_id' => isset( $row['sale_item_id'] ) ? absint( $row['sale_item_id'] ) : 0,
				'qty'          => isset( $row['qty'] ) ? $this->to_float( $row['qty'] ) : 0,
				'destination'  => isset( $row['destination'] ) ? sanitize_key( (string) $row['destination'] ) : '',
				'replace'      => isset( $row['replace'] ) ? $this->to_bool( $row['replace'] ) : false,
				'problem'      => isset( $row['problem'] ) ? sanitize_text_field( (string) $row['problem'] ) : '',
			);
		}

		$new_items = array();
		foreach ( (array) ( isset( $params['new_items'] ) ? $params['new_items'] : array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$line = array(
				'product_id'   => isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0,
				'variation_id' => isset( $row['variation_id'] ) ? absint( $row['variation_id'] ) : 0,
				'qty'          => isset( $row['qty'] ) ? $this->to_float( $row['qty'] ) : 0,
			);
			if ( array_key_exists( 'unit_price', $row ) && '' !== $row['unit_price'] && null !== $row['unit_price'] ) {
				$line['unit_price'] = $this->to_float( $row['unit_price'] );
			}
			$new_items[] = $line;
		}

		$data = array(
			'items'       => $items,
			'new_items'   => $new_items,
			'reason'      => $this->str( $params, 'reason', '', 255 ),
			'return_date' => $this->date( $params, 'return_date' ),
		);

		if ( $this->has( $params, 'settlement' ) ) {
			$data['settlement'] = $this->str( $params, 'settlement' );
		}
		if ( isset( $params['credit_split'] ) && is_array( $params['credit_split'] ) ) {
			$data['credit_split'] = $params['credit_split'];
		}
		if ( isset( $params['refund'] ) && is_array( $params['refund'] ) ) {
			$data['refund'] = array(
				'method'     => isset( $params['refund']['method'] ) ? sanitize_key( (string) $params['refund']['method'] ) : '',
				'cashbox_id' => isset( $params['refund']['cashbox_id'] ) ? absint( $params['refund']['cashbox_id'] ) : 0,
				'reference'  => isset( $params['refund']['reference'] ) ? sanitize_text_field( (string) $params['refund']['reference'] ) : '',
			);
		}

		return $data;
	}

	/* ------------------------------------------------------------------
	 * RMA
	 * ---------------------------------------------------------------- */

	/**
	 * GET /rma
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_rma( $request ) {
		if ( ! MJERP_Capabilities::can_manage_sales() ) {
			return $this->forbidden();
		}
		$params = $this->params( $request );

		$result = MJERP_RMA_Repo::query(
			array(
				'status'      => $this->str( $params, 'status' ),
				'supplier_id' => $this->int( $params, 'supplier_id' ),
				'search'      => $this->str( $params, 'search', '', 190 ),
				'page'        => max( 1, $this->int( $params, 'page', 1 ) ),
				'per_page'    => $this->int( $params, 'per_page', 30 ),
			)
		);
		$result['totals']   = MJERP_RMA_Repo::totals();
		$result['statuses'] = MJERP_RMA_Repo::statuses();

		return $this->ok( $result );
	}

	/**
	 * GET /rma/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_rma( $request ) {
		if ( ! MJERP_Capabilities::can_manage_sales() ) {
			return $this->forbidden();
		}
		$rma = MJERP_RMA_Repo::find( $this->route_id( $request ) );
		return $rma ? $this->ok( $rma ) : $this->fail( 'mjerp_not_found', __( 'El RMA no existe.', 'mj-erp' ), 404 );
	}

	/**
	 * PUT /rma/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_rma( $request ) {
		if ( ! MJERP_Capabilities::can_manage_sales() ) {
			return $this->forbidden();
		}
		$id     = $this->route_id( $request );
		$params = $this->params( $request );

		$data = array();
		foreach ( array( 'supplier_id', 'problem', 'note' ) as $key ) {
			if ( $this->has( $params, $key ) ) {
				$data[ $key ] = $params[ $key ];
			}
		}

		$result = MJERP_RMA_Repo::update( $id, $data );
		return is_wp_error( $result ) ? $result : $this->ok( MJERP_RMA_Repo::find( $id ) );
	}

	/**
	 * POST /rma/{id}/resolve
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resolve_rma( $request ) {
		if ( ! MJERP_Capabilities::can_manage_sales() ) {
			return $this->forbidden();
		}
		$params = $this->params( $request );
		$result = MJERP_Returns::resolve_rma( $this->route_id( $request ), $this->str( $params, 'status' ), $this->str( $params, 'note', '', 255 ) );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * 403 for someone who may not correct sales.
	 *
	 * @return WP_Error
	 */
	protected function forbidden() {
		return $this->fail( 'mjerp_forbidden', __( 'Las devoluciones y los RMA los maneja solo quien puede corregir ventas.', 'mj-erp' ), 403 );
	}
}
