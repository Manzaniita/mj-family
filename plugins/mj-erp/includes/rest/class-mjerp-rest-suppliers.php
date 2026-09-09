<?php
defined( 'ABSPATH' ) || exit;

/**
 * /mjerp/v1/suppliers and /mjerp/v1/cashboxes — CRUD.
 */
class MJERP_REST_Suppliers extends MJERP_REST_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'suppliers';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		$list_args = array(
			'search'           => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'include_inactive' => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
		);

		/* Suppliers */
		register_rest_route(
			$ns,
			'/suppliers',
			array(
				$this->endpoint( WP_REST_Server::READABLE, 'list_suppliers', $list_args ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'create_supplier', $this->supplier_args() ),
			)
		);

		register_rest_route(
			$ns,
			'/suppliers/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del proveedor.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'get_supplier' ),
				$this->endpoint( WP_REST_Server::EDITABLE, 'update_supplier', $this->supplier_args() ),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_supplier' ),
			)
		);

		/* Cashboxes */
		register_rest_route(
			$ns,
			'/cashboxes',
			array(
				$this->endpoint( WP_REST_Server::READABLE, 'list_cashboxes', $list_args ),
				$this->endpoint( WP_REST_Server::CREATABLE, 'create_cashbox', $this->cashbox_args() ),
			)
		);

		register_rest_route(
			$ns,
			'/cashboxes/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID de la caja.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::READABLE, 'get_cashbox' ),
				$this->endpoint( WP_REST_Server::EDITABLE, 'update_cashbox', $this->cashbox_args() ),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_cashbox' ),
			)
		);
	}

	/**
	 * Supplier args.
	 *
	 * @return array
	 */
	protected function supplier_args() {
		return array(
			'name'             => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'contact_name'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'email'            => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'phone'            => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'country'          => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'default_currency' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'notes'            => array( 'sanitize_callback' => array( $this, 'arg_textarea' ) ),
			'is_active'        => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
		);
	}

	/**
	 * Cashbox args.
	 *
	 * @return array
	 */
	protected function cashbox_args() {
		return array(
			'name'      => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'currency'  => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
			'is_active' => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Suppliers
	 * ------------------------------------------------------------------ */

	/**
	 * GET /suppliers
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_suppliers( $request ) {
		$check = $this->repo_check( 'MJERP_Suppliers_Repo' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$p                = $this->params( $request );
		$search           = $this->str( $p, 'search', '', 190 );
		$include_inactive = $this->bool( $p, 'include_inactive', false );

		$rows = MJERP_Suppliers_Repo::all( $include_inactive );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$rows = is_array( $rows ) ? array_values( $rows ) : array();

		if ( '' !== $search ) {
			$rows = $this->filter_rows( $rows, $search, array( 'name', 'contact_name', 'email', 'phone', 'country' ) );
		}

		return $this->ok( array_values( array_map( array( $this, 'cast_supplier' ), $rows ) ) );
	}

	/**
	 * GET /suppliers/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_supplier( $request ) {
		$supplier = $this->load_supplier( $this->route_id( $request ) );
		if ( is_wp_error( $supplier ) ) {
			return $supplier;
		}
		return $this->ok( $supplier );
	}

	/**
	 * POST /suppliers
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_supplier( $request ) {
		$check = $this->repo_check( 'MJERP_Suppliers_Repo' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$data = $this->supplier_data( $this->params( $request ), null );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$id = MJERP_Suppliers_Repo::create( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = absint( $id );
		if ( ! $id ) {
			return $this->fail( 'mjerp_create_failed', __( 'No se pudo crear el proveedor.', 'mj-erp' ), 500 );
		}

		$supplier = $this->load_supplier( $id );
		if ( is_wp_error( $supplier ) ) {
			return $supplier;
		}

		do_action( 'mjerp_supplier_saved', $id, $supplier, true );

		return $this->ok( $supplier, 201 );
	}

	/**
	 * PUT /suppliers/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_supplier( $request ) {
		$id       = $this->route_id( $request );
		$supplier = $this->load_supplier( $id );
		if ( is_wp_error( $supplier ) ) {
			return $supplier;
		}

		$data = $this->supplier_data( $this->params( $request ), $supplier );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data ) ) {
			return $this->ok( $supplier );
		}

		$result = MJERP_Suppliers_Repo::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$supplier = $this->load_supplier( $id );
		if ( is_wp_error( $supplier ) ) {
			return $supplier;
		}

		do_action( 'mjerp_supplier_saved', $id, $supplier, false );

		return $this->ok( $supplier );
	}

	/**
	 * DELETE /suppliers/{id} — hard delete, or soft (is_active = 0) when referenced by purchases.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_supplier( $request ) {
		$id       = $this->route_id( $request );
		$supplier = $this->load_supplier( $id );
		if ( is_wp_error( $supplier ) ) {
			return $supplier;
		}

		$result = MJERP_Suppliers_Repo::delete( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$after       = MJERP_Suppliers_Repo::find( $id );
		$deactivated = is_array( $after ) && ! empty( $after );

		do_action( 'mjerp_supplier_deleted', $id, $deactivated );

		return $this->ok(
			array(
				'deleted'     => true,
				'id'          => $id,
				'deactivated' => $deactivated,
				'supplier'    => $deactivated ? $this->cast_supplier( $after ) : null,
				'message'     => $deactivated
					? __( 'El proveedor tiene compras asociadas, por eso se desactivó en lugar de eliminarse.', 'mj-erp' )
					: __( 'Proveedor eliminado.', 'mj-erp' ),
			)
		);
	}

	/**
	 * Build supplier data (only keys sent; name required on create).
	 *
	 * @param array      $p       Params.
	 * @param array|null $current Current row.
	 * @return array|WP_Error
	 */
	protected function supplier_data( $p, $current ) {
		$is_new = null === $current;
		$data   = array();

		if ( $this->has( $p, 'name' ) ) {
			$name = $this->str( $p, 'name', '', 190 );
			if ( '' === $name ) {
				return $this->fail( 'mjerp_invalid_name', __( 'El nombre del proveedor es obligatorio.', 'mj-erp' ), 400, array( 'field' => 'name' ) );
			}
			$data['name'] = $name;
		} elseif ( $is_new ) {
			return $this->fail( 'mjerp_invalid_name', __( 'El nombre del proveedor es obligatorio.', 'mj-erp' ), 400, array( 'field' => 'name' ) );
		}

		if ( $this->has( $p, 'contact_name' ) ) {
			$data['contact_name'] = $this->str( $p, 'contact_name', '', 190 );
		}

		if ( $this->has( $p, 'email' ) ) {
			$email = trim( $this->str( $p, 'email', '', 190 ) );
			if ( '' !== $email ) {
				$email = sanitize_email( $email );
				if ( '' === $email || ! is_email( $email ) ) {
					return $this->fail( 'mjerp_invalid_email', __( 'El email del proveedor no es válido.', 'mj-erp' ), 400, array( 'field' => 'email' ) );
				}
			}
			$data['email'] = $email;
		}

		if ( $this->has( $p, 'phone' ) ) {
			$data['phone'] = $this->str( $p, 'phone', '', 60 );
		}

		if ( $this->has( $p, 'country' ) ) {
			$data['country'] = $this->str( $p, 'country', '', 100 );
		}

		if ( $this->has( $p, 'default_currency' ) ) {
			$raw = is_scalar( $p['default_currency'] ) ? trim( (string) $p['default_currency'] ) : '';
			if ( '' === $raw ) {
				$data['default_currency'] = '';
			} else {
				$currency = $this->clean_currency( $raw );
				if ( '' === $currency ) {
					return $this->fail( 'mjerp_invalid_currency', __( 'La moneda debe ser un código de 3 letras (ej. USD).', 'mj-erp' ), 400, array( 'field' => 'default_currency' ) );
				}
				$data['default_currency'] = $currency;
			}
		}

		if ( $this->has( $p, 'notes' ) ) {
			$data['notes'] = $this->text( $p, 'notes', '' );
		}

		if ( $this->has( $p, 'is_active' ) ) {
			$data['is_active'] = $this->bool( $p, 'is_active', true ) ? 1 : 0;
		} elseif ( $is_new ) {
			$data['is_active'] = 1;
		}

		if ( $is_new ) {
			foreach ( array( 'contact_name', 'email', 'phone', 'country', 'default_currency', 'notes' ) as $key ) {
				if ( ! isset( $data[ $key ] ) ) {
					$data[ $key ] = '';
				}
			}
		}

		return $data;
	}

	/**
	 * Supplier row or 404.
	 *
	 * @param int $id Supplier id.
	 * @return array|WP_Error
	 */
	protected function load_supplier( $id ) {
		$check = $this->repo_check( 'MJERP_Suppliers_Repo' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$id  = absint( $id );
		$row = $id ? MJERP_Suppliers_Repo::find( $id ) : null;
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( empty( $row ) || ! is_array( $row ) ) {
			return $this->fail( 'mjerp_not_found', __( 'El proveedor no existe.', 'mj-erp' ), 404, array( 'supplier_id' => $id ) );
		}
		return $this->cast_supplier( $row );
	}

	/**
	 * Normalise a supplier row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public function cast_supplier( $row ) {
		$row = (array) $row;
		if ( isset( $row['id'] ) ) {
			$row['id'] = (int) $row['id'];
		}
		if ( array_key_exists( 'is_active', $row ) ) {
			$row['is_active'] = (bool) $this->to_bool( $row['is_active'], true );
		}
		foreach ( array( 'name', 'contact_name', 'email', 'phone', 'country', 'default_currency', 'notes' ) as $key ) {
			if ( ! array_key_exists( $key, $row ) || null === $row[ $key ] ) {
				$row[ $key ] = '';
			}
		}
		return $row;
	}

	/* ---------------------------------------------------------------------
	 * Cashboxes
	 * ------------------------------------------------------------------ */

	/**
	 * GET /cashboxes
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_cashboxes( $request ) {
		$check = $this->repo_check( 'MJERP_Cashboxes_Repo' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$p                = $this->params( $request );
		$search           = $this->str( $p, 'search', '', 190 );
		$include_inactive = $this->bool( $p, 'include_inactive', false );

		$rows = MJERP_Cashboxes_Repo::all( $include_inactive );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$rows = is_array( $rows ) ? array_values( $rows ) : array();

		if ( '' !== $search ) {
			$rows = $this->filter_rows( $rows, $search, array( 'name', 'currency' ) );
		}

		return $this->ok( array_values( array_map( array( $this, 'cast_cashbox' ), $rows ) ) );
	}

	/**
	 * GET /cashboxes/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_cashbox( $request ) {
		$cashbox = $this->load_cashbox( $this->route_id( $request ) );
		if ( is_wp_error( $cashbox ) ) {
			return $cashbox;
		}
		return $this->ok( $cashbox );
	}

	/**
	 * POST /cashboxes
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_cashbox( $request ) {
		$check = $this->repo_check( 'MJERP_Cashboxes_Repo' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$data = $this->cashbox_data( $this->params( $request ), null );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$id = MJERP_Cashboxes_Repo::create( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = absint( $id );
		if ( ! $id ) {
			return $this->fail( 'mjerp_create_failed', __( 'No se pudo crear la caja.', 'mj-erp' ), 500 );
		}

		$cashbox = $this->load_cashbox( $id );
		if ( is_wp_error( $cashbox ) ) {
			return $cashbox;
		}

		do_action( 'mjerp_cashbox_saved', $id, $cashbox, true );

		return $this->ok( $cashbox, 201 );
	}

	/**
	 * PUT /cashboxes/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_cashbox( $request ) {
		$id      = $this->route_id( $request );
		$cashbox = $this->load_cashbox( $id );
		if ( is_wp_error( $cashbox ) ) {
			return $cashbox;
		}

		$data = $this->cashbox_data( $this->params( $request ), $cashbox );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data ) ) {
			return $this->ok( $cashbox );
		}

		$result = MJERP_Cashboxes_Repo::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$cashbox = $this->load_cashbox( $id );
		if ( is_wp_error( $cashbox ) ) {
			return $cashbox;
		}

		do_action( 'mjerp_cashbox_saved', $id, $cashbox, false );

		return $this->ok( $cashbox );
	}

	/**
	 * DELETE /cashboxes/{id} — hard delete, or soft when referenced by expenses.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_cashbox( $request ) {
		$id      = $this->route_id( $request );
		$cashbox = $this->load_cashbox( $id );
		if ( is_wp_error( $cashbox ) ) {
			return $cashbox;
		}

		$result = MJERP_Cashboxes_Repo::delete( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$after       = MJERP_Cashboxes_Repo::find( $id );
		$deactivated = is_array( $after ) && ! empty( $after );

		do_action( 'mjerp_cashbox_deleted', $id, $deactivated );

		return $this->ok(
			array(
				'deleted'     => true,
				'id'          => $id,
				'deactivated' => $deactivated,
				'cashbox'     => $deactivated ? $this->cast_cashbox( $after ) : null,
				'message'     => $deactivated
					? __( 'La caja tiene gastos asociados, por eso se desactivó en lugar de eliminarse.', 'mj-erp' )
					: __( 'Caja eliminada.', 'mj-erp' ),
			)
		);
	}

	/**
	 * Build cashbox data.
	 *
	 * @param array      $p       Params.
	 * @param array|null $current Current row.
	 * @return array|WP_Error
	 */
	protected function cashbox_data( $p, $current ) {
		$is_new = null === $current;
		$data   = array();

		if ( $this->has( $p, 'name' ) ) {
			$name = $this->str( $p, 'name', '', 190 );
			if ( '' === $name ) {
				return $this->fail( 'mjerp_invalid_name', __( 'El nombre de la caja es obligatorio.', 'mj-erp' ), 400, array( 'field' => 'name' ) );
			}
			$data['name'] = $name;
		} elseif ( $is_new ) {
			return $this->fail( 'mjerp_invalid_name', __( 'El nombre de la caja es obligatorio.', 'mj-erp' ), 400, array( 'field' => 'name' ) );
		}

		if ( $this->has( $p, 'currency' ) ) {
			$raw = is_scalar( $p['currency'] ) ? trim( (string) $p['currency'] ) : '';
			if ( '' === $raw ) {
				if ( $is_new ) {
					$data['currency'] = $this->default_local_currency();
				} else {
					return $this->fail( 'mjerp_invalid_currency', __( 'La moneda de la caja es obligatoria.', 'mj-erp' ), 400, array( 'field' => 'currency' ) );
				}
			} else {
				$currency = $this->clean_currency( $raw );
				if ( '' === $currency ) {
					return $this->fail( 'mjerp_invalid_currency', __( 'La moneda debe ser un código de 3 letras (ej. ARS).', 'mj-erp' ), 400, array( 'field' => 'currency' ) );
				}
				$data['currency'] = $currency;
			}
		} elseif ( $is_new ) {
			$data['currency'] = $this->default_local_currency();
		}

		if ( $this->has( $p, 'is_active' ) ) {
			$data['is_active'] = $this->bool( $p, 'is_active', true ) ? 1 : 0;
		} elseif ( $is_new ) {
			$data['is_active'] = 1;
		}

		return $data;
	}

	/**
	 * Cashbox row or 404.
	 *
	 * @param int $id Cashbox id.
	 * @return array|WP_Error
	 */
	protected function load_cashbox( $id ) {
		$check = $this->repo_check( 'MJERP_Cashboxes_Repo' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$id  = absint( $id );
		$row = $id ? MJERP_Cashboxes_Repo::find( $id ) : null;
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( empty( $row ) || ! is_array( $row ) ) {
			return $this->fail( 'mjerp_not_found', __( 'La caja no existe.', 'mj-erp' ), 404, array( 'cashbox_id' => $id ) );
		}
		return $this->cast_cashbox( $row );
	}

	/**
	 * Normalise a cashbox row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public function cast_cashbox( $row ) {
		$row = (array) $row;
		if ( isset( $row['id'] ) ) {
			$row['id'] = (int) $row['id'];
		}
		if ( array_key_exists( 'is_active', $row ) ) {
			$row['is_active'] = (bool) $this->to_bool( $row['is_active'], true );
		}
		if ( ! isset( $row['name'] ) ) {
			$row['name'] = '';
		}
		if ( empty( $row['currency'] ) ) {
			$row['currency'] = $this->default_local_currency();
		}
		return $row;
	}

	/* ---------------------------------------------------------------------
	 * Shared
	 * ------------------------------------------------------------------ */

	/**
	 * Default currency for cashboxes.
	 *
	 * @return string
	 */
	protected function default_local_currency() {
		$currency = $this->clean_currency( $this->setting( 'local_currency', 'ARS' ) );
		return '' === $currency ? 'ARS' : $currency;
	}

	/**
	 * Make sure a repository class exists.
	 *
	 * @param string $class Class name.
	 * @return true|WP_Error
	 */
	protected function repo_check( $class ) {
		if ( ! class_exists( $class ) ) {
			return $this->fail( 'mjerp_unavailable', __( 'El módulo solicitado no está disponible.', 'mj-erp' ), 500 );
		}
		return true;
	}

	/**
	 * Case-insensitive "contains" filter over several columns.
	 *
	 * @param array  $rows    Rows.
	 * @param string $search  Term.
	 * @param array  $columns Columns.
	 * @return array
	 */
	protected function filter_rows( $rows, $search, $columns ) {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
		$out    = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $columns as $column ) {
				if ( ! isset( $row[ $column ] ) || ! is_scalar( $row[ $column ] ) ) {
					continue;
				}
				$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $row[ $column ] ) : strtolower( (string) $row[ $column ] );
				if ( false !== strpos( $haystack, $needle ) ) {
					$out[] = $row;
					break;
				}
			}
		}
		return $out;
	}
}
