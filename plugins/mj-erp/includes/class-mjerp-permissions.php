<?php
/**
 * Configurable permissions for resellers: what each one may do in the portal and how the shop
 * treats their purchases.
 *
 * Nothing here is decided in code. Every permission is resolved through the same three-step chain:
 *
 *     ajustes → defaults  →  ajustes → roles[rol]  →  ficha del revendedor (overrides_json)
 *
 * A step only overrides the keys it actually carries, so raising a default later still reaches
 * every reseller who never had that key set by hand. That is what makes "todo configurable por rol
 * y por usuario" work without turning every change into a migration.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Permissions
 */
class MJERP_Permissions {

	/**
	 * Per-request cache of resolved permission sets, keyed by user id.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Catalogue of permissions: key => { label, help, type, options }.
	 *
	 * The UI builds the settings screen from this list, so adding a permission here is enough for
	 * it to become configurable — no screen has to be edited.
	 *
	 * @return array
	 */
	public static function keys() {
		$keys = array(
			'report_sale'            => array(
				'label' => __( 'Marcar productos como vendidos', 'mj-erp' ),
				'help'  => __( 'Puede informar desde su cuenta qué vendió de lo que le entregaste.', 'mj-erp' ),
				'type'  => 'bool',
			),
			'register_payment'       => array(
				'label' => __( 'Registrar pagos', 'mj-erp' ),
				'help'  => __( 'Puede cargar los pagos que te hizo, con importe y medio de pago.', 'mj-erp' ),
				'type'  => 'bool',
			),
			'partial_payments'       => array(
				'label' => __( 'Pagos parciales', 'mj-erp' ),
				'help'  => __( 'Puede pagar una parte de lo que debe en lugar del total.', 'mj-erp' ),
				'type'  => 'bool',
			),
			'payment_needs_approval' => array(
				'label' => __( 'Sus pagos quedan a confirmar', 'mj-erp' ),
				'help'  => __( 'El pago no baja el saldo hasta que vos lo confirmás desde el ERP.', 'mj-erp' ),
				'type'  => 'bool',
			),
			'payment_methods'        => array(
				'label' => __( 'Medios de pago habilitados', 'mj-erp' ),
				'help'  => __( 'Vacío = todos los medios de pago configurados.', 'mj-erp' ),
				'type'  => 'methods',
			),
			'returns'                => array(
				'label' => __( 'Informar devoluciones', 'mj-erp' ),
				'help'  => __( 'Puede avisar que devuelve mercadería que no vendió.', 'mj-erp' ),
				'type'  => 'bool',
			),
			'see_account'            => array(
				'label' => __( 'Ver su cuenta corriente', 'mj-erp' ),
				'help'  => __( 'Ve el saldo, los movimientos y lo que debe.', 'mj-erp' ),
				'type'  => 'bool',
			),
			'shop_consignment'       => array(
				'label' => __( 'Sus compras descuentan de su consignación', 'mj-erp' ),
				'help'  => __( 'Cuando compra en la tienda, lo que tiene entregado se le imputa a su cuenta en lugar de salir del depósito.', 'mj-erp' ),
				'type'  => 'bool',
			),
			'price_mode'             => array(
				'label'   => __( 'Precio que ve en la tienda', 'mj-erp' ),
				'help'    => __( 'Heredar usa el ajuste general de la tienda.', 'mj-erp' ),
				'type'    => 'enum',
				'options' => array(
					'heredar' => __( 'Heredar del ajuste general', 'mj-erp' ),
					'publico' => __( 'Precio público', 'mj-erp' ),
					'lista'   => __( 'Su lista de precios', 'mj-erp' ),
				),
			),
			'price_list_key'         => array(
				'label' => __( 'Lista de precios en la tienda', 'mj-erp' ),
				'help'  => __( 'Vacío = la lista general configurada para la tienda.', 'mj-erp' ),
				'type'  => 'price_list',
			),
		);

		/**
		 * Filters the catalogue of configurable reseller permissions.
		 *
		 * @param array $keys Catalogue.
		 */
		$filtered = apply_filters( 'mjerp_permission_keys', $keys );
		return is_array( $filtered ) ? $filtered : $keys;
	}

