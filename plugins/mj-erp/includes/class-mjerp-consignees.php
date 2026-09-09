<?php
defined( 'ABSPATH' ) || exit;

/**
 * Consignees (resellers): the ERP record and its WordPress/WooCommerce account.
 *
 * A consignee is always backed by a WordPress user, so the same person is a WooCommerce customer,
 * can log into the portal, and shows up in the customer pickers. The ERP row adds what WordPress
 * does not know: which price list applies, the credit limit, notes and whether they are still
 * active. `user_id` is unique, so one account is one consignee.
 *
 * Deleting is a soft delete whenever the consignee already has movements: their history is the
 * backing of a debt and must never disappear silently.
 *
 * @package MJ_ERP
 */
class MJERP_Consignees {

	const FORMATS = array(
		'user_id'           => '%d',
		'name'              => '%s',
		'email'             => '%s',
		'phone'             => '%s',
		'price_list_key'    => '%s',
		'credit_limit_base' => '%f',
		'notes'             => '%s',
		'is_active'         => '%d',
		'created_by'        => '%d',
		'created_at'        => '%s',
		'updated_at'        => '%s',
	);

	/**
	 * Request-level cache of the consignee resolved for the current user.
	 *
	 * @var array|null|false false = not resolved yet.
	 */
	private static $current = false;

	/**
	 * Hooks. Keeps the reseller role in sync when the setting changes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'mjerp_settings_updated', array( __CLASS__, 'on_settings_updated' ), 10, 2 );
	}

	/**
	 * Re-creates the reseller role when its key or label changed in the settings.
	 *
	 * @param array $new     New settings.
	 * @param array $current Previous settings.
	 * @return void
	 */
	public static function on_settings_updated( $new, $current ) {
		$new_role = isset( $new['consignee_role'] ) ? (string) $new['consignee_role'] : '';
		$old_role = isset( $current['consignee_role'] ) ? (string) $current['consignee_role'] : '';
		$new_label = isset( $new['consignee_role_label'] ) ? (string) $new['consignee_role_label'] : '';
		$old_label = isset( $current['consignee_role_label'] ) ? (string) $current['consignee_role_label'] : '';

		if ( $new_role !== $old_role || $new_label !== $old_label ) {
			MJERP_Capabilities::ensure_consignee_role( $new_role );
		}
	}

	private static function table() : string {
		return MJERP_Install::table( 'consignees' );
	}

	/* ------------------------------------------------------------------
	 * Read
	 * ---------------------------------------------------------------- */

