<?php
/**
 * Opciones del plugin (mjto_options), helpers y página Turnos → Ajustes.
 *
 * Nada está hardcodeado: horarios, seña, textos públicos, plantillas de
 * mail y WhatsApp, campos del formulario, ficha técnica, métodos de pago y
 * el panel salen de acá. Un tema hijo propone otros defaults con el filtro
 * `mjto_option_defaults` y nunca pisa lo que la administradora guardó.
 *
 * La página de ajustes tiene siete pestañas; cada una es un <form> propio,
 * por eso la sanitización hace merge de lo enviado sobre lo guardado.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Defaults
 * ---------------------------------------------------------------------- */

/** Plantillas de mail y WhatsApp por defecto (con placeholders, ver mjto_placeholder_list()). */
function mjto_default_templates() {
	return [
		// Para la dueña
		'tpl_owner_new_subject'            => 'Nueva reserva: {nombre_completo} — {fecha} a las {hora}',
		'tpl_owner_new_body'               => "Hola:\n\nEntró una reserva nueva desde la web.\n\nClienta: {nombre_completo}\nTeléfono: {telefono}\nServicio: {servicio}\nCuándo: {fecha} a las {hora} ({duracion})\nPrecio: {precio}\nSeña: {sena}\nEstado: {estado}\n\nVer el turno: {link_turno}\n\n— {negocio}",
		'tpl_owner_cancel_subject'         => 'Turno cancelado: {nombre_completo} — {fecha} a las {hora}',
		'tpl_owner_cancel_body'            => "Hola:\n\n{nombre_completo} canceló su turno.\n\nServicio: {servicio}\nEra el {fecha} a las {hora}\nTeléfono: {telefono}\nMotivo: {motivo}\n\nEse horario quedó libre otra vez.\n\n— {negocio}",
		'tpl_owner_deposit_notice_subject' => '{nombre_completo} avisó que transfirió la seña',
		'tpl_owner_deposit_notice_body'    => "Hola:\n\n{nombre_completo} avisó desde la web que ya transfirió la seña de {sena}.\n\nServicio: {servicio}\nTurno: {fecha} a las {hora}\nTeléfono: {telefono}\n\nCuando veas la transferencia, confirmá el turno desde el panel: {link_turno}\n\n— {negocio}",
		// Para la clienta
		'tpl_client_received_subject'      => 'Recibimos tu reserva, {nombre}',
		'tpl_client_received_body'         => "Hola {nombre}:\n\nRecibimos tu reserva y en breve te la confirmamos.\n\nServicio: {servicio}\nCuándo: {fecha} a las {hora}\nDuración aproximada: {duracion}\nDónde: {direccion}\n\nPodés ver el estado de tu turno acá: {link_turno}\n\nSi necesitás cambiar algo, escribinos por WhatsApp: {whatsapp_negocio}\n\n¡Gracias!\n{negocio}",
		'tpl_client_deposit_subject'       => 'Tu reserva está casi lista, {nombre}: falta la seña',
		'tpl_client_deposit_body'          => "Hola {nombre}:\n\nRecibimos tu reserva. Para dejar el turno confirmado te pedimos una seña de {sena}.\n\nServicio: {servicio}\nCuándo: {fecha} a las {hora}\n\nCómo transferir:\n{instrucciones_sena}\n{link_pago}\n\nTenés tiempo hasta {fecha_limite}. Cuando transfieras, avisanos desde esta página: {link_turno}\n\nCualquier duda, escribinos por WhatsApp: {whatsapp_negocio}\n\n¡Gracias!\n{negocio}",
		'tpl_client_confirmed_subject'     => '¡Turno confirmado! {fecha} a las {hora}',
		'tpl_client_confirmed_body'        => "Hola {nombre}:\n\n¡Tu turno quedó confirmado! Te esperamos.\n\nServicio: {servicio}\nCuándo: {fecha} a las {hora}\nDuración aproximada: {duracion}\nDónde: {direccion}\n\nSi no podés venir, avisanos con tiempo así le damos el lugar a otra persona: {link_turno}\n\n¡Nos vemos!\n{negocio}",
		'tpl_client_reminder_subject'      => 'Recordatorio: tu turno del {fecha} a las {hora}',
		'tpl_client_reminder_body'         => "Hola {nombre}:\n\nTe recordamos tu turno de {servicio} el {fecha} a las {hora}.\n\nDónde: {direccion}\n\nSi necesitás cambiarlo, escribinos por WhatsApp: {whatsapp_negocio}\nEstado del turno: {link_turno}\n\n¡Te esperamos!\n{negocio}",
		'tpl_client_cancelled_subject'     => 'Tu turno del {fecha} fue cancelado',
		'tpl_client_cancelled_body'        => "Hola {nombre}:\n\nTu turno de {servicio} del {fecha} a las {hora} quedó cancelado.\nMotivo: {motivo}\n\nSi querés reservar otro día, escribinos por WhatsApp: {whatsapp_negocio}\n\nGracias,\n{negocio}",
		// WhatsApp (texto plano): los manda la dueña desde el panel
		'tpl_wa_confirm'                   => '¡Hola {nombre}! Soy de {negocio}. Tu turno de {servicio} quedó confirmado para el {fecha} a las {hora}. ¡Te espero!',
		'tpl_wa_deposit'                   => '¡Hola {nombre}! Te escribo de {negocio} por tu turno de {servicio} del {fecha} a las {hora}. Para confirmarlo falta la seña de {sena}. {instrucciones_sena} ¡Gracias!',
		'tpl_wa_reminder'                  => '¡Hola {nombre}! Te recuerdo tu turno de {servicio} en {negocio}: {fecha} a las {hora}. ¿Me confirmás que venís? ¡Gracias!',
		'tpl_wa_control'                   => '¡Hola {nombre}! ¿Cómo va tu pelo después del {servicio}? Te escribo de {negocio} para hacer el control. Cualquier duda, contame.',
		'tpl_wa_retouch'                   => '¡Hola {nombre}! Te escribo de {negocio}: ya pasaron unos meses desde tu {servicio} del {fecha} y es buen momento para pensar en el retoque. ¿Querés que te reserve un turno?',
		'tpl_wa_generic'                   => '¡Hola {nombre}! Te escribo de {negocio}.',
		// El que manda la CLIENTA desde la web con el comprobante
		'tpl_wa_client_deposit'            => 'Hola {negocio}, soy {nombre}. Te mando el comprobante de la seña del turno del {fecha} a las {hora}.',
	];
}

/** Campos del formulario público por defecto (los de sistema siempre existen). */
function mjto_default_form_fields() {
	return [
		['key' => 'first_name', 'label' => 'Nombre', 'placeholder' => '', 'type' => 'text', 'required' => true, 'enabled' => true, 'options' => [], 'system' => true],
		['key' => 'last_name', 'label' => 'Apellido', 'placeholder' => '', 'type' => 'text', 'required' => true, 'enabled' => true, 'options' => [], 'system' => true],
		['key' => 'phone', 'label' => 'Teléfono / WhatsApp', 'placeholder' => 'Ej: 11 5555 1234', 'type' => 'tel', 'required' => true, 'enabled' => true, 'options' => [], 'system' => true],
		['key' => 'email', 'label' => 'Email', 'placeholder' => 'Para mandarte la confirmación', 'type' => 'email', 'required' => false, 'enabled' => true, 'options' => [], 'system' => true],
		['key' => 'locality', 'label' => 'Localidad', 'placeholder' => '', 'type' => 'text', 'required' => true, 'enabled' => true, 'options' => [], 'system' => true],
		['key' => 'notes', 'label' => '¿Algo que quieras contarnos?', 'placeholder' => '', 'type' => 'textarea', 'required' => false, 'enabled' => true, 'options' => [], 'system' => true],
	];
}

/** Ficha técnica por defecto (pensada para alisados; se edita en Turnos → Ajustes → Ficha). */
function mjto_default_ficha_blocks() {
	$f = function ($key, $label, $type, $extra = []) {
		return array_merge([
			'key'           => $key,
			'label'         => $label,
			'type'          => $type,
			'options'       => [],
			'required'      => false,
			'carry'         => false,
			'role'          => '',
			'offset_days'   => 0,
			'offset_months' => 0,
			'help'          => '',
			'placeholder'   => '',
			'width'         => 'full',
		], $extra);
	};
	$o = function ($value, $label, $warn = false, $warn_text = '') {
		return ['value' => $value, 'label' => $label, 'warn' => (bool) $warn, 'warn_text' => $warn_text];
	};
	return [
		[
			'key'    => 'quimico',
			'title'  => 'Historial químico',
			'fields' => [
				$f('quimico', 'Tratamientos previos', 'checkbox_group', ['carry' => true, 'options' => [
					$o('tintura', 'Tintura'),
					$o('decoloracion', 'Decoloración / Reflejos'),
					$o('alisados', 'Alisados previos'),
					$o('permanente', 'Permanente'),
				]]),
			],
		],
		[
			'key'    => 'fibra',
			'title'  => 'Análisis de la fibra',
			'fields' => [
				$f('grosor', 'Grosor', 'radio', ['carry' => true, 'options' => [$o('fino', 'Fino'), $o('medio', 'Medio'), $o('grueso', 'Grueso')]]),
				$f('porosidad', 'Porosidad', 'radio', ['carry' => true, 'options' => [$o('baja', 'Baja'), $o('media', 'Media'), $o('alta', 'Alta')]]),
				$f('elasticidad', 'Elasticidad', 'radio', ['carry' => true, 'options' => [
					$o('buena', 'Buena'),
					$o('regular', 'Regular'),
					$o('critica', 'Crítica (chicle)', true, 'Elasticidad crítica: la fibra está muy dañada. Evaluá si conviene hacer el tratamiento o reforzar con reconstrucción antes.'),
				]]),
				$f('cuero', 'Cuero cabelludo', 'radio', ['carry' => true, 'options' => [
					$o('sano', 'Sano'),
					$o('sensible', 'Sensible'),
					$o('lesiones', 'Con lesiones', true, 'Cuero cabelludo con lesiones: no aplicar producto sobre la zona afectada y considerar posponer el servicio.'),
				]]),
			],
		],
		[
			'key'    => 'trabajo',
			'title'  => 'Trabajo técnico',
			'fields' => [
				$f('prueba_mechon', 'Resultado de la prueba de mechón', 'textarea'),
				$f('producto', 'Producto seleccionado', 'text', ['role' => 'product', 'placeholder' => 'Ej: Evans líquido, orgánico…']),
				$f('plancha', 'Temperatura de plancha y pasadas', 'text', ['placeholder' => 'Ej: 200 °C, 8 pasadas']),
				$f('observaciones', 'Observaciones / cuidados posteriores', 'textarea'),
			],
		],
		[
			'key'    => 'caja',
			'title'  => 'Caja y seguimiento',
			'fields' => [
				$f('dejo_sena', '¿Dejó seña?', 'toggle', ['role' => 'deposit_flag', 'width' => 'half']),
				$f('sena_monto', 'Monto de la seña', 'money', ['role' => 'deposit', 'width' => 'half']),
				$f('metodo_pago', 'Método de pago', 'radio', ['role' => 'payment_method']),
				$f('total', 'Total cobrado', 'money', ['role' => 'total']),
				$f('fecha_control', 'Control (post 3 días)', 'date', ['role' => 'control_date', 'offset_days' => 3, 'width' => 'half']),
				$f('fecha_retoque', 'Retoque sugerido (4 meses)', 'date', ['role' => 'retouch_date', 'offset_months' => 4, 'width' => 'half']),
			],
		],
	];
}

/** Etiquetas de estado por defecto. */
function mjto_default_status_labels() {
	return [
		'pending'          => 'Pendiente',
		'awaiting_deposit' => 'Esperando seña',
		'confirmed'        => 'Confirmado',
		'completed'        => 'Atendida',
		'cancelled'        => 'Cancelado',
		'no_show'          => 'No vino',
	];
}

/**
 * Mensajes de la reserva y del panel por defecto (clave => texto). Cada uno
 * es la opción msg_{clave} (Turnos → Ajustes → Textos → Mensajes); un %s se
 * reemplaza por el nombre del campo. mjto_booking_messages() los lee.
 */
