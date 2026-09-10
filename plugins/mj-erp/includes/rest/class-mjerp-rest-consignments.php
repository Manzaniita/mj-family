<?php
defined( 'ABSPATH' ) || exit;

/**
 * /mjerp/v1 — consignees, consignments, consignment sales, payments and the current account.
 *
 * Everything here is the shop owner's side of the module and runs behind `manage_mjerp`. The
 * reseller's own screen has its own controller (MJERP_REST_Portal) so the two permission models
 * never share a route.
 *
 * @package MJ_ERP
 */
class MJERP_REST_Consignments extends MJERP_REST_Controller {

	protected $rest_base = 'consignments';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns = $this->namespace;

		/* ---------------------------------------------------------------- Consignees */

		register_rest_route(
			$ns,
			'/consignees',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_consignees',
					array(
						'search'           => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'include_inactive' => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
						'with_account'     => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
					)
				),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'create_consignee',
					array(
						'user_id'           => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'name'              => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'email'             => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'username'          => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'phone'             => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'price_list_key'    => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'credit_limit_base' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'notes'             => array( 'sanitize_callback' => array( $this, 'arg_textarea' ) ),
						'permissions'       => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
						'send_invite'       => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/consignees/candidates',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_candidates',
					array(
						'search' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'limit'  => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/consignees/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del revendedor.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'get_consignee' ),
				$this->endpoint(
					WP_REST_Server::EDITABLE,
					'update_consignee',
					array(
						'name'              => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'email'             => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'phone'             => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'price_list_key'    => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'credit_limit_base' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'notes'             => array( 'sanitize_callback' => array( $this, 'arg_textarea' ) ),
						'permissions'       => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
						'is_active'         => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
					)
				),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_consignee' ),
			)
		);

		register_rest_route(
			$ns,
			'/consignees/(?P<id>\d+)/account',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del revendedor.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'get_account' ),
			)
		);

		register_rest_route(
			$ns,
			'/consignees/(?P<id>\d+)/statement',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del revendedor.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::READABLE,
					'get_statement',
					array( 'limit' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ) )
				),
			)
		);

		register_rest_route(
			$ns,
			'/consignees/(?P<id>\d+)/stock',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del revendedor.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::READABLE,
					'get_consignee_stock',
					array( 'all' => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ) )
				),
			)
		);

		/* -------------------------------------------------------------- Consignments */

		register_rest_route(
			$ns,
			'/consignments',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_consignments',
					array(
						'consignee_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'status'       => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'search'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'page'         => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'per_page'     => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'orderby'      => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'order'        => array( 'sanitize_callback' => array( $this, 'arg_order' ) ),
					)
				),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'create_consignment',
					array(
						'consignee_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'code'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'delivered_at' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'notes'        => array( 'sanitize_callback' => array( $this, 'arg_textarea' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/consignments/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la entrega.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'get_consignment' ),
				$this->endpoint(
					WP_REST_Server::EDITABLE,
					'update_consignment',
					array(
						'code'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'consignee_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'delivered_at' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'notes'        => array( 'sanitize_callback' => array( $this, 'arg_textarea' ) ),
					)
				),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_consignment' ),
			)
		);

		register_rest_route(
			$ns,
			'/consignments/(?P<id>\d+)/status',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la entrega.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'set_consignment_status',
					array( 'status' => array( 'sanitize_callback' => array( $this, 'arg_key' ) ) )
				),
			)
		);

		register_rest_route(
			$ns,
			'/consignments/(?P<id>\d+)/items',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la entrega.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'add_consignment_item',
					array(
						'product_id'      => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'variation_id'    => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'qty'             => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'unit_price_base' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'price_list_key'  => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'reason'          => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'name'            => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'sku'             => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/consignments/(?P<id>\d+)/items/(?P<item_id>\d+)',
			array(
				'args' => array(
					'id'      => $this->id_arg( __( 'ID de la entrega.', 'mj-erp' ) ),
					'item_id' => $this->id_arg( __( 'ID de la línea.', 'mj-erp' ) ),
				),
				$this->endpoint(
					WP_REST_Server::EDITABLE,
					'update_consignment_item',
					array(
						'qty'             => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'unit_price_base' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'price_list_key'  => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'name'            => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'sku'             => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'sort_order'      => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_consignment_item' ),
			)
		);

		register_rest_route(
			$ns,
			'/consignments/price-suggestion',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'price_suggestion',
					array(
						'product_id'     => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'consignee_id'   => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'price_list_key' => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
					)
				),
			)
		);

		/* --------------------------------------------------------------------- Sales */

		register_rest_route(
			$ns,
			'/consignment-sales',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_sales',
					array(
						'consignee_id'   => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'consignment_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'status'         => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'unpaid'         => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
						'date_from'      => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'date_to'        => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'page'           => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'per_page'       => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'order'          => array( 'sanitize_callback' => array( $this, 'arg_order' ) ),
					)
				),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'create_sale',
					array(
						'consignment_item_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'qty'                 => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'unit_price_base'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
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
			'/consignment-sales/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'get_sale' ),
				$this->endpoint(
					WP_REST_Server::EDITABLE,
					'update_sale',
					array(
						'qty'             => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'unit_price_base' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'sale_date'       => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'retail_amount'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'retail_currency' => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
						'note'            => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_sale' ),
			)
		);

		register_rest_route(
			$ns,
			'/consignment-sales/(?P<id>\d+)/approve',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'approve_sale' ),
			)
		);

		register_rest_route(
			$ns,
			'/consignment-sales/(?P<id>\d+)/reject',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la venta.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'reject_sale',
					array( 'note' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ) )
				),
			)
		);

		/* ------------------------------------------------------------------- Returns */

		register_rest_route(
			$ns,
			'/consignment-returns',
			array(
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'create_return',
					array(
						'consignment_item_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'qty'                 => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'return_date'         => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'note'                => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/consignment-returns/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la devolución.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_return' ),
			)
		);

		/* ------------------------------------------------------------------ Payments */

		register_rest_route(
			$ns,
			'/consignment-payments',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_payments',
					array(
						'consignee_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'kind'         => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'status'       => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'date_from'    => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'date_to'      => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'page'         => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'per_page'     => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'order'        => array( 'sanitize_callback' => array( $this, 'arg_order' ) ),
					)
				),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'create_payment',
					array(
						'consignee_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'kind'         => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'amount'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'currency'     => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
						'rate'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'payment_date' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'method'       => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'cashbox_id'   => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'reference'    => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'reason'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'note'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/consignment-payments/preview',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'preview_allocation',
					array(
						'consignee_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'amount'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'currency'     => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
						'rate'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/consignment-payments/(?P<id>\d+)/confirm',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del pago.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'confirm_payment' ),
			)
		);

		register_rest_route(
			$ns,
			'/consignment-payments/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del pago.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'get_payment' ),
				$this->endpoint(
					WP_REST_Server::EDITABLE,
					'update_payment',
					array(
						'amount'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'currency'     => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
						'rate'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'payment_date' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'method'       => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'cashbox_id'   => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'reference'    => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'reason'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'note'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_payment' ),
			)
		);

		/*
		 * Los comprobantes se miran desde acá y no rebotando a wp-admin: quien lleva el ERP no
		 * necesariamente entra al panel de WordPress, y mandarlo a una pantalla que quizá no
		 * puede abrir es lo mismo que no dejarlo ver el comprobante.
		 */
		register_rest_route(
			$ns,
			'/consignment-payments/(?P<id>\d+)/receipts',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del pago.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'list_payment_receipts' ),
			)
		);

		register_rest_route(
			$ns,
			'/receipts/(?P<id>\d+)/review',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del comprobante.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'review_receipt',
					array(
						'decision' => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'reason'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Comprobantes
	 * ---------------------------------------------------------------- */

	/**
	 * GET /consignment-payments/{id}/receipts
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_payment_receipts( $request ) {
		$id = absint( $request['id'] );

		$payment = MJERP_Consignment_Payments_Repo::find( $id );
		if ( ! $payment ) {
			return $this->fail( 'mjerp_not_found', __( 'El pago no existe.', 'mj-erp' ), 404 );
		}
		if ( ! class_exists( 'MJPM_Receipts' ) || ! class_exists( 'MJERP_Receipts' ) ) {
			return $this->fail(
				'mjerp_receipts_unavailable',
				__( 'MJ Medios de Pago no está activo, así que no hay comprobantes que mostrar.', 'mj-erp' ),
				409
			);
		}

		$can_review = MJERP_Receipts::can_review();
		$items      = array();

		foreach ( (array) MJPM_Receipts::for_object( MJERP_Receipts::CONTEXT, $id ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$items[] = array_merge(
				$row,
				array(
					'url'          => MJPM_Receipts::file_url( $row ),
					'download_url' => MJPM_Receipts::file_url( $row, true ),
					'can_review'   => $can_review,
				)
			);
		}

		return $this->ok(
			array(
				'items'      => $items,
				'total'      => count( $items ),
				'can_review' => $can_review,
			)
		);
	}

	/**
	 * POST /receipts/{id}/review — aprobar o rechazar un comprobante sin salir del ERP.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function review_receipt( $request ) {
		if ( ! class_exists( 'MJPM_Receipts' ) || ! class_exists( 'MJERP_Receipts' ) ) {
			return $this->fail( 'mjerp_receipts_unavailable', __( 'MJ Medios de Pago no está activo.', 'mj-erp' ), 409 );
		}
		if ( ! MJERP_Receipts::can_review() ) {
			return $this->fail( 'mjerp_forbidden', __( 'No tenés permiso para revisar comprobantes.', 'mj-erp' ), 403 );
		}

		$id       = absint( $request['id'] );
		$p        = $this->params( $request );
		$decision = $this->str( $p, 'decision' );

		if ( ! in_array( $decision, array( 'aprobado', 'rechazado', 'pendiente' ), true ) ) {
			return $this->fail( 'mjerp_invalid_decision', __( 'La decisión tiene que ser aprobado, rechazado o pendiente.', 'mj-erp' ), 400, array( 'field' => 'decision' ) );
		}

		$receipt = MJPM_Receipts::find( $id );
		if ( ! $receipt ) {
			return $this->fail( 'mjerp_not_found', __( 'El comprobante no existe.', 'mj-erp' ), 404 );
		}
		// Solo comprobantes de pagos del ERP: esta ruta no es una puerta trasera al resto.
		if ( MJERP_Receipts::CONTEXT !== (string) $receipt['context'] ) {
			return $this->fail( 'mjerp_not_found', __( 'Ese comprobante no es de un pago de reventa.', 'mj-erp' ), 404 );
		}

		$result = MJPM_Receipts::review( $id, $decision, $this->str( $p, 'reason', '', 255 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( array( 'receipt' => MJPM_Receipts::find( $id ) ) );
	}

	/* ------------------------------------------------------------------
	 * Consignees
	 * ---------------------------------------------------------------- */

	/**
	 * GET /consignees
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_consignees( $request ) {
		$p        = $this->params( $request );
		$search   = $this->str( $p, 'search' );
		$inactive = $this->to_bool( $this->has( $p, 'include_inactive' ) ? $p['include_inactive'] : false, false );

		$items = '' !== $search
			? MJERP_Consignees::search( $search, $inactive )
			: MJERP_Consignees::all( $inactive );

		if ( $this->to_bool( $this->has( $p, 'with_account' ) ? $p['with_account'] : true, true ) ) {
			foreach ( $items as $index => $consignee ) {
				$items[ $index ]['account'] = MJERP_Consignment_Account::summary( (int) $consignee['id'] );
			}
		}

		return $this->ok(
			array(
				'items'  => $items,
				'total'  => count( $items ),
				'totals' => MJERP_Consignment_Account::totals(),
			)
		);
	}

	/**
	 * GET /consignees/candidates
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_candidates( $request ) {
		$p = $this->params( $request );
		return $this->ok(
			array(
				'items' => MJERP_Consignees::candidate_users( $this->str( $p, 'search' ), $this->int( $p, 'limit', 20 ) ),
				'can_create_users' => current_user_can( 'create_users' ),
			)
		);
	}

	/**
	 * POST /consignees
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_consignee( $request ) {
		$p = $this->params( $request );

		$data = array_intersect_key(
			$p,
			array_flip( array( 'user_id', 'name', 'email', 'username', 'phone', 'price_list_key', 'credit_limit_base', 'notes', 'permissions', 'send_invite' ) )
		);

		// Never sanitized away: a password has to travel verbatim.
		$raw = $request->get_json_params();
		if ( is_array( $raw ) && isset( $raw['password'] ) && is_string( $raw['password'] ) ) {
			$data['password'] = $raw['password'];
		}

		$id = MJERP_Consignees::create( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$consignee = MJERP_Consignees::find( $id );
		$password  = MJERP_Consignees::take_generated_password();

		return $this->ok(
			array(
				'consignee' => $consignee,
				'account'   => MJERP_Consignment_Account::summary( (int) $id ),
				// Shown once so the shop owner can hand it over; never stored anywhere.
				'generated_password' => $password,
			),
			201
		);
	}

	/**
	 * GET /consignees/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_consignee( $request ) {
		$id        = absint( $request['id'] );
		$dashboard = MJERP_Consignment_Account::dashboard( $id );
		if ( ! $dashboard ) {
			return $this->fail( 'mjerp_not_found', __( 'El revendedor no existe.', 'mj-erp' ), 404 );
		}
		return $this->ok( $dashboard );
	}

	/**
	 * PUT /consignees/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_consignee( $request ) {
		$id = absint( $request['id'] );
		$p  = $this->params( $request );

		$data = array();
		foreach ( array( 'name', 'email', 'phone', 'price_list_key', 'credit_limit_base', 'notes', 'permissions', 'is_active' ) as $key ) {
			if ( $this->has( $p, $key ) ) {
				$data[ $key ] = $p[ $key ];
			}
		}

		$result = MJERP_Consignees::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok(
			array(
				'consignee' => MJERP_Consignees::find( $id ),
				'account'   => MJERP_Consignment_Account::summary( $id ),
			)
		);
	}

	/**
	 * DELETE /consignees/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_consignee( $request ) {
		$result = MJERP_Consignees::delete( absint( $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * GET /consignees/{id}/account
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_account( $request ) {
		$id = absint( $request['id'] );
		if ( ! MJERP_Consignees::find( $id ) ) {
			return $this->fail( 'mjerp_not_found', __( 'El revendedor no existe.', 'mj-erp' ), 404 );
		}
		return $this->ok( MJERP_Consignment_Account::summary( $id ) );
	}

	/**
	 * GET /consignees/{id}/statement
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_statement( $request ) {
		$id = absint( $request['id'] );
		if ( ! MJERP_Consignees::find( $id ) ) {
			return $this->fail( 'mjerp_not_found', __( 'El revendedor no existe.', 'mj-erp' ), 404 );
		}
		$p = $this->params( $request );
		return $this->ok(
			array(
				'items'   => MJERP_Consignment_Account::statement( $id, $this->int( $p, 'limit', 200 ) ),
				'account' => MJERP_Consignment_Account::summary( $id ),
			)
		);
	}

	/**
	 * GET /consignees/{id}/stock
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_consignee_stock( $request ) {
		$id = absint( $request['id'] );
		if ( ! MJERP_Consignees::find( $id ) ) {
			return $this->fail( 'mjerp_not_found', __( 'El revendedor no existe.', 'mj-erp' ), 404 );
		}
		$p   = $this->params( $request );
		$all = $this->to_bool( $this->has( $p, 'all' ) ? $p['all'] : false, false );

		return $this->ok(
			array(
				'items'  => MJERP_Consignments_Repo::stock_on_hand( $id, ! $all ),
				'totals' => MJERP_Consignments_Repo::on_hand_totals( $id ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Consignments
	 * ---------------------------------------------------------------- */

	/**
	 * GET /consignments
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_consignments( $request ) {
		$p = $this->params( $request );

		$result = MJERP_Consignments_Repo::query(
			array(
				'consignee_id' => $this->int( $p, 'consignee_id', 0 ),
				'status'       => $this->str( $p, 'status' ),
				'search'       => $this->str( $p, 'search' ),
				'page'         => $this->int( $p, 'page', 1 ),
				'per_page'     => $this->int( $p, 'per_page', 20 ),
				'orderby'      => $this->str( $p, 'orderby', 'delivered_at' ),
				'order'        => $this->str( $p, 'order', 'DESC' ),
			)
		);

		// The list shows who each delivery went to.
		$names = array();
		foreach ( $result['items'] as $index => $row ) {
			$cid = (int) $row['consignee_id'];
			if ( ! isset( $names[ $cid ] ) ) {
				$consignee     = MJERP_Consignees::find( $cid );
				$names[ $cid ] = $consignee ? (string) $consignee['name'] : '';
			}
			$result['items'][ $index ]['consignee_name'] = $names[ $cid ];
		}

		$result['statuses'] = MJERP_Settings::consignment_statuses();

		return $this->ok( $result );
	}

	/**
	 * POST /consignments
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_consignment( $request ) {
		$p = $this->params( $request );

		$id = MJERP_Consignments_Repo::create(
			array(
				'consignee_id' => $this->int( $p, 'consignee_id', 0 ),
				'code'         => $this->str( $p, 'code' ),
				'delivered_at' => $this->str( $p, 'delivered_at' ),
				'notes'        => $this->str( $p, 'notes' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->ok( MJERP_Consignments_Repo::get_full( $id ), 201 );
	}

	/**
	 * GET /consignments/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_consignment( $request ) {
		$full = MJERP_Consignments_Repo::get_full( absint( $request['id'] ) );
		if ( ! $full ) {
			return $this->fail( 'mjerp_not_found', __( 'La entrega no existe.', 'mj-erp' ), 404 );
		}
		$full['sales'] = MJERP_Consignment_Sales_Repo::query(
			array(
				'consignment_id' => (int) $full['id'],
				'per_page'       => 200,
			)
		);
		return $this->ok( $full );
	}

	/**
	 * PUT /consignments/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_consignment( $request ) {
		$id = absint( $request['id'] );
		$p  = $this->params( $request );

		$data = array();
		foreach ( array( 'code', 'consignee_id', 'delivered_at', 'notes' ) as $key ) {
			if ( $this->has( $p, $key ) ) {
				$data[ $key ] = $p[ $key ];
			}
		}

		$result = MJERP_Consignments_Repo::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( MJERP_Consignments_Repo::get_full( $id ) );
	}

	/**
	 * DELETE /consignments/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_consignment( $request ) {
		$result = MJERP_Consignments_Repo::delete( absint( $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * POST /consignments/{id}/status
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_consignment_status( $request ) {
		$id = absint( $request['id'] );
		$p  = $this->params( $request );

		$result = MJERP_Consignments_Repo::set_status( $id, $this->str( $p, 'status' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( MJERP_Consignments_Repo::get_full( $id ) );
	}

	/**
	 * POST /consignments/{id}/items
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_consignment_item( $request ) {
		$id = absint( $request['id'] );
		$p  = $this->params( $request );

		$data = array();
		foreach ( array( 'product_id', 'variation_id', 'qty', 'unit_price_base', 'price_list_key', 'name', 'sku' ) as $key ) {
			if ( $this->has( $p, $key ) ) {
				$data[ $key ] = $p[ $key ];
			}
		}

		$item_id = MJERP_Consignments_Repo::add_item( $id, $data );
		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		return $this->ok( MJERP_Consignments_Repo::get_full( $id ), 201 );
	}

	/**
	 * PUT /consignments/{id}/items/{item_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_consignment_item( $request ) {
		$id      = absint( $request['id'] );
		$item_id = absint( $request['item_id'] );
		$p       = $this->params( $request );

		$item = MJERP_Consignments_Repo::find_item( $item_id );
		if ( ! $item || (int) $item['consignment_id'] !== $id ) {
			return $this->fail( 'mjerp_not_found', __( 'El producto no pertenece a esta entrega.', 'mj-erp' ), 404 );
		}

		$data = array();
		foreach ( array( 'qty', 'unit_price_base', 'price_list_key', 'name', 'sku', 'sort_order' ) as $key ) {
			if ( $this->has( $p, $key ) ) {
				$data[ $key ] = $p[ $key ];
			}
		}
		// No es una columna: viaja para quedar anotado en la bitácora del revendedor.
		if ( $this->has( $p, 'reason' ) ) {
			$data['reason'] = $this->str( $p, 'reason', '', 255 );
		}

		$result = MJERP_Consignments_Repo::update_item( $item_id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( MJERP_Consignments_Repo::get_full( $id ) );
	}

	/**
	 * DELETE /consignments/{id}/items/{item_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_consignment_item( $request ) {
		$id      = absint( $request['id'] );
		$item_id = absint( $request['item_id'] );

		$item = MJERP_Consignments_Repo::find_item( $item_id );
		if ( ! $item || (int) $item['consignment_id'] !== $id ) {
			return $this->fail( 'mjerp_not_found', __( 'El producto no pertenece a esta entrega.', 'mj-erp' ), 404 );
		}

		$result = MJERP_Consignments_Repo::delete_item( $item_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( MJERP_Consignments_Repo::get_full( $id ) );
	}

	/**
	 * GET /consignments/price-suggestion
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function price_suggestion( $request ) {
		$p          = $this->params( $request );
		$product_id = $this->int( $p, 'product_id', 0 );
		if ( $product_id <= 0 ) {
			return $this->fail( 'mjerp_invalid_product', __( 'Falta el producto.', 'mj-erp' ), 400 );
		}

		return $this->ok(
			MJERP_Consignees::suggested_price(
				$product_id,
				$this->int( $p, 'consignee_id', 0 ),
				$this->str( $p, 'price_list_key' )
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Sales
	 * ---------------------------------------------------------------- */

	/**
	 * GET /consignment-sales
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_sales( $request ) {
		$p = $this->params( $request );

		$result = MJERP_Consignment_Sales_Repo::query(
			array(
				'consignee_id'   => $this->int( $p, 'consignee_id', 0 ),
				'consignment_id' => $this->int( $p, 'consignment_id', 0 ),
				'status'         => $this->str( $p, 'status' ),
				'unpaid'         => $this->to_bool( $this->has( $p, 'unpaid' ) ? $p['unpaid'] : false, false ),
				'date_from'      => $this->str( $p, 'date_from' ),
				'date_to'        => $this->str( $p, 'date_to' ),
				'page'           => $this->int( $p, 'page', 1 ),
				'per_page'       => $this->int( $p, 'per_page', 20 ),
				'order'          => $this->str( $p, 'order', 'DESC' ),
			)
		);

		$names = array();
		foreach ( $result['items'] as $index => $row ) {
			$cid = (int) $row['consignee_id'];
			if ( ! isset( $names[ $cid ] ) ) {
				$consignee     = MJERP_Consignees::find( $cid );
				$names[ $cid ] = $consignee ? (string) $consignee['name'] : '';
			}
			$result['items'][ $index ]['consignee_name'] = $names[ $cid ];
		}

		$result['pending_total'] = MJERP_Consignment_Sales_Repo::pending_count( $this->int( $p, 'consignee_id', 0 ) );

		return $this->ok( $result );
	}

	/**
	 * POST /consignment-sales
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_sale( $request ) {
		$p = $this->params( $request );

		$data = array();
		foreach ( array( 'consignment_item_id', 'qty', 'unit_price_base', 'sale_date', 'retail_amount', 'retail_currency', 'note' ) as $key ) {
			if ( $this->has( $p, $key ) ) {
				$data[ $key ] = $p[ $key ];
			}
		}

		$id = MJERP_Consignment_Sales_Repo::create( $data, MJERP_Consignment_Sales_Repo::SOURCE_ADMIN );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$sale = MJERP_Consignment_Sales_Repo::find( $id );

		return $this->ok(
			array(
				'sale'    => $sale,
				'account' => MJERP_Consignment_Account::summary( (int) $sale['consignee_id'] ),
			),
			201
		);
	}

	/**
	 * GET /consignment-sales/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_sale( $request ) {
		$sale = MJERP_Consignment_Sales_Repo::find( absint( $request['id'] ) );
		if ( ! $sale ) {
			return $this->fail( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), 404 );
		}
		$sale['allocations'] = MJERP_Consignment_Payments_Repo::allocations_for_sale( (int) $sale['id'] );
		return $this->ok( $sale );
	}

	/**
	 * PUT /consignment-sales/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_sale( $request ) {
		$id = absint( $request['id'] );
		$p  = $this->params( $request );

		$data = array();
		foreach ( array( 'qty', 'unit_price_base', 'sale_date', 'retail_amount', 'retail_currency', 'note' ) as $key ) {
			if ( $this->has( $p, $key ) ) {
				$data[ $key ] = $p[ $key ];
			}
		}

		$result = MJERP_Consignment_Sales_Repo::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$sale = MJERP_Consignment_Sales_Repo::find( $id );

		return $this->ok(
			array(
				'sale'    => $sale,
				'account' => MJERP_Consignment_Account::summary( (int) $sale['consignee_id'] ),
			)
		);
	}

	/**
	 * DELETE /consignment-sales/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_sale( $request ) {
		$sale = MJERP_Consignment_Sales_Repo::find( absint( $request['id'] ) );
		if ( ! $sale ) {
			return $this->fail( 'mjerp_not_found', __( 'La venta no existe.', 'mj-erp' ), 404 );
		}

		$result = MJERP_Consignment_Sales_Repo::delete( (int) $sale['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok(
			array(
				'deleted' => true,
				'account' => MJERP_Consignment_Account::summary( (int) $sale['consignee_id'] ),
			)
		);
	}

	/**
	 * POST /consignment-sales/{id}/approve
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_sale( $request ) {
		$id     = absint( $request['id'] );
		$result = MJERP_Consignment_Sales_Repo::approve( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$sale = MJERP_Consignment_Sales_Repo::find( $id );

		return $this->ok(
			array(
				'sale'    => $sale,
				'account' => MJERP_Consignment_Account::summary( (int) $sale['consignee_id'] ),
			)
		);
	}

	/**
	 * POST /consignment-sales/{id}/reject
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject_sale( $request ) {
		$id = absint( $request['id'] );
		$p  = $this->params( $request );

		$result = MJERP_Consignment_Sales_Repo::reject( $id, $this->str( $p, 'note' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$sale = MJERP_Consignment_Sales_Repo::find( $id );

		return $this->ok(
			array(
				'sale'    => $sale,
				'account' => MJERP_Consignment_Account::summary( (int) $sale['consignee_id'] ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Returns
	 * ---------------------------------------------------------------- */

	/**
	 * POST /consignment-returns
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_return( $request ) {
		$p = $this->params( $request );

		$id = MJERP_Consignments_Repo::add_return(
			array(
				'consignment_item_id' => $this->int( $p, 'consignment_item_id', 0 ),
				'qty'                 => $this->str( $p, 'qty' ),
				'return_date'         => $this->str( $p, 'return_date' ),
				'note'                => $this->str( $p, 'note' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$item = MJERP_Consignments_Repo::find_item( $this->int( $p, 'consignment_item_id', 0 ) );

		return $this->ok(
			array(
				'id'      => $id,
				'item'    => $item,
				'account' => $item ? MJERP_Consignment_Account::summary( (int) $item['consignee_id'] ) : null,
			),
			201
		);
	}

	/**
	 * DELETE /consignment-returns/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_return( $request ) {
		$result = MJERP_Consignments_Repo::delete_return( absint( $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	/* ------------------------------------------------------------------
	 * Payments
	 * ---------------------------------------------------------------- */

	/**
	 * GET /consignment-payments
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_payments( $request ) {
		$p = $this->params( $request );

		$result = MJERP_Consignment_Payments_Repo::query(
			array(
				'consignee_id' => $this->int( $p, 'consignee_id', 0 ),
				'kind'         => $this->str( $p, 'kind' ),
				'status'       => $this->str( $p, 'status' ),
				'date_from'    => $this->str( $p, 'date_from' ),
				'date_to'      => $this->str( $p, 'date_to' ),
				'page'         => $this->int( $p, 'page', 1 ),
				'per_page'     => $this->int( $p, 'per_page', 20 ),
				'order'        => $this->str( $p, 'order', 'DESC' ),
			)
		);

		$result['methods']        = MJERP_Settings::payment_methods();
		$result['declared_count'] = MJERP_Consignment_Payments_Repo::declared_count( $this->int( $p, 'consignee_id', 0 ) );
		$result                   = $this->with_receipts( $result );

		return $this->ok( $result );
	}

	/**
	 * POST /consignment-payments/{id}/confirm — the shop owner confirms a payment the reseller
	 * declared, which is when it starts cancelling their debt.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm_payment( $request ) {
		$id      = $this->route_id( $request );
		$payment = MJERP_Consignment_Payments_Repo::find( $id );
		if ( ! $payment ) {
			return $this->fail( 'mjerp_not_found', __( 'El pago no existe.', 'mj-erp' ), 404 );
		}

		$result = MJERP_Consignment_Payments_Repo::confirm( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok(
			array(
				'payment' => MJERP_Consignment_Payments_Repo::find( $id ),
				'account' => MJERP_Consignment_Account::summary( (int) $payment['consignee_id'] ),
			)
		);
	}

	/**
	 * GET /consignment-payments/preview — what an amount would pay for, without saving anything.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_allocation( $request ) {
		$p            = $this->params( $request );
		$consignee_id = $this->int( $p, 'consignee_id', 0 );

		if ( ! MJERP_Consignees::find( $consignee_id ) ) {
			return $this->fail( 'mjerp_not_found', __( 'El revendedor no existe.', 'mj-erp' ), 404 );
		}

		$amount   = $this->to_float( $this->has( $p, 'amount' ) ? $p['amount'] : 0, 0 );
		$currency = $this->clean_currency( $this->has( $p, 'currency' ) ? $p['currency'] : $this->setting( 'local_currency', 'ARS' ) );

		$rate = $this->to_float( $this->has( $p, 'rate' ) ? $p['rate'] : 0, 0 );
		if ( $rate <= 0 ) {
			$rate = class_exists( 'MJERP_Dolar' ) ? MJERP_Dolar::rate() : 0.0;
		}
		if ( $rate <= 0 ) {
			$rate = MJERP_Helpers::to_float( $this->setting( 'sale_rate', 1 ) );
		}
		if ( $rate <= 0 ) {
			$rate = 1.0;
		}

		$base_currency = strtoupper( (string) $this->setting( 'base_currency', 'USD' ) );
		$amount_base   = round( MJERP_Helpers::to_base( $amount, $currency, $rate, $base_currency ), 4 );

		$plan = MJERP_Consignment_Payments_Repo::plan_fifo( $consignee_id, $amount_base );

		return $this->ok(
			array(
				'amount'      => round( $amount, 4 ),
				'currency'    => $currency,
				'rate'        => round( $rate, 6 ),
				'amount_base' => $amount_base,
				'lines'       => $plan['lines'],
				'allocated'   => $plan['allocated'],
				'unallocated' => $plan['unallocated'],
				'outstanding' => MJERP_Consignment_Sales_Repo::outstanding( $consignee_id ),
				'account'     => MJERP_Consignment_Account::summary( $consignee_id ),
			)
		);
	}

	/**
	 * POST /consignment-payments
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_payment( $request ) {
		$p = $this->params( $request );

		$data = array();
		foreach ( array( 'consignee_id', 'kind', 'amount', 'currency', 'rate', 'payment_date', 'method', 'cashbox_id', 'reference', 'reason', 'note' ) as $key ) {
			if ( $this->has( $p, $key ) ) {
				$data[ $key ] = $p[ $key ];
			}
		}

		$allocations = $this->read_allocations( $request );
		if ( is_wp_error( $allocations ) ) {
			return $allocations;
		}
		if ( null !== $allocations ) {
			$data['allocations'] = $allocations;
		}

		$id = MJERP_Consignment_Payments_Repo::create( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$payment = MJERP_Consignment_Payments_Repo::find( $id );

		return $this->ok(
			array(
				'payment' => $payment,
				'account' => MJERP_Consignment_Account::summary( (int) $payment['consignee_id'] ),
			),
			201
		);
	}

	/**
	 * GET /consignment-payments/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_payment( $request ) {
		$payment = MJERP_Consignment_Payments_Repo::find( absint( $request['id'] ) );
		if ( ! $payment ) {
			return $this->fail( 'mjerp_not_found', __( 'El pago no existe.', 'mj-erp' ), 404 );
		}
		if ( class_exists( 'MJERP_Receipts' ) && MJERP_Receipts::available() ) {
			$payment['receipt'] = MJERP_Receipts::payload( $payment );
		}
		return $this->ok( $payment );
	}

	/**
	 * Agrega a cada pago su bloque de comprobante, para que la pantalla del
	 * revendedor pueda mostrar el archivo antes de confirmar el pago.
	 *
	 * @param array $result Resultado de la consulta.
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
	 * PUT /consignment-payments/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_payment( $request ) {
		$id = absint( $request['id'] );
		$p  = $this->params( $request );

		$data = array();
		foreach ( array( 'amount', 'currency', 'rate', 'payment_date', 'method', 'cashbox_id', 'reference', 'reason', 'note' ) as $key ) {
			if ( $this->has( $p, $key ) ) {
				$data[ $key ] = $p[ $key ];
			}
		}

		$allocations = $this->read_allocations( $request );
		if ( is_wp_error( $allocations ) ) {
			return $allocations;
		}
		if ( null !== $allocations ) {
			$data['allocations'] = $allocations;
		}

		$result = MJERP_Consignment_Payments_Repo::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payment = MJERP_Consignment_Payments_Repo::find( $id );

		return $this->ok(
			array(
				'payment' => $payment,
				'account' => MJERP_Consignment_Account::summary( (int) $payment['consignee_id'] ),
			)
		);
	}

	/**
	 * DELETE /consignment-payments/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_payment( $request ) {
		$payment = MJERP_Consignment_Payments_Repo::find( absint( $request['id'] ) );
		if ( ! $payment ) {
			return $this->fail( 'mjerp_not_found', __( 'El pago no existe.', 'mj-erp' ), 404 );
		}

		/* El comprobante se va con el pago. */
		if ( class_exists( 'MJPM_Receipts' ) && class_exists( 'MJERP_Receipts' ) ) {
			MJPM_Receipts::delete_for_object( MJERP_Receipts::CONTEXT, (int) $payment['id'] );
		}

		$result = MJERP_Consignment_Payments_Repo::delete( (int) $payment['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok(
			array(
				'deleted' => true,
				'account' => MJERP_Consignment_Account::summary( (int) $payment['consignee_id'] ),
			)
		);
	}

	/**
	 * Reads the `allocations` param: null when absent (plan automatically), array when given.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|null|WP_Error
	 */
	protected function read_allocations( $request ) {
		$p = $this->params( $request );

		if ( ! array_key_exists( 'allocations', $p ) ) {
			return null;
		}
		$raw = $p['allocations'];
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		if ( ! is_array( $raw ) ) {
			return $this->fail( 'mjerp_invalid_allocations', __( 'Las imputaciones tienen que ser una lista.', 'mj-erp' ), 400, array( 'field' => 'allocations' ) );
		}

		$out = array();
		foreach ( $raw as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$out[] = array(
				'sale_id'     => isset( $line['sale_id'] ) ? absint( $line['sale_id'] ) : 0,
				'amount_base' => isset( $line['amount_base'] ) ? $this->to_float( $line['amount_base'], 0 ) : 0,
				'reason'      => isset( $line['reason'] ) ? sanitize_text_field( (string) $line['reason'] ) : '',
			);
		}

		return $out;
	}
}
