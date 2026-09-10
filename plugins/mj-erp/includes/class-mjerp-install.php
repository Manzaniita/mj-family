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
	KEY customer_user_id (customer_user_id),
	KEY payment_status (payment_status)
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