function mjto_default_messages() {
	return [
		'required'               => 'Completá el campo "%s".',
		'invalid_email'          => 'Revisá el email: no parece válido.',
		'invalid_phone'          => 'Revisá el teléfono: necesitamos al menos 6 números.',
		'invalid_number'         => 'El campo "%s" tiene que ser un número.',
		'invalid_option'         => 'Elegí una opción válida en "%s".',
		'too_long'               => 'El campo "%s" es demasiado largo.',
		'invalid_service'        => 'Elegí un servicio válido.',
		'invalid_service_public' => 'Ese servicio no está disponible para reservar online.',
		'invalid_date'           => 'Elegí un día y un horario válidos.',
		'invalid_range'          => 'El rango de fechas no es válido.',
		'out_of_range'           => 'Ese día no está disponible para reservar online.',
		'slot_taken'             => 'Ese horario ya no está disponible. Elegí otro.',
		'busy'                   => 'Estamos procesando otra reserva. Probá de nuevo en unos segundos.',
		'spam'                   => 'No pudimos procesar la reserva. Volvé a intentar.',
		'rate_limited'           => 'Hiciste demasiados intentos seguidos. Esperá unos minutos y probá de nuevo.',
		'booking_failed'         => 'No pudimos guardar la reserva. Probá de nuevo en un momento.',
		'not_found'              => 'No encontramos ese turno.',
		'already_cancelled'      => 'Ese turno ya estaba cancelado.',
		'deposit_not_pending'    => 'Este turno no está esperando la seña.',
		'client_required'        => 'Faltan el nombre o el teléfono de la clienta.',
		'client_not_found'       => 'No encontramos esa clienta.',
		'client_mismatch'        => 'Ese turno no es de esta clienta.',
		'invalid_status'         => 'Ese estado no existe.',
		'visit_not_found'        => 'No encontramos esa visita.',
		'db'                     => 'No pudimos guardar los datos. Probá de nuevo.',
	];
}

/**
 * Textos cuyo vacío significa "no se muestra" (o "usar otro dato"): no se
 * reemplazan por el default cuando la administradora los deja vacíos, aunque
 * un tema hijo proponga un texto por mjto_option_defaults.
 */
function mjto_optional_text_keys() {
	return [
		'booking_policy',
		'booking_subtitle',
		'services_subtitle',
		'home_services_subtitle',
		'home_booking_subtitle',
		'home_services_link',
		'home_booking_image',
		'deposit_instructions',
		'deposit_link',
		'business_address',
		'business_instagram',
		'mail_from_name',
		'panel_title',
	];
}

function mjto_option_defaults() {
	$defaults = [
		// Agenda
		'weekly_hours'          => [1 => '09:00-18:00', 2 => '09:00-18:00', 3 => '09:00-18:00', 4 => '09:00-18:00', 5 => '09:00-18:00', 6 => '09:00-14:00', 7 => ''],
		'slot_step'             => 30,
		'buffer_min'            => 0,
		'min_notice_hours'      => 12,
		'max_days_ahead'        => 60,
		'default_duration_min'  => 120,
		'closed_dates'          => '',
		'week_start'            => 1,
		// Reservas, confirmación y seña
		'confirm_mode'          => 'deposit', // deposit | manual | auto
		'deposit_type'          => 'fixed',   // fixed | percent
		'deposit_amount'        => 0,
		'deposit_percent'       => 30,
		'deposit_instructions'  => '',
		'deposit_link'          => '',
		'deposit_hours'         => 24,        // 0 = sin vencimiento
		'client_can_cancel'     => 1,
		'cancel_hours_before'   => 24,
		'booking_policy'        => '',
		'rate_limit'            => 5,         // reservas por IP cada 10 minutos
		// Notificaciones
		'owner_email'           => (string) get_option('admin_email'),
		'owner_notify_new'      => 1,
		'owner_notify_cancel'   => 1,
		'owner_notify_deposit_notice' => 1,
		'client_email_enabled'  => 1,
		'reminder_enabled'      => 1,
		'reminder_hours_before' => 24,
		'mail_from_name'        => '', // Vacío = nombre del negocio.
		'cancel_reason_client'          => 'Cancelado por la clienta desde la web', // {motivo} cuando cancela desde su link.
		'cancel_reason_deposit_expired' => 'Seña no recibida a tiempo',             // {motivo} cuando vence la seña.
		// Negocio
		'business_name'         => '', // Vacío = nombre del sitio.
		'business_address'      => '', // Vacío = dirección del tema, si existe.
		'business_whatsapp'     => '', // Vacío = WhatsApp del tema, si existe.
		'business_instagram'    => '',
		'whatsapp_country'      => '54',
		'whatsapp_prefix'       => '549',
		// Páginas públicas y menú
		'page_slug'             => 'turnos',
		'menu_label'            => 'Turnos',
		'show_in_menu'          => 1,
		'show_bottom_nav'       => 1,
		'header_cta_enabled'    => 1,
		'header_cta'            => 'Reservar turno',
		'services_page_enabled' => 1,
		'services_slug'         => 'servicios',
		'services_menu_label'   => 'Servicios',
		'services_in_menu'      => 1,
		// Portada
		'home_services_enabled'  => 1,
		'home_services_eyebrow'  => 'Servicios',
		'home_services_title'    => 'Lo que hacemos',
		'home_services_subtitle' => '',
		'home_services_link'     => 'Ver todos los servicios',
		'home_services_count'    => 4,
		'home_booking_enabled'   => 1,
		'home_booking_eyebrow'   => 'Turnos',
		'home_booking_title'     => 'Reservá tu turno online',
		'home_booking_subtitle'  => 'Elegí el servicio, el día y la hora. Te confirmamos enseguida.',
		'home_booking_button'    => 'Reservar ahora',
		'home_booking_image'     => 0,
		// Textos de la reserva
		'booking_eyebrow'              => 'Turnos',
		'booking_title'                => 'Reservá tu turno',
		'booking_subtitle'             => 'Elegí el servicio, el día y el horario que te quede cómodo.',
		'booking_step_service'         => 'Servicio',
		'booking_step_date'            => 'Día y hora',
		'booking_step_details'         => 'Tus datos',
		'booking_step_done'            => 'Listo',
		'booking_pick_day'             => 'Elegí un día',
		'booking_pick_time'            => 'Elegí un horario',
		'booking_no_slots'             => 'No quedan horarios libres ese día.',
		'booking_no_days'              => 'No hay días disponibles en este mes.',
		'booking_summary_title'        => 'Tu reserva',
		'booking_submit'               => 'Confirmar reserva',
		'booking_back'                 => 'Volver',
		'booking_next'                 => 'Continuar',
		'booking_success_title'        => '¡Reserva recibida!',
		'booking_success_pending'      => 'Te confirmamos el turno a la brevedad por WhatsApp o email.',
		'booking_success_deposit'      => 'Para confirmar el turno, transferí la seña y avisanos.',
		'booking_success_auto'         => 'Tu turno quedó confirmado. ¡Te esperamos!',
		'booking_deposit_title'        => 'Seña',
		'booking_deposit_amount_label' => 'Monto de la seña',
		'booking_deposit_deadline'     => 'Tenés tiempo hasta {fecha_limite} para enviar la seña.',
		'booking_notified_button'      => 'Ya transferí la seña',
		'booking_notified_done'        => '¡Gracias! Te confirmamos apenas veamos la transferencia.',
		'booking_whatsapp_button'      => 'Enviar comprobante por WhatsApp',
		'booking_pay_link_button'      => 'Pagar la seña online',
		'booking_status_link'          => 'Ver el estado de mi turno',
		'booking_cancel_button'        => 'Cancelar turno',
		'booking_cancel_confirm'       => '¿Seguro que querés cancelar el turno?',
		'booking_cancelled_text'       => 'Tu turno fue cancelado.',
		'booking_cannot_cancel'        => 'Este turno ya no se puede cancelar desde la web. Escribinos por WhatsApp.',
		'booking_login_hint'           => '¿Sos la dueña? Ingresá para abrir el panel',
		'booking_panel_link'           => 'Abrir el panel de turnos',
		'booking_policy_title'         => 'Antes de reservar',
		'booking_retry'                => 'Reintentar',
		'booking_error_required'       => 'Completá este campo.',
		'booking_error_email'          => 'Revisá el email: no parece válido.',
		'booking_error_phone'          => 'Revisá el teléfono: necesitamos al menos 6 números.',
		'booking_error_number'         => 'Tiene que ser un número.',
		'booking_slots_morning'        => 'Mañana',
		'booking_slots_afternoon'      => 'Tarde',
		'booking_slots_evening'        => 'Noche',
		'booking_status_title'         => 'Estado de tu turno',
		'booking_status_not_found'     => 'No encontramos ese turno. Revisá el link o escribinos.',
		'booking_new_booking'          => 'Hacer otra reserva',
		'booking_whatsapp_label'       => 'WhatsApp',
		// Página de servicios
		'services_eyebrow'      => 'Servicios',
		'services_title'        => 'Servicios',
		'services_subtitle'     => '',
		'services_book_button'  => 'Reservar',
		'services_empty'        => 'Pronto vas a poder ver los servicios acá.',
		// Etiquetas
		'label_duration'        => 'Duración',
		'label_price'           => 'Precio',
		'label_deposit'         => 'Seña',
		'label_from'            => 'desde',
		'label_minutes'         => 'min',
		'label_hours'           => 'h',
		'price_visible'         => 1,
		'status_labels'         => mjto_default_status_labels(),
		// Formulario público y ficha técnica
		'form_fields'           => mjto_default_form_fields(),
		'ficha_blocks'          => mjto_default_ficha_blocks(),
		'payment_methods'       => "Efectivo\nTransferencia / QR",
		'visit_prefill_carry'   => 1,
		// Panel
		'panel_title'           => '', // Vacío = nombre del negocio.
		'panel_roles'           => [],
		'panel_theme'           => 'theme', // theme | light | dark
		'panel_accent'          => '',      // Vacío = acento del tema, si existe; si no, #e11d84.
		'caja_enabled'          => 1,
		// Moneda sin WooCommerce
		'currency_symbol'       => '$',
		'currency_decimals'     => 0,
	];
	$defaults = array_merge($defaults, mjto_default_templates());
	foreach (mjto_default_messages() as $key => $text) {
		$defaults['msg_' . $key] = $text;
	}
	return (array) apply_filters('mjto_option_defaults', $defaults);
}

/* -------------------------------------------------------------------------
 * Lectura
 * ---------------------------------------------------------------------- */

/** Lo guardado tal cual (sin defaults). */
function mjto_get_options_raw() {
	$saved = get_option('mjto_options', []);
	return is_array($saved) ? $saved : [];
}

/** Opciones efectivas: guardado + defaults (con merge por día, por estado y fallback de email). */
function mjto_get_options() {
	$defaults = mjto_option_defaults();
	$saved    = mjto_get_options_raw();
	$o        = wp_parse_args($saved, $defaults);

	/*
	 * weekly_hours va por día ISO con claves enteras 1..7: wp_parse_args /
	 * array_merge las renumerarían desde 0 (y correrían los días), así que se
	 * mezcla a mano. Nunca usar esas funciones sobre arrays indexados por día.
	 */
	$saved_wh   = is_array($o['weekly_hours']) ? $o['weekly_hours'] : [];
	$default_wh = is_array($defaults['weekly_hours']) ? $defaults['weekly_hours'] : [];
	$o['weekly_hours'] = [];
	for ($d = 1; $d <= 7; $d++) {
		$o['weekly_hours'][$d] = array_key_exists($d, $saved_wh) ? (string) $saved_wh[$d] : (string) ($default_wh[$d] ?? '');
	}
	$o['status_labels'] = wp_parse_args(is_array($o['status_labels']) ? $o['status_labels'] : [], $defaults['status_labels']);
	foreach ($o['status_labels'] as $k => $v) {
		if ('' === trim((string) $v) && isset($defaults['status_labels'][$k])) {
			$o['status_labels'][$k] = $defaults['status_labels'][$k];
		}
	}
	if (!is_array($o['form_fields']) || !$o['form_fields']) {
		$o['form_fields'] = $defaults['form_fields'];
	}
	if (!is_array($o['ficha_blocks'])) {
		$o['ficha_blocks'] = $defaults['ficha_blocks'];
	}
	if (!is_array($o['panel_roles'])) {
		$o['panel_roles'] = [];
	}
	if ('' === trim((string) $o['owner_email']) || !is_email($o['owner_email'])) {
		$o['owner_email'] = (string) get_option('admin_email');
	}
	/*
	 * Un texto guardado vacío usa el default (es lo que muestra el placeholder
	 * en Ajustes). Así "Guardar" sin tocar nada no pisa los textos que propone
	 * un tema hijo por el filtro mjto_option_defaults. No aplica a arrays
	 * (weekly_hours: vacío = cerrado), a números ni a los textos opcionales
	 * (mjto_optional_text_keys: vacío = no se muestra).
	 */
	$optional = array_flip(mjto_optional_text_keys());
	foreach ($defaults as $key => $default) {
		if (isset($optional[$key])) {
			continue;
		}
		if (is_string($default) && '' !== $default && isset($o[$key]) && is_string($o[$key]) && '' === trim($o[$key])) {
			$o[$key] = $default;
		}
	}
	unset($o['_tab'], $o['form_fields_json'], $o['ficha_blocks_json']);
	return $o;
}

