<?php
defined( 'ABSPATH' ) || exit;

/**
 * Las cuentas de la tienda, vistas desde el ERP.
 *
 * Esta clase mira usuarios de WordPress/WooCommerce. La libreta de clientes del ERP —la que se
 * carga en el mostrador y no necesita cuenta— vive en MJERP_Customers_Repo. Acá está el puente
 * entre las dos: cuando alguien se registra en la tienda, el ERP se fija si esa persona ya venía
 * comprando en el mostrador.
 */
class MJERP_Customers {

	/**
	 * User meta holding the counter card the ERP thinks this new account belongs to.
	 *
	 * Se guarda en el alta en vez de recalcularlo después porque buscar coincidencias recorriendo
	 * usuarios sería caro y, sobre todo, tardío: el mail y el teléfono con los que se registró son
	 * los de ese momento, y la persona los puede cambiar al día siguiente.
	 */
	const META_SUGGESTION = '_mjerp_customer_suggestion';

	/**
	 * Hooks the shop-account side of the customers book.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 20, 1 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'on_user_register' ), 20, 1 );
		add_action( 'delete_user', array( __CLASS__, 'on_user_deleted' ), 10, 1 );
	}

	/**
	 * Somebody opened an account in the shop: ¿ya lo conocíamos del mostrador?
	 *
	 * Con `customers_link_mode` en `automatico` se vincula solo. En `sugerir` —el default— no se
	 * toca nada y se deja anotado el candidato, porque unir dos historiales es de las pocas cosas
	 * acá que conviene mirar antes de hacer: dos personas de la misma casa comparten teléfono.
	 *
	 * @param int $user_id New user id.
	 * @return void
	 */
	public static function on_user_register( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! class_exists( 'MJERP_Customers_Repo' ) || ! MJERP_Settings::customers_enabled() ) {
			return;
		}

		$mode = MJERP_Customers_Repo::link_mode();
		if ( 'nunca' === $mode ) {
			return;
		}

		// Si ya tiene ficha (por ejemplo, la creó el propio ERP), no hay nada que proponer.
		if ( MJERP_Customers_Repo::find_by_user( $user_id, false ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$candidates = MJERP_Customers_Repo::find_candidates( MJERP_Customers_Repo::fields_from_user( $user ), 1 );
		if ( empty( $candidates ) ) {
			return;
		}

		if ( 'automatico' === $mode ) {
			MJERP_Customers_Repo::link( (int) $candidates[0]['id'], $user_id );
			return;
		}

		update_user_meta( $user_id, self::META_SUGGESTION, (int) $candidates[0]['id'] );
	}

	/**
	 * Un usuario borrado deja su ficha en pie, pero sin cuenta: el historial de ventas no se toca.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function on_user_deleted( $user_id ) {
		if ( ! class_exists( 'MJERP_Customers_Repo' ) ) {
			return;
		}
		$card = MJERP_Customers_Repo::find_by_user( $user_id, false );
		if ( $card ) {
			MJERP_Customers_Repo::unlink( (int) $card['id'] );
		}
	}

	/**
	 * Accounts that registered recently and look like a customer we already had.
	 *
	 * @param int $limit Max rows.
	 * @return array [ { user, customer } ]
	 */
	public static function pending_suggestions( int $limit = 20 ) : array {
		if ( ! class_exists( 'MJERP_Customers_Repo' ) || 'sugerir' !== MJERP_Customers_Repo::link_mode() ) {
			return array();
		}

		$query = new WP_User_Query(
			array(
				'meta_key'    => self::META_SUGGESTION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'number'      => max( 1, min( 100, $limit ) ),
				'orderby'     => 'registered',
				'order'       => 'DESC',
				'fields'      => 'all',
				'count_total' => false,
			)
		);

		$out = array();
		foreach ( (array) $query->get_results() as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$customer_id = absint( get_user_meta( $user->ID, self::META_SUGGESTION, true ) );
			$customer    = $customer_id ? MJERP_Customers_Repo::find( $customer_id ) : null;

			// La sugerencia caducó: la ficha se borró, ya se vinculó a otra cuenta, o esta cuenta ya
			// tiene la suya. Se limpia acá para que la lista no muestre trabajo que no existe.
			if ( ! $customer || $customer['user_id'] || MJERP_Customers_Repo::find_by_user( (int) $user->ID, false ) ) {
				delete_user_meta( $user->ID, self::META_SUGGESTION );
				continue;
			}

			$out[] = array(
				'user'     => self::summary( $user ),
				'customer' => $customer,
			);
		}

		return $out;
	}

	/**
	 * Drops the pending suggestion of an account.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function dismiss_suggestion( $user_id ) {
		delete_user_meta( absint( $user_id ), self::META_SUGGESTION );
	}

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
