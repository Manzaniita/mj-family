<?php
/**
 * Asistente de contenido inicial para MJ ProSkin.
 *
 * Crea las páginas de arranque armadas con los bloques y patrones de ultralujo
 * de ProSkin como BORRADOR (nada se publica solo).
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function () {
	if (class_exists('MJ_Menu')) {
		add_submenu_page(
			MJ_Menu::slug(),
			'Contenido ProSkin',
			'ProSkin · Contenido inicial',
			'manage_options',
			'mj-proskin-contenido',
			'mj_proskin_render_content_page'
		);
		return;
	}
	add_theme_page(
		'Contenido ProSkin',
		'Contenido inicial (ProSkin)',
		'manage_options',
		'mj-proskin-contenido',
		'mj_proskin_render_content_page'
	);
});

/**
 * Renderiza la pantalla del asistente de contenido inicial en wp-admin.
 */
function mj_proskin_render_content_page() {
	if (!current_user_can('manage_options')) {
		return;
	}
	$done = isset($_GET['cargado']) ? sanitize_text_field(wp_unslash($_GET['cargado'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- solo feedback visual.
	?>
	<div class="wrap proskin-admin-wrap" style="max-width:900px; margin-top:20px;">
		<h1 style="display:flex; align-items:center; gap:12px; font-size:26px;">
			<span style="display:inline-block; width:14px; height:14px; border-radius:50%; background:#7c3aed; box-shadow:0 0 12px #7c3aed;"></span>
			MJ ProSkin — Contenido inicial de ultralujo
		</h1>
		<div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px; margin-top:16px; box-shadow:0 4px 16px rgba(0,0,0,0.04);">
			<p style="font-size:15px; line-height:1.6; color:#334155; margin-top:0;">
				Crea las páginas clave de tu tienda con la estética interactiva y futurista de <strong>MJ ProSkin</strong> directamente en el editor de bloques de WordPress (Gutenberg), <strong>guardadas como borrador</strong> para que puedas editarlas, cambiar fotos, textos y publicar a tu propio ritmo.
			</p>

			<?php if ('' !== $done) : ?>
				<div class="notice notice-success inline" style="margin:16px 0; border-radius:8px;">
					<p><strong>¡Excelente!</strong> Se crearon las siguientes páginas: <?php echo esc_html($done); ?>. Ya podés verlas y editarlas en <a href="<?php echo esc_url(admin_url('edit.php?post_type=page')); ?>">Páginas</a>.</p>
				</div>
			<?php endif; ?>

			<h3 style="margin-top:20px; font-size:16px; color:#0f172a;">Páginas que se crearán como borrador:</h3>
			<ul style="list-style:none; padding:0; margin:12px 0 24px; display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
				<li style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">
					<strong>✨ Portada de Ultralujo</strong>
					<p style="margin:4px 0 0; font-size:12px; color:#64748b;">Hero holográfico, vitrina interactiva, beneficios VIP y membresía.</p>
				</li>
				<li style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">
					<strong>💎 Experiencia & Nosotros</strong>
					<p style="margin:4px 0 0; font-size:12px; color:#64748b;">Historia de marca, pilares de exclusividad y cifras de excelencia.</p>
				</li>
				<li style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">
					<strong>🛡️ Preguntas Frecuentes VIP</strong>
					<p style="margin:4px 0 0; font-size:12px; color:#64748b;">Acordeón con dudas sobre autenticidad, envíos, cuotas y garantías.</p>
				</li>
				<li style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">
					<strong>🛎️ Concierge & Contacto</strong>
					<p style="margin:4px 0 0; font-size:12px; color:#64748b;">Canales prioritarios, WhatsApp directo y formulario de atención VIP.</p>
				</li>
			</ul>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="mj_proskin_cargar_contenido" />
				<?php wp_nonce_field('mj_proskin_cargar_contenido'); ?>
				<button type="submit" class="button button-primary button-hero" style="background:#7c3aed; border-color:#6d28d9; text-shadow:none; box-shadow:0 4px 14px rgba(124,58,237,0.35);">
					Generar páginas de inicio como borrador
				</button>
			</form>
		</div>
	</div>
	<?php
}

/**
 * Verifica si ya existe una página con determinado slug o título.
 *
 * @param string $slug Slug o ruta esperada.
 * @return bool
 */
function mj_proskin_page_exists($slug) {
	$posts = get_posts([
		'post_type'      => 'page',
		'name'           => sanitize_title($slug),
		'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
		'posts_per_page' => 1,
		'fields'         => 'ids',
	]);
	return !empty($posts);
}

add_action('admin_post_mj_proskin_cargar_contenido', function () {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('No tenés permisos para realizar esta acción.', 'mj-proskin'));
	}
	check_admin_referer('mj_proskin_cargar_contenido');

	$created = [];

	// 1. Portada ProSkin
	if (!mj_proskin_page_exists('portada-proskin') && !mj_proskin_page_exists('inicio')) {
		$patterns = function_exists('mj_proskin_patterns') ? mj_proskin_patterns() : [];
		$content  = isset($patterns['proskin-portada']['content']) ? $patterns['proskin-portada']['content'] : '';
		$id       = wp_insert_post([
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Inicio · Experiencia ProSkin',
			'post_name'    => 'inicio',
			'post_content' => $content,
		]);
		if (!is_wp_error($id) && $id > 0) {
			$created[] = 'Inicio';
		}
	}

	// 2. Sobre Nosotros / Manifiesto
	if (!mj_proskin_page_exists('nosotros')) {
		$content = mj_proskin_block('encabezado', [
			'eyebrow'  => 'Manifiesto de Lujo',
			'title'    => 'Diseño sin concesiones',
			'subtitle' => 'Nuestra historia, visión y el compromiso innegociable con la calidad extrema.',
		])
		. mj_proskin_block('nosotros', [
			'layout'    => 'full',
			'eyebrow'   => 'Identidad de Marca',
			'title'     => 'Vanguardia, innovación y exclusividad',
			'linkLabel' => 'Explorar Colección',
		])
		. mj_proskin_prose((string) mj_proskin_text('about'));

		$id = wp_insert_post([
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Nosotros',
			'post_name'    => 'nosotros',
			'post_content' => $content,
		]);
		if (!is_wp_error($id) && $id > 0) {
			$created[] = 'Nosotros';
		}
	}

	// 3. Preguntas frecuentes VIP
	if (!mj_proskin_page_exists('faq')) {
		$faq_items = [];
		foreach ((array) mj_proskin_text('faq') as $item) {
			if (is_array($item) && count($item) >= 2) {
				$faq_items[] = ['q' => $item[0], 'a' => $item[1]];
			}
		}
		$content = mj_proskin_block('encabezado', [
			'eyebrow'  => 'Soporte y Asistencia VIP',
			'title'    => 'Preguntas Frecuentes',
			'subtitle' => 'Todo lo que necesitás saber sobre autenticidad, envíos de alta seguridad y compras en nuestra tienda.',
		])
		. mj_proskin_block('faq', [
			'items'           => $faq_items,
			'showContactCard' => true,
		]);

		$id = wp_insert_post([
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Preguntas frecuentes',
			'post_name'    => 'faq',
			'post_content' => $content,
		]);
		if (!is_wp_error($id) && $id > 0) {
			$created[] = 'Preguntas frecuentes';
		}
	}

	// 4. Concierge & Contacto
	if (!mj_proskin_page_exists('contacto')) {
		$content = mj_proskin_block('encabezado', [
			'eyebrow'  => 'Atención Personalizada',
			'title'    => 'Concierge & Contacto',
			'subtitle' => 'Nuestro equipo de asesores está disponible para asistirte con cada detalle.',
		])
		. mj_proskin_block('contacto', [
			'showForm'     => true,
			'showChannels' => true,
			'showMap'      => false,
		]);

		$id = wp_insert_post([
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Contacto',
			'post_name'    => 'contacto',
			'post_content' => $content,
		]);
		if (!is_wp_error($id) && $id > 0) {
			$created[] = 'Contacto';
		}
	}

	$msg = !empty($created) ? implode(', ', $created) : 'las páginas ya estaban creadas';
	wp_safe_redirect(add_query_arg('cargado', rawurlencode($msg), admin_url('admin.php?page=mj-proskin-contenido')));
	exit;
});