function mjto_opt($key) {
	$o = mjto_get_options();
	return isset($o[$key]) ? $o[$key] : '';
}

/* -------------------------------------------------------------------------
 * Helpers de negocio, páginas y estados
 * ---------------------------------------------------------------------- */

function mjto_can_manage() {
	return current_user_can(MJTO_CAP);
}

function mjto_business_name() {
	$name = trim((string) mjto_opt('business_name'));
	return '' !== $name ? $name : (string) get_bloginfo('name');
}

/** Dirección del negocio: la propia o la del tema MJ Appearance, si existe. */
function mjto_business_address() {
	$address = trim((string) mjto_opt('business_address'));
	if ('' === $address && function_exists('mj_get_options')) {
		$theme   = mj_get_options();
		$address = trim((string) ($theme['contact_address'] ?? ''));
	}
	return $address;
}

/** WhatsApp del negocio en dígitos listos para wa.me (o '' si no hay). */
function mjto_business_whatsapp() {
	$raw = trim((string) mjto_opt('business_whatsapp'));
	if ('' === $raw && function_exists('mj_get_options')) {
		$theme = mj_get_options();
		$raw   = trim((string) ($theme['contact_whatsapp'] ?? ''));
	}
	return '' !== $raw ? mjto_whatsapp_number($raw) : '';
}

/** Instagram del negocio (URL o usuario) tal como se cargó. */
function mjto_business_instagram() {
	return trim((string) mjto_opt('business_instagram'));
}

/** Email de la dueña (siempre uno válido). */
function mjto_owner_email() {
	$email = sanitize_email((string) mjto_opt('owner_email'));
	return is_email($email) ? $email : (string) get_option('admin_email');
}

function mjto_page_slug() {
	$slug = sanitize_title((string) mjto_opt('page_slug'));
	return '' !== $slug ? $slug : 'turnos';
}

function mjto_services_slug() {
	$slug = sanitize_title((string) mjto_opt('services_slug'));
	return '' !== $slug ? $slug : 'servicios';
}

/** Estados: key => etiqueta. */
function mjto_statuses() {
	$labels = mjto_opt('status_labels');
	return wp_parse_args(is_array($labels) ? $labels : [], mjto_default_status_labels());
}

function mjto_status_label($status) {
	$statuses = mjto_statuses();
	$status   = (string) $status;
	return isset($statuses[$status]) ? (string) $statuses[$status] : $status;
}

/** Roles adicionales (además de administrator) que pueden abrir el panel. */
function mjto_panel_roles() {
	$roles = mjto_opt('panel_roles');
	return is_array($roles) ? array_values(array_filter(array_map('sanitize_key', $roles))) : [];
}

/** Franjas semanales efectivas: [1 => '09:00-18:00', …, 7 => '']. */
function mjto_weekly_hours() {
	$hours = mjto_opt('weekly_hours');
	$out   = [];
	for ($d = 1; $d <= 7; $d++) {
		$out[$d] = isset($hours[$d]) ? (string) $hours[$d] : '';
	}
	return $out;
}

/** Fechas cerradas: ['YYYY-MM-DD' => 'motivo']. */
function mjto_closed_dates() {
	$out = [];
	foreach (preg_split('/\r\n|\r|\n/', (string) mjto_opt('closed_dates')) as $line) {
		$line = trim($line);
		if ('' === $line) {
			continue;
		}
		$parts  = array_map('trim', explode('|', $line, 2));
		$date   = $parts[0];
		$reason = isset($parts[1]) ? $parts[1] : '';
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && (!function_exists('mjto_valid_date') || mjto_valid_date($date))) {
			$out[$date] = $reason;
		}
	}
	ksort($out);
	return $out;
}

/** Métodos de pago (uno por línea en el ajuste). */
function mjto_payment_methods() {
	$lines = preg_split('/\r\n|\r|\n/', (string) mjto_opt('payment_methods'));
	$out   = [];
	foreach ($lines as $line) {
		$line = trim($line);
		if ('' !== $line) {
			$out[] = $line;
		}
	}
	return array_values(array_unique($out));
}

/** Campos del formulario público, normalizados y con los de sistema garantizados. */
function mjto_form_fields() {
	return mjto_normalize_form_fields(mjto_opt('form_fields'));
}

/** Bloques de la ficha técnica, normalizados. */
function mjto_ficha_blocks() {
	return mjto_normalize_ficha_blocks(mjto_opt('ficha_blocks'));
}

