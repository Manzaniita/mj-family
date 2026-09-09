<?php
/**
 * Settings: defaults, schema, get/update/sanitize.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Settings
 */
class MJERP_Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'mjerp_settings';

	/**
	 * Request-level cache of the merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Route slugs that must never be used for the app.
	 *
	 * @var string[]
	 */
	private static $reserved_slugs = array( 'wp-admin', 'wp-json', 'wp-login', 'wp-login.php', 'wp-content', 'wp-includes', 'feed', 'admin', 'login', 'index.php', 'xmlrpc.php' );

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = array(
			'route_slug'                => 'mjerp',
			'app_title'                 => 'MJ ERP',
			'theme'                     => 'dark',
			'locale'                    => 'es-AR',
			'decimals'                  => 2,
			'base_currency'             => 'USD',
			'local_currency'            => 'ARS',
			'currencies'                => array( 'USD', 'ARS' ),
			'currency_symbols'          => array(
				'USD' => 'US$',
				'ARS' => '$',
			),
			'default_rate'              => 1000,
			'sale_rate'                 => 1000,
			'sale_rate_source'          => 'mjdolar',
			'proration_method'          => 'fob',
			'destinations'              => array( 'Local', 'Depósito' ),
			'expense_types'             => array(
				array(
					'key'   => 'envio',
					'label' => 'Envío',
				),
				array(
					'key'   => 'despachante',
					'label' => 'Despachante',
				),
				array(
					'key'   => 'impuestos',
					'label' => 'Impuestos',
				),
				array(
					'key'   => 'comision',
					'label' => 'Comisión',
				),
				array(
					'key'   => 'otro',
					'label' => 'Otro',
				),
			),
			'price_lists'               => array(
				array(
					'key'    => 'publico',
					'label'  => 'Público',
					'markup' => 70,
				),
				array(
					'key'    => 'mayorista',
					'label'  => 'Mayorista',
					'markup' => 40,
				),
			),
			'code_prefix'               => 'IMP',
			'code_format'               => '{prefix}-{year}-{seq}',
			'code_seq_digits'           => 4,
			'stock_on_receive'          => 'increase',
			'enable_stock_management'   => true,
			'update_cost_on_receive'    => true,
			'cost_meta_key'             => '_mjerp_cost',
			'update_price_on_receive'   => false,
			'price_list_for_price'      => 'publico',
			'price_target'              => 'regular_price',
			'price_rounding'            => 0,
			'price_publish_mode'        => 'auto',
			'usd_rounding'              => 0.0,
			'usd_rounding_mode'         => 'nearest',
			'allow_unreceive'           => true,

			/* --- Consignaciones ------------------------------------------------------------ */
			'consignee_role'            => 'reventa',
			'consignee_role_label'      => 'Reventa',
			'consignment_code_prefix'   => 'CON',
			'consignment_code_format'   => '{prefix}-{year}-{seq}',
			'consignment_code_seq_digits' => 4,
			'consignment_price_list'    => 'mayorista',
			'consignment_stock_on_sale' => 'decrease',
			'consignment_approval'      => 'portal',
			'consignment_allow_oversell' => false,
			'consignment_allocation'    => 'fifo',
			'consignment_payment_methods' => array(
				array(
					'key'   => 'efectivo',
					'label' => 'Efectivo',
				),
				array(
					'key'   => 'transferencia',
					'label' => 'Transferencia',
				),
				array(
					'key'   => 'dolares',
					'label' => 'Dólares',
				),
				array(
					'key'   => 'otro',
					'label' => 'Otro',
				),
			),
			'portal_enabled'            => true,
			'portal_slug'               => 'reventa',
			'portal_title'              => 'Mi cuenta',
			'portal_allow_sales'        => true,
			'portal_allow_returns'      => false,

			'allowed_roles'             => array( 'administrator', 'shop_manager' ),
			'new_product_status'        => 'draft',
			'delete_data_on_uninstall'  => false,
		);

		/**
		 * Filters the default settings.
		 *
		 * @param array $defaults Defaults.
		 */
		$filtered = apply_filters( 'mjerp_settings_defaults', $defaults );
		return is_array( $filtered ) ? array_merge( $defaults, $filtered ) : $defaults;
	}

	/**
	 * All settings (defaults merged with the stored option), type-coerced.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored = array_intersect_key( $stored, $defaults );

		$settings = array_merge( $defaults, $stored );
		$settings = self::coerce_types( $settings, $defaults );
		$settings = self::ensure_consistency( $settings, $defaults );

		/**
		 * Filters the merged settings.
		 *
		 * @param array $settings Settings.
		 */
		$filtered = apply_filters( 'mjerp_settings', $settings );
		self::$cache = is_array( $filtered ) ? array_merge( $settings, $filtered ) : $settings;

		return self::$cache;
	}

	/**
	 * Gets one setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default when the key does not exist.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Sanitizes and saves a partial set of settings. Returns the full saved settings.
	 *
	 * @param array $partial Partial settings.
	 * @return array
	 */
	public static function update( array $partial ) {
		$defaults = self::defaults();
		$current  = self::all();
		$clean    = self::sanitize( $partial );

		$new = array_merge( $current, $clean );
		$new = self::ensure_consistency( $new, $defaults );
		$new = array_intersect_key( $new, $defaults );

		update_option( self::OPTION, $new, true );
		self::$cache = null;

		$slug_changed = ( (string) $new['route_slug'] !== (string) $current['route_slug'] )
			|| ( (string) $new['portal_slug'] !== (string) $current['portal_slug'] )
			|| ( (bool) $new['portal_enabled'] !== (bool) $current['portal_enabled'] );

		$old_roles = (array) $current['allowed_roles'];
		$new_roles = (array) $new['allowed_roles'];
		sort( $old_roles );
		sort( $new_roles );
		$roles_changed = ( $old_roles !== $new_roles );

		if ( $slug_changed ) {
			update_option( 'mjerp_flush_rewrite', 1, true );
			if ( did_action( 'init' ) ) {
				// Register the new rules right away so the new URLs work in the very next request.
				if ( class_exists( 'MJERP_Frontend' ) ) {
					MJERP_Frontend::register_rewrite();
				}
				if ( class_exists( 'MJERP_Portal' ) ) {
					MJERP_Portal::register_rewrite();
				}
				flush_rewrite_rules( false );
			}
		}

		if ( $roles_changed && class_exists( 'MJERP_Install' ) ) {
			MJERP_Install::sync_capabilities( $new_roles );
		}

		/**
		 * Fires after the settings were updated.
		 *
		 * @param array $new     New settings.
		 * @param array $current Previous settings.
		 */
		do_action( 'mjerp_settings_updated', $new, $current );

		return self::all();
	}

	/**
	 * Sanitizes an input array key by key. Unknown keys are dropped.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized subset (only the keys present in $input).
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$out      = array();

		foreach ( $input as $key => $value ) {
			$key = (string) $key;
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$out[ $key ] = self::sanitize_field( $key, $value, $defaults[ $key ] );
		}

		// Cross-key rules that can be applied on the subset itself.
		if ( isset( $out['currencies'] ) ) {
			foreach ( array( 'base_currency', 'local_currency' ) as $ck ) {
				if ( isset( $out[ $ck ] ) && ! in_array( $out[ $ck ], $out['currencies'], true ) ) {
					array_unshift( $out['currencies'], $out[ $ck ] );
				}
			}
			$out['currencies'] = array_values( array_unique( $out['currencies'] ) );
		}
		if ( isset( $out['price_lists'], $out['price_list_for_price'] ) ) {
			$keys = wp_list_pluck( $out['price_lists'], 'key' );
			if ( ! in_array( $out['price_list_for_price'], $keys, true ) ) {
				$out['price_list_for_price'] = ! empty( $keys ) ? (string) reset( $keys ) : '';
			}
		}

		return $out;
	}

	/**
	 * Purchase status catalogue.
	 *
	 * @return array
	 */
	public static function statuses() {
		$statuses = array(
			'borrador'    => array(
				'label' => __( 'Borrador', 'mj-erp' ),
				'color' => 'neutral',
			),
			'en_transito' => array(
				'label' => __( 'En tránsito', 'mj-erp' ),
				'color' => 'warning',
			),
			'recibida'    => array(
				'label' => __( 'Recibida', 'mj-erp' ),
				'color' => 'success',
			),
			'cancelada'   => array(
				'label' => __( 'Cancelada', 'mj-erp' ),
				'color' => 'danger',
			),
		);

		/**
		 * Filters the status catalogue (labels/colors only; keys are fixed).
		 *
		 * @param array $statuses Statuses.
		 */
		$filtered = apply_filters( 'mjerp_statuses', $statuses );
		return is_array( $filtered ) ? array_merge( $statuses, array_intersect_key( $filtered, $statuses ) ) : $statuses;
	}

	/**
	 * Label of a status key.
	 *
	 * @param string $key Status key.
	 * @return string
	 */
	public static function status_label( $key ) {
		$statuses = self::statuses();
		$key      = sanitize_key( (string) $key );
		return isset( $statuses[ $key ] ) ? $statuses[ $key ]['label'] : ucfirst( str_replace( '_', ' ', $key ) );
	}

	/**
	 * Expense types list `[ {key,label}, ... ]`.
	 *
	 * @return array
	 */
	public static function expense_types() {
		$types = self::get( 'expense_types', array() );
		return is_array( $types ) ? array_values( $types ) : array();
	}

	/**
	 * Label of an expense type key.
	 *
	 * @param string $key Expense type key.
	 * @return string
	 */
	public static function expense_type_label( $key ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			$key = 'otro';
		}
		foreach ( self::expense_types() as $type ) {
			if ( isset( $type['key'] ) && (string) $type['key'] === $key ) {
				return isset( $type['label'] ) && '' !== (string) $type['label'] ? (string) $type['label'] : ucfirst( $key );
			}
		}
		$defaults = self::defaults();
		foreach ( $defaults['expense_types'] as $type ) {
			if ( $type['key'] === $key ) {
				return $type['label'];
			}
		}
		if ( 'otro' === $key ) {
			return __( 'Otro', 'mj-erp' );
		}
		return ucfirst( str_replace( array( '_', '-' ), ' ', $key ) );
	}

	/**
	 * Consignment payment methods `[ {key,label}, ... ]`.
	 *
	 * @return array
	 */
	public static function payment_methods() {
		$methods = self::get( 'consignment_payment_methods', array() );
		return is_array( $methods ) ? array_values( $methods ) : array();
	}

	/**
	 * Label of a payment method key.
	 *
	 * @param string $key Method key.
	 * @return string
	 */
	public static function payment_method_label( $key ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			$key = 'otro';
		}
		foreach ( self::payment_methods() as $method ) {
			if ( isset( $method['key'] ) && (string) $method['key'] === $key ) {
				return isset( $method['label'] ) && '' !== (string) $method['label'] ? (string) $method['label'] : ucfirst( $key );
			}
		}
		return 'otro' === $key ? __( 'Otro', 'mj-erp' ) : ucfirst( str_replace( array( '_', '-' ), ' ', $key ) );
	}

	/**
	 * Consignment status catalogue.
	 *
	 * @return array
	 */
	public static function consignment_statuses() {
		$statuses = array(
			'borrador'  => array(
				'label' => __( 'Borrador', 'mj-erp' ),
				'color' => 'neutral',
			),
			'entregada' => array(
				'label' => __( 'Entregada', 'mj-erp' ),
				'color' => 'success',
			),
			'cerrada'   => array(
				'label' => __( 'Cerrada', 'mj-erp' ),
				'color' => 'info',
			),
			'cancelada' => array(
				'label' => __( 'Cancelada', 'mj-erp' ),
				'color' => 'danger',
			),
		);

		/**
		 * Filters the consignment status catalogue (labels/colors only; keys are fixed).
		 *
		 * @param array $statuses Statuses.
		 */
		$filtered = apply_filters( 'mjerp_consignment_statuses', $statuses );
		return is_array( $filtered ) ? array_merge( $statuses, array_intersect_key( $filtered, $statuses ) ) : $statuses;
	}

	/**
	 * Rounding steps offered for the published USD price (step => label).
	 *
	 * Keys are compared as floats: PHP turns '0', '1', '5' and '10' into integer keys while '0.5'
	 * stays a string, so never compare them with `array_key_exists`.
	 *
	 * @return array
	 */
	public static function usd_rounding_choices() {
		return array(
			'0'   => __( 'Sin redondeo (con centavos)', 'mj-erp' ),
			'0.5' => __( 'A los 50 centavos', 'mj-erp' ),
			'1'   => __( 'Al dólar', 'mj-erp' ),
			'5'   => __( 'A los 5 dólares', 'mj-erp' ),
			'10'  => __( 'A los 10 dólares', 'mj-erp' ),
		);
	}

	/**
	 * Price lists `[ {key,label,markup}, ... ]`.
	 *
	 * @return array
	 */
	public static function price_lists() {
		$lists = self::get( 'price_lists', array() );
		return is_array( $lists ) ? array_values( $lists ) : array();
	}

	/**
	 * One price list by key (null when it does not exist).
	 *
	 * @param string $key List key.
	 * @return array|null `{key,label,markup}`
	 */
	public static function price_list( $key ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return null;
		}
		foreach ( self::price_lists() as $list ) {
			if ( isset( $list['key'] ) && (string) $list['key'] === $key ) {
				return array(
					'key'    => (string) $list['key'],
					'label'  => isset( $list['label'] ) ? (string) $list['label'] : $key,
					'markup' => isset( $list['markup'] ) ? (float) $list['markup'] : 0.0,
				);
			}
		}
		return null;
	}

	/**
	 * Allowed currencies.
	 *
	 * @return string[]
	 */
	public static function currencies() {
		$list = self::get( 'currencies', array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Symbol of a currency (falls back to the code itself).
	 *
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function symbol( $currency ) {
		$currency = strtoupper( trim( (string) $currency ) );
		$symbols  = self::get( 'currency_symbols', array() );
		if ( is_array( $symbols ) && isset( $symbols[ $currency ] ) && '' !== (string) $symbols[ $currency ] ) {
			return (string) $symbols[ $currency ];
		}
		return $currency;
	}

	/**
	 * Clears the request-level cache (e.g. after a direct option write).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Sanitizes a single field.
	 *
	 * @param string $key     Key.
	 * @param mixed  $value   Raw value.
	 * @param mixed  $default Default for that key.
	 * @return mixed
	 */
	private static function sanitize_field( $key, $value, $default ) {
		switch ( $key ) {
			case 'route_slug':
				$slug = is_scalar( $value ) ? sanitize_title( (string) $value ) : '';
				$slug = trim( $slug, '/-' );
				if ( '' === $slug || in_array( $slug, self::$reserved_slugs, true ) ) {
					$slug = 'mjerp';
				}
				return $slug;

			case 'app_title':
				$title = self::text( $value, 100 );
				return '' === $title ? $default : $title;

			case 'theme':
				return self::enum( $value, array( 'dark', 'light' ), $default );

			case 'locale':
				$locale = is_scalar( $value ) ? trim( (string) $value ) : '';
				$locale = str_replace( '_', '-', $locale );
				if ( ! preg_match( '/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $locale ) ) {
					return $default;
				}
				return $locale;

			case 'decimals':
				return max( 0, min( 4, self::int( $value, $default ) ) );

			case 'base_currency':
			case 'local_currency':
				$code = self::currency_code( $value );
				return '' === $code ? $default : $code;

			case 'currencies':
				$list = array();
				foreach ( self::to_list( $value ) as $item ) {
					$code = self::currency_code( is_array( $item ) ? ( isset( $item['key'] ) ? $item['key'] : ( isset( $item['code'] ) ? $item['code'] : '' ) ) : $item );
					if ( '' !== $code ) {
						$list[] = $code;
					}
				}
				$list = array_values( array_unique( $list ) );
				return empty( $list ) ? $default : $list;

			case 'currency_symbols':
				$symbols = array();
				if ( is_array( $value ) ) {
					foreach ( $value as $k => $v ) {
						if ( is_array( $v ) ) {
							// Row form: {key|code, symbol|label|value}.
							$code = isset( $v['key'] ) ? $v['key'] : ( isset( $v['code'] ) ? $v['code'] : ( isset( $v['currency'] ) ? $v['currency'] : $k ) );
							$sym  = isset( $v['symbol'] ) ? $v['symbol'] : ( isset( $v['label'] ) ? $v['label'] : ( isset( $v['value'] ) ? $v['value'] : '' ) );
						} else {
							$code = $k;
							$sym  = $v;
						}
						$code = self::currency_code( $code );
						$sym  = self::text( $sym, 10 );
						if ( '' !== $code ) {
							$symbols[ $code ] = '' === $sym ? $code : $sym;
						}
					}
				}
				return empty( $symbols ) ? $default : $symbols;

			case 'default_rate':
			case 'sale_rate':
				$rate = MJERP_Helpers::to_float( $value, -1 );
				return $rate > 0 ? $rate : (float) $default;

			case 'sale_rate_source':
				return self::enum( $value, array( 'mjdolar', 'manual' ), $default );

			case 'price_publish_mode':
				return self::enum( $value, array( 'auto', 'usd', 'local' ), $default );

			case 'usd_rounding':
				$step = MJERP_Helpers::to_float( $value, -1 );
				if ( $step < 0 ) {
					return (float) $default;
				}
				// Only the offered steps are accepted, so the JS mirror and the server agree.
				foreach ( array_keys( self::usd_rounding_choices() ) as $allowed ) {
					if ( abs( (float) $allowed - $step ) < 0.0001 ) {
						return (float) $allowed;
					}
				}
				return (float) $default;

			case 'usd_rounding_mode':
				return self::enum( $value, array( 'nearest', 'up' ), $default );

			case 'proration_method':
				return self::enum( $value, array( 'fob', 'qty', 'none' ), $default );

			case 'destinations':
				$list = array();
				foreach ( self::to_list( $value ) as $item ) {
					if ( is_array( $item ) ) {
						$item = isset( $item['label'] ) ? $item['label'] : ( isset( $item['name'] ) ? $item['name'] : '' );
					}
					$item = self::text( $item, 100 );
					if ( '' !== $item ) {
						$list[] = $item;
					}
				}
				return array_values( array_unique( $list ) );

			case 'expense_types':
				$rows = self::keyed_rows( $value, false );
				$keys = wp_list_pluck( $rows, 'key' );
				if ( ! in_array( 'otro', $keys, true ) ) {
					$rows[] = array(
						'key'   => 'otro',
						'label' => 'Otro',
					);
				}
				return $rows;

			case 'price_lists':
				return self::keyed_rows( $value, true );

			case 'code_prefix':
				$prefix = self::text( $value, 20 );
				return '' === $prefix ? $default : $prefix;

			case 'code_format':
				$format = self::text( $value, 60 );
				if ( '' === $format ) {
					return $default;
				}
				if ( false === strpos( $format, '{seq}' ) ) {
					$format .= '-{seq}';
				}
				return $format;

			case 'code_seq_digits':
				return max( 1, min( 10, self::int( $value, $default ) ) );

			case 'stock_on_receive':
				return self::enum( $value, array( 'increase', 'none' ), $default );

			case 'enable_stock_management':
			case 'update_cost_on_receive':
			case 'update_price_on_receive':
			case 'allow_unreceive':
			case 'delete_data_on_uninstall':
				return MJERP_Helpers::bool( $value );

			case 'cost_meta_key':
				$meta = is_scalar( $value ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value ) : '';
				$meta = substr( (string) $meta, 0, 190 );
				if ( '' === $meta || in_array( strtolower( $meta ), self::reserved_meta_keys(), true ) ) {
					// Writing the landed cost into a WooCommerce/WordPress core field would corrupt
					// prices, stock or the catalogue, so those keys are never accepted.
					return $default;
				}
				return $meta;

			case 'price_list_for_price':
				return is_scalar( $value ) ? sanitize_key( (string) $value ) : $default;

			case 'price_target':
				return self::enum( $value, array( 'regular_price', 'sale_price' ), $default );

			case 'price_rounding':
				return max( 0, self::int( $value, $default ) );

			case 'allowed_roles':
				return self::roles( $value );

			case 'new_product_status':
				return self::enum( $value, array( 'draft', 'publish' ), $default );

			/* --- Consignaciones ------------------------------------------------------------ */

			case 'consignee_role':
				// Not validated against the existing roles: the plugin creates it when missing.
				$role = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
				return '' !== $role ? $role : $default;

			case 'consignee_role_label':
			case 'portal_title':
				$label = self::text( $value, 60 );
				return '' === $label ? $default : $label;

			case 'consignment_code_prefix':
				$prefix = self::text( $value, 20 );
				return '' === $prefix ? $default : $prefix;

			case 'consignment_code_format':
				$format = self::text( $value, 60 );
				if ( '' === $format ) {
					return $default;
				}
				if ( false === strpos( $format, '{seq}' ) ) {
					$format .= '-{seq}';
				}
				return $format;

			case 'consignment_code_seq_digits':
				return max( 1, min( 10, self::int( $value, $default ) ) );

			case 'consignment_price_list':
				return is_scalar( $value ) ? sanitize_key( (string) $value ) : $default;

			case 'consignment_stock_on_sale':
				return self::enum( $value, array( 'decrease', 'none' ), $default );

			case 'consignment_approval':
				// always: todo pasa por aprobación · portal: solo lo que carga el revendedor · never: nada.
				return self::enum( $value, array( 'always', 'portal', 'never' ), $default );

			case 'consignment_allocation':
				return self::enum( $value, array( 'fifo', 'manual' ), $default );

			case 'consignment_payment_methods':
				$rows = self::keyed_rows( $value, false );
				$keys = wp_list_pluck( $rows, 'key' );
				if ( ! in_array( 'otro', $keys, true ) ) {
					$rows[] = array(
						'key'   => 'otro',
						'label' => 'Otro',
					);
				}
				return $rows;

			case 'consignment_allow_oversell':
			case 'portal_enabled':
			case 'portal_allow_sales':
			case 'portal_allow_returns':
				return MJERP_Helpers::bool( $value );

			case 'portal_slug':
				$slug = is_scalar( $value ) ? sanitize_title( (string) $value ) : '';
				$slug = trim( $slug, '/-' );
				if ( '' === $slug || in_array( $slug, self::$reserved_slugs, true ) ) {
					$slug = $default;
				}
				return $slug;
		}

		return $default;
	}

	/**
	 * Cross-key consistency on a full settings array.
	 *
	 * @param array $s        Settings (full).
	 * @param array $defaults Defaults.
	 * @return array
	 */
	private static function ensure_consistency( array $s, array $defaults ) {
		// Currencies must contain both base and local currency.
		if ( ! is_array( $s['currencies'] ) || empty( $s['currencies'] ) ) {
			$s['currencies'] = $defaults['currencies'];
		}
		foreach ( array( 'local_currency', 'base_currency' ) as $ck ) {
			if ( ! in_array( $s[ $ck ], $s['currencies'], true ) ) {
				array_unshift( $s['currencies'], $s[ $ck ] );
			}
		}
		$s['currencies'] = array_values( array_unique( $s['currencies'] ) );

		// Every currency has a symbol (fallback: the code itself).
		if ( ! is_array( $s['currency_symbols'] ) ) {
			$s['currency_symbols'] = array();
		}
		foreach ( $s['currencies'] as $code ) {
			if ( ! isset( $s['currency_symbols'][ $code ] ) || '' === (string) $s['currency_symbols'][ $code ] ) {
				$s['currency_symbols'][ $code ] = isset( $defaults['currency_symbols'][ $code ] ) ? $defaults['currency_symbols'][ $code ] : $code;
			}
		}

		// Expense type "otro" always exists (repos fall back to it).
		if ( ! is_array( $s['expense_types'] ) ) {
			$s['expense_types'] = $defaults['expense_types'];
		}
		$type_keys = wp_list_pluck( $s['expense_types'], 'key' );
		if ( ! in_array( 'otro', $type_keys, true ) ) {
			$s['expense_types'][] = array(
				'key'   => 'otro',
				'label' => 'Otro',
			);
		}

		// price_list_for_price must reference an existing list.
		if ( ! is_array( $s['price_lists'] ) ) {
			$s['price_lists'] = array();
		}
		$list_keys = wp_list_pluck( $s['price_lists'], 'key' );
		if ( ! in_array( $s['price_list_for_price'], $list_keys, true ) ) {
			$s['price_list_for_price'] = ! empty( $list_keys ) ? (string) reset( $list_keys ) : '';
		}

		// Administrator is always allowed.
		if ( ! is_array( $s['allowed_roles'] ) ) {
			$s['allowed_roles'] = array();
		}
		if ( ! in_array( 'administrator', $s['allowed_roles'], true ) ) {
			array_unshift( $s['allowed_roles'], 'administrator' );
		}
		$s['allowed_roles'] = array_values( array_unique( $s['allowed_roles'] ) );

		if ( '' === (string) $s['route_slug'] ) {
			$s['route_slug'] = 'mjerp';
		}

		// The consignment price list may reference a list that was later deleted.
		if ( '' !== (string) $s['consignment_price_list'] && ! in_array( $s['consignment_price_list'], $list_keys, true ) ) {
			$s['consignment_price_list'] = (string) $s['price_list_for_price'];
		}

		// Payment method "otro" always exists (the repos fall back to it).
		if ( ! is_array( $s['consignment_payment_methods'] ) ) {
			$s['consignment_payment_methods'] = $defaults['consignment_payment_methods'];
		}
		if ( ! in_array( 'otro', wp_list_pluck( $s['consignment_payment_methods'], 'key' ), true ) ) {
			$s['consignment_payment_methods'][] = array(
				'key'   => 'otro',
				'label' => 'Otro',
			);
		}

		// Two apps cannot answer on the same URL.
		if ( (string) $s['portal_slug'] === (string) $s['route_slug'] ) {
			$s['portal_slug'] = $defaults['portal_slug'] === (string) $s['route_slug']
				? $s['route_slug'] . '-reventa'
				: $defaults['portal_slug'];
		}
		if ( '' === (string) $s['portal_slug'] ) {
			$s['portal_slug'] = $defaults['portal_slug'];
		}

		if ( '' === (string) $s['consignee_role'] ) {
			$s['consignee_role'] = $defaults['consignee_role'];
		}

		return $s;
	}

	/**
	 * Coerces stored values to the type of their default (bool/int/float/array/string).
	 *
	 * @param array $settings Settings.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	private static function coerce_types( array $settings, array $defaults ) {
		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $default;
				continue;
			}
			$value = $settings[ $key ];
			if ( is_bool( $default ) ) {
				$settings[ $key ] = MJERP_Helpers::bool( $value );
			} elseif ( is_int( $default ) ) {
				$settings[ $key ] = MJERP_Helpers::to_int( $value, $default );
			} elseif ( is_float( $default ) ) {
				$settings[ $key ] = MJERP_Helpers::to_float( $value, $default );
			} elseif ( is_array( $default ) ) {
				$settings[ $key ] = is_array( $value ) ? $value : $default;
			} else {
				$settings[ $key ] = is_scalar( $value ) ? (string) $value : $default;
			}
		}
		// Rates are numeric but their defaults are ints; keep them as floats.
		foreach ( array( 'default_rate', 'sale_rate' ) as $rk ) {
			$rate = MJERP_Helpers::to_float( $settings[ $rk ], 0 );
			$settings[ $rk ] = $rate > 0 ? $rate : (float) $defaults[ $rk ];
		}
		return $settings;
	}

	/**
	 * Enum sanitizer.
	 *
	 * @param mixed  $value   Raw value.
	 * @param array  $allowed Allowed values.
	 * @param string $default Default.
	 * @return string
	 */
	private static function enum( $value, array $allowed, $default ) {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Int sanitizer with default.
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $default Default.
	 * @return int
	 */
	private static function int( $value, $default ) {
		if ( is_bool( $value ) || null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return (int) $default;
		}
		return MJERP_Helpers::to_int( $value, $default );
	}

	/**
	 * Text sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Max length.
	 * @return string
	 */
	private static function text( $value, $max = 0 ) {
		return MJERP_Helpers::text( $value, $max );
	}

	/**
	 * Currency code sanitizer ('' when invalid).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function currency_code( $value ) {
		$code = is_scalar( $value ) ? strtoupper( trim( (string) $value ) ) : '';
		return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '';
	}

	/**
	 * Normalizes a list input: arrays are kept; strings are split by newline/comma/semicolon.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	private static function to_list( $value ) {
		if ( is_array( $value ) ) {
			return array_values( $value );
		}
		if ( is_scalar( $value ) ) {
			$parts = preg_split( '/[\r\n,;]+/', (string) $value );
			return is_array( $parts ) ? $parts : array();
		}
		return array();
	}

	/**
	 * Sanitizes rows of `{key,label[,markup]}`.
	 *
	 * @param mixed $value       Raw value.
	 * @param bool  $with_markup Whether rows carry a markup percentage.
	 * @return array
	 */
	private static function keyed_rows( $value, $with_markup ) {
		$rows = array();
		$seen = array();
		if ( ! is_array( $value ) ) {
			return $rows;
		}
		foreach ( $value as $k => $row ) {
			if ( is_scalar( $row ) ) {
				// "key" => "label" or plain label list.
				$row = is_string( $k ) ? array(
					'key'   => $k,
					'label' => $row,
				) : array( 'label' => $row );
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = self::text( isset( $row['label'] ) ? $row['label'] : ( isset( $row['name'] ) ? $row['name'] : '' ), 100 );
			$key   = isset( $row['key'] ) && is_scalar( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( '' === $key && '' !== $label ) {
				$key = sanitize_key( str_replace( '-', '_', sanitize_title( remove_accents( $label ) ) ) );
			}
			if ( '' === $label && '' !== $key ) {
				$label = ucfirst( str_replace( '_', ' ', $key ) );
			}
			if ( '' === $key || '' === $label || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean        = array(
				'key'   => $key,
				'label' => $label,
			);
			if ( $with_markup ) {
				$markup = isset( $row['markup'] ) ? MJERP_Helpers::to_float( $row['markup'], 0 ) : 0.0;
				$clean['markup'] = round( max( -100, $markup ), 4 );
			}
			$rows[] = $clean;
		}
		return $rows;
	}

	/**
	 * Meta keys the landed cost must never be written to: WooCommerce and WordPress own them, and
	 * overwriting one would corrupt prices, stock or the catalogue.
	 *
	 * @return string[] Lowercase keys.
	 */
	public static function reserved_meta_keys() {
		$keys = array(
			'_price',
			'_regular_price',
			'_sale_price',
			'_sale_price_dates_from',
			'_sale_price_dates_to',
			'_stock',
			'_stock_status',
			'_manage_stock',
			'_backorders',
			'_low_stock_amount',
			'_sku',
			'_weight',
			'_length',
			'_width',
			'_height',
			'_tax_status',
			'_tax_class',
			'_virtual',
			'_downloadable',
			'_visibility',
			'_featured',
			'_product_attributes',
			'_default_attributes',
			'_children',
			'_variation_description',
			'_thumbnail_id',
			'_product_image_gallery',
			'_wp_page_template',
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'total_sales',
		);

		/**
		 * Filters the meta keys that cannot be used to store the landed cost.
		 *
		 * @param string[] $keys Reserved keys (lowercase).
		 */
		$keys = apply_filters( 'mjerp_reserved_meta_keys', $keys );

		return array_map( 'strtolower', array_map( 'strval', (array) $keys ) );
	}

	/**
	 * Sanitizes a roles list (array of keys, or map role => bool). Only existing roles are kept; administrator always included.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function roles( $value ) {
		$input = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				if ( is_array( $v ) ) {
					if ( isset( $v['key'] ) && ( ! isset( $v['enabled'] ) || MJERP_Helpers::bool( $v['enabled'] ) ) ) {
						$input[] = $v['key'];
					}
				} elseif ( is_string( $k ) && ! is_numeric( $k ) ) {
					if ( MJERP_Helpers::bool( $v ) ) {
						$input[] = $k;
					}
				} elseif ( is_scalar( $v ) ) {
					$input[] = $v;
				}
			}
		} elseif ( is_scalar( $value ) ) {
			$input = self::to_list( $value );
		}

		$existing = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->roles ) : array();
		$clean    = array( 'administrator' );
		foreach ( $input as $role ) {
			$role = sanitize_key( (string) $role );
			if ( '' === $role ) {
				continue;
			}
			if ( ! empty( $existing ) && ! in_array( $role, $existing, true ) ) {
				continue;
			}
			$clean[] = $role;
		}
		return array_values( array_unique( $clean ) );
	}
}
