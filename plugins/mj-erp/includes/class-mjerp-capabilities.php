<?php
/**
 * Capability helpers.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Capabilities
 */
class MJERP_Capabilities {

	/**
	 * Capability required to use the ERP.
	 */
	const CAP = 'manage_mjerp';

	/**
	 * Capability required to use the consignee portal.
	 *
	 * Deliberately separate from CAP: a reseller must never reach the ERP, and someone who runs the
	 * ERP does not automatically get a portal account (they have no consignee record).
	 */
	const CAP_PORTAL = 'mjerp_consignee';

	/**
	 * Whether the current user may use the ERP.
	 *
	 * @return bool
	 */
	public static function current_user_can() {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return false;
		}
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Whether a given user may use the ERP.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_can( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}
		return user_can( $user_id, self::CAP ) || user_can( $user_id, 'manage_options' );
	}

	/**
	 * Whether a user may correct sales that already exist and read their history.
	 *
	 * Cambiar lo que ya se vendió toca plata que ya existe, así que no alcanza con poder usar el ERP:
	 * hace falta tener uno de los roles de Configuración → Ventas → Corregir ventas. El administrador
	 * puede siempre.
	 *
	 * @param int $user_id User id (0 = current user).
	 * @return bool
	 */
	public static function can_manage_sales( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$allowed = false;

		if ( $user_id && self::user_can( $user_id ) ) {
			if ( user_can( $user_id, 'manage_options' ) ) {
				$allowed = true;
			} else {
				$user  = get_userdata( $user_id );
				$roles = class_exists( 'MJERP_Settings' ) ? (array) MJERP_Settings::get( 'sales_admin_roles', array( 'administrator' ) ) : array( 'administrator' );
				$allowed = $user && array_intersect( (array) $user->roles, array_map( 'strval', $roles ) );
			}
		}

		/**
		 * Filters whether a user may correct sales and read their history.
		 *
		 * @param bool $allowed Whether the user may.
		 * @param int  $user_id User id.
		 */
		return (bool) apply_filters( 'mjerp_can_manage_sales', (bool) $allowed, (int) $user_id );
	}

	/**
	 * Roles that must receive the capability (settings + administrator).
	 *
	 * @param array|null $roles Optional explicit list of role keys.
	 * @return string[]
	 */
	public static function allowed_roles( $roles = null ) {
		if ( null === $roles ) {
			$roles = class_exists( 'MJERP_Settings' ) ? MJERP_Settings::get( 'allowed_roles', array( 'administrator' ) ) : array( 'administrator' );
		}
		if ( ! is_array( $roles ) ) {
			$roles = array();
		}
		$clean = array();
		foreach ( $roles as $role ) {
			$role = sanitize_key( (string) $role );
			if ( '' !== $role ) {
				$clean[] = $role;
			}
		}
		if ( ! in_array( 'administrator', $clean, true ) ) {
			array_unshift( $clean, 'administrator' );
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Whether a role currently has the capability.
	 *
	 * @param string      $role_key Role key.
	 * @param string|null $cap      Capability; null = CAP.
	 * @return bool
	 */
	public static function role_has_cap( $role_key, $cap = null ) {
		$role = get_role( sanitize_key( (string) $role_key ) );
		$cap  = ( null === $cap ) ? self::CAP : (string) $cap;
		return $role instanceof WP_Role && $role->has_cap( $cap );
	}

	/**
	 * Role key configured for consignees (resellers).
	 *
	 * @return string
	 */
	public static function consignee_role() {
		$role = class_exists( 'MJERP_Settings' ) ? MJERP_Settings::get( 'consignee_role', 'reventa' ) : 'reventa';
		$role = sanitize_key( (string) $role );
		return '' === $role ? 'reventa' : $role;
	}

	/**
	 * Whether the current user may use the consignee portal.
	 *
	 * An administrator is *not* let in by fiat: the portal only makes sense for a user that has a
	 * consignee record, and that check belongs to MJERP_Consignees.
	 *
	 * @return bool
	 */
	public static function current_user_can_portal() {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return false;
		}
		return current_user_can( self::CAP_PORTAL );
	}

	/**
	 * Creates the consignee role when missing and makes sure it holds CAP_PORTAL.
	 *
	 * The role is created with `read` only: a reseller must be able to log in and nothing else.
	 * Never removes the role, so demoting it in the settings does not lock existing users out of
	 * their accounts.
	 *
	 * @param string|null $role_key Role key; null = settings.
	 * @return string The role key that was ensured.
	 */
	public static function ensure_consignee_role( $role_key = null ) {
		$role_key = ( null === $role_key ) ? self::consignee_role() : sanitize_key( (string) $role_key );
		if ( '' === $role_key ) {
			$role_key = 'reventa';
		}

		$role = get_role( $role_key );
		if ( ! $role instanceof WP_Role ) {
			$label = class_exists( 'MJERP_Settings' ) ? (string) MJERP_Settings::get( 'consignee_role_label', 'Reventa' ) : 'Reventa';
			$label = '' !== trim( $label ) ? trim( $label ) : 'Reventa';
			add_role( $role_key, $label, array( 'read' => true ) );
			$role = get_role( $role_key );
		}

		if ( $role instanceof WP_Role && ! $role->has_cap( self::CAP_PORTAL ) ) {
			$role->add_cap( self::CAP_PORTAL );
		}

		return $role_key;
	}
}