/** Moneda: la de WooCommerce si está; si no, la del plugin. */
function mjto_currency() {
	if (function_exists('get_woocommerce_currency_symbol') && function_exists('wc_get_price_decimals')) {
		$currency = [
			'code'        => (string) get_woocommerce_currency(),
			'symbol'      => html_entity_decode((string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
			'decimals'    => (int) wc_get_price_decimals(),
			'thousandSep' => (string) wc_get_price_thousand_separator(),
			'decimalSep'  => (string) wc_get_price_decimal_separator(),
			'position'    => (string) get_option('woocommerce_currency_pos', 'left'),
		];
	} else {
		$symbol   = trim((string) mjto_opt('currency_symbol'));
		$currency = [
			'code'        => 'ARS',
			'symbol'      => '' !== $symbol ? $symbol : '$',
			'decimals'    => max(0, min(4, (int) mjto_opt('currency_decimals'))),
			'thousandSep' => '.',
			'decimalSep'  => ',',
			'position'    => 'left_space',
		];
	}
	return (array) apply_filters('mjto_currency', $currency);
}

/** Claves de texto público que viajan a la SPA/widget. */
function mjto_public_copy_keys() {
	$keys = [];
	foreach (array_keys(mjto_option_defaults()) as $key) {
		if (0 === strpos($key, 'booking_') || 0 === strpos($key, 'label_')) {
			$keys[] = $key;
		}
	}
	return array_merge($keys, ['services_eyebrow', 'services_title', 'services_subtitle', 'services_book_button', 'services_empty']);
}

/** Todo el copy público: booking_*, services_*, label_* + statuses. */
function mjto_public_copy() {
	$o    = mjto_get_options();
	$copy = [];
	foreach (mjto_public_copy_keys() as $key) {
		$copy[$key] = isset($o[$key]) ? (string) $o[$key] : '';
	}
	$copy['statuses'] = mjto_statuses();
	return $copy;
}

/** Placeholders disponibles en plantillas (clave => descripción). */
function mjto_placeholder_list() {
	return [
		'{nombre}'             => 'Nombre de la clienta',
		'{apellido}'           => 'Apellido',
		'{nombre_completo}'    => 'Nombre y apellido',
		'{telefono}'           => 'Teléfono como lo escribió',
		'{servicio}'           => 'Nombre del servicio',
		'{fecha}'              => 'Fecha del turno (ej. "sábado 12 de septiembre")',
		'{hora}'               => 'Hora de inicio',
		'{fecha_hora}'         => 'Fecha y hora juntas',
		'{duracion}'           => 'Duración (ej. "2 h 30 min")',
		'{precio}'             => 'Precio del servicio',
		'{sena}'               => 'Monto de la seña (también {seña})',
		'{instrucciones_sena}' => 'Instrucciones para transferir la seña',
		'{link_pago}'          => 'Link de pago de la seña (si hay)',
		'{fecha_limite}'       => 'Hasta cuándo puede enviar la seña',
		'{negocio}'            => 'Nombre del negocio',
		'{direccion}'          => 'Dirección del negocio',
		'{whatsapp_negocio}'   => 'WhatsApp del negocio',
		'{link_turno}'         => 'Link público al estado del turno',
		'{fecha_control}'      => 'Fecha sugerida de control',
		'{fecha_retoque}'      => 'Fecha sugerida de retoque',
		'{motivo}'             => 'Motivo de cancelación',
		'{estado}'             => 'Estado del turno',
	];
}

/* -------------------------------------------------------------------------
 * Normalización de campos (formulario público y ficha técnica)
 * ---------------------------------------------------------------------- */

function mjto_form_field_types() {
	return ['text', 'tel', 'email', 'textarea', 'select', 'number'];
}

function mjto_ficha_field_types() {
	return ['checkbox_group', 'radio', 'select', 'text', 'textarea', 'number', 'money', 'date', 'toggle'];
}

function mjto_ficha_roles() {
	return ['', 'total', 'deposit', 'deposit_flag', 'payment_method', 'control_date', 'retouch_date', 'product'];
}

/** Clave de campo: minúsculas, letras/números/guion bajo; si viene vacía se deriva de la etiqueta. */
function mjto_field_key($key, $label = '') {
	$key = sanitize_key((string) $key);
	if ('' === $key) {
		$key = str_replace('-', '_', sanitize_title((string) $label));
		$key = sanitize_key($key);
	}
	return substr($key, 0, 60);
}

/** Opciones de un campo → [{value, label, warn, warn_text}], valores únicos. */
function mjto_normalize_field_options($options) {
	$out  = [];
	$seen = [];
	if (!is_array($options)) {
		return $out;
	}
	foreach ($options as $opt) {
		if (is_string($opt)) {
			$opt = ['label' => $opt];
		}
		if (!is_array($opt)) {
			continue;
		}
		$label = sanitize_text_field((string) ($opt['label'] ?? ''));
		$value = mjto_field_key($opt['value'] ?? '', $label);
		if ('' === $value && '' === $label) {
			continue;
		}
		if ('' === $value) {
			$value = 'op_' . (count($out) + 1);
		}
		if ('' === $label) {
			$label = $value;
		}
		if (isset($seen[$value])) {
			continue;
		}
		$seen[$value] = true;
		$out[]        = [
			'value'     => $value,
			'label'     => $label,
			'warn'      => !empty($opt['warn']),
			'warn_text' => sanitize_textarea_field((string) ($opt['warn_text'] ?? '')),
		];
	}
	return $out;
}

/**
 * Campos del formulario público. Los de sistema siempre están (si faltan se
 * agregan al final con su default); first_name y phone quedan obligatorios y
 * activos aunque el JSON diga otra cosa; el tipo de los de sistema no cambia.
 */
function mjto_normalize_form_fields($fields) {
	$system = [];
	foreach (mjto_default_form_fields() as $def) {
		$system[$def['key']] = $def;
	}
	$out  = [];
	$seen = [];
	foreach ((array) $fields as $field) {
		if (!is_array($field)) {
			continue;
		}
		$label = sanitize_text_field((string) ($field['label'] ?? ''));
		$key   = mjto_field_key($field['key'] ?? '', $label);
		if ('' === $key || isset($seen[$key])) {
			continue;
		}
		$is_system = isset($system[$key]);
		$type      = sanitize_key((string) ($field['type'] ?? 'text'));
		if ($is_system) {
			$type = $system[$key]['type'];
		} elseif (!in_array($type, mjto_form_field_types(), true)) {
			$type = 'text';
		}
		$required = !empty($field['required']);
		$enabled  = !array_key_exists('enabled', $field) || !empty($field['enabled']);
		if (in_array($key, ['first_name', 'phone'], true)) {
			$required = true;
			$enabled  = true;
		}
		$seen[$key] = true;
		$out[]      = [
			'key'         => $key,
			'label'       => '' !== $label ? $label : ($is_system ? $system[$key]['label'] : $key),
			'placeholder' => sanitize_text_field((string) ($field['placeholder'] ?? '')),
			'type'        => $type,
			'required'    => $required,
			'enabled'     => $enabled,
			'options'     => 'select' === $type ? array_map(fn ($o) => ['value' => $o['value'], 'label' => $o['label']], mjto_normalize_field_options($field['options'] ?? [])) : [],
			'system'      => $is_system,
		];
	}
	foreach ($system as $key => $def) {
		if (!isset($seen[$key])) {
			$out[] = $def;
		}
	}
	return $out;
}

/** Bloques de la ficha técnica: claves únicas (bloques y campos), tipos y roles válidos, opciones limpias. */
function mjto_normalize_ficha_blocks($blocks) {
	$out         = [];
	$seen_blocks = [];
	$seen_fields = [];
	$types       = mjto_ficha_field_types();
	$roles       = mjto_ficha_roles();
	foreach ((array) $blocks as $block) {
		if (!is_array($block)) {
			continue;
		}
		$title = sanitize_text_field((string) ($block['title'] ?? ''));
		$bkey  = mjto_field_key($block['key'] ?? '', $title);
		if ('' === $bkey) {
			$bkey = 'bloque_' . (count($out) + 1);
		}
		if (isset($seen_blocks[$bkey])) {
			continue;
		}
		$seen_blocks[$bkey] = true;
		$fields             = [];
		foreach ((array) ($block['fields'] ?? []) as $field) {
			if (!is_array($field)) {
				continue;
			}
			$label = sanitize_text_field((string) ($field['label'] ?? ''));
			$key   = mjto_field_key($field['key'] ?? '', $label);
			if ('' === $key || isset($seen_fields[$key])) {
				continue;
			}
			$type = sanitize_key((string) ($field['type'] ?? 'text'));
			if (!in_array($type, $types, true)) {
				$type = 'text';
			}
			$role = sanitize_key((string) ($field['role'] ?? ''));
			if (!in_array($role, $roles, true)) {
				$role = '';
			}
			$width              = ('half' === ($field['width'] ?? 'full')) ? 'half' : 'full';
			$seen_fields[$key]  = true;
			$fields[]           = [
				'key'           => $key,
				'label'         => '' !== $label ? $label : $key,
				'type'          => $type,
				'options'       => in_array($type, ['checkbox_group', 'radio', 'select'], true) ? mjto_normalize_field_options($field['options'] ?? []) : [],
				'required'      => !empty($field['required']),
				'carry'         => !empty($field['carry']),
				'role'          => $role,
				'offset_days'   => max(0, min(3650, (int) ($field['offset_days'] ?? 0))),
				'offset_months' => max(0, min(120, (int) ($field['offset_months'] ?? 0))),
				'help'          => sanitize_textarea_field((string) ($field['help'] ?? '')),
				'placeholder'   => sanitize_text_field((string) ($field['placeholder'] ?? '')),
				'width'         => $width,
			];
		}
		$out[] = [
			'key'    => $bkey,
			'title'  => '' !== $title ? $title : $bkey,
			'fields' => $fields,
		];
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Sanitización
 * ---------------------------------------------------------------------- */

/** Pestañas de Turnos → Ajustes. */
function mjto_option_tabs() {
	return [
		'agenda'         => 'Agenda',
		'reservas'       => 'Reservas y seña',
		'notificaciones' => 'Notificaciones',
		'textos'         => 'Textos y páginas',
		'formulario'     => 'Formulario',
		'ficha'          => 'Ficha técnica',
		'panel'          => 'Panel',
	];
}

/** Esquema: clave => [pestaña, tipo, extra]. Es la whitelist de la sanitización. */
function mjto_option_schema() {
	$s = [
		// Agenda
		'weekly_hours'         => ['agenda', 'weekly_hours'],
		'slot_step'            => ['agenda', 'int', [5, 240]],
		'buffer_min'           => ['agenda', 'int', [0, 600]],
		'min_notice_hours'     => ['agenda', 'int', [0, 720]],
		'max_days_ahead'       => ['agenda', 'int', [1, 365]],
		'default_duration_min' => ['agenda', 'int', [5, 1440]],
		'closed_dates'         => ['agenda', 'closed_dates'],
		'week_start'           => ['agenda', 'enum_int', [1, 7]],
		// Reservas
		'confirm_mode'         => ['reservas', 'enum', ['deposit', 'manual', 'auto']],
		'deposit_type'         => ['reservas', 'enum', ['fixed', 'percent']],
		'deposit_amount'       => ['reservas', 'float', [0, 99999999]],
		'deposit_percent'      => ['reservas', 'float', [0, 100]],
		'deposit_instructions' => ['reservas', 'textarea'],
		'deposit_link'         => ['reservas', 'url'],
		'deposit_hours'        => ['reservas', 'int', [0, 720]],
		'client_can_cancel'    => ['reservas', 'bool'],
		'cancel_hours_before'  => ['reservas', 'int', [0, 720]],
		'booking_policy'       => ['reservas', 'textarea'],
		'rate_limit'           => ['reservas', 'int', [1, 1000]],
		// Notificaciones
		'owner_email'                 => ['notificaciones', 'email'],
		'owner_notify_new'            => ['notificaciones', 'bool'],
		'owner_notify_cancel'         => ['notificaciones', 'bool'],
		'owner_notify_deposit_notice' => ['notificaciones', 'bool'],
		'client_email_enabled'        => ['notificaciones', 'bool'],
		'reminder_enabled'            => ['notificaciones', 'bool'],
		'reminder_hours_before'       => ['notificaciones', 'int', [1, 720]],
		'mail_from_name'              => ['notificaciones', 'text'],
		'cancel_reason_client'          => ['notificaciones', 'text'],
		'cancel_reason_deposit_expired' => ['notificaciones', 'text'],
		// Negocio y páginas
		'business_name'         => ['textos', 'text'],
		'business_address'      => ['textos', 'text'],
		'business_whatsapp'     => ['textos', 'text'],
		'business_instagram'    => ['textos', 'text'],
		'whatsapp_country'      => ['textos', 'digits'],
		'whatsapp_prefix'       => ['textos', 'digits'],
		'page_slug'             => ['textos', 'slug'],
		'menu_label'            => ['textos', 'text'],
		'show_in_menu'          => ['textos', 'bool'],
		'show_bottom_nav'       => ['textos', 'bool'],
		'header_cta_enabled'    => ['textos', 'bool'],
		'header_cta'            => ['textos', 'text'],
		'services_page_enabled' => ['textos', 'bool'],
		'services_slug'         => ['textos', 'slug'],
		'services_menu_label'   => ['textos', 'text'],
		'services_in_menu'      => ['textos', 'bool'],
		'home_services_enabled' => ['textos', 'bool'],
		'home_services_count'   => ['textos', 'int', [1, 12]],
		'home_booking_enabled'  => ['textos', 'bool'],
		'home_booking_image'    => ['textos', 'image'],
		'price_visible'         => ['textos', 'bool'],
		'status_labels'         => ['textos', 'status_labels'],
		// Formulario y ficha
		'form_fields'           => ['formulario', 'form_fields'],
		'ficha_blocks'          => ['ficha', 'ficha_blocks'],
		'payment_methods'       => ['ficha', 'textarea'],
		'visit_prefill_carry'   => ['ficha', 'bool'],
		// Panel
		'panel_title'           => ['panel', 'text'],
		'panel_roles'           => ['panel', 'roles'],
		'panel_theme'           => ['panel', 'enum', ['theme', 'light', 'dark']],
		'panel_accent'          => ['panel', 'hex'],
		'caja_enabled'          => ['panel', 'bool'],
		'currency_symbol'       => ['panel', 'text'],
		'currency_decimals'     => ['panel', 'int', [0, 4]],
	];
	/* Textos públicos: todo lo que empieza con home_*, booking_*, services_*, label_*, msg_* que no esté arriba. */
	foreach (array_keys(mjto_option_defaults()) as $key) {
		if (isset($s[$key])) {
			continue;
		}
		if (preg_match('/^(home_|booking_|services_|label_|msg_)/', $key)) {
			$s[$key] = ['textos', 'text'];
		} elseif (0 === strpos($key, 'tpl_')) {
			$s[$key] = ['notificaciones', substr($key, -8) === '_subject' ? 'text' : 'textarea'];
		}
	}
	return $s;
}

/** "09:00-13:00, 15:00-20:00" → franjas válidas, ordenadas y sin solaparse (las inválidas se descartan). */
function mjto_sanitize_ranges($string) {
	$ranges = [];
	foreach (preg_split('/[,;\n]+/', (string) $string) as $part) {
		$part = trim($part);
		if ('' === $part) {
			continue;
		}
		if (!preg_match('/^(\d{1,2})[:.](\d{2})\s*(?:-|–|a)\s*(\d{1,2})[:.](\d{2})$/u', $part, $m)) {
			continue;
		}
		$sh = (int) $m[1];
		$sm = (int) $m[2];
		$eh = (int) $m[3];
		$em = (int) $m[4];
		if ($sh > 23 || $eh > 24 || $sm > 59 || $em > 59) {
			continue;
		}
		$start = $sh * 60 + $sm;
		$end   = $eh * 60 + $em;
		if ($end <= $start || $end > 1440) {
			continue;
		}
		$ranges[] = [$start, $end];
	}
	usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
	$clean    = [];
	$last_end = -1;
	foreach ($ranges as $r) {
		if ($r[0] < $last_end) {
			continue; // Se solapa con la anterior: se descarta.
		}
		$clean[]  = $r;
		$last_end = $r[1];
	}
	return implode(', ', array_map(fn ($r) => sprintf('%02d:%02d-%02d:%02d', intdiv($r[0], 60), $r[0] % 60, intdiv($r[1], 60), $r[1] % 60), $clean));
}

/** Fechas cerradas: conserva solo las líneas "YYYY-MM-DD | motivo" válidas. */
function mjto_sanitize_closed_dates($string) {
	$out = [];
	foreach (preg_split('/\r\n|\r|\n/', (string) $string) as $line) {
		$line = trim(sanitize_text_field($line));
		if ('' === $line) {
			continue;
		}
		$parts  = array_map('trim', explode('|', $line, 2));
		$date   = $parts[0];
		$reason = isset($parts[1]) ? $parts[1] : '';
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			continue;
		}
		$out[$date] = $date . ('' !== $reason ? ' | ' . $reason : '');
	}
	ksort($out);
	return implode("\n", $out);
}

/** Roles que pueden abrir el panel: existentes y distintos de administrator (que siempre puede). */
function mjto_sanitize_roles($roles) {
	$all = array_keys(wp_roles()->roles);
	$out = [];
	foreach ((array) $roles as $role) {
		$role = sanitize_key((string) $role);
		if ('' !== $role && 'administrator' !== $role && in_array($role, $all, true)) {
			$out[] = $role;
		}
	}
	return array_values(array_unique($out));
}

/**
 * Sincroniza la capability del panel: administrator + los roles elegidos la
 * tienen; el resto no. Nunca se la saca a administrator.
 */
function mjto_sync_panel_caps($roles = null) {
	if (null === $roles) {
		$roles = mjto_panel_roles();
	}
	$roles    = array_map('sanitize_key', (array) $roles);
	$wp_roles = wp_roles();
	foreach ($wp_roles->role_objects as $slug => $role) {
		$should = ('administrator' === $slug) || in_array($slug, $roles, true);
		if ($should && !$role->has_cap(MJTO_CAP)) {
			$role->add_cap(MJTO_CAP);
		} elseif (!$should && $role->has_cap(MJTO_CAP)) {
			$role->remove_cap(MJTO_CAP);
		}
	}
}

add_action('admin_init', function () {
	register_setting('mjto_options_group', 'mjto_options', [
		'sanitize_callback' => 'mjto_sanitize_options',
		'default'           => [],
	]);
});

/**
 * Cada pestaña manda solo sus campos: se sanitiza lo que llega (whitelist)
 * y se hace merge sobre lo guardado. Los checkbox de la pestaña enviada que
 * no llegan se guardan en 0. Si el JSON del constructor es inválido, se
 * conserva el valor anterior.
 */
function mjto_sanitize_options($input) {
	$input  = is_array($input) ? $input : [];
	$saved  = mjto_get_options_raw();
	$schema = mjto_option_schema();
	$tab    = isset($input['_tab']) ? sanitize_key((string) $input['_tab']) : '';
	$out    = [];

	foreach ($schema as $key => $def) {
		$key_tab = $def[0];
		$type    = $def[1];
		$extra   = isset($def[2]) ? $def[2] : null;
		$present = array_key_exists($key, $input);
		$in_tab  = ('' !== $tab && $tab === $key_tab);

		if ('bool' === $type) {
			if (!$present && !$in_tab) {
				continue;
			}
			$out[$key] = ($present && !empty($input[$key])) ? 1 : 0;
			continue;
		}
		if (!$present) {
			continue;
		}
		$raw = $input[$key];
		switch ($type) {
			case 'int':
				$out[$key] = max((int) $extra[0], min((int) $extra[1], (int) $raw));
				break;
			case 'enum_int':
				$out[$key] = in_array((int) $raw, array_map('intval', $extra), true) ? (int) $raw : (int) $extra[0];
				break;
			case 'float':
				$v         = (float) str_replace(',', '.', (string) $raw);
				$out[$key] = max((float) $extra[0], min((float) $extra[1], round($v, 2)));
				break;
			case 'enum':
				$out[$key] = in_array((string) $raw, $extra, true) ? (string) $raw : (string) $extra[0];
				break;
			case 'textarea':
				$out[$key] = sanitize_textarea_field((string) $raw);
				break;
			case 'url':
				$out[$key] = esc_url_raw(trim((string) $raw));
				break;
			case 'email':
				$out[$key] = sanitize_email((string) $raw);
				break;
			case 'hex':
				$hex       = sanitize_hex_color(trim((string) $raw));
				$out[$key] = $hex ? $hex : '';
				break;
			case 'slug':
				$out[$key] = sanitize_title((string) $raw);
				break;
			case 'digits':
				$out[$key] = preg_replace('/\D+/', '', (string) $raw);
				break;
			case 'image':
				$out[$key] = absint(strtok((string) $raw, ','));
				break;
			case 'weekly_hours':
				$hours = [];
				for ($d = 1; $d <= 7; $d++) {
					$hours[$d] = is_array($raw) && isset($raw[$d]) ? mjto_sanitize_ranges($raw[$d]) : '';
				}
				$out[$key] = $hours;
				break;
			case 'closed_dates':
				$out[$key] = mjto_sanitize_closed_dates($raw);
				break;
			case 'status_labels':
				$labels = [];
				foreach (array_keys(mjto_default_status_labels()) as $status) {
					$labels[$status] = is_array($raw) && isset($raw[$status]) ? sanitize_text_field((string) $raw[$status]) : '';
				}
				$out[$key] = $labels;
				break;
			case 'roles':
				$out[$key] = mjto_sanitize_roles($raw);
				break;
			case 'form_fields':
				if (is_array($raw)) {
					$out[$key] = mjto_normalize_form_fields($raw);
				}
				break;
			case 'ficha_blocks':
				if (is_array($raw)) {
					$out[$key] = mjto_normalize_ficha_blocks($raw);
				}
				break;
			default:
				$out[$key] = sanitize_text_field((string) $raw);
		}
	}

	/* JSON del constructor (textarea): si es inválido, queda lo anterior. */
	if (isset($input['form_fields_json']) && is_string($input['form_fields_json'])) {
		$decoded = json_decode(trim($input['form_fields_json']), true);
		if (is_array($decoded)) {
			$out['form_fields'] = mjto_normalize_form_fields($decoded);
		} elseif ('' !== trim($input['form_fields_json'])) {
			add_settings_error('mjto_options', 'mjto_form_json', 'El JSON del formulario no es válido: se conservó la versión anterior.', 'error');
		}
	}
	if (isset($input['ficha_blocks_json']) && is_string($input['ficha_blocks_json'])) {
		$decoded = json_decode(trim($input['ficha_blocks_json']), true);
		if (is_array($decoded)) {
			$out['ficha_blocks'] = mjto_normalize_ficha_blocks($decoded);
		} elseif ('' !== trim($input['ficha_blocks_json'])) {
			add_settings_error('mjto_options', 'mjto_ficha_json', 'El JSON de la ficha no es válido: se conservó la versión anterior.', 'error');
		}
	}
	/* En la pestaña Panel, sin ningún rol tildado → lista vacía. */
	if ('panel' === $tab && !array_key_exists('panel_roles', $out)) {
		$out['panel_roles'] = [];
	}
	if (array_key_exists('panel_roles', $out)) {
		mjto_sync_panel_caps($out['panel_roles']);
	}

	$merged = array_merge($saved, $out);
	unset($merged['_tab'], $merged['form_fields_json'], $merged['ficha_blocks_json']);
	return $merged;
}

/* -------------------------------------------------------------------------
 * Menú wp-admin: Turnos → Ajustes | Abrir el panel | Servicios
 * ---------------------------------------------------------------------- */
add_action('admin_menu', function () {
	add_menu_page('MJ Turnos', 'Turnos', MJTO_CAP, 'mjto', 'mjto_render_settings_page', 'dashicons-calendar-alt', 57);
	/*
	 * Mismo slug que el padre: si acá pidiéramos manage_options, WordPress
	 * bloquearía admin.php?page=mjto entero para los roles que solo tienen
	 * MJTO_CAP. El callback ya exige manage_options y a los demás les muestra
	 * el botón "Abrir el panel".
	 */
	add_submenu_page('mjto', 'Ajustes', 'Ajustes', MJTO_CAP, 'mjto', 'mjto_render_settings_page');
	/* WP acepta URLs absolutas como slug: abre el panel en el sitio. */
	add_submenu_page('mjto', 'Abrir el panel', 'Abrir el panel', MJTO_CAP, mjto_panel_url());
	add_submenu_page('mjto', 'Servicios', 'Servicios', 'manage_options', 'edit.php?post_type=mjto_service');
});

/** URL del panel de la dueña (misma URL que la reserva pública). */
function mjto_panel_url() {
	return home_url('/' . mjto_page_slug());
}

/* Barra de admin: nodo "Turnos" → panel. */
add_action('admin_bar_menu', function ($bar) {
	if (!current_user_can(MJTO_CAP)) {
		return;
	}
	$bar->add_node([
		'id'    => 'mjto-panel',
		'title' => '<span class="ab-icon dashicons dashicons-calendar-alt" style="margin-top:2px;"></span><span class="ab-label">Turnos</span>',
		'href'  => mjto_panel_url(),
		'meta'  => ['title' => 'Abrir el panel de turnos'],
	]);
}, 80);

/* -------------------------------------------------------------------------
 * Scripts de la página de ajustes: medios + constructor de campos (React)
 * ---------------------------------------------------------------------- */

/** Pestaña activa de la página de ajustes. */
function mjto_settings_current_tab() {
	$tabs = mjto_option_tabs();
	$tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'agenda'; // phpcs:ignore WordPress.Security.NonceVerification -- solo elige qué pestaña mostrar.
	return isset($tabs[$tab]) ? $tab : 'agenda';
}

/** Datos que lee el constructor de campos (window.MJTO en modo builder). */
function mjto_builder_boot($kind) {
	return [
		'mode'           => 'builder',
		'kind'           => $kind,
		'defaults'       => [
			'form'  => mjto_default_form_fields(),
			'ficha' => mjto_default_ficha_blocks(),
		],
		'paymentMethods' => mjto_payment_methods(),
		'statuses'       => mjto_statuses(),
		'fieldTypes'     => [
			'form'  => mjto_form_field_types(),
			'ficha' => mjto_ficha_field_types(),
		],
		'roles'          => array_values(array_filter(mjto_ficha_roles())),
		'currency'       => mjto_currency(),
		'locale'         => get_locale(),
	];
}

add_action('admin_enqueue_scripts', function ($hook) {
	if ('toplevel_page_mjto' !== $hook) {
		return;
	}
	wp_enqueue_media();
	$tab = mjto_settings_current_tab();
	if (!in_array($tab, ['formulario', 'ficha'], true)) {
		return;
	}
	$js  = MJTO_DIR . 'dist/app.js';
	$css = MJTO_DIR . 'dist/app.css';
	if (!file_exists($js)) {
		return;
	}
	if (file_exists($css)) {
		wp_enqueue_style('mjto-app', MJTO_URL . 'dist/app.css', [], (string) filemtime($css));
	}
	wp_enqueue_script('mjto-builder', MJTO_URL . 'dist/app.js', [], (string) filemtime($js), true);
	wp_add_inline_script('mjto-builder', 'window.MJTO = ' . wp_json_encode(mjto_builder_boot('formulario' === $tab ? 'form' : 'ficha'), JSON_UNESCAPED_UNICODE) . ';', 'before');
});

/* La app es un módulo ES: type="module" (idempotente por si otro filtro ya lo puso). */
add_filter('script_loader_tag', function ($tag, $handle) {
	if ('mjto-builder' !== $handle || false !== strpos($tag, 'type="module"')) {
		return $tag;
	}
	/* $tag trae también el inline window.MJTO de antes: solo se toca el <script> con src. */
	return preg_replace_callback('/<script\b([^>]*?)\s*src=/', function ($m) {
		$attrs = preg_replace('/\stype=(["\'])[^"\']*\1/', '', $m[1]);
		return '<script type="module"' . $attrs . ' src=';
	}, $tag);
}, 10, 2);

/** Selector de imagen (una sola), mismo patrón que MJ Colegas. */
function mjto_render_media_picker($name, $id, $button_label, $description) {
	$id    = absint(strtok((string) $id, ','));
	$thumb = $id ? wp_get_attachment_image_url($id, 'medium') : '';
	?>
	<div class="mjto-media">
		<div class="mjto-media__preview" style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:8px;">
			<?php if ($thumb) : ?>
				<span class="mjto-media__item" data-id="<?php echo esc_attr($id); ?>" style="position:relative; display:inline-block;">
					<img src="<?php echo esc_url($thumb); ?>" alt="" style="width:120px; height:90px; object-fit:cover; border-radius:6px; display:block; background:#f0f0f1;" />
					<button type="button" class="mjto-media__remove" aria-label="Quitar" style="position:absolute; top:-6px; right:-6px; width:20px; height:20px; border-radius:50%; background:#d63638; color:#fff; border:0; cursor:pointer; line-height:1; padding:0;">&times;</button>
				</span>
			<?php endif; ?>
		</div>
		<input type="hidden" class="mjto-media__value" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($id ? $id : ''); ?>" />
		<button type="button" class="button mjto-media__add"><?php echo esc_html($button_label); ?></button>
		<?php if ($description) : ?>
			<p class="description"><?php echo esc_html($description); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/** Script del selector de medios (una vez por pantalla). */
function mjto_media_picker_script() {
	static $printed = false;
	if ($printed) {
		return;
	}
	$printed = true;
	?>
	<script>
	jQuery(function ($) {
		function item(id, url) {
			return $('<span class="mjto-media__item" style="position:relative; display:inline-block;" />')
				.attr('data-id', id)
				.append($('<img alt="" style="width:120px; height:90px; object-fit:cover; border-radius:6px; display:block; background:#f0f0f1;" />').attr('src', url))
				.append($('<button type="button" class="mjto-media__remove" aria-label="Quitar" style="position:absolute; top:-6px; right:-6px; width:20px; height:20px; border-radius:50%; background:#d63638; color:#fff; border:0; cursor:pointer; line-height:1; padding:0;">&times;</button>'));
		}
		$(document).on('click', '.mjto-media__add', function (e) {
			e.preventDefault();
			var box = $(this).closest('.mjto-media');
			var input = box.find('.mjto-media__value');
			var preview = box.find('.mjto-media__preview');
			var frame = wp.media({ title: 'Elegir imagen', button: { text: 'Usar' }, library: { type: 'image' }, multiple: false });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				preview.empty().append(item(a.id, (a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url));
				input.val(String(a.id));
			});
			frame.open();
		});
		$(document).on('click', '.mjto-media__remove', function () {
			var box = $(this).closest('.mjto-media');
			box.find('.mjto-media__value').val('');
			$(this).closest('.mjto-media__item').remove();
		});
	});
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * Helpers de campos de la página de ajustes
 * ---------------------------------------------------------------------- */

/**
 * [valores para los campos, defaults] (una vez por request).
 * Los campos de texto muestran lo guardado tal cual (vacío = se usa el default,
 * que se ve como placeholder); números, opciones y arrays muestran el valor efectivo.
 * Los textos opcionales (vacío = no se muestra) que nunca se guardaron muestran
 * el texto propuesto (default o tema hijo) para que "Guardar" no lo borre.
 */
function mjto_settings_values() {
	static $cache = null;
	if (null === $cache) {
		$defaults = mjto_option_defaults();
		$raw      = mjto_get_options_raw();
		$o        = mjto_get_options();
		$optional = array_flip(mjto_optional_text_keys());
		foreach (mjto_option_schema() as $key => $def) {
			if (in_array($def[1], ['text', 'textarea', 'email', 'url', 'hex', 'slug', 'digits'], true)) {
				if (isset($optional[$key]) && !array_key_exists($key, $raw)) {
					continue; // Nunca guardado: queda el valor efectivo (el propuesto).
				}
				$o[$key] = (array_key_exists($key, $raw) && is_scalar($raw[$key])) ? (string) $raw[$key] : '';
			}
		}
		$o['status_labels_raw'] = (isset($raw['status_labels']) && is_array($raw['status_labels'])) ? $raw['status_labels'] : [];
		$cache = [$o, $defaults];
	}
	return $cache;
}

/** Placeholder por defecto de una clave (el default efectivo, si es escalar). Los textos opcionales no tienen: vacío = no se muestra. */
function mjto_default_placeholder($key) {
	if (in_array($key, mjto_optional_text_keys(), true)) {
		return '';
	}
	[, $d] = mjto_settings_values();
	return (isset($d[$key]) && is_scalar($d[$key])) ? (string) $d[$key] : '';
}

/** Descripción para un texto opcional ('' si la clave no lo es). */
function mjto_optional_text_desc($key, $text = 'Vacío = no se muestra.') {
	return in_array($key, mjto_optional_text_keys(), true) ? $text : '';
}

function mjto_field_text($key, $placeholder = null, $class = 'regular-text', $type = 'text') {
	[$o] = mjto_settings_values();
	if (null === $placeholder) {
		$placeholder = mjto_default_placeholder($key);
	}
	printf(
		'<input type="%1$s" id="mjto_%2$s" name="mjto_options[%2$s]" value="%3$s" class="%4$s" placeholder="%5$s" />',
		esc_attr($type),
		esc_attr($key),
		esc_attr(isset($o[$key]) && is_scalar($o[$key]) ? (string) $o[$key] : ''),
		esc_attr($class),
		esc_attr($placeholder)
	);
}

function mjto_field_textarea($key, $rows = 4, $placeholder = null, $class = 'large-text') {
	[$o] = mjto_settings_values();
	if (null === $placeholder) {
		$placeholder = mjto_default_placeholder($key);
	}
	printf(
		'<textarea id="mjto_%1$s" name="mjto_options[%1$s]" rows="%2$d" class="%3$s" placeholder="%4$s">%5$s</textarea>',
		esc_attr($key),
		(int) $rows,
		esc_attr($class),
		esc_attr($placeholder),
		esc_textarea(isset($o[$key]) && is_scalar($o[$key]) ? (string) $o[$key] : '')
	);
}

function mjto_field_number($key, $min, $max, $step = 1, $suffix = '') {
	[$o] = mjto_settings_values();
	printf(
		'<input type="number" id="mjto_%1$s" name="mjto_options[%1$s]" value="%2$s" min="%3$s" max="%4$s" step="%5$s" class="small-text" placeholder="%6$s" />%7$s',
		esc_attr($key),
		esc_attr(isset($o[$key]) && is_scalar($o[$key]) ? (string) $o[$key] : ''),
		esc_attr($min),
		esc_attr($max),
		esc_attr($step),
		esc_attr(mjto_default_placeholder($key)),
		'' !== $suffix ? ' ' . esc_html($suffix) : ''
	);
}

function mjto_field_check($key, $label) {
	[$o] = mjto_settings_values();
	printf(
		'<label><input type="checkbox" name="mjto_options[%1$s]" value="1" %2$s /> %3$s</label>',
		esc_attr($key),
		checked((int) (isset($o[$key]) ? $o[$key] : 0), 1, false),
		esc_html($label)
	);
}

function mjto_field_select($key, array $choices) {
	[$o] = mjto_settings_values();
	$current = isset($o[$key]) && is_scalar($o[$key]) ? (string) $o[$key] : '';
	echo '<select id="mjto_' . esc_attr($key) . '" name="mjto_options[' . esc_attr($key) . ']">';
	foreach ($choices as $value => $label) {
		printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($current, (string) $value, false), esc_html($label));
	}
	echo '</select>';
}

function mjto_field_radios($key, array $choices) {
	[$o] = mjto_settings_values();
	$current = isset($o[$key]) && is_scalar($o[$key]) ? (string) $o[$key] : '';
	foreach ($choices as $value => $label) {
		printf(
			'<label style="display:block; margin:4px 0;"><input type="radio" name="mjto_options[%1$s]" value="%2$s" %3$s /> %4$s</label>',
			esc_attr($key),
			esc_attr($value),
			checked($current, (string) $value, false),
			esc_html($label)
		);
	}
}

/** Fila de tabla: etiqueta + control + descripción. */
function mjto_field_row($label, $for, callable $render, $desc = '') {
	echo '<tr><th scope="row">';
	if ($for) {
		echo '<label for="mjto_' . esc_attr($for) . '">' . esc_html($label) . '</label>';
	} else {
		echo esc_html($label);
	}
	echo '</th><td>';
	$render();
	if ('' !== $desc) {
		echo '<p class="description">' . wp_kses($desc, ['strong' => [], 'code' => [], 'em' => [], 'br' => [], 'a' => ['href' => [], 'target' => []]]) . '</p>';
	}
	echo '</td></tr>';
}

/* -------------------------------------------------------------------------
 * Página Turnos → Ajustes
 * ---------------------------------------------------------------------- */
function mjto_render_settings_page() {
	if (!current_user_can('manage_options')) {
		if (current_user_can(MJTO_CAP)) {
			?>
			<div class="wrap">
				<h1>Turnos</h1>
				<p>Tu usuario puede administrar los turnos desde el panel.</p>
				<p><a class="button button-primary button-hero" href="<?php echo esc_url(mjto_panel_url()); ?>">Abrir el panel</a></p>
			</div>
			<?php
		}
		return;
	}
	$tabs = mjto_option_tabs();
	$tab  = mjto_settings_current_tab();
	$base = admin_url('admin.php?page=mjto');
	?>
	<div class="wrap">
		<h1>MJ Turnos — Ajustes</h1>
		<p>Todo lo que ven las clientas y cómo trabaja la agenda se configura acá. Lo que dejes vacío usa el valor en gris, salvo los campos que dicen "vacío = no se muestra". El panel del día a día está en <a href="<?php echo esc_url(mjto_panel_url()); ?>" target="_blank"><?php echo esc_html(mjto_panel_url()); ?></a> (para visitantes esa misma URL es la reserva).</p>

		<?php
		$errors = get_settings_errors('mjto_options');
		if ($errors) {
			settings_errors('mjto_options');
		} elseif (isset($_GET['settings-updated'])) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>Ajustes guardados.</p></div>';
		}
		?>

		<h2 class="nav-tab-wrapper">
			<?php foreach ($tabs as $slug => $label) : ?>
				<a class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', $slug, $base)); ?>"><?php echo esc_html($label); ?></a>
			<?php endforeach; ?>
		</h2>

		<form method="post" action="options.php">
			<?php settings_fields('mjto_options_group'); ?>
			<input type="hidden" name="mjto_options[_tab]" value="<?php echo esc_attr($tab); ?>" />
			<?php
			$renderer = 'mjto_settings_tab_' . $tab;
			if (function_exists($renderer)) {
				$renderer();
			}
			submit_button('Guardar ajustes');
			?>
		</form>
	</div>
	<?php
	mjto_media_picker_script();
}

/* ---- Agenda ---------------------------------------------------------- */
function mjto_settings_tab_agenda() {
	[$o, $d] = mjto_settings_values();
	$days    = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
	$hours   = mjto_weekly_hours();
	?>
	<h2>Horarios de atención</h2>
	<p>Una o varias franjas por día, separadas por coma. Formato <code>HH:MM-HH:MM</code>. Vacío = cerrado ese día.</p>
	<table class="form-table" role="presentation">
		<?php foreach ($days as $dow => $name) : ?>
			<tr>
				<th scope="row"><label for="mjto_wh_<?php echo (int) $dow; ?>"><?php echo esc_html($name); ?></label></th>
				<td>
					<input type="text" id="mjto_wh_<?php echo (int) $dow; ?>" name="mjto_options[weekly_hours][<?php echo (int) $dow; ?>]" value="<?php echo esc_attr($hours[$dow]); ?>" class="regular-text" placeholder="cerrado" />
					<span class="description">ej. <code>09:00-13:00, 15:00-20:00</code></span>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>

	<h2>Cómo se arman los horarios</h2>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Cada cuántos minutos', 'slot_step', fn () => mjto_field_number('slot_step', 5, 240, 5, 'minutos'), 'Separación entre los horarios que se ofrecen (ej. 30 → 09:00, 09:30, 10:00…).');
		mjto_field_row('Descanso entre turnos', 'buffer_min', fn () => mjto_field_number('buffer_min', 0, 600, 5, 'minutos'), 'Tiempo libre que se reserva después de cada turno. Un servicio puede tener el suyo propio.');
		mjto_field_row('Duración por defecto', 'default_duration_min', fn () => mjto_field_number('default_duration_min', 5, 1440, 5, 'minutos'), 'Para servicios sin duración cargada y para turnos libres desde el panel.');
		mjto_field_row('Anticipación mínima', 'min_notice_hours', fn () => mjto_field_number('min_notice_hours', 0, 720, 1, 'horas'), 'Desde la web no se puede reservar con menos anticipación que esta. Desde el panel, sí.');
		mjto_field_row('Hasta cuántos días adelante', 'max_days_ahead', fn () => mjto_field_number('max_days_ahead', 1, 365, 1, 'días'), 'El calendario de la web llega hasta hoy + estos días.');
		mjto_field_row('La semana empieza en', 'week_start', fn () => mjto_field_select('week_start', [1 => 'Lunes', 7 => 'Domingo']), 'Primera columna del calendario.');
		mjto_field_row('Días cerrados', 'closed_dates', fn () => mjto_field_textarea('closed_dates', 6, "2026-12-25 | Navidad\n2027-01-01 | Año nuevo", 'large-text code'), 'Uno por línea: <code>AAAA-MM-DD | motivo</code> (el motivo es opcional). Para vacaciones largas o franjas de un día usá los bloqueos desde el panel (Más → Bloqueos).');
		?>
	</table>
	<?php
}

/* ---- Reservas y seña ------------------------------------------------- */
function mjto_settings_tab_reservas() {
	[$o]      = mjto_settings_values();
	$currency = mjto_currency();
	?>
	<h2>Confirmación</h2>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Cuando entra una reserva', '', fn () => mjto_field_radios('confirm_mode', [
			'deposit' => 'Queda pendiente hasta que reciba la seña (recomendado)',
			'manual'  => 'Queda pendiente hasta que yo la confirme desde el panel',
			'auto'    => 'Se confirma al instante',
		]), 'Con seña: la clienta ve el monto y las instrucciones al terminar y puede avisar "ya transferí". Si la seña calculada da 0, la reserva queda pendiente como en el modo manual.');
		?>
	</table>

	<h2>Seña</h2>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Cómo se calcula', '', function () use ($currency) {
			mjto_field_radios('deposit_type', ['fixed' => 'Monto fijo', 'percent' => 'Porcentaje del precio del servicio']);
			echo '<div style="margin-top:8px;">';
			echo '<label>Monto fijo: ' . esc_html($currency['symbol']) . ' ';
			mjto_field_number('deposit_amount', 0, 99999999, 0.01);
			echo '</label> &nbsp; <label>Porcentaje: ';
			mjto_field_number('deposit_percent', 0, 100, 1, '%');
			echo '</label></div>';
		}, 'Si un servicio tiene seña propia (en su ficha), manda la del servicio. Se redondea a los decimales de la moneda.');
		mjto_field_row('Instrucciones para transferir', 'deposit_instructions', fn () => mjto_field_textarea('deposit_instructions', 5, "Alias: mi.alias.mp\nCBU: 0000000000000000000000\nTitular: Nombre Apellido"), 'Se muestran al terminar la reserva y en el mail de seña ({instrucciones_sena}). Vacío = no se muestran.');
		mjto_field_row('Link de pago (opcional)', 'deposit_link', fn () => mjto_field_text('deposit_link', 'https://link.mercadopago.com.ar/…', 'large-text', 'url'), 'Si lo cargás, aparece el botón "Pagar la seña online" ({link_pago}).');
		mjto_field_row('Plazo para enviar la seña', 'deposit_hours', fn () => mjto_field_number('deposit_hours', 0, 720, 1, 'horas'), 'Pasado el plazo sin aviso de la clienta, el turno se cancela solo y avisa por email. <strong>0 = sin vencimiento</strong> (nunca se cancela sola).');
		?>
	</table>

	<h2>Cancelación por la clienta</h2>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Permitir', '', fn () => mjto_field_check('client_can_cancel', 'La clienta puede cancelar su turno desde la página de estado'), '');
		mjto_field_row('Hasta cuántas horas antes', 'cancel_hours_before', fn () => mjto_field_number('cancel_hours_before', 0, 720, 1, 'horas'), 'Más cerca del turno, la web le pide que escriba por WhatsApp.');
		?>
	</table>

	<h2>Antes de reservar</h2>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Política / indicaciones', 'booking_policy', fn () => mjto_field_textarea('booking_policy', 5, 'Ej: Vení con el pelo lavado y sin productos. Si no podés venir, avisá con 24 h de anticipación para que le demos el lugar a otra persona.'), 'Se muestra en el paso de datos, bajo el título "' . esc_html(mjto_opt('booking_policy_title')) . '" (editable en Textos). Vacío = no se muestra.');
		mjto_field_row('Límite anti-spam', 'rate_limit', fn () => mjto_field_number('rate_limit', 1, 1000, 1, 'reservas por IP cada 10 minutos'), 'Protege el formulario público de robots. Un número bajo alcanza.');
		?>
	</table>
	<?php
}

