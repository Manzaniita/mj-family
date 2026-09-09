# MJ Turnos Online

Reserva de turnos online con agenda, ficha técnica por clienta, seña, recordatorios y un panel pensado para el celular. No necesita WooCommerce.

## Cómo funciona

1. **Servicios**: en *Turnos → Servicios* cargás cada tratamiento con su duración, precio (visible o no), seña propia (opcional), color y foto. Con la duración se calculan los horarios libres.
2. **La clienta reserva** en `/turnos` (o donde pongas el shortcode): elige servicio, día y hora, deja sus datos y recibe la confirmación por email. Según el modo elegido, el turno queda:
   - **Pendiente hasta recibir la seña** (por defecto): ve el monto, tus instrucciones para transferir y un botón "Ya transferí la seña" que te avisa; si no llega la seña a tiempo, el turno se cancela solo.
   - **Pendiente hasta que confirmes vos** (manual).
   - **Confirmado al instante** (auto).
3. **Vos entrás a la misma URL** (`/turnos`) con tu sesión de WordPress y ves el **panel**: la agenda del día, los turnos, las clientas con su ficha, la caja y los bloqueos. También desde la barra de arriba ("Turnos") o desde *Turnos → Abrir el panel*. Si querés ver la reserva como la ve una clienta: `/turnos?vista=publica`.
4. **Cada turno confirmado crea una ficha** de esa visita (historial químico, análisis de la fibra, trabajo técnico, caja y seguimiento). Las opciones marcadas como advertencia (por ejemplo, elasticidad crítica) aparecen resaltadas. La ficha de la clienta es su línea de tiempo de visitas: la próxima vez ya tenés precargado lo que se repite.
5. **Recordatorios**: el día anterior (o las horas que elijas) se manda un email automático a las clientas con turno confirmado. Desde el panel también mandás WhatsApp con un toque: confirmar, pedir la seña, recordar, control post servicio, retoque.

## Instalación

- Subí el zip desde *Plugins → Añadir nuevo → Subir plugin* y activalo. Se crean las tablas, el permiso `mjto_manage` para las administradoras y la tarea horaria (recordatorios y señas vencidas).
- **Con el tema MJ Appearance (3.5 o superior)**: no hay que crear ninguna página. Aparecen solas la página `/turnos`, la de `/servicios`, el ítem del menú, el botón "Reservar" del header y dos secciones en la portada (se ordenan y ocultan en *Apariencia → MJ Appearance → Portada*).
- **Con cualquier otro tema**: creá una página (por ejemplo "Turnos", con la URL `/turnos`) y pegale el shortcode `[mj_turnos]`. Para preseleccionar un servicio: `[mj_turnos servicio="alisado-organico"]`. El panel funciona igual en esa URL.
- Si en el sitio no llegan los emails, instalá un plugin de SMTP: el plugin usa el correo de WordPress.

## Qué configurar (Turnos → Ajustes)

- **Agenda**: horarios por día (`09:00-13:00, 15:00-20:00`; vacío = cerrado), cada cuántos minutos se ofrecen horarios, descanso entre turnos, anticipación mínima, hasta cuántos días adelante, duración por defecto, días cerrados (feriados, vacaciones) y con qué día empieza la semana.
- **Reservas y seña**: modo de confirmación, seña fija o porcentaje, instrucciones para transferir (alias, CBU, titular), link de pago opcional, horas para enviarla, si la clienta puede cancelar y con cuánta anticipación, texto de política, límite anti-spam.
- **Notificaciones**: tu email, qué avisos querés recibir, recordatorio y todas las plantillas de email y WhatsApp (con los placeholders listados ahí mismo).
- **Textos y páginas**: nombre, dirección y WhatsApp del negocio (si están vacíos usa los del tema), URL de la página de turnos, etiquetas del menú y del header, textos de la portada y de la reserva, página de servicios, nombres de los estados, si se muestra el precio.
- **Formulario**: qué datos pide la reserva (nombre y teléfono son obligatorios siempre; podés renombrar, desactivar o agregar campos).
- **Ficha técnica**: los bloques y campos de la ficha, con opciones y advertencias. Viene armada para alisados; se puede restaurar la ficha por defecto.
- **Panel**: título, quién más puede entrar (roles), apariencia clara u oscura, color de acento, si se muestra la caja, y la moneda (si no tenés WooCommerce).