	/**
	 * All consignees ordered by name.
	 *
	 * @param bool $include_inactive Include deactivated consignees.
	 * @return array
	 */
	public static function all( bool $include_inactive = false ) : array {
		global $wpdb;
		$table = self::table();
		$sql   = "SELECT * FROM {$table}";
		if ( ! $include_inactive ) {
			$sql .= ' WHERE is_active = 1';
		}
		$sql .= ' ORDER BY name ASC, id ASC';
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row );
		}
		return $out;
	}

	/**
	 * Find one consignee by id.
	 *
	 * @param int $id Consignee id.
	 * @return array|null
	 */
	public static function find( $id ) : ?array {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Find one consignee by WordPress user id.
	 *
	 * @param int $user_id User id.
	 * @return array|null
	 */
	public static function find_by_user( $user_id ) : ?array {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * The active consignee of the logged-in user, or null.
	 *
	 * This is the only way the portal ever identifies a consignee: no request may name one.
	 *
	 * @return array|null
	 */
	public static function current() : ?array {
		if ( false !== self::$current ) {
			return self::$current;
		}

		self::$current = null;
		$user_id       = get_current_user_id();
		if ( $user_id > 0 ) {
			$row = self::find_by_user( $user_id );
			if ( $row && $row['is_active'] ) {
				self::$current = $row;
			}
		}

		return self::$current;
	}

	/**
	 * Clears the per-request cache (after an update to the current user's row).
	 *
	 * @return void
	 */
	public static function flush_current() : void {
		self::$current = false;
	}

	/**
	 * Search consignees by name, email or phone.
	 *
	 * @param string $term             Term.
	 * @param bool   $include_inactive Include deactivated.
	 * @param int    $limit            Max rows.
	 * @return array
	 */
	public static function search( $term, bool $include_inactive = false, int $limit = 50 ) : array {
		global $wpdb;
		$term = trim( sanitize_text_field( (string) $term ) );
		if ( '' === $term ) {
			return self::all( $include_inactive );
		}
		$table = self::table();
		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$limit = max( 1, min( 200, $limit ) );
		$sql   = "SELECT * FROM {$table} WHERE (name LIKE %s OR email LIKE %s OR phone LIKE %s)";
		if ( ! $include_inactive ) {
			$sql .= ' AND is_active = 1';
		}
		$sql .= ' ORDER BY name ASC, id ASC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $like, $like, $like, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row );
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Write
	 * ---------------------------------------------------------------- */

	/**
	 * Creates a consignee.
	 *
	 * Accepts either `user_id` (link an existing WordPress account) or `email` + `name` (create a
	 * new account with the reseller role). Creating accounts requires `create_users`, which keeps a
	 * shop manager from minting logins unless WordPress lets them.
	 *
	 * @param array $data user_id | (name, email, password, send_invite), phone, price_list_key,
	 *                    credit_limit_base, notes, is_active.
	 * @return int|WP_Error New consignee id.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( ! $user instanceof WP_User ) {
				return new WP_Error( 'mjerp_user_not_found', __( 'La cuenta de usuario elegida no existe.', 'mj-erp' ), array( 'status' => 404, 'field' => 'user_id' ) );
			}
			$existing = self::find_by_user( $user_id );
			if ( $existing ) {
				return new WP_Error(
					'mjerp_consignee_exists',
					__( 'Esa cuenta ya está registrada como revendedor.', 'mj-erp' ),
					array( 'status' => 409, 'field' => 'user_id', 'consignee_id' => $existing['id'] )
				);
			}
			// An existing account keeps its roles; the reseller role is added, never swapped in.
			self::grant_role( $user_id );
		} else {
			$created = self::create_user( $data );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$user_id = $created;
			$user    = get_userdata( $user_id );
		}

		$row = self::prepare( $data, null, $user );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$row['user_id'] = $user_id;

		$defaults = array(
			'email'             => $user instanceof WP_User ? (string) $user->user_email : '',
			'phone'             => '',
			'price_list_key'    => self::default_price_list(),
			'credit_limit_base' => 0,
			'notes'             => '',
			'is_active'         => 1,
		);
		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $row ) ) {
				$row[ $key ] = $value;
			}
		}

		$now               = current_time( 'mysql' );
		$row['created_by'] = get_current_user_id();
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$result = $wpdb->insert( self::table(), $row, self::formats( $row ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo guardar el revendedor.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;
		self::flush_current();

		/**
		 * Fires after a consignee was created.
		 *
		 * @param int   $id      Consignee id.
		 * @param int   $user_id WordPress user id.
		 */
		do_action( 'mjerp_consignee_created', $id, $user_id );

		return $id;
	}

	/**
	 * Updates a consignee. The linked user account is never re-pointed: that would move a debt
	 * from one person to another.
	 *
	 * @param int   $id   Consignee id.
	 * @param array $data Fields to change.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'El revendedor no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$user = get_userdata( $existing['user_id'] );

		$row = self::prepare( $data, $existing, $user instanceof WP_User ? $user : null );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( empty( $row ) ) {
			return true;
		}

		$row['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::table(), $row, array( 'id' => $existing['id'] ), self::formats( $row ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar el revendedor.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		self::flush_current();

		/**
		 * Fires after a consignee was updated.
		 *
		 * @param int   $id  Consignee id.
		 * @param array $row Changed columns.
		 */
		do_action( 'mjerp_consignee_updated', (int) $existing['id'], $row );

		return true;
	}

	/**
	 * Deletes a consignee: soft delete when they already have consignments, sales or payments.
	 *
	 * The WordPress account is left alone in every case — removing a login is not this plugin's
	 * business, and the person may still be a customer.
	 *
	 * @param int $id Consignee id.
	 * @return array|WP_Error { deleted: bool, deactivated: bool }
	 */
	public static function delete( $id ) {
		global $wpdb;

		$existing = self::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'El revendedor no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		if ( self::is_referenced( $existing['id'] ) ) {
			$result = $wpdb->update(
				self::table(),
				array(
					'is_active'  => 0,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $existing['id'] ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			if ( false === $result ) {
				return new WP_Error( 'mjerp_db_error', __( 'No se pudo desactivar el revendedor.', 'mj-erp' ), array( 'status' => 500 ) );
			}
			self::flush_current();
			return array(
				'deleted'     => false,
				'deactivated' => true,
			);
		}

		$result = $wpdb->delete( self::table(), array( 'id' => $existing['id'] ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar el revendedor.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		self::flush_current();

		return array(
			'deleted'     => true,
			'deactivated' => false,
		);
	}

	/**
	 * Whether the consignee has any movement (so deleting must be a soft delete).
	 *
	 * @param int $id Consignee id.
	 * @return bool
	 */
	public static function is_referenced( $id ) : bool {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 ) {
			return false;
		}
		foreach ( array( 'consignments', 'consignment_sales', 'consignment_payments' ) as $name ) {
			$table = MJERP_Install::table( $name );
			$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE consignee_id = %d LIMIT 1", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $found > 0 ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------
	 * WordPress account
	 * ---------------------------------------------------------------- */

	/**
	 * Creates the WordPress account of a new consignee.
	 *
	 * @param array $data name, email, password, username, send_invite.
	 * @return int|WP_Error New user id.
	 */
	private static function create_user( array $data ) {
		if ( ! current_user_can( 'create_users' ) ) {
			return new WP_Error(
				'mjerp_cannot_create_users',
				__( 'No tenés permiso para crear cuentas de usuario. Pedí a un administrador que cree la cuenta y después vinculala.', 'mj-erp' ),
				array( 'status' => 403 )
			);
		}

		$name = MJERP_Helpers::text( isset( $data['name'] ) ? $data['name'] : '', 190 );
		if ( '' === $name ) {
			return new WP_Error( 'mjerp_invalid_name', __( 'El nombre del revendedor es obligatorio.', 'mj-erp' ), array( 'status' => 400, 'field' => 'name' ) );
		}

		$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'mjerp_invalid_email', __( 'Hace falta un email válido para crear la cuenta del revendedor.', 'mj-erp' ), array( 'status' => 400, 'field' => 'email' ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error(
				'mjerp_email_exists',
				__( 'Ya existe una cuenta con ese email. Vinculá esa cuenta en vez de crear una nueva.', 'mj-erp' ),
				array( 'status' => 409, 'field' => 'email', 'user_id' => (int) email_exists( $email ) )
			);
		}

		$login = isset( $data['username'] ) ? sanitize_user( (string) $data['username'], true ) : '';
		if ( '' === $login ) {
			$login = self::unique_login( $name, $email );
		} elseif ( username_exists( $login ) ) {
			return new WP_Error( 'mjerp_username_exists', __( 'Ese nombre de usuario ya está ocupado.', 'mj-erp' ), array( 'status' => 409, 'field' => 'username' ) );
		}

		$password  = isset( $data['password'] ) ? (string) $data['password'] : '';
		$generated = '';
		if ( '' === $password ) {
			$password  = wp_generate_password( 16, true, false );
			$generated = $password;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'first_name'   => $name,
				'role'         => MJERP_Capabilities::ensure_consignee_role(),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			$user_id->add_data( array( 'status' => 400 ) );
			return $user_id;
		}

		$user_id = (int) $user_id;

		// WooCommerce billing fields, so the account looks complete in the store too.
		update_user_meta( $user_id, 'billing_first_name', $name );
		if ( ! empty( $data['phone'] ) ) {
			update_user_meta( $user_id, 'billing_phone', MJERP_Helpers::text( $data['phone'], 60 ) );
		}
		update_user_meta( $user_id, 'billing_email', $email );

		if ( ! empty( $data['send_invite'] ) ) {
			// The reseller sets their own password from the email; we never keep the generated one.
			wp_new_user_notification( $user_id, null, 'user' );
			$generated = '';
		}

		if ( '' !== $generated ) {
			/*
			 * Handed back once, in the create response, so the shop owner can pass it on. It is
			 * never stored: the only copy lives in that single HTTP response.
			 */
			self::$last_generated_password = $generated;
		}

		return $user_id;
	}

	/**
	 * Password generated for the last account created in this request ('' when none).
	 *
	 * @var string
	 */
	private static $last_generated_password = '';

	/**
	 * Reads and clears the one-shot generated password.
	 *
	 * @return string
	 */
	public static function take_generated_password() : string {
		$password                      = self::$last_generated_password;
		self::$last_generated_password = '';
		return $password;
	}

	/**
	 * Adds the reseller role to an existing account without touching its other roles.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function grant_role( $user_id ) : void {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}
		$role = MJERP_Capabilities::ensure_consignee_role();
		$user = get_userdata( $user_id );
		if ( $user instanceof WP_User && ! in_array( $role, (array) $user->roles, true ) ) {
			$user->add_role( $role );
		}
	}

	/**
	 * A login that is free, derived from the name (falling back to the email local part).
	 *
	 * @param string $name  Display name.
	 * @param string $email Email.
	 * @return string
	 */
	private static function unique_login( string $name, string $email ) : string {
		$base = sanitize_user( remove_accents( $name ), true );
		$base = strtolower( str_replace( ' ', '.', trim( $base ) ) );
		if ( '' === $base ) {
			$parts = explode( '@', $email );
			$base  = sanitize_user( $parts[0], true );
		}
		if ( '' === $base ) {
			$base = 'reventa';
		}
		$base  = substr( $base, 0, 50 );
		$login = $base;
		$i     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			$i++;
			if ( $i > 500 ) {
				$login = $base . '-' . wp_generate_password( 6, false, false );
				break;
			}
		}
		return $login;
	}

	/**
	 * WordPress accounts that could become consignees: hold the reseller role, or are customers
	 * without a consignee record yet.
	 *
	 * @param string $term  Search term.
	 * @param int    $limit Max rows.
	 * @return array [ { id, name, email, login, roles, is_consignee } ]
	 */
	public static function candidate_users( $term = '', $limit = 20 ) : array {
		$term  = trim( sanitize_text_field( (string) $term ) );
		$limit = max( 1, min( 100, (int) $limit ) );

		if ( ! current_user_can( 'list_users' ) ) {
			return array();
		}

		$args = array(
			'number'      => $limit,
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'fields'      => 'all',
			'count_total' => false,
		);
		if ( '' !== $term ) {
			$args['search']         = '*' . $term . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}

		$query = new WP_User_Query( $args );
		$taken = self::linked_user_ids();

		$out = array();
		foreach ( (array) $query->get_results() as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			$name = trim( (string) $user->display_name );
			if ( '' === $name ) {
				$name = (string) $user->user_login;
			}
			$out[] = array(
				'id'           => (int) $user->ID,
				'name'         => html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ),
				'email'        => (string) $user->user_email,
				'login'        => (string) $user->user_login,
				'roles'        => array_values( array_map( 'strval', (array) $user->roles ) ),
				'is_consignee' => in_array( (int) $user->ID, $taken, true ),
			);
		}

		return $out;
	}

	/**
	 * User ids already linked to a consignee.
	 *
	 * @return int[]
	 */
	public static function linked_user_ids() : array {
		global $wpdb;
		$table = self::table();
		$ids   = $wpdb->get_col( "SELECT user_id FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/* ------------------------------------------------------------------
	 * Pricing
	 * ---------------------------------------------------------------- */

	/**
	 * Price list key applied to a consignee, falling back to the configured default.
	 *
	 * @param array|int|null $consignee Consignee row or id.
	 * @return string
	 */
	public static function price_list_key( $consignee ) : string {
		if ( is_numeric( $consignee ) ) {
			$consignee = self::find( $consignee );
		}
		$key = ( is_array( $consignee ) && ! empty( $consignee['price_list_key'] ) ) ? (string) $consignee['price_list_key'] : '';
		if ( '' !== $key && MJERP_Settings::price_list( $key ) ) {
			return $key;
		}
		return self::default_price_list();
	}

	/**
	 * Default price list for consignees: the `consignment_price_list` setting when it still exists,
	 * otherwise the one used for published prices.
	 *
	 * @return string
	 */
	public static function default_price_list() : string {
		$key = (string) MJERP_Settings::get( 'consignment_price_list', '' );
		if ( '' !== $key && MJERP_Settings::price_list( $key ) ) {
			return $key;
		}
		return (string) MJERP_Settings::get( 'price_list_for_price', '' );
	}

	/**
	 * Suggested unit price (base currency) for a product under a consignee's price list.
	 *
	 * Built from the landed cost the ERP already stores, with the list markup and the USD rounding
	 * of MJ Dólar applied, so what a reseller is charged sits on the same ladder as the shelf price.
	 *
	 * @param int            $product_id   Product or variation id.
	 * @param array|int|null $consignee    Consignee row or id.
	 * @param string|null    $list_key     Force a list key.
	 * @return array { unit_price_base: float, price_list_key: string, cost_base: float|null, has_cost: bool }
	 */
	public static function suggested_price( $product_id, $consignee = null, $list_key = null ) : array {
		$key  = ( null === $list_key || '' === $list_key ) ? self::price_list_key( $consignee ) : sanitize_key( (string) $list_key );
		$list = MJERP_Settings::price_list( $key );

		$out = array(
			'unit_price_base' => 0.0,
			'price_list_key'  => $key,
			'cost_base'       => null,
			'has_cost'        => false,
		);

		$fields    = MJERP_Products::cost_fields( absint( $product_id ) );
		$cost_base = isset( $fields['cost_base'] ) ? $fields['cost_base'] : null;

		if ( null === $cost_base || $cost_base <= 0 ) {
			// No landed cost yet: fall back to the published USD price so the field is never blank.
			$published = isset( $fields['price_usd'] ) ? $fields['price_usd'] : null;
			if ( null !== $published && $published > 0 ) {
				$out['unit_price_base'] = round( (float) $published, 4 );
			}
			return $out;
		}

		$out['cost_base'] = (float) $cost_base;
		$out['has_cost']  = true;

		$markup  = $list ? (float) $list['markup'] : 0.0;
		$usd_cfg = class_exists( 'MJERP_Dolar' ) ? MJERP_Dolar::usd_config() : null;
		$price   = MJERP_Costing::price_for( $cost_base, $markup, MJERP_Dolar::rate(), 0, $usd_cfg );

		$out['unit_price_base'] = (float) $price['usd'];

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	/**
	 * Sanitize + validate the provided fields.
	 *
	 * @param array        $data     Input.
	 * @param array|null   $existing Existing row on update.
	 * @param WP_User|null $user     Linked account (used to default the name).
	 * @return array|WP_Error
	 */
	private static function prepare( array $data, ?array $existing, ?WP_User $user ) {
		$row = array();

		if ( array_key_exists( 'name', $data ) || ! $existing ) {
			$name = MJERP_Helpers::text( isset( $data['name'] ) ? $data['name'] : '', 190 );
			if ( '' === $name && $user instanceof WP_User ) {
				$name = trim( (string) $user->display_name );
				if ( '' === $name ) {
					$name = (string) $user->user_login;
				}
			}
			if ( '' === $name ) {
				return new WP_Error( 'mjerp_invalid_name', __( 'El nombre del revendedor es obligatorio.', 'mj-erp' ), array( 'status' => 400, 'field' => 'name' ) );
			}
			$row['name'] = $name;
		}

		if ( array_key_exists( 'email', $data ) ) {
			$raw   = trim( (string) $data['email'] );
			$email = sanitize_email( $raw );
			if ( '' !== $raw && ( '' === $email || ! is_email( $email ) ) ) {
				return new WP_Error( 'mjerp_invalid_email', __( 'El email del revendedor es inválido.', 'mj-erp' ), array( 'status' => 400, 'field' => 'email' ) );
			}
			$row['email'] = $email;
		}

		if ( array_key_exists( 'phone', $data ) ) {
			$row['phone'] = MJERP_Helpers::text( $data['phone'], 60 );
		}

		if ( array_key_exists( 'price_list_key', $data ) ) {
			$key = sanitize_key( (string) $data['price_list_key'] );
			if ( '' !== $key && ! MJERP_Settings::price_list( $key ) ) {
				return new WP_Error( 'mjerp_invalid_price_list', __( 'La lista de precio elegida no existe.', 'mj-erp' ), array( 'status' => 400, 'field' => 'price_list_key' ) );
			}
			$row['price_list_key'] = $key;
		}

		if ( array_key_exists( 'credit_limit_base', $data ) ) {
			$limit = MJERP_Helpers::to_float( $data['credit_limit_base'] );
			if ( $limit < 0 ) {
				return new WP_Error( 'mjerp_invalid_limit', __( 'El límite de crédito no puede ser negativo.', 'mj-erp' ), array( 'status' => 400, 'field' => 'credit_limit_base' ) );
			}
			$row['credit_limit_base'] = round( $limit, 4 );
		}

		if ( array_key_exists( 'notes', $data ) ) {
			$row['notes'] = sanitize_textarea_field( (string) $data['notes'] );
		}

		if ( array_key_exists( 'is_active', $data ) ) {
			$row['is_active'] = MJERP_Helpers::bool( $data['is_active'] ) ? 1 : 0;
		}

		return $row;
	}

	/**
	 * Row → API shape, with the linked account decorated in.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private static function cast( array $row ) : array {
		$user_id = (int) self::v( $row, 'user_id', 0 );
		$user    = $user_id ? get_userdata( $user_id ) : false;

		$out = array(
			'id'                => (int) $row['id'],
			'user_id'           => $user_id,
			'name'              => (string) self::v( $row, 'name' ),
			'email'             => (string) self::v( $row, 'email' ),
			'phone'             => (string) self::v( $row, 'phone' ),
			'price_list_key'    => (string) self::v( $row, 'price_list_key' ),
			'credit_limit_base' => round( (float) self::v( $row, 'credit_limit_base', 0 ), 4 ),
			'notes'             => (string) self::v( $row, 'notes' ),
			'is_active'         => (bool) (int) self::v( $row, 'is_active', 1 ),
			'created_at'        => self::datetime_or_null( self::v( $row, 'created_at' ) ),
			'updated_at'        => self::datetime_or_null( self::v( $row, 'updated_at' ) ),
			'user_exists'       => $user instanceof WP_User,
			'user_login'        => '',
			'user_email'        => '',
			'avatar'            => null,
			'roles'             => array(),
			'edit_user_link'    => '',
		);

		if ( $user instanceof WP_User ) {
			$out['user_email'] = (string) $user->user_email;
			$out['roles']      = array_values( array_map( 'strval', (array) $user->roles ) );
			$avatar            = get_avatar_url( $user_id, array( 'size' => 48 ) );
			$out['avatar']     = $avatar ? $avatar : null;
			if ( '' === $out['email'] ) {
				$out['email'] = (string) $user->user_email;
			}
			// The login and the user editor are only useful to someone who administers users.
			if ( current_user_can( 'list_users' ) ) {
				$out['user_login'] = (string) $user->user_login;
			}
			if ( current_user_can( 'edit_user', $user_id ) ) {
				$out['edit_user_link'] = admin_url( 'user-edit.php?user_id=' . $user_id );
			}
		}

		$list                 = MJERP_Settings::price_list( self::price_list_key( $out ) );
		$out['price_list']    = $list ? $list : null;
		$out['has_portal']    = $user instanceof WP_User && user_can( $user_id, MJERP_Capabilities::CAP_PORTAL );

		return $out;
	}

	private static function v( array $row, string $key, $default = '' ) {
		return ( isset( $row[ $key ] ) && null !== $row[ $key ] ) ? $row[ $key ] : $default;
	}

	private static function formats( array $row ) : array {
		$formats = array();
		foreach ( array_keys( $row ) as $key ) {
			$formats[] = isset( self::FORMATS[ $key ] ) ? self::FORMATS[ $key ] : '%s';
		}
		return $formats;
	}

	private static function datetime_or_null( $value ) : ?string {
		if ( null === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}
		return $value;
	}
}