/* ---- Notificaciones -------------------------------------------------- */
function mjto_settings_tab_notificaciones() {
	[$o, $d] = mjto_settings_values();
	?>
	<h2>Quién recibe qué</h2>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Tu email', 'owner_email', fn () => mjto_field_text('owner_email', (string) get_option('admin_email'), 'regular-text', 'email'), 'Ahí llegan los avisos y es el "responder a" de los mails a las clientas. Vacío = el email del administrador.');
		mjto_field_row('Avisarme por email', '', function () {
			mjto_field_check('owner_notify_new', 'Cuando entra una reserva nueva');
			echo '<br>';
			mjto_field_check('owner_notify_cancel', 'Cuando una clienta cancela');
			echo '<br>';
			mjto_field_check('owner_notify_deposit_notice', 'Cuando una clienta avisa que transfirió la seña');
		}, '');
		mjto_field_row('Mails a la clienta', '', function () {
			mjto_field_check('client_email_enabled', 'Enviar mails a la clienta (recibida / seña / confirmado / cancelado)');
			echo '<br>';
			mjto_field_check('reminder_enabled', 'Enviar recordatorio antes del turno');
		}, 'Solo si la clienta dejó su email. El recordatorio se manda una vez, con la anticipación de abajo (el sitio revisa cada hora).');
		mjto_field_row('Recordatorio: horas antes', 'reminder_hours_before', fn () => mjto_field_number('reminder_hours_before', 1, 720, 1, 'horas'), '');
		mjto_field_row('Nombre del remitente', 'mail_from_name', fn () => mjto_field_text('mail_from_name', mjto_business_name()), 'Vacío = nombre del negocio.');
		?>
	</table>

	<h2>Motivos de cancelación automáticos</h2>
	<p>Se guardan como motivo del turno y llegan a la clienta y a vos como <code>{motivo}</code> en los mails de cancelación.</p>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Cuando cancela la clienta', 'cancel_reason_client', fn () => mjto_field_text('cancel_reason_client', null, 'large-text'), 'Desde su link de estado.');
		mjto_field_row('Cuando vence la seña', 'cancel_reason_deposit_expired', fn () => mjto_field_text('cancel_reason_deposit_expired', null, 'large-text'), 'Cuando pasa el plazo de la seña sin aviso y el turno se cancela solo.');
		?>
	</table>

	<h2>Plantillas</h2>
	<p>Podés usar estos comodines en asuntos y textos; se reemplazan por los datos del turno:</p>
	<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:2px 24px; max-width:1000px; margin-bottom:16px;">
		<?php foreach (mjto_placeholder_list() as $ph => $desc) : ?>
			<div><code><?php echo esc_html($ph); ?></code> <span class="description"><?php echo esc_html($desc); ?></span></div>
		<?php endforeach; ?>
	</div>

	<?php
	$mail_groups = [
		'Mails para vos' => [
			'owner_new'            => 'Reserva nueva',
			'owner_cancel'         => 'Cancelación de una clienta',
			'owner_deposit_notice' => 'Aviso de seña transferida',
		],
		'Mails para la clienta' => [
			'client_received'  => 'Reserva recibida (modo manual: queda pendiente)',
			'client_deposit'   => 'Esperando la seña',
			'client_confirmed' => 'Turno confirmado',
			'client_reminder'  => 'Recordatorio',
			'client_cancelled' => 'Turno cancelado',
		],
	];
	foreach ($mail_groups as $group => $templates) :
		?>
		<h3><?php echo esc_html($group); ?></h3>
		<table class="form-table" role="presentation">
			<?php foreach ($templates as $base => $label) : ?>
				<tr>
					<th scope="row"><label for="mjto_tpl_<?php echo esc_attr($base); ?>_subject"><?php echo esc_html($label); ?></label></th>
					<td>
						<?php mjto_field_text("tpl_{$base}_subject", null, 'large-text'); ?>
						<p class="description" style="margin:2px 0 6px;">asunto</p>
						<?php mjto_field_textarea("tpl_{$base}_body", 9); ?>
						<p class="description" style="margin-top:2px;">cuerpo (texto plano)</p>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
	<?php endforeach; ?>

	<h3>Mensajes de WhatsApp</h3>
	<p>Se abren desde el panel con el mensaje listo para mandar (vos lo revisás antes de enviarlo). El último es el que manda la clienta desde la web con el comprobante de la seña.</p>
	<table class="form-table" role="presentation">
		<?php
		$wa = [
			'tpl_wa_confirm'        => 'Confirmar turno',
			'tpl_wa_deposit'        => 'Pedir la seña',
			'tpl_wa_reminder'       => 'Recordatorio',
			'tpl_wa_control'        => 'Control post servicio',
			'tpl_wa_retouch'        => 'Retoque sugerido',
			'tpl_wa_generic'        => 'Mensaje libre',
			'tpl_wa_client_deposit' => 'La clienta manda el comprobante',
		];
		foreach ($wa as $key => $label) {
			mjto_field_row($label, $key, fn () => mjto_field_textarea($key, 3), '');
		}
		?>
	</table>
	<?php
}

