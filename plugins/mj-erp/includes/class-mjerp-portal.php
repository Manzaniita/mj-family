<?php
defined( 'ABSPATH' ) || exit;

/**
 * The reseller portal: a small standalone screen where a consignee sees what they hold, reports the
 * units they sold and checks what they owe.
 *
 * Kept deliberately apart from the ERP app: its own route, its own capability, its own tiny script
 * and its own REST namespace. A reseller must never reach the ERP, and the portal must never be
 * able to name a consignee — the account it shows is always resolved from the logged-in user.
 *
 * @package MJ_ERP
 */
class MJERP_Portal {

	/**
	 * Query var that identifies a portal request.
	 */
	const QUERY_VAR = 'mjerp_portal';

	/**
	 * Hooks everything.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ), 10 );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );

		// A reseller has no business in wp-admin; send them to their own screen instead.
		add_action( 'admin_init', array( __CLASS__, 'block_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
	}

	/**
	 * Whether the portal is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled() : bool {
		return (bool) MJERP_Helpers::bool( MJERP_Settings::get( 'portal_enabled', true ) );
	}

	/**
	 * Route slug from settings (sanitized, never empty, never the ERP's own slug).
	 *
	 * @return string
	 */
	public static function slug() : string {
		$slug = (string) MJERP_Settings::get( 'portal_slug', 'reventa' );
		$slug = trim( sanitize_title( $slug ), '/-' );
		if ( '' === $slug ) {
			$slug = 'reventa';
		}
		if ( class_exists( 'MJERP_Frontend' ) && $slug === MJERP_Frontend::slug() ) {
			$slug .= '-reventa';
		}
		return $slug;
	}

