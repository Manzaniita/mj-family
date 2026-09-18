<?php
defined( 'ABSPATH' ) || exit;

/**
 * /mjerp/v1 — Caja: saldos, libro de cada caja, movimientos a mano, transferencias y cierres.
 *
 * Ver el libro y anotar un movimiento lo hace cualquiera que use el ERP. Borrar un movimiento o
 * deshacer un cierre es cambiar plata que ya se contó: lo hace solo quien puede corregir ventas.
 * Con el módulo apagado (Configuración → Caja y reportes), todas las rutas rechazan.
 *
 * @package MJ_ERP
 */
class MJERP_REST_Cash extends MJERP_REST_Controller {

	protected $rest_base = 'cash';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns  = $this->namespace;
		$box = array( 'id' => $this->id_arg( __( 'ID de la caja.', 'mj-erp' ) ) );

		register_rest_route(
			$ns,
			'/cash',
			array(
				$this->endpoint( WP_REST_Server::READABLE, 'get_summary' ),
			)
		);

		register_rest_route(
			$ns,
			'/cash/transfer',
			array(
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'transfer',
					array(
						'from_id'       => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'to_id'         => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'amount'        => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'amount_to'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'movement_date' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'concept'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'reference'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/cash/(?P<id>\d+)/movements',
			array(
				'args' => $box,
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_movements',
					array(
						'date_from' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'date_to'   => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'source'    => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'page'      => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						'per_page'  => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'create_movement',
					array(
						'direction'     => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'kind'          => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
						'amount'        => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'currency'      => array( 'sanitize_callback' => array( $this, 'arg_currency' ) ),
						'rate'          => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'movement_date' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'concept'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'reference'     => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/cash-movements/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del movimiento.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_movement' ),
			)
		);

		register_rest_route(
			$ns,
			'/cash/(?P<id>\d+)/closures',
			array(
				'args' => $box,
				$this->endpoint(
					WP_REST_Server::READABLE,
					'list_closures',
					array(
						'date' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
					)
				),
				$this->endpoint(
					WP_REST_Server::CREATABLE,
					'close_day',
					array(
						'closure_date'    => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
						'counted_balance' => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
						'note'            => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/cash-closures/(?P<id>\d+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'ID del cierre.', 'mj-erp' ) ) ),
				$this->endpoint( WP_REST_Server::DELETABLE, 'delete_closure' ),
			)
		);
	}

	/**
	 * Module on, or the reason it is not.
	 *
	 * @return true|WP_Error
	 */
	protected function require_enabled() {
		if ( ! class_exists( 'MJERP_Cash_Repo' ) || ! MJERP_Cash_Repo::enabled() ) {
			return $this->fail( 'mjerp_cash_disabled', __( 'La Caja está apagada en Configuración → Caja y reportes.', 'mj-erp' ), 409 );
		}
		return true;
	}

	/**
	 * Correcting money already counted: only for whoever corrects sales.
	 *
	 * @return true|WP_Error
	 */
	protected function require_manage() {
		if ( ! MJERP_Capabilities::can_manage_sales() ) {
			return $this->fail( 'mjerp_forbidden', __( 'Borrar movimientos y deshacer cierres lo hace solo quien puede corregir ventas (Configuración → Ventas → Corregir ventas).', 'mj-erp' ), 403 );
		}
		return true;
	}

	/**
	 * The cashbox of the route, or a 404.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	protected function load_cashbox( $request ) {
		$box = MJERP_Cashboxes_Repo::find( $this->route_id( $request ) );
		if ( ! $box ) {
			return $this->fail( 'mjerp_not_found', __( 'La caja no existe.', 'mj-erp' ), 404 );
		}
		return $box;
	}

	/**
	 * GET /cash — every cashbox with its balance, plus what the screen needs to draw itself.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary( $request ) {
		unset( $request );
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		return $this->ok(
			array(
				'cashboxes'       => MJERP_Cash_Repo::balances_summary(),
				'sources'         => MJERP_Cash_Repo::sources(),
				'kinds'           => MJERP_Cash_Repo::kinds(),
				'closure_enabled' => MJERP_Cash_Repo::closures_enabled(),
				'require_reason'  => MJERP_Helpers::bool( $this->setting( 'cash_require_reason', true ) ),
				'can_manage'      => MJERP_Capabilities::can_manage_sales(),
				'today'           => current_time( 'Y-m-d' ),
			)
		);
	}

	/**
	 * GET /cash/{id}/movements
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_movements( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$box = $this->load_cashbox( $request );
		if ( is_wp_error( $box ) ) {
			return $box;
		}

		$params = $this->params( $request );
		$result = MJERP_Cash_Repo::ledger(
			$box['id'],
			array(
				'date_from' => (string) $this->date( $params, 'date_from' ),
				'date_to'   => (string) $this->date( $params, 'date_to' ),
				'source'    => $this->str( $params, 'source' ),
				'page'      => max( 1, $this->int( $params, 'page', 1 ) ),
				'per_page'  => $this->int( $params, 'per_page', 50 ),
			)
		);

		$result['cashbox']  = $box;
		$result['balances'] = MJERP_Cash_Repo::balances( $box['id'] );

		return $this->ok( $result );
	}

	/**
	 * POST /cash/{id}/movements — a manual income or expense.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_movement( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$box = $this->load_cashbox( $request );
		if ( is_wp_error( $box ) ) {
			return $box;
		}

		$params = $this->params( $request );
		$id     = MJERP_Cash_Repo::create_movement(
			array(
				'cashbox_id'    => $box['id'],
				'direction'     => $this->str( $params, 'direction', MJERP_Cash_Repo::DIR_IN ),
				'kind'          => $this->str( $params, 'kind', 'otro' ),
				'amount'        => $this->float( $params, 'amount' ),
				'currency'      => $this->currency( $params, 'currency', $box['currency'] ),
				'rate'          => $this->float( $params, 'rate' ),
				'movement_date' => $this->date( $params, 'movement_date' ),
				'concept'       => $this->str( $params, 'concept', '', 255 ),
				'reference'     => $this->str( $params, 'reference', '', 190 ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->ok(
			array(
				'id'       => $id,
				'movement' => MJERP_Cash_Repo::find_movement( $id ),
				'balances' => MJERP_Cash_Repo::balances( $box['id'] ),
			),
			201
		);
	}

	/**
	 * POST /cash/transfer — money from one cashbox to another.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function transfer( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}

		$params = $this->params( $request );
		$result = MJERP_Cash_Repo::transfer(
			array(
				'from_id'       => $this->int( $params, 'from_id' ),
				'to_id'         => $this->int( $params, 'to_id' ),
				'amount'        => $this->float( $params, 'amount' ),
				'amount_to'     => $this->float( $params, 'amount_to' ),
				'movement_date' => $this->date( $params, 'movement_date' ),
				'concept'       => $this->str( $params, 'concept', '', 255 ),
				'reference'     => $this->str( $params, 'reference', '', 190 ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( $result, 201 );
	}

	/**
	 * DELETE /cash-movements/{id} — only manual movements (a transfer goes whole).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_movement( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$manage = $this->require_manage();
		if ( is_wp_error( $manage ) ) {
			return $manage;
		}

		$result = MJERP_Cash_Repo::delete_movement( $this->route_id( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * GET /cash/{id}/closures — the list, plus the preview of a day when `date` is sent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_closures( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$box = $this->load_cashbox( $request );
		if ( is_wp_error( $box ) ) {
			return $box;
		}

		$params  = $this->params( $request );
		$preview = null;
		$date    = $this->date( $params, 'date' );
		if ( $date ) {
			$preview = MJERP_Cash_Repo::preview_closure( $box['id'], $date );
			if ( is_wp_error( $preview ) ) {
				return $preview;
			}
		}

		return $this->ok(
			array(
				'items'   => MJERP_Cash_Repo::closures( $box['id'] ),
				'preview' => $preview,
				'enabled' => MJERP_Cash_Repo::closures_enabled(),
			)
		);
	}

	/**
	 * POST /cash/{id}/closures — closes a day.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function close_day( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$box = $this->load_cashbox( $request );
		if ( is_wp_error( $box ) ) {
			return $box;
		}

		$params = $this->params( $request );
		$data   = array(
			'cashbox_id'   => $box['id'],
			'closure_date' => $this->date( $params, 'closure_date' ),
			'note'         => $this->str( $params, 'note', '', 255 ),
		);
		if ( $this->has( $params, 'counted_balance' ) && '' !== (string) $params['counted_balance'] ) {
			$data['counted_balance'] = $this->float( $params, 'counted_balance' );
		}

		$id = MJERP_Cash_Repo::close_day( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->ok( MJERP_Cash_Repo::find_closure( $id ), 201 );
	}

	/**
	 * DELETE /cash-closures/{id} — undoes a closure.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_closure( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$manage = $this->require_manage();
		if ( is_wp_error( $manage ) ) {
			return $manage;
		}

		$result = MJERP_Cash_Repo::delete_closure( $this->route_id( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( array( 'deleted' => true ) );
	}
}