/* ---- Textos y páginas ------------------------------------------------ */

/** Etiquetas humanas de los textos públicos (para la pestaña Textos). */
function mjto_copy_field_labels() {
	return [
		'booking' => [
			'booking_eyebrow'              => 'Antetítulo',
			'booking_title'                => 'Título',
			'booking_subtitle'             => 'Subtítulo',
			'booking_step_service'         => 'Paso 1 (servicio)',
			'booking_step_date'            => 'Paso 2 (día y hora)',
			'booking_step_details'         => 'Paso 3 (datos)',
			'booking_step_done'            => 'Paso 4 (listo)',
			'booking_pick_day'             => 'Elegir día',
			'booking_pick_time'            => 'Elegir horario',
			'booking_no_slots'             => 'Sin horarios ese día',
			'booking_no_days'              => 'Sin días en el mes',
			'booking_summary_title'        => 'Título del resumen',
			'booking_next'                 => 'Botón continuar',
			'booking_back'                 => 'Botón volver',
			'booking_submit'               => 'Botón confirmar reserva',
			'booking_policy_title'         => 'Título de la política',
			'booking_success_title'        => 'Título al terminar',
			'booking_success_pending'      => 'Mensaje: queda pendiente',
			'booking_success_deposit'      => 'Mensaje: falta la seña',
			'booking_success_auto'         => 'Mensaje: confirmado al instante',
			'booking_deposit_title'        => 'Título de la tarjeta de seña',
			'booking_deposit_amount_label' => 'Etiqueta del monto',
			'booking_deposit_deadline'     => 'Plazo de la seña ({fecha_limite})',
			'booking_notified_button'      => 'Botón "ya transferí"',
			'booking_notified_done'        => 'Mensaje tras avisar',
			'booking_whatsapp_button'      => 'Botón comprobante por WhatsApp',
			'booking_pay_link_button'      => 'Botón pagar online',
			'booking_status_link'          => 'Enlace al estado del turno',
			'booking_cancel_button'        => 'Botón cancelar',
			'booking_cancel_confirm'       => 'Pregunta al cancelar',
			'booking_cancelled_text'       => 'Mensaje: turno cancelado',
			'booking_cannot_cancel'        => 'Mensaje: ya no se puede cancelar',
			'booking_login_hint'           => 'Enlace para la dueña (sin sesión)',
			'booking_panel_link'           => 'Enlace al panel (con sesión)',
			'booking_retry'                => 'Botón reintentar',
			'booking_error_required'       => 'Validación: campo vacío',
			'booking_error_email'          => 'Validación: email',
			'booking_error_phone'          => 'Validación: teléfono',
			'booking_error_number'         => 'Validación: número',
			'booking_slots_morning'        => 'Franja: mañana',
			'booking_slots_afternoon'      => 'Franja: tarde',
			'booking_slots_evening'        => 'Franja: noche',
			'booking_status_title'         => 'Título de la página de estado',
			'booking_status_not_found'     => 'Mensaje: turno no encontrado',
			'booking_new_booking'          => 'Enlace "hacer otra reserva"',
			'booking_whatsapp_label'       => 'Etiqueta del botón de WhatsApp',
		],
		'services' => [
			'services_eyebrow'     => 'Antetítulo',
			'services_title'       => 'Título',
			'services_subtitle'    => 'Subtítulo',
			'services_book_button' => 'Botón reservar',
			'services_empty'       => 'Sin servicios cargados',
		],
		'labels' => [
			'label_duration' => 'Duración',
			'label_price'    => 'Precio',
			'label_deposit'  => 'Seña',
			'label_from'     => '"desde" (precio orientativo)',
			'label_minutes'  => 'Abreviatura de minutos',
			'label_hours'    => 'Abreviatura de horas',
		],
	];
}

