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
	 * Known cashbox ids, or null when not resolved yet (request-level cache).
	 *
	 * @var int[]|null
	 */
	private static $cashbox_ids = null;

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
			// Acceso rápido a la tienda desde la barra superior del ERP (el camino de vuelta).
			'shop_link'                 => true,
			'shop_link_label'           => '',
			'shop_link_url'             => '',
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
			/*
			 * Una venta avisada por el revendedor vale desde el momento en que la carga: la
			 * mercadería ya salió y la deuda ya existe. Lo que espera confirmación es el PAGO, que
			 * es lo único que el dueño puede verificar de verdad (que la plata entró). Se puede
			 * cambiar, pero éste es el circuito real de una consignación.
			 */
			'consignment_approval'      => 'never',
			'consignment_allow_oversell' => false,
			'consignment_allocation'    => 'fifo',
			/*
			 * `cashbox_id` = la caja donde entra esa plata (0 = la caja por defecto). Se elige en
			 * Configuración; acá arranca en 0 para que una tienda que nunca tocó el tema siga
			 * funcionando exactamente igual que antes.
			 */
			'consignment_payment_methods' => array(
				array(
					'key'        => 'efectivo',
					'label'      => 'Efectivo',
					'cashbox_id' => 0,
				),
				array(
					'key'        => 'transferencia',
					'label'      => 'Transferencia',
					'cashbox_id' => 0,
				),
				array(
					'key'        => 'dolares',
					'label'      => 'Dólares',
					'cashbox_id' => 0,
				),
				array(
					'key'        => 'otro',
					'label'      => 'Otro',
					'cashbox_id' => 0,
				),
			),
			'portal_enabled'            => true,
			'portal_slug'               => 'reventa',
			'portal_title'              => 'Mi cuenta',
			'portal_allow_sales'        => true,
			'portal_allow_returns'      => false,
			/*
			 * Minutos que tiene el revendedor para deshacer una venta que cargó él mismo. Como la
			 * venta ya no espera confirmación, sin esto un botón mal apretado le queda como deuda
			 * hasta que el dueño la corrija a mano. 0 = no puede deshacer nada.
			 */
			'portal_undo_minutes'       => 1440,

			/* --- Ventas -------------------------------------------------------------------- */
			'sales_enabled'              => true,
			'sales_code_prefix'          => 'VTA',
			'sales_code_format'          => '{prefix}-{year}-{seq}',
			'sales_code_seq_digits'      => 4,
			'sales_channels'             => array(
				array(
					'key'   => 'mostrador',
					'label' => 'Mostrador',
				),
				array(
					'key'   => 'whatsapp',
					'label' => 'WhatsApp',
				),
				array(
					'key'   => 'feria',
					'label' => 'Feria',
				),
				array(
					'key'   => 'tienda',
					'label' => 'Tienda online',
				),
				array(
					'key'   => 'otro',
					'label' => 'Otro',
				),
			),
			'sales_default_channel'      => 'mostrador',

			/*
			 * Entrega: qué hay que hacer para que lo vendido llegue a manos del cliente.
			 *
			 * Cada método dice si la venta ya queda entregada al elegirlo (`delivered`): un
			 * mostrador se entrega en el acto, un envío queda pendiente hasta que se despacha.
			 * Esa marca es lo que evita que el mostrador ensucie la lista de entregas pendientes.
			 */
			'sales_delivery_enabled'     => true,
			'sales_delivery_methods'     => array(
				array(
					'key'       => 'mostrador',
					'label'     => 'Entregado en el momento',
					'delivered' => 1,
				),
				array(
					'key'       => 'punto_encuentro',
					'label'     => 'Punto de encuentro',
					'delivered' => 0,
				),
				array(
					'key'       => 'envio',
					'label'     => 'Envío',
					'delivered' => 0,
				),
				array(
					'key'       => 'retira_local',
					'label'     => 'Retira en el local',
					'delivered' => 0,
				),
			),
			'sales_default_delivery_method' => 'mostrador',
			'sales_shop_delivery_method'    => 'envio',
			'sales_shop_channel'         => 'tienda',
			'sales_payment_methods'      => array(
				array(
					'key'        => 'efectivo',
					'label'      => 'Efectivo',
					'cashbox_id' => 0,
				),
				array(
					'key'        => 'transferencia',
					'label'      => 'Transferencia',
					'cashbox_id' => 0,
				),
				array(
					'key'        => 'tarjeta',
					'label'      => 'Tarjeta',
					'cashbox_id' => 0,
				),
				array(
					'key'        => 'dolares',
					'label'      => 'Dólares',
					'cashbox_id' => 0,
				),
				array(
					'key'        => 'cuenta_corriente',
					'label'      => 'Cuenta corriente',
					'cashbox_id' => 0,
				),
				array(
					'key'        => 'otro',
					'label'      => 'Otro',
					'cashbox_id' => 0,
				),
			),
			'sales_default_payment_method' => 'efectivo',
			'sales_default_cashbox'      => 0,
			'sales_price_list'           => 'publico',
			// Catálogo de la pantalla de venta (mostrador): cuántos productos por página y si van con foto.
			'sales_pos_per_page'         => 24,
			'sales_pos_images'           => true,
			// Debajo de este ancho de pantalla la venta se carga en pasos (celular).
			'sales_pos_steps_width'      => 900,

			/*
			 * Redondeo del precio que el ERP sugiere al cargar una venta.
			 *
			 * `tienda` es el default y el único que cierra: usa la misma escalera con la que MJ
			 * Dólar publica el precio en la vidriera, así lo que ve el mostrador es lo que paga
			 * quien compra online. Los otros modos existen para quien quiera otra cosa: `erp` usa el
			 * paso de `price_rounding`, `personalizado` el de `sales_price_rounding`, y `ninguno`
			 * deja los centavos crudos.
			 */
			'sales_price_rounding_mode'  => 'tienda',
			'sales_price_rounding'       => 0,
			'sales_track_cost'           => true,
			'sales_allow_discount'       => true,
			'sales_allow_oversell'       => false,

			/* Emisión de la orden de WooCommerce (la que dispara la notificación de la app). */
			'sales_create_order'         => true,
			/*
			 * Cuándo una venta se vuelve firme: cuándo se emite el pedido en WooCommerce y cuándo
			 * baja el stock. Anotar una venta no reserva nada; lo que compromete la mercadería es
			 * que el cliente la pague o que se la lleve.
			 *
			 * registrar | pago_o_entrega | pago | entrega | manual
			 */
			'sales_commit_when'          => 'pago_o_entrega',
			'sales_order_status_paid'    => 'completed',
			'sales_order_status_unpaid'  => 'on-hold',
			'sales_order_created_via'    => 'mj-erp',
			'sales_order_note'           => 'Venta registrada desde MJ ERP',
			'sales_reduce_stock'         => 'auto',

			/* Espejo de lo que se vende en la tienda. */
			'sales_capture_shop_orders'  => true,
			'sales_capture_manual_orders' => true,
			'sales_capture_payment'      => true,
			'sales_capture_on'           => 'creada',
			'sales_capture_statuses'     => array( 'processing', 'completed' ),
			'sales_void_statuses'        => array( 'cancelled', 'refunded', 'failed' ),

			/* Reglas de consignación aplicadas a lo que compra un revendedor en la tienda. */
			'sales_consignment_enabled'  => true,
			'sales_consignment_allocation' => 'fifo',
			'sales_consignment_approval' => 'auto',
			'sales_consignment_partial_lines' => true,
			'sales_consignment_payment_method' => 'cuenta_corriente',

			/* --- Clientes ------------------------------------------------------------------ */

			/*
			 * La libreta de clientes del ERP. Un cliente de acá no es una cuenta de WooCommerce: es
			 * el registro de a quién se le vendió. Se crea desde el mostrador con un nombre y nada
			 * más, y no genera usuarios ni manda mails.
			 */
			'customers_enabled'          => true,
			'customers_doc_types'        => array(
				array(
					'key'   => 'dni',
					'label' => 'DNI',
				),
				array(
					'key'   => 'cuit',
					'label' => 'CUIT',
				),
				array(
					'key'   => 'cuil',
					'label' => 'CUIL',
				),
				array(
					'key'   => 'pasaporte',
					'label' => 'Pasaporte',
				),
				array(
					'key'   => 'otro',
					'label' => 'Otro',
				),
			),
			'customers_default_doc_type' => 'dni',

			/*
			 * Qué pasa cuando alguien que ya compró en el mostrador se crea una cuenta en la tienda.
			 *
			 * `sugerir` (default) avisa y deja que vos decidas: dos personas pueden compartir un
			 * teléfono, y fusionar historiales sin mirar es lo único difícil de deshacer acá.
			 * `automatico` las une solo; `nunca` no busca coincidencias.
			 */
			'customers_link_mode'        => 'sugerir',
			'customers_link_match'       => array( 'email' ),
			'customers_link_backfill'    => true,

			/* Crear un cliente sin salir de la pantalla de venta. */
			'customers_quick_create'     => true,
			'customers_require_on_sale'  => false,

			/* Campos que la ficha pide además del nombre. */
			'customers_fields'           => array( 'phone', 'email', 'doc', 'address', 'notes' ),

			/* Precio que ve un revendedor logueado en la tienda. */
			'shop_price_mode'            => 'publico',
			'shop_price_list'            => 'mayorista',
			'shop_price_notice'          => true,

			/* Permisos del portal, por defecto y por rol (por usuario viven en su ficha). */
			'portal_permissions'         => array(
				'defaults' => array(
					'report_sale'            => true,
					'register_payment'       => false,
					'partial_payments'       => true,
					'payment_needs_approval' => true,
					'payment_methods'        => array(),
					'payment_receipt'        => 'heredar',
					'returns'                => false,
					'see_account'            => true,
					'shop_consignment'       => true,
					'price_mode'             => 'heredar',
					'price_list_key'         => '',
				),
				'roles'    => array(),
			),

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
	 * Sale status catalogue.
	 *
	 * @return array
	 */
	public static function sale_statuses() {
		$statuses = array(
			'borrador'   => array(
				'label' => __( 'Borrador', 'mj-erp' ),
				'color' => 'neutral',
			),
			'confirmada' => array(
				'label' => __( 'Confirmada', 'mj-erp' ),
				'color' => 'success',
			),
			'anulada'    => array(
				'label' => __( 'Anulada', 'mj-erp' ),
				'color' => 'danger',
			),
		);

		/**
		 * Filters the sale status catalogue (labels/colors only; keys are fixed).
		 *
		 * @param array $statuses Statuses.
		 */
		$filtered = apply_filters( 'mjerp_sale_statuses', $statuses );
		return is_array( $filtered ) ? array_merge( $statuses, array_intersect_key( $filtered, $statuses ) ) : $statuses;
	}

	/**
	 * Payment status catalogue of a sale.
	 *
	 * @return array
	 */
	public static function sale_payment_statuses() {
		$statuses = array(
			'impaga'  => array(
				'label' => __( 'Impaga', 'mj-erp' ),
				'color' => 'danger',
			),
			'parcial' => array(
				'label' => __( 'Pago parcial', 'mj-erp' ),
				'color' => 'warning',
			),
			'pagada'  => array(
				'label' => __( 'Pagada', 'mj-erp' ),
				'color' => 'success',
			),
		);

		/**
		 * Filters the sale payment status catalogue.
		 *
		 * @param array $statuses Statuses.
		 */
		$filtered = apply_filters( 'mjerp_sale_payment_statuses', $statuses );
		return is_array( $filtered ) ? array_merge( $statuses, array_intersect_key( $filtered, $statuses ) ) : $statuses;
	}

	/**
	 * Sale channels `[ {key,label}, ... ]`.
	 *
	 * @return array
	 */
	public static function sale_channels() {
		$list = self::get( 'sales_channels', array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Label of a sale channel key.
	 *
	 * @param string $key Channel key.
	 * @return string
	 */
	public static function sale_channel_label( $key ) {
		return self::label_from( self::sale_channels(), $key );
	}

	/**
	 * Sale payment methods `[ {key,label}, ... ]`.
	 *
	 * @return array
	 */
	public static function sale_payment_methods() {
		$list = self::get( 'sales_payment_methods', array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Label of a sale payment method key.
	 *
	 * @param string $key Method key.
	 * @return string
	 */
	public static function sale_payment_method_label( $key ) {
		return self::label_from( self::sale_payment_methods(), $key );
	}

	/**
	 * Whether the delivery step is on.
	 *
	 * @return bool
	 */
	public static function delivery_enabled() {
		return MJERP_Helpers::bool( self::get( 'sales_delivery_enabled', true ) );
	}

	/**
	 * Delivery methods `[ {key,label,delivered}, ... ]`.
	 *
	 * @return array
	 */
	public static function sale_delivery_methods() {
		$list = self::get( 'sales_delivery_methods', array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * One delivery method row, or null.
	 *
	 * @param string $key Method key.
	 * @return array|null
	 */
	public static function sale_delivery_method( $key ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return null;
		}
		return self::row_from( self::sale_delivery_methods(), $key );
	}

	/**
	 * Label of a delivery method key.
	 *
	 * @param string $key Method key.
	 * @return string
	 */
	public static function sale_delivery_method_label( $key ) {
		/*
		 * Sin método no hay etiqueta: label_from() trata la clave vacía como el comodín «Otro», y
		 * acá eso sería mentira — las ventas de antes de este paso no tienen forma de entrega, y
		 * decir «Otro» en toda la lista no informa nada.
		 */
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return '';
		}
		return self::label_from( self::sale_delivery_methods(), $key );
	}

	/**
	 * Whether choosing this method already means the sale left with the customer.
	 *
	 * Es la diferencia entre vender en el mostrador y tener que llevar algo: sin esta marca, cada
	 * venta de mano en mano habría que marcarla entregada a mano, y la lista de pendientes dejaría
	 * de servir para lo único que sirve — ver qué falta entregar.
	 *
	 * @param string $key Method key.
	 * @return bool
	 */
	public static function delivery_method_is_instant( $key ) {
		$row = self::sale_delivery_method( $key );
		return $row ? ! empty( $row['delivered'] ) : false;
	}

	/**
	 * Delivery status catalogue of a sale (labels/colors only; keys are fixed).
	 *
	 * @return array
	 */
	public static function sale_delivery_statuses() {
		$statuses = array(
			'pendiente'  => array(
				'label' => __( 'A entregar', 'mj-erp' ),
				'color' => 'warning',
			),
			'en_camino'  => array(
				'label' => __( 'En camino', 'mj-erp' ),
				'color' => 'info',
			),
			'entregado'  => array(
				'label' => __( 'Entregado', 'mj-erp' ),
				'color' => 'success',
			),
		);

		/**
		 * Filters the delivery status catalogue.
		 *
		 * @param array $statuses Statuses.
		 */
		$filtered = apply_filters( 'mjerp_sale_delivery_statuses', $statuses );
		return is_array( $filtered ) ? array_merge( $statuses, array_intersect_key( $filtered, $statuses ) ) : $statuses;
	}

	/* ------------------------------------------------------------------
	 * Clientes
	 * ---------------------------------------------------------------- */

	/**
	 * Whether the customers book is on.
	 *
	 * @return bool
	 */
	public static function customers_enabled() {
		return MJERP_Helpers::bool( self::get( 'customers_enabled', true ) );
	}

	/**
	 * Document types a customer card accepts `[ {key,label}, ... ]`.
	 *
	 * @return array
	 */
	public static function customer_doc_types() {
		$list = self::get( 'customers_doc_types', array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Label of a document type key.
	 *
	 * @param string $key Doc type key.
	 * @return string
	 */
	public static function customer_doc_type_label( $key ) {
		return self::label_from( self::customer_doc_types(), $key );
	}

	/**
	 * Optional fields the customer card shows.
	 *
	 * @return string[]
	 */
	public static function customer_fields() {
		$list = self::get( 'customers_fields', array() );
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : array();
	}

	/**
	 * Whether an optional customer field is enabled.
	 *
	 * @param string $key Field key (phone, email, doc, company, address, notes, price_list).
	 * @return bool
	 */
	public static function customer_field_enabled( $key ) {
		return in_array( sanitize_key( (string) $key ), self::customer_fields(), true );
	}

	/* ------------------------------------------------------------------
	 * Payment method → cashbox
	 *
	 * Every peso that comes in has to land somewhere: a transfer in the transfer box, physical
	 * dollars in the dollar box. Which box is decided once, on the method itself, and every path
	 * that records a collection (ERP sale, reseller payment, WooCommerce order) reads it from here.
	 * Nothing is hardcoded: the method list and the cashbox list are both configuration.
	 * ---------------------------------------------------------------- */

	/**
	 * Looks a `{key,label,...}` row up in a list.
	 *
	 * @param array  $rows Rows.
	 * @param string $key  Key.
	 * @return array|null
	 */
	private static function row_from( array $rows, $key ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return null;
		}
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['key'] ) && (string) $row['key'] === $key ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * One sale payment method row, or null.
	 *
	 * @param string $key Method key.
	 * @return array|null
	 */
	public static function sale_payment_method( $key ) {
		return self::row_from( self::sale_payment_methods(), $key );
	}

	/**
	 * One consignment payment method row, or null.
	 *
	 * @param string $key Method key.
	 * @return array|null
	 */
	public static function payment_method( $key ) {
		return self::row_from( self::payment_methods(), $key );
	}

	/**
	 * Cashbox the money of a payment method lands in.
	 *
	 * Looks in the catalogue of the scope asked for first and in the other one after, because both
	 * lists share their keys ("transferencia" is the same money whether it came from a customer or
	 * from a reseller): configuring the box once is enough. Falls back to the general default
	 * (`sales_default_cashbox`), and to 0 ("sin caja") when not even that one is set — so the whole
	 * feature is opt-in and nothing changes for a shop that never configures it.
	 *
	 * @param string $key   Method key.
	 * @param string $scope 'sales' | 'consignment'.
	 * @return int Cashbox id (0 = none).
	 */
	public static function cashbox_for_method( $key, $scope = 'sales' ) {
		$rows = ( 'consignment' === $scope )
			? array( self::payment_method( $key ), self::sale_payment_method( $key ) )
			: array( self::sale_payment_method( $key ), self::payment_method( $key ) );

		$cashbox = 0;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['cashbox_id'] ) && self::cashbox_exists( $row['cashbox_id'] ) ) {
				$cashbox = absint( $row['cashbox_id'] );
				break;
			}
		}

		if ( $cashbox <= 0 ) {
			$fallback = absint( self::get( 'sales_default_cashbox', 0 ) );
			$cashbox  = self::cashbox_exists( $fallback ) ? $fallback : 0;
		}

		/**
		 * Filters the cashbox resolved for a payment method.
		 *
		 * @param int    $cashbox Cashbox id (0 = none).
		 * @param string $key     Method key.
		 * @param string $scope   Scope asked for.
		 */
		return absint( apply_filters( 'mjerp_cashbox_for_method', $cashbox, sanitize_key( (string) $key ), (string) $scope ) );
	}

	/**
	 * Whether a cashbox id exists (request-level cache: the settings are read on every request).
	 *
	 * @param int $id Cashbox id.
	 * @return bool
	 */
	public static function cashbox_exists( $id ) {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return false;
		}
		if ( ! class_exists( 'MJERP_Cashboxes_Repo' ) || ! class_exists( 'MJERP_Install' ) ) {
			// Too early to check (or the table is not there yet): keep what was configured.
			return true;
		}
		if ( null === self::$cashbox_ids ) {
			self::$cashbox_ids = array();
			foreach ( (array) MJERP_Cashboxes_Repo::all( true ) as $box ) {
				if ( isset( $box['id'] ) ) {
					self::$cashbox_ids[] = (int) $box['id'];
				}
			}
		}
		return in_array( $id, self::$cashbox_ids, true );
	}

	/**
	 * Clears the cashbox id cache (after creating or deleting one).
	 *
	 * @return void
	 */
	public static function flush_cashboxes() {
		self::$cashbox_ids = null;
		self::flush_cache();
	}


	/**
	 * Label lookup over a `[ {key,label} ]` list, humanising unknown keys.
	 *
	 * @param array  $rows Rows.
	 * @param string $key  Key.
	 * @return string
	 */
	private static function label_from( array $rows, $key ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			$key = 'otro';
		}
		foreach ( $rows as $row ) {
			if ( isset( $row['key'] ) && (string) $row['key'] === $key ) {
				return isset( $row['label'] ) && '' !== (string) $row['label'] ? (string) $row['label'] : ucfirst( $key );
			}
		}
		return 'otro' === $key ? __( 'Otro', 'mj-erp' ) : ucfirst( str_replace( array( '_', '-' ), ' ', $key ) );
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

			case 'shop_link':
				return MJERP_Helpers::bool( $value );

			case 'shop_link_label':
				return self::text( $value, 60 );

			case 'shop_link_url':
				$url = is_scalar( $value ) ? esc_url_raw( trim( (string) $value ) ) : '';
				return $url;

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
				$rows = self::keyed_rows( $value, false, true );
				$keys = wp_list_pluck( $rows, 'key' );
				if ( ! in_array( 'otro', $keys, true ) ) {
					$rows[] = array(
						'key'        => 'otro',
						'label'      => 'Otro',
						'cashbox_id' => 0,
					);
				}
				return $rows;

			case 'portal_undo_minutes':
				// Hasta una semana: más que eso ya no es "me equivoqué", es rehacer la historia.
				return max( 0, min( 10080, self::int( $value, $default ) ) );

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

			/* --- Ventas -------------------------------------------------------------------- */

			case 'sales_enabled':
			case 'sales_track_cost':
			case 'sales_allow_discount':
			case 'sales_allow_oversell':
			case 'sales_create_order':
			case 'sales_capture_shop_orders':
			case 'sales_capture_manual_orders':
			case 'sales_capture_payment':
			case 'sales_consignment_enabled':
			case 'sales_consignment_partial_lines':
			case 'sales_pos_images':
			case 'sales_delivery_enabled':
			case 'shop_price_notice':
				return MJERP_Helpers::bool( $value );

			case 'sales_delivery_methods':
				// El cuarto argumento trae la marca "ya queda entregada" de cada método.
				return self::keyed_rows( $value, false, false, 'delivered' );

			case 'sales_pos_per_page':
				return max( 6, min( 96, self::int( $value, $default ) ) );

			case 'sales_pos_steps_width':
				// 0 = nunca; el tope evita dejar el paso a paso activo en un monitor.
				return max( 0, min( 1600, self::int( $value, $default ) ) );

			case 'sales_code_prefix':
				$prefix = self::text( $value, 20 );
				return '' === $prefix ? $default : $prefix;

			case 'sales_code_format':
				$format = self::text( $value, 60 );
				if ( '' === $format ) {
					return $default;
				}
				if ( false === strpos( $format, '{seq}' ) ) {
					$format .= '-{seq}';
				}
				return $format;

			case 'sales_code_seq_digits':
				return max( 1, min( 10, self::int( $value, $default ) ) );

			case 'sales_channels':
				$rows = self::keyed_rows( $value, false );
				if ( ! in_array( 'otro', wp_list_pluck( $rows, 'key' ), true ) ) {
					$rows[] = array(
						'key'   => 'otro',
						'label' => 'Otro',
					);
				}
				return $rows;

			case 'sales_payment_methods':
				$rows = self::keyed_rows( $value, false, true );
				if ( ! in_array( 'otro', wp_list_pluck( $rows, 'key' ), true ) ) {
					$rows[] = array(
						'key'        => 'otro',
						'label'      => 'Otro',
						'cashbox_id' => 0,
					);
				}
				return $rows;

			case 'sales_default_channel':
			case 'sales_shop_channel':
			case 'sales_default_delivery_method':
			case 'sales_shop_delivery_method':
			case 'sales_default_payment_method':
			case 'sales_consignment_payment_method':
			case 'sales_price_list':
			case 'shop_price_list':
				return is_scalar( $value ) ? sanitize_key( (string) $value ) : $default;

			case 'sales_default_cashbox':
				return max( 0, self::int( $value, 0 ) );

			case 'sales_price_rounding_mode':
				$mode = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
				return in_array( $mode, array( 'tienda', 'erp', 'personalizado', 'ninguno' ), true ) ? $mode : $default;

			case 'sales_price_rounding':
				return max( 0, self::int( $value, $default ) );

			/* --- Clientes ------------------------------------------------------------------ */

			case 'customers_enabled':
			case 'customers_link_backfill':
			case 'customers_quick_create':
			case 'customers_require_on_sale':
				return MJERP_Helpers::bool( $value );

			case 'customers_doc_types':
				return self::keyed_rows( $value, false );

			case 'customers_default_doc_type':
				return is_scalar( $value ) ? sanitize_key( (string) $value ) : $default;

			case 'customers_link_mode':
				$mode = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
				return in_array( $mode, array( 'sugerir', 'automatico', 'nunca' ), true ) ? $mode : $default;

			case 'customers_link_match':
				$fields = array();
				foreach ( self::to_list( $value ) as $item ) {
					if ( is_array( $item ) ) {
						$item = isset( $item['key'] ) ? $item['key'] : '';
					}
					$item = sanitize_key( (string) $item );
					if ( in_array( $item, array( 'email', 'phone', 'doc_number' ), true ) && ! in_array( $item, $fields, true ) ) {
						$fields[] = $item;
					}
				}
				return $fields;

			case 'customers_fields':
				$fields = array();
				foreach ( self::to_list( $value ) as $item ) {
					if ( is_array( $item ) ) {
						$item = isset( $item['key'] ) ? $item['key'] : '';
					}
					$item = sanitize_key( (string) $item );
					if ( in_array( $item, array( 'phone', 'email', 'doc', 'company', 'address', 'notes', 'price_list' ), true ) && ! in_array( $item, $fields, true ) ) {
						$fields[] = $item;
					}
				}
				return $fields;

			case 'sales_order_status_paid':
			case 'sales_order_status_unpaid':
				$status = self::order_status( $value );
				return '' === $status ? $default : $status;

			case 'sales_capture_statuses':
			case 'sales_void_statuses':
				$list = array();
				foreach ( self::to_list( $value ) as $item ) {
					if ( is_array( $item ) ) {
						$item = isset( $item['key'] ) ? $item['key'] : '';
					}
					$status = self::order_status( $item );
					if ( '' !== $status ) {
						$list[] = $status;
					}
				}
				return array_values( array_unique( $list ) );

			case 'sales_order_created_via':
				$via = is_scalar( $value ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value ) : '';
				$via = substr( (string) $via, 0, 60 );
				return '' === $via ? $default : $via;

			case 'sales_order_note':
				return self::text( $value, 190 );

			case 'sales_commit_when':
				return self::enum( $value, array( 'registrar', 'pago_o_entrega', 'pago', 'entrega', 'manual' ), $default );

			case 'sales_reduce_stock':
				return self::enum( $value, array( 'auto', 'siempre', 'nunca' ), $default );

			case 'sales_capture_on':
				return self::enum( $value, array( 'creada', 'estado' ), $default );

			case 'sales_consignment_allocation':
				return self::enum( $value, array( 'fifo', 'lifo' ), $default );

			case 'sales_consignment_approval':
				return self::enum( $value, array( 'auto', 'pendiente' ), $default );

			case 'shop_price_mode':
				return self::enum( $value, array( 'publico', 'lista' ), $default );

			case 'portal_permissions':
				return self::permission_tree( $value, is_array( $default ) ? $default : array() );
		}

		return $default;
	}

	/**
	 * Sanitizes a WooCommerce order status key ('' when unknown).
	 *
	 * Accepts both `wc-processing` and `processing`; stores the short form. When WooCommerce is not
	 * loaded the key is only shape-checked, so a status configured before WooCommerce booted is not
	 * silently dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function order_status( $value ) {
		$key = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		if ( '' === $key ) {
			return '';
		}
		if ( 0 === strpos( $key, 'wc-' ) ) {
			$key = substr( $key, 3 );
		}
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return $key;
		}
		$allowed = array();
		foreach ( array_keys( (array) wc_get_order_statuses() ) as $status ) {
			$allowed[] = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		}
		return in_array( $key, $allowed, true ) ? $key : '';
	}

	/**
	 * Sanitizes the `{ defaults: {...}, roles: { role: {...} } }` permission tree.
	 *
	 * Role branches keep only the keys they actually override, so a permission that is later
	 * changed in the defaults still reaches every role that never overrode it.
	 *
	 * @param mixed $value    Raw value.
	 * @param array $defaults Default tree.
	 * @return array
	 */
	private static function permission_tree( $value, array $defaults ) {
		$base = isset( $defaults['defaults'] ) && is_array( $defaults['defaults'] ) ? $defaults['defaults'] : array();
		$out  = array(
			'defaults' => $base,
			'roles'    => array(),
		);

		if ( ! is_array( $value ) ) {
			return $out;
		}

		if ( isset( $value['defaults'] ) && is_array( $value['defaults'] ) ) {
			$out['defaults'] = array_merge( $base, self::permission_set( $value['defaults'], array_keys( $base ) ) );
		}

		if ( isset( $value['roles'] ) && is_array( $value['roles'] ) ) {
			foreach ( $value['roles'] as $role_key => $set ) {
				$role_key = sanitize_key( (string) $role_key );
				if ( '' === $role_key || ! is_array( $set ) ) {
					continue;
				}
				$clean = self::permission_set( $set, array_keys( $base ) );
				if ( ! empty( $clean ) ) {
					$out['roles'][ $role_key ] = $clean;
				}
			}
		}

		return $out;
	}

	/**
	 * Sanitizes one permission set. Unknown keys are dropped; missing keys stay missing (they mean
	 * "inherit"), which is what makes the defaults → role → user chain work.
	 *
	 * @param array $set     Raw set.
	 * @param array $allowed Allowed keys.
	 * @return array
	 */
	public static function permission_set( $set, array $allowed = array() ) {
		if ( ! is_array( $set ) ) {
			return array();
		}
		if ( empty( $allowed ) ) {
			$defaults = self::defaults();
			$allowed  = array_keys( $defaults['portal_permissions']['defaults'] );
		}

		$out = array();
		foreach ( $set as $key => $raw ) {
			$key = sanitize_key( (string) $key );
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			switch ( $key ) {
				case 'payment_methods':
					$methods = array();
					foreach ( self::to_list( $raw ) as $method ) {
						if ( is_array( $method ) ) {
							$method = isset( $method['key'] ) ? $method['key'] : '';
						}
						$method = sanitize_key( (string) $method );
						if ( '' !== $method ) {
							$methods[] = $method;
						}
					}
					$out[ $key ] = array_values( array_unique( $methods ) );
					break;

				case 'price_mode':
					$out[ $key ] = self::enum( $raw, array( 'heredar', 'publico', 'lista' ), 'heredar' );
					break;

				case 'payment_receipt':
					$out[ $key ] = self::enum( $raw, array( 'heredar', 'no', 'opcional', 'obligatorio' ), 'heredar' );
					break;

				case 'price_list_key':
					$out[ $key ] = is_scalar( $raw ) ? sanitize_key( (string) $raw ) : '';
					break;

				default:
					$out[ $key ] = MJERP_Helpers::bool( $raw );
					break;
			}
		}
		return $out;
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

		/* --- Ventas -------------------------------------------------------------------- */

		// Channels and payment methods always keep an "otro" entry: the repos fall back to it.
		foreach ( array( 'sales_channels', 'sales_payment_methods' ) as $list_key ) {
			if ( ! is_array( $s[ $list_key ] ) || empty( $s[ $list_key ] ) ) {
				$s[ $list_key ] = $defaults[ $list_key ];
			}
			if ( ! in_array( 'otro', wp_list_pluck( $s[ $list_key ], 'key' ), true ) ) {
				$s[ $list_key ][] = array(
					'key'   => 'otro',
					'label' => 'Otro',
				);
			}
		}

		$channel_keys = wp_list_pluck( $s['sales_channels'], 'key' );
		foreach ( array( 'sales_default_channel', 'sales_shop_channel' ) as $ck ) {
			if ( ! in_array( (string) $s[ $ck ], $channel_keys, true ) ) {
				$s[ $ck ] = in_array( $defaults[ $ck ], $channel_keys, true ) ? $defaults[ $ck ] : (string) reset( $channel_keys );
			}
		}

		/*
		 * Entrega: sin métodos no hay paso que dar, así que la lista vacía vuelve a los de fábrica,
		 * y un método por defecto que ya no existe se reemplaza en vez de quedar colgado.
		 */
		if ( ! is_array( $s['sales_delivery_methods'] ) || empty( $s['sales_delivery_methods'] ) ) {
			$s['sales_delivery_methods'] = $defaults['sales_delivery_methods'];
		}
		$delivery_keys = wp_list_pluck( $s['sales_delivery_methods'], 'key' );
		foreach ( array( 'sales_default_delivery_method', 'sales_shop_delivery_method' ) as $dk ) {
			if ( ! in_array( (string) $s[ $dk ], $delivery_keys, true ) ) {
				$s[ $dk ] = in_array( $defaults[ $dk ], $delivery_keys, true ) ? $defaults[ $dk ] : (string) reset( $delivery_keys );
			}
		}

		$method_keys = wp_list_pluck( $s['sales_payment_methods'], 'key' );
		foreach ( array( 'sales_default_payment_method', 'sales_consignment_payment_method' ) as $mk ) {
			if ( ! in_array( (string) $s[ $mk ], $method_keys, true ) ) {
				$s[ $mk ] = in_array( $defaults[ $mk ], $method_keys, true ) ? $defaults[ $mk ] : (string) reset( $method_keys );
			}
		}

		// Price lists used by the sales module may reference a list that was later deleted.
		foreach ( array( 'sales_price_list', 'shop_price_list' ) as $pk ) {
			if ( '' !== (string) $s[ $pk ] && ! in_array( (string) $s[ $pk ], $list_keys, true ) ) {
				$s[ $pk ] = (string) $s['price_list_for_price'];
			}
		}

		// An empty capture list would silently stop mirroring the shop, which reads as a bug.
		if ( ! is_array( $s['sales_capture_statuses'] ) || empty( $s['sales_capture_statuses'] ) ) {
			$s['sales_capture_statuses'] = $defaults['sales_capture_statuses'];
		}
		if ( ! is_array( $s['sales_void_statuses'] ) ) {
			$s['sales_void_statuses'] = $defaults['sales_void_statuses'];
		}
		// A status that both confirms and voids a sale would flip-flop it: capture wins.
		$s['sales_void_statuses'] = array_values( array_diff( $s['sales_void_statuses'], $s['sales_capture_statuses'] ) );

		// The permission tree always carries its two branches with every default key present.
		if ( ! is_array( $s['portal_permissions'] ) ) {
			$s['portal_permissions'] = $defaults['portal_permissions'];
		}
		$s['portal_permissions']['defaults'] = array_merge(
			$defaults['portal_permissions']['defaults'],
			isset( $s['portal_permissions']['defaults'] ) && is_array( $s['portal_permissions']['defaults'] ) ? $s['portal_permissions']['defaults'] : array()
		);
		if ( ! isset( $s['portal_permissions']['roles'] ) || ! is_array( $s['portal_permissions']['roles'] ) ) {
			$s['portal_permissions']['roles'] = array();
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
	 * Sanitizes rows of `{key,label[,markup][,cashbox_id]}`.
	 *
	 * @param mixed $value        Raw value.
	 * @param bool  $with_markup  Whether rows carry a markup percentage.
	 * @param bool  $with_cashbox Whether rows carry the cashbox the money lands in.
	 * @return array
	 */
	private static function keyed_rows( $value, $with_markup, $with_cashbox = false, $bool_field = '' ) {
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
			if ( $with_cashbox ) {
				// 0 = "la caja por defecto". A cashbox that no longer exists is dropped here
				// instead of being kept as a dangling id nobody can see in the form.
				$cashbox = isset( $row['cashbox_id'] ) ? absint( $row['cashbox_id'] ) : 0;
				$clean['cashbox_id'] = self::cashbox_exists( $cashbox ) ? $cashbox : 0;
			}
			if ( '' !== $bool_field ) {
				$clean[ $bool_field ] = ! empty( $row[ $bool_field ] ) && MJERP_Helpers::bool( $row[ $bool_field ] ) ? 1 : 0;
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
