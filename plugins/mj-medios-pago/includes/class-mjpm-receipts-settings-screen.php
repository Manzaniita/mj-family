<?php
/**
 * Pestaña "Ajustes" de la pantalla de comprobantes.
 *
 * Tres formularios, en el orden en que se piensa el problema:
 *
 *   1. Regla general: qué se pide, qué archivos, plazos, qué pasa al aprobar,
 *      quién revisa, qué mails salen, cuánto se guarda.
 *   2. Regla por medio de pago: cuál de ellos pide comprobante, y con qué texto
 *      y qué estado al aprobar. Lo que quede en "Heredar" sigue al general.
 *   3. Equivalencias con MJ ERP: qué método del ERP es cada gateway, para
 *      configurar el comprobante una sola vez y que valga también en reventas.
 *
 * Nada tiene el valor escrito en el código de la interfaz: todo sale de
 * MJPM_Settings::receipt_defaults() y de lo guardado.
 */

defined('ABSPATH') || exit;

class MJPM_Receipts_Settings_Screen {

	public static function render() {
		$s    = MJPM_Settings::receipts();
		$noun = MJPM_Receipts::noun();

		self::general($s, $noun);
		self::per_method();
		self::mapping();
	}

	private static function post_url() {
		return admin_url('admin-post.php');
	}

	/* ---------------------------- Regla general ---------------------------- */

