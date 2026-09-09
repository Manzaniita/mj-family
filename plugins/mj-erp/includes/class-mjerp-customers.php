<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce customer search (foundation for future modules).
 */
class MJERP_Customers {

	/**
	 * Search customers by login, email, display name, first/last name or billing data.
	 *
	 * @param string $term  Term.
	 * @param int    $limit Max results.
	 * @return array [ { id, name, email, login, orders_count, total_spent, roles, avatar } ]
	 */
	public static function search( $term, $limit = 20 ) : array {
		$term  = trim( sanitize_text_field( (string) $term ) );
		$limit = max( 1, min( 100, (int) $limit ) );

		if ( '' === $term ) {
			return array();
		}

		$found = array();
		$scope = self::role_scope();

		// 1) Core user columns.
		$by_columns = new WP_User_Query(
			array_merge(
				$scope,
				array(
					'search'         => '*' . $term . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
					'number'         => $limit,
					'orderby'        => 'display_name',
					'order'          => 'ASC',
					'fields'         => 'all',
					'count_total'    => false,
				)
			)
		);
		foreach ( (array) $by_columns->get_results() as $user ) {
			if ( $user instanceof WP_User ) {
				$found[ (int) $user->ID ] = $user;
			}
		}

		// 2) Name / billing meta.
		if ( count( $found ) < $limit ) {
			$meta_keys  = array( 'first_name', 'last_name', 'billing_first_name', 'billing_last_name', 'billing_company', 'billing_email', 'billing_phone' );
			$meta_query = array( 'relation' => 'OR' );
			foreach ( $meta_keys as $meta_key ) {
				$meta_query[] = array(
					'key'     => $meta_key,
					'value'   => $term,
					'compare' => 'LIKE',
				);
			}

			$by_meta = new WP_User_Query(
				array_merge(
					$scope,
					array(
						'meta_query'  => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'number'      => $limit,
						'orderby'     => 'display_name',
						'order'       => 'ASC',
						'fields'      => 'all',
						'count_total' => false,
					)
				)
			);
			foreach ( (array) $by_meta->get_results() as $user ) {
				if ( $user instanceof WP_User && ! isset( $found[ (int) $user->ID ] ) ) {
					$found[ (int) $user->ID ] = $user;
				}
			}
		}

		if ( empty( $found ) ) {
			return array();
		}

		$found = array_slice( $found, 0, $limit, true );

		$items = array();
		foreach ( $found as $user ) {
			$summary = self::summary( $user );
			if ( $summary ) {
				$items[] = $summary;
			}
		}

		// Customers/subscribers first, then alphabetical.
		usort(
			$items,
			function ( $a, $b ) {
				$a_customer = (int) ( ! empty( array_intersect( array( 'customer', 'subscriber' ), $a['roles'] ) ) );
				$b_customer = (int) ( ! empty( array_intersect( array( 'customer', 'subscriber' ), $b['roles'] ) ) );
				if ( $a_customer !== $b_customer ) {
					return $b_customer - $a_customer;
				}
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $items;
	}

	/**
	 * Restricts the search to buyer roles unless the caller may list users anyway.
	 *
	 * Without this, anyone holding `manage_mjerp` could enumerate every WordPress account
	 * (administrators included) through the customer picker.
	 *
	 * @return array WP_User_Query arguments.
	 */
	protected static function role_scope() : array {
		if ( current_user_can( 'list_users' ) ) {
			return array();
		}

		/**
		 * Filters the roles the customer search is limited to for non-administrators.
		 *
		 * @param string[] $roles Role keys.
		 */
		$roles = (array) apply_filters( 'mjerp_customer_roles', array( 'customer', 'subscriber' ) );
		$roles = array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );

		return $roles ? array( 'role__in' => $roles ) : array();
	}

	/**
	 * Single customer summary.
	 *
	 * @param int $id User id.
	 * @return array|null
	 */
	public static function find( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		$user = get_userdata( $id );
		return $user instanceof WP_User ? self::summary( $user ) : null;
	}

	/**
	 * Build the customer summary.
	 *
	 * @param WP_User|int $user User.
	 * @return array|null
	 */
	public static function summary( $user ) {
		if ( ! $user instanceof WP_User ) {
			$user = $user ? get_userdata( absint( $user ) ) : false;
		}
		if ( ! $user instanceof WP_User ) {
			return null;
		}

		$name = trim( (string) $user->display_name );
		if ( '' === $name ) {
			$name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
		}
		if ( '' === $name ) {
			$name = (string) $user->user_login;
		}

		$orders_count = 0;
		$total_spent  = 0.0;

		if ( class_exists( 'WC_Customer' ) ) {
			try {
				$customer     = new WC_Customer( $user->ID );
				$orders_count = (int) $customer->get_order_count();
				$total_spent  = (float) $customer->get_total_spent();

				if ( (string) $user->user_login === $name ) {
					$billing_name = trim( (string) $customer->get_billing_first_name() . ' ' . (string) $customer->get_billing_last_name() );
					if ( '' !== $billing_name ) {
						$name = $billing_name;
					}
				}
			} catch ( Exception $e ) {
				$orders_count = 0;
				$total_spent  = 0.0;
			}
		}

		$avatar = get_avatar_url( $user->ID, array( 'size' => 48 ) );

		$summary = array(
			'id'           => (int) $user->ID,
			'name'         => html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ),
			'email'        => (string) $user->user_email,
			'orders_count' => $orders_count,
			'total_spent'  => round( $total_spent, 2 ),
			'roles'        => array_values( array_map( 'strval', (array) $user->roles ) ),
			'avatar'       => $avatar ? $avatar : null,
		);

		// The account name is only useful to someone who administers users anyway.
		if ( current_user_can( 'list_users' ) ) {
			$summary['login'] = (string) $user->user_login;
		}

		return $summary;
	}
}
