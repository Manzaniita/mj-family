<?php
defined( 'ABSPATH' ) || exit;

/**
 * /mjerp/v1 — sales, their lines and the money collected against them.
 *
 * The shop owner's side of the module: everything here runs behind `manage_mjerp`. What a reseller
 * can do lives in MJERP_REST_Portal, with its own permission model.
 *
 * @package MJ_ERP
 */
class MJERP_REST_Sales extends MJERP_REST_Controller {

	protected $rest_base = 'sales';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/sales',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_sales',
					array(
						'search'           => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'status'           => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'source'           => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'channel'          => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'payment_status'   => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'delivery_status'  => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'delivery_method'  => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'consignee_id'     => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'customer_id'      => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'customer_user_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'only_consignment' => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
						'date_from'        => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'date_to'          => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'page'             => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'per_page'         => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'orderby'          => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'order'            => array( 'sanitize_callback' => array( $this, 'arg_order' ) ),
					)
				),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'create_sale',
					array(
						'items'            => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
						'customer_id'      => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'customer_user_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'customer_name'    => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'customer_email'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'customer_phone'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'consignee_id'     => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'channel'          => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'sale_date'        => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'price_list_key'   => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'discount_total'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'shipping_total'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'note'             => array( 'sanitize_callback' => array( $this, 'arg_textarea' ) ),
						'payment_method'   => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'cashbox_id'       => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'delivery_method'  => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'delivery_note'    => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'delivered'        => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
						'create_order'     => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
						'payment'          => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/sales/preview',
			array(
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'preview_sale',
					array(
						'items'            => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
						'customer_id'      => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'customer_user_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'consignee_id'     => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'price_list_key'   => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'discount_total'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'shipping_total'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/sales/stats',
			array(
				$this->endpoint( WP_REST_Server::READABLE, 'get_stats' ),
			)
		);

		register_rest_route(
			$ns,
			'/sales/customer/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del usuario cliente.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'get_customer_context' ),
			)
		);

		register_rest_route(
			$ns,
			'/sales/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'get_sale' ),
				$this->endpoint(
					WP_REST_Server::EDITABLE,
					'update_sale',
					array(
						'channel'        => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'sale_date'      => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'note'           => array( 'sanitize_callback' => array( $this, 'arg_textarea' ) ),
						'customer_id'    => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'customer_name'  => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'customer_email' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'customer_phone' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'payment_method' => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'cashbox_id'     => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_sale' ),
			)
		);

		register_rest_route(
			$ns,
			'/sales/(?P<id>\d+)/void',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'void_sale',
					array( 'reason' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ) )
				),
			)
		);

		register_rest_route(
			$ns,
			'/sales/(?P<id>\d+)/delivery',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'set_delivery',
					array(
						'status' => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'method' => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'note'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		$edit_args = array(
			'items'          => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
			'discount_total' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'shipping_total' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'reason'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
		);

		register_rest_route(
			$ns,
			'/sales/(?P<id>\d+)/items',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::EDITABLE, 'edit_items', $edit_args ),
			)
		);

		register_rest_route(
			$ns,
			'/sales/(?P<id>\d+)/items/preview',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'preview_items', $edit_args ),
			)
		);

		register_rest_route(
			$ns,
			'/sales/(?P<id>\d+)/log',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'list_log' ),
			)
		);

		register_rest_route(
			$ns,
			'/sales/(?P<id>\d+)/order',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'create_order' ),
			)
		);

		register_rest_route(
			$ns,
			'/sales/(?P<id>\d+)/payments',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'list_payments' ),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'create_payment',
					array(
						'amount'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'currency'     => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
						'rate'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'method'       => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'cashbox_id'   => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'payment_date' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'reference'    => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'note'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						// Con el medio «saldo a favor»: de qué saldo sale (la moneda).
						'credit_currency' => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/sale-payments/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del cobro.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::EDITABLE,
					'update_payment',
					array(
						'amount'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'currency'     => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
						'rate'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'method'       => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'cashbox_id'   => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'payment_date' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'reference'    => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'note'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_payment' ),
			)
		);

		register_rest_route(
			$ns,
			'/sale-payments/(?P<id>\d+)/confirm',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del cobro.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'confirm_payment' ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Sales
	 * ---------------------------------------------------------------- */

	/**
	 * GET /sales
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_sales( $request ) {
		$params = $this->params( $request );

		$result = MJERP_Sales_Repo::query(
			array(
				'search'           => $this->str( $params, 'search' ),
				'status'           => $this->str( $params, 'status' ),
				'source'           => $this->str( $params, 'source' ),
				'channel'          => $this->str( $params, 'channel' ),
				'payment_status'   => $this->str( $params, 'payment_status' ),
				'delivery_status'  => $this->str( $params, 'delivery_status' ),
				'delivery_method'  => $this->str( $params, 'delivery_method' ),
				'consignee_id'     => $this->int( $params, 'consignee_id' ),
				'customer_id'      => $this->int( $params, 'customer_id' ),
				'customer_user_id' => $this->int( $params, 'customer_user_id' ),
				'only_consignment' => $this->bool( $params, 'only_consignment' ),
				'date_from'        => (string) $this->date( $params, 'date_from' ),
				'date_to'          => (string) $this->date( $params, 'date_to' ),
				'page'             => max( 1, $this->int( $params, 'page', 1 ) ),
				'per_page'         => $this->int( $params, 'per_page', 20 ),
				'orderby'          => $this->str( $params, 'orderby', 'sale_date' ),
				'order'            => $this->str( $params, 'order', 'DESC' ),
			)
		);

		return $this->ok( $result );
	}

	/**
	 * POST /sales
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_sale( $request ) {
		$params = $this->params( $request );

		$data = array(
			'items'            => $this->sale_items( $params ),
			'customer_id'      => $this->int( $params, 'customer_id' ),
			'customer_user_id' => $this->int( $params, 'customer_user_id' ),
			'customer_name'    => $this->str( $params, 'customer_name', '', 190 ),
			'customer_email'   => $this->str( $params, 'customer_email', '', 190 ),
			'customer_phone'   => $this->str( $params, 'customer_phone', '', 60 ),
			'consignee_id'     => $this->int( $params, 'consignee_id' ),
			'channel'          => $this->str( $params, 'channel' ),
			'sale_date'        => $this->date( $params, 'sale_date' ),
			'price_list_key'   => $this->str( $params, 'price_list_key' ),
			'discount_total'   => $this->float( $params, 'discount_total' ),
			'shipping_total'   => $this->float( $params, 'shipping_total' ),
			'note'             => $this->text( $params, 'note' ),
			'payment_method'   => $this->str( $params, 'payment_method' ),
			'cashbox_id'       => $this->int( $params, 'cashbox_id' ),
			'delivery_note'    => $this->str( $params, 'delivery_note', '', 190 ),
			'payment'          => $this->payment_input( $params ),
		);

		// Only pass the key when it was actually sent: absent means "lo que diga la configuración".
		if ( $this->has( $params, 'create_order' ) ) {
			$data['create_order'] = $this->bool( $params, 'create_order' );
		}
		if ( $this->has( $params, 'delivery_method' ) ) {
			$data['delivery_method'] = $this->str( $params, 'delivery_method' );
		}
		if ( $this->has( $params, 'delivered' ) ) {
			$data['delivered'] = $this->bool( $params, 'delivered' );
		}

		$sale = MJERP_Sales::create_from_erp( $data );

		if ( is_wp_error( $sale ) ) {
			return $sale;
		}

		return $this->ok( $sale, 201 );
	}

	/**
	 * POST /sales/preview
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_sale( $request ) {
		$params = $this->params( $request );

		$preview = MJERP_Sales::preview(
			array(
				'items'            => $this->sale_items( $params ),
				'customer_id'      => $this->int( $params, 'customer_id' ),
				'customer_user_id' => $this->int( $params, 'customer_user_id' ),
				'consignee_id'     => $this->int( $params, 'consignee_id' ),
				'price_list_key'   => $this->str( $params, 'price_list_key' ),
				'discount_total'   => $this->float( $params, 'discount_total' ),
				'shipping_total'   => $this->float( $params, 'shipping_total' ),
			)
		);

		return $this->ok( $preview );
	}

	/**
	 * GET /sales/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_sale( $request ) {
		$sale = MJERP_Sales_Repo::get_full( $this->route_id( $request ) );
		if ( ! $sale ) {
			return $this->fail( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), 404 );
		}
		return $this->ok( $sale );
	}

	/**
	 * PUT /sales/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_sale( $request ) {
		$id     = $this->route_id( $request );
		$params = $this->params( $request );

		$data = array();
		foreach ( array( 'channel', 'sale_date', 'note', 'customer_id', 'customer_name', 'customer_email', 'customer_phone', 'payment_method', 'cashbox_id' ) as $key ) {
			if ( $this->has( $params, $key ) ) {
				$data[ $key ] = $params[ $key ];
			}
		}

		$result = MJERP_Sales_Repo::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( MJERP_Sales_Repo::get_full( $id ) );
	}

	/**
	 * DELETE /sales/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_sale( $request ) {
		$id   = $this->route_id( $request );
		$sale = MJERP_Sales_Repo::find( $id );
		if ( ! $sale ) {
			return $this->fail( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), 404 );
		}

		// Deleting has to unwind the same things voiding does, or the reseller keeps a debt that
		// points at a sale nobody can open any more.
		if ( MJERP_Sales_Repo::STATUS_VOID !== $sale['status'] ) {
			$voided = MJERP_Sales::void_sale( $id, __( 'Venta eliminada', 'mj-erp' ) );
			if ( is_wp_error( $voided ) ) {
				return $voided;
			}
		}

		$result = MJERP_Sales_Repo::delete( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * POST /sales/{id}/void
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function void_sale( $request ) {
		$id     = $this->route_id( $request );
		$params = $this->params( $request );

		$result = MJERP_Sales::void_sale( $id, $this->str( $params, 'reason', '', 255 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( MJERP_Sales_Repo::get_full( $id ) );
	}

	/**
	 * POST /sales/{id}/delivery — registra qué pasó con la entrega.
	 *
	 * Con `status` solo, es el botón de un toque de la lista de ventas. Con `method` o `note`
	 * también se corrige cómo se entrega y dónde, sin tocar el resto de la venta.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_delivery( $request ) {
		$id     = $this->route_id( $request );
		$params = $this->params( $request );

		$data = array();
		if ( $this->has( $params, 'status' ) ) {
			$data['status'] = $this->str( $params, 'status' );
		}
		if ( $this->has( $params, 'method' ) ) {
			$data['method'] = $this->str( $params, 'method' );
		}
		if ( $this->has( $params, 'note' ) ) {
			$data['note'] = $this->str( $params, 'note', '', 190 );
		}

		if ( empty( $data ) ) {
			return $this->fail( 'mjerp_invalid', __( 'No hay nada que registrar: mandá el estado, el método o la nota.', 'mj-erp' ), 400 );
		}

		$result = MJERP_Sales_Repo::set_delivery( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( MJERP_Sales_Repo::get_full( $id ) );
	}

	/**
	 * PUT /sales/{id}/items — corrige los productos de una venta sin cobrar.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function edit_items( $request ) {
		$result = MJERP_Sale_Editor::apply( $this->route_id( $request ), $this->edit_input( $this->params( $request ) ) );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * POST /sales/{id}/items/preview — cómo quedaría la venta, sin guardar nada.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_items( $request ) {
		$result = MJERP_Sale_Editor::preview( $this->route_id( $request ), $this->edit_input( $this->params( $request ) ) );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * GET /sales/{id}/log — quién cambió qué en la venta, del más nuevo al más viejo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_log( $request ) {
		if ( ! MJERP_Capabilities::can_manage_sales() ) {
			return $this->fail( 'mjerp_forbidden', __( 'El historial de una venta lo ve solo quien puede corregir ventas.', 'mj-erp' ), 403 );
		}

		$id = $this->route_id( $request );
		if ( ! MJERP_Sales_Repo::find( $id ) ) {
			return $this->fail( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), 404 );
		}

		return $this->ok(
			array(
				'items'   => MJERP_Sale_Log::for_sale( $id ),
				'enabled' => MJERP_Sale_Log::enabled(),
			)
		);
	}

	/**
	 * POST /sales/{id}/order — emits the WooCommerce order of a sale that does not have one yet.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_order( $request ) {
		$id   = $this->route_id( $request );
		$sale = MJERP_Sales_Repo::find( $id );
		if ( ! $sale ) {
			return $this->fail( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), 404 );
		}
		if ( $sale['order_id'] ) {
			return $this->fail( 'mjerp_order_exists', __( 'La venta ya tiene un pedido en WooCommerce.', 'mj-erp' ), 409 );
		}

		$order = MJERP_Orders::create_for_sale( $id );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return $this->ok( MJERP_Sales_Repo::get_full( $id ) );
	}

	/**
	 * GET /sales/stats
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_stats( $request ) {
		unset( $request );
		return $this->ok( MJERP_Sales_Repo::stats() );
	}

	/**
	 * GET /sales/customer/{id} — what the new-sale screen needs to know about a customer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_customer_context( $request ) {
		$user_id = $this->route_id( $request );

		$consignee = MJERP_Sales::resolve_consignee( $user_id, 0 );
		$data      = array(
			'user_id'   => $user_id,
			'consignee' => null,
			'stock'     => array(),
			'account'   => null,
		);

		if ( $consignee ) {
			$data['consignee'] = array(
				'id'             => (int) $consignee['id'],
				'name'           => (string) $consignee['name'],
				'price_list_key' => (string) $consignee['price_list_key'],
			);
			$data['stock']   = MJERP_Consignments_Repo::stock_on_hand( (int) $consignee['id'], true );
			$data['account'] = MJERP_Consignment_Account::summary( (int) $consignee['id'] );
		}

		return $this->ok( $data );
	}

	/* ------------------------------------------------------------------
	 * Payments
	 * ---------------------------------------------------------------- */

	/**
	 * GET /sales/{id}/payments
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_payments( $request ) {
		$id = $this->route_id( $request );
		return $this->ok(
			array(
				'items'  => MJERP_Sale_Payments_Repo::for_sale( $id ),
				'totals' => MJERP_Sale_Payments_Repo::totals( $id ),
			)
		);
	}

	/**
	 * POST /sales/{id}/payments
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_payment( $request ) {
		$id     = $this->route_id( $request );
		$params = $this->params( $request );

		$sale = MJERP_Sales_Repo::find( $id );
		if ( ! $sale ) {
			return $this->fail( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), 404 );
		}

		$amount = $this->has( $params, 'amount' ) ? $this->float( $params, 'amount' ) : 0.0;
		if ( $amount <= 0 ) {
			// No amount means "cobré todo lo que faltaba".
			$amount = round( MJERP_Sales_Repo::amount_due( $sale ) - (float) $sale['paid_total'], 4 );
		}

		$payment_id = MJERP_Sale_Payments_Repo::create(
			array(
				'sale_id'      => $id,
				'amount'       => $amount,
				'currency'     => $this->currency( $params, 'currency', $sale['currency'] ),
				'rate'         => $this->float( $params, 'rate', (float) $sale['rate'] ),
				'method'       => $this->str( $params, 'method' ),
				// 0 = decidila por el medio de pago. Solo se hereda la caja de la venta cuando el
				// cobro entra por el mismo medio con el que se cargó.
				'cashbox_id'   => $this->int(
					$params,
					'cashbox_id',
					sanitize_key( $this->str( $params, 'method' ) ) === sanitize_key( (string) $sale['payment_method'] ) ? (int) $sale['cashbox_id'] : 0
				),
				'payment_date'    => $this->date( $params, 'payment_date' ),
				'reference'       => $this->str( $params, 'reference', '', 190 ),
				'note'            => $this->str( $params, 'note', '', 255 ),
				'credit_currency' => $this->currency( $params, 'credit_currency', '' ),
				'status'          => MJERP_Sale_Payments_Repo::STATUS_CONFIRMED,
				'source'          => MJERP_Sale_Payments_Repo::SOURCE_ADMIN,
			)
		);

		if ( is_wp_error( $payment_id ) ) {
			return $payment_id;
		}

		return $this->ok( MJERP_Sales_Repo::get_full( $id ), 201 );
	}

	/**
	 * PUT /sale-payments/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_payment( $request ) {
		$id      = $this->route_id( $request );
		$params  = $this->params( $request );
		$payment = MJERP_Sale_Payments_Repo::find( $id );
		if ( ! $payment ) {
			return $this->fail( 'mjerp_not_found', __( 'El cobro no existe.', 'mj-erp' ), 404 );
		}

		$data = array();
		foreach ( array( 'amount', 'currency', 'rate', 'method', 'cashbox_id', 'payment_date', 'reference', 'note' ) as $key ) {
			if ( $this->has( $params, $key ) ) {
				$data[ $key ] = $params[ $key ];
			}
		}

		$result = MJERP_Sale_Payments_Repo::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( MJERP_Sales_Repo::get_full( (int) $payment['sale_id'] ) );
	}

	/**
	 * POST /sale-payments/{id}/confirm
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm_payment( $request ) {
		$id      = $this->route_id( $request );
		$payment = MJERP_Sale_Payments_Repo::find( $id );
		if ( ! $payment ) {
			return $this->fail( 'mjerp_not_found', __( 'El cobro no existe.', 'mj-erp' ), 404 );
		}

		$result = MJERP_Sale_Payments_Repo::confirm( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( MJERP_Sales_Repo::get_full( (int) $payment['sale_id'] ) );
	}

	/**
	 * DELETE /sale-payments/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_payment( $request ) {
		$id      = $this->route_id( $request );
		$payment = MJERP_Sale_Payments_Repo::find( $id );
		if ( ! $payment ) {
			return $this->fail( 'mjerp_not_found', __( 'El cobro no existe.', 'mj-erp' ), 404 );
		}

		$result = MJERP_Sale_Payments_Repo::delete( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( MJERP_Sales_Repo::get_full( (int) $payment['sale_id'] ) );
	}

	/* ------------------------------------------------------------------
	 * Input shaping
	 * ---------------------------------------------------------------- */

	/**
	 * Sanitizes the `items` payload of a sale.
	 *
	 * @param array $params Params.
	 * @return array
	 */
	protected function sale_items( array $params ) : array {
		$raw = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();
		$out = array();

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$line = array(
				// Only a correction reads it: the line of the sale this row stands for (0 = new).
				'id'           => isset( $item['id'] ) ? absint( $item['id'] ) : 0,
				'product_id'   => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
				'variation_id' => isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0,
				'qty'          => isset( $item['qty'] ) ? $this->to_float( $item['qty'] ) : 0,
				'name'         => isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '',
				'discount'     => isset( $item['discount'] ) ? $this->to_float( $item['discount'] ) : 0,
			);
			// A missing price is not zero: it means "use the one the ERP suggests".
			if ( array_key_exists( 'unit_price', $item ) && '' !== $item['unit_price'] && null !== $item['unit_price'] ) {
				$line['unit_price'] = $this->to_float( $item['unit_price'] );
			}
			$out[] = $line;
		}

		return $out;
	}

	/**
	 * Sanitizes the body of a correction.
	 *
	 * Absent amounts stay as they are on the sale; a present one, even 0, replaces them.
	 *
	 * @param array $params Params.
	 * @return array
	 */
	protected function edit_input( array $params ) : array {
		$data = array(
			'items'  => $this->sale_items( $params ),
			'reason' => $this->str( $params, 'reason', '', 255 ),
		);
		foreach ( array( 'discount_total', 'shipping_total' ) as $key ) {
			if ( $this->has( $params, $key ) ) {
				$data[ $key ] = $this->float( $params, $key );
			}
		}
		return $data;
	}

	/**
	 * Sanitizes the optional `payment` block of a new sale.
	 *
	 * @param array $params Params.
	 * @return array|null
	 */
	protected function payment_input( array $params ) {
		if ( ! isset( $params['payment'] ) || ! is_array( $params['payment'] ) ) {
			return null;
		}
		$payment = $params['payment'];

		$out = array(
			'method'     => isset( $payment['method'] ) ? sanitize_key( (string) $payment['method'] ) : '',
			'cashbox_id' => isset( $payment['cashbox_id'] ) ? absint( $payment['cashbox_id'] ) : 0,
			'currency'   => isset( $payment['currency'] ) ? $this->clean_currency( $payment['currency'] ) : '',
			'reference'  => isset( $payment['reference'] ) ? sanitize_text_field( (string) $payment['reference'] ) : '',
			'note'       => isset( $payment['note'] ) ? sanitize_text_field( (string) $payment['note'] ) : '',
		);
		if ( array_key_exists( 'amount', $payment ) && '' !== $payment['amount'] && null !== $payment['amount'] ) {
			$out['amount'] = $this->to_float( $payment['amount'] );
		}
		if ( array_key_exists( 'rate', $payment ) && '' !== $payment['rate'] ) {
			$out['rate'] = $this->to_float( $payment['rate'] );
		}
		if ( isset( $payment['payment_date'] ) ) {
			$out['payment_date'] = $this->sanitize_date( $payment['payment_date'] );
		}

		return $out;
	}
}
