<?php
/**
 * Textos y contenidos de ultralujo para MJ ProSkin.
 *
 * Centraliza las propuestas de texto para la tienda, las ventajas exclusivas,
 * las preguntas frecuentes VIP y la narrativa de marca.
 */

defined('ABSPATH') || exit;

/**
 * Textos predefinidos de MJ ProSkin.
 *
 * @param string $key Clave solicitada o vacío para todo el catálogo.
 * @return mixed
 */
function mj_proskin_text($key = '') {
	static $texts = null;
	if (null === $texts) {
		$texts = [
			// Ventajas exclusivas y garantías VIP
			'advantages' => [
				[
					'icon'        => 'shield-check',
					'badge'       => '100% CERTIFICADO',
					'title'       => 'Autenticidad Garantizada',
					'description' => 'Cada pieza pasa por un riguroso control de calidad y cuenta con sello holográfico de autenticidad.',
				],
				[
					'icon'        => 'truck',
					'badge'       => 'BLINDADO EXPRESS',
					'title'       => 'Envíos con Prioridad VIP',
					'description' => 'Entregas ultra rápidas a todo el país con seguimiento en tiempo real y seguro de transporte.',
				],
				[
					'icon'        => 'package',
					'badge'       => 'UNBOXING ÚNICO',
					'title'       => 'Packaging de Colección',
					'description' => 'Presentación premium en caja rígida magnética, lazo de seda y tarjeta de bienvenida exclusiva.',
				],
				[
					'icon'        => 'sparkles',
					'badge'       => 'CONCIERGE 24/7',
					'title'       => 'Atención Personalizada',
					'description' => 'Un asesor de estilo dedicado para resolver cualquier consulta por WhatsApp con trato de alta costura.',
				],
			],

			// Preguntas frecuentes VIP
			'faq' => [
				[
					'¿Cómo garantizan la autenticidad y calidad de los productos?',
					'Todas nuestras piezas son 100% originales, importadas o diseñadas bajo los estándares más exigentes del mercado. Cada pedido incluye su certificado de autenticidad y número de serie único.',
				],
				[
					'¿Cuáles son los tiempos de entrega para envíos VIP?',
					'Despachamos en menos de 24 horas hábiles. Para CABA y GBA contás con opción de entrega rápida en el día (Same Day) o 24 hs. Para el resto del país, entre 48 y 72 hs por correo privado con seguimiento prioritario.',
				],
				[
					'¿Qué medios de pago y financiación aceptan?',
					'Podés abonar con tarjeta de crédito en hasta 3, 6 y 12 cuotas sin interés, tarjeta de débito, transferencia bancaria con un 15% de descuento especial acumulable, y saldo en Mercado Pago.',
				],
				[
					'¿Cómo funciona el Club VIP ProSkin?',
					'Al realizar tu primera compra o registrarte en la tienda, ingresás automáticamente a nuestro Club VIP. Tenés acceso a lanzamientos secretos 48 horas antes, descuentos exclusivos y obsequios en fechas especiales.',
				],
				[
					'¿Cómo gestionar un cambio o devolución de cortesía?',
					'Tenés 30 días corridos para solicitar un cambio sin complicaciones. Ponete en contacto con nuestro equipo de atención Concierge y retiramos el producto por tu domicilio sin costo.',
				],
			],

			// Manifiesto / Sobre Nosotros
			'about' => "## La revolución del lujo contemporáneo\n\nNacimos con una premisa audaz: que comprar online no tiene por qué ser aburrido, genérico ni frío. Creemos que la exclusividad no es solo un precio, sino una vivencia completa: la sorpresa visual de cada detalle, la calidad que se siente al tacto y el placer de tener algo extraordinario.\n\n## Pasión por el diseño y la innovación\n\nSeleccionamos y curamos piezas que combinan vanguardia tecnológica, estética futurista y materiales de primera línea. Cada lanzamiento es una declaración de intenciones: no seguimos tendencias pasajeras, las creamos.\n\n## Compromiso con la excelencia\n\nDesde el primer clic en la tienda hasta que abrís la caja de tu pedido, cada segundo está pensado para sorprenderte. Nuestro equipo Concierge está siempre a tu lado para que disfrutes de una atención cálida, inmediata y a tu medida.",

			// Políticas de servicio VIP
			'policies' => "## 1. Envíos y entregas seguras\nTodos los paquetes se despachan en cajas reforzadas con precinto de seguridad holográfico. Si el paquete llegara con algún indicio de manipulación, no lo recibas y te enviamos uno nuevo en el acto.\n\n## 2. Garantía total de satisfacción\nSi por cualquier motivo el producto no supera tus expectativas, podés solicitar el cambio o la devolución total de tu dinero dentro de los primeros 10 días de recibido.\n\n## 3. Privacidad y datos blindados\nTus datos personales y de pago están cifrados bajo protocolo SSL de 256 bits y nunca se comparten con terceros.",
		];
	}

	if ('' === $key) {
		return $texts;
	}

	return isset($texts[$key]) ? $texts[$key] : null;
}

/**
 * Genera el comentario de apertura/cierre de un bloque MJ para Gutenberg.
 *
 * @param string $name  Nombre del bloque sin el prefijo 'mj/'.
 * @param array  $attrs Atributos del bloque.
 * @return string
 */
function mj_proskin_block($name, array $attrs = []) {
	$json = !empty($attrs) ? ' ' . wp_json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
	return '<!-- wp:mj/' . $name . $json . ' /-->' . "\n\n";
}

/**
 * Convierte un texto con títulos '## ' y párrafos en bloques estándar de Gutenberg.
 *
 * @param string $text Texto original.
 * @return string
 */
function mj_proskin_prose($text) {
	$out = '';
	foreach (preg_split("/\n\s*\n/", trim((string) $text)) as $chunk) {
		$chunk = trim($chunk);
		if (0 === strpos($chunk, '## ')) {
			$out .= '<!-- wp:heading {"level":2,"className":"proskin-gradient-heading"} --><h2 class="wp-block-heading proskin-gradient-heading">' . esc_html(substr($chunk, 3)) . '</h2><!-- /wp:heading -->' . "\n\n";
		} elseif ('' !== $chunk) {
			$out .= '<!-- wp:paragraph {"className":"proskin-luxury-paragraph"} --><p class="proskin-luxury-paragraph">' . nl2br(esc_html($chunk)) . '</p><!-- /wp:paragraph -->' . "\n\n";
		}
	}
	return $out;
}