	/**
	 * Registers the rewrite rule.
	 *
	 * @return void
	 */
	public static function register_rewrite() {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_rewrite_rule( '^' . preg_quote( self::slug(), '#' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Registers the public query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		if ( ! is_array( $vars ) ) {
			$vars = array();
		}
		if ( ! in_array( self::QUERY_VAR, $vars, true ) ) {
			$vars[] = self::QUERY_VAR;
		}
		return $vars;
	}

	/**
	 * Public URL of the portal.
	 *
	 * @return string
	 */
	public static function url() : string {
		$url = get_option( 'permalink_structure' )
			? home_url( '/' . self::slug() . '/' )
			: home_url( '/?' . self::QUERY_VAR . '=1' );

		/**
		 * Filters the portal URL.
		 *
		 * @param string $url URL.
		 */
		return (string) apply_filters( 'mjerp_portal_url', $url );
	}

	/**
	 * Whether the current request targets the portal.
	 *
	 * @return bool
	 */
	public static function is_portal_request() : bool {
		$value = get_query_var( self::QUERY_VAR, '' );
		if ( '' === $value || null === $value ) {
			$value = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$value = is_scalar( $value ) ? (string) $value : '';
		return '' !== $value && '0' !== $value && 'false' !== strtolower( $value );
	}

	/**
	 * Renders the portal (or redirects / 403) on portal requests.
	 *
	 * @return void
	 */
	public static function maybe_render() {
		if ( ! self::is_portal_request() ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			return; // Let WordPress 404 the URL: the portal is off.
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'X-Frame-Options: SAMEORIGIN', true );
			header( 'Content-Security-Policy: frame-ancestors \'self\'', true );
			header( 'Referrer-Policy: strict-origin-when-cross-origin', true );
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}

		$consignee = MJERP_Consignees::current();
		if ( ! $consignee || ! MJERP_Capabilities::current_user_can_portal() ) {
			self::render_denied( $consignee );
			exit;
		}

		show_admin_bar( false );
		self::render( $consignee );
		exit;
	}

	/**
	 * Outputs the portal HTML shell.
	 *
	 * @param array $consignee Consignee row.
	 * @return void
	 */
	public static function render( array $consignee ) {
		global $wp_query;

		if ( $wp_query instanceof WP_Query ) {
			$wp_query->is_404 = false;
		}
		status_header( 200 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		}

		$config = self::config( $consignee );

		/**
		 * Filters the template path of the portal shell.
		 *
		 * @param string $template Absolute path.
		 */
		$template = apply_filters( 'mjerp_portal_template', MJERP_DIR . 'templates/portal.php' );
		if ( ! is_string( $template ) || ! file_exists( $template ) ) {
			$template = MJERP_DIR . 'templates/portal.php';
		}

		include $template;
	}

	/**
	 * Configuration injected as `window.MJPORTAL`.
	 *
	 * Carries no consignee id the client could tamper with: the REST layer resolves the account
	 * from the session on every call. What is here is for display only.
	 *
	 * @param array $consignee Consignee row.
	 * @return array
	 */
	public static function config( array $consignee ) : array {
		$user     = wp_get_current_user();
		$avatar   = $user->ID ? get_avatar_url( $user->ID, array( 'size' => 64 ) ) : '';
		$settings = MJERP_Settings::all();
		$user_id  = (int) $consignee['user_id'];

		/*
		 * What this reseller may do, already resolved through defaults → role → their own record.
		 * The two global portal switches are a ceiling: a feature turned off for everyone can never
		 * be turned back on per user.
		 */
		$can = array(
			'report_sale'            => MJERP_Helpers::bool( MJERP_Settings::get( 'portal_allow_sales', true ) )
				&& MJERP_Permissions::can( 'report_sale', $user_id, $consignee ),
			'returns'                => MJERP_Helpers::bool( MJERP_Settings::get( 'portal_allow_returns', false ) )
				&& MJERP_Permissions::can( 'returns', $user_id, $consignee ),
			'register_payment'       => MJERP_Permissions::can( 'register_payment', $user_id, $consignee ),
			'partial_payments'       => MJERP_Permissions::can( 'partial_payments', $user_id, $consignee ),
			'payment_needs_approval' => MJERP_Permissions::can( 'payment_needs_approval', $user_id, $consignee ),
			'see_account'            => MJERP_Permissions::can( 'see_account', $user_id, $consignee ),
		);

		$config = array(
			'version'      => MJERP_VERSION,
			'restUrl'      => esc_url_raw( rest_url( 'mjerp/v1/portal/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'portalUrl'    => self::url(),
			'homeUrl'      => home_url( '/' ),
			'logoutUrl'    => wp_logout_url( home_url( '/' ) ),
			'pluginUrl'    => MJERP_URL,
			'title'        => (string) MJERP_Settings::get( 'portal_title', 'Mi cuenta' ),
			'siteName'     => get_bloginfo( 'name' ),
			'theme'        => 'light' === (string) MJERP_Settings::get( 'theme', 'dark' ) ? 'light' : 'dark',
			'locale'       => (string) MJERP_Settings::get( 'locale', 'es-AR' ),
			'decimals'     => (int) MJERP_Settings::get( 'decimals', 2 ),
			'baseCurrency' => strtoupper( (string) MJERP_Settings::get( 'base_currency', 'USD' ) ),
			'localCurrency' => strtoupper( (string) MJERP_Settings::get( 'local_currency', 'ARS' ) ),
			'symbols'      => is_array( $settings['currency_symbols'] ) ? $settings['currency_symbols'] : array(),
			'rate'         => class_exists( 'MJERP_Dolar' ) ? MJERP_Dolar::rate() : 0.0,
			'canSell'      => $can['report_sale'],
			'canReturn'    => $can['returns'],
			'can'          => $can,
			'paymentMethods' => MJERP_Permissions::payment_methods( $user_id, $consignee ),
			'needsApproval' => MJERP_Consignment_Sales_Repo::STATUS_PENDING === MJERP_Consignment_Sales_Repo::initial_status( MJERP_Consignment_Sales_Repo::SOURCE_PORTAL ),
			'today'        => current_time( 'Y-m-d' ),
			'consignee'    => array(
				'name'   => (string) $consignee['name'],
				'avatar' => is_string( $avatar ) ? $avatar : '',
			),
		);

		/**
		 * Filters the JS configuration of the portal.
		 *
		 * @param array $config    Config.
		 * @param array $consignee Consignee row.
		 */
		$filtered = apply_filters( 'mjerp_portal_config', $config, $consignee );
		return is_array( $filtered ) ? $filtered : $config;
	}

	/**
	 * Page shown to a logged-in user who is not an active consignee.
	 *
	 * @param array|null $consignee Consignee row (null when there is none).
	 * @return void
	 */
	public static function render_denied( $consignee = null ) {
		status_header( 403 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		}

		$user     = wp_get_current_user();
		$title    = (string) MJERP_Settings::get( 'portal_title', 'Mi cuenta' );
		$name     = $user->ID ? ( '' !== trim( (string) $user->display_name ) ? $user->display_name : $user->user_login ) : '';
		$message  = ( $consignee && empty( $consignee['is_active'] ) )
			? __( 'Tu cuenta de revendedor está desactivada. Escribinos si creés que es un error.', 'mj-erp' )
			: __( 'Tu usuario todavía no está dado de alta como revendedor. Escribinos para que te habilitemos.', 'mj-erp' );
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?></title>
<style>
	html,body{margin:0;padding:0;background:#0f1115;color:#e7e9ee;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;min-height:100vh}
	.box{max-width:460px;margin:14vh auto;padding:30px;background:#171a21;border:1px solid #262a33;border-radius:14px}
	.box h1{margin:0 0 10px;font-size:20px}
	.box p{margin:0 0 14px;color:#9aa1ad}
	.box a{display:inline-block;margin:6px 8px 0 0;padding:9px 14px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;font-weight:600}
	.box a.ghost{background:transparent;border:1px solid #262a33;color:#e7e9ee}
</style>
</head>
<body>
<div class="box">
	<h1><?php echo esc_html( $title ); ?></h1>
	<p>
		<?php
		if ( '' !== $name ) {
			/* translators: %s: user display name */
			echo esc_html( sprintf( __( 'Estás conectado como %s.', 'mj-erp' ), $name ) );
			echo ' ';
		}
		echo esc_html( $message );
		?>
	</p>
	<p>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Ir a la tienda', 'mj-erp' ); ?></a>
		<a class="ghost" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Cambiar de usuario', 'mj-erp' ); ?></a>
	</p>
</div>
</body>
</html>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Reseller housekeeping
	 * ---------------------------------------------------------------- */

	/**
	 * Whether the current user is *only* a reseller (so wp-admin is not for them).
	 *
	 * @return bool
	 */
	public static function is_pure_consignee() : bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) || MJERP_Capabilities::current_user_can() ) {
			return false;
		}
		return MJERP_Capabilities::current_user_can_portal() && null !== MJERP_Consignees::current();
	}

	/**
	 * Redirects resellers away from wp-admin (AJAX excluded, WooCommerce needs it).
	 *
	 * @return void
	 */
	public static function block_admin() {
		if ( wp_doing_ajax() || ! self::is_pure_consignee() ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Hides the admin bar for resellers.
	 *
	 * @param bool $show Whether to show it.
	 * @return bool
	 */
	public static function hide_admin_bar( $show ) {
		return self::is_pure_consignee() ? false : $show;
	}
}
