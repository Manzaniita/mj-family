<?php
/**
 * Standalone app route: rewrite rule, query var, template_redirect, app config and rendering.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MJERP_Frontend
 */
class MJERP_Frontend {

	/**
	 * Query var that identifies an app request.
	 */
	const QUERY_VAR = 'mjerp_app';

	/**
	 * Hooks everything.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ), 10 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );

		// Acceso rápido desde la tienda (temas de la familia MJ).
		add_filter( 'mj_nav_audiences', array( __CLASS__, 'nav_audience' ) );
		add_filter( 'mj_nav_sources', array( __CLASS__, 'nav_source' ) );
	}

	/**
	 * Adds the "can use the ERP" audience to the store's navigation bar.
	 *
	 * The theme only knows whether somebody is logged in; who may open the ERP is the plugin's
	 * business. Declaring it as an audience is what lets the shortcut be shown to the shop owner
	 * and to nobody else: the theme drops the item server-side, so a customer's browser never
	 * receives the link at all.
	 *
	 * @param array $audiences Audiences declared so far.
	 * @return array
	 */
	public static function nav_audience( $audiences ) {
		if ( ! is_array( $audiences ) ) {
			$audiences = array();
		}
		$audiences['erp'] = array(
			'label' => __( 'Solo quien puede entrar al ERP', 'mj-erp' ),
			'can'   => array( 'MJERP_Capabilities', 'current_user_can' ),
		);
		return $audiences;
	}

	/**
	 * Offers the ERP as an item of the store's navigation bar.
	 *
	 * It is a proposal, not a decision: the shop owner chooses in Apariencia → MJ Appearance →
	 * Barra de navegación whether it shows, what it is called, where it sits and who sees it.
	 *
	 * @param array $sources Items declared so far.
	 * @return array
	 */
	public static function nav_source( $sources ) {
		if ( ! is_array( $sources ) ) {
			$sources = array();
		}

		$sources['mjerp'] = array(
			'label'     => class_exists( 'MJERP_Settings' ) ? (string) MJERP_Settings::get( 'app_title', 'MJ ERP' ) : 'MJ ERP',
			'href'      => self::app_url(),
			'icon'      => 'layout-dashboard',
			'audience'  => 'erp',
			'placement' => 'header',
			'group'     => 'MJ ERP',
			'default'   => true,
			'note'      => __( 'Atajo al ERP desde la tienda. Solo lo ve quien tiene permiso para entrar.', 'mj-erp' ),
		);

		return $sources;
	}

	/**
	 * Route slug from settings (sanitized, never empty).
	 *
	 * @return string
	 */
	public static function slug() {
		$slug = class_exists( 'MJERP_Settings' ) ? (string) MJERP_Settings::get( 'route_slug', 'mjerp' ) : 'mjerp';
		$slug = trim( sanitize_title( $slug ), '/-' );
		return '' === $slug ? 'mjerp' : $slug;
	}

