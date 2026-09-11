<?php
defined( 'ABSPATH' ) || exit;

/**
 * /mjerp/v1 — la libreta de clientes del ERP.
 *
 * Un cliente de acá es a quién se le vendió, no una cuenta de la tienda: se crea desde el mostrador
 * con un nombre y nada más, y ninguna de estas rutas crea usuarios de WordPress ni manda mails. La
 * vinculación con una cuenta de WooCommerce es un paso aparte y explícito (`/link`).
 *
 * @package MJ_ERP
 */
class MJERP_REST_Customers extends MJERP_REST_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'customers';

	/**
	 * Fields the card accepts on create/update.
	 *
	 * @var string[]
	 */
	const FIELDS = array(
		'name',
		'company',
		'doc_type',
		'doc_number',
		'email',
		'phone',
		'address',
		'city',
		'province',
		'postcode',
		'price_list_key',
		'channel',
		'notes',
		'is_active',
	);

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns = $this->namespace;

		$writable = array(
			'name'           => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'company'        => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'doc_type'       => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
			'doc_number'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'email'          => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'phone'          => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'address'        => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'city'           => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'province'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'postcode'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'price_list_key' => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
			'channel'        => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
			'notes'          => array( 'sanitize_callback' => array( $this, 'arg_textarea' ) ),
			'is_active'      => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
		);

		register_rest_route(
			$ns,
			'/customers',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_customers',
					array(
						'search'    => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'linked'    => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'is_active' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'page'      => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'per_page'  => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'orderby'   => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'order'     => array( 'sanitize_callback' => array( $this, 'arg_order' ) ),
					)
				),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'create_customer',
					array_merge( $writable, array( 'user_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ) ) )
				),
			)
		);

		register_rest_route(
			$ns,
			'/customers/search',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'search_customers',
					array(
						'q'        => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'limit'    => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'accounts' => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/customers/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del cliente.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'get_customer' ),
				$this->endpoint( WP_REST_Server::EDITABLE, 'update_customer', $writable ),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_customer' ),
			)
		);

		register_rest_route(
			$ns,
			'/customers/(?P<id>\d+)/link',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del cliente.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'link_customer',
					array(
						'user_id'  => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'backfill' => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
					)
				),
				$this->endpoint( WP_REST_Server::DELETABLE, 'unlink_customer' ),
			)
		);

		register_rest_route(
			$ns,
			'/customers/(?P<id>\d+)/sales',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del cliente.', 'mj-erp' ) ) ),
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_customer_sales',
					array(
						'page'     => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'per_page' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/customers/candidates',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_candidates',
					array(
						'user_id' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'email'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'phone'   => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/customers/suggestions',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_suggestions',
					array( 'limit' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ) )
				),
			)
		);

		register_rest_route(
			$ns,
			'/customers/suggestions/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la cuenta de la tienda.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::DELETABLE, 'dismiss_suggestion' ),
			)
		);

		register_rest_route(
			$ns,
			'/customers/accounts',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'search_accounts',
					array(
						'q'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'limit' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Fichas
	 * ---------------------------------------------------------------- */

	/**
	 * GET /customers
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_customers( $request ) {
		$params = $this->params( $request );

		$active = null;
		if ( $this->has( $params, 'is_active' ) && '' !== $this->str( $params, 'is_active' ) ) {
			$active = $this->bool( $params, 'is_active' );
		}

		return $this->ok(
			MJERP_Customers_Repo::query(
				array(
					'search'    => $this->str( $params, 'search', '', 190 ),
					'linked'    => $this->str( $params, 'linked' ),
					'is_active' => $active,
					'page'      => max( 1, $this->int( $params, 'page', 1 ) ),
					'per_page'  => $this->int( $params, 'per_page', 20 ),
					'orderby'   => $this->str( $params, 'orderby', 'name' ),
					'order'     => $this->str( $params, 'order', 'ASC' ),
				)
			)
		);
	}

	/**
	 * GET /customers/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_customer( $request ) {
		$customer = MJERP_Customers_Repo::find( $this->route_id( $request ) );
		if ( ! $customer ) {
			return $this->fail( 'mjerp_not_found', __( 'El cliente no existe.', 'mj-erp' ), 404 );
		}
		return $this->ok( $customer );
	}

	/**
	 * POST /customers
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_customer( $request ) {
		$params = $this->params( $request );
		$data   = $this->fields( $params );

		if ( $this->has( $params, 'user_id' ) ) {
			$data['user_id'] = $this->int( $params, 'user_id' );
		}

		$id = MJERP_Customers_Repo::create( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->ok( MJERP_Customers_Repo::find( $id ), 201 );
	}

	/**
	 * PUT /customers/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_customer( $request ) {
		$id     = $this->route_id( $request );
		$params = $this->params( $request );

		$result = MJERP_Customers_Repo::update( $id, $this->fields( $params ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( MJERP_Customers_Repo::find( $id ) );
	}

	/**
	 * DELETE /customers/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_customer( $request ) {
		$id     = $this->route_id( $request );
		$result = MJERP_Customers_Repo::delete( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Cuando tenía ventas no se borra: se archiva, y la respuesta lo dice para que la pantalla
		// no anuncie un borrado que no pasó.
		$still = MJERP_Customers_Repo::find( $id, false );

		return $this->ok(
			array(
				'deleted'  => null === $still,
				'archived' => null !== $still,
				'customer' => $still,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Búsqueda
	 * ---------------------------------------------------------------- */

	/**
	 * GET /customers/search — lo que alimenta el buscador de la pantalla de venta.
	 *
	 * Devuelve primero las fichas del ERP y después las cuentas de la tienda que todavía no tienen
	 * ficha, marcadas con `type` para que quien elija sepa qué está eligiendo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search_customers( $request ) {
		$params = $this->params( $request );
		$term   = trim( $this->str( $params, 'q', '', 190 ) );
		$limit  = $this->int( $params, 'limit', 20 );
		$limit  = max( 1, min( 50, $limit ? $limit : 20 ) );

		$out  = array();
		$seen = array();

		if ( MJERP_Settings::customers_enabled() ) {
			foreach ( MJERP_Customers_Repo::search( $term, $limit ) as $customer ) {
				if ( $customer['user_id'] ) {
					$seen[ (int) $customer['user_id'] ] = true;
				}
				$out[] = $this->shape_customer( $customer );
			}
		}

		// Las cuentas de la tienda siguen a mano: sirven para vincular, y para vender a alguien que
		// ya compró online y todavía no tiene ficha.
		$accounts = $this->bool( $params, 'accounts', true );
		if ( $accounts && '' !== $term && count( $out ) < $limit ) {
			foreach ( $this->accounts( $term, $limit ) as $account ) {
				if ( isset( $seen[ (int) $account['id'] ] ) || count( $out ) >= $limit ) {
					continue;
				}
				$out[] = $this->shape_account( $account );
			}
		}

		return $this->ok( array_slice( $out, 0, $limit ) );
	}

	/**
	 * GET /customers/accounts — solo cuentas de la tienda (para el diálogo de vinculación).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search_accounts( $request ) {
		$params = $this->params( $request );
		$term   = trim( $this->str( $params, 'q', '', 190 ) );
		$limit  = $this->int( $params, 'limit', 20 );
		$limit  = max( 1, min( 50, $limit ? $limit : 20 ) );

		if ( '' === $term ) {
			return $this->ok( array() );
		}

		$out = array();
		foreach ( $this->accounts( $term, $limit ) as $account ) {
			$row = $this->shape_account( $account );
			// Se muestra igual, pero avisando: elegir una cuenta ya tomada solo puede terminar en un
			// error, y es mejor verlo antes de hacer clic.
			$taken             = MJERP_Customers_Repo::find_by_user( (int) $account['id'], false );
			$row['taken_by']   = $taken ? array(
				'id'   => (int) $taken['id'],
				'name' => (string) $taken['name'],
			) : null;
			$out[] = $row;
		}

		return $this->ok( $out );
	}

	/**
	 * GET /customers/candidates — fichas del mostrador que podrían ser esta cuenta.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_candidates( $request ) {
		$params  = $this->params( $request );
		$user_id = $this->int( $params, 'user_id' );

		$data = array(
			'email' => $this->str( $params, 'email', '', 190 ),
			'phone' => $this->str( $params, 'phone', '', 60 ),
		);

		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof WP_User ) {
				$from = MJERP_Customers_Repo::fields_from_user( $user );
				foreach ( array( 'email', 'phone' ) as $key ) {
					if ( '' === $data[ $key ] ) {
						$data[ $key ] = (string) $from[ $key ];
					}
				}
			}
		}

		return $this->ok(
			array(
				'mode'       => MJERP_Customers_Repo::link_mode(),
				'match'      => MJERP_Customers_Repo::match_fields(),
				'candidates' => MJERP_Customers_Repo::find_candidates( $data, 5 ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Vinculación
	 * ---------------------------------------------------------------- */

	/**
	 * GET /customers/suggestions — cuentas nuevas que parecen ser un cliente que ya teníamos.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_suggestions( $request ) {
		$params = $this->params( $request );
		$limit  = $this->int( $params, 'limit', 20 );

		return $this->ok(
			array(
				'mode'  => MJERP_Customers_Repo::link_mode(),
				'items' => MJERP_Customers::pending_suggestions( $limit ? $limit : 20 ),
			)
		);
	}

	/**
	 * DELETE /customers/suggestions/{user_id} — "no, no son la misma persona".
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss_suggestion( $request ) {
		MJERP_Customers::dismiss_suggestion( $this->route_id( $request ) );
		return $this->ok( array( 'dismissed' => true ) );
	}

	/**
	 * POST /customers/{id}/link
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function link_customer( $request ) {
		$params   = $this->params( $request );
		$backfill = $this->has( $params, 'backfill' ) ? $this->bool( $params, 'backfill' ) : null;

		$customer = MJERP_Customers_Repo::link( $this->route_id( $request ), $this->int( $params, 'user_id' ), $backfill );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		return $this->ok( $customer );
	}

	/**
	 * DELETE /customers/{id}/link
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unlink_customer( $request ) {
		$customer = MJERP_Customers_Repo::unlink( $this->route_id( $request ) );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		return $this->ok( $customer );
	}

	/* ------------------------------------------------------------------
	 * Historial
	 * ---------------------------------------------------------------- */

	/**
	 * GET /customers/{id}/sales
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_customer_sales( $request ) {
		$id       = $this->route_id( $request );
		$params   = $this->params( $request );
		$customer = MJERP_Customers_Repo::find( $id );

		if ( ! $customer ) {
			return $this->fail( 'mjerp_not_found', __( 'El cliente no existe.', 'mj-erp' ), 404 );
		}

		$sales = MJERP_Sales_Repo::query(
			array(
				'customer_id' => $id,
				'page'        => max( 1, $this->int( $params, 'page', 1 ) ),
				'per_page'    => $this->int( $params, 'per_page', 20 ),
				'orderby'     => 'sale_date',
				'order'       => 'DESC',
			)
		);

		return $this->ok(
			array(
				'customer' => $customer,
				'sales'    => $sales,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	/**
	 * Only the fields that were actually sent (so a PUT never blanks what it did not touch).
	 *
	 * @param array $params Params.
	 * @return array
	 */
	protected function fields( array $params ) : array {
		$data = array();
		foreach ( self::FIELDS as $key ) {
			if ( $this->has( $params, $key ) ) {
				$data[ $key ] = $params[ $key ];
			}
		}
		return $data;
	}

	/**
	 * Shop accounts matching a term.
	 *
	 * @param string $term  Term.
	 * @param int    $limit Limit.
	 * @return array
	 */
	protected function accounts( string $term, int $limit ) : array {
		if ( ! class_exists( 'MJERP_Customers' ) ) {
			return array();
		}
		$rows = MJERP_Customers::search( $term, $limit );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * An ERP card as the picker consumes it.
	 *
	 * @param array $customer Customer.
	 * @return array
	 */
	protected function shape_customer( array $customer ) : array {
		return array_merge(
			$customer,
			array(
				'type'        => 'customer',
				'customer_id' => (int) $customer['id'],
				'avatar'      => isset( $customer['account']['avatar'] ) ? $customer['account']['avatar'] : null,
			)
		);
	}

	/**
	 * A shop account as the picker consumes it.
	 *
	 * @param array $account Account summary from MJERP_Customers::summary().
	 * @return array
	 */
	protected function shape_account( array $account ) : array {
		return array(
			'type'         => 'account',
			'id'           => 0,
			'customer_id'  => 0,
			'user_id'      => (int) $account['id'],
			'is_linked'    => false,
			'name'         => (string) $account['name'],
			'email'        => isset( $account['email'] ) ? (string) $account['email'] : '',
			'phone'        => '',
			'avatar'       => isset( $account['avatar'] ) ? $account['avatar'] : null,
			'orders_count' => isset( $account['orders_count'] ) ? (int) $account['orders_count'] : 0,
			'total_spent'  => isset( $account['total_spent'] ) ? (float) $account['total_spent'] : 0.0,
		);
	}
}
