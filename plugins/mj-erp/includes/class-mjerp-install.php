<?php
/**
 * Installation: tables (dbDelta), upgrades, capabilities, defaults, rewrite flush.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Install
 */
class MJERP_Install {

	/**
	 * Option holding the installed DB schema version.
	 */
	const OPTION_DB_VERSION = 'mjerp_db_version';

	/**
	 * Option flag that asks for a rewrite flush on the next `init`.
	 */
	const OPTION_FLUSH = 'mjerp_flush_rewrite';

	/**
	 * Short table names (without prefix).
	 *
	 * @return string[]
	 */
	public static function table_names() {
		return array(
			'suppliers',
			'cashboxes',
			'customers',
			'purchases',
			'purchase_items',
			'purchase_expenses',
			'stock_movements',
			'consignees',
			'consignments',
			'consignment_items',
			'consignment_sales',
			'consignment_returns',
			'consignment_payments',
			'consignment_allocations',
			'consignment_log',
			'sales',
			'sale_items',
			'sale_payments',
			'sale_log',
			'customer_credit',
			'sale_returns',
			'sale_return_items',
			'rma',
		);
	}

	/**
	 * Full table name: `table('purchases')` → `{$wpdb->prefix}mjerp_purchases`.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		$name = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $name ) );
		return $wpdb->prefix . 'mjerp_' . $name;
	}

	/**
	 * Loads the classes the installer depends on (the activation hook runs outside the plugin boot).
	 *
	 * @return void
	 */
	private static function load_dependencies() {
		$dir = defined( 'MJERP_DIR' ) ? MJERP_DIR : trailingslashit( dirname( __DIR__ ) );
		if ( ! class_exists( 'MJERP_Capabilities' ) ) {
			require_once $dir . 'includes/class-mjerp-capabilities.php';
		}
		if ( ! class_exists( 'MJERP_Helpers' ) ) {
			require_once $dir . 'includes/class-mjerp-helpers.php';
		}
		if ( ! class_exists( 'MJERP_Settings' ) ) {
			require_once $dir . 'includes/class-mjerp-settings.php';
		}
	}

	/**
	 * Activation hook handler.
	 *
	 * @param bool $network_wide Network-wide activation.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		self::load_dependencies();

		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}

		self::install();
	}

	/**
	 * Installs/updates everything for the current site. Idempotent.
	 *
	 * @return void
	 */
	public static function install() {
		self::load_dependencies();

		self::create_tables();
		self::sync_capabilities();
		MJERP_Capabilities::ensure_consignee_role();

		// Default settings only when the option does not exist yet (keeps user config on re-activation).
		add_option( MJERP_Settings::OPTION, MJERP_Settings::defaults(), '', true );
		MJERP_Settings::flush_cache();

		update_option( self::OPTION_FLUSH, 1, true );
		update_option( self::OPTION_DB_VERSION, MJERP_DB_VERSION, true );

		/**
		 * Fires after the plugin (re)installed its schema, capabilities and defaults.
		 */
		do_action( 'mjerp_installed' );
	}