	/**
	 * The stored permission tree, always with both branches present.
	 *
	 * @return array `{ defaults: array, roles: array }`
	 */
	public static function tree() {
		$tree = MJERP_Settings::get( 'portal_permissions', array() );
		if ( ! is_array( $tree ) ) {
			$tree = array();
		}
		$defaults = MJERP_Settings::defaults();
		$base     = $defaults['portal_permissions']['defaults'];

		return array(
			'defaults' => array_merge( $base, isset( $tree['defaults'] ) && is_array( $tree['defaults'] ) ? $tree['defaults'] : array() ),
			'roles'    => isset( $tree['roles'] ) && is_array( $tree['roles'] ) ? $tree['roles'] : array(),
		);
	}

	/**
	 * Global defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		$tree = self::tree();
		return $tree['defaults'];
	}

	/**
	 * Permissions of a role (defaults + that role's overrides).
	 *
	 * @param string $role_key Role key.
	 * @return array
	 */
	public static function for_role( $role_key ) {
		$tree     = self::tree();
		$role_key = sanitize_key( (string) $role_key );
		$override = isset( $tree['roles'][ $role_key ] ) && is_array( $tree['roles'][ $role_key ] ) ? $tree['roles'][ $role_key ] : array();
		return array_merge( $tree['defaults'], $override );
	}

	/**
	 * Overrides stored on a consignee record.
	 *
	 * @param array|null $consignee Consignee row.
	 * @return array
	 */
	public static function for_consignee( $consignee ) {
		if ( ! is_array( $consignee ) ) {
			return array();
		}
		$overrides = isset( $consignee['overrides'] ) && is_array( $consignee['overrides'] ) ? $consignee['overrides'] : array();
		if ( empty( $overrides ) && ! empty( $consignee['overrides_json'] ) ) {
			$decoded   = json_decode( (string) $consignee['overrides_json'], true );
			$overrides = is_array( $decoded ) ? $decoded : array();
		}
		$set = isset( $overrides['permissions'] ) && is_array( $overrides['permissions'] ) ? $overrides['permissions'] : array();
		return MJERP_Settings::permission_set( $set );
	}

	/**
	 * Fully resolved permissions for a user.
	 *
	 * Roles are applied in the order WordPress lists them on the user, so a user with two roles
	 * ends up with the last one's overrides on top — predictable, and visible in the UI.
	 *
	 * @param int        $user_id   User id (0 = current user).
	 * @param array|null $consignee Consignee row; null = looked up from the user.
	 * @return array
	 */
	public static function resolve( $user_id = 0, $consignee = null ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$cache_key = $user_id . ( is_array( $consignee ) && isset( $consignee['id'] ) ? ':' . (int) $consignee['id'] : '' );
		if ( isset( self::$cache[ $cache_key ] ) ) {
			return self::$cache[ $cache_key ];
		}

		$tree     = self::tree();
		$resolved = $tree['defaults'];

		$user = $user_id ? get_userdata( $user_id ) : null;
		if ( $user instanceof WP_User ) {
			foreach ( (array) $user->roles as $role_key ) {
				$role_key = sanitize_key( (string) $role_key );
				if ( isset( $tree['roles'][ $role_key ] ) && is_array( $tree['roles'][ $role_key ] ) ) {
					$resolved = array_merge( $resolved, $tree['roles'][ $role_key ] );
				}
			}
		}

		if ( null === $consignee && $user_id && class_exists( 'MJERP_Consignees' ) ) {
			$consignee = MJERP_Consignees::find_by_user( $user_id );
		}
		$resolved = array_merge( $resolved, self::for_consignee( $consignee ) );

		/**
		 * Filters the resolved permissions of a user.
		 *
		 * @param array      $resolved  Permissions.
		 * @param int        $user_id   User id.
		 * @param array|null $consignee Consignee row.
		 */
		$resolved = apply_filters( 'mjerp_permissions', $resolved, $user_id, $consignee );

		self::$cache[ $cache_key ] = is_array( $resolved ) ? $resolved : $tree['defaults'];
		return self::$cache[ $cache_key ];
	}