	private static function general(array $s, $noun) {
		$statuses = MJPM_Settings::order_statuses();
		$limit    = MJPM_Vault::server_limit();
		?>
		<form method="post" action="<?php echo esc_url(self::post_url()); ?>" class="mjpm-rc-card">
			<input type="hidden" name="action" value="mjpm_save_receipts">
			<?php wp_nonce_field('mjpm_save_receipts'); ?>

			<h2>Regla general</h2>
			<p class="mjpm-rc-bajada">Esto es lo que vale para todos los medios de pago que estén en “Heredar”. Cambiarlo acá alcanza a todos los que nunca pisaste a mano.</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Función</th>
					<td>
						<label><input type="checkbox" name="receipts[enabled]" value="1" <?php checked($s['enabled'], 1); ?>> Pedir <?php echo esc_html(MJPM_Receipts::noun(true)); ?> de pago</label>
						<p class="description">Apagado, nada de esto se le muestra al cliente y la bandeja queda vacía. Lo ya subido no se borra.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjpm-noun">Cómo se llama</label></th>
					<td>
						<input type="text" id="mjpm-noun" name="receipts[noun]" value="<?php echo esc_attr($s['noun']); ?>" class="regular-text" maxlength="40">
						<input type="text" name="receipts[noun_plural]" value="<?php echo esc_attr($s['noun_plural']); ?>" class="regular-text" maxlength="40" aria-label="Plural">
						<p class="description">Singular y plural. Se usa en toda la interfaz, en los mails y en el menú: “comprobante”, “transferencia”, “constancia”…</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjpm-mode">Por defecto</label></th>
					<td>
						<select id="mjpm-mode" name="receipts[mode]">
							<?php foreach (MJPM_Settings::receipt_modes() as $key => $label) : ?>
								<option value="<?php echo esc_attr($key); ?>" <?php selected($s['mode'], $key); ?>><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Lo que hace un medio de pago que no configuraste aparte. Si querés pedirlo solo en transferencia, dejá esto en “No pide” y marcá transferencia abajo.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjpm-text">Qué le decimos</label></th>
					<td>
						<textarea id="mjpm-text" name="receipts[text]" rows="3" class="large-text"><?php echo esc_textarea($s['text']); ?></textarea>
						<p class="description">El texto que ve el cliente arriba del botón de subir. Cada medio de pago puede tener el suyo.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Qué le pedimos además del archivo</th>
					<td>
						<label><input type="checkbox" name="receipts[ask_amount]" value="1" <?php checked($s['ask_amount'], 1); ?>> El importe que pagó</label><br>
						<label><input type="checkbox" name="receipts[ask_reference]" value="1" <?php checked($s['ask_reference'], 1); ?>> El número de operación</label><br>
						<label><input type="checkbox" name="receipts[ask_note]" value="1" <?php checked($s['ask_note'], 1); ?>> Una aclaración libre</label>
						<p class="description">Todos opcionales para el cliente: nunca le bloquean el envío del archivo.</p>
					</td>
				</tr>
			</table>

			<h3>Archivos</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Tipos que se aceptan</th>
					<td>
						<?php
						$allowed = MJPM_Vault::allowed_types();
						foreach (MJPM_Vault::type_labels() as $ext => $label) :
							?>
							<label class="mjpm-rc-inline">
								<input type="checkbox" name="receipt_types[]" value="<?php echo esc_attr($ext); ?>" <?php checked(in_array($ext, $allowed, true), true); ?>>
								<?php echo esc_html($label); ?>
							</label>
						<?php endforeach; ?>
						<p class="description">El HEIC del iPhone se convierte a JPG solo, si el servidor tiene Imagick; si no, se le pide al cliente que lo mande como JPG o PDF.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjpm-max-mb">Tamaño máximo</label></th>
					<td>
						<input type="number" id="mjpm-max-mb" name="receipts[max_mb]" value="<?php echo esc_attr($s['max_mb']); ?>" min="1" max="64" step="1" class="small-text"> MB por archivo
						<p class="description">
							<?php if ($limit > 0) : ?>
								Este servidor no acepta más de <strong><?php echo esc_html(MJPM_Vault::size_text($limit)); ?></strong> por archivo, así que ese es el techo real.
							<?php else : ?>
								No pudimos leer el límite del servidor.
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjpm-max-files">Cuántos por compra</label></th>
					<td>
						<input type="number" id="mjpm-max-files" name="receipts[max_files]" value="<?php echo esc_attr($s['max_files']); ?>" min="1" max="20" step="1" class="small-text">
						<p class="description">Para los pagos en partes: el cliente puede subir uno por transferencia. Cada archivo se revisa aparte.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Optimizador de imágenes</th>
					<td>
						<label><input type="checkbox" name="receipts[optimize]" value="1" <?php checked($s['optimize'], 1); ?>> Pasar las imágenes por MJ Optimizador de Imágenes</label>
						<p class="description">
							Apagado (recomendado), el archivo se guarda exactamente como llegó: un comprobante es un documento y conviene no tocarlo.
							Prendido, se corrige la rotación y se recorta al tamaño máximo que tengas configurado en el optimizador, lo que ocupa menos disco.
							Nunca cambia el formato ni convierte a WebP.
							<?php if (!MJPM_Vault::optimizer_available()) : ?>
								<br><strong>Ahora mismo no tiene efecto:</strong> MJ Optimizador de Imágenes no está activo.
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjpm-keep">Cuánto se guardan</label></th>
					<td>
						<select id="mjpm-keep" name="receipts[keep_days]">
							<?php foreach (MJPM_Settings::keep_choices() as $days => $label) : ?>
								<option value="<?php echo esc_attr($days); ?>" <?php selected((int) $s['keep_days'], (int) $days); ?>><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Pasado ese tiempo se borra el archivo y queda el registro: importe, fecha, referencia y quién lo aprobó. Son datos bancarios de tus clientes: guardar menos es guardar mejor.</p>
					</td>
				</tr>
			</table>

			<h3>Plazos</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mjpm-hours">Plazo para subirlo</label></th>
					<td>
						<input type="number" id="mjpm-hours" name="receipts[hours]" value="<?php echo esc_attr($s['hours']); ?>" min="0" max="8760" step="1" class="small-text"> horas
						<p class="description">0 = sin plazo. Solo se le muestra al cliente como fecha límite; no cancela nada solo.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjpm-remind">Recordatorio</label></th>
					<td>
						<input type="number" id="mjpm-remind" name="receipts[remind_hours]" value="<?php echo esc_attr($s['remind_hours']); ?>" min="0" max="8760" step="1" class="small-text"> horas
						<p class="description">0 = sin recordatorio. Pasadas esas horas, si no subió nada, le llega un mail con el link. Se manda una sola vez.</p>
					</td>
				</tr>
			</table>

			<h3>Cuando decidís</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mjpm-on-ok">Al aprobar el pago</label></th>
					<td>
						<select id="mjpm-on-ok" name="receipts[on_ok]">
							<option value="">No tocar el estado del pedido</option>
							<?php foreach ($statuses as $slug => $label) : ?>
								<option value="<?php echo esc_attr($slug); ?>" <?php selected($s['on_ok'], $slug); ?>><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjpm-on-partial">Con un pago parcial</label></th>
					<td>
						<select id="mjpm-on-partial" name="receipts[on_partial]">
							<option value="">No tocar el estado del pedido</option>
							<?php foreach ($statuses as $slug => $label) : ?>
								<option value="<?php echo esc_attr($slug); ?>" <?php selected($s['on_partial'], $slug); ?>><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Al aprobar decís cuánto entró. Si no cubre lo que falta, el pedido queda con saldo: el cliente ve lo pagado y lo que falta abonar, y puede subir el próximo <?php echo esc_html($noun); ?>. Cuando se completa, pasa al estado de “Al aprobar el pago”.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjpm-on-no">Al rechazar el pago</label></th>
					<td>
						<select id="mjpm-on-no" name="receipts[on_reject]">
							<option value="">No tocar el estado del pedido</option>
							<?php foreach ($statuses as $slug => $label) : ?>
								<option value="<?php echo esc_attr($slug); ?>" <?php selected($s['on_reject'], $slug); ?>><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Si lo dejás sin tocar, el pedido queda donde está y solo se le avisa al cliente con el motivo.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Email del pedido</th>
					<td>
						<label><input type="checkbox" name="receipts[attach]" value="1" <?php checked($s['attach'], 1); ?>> Adjuntar el archivo a los mails de pedido que te llegan a vos</label>
						<p class="description">Solo a los mails internos de la tienda, nunca a los del cliente: mandar un dato bancario de vuelta por mail no suma nada.</p>
					</td>
				</tr>
			</table>

			<h3>Dónde lo sube</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Con cuenta</th>
					<td>
						<label><input type="checkbox" name="receipts[show_account]" value="1" <?php checked($s['show_account'], 1); ?>> Mostrarlo en Mi cuenta → detalle del pedido</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Sin cuenta</th>
					<td>
						<label><input type="checkbox" name="receipts[guest_links]" value="1" <?php checked($s['guest_links'], 1); ?>> Habilitar el link firmado para compras sin cuenta</label>
						<p class="description">Apagado, quien compró como invitado no tiene forma de subir nada. Prendido, el link va en los mails y en la página de gracias.</p>
						<p>
							<label for="mjpm-link-days">El link vive</label>
							<input type="number" id="mjpm-link-days" name="receipts[link_days]" value="<?php echo esc_attr($s['link_days']); ?>" min="1" max="365" step="1" class="small-text"> días
						</p>
					</td>
				</tr>
			</table>

			<h3>Quién los puede ver y decidir</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Además de administradores</th>
					<td>
						<label><input type="checkbox" name="receipts[cap_shop]" value="1" <?php checked($s['cap_shop'], 1); ?>> Quien administra la tienda (WooCommerce)</label><br>
						<label><input type="checkbox" name="receipts[cap_erp]" value="1" <?php checked($s['cap_erp'], 1); ?>> Quien administra MJ ERP</label>
						<p class="description">Un comprobante tiene nombre, CBU e importe. Dale acceso solo a quien lo necesite para trabajar.</p>
					</td>
				</tr>
			</table>

			<h3>Avisos</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">A vos</th>
					<td>
						<label><input type="checkbox" name="receipts[mail_new]" value="1" <?php checked($s['mail_new'], 1); ?>> Avisarme por mail cuando entre uno nuevo</label>
						<p>
							<input type="email" name="receipts[mail_new_to]" value="<?php echo esc_attr($s['mail_new_to']); ?>" class="regular-text" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
							<span class="description">Vacío = el email de administración del sitio.</span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Al aprobar</th>
					<td>
						<label><input type="checkbox" name="receipts[mail_ok]" value="1" <?php checked($s['mail_ok'], 1); ?>> Mandarle un mail al cliente</label>
						<p><input type="text" name="receipts[mail_ok_subject]" value="<?php echo esc_attr($s['mail_ok_subject']); ?>" class="large-text" maxlength="190"></p>
						<p><textarea name="receipts[mail_ok_body]" rows="4" class="large-text"><?php echo esc_textarea($s['mail_ok_body']); ?></textarea></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Con un pago parcial</th>
					<td>
						<label><input type="checkbox" name="receipts[mail_partial]" value="1" <?php checked($s['mail_partial'], 1); ?>> Mandarle un mail al cliente</label>
						<p><input type="text" name="receipts[mail_partial_subject]" value="<?php echo esc_attr($s['mail_partial_subject']); ?>" class="large-text" maxlength="190"></p>
						<p><textarea name="receipts[mail_partial_body]" rows="5" class="large-text"><?php echo esc_textarea($s['mail_partial_body']); ?></textarea></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Al rechazar</th>
					<td>
						<label><input type="checkbox" name="receipts[mail_no]" value="1" <?php checked($s['mail_no'], 1); ?>> Mandarle un mail al cliente</label>
						<p><input type="text" name="receipts[mail_no_subject]" value="<?php echo esc_attr($s['mail_no_subject']); ?>" class="large-text" maxlength="190"></p>
						<p><textarea name="receipts[mail_no_body]" rows="5" class="large-text"><?php echo esc_textarea($s['mail_no_body']); ?></textarea></p>
					</td>
				</tr>
				<tr>
					<th scope="row">WhatsApp</th>
					<td>
						<label><input type="checkbox" name="receipts[whatsapp]" value="1" <?php checked($s['whatsapp'], 1); ?>> Botón de WhatsApp en la bandeja</label>
						<p><input type="text" name="receipts[whatsapp_ok]" value="<?php echo esc_attr($s['whatsapp_ok']); ?>" class="large-text" aria-label="Mensaje al aprobar"></p>
						<p><input type="text" name="receipts[whatsapp_partial]" value="<?php echo esc_attr($s['whatsapp_partial']); ?>" class="large-text" aria-label="Mensaje con un pago parcial"></p>
						<p><input type="text" name="receipts[whatsapp_no]" value="<?php echo esc_attr($s['whatsapp_no']); ?>" class="large-text" aria-label="Mensaje al rechazar"></p>
						<p class="description">Abre WhatsApp con el mensaje ya escrito, usando el teléfono de la compra: al aprobar, con un pago parcial y al rechazar, según lo último que decidiste.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Resumen del pago</th>
					<td>
						<label><input type="checkbox" name="receipts[mail_summary]" value="1" <?php checked($s['mail_summary'], 1); ?>> Sumar al final de cada mail lo acreditado, lo pagado, lo que falta abonar y tu mensaje</label>
						<p class="description">Así el cliente tiene todo claro aunque el texto de arriba no use los marcadores de importes.</p>
					</td>
				</tr>
			</table>

			<p class="mjpm-rc-placeholders">
				<strong>Marcadores</strong> que podés usar en los textos y mensajes:
				<code>{cliente}</code> <code>{numero}</code> <code>{total}</code> <code>{metodo}</code>
				<code>{comprobante}</code> <code>{motivo}</code> <code>{link}</code> <code>{tienda}</code>
				<code>{importe}</code> (lo que entró con ese pago) <code>{pagado}</code> <code>{falta}</code>.
				<code>{motivo}</code> es el motivo del rechazo o el mensaje que escribiste al aprobar.
			</p>

			<h3>Textos que ve el cliente</h3>
			<p class="mjpm-rc-bajada">En su pedido, en la página de gracias, en el link sin cuenta y en el resumen de los mails. Vacío = el texto de fábrica (el que se ve en gris). Lo que está entre llaves se reemplaza solo: dejalo como está.</p>
			<table class="widefat striped mjpm-rc-texts" role="presentation">
				<tbody>
					<?php
					$saved_texts = is_array($s['texts']) ? $s['texts'] : [];
					foreach (MJPM_Settings::receipt_texts() as $key => $def) :
						?>
						<tr>
							<th scope="row"><label for="mjpm-text-<?php echo esc_attr($key); ?>"><?php echo esc_html($def[0]); ?></label></th>
							<td><input type="text" id="mjpm-text-<?php echo esc_attr($key); ?>" name="receipts[texts][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr(isset($saved_texts[$key]) ? $saved_texts[$key] : ''); ?>" placeholder="<?php echo esc_attr($def[1]); ?>" class="large-text" maxlength="255"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button('Guardar la regla general'); ?>
		</form>
		<?php
	}

