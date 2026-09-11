<?php
/**
 * Customers repository — la libreta de clientes del ERP.
 *
 * Un cliente de acá es el registro de a quién se le vendió, no una cuenta de la tienda. Nace del
 * mostrador: alguien compra, deja un nombre y un teléfono, y eso ya alcanza para que la venta tenga
 * dueño. Nada de esto crea usuarios en WordPress ni manda mails.
 *
 * `user_id` queda en 0 hasta que esa misma persona se hace una cuenta en la tienda y alguien las
 * vincula (o el ERP lo propone, según `customers_link_mode`). Recién ahí el historial del mostrador
 * y el de la tienda pasan a ser el mismo, y las ventas viejas se rellenan con la cuenta si la
 * configuración lo pide.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Customers_Repo
 */
class MJERP_Customers_Repo {

	/**
	 * Column formats for $wpdb.
	 */
	const FORMATS = array(
		'user_id'        => '%d',
		'name'           => '%s',
		'company'        => '%s',
		'doc_type'       => '%s',
		'doc_number'     => '%s',
		'email'          => '%s',
		'phone'          => '%s',
		'address'        => '%s',
		'city'           => '%s',
		'province'       => '%s',
		'postcode'       => '%s',
		'price_list_key' => '%s',
		'channel'        => '%s',
		'notes'          => '%s',
		'is_active'      => '%d',
		'linked_at'      => '%s',
		'linked_by'      => '%d',
		'created_by'     => '%d',
		'created_at'     => '%s',
		'updated_at'     => '%s',
	);

	/**
	 * Campos por los que se puede reconocer a alguien que se acaba de crear una cuenta en la tienda.
	 */
	const MATCH_FIELDS = array( 'email', 'phone', 'doc_number' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() : string {
		return MJERP_Install::table( 'customers' );
	}

	/**
	 * Sales table (used for the per-customer counters).
	 *
	 * @return string
	 */
	private static function sales_table() : string {
		return MJERP_Install::table( 'sales' );
	}

	/* ------------------------------------------------------------------
	 * Lectura
	 * ---------------------------------------------------------------- */

	/**
	 * Paged listing with the filters the customers screen offers.
	 *
	 * @param array $args search, linked ('', 'si', 'no'), is_active, page, per_page, orderby, order,
	 *                    with_stats.
	 * @return array { items, total, page, per_page, pages }
	 */
	public static function query( array $args = array() ) : array {
		global $wpdb;

		$args = array_merge(
			array(
				'search'     => '',
				'linked'     => '',
				'is_active'  => null,
				'page'       => 1,
				'per_page'   => 20,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'with_stats' => true,
			),
			$args
		);

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(name LIKE %s OR company LIKE %s OR email LIKE %s OR phone LIKE %s OR doc_number LIKE %s OR notes LIKE %s)';
			for ( $i = 0; $i < 6; $i++ ) {
				$params[] = $like;
			}
		}

		$linked = sanitize_key( (string) $args['linked'] );
		if ( 'si' === $linked ) {
			$where[] = 'user_id > 0';
		} elseif ( 'no' === $linked ) {
			$where[] = 'user_id = 0';
		}

		if ( null !== $args['is_active'] ) {
			$where[]  = 'is_active = %d';
			$params[] = MJERP_Helpers::bool( $args['is_active'] ) ? 1 : 0;
		}

		$where_sql = implode( ' AND ', $where );

		$allowed_orderby = array( 'name', 'created_at', 'updated_at', 'id', 'email', 'company' );
		$orderby         = in_array( (string) $args['orderby'], $allowed_orderby, true ) ? (string) $args['orderby'] : 'name';
		$order           = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB

		$sql        = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$sql_params = array_merge( $params, array( $per_page, $offset ) );
		$rows       = $wpdb->get_results( $wpdb->prepare( $sql, $sql_params ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::cast( $row, MJERP_Helpers::bool( $args['with_stats'] ) );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
		);
	}

	/**
	 * One customer.
	 *
	 * @param int  $id         Customer id.
	 * @param bool $with_stats Include the sales counters.
	 * @return array|null
	 */
	public static function find( $id, bool $with_stats = true ) : ?array {
		global $wpdb;

		$id = absint( $id );
		if ( $id <= 0 ) {
			return null;
		}

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $row ? self::cast( $row, $with_stats ) : null;
	}

	/**
	 * The customer linked to a WordPress account, if any.
	 *
	 * @param int  $user_id    User id.
	 * @param bool $with_stats Include the sales counters.
	 * @return array|null
	 */
	public static function find_by_user( $user_id, bool $with_stats = false ) : ?array {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return null;
		}

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT 1", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $row ? self::cast( $row, $with_stats ) : null;
	}