/** Etiquetas humanas de los mensajes de error (opciones msg_*), agrupados. */
function mjto_message_field_labels() {
	return [
		'Al reservar desde la web' => [
			'required'               => 'Campo obligatorio vacío',
			'invalid_email'          => 'Email inválido',
			'invalid_phone'          => 'Teléfono inválido',
			'invalid_number'         => 'Número inválido',
			'invalid_option'         => 'Opción inválida',
			'too_long'               => 'Texto demasiado largo',
			'invalid_service'        => 'Servicio inválido',
			'invalid_service_public' => 'Servicio que no se reserva online',
			'invalid_date'           => 'Día u horario inválidos',
			'invalid_range'          => 'Rango de fechas inválido',
			'out_of_range'           => 'Día fuera del calendario',
			'slot_taken'             => 'Horario que se acaba de ocupar',
			'busy'                   => 'Otra reserva en curso',
			'spam'                   => 'Reserva rechazada (anti-spam)',
			'rate_limited'           => 'Demasiados intentos',
			'booking_failed'         => 'No se pudo guardar la reserva',
		],
		'En la página de estado del turno' => [
			'not_found'           => 'Turno no encontrado',
			'already_cancelled'   => 'Turno ya cancelado',
			'deposit_not_pending' => 'Turno que no espera seña',
		],
		'En el panel' => [
			'client_required'  => 'Faltan nombre o teléfono',
			'client_not_found' => 'Clienta no encontrada',
			'client_mismatch'  => 'Turno de otra clienta',
			'invalid_status'   => 'Estado inexistente',
			'visit_not_found'  => 'Visita no encontrada',
			'db'               => 'Error al guardar',
		],
	];
}