	/**
	 * Whether a user holds a boolean permission.
	 *
	 * @param string     $permission Permission key.
	 * @param int        $user_id    User id (0 = current user).
	 * @param array|null $consignee  Consignee row.
	 * @return bool
	 */
	public static function can( $permission, $user_id = 0, $consignee = null ) {
		$resolved   = self::resolve( $user_id, $consignee );
		$permission = sanitize_key( (string) $permission );
		return isset( $resolved[ $permission ] ) ? MJERP_Helpers::bool( $resolved[ $permission ] ) : false;
	}

	/**
	 * Value of a non-boolean permission.
	 *
	 * @param string     $permission Permission key.
	 * @param int        $user_id    User id.
	 * @param array|null $consignee  Consignee row.
	 * @param mixed      $default    Fallback.
	 * @return mixed
	 */
	public static function value( $permission, $user_id = 0, $consignee = null, $default = null ) {
		$resolved   = self::resolve( $user_id, $consignee );
		$permission = sanitize_key( (string) $permission );
		return array_key_exists( $permission, $resolved ) ? $resolved[ $permission ] : $default;
	}

	/**
	 * Payment methods a reseller may use, as `[ {key,label} ]`.
	 *
	 * An empty permission means "every configured method"; a method that was later deleted from the
	 * settings silently drops out instead of showing an option that would fail on save.
	 *
	 * @param int        $user_id   User id.
	 * @param array|null $consignee Consignee row.
	 * @return array
	 */
	public static function payment_methods( $user_id = 0, $consignee = null ) {
		$all     = MJERP_Settings::payment_methods();
		$allowed = self::value( 'payment_methods', $user_id, $consignee, array() );
		if ( ! is_array( $allowed ) || empty( $allowed ) ) {
			return $all;
		}
		$out = array();
		foreach ( $all as $method ) {
			if ( isset( $method['key'] ) && in_array( (string) $method['key'], $allowed, true ) ) {
				$out[] = $method;
			}
		}
		return empty( $out ) ? $all : $out;
	}

	/**
	 * Whether a reseller may use a given payment method.
	 *
	 * @param string     $method    Method key.
	 * @param int        $user_id   User id.
	 * @param array|null $consignee Consignee row.
	 * @return bool
	 */
	public static function allows_method( $method, $user_id = 0, $consignee = null ) {
		$method = sanitize_key( (string) $method );
		if ( '' === $method ) {
			return false;
		}
		foreach ( self::payment_methods( $user_id, $consignee ) as $row ) {
			if ( isset( $row['key'] ) && (string) $row['key'] === $method ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Price list a reseller sees in the shop, or '' when they see the public price.
	 *
	 * @param int        $user_id   User id.
	 * @param array|null $consignee Consignee row.
	 * @return string Price list key ('' = public price).
	 */
	public static function shop_price_list( $user_id = 0, $consignee = null ) {
		$mode = (string) self::value( 'price_mode', $user_id, $consignee, 'heredar' );
		if ( 'heredar' === $mode || '' === $mode ) {
			$mode = (string) MJERP_Settings::get( 'shop_price_mode', 'publico' );
		}
		if ( 'lista' !== $mode ) {
			return '';
		}

		$list = (string) self::value( 'price_list_key', $user_id, $consignee, '' );
		if ( '' === $list && is_array( $consignee ) && ! empty( $consignee['price_list_key'] ) ) {
			$list = (string) $consignee['price_list_key'];
		}
		if ( '' === $list ) {
			$list = (string) MJERP_Settings::get( 'shop_price_list', '' );
		}
		return null === MJERP_Settings::price_list( $list ) ? '' : $list;
	}

	/**
	 * Clears the per-request cache (after saving settings or a consignee).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = array();
	}
}