	/**
	 * Registers the rewrite rule `^<slug>/?$` → `index.php?mjerp_app=1`.
	 *
	 * @return void
	 */
	public static function register_rewrite() {
		add_rewrite_rule( '^' . preg_quote( self::slug(), '#' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Flushes rewrite rules once when the `mjerp_flush_rewrite` flag is set.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite() {
		if ( ! get_option( 'mjerp_flush_rewrite' ) ) {
			return;
		}
		delete_option( 'mjerp_flush_rewrite' );
		flush_rewrite_rules( false );
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
	 * Fallback URL that works without pretty permalinks.
	 *
	 * @return string
	 */
	public static function fallback_url() {
		return home_url( '/?' . self::QUERY_VAR . '=1' );
	}

	/**
	 * Public URL of the app.
	 *
	 * @return string
	 */
	public static function app_url() {
		if ( get_option( 'permalink_structure' ) ) {
			$url = home_url( '/' . self::slug() . '/' );
		} else {
			$url = self::fallback_url();
		}

		/**
		 * Filters the app URL.
		 *
		 * @param string $url URL.
		 */
		return (string) apply_filters( 'mjerp_app_url', $url );
	}

	/**
	 * Whether the current request targets the app (query var or ?mjerp_app=1 fallback).
	 *
	 * @return bool
	 */
	public static function is_app_request() {
		$value = get_query_var( self::QUERY_VAR, '' );
		if ( '' === $value || null === $value ) {
			$value = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$value = is_scalar( $value ) ? (string) $value : '';
		return '' !== $value && '0' !== $value && 'false' !== strtolower( $value );
	}

	/**
	 * Renders the app (or redirects/403) on app requests.
	 *
	 * @return void
	 */
	public static function maybe_render() {
		if ( ! self::is_app_request() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
			// The ERP is an authenticated back-office UI: never let another site frame it.
			header( 'X-Frame-Options: SAMEORIGIN', true );
			header( 'Content-Security-Policy: frame-ancestors \'self\'', true );
			header( 'Referrer-Policy: strict-origin-when-cross-origin', true );
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::app_url() ) );
			exit;
		}

		if ( ! MJERP_Capabilities::current_user_can() ) {
			/*
			 * Un revendedor no tiene nada que hacer en el ERP, pero sí tiene su propia pantalla.
			 * Mandarlo ahí es la respuesta correcta: el 403 lo dejaba en una puerta cerrada sin
			 * decirle a dónde ir, y es exactamente lo que pasa cuando le pasás el link del ERP
			 * en vez del suyo.
			 */
			$portal = self::portal_url_for_current_user();
			if ( '' !== $portal ) {
				wp_safe_redirect( $portal );
				exit;
			}
			self::render_403();
			exit;
		}

		show_admin_bar( false );
		self::render_app();
		exit;
	}

	/**
	 * Outputs the app HTML shell.
	 *
	 * @return void
	 */
	public static function render_app() {
		global $wp_query;

		if ( $wp_query instanceof WP_Query ) {
			$wp_query->is_404 = false;
		}
		status_header( 200 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		}

		$config = self::app_config();

		/**
		 * Filters the template path of the app shell.
		 *
		 * @param string $template Absolute path.
		 */
		$template = apply_filters( 'mjerp_app_template', MJERP_DIR . 'templates/app.php' );
		if ( ! is_string( $template ) || ! file_exists( $template ) ) {
			$template = MJERP_DIR . 'templates/app.php';
		}

		include $template;
	}

	/**
	 * Configuration injected as `window.MJERP`.
	 *
	 * @return array
	 */
	public static function app_config() {
		$user   = wp_get_current_user();
		$avatar = $user->ID ? get_avatar_url( $user->ID, array( 'size' => 64 ) ) : '';

		$config = array(
			'version'           => MJERP_VERSION,
			'restUrl'           => esc_url_raw( rest_url( 'mjerp/v1/' ) ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'appUrl'            => self::app_url(),
			'homeUrl'           => home_url( '/' ),
			'adminUrl'          => admin_url(),
			'logoutUrl'         => wp_logout_url( home_url( '/' ) ),
			'loginUrl'          => wp_login_url( self::app_url() ),
			'pluginUrl'         => MJERP_URL,
			'user'              => array(
				'id'     => (int) $user->ID,
				'name'   => (string) ( '' !== trim( (string) $user->display_name ) ? $user->display_name : $user->user_login ),
				'email'  => (string) $user->user_email,
				'avatar' => is_string( $avatar ) ? $avatar : '',
			),
			'settings'          => MJERP_Settings::all(),
			'statuses'          => MJERP_Settings::statuses(),
			'wcCurrency'        => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'wcVersion'         => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'roles'             => self::roles(),
			'permalinksEnabled' => (bool) get_option( 'permalink_structure' ),
			'today'             => current_time( 'Y-m-d' ),
			'timezone'          => wp_timezone_string(),
		);

		/**
		 * Filters the JS configuration of the app.
		 *
		 * @param array $config Config.
		 */
		$filtered = apply_filters( 'mjerp_app_config', $config );
		return is_array( $filtered ) ? $filtered : $config;
	}

	/**
	 * Editable roles as `[ {key,label} ]`.
	 *
	 * @return array
	 */
	public static function roles() {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
		if ( empty( $roles ) || ! is_array( $roles ) ) {
			$roles = wp_roles()->roles;
		}

		$out = array();
		foreach ( $roles as $key => $role ) {
			$name  = isset( $role['name'] ) ? (string) $role['name'] : (string) $key;
			$out[] = array(
				'key'   => (string) $key,
				'label' => translate_user_role( $name ),
			);
		}
		if ( ! in_array( 'administrator', wp_list_pluck( $out, 'key' ), true ) ) {
			array_unshift(
				$out,
				array(
					'key'   => 'administrator',
					'label' => translate_user_role( 'Administrator' ),
				)
			);
		}
		return $out;
	}

	/**
	 * Portal URL when the logged-in user is an active reseller, '' otherwise.
	 *
	 * @return string
	 */
	public static function portal_url_for_current_user() {
		if ( ! class_exists( 'MJERP_Portal' ) || ! MJERP_Portal::is_enabled() ) {
			return '';
		}
		/*
		 * Alcanza con que tenga el rol de reventa: si además su ficha está desactivada, el propio
		 * portal se lo explica con todas las letras ("tu cuenta de revendedor está desactivada"),
		 * que es mucho más útil que un 403 hablándole de una capacidad de WordPress.
		 */
		if ( ! MJERP_Capabilities::current_user_can_portal() ) {
			return '';
		}
		return MJERP_Portal::url();
	}

	/**
	 * Minimal 403 page for logged-in users without the capability.
	 *
	 * @return void
	 */
	public static function render_403() {
		status_header( 403 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		}

		$user       = wp_get_current_user();
		$title      = class_exists( 'MJERP_Settings' ) ? (string) MJERP_Settings::get( 'app_title', 'MJ ERP' ) : 'MJ ERP';
		$theme      = class_exists( 'MJERP_Settings' ) ? (string) MJERP_Settings::get( 'theme', 'dark' ) : 'dark';
		$is_light   = ( 'light' === $theme );
		$bg         = $is_light ? '#f4f5f7' : '#0f1115';
		$card       = $is_light ? '#ffffff' : '#171a21';
		$border     = $is_light ? '#e2e5ea' : '#262a33';
		$text       = $is_light ? '#1a1d24' : '#e7e9ee';
		$muted      = $is_light ? '#5f6572' : '#9aa1ad';
		$home_url   = home_url( '/' );
		$admin_url  = admin_url();
		$logout_url = wp_logout_url( self::app_url() );
		$user_name  = $user->ID ? ( '' !== trim( (string) $user->display_name ) ? $user->display_name : $user->user_login ) : '';
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?> · <?php esc_html_e( 'Acceso denegado', 'mj-erp' ); ?></title>
<style>
	html,body{margin:0;padding:0;background:<?php echo esc_html( $bg ); ?>;color:<?php echo esc_html( $text ); ?>;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;min-height:100vh}
	.mj-403{max-width:480px;margin:12vh auto;padding:32px;background:<?php echo esc_html( $card ); ?>;border:1px solid <?php echo esc_html( $border ); ?>;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.15)}
	.mj-403 h1{margin:0 0 8px;font-size:22px}
	.mj-403 p{margin:0 0 12px;color:<?php echo esc_html( $muted ); ?>}
	.mj-403 a{display:inline-block;margin:6px 8px 0 0;padding:9px 14px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;font-weight:600}
	.mj-403 a.secondary{background:transparent;border:1px solid <?php echo esc_html( $border ); ?>;color:<?php echo esc_html( $text ); ?>}
</style>
</head>
<body>
<div class="mj-403">
	<h1><?php esc_html_e( 'No tenés permiso para acceder al ERP', 'mj-erp' ); ?></h1>
	<p>
		<?php
		if ( '' !== $user_name ) {
			/* translators: %s: user display name */
			echo esc_html( sprintf( __( 'Estás conectado como %s.', 'mj-erp' ), $user_name ) );
			echo ' ';
		}
		esc_html_e( 'Tu usuario no tiene la capacidad "manage_mjerp". Pedile a un administrador que habilite tu rol desde la configuración del ERP.', 'mj-erp' );
		?>
	</p>
	<p>
		<a href="<?php echo esc_url( $home_url ); ?>"><?php esc_html_e( 'Ir a la tienda', 'mj-erp' ); ?></a>
		<a class="secondary" href="<?php echo esc_url( $admin_url ); ?>"><?php esc_html_e( 'Ir al panel de WordPress', 'mj-erp' ); ?></a>
		<a class="secondary" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Cambiar de usuario', 'mj-erp' ); ?></a>
	</p>
</div>
</body>
</html>
		<?php
	}
}
