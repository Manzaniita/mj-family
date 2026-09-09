<?php
/**
 * wp-admin: página WooCommerce → Dólar, barra de admin, widget del
 * escritorio, avisos y handlers AJAX.
 */

defined('ABSPATH') || exit;

class MJD_Admin {

	const SLUG  = 'mj-dolar';
	const NONCE = 'mjd_admin';

	/** Hooks que también corren en el frontend (barra de admin). */
	public static function init_global() {
		add_action('admin_bar_menu', [__CLASS__, 'admin_bar'], 100);
	}

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);
		add_action('update_option_' . MJD_Settings::OPTION, [__CLASS__, 'settings_updated'], 10, 2);
		add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
		add_action('admin_notices', [__CLASS__, 'notices']);
		add_action('wp_dashboard_setup', [__CLASS__, 'dashboard_widget']);
		add_filter('plugin_action_links_' . plugin_basename(MJD_FILE), [__CLASS__, 'action_links']);

		add_action('wp_ajax_mjd_update_rate', [__CLASS__, 'ajax_update_rate']);
		add_action('wp_ajax_mjd_sync_start', [__CLASS__, 'ajax_sync_start']);
		add_action('wp_ajax_mjd_sync_step', [__CLASS__, 'ajax_sync_step']);
		add_action('wp_ajax_mjd_suggest', [__CLASS__, 'ajax_suggest']);
	}

	public static function page_url() {
		return admin_url('admin.php?page=' . self::SLUG);
	}

	public static function menu() {
		add_submenu_page('woocommerce', 'MJ Dólar — Tipo de cambio', '💵 Dólar', 'manage_options', self::SLUG, [__CLASS__, 'render']);
	}

	public static function action_links($links) {
		array_unshift($links, '<a href="' . esc_url(self::page_url()) . '">Tipo de cambio</a>');
		return $links;
	}

	public static function register_settings() {
		register_setting('mjd_settings_group', MJD_Settings::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => ['MJD_Settings', 'sanitize'],
			'default'           => MJD_Settings::defaults(),
		]);
	}

	/** Cambió el redondeo → recalcular; cambió algo del header → limpiar cachés de página. */
	public static function settings_updated($old, $new) {
		MJD_Settings::flush();
		$old = wp_parse_args(is_array($old) ? $old : [], MJD_Settings::defaults());
		$new = wp_parse_args(is_array($new) ? $new : [], MJD_Settings::defaults());

		if (((int) $old['rounding'] !== (int) $new['rounding'] || $old['rounding_mode'] !== $new['rounding_mode']) && MJD_Rate::is_set()) {
			MJD_Rate::flush_caches();
			MJD_Sync::start('rounding');
			return;
		}
		if ((int) $old['show_header'] !== (int) $new['show_header'] || $old['header_label'] !== $new['header_label'] || $old['show_usd_price'] !== $new['show_usd_price']) {
			MJD_Rate::flush_caches();
		}
	}

	public static function assets($hook) {
		if ('woocommerce_page_' . self::SLUG !== $hook && 'index.php' !== $hook) {
			return;
		}
		$state = MJD_Sync::state();
		wp_enqueue_style('mjd-admin', MJD_URL . 'assets/admin.css', [], MJD_VERSION);
		wp_enqueue_script('mjd-admin', MJD_URL . 'assets/admin.js', ['jquery'], MJD_VERSION, true);
		wp_localize_script('mjd-admin', 'mjdAdmin', [
			'ajaxUrl'      => admin_url('admin-ajax.php'),
			'nonce'        => wp_create_nonce(self::NONCE),
			'pageUrl'      => self::page_url(),
			'rate'         => MJD_Rate::get(),
			'rounding'     => (int) MJD_Settings::get('rounding'),
			'roundingMode' => (string) MJD_Settings::get('rounding_mode'),
			'decimals'     => (int) wc_get_price_decimals(),
			'decimalSep'   => wc_get_price_decimal_separator(),
			'thousandSep'  => wc_get_price_thousand_separator(),
			'symbol'       => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
			'position'     => get_option('woocommerce_currency_pos', 'left_space'),
			'syncRunning'  => 'running' === $state['status'],
			'syncDone'     => (int) $state['done'],
			'syncTotal'    => (int) $state['total'],
			'suggest'      => MJD_Suggest::enabled(),
			'i18n'         => [
				'invalid'     => 'Escribí un número mayor a cero.',
				'saving'      => 'Guardando…',
				'saved'       => 'Tipo de cambio guardado. Recalculando precios…',
				'error'       => 'No se pudo guardar. Probá de nuevo.',
				'syncing'     => 'Recalculando precios en pesos…',
				'syncDone'    => 'Precios actualizados.',
				'confirmSync' => '¿Recalcular ahora los precios en pesos de todos los productos con USD?',
				'example'     => 'Ejemplo:',
				'loading'     => sprintf('Consultando %s…', MJD_Suggest::provider()),
				'suggestFail' => 'No se pudo obtener la cotización.',
				'deviation'   => 'El TC cargado se desvía %s % del sugerido.',
				'ago'         => 'hace %s',
			],
		]);
	}

	/* ------------------------------ Avisos ------------------------------ */

	public static function notices() {
		if (!current_user_can('manage_options')) {
			return;
		}
		if (get_transient('mjd_activated')) {
			delete_transient('mjd_activated');
			echo '<div class="notice notice-success is-dismissible"><p><strong>MJ Dólar activado.</strong> Cargá el tipo de cambio en <a href="' . esc_url(self::page_url()) . '">WooCommerce → Dólar</a> y después completá el precio USD de tus productos (ficha, edición rápida, en lote o CSV).</p></div>';
			return;
		}
		$screen = get_current_screen();
		if (!$screen || MJD_Rate::is_set()) {
			return;
		}
		if (in_array($screen->id, ['product', 'edit-product', 'dashboard'], true)) {
			$counts = MJD_Sync::counts();
			if ($counts['products'] + $counts['variations'] > 0) {
				echo '<div class="notice notice-warning"><p><strong>MJ Dólar:</strong> no hay tipo de cambio cargado. Los productos con precio USD se muestran con el último precio en pesos guardado. <a href="' . esc_url(self::page_url()) . '">Cargar el tipo de cambio</a>.</p></div>';
			}
		}
	}

	/* --------------------------- Barra de admin -------------------------- */

	public static function admin_bar($bar) {
		if (!current_user_can('manage_options') || !is_admin_bar_showing()) {
			return;
		}
		$set = MJD_Rate::is_set();
		$at  = MJD_Rate::updated_at();
		$bar->add_node([
			'id'    => 'mjd-rate',
			'title' => $set ? '💵 Dólar ' . esc_html(MJD_Rate::format_rate()) : '💵 Cargar tipo de cambio',
			'href'  => self::page_url(),
			'meta'  => [
				'title' => $set && $at ? 'Actualizado hace ' . human_time_diff($at) . ' · clic para cambiarlo' : 'Sin tipo de cambio cargado',
			],
		]);
	}

	/* ------------------------- Widget del escritorio --------------------- */

	public static function dashboard_widget() {
		if (!current_user_can('manage_options')) {
			return;
		}
		wp_add_dashboard_widget('mjd_dashboard', '💵 MJ Dólar — Tipo de cambio', [__CLASS__, 'render_dashboard_widget']);
	}

	public static function render_dashboard_widget() {
		$set = MJD_Rate::is_set();
		echo '<div class="mjd-widget">';
		echo '<p class="mjd-widget__rate' . ($set ? '' : ' is-empty') . '">' . ($set ? esc_html(MJD_Rate::format_rate()) : 'Sin tipo de cambio') . '</p>';
		if ($set) {
			echo '<p class="mjd-widget__meta">' . esc_html(self::updated_text()) . '</p>';
		}
		self::render_rate_form('widget');
		echo '<p class="mjd-widget__link"><a href="' . esc_url(self::page_url()) . '">Ajustes, historial y recálculo →</a></p>';
		echo '</div>';
	}

	/* ------------------------------ Helpers ------------------------------ */

	private static function updated_text() {
		$at = MJD_Rate::updated_at();
		if (!$at) {
			return '';
		}
		$text = 'Actualizado el ' . wp_date(get_option('date_format') . ', ' . get_option('time_format'), $at);
		$by   = MJD_Rate::updated_by_name();
		if ('' !== $by) {
			$text .= ' por ' . $by;
		}
		return $text . ' (hace ' . human_time_diff($at) . ')';
	}

	/** Formulario de TC compartido por la página y el widget (lo maneja admin.js). */
	private static function render_rate_form($context = 'page') {
		$symbol      = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
		$placeholder = MJD_Rate::is_set() ? wc_format_localized_price(MJD_Rate::normalize(MJD_Rate::get())) : '1450';
		?>
		<form class="mjd-rate-form" data-mjd-rate-form data-context="<?php echo esc_attr($context); ?>">
			<label class="mjd-rate-form__label" for="mjd-rate-input-<?php echo esc_attr($context); ?>">Nuevo tipo de cambio (pesos por dólar)</label>
			<div class="mjd-rate-form__row">
				<span class="mjd-rate-form__prefix"><?php echo esc_html($symbol); ?></span>
				<input type="text" inputmode="decimal" autocomplete="off" class="mjd-rate-form__input" id="mjd-rate-input-<?php echo esc_attr($context); ?>" name="rate" placeholder="<?php echo esc_attr($placeholder); ?>" value="">
				<?php if ('page' === $context) : ?>
					<input type="text" class="mjd-rate-form__note" name="note" placeholder="Nota (opcional): p. ej. blue venta" maxlength="120">
				<?php endif; ?>
				<button type="submit" class="button button-primary">Guardar y recalcular</button>
			</div>
			<p class="mjd-rate-form__preview" data-mjd-rate-preview></p>
			<p class="mjd-rate-form__feedback" data-mjd-rate-feedback hidden></p>
		</form>
		<?php
	}

	/* ------------------------------- Página ------------------------------ */

	public static function render() {
		if (!current_user_can('manage_options')) {
			wp_die('Sin permisos.');
		}
		$o        = MJD_Settings::get();
		$set      = MJD_Rate::is_set();
		$state    = MJD_Sync::state();
		$counts   = MJD_Sync::counts();
		$history  = MJD_Rate::history(30);
		$running  = 'running' === $state['status'];
		$percent  = $state['total'] > 0 ? (int) floor($state['done'] * 100 / $state['total']) : 100;
		$updated  = isset($_GET['mjd_updated']);
		$example  = $set ? MJD_Rate::format_ars(MJD_Rate::convert(100)) : '';
		?>
		<div class="wrap mjd-wrap">
			<h1 class="mjd-title">💵 MJ Dólar</h1>
			<p class="mjd-bajada">Cargás el tipo de cambio y la tienda recalcula sola los precios en pesos de todos los productos con precio USD. Los productos sin precio USD siguen en pesos fijos, como siempre.</p>

			<?php if ($updated) : ?>
				<div class="notice notice-success is-dismissible"><p>Tipo de cambio guardado. <?php echo $running ? 'Los precios en pesos se están recalculando (podés cerrar esta página: sigue en segundo plano).' : 'Precios actualizados.'; ?></p></div>
			<?php endif; ?>

			<div class="mjd-grid">
				<section class="mjd-card mjd-card--rate">
					<h2>Tipo de cambio vigente</h2>
					<p class="mjd-rate-big<?php echo $set ? '' : ' is-empty'; ?>"><?php echo $set ? esc_html(MJD_Rate::format_rate()) : 'Todavía no cargaste el tipo de cambio'; ?></p>
					<p class="mjd-rate-meta">
						<?php if ($set) : ?>
							<?php echo esc_html(self::updated_text()); ?> · USD 100 → <strong><?php echo esc_html($example); ?></strong>
						<?php else : ?>
							Hasta que lo cargues, los productos con precio USD muestran el último precio en pesos que tenían guardado.
						<?php endif; ?>
					</p>

					<?php self::render_rate_form('page'); ?>

					<div class="mjd-sync" data-mjd-sync data-status="<?php echo esc_attr($state['status']); ?>" <?php echo $running ? '' : 'hidden'; ?>>
						<div class="mjd-sync__bar"><div class="mjd-sync__fill" data-mjd-sync-fill style="width:<?php echo (int) $percent; ?>%"></div></div>
						<p class="mjd-sync__text" data-mjd-sync-text><?php echo $running ? 'Recalculando precios en pesos… ' . (int) $state['done'] . ' / ' . (int) $state['total'] : ''; ?></p>
					</div>
				</section>

				<?php if (MJD_Suggest::enabled()) : ?>
					<section class="mjd-card mjd-card--suggest" data-mjd-suggest>
						<h2>Cotización sugerida <small>(<?php echo esc_html(MJD_Suggest::provider() . ' · ' . MJD_Settings::suggest_sources()[$o['suggest_source']] . ' ' . $o['suggest_side']); ?>)</small></h2>
						<p class="mjd-suggest__value" data-mjd-suggest-value>Consultando…</p>
						<p class="mjd-suggest__meta" data-mjd-suggest-meta></p>
						<p class="mjd-suggest__alert" data-mjd-suggest-alert hidden></p>
						<p class="mjd-suggest__actions">
							<button type="button" class="button button-primary" data-mjd-suggest-use data-value="" disabled>Usar este valor</button>
							<button type="button" class="button" data-mjd-suggest-refresh>Actualizar</button>
						</p>
						<p class="description">Es solo una referencia: el valor no se aplica hasta que vos lo guardes.</p>
					</section>
				<?php endif; ?>

				<section class="mjd-card mjd-card--catalog">
					<h2>Catálogo</h2>
					<ul class="mjd-stats">
						<li><strong data-mjd-count="products"><?php echo (int) $counts['products']; ?></strong> productos con precio USD</li>
						<li><strong data-mjd-count="variations"><?php echo (int) $counts['variations']; ?></strong> variaciones con precio USD</li>
						<li><strong data-mjd-count="stale"><?php echo (int) $counts['stale']; ?></strong> con pesos guardados a otro TC</li>
					</ul>
					<p>
						<button type="button" class="button" data-mjd-sync-start <?php disabled(!$set); ?>>Recalcular todos los precios ahora</button>
						<a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=product&mjd_filter=with')); ?>">Ver productos con USD</a>
						<a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=product&mjd_filter=without')); ?>">Ver productos sin USD</a>
					</p>
					<p class="description">El recálculo corre solo al cambiar el TC o el redondeo. Mientras corre, el cliente ya ve los precios nuevos: esto solo deja la base coherente para ordenar, filtrar e informes.</p>
				</section>

				<section class="mjd-card mjd-card--history">
					<h2>Historial</h2>
					<?php if (!$history) : ?>
						<p class="description">Todavía no hay cambios registrados.</p>
					<?php else : ?>
						<table class="widefat striped mjd-history">
							<thead><tr><th>Fecha</th><th>TC</th><th>Cambio</th><th>Usuario</th><th>Nota</th></tr></thead>
							<tbody>
							<?php foreach ($history as $row) :
								$user   = !empty($row['by']) ? get_userdata((int) $row['by']) : null;
								$old    = isset($row['old']) ? (float) $row['old'] : 0;
								$new    = isset($row['rate']) ? (float) $row['rate'] : 0;
								$change = $old > 0 && $new > 0 ? ($new - $old) / $old * 100 : null;
								?>
								<tr>
									<td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $row['at'])); ?></td>
									<td><strong><?php echo esc_html(MJD_Rate::format_rate($new)); ?></strong></td>
									<td>
										<?php if (null === $change) : ?>
											<small>primer valor</small>
										<?php else : ?>
											<span class="<?php echo $change >= 0 ? 'mjd-up' : 'mjd-down'; ?>"><?php echo esc_html(($change >= 0 ? '+' : '') . number_format_i18n($change, 1) . ' %'); ?></span>
											<small>desde <?php echo esc_html(MJD_Rate::format_rate($old)); ?></small>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html($user ? $user->display_name : ($row['by'] ? '#' . (int) $row['by'] : 'sistema')); ?></td>
									<td><?php echo esc_html(isset($row['note']) ? $row['note'] : ''); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</section>
			</div>

			<form method="post" action="options.php" class="mjd-settings">
				<?php settings_fields('mjd_settings_group'); ?>
				<h2>Ajustes</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mjd_rounding">Redondeo del precio en pesos</label></th>
						<td>
							<select id="mjd_rounding" name="<?php echo esc_attr(MJD_Settings::OPTION); ?>[rounding]">
								<?php foreach (MJD_Settings::rounding_choices() as $value => $label) : ?>
									<option value="<?php echo esc_attr($value); ?>" <?php selected((int) $o['rounding'], (int) $value); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
							<select name="<?php echo esc_attr(MJD_Settings::OPTION); ?>[rounding_mode]" aria-label="Modo de redondeo">
								<option value="nearest" <?php selected($o['rounding_mode'], 'nearest'); ?>>al más cercano</option>
								<option value="up" <?php selected($o['rounding_mode'], 'up'); ?>>siempre para arriba</option>
							</select>
							<p class="description">Ejemplo con USD 15 a <?php echo esc_html($set ? MJD_Rate::format_rate() : '$ 1.450'); ?>: sin redondeo <?php echo esc_html(MJD_Rate::format_ars(15 * ($set ? MJD_Rate::get() : 1450))); ?>; a los $100 <?php echo esc_html(MJD_Rate::format_ars(round(15 * ($set ? MJD_Rate::get() : 1450) / 100) * 100)); ?>. Cambiar esto recalcula todos los precios.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Header de la tienda</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr(MJD_Settings::OPTION); ?>[show_header]" value="1" <?php checked((int) $o['show_header'], 1); ?>> Mostrar el tipo de cambio en el header</label>
							<p style="margin-top:8px;">
								<label for="mjd_header_label">Texto:</label>
								<input type="text" id="mjd_header_label" name="<?php echo esc_attr(MJD_Settings::OPTION); ?>[header_label]" value="<?php echo esc_attr($o['header_label']); ?>" class="regular-text" maxlength="30" placeholder="Dólar">
							</p>
							<p class="description">Se ve como “<?php echo esc_html($o['header_label']); ?> <?php echo esc_html($set ? MJD_Rate::format_rate() : '$ 1.450'); ?>”. Requiere el tema MJ Appearance 3.2 o superior.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mjd_show_usd_price">Precio en USD visible</label></th>
						<td>
							<select id="mjd_show_usd_price" name="<?php echo esc_attr(MJD_Settings::OPTION); ?>[show_usd_price]">
								<option value="product" <?php selected($o['show_usd_price'], 'product'); ?>>Solo en la ficha del producto (debajo del precio en pesos)</option>
								<option value="all" <?php selected($o['show_usd_price'], 'all'); ?>>En la ficha y en los listados</option>
								<option value="off" <?php selected($o['show_usd_price'], 'off'); ?>>No mostrar (solo pesos)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Cotización sugerida</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr(MJD_Settings::OPTION); ?>[suggest_enabled]" value="1" <?php checked((int) $o['suggest_enabled'], 1); ?>> Mostrar en esta página la cotización de <?php echo esc_html(MJD_Suggest::provider()); ?> como referencia</label>
							<p style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
								<select name="<?php echo esc_attr(MJD_Settings::OPTION); ?>[suggest_source]" aria-label="Fuente">
									<?php foreach (MJD_Settings::suggest_sources() as $slug => $label) : ?>
										<option value="<?php echo esc_attr($slug); ?>" <?php selected($o['suggest_source'], $slug); ?>><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
								<select name="<?php echo esc_attr(MJD_Settings::OPTION); ?>[suggest_side]" aria-label="Punta">
									<option value="venta" <?php selected($o['suggest_side'], 'venta'); ?>>venta</option>
									<option value="compra" <?php selected($o['suggest_side'], 'compra'); ?>>compra</option>
								</select>
								<label>Avisar si el TC cargado se desvía más de
									<input type="number" min="0" max="100" step="1" style="width:70px;" name="<?php echo esc_attr(MJD_Settings::OPTION); ?>[suggest_alert_pct]" value="<?php echo esc_attr((int) $o['suggest_alert_pct']); ?>"> %
								</label>
							</p>
							<p class="description">Nunca se aplica sola: siempre lo confirmás vos con el botón “Usar este valor”.</p>
						</td>
					</tr>
				</table>
				<?php submit_button('Guardar ajustes'); ?>
			</form>

			<section class="mjd-card mjd-dev">
				<h2>Para desarrolladores (otros plugins MJ)</h2>
				<ul>
					<li><code>MJD_Rate::get()</code> → TC vigente (float, 0 si no está cargado). <code>MJD_Rate::convert($usd, $rate = null, $product = null)</code> → pesos redondeados.</li>
					<li><code>MJD_Product::usd_effective($product)</code> → <code>[usd_normal, usd_oferta|null]</code>. <code>MJD_Product::has_usd($product)</code>.</li>
					<li>Filtros: <code>mjd_rate</code> (ajustar el TC leído), <code>mjd_convert_raw</code> (antes del redondeo: recargos por categoría, etc.), <code>mjd_convert</code> (después).</li>
					<li>Acciones: <code>mjd_rate_updated($nuevo, $viejo, $nota)</code>, <code>mjd_sync_finished($estado)</code>, <code>mjd_flush_caches</code>.</li>
					<li>REST: <code>GET /wp-json/mjd/v1/rate</code>, <code>POST /wp-json/mjd/v1/rate</code> (admin), <code>GET /wp-json/mjd/v1/convert?usd=100</code>. Store API: <code>extensions.mjd</code> en cada producto.</li>
					<li>Meta: <code>_mjd_usd_regular</code>, <code>_mjd_usd_sale</code> (producto/variación), <code>_mjd_rate_applied</code>; pedidos <code>_mjd_rate</code>, líneas <code>_mjd_usd_unit</code>. CSV: columnas “Precio USD” y “Precio oferta USD”.</li>
					<li>WP-CLI: <code>wp mjd rate [valor] [--note=…]</code>, <code>wp mjd sync</code>.</li>
				</ul>
			</section>
			<p class="mjd-credito">MJ Dólar <?php echo esc_html(MJD_VERSION); ?> · Creado por MJ Importaciones</p>
		</div>
		<?php
	}

	/* -------------------------------- AJAX ------------------------------- */

	private static function guard() {
		check_ajax_referer(self::NONCE);
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Sin permisos.'], 403);
		}
	}

	public static function ajax_update_rate() {
		self::guard();
		$rate   = isset($_POST['rate']) ? wp_unslash($_POST['rate']) : '';
		$note   = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
		$result = MJD_Rate::update($rate, $note);
		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()]);
		}
		wp_send_json_success([
			'rate'      => $result,
			'formatted' => MJD_Rate::format_rate($result),
			'updated'   => self::updated_text(),
			'state'     => MJD_Sync::status(),
		]);
	}

	public static function ajax_sync_start() {
		self::guard();
		if (!MJD_Rate::is_set()) {
			wp_send_json_error(['message' => 'Primero cargá el tipo de cambio.']);
		}
		MJD_Sync::start('manual');
		wp_send_json_success(MJD_Sync::status());
	}

	public static function ajax_sync_step() {
		self::guard();
		$state = MJD_Sync::step();
		if ('running' === $state['status']) {
			$state['percent'] = $state['total'] > 0 ? (int) floor($state['done'] * 100 / $state['total']) : 100;
			wp_send_json_success($state);
		}
		wp_send_json_success(MJD_Sync::status());
	}

	public static function ajax_suggest() {
		self::guard();
		$data = MJD_Suggest::fetch(!empty($_POST['force']));
		if (is_wp_error($data)) {
			wp_send_json_error(['message' => $data->get_error_message()]);
		}
		$rate = MJD_Rate::get();
		wp_send_json_success(array_merge($data, [
			'formatted'   => MJD_Rate::format_rate($data['value']),
			'input'       => wc_format_localized_price(MJD_Product::decimal($data['value'])),
			'deviation'   => round(MJD_Suggest::deviation($rate, $data['value']), 1),
			'alert'       => MJD_Suggest::should_alert($rate, $data['value']),
			'fetchedAgo'  => human_time_diff((int) $data['fetched_at']),
		]));
	}
}
