<?php
/**
 * MJ Log — el cuaderno de errores compartido de la familia MJ.
 *
 * ESTE ARCHIVO ES LA COPIA MAESTRA.
 *
 * Vive en shared/ y se reparte a cada plugin con:
 *
 *     .\tools\sincronizar-compartido.ps1
 *
 * No editar las copias que están dentro de los plugins: se pisan. Editar esta
 * y volver a repartir. El CI verifica que todas las copias estén iguales.
 *
 * ---------------------------------------------------------------------------
 * POR QUÉ EXISTE
 *
 * Hasta ahora, cuando algo fallaba en silencio no quedaba rastro en ningún
 * lado. Si el cron no actualizaba el dólar, la tienda seguía vendiendo al tipo
 * de cambio de ayer y nadie se enteraba hasta mirar los números. Si el ERP no
 * lograba emitir el pedido de WooCommerce al cobrar una venta, el stock no
 * bajaba y el motivo se perdía.
 *
 * Esto escribe esas cosas donde ya sabés mirar: WooCommerce → Estado →
 * Registros. Si WooCommerce no está, cae al registro de errores de PHP.
 *
 * ---------------------------------------------------------------------------
 * CÓMO SE USA
 *
 *     MJ_Log::error('dolar', 'No se pudo leer la cotización', ['url' => $url]);
 *     MJ_Log::aviso('envios', 'Andreani tardó más de 10s');
 *     MJ_Log::info('erp', 'Pedido emitido', ['venta' => 12, 'pedido' => 848]);
 *
 * El primer dato es de qué plugin viene, y define el archivo de registro:
 * 'dolar' escribe en mj-dolar. Así cada plugin tiene su cuaderno aparte.
 *
 * ---------------------------------------------------------------------------
 * CUÁNTO SE GUARDA
 *
 * Se configura en Ajustes → MJ · Registros, y también se puede fijar desde
 * wp-config.php, que manda sobre el ajuste:
 *
 *     define('MJ_LOG_NIVEL', 'todo');   // apagado | errores | todo
 *
 * Por defecto guarda solo los errores: es lo que sirve para entender un
 * problema sin llenar el disco. 'todo' se usa mientras se persigue algo raro.
 *
 * @package MJ_Family
 */

defined('ABSPATH') || exit;

