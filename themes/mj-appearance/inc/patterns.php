<?php
/**
 * Patrones de bloques nativos de Gutenberg para Cabecera y Pie de página.
 */
defined('ABSPATH') || exit;

add_action('init', function () {
	if (!function_exists('register_block_pattern_category') || !function_exists('register_block_pattern')) {
		return;
	}

	register_block_pattern_category('mj-header', [
		'label' => 'Cabeceras MJ',
	]);

	register_block_pattern_category('mj-footer', [
		'label' => 'Pies de página MJ',
	]);

	// Patrón 1: Cabecera completa con flexbox nativo
	$header_content = file_get_contents(get_template_directory() . '/parts/header.html');
	if (false !== $header_content) {
		register_block_pattern('mj-appearance/header-full', [
			'title'       => 'Cabecera completa MJ',
			'description' => 'Estructura flexible con anuncios, barra de envío gratis, logo, buscador, mega menú y accesos rápidos.',
			'categories'  => ['mj-header', 'header'],
			'blockTypes'  => ['core/template-part/header'],
			'content'     => $header_content,
		]);
	}

	// Patrón 2: Cabecera minimalista en una sola fila
	register_block_pattern('mj-appearance/header-minimal', [
		'title'       => 'Cabecera en línea minimalista',
		'description' => 'Fila compacta con logo a la izquierda, menú inline centrado y carrito/cuenta a la derecha.',
		'categories'  => ['mj-header', 'header'],
		'blockTypes'  => ['core/template-part/header'],
		'content'     => '<!-- wp:group {"align":"full","className":"mj-header-bar-group","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull mj-header-bar-group"><!-- wp:group {"align":"wide","className":"mj-header-flex-row","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} --><div class="wp-block-group alignwide mj-header-flex-row"><!-- wp:mj/logo /--><!-- wp:mj/nav {"style":"inline"} /--><!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","alignItems":"center"}} --><div class="wp-block-group"><!-- wp:mj/dolar /--><!-- wp:mj/tema-toggle /--><!-- wp:mj/cuenta /--><!-- wp:mj/carrito /--></div><!-- /wp:group --></div><!-- /wp:group --></div><!-- /wp:group -->',
	]);

	// Patrón 3: Pie de página completo
	$footer_content = file_get_contents(get_template_directory() . '/parts/footer.html');
	if (false !== $footer_content) {
		register_block_pattern('mj-appearance/footer-full', [
			'title'       => 'Pie de página completo MJ',
			'description' => 'Pie de página con newsletter, 3 columnas (marca, enlaces y contacto) y barra legal con sellos de pago.',
			'categories'  => ['mj-footer', 'footer'],
			'blockTypes'  => ['core/template-part/footer'],
			'content'     => $footer_content,
		]);
	}

	// Patrón 4: Pie de página minimalista
	register_block_pattern('mj-appearance/footer-minimal', [
		'title'       => 'Pie de página minimalista',
		'description' => 'Pie de página compacto con logo de marca, enlaces horizontales y copyright.',
		'categories'  => ['mj-footer', 'footer'],
		'blockTypes'  => ['core/template-part/footer'],
		'content'     => '<!-- wp:group {"align":"full","className":"mj-footer-main-group","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull mj-footer-main-group"><!-- wp:group {"align":"wide","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} --><div class="wp-block-group alignwide"><!-- wp:mj/footer-brand {"showSocials":true} /--><!-- wp:mj/footer-legal /--></div><!-- /wp:group --></div><!-- /wp:group -->',
	]);
});
