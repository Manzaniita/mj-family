<?php
defined( 'ABSPATH' ) || exit;

/**
 * /mjerp/v1/purchases — purchases, items, expenses, status workflow, receive/unreceive.
 */
class MJERP_REST_Purchases extends MJERP_REST_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'purchases';

	/**
	 * Header fields accepted on create / update.
	 *
	 * @var array
	 */
	protected $header_fields = array( 'code', 'purchase_date', 'supplier_id', 'supplier_name', 'destination', 'base_currency', 'default_rate', 'proration_method', 'notes' );

	/**
	 * Allowed status transitions via POST /status.
	 *
	 * @var array
	 */
	protected $transitions = array(
		'borrador'    => array( 'en_transito', 'cancelada' ),
		'en_transito' => array( 'borrador', 'cancelada' ),
		'cancelada'   => array( 'borrador' ),
		'recibida'    => array(),
	);

	/* ---------------------------------------------------------------------
	 * Routes
	 * ------------------------------------------------------------------ */

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Registered BEFORE the /{id} routes so it can never collide with them.
		register_rest_route(
			$ns,
			'/purchases/next-code',
			array(
				$this->endpoint( WP_REST_Server::READABLE, 'next_code' ),
			)
		);

		register_rest_route(
			$ns,
			'/purchases',
			array(
				$this->endpoint( WP_REST_Server::READABLE, 'list_purchases', $this->list_args() ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'create_purchase', $this->header_args() ),
			)
		);

		register_rest_route(
			$ns,
			'/purchases/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la compra.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'get_purchase' ),
				$this->endpoint( WP_REST_Server::EDITABLE, 'update_purchase', $this->header_args() ),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_purchase' ),
			)
		);

		register_rest_route(
			$ns,
			'/purchases/(?P<id>\d+)/duplicate',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la compra.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'duplicate_purchase' ),
			)
		);

		register_rest_route(
			$ns,
			'/purchases/(?P<id>\d+)/status',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la compra.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'change_status',
					array(
						'status' => array(
							'description'       => __( 'Nuevo estado: borrador, en_transito o cancelada.', 'mj-erp' ),
							'sanitize_callback' => array( $this, 'arg_key' ),
						),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/purchases/(?P<id>\d+)/items',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la compra.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'add_item', $this->item_args() ),
			)
		);

		register_rest_route(
			$ns,
			'/purchases/(?P<id>\d+)/items/bulk',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la compra.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'bulk_items', $this->bulk_args() ),
			)
		);

		register_rest_route(
			$ns,
			'/purchases/(?P<id>\d+)/items/(?P<item_id>\d+)',
			array(
				'args' => array(
					'id'      => $this->id_arg( __( 'ID de la compra.', 'mj-erp' ) ),
					'item_id' => $this->id_arg( __( 'ID del ítem.', 'mj-erp' ) ),
				),
				$this->endpoint( WP_REST_Server::EDITABLE, 'update_item', $this->item_args() ),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_item' ),
			)
		);

		register_rest_route(
			$ns,
			'/purchases/(?P<id>\d+)/items/(?P<item_id>\d+)/link',
			array(
				'args' => array(
					'id'      => $this->id_arg( __( 'ID de la compra.', 'mj-erp' ) ),
					'item_id' => $this->id_arg( __( 'ID del ítem.', 'mj-erp' ) ),
				),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'link_item',
					array(
						'product_id'   => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'variation_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/purchases/(?P<id>\d+)/expenses',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la compra.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'add_expense', $this->expense_args() ),
			)
		);

		register_rest_route(
			$ns,
			'/purchases/(?P<id>\d+)/expenses/(?P<expense_id>\d+)',
			array(
				'args' => array(
					'id'         => $this->id_arg( __( 'ID de la compra.', 'mj-erp' ) ),
					'expense_id' => $this->id_arg( __( 'ID del gasto.', 'mj-erp' ) ),
				),
				$this->endpoint( WP_REST_Server::EDITABLE, 'update_expense', $this->expense_args() ),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_expense' ),
			)
		);

		register_rest_route(
			$ns,
			'/purchases/(?P<id>\d+)/receive',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la compra.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'receive_purchase', $this->receive_args() ),
			)
		);

		register_rest_route(
			$ns,
			'/purchases/(?P<id>\d+)/unreceive',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la compra.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'unreceive_purchase',
					array(
						'note' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Args schemas
	 * ------------------------------------------------------------------ */

	/**
	 * GET /purchases args.
	 *
	 * @return array
	 */
	protected function list_args() {
		return array(
			'status'      => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
			'supplier_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
			'search'      => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'date_from'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'date_to'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'page'        => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
			'per_page'    => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
			'orderby'     => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
			'order'       => array( 'sanitize_callback' => array( $this, 'arg_order' ) ),
		);
	}

	/**
	 * Header fields args (create / update).
	 *
	 * @return array
	 */
	protected function header_args() {
		return array(
			'code'             => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'purchase_date'    => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'supplier_id'      => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
			'supplier_name'    => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'destination'      => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'base_currency'    => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
			'default_rate'     => array( 'sanitize_callback' => array( $this, 'arg_float' ) ),
			'proration_method' => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
			'notes'            => array( 'sanitize_callback' => array( $this, 'arg_textarea' ) ),
		);
	}

	/**
	 * Item args.
	 *
	 * @return array
	 */
	protected function item_args() {
		return array(
			'product_id'   => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
			'variation_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
			'name'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'sku'          => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'quantity'     => array( 'sanitize_callback' => array( $this, 'arg_float' ) ),
			'unit_cost'    => array( 'sanitize_callback' => array( $this, 'arg_float' ) ),
			'currency'     => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
			'rate'         => array( 'sanitize_callback' => array( $this, 'arg_float' ) ),
			'sort_order'   => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
		);
	}

	/**
	 * Expense args.
	 *
	 * @return array
	 */
	protected function expense_args() {
		return array(
			'type'         => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
			'description'  => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'amount'       => array( 'sanitize_callback' => array( $this, 'arg_float' ) ),
			'currency'     => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
			'rate'         => array( 'sanitize_callback' => array( $this, 'arg_float' ) ),
			'is_prorated'  => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
			'cashbox_id'   => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
			'expense_date' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'sort_order'   => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
		);
	}

	/**
	 * Bulk import args.
	 *
	 * Each row accepts: product_id?, variation_id?, sku?, name?, quantity,
	 * unit_cost, currency?, rate?. Rows are deep-sanitized in bulk_items().
	 *
	 * @return array
	 */
	protected function bulk_args() {
		return array(
			'rows'             => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
			'create_missing'   => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
			'match_by'         => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
			'default_currency' => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
			'default_rate'     => array( 'sanitize_callback' => array( $this, 'arg_float' ) ),
		);
	}

	/**
	 * Receive args.
	 *
	 * @return array
	 */
	protected function receive_args() {
		return array(
			'items'        => array( 'sanitize_callback' => array( $this, 'arg_array' ) ),
			'add_stock'    => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
			'update_cost'  => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
			'update_price' => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
			'price_list'   => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
			'price_target' => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
			'note'         => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Purchases
	 * ------------------------------------------------------------------ */

	/**
	 * GET /purchases/next-code
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function next_code( $request ) {
		$repo = $this->repo_check();
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		$code = MJERP_Purchases_Repo::next_code();
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		return $this->ok( array( 'code' => (string) $code ) );
	}

	/**
	 * GET /purchases
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_purchases( $request ) {
		$repo = $this->repo_check();
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		$p = $this->params( $request );

		$status = sanitize_key( $this->str( $p, 'status' ) );
		if ( 'all' === $status || ! array_key_exists( $status, $this->statuses() ) ) {
			$status = '';
		}

		$orderby = sanitize_key( $this->str( $p, 'orderby', 'purchase_date' ) );
		if ( ! in_array( $orderby, array( 'purchase_date', 'code', 'total', 'created_at' ), true ) ) {
			$orderby = 'purchase_date';
		}

		$order = strtoupper( $this->str( $p, 'order', 'DESC' ) );
		if ( 'ASC' !== $order ) {
			$order = 'DESC';
		}

		$page     = max( 1, $this->int( $p, 'page', 1 ) );
		$per_page = $this->int( $p, 'per_page', 20 );
		$per_page = max( 1, min( 100, $per_page ? $per_page : 20 ) );

		$date_from = $this->date( $p, 'date_from' );
		$date_to   = $this->date( $p, 'date_to' );
		if ( $date_from && $date_to && $date_from > $date_to ) {
			$tmp       = $date_from;
			$date_from = $date_to;
			$date_to   = $tmp;
		}

		$args = array(
			'status'      => $status,
			'supplier_id' => $this->int( $p, 'supplier_id', 0 ),
			'search'      => $this->str( $p, 'search', '', 190 ),
			'date_from'   => $date_from,
			'date_to'     => $date_to,
			'page'        => $page,
			'per_page'    => $per_page,
			'orderby'     => $orderby,
			'order'       => $order,
		);

		$result = MJERP_Purchases_Repo::query( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			$result = array();
		}

		$items = isset( $result['items'] ) && is_array( $result['items'] ) ? array_values( $result['items'] ) : array();
		$total = isset( $result['total'] ) ? (int) $result['total'] : count( $items );
		$pages = isset( $result['pages'] ) ? (int) $result['pages'] : (int) ceil( $total / $per_page );

		return $this->ok(
			array(
				'items'    => $items,
				'total'    => $total,
				'pages'    => max( 1, $pages ),
				'page'     => isset( $result['page'] ) ? (int) $result['page'] : $page,
				'per_page' => $per_page,
				'filters'  => $args,
			)
		);
	}

	/**
	 * POST /purchases
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_purchase( $request ) {
		$repo = $this->repo_check();
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		$p    = $this->params( $request );
		$data = $this->header_data( $p, null );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$id = MJERP_Purchases_Repo::create( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = absint( $id );
		if ( ! $id ) {
			return $this->fail( 'mjerp_create_failed', __( 'No se pudo crear la compra.', 'mj-erp' ), 500 );
		}

		$this->recalculate( $id );

		$full = $this->load_purchase( $id );
		if ( is_wp_error( $full ) ) {
			return $full;
		}

		do_action( 'mjerp_purchase_created', $id, $full );

		return $this->ok( $full, 201 );
	}

	/**
	 * GET /purchases/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_purchase( $request ) {
		$purchase = $this->load_purchase( $this->route_id( $request ) );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}
		return $this->ok( $purchase );
	}

	/**
	 * PUT /purchases/{id} — header fields. When the purchase is locked only `notes` may change.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_purchase( $request ) {
		$id       = $this->route_id( $request );
		$purchase = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}

		$p    = $this->params( $request );
		$data = $this->header_data( $p, $purchase );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data ) ) {
			return $this->ok( $purchase );
		}

		$editable = $this->is_editable( $purchase );

		if ( ! $editable ) {
			// Drop fields that did not actually change, then refuse anything but notes.
			foreach ( $data as $key => $value ) {
				if ( 'notes' === $key ) {
					continue;
				}
				$current = isset( $purchase[ $key ] ) ? $purchase[ $key ] : null;
				if ( $this->same_value( $value, $current ) ) {
					unset( $data[ $key ] );
				}
			}
			$others = array_diff_key( $data, array( 'notes' => true ) );
			if ( ! empty( $others ) ) {
				return $this->require_editable( $purchase );
			}
			if ( ! array_key_exists( 'notes', $data ) ) {
				return $this->ok( $purchase );
			}
			$data = array( 'notes' => $data['notes'] );
		}

		$result = MJERP_Purchases_Repo::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $editable ) {
			$this->recalculate( $id );
		}

		$full = $this->load_purchase( $id );
		if ( is_wp_error( $full ) ) {
			return $full;
		}

		do_action( 'mjerp_purchase_updated', $id, $full, $data );

		return $this->ok( $full );
	}

	/**
	 * DELETE /purchases/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_purchase( $request ) {
		$id       = $this->route_id( $request );
		$purchase = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}

		if ( 'recibida' === $purchase['status'] ) {
			return $this->fail(
				'mjerp_locked',
				__( 'No se puede eliminar una compra recibida. Primero deshacé la recepción.', 'mj-erp' ),
				409,
				array( 'purchase_id' => $id, 'purchase_status' => 'recibida' )
			);
		}

		$result = MJERP_Purchases_Repo::delete( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		do_action( 'mjerp_purchase_deleted', $id, $purchase );

		return $this->ok(
			array(
				'deleted' => true,
				'id'      => $id,
				'code'    => isset( $purchase['code'] ) ? (string) $purchase['code'] : '',
			)
		);
	}

	/**
	 * POST /purchases/{id}/duplicate
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_purchase( $request ) {
		$id     = $this->route_id( $request );
		$source = $this->load_purchase( $id );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$new_id   = 0;
		$warnings = array();

		if ( is_callable( array( 'MJERP_Purchases_Repo', 'duplicate' ) ) ) {
			$result = MJERP_Purchases_Repo::duplicate( $id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( is_array( $result ) && isset( $result['id'] ) ) {
				$new_id = absint( $result['id'] );
			} else {
				$new_id = absint( $result );
			}
		} else {
			$data = array(
				'status'           => 'borrador',
				'purchase_date'    => current_time( 'Y-m-d' ),
				'supplier_id'      => isset( $source['supplier_id'] ) ? absint( $source['supplier_id'] ) : 0,
				'supplier_name'    => isset( $source['supplier_name'] ) ? (string) $source['supplier_name'] : '',
				'destination'      => isset( $source['destination'] ) ? (string) $source['destination'] : '',
				'base_currency'    => isset( $source['base_currency'] ) ? (string) $source['base_currency'] : $this->setting( 'base_currency', 'USD' ),
				'default_rate'     => isset( $source['default_rate'] ) ? (float) $source['default_rate'] : 1.0,
				'proration_method' => isset( $source['proration_method'] ) ? (string) $source['proration_method'] : 'fob',
				'notes'            => isset( $source['notes'] ) ? (string) $source['notes'] : '',
			);

			$created = MJERP_Purchases_Repo::create( $data );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$new_id = absint( $created );
			if ( ! $new_id ) {
				return $this->fail( 'mjerp_create_failed', __( 'No se pudo duplicar la compra.', 'mj-erp' ), 500 );
			}

			$sort = 0;
			foreach ( (array) ( isset( $source['items'] ) ? $source['items'] : array() ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$sort++;
				$item_data = array(
					'product_id'   => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
					'variation_id' => isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0,
					'name'         => isset( $item['name'] ) ? (string) $item['name'] : '',
					'sku'          => isset( $item['sku'] ) ? (string) $item['sku'] : '',
					'quantity'     => isset( $item['quantity'] ) ? (float) $item['quantity'] : 1.0,
					'unit_cost'    => isset( $item['unit_cost'] ) ? (float) $item['unit_cost'] : 0.0,
					'currency'     => isset( $item['currency'] ) ? (string) $item['currency'] : $data['base_currency'],
					'rate'         => isset( $item['rate'] ) ? (float) $item['rate'] : $data['default_rate'],
					'sort_order'   => isset( $item['sort_order'] ) ? (int) $item['sort_order'] : $sort,
				);
				$r = MJERP_Purchases_Repo::add_item( $new_id, $item_data );
				if ( is_wp_error( $r ) ) {
					$warnings[] = sprintf(
						/* translators: 1: item name, 2: error message */
						__( 'Ítem «%1$s» no copiado: %2$s', 'mj-erp' ),
						$item_data['name'],
						$r->get_error_message()
					);
				}
			}

			$sort = 0;
			foreach ( (array) ( isset( $source['expenses'] ) ? $source['expenses'] : array() ) as $expense ) {
				if ( ! is_array( $expense ) ) {
					continue;
				}
				$sort++;
				$expense_data = array(
					'type'         => isset( $expense['type'] ) ? (string) $expense['type'] : 'otro',
					'description'  => isset( $expense['description'] ) ? (string) $expense['description'] : '',
					'amount'       => isset( $expense['amount'] ) ? (float) $expense['amount'] : 0.0,
					'currency'     => isset( $expense['currency'] ) ? (string) $expense['currency'] : $this->setting( 'local_currency', 'ARS' ),
					'rate'         => isset( $expense['rate'] ) ? (float) $expense['rate'] : $data['default_rate'],
					'is_prorated'  => ! empty( $expense['is_prorated'] ) ? 1 : 0,
					'cashbox_id'   => isset( $expense['cashbox_id'] ) ? absint( $expense['cashbox_id'] ) : 0,
					'expense_date' => null,
					'sort_order'   => isset( $expense['sort_order'] ) ? (int) $expense['sort_order'] : $sort,
				);
				$r = MJERP_Purchases_Repo::add_expense( $new_id, $expense_data );
				if ( is_wp_error( $r ) ) {
					$warnings[] = sprintf(
						/* translators: 1: expense description, 2: error message */
						__( 'Gasto «%1$s» no copiado: %2$s', 'mj-erp' ),
						'' !== $expense_data['description'] ? $expense_data['description'] : $expense_data['type'],
						$r->get_error_message()
					);
				}
			}
		}

		if ( ! $new_id ) {
			return $this->fail( 'mjerp_create_failed', __( 'No se pudo duplicar la compra.', 'mj-erp' ), 500 );
		}

		$this->recalculate( $new_id );

		$full = $this->load_purchase( $new_id );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		if ( ! empty( $warnings ) ) {
			$full['warnings'] = $warnings;
		}
		$full['duplicated_from'] = array(
			'id'   => $id,
			'code' => isset( $source['code'] ) ? (string) $source['code'] : '',
		);

		do_action( 'mjerp_purchase_duplicated', $new_id, $id );

		return $this->ok( $full, 201 );
	}

	/**
	 * POST /purchases/{id}/status
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_status( $request ) {
		$id       = $this->route_id( $request );
		$purchase = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}

		$p      = $this->params( $request );
		$status = sanitize_key( $this->str( $p, 'status' ) );

		if ( 'recibida' === $status ) {
			return $this->fail(
				'mjerp_invalid_status',
				__( 'Para marcar la compra como recibida usá la acción «Marcar recibida».', 'mj-erp' ),
				400
			);
		}
		if ( ! in_array( $status, array( 'borrador', 'en_transito', 'cancelada' ), true ) ) {
			return $this->fail( 'mjerp_invalid_status', __( 'Estado inválido.', 'mj-erp' ), 400 );
		}

		$current = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';
		if ( $current === $status ) {
			return $this->ok( $purchase );
		}

		$allowed = isset( $this->transitions[ $current ] ) ? $this->transitions[ $current ] : array();
		if ( ! in_array( $status, $allowed, true ) ) {
			$message = 'recibida' === $current
				? __( 'La compra está recibida. Para cambiar su estado primero deshacé la recepción.', 'mj-erp' )
				: sprintf(
					/* translators: 1: current status label, 2: target status label */
					__( 'No se puede pasar de «%1$s» a «%2$s».', 'mj-erp' ),
					$this->status_label( $current ),
					$this->status_label( $status )
				);
			return $this->fail(
				'mjerp_invalid_transition',
				$message,
				409,
				array( 'from' => $current, 'to' => $status )
			);
		}

		$result = MJERP_Purchases_Repo::set_status( $id, $status, array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$full = $this->load_purchase( $id );
		if ( is_wp_error( $full ) ) {
			return $full;
		}

		do_action( 'mjerp_purchase_status_changed', $id, $status, $current );

		return $this->ok( $full );
	}

	/* ---------------------------------------------------------------------
	 * Items
	 * ------------------------------------------------------------------ */

	/**
	 * POST /purchases/{id}/items
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_item( $request ) {
		$id       = $this->route_id( $request );
		$purchase = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}
		$lock = $this->require_editable( $purchase );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$p    = $this->params( $request );
		$data = $this->item_data( $p, $purchase, null );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! isset( $data['sort_order'] ) ) {
			$data['sort_order'] = $this->next_sort_order( isset( $purchase['items'] ) ? $purchase['items'] : array() );
		}

		$item_id = MJERP_Purchases_Repo::add_item( $id, $data );
		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		$this->recalculate( $id );

		$full = $this->load_purchase( $id );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		$full['last_item_id'] = absint( $item_id );

		return $this->ok( $full, 201 );
	}

	/**
	 * PUT /purchases/{id}/items/{item_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id       = $this->route_id( $request );
		$item_id  = $this->route_id( $request, 'item_id' );
		$purchase = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}
		$lock = $this->require_editable( $purchase );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$item = $this->find_item( $purchase, $item_id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$p    = $this->params( $request );
		$data = $this->item_data( $p, $purchase, $item );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data ) ) {
			return $this->ok( $purchase );
		}

		$result = MJERP_Purchases_Repo::update_item( $item_id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->recalculate( $id );

		return $this->ok( $this->load_purchase( $id ) );
	}

	/**
	 * DELETE /purchases/{id}/items/{item_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id       = $this->route_id( $request );
		$item_id  = $this->route_id( $request, 'item_id' );
		$purchase = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}
		$lock = $this->require_editable( $purchase );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$item = $this->find_item( $purchase, $item_id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$result = MJERP_Purchases_Repo::delete_item( $item_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->recalculate( $id );

		return $this->ok( $this->load_purchase( $id ) );
	}

	/**
	 * POST /purchases/{id}/items/bulk — spreadsheet import and product picker.
	 *
	 * Row shape: { product_id?: int, variation_id?: int, sku?: string,
	 * name?: string, quantity: float, unit_cost: float, currency?: string,
	 * rate?: float }. A row carrying product_id (or variation_id) is linked by
	 * id; every other row is resolved by SKU/name according to match_by.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_items( $request ) {
		$id       = $this->route_id( $request );
		$purchase = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}
		$lock = $this->require_editable( $purchase );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		if ( ! is_callable( array( 'MJERP_Purchases_Repo', 'add_items_bulk' ) ) ) {
			return $this->fail( 'mjerp_unavailable', __( 'La importación masiva no está disponible.', 'mj-erp' ), 500 );
		}

		$p    = $this->params( $request );
		$rows = isset( $p['rows'] ) && is_array( $p['rows'] ) ? array_values( $p['rows'] ) : array();

		if ( empty( $rows ) ) {
			return $this->fail( 'mjerp_invalid_rows', __( 'No se recibieron filas para importar.', 'mj-erp' ), 400 );
		}
		if ( count( $rows ) > 1000 ) {
			return $this->fail( 'mjerp_too_many_rows', __( 'Se pueden importar hasta 1000 filas por vez.', 'mj-erp' ), 400 );
		}

		$allowed       = $this->allowed_currencies();
		$base_currency = isset( $purchase['base_currency'] ) ? (string) $purchase['base_currency'] : $this->setting( 'base_currency', 'USD' );
		$default_rate  = isset( $purchase['default_rate'] ) ? (float) $purchase['default_rate'] : 1.0;

		$row_currency = $this->currency( $p, 'default_currency', $base_currency );
		if ( ! in_array( $row_currency, $allowed, true ) ) {
			$row_currency = $base_currency;
		}
		$row_rate = $this->float( $p, 'default_rate', $default_rate );
		if ( $row_rate <= 0 ) {
			$row_rate = $default_rate > 0 ? $default_rate : 1.0;
		}

		$match_by = sanitize_key( $this->str( $p, 'match_by', 'both' ) );
		if ( ! in_array( $match_by, array( 'sku', 'name', 'both' ), true ) ) {
			$match_by = 'both';
		}
		$create_missing = $this->bool( $p, 'create_missing', false );

		$clean = array();
		$sort  = $this->next_sort_order( isset( $purchase['items'] ) ? $purchase['items'] : array() );
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				$row = array();
			}
			$currency = $this->currency( $row, 'currency', $row_currency );
			if ( ! in_array( $currency, $allowed, true ) ) {
				$currency = $row_currency;
			}
			$rate = $this->float( $row, 'rate', $row_rate );
			if ( $rate <= 0 ) {
				$rate = $row_rate;
			}
			// Explicit WooCommerce ids (product picker); absint keeps them unsigned.
			$product_id   = absint( $this->int( $row, 'product_id', 0 ) );
			$variation_id = absint( $this->int( $row, 'variation_id', 0 ) );

			$clean[] = array(
				'row'          => $index + 1,
				'sku'          => $this->str( $row, 'sku', '', 100 ),
				'name'         => $this->str( $row, 'name', '', 255 ),
				'quantity'     => $this->float( $row, 'quantity', 0 ),
				'unit_cost'    => $this->float( $row, 'unit_cost', 0 ),
				'currency'     => $currency,
				'rate'         => $rate,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'sort_order'   => $sort + $index,
			);
		}

		$opts = array(
			'create_missing'   => $create_missing,
			'match_by'         => $match_by,
			'default_currency' => $row_currency,
			'default_rate'     => $row_rate,
			'user_id'          => get_current_user_id(),
		);

		$report = MJERP_Purchases_Repo::add_items_bulk( $id, $clean, $opts );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$this->recalculate( $id );

		$full = $this->load_purchase( $id );
		if ( is_wp_error( $full ) ) {
			return $full;
		}

		return $this->ok(
			array(
				'purchase' => $full,
				'report'   => $this->normalize_bulk_report( $report, count( $clean ) ),
			)
		);
	}

	/**
	 * POST /purchases/{id}/items/{item_id}/link
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function link_item( $request ) {
		$id       = $this->route_id( $request );
		$item_id  = $this->route_id( $request, 'item_id' );
		$purchase = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}
		$lock = $this->require_editable( $purchase );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$item = $this->find_item( $purchase, $item_id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$p            = $this->params( $request );
		$product_id   = $this->int( $p, 'product_id', 0 );
		$variation_id = $this->int( $p, 'variation_id', 0 );

		if ( ! $product_id && ! $variation_id ) {
			return $this->fail( 'mjerp_invalid_product', __( 'Elegí un producto de WooCommerce para vincular.', 'mj-erp' ), 400 );
		}

		$resolved = $this->resolve_product( $product_id, $variation_id );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( null === $resolved ) {
			return $this->fail( 'mjerp_invalid_product', __( 'Elegí un producto de WooCommerce para vincular.', 'mj-erp' ), 400 );
		}

		$data = array(
			'product_id'   => $resolved['product_id'],
			'variation_id' => $resolved['variation_id'],
		);

		$summary = $resolved['summary'];
		if ( '' === trim( isset( $item['name'] ) ? (string) $item['name'] : '' ) && ! empty( $summary['name'] ) ) {
			$data['name'] = sanitize_text_field( (string) $summary['name'] );
		}
		if ( '' === trim( isset( $item['sku'] ) ? (string) $item['sku'] : '' ) && ! empty( $summary['sku'] ) ) {
			$data['sku'] = sanitize_text_field( (string) $summary['sku'] );
		}

		$result = MJERP_Purchases_Repo::update_item( $item_id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->recalculate( $id );

		return $this->ok( $this->load_purchase( $id ) );
	}

	/* ---------------------------------------------------------------------
	 * Expenses
	 * ------------------------------------------------------------------ */

	/**
	 * POST /purchases/{id}/expenses
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_expense( $request ) {
		$id       = $this->route_id( $request );
		$purchase = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}
		$lock = $this->require_editable( $purchase );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$p    = $this->params( $request );
		$data = $this->expense_data( $p, $purchase, null );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! isset( $data['sort_order'] ) ) {
			$data['sort_order'] = $this->next_sort_order( isset( $purchase['expenses'] ) ? $purchase['expenses'] : array() );
		}

		$expense_id = MJERP_Purchases_Repo::add_expense( $id, $data );
		if ( is_wp_error( $expense_id ) ) {
			return $expense_id;
		}

		$this->recalculate( $id );

		$full = $this->load_purchase( $id );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		$full['last_expense_id'] = absint( $expense_id );

		return $this->ok( $full, 201 );
	}

	/**
	 * PUT /purchases/{id}/expenses/{expense_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_expense( $request ) {
		$id         = $this->route_id( $request );
		$expense_id = $this->route_id( $request, 'expense_id' );
		$purchase   = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}
		$lock = $this->require_editable( $purchase );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$expense = $this->find_expense( $purchase, $expense_id );
		if ( is_wp_error( $expense ) ) {
			return $expense;
		}

		$p    = $this->params( $request );
		$data = $this->expense_data( $p, $purchase, $expense );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data ) ) {
			return $this->ok( $purchase );
		}

		$result = MJERP_Purchases_Repo::update_expense( $expense_id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->recalculate( $id );

		return $this->ok( $this->load_purchase( $id ) );
	}

	/**
	 * DELETE /purchases/{id}/expenses/{expense_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_expense( $request ) {
		$id         = $this->route_id( $request );
		$expense_id = $this->route_id( $request, 'expense_id' );
		$purchase   = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}
		$lock = $this->require_editable( $purchase );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$expense = $this->find_expense( $purchase, $expense_id );
		if ( is_wp_error( $expense ) ) {
			return $expense;
		}

		$result = MJERP_Purchases_Repo::delete_expense( $expense_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->recalculate( $id );

		return $this->ok( $this->load_purchase( $id ) );
	}

	/* ---------------------------------------------------------------------
	 * Receive / unreceive
	 * ------------------------------------------------------------------ */

	/**
	 * POST /purchases/{id}/receive
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive_purchase( $request ) {
		$id       = $this->route_id( $request );
		$purchase = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}

		$status = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';
		if ( 'recibida' === $status ) {
			return $this->fail( 'mjerp_already_received', __( 'La compra ya fue recibida.', 'mj-erp' ), 409, array( 'purchase_status' => $status ) );
		}
		if ( ! in_array( $status, array( 'borrador', 'en_transito' ), true ) ) {
			return $this->fail(
				'mjerp_invalid_status',
				__( 'Solo se pueden recibir compras en borrador o en tránsito.', 'mj-erp' ),
				409,
				array( 'purchase_status' => $status )
			);
		}

		$items = isset( $purchase['items'] ) && is_array( $purchase['items'] ) ? $purchase['items'] : array();
		if ( empty( $items ) ) {
			return $this->fail( 'mjerp_no_items', __( 'La compra no tiene productos para recibir.', 'mj-erp' ), 400 );
		}

		if ( ! class_exists( 'MJERP_Stock' ) || ! is_callable( array( 'MJERP_Stock', 'receive' ) ) ) {
			return $this->fail( 'mjerp_unavailable', __( 'El módulo de stock no está disponible.', 'mj-erp' ), 500 );
		}

		$p       = $this->params( $request );
		$options = array( 'user_id' => get_current_user_id() );

		if ( $this->has( $p, 'items' ) && null !== $p['items'] ) {
			$map = $this->parse_received_items( $p['items'], $items );
			if ( is_wp_error( $map ) ) {
				return $map;
			}
			$options['items'] = $map;
		}

		foreach ( array( 'add_stock', 'update_cost', 'update_price' ) as $flag ) {
			if ( $this->has( $p, $flag ) && null !== $p[ $flag ] && '' !== $p[ $flag ] ) {
				$options[ $flag ] = $this->bool( $p, $flag, false );
			}
		}

		if ( $this->has( $p, 'price_list' ) ) {
			$list_key = sanitize_key( $this->str( $p, 'price_list' ) );
			if ( '' !== $list_key ) {
				if ( ! $this->price_list_exists( $list_key ) ) {
					return $this->fail(
						'mjerp_invalid_price_list',
						sprintf(
							/* translators: %s: list key */
							__( 'La lista de precios «%s» no existe.', 'mj-erp' ),
							$list_key
						),
						400
					);
				}
				$options['price_list'] = $list_key;
			}
		}

		if ( $this->has( $p, 'price_target' ) ) {
			$target = sanitize_key( $this->str( $p, 'price_target' ) );
			if ( '' !== $target ) {
				if ( ! in_array( $target, array( 'regular_price', 'sale_price' ), true ) ) {
					return $this->fail( 'mjerp_invalid_price_target', __( 'El precio destino debe ser regular_price o sale_price.', 'mj-erp' ), 400 );
				}
				$options['price_target'] = $target;
			}
		}

		if ( $this->has( $p, 'note' ) ) {
			$options['note'] = $this->str( $p, 'note', '', 255 );
		}

		/**
		 * Filter the options passed to MJERP_Stock::receive().
		 *
		 * @param array $options  Options.
		 * @param array $purchase Full purchase.
		 */
		$options = apply_filters( 'mjerp_rest_receive_options', $options, $purchase );

		$report = MJERP_Stock::receive( $purchase, $options );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$this->recalculate( $id );

		$full = $this->load_purchase( $id );
		if ( is_wp_error( $full ) ) {
			return $full;
		}

		return $this->ok(
			array(
				'purchase' => $full,
				'report'   => $this->normalize_receive_report( $report ),
			)
		);
	}

	/**
	 * POST /purchases/{id}/unreceive
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unreceive_purchase( $request ) {
		$id       = $this->route_id( $request );
		$purchase = $this->load_purchase( $id );
		if ( is_wp_error( $purchase ) ) {
			return $purchase;
		}

		$status = isset( $purchase['status'] ) ? (string) $purchase['status'] : '';
		if ( 'recibida' !== $status ) {
			return $this->fail( 'mjerp_not_received', __( 'La compra no está recibida.', 'mj-erp' ), 409, array( 'purchase_status' => $status ) );
		}

		if ( ! $this->to_bool( $this->setting( 'allow_unreceive', true ), true ) ) {
			return $this->fail( 'mjerp_unreceive_disabled', __( 'Deshacer la recepción está deshabilitado en la configuración.', 'mj-erp' ), 403 );
		}

		if ( ! class_exists( 'MJERP_Stock' ) || ! is_callable( array( 'MJERP_Stock', 'unreceive' ) ) ) {
			return $this->fail( 'mjerp_unavailable', __( 'El módulo de stock no está disponible.', 'mj-erp' ), 500 );
		}

		$p       = $this->params( $request );
		$options = array(
			'user_id' => get_current_user_id(),
			'note'    => $this->str( $p, 'note', '', 255 ),
		);

		$report = MJERP_Stock::unreceive( $purchase, $options );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$this->recalculate( $id );

		$full = $this->load_purchase( $id );
		if ( is_wp_error( $full ) ) {
			return $full;
		}

		return $this->ok(
			array(
				'purchase' => $full,
				'report'   => $this->normalize_receive_report( $report ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Data builders
	 * ------------------------------------------------------------------ */

	/**
	 * Build the header data array (only keys that were sent). Applies defaults on create.
	 *
	 * @param array      $p       Params.
	 * @param array|null $current Current purchase (null on create).
	 * @return array|WP_Error
	 */
	protected function header_data( $p, $current ) {
		$is_new  = null === $current;
		$data    = array();
		$allowed = $this->allowed_currencies();

		if ( $this->has( $p, 'code' ) ) {
			$code = $this->str( $p, 'code', '', 60 );
			if ( '' !== $code ) {
				$data['code'] = $code;
			} elseif ( ! $is_new ) {
				return $this->fail( 'mjerp_invalid_code', __( 'El código no puede estar vacío.', 'mj-erp' ), 400, array( 'field' => 'code' ) );
			}
		}

		if ( $this->has( $p, 'purchase_date' ) ) {
			$raw = is_scalar( $p['purchase_date'] ) ? trim( (string) $p['purchase_date'] ) : '';
			if ( '' === $raw ) {
				if ( ! $is_new ) {
					return $this->fail( 'mjerp_invalid_date', __( 'La fecha es obligatoria.', 'mj-erp' ), 400, array( 'field' => 'purchase_date' ) );
				}
			} else {
				$date = $this->sanitize_date( $raw );
				if ( null === $date ) {
					return $this->fail( 'mjerp_invalid_date', __( 'La fecha de la compra no es válida.', 'mj-erp' ), 400, array( 'field' => 'purchase_date' ) );
				}
				$data['purchase_date'] = $date;
			}
		}

		if ( $this->has( $p, 'supplier_id' ) ) {
			$supplier_id = $this->int( $p, 'supplier_id', 0 );
			if ( $supplier_id > 0 ) {
				$supplier = $this->find_supplier( $supplier_id );
				if ( is_wp_error( $supplier ) ) {
					return $supplier;
				}
				$data['supplier_id'] = $supplier_id;
				if ( is_array( $supplier ) && ! empty( $supplier['name'] ) && ( ! $this->has( $p, 'supplier_name' ) || '' === $this->str( $p, 'supplier_name' ) ) ) {
					$data['supplier_name'] = sanitize_text_field( (string) $supplier['name'] );
				}
			} else {
				$data['supplier_id'] = 0;
			}
		}

		if ( $this->has( $p, 'supplier_name' ) ) {
			$name = $this->str( $p, 'supplier_name', '', 190 );
			if ( '' !== $name || ! isset( $data['supplier_name'] ) ) {
				$data['supplier_name'] = $name;
			}
		}

		if ( $this->has( $p, 'destination' ) ) {
			$data['destination'] = $this->str( $p, 'destination', '', 100 );
		}

		if ( $this->has( $p, 'base_currency' ) ) {
			$currency = $this->currency( $p, 'base_currency', '' );
			if ( '' === $currency || ! in_array( $currency, $allowed, true ) ) {
				return $this->fail(
					'mjerp_invalid_currency',
					sprintf(
						/* translators: %s: comma separated currencies */
						__( 'Moneda base inválida. Monedas permitidas: %s.', 'mj-erp' ),
						implode( ', ', $allowed )
					),
					400,
					array( 'field' => 'base_currency' )
				);
			}
			$data['base_currency'] = $currency;
		}

		if ( $this->has( $p, 'default_rate' ) ) {
			$rate = $this->float( $p, 'default_rate', 0 );
			if ( $rate <= 0 ) {
				return $this->fail( 'mjerp_invalid_rate', __( 'El tipo de cambio por defecto debe ser mayor a cero.', 'mj-erp' ), 400, array( 'field' => 'default_rate' ) );
			}
			$data['default_rate'] = $rate;
		}

		if ( $this->has( $p, 'proration_method' ) ) {
			$method = sanitize_key( $this->str( $p, 'proration_method' ) );
			if ( ! in_array( $method, array( 'fob', 'qty', 'none' ), true ) ) {
				return $this->fail( 'mjerp_invalid_proration', __( 'Método de prorrateo inválido (fob, qty o none).', 'mj-erp' ), 400, array( 'field' => 'proration_method' ) );
			}
			$data['proration_method'] = $method;
		}

		if ( $this->has( $p, 'notes' ) ) {
			$data['notes'] = $this->text( $p, 'notes', '' );
		}

		if ( $is_new ) {
			$data['status'] = 'borrador';
			if ( ! isset( $data['purchase_date'] ) ) {
				$data['purchase_date'] = current_time( 'Y-m-d' );
			}
			if ( ! isset( $data['base_currency'] ) ) {
				$base = $this->clean_currency( $this->setting( 'base_currency', 'USD' ) );
				$data['base_currency'] = in_array( $base, $allowed, true ) ? $base : $allowed[0];
			}
			if ( ! isset( $data['default_rate'] ) ) {
				$rate = $this->to_float( $this->setting( 'default_rate', 1 ), 1 );
				$data['default_rate'] = $rate > 0 ? $rate : 1.0;
			}
			if ( ! isset( $data['proration_method'] ) ) {
				$method = sanitize_key( (string) $this->setting( 'proration_method', 'fob' ) );
				$data['proration_method'] = in_array( $method, array( 'fob', 'qty', 'none' ), true ) ? $method : 'fob';
			}
			if ( ! isset( $data['supplier_id'] ) ) {
				$data['supplier_id'] = 0;
			}
			if ( ! isset( $data['supplier_name'] ) ) {
				$data['supplier_name'] = '';
			}
			if ( ! isset( $data['destination'] ) ) {
				$data['destination'] = '';
			}
			if ( ! isset( $data['notes'] ) ) {
				$data['notes'] = '';
			}
		}

		return $data;
	}

	/**
	 * Build item data (only keys that were sent; defaults on create).
	 *
	 * @param array      $p        Params.
	 * @param array      $purchase Purchase.
	 * @param array|null $current  Current item (null on create).
	 * @return array|WP_Error
	 */
	protected function item_data( $p, $purchase, $current ) {
		$is_new        = null === $current;
		$data          = array();
		$allowed       = $this->allowed_currencies();
		$base_currency = isset( $purchase['base_currency'] ) ? (string) $purchase['base_currency'] : $this->setting( 'base_currency', 'USD' );
		$default_rate  = isset( $purchase['default_rate'] ) ? (float) $purchase['default_rate'] : 1.0;
		if ( $default_rate <= 0 ) {
			$default_rate = 1.0;
		}
		$snapshot = null;

		if ( $this->has( $p, 'product_id' ) || $this->has( $p, 'variation_id' ) ) {
			$current_pid  = ! $is_new && isset( $current['product_id'] ) ? absint( $current['product_id'] ) : 0;
			$current_vid  = ! $is_new && isset( $current['variation_id'] ) ? absint( $current['variation_id'] ) : 0;
			$product_id   = $this->has( $p, 'product_id' ) ? $this->int( $p, 'product_id', 0 ) : $current_pid;
			$variation_id = $this->has( $p, 'variation_id' ) ? $this->int( $p, 'variation_id', 0 ) : $current_vid;
			if ( ! $is_new && $product_id === $current_pid && $variation_id === $current_vid ) {
				// Link unchanged (e.g. the whole row was sent back): nothing to resolve.
				$product_id   = 0;
				$variation_id = 0;
				$skip_link    = true;
			} else {
				$skip_link = false;
			}
			if ( $skip_link ) {
				// Keep current link untouched.
				$product_id = -1;
			}
			if ( -1 === $product_id ) {
				$product_id = 0;
			} elseif ( $product_id || $variation_id ) {
				$resolved = $this->resolve_product( $product_id, $variation_id );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}
				$data['product_id']   = $resolved['product_id'];
				$data['variation_id'] = $resolved['variation_id'];
				$snapshot             = $resolved['summary'];
			} else {
				// Explicit unlink.
				$data['product_id']   = 0;
				$data['variation_id'] = 0;
			}
		}

		// Name.
		$name_given = null;
		if ( $this->has( $p, 'name' ) ) {
			$name_given   = $this->str( $p, 'name', '', 255 );
			$data['name'] = $name_given;
		}
		$effective_name = null !== $name_given ? $name_given : ( $is_new ? '' : trim( (string) ( isset( $current['name'] ) ? $current['name'] : '' ) ) );
		if ( '' === $effective_name ) {
			if ( $snapshot && ! empty( $snapshot['name'] ) ) {
				$data['name'] = sanitize_text_field( (string) $snapshot['name'] );
			} elseif ( $is_new || null !== $name_given ) {
				return $this->fail( 'mjerp_invalid_name', __( 'Ingresá el nombre del producto o vinculalo a un producto de WooCommerce.', 'mj-erp' ), 400, array( 'field' => 'name' ) );
			}
		}

		// SKU.
		$sku_given = null;
		if ( $this->has( $p, 'sku' ) ) {
			$sku_given   = $this->str( $p, 'sku', '', 100 );
			$data['sku'] = $sku_given;
		}
		$effective_sku = null !== $sku_given ? $sku_given : ( $is_new ? '' : trim( (string) ( isset( $current['sku'] ) ? $current['sku'] : '' ) ) );
		if ( '' === $effective_sku && $snapshot && ! empty( $snapshot['sku'] ) ) {
			$data['sku'] = sanitize_text_field( (string) $snapshot['sku'] );
		}

		// Quantity.
		if ( $this->has( $p, 'quantity' ) ) {
			$qty = $this->float( $p, 'quantity', 0 );
			if ( $qty <= 0 ) {
				return $this->fail( 'mjerp_invalid_quantity', __( 'La cantidad debe ser mayor a cero.', 'mj-erp' ), 400, array( 'field' => 'quantity' ) );
			}
			$data['quantity'] = $qty;
		} elseif ( $is_new ) {
			return $this->fail( 'mjerp_invalid_quantity', __( 'Ingresá la cantidad.', 'mj-erp' ), 400, array( 'field' => 'quantity' ) );
		}

		// Unit cost.
		if ( $this->has( $p, 'unit_cost' ) ) {
			$cost = $this->float( $p, 'unit_cost', 0 );
			if ( $cost < 0 ) {
				return $this->fail( 'mjerp_invalid_cost', __( 'El costo unitario no puede ser negativo.', 'mj-erp' ), 400, array( 'field' => 'unit_cost' ) );
			}
			$data['unit_cost'] = $cost;
		} elseif ( $is_new ) {
			return $this->fail( 'mjerp_invalid_cost', __( 'Ingresá el costo unitario.', 'mj-erp' ), 400, array( 'field' => 'unit_cost' ) );
		}

		// Currency.
		if ( $this->has( $p, 'currency' ) ) {
			$currency = $this->currency( $p, 'currency', '' );
			if ( '' === $currency || ! in_array( $currency, $allowed, true ) ) {
				return $this->fail(
					'mjerp_invalid_currency',
					sprintf(
						/* translators: %s: comma separated currencies */
						__( 'Moneda inválida. Monedas permitidas: %s.', 'mj-erp' ),
						implode( ', ', $allowed )
					),
					400,
					array( 'field' => 'currency' )
				);
			}
			$data['currency'] = $currency;
		} elseif ( $is_new ) {
			$data['currency'] = in_array( $base_currency, $allowed, true ) ? $base_currency : $allowed[0];
		}
		$effective_currency = isset( $data['currency'] ) ? $data['currency'] : ( isset( $current['currency'] ) ? (string) $current['currency'] : $base_currency );

		// Rate.
		if ( $this->has( $p, 'rate' ) ) {
			$rate = $this->float( $p, 'rate', 0 );
			if ( $rate <= 0 ) {
				if ( $effective_currency === $base_currency ) {
					$rate = $default_rate;
				} else {
					return $this->fail( 'mjerp_invalid_rate', __( 'El tipo de cambio debe ser mayor a cero.', 'mj-erp' ), 400, array( 'field' => 'rate' ) );
				}
			}
			$data['rate'] = $rate;
		} elseif ( $is_new ) {
			$data['rate'] = $default_rate;
		}

		if ( $this->has( $p, 'sort_order' ) ) {
			$data['sort_order'] = $this->int( $p, 'sort_order', 0 );
		}

		return $data;
	}

	/**
	 * Build expense data (only keys that were sent; defaults on create).
	 *
	 * @param array      $p        Params.
	 * @param array      $purchase Purchase.
	 * @param array|null $current  Current expense (null on create).
	 * @return array|WP_Error
	 */
	protected function expense_data( $p, $purchase, $current ) {
		$is_new         = null === $current;
		$data           = array();
		$allowed        = $this->allowed_currencies();
		$base_currency  = isset( $purchase['base_currency'] ) ? (string) $purchase['base_currency'] : $this->setting( 'base_currency', 'USD' );
		$local_currency = $this->clean_currency( $this->setting( 'local_currency', 'ARS' ) );
		$default_rate   = isset( $purchase['default_rate'] ) ? (float) $purchase['default_rate'] : 1.0;
		if ( $default_rate <= 0 ) {
			$default_rate = 1.0;
		}

		if ( $this->has( $p, 'type' ) ) {
			$type = sanitize_key( $this->str( $p, 'type' ) );
			if ( '' === $type || ! $this->expense_type_exists( $type ) ) {
				$type = 'otro';
			}
			$data['type'] = $type;
		} elseif ( $is_new ) {
			$data['type'] = 'otro';
		}

		if ( $this->has( $p, 'description' ) ) {
			$data['description'] = $this->str( $p, 'description', '', 255 );
		}

		if ( $this->has( $p, 'amount' ) ) {
			$amount = $this->float( $p, 'amount', 0 );
			if ( $amount < 0 ) {
				return $this->fail( 'mjerp_invalid_amount', __( 'El monto no puede ser negativo.', 'mj-erp' ), 400, array( 'field' => 'amount' ) );
			}
			$data['amount'] = $amount;
		} elseif ( $is_new ) {
			return $this->fail( 'mjerp_invalid_amount', __( 'Ingresá el monto del gasto.', 'mj-erp' ), 400, array( 'field' => 'amount' ) );
		}

		if ( $this->has( $p, 'currency' ) ) {
			$currency = $this->currency( $p, 'currency', '' );
			if ( '' === $currency || ! in_array( $currency, $allowed, true ) ) {
				return $this->fail(
					'mjerp_invalid_currency',
					sprintf(
						/* translators: %s: comma separated currencies */
						__( 'Moneda inválida. Monedas permitidas: %s.', 'mj-erp' ),
						implode( ', ', $allowed )
					),
					400,
					array( 'field' => 'currency' )
				);
			}
			$data['currency'] = $currency;
		} elseif ( $is_new ) {
			if ( in_array( $local_currency, $allowed, true ) ) {
				$data['currency'] = $local_currency;
			} else {
				$data['currency'] = in_array( $base_currency, $allowed, true ) ? $base_currency : $allowed[0];
			}
		}
		$effective_currency = isset( $data['currency'] ) ? $data['currency'] : ( isset( $current['currency'] ) ? (string) $current['currency'] : $base_currency );

		if ( $this->has( $p, 'rate' ) ) {
			$rate = $this->float( $p, 'rate', 0 );
			if ( $rate <= 0 ) {
				if ( $effective_currency === $base_currency ) {
					$rate = $default_rate;
				} else {
					return $this->fail( 'mjerp_invalid_rate', __( 'El tipo de cambio debe ser mayor a cero.', 'mj-erp' ), 400, array( 'field' => 'rate' ) );
				}
			}
			$data['rate'] = $rate;
		} elseif ( $is_new ) {
			$data['rate'] = $default_rate;
		}

		if ( $this->has( $p, 'is_prorated' ) ) {
			$data['is_prorated'] = $this->bool( $p, 'is_prorated', true ) ? 1 : 0;
		} elseif ( $is_new ) {
			$data['is_prorated'] = 1;
		}

		if ( $this->has( $p, 'cashbox_id' ) ) {
			$cashbox_id = $this->int( $p, 'cashbox_id', 0 );
			if ( $cashbox_id > 0 ) {
				$cashbox = $this->find_cashbox( $cashbox_id );
				if ( is_wp_error( $cashbox ) ) {
					return $cashbox;
				}
			}
			$data['cashbox_id'] = $cashbox_id;
		} elseif ( $is_new ) {
			$data['cashbox_id'] = 0;
		}

		if ( $this->has( $p, 'expense_date' ) ) {
			$raw = is_scalar( $p['expense_date'] ) ? trim( (string) $p['expense_date'] ) : '';
			if ( '' === $raw ) {
				$data['expense_date'] = null;
			} else {
				$date = $this->sanitize_date( $raw );
				if ( null === $date ) {
					return $this->fail( 'mjerp_invalid_date', __( 'La fecha del gasto no es válida.', 'mj-erp' ), 400, array( 'field' => 'expense_date' ) );
				}
				$data['expense_date'] = $date;
			}
		}

		if ( $this->has( $p, 'sort_order' ) ) {
			$data['sort_order'] = $this->int( $p, 'sort_order', 0 );
		}

		return $data;
	}

	/**
	 * Turn the `items` receive param (list of {item_id, received_qty} or a map) into item_id => qty.
	 *
	 * @param mixed $raw   Raw param.
	 * @param array $items Purchase items.
	 * @return array|WP_Error
	 */
	protected function parse_received_items( $raw, $items ) {
		if ( ! is_array( $raw ) ) {
			return $this->fail( 'mjerp_invalid_items', __( 'El listado de cantidades recibidas no es válido.', 'mj-erp' ), 400 );
		}

		$by_id = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$by_id[ absint( $item['id'] ) ] = $item;
			}
		}

		$map = array();
		foreach ( $raw as $key => $entry ) {
			if ( is_array( $entry ) ) {
				$item_id = isset( $entry['item_id'] ) ? absint( $entry['item_id'] ) : ( isset( $entry['id'] ) ? absint( $entry['id'] ) : absint( $key ) );
				if ( array_key_exists( 'received_qty', $entry ) && null !== $entry['received_qty'] && '' !== $entry['received_qty'] ) {
					$qty = $this->to_float( $entry['received_qty'], -1 );
				} elseif ( array_key_exists( 'qty', $entry ) && null !== $entry['qty'] && '' !== $entry['qty'] ) {
					$qty = $this->to_float( $entry['qty'], -1 );
				} else {
					$qty = isset( $by_id[ $item_id ]['quantity'] ) ? (float) $by_id[ $item_id ]['quantity'] : 0.0;
				}
			} else {
				$item_id = absint( $key );
				$qty     = $this->to_float( $entry, -1 );
			}

			if ( ! $item_id || ! isset( $by_id[ $item_id ] ) ) {
				return $this->fail(
					'mjerp_invalid_item',
					sprintf(
						/* translators: %d: item id */
						__( 'El ítem #%d no pertenece a esta compra.', 'mj-erp' ),
						$item_id
					),
					400
				);
			}
			if ( $qty < 0 ) {
				return $this->fail(
					'mjerp_invalid_quantity',
					sprintf(
						/* translators: %s: item name */
						__( 'La cantidad recibida de «%s» no es válida.', 'mj-erp' ),
						isset( $by_id[ $item_id ]['name'] ) ? $by_id[ $item_id ]['name'] : '#' . $item_id
					),
					400
				);
			}
			$map[ $item_id ] = $qty;
		}

		// Items not mentioned default to their ordered quantity (MJERP_Stock does the same).
		$total = 0.0;
		foreach ( $by_id as $item_id => $item ) {
			$total += isset( $map[ $item_id ] ) ? $map[ $item_id ] : (float) ( isset( $item['quantity'] ) ? $item['quantity'] : 0 );
		}
		if ( $total <= 0 ) {
			return $this->fail( 'mjerp_nothing_received', __( 'Ingresá al menos una cantidad recibida mayor a cero.', 'mj-erp' ), 400 );
		}

		return $map;
	}

	/* ---------------------------------------------------------------------
	 * Lookups
	 * ------------------------------------------------------------------ */

	/**
	 * Make sure the repository exists.
	 *
	 * @return true|WP_Error
	 */
	protected function repo_check() {
		if ( ! class_exists( 'MJERP_Purchases_Repo' ) ) {
			return $this->fail( 'mjerp_unavailable', __( 'El módulo de compras no está disponible.', 'mj-erp' ), 500 );
		}
		return true;
	}

	/**
	 * Find an item inside a loaded purchase.
	 *
	 * @param array $purchase Purchase.
	 * @param int   $item_id  Item id.
	 * @return array|WP_Error
	 */
	protected function find_item( $purchase, $item_id ) {
		$item_id = absint( $item_id );
		foreach ( (array) ( isset( $purchase['items'] ) ? $purchase['items'] : array() ) as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) && absint( $item['id'] ) === $item_id ) {
				return $item;
			}
		}
		if ( $item_id && is_callable( array( 'MJERP_Purchases_Repo', 'get_item' ) ) ) {
			$item = MJERP_Purchases_Repo::get_item( $item_id );
			if ( is_array( $item ) && isset( $item['purchase_id'] ) && absint( $item['purchase_id'] ) === absint( $purchase['id'] ) ) {
				return $item;
			}
		}
		return $this->fail( 'mjerp_item_not_found', __( 'El ítem no existe en esta compra.', 'mj-erp' ), 404, array( 'item_id' => $item_id ) );
	}

	/**
	 * Find an expense inside a loaded purchase.
	 *
	 * @param array $purchase   Purchase.
	 * @param int   $expense_id Expense id.
	 * @return array|WP_Error
	 */
	protected function find_expense( $purchase, $expense_id ) {
		$expense_id = absint( $expense_id );
		foreach ( (array) ( isset( $purchase['expenses'] ) ? $purchase['expenses'] : array() ) as $expense ) {
			if ( is_array( $expense ) && isset( $expense['id'] ) && absint( $expense['id'] ) === $expense_id ) {
				return $expense;
			}
		}
		if ( $expense_id && is_callable( array( 'MJERP_Purchases_Repo', 'get_expense' ) ) ) {
			$expense = MJERP_Purchases_Repo::get_expense( $expense_id );
			if ( is_array( $expense ) && isset( $expense['purchase_id'] ) && absint( $expense['purchase_id'] ) === absint( $purchase['id'] ) ) {
				return $expense;
			}
		}
		return $this->fail( 'mjerp_expense_not_found', __( 'El gasto no existe en esta compra.', 'mj-erp' ), 404, array( 'expense_id' => $expense_id ) );
	}

	/**
	 * Supplier lookup → row or 400 error.
	 *
	 * @param int $supplier_id Supplier id.
	 * @return array|WP_Error
	 */
	protected function find_supplier( $supplier_id ) {
		if ( ! class_exists( 'MJERP_Suppliers_Repo' ) || ! is_callable( array( 'MJERP_Suppliers_Repo', 'find' ) ) ) {
			return array();
		}
		$supplier = MJERP_Suppliers_Repo::find( absint( $supplier_id ) );
		if ( is_wp_error( $supplier ) ) {
			return $supplier;
		}
		if ( empty( $supplier ) ) {
			return $this->fail( 'mjerp_supplier_not_found', __( 'El proveedor seleccionado no existe.', 'mj-erp' ), 400, array( 'field' => 'supplier_id' ) );
		}
		return (array) $supplier;
	}

	/**
	 * Cashbox lookup → row or 400 error.
	 *
	 * @param int $cashbox_id Cashbox id.
	 * @return array|WP_Error
	 */
	protected function find_cashbox( $cashbox_id ) {
		if ( ! class_exists( 'MJERP_Cashboxes_Repo' ) || ! is_callable( array( 'MJERP_Cashboxes_Repo', 'find' ) ) ) {
			return array();
		}
		$cashbox = MJERP_Cashboxes_Repo::find( absint( $cashbox_id ) );
		if ( is_wp_error( $cashbox ) ) {
			return $cashbox;
		}
		if ( empty( $cashbox ) ) {
			return $this->fail( 'mjerp_cashbox_not_found', __( 'La caja seleccionada no existe.', 'mj-erp' ), 400, array( 'field' => 'cashbox_id' ) );
		}
		return (array) $cashbox;
	}

	/**
	 * Resolve a WooCommerce product/variation pair.
	 *
	 * @param int $product_id   Product id (parent or simple).
	 * @param int $variation_id Variation id.
	 * @return array|null|WP_Error  [product_id, variation_id, summary, product] | null when both are 0.
	 */
	protected function resolve_product( $product_id, $variation_id ) {
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		$lookup       = $variation_id ? $variation_id : $product_id;
		if ( ! $lookup ) {
			return null;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $this->fail( 'mjerp_unavailable', __( 'WooCommerce no está disponible.', 'mj-erp' ), 500 );
		}

		$product = wc_get_product( $lookup );
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return $this->fail( 'mjerp_product_not_found', __( 'El producto seleccionado no existe en WooCommerce.', 'mj-erp' ), 404, array( 'product_id' => $lookup ) );
		}

		if ( $product->is_type( 'variation' ) ) {
			$resolved_variation = $product->get_id();
			$resolved_product   = $product->get_parent_id();
		} elseif ( $product->is_type( 'variable' ) ) {
			return $this->fail(
				'mjerp_variable_product',
				sprintf(
					/* translators: %s: product name */
					__( '«%s» es un producto variable: elegí una variación específica.', 'mj-erp' ),
					$product->get_name()
				),
				400,
				array( 'product_id' => $product->get_id() )
			);
		} else {
			$resolved_variation = 0;
			$resolved_product   = $product->get_id();
		}

		return array(
			'product_id'   => (int) $resolved_product,
			'variation_id' => (int) $resolved_variation,
			'summary'      => $this->product_summary( $product ),
			'product'      => $product,
		);
	}

	/**
	 * Product summary (MJERP_Products::summary when available).
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	protected function product_summary( $product ) {
		if ( class_exists( 'MJERP_Products' ) && is_callable( array( 'MJERP_Products', 'summary' ) ) ) {
			$summary = MJERP_Products::summary( $product );
			if ( is_array( $summary ) ) {
				return $summary;
			}
		}
		$is_variation = $product->is_type( 'variation' );
		return array(
			'id'           => $product->get_id(),
			'product_id'   => $is_variation ? $product->get_parent_id() : $product->get_id(),
			'variation_id' => $is_variation ? $product->get_id() : 0,
			'type'         => $product->get_type(),
			'name'         => $is_variation && function_exists( 'wc_get_formatted_variation' )
				? trim( $product->get_name() . ' - ' . wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, false ) ) )
				: $product->get_name(),
			'sku'          => (string) $product->get_sku(),
			'is_variation' => $is_variation,
		);
	}

	/**
	 * Whether a price list key exists in settings.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	protected function price_list_exists( $key ) {
		foreach ( (array) $this->setting( 'price_lists', array() ) as $list ) {
			if ( is_array( $list ) && isset( $list['key'] ) && sanitize_key( (string) $list['key'] ) === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an expense type key exists in settings.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	protected function expense_type_exists( $key ) {
		foreach ( (array) $this->setting( 'expense_types', array() ) as $type ) {
			if ( is_array( $type ) && isset( $type['key'] ) && sanitize_key( (string) $type['key'] ) === $key ) {
				return true;
			}
		}
		return 'otro' === $key;
	}

	/**
	 * Next sort_order for a list of rows.
	 *
	 * @param array $rows Rows with sort_order.
	 * @return int
	 */
	protected function next_sort_order( $rows ) {
		$max = 0;
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && isset( $row['sort_order'] ) ) {
				$max = max( $max, (int) $row['sort_order'] );
			}
		}
		return $max + 1;
	}

	/**
	 * Guarantee the bulk report shape.
	 *
	 * @param mixed $report Raw report.
	 * @param int   $rows   Rows sent.
	 * @return array
	 */
	protected function normalize_bulk_report( $report, $rows ) {
		$report = is_array( $report ) ? $report : array();
		$out    = array(
			'rows'         => (int) $rows,
			'added'        => isset( $report['added'] ) ? (int) $report['added'] : 0,
			'linked'       => isset( $report['linked'] ) ? (int) $report['linked'] : 0,
			'linked_by_id' => isset( $report['linked_by_id'] ) ? (int) $report['linked_by_id'] : 0,
			'created'      => isset( $report['created'] ) ? (int) $report['created'] : 0,
			'unlinked'     => isset( $report['unlinked'] ) ? (int) $report['unlinked'] : 0,
			'errors'       => array(),
		);
		foreach ( (array) ( isset( $report['errors'] ) ? $report['errors'] : array() ) as $error ) {
			if ( is_array( $error ) ) {
				$out['errors'][] = array(
					'row'     => isset( $error['row'] ) ? (int) $error['row'] : 0,
					'message' => isset( $error['message'] ) ? (string) $error['message'] : __( 'Error desconocido.', 'mj-erp' ),
				);
			} elseif ( is_scalar( $error ) ) {
				$out['errors'][] = array( 'row' => 0, 'message' => (string) $error );
			}
		}
		foreach ( $report as $key => $value ) {
			if ( ! array_key_exists( $key, $out ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Guarantee the receive/unreceive report shape.
	 *
	 * @param mixed $report Raw report.
	 * @return array
	 */
	protected function normalize_receive_report( $report ) {
		$report = is_array( $report ) ? $report : array();
		$items  = isset( $report['items'] ) && is_array( $report['items'] ) ? array_values( $report['items'] ) : array();
		$errors = isset( $report['errors'] ) ? (int) $report['errors'] : 0;
		if ( ! isset( $report['errors'] ) ) {
			foreach ( $items as $row ) {
				if ( is_array( $row ) && isset( $row['status'] ) && 'error' === $row['status'] ) {
					$errors++;
				}
			}
		}
		$out = array(
			'items'             => $items,
			'added_stock_items' => isset( $report['added_stock_items'] ) ? (int) $report['added_stock_items'] : 0,
			'errors'            => $errors,
		);
		foreach ( $report as $key => $value ) {
			if ( ! array_key_exists( $key, $out ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
