<?php
/**
 * Plugin singleton: init sequence, WooCommerce dependency check, HPOS compatibility, admin integration.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Plugin
 */
final class MJERP_Plugin {

	/**
	 * Admin page slug (redirects to the standalone app).
	 */
	const ADMIN_PAGE = 'mjerp';

	/**
	 * Singleton instance.
	 *
	 * @var MJERP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether the plugin fully booted (WooCommerce present and every file loaded).
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Include files that could not be found (relative to MJERP_DIR).
	 *
	 * @var string[]
	 */
	private $missing_files = array();

	/**
	 * Returns the singleton.
	 *
	 * @return MJERP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Include files in dependency order (relative to MJERP_DIR).
	 *
	 * @return string[]
	 */
	public static function files() {
		return array(
			'includes/class-mjerp-capabilities.php',
			'includes/class-mjerp-helpers.php',
			'includes/class-mjerp-settings.php',
			'includes/class-mjerp-permissions.php',
			'includes/class-mjerp-install.php',
			'includes/class-mjerp-costing.php',
			'includes/class-mjerp-dolar.php',
			'includes/repositories/class-mjerp-purchases-repo.php',
			'includes/repositories/class-mjerp-suppliers-repo.php',
			'includes/repositories/class-mjerp-cashboxes-repo.php',
			'includes/repositories/class-mjerp-movements-repo.php',
			'includes/class-mjerp-products.php',
			'includes/class-mjerp-customers.php',
			'includes/class-mjerp-stock.php',
			'includes/class-mjerp-consignees.php',
			'includes/repositories/class-mjerp-consignments-repo.php',
			'includes/repositories/class-mjerp-consignment-sales-repo.php',
			'includes/repositories/class-mjerp-consignment-payments-repo.php',
			'includes/class-mjerp-consignment-account.php',
			'includes/repositories/class-mjerp-sales-repo.php',
			'includes/repositories/class-mjerp-sale-payments-repo.php',
			'includes/class-mjerp-sales.php',
			'includes/class-mjerp-orders.php',
			'includes/class-mjerp-pricing.php',
			'includes/class-mjerp-frontend.php',
			'includes/class-mjerp-portal.php',
			'includes/class-mjerp-receipts.php',
			'includes/rest/class-mjerp-rest-controller.php',
			'includes/rest/class-mjerp-rest-bootstrap.php',
			'includes/rest/class-mjerp-rest-purchases.php',
			'includes/rest/class-mjerp-rest-suppliers.php',
			'includes/rest/class-mjerp-rest-products.php',
			'includes/rest/class-mjerp-rest-settings.php',
			'includes/rest/class-mjerp-rest-consignments.php',
			'includes/rest/class-mjerp-rest-sales.php',
			'includes/rest/class-mjerp-rest-portal.php',
			'includes/rest/class-mjerp-rest.php',
		);
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether the plugin fully booted.
	 *
	 * @return bool
	 */
	public function is_booted() {
		return $this->booted;
	}

	/**
	 * Constructor: boots the plugin.
	 */
	private function __construct() {
		// WP 6.7+ expects translations to load on/after `init` (avoids early wp_get_current_user() calls).
		if ( did_action( 'init' ) ) {
			$this->load_textdomain();
		} else {
			add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		}

		// Harmless when WooCommerce is absent; declared early so it always runs on `before_woocommerce_init`.
		add_action( 'before_woocommerce_init', array( $this, 'declare_wc_compatibility' ) );

		if ( ! self::is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			add_action( 'network_admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		if ( ! $this->includes() ) {
			add_action( 'admin_notices', array( $this, 'missing_files_notice' ) );
			return;
		}

		// Registered before anything reads the settings: the bridge injects the `dolar` block and
		// the MJ Dólar exchange rate into them, and MJERP_Settings caches the merged result.
		MJERP_Dolar::init();

		MJERP_Install::maybe_upgrade();
		MJERP_Consignees::init();
		MJERP_Consignment_Account::init();
		MJERP_Orders::init();
		MJERP_Pricing::init();
		MJERP_Frontend::init();
		MJERP_Portal::init();
		MJERP_Receipts::init();
		MJERP_REST::init();

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_app' ) );
		add_filter( 'plugin_action_links_' . MJERP_BASENAME, array( $this, 'plugin_action_links' ) );

		$this->booted = true;

		/**
		 * Fires once the plugin finished booting.
		 *
		 * @param MJERP_Plugin $plugin Plugin instance.
		 */
		do_action( 'mjerp_loaded', $this );
	}

	/**
	 * Prevents cloning.
	 */
	private function __clone() {}

	/**
	 * Loads the text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'mj-erp', false, dirname( MJERP_BASENAME ) . '/languages' );
	}

	/**
	 * Requires every include file. Returns false (and records the missing ones) when any is absent.
	 *
	 * @return bool
	 */
	private function includes() {
		$this->missing_files = array();
		foreach ( self::files() as $file ) {
			if ( ! file_exists( MJERP_DIR . $file ) ) {
				$this->missing_files[] = $file;
			}
		}
		if ( ! empty( $this->missing_files ) ) {
			return false;
		}
		foreach ( self::files() as $file ) {
			require_once MJERP_DIR . $file;
		}
		return true;
	}

	/**
	 * Declares HPOS (custom order tables) compatibility.
	 *
	 * @return void
	 */
	public function declare_wc_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MJERP_FILE, true );
		}
	}

	/**
	 * Admin notice when WooCommerce is not active.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$install_url = admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'MJ ERP', 'mj-erp' ) . ':</strong> ';
		echo esc_html__( 'este plugin necesita WooCommerce activo para funcionar. Instalá y activá WooCommerce para usar el ERP.', 'mj-erp' );
		echo ' <a href="' . esc_url( $install_url ) . '">' . esc_html__( 'Instalar WooCommerce', 'mj-erp' ) . '</a></p></div>';
	}

	/**
	 * Admin notice when plugin files are missing (broken upload).
	 *
	 * @return void
	 */
	public function missing_files_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'MJ ERP', 'mj-erp' ) . ':</strong> ';
		echo esc_html__( 'la instalación está incompleta, faltan archivos del plugin. Volvé a subir el paquete completo.', 'mj-erp' );
		echo '</p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $this->missing_files as $file ) {
			echo '<li><code>' . esc_html( $file ) . '</code></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Adds the top-level "MJ ERP" admin menu.
	 *
	 * @return void
	 */
	public function admin_menu() {
		add_menu_page(
			__( 'MJ ERP', 'mj-erp' ),
			__( 'MJ ERP', 'mj-erp' ),
			MJERP_Capabilities::CAP,
			self::ADMIN_PAGE,
			array( $this, 'render_admin_page' ),
			'dashicons-store',
			56
		);
	}

	/**
	 * Redirects wp-admin/admin.php?page=mjerp to the standalone app.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_app() {
		if ( wp_doing_ajax() || ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::ADMIN_PAGE !== $page ) {
			return;
		}
		if ( ! MJERP_Capabilities::current_user_can() ) {
			return;
		}
		wp_safe_redirect( MJERP_Frontend::app_url() );
		exit;
	}

	/**
	 * Fallback admin page (only visible if the redirect could not happen).
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! MJERP_Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'No tenés permiso para acceder a esta página.', 'mj-erp' ), 403 );
		}
		$url = MJERP_Frontend::app_url();
		echo '<div class="wrap"><h1>' . esc_html__( 'MJ ERP', 'mj-erp' ) . '</h1>';
		echo '<p>' . esc_html__( 'El ERP se abre en una pantalla independiente, fuera del panel de WordPress.', 'mj-erp' ) . '</p>';
		echo '<p><a class="button button-primary button-hero" href="' . esc_url( $url ) . '">' . esc_html__( 'Abrir ERP', 'mj-erp' ) . '</a></p>';
		echo '<p><code>' . esc_html( $url ) . '</code></p></div>';
	}

	/**
	 * Adds "Abrir ERP" and "Configuración" links to the plugin row.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$app_url = MJERP_Frontend::app_url();
		$custom  = array(
			'mjerp_open'     => '<a href="' . esc_url( $app_url ) . '">' . esc_html__( 'Abrir ERP', 'mj-erp' ) . '</a>',
			'mjerp_settings' => '<a href="' . esc_url( $app_url . '#/configuracion' ) . '">' . esc_html__( 'Configuración', 'mj-erp' ) . '</a>',
		);
		return array_merge( $custom, $links );
	}
}
