<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers every REST controller of the plugin under the `mjerp/v1` namespace.
 */
class MJERP_REST {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'mjerp/v1';

	/**
	 * Whether routes were already registered in this request.
	 *
	 * @var bool
	 */
	protected static $registered = false;

	/**
	 * Hook into rest_api_init.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Controller class => file map (files live next to this one).
	 *
	 * @return array
	 */
	public static function controllers() {
		return array(
			'MJERP_REST_Bootstrap' => 'class-mjerp-rest-bootstrap.php',
			'MJERP_REST_Purchases' => 'class-mjerp-rest-purchases.php',
			'MJERP_REST_Suppliers' => 'class-mjerp-rest-suppliers.php',
			'MJERP_REST_Products'  => 'class-mjerp-rest-products.php',
			'MJERP_REST_Customers' => 'class-mjerp-rest-customers.php',
			'MJERP_REST_Settings'  => 'class-mjerp-rest-settings.php',
			'MJERP_REST_Consignments' => 'class-mjerp-rest-consignments.php',
			'MJERP_REST_Sales'     => 'class-mjerp-rest-sales.php',
			'MJERP_REST_Credit'    => 'class-mjerp-rest-credit.php',
			'MJERP_REST_Returns'   => 'class-mjerp-rest-returns.php',
			'MJERP_REST_Cash'      => 'class-mjerp-rest-cash.php',
			'MJERP_REST_Reports'   => 'class-mjerp-rest-reports.php',
			'MJERP_REST_Portal'    => 'class-mjerp-rest-portal.php',
		);
	}

	/**
	 * Make sure the controller classes are loaded (the bootstrap normally requires them,
	 * this is only a safety net).
	 */
	public static function load_files() {
		$dir = __DIR__;

		if ( ! class_exists( 'MJERP_REST_Controller' ) && file_exists( $dir . '/class-mjerp-rest-controller.php' ) ) {
			require_once $dir . '/class-mjerp-rest-controller.php';
		}

		foreach ( self::controllers() as $class => $file ) {
			if ( ! class_exists( $class ) && file_exists( $dir . '/' . $file ) ) {
				require_once $dir . '/' . $file;
			}
		}
	}

	/**
	 * Instantiate every controller and register its routes.
	 */
	public static function register_routes() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		self::load_files();

		if ( ! class_exists( 'MJERP_REST_Controller' ) ) {
			return;
		}

		/**
		 * Filter the list of controller classes registered by MJ ERP.
		 *
		 * @param array $classes Class names.
		 */
		$classes = apply_filters( 'mjerp_rest_controllers', array_keys( self::controllers() ) );

		foreach ( (array) $classes as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}
			$controller = new $class();
			if ( is_callable( array( $controller, 'register_routes' ) ) ) {
				$controller->register_routes();
			}
		}

		/**
		 * Fires after MJ ERP registered its REST routes.
		 */
		do_action( 'mjerp_rest_routes_registered' );
	}
}
