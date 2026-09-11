<?php
defined( 'ABSPATH' ) || exit;

/**
 * GET /mjerp/v1/bootstrap — everything the SPA needs on boot.
 */
class MJERP_REST_Bootstrap extends MJERP_REST_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'bootstrap';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/bootstrap',
			array(
				$this->endpoint( WP_REST_Server::READABLE, 'get_bootstrap' ),
			)
		);
	}

	/**
	 * GET /bootstrap
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_bootstrap( $request ) {
		$settings = $this->settings();

		$suppliers = array();
		if ( class_exists( 'MJERP_Suppliers_Repo' ) && is_callable( array( 'MJERP_Suppliers_Repo', 'all' ) ) ) {
			$suppliers = MJERP_Suppliers_Repo::all( false );
		}

		$cashboxes = array();
		if ( class_exists( 'MJERP_Cashboxes_Repo' ) && is_callable( array( 'MJERP_Cashboxes_Repo', 'all' ) ) ) {
			$cashboxes = MJERP_Cashboxes_Repo::all( false );
		}

		$stats  = $this->default_stats();
		$recent = array();
		if ( class_exists( 'MJERP_Purchases_Repo' ) ) {
			if ( is_callable( array( 'MJERP_Purchases_Repo', 'stats' ) ) ) {
				$raw = MJERP_Purchases_Repo::stats();
				if ( is_array( $raw ) ) {
					$stats = array_replace_recursive( $stats, $raw );
				}
			}
			if ( is_callable( array( 'MJERP_Purchases_Repo', 'recent' ) ) ) {
				$recent = MJERP_Purchases_Repo::recent( 5 );
			}
		}

		$data = array(
			'version'          => defined( 'MJERP_VERSION' ) ? MJERP_VERSION : '',
			'settings'         => $settings,
			'statuses'         => $this->statuses(),
			'user'             => $this->current_user(),
			'suppliers'        => is_array( $suppliers ) ? array_values( $suppliers ) : array(),
			'cashboxes'        => is_array( $cashboxes ) ? array_values( $cashboxes ) : array(),
			'stats'            => $stats,
			'recent_purchases' => is_array( $recent ) ? array_values( $recent ) : array(),
			'wc'               => $this->wc_info(),
			'expense_types'    => $this->key_label_list( isset( $settings['expense_types'] ) ? $settings['expense_types'] : array() ),
			'price_lists'      => $this->key_label_list( isset( $settings['price_lists'] ) ? $settings['price_lists'] : array(), true ),
			'roles'            => $this->roles(),
			'consignment'      => $this->consignment_info(),
			'sales'            => $this->sales_info(),
			'app_url'          => $this->app_url(),
			'home_url'         => home_url( '/' ),
			'admin_url'        => admin_url(),
			'today'            => current_time( 'Y-m-d' ),
			'server_time'      => current_time( 'mysql' ),
		);

		/**
		 * Filter the bootstrap payload.
		 *
		 * @param array $data Payload.
		 */
		$data = apply_filters( 'mjerp_rest_bootstrap', $data );

		return $this->ok( $data );
	}

	/**
	 * Consignment counters and portal info for the nav badge and the dashboard.
	 *
	 * @return array
	 */
	protected function consignment_info() {
		$info = array(
			'enabled'       => false,
			'consignees'    => 0,
			'pending_sales' => 0,
			'balance_base'  => 0.0,
			'on_hand_units' => 0.0,
			'on_hand_value_base' => 0.0,
			'unallocated_base'   => 0.0,
			'declared_payments'  => 0,
			'declared_base'      => 0.0,
			'over_limit'    => 0,
			'portal_enabled' => false,
			'portal_url'    => '',
			'payment_methods' => array(),
			'statuses'      => array(),
		);

		if ( ! class_exists( 'MJERP_Consignment_Account' ) ) {
			return $info;
		}

		$totals = MJERP_Consignment_Account::totals();

		$info['enabled']            = true;
		$info['consignees']         = (int) $totals['consignees'];
		$info['pending_sales']      = (int) $totals['pending_sales'];
		$info['balance_base']       = (float) $totals['balance_base'];
		$info['on_hand_units']      = (float) $totals['on_hand_units'];
		$info['on_hand_value_base'] = (float) $totals['on_hand_value_base'];
		$info['unallocated_base']   = (float) $totals['unallocated_base'];
		$info['declared_payments']  = (int) $totals['declared_payments'];
		$info['declared_base']      = (float) $totals['declared_base'];
		$info['over_limit']         = (int) $totals['over_limit'];
		$info['payment_methods']    = MJERP_Settings::payment_methods();
		$info['statuses']           = MJERP_Settings::consignment_statuses();

		if ( class_exists( 'MJERP_Portal' ) ) {
			$info['portal_enabled'] = MJERP_Portal::is_enabled();
			$info['portal_url']     = MJERP_Portal::is_enabled() ? MJERP_Portal::url() : '';
		}

		return $info;
	}

	/**
	 * Sales catalogues and counters for the nav badge, the dashboard and the new-sale screen.
	 *
	 * @return array
	 */
	protected function sales_info() {
		$info = array(
			'enabled'          => false,
			'statuses'         => array(),
			'payment_statuses' => array(),
			'channels'         => array(),
			'payment_methods'  => array(),
			'delivery_enabled' => false,
			'delivery_methods' => array(),
			'delivery_statuses' => array(),
			'commit_when'      => 'pago_o_entrega',
			'create_order'     => true,
			'order_statuses'   => array(),
			'stats'            => array(),
			'unpaid_count'     => 0,
			'declared_count'   => 0,
			'pending_delivery' => 0,
			'currency'         => '',
			'defaults'         => array(),
			'permissions'      => array(),
		);

		if ( ! class_exists( 'MJERP_Sales_Repo' ) || ! class_exists( 'MJERP_Sales' ) ) {
			return $info;
		}

		$settings = $this->settings();

		$info['enabled']          = MJERP_Sales::is_enabled();
		$info['statuses']         = MJERP_Settings::sale_statuses();
		$info['payment_statuses'] = MJERP_Settings::sale_payment_statuses();
		$info['channels']         = MJERP_Settings::sale_channels();
		$info['payment_methods']  = MJERP_Settings::sale_payment_methods();
		$info['delivery_enabled'] = MJERP_Settings::delivery_enabled();
		$info['delivery_methods'] = MJERP_Settings::sale_delivery_methods();
		$info['delivery_statuses'] = MJERP_Settings::sale_delivery_statuses();
		/*
		 * Cuándo una venta se vuelve firme. La pantalla de carga lo usa para decir, antes de
		 * confirmar, si esto va a emitir el pedido y bajar el stock o si queda anotado nomás.
		 */
		$info['commit_when']      = (string) MJERP_Helpers::array_get( $settings, 'sales_commit_when', 'pago_o_entrega' );
		$info['create_order']     = MJERP_Helpers::bool( MJERP_Helpers::array_get( $settings, 'sales_create_order', true ) );
		$info['order_statuses']   = class_exists( 'MJERP_Orders' ) ? MJERP_Orders::statuses() : array();
		$info['currency']         = MJERP_Sales_Repo::shop_currency();
		$info['permissions']      = class_exists( 'MJERP_Permissions' ) ? MJERP_Permissions::keys() : array();

		if ( $info['enabled'] ) {
			$info['stats']          = MJERP_Sales_Repo::stats();
			$info['unpaid_count']   = MJERP_Sales_Repo::unpaid_count();
			$info['declared_count'] = class_exists( 'MJERP_Sale_Payments_Repo' ) ? MJERP_Sale_Payments_Repo::declared_count() : 0;
			// Lo que falta entregar: es el otro «esto espera algo tuyo» del menú, junto al saldo.
			$info['pending_delivery'] = $info['delivery_enabled'] ? MJERP_Sales_Repo::pending_delivery_count() : 0;
		}

		$default_method = (string) MJERP_Helpers::array_get( $settings, 'sales_default_payment_method', 'efectivo' );

		$info['defaults'] = array(
			'channel'        => (string) MJERP_Helpers::array_get( $settings, 'sales_default_channel', 'mostrador' ),
			'payment_method' => $default_method,
			// The box of the default method, not the general one: the form opens already pointing
			// where that money actually goes.
			'cashbox_id'     => (int) MJERP_Settings::cashbox_for_method( $default_method, 'sales' ),
			'fallback_cashbox_id' => (int) MJERP_Helpers::array_get( $settings, 'sales_default_cashbox', 0 ),
			'price_list'     => (string) MJERP_Helpers::array_get( $settings, 'sales_price_list', '' ),
			'create_order'   => MJERP_Helpers::bool( MJERP_Helpers::array_get( $settings, 'sales_create_order', true ) ),
			'rate'           => MJERP_Sales_Repo::default_rate( MJERP_Sales_Repo::shop_currency() ),
			'allow_discount' => MJERP_Helpers::bool( MJERP_Helpers::array_get( $settings, 'sales_allow_discount', true ) ),
			'delivery_method' => (string) MJERP_Helpers::array_get( $settings, 'sales_default_delivery_method', '' ),
		);

		return $info;
	}

	/**
	 * Empty stats skeleton (keys always present for the dashboard).
	 *
	 * @return array
	 */
	protected function default_stats() {
		$counts = array();
		foreach ( array_keys( $this->statuses() ) as $key ) {
			$counts[ $key ] = 0;
		}
		return array(
			'counts'           => $counts,
			'month'            => array( 'count' => 0, 'total' => 0.0 ),
			'year'             => array( 'count' => 0, 'total' => 0.0 ),
			'in_transit_total' => 0.0,
		);
	}

	/**
	 * Current user summary.
	 *
	 * @return array
	 */
	protected function current_user() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return array(
				'id'     => 0,
				'name'   => '',
				'email'  => '',
				'avatar' => '',
				'roles'  => array(),
			);
		}
		$avatar = get_avatar_url( $user->ID, array( 'size' => 64 ) );
		return array(
			'id'     => (int) $user->ID,
			'name'   => (string) $user->display_name,
			'email'  => (string) $user->user_email,
			'avatar' => $avatar ? (string) $avatar : '',
			'roles'  => array_values( (array) $user->roles ),
		);
	}

	/**
	 * WooCommerce info.
	 *
	 * @return array
	 */
	protected function wc_info() {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$symbol   = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' ) : '';
		$version  = '';
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( $wc && isset( $wc->version ) ) {
				$version = (string) $wc->version;
			}
		}
		if ( '' === $version && defined( 'WC_VERSION' ) ) {
			$version = (string) WC_VERSION;
		}
		return array(
			'currency'        => (string) $currency,
			'currency_symbol' => (string) $symbol,
			'version'         => $version,
			'active'          => function_exists( 'WC' ),
		);
	}

	/**
	 * Roles as [{key,label}].
	 *
	 * @return array
	 */
	protected function roles() {
		$out   = array();
		$roles = wp_roles();
		$names = $roles ? $roles->get_names() : array();
		foreach ( (array) $names as $key => $label ) {
			$out[] = array(
				'key'   => (string) $key,
				'label' => translate_user_role( $label ),
			);
		}
		return $out;
	}

	/**
	 * Normalise a list of {key,label[,markup]} entries.
	 *
	 * @param mixed $list        Raw list.
	 * @param bool  $with_markup Include markup.
	 * @return array
	 */
	protected function key_label_list( $list, $with_markup = false ) {
		$out = array();
		foreach ( (array) $list as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['key'] ) ) {
				continue;
			}
			$row = array(
				'key'   => sanitize_key( (string) $entry['key'] ),
				'label' => isset( $entry['label'] ) ? (string) $entry['label'] : (string) $entry['key'],
			);
			if ( $with_markup ) {
				$row['markup'] = isset( $entry['markup'] ) ? (float) $entry['markup'] : 0.0;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * SPA URL.
	 *
	 * @return string
	 */
	protected function app_url() {
		if ( class_exists( 'MJERP_Frontend' ) && is_callable( array( 'MJERP_Frontend', 'app_url' ) ) ) {
			return (string) MJERP_Frontend::app_url();
		}
		$slug = $this->setting( 'route_slug', 'mjerp' );
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			$slug = 'mjerp';
		}
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . $slug . '/' );
		}
		return home_url( '/?mjerp_app=1' );
	}
}
