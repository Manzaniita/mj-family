<?php
/**
 * Textos de arranque de Jaz Beauty Art: políticas, preguntas frecuentes,
 * "Sobre mí", servicios y productos.
 *
 * Son PROPUESTAS que se usan una sola vez: para armar las plantillas de
 * páginas y para la carga del contenido inicial (inc/contenido.php). Una vez
 * cargados, se editan desde WordPress (páginas, Turnos → Servicios, Productos)
 * y estos textos no vuelven a tocar nada. Filtro: mj_jazbeauty_text.
 */

defined('ABSPATH') || exit;

function mj_jazbeauty_text($key) {
	$texts = [
		'booking_policy' => "La seña es del 50 % del servicio y se transfiere a Mercado Pago.\n"
			. "Cuando elegís el horario, queda reservado para vos durante 1 hora: en ese tiempo transferí y subí el comprobante.\n"
			. 'Podés reprogramar o cancelar desde tu turno con 48 horas de anticipación.',

		'policies' => "Estas políticas existen para cuidar tu turno y el de todas mis clientas. Las escribí para que sean claras: cualquier duda, escribime por Instagram.\n\n"
			. "## Seña del 50 %\n\n"
			. "Para reservar un turno se deja una seña del 50 % del valor del servicio, por transferencia a Mercado Pago. El resto se abona el día del turno. La página te muestra el monto exacto de la seña antes de confirmar.\n\n"
			. "## Una hora para enviar el comprobante\n\n"
			. "Cuando elegís el horario, queda reservado para vos durante 1 hora. En ese tiempo hacé la transferencia y subí el comprobante desde la página de tu turno. Si pasa la hora y no llegó el comprobante, el horario se libera para que lo pueda tomar otra persona. Cuando lo reviso, te llega la confirmación.\n\n"
			. "## Reprogramaciones\n\n"
			. "Si necesitás cambiar el día o el horario, lo hacés vos misma desde tu turno. Con 48 horas o más de anticipación, reprogramás y tu seña se mantiene. Con menos de 48 horas también podés elegir otro día, pero la seña no se conserva y vas a tener que abonar una nueva.\n\n"
			. "## Cancelaciones\n\n"
			. "La seña no se devuelve. Si cancelás con 48 horas o más de anticipación, la seña queda como saldo a favor en tu cuenta para tu próximo turno. Con menos de 48 horas, la seña se pierde.\n\n"
			. "## Tolerancia de 15 minutos\n\n"
			. "Te espero hasta 15 minutos después del horario del turno. Pasado ese tiempo el turno se cancela y la seña no se devuelve, porque no llegaría a hacer el trabajo como corresponde.\n\n"
			. "## Inasistencias\n\n"
			. "Si no venís al turno sin avisar, la seña se pierde.\n\n"
			. "## Si cancelo yo\n\n"
			. "Si por algún motivo tengo que cancelar o mover tu turno, te aviso y elegís qué preferís: otro día y horario conservando tu seña, o la devolución de la seña.\n\n"
			. "## Formas de pago\n\n"
			. "La seña y los productos se pagan por transferencia a Mercado Pago. Los datos para transferir aparecen al reservar o al comprar. El resto del servicio lo podés abonar el día del turno.\n\n"
			. "## Compras de productos\n\n"
			. 'Los productos del Beauty Shop se pagan por transferencia y se confirman cuando reviso el comprobante. No hago envíos: los retirás en el lugar donde atiendo o coordinamos un punto de entrega. Cuando tu compra esté lista para retirar, te aviso.',

		'about' => "Soy Jaz, y Jaz Beauty Art es mi espacio: un lugar pensado para que te sientas cómoda y salgas con una mirada que te encante.\n\n"
			. "## Mi emprendimiento\n\n"
			. "Me especializo en pestañas y cejas. Trabajo con turnos, con tiempo para cada clienta y con productos de calidad, porque creo que los detalles hacen la diferencia.\n\n"
			. "## Mi formación\n\n"
			. 'Contá acá tus cursos, tus capacitaciones y todo lo que aprendiste y vas a seguir aprendiendo en el mundo de la belleza.',

		'faq' => [
			['¿Cuánto dura cada tratamiento?', 'Cada servicio tiene su duración en la página de Servicios y al reservar. Un lash lifting dura alrededor de una hora; las extensiones, un poco más.'],
			['¿Cómo tengo que ir al turno?', 'Vení sin maquillaje en los ojos y, si usás lentes de contacto, traé su estuche para sacártelos durante el tratamiento.'],
			['¿Cuánto tengo que pagar de seña?', 'La seña es el 50 % del servicio. La página te muestra el monto exacto antes de confirmar la reserva.'],
			['¿Cómo puedo reprogramar?', 'Desde tu turno (en Mi cuenta → Mis turnos o con el link del mail). Con 48 horas o más de anticipación, tu seña se mantiene.'],
			['¿Qué pasa si llego tarde?', 'Hay 15 minutos de tolerancia. Pasado ese tiempo el turno se cancela y la seña no se devuelve.'],
			['¿Qué pasa si no voy?', 'Si no venís sin avisar, la seña se pierde.'],
			['¿Cómo compro un producto?', 'Elegís el producto en el Beauty Shop, transferís a Mercado Pago y subís el comprobante. Cuando lo reviso, tu compra queda confirmada.'],
			['¿Dónde retiro mi producto?', 'No hago envíos: lo retirás en el lugar donde atiendo o coordinamos un punto de entrega. Te aviso cuando esté listo para retirar.'],
			['¿Qué medios de pago aceptan?', 'Transferencia a Mercado Pago para la seña y los productos. El resto del servicio lo podés abonar el día del turno.'],
			['¿Cuándo debería volver a realizarme el tratamiento?', 'Depende del tratamiento: en cada servicio figura el mantenimiento recomendado. Unos días antes te llega un mensaje para que reserves tu próximo turno.'],
		],

		/* Servicios: [nombre, categoría, frase corta]. Nacen en borrador y sin precio: Jaz completa precio, duración, foto y mantenimiento. */
		'categories' => [['Pestañas', 1], ['Cejas', 2], ['Combos', 3]],
		'services'   => [
			['Lash Lifting Tradicional', 'Pestañas', 'Levantamiento y curvado natural de pestañas.'],
			['Lash Lifting Coreano / Avanzado', 'Pestañas', 'Técnica especializada para un levantamiento con mayor precisión, cuidado del pelo y resultados más duraderos.'],
			['Tinte / Nutrición de Pestañas (Botox Lash)', 'Pestañas', 'Complemento de hidratación y color tras el lifting.'],
			['Extensiones de Pestañas Clásicas (Pelo a Pelo)', 'Pestañas', 'Aplicación individual para un efecto natural pero definido.'],
			['Extensiones de Pestañas Efecto Rímel / Volumen Suave', 'Pestañas', 'Técnica de abanicos o volumen inicial para mayor densidad.'],
			['Mantenimiento y Retiro Profesional de Extensiones', 'Pestañas', 'Mantenimiento de tus extensiones o retiro profesional, cuidando tu pestaña natural.'],
			['Perfilado y Depilación de Cejas', 'Cejas', 'Diseño y limpieza según la forma del rostro.'],
			['Visagismo de Cejas', 'Cejas', 'Estudio morfológico y simetría personalizada para enmarcar la mirada.'],
			['Laminado de Cejas (Brow Lamination)', 'Cejas', 'Peinado, fijación y alisado para dar un aspecto más tupido y ordenado.'],
			['Tinte / Henna para Cejas', 'Cejas', 'Coloración temporal para rellenar espacios y dar definición.'],
			['Combo Look Natural', 'Combos', 'Lash Lifting Tradicional + Perfilado y Visagismo de Cejas.'],
			['Combo Full Mirada', 'Combos', 'Lash Lifting Avanzado (Coreano) + Laminado y Perfilado de Cejas.'],
			['Combo Glamour', 'Combos', 'Extensiones de Pestañas Clásicas + Diseño y Perfilado de Cejas.'],
		],

		/* Productos: [nombre, descripción corta]. En borrador y sin precio. */
		'products'   => [
			['Aceite de almendras Jaz Beauty Art', 'El aceite de almendras de mi marca, para nutrir pestañas y cejas.'],
			['Puluk ToxTox Lash Volumen', 'Tratamiento de volumen para pestañas.'],
			['Puluk ToxTox Lash Vital', 'Tratamiento nutritivo y fortalecedor de pestañas.'],
			['Promoter', 'Promoter para el crecimiento de pestañas y cejas.'],
		],
	];
	$value = $texts[$key] ?? '';
	return apply_filters('mj_jazbeauty_text', $value, $key);
}