	/**
	 * Search for the picker: name, company, email, phone or document.
	 *
	 * @param string $term             Term.
	 * @param int    $limit            Max rows.
	 * @param bool   $include_inactive Include archived customers.
	 * @return array
	 */
	public static function search( $term, int $limit = 20, bool $include_inactive = false ) : array {
		global $wpdb;

		$term  = trim( sanitize_text_field( (string) $term ) );
		$limit = max( 1, min( 100, $limit ) );
		$table = self::table();

		if ( '' === $term ) {
			$sql  = "SELECT * FROM {$table}" . ( $include_inactive ? '' : ' WHERE is_active = 1' ) . ' ORDER BY updated_at DESC, id DESC LIMIT %d';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$sql  = "SELECT * FROM {$table} WHERE (name LIKE %s OR company LIKE %s OR email LIKE %s OR phone LIKE %s OR doc_number LIKE %s)";
			if ( ! $include_inactive ) {
				$sql .= ' AND is_active = 1';
			}
			// Lo que empieza con el término va primero: escribir "mar" tiene que traer "María"
			// antes que "Ana Marcos".
			$sql  .= ' ORDER BY (name LIKE %s) DESC, name ASC, id ASC LIMIT %d';
			$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare( $sql, $like, $like, $like, $like, $like, $wpdb->esc_like( $term ) . '%', $limit ),
				ARRAY_A
			);
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row, false );
		}

		return $out;
	}

	/**
	 * Customers that look like the given data and are still free to link.
	 *
	 * Se usa para proponer la vinculación cuando alguien se crea una cuenta en la tienda: se compara
	 * solo por los campos que la configuración habilita (`customers_link_match`), y nunca contra
	 * fichas que ya tienen cuenta.
	 *
	 * @param array $data  email, phone, doc_number.
	 * @param int   $limit Max rows.
	 * @return array
	 */
	public static function find_candidates( array $data, int $limit = 5 ) : array {
		global $wpdb;

		$fields = self::match_fields();
		if ( empty( $fields ) ) {
			return array();
		}

		$clauses = array();
		$params  = array();

		foreach ( $fields as $field ) {
			$value = self::normalize_match( $field, MJERP_Helpers::array_get( $data, $field, '' ) );
			if ( '' === $value ) {
				continue;
			}
			if ( 'phone' === $field ) {
				// Un teléfono se escribe de mil maneras: se comparan solo los últimos dígitos, que
				// son los que no cambian entre "+54 9 11 5555-1234" y "1155551234".
				$clauses[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE %s";
				$params[]  = '%' . $wpdb->esc_like( $value );
			} else {
				$clauses[] = $field . ' = %s';
				$params[]  = $value;
			}
		}

		if ( empty( $clauses ) ) {
			return array();
		}

		$table    = self::table();
		$sql      = "SELECT * FROM {$table} WHERE user_id = 0 AND is_active = 1 AND (" . implode( ' OR ', $clauses ) . ') ORDER BY updated_at DESC, id DESC LIMIT %d';
		$params[] = max( 1, min( 20, $limit ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::cast( $row, true );
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Escritura
	 * ---------------------------------------------------------------- */

	/**
	 * Creates a customer.
	 *
	 * @param array $data Fields.
	 * @return int|WP_Error New id.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$row = self::prepare( $data, null );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$defaults = array(
			'user_id'        => 0,
			'company'        => '',
			'doc_type'       => '',
			'doc_number'     => '',
			'email'          => '',
			'phone'          => '',
			'address'        => '',
			'city'           => '',
			'province'       => '',
			'postcode'       => '',
			'price_list_key' => '',
			'channel'        => '',
			'notes'          => '',
			'is_active'      => 1,
		);
		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $row ) ) {
				$row[ $key ] = $value;
			}
		}

		$now               = current_time( 'mysql' );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$row['created_by'] = get_current_user_id();

		if ( ! empty( $row['user_id'] ) ) {
			$row['linked_at'] = $now;
			$row['linked_by'] = get_current_user_id();
		}

		$result = $wpdb->insert( self::table(), $row, self::formats( $row ) ); // phpcs:ignore WordPress.DB
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo guardar el cliente.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a customer was created.
		 *
		 * @param int   $id   Customer id.
		 * @param array $data Input.
		 */
		do_action( 'mjerp_customer_created', $id, $data );

		return $id;
	}

	/**
	 * Updates a customer.
	 *
	 * @param int   $id   Customer id.
	 * @param array $data Fields.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$existing = self::find( $id, false );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'El cliente no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$row = self::prepare( $data, $existing );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( empty( $row ) ) {
			return true;
		}

		$row['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::table(), $row, array( 'id' => $existing['id'] ), self::formats( $row ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo actualizar el cliente.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		// La venta guarda el nombre del cliente tal como estaba ese día, pero un nombre mal escrito
		// no es historia: corregirlo tiene que verse también en la lista de ventas.
		self::sync_sales_snapshot( (int) $existing['id'], $row );

		/**
		 * Fires after a customer was updated.
		 *
		 * @param int   $id   Customer id.
		 * @param array $data Input.
		 */
		do_action( 'mjerp_customer_updated', (int) $existing['id'], $data );

		return true;
	}

	/**
	 * Deletes a customer: archiva (is_active = 0) cuando ya tiene ventas, borra cuando no.
	 *
	 * Una ficha con ventas no se puede borrar sin dejar esas ventas sin dueño, así que se archiva:
	 * deja de aparecer en el buscador y el historial sigue completo.
	 *
	 * @param int $id Customer id.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$existing = self::find( $id, false );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'El cliente no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		if ( self::sales_count( (int) $existing['id'] ) > 0 ) {
			$result = $wpdb->update( // phpcs:ignore WordPress.DB
				self::table(),
				array(
					'is_active'  => 0,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $existing['id'] ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$result = $wpdb->delete( self::table(), array( 'id' => $existing['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		}

		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo eliminar el cliente.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Vinculación con la cuenta de la tienda
	 * ---------------------------------------------------------------- */

	/**
	 * Links a customer to a WooCommerce/WordPress account.
	 *
	 * @param int       $id       Customer id.
	 * @param int       $user_id  User id.
	 * @param bool|null $backfill Rewrite past sales with the account (null = lo que diga la config).
	 * @return array|WP_Error The linked customer.
	 */
	public static function link( $id, $user_id, $backfill = null ) {
		global $wpdb;

		$existing = self::find( $id, false );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'El cliente no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$user_id = absint( $user_id );
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'mjerp_invalid_user', __( 'La cuenta de la tienda no existe.', 'mj-erp' ), array( 'status' => 400, 'field' => 'user_id' ) );
		}

		// Una cuenta no puede ser dos clientes: si no, el historial se parte en dos y ninguno de los
		// dos lados muestra lo que la persona compró de verdad.
		$taken = self::find_by_user( $user_id, false );
		if ( $taken && (int) $taken['id'] !== (int) $existing['id'] ) {
			return new WP_Error(
				'mjerp_user_taken',
				sprintf(
					/* translators: %s: customer name. */
					__( 'Esa cuenta ya está vinculada al cliente «%s».', 'mj-erp' ),
					$taken['name']
				),
				array(
					'status'      => 409,
					'field'       => 'user_id',
					'customer_id' => (int) $taken['id'],
				)
			);
		}

		$now = current_time( 'mysql' );
		$row = array(
			'user_id'    => $user_id,
			'linked_at'  => $now,
			'linked_by'  => get_current_user_id(),
			'updated_at' => $now,
		);

		// Los datos de la cuenta completan lo que falte en la ficha, sin pisar lo que ya se cargó a
		// mano: quien atendió el mostrador sabía mejor cómo se llama la persona.
		foreach ( self::fields_from_user( $user ) as $key => $value ) {
			if ( '' === (string) MJERP_Helpers::array_get( $existing, $key, '' ) && '' !== $value ) {
				$row[ $key ] = $value;
			}
		}

		$result = $wpdb->update( self::table(), $row, array( 'id' => $existing['id'] ), self::formats( $row ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo vincular el cliente.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		if ( null === $backfill ) {
			$backfill = MJERP_Helpers::bool( MJERP_Settings::get( 'customers_link_backfill', true ) );
		}
		if ( $backfill ) {
			self::backfill_sales( (int) $existing['id'], $user_id );
		}

		/**
		 * Fires after a customer was linked to a shop account.
		 *
		 * @param int $id      Customer id.
		 * @param int $user_id User id.
		 */
		do_action( 'mjerp_customer_linked', (int) $existing['id'], $user_id );

		return self::find( (int) $existing['id'] );
	}

	/**
	 * Unlinks a customer from its shop account (la ficha y su historial quedan intactos).
	 *
	 * @param int $id Customer id.
	 * @return array|WP_Error
	 */
	public static function unlink( $id ) {
		global $wpdb;

		$existing = self::find( $id, false );
		if ( ! $existing ) {
			return new WP_Error( 'mjerp_not_found', __( 'El cliente no existe.', 'mj-erp' ), array( 'status' => 404 ) );
		}

		$result = $wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'user_id'    => 0,
				'linked_at'  => null,
				'linked_by'  => null,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $existing['id'] ),
			array( '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'mjerp_db_error', __( 'No se pudo desvincular el cliente.', 'mj-erp' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after a customer was unlinked.
		 *
		 * @param int $id      Customer id.
		 * @param int $user_id Account it was linked to.
		 */
		do_action( 'mjerp_customer_unlinked', (int) $existing['id'], (int) $existing['user_id'] );

		return self::find( (int) $existing['id'] );
	}

	/**
	 * Finds — or creates — the customer for a shop account.
	 *
	 * Es lo que corre cuando entra un pedido de la tienda: si esa cuenta ya tiene ficha, se usa; si
	 * no, se busca una del mostrador que coincida (solo con `customers_link_mode` en `automatico`)
	 * y, en última instancia, se crea una nueva para que la venta no quede sin dueño.
	 *
	 * @param int   $user_id User id.
	 * @param array $data    Extra fields (name, email, phone…) coming from the order.
	 * @return array|null
	 */
	public static function ensure_for_user( $user_id, array $data = array() ) : ?array {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return null;
		}

		$existing = self::find_by_user( $user_id, false );
		if ( $existing ) {
			return $existing;
		}

		$filled = static function ( $value ) {
			return '' !== $value && null !== $value;
		};

		$user = get_userdata( $user_id );
		$from = $user instanceof WP_User ? self::fields_from_user( $user ) : array();
		$data = array_filter( array_merge( $from, array_filter( $data, $filled ) ), $filled );

		if ( 'automatico' === self::link_mode() ) {
			$candidates = self::find_candidates( $data, 1 );
			if ( ! empty( $candidates ) ) {
				$linked = self::link( (int) $candidates[0]['id'], $user_id );
				if ( ! is_wp_error( $linked ) ) {
					return $linked;
				}
			}
		}

		$data['user_id'] = $user_id;
		$id              = self::create( $data );

		return is_wp_error( $id ) ? null : self::find( $id, false );
	}

	/**
	 * Rewrites past sales of a customer with the account it was just linked to.
	 *
	 * @param int $id      Customer id.
	 * @param int $user_id User id.
	 * @return int Rows touched.
	 */
	public static function backfill_sales( $id, $user_id ) : int {
		global $wpdb;

		$id      = absint( $id );
		$user_id = absint( $user_id );
		if ( ! $id || ! $user_id ) {
			return 0;
		}

		$sales = self::sales_table();

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$sales} SET customer_user_id = %d WHERE customer_id = %d AND customer_user_id = 0",
				$user_id,
				$id
			)
		);
	}

	/**
	 * Number of sales of a customer.
	 *
	 * @param int $id Customer id.
	 * @return int
	 */
	public static function sales_count( $id ) : int {
		$stats = self::stats( $id );
		return (int) $stats['sales_count'];
	}

	/**
	 * Sales counters of a customer (voided sales do not count).
	 *
	 * @param int $id Customer id.
	 * @return array { sales_count, total, total_base, due, last_sale_date }
	 */
	public static function stats( $id ) : array {
		global $wpdb;

		$out = array(
			'sales_count'    => 0,
			'total'          => 0.0,
			'total_base'     => 0.0,
			'due'            => 0.0,
			'last_sale_date' => null,
		);

		$id = absint( $id );
		if ( ! $id ) {
			return $out;
		}

		$sales = self::sales_table();
		$void  = class_exists( 'MJERP_Sales_Repo' ) ? MJERP_Sales_Repo::STATUS_VOID : 'anulada';

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) AS sales_count,
					COALESCE(SUM(total), 0) AS total,
					COALESCE(SUM(total_base), 0) AS total_base,
					COALESCE(SUM(total - paid_total), 0) AS due,
					MAX(sale_date) AS last_sale_date
				FROM {$sales} WHERE customer_id = %d AND status <> %s",
				$id,
				$void
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return $out;
		}

		return array(
			'sales_count'    => (int) $row['sales_count'],
			'total'          => round( (float) $row['total'], 2 ),
			'total_base'     => round( (float) $row['total_base'], 2 ),
			'due'            => round( max( 0, (float) $row['due'] ), 2 ),
			'last_sale_date' => $row['last_sale_date'] ? (string) $row['last_sale_date'] : null,
		);
	}

	/* ------------------------------------------------------------------
	 * Configuración
	 * ---------------------------------------------------------------- */

	/**
	 * How the ERP reacts when someone with a counter history opens an account.
	 *
	 * @return string `sugerir` | `automatico` | `nunca`.
	 */
	public static function link_mode() : string {
		$mode = sanitize_key( (string) MJERP_Settings::get( 'customers_link_mode', 'sugerir' ) );
		return in_array( $mode, array( 'sugerir', 'automatico', 'nunca' ), true ) ? $mode : 'sugerir';
	}

	/**
	 * Fields used to recognise a returning customer.
	 *
	 * @return string[]
	 */
	public static function match_fields() : array {
		if ( 'nunca' === self::link_mode() ) {
			return array();
		}

		$raw = MJERP_Settings::get( 'customers_link_match', array( 'email' ) );
		$out = array();
		foreach ( (array) $raw as $field ) {
			$field = sanitize_key( (string) $field );
			if ( in_array( $field, self::MATCH_FIELDS, true ) && ! in_array( $field, $out, true ) ) {
				$out[] = $field;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Shaping
	 * ---------------------------------------------------------------- */

	/**
	 * Sanitize + validate the provided fields.
	 *
	 * @param array      $data     Input.
	 * @param array|null $existing Existing row on update.
	 * @return array|WP_Error
	 */
	private static function prepare( array $data, ?array $existing ) {
		$row = array();

		if ( array_key_exists( 'name', $data ) || ! $existing ) {
			$name = MJERP_Helpers::text( MJERP_Helpers::array_get( $data, 'name', '' ), 190 );
			if ( '' === $name ) {
				return new WP_Error( 'mjerp_invalid_name', __( 'El nombre del cliente es obligatorio.', 'mj-erp' ), array( 'status' => 400, 'field' => 'name' ) );
			}
			$row['name'] = $name;
		}

		if ( array_key_exists( 'user_id', $data ) ) {
			$user_id = absint( $data['user_id'] );
			if ( $user_id && ! get_userdata( $user_id ) ) {
				return new WP_Error( 'mjerp_invalid_user', __( 'La cuenta de la tienda no existe.', 'mj-erp' ), array( 'status' => 400, 'field' => 'user_id' ) );
			}
			if ( $user_id ) {
				$taken = self::find_by_user( $user_id, false );
				if ( $taken && ( ! $existing || (int) $taken['id'] !== (int) $existing['id'] ) ) {
					return new WP_Error(
						'mjerp_user_taken',
						sprintf(
							/* translators: %s: customer name. */
							__( 'Esa cuenta ya está vinculada al cliente «%s».', 'mj-erp' ),
							$taken['name']
						),
						array(
							'status'      => 409,
							'field'       => 'user_id',
							'customer_id' => (int) $taken['id'],
						)
					);
				}
			}
			$row['user_id'] = $user_id;
		}

		$text_fields = array(
			'company'  => 190,
			'city'     => 120,
			'province' => 120,
			'address'  => 255,
			'postcode' => 30,
			'phone'    => 60,
			'doc_number' => 60,
		);
		foreach ( $text_fields as $key => $max ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = MJERP_Helpers::text( $data[ $key ], $max );
			}
		}

		if ( array_key_exists( 'doc_type', $data ) ) {
			$doc_type = sanitize_key( (string) $data['doc_type'] );
			if ( '' !== $doc_type ) {
				$allowed = wp_list_pluck( MJERP_Settings::customer_doc_types(), 'key' );
				if ( ! in_array( $doc_type, $allowed, true ) ) {
					return new WP_Error( 'mjerp_invalid_doc_type', __( 'Ese tipo de documento no está configurado.', 'mj-erp' ), array( 'status' => 400, 'field' => 'doc_type' ) );
				}
			}
			$row['doc_type'] = $doc_type;
		}

		if ( array_key_exists( 'email', $data ) ) {
			$raw   = trim( (string) $data['email'] );
			$email = sanitize_email( $raw );
			if ( '' !== $raw && ( '' === $email || ! is_email( $email ) ) ) {
				return new WP_Error( 'mjerp_invalid_email', __( 'El email del cliente es inválido.', 'mj-erp' ), array( 'status' => 400, 'field' => 'email' ) );
			}
			$row['email'] = $email;
		}

		if ( array_key_exists( 'price_list_key', $data ) ) {
			$key = sanitize_key( (string) $data['price_list_key'] );
			if ( '' !== $key && ! MJERP_Settings::price_list( $key ) ) {
				return new WP_Error( 'mjerp_invalid_price_list', __( 'Esa lista de precios no existe.', 'mj-erp' ), array( 'status' => 400, 'field' => 'price_list_key' ) );
			}
			$row['price_list_key'] = $key;
		}

		if ( array_key_exists( 'channel', $data ) ) {
			$row['channel'] = sanitize_key( (string) $data['channel'] );
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
	 * Customer fields readable from a WordPress/WooCommerce account.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	public static function fields_from_user( WP_User $user ) : array {
		$name = trim( (string) get_user_meta( $user->ID, 'billing_first_name', true ) . ' ' . (string) get_user_meta( $user->ID, 'billing_last_name', true ) );
		if ( '' === $name ) {
			$name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
		}
		if ( '' === $name ) {
			$name = trim( (string) $user->display_name );
		}
		if ( '' === $name ) {
			$name = (string) $user->user_login;
		}

		return array(
			'name'     => MJERP_Helpers::text( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ), 190 ),
			'company'  => MJERP_Helpers::text( get_user_meta( $user->ID, 'billing_company', true ), 190 ),
			'email'    => sanitize_email( (string) $user->user_email ),
			'phone'    => MJERP_Helpers::text( get_user_meta( $user->ID, 'billing_phone', true ), 60 ),
			'address'  => MJERP_Helpers::text( get_user_meta( $user->ID, 'billing_address_1', true ), 255 ),
			'city'     => MJERP_Helpers::text( get_user_meta( $user->ID, 'billing_city', true ), 120 ),
			'province' => MJERP_Helpers::text( get_user_meta( $user->ID, 'billing_state', true ), 120 ),
			'postcode' => MJERP_Helpers::text( get_user_meta( $user->ID, 'billing_postcode', true ), 30 ),
		);
	}

	/**
	 * Keeps the customer snapshot of past sales in sync with the card.
	 *
	 * @param int   $id  Customer id.
	 * @param array $row Columns that changed.
	 * @return void
	 */
	private static function sync_sales_snapshot( int $id, array $row ) : void {
		global $wpdb;

		$map = array(
			'name'  => 'customer_name',
			'email' => 'customer_email',
			'phone' => 'customer_phone',
		);

		$update  = array();
		$formats = array();
		foreach ( $map as $key => $column ) {
			// Un valor que se vació no se propaga: la venta guarda el contacto que la persona dio
			// ese día, y borrarlo de la ficha hoy no lo vuelve falso en el comprobante de entonces.
			if ( array_key_exists( $key, $row ) && '' !== (string) $row[ $key ] ) {
				$update[ $column ] = $row[ $key ];
				$formats[]         = '%s';
			}
		}

		if ( empty( $update ) ) {
			return;
		}

		$wpdb->update( self::sales_table(), $update, array( 'customer_id' => $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Normalizes a value before comparing it against the card.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return string
	 */
	private static function normalize_match( string $field, $value ) : string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( 'email' === $field ) {
			return sanitize_email( $value );
		}

		if ( 'phone' === $field ) {
			$digits = (string) preg_replace( '/\D+/', '', $value );
			// Los últimos 8 dígitos alcanzan para reconocer un número sin confundir dos personas.
			return strlen( $digits ) >= 8 ? substr( $digits, -8 ) : '';
		}

		return (string) preg_replace( '/[^A-Za-z0-9]/', '', $value );
	}

	/**
	 * Row → API shape.
	 *
	 * @param array $row        DB row.
	 * @param bool  $with_stats Include the sales counters.
	 * @return array
	 */
	private static function cast( array $row, bool $with_stats = false ) : array {
		$id      = (int) $row['id'];
		$user_id = (int) self::v( $row, 'user_id', 0 );

		$out = array(
			'id'             => $id,
			'user_id'        => $user_id,
			'is_linked'      => $user_id > 0,
			'name'           => (string) self::v( $row, 'name' ),
			'company'        => (string) self::v( $row, 'company' ),
			'doc_type'       => (string) self::v( $row, 'doc_type' ),
			'doc_number'     => (string) self::v( $row, 'doc_number' ),
			'email'          => (string) self::v( $row, 'email' ),
			'phone'          => (string) self::v( $row, 'phone' ),
			'address'        => (string) self::v( $row, 'address' ),
			'city'           => (string) self::v( $row, 'city' ),
			'province'       => (string) self::v( $row, 'province' ),
			'postcode'       => (string) self::v( $row, 'postcode' ),
			'price_list_key' => (string) self::v( $row, 'price_list_key' ),
			'channel'        => (string) self::v( $row, 'channel' ),
			'notes'          => (string) self::v( $row, 'notes' ),
			'is_active'      => (bool) (int) self::v( $row, 'is_active', 1 ),
			'linked_at'      => self::datetime_or_null( self::v( $row, 'linked_at', null ) ),
			'created_at'     => self::datetime_or_null( self::v( $row, 'created_at', null ) ),
			'updated_at'     => self::datetime_or_null( self::v( $row, 'updated_at', null ) ),
			'account'        => null,
		);

		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof WP_User ) {
				$avatar         = get_avatar_url( $user_id, array( 'size' => 48 ) );
				$out['account'] = array(
					'id'     => $user_id,
					'name'   => html_entity_decode( (string) $user->display_name, ENT_QUOTES, 'UTF-8' ),
					'email'  => (string) $user->user_email,
					'avatar' => $avatar ? (string) $avatar : null,
					'roles'  => array_values( array_map( 'strval', (array) $user->roles ) ),
				);
			} else {
				// La cuenta se borró de WordPress: la ficha sigue, pero no miente diciendo que está
				// vinculada a algo que ya no existe.
				$out['is_linked'] = false;
			}
		}

		if ( $with_stats ) {
			$out['stats'] = self::stats( $id );
		}

		return $out;
	}

	/**
	 * Reads a column with a default.
	 *
	 * @param array  $row     Row.
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private static function v( array $row, string $key, $default = '' ) {
		return ( isset( $row[ $key ] ) && null !== $row[ $key ] ) ? $row[ $key ] : $default;
	}

	/**
	 * $wpdb formats for the given row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function formats( array $row ) : array {
		$formats = array();
		foreach ( array_keys( $row ) as $key ) {
			$formats[] = isset( self::FORMATS[ $key ] ) ? self::FORMATS[ $key ] : '%s';
		}
		return $formats;
	}

	/**
	 * Normalizes a datetime column ('0000-00-00…' → null).
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
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