function mjto_settings_tab_textos() {
	[$o, $d]     = mjto_settings_values();
	$labels      = mjto_copy_field_labels();
	$theme       = function_exists('mj_get_options') ? mj_get_options() : [];
	$has_theme   = function_exists('mj_get_options');
	$theme_addr  = (string) ($theme['contact_address'] ?? '');
	$theme_wa    = (string) ($theme['contact_whatsapp'] ?? '');
	$site_slug   = mjto_page_slug();
	?>
	<h2>El negocio</h2>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Nombre', 'business_name', fn () => mjto_field_text('business_name', (string) get_bloginfo('name')), 'Vacío = nombre del sitio. Se usa en mails, WhatsApp y en el panel ({negocio}).');
		mjto_field_row('Dirección', 'business_address', fn () => mjto_field_text('business_address', $theme_addr, 'large-text'), $has_theme ? 'Vacío = la dirección cargada en el tema MJ Appearance.' : 'Se usa en los mails ({direccion}).');
		mjto_field_row('WhatsApp', 'business_whatsapp', fn () => mjto_field_text('business_whatsapp', '' !== $theme_wa ? $theme_wa : '54 9 11 5555 1234'), $has_theme ? 'Número al que escriben las clientas (comprobantes, cambios). Vacío = el WhatsApp del tema.' : 'Número al que escriben las clientas (comprobantes, cambios).');
		mjto_field_row('Instagram', 'business_instagram', fn () => mjto_field_text('business_instagram', 'https://instagram.com/…'), '');
		mjto_field_row('Código de país y prefijo de WhatsApp', '', function () {
			echo '<label>País: ';
			mjto_field_text('whatsapp_country', null, 'small-text');
			echo '</label> &nbsp; <label>Prefijo para celulares: ';
			mjto_field_text('whatsapp_prefix', null, 'small-text');
			echo '</label>';
		}, 'Para armar los links de WhatsApp. Argentina: país <code>54</code>, prefijo <code>549</code>. Si el número ya empieza con el código de país, se deja como está.');
		?>
	</table>

	<h2>Páginas y menú</h2>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Página de turnos (URL)', 'page_slug', fn () => mjto_field_text('page_slug'), 'La reserva vive en /<strong>' . esc_html($site_slug) . '</strong>. Vos, con tu sesión iniciada, en esa misma URL ves el panel (con <code>?vista=publica</code> ves la reserva).');
		mjto_field_row('En el menú', '', function () {
			mjto_field_text('menu_label');
			echo '<br><span style="display:inline-block; margin-top:8px;">';
			mjto_field_check('show_in_menu', 'Mostrar en el menú principal');
			echo '</span><br>';
			mjto_field_check('show_bottom_nav', 'Mostrar en la barra inferior del celular');
		}, 'Texto del enlace y dónde aparece (tema MJ Appearance).');
		mjto_field_row('Botón en la cabecera', '', function () {
			mjto_field_check('header_cta_enabled', 'Mostrar un botón destacado en la cabecera');
			echo '<br>';
			mjto_field_text('header_cta');
		}, 'Texto del botón (lleva a la reserva).');
		mjto_field_row('Página de servicios', '', function () {
			mjto_field_check('services_page_enabled', 'Tener una página con todos los servicios');
			echo '<br><label style="display:inline-block; margin-top:8px;">URL: /';
			mjto_field_text('services_slug');
			echo '</label><br><label style="display:inline-block; margin-top:8px;">En el menú: ';
			mjto_field_text('services_menu_label');
			echo '</label><br><span style="display:inline-block; margin-top:8px;">';
			mjto_field_check('services_in_menu', 'Mostrar en el menú principal');
			echo '</span>';
		}, '');
		?>
	</table>

	<h2>Portada</h2>
	<p>Dos secciones para la portada del tema MJ Appearance (se ordenan y ocultan desde Apariencia → MJ Appearance → Secciones de la portada).</p>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Sección de servicios', '', function () {
			mjto_field_check('home_services_enabled', 'Mostrar los servicios en la portada');
			echo '<br>';
			mjto_field_text('home_services_eyebrow');
			echo ' <span class="description">antetítulo</span><br>';
			mjto_field_text('home_services_title', null, 'large-text');
			echo ' <span class="description">título</span><br>';
			mjto_field_text('home_services_subtitle', null, 'large-text');
			echo ' <span class="description">subtítulo (vacío = sin subtítulo)</span><br>';
			mjto_field_text('home_services_link');
			echo ' <span class="description">enlace a la página de servicios (vacío = sin enlace)</span><br>';
			echo '<label style="display:inline-block; margin-top:6px;">Cuántos: ';
			mjto_field_number('home_services_count', 1, 12, 1);
			echo '</label>';
		}, '');
		mjto_field_row('Sección de reserva', '', function () use ($o) {
			mjto_field_check('home_booking_enabled', 'Mostrar el banner "reservá tu turno" en la portada');
			echo '<br>';
			mjto_field_text('home_booking_eyebrow');
			echo ' <span class="description">antetítulo</span><br>';
			mjto_field_text('home_booking_title', null, 'large-text');
			echo ' <span class="description">título</span><br>';
			mjto_field_text('home_booking_subtitle', null, 'large-text');
			echo ' <span class="description">subtítulo (vacío = sin subtítulo)</span><br>';
			mjto_field_text('home_booking_button');
			echo ' <span class="description">botón</span>';
			echo '<div style="margin-top:10px;">';
			mjto_render_media_picker('mjto_options[home_booking_image]', $o['home_booking_image'], 'Elegir imagen…', 'Foto del banner (opcional). Vacío = banner con el degradado del sitio.');
			echo '</div>';
		}, '');
		?>
	</table>

	<h2>Textos de la reserva</h2>
	<table class="form-table" role="presentation">
		<?php foreach ($labels['booking'] as $key => $label) : ?>
			<?php mjto_field_row($label, $key, fn () => mjto_field_text($key, null, 'large-text'), mjto_optional_text_desc($key)); ?>
		<?php endforeach; ?>
	</table>

	<h2>Página de servicios</h2>
	<table class="form-table" role="presentation">
		<?php foreach ($labels['services'] as $key => $label) : ?>
			<?php mjto_field_row($label, $key, fn () => mjto_field_text($key, null, 'large-text'), mjto_optional_text_desc($key)); ?>
		<?php endforeach; ?>
	</table>

	<h2>Mensajes</h2>
	<p>Los avisos de error que ve la clienta al reservar o al abrir el link de su turno (y los del panel). Donde aparece <code>%s</code> se reemplaza por el nombre del campo (por ejemplo "Completá el campo "Email""). El de "ya no se puede cancelar" está arriba, en Textos de la reserva.</p>
	<?php foreach (mjto_message_field_labels() as $group => $messages) : ?>
		<h3><?php echo esc_html($group); ?></h3>
		<table class="form-table" role="presentation">
			<?php foreach ($messages as $key => $label) : ?>
				<?php
				$has_field = false !== strpos((string) ($d['msg_' . $key] ?? ''), '%s');
				mjto_field_row($label, 'msg_' . $key, fn () => mjto_field_text('msg_' . $key, null, 'large-text'), $has_field ? '<code>%s</code> = nombre del campo.' : '');
				?>
			<?php endforeach; ?>
		</table>
	<?php endforeach; ?>

	<h2>Etiquetas y precios</h2>
	<table class="form-table" role="presentation">
		<?php foreach ($labels['labels'] as $key => $label) : ?>
			<?php mjto_field_row($label, $key, fn () => mjto_field_text($key), ''); ?>
		<?php endforeach; ?>
		<?php mjto_field_row('Precios', '', fn () => mjto_field_check('price_visible', 'Mostrar los precios en la web (cada servicio puede ocultar el suyo)'), ''); ?>
	</table>

	<h2>Nombres de los estados</h2>
	<table class="form-table" role="presentation">
		<?php foreach (mjto_default_status_labels() as $status => $default) : ?>
			<tr>
				<th scope="row"><label for="mjto_status_<?php echo esc_attr($status); ?>"><code><?php echo esc_html($status); ?></code></label></th>
				<td><input type="text" id="mjto_status_<?php echo esc_attr($status); ?>" name="mjto_options[status_labels][<?php echo esc_attr($status); ?>]" value="<?php echo esc_attr($o['status_labels_raw'][$status] ?? ''); ?>" class="regular-text" placeholder="<?php echo esc_attr(mjto_status_label($status)); ?>" /></td>
			</tr>
		<?php endforeach; ?>
	</table>
	<?php
}

/* ---- Formulario y ficha (constructor de campos) ---------------------- */

/** Constructor React + textarea JSON (edición avanzada, colapsada). */
function mjto_builder_markup($kind, $option_key, array $value) {
	$target = 'mjto-' . $kind . '-json';
	$json   = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if (!file_exists(MJTO_DIR . 'dist/app.js')) {
		echo '<div class="notice notice-warning inline"><p>Falta compilar la app del plugin (<code>app/</code> → <code>npm run build</code>): mientras tanto podés editar el JSON de abajo.</p></div>';
	}
	?>
	<div id="mjto-fields-builder" data-kind="<?php echo esc_attr($kind); ?>" data-target="<?php echo esc_attr($target); ?>" style="max-width:1000px; margin:12px 0;"></div>
	<details style="max-width:1000px; margin-top:12px;">
		<summary style="cursor:pointer; font-weight:600;">Edición avanzada (JSON)</summary>
		<p class="description">Es lo que guarda el constructor. Si lo editás a mano y queda inválido, se conserva la versión anterior.</p>
		<textarea id="<?php echo esc_attr($target); ?>" name="mjto_options[<?php echo esc_attr($option_key); ?>]" rows="18" class="large-text code" spellcheck="false"><?php echo esc_textarea($json); ?></textarea>
	</details>
	<?php
}

function mjto_settings_tab_formulario() {
	?>
	<h2>Formulario de la reserva</h2>
	<p>Los datos que se le piden a la clienta en el paso "<?php echo esc_html(mjto_opt('booking_step_details')); ?>". Los campos de sistema (nombre, apellido, teléfono, email, localidad, comentario) se pueden renombrar o desactivar; <strong>nombre y teléfono</strong> son siempre obligatorios. Los campos que agregues se guardan en la ficha de la clienta.</p>
	<?php mjto_builder_markup('form', 'form_fields_json', mjto_form_fields()); ?>
	<?php
}

function mjto_settings_tab_ficha() {
	?>
	<h2>Ficha técnica</h2>
	<p>Lo que completás en cada visita desde el panel: bloques con campos (opciones, texto, montos, fechas). Una opción marcada como <strong>advertencia</strong> muestra un aviso al elegirla y queda en la ficha. Los campos con <strong>precargar</strong> arrancan con lo que pusiste en la visita anterior. Los <strong>roles</strong> conectan campos con la caja (total, seña, método de pago) y con las fechas sugeridas (control, retoque).</p>
	<?php mjto_builder_markup('ficha', 'ficha_blocks_json', mjto_ficha_blocks()); ?>

	<h2>Caja y visitas</h2>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Métodos de pago', 'payment_methods', fn () => mjto_field_textarea('payment_methods', 4, null, 'regular-text'), 'Uno por línea. Los usa el campo con rol "método de pago" si no tiene opciones propias, y el resumen de la caja.');
		mjto_field_row('Visitas nuevas', '', fn () => mjto_field_check('visit_prefill_carry', 'Precargar los campos marcados "precargar" con la última visita de la clienta'), '');
		?>
	</table>
	<?php
}

/* ---- Panel ----------------------------------------------------------- */

/** Acento efectivo del panel: el propio, el del tema si existe, o fucsia. */
function mjto_panel_accent() {
	$own = sanitize_hex_color((string) mjto_opt('panel_accent'));
	if ($own) {
		return $own;
	}
	if (function_exists('mj_get_options')) {
		$theme = mj_get_options();
		$hex   = sanitize_hex_color((string) ($theme['color_accent'] ?? ''));
		if (!$hex && function_exists('mj_color_defaults')) {
			$hex = sanitize_hex_color((string) (mj_color_defaults()['color_accent'] ?? ''));
		}
		if ($hex) {
			return $hex;
		}
	}
	return '#e11d84';
}

/** Título efectivo del panel. */
function mjto_panel_title() {
	$title = trim((string) mjto_opt('panel_title'));
	return '' !== $title ? $title : mjto_business_name();
}

function mjto_settings_tab_panel() {
	[$o]      = mjto_settings_values();
	$roles    = wp_roles()->roles;
	$chosen   = mjto_panel_roles();
	$has_woo  = function_exists('get_woocommerce_currency_symbol');
	$currency = mjto_currency();
	?>
	<h2>El panel</h2>
	<p>Es tu herramienta del día a día: agenda, clientas, fichas y caja. Se abre en <a href="<?php echo esc_url(mjto_panel_url()); ?>" target="_blank"><?php echo esc_html(mjto_panel_url()); ?></a> con tu sesión iniciada (también desde la barra de arriba, "Turnos").</p>
	<table class="form-table" role="presentation">
		<?php
		mjto_field_row('Título', 'panel_title', fn () => mjto_field_text('panel_title', mjto_business_name()), 'Vacío = nombre del negocio.');
		mjto_field_row('Quién más puede entrar', '', function () use ($roles, $chosen) {
			echo '<fieldset>';
			foreach ($roles as $slug => $role) {
				if ('administrator' === $slug) {
					continue;
				}
				printf(
					'<label style="display:block; margin:4px 0;"><input type="checkbox" name="mjto_options[panel_roles][]" value="%1$s" %2$s /> %3$s</label>',
					esc_attr($slug),
					checked(in_array($slug, $chosen, true), true, false),
					esc_html(translate_user_role($role['name']))
				);
			}
			echo '</fieldset>';
		}, 'Los administradores siempre pueden. Los roles tildados reciben el permiso <code>' . MJTO_CAP . '</code> (abrir el panel y usar su API); no tocan el resto de WordPress.');
		mjto_field_row('Apariencia', 'panel_theme', fn () => mjto_field_select('panel_theme', ['theme' => 'Como el sitio (tema MJ Appearance)', 'light' => 'Clara (día)', 'dark' => 'Oscura (noche)']), 'Con "como el sitio" sigue el modo elegido en el tema; sin el tema, clara.');
		mjto_field_row('Color de acento', 'panel_accent', fn () => mjto_field_text('panel_accent', mjto_panel_accent(), 'small-text', 'text'), 'Hexadecimal, ej. <code>#e11d84</code>. Vacío = acento del tema (o fucsia si no hay tema).');
		mjto_field_row('Caja', '', fn () => mjto_field_check('caja_enabled', 'Mostrar la pestaña Caja (cobrado, señas, métodos de pago, export)'), '');
		?>
	</table>

	<h2>Moneda</h2>
	<table class="form-table" role="presentation">
		<?php if ($has_woo) : ?>
			<tr>
				<th scope="row">Formato</th>
				<td><p>Se usa la moneda de WooCommerce: <strong><?php echo esc_html($currency['symbol'] . ' (' . $currency['code'] . ')'); ?></strong>, <?php echo (int) $currency['decimals']; ?> decimales. Se cambia en WooCommerce → Ajustes → General.</p></td>
			</tr>
		<?php else : ?>
			<?php
			mjto_field_row('Símbolo', 'currency_symbol', fn () => mjto_field_text('currency_symbol', null, 'small-text'), 'Sin WooCommerce, los precios se muestran con este símbolo.');
			mjto_field_row('Decimales', 'currency_decimals', fn () => mjto_field_number('currency_decimals', 0, 4, 1), 'Separador de miles "." y de decimales ",".');
			?>
		<?php endif; ?>
	</table>
	<?php
}