	/**
	 * Deactivation hook handler.
	 *
	 * @param bool $network_wide Network-wide deactivation.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				// `flush_rewrite_rules()` would regenerate the rules from the *current* request's
				// $wp_rewrite, which still belongs to the original site, and store them under the
				// switched site. Dropping the cached option instead lets each site rebuild its own
				// rules on its next request.
				delete_option( 'rewrite_rules' );
				restore_current_blog();
			}
			return;
		}
		flush_rewrite_rules();
	}

	/**
	 * Re-runs the installer when the stored DB version differs from MJERP_DB_VERSION.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return;
		}
		$installed = (string) get_option( self::OPTION_DB_VERSION, '' );
		if ( '' !== $installed && $installed === (string) MJERP_DB_VERSION ) {
			return;
		}

		self::install();

		// Solo para instalaciones que ya existían: una recién creada nace con los valores nuevos.
		if ( '' !== $installed ) {
			self::migrate( $installed );
		}

		/**
		 * Fires after a schema upgrade.
		 *
		 * @param string $from Previous version ('' on fresh installs).
		 * @param string $to   New version.
		 */
		do_action( 'mjerp_upgraded', $installed, MJERP_DB_VERSION );
	}

	/**
	 * Data migrations for stores that were already running an older version.
	 *
	 * Cada bloque se ejecuta una sola vez, comparando contra la versión que estaba instalada.
	 *
	 * @param string $from Version that was installed before this upgrade.
	 * @return void
	 */
	protected static function migrate( $from ) {
		if ( version_compare( $from, '1.3.0', '<' ) ) {
			self::migrate_130_pending_quantities();
			self::migrate_130_sales_need_no_approval();
		}

		if ( version_compare( $from, '1.5.0', '<' ) ) {
			self::migrate_150_customer_cards();
		}

		if ( version_compare( $from, '1.6.0', '<' ) ) {
			self::migrate_160_deliveries();
		}

		if ( version_compare( $from, '1.7.0', '<' ) ) {
			self::migrate_170_committed_sales();
		}

		if ( version_compare( $from, '1.8.0', '<' ) ) {
			self::migrate_180_sale_payment_rates();
		}

		if ( version_compare( $from, '1.9.0', '<' ) ) {
			self::migrate_190_sale_payments_base();
		}

		if ( version_compare( $from, '1.13.0', '<' ) ) {
			self::migrate_1130_sale_payments_currency();
		}

		/**
		 * Fires so other code can run its own migrations.
		 *
		 * @param string $from Previous version.
		 */
		do_action( 'mjerp_migrate', $from );
	}

	/**
	 * 1.3.0 — rellena `qty_pending` en las entregas que ya existían.
	 *
	 * La columna nace en cero, así que una venta avisada y sin confirmar de antes no estaría
	 * reservando su unidad hasta que algo tocara esa línea.
	 *
	 * @return void
	 */
	protected static function migrate_130_pending_quantities() {
		global $wpdb;

		$items = self::table( 'consignment_items' );
		$sales = self::table( 'consignment_sales' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$items} i SET i.qty_pending = COALESCE((
					SELECT SUM(s.qty) FROM {$sales} s
					WHERE s.consignment_item_id = i.id AND s.status = %s
				), 0)",
				'pendiente'
			)
		);
	}

	/**
	 * 1.3.0 — deja de pedir confirmación para las ventas del portal.
	 *
	 * El circuito real de una consignación es: el revendedor avisa lo que vendió (y eso ya baja
	 * stock y genera la deuda) y el dueño confirma **el pago**, que es lo único que puede verificar.
	 * Pedir confirmación de la venta además duplicaba el trabajo y dejaba la deuda en el aire.
	 *
	 * Solo se toca el valor que era el default anterior: quien eligió `always` a propósito lo
	 * conserva.
	 *
	 * @return void
	 */
	protected static function migrate_130_sales_need_no_approval() {
		$settings = get_option( MJERP_Settings::OPTION, array() );
		if ( ! is_array( $settings ) ) {
			return;
		}
		$current = isset( $settings['consignment_approval'] ) ? (string) $settings['consignment_approval'] : '';
		if ( 'portal' !== $current ) {
			return;
		}

		$settings['consignment_approval'] = 'never';
		update_option( MJERP_Settings::OPTION, $settings, true );
		MJERP_Settings::flush_cache();
	}

	/**
	 * 1.6.0 — las ventas que ya existían nacen entregadas.
	 *
	 * La columna arranca en «pendiente», así que sin esto el día que se actualiza el plugin
	 * aparecerían años de ventas reclamando una entrega que ya pasó. Lo que se vendió antes de
	 * existir este paso está cerrado: se marca entregado y sin método (no hay forma de saber cuál
	 * fue), y lo que realmente quedó a medias se corrige a mano desde la lista de ventas.
	 *
	 * Las anuladas se dejan como están: no se entregó nada.
	 *
	 * @return void
	 */
	protected static function migrate_160_deliveries() {
		global $wpdb;

		$sales = self::table( 'sales' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$sales}
				SET delivery_status = %s, delivered_at = COALESCE(delivered_at, created_at)
				WHERE delivery_status = %s AND status <> %s",
				'entregado',
				'pendiente',
				'anulada'
			)
		);
	}

	/**
	 * 1.7.0 — las ventas que ya existían quedan como "ya comprometidas".
	 *
	 * Desde esta versión el pedido y el descuento de stock esperan a que la venta esté cobrada o
	 * entregada. Lo de antes ya pasó por los dos caminos viejos (pedido emitido o stock descontado
	 * a mano), así que `wants_order` arranca en 1 —que es el default de la columna— y no hay nada
	 * que recalcular: `maybe_commit()` se corta solo en cuanto ve el pedido o el stock aplicado.
	 *
	 * Lo único que hay que arreglar es la venta sin pedido y sin stock aplicado: son las que se
	 * cargaron con "no emitir pedido" y con el stock apagado. Se marcan para que no se les emita un
	 * pedido de la nada la próxima vez que se cobren.
	 *
	 * @return void
	 */
	protected static function migrate_170_committed_sales() {
		global $wpdb;

		$sales = self::table( 'sales' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$sales} SET wants_order = 0 WHERE order_id = 0 AND stock_applied = 0"
		);
	}

	/**
	 * 1.8.0 — los cobros de ventas que se guardaron con el dólar a 1.
	 *
	 * Un cobro que llegaba sin TC (el de un pedido de la tienda, o "cobré todo lo que faltaba")
	 * tomaba el TC de venta de la configuración, y sin ese ajuste cargado caía en 1: $ 25.000
	 * quedaban como US$ 25.000 y el "Cobrado" de la lista de ventas sumaba pesos como dólares.
	 *
	 * Se corrigen solo esos: cobros en la misma moneda que su venta, con TC exactamente 1, de una
	 * venta que sí tiene su TC. Toman el de la venta, que es con el que se calculó su total.
	 * Después se rehace el total cobrado en dólares de cada venta; lo cobrado en su moneda no
	 * cambia, así que ninguna venta cambia de estado.
	 *
	 * @return void
	 */
	protected static function migrate_180_sale_payment_rates() {
		global $wpdb;

		$payments = self::table( 'sale_payments' );
		$sales    = self::table( 'sales' );
		$base     = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );

		// Los nombres de tabla son propios (self::table); los valores van por prepare().
		$fix_rates = "UPDATE {$payments} p INNER JOIN {$sales} s ON s.id = p.sale_id SET p.rate = s.rate, p.amount_base = ROUND(p.amount / s.rate, 4) WHERE p.currency = s.currency AND p.currency <> %s AND p.rate = 1 AND s.rate > 1"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas propias.
		$wpdb->query( $wpdb->prepare( $fix_rates, $base ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$paid_base = "UPDATE {$sales} s SET s.paid_base = ROUND(COALESCE((SELECT SUM(p.amount_base) FROM {$payments} p WHERE p.sale_id = s.id AND p.status = %s), 0), 4)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas propias.
		$wpdb->query( $wpdb->prepare( $paid_base, 'confirmado' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 1.9.0 — los cobros que siguieron sumando pesos como dólares después de la 1.8.0.
	 *
	 * La 1.8.0 buscaba cobros en pesos con TC 1, pero el mismo error tiene otra forma: la venta o el
	 * cobro anotados con la moneda base. El total de la venta se pasa a dólares dividiendo siempre
	 * por su TC; el cobro miraba la moneda y, si decía "USD", guardaba los $ 25.000 como US$ 25.000.
	 * El Facturado daba bien y el Cobrado no.
	 *
	 * Se corrige por el síntoma y no por la etiqueta, con el TC de la venta, que es el dólar del día
	 * en que se vendió:
	 *
	 * - Un cobro anotado en la moneda base sobre una venta en otra moneda pasa a la de la venta
	 *   cuando su importe, leído en dólares, sería más de diez veces el total de la venta.
	 * - Un cobro en la moneda de la venta con un TC menor a un décimo del de la venta (el 1 de antes,
	 *   o 1,57 por 1.570) toma el de la venta.
	 * - Los dólares de cada cobro en la moneda de la venta se rehacen dividiendo por su TC, como el
	 *   total. Si esa moneda es la base, solo cuando el importe no puede ser dólares.
	 *
	 * Después se rehace el Cobrado de cada venta. Lo cobrado en su moneda no cambia, así que ninguna
	 * venta cambia de estado.
	 *
	 * @return void
	 */
	protected static function migrate_190_sale_payments_base() {
		global $wpdb;

		$payments = self::table( 'sale_payments' );
		$sales    = self::table( 'sales' );
		$base     = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );

		// Un importe que en dólares sería más de diez veces el total de la venta está en pesos.
		$not_dollars = '( p.currency <> %s OR ( s.total_base > 0 AND p.amount > s.total_base * 10 ) )';

		// Los nombres de tabla son propios (self::table); los valores van por prepare().
		$relabel = "UPDATE {$payments} p INNER JOIN {$sales} s ON s.id = p.sale_id SET p.currency = s.currency, p.rate = s.rate WHERE p.currency = %s AND s.currency <> %s AND s.rate > 1 AND s.total_base > 0 AND p.amount > s.total_base * 10"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas propias.
		$wpdb->query( $wpdb->prepare( $relabel, $base, $base ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$fix_rates = "UPDATE {$payments} p INNER JOIN {$sales} s ON s.id = p.sale_id SET p.rate = s.rate WHERE p.currency = s.currency AND s.rate > 1 AND p.rate * 10 < s.rate AND {$not_dollars}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas propias.
		$wpdb->query( $wpdb->prepare( $fix_rates, $base ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$fix_base = "UPDATE {$payments} p INNER JOIN {$sales} s ON s.id = p.sale_id SET p.amount_base = ROUND(p.amount / p.rate, 4) WHERE p.currency = s.currency AND s.rate > 1 AND p.rate > 0 AND {$not_dollars}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas propias.
		$wpdb->query( $wpdb->prepare( $fix_base, $base ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$paid_base = "UPDATE {$sales} s SET s.paid_base = ROUND(COALESCE((SELECT SUM(p.amount_base) FROM {$payments} p WHERE p.sale_id = s.id AND p.status = %s), 0), 4)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas propias.
		$wpdb->query( $wpdb->prepare( $paid_base, 'confirmado' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 1.13.0 — los cobros de la pantalla de venta que quedaron en dólares.
	 *
	 * La causa real de todo el Cobrado mal: una venta cargada con «Cobrado» mandaba su cobro con la
	 * moneda vacía, y la moneda vacía se volvía USD. Los $ 24.500 quedaban como US$ 24.500. La 1.9.0
	 * arregló los que había hasta ese día; la fuente seguía abierta y los de después volvieron a
	 * entrar mal.
	 *
	 * Ninguna pantalla cobra una venta en otra moneda que la suya (el cobro en dólares se anota en
	 * pesos, con su TC), así que un cobro en la moneda base sobre una venta en pesos es este error.
	 * Igual se pide que el importe no pueda ser dólares: más del doble de lo que vale la venta.
	 *
	 * - Esos cobros pasan a la moneda de su venta, con el TC de la venta (el dólar del día en que se
	 *   vendió) si el suyo no sirve.
	 * - Los dólares de todo cobro en la moneda de su venta se rehacen dividiendo por su TC, como el
	 *   total de la venta.
	 * - Se rehace el Cobrado de cada venta. Lo cobrado en su moneda no cambia: ninguna venta cambia de
	 *   estado.
	 *
	 * @return void
	 */
	protected static function migrate_1130_sale_payments_currency() {
		global $wpdb;

		$payments = self::table( 'sale_payments' );
		$sales    = self::table( 'sales' );
		$base     = strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) );

		// Los nombres de tabla son propios (self::table); los valores van por prepare().
		$relabel = "UPDATE {$payments} p INNER JOIN {$sales} s ON s.id = p.sale_id SET p.currency = s.currency, p.rate = IF(p.rate * 10 < s.rate, s.rate, p.rate) WHERE p.currency = %s AND s.currency <> %s AND s.rate > 1 AND p.amount > 0 AND ( s.total_base <= 0 OR p.amount > s.total_base * 2 )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas propias.
		$wpdb->query( $wpdb->prepare( $relabel, $base, $base ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$fix_rates = "UPDATE {$payments} p INNER JOIN {$sales} s ON s.id = p.sale_id SET p.rate = s.rate WHERE p.currency = s.currency AND s.currency <> %s AND s.rate > 1 AND p.rate * 10 < s.rate"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas propias.
		$wpdb->query( $wpdb->prepare( $fix_rates, $base ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$fix_base = "UPDATE {$payments} p INNER JOIN {$sales} s ON s.id = p.sale_id SET p.amount_base = ROUND(p.amount / p.rate, 4) WHERE p.currency = s.currency AND s.currency <> %s AND p.rate > 0"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas propias.
		$wpdb->query( $wpdb->prepare( $fix_base, $base ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$paid_base = "UPDATE {$sales} s SET s.paid_base = ROUND(COALESCE((SELECT SUM(p.amount_base) FROM {$payments} p WHERE p.sale_id = s.id AND p.status = %s), 0), 4)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas propias.
		$wpdb->query( $wpdb->prepare( $paid_base, 'confirmado' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 1.5.0 — la libreta de clientes estrena con el historial que ya había.
	 *
	 * Antes de esto una venta guardaba a quién se le vendió como texto suelto (`customer_name`) y,
	 * si la persona tenía cuenta, el id del usuario. Ninguna de las dos cosas es una ficha: no se
	 * podía abrir un cliente y ver qué compró. Esta migración arma una ficha por cada persona que ya
	 * aparecía en las ventas y las reapunta.
	 *
	 * Se agrupa por cuenta cuando la hay y por email cuando no, que es lo único que identifica a un
	 * comprador de mostrador sin repetir a la misma persona en dos fichas. Una venta con un nombre y
	 * nada más se queda como está: inventarle una ficha por venta llenaría la libreta de duplicados
	 * que después hay que limpiar a mano.
	 *
	 * @return void
	 */
	protected static function migrate_150_customer_cards() {
		global $wpdb;

		if ( ! class_exists( 'MJERP_Customers_Repo' ) ) {
			return;
		}

		$sales     = self::table( 'sales' );
		$customers = self::table( 'customers' );

		// Una tienda con muchísimo historial no puede pagar esto dentro de un request. El tope es
		// filtrable, y lo que quede afuera se puede vincular a mano desde la pantalla de clientes.
		$limit = (int) apply_filters( 'mjerp_migrate_customer_cards_limit', 2000 );
		if ( $limit <= 0 ) {
			return;
		}

		// 1) Las que tienen cuenta: la cuenta es la identidad, el nombre es solo lo que se escribió.
		$accounts = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT DISTINCT customer_user_id FROM {$sales}
				WHERE customer_user_id > 0 AND customer_id = 0 LIMIT %d",
				$limit
			)
		);

		$done = 0;
		foreach ( (array) $accounts as $user_id ) {
			$user_id = absint( $user_id );
			if ( ! $user_id || ! get_userdata( $user_id ) ) {
				continue;
			}

			$card = MJERP_Customers_Repo::find_by_user( $user_id, false );
			if ( ! $card ) {
				$fields = MJERP_Customers_Repo::fields_from_user( get_userdata( $user_id ) );
				$fields['user_id'] = $user_id;
				$id                = MJERP_Customers_Repo::create( $fields );
				if ( is_wp_error( $id ) ) {
					continue;
				}
				$card = array( 'id' => (int) $id );
			}

			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"UPDATE {$sales} SET customer_id = %d WHERE customer_user_id = %d AND customer_id = 0",
					(int) $card['id'],
					$user_id
				)
			);
			$done++;
		}

		$remaining = max( 0, $limit - $done );
		if ( $remaining <= 0 ) {
			return;
		}

		// 2) Las de mostrador con email: el email agrupa a la misma persona en una sola ficha.
		$guests = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT customer_email, MAX(customer_name) AS customer_name, MAX(customer_phone) AS customer_phone
				FROM {$sales}
				WHERE customer_user_id = 0 AND customer_id = 0 AND customer_email <> ''
				GROUP BY customer_email LIMIT %d",
				$remaining
			),
			ARRAY_A
		);

		foreach ( (array) $guests as $guest ) {
			$email = sanitize_email( (string) $guest['customer_email'] );
			if ( '' === $email ) {
				continue;
			}

			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$customers} WHERE email = %s LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB

			if ( $existing ) {
				$card_id = (int) $existing;
			} else {
				$name = MJERP_Helpers::text( $guest['customer_name'], 190 );
				if ( '' === $name ) {
					$name = $email;
				}
				$card_id = MJERP_Customers_Repo::create(
					array(
						'name'  => $name,
						'email' => $email,
						'phone' => MJERP_Helpers::text( $guest['customer_phone'], 60 ),
					)
				);
				if ( is_wp_error( $card_id ) ) {
					continue;
				}
			}

			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"UPDATE {$sales} SET customer_id = %d WHERE customer_user_id = 0 AND customer_id = 0 AND customer_email = %s",
					(int) $card_id,
					$email
				)
			);
		}
	}

	/**
	 * Creates/updates all tables with dbDelta.
	 *
	 * @return array dbDelta report.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$suppliers = self::table( 'suppliers' );
		$cashboxes = self::table( 'cashboxes' );
		$customers = self::table( 'customers' );
		$purchases = self::table( 'purchases' );
		$items     = self::table( 'purchase_items' );
		$expenses  = self::table( 'purchase_expenses' );
		$movements = self::table( 'stock_movements' );

		$consignees   = self::table( 'consignees' );
		$consignments = self::table( 'consignments' );
		$cons_items   = self::table( 'consignment_items' );
		$cons_sales   = self::table( 'consignment_sales' );
		$cons_returns = self::table( 'consignment_returns' );
		$cons_log     = self::table( 'consignment_log' );
		$cons_pays    = self::table( 'consignment_payments' );
		$cons_allocs  = self::table( 'consignment_allocations' );

		$sales     = self::table( 'sales' );
		$sale_items = self::table( 'sale_items' );
		$sale_pays  = self::table( 'sale_payments' );
		$sale_log   = self::table( 'sale_log' );
		$credit     = self::table( 'customer_credit' );
		$returns    = self::table( 'sale_returns' );
		$ret_items  = self::table( 'sale_return_items' );
		$rma        = self::table( 'rma' );

		$sql = array();

		$sql[] = "CREATE TABLE {$suppliers} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	name VARCHAR(190) NOT NULL,
	contact_name VARCHAR(190) NULL,
	email VARCHAR(190) NULL,
	phone VARCHAR(60) NULL,
	country VARCHAR(100) NULL,
	default_currency VARCHAR(3) NULL,
	notes TEXT NULL,
	is_active TINYINT(1) NOT NULL DEFAULT 1,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY name (name)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$cashboxes} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	name VARCHAR(190) NOT NULL,
	currency VARCHAR(3) NOT NULL DEFAULT 'ARS',
	is_active TINYINT(1) NOT NULL DEFAULT 1,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id)
) {$charset_collate};";

		/*
		 * Libreta de clientes del ERP.
		 *
		 * Un cliente de acá NO es una cuenta de WooCommerce: es el registro de a quién se le vendió,
		 * y nace del mostrador, donde la persona da un nombre y un teléfono y nada más. `user_id`
		 * queda en 0 hasta que esa misma persona se crea una cuenta en la tienda y alguien las
		 * vincula; recién ahí el historial del mostrador y el de la tienda son el mismo.
		 *
		 * Por eso `user_id` lleva un índice común y no uno único: muchísimas fichas conviven con
		 * user_id = 0. La unicidad de una cuenta ya vinculada la cuida el repositorio.
		 */
		$sql[] = "CREATE TABLE {$customers} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	name VARCHAR(190) NOT NULL,
	company VARCHAR(190) NULL,
	doc_type VARCHAR(30) NULL,
	doc_number VARCHAR(60) NULL,
	email VARCHAR(190) NULL,
	phone VARCHAR(60) NULL,
	address VARCHAR(255) NULL,
	city VARCHAR(120) NULL,
	province VARCHAR(120) NULL,
	postcode VARCHAR(30) NULL,
	price_list_key VARCHAR(60) NULL,
	channel VARCHAR(60) NULL,
	notes TEXT NULL,
	is_active TINYINT(1) NOT NULL DEFAULT 1,
	linked_at DATETIME NULL,
	linked_by BIGINT UNSIGNED NULL,
	created_by BIGINT UNSIGNED NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY user_id (user_id),
	KEY name (name),
	KEY email (email),
	KEY phone (phone),
	KEY doc_number (doc_number),
	KEY is_active (is_active)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$purchases} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	code VARCHAR(60) NOT NULL,
	status VARCHAR(30) NOT NULL DEFAULT 'borrador',
	purchase_date DATE NULL,
	supplier_id BIGINT UNSIGNED NULL,
	supplier_name VARCHAR(190) NULL,
	destination VARCHAR(100) NULL,
	base_currency VARCHAR(3) NOT NULL DEFAULT 'USD',
	default_rate DECIMAL(18,6) NOT NULL DEFAULT 1,
	proration_method VARCHAR(20) NOT NULL DEFAULT 'fob',
	notes TEXT NULL,
	fob_total DECIMAL(18,4) NOT NULL DEFAULT 0,
	expenses_total DECIMAL(18,4) NOT NULL DEFAULT 0,
	total DECIMAL(18,4) NOT NULL DEFAULT 0,
	items_count INT NOT NULL DEFAULT 0,
	calc_json LONGTEXT NULL,
	received_at DATETIME NULL,
	received_by BIGINT UNSIGNED NULL,
	created_by BIGINT UNSIGNED NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code),
	KEY status (status),
	KEY supplier_id (supplier_id),
	KEY purchase_date (purchase_date)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$items} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	purchase_id BIGINT UNSIGNED NOT NULL,
	product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	name VARCHAR(255) NOT NULL,
	sku VARCHAR(100) NULL,
	quantity DECIMAL(18,4) NOT NULL DEFAULT 1,
	unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
	currency VARCHAR(3) NOT NULL DEFAULT 'USD',
	rate DECIMAL(18,6) NOT NULL DEFAULT 1,
	fob_base DECIMAL(18,6) NOT NULL DEFAULT 0,
	share_base DECIMAL(18,6) NOT NULL DEFAULT 0,
	landed_unit_base DECIMAL(18,6) NOT NULL DEFAULT 0,
	received_qty DECIMAL(18,4) NULL,
	sort_order INT NOT NULL DEFAULT 0,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY purchase_id (purchase_id),
	KEY product_id (product_id)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$expenses} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	purchase_id BIGINT UNSIGNED NOT NULL,
	type VARCHAR(60) NOT NULL DEFAULT 'otro',
	description VARCHAR(255) NULL,
	amount DECIMAL(18,6) NOT NULL DEFAULT 0,
	currency VARCHAR(3) NOT NULL DEFAULT 'ARS',
	rate DECIMAL(18,6) NOT NULL DEFAULT 1,
	amount_base DECIMAL(18,6) NOT NULL DEFAULT 0,
	is_prorated TINYINT(1) NOT NULL DEFAULT 1,
	cashbox_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	expense_date DATE NULL,
	sort_order INT NOT NULL DEFAULT 0,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY purchase_id (purchase_id)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$movements} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	product_id BIGINT UNSIGNED NOT NULL,
	variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	qty DECIMAL(18,4) NOT NULL,
	type VARCHAR(40) NOT NULL,
	ref_type VARCHAR(40) NULL,
	ref_id BIGINT UNSIGNED NULL,
	stock_before DECIMAL(18,4) NULL,
	stock_after DECIMAL(18,4) NULL,
	unit_cost_base DECIMAL(18,6) NULL,
	note VARCHAR(255) NULL,
	user_id BIGINT UNSIGNED NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY product_id (product_id),
	KEY ref (ref_type,ref_id)
) {$charset_collate};";

		/*
		 * Consignment module.
		 *
		 * Money lives in the base currency (USD): `unit_price_base` is frozen when the goods are
		 * handed over, so a moving exchange rate never rewrites a debt that was already agreed.
		 * Stock leaves WooCommerce when the consignee reports the sale, not at delivery.
		 */

		$sql[] = "CREATE TABLE {$consignees} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id BIGINT UNSIGNED NOT NULL,
	name VARCHAR(190) NOT NULL,
	email VARCHAR(190) NULL,
	phone VARCHAR(60) NULL,
	price_list_key VARCHAR(60) NULL,
	credit_limit_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	notes TEXT NULL,
	overrides_json LONGTEXT NULL,
	is_active TINYINT(1) NOT NULL DEFAULT 1,
	created_by BIGINT UNSIGNED NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY user_id (user_id),
	KEY name (name)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$consignments} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	code VARCHAR(60) NOT NULL,
	consignee_id BIGINT UNSIGNED NOT NULL,
	status VARCHAR(30) NOT NULL DEFAULT 'borrador',
	delivered_at DATE NULL,
	base_currency VARCHAR(3) NOT NULL DEFAULT 'USD',
	notes TEXT NULL,
	items_count INT NOT NULL DEFAULT 0,
	qty_total DECIMAL(18,4) NOT NULL DEFAULT 0,
	value_total_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	created_by BIGINT UNSIGNED NULL,
	delivered_by BIGINT UNSIGNED NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code),
	KEY consignee_id (consignee_id),
	KEY status (status),
	KEY delivered_at (delivered_at)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$cons_items} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	consignment_id BIGINT UNSIGNED NOT NULL,
	consignee_id BIGINT UNSIGNED NOT NULL,
	product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	name VARCHAR(255) NOT NULL,
	sku VARCHAR(100) NULL,
	qty DECIMAL(18,4) NOT NULL DEFAULT 0,
	unit_price_base DECIMAL(18,6) NOT NULL DEFAULT 0,
	price_list_key VARCHAR(60) NULL,
	qty_sold DECIMAL(18,4) NOT NULL DEFAULT 0,
	qty_pending DECIMAL(18,4) NOT NULL DEFAULT 0,
	qty_returned DECIMAL(18,4) NOT NULL DEFAULT 0,
	sort_order INT NOT NULL DEFAULT 0,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY consignment_id (consignment_id),
	KEY consignee_id (consignee_id),
	KEY product (product_id,variation_id)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$cons_sales} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	consignee_id BIGINT UNSIGNED NOT NULL,
	consignment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	consignment_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	name VARCHAR(255) NOT NULL,
	sku VARCHAR(100) NULL,
	qty DECIMAL(18,4) NOT NULL DEFAULT 0,
	unit_price_base DECIMAL(18,6) NOT NULL DEFAULT 0,
	amount_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	allocated_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	retail_amount DECIMAL(18,4) NULL,
	retail_currency VARCHAR(3) NULL,
	sale_date DATE NULL,
	status VARCHAR(20) NOT NULL DEFAULT 'aprobada',
	source VARCHAR(20) NOT NULL DEFAULT 'admin',
	stock_applied TINYINT(1) NOT NULL DEFAULT 0,
	order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	order_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	sale_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	note VARCHAR(255) NULL,
	created_by BIGINT UNSIGNED NULL,
	approved_by BIGINT UNSIGNED NULL,
	approved_at DATETIME NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY consignee_id (consignee_id),
	KEY consignment_item_id (consignment_item_id),
	KEY status (status),
	KEY sale_date (sale_date),
	KEY order_id (order_id),
	KEY sale_id (sale_id)
) {$charset_collate};";

		/*
		 * Bitácora de la cuenta de un revendedor. Existe para que las correcciones sean visibles:
		 * si le cambiás el precio acordado de algo que ya tiene, o borrás una venta suya, se tiene
		 * que enterar sin tener que preguntarte. `visible_to_consignee` deja anotar movimientos
		 * internos sin mostrárselos.
		 */
		$sql[] = "CREATE TABLE {$cons_log} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	consignee_id BIGINT UNSIGNED NOT NULL,
	kind VARCHAR(30) NOT NULL DEFAULT '',
	action VARCHAR(30) NOT NULL DEFAULT '',
	object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	summary VARCHAR(255) NOT NULL DEFAULT '',
	detail TEXT NULL,
	amount_base DECIMAL(18,4) NULL,
	reason VARCHAR(255) NULL,
	visible_to_consignee TINYINT(1) NOT NULL DEFAULT 1,
	actor_id BIGINT UNSIGNED NULL,
	actor_name VARCHAR(190) NULL,
	source VARCHAR(20) NOT NULL DEFAULT 'admin',
	created_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY consignee_id (consignee_id),
	KEY created_at (created_at)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$cons_returns} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	consignee_id BIGINT UNSIGNED NOT NULL,
	consignment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	consignment_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	qty DECIMAL(18,4) NOT NULL DEFAULT 0,
	return_date DATE NULL,
	note VARCHAR(255) NULL,
	created_by BIGINT UNSIGNED NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY consignee_id (consignee_id),
	KEY consignment_item_id (consignment_item_id)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$cons_pays} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	consignee_id BIGINT UNSIGNED NOT NULL,
	kind VARCHAR(20) NOT NULL DEFAULT 'pago',
	payment_date DATE NULL,
	amount DECIMAL(18,4) NOT NULL DEFAULT 0,
	currency VARCHAR(3) NOT NULL DEFAULT 'ARS',
	rate DECIMAL(18,6) NOT NULL DEFAULT 1,
	amount_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	allocated_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	method VARCHAR(60) NULL,
	cashbox_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	reference VARCHAR(190) NULL,
	reason VARCHAR(255) NULL,
	note VARCHAR(255) NULL,
	status VARCHAR(20) NOT NULL DEFAULT 'confirmado',
	source VARCHAR(20) NOT NULL DEFAULT 'admin',
	sale_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	created_by BIGINT UNSIGNED NULL,
	confirmed_by BIGINT UNSIGNED NULL,
	confirmed_at DATETIME NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY consignee_id (consignee_id),
	KEY kind (kind),
	KEY payment_date (payment_date),
	KEY status (status)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$cons_allocs} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	payment_id BIGINT UNSIGNED NOT NULL,
	sale_id BIGINT UNSIGNED NOT NULL,
	consignee_id BIGINT UNSIGNED NOT NULL,
	amount_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	is_manual TINYINT(1) NOT NULL DEFAULT 0,
	reason VARCHAR(255) NULL,
	created_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY payment_id (payment_id),
	KEY sale_id (sale_id),
	KEY consignee_id (consignee_id)
) {$charset_collate};";

		/*
		 * Sales module.
		 *
		 * A sale is the ERP's view of something that left the shop, no matter where it was rung up:
		 * the WooCommerce order is still the source of truth for the money the customer paid
		 * (`order_id`), and this table adds what Woo does not know — landed cost at the time of the
		 * sale, which lines came out of a reseller's consignment, and what was actually collected.
		 *
		 * Amounts live twice: in the currency the sale was rung up in (`total`) and in the base
		 * currency (`total_base`), frozen with the rate of the day so a moving exchange rate never
		 * rewrites a margin that was already earned.
		 */

		$sql[] = "CREATE TABLE {$sales} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	code VARCHAR(60) NOT NULL,
	order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	order_number VARCHAR(60) NULL,
	order_status VARCHAR(30) NULL,
	source VARCHAR(20) NOT NULL DEFAULT 'erp',
	channel VARCHAR(60) NULL,
	status VARCHAR(20) NOT NULL DEFAULT 'confirmada',
	sale_date DATE NULL,
	customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	customer_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	customer_name VARCHAR(190) NULL,
	customer_email VARCHAR(190) NULL,
	customer_phone VARCHAR(60) NULL,
	consignee_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	price_list_key VARCHAR(60) NULL,
	currency VARCHAR(3) NOT NULL DEFAULT 'ARS',
	rate DECIMAL(18,6) NOT NULL DEFAULT 1,
	items_count INT NOT NULL DEFAULT 0,
	qty_total DECIMAL(18,4) NOT NULL DEFAULT 0,
	subtotal DECIMAL(18,4) NOT NULL DEFAULT 0,
	discount_total DECIMAL(18,4) NOT NULL DEFAULT 0,
	shipping_total DECIMAL(18,4) NOT NULL DEFAULT 0,
	tax_total DECIMAL(18,4) NOT NULL DEFAULT 0,
	total DECIMAL(18,4) NOT NULL DEFAULT 0,
	total_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	cost_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	profit_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	consignment_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	consignment_total DECIMAL(18,4) NOT NULL DEFAULT 0,
	consignment_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
	paid_total DECIMAL(18,4) NOT NULL DEFAULT 0,
	paid_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	payment_status VARCHAR(20) NOT NULL DEFAULT 'impaga',
	payment_method VARCHAR(60) NULL,
	cashbox_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	wants_order TINYINT(1) NOT NULL DEFAULT 1,
	delivery_method VARCHAR(60) NULL,
	delivery_status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
	delivery_note VARCHAR(190) NULL,
	delivered_at DATETIME NULL,
	delivered_by BIGINT UNSIGNED NULL,
	stock_applied TINYINT(1) NOT NULL DEFAULT 0,
	note TEXT NULL,
	created_by BIGINT UNSIGNED NULL,
	voided_by BIGINT UNSIGNED NULL,
	voided_at DATETIME NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code),
	KEY order_id (order_id),
	KEY source (source),
	KEY status (status),
	KEY sale_date (sale_date),
	KEY consignee_id (consignee_id),
	KEY customer_id (customer_id),
	KEY customer_user_id (customer_user_id),
	KEY payment_status (payment_status),
	KEY delivery_status (delivery_status)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$sale_items} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	sale_id BIGINT UNSIGNED NOT NULL,
	order_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	name VARCHAR(255) NOT NULL,
	sku VARCHAR(100) NULL,
	qty DECIMAL(18,4) NOT NULL DEFAULT 0,
	unit_price DECIMAL(18,6) NOT NULL DEFAULT 0,
	discount DECIMAL(18,4) NOT NULL DEFAULT 0,
	line_total DECIMAL(18,4) NOT NULL DEFAULT 0,
	line_total_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	unit_cost_base DECIMAL(18,6) NOT NULL DEFAULT 0,
	cost_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	origin VARCHAR(20) NOT NULL DEFAULT 'deposito',
	consignee_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	consignment_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	consignment_sale_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	consignment_price_base DECIMAL(18,6) NOT NULL DEFAULT 0,
	sort_order INT NOT NULL DEFAULT 0,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY sale_id (sale_id),
	KEY product (product_id,variation_id),
	KEY origin (origin),
	KEY consignment_sale_id (consignment_sale_id)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$sale_pays} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	sale_id BIGINT UNSIGNED NOT NULL,
	payment_date DATE NULL,
	amount DECIMAL(18,4) NOT NULL DEFAULT 0,
	currency VARCHAR(3) NOT NULL DEFAULT 'ARS',
	rate DECIMAL(18,6) NOT NULL DEFAULT 1,
	amount_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	method VARCHAR(60) NULL,
	cashbox_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	reference VARCHAR(190) NULL,
	note VARCHAR(255) NULL,
	status VARCHAR(20) NOT NULL DEFAULT 'confirmado',
	source VARCHAR(20) NOT NULL DEFAULT 'admin',
	created_by BIGINT UNSIGNED NULL,
	confirmed_by BIGINT UNSIGNED NULL,
	confirmed_at DATETIME NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY sale_id (sale_id),
	KEY payment_date (payment_date),
	KEY status (status)
) {$charset_collate};";

		/*
		 * Historial de cada venta: quién la cargó, cobró, entregó, editó o anuló, y cuándo. Se
		 * escribe solo desde los hooks del módulo de ventas (MJERP_Sale_Log), así ningún camino que
		 * cambie una venta se lo puede saltear.
		 */
		$sql[] = "CREATE TABLE {$sale_log} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	sale_id BIGINT UNSIGNED NOT NULL,
	action VARCHAR(30) NOT NULL DEFAULT '',
	summary VARCHAR(255) NOT NULL DEFAULT '',
	detail LONGTEXT NULL,
	reason VARCHAR(255) NULL,
	actor_id BIGINT UNSIGNED NULL,
	actor_name VARCHAR(190) NULL,
	created_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY sale_id (sale_id),
	KEY created_at (created_at)
) {$charset_collate};";

		/*
		 * Saldo a favor de los clientes: un libro de movimientos con signo, en la moneda de cada uno.
		 * El saldo es la suma por moneda y no se guarda en ningún otro lado (MJERP_Customer_Credit_Repo).
		 */
		$sql[] = "CREATE TABLE {$credit} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	customer_id BIGINT UNSIGNED NOT NULL,
	currency VARCHAR(3) NOT NULL DEFAULT 'ARS',
	amount DECIMAL(18,4) NOT NULL DEFAULT 0,
	kind VARCHAR(20) NOT NULL DEFAULT '',
	sale_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	payment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	return_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	reverses_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	rate DECIMAL(18,6) NOT NULL DEFAULT 0,
	method VARCHAR(60) NULL,
	cashbox_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	reference VARCHAR(190) NULL,
	reason VARCHAR(255) NULL,
	note VARCHAR(255) NULL,
	created_by BIGINT UNSIGNED NULL,
	created_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY customer_id (customer_id),
	KEY sale_id (sale_id),
	KEY order_id (order_id),
	KEY payment_id (payment_id),
	KEY return_id (return_id),
	KEY reverses_id (reverses_id)
) {$charset_collate};";

		/*
		 * Devoluciones, cambios y garantías de ventas cobradas (MJERP_Returns): qué volvió, a dónde fue
		 * cada unidad, qué se llevó a cambio y qué se hizo con la plata.
		 */
		$sql[] = "CREATE TABLE {$returns} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	code VARCHAR(60) NOT NULL,
	sale_id BIGINT UNSIGNED NOT NULL,
	customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	return_date DATE NULL,
	kind VARCHAR(20) NOT NULL DEFAULT 'devolucion',
	currency VARCHAR(3) NOT NULL DEFAULT 'ARS',
	returned_value DECIMAL(18,4) NOT NULL DEFAULT 0,
	new_value DECIMAL(18,4) NOT NULL DEFAULT 0,
	total_before DECIMAL(18,4) NOT NULL DEFAULT 0,
	total_after DECIMAL(18,4) NOT NULL DEFAULT 0,
	paid_before DECIMAL(18,4) NOT NULL DEFAULT 0,
	excess DECIMAL(18,4) NOT NULL DEFAULT 0,
	due_after DECIMAL(18,4) NOT NULL DEFAULT 0,
	settlement VARCHAR(20) NOT NULL DEFAULT 'ninguno',
	settlement_detail LONGTEXT NULL,
	refund_method VARCHAR(60) NULL,
	refund_cashbox_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	refund_reference VARCHAR(190) NULL,
	reason VARCHAR(255) NULL,
	created_by BIGINT UNSIGNED NULL,
	created_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code),
	KEY sale_id (sale_id),
	KEY customer_id (customer_id)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$ret_items} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	return_id BIGINT UNSIGNED NOT NULL,
	sale_id BIGINT UNSIGNED NOT NULL,
	sale_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	type VARCHAR(20) NOT NULL DEFAULT 'devuelto',
	replaced TINYINT(1) NOT NULL DEFAULT 0,
	product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	name VARCHAR(255) NOT NULL DEFAULT '',
	sku VARCHAR(100) NULL,
	qty DECIMAL(18,4) NOT NULL DEFAULT 0,
	unit_value DECIMAL(18,6) NOT NULL DEFAULT 0,
	line_value DECIMAL(18,4) NOT NULL DEFAULT 0,
	unit_cost_base DECIMAL(18,6) NOT NULL DEFAULT 0,
	destination VARCHAR(20) NULL,
	problem VARCHAR(255) NULL,
	rma_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY return_id (return_id),
	KEY sale_id (sale_id)
) {$charset_collate};";

		/*
		 * RMA: lo que volvió fallado y espera el reclamo al proveedor. Su costo es pérdida por
		 * garantías recién cuando se da de baja.
		 */
		$sql[] = "CREATE TABLE {$rma} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	code VARCHAR(60) NOT NULL,
	status VARCHAR(20) NOT NULL DEFAULT 'abierto',
	sale_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	return_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	return_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	name VARCHAR(255) NOT NULL DEFAULT '',
	sku VARCHAR(100) NULL,
	qty DECIMAL(18,4) NOT NULL DEFAULT 0,
	unit_cost_base DECIMAL(18,6) NOT NULL DEFAULT 0,
	cost_base DECIMAL(18,4) NOT NULL DEFAULT 0,
	supplier_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	supplier_name VARCHAR(190) NULL,
	problem VARCHAR(255) NULL,
	note TEXT NULL,
	resolution_note VARCHAR(255) NULL,
	resolved_at DATETIME NULL,
	resolved_by BIGINT UNSIGNED NULL,
	created_by BIGINT UNSIGNED NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY code (code),
	KEY status (status),
	KEY sale_id (sale_id),
	KEY supplier_id (supplier_id),
	KEY product (product_id,variation_id)
) {$charset_collate};";

		$report = array();
		foreach ( $sql as $statement ) {
			$result = dbDelta( $statement );
			if ( is_array( $result ) ) {
				$report = array_merge( $report, $result );
			}
		}
		return $report;
	}

	/**
	 * Whether every plugin table exists.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;
		foreach ( self::table_names() as $name ) {
			$table = self::table( $name );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $found !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Drops every plugin table (used by uninstall when the user opted in).
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;
		foreach ( self::table_names() as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Removes `manage_mjerp` from every role and grants it to the allowed roles (always administrator).
	 *
	 * @param array|null $roles Role keys; null = settings `allowed_roles`.
	 * @return void
	 */
	public static function sync_capabilities( $roles = null ) {
		self::load_dependencies();

		$wp_roles = wp_roles();
		if ( ! $wp_roles instanceof WP_Roles ) {
			return;
		}

		$allowed = MJERP_Capabilities::allowed_roles( $roles );
		$cap     = MJERP_Capabilities::CAP;

		foreach ( array_keys( $wp_roles->roles ) as $role_key ) {
			$role = $wp_roles->get_role( $role_key );
			if ( ! $role ) {
				continue;
			}
			if ( in_array( $role_key, $allowed, true ) ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			} elseif ( $role->has_cap( $cap ) ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Removes `manage_mjerp` from every role.
	 *
	 * @return void
	 */
	public static function remove_capabilities() {
		self::load_dependencies();

		$wp_roles = wp_roles();
		if ( ! $wp_roles instanceof WP_Roles ) {
			return;
		}
		foreach ( array_keys( $wp_roles->roles ) as $role_key ) {
			$role = $wp_roles->get_role( $role_key );
			if ( $role && $role->has_cap( MJERP_Capabilities::CAP ) ) {
				$role->remove_cap( MJERP_Capabilities::CAP );
			}
		}
	}
}