## El panel

- **Hoy**: la agenda del día con navegación por fecha. En cada turno: confirmar, registrar la seña, abrir la ficha, WhatsApp con plantillas, reprogramar, marcar atendida o "no vino", cancelar (con motivo y aviso opcional), eliminar. Botón "Nuevo turno" para cargar reservas por teléfono (podés elegir "otro horario" fuera de la grilla).
- **Turnos**: listado por rangos (hoy, mañana, semana, mes, próximos, pasados, personalizado), filtros por estado y buscador.
- **Clientas**: buscador por nombre o teléfono, filtros por año y mes, orden. Tocando una clienta se abre su **ficha**: datos, advertencias de la última visita, sus visitas (con el detalle de cada una) y sus turnos. "Nueva visita" carga una visita sin turno.
- **Caja**: cobrado, señas y visitas del mes, desglose por método de pago y exportación a CSV.
- **Más**: bloqueos de agenda (un día, varios días o una franja), exportar clientas/visitas/turnos, enlaces a la reserva pública, a los ajustes y a los servicios, cerrar sesión.

## Prueba corta

1. Cargá un servicio en *Turnos → Servicios* (por ejemplo "Alisado" de 120 minutos) y publicalo.
2. En *Turnos → Ajustes → Reservas y seña* dejá el modo "pendiente hasta recibir la seña", poné un monto y tus instrucciones para transferir.
3. Abrí `/turnos?vista=publica` (o la página con el shortcode) en una ventana de incógnito y reservá con un email tuyo. Tenés que ver el paso final con la seña y recibir dos emails: el tuyo de "nueva reserva" y el de la clienta pidiendo la seña.
4. Tocá "Ya transferí la seña": te llega un aviso por email.
5. Entrá a `/turnos` con tu usuario: el turno aparece en *Hoy* (o en la fecha que elegiste) con la marca de "avisó la seña". Tocá "Seña recibida", cargá el monto y confirmá: la clienta recibe el email de confirmación y se crea la ficha.
6. Abrí la ficha, completá algo (probá marcar "Crítica" en Elasticidad para ver la advertencia) y guardá. En *Caja* tiene que aparecer el total.
7. Desde el panel, mandate un WhatsApp de prueba con la plantilla "Recordatorio".

## Bueno saber

- Al desactivar el plugin no se borra nada: las clientas, turnos, fichas y ajustes quedan en la base para cuando lo vuelvas a activar.
- Los horarios de la agenda y de los turnos usan la zona horaria configurada en *Ajustes → Generales* de WordPress. Revisá que sea la de tu ciudad.
- Los emails automáticos salen con el nombre del negocio y responden a tu email (el de *Notificaciones*).
- Los recordatorios y la cancelación de señas vencidas corren con la tarea programada de WordPress (cada hora, cuando hay visitas al sitio). Si tu hosting permite un cron real, mejor.
- Las exportaciones son CSV con separador `;`, listas para abrir en Excel en español.
- El límite anti-spam cuenta las **reservas creadas** por IP cada 10 minutos (el número de *Reservas y seña*) y, aparte, frena a quien insiste con 4 veces esa cantidad de intentos fallidos. Por seguridad usa solo la IP con la que llega la conexión (`REMOTE_ADDR`), sin mirar cabeceras de proxy, que cualquiera puede falsificar. Si tu hosting está detrás de un proxy o CDN (Cloudflare, Nginx como reverse proxy, un balanceador) y no reescribe esa IP, todas las visitantes comparten la misma y el límite se vuelve global. En ese caso, primero fijate si el hosting tiene una opción para "restaurar la IP real"; si no, agregá en un plugin propio o en el `functions.php` del tema hijo un filtro que tome la cabecera **solo de tu proxy de confianza** (el plugin descarta lo que no sea una IP válida):

  ```php
  add_filter('mjto_client_ip', function ($ip) {
      // Cloudflare manda la IP real en esta cabecera. Con Nginx/balanceador suele ser HTTP_X_REAL_IP o el primer valor de HTTP_X_FORWARDED_FOR.
      if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
          return sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
      }
      return $ip;
  });
  ```

  Usalo solo si todo el tráfico entra por ese proxy: si el servidor también acepta conexiones directas, una persona podría inventar la cabecera y saltarse el límite.