	/* ------------------------- Regla por medio de pago --------------------- */

	private static function per_method() {
		$gateways = MJPM_Methods::gateways();
		$statuses = MJPM_Settings::order_statuses();
		?>
		<form method="post" action="<?php echo esc_url(self::post_url()); ?>" class="mjpm-rc-card">
			<input type="hidden" name="action" value="mjpm_save_receipt_methods">
			<?php wp_nonce_field('mjpm_save_receipt_methods'); ?>

			<h2>Por medio de pago</h2>
			<p class="mjpm-rc-bajada">Acá elegís en cuáles se pide. Lo que quede en “Heredar” sigue la regla general de arriba.</p>

			<?php if (!$gateways) : ?>
				<p>No hay medios de pago activos en WooCommerce todavía.</p>
			<?php else : ?>
				<table class="widefat striped mjpm-rc-methods">
					<thead>
						<tr>
							<th>Medio de pago</th>
							<th>¿Pide <?php echo esc_html(MJPM_Receipts::noun()); ?>?</th>
							<th>Texto para el cliente</th>
							<th>Archivos</th>
							<th>Plazo</th>
							<th>Al aprobar</th>
							<th>Adjuntar</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($gateways as $id => $gateway) :
							$cfg = MJPM_Settings::method($id);
							?>
							<tr>
								<td>
									<strong><?php echo esc_html(wp_strip_all_tags($gateway->get_title())); ?></strong>
									<br><code><?php echo esc_html($id); ?></code>
									<?php if ('yes' !== $gateway->enabled) : ?>
										<br><span class="mjpm-rc-off">Desactivado en WooCommerce</span>
									<?php endif; ?>
								</td>
								<td>
									<select name="methods[<?php echo esc_attr($id); ?>][receipt]">
										<?php foreach (MJPM_Settings::receipt_modes(true) as $key => $label) : ?>
											<option value="<?php echo esc_attr($key); ?>" <?php selected($cfg['receipt'], $key); ?>><?php echo esc_html($label); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<textarea name="methods[<?php echo esc_attr($id); ?>][receipt_text]" rows="2" class="mjpm-rc-textarea" placeholder="El texto general"><?php echo esc_textarea($cfg['receipt_text']); ?></textarea>
								</td>
								<td>
									<input type="number" name="methods[<?php echo esc_attr($id); ?>][receipt_files]" value="<?php echo esc_attr($cfg['receipt_files']); ?>" min="0" max="20" step="1" class="small-text" placeholder="0">
									<br><span class="description">0 = el general</span>
								</td>
								<td>
									<input type="number" name="methods[<?php echo esc_attr($id); ?>][receipt_hours]" value="<?php echo esc_attr($cfg['receipt_hours'] < 0 ? '' : $cfg['receipt_hours']); ?>" min="0" max="8760" step="1" class="small-text" placeholder="—">
									<br><span class="description">h · vacío = el general</span>
								</td>
								<td>
									<select name="methods[<?php echo esc_attr($id); ?>][receipt_on_ok]">
										<option value="heredar" <?php selected($cfg['receipt_on_ok'], 'heredar'); ?>>Heredar</option>
										<option value="" <?php selected($cfg['receipt_on_ok'], ''); ?>>No tocar</option>
										<?php foreach ($statuses as $slug => $label) : ?>
											<option value="<?php echo esc_attr($slug); ?>" <?php selected($cfg['receipt_on_ok'], $slug); ?>><?php echo esc_html($label); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="methods[<?php echo esc_attr($id); ?>][receipt_attach]">
										<option value="-1" <?php selected((int) $cfg['receipt_attach'], -1); ?>>Heredar</option>
										<option value="0" <?php selected((int) $cfg['receipt_attach'], 0); ?>>No</option>
										<option value="1" <?php selected((int) $cfg['receipt_attach'], 1); ?>>Sí</option>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button('Guardar la regla por medio de pago'); ?>
			<?php endif; ?>
		</form>
		<?php
	}

