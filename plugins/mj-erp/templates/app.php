<?php
/**
 * Standalone HTML shell of the MJ ERP app.
 *
 * Included by MJERP_Frontend::render_app() with `$config` (the `window.MJERP` payload) in scope.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $config ) || ! is_array( $config ) ) {
	$config = class_exists( 'MJERP_Frontend' ) ? MJERP_Frontend::app_config() : array();
}

$mjerp_settings   = ( isset( $config['settings'] ) && is_array( $config['settings'] ) ) ? $config['settings'] : array();
$mjerp_theme      = ( isset( $mjerp_settings['theme'] ) && 'light' === $mjerp_settings['theme'] ) ? 'light' : 'dark';
$mjerp_title      = ( isset( $mjerp_settings['app_title'] ) && '' !== trim( (string) $mjerp_settings['app_title'] ) ) ? (string) $mjerp_settings['app_title'] : 'MJ ERP';
$mjerp_plugin_url = ! empty( $config['pluginUrl'] ) ? (string) $config['pluginUrl'] : ( defined( 'MJERP_URL' ) ? MJERP_URL : '' );
$mjerp_version    = ! empty( $config['version'] ) ? (string) $config['version'] : ( defined( 'MJERP_VERSION' ) ? MJERP_VERSION : '1.0.0' );
$mjerp_dir        = defined( 'MJERP_DIR' ) ? MJERP_DIR : trailingslashit( dirname( __DIR__ ) );
$mjerp_charset    = get_bloginfo( 'charset' );
$mjerp_locale     = ( isset( $mjerp_settings['locale'] ) && '' !== (string) $mjerp_settings['locale'] ) ? (string) $mjerp_settings['locale'] : 'es-AR';
$mjerp_theme_color = ( 'light' === $mjerp_theme ) ? '#f4f5f7' : '#0f1115';

/**
 * Scripts in load order. calc.js is optional (the costing engine may live inside core.js).
 */
$mjerp_scripts = array(
	'core.js',
	'calc.js',
	'components.js',
	'components-consignment.js',
	'page-dashboard.js',
	'page-purchases.js',
	'page-purchase.js',
	'page-suppliers.js',
	'page-products.js',
	'page-consignees.js',
	'page-consignee.js',
	'page-consignment.js',
	'page-settings.js',
	'page-soon.js',
	'app.js',
);
$mjerp_optional_scripts = array( 'calc.js' );

$mjerp_favicon = 'data:image/svg+xml,' . rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#2563eb"/><text x="32" y="41" font-family="Arial,Helvetica,sans-serif" font-size="26" font-weight="700" fill="#ffffff" text-anchor="middle">MJ</text></svg>' );
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?php echo esc_attr( $mjerp_theme ); ?>" data-locale="<?php echo esc_attr( $mjerp_locale ); ?>">
<head>
<meta charset="<?php echo esc_attr( $mjerp_charset ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?php echo esc_attr( $mjerp_theme_color ); ?>">
<meta name="format-detection" content="telephone=no">
<title><?php echo esc_html( $mjerp_title ); ?></title>
<link rel="icon" href="<?php echo esc_attr( $mjerp_favicon ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', $mjerp_version, $mjerp_plugin_url . 'assets/css/app.css' ) ); ?>">
<script>window.MJERP = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE ); ?>;</script>
</head>
<body class="mj-body">
<div id="mjerp-app"><div class="mj-boot"><?php esc_html_e( 'Cargando…', 'mj-erp' ); ?></div></div>
<noscript>
	<div class="mj-boot" style="padding:24px;text-align:center"><?php esc_html_e( 'MJ ERP necesita JavaScript habilitado en el navegador.', 'mj-erp' ); ?></div>
</noscript>
<?php
foreach ( $mjerp_scripts as $mjerp_script ) {
	if ( in_array( $mjerp_script, $mjerp_optional_scripts, true ) && ! file_exists( $mjerp_dir . 'assets/js/' . $mjerp_script ) ) {
		continue;
	}
	$mjerp_src = add_query_arg( 'ver', $mjerp_version, $mjerp_plugin_url . 'assets/js/' . $mjerp_script );
	echo '<script src="' . esc_url( $mjerp_src ) . '"></script>' . "\n";
}
?>
</body>
</html>