if (!class_exists('MJ_Log')) :

	/**
	 * Cuaderno de errores compartido.
	 */
	class MJ_Log {

		/**
		 * Versión de este archivo compartido.
		 *
		 * Si dos plugins traen copias distintas, gana el que cargue primero.
		 * Este número deja ver cuál quedó activa, desde Ajustes → MJ · Registros.
		 */
		const VERSION = '1.0.0';

		/**
		 * Ajuste donde se guarda cuánto registrar.
		 */
		const OPCION = 'mj_log_nivel';

		/**
		 * Cuánto registrar, de menos a más.
		 */
		const NIVELES = ['apagado', 'errores', 'todo'];

		/**
		 * Nivel resuelto, cacheado por pedido.
		 *
		 * @var string|null
		 */
		private static $nivel = null;

		/**
		 * Si los enganches del panel ya se pusieron.
		 *
		 * @var bool
		 */
		private static $iniciado = false;

		/**
		 * Enganches del panel.
		 *
		 * Lo llaman todos los plugins que traen este archivo, sin coordinarse
		 * entre ellos: el primero que arranca lo hace y el resto no repite.
		 */
		public static function init() {
			if (self::$iniciado) {
				return;
			}
			self::$iniciado = true;

			add_action('admin_menu', [__CLASS__, 'menu']);
			add_action('admin_init', [__CLASS__, 'registrar_ajuste']);
		}

		/* ------------------------------------------------------------------ */
		/* Escribir                                                            */
		/* ------------------------------------------------------------------ */

		/**
		 * Algo falló y hay que enterarse.
		 *
		 * @param string $origen   De qué plugin viene: 'dolar', 'erp', 'envios'…
		 * @param string $mensaje  Qué pasó, en castellano.
		 * @param array  $contexto Datos que ayuden a entenderlo.
		 */
		public static function error($origen, $mensaje, $contexto = []) {
			self::escribir('error', $origen, $mensaje, $contexto);
		}

		/**
		 * Algo raro que todavía no rompe nada, pero conviene mirar.
		 *
		 * @param string $origen   De qué plugin viene.
		 * @param string $mensaje  Qué pasó.
		 * @param array  $contexto Datos que ayuden a entenderlo.
		 */
		public static function aviso($origen, $mensaje, $contexto = []) {
			self::escribir('warning', $origen, $mensaje, $contexto);
		}

		/**
		 * Algo que salió bien y sirve tener anotado.
		 *
		 * Solo se guarda con el nivel en 'todo'.
		 *
		 * @param string $origen   De qué plugin viene.
		 * @param string $mensaje  Qué pasó.
		 * @param array  $contexto Datos que ayuden a entenderlo.
		 */
		public static function info($origen, $mensaje, $contexto = []) {
			self::escribir('info', $origen, $mensaje, $contexto);
		}

		/**
		 * Guarda una línea, si el nivel configurado lo permite.
		 *
		 * @param string $severidad 'error' | 'warning' | 'info'.
		 * @param string $origen    De qué plugin viene.
		 * @param string $mensaje   Qué pasó.
		 * @param array  $contexto  Datos sueltos.
		 */
		private static function escribir($severidad, $origen, $mensaje, $contexto = []) {
			$nivel = self::nivel();

			if ('apagado' === $nivel) {
				return;
			}
			if ('errores' === $nivel && 'info' === $severidad) {
				return;
			}

			$origen  = self::limpiar_origen($origen);
			$mensaje = (string) $mensaje;

			if (!empty($contexto)) {
				$mensaje .= ' — ' . self::formatear_contexto($contexto);
			}

			/**
			 * Última chance de cambiar o descartar una línea antes de guardarla.
			 *
			 * Devolver cadena vacía la descarta.
			 *
			 * @param string $mensaje   La línea armada.
			 * @param string $severidad 'error' | 'warning' | 'info'.
			 * @param string $origen    De qué plugin viene.
			 * @param array  $contexto  Datos sueltos.
			 */
			$mensaje = (string) apply_filters('mj_log_linea', $mensaje, $severidad, $origen, $contexto);

			if ('' === $mensaje) {
				return;
			}

			if (function_exists('wc_get_logger')) {
				$logger = wc_get_logger();
				if ($logger) {
					$logger->log($severidad, $mensaje, ['source' => 'mj-' . $origen]);
					return;
				}
			}

			// Sin WooCommerce: al registro de errores de PHP, que siempre está.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Es el único destino disponible cuando no hay WooCommerce.
			error_log(sprintf('[mj-%s] [%s] %s', $origen, $severidad, $mensaje));
		}

		/* ------------------------------------------------------------------ */
		/* Nivel                                                               */
		/* ------------------------------------------------------------------ */

		/**
		 * Cuánto hay que registrar.
		 *
		 * Orden de prioridad: la constante de wp-config manda sobre todo,
		 * después el filtro, después el ajuste del panel.
		 *
		 * @return string 'apagado' | 'errores' | 'todo'
		 */
		public static function nivel() {
			if (null !== self::$nivel) {
				return self::$nivel;
			}

			$nivel = '';

			if (defined('MJ_LOG_NIVEL')) {
				$nivel = (string) MJ_LOG_NIVEL;
			}

			if (!in_array($nivel, self::NIVELES, true)) {
				$nivel = (string) get_option(self::OPCION, 'errores');
			}

			/**
			 * Cuánto registrar.
			 *
			 * @param string $nivel 'apagado' | 'errores' | 'todo'.
			 */
			$nivel = (string) apply_filters('mj_log_nivel', $nivel);

			if (!in_array($nivel, self::NIVELES, true)) {
				$nivel = 'errores';
			}

			self::$nivel = $nivel;
			return $nivel;
		}

		/**
		 * Olvida el nivel cacheado. Para los tests y para cuando se guarda el ajuste.
		 */
		public static function olvidar_nivel() {
			self::$nivel = null;
		}

		/* ------------------------------------------------------------------ */
		/* Ayudas                                                              */
		/* ------------------------------------------------------------------ */

		/**
		 * Deja el origen en algo que sirva como nombre de archivo.
		 *
		 * @param string $origen Origen crudo.
		 * @return string
		 */
		private static function limpiar_origen($origen) {
			$origen = strtolower((string) $origen);
			$origen = preg_replace('/[^a-z0-9_-]/', '', $origen);
			return '' !== $origen ? $origen : 'mj';
		}

		/**
		 * Convierte los datos sueltos en algo legible de un vistazo.
		 *
		 * @param array $contexto Datos sueltos.
		 * @return string
		 */
		private static function formatear_contexto($contexto) {
			if (!is_array($contexto)) {
				return (string) $contexto;
			}

			$partes = [];
			foreach ($contexto as $clave => $valor) {
				if (is_scalar($valor) || null === $valor) {
					$texto = is_bool($valor) ? ($valor ? 'sí' : 'no') : (string) $valor;
				} else {
					$texto = wp_json_encode($valor);
				}
				if (is_string($texto) && strlen($texto) > 500) {
					$texto = substr($texto, 0, 500) . '…';
				}
				$partes[] = is_int($clave) ? $texto : $clave . '=' . $texto;
			}

			return implode(' ', $partes);
		}

		/* ------------------------------------------------------------------ */
		/* Pantalla de ajustes                                                 */
		/* ------------------------------------------------------------------ */

		/**
		 * Agrega la pantalla, una sola vez aunque haya varios plugins MJ.
		 */
		public static function menu() {
			add_options_page(
				'MJ · Registros',
				'MJ · Registros',
				'manage_options',
				'mj-registros',
				[__CLASS__, 'pantalla']
			);
		}

		/**
		 * Registra el ajuste del nivel.
		 */
		public static function registrar_ajuste() {
			register_setting(
				'mj_registros',
				self::OPCION,
				[
					'type'              => 'string',
					'sanitize_callback' => [__CLASS__, 'sanear_nivel'],
					'default'           => 'errores',
				]
			);
		}

		/**
		 * Se asegura de que el ajuste guardado sea uno de los tres válidos.
		 *
		 * @param mixed $valor Lo que llegó del formulario.
		 * @return string
		 */
		public static function sanear_nivel($valor) {
			self::olvidar_nivel();
			$valor = is_string($valor) ? $valor : '';
			return in_array($valor, self::NIVELES, true) ? $valor : 'errores';
		}

		/**
		 * Dibuja la pantalla.
		 */
		public static function pantalla() {
			if (!current_user_can('manage_options')) {
				return;
			}

			$nivel    = self::nivel();
			$fijado   = defined('MJ_LOG_NIVEL') && in_array((string) MJ_LOG_NIVEL, self::NIVELES, true);
			$opciones = [
				'apagado' => ['No guardar nada', 'La tienda no anota nada. Queda todo como antes de que esto existiera.'],
				'errores' => ['Solo los errores', 'Lo recomendado. Guarda lo que falló, que es lo que sirve para entender un problema.'],
				'todo'    => ['Todo', 'Suma lo que salió bien. Para cuando estás persiguiendo algo raro; después conviene volver a "solo los errores".'],
			];
			?>
			<div class="wrap">
				<h1>MJ · Registros</h1>

				<p style="max-width:46em;">
					Acá se decide cuánto anotan los plugins de MJ cuando algo falla.
					Lo anotado se lee en
					<a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs')); ?>">WooCommerce → Estado → Registros</a>,
					eligiendo el archivo que empieza con <code>mj-</code>.
				</p>

				<?php if ($fijado) : ?>
					<div class="notice notice-info">
						<p>
							El nivel está fijado en <code>wp-config.php</code> con
							<code>MJ_LOG_NIVEL</code>, así que manda esa y lo que
							elijas acá no tiene efecto. Ahora mismo:
							<strong><?php echo esc_html($nivel); ?></strong>.
						</p>
					</div>
				<?php endif; ?>

				<form method="post" action="options.php">
					<?php settings_fields('mj_registros'); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Cuánto guardar</th>
							<td>
								<fieldset <?php disabled($fijado); ?>>
									<?php foreach ($opciones as $clave => $texto) : ?>
										<label style="display:block; margin-bottom:10px;">
											<input
												type="radio"
												name="<?php echo esc_attr(self::OPCION); ?>"
												value="<?php echo esc_attr($clave); ?>"
												<?php checked($nivel, $clave); ?>
												<?php disabled($fijado); ?>
											/>
											<strong><?php echo esc_html($texto[0]); ?></strong><br />
											<span class="description" style="margin-left:24px;">
												<?php echo esc_html($texto[1]); ?>
											</span>
										</label>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>
					</table>
					<?php if (!$fijado) : ?>
						<?php submit_button('Guardar'); ?>
					<?php endif; ?>
				</form>

				<hr />
				<p class="description">
					MJ Log <?php echo esc_html(self::VERSION); ?>.
					Lo carga el primer plugin de MJ que arranca; todos comparten el mismo.
				</p>
			</div>
			<?php
		}
	}

endif;