	/* ------------------------- Equivalencias con el ERP -------------------- */

	private static function mapping() {
		if (!MJPM_Mapping::erp_available()) {
			return;
		}
		$gateways  = MJPM_Methods::gateways();
		$methods   = MJPM_Mapping::erp_methods();
		$map       = MJPM_Mapping::all();
		$has_boxes = MJPM_Mapping::cashboxes_available();
		?>
		<form method="post" action="<?php echo esc_url(self::post_url()); ?>" class="mjpm-rc-card">
			<input type="hidden" name="action" value="mjpm_save_mapping">
			<?php wp_nonce_field('mjpm_save_mapping'); ?>

			<h2>Equivalencias con MJ ERP</h2>
			<p class="mjpm-rc-bajada">
				La tienda identifica los medios de pago con el id del gateway y el ERP con sus propias claves.
				Decí acá qué es qué y alcanza con configurar el <?php echo esc_html(MJPM_Receipts::noun()); ?> una sola vez:
				la misma regla vale para las ventas y para los pagos de las reventas.
				De paso, una venta que entra de la tienda deja de caer en el método “Otro”.
			</p>
			<?php if ($has_boxes) : ?>
				<p class="mjpm-rc-bajada">
					La equivalencia también decide <strong>en qué caja entra la plata</strong> de cada
					pedido de la tienda. La caja se elige una sola vez, en
					<em>MJ ERP → Configuración → Ventas → Medios de pago</em>, y desde acá se ve el
					resultado: no hay nada que cargar dos veces.
				</p>
			<?php endif; ?>

			<table class="widefat striped mjpm-rc-mapping">
				<thead>
					<tr>
						<th>Medio de pago de la tienda</th>
						<th>Método en el ERP</th>
						<?php if ($has_boxes) : ?><th>Caja de ingreso</th><?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($gateways as $id => $gateway) :
						$current   = isset($map[$id]) ? $map[$id] : '';
						$suggested = '' === $current ? MJPM_Mapping::suggest($id) : '';
						?>
						<tr>
							<td>
								<strong><?php echo esc_html(wp_strip_all_tags($gateway->get_title())); ?></strong>
								<br><code><?php echo esc_html($id); ?></code>
							</td>
							<td>
								<select name="mapping[<?php echo esc_attr($id); ?>]">
									<option value="">— Sin equivalencia —</option>
									<?php foreach ($methods as $method) : ?>
										<option value="<?php echo esc_attr($method['key']); ?>" <?php selected('' !== $current ? $current : $suggested, $method['key']); ?>>
											<?php echo esc_html($method['label']); ?> (<?php echo esc_html($method['key']); ?>)
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ('' === $current && '' !== $suggested) : ?>
									<br><span class="description">Sugerencia automática: guardá para confirmarla.</span>
								<?php endif; ?>
							</td>
							<?php if ($has_boxes) :
								$cashbox = '' !== $current ? MJPM_Mapping::cashbox_for_gateway($id) : 0;
								$box_name = MJPM_Mapping::cashbox_label($cashbox);
								?>
								<td>
									<?php if ('' === $current) : ?>
										<span class="description">Elegí primero el método del ERP.</span>
									<?php elseif ('' !== $box_name) : ?>
										<strong><?php echo esc_html($box_name); ?></strong>
									<?php else : ?>
										<span class="description">Sin caja asignada todavía.</span>
									<?php endif; ?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button('Guardar las equivalencias'); ?>
		</form>
		<?php
	}
}
