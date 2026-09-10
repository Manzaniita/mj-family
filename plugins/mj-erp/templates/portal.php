<?php
/**
 * HTML shell of the consignee portal.
 *
 * Included by MJERP_Portal::render() with `$config` (the `window.MJPORTAL` payload) and
 * `$consignee` in scope. Deliberately loads only portal.js: the ERP bundle has nothing a reseller
 * should be able to reach, not even as dead code in their browser.
 *
 * @package MJ_ERP
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $config ) || ! is_array( $config ) ) {
	$config = array();
}

$mjp_theme       = ( isset( $config['theme'] ) && 'light' === $config['theme'] ) ? 'light' : 'dark';
$mjp_title       = ! empty( $config['title'] ) ? (string) $config['title'] : 'Mi cuenta';
$mjp_site        = ! empty( $config['siteName'] ) ? (string) $config['siteName'] : '';
$mjp_plugin_url  = ! empty( $config['pluginUrl'] ) ? (string) $config['pluginUrl'] : ( defined( 'MJERP_URL' ) ? MJERP_URL : '' );
$mjp_version     = ! empty( $config['version'] ) ? (string) $config['version'] : ( defined( 'MJERP_VERSION' ) ? MJERP_VERSION : '1.0.0' );
$mjp_charset     = get_bloginfo( 'charset' );
$mjp_locale      = ! empty( $config['locale'] ) ? (string) $config['locale'] : 'es-AR';
$mjp_theme_color = ( 'light' === $mjp_theme ) ? '#f4f5f7' : '#0f1115';

$mjp_favicon = 'data:image/svg+xml,' . rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#16a34a"/><text x="32" y="41" font-family="Arial,Helvetica,sans-serif" font-size="26" font-weight="700" fill="#ffffff" text-anchor="middle">MJ</text></svg>' );
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?php echo esc_attr( $mjp_theme ); ?>" data-locale="<?php echo esc_attr( $mjp_locale ); ?>">
<head>
<meta charset="<?php echo esc_attr( $mjp_charset ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?php echo esc_attr( $mjp_theme_color ); ?>">
<meta name="format-detection" content="telephone=no">
<title><?php echo esc_html( $mjp_title . ( '' !== $mjp_site ? ' · ' . $mjp_site : '' ) ); ?></title>
<link rel="icon" href="<?php echo esc_attr( $mjp_favicon ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', $mjp_version, $mjp_plugin_url . 'assets/css/app.css' ) ); ?>">
<?php
/*
 * Comprobantes de pago: los maneja MJ Medios de Pago y esta plantilla no pasa
 * por wp_head(), así que se piden explícitamente. Sin ese plugin no imprime nada.
 */
if ( class_exists( 'MJPM_Receipts_Front' ) ) {
	MJPM_Receipts_Front::print_styles();
}
?>
<script>window.MJPORTAL = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE ); ?>;</script>
</head>
<body class="mj-body mj-body--portal">
<div id="mjerp-portal"><div class="mj-boot"><?php esc_html_e( 'Cargando…', 'mj-erp' ); ?></div></div>
<noscript>
	<div class="mj-boot" style="padding:24px;text-align:center"><?php esc_html_e( 'Esta pantalla necesita JavaScript habilitado en el navegador.', 'mj-erp' ); ?></div>
</noscript>
<?php
if ( class_exists( 'MJPM_Receipts_Front' ) ) {
	MJPM_Receipts_Front::print_scripts();
}
?>
<script src="<?php echo esc_url( add_query_arg( 'ver', $mjp_version, $mjp_plugin_url . 'assets/js/portal.js' ) ); ?>"></script>
</body>
</html>
