<?php
defined( 'ABSPATH' ) || exit;

/**
 * /mjerp/v1 — Reportes: rentabilidad por producto, canal, mes y cliente, y lo vendido bajo costo.
 *
 * Solo lectura. Corre detrás de `manage_mjerp`, como el margen del inicio: quien usa el ERP ya
 * ve costos. Con el módulo apagado (Configuración → Caja y reportes), rechaza.
 *
 * @package MJ_ERP
 */
class MJERP_REST_Reports extends MJERP_REST_Controller {

	protected $rest_base = 'reports';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns    = $this->namespace;
		$range = array(
			'date_from' => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
			'date_to'   => array( 'sanitize_callback' => array( $this, 'arg_date' ) ),
		);

		register_rest_route(
			$ns,
			'/reports/summary',
			array(
				$this->endpoint( WP_REST_Server::READABLE, 'get_summary', $range ),
			)
		);

		register_rest_route(
			$ns,
			'/reports/products',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'get_products',
					array_merge(
						$range,
						array(
							'orderby'      => array( 'sanitize_callback' => array( $this, 'arg_key' ) ),
							'order'        => array( 'sanitize_callback' => array( $this, 'arg_order' ) ),
							'search'       => array( 'sanitize_callback' => array( $this, 'arg_text' ) ),
							'only_flagged' => array( 'sanitize_callback' => array( $this, 'arg_bool' ) ),
							'page'         => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
							'per_page'     => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						)
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/reports/below-cost',
			array(
				$this->endpoint( WP_REST_Server::READABLE, 'get_below_cost', $range ),
			)
		);

		register_rest_route(
			$ns,
			'/reports/monthly',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'get_monthly',
					array(
						'months' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/reports/customers',
			array(
				$this->endpoint(
					WP_REST_Server::READABLE,
					'get_customers',
					array_merge(
						$range,
						array(
							'limit' => array( 'sanitize_callback' => array( $this, 'arg_int' ) ),
						)
					)
				),
			)
		);
	}

	/**
	 * Module on, or the reason it is not.
	 *
	 * @return true|WP_Error
	 */
	protected function require_enabled() {
		if ( ! class_exists( 'MJERP_Reports_Repo' ) || ! MJERP_Reports_Repo::enabled() ) {
			return $this->fail( 'mjerp_reports_disabled', __( 'Los Reportes están apagados en Configuración → Caja y reportes.', 'mj-erp' ), 409 );
		}
		return true;
	}

	/**
	 * Period of the request (defaults: this month).
	 *
	 * @param array $params Params.
	 * @return array {from, to}
	 */
	protected function range_of( array $params ) : array {
		return MJERP_Reports_Repo::range( $this->date( $params, 'date_from' ), $this->date( $params, 'date_to' ) );
	}

	/**
	 * GET /reports/summary
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$range   = $this->range_of( $this->params( $request ) );
		$summary = MJERP_Reports_Repo::summary( $range['from'], $range['to'] );

		$summary['min_margin_pct']   = MJERP_Reports_Repo::min_margin_pct();
		$summary['below_cost_alert'] = MJERP_Helpers::bool( $this->setting( 'reports_below_cost_alert', true ) );

		return $this->ok( $summary );
	}

	/**
	 * GET /reports/products
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_products( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$params = $this->params( $request );
		$range  = $this->range_of( $params );

		return $this->ok(
			MJERP_Reports_Repo::products(
				$range['from'],
				$range['to'],
				array(
					'orderby'      => $this->str( $params, 'orderby', 'profit_base' ),
					'order'        => $this->str( $params, 'order', 'DESC' ),
					'search'       => $this->str( $params, 'search' ),
					'only_flagged' => $this->bool( $params, 'only_flagged' ),
					'page'         => max( 1, $this->int( $params, 'page', 1 ) ),
					'per_page'     => $this->int( $params, 'per_page', 50 ),
				)
			)
		);
	}

	/**
	 * GET /reports/below-cost
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_below_cost( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$range = $this->range_of( $this->params( $request ) );

		return $this->ok(
			array(
				'items' => MJERP_Reports_Repo::below_cost( $range['from'], $range['to'] ),
				'from'  => $range['from'],
				'to'    => $range['to'],
			)
		);
	}

	/**
	 * GET /reports/monthly
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_monthly( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$params = $this->params( $request );

		return $this->ok( array( 'items' => MJERP_Reports_Repo::monthly( $this->int( $params, 'months', 12 ) ) ) );
	}

	/**
	 * GET /reports/customers
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_customers( $request ) {
		$enabled = $this->require_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$params = $this->params( $request );
		$range  = $this->range_of( $params );

		return $this->ok( array( 'items' => MJERP_Reports_Repo::customers( $range['from'], $range['to'], $this->int( $params, 'limit', 20 ) ) ) );
	}
}
