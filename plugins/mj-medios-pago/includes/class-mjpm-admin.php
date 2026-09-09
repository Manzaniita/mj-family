<?php
/**
 * wp-admin: página WooCommerce → Medios de pago (tabla de ajustes, métodos
 * propios, ajustes generales, vista previa), exclusión por producto y por
 * categoría.
 */

defined('ABSPATH') || exit;

class MJPM_Admin {

	const SLUG = 'mj-medios-pago';

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
		add_action('admin_notices', [__CLASS__, 'notices']);
		add_filter('plugin_action_links_' . plugin_basename(MJPM_FILE), [__CLASS__, 'action_links']);

		add_action('admin_post_mjpm_save_methods', [__CLASS__, 'save_methods']);
		add_action('admin_post_mjpm_save_settings', [__CLASS__, 'save_settings']);
		add_action('admin_post_mjpm_save_custom', [__CLASS__, 'save_custom']);
		add_action('admin_post_mjpm_delete_custom', [__CLASS__, 'delete_custom']);

		/* Exclusión por producto. */
		add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'product_field']);
		add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_product_field']);

		/* Exclusión por categoría. */
		add_action('product_cat_add_form_fields', [__CLASS__, 'category_add_field']);
		add_action('product_cat_edit_form_fields', [__CLASS__, 'category_edit_field']);
		add_action('created_product_cat', [__CLASS__, 'save_category_field']);
		add_action('edited_product_cat', [__CLASS__, 'save_category_field']);
	}

	public static function page_url($args = []) {
		return add_query_arg($args, admin_url('admin.php?page=' . self::SLUG));
	}

	public static function menu() {
		add_submenu_page('woocommerce', 'MJ Medios de Pago', '💳 Medios de pago', 'manage_options', self::SLUG, [__CLASS__, 'render']);
	}

	public static function action_links($links) {
		array_unshift($links, '<a href="' . esc_url(self::page_url()) . '">Configurar</a>');
		return $links;
	}

	public static function assets($hook) {
		if ('woocommerce_page_' . self::SLUG !== $hook) {
			return;
		}
		wp_enqueue_style('mjpm-admin', MJPM_URL . 'assets/admin.css', [], MJPM_VERSION);
		wp_enqueue_script('mjpm-admin', MJPM_URL . 'assets/admin.js', ['jquery'], MJPM_VERSION, true);
		wp_localize_script('mjpm-admin', 'mjpmAdmin', [
			'rounding'     => (int) MJPM_Settings::get('rounding'),
			'roundingMode' => (string) MJPM_Settings::get('rounding_mode'),
			'decimals'     => (int) wc_get_price_decimals(),
			'decimalSep'   => wc_get_price_decimal_separator(),
			'thousandSep'  => wc_get_price_thousand_separator(),
			'symbol'       => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
			'position'     => get_option('woocommerce_currency_pos', 'left_space'),
			'confirmDelete' => '¿Borrar este método propio? Los pedidos ya hechos no se tocan.',
		]);
	}

	public static function notices() {
		if (!current_user_can('manage_options')) {
			return;
		}
		if (get_transient('mjpm_activated')) {
			delete_transient('mjpm_activated');
			echo '<div class="notice notice-success is-dismissible"><p><strong>MJ Medios de Pago activado.</strong> Configuralo en <a href="' . esc_url(self::page_url()) . '">WooCommerce → Medios de pago</a>: elegí el método con precio base y el ajuste de cada uno.</p></div>';
		}
	}

	/* ------------------------------ Guardado ----------------------------- */

	private static function guard($action) {
		check_admin_referer($action);
		if (!current_user_can('manage_options')) {
			wp_die('Sin permisos.');
		}
	}

	private static function back($args) {
		wp_safe_redirect(self::page_url($args));
		exit;
	}

	public static function save_methods() {
		self::guard('mjpm_save_methods');
		$gateways = MJPM_Methods::gateways();
		$posted   = isset($_POST['methods']) && is_array($_POST['methods']) ? wp_unslash($_POST['methods']) : [];
		$all      = MJPM_Settings::methods();

		foreach ($gateways as $id => $gateway) {
			$in = isset($posted[$id]) && is_array($posted[$id]) ? $posted[$id] : [];
			if (!$in) {
				continue;
			}
			$in['show'] = empty($in['show']) ? 0 : 1;
			$all[$id]   = MJPM_Settings::sanitize_method($in);
		}
		MJPM_Settings::update_methods($all);

		$base    = isset($_POST['base_method']) ? sanitize_key(wp_unslash($_POST['base_method'])) : '';
		$display = isset($_POST['display_method']) ? sanitize_key(wp_unslash($_POST['display_method'])) : '';
		MJPM_Settings::update([
			'base_method'    => isset($gateways[$base]) ? $base : '',
			'display_method' => isset($gateways[$display]) ? $display : '',
		]);
		MJPM_Settings::flush_caches();
		self::back(['saved' => 'methods']);
	}

	public static function save_settings() {
		self::guard('mjpm_save_settings');
		$in = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
		MJPM_Settings::update([
			'rounding'        => isset($in['rounding']) ? absint($in['rounding']) : 100,
			'rounding_mode'   => isset($in['rounding_mode']) ? sanitize_key($in['rounding_mode']) : 'nearest',
			'apply_on_sale'   => empty($in['apply_on_sale']) ? 0 : 1,
			'stack_coupons'   => empty($in['stack_coupons']) ? 0 : 1,
			'show_selector'   => empty($in['show_selector']) ? 0 : 1,
			'selector_label'  => isset($in['selector_label']) ? sanitize_text_field($in['selector_label']) : 'Ver precios con',
			'show_badge'      => empty($in['show_badge']) ? 0 : 1,
			'show_panel'      => empty($in['show_panel']) ? 0 : 1,
			'show_list_price' => empty($in['show_list_price']) ? 0 : 1,
		]);
		MJPM_Settings::flush_caches();
		self::back(['saved' => 'settings']);
	}

	private static function unique_slug($title, array $existing) {
		$base = substr(sanitize_title($title), 0, 40);
		if ('' === $base) {
			$base = 'metodo';
		}
		$slug = $base;
		$i    = 2;
		while (isset($existing[$slug])) {
			$slug = $base . '-' . $i;
			$i++;
		}
		return $slug;
	}

	public static function save_custom() {
		self::guard('mjpm_save_custom');
		$in   = isset($_POST['custom']) && is_array($_POST['custom']) ? wp_unslash($_POST['custom']) : [];
		$slug = isset($in['slug']) ? sanitize_key($in['slug']) : '';
		$def  = MJPM_Settings::sanitize_custom($in);
		if ('' === $def['title']) {
			self::back(['error' => 'title', 'edit' => $slug]);
		}
		$all = MJPM_Settings::custom();
		if ('' === $slug) {
			$slug = self::unique_slug($def['title'], $all);
		} elseif (!isset($all[$slug])) {
			self::back(['error' => 'missing']);
		}
		$def['id']  = MJPM_Settings::gateway_id($slug);
		$all[$slug] = $def;
		MJPM_Settings::update_custom($all);

		/* "Activar" vive en la opción del gateway de WooCommerce (así funciona su interruptor). */
		$option = get_option('woocommerce_' . $def['id'] . '_settings', []);
		$option = is_array($option) ? $option : [];
		$option['enabled'] = empty($in['enabled']) ? 'no' : 'yes';
		update_option('woocommerce_' . $def['id'] . '_settings', $option);

		MJPM_Settings::flush_caches();
		self::back(['saved' => 'custom']);
	}

	public static function delete_custom() {
		$slug = isset($_GET['slug']) ? sanitize_key(wp_unslash($_GET['slug'])) : '';
		self::guard('mjpm_delete_' . $slug);
		$all = MJPM_Settings::custom();
		if (isset($all[$slug])) {
			$id = $all[$slug]['id'];
			unset($all[$slug]);
			MJPM_Settings::update_custom($all);
			delete_option('woocommerce_' . $id . '_settings');
			$methods = MJPM_Settings::methods();
			unset($methods[$id]);
			MJPM_Settings::update_methods($methods);
			$s = MJPM_Settings::get();
			MJPM_Settings::update([
				'base_method'    => $s['base_method'] === $id ? '' : $s['base_method'],
				'display_method' => $s['display_method'] === $id ? '' : $s['display_method'],
			]);
			MJPM_Settings::flush_caches();
		}
		self::back(['saved' => 'deleted']);
	}

	/* --------------------- Exclusión por producto/categoría ---------------- */

	public static function product_field() {
		$excluded = 'yes' === get_post_meta(get_the_ID(), '_mjpm_exclude', true);
		echo '<div class="options_group mjpm-product-options">';
		woocommerce_wp_checkbox([
			'id'          => '_mjpm_exclude',
			'label'       => 'Medios de pago',
			'description' => 'Sin descuento ni recargo por medio de pago: este producto se vende siempre al precio base.',
			'value'       => $excluded ? 'yes' : 'no',
		]);
		echo '<input type="hidden" name="mjpm_fields" value="1">';
		echo '</div>';
	}

	public static function save_product_field($product) {
		if (!$product instanceof WC_Product || !isset($_POST['mjpm_fields'])) {
			return;
		}
		if (!empty($_POST['_mjpm_exclude'])) {
			$product->update_meta_data('_mjpm_exclude', 'yes');
		} else {
			$product->delete_meta_data('_mjpm_exclude');
		}
	}

	public static function category_add_field() {
		echo '<div class="form-field"><label><input type="checkbox" name="mjpm_exclude" value="yes"> Sin descuento ni recargo por medio de pago</label>';
		echo '<input type="hidden" name="mjpm_cat_fields" value="1">';
		echo '<p>Los productos de esta categoría se venden siempre al precio base (MJ Medios de Pago).</p></div>';
	}

	public static function category_edit_field($term) {
		$excluded = 'yes' === get_term_meta($term->term_id, 'mjpm_exclude', true);
		echo '<tr class="form-field"><th scope="row">Medios de pago</th><td>';
		echo '<label><input type="checkbox" name="mjpm_exclude" value="yes" ' . checked($excluded, true, false) . '> Sin descuento ni recargo por medio de pago</label>';
		echo '<input type="hidden" name="mjpm_cat_fields" value="1">';
		echo '<p class="description">Los productos de esta categoría se venden siempre al precio base (MJ Medios de Pago).</p>';
		echo '</td></tr>';
	}

	public static function save_category_field($term_id) {
		if (!isset($_POST['mjpm_cat_fields'])) {
			return;
		}
		if (!empty($_POST['mjpm_exclude'])) {
			update_term_meta($term_id, 'mjpm_exclude', 'yes');
		} else {
			delete_term_meta($term_id, 'mjpm_exclude');
		}
	}

	/* -------------------------------- Página ----------------------------- */

	private static function option_name($id) {
		return 'woocommerce_' . $id . '_settings';
	}

	public static function render() {
		if (!current_user_can('manage_options')) {
			wp_die('Sin permisos.');
		}
		$s        = MJPM_Settings::get();
		$gateways = MJPM_Methods::gateways();
		$custom   = MJPM_Settings::custom();
		$saved    = isset($_GET['saved']) ? sanitize_key($_GET['saved']) : '';
		$error    = isset($_GET['error']) ? sanitize_key($_GET['error']) : '';
		$edit     = isset($_GET['edit']) ? sanitize_key($_GET['edit']) : '';
		$editing  = '' !== $edit && isset($custom[$edit]) ? $custom[$edit] : null;
		$example  = isset($_GET['ejemplo']) ? MJPM_Settings::decimal(wp_unslash($_GET['ejemplo'])) : 100000;
		if ($example <= 0) {
			$example = 100000;
		}
		$has_mjd  = class_exists('MJD_Rate');
		$messages = [
			'methods'  => 'Ajustes por medio de pago guardados.',
			'settings' => 'Ajustes generales guardados.',
			'custom'   => 'Método propio guardado. Ya aparece en el checkout y en la tabla de ajustes.',
			'deleted'  => 'Método propio borrado.',
		];
		?>
		<div class="wrap mjpm-wrap">
			<h1 class="mjpm-title">💳 MJ Medios de Pago</h1>
			<p class="mjpm-bajada">Elegí qué medio de pago tiene el precio base (el cargado en WooCommerce) y qué descuento o recargo lleva cada uno de los demás. El cliente ve el precio según el medio que elige y el ajuste entra al pedido como una línea aparte, con su justificación.</p>

			<?php if (isset($messages[$saved])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html($messages[$saved]); ?></p></div>
			<?php endif; ?>
			<?php if ('title' === $error) : ?>
				<div class="notice notice-error"><p>El método propio necesita un título.</p></div>
			<?php elseif ('missing' === $error) : ?>
				<div class="notice notice-error"><p>Ese método propio ya no existe.</p></div>
			<?php endif; ?>
			<?php if ('' === $s['base_method']) : ?>
				<div class="notice notice-warning"><p><strong>Falta elegir el precio base.</strong> Marcá en la tabla qué medio de pago corresponde al precio cargado en WooCommerce.</p></div>
			<?php endif; ?>

			<!-- ------------------------- Tabla de ajustes ------------------------- -->
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mjpm-card">
				<input type="hidden" name="action" value="mjpm_save_methods">
				<?php wp_nonce_field('mjpm_save_methods'); ?>
				<h2>Medios de pago y ajustes</h2>
				<p class="description">Aparecen todos los medios de pago registrados en WooCommerce (los desactivados en gris). Los propios se crean más abajo. “Por defecto” es el método con el que se muestran los precios hasta que el visitante elige otro.</p>
				<div class="mjpm-table-wrap">
				<table class="widefat mjpm-table">
					<thead>
						<tr>
							<th>Medio de pago</th>
							<th class="mjpm-center">Precio base</th>
							<th class="mjpm-center">Por defecto</th>
							<th>Ajuste</th>
							<th>Justificación (línea del carrito)</th>
							<th>Badge en tarjetas</th>
							<th>Nombre corto</th>
							<th>Mínimo</th>
							<th class="mjpm-center">Mostrar</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($gateways as $id => $gateway) :
						$cfg     = MJPM_Settings::method($id);
						$enabled = 'yes' === $gateway->enabled;
						$is_own  = (bool) MJPM_Settings::custom_by_gateway($id);
						$name    = 'methods[' . esc_attr($id) . ']';
						?>
						<tr class="<?php echo $enabled ? '' : 'mjpm-row--off'; ?>" data-mjpm-row>
							<td class="mjpm-method">
								<strong><?php echo esc_html(wp_strip_all_tags((string) $gateway->get_title())); ?></strong>
								<small><?php echo esc_html($id); ?><?php echo $is_own ? ' · propio' : ''; ?><?php echo $enabled ? '' : ' · desactivado'; ?></small>
								<?php if (!$is_own) : ?>
									<small><a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $id)); ?>">ajustes en WooCommerce</a></small>
								<?php endif; ?>
							</td>
							<td class="mjpm-center"><input type="radio" name="base_method" value="<?php echo esc_attr($id); ?>" <?php checked($s['base_method'], $id); ?> title="Este método corresponde al precio cargado en WooCommerce"></td>
							<td class="mjpm-center"><input type="radio" name="display_method" value="<?php echo esc_attr($id); ?>" <?php checked($s['display_method'], $id); ?> title="Con este método se muestran los precios por defecto"></td>
							<td class="mjpm-adjust">
								<select name="<?php echo $name; ?>[kind]" data-mjpm-kind <?php disabled($s['base_method'], $id); ?>>
									<option value="none" <?php selected($cfg['kind'], 'none'); ?>>Sin ajuste</option>
									<option value="discount" <?php selected($cfg['kind'], 'discount'); ?>>Descuento</option>
									<option value="surcharge" <?php selected($cfg['kind'], 'surcharge'); ?>>Recargo</option>
								</select>
								<span class="mjpm-adjust__value">
									<input type="text" inputmode="decimal" name="<?php echo $name; ?>[value]" value="<?php echo esc_attr($cfg['value'] > 0 ? MJPM_Methods::number($cfg['value']) : ''); ?>" placeholder="10" data-mjpm-value>
									<select name="<?php echo $name; ?>[mode]" data-mjpm-mode>
										<option value="percent" <?php selected($cfg['mode'], 'percent'); ?>>% por unidad</option>
										<option value="fixed" <?php selected($cfg['mode'], 'fixed'); ?>>$ fijo por pedido</option>
									</select>
								</span>
								<?php if ($s['base_method'] === $id) : ?>
									<input type="hidden" name="<?php echo $name; ?>[kind]" value="none">
									<small class="mjpm-base-note">precio base</small>
								<?php endif; ?>
								<small class="mjpm-example" data-mjpm-example></small>
							</td>
							<td><input type="text" name="<?php echo $name; ?>[label]" value="<?php echo esc_attr($cfg['label']); ?>" placeholder="Descuento por pago con {metodo} ({porcentaje})" class="mjpm-wide"></td>
							<td><input type="text" name="<?php echo $name; ?>[badge]" value="<?php echo esc_attr($cfg['badge']); ?>" placeholder="{porcentaje} OFF con {metodo}" class="mjpm-wide"></td>
							<td><input type="text" name="<?php echo $name; ?>[short]" value="<?php echo esc_attr($cfg['short']); ?>" placeholder="<?php echo esc_attr(wp_strip_all_tags((string) $gateway->get_title())); ?>"></td>
							<td><input type="text" inputmode="decimal" name="<?php echo $name; ?>[min_amount]" value="<?php echo esc_attr($cfg['min_amount'] > 0 ? MJPM_Methods::number($cfg['min_amount']) : ''); ?>" placeholder="0" class="mjpm-narrow"></td>
							<td class="mjpm-center"><input type="checkbox" name="<?php echo $name; ?>[show]" value="1" <?php checked((int) $cfg['show'], 1); ?>></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p class="description">Placeholders para justificación y badge: <code>{metodo}</code> (nombre corto), <code>{porcentaje}</code> (“10%”), <code>{monto}</code> (monto fijo). Vacío = texto automático. El badge solo se usa en descuentos por porcentaje. “Mínimo” = subtotal mínimo de productos elegibles para aplicar el ajuste.</p>
				<p class="submit"><button type="submit" class="button button-primary">Guardar medios de pago</button></p>
			</form>

			<div class="mjpm-grid">
				<!-- ------------------------- Métodos propios ------------------------ -->
				<section class="mjpm-card">
					<h2>Métodos propios</h2>
					<p class="description">Medios de pago offline que creás vos (por ejemplo “Pago en dólares en el local”). Funcionan como cualquier otro: aparecen en el checkout, en el pedido y en los emails.</p>
					<?php if ($custom) : ?>
						<table class="widefat striped mjpm-custom-list">
							<thead><tr><th>Método</th><th>Estado del pedido</th><th>Moneda</th><th>Activo</th><th></th></tr></thead>
							<tbody>
							<?php foreach ($custom as $slug => $def) :
								$opt = get_option(self::option_name($def['id']), []);
								$on  = !is_array($opt) || !isset($opt['enabled']) || 'yes' === $opt['enabled'];
								?>
								<tr>
									<td><strong><?php echo esc_html($def['title']); ?></strong><br><small><?php echo esc_html($def['id']); ?></small></td>
									<td><?php echo esc_html(MJPM_Settings::statuses()[$def['status']]); ?></td>
									<td><?php echo esc_html($def['currency']); ?></td>
									<td><?php echo $on ? 'Sí' : 'No'; ?></td>
									<td class="mjpm-actions">
										<a href="<?php echo esc_url(self::page_url(['edit' => $slug]) . '#mjpm-custom-form'); ?>">Editar</a> ·
										<a class="mjpm-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mjpm_delete_custom&slug=' . $slug), 'mjpm_delete_' . $slug)); ?>">Borrar</a>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="description">Todavía no creaste ninguno.</p>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="mjpm-custom-form" class="mjpm-custom-form">
						<input type="hidden" name="action" value="mjpm_save_custom">
						<?php wp_nonce_field('mjpm_save_custom'); ?>
						<input type="hidden" name="custom[slug]" value="<?php echo esc_attr($editing ? $edit : ''); ?>">
						<h3><?php echo $editing ? 'Editar “' . esc_html($editing['title']) . '”' : 'Nuevo método propio'; ?></h3>
						<?php
						$d   = $editing ? $editing : MJPM_Settings::custom_defaults();
						$opt = $editing ? get_option(self::option_name($editing['id']), []) : [];
						$on  = !$editing || !is_array($opt) || !isset($opt['enabled']) || 'yes' === $opt['enabled'];
						?>
						<p><label>Título <span class="description">(lo ve el cliente en el checkout)</span><br><input type="text" name="custom[title]" value="<?php echo esc_attr($d['title']); ?>" class="widefat" required placeholder="Pago en dólares en el local"></label></p>
						<p><label>Descripción en el checkout<br><textarea name="custom[description]" rows="2" class="widefat" placeholder="Pagás en efectivo en dólares al retirar tu pedido."><?php echo esc_textarea($d['description']); ?></textarea></label></p>
						<p><label>Instrucciones (página de gracias y email)<br><textarea name="custom[instructions]" rows="3" class="widefat" placeholder="Te esperamos en Av. Siempreviva 123, de lunes a viernes de 9 a 18. Traé el número de pedido."><?php echo esc_textarea($d['instructions']); ?></textarea></label></p>
						<p><label>Estado del pedido al confirmar<br>
							<select name="custom[status]">
								<?php foreach (MJPM_Settings::statuses() as $value => $label) : ?>
									<option value="<?php echo esc_attr($value); ?>" <?php selected($d['status'], $value); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select></label></p>
						<p><label>Moneda en que se cobra<br>
							<select name="custom[currency]">
								<option value="ARS" <?php selected($d['currency'], 'ARS'); ?>>Pesos (moneda de la tienda)</option>
								<option value="USD" <?php selected($d['currency'], 'USD'); ?> <?php disabled(!$has_mjd); ?>>Dólares<?php echo $has_mjd ? ' (muestra el total en USD con el TC de MJ Dólar)' : ' (requiere MJ Dólar activo)'; ?></option>
							</select></label></p>
						<p><label><input type="checkbox" name="custom[enabled]" value="1" <?php checked($on); ?>> Activo en el checkout</label></p>
						<p class="submit">
							<button type="submit" class="button button-primary"><?php echo $editing ? 'Guardar cambios' : 'Crear método'; ?></button>
							<?php if ($editing) : ?><a class="button" href="<?php echo esc_url(self::page_url()); ?>">Cancelar</a><?php endif; ?>
						</p>
						<p class="description">Después de crearlo, asignale su descuento o recargo en la tabla de arriba.</p>
					</form>
				</section>

				<!-- ------------------------- Ajustes generales ---------------------- -->
				<section class="mjpm-card">
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="mjpm_save_settings">
						<?php wp_nonce_field('mjpm_save_settings'); ?>
						<h2>Ajustes generales</h2>
						<table class="form-table mjpm-form-table" role="presentation">
							<tr>
								<th scope="row"><label for="mjpm_rounding">Redondeo</label></th>
								<td>
									<select id="mjpm_rounding" name="settings[rounding]">
										<?php foreach (MJPM_Settings::rounding_choices() as $value => $label) : ?>
											<option value="<?php echo esc_attr($value); ?>" <?php selected((int) $s['rounding'], (int) $value); ?>><?php echo esc_html($label); ?></option>
										<?php endforeach; ?>
									</select>
									<select name="settings[rounding_mode]" aria-label="Modo">
										<option value="nearest" <?php selected($s['rounding_mode'], 'nearest'); ?>>al más cercano</option>
										<option value="up" <?php selected($s['rounding_mode'], 'up'); ?>>siempre para arriba</option>
									</select>
									<p class="description">Del precio con descuento o recargo. <?php echo $has_mjd ? 'Conviene usar el mismo que en MJ Dólar.' : ''; ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">Reglas</th>
								<td>
									<label><input type="checkbox" name="settings[apply_on_sale]" value="1" <?php checked((int) $s['apply_on_sale'], 1); ?>> Los descuentos también se aplican a productos en oferta</label><br>
									<label><input type="checkbox" name="settings[stack_coupons]" value="1" <?php checked((int) $s['stack_coupons'], 1); ?>> Los descuentos se acumulan con cupones</label>
									<p class="description">Los recargos se aplican siempre. El porcentaje se calcula solo sobre productos, nunca sobre el envío.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">En la tienda</th>
								<td>
									<label><input type="checkbox" name="settings[show_selector]" value="1" <?php checked((int) $s['show_selector'], 1); ?>> Selector “Ver precios con” en el header y el carrito</label><br>
									<label style="display:inline-block; margin:6px 0 8px 24px;">Texto del selector <input type="text" name="settings[selector_label]" value="<?php echo esc_attr($s['selector_label']); ?>" maxlength="40"></label><br>
									<label><input type="checkbox" name="settings[show_badge]" value="1" <?php checked((int) $s['show_badge'], 1); ?>> Badge en las tarjetas de producto (“10% OFF con transferencia”)</label><br>
									<label><input type="checkbox" name="settings[show_panel]" value="1" <?php checked((int) $s['show_panel'], 1); ?>> Panel “Precio según medio de pago” en la ficha</label><br>
									<label><input type="checkbox" name="settings[show_list_price]" value="1" <?php checked((int) $s['show_list_price'], 1); ?>> Mostrar el precio base tachado cuando el método elegido tiene descuento</label>
									<p class="description">Requiere el tema MJ Appearance 3.3 o superior.</p>
								</td>
							</tr>
						</table>
						<p class="submit"><button type="submit" class="button button-primary">Guardar ajustes</button></p>
					</form>
				</section>
			</div>

			<!-- --------------------------- Vista previa --------------------------- -->
			<section class="mjpm-card">
				<h2>Vista previa</h2>
				<form method="get" class="mjpm-preview-form">
					<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
					<label>Producto de <input type="text" inputmode="decimal" name="ejemplo" value="<?php echo esc_attr(MJPM_Methods::number($example)); ?>"></label>
					<button class="button">Calcular</button>
				</form>
				<table class="widefat striped mjpm-preview">
					<thead><tr><th>Medio de pago</th><th>Ajuste</th><th>Precio que ve el cliente</th><th>Línea del carrito (1 unidad)</th></tr></thead>
					<tbody>
					<?php foreach ($gateways as $id => $gateway) :
						if ('yes' !== $gateway->enabled) {
							continue;
						}
						$cfg   = MJPM_Methods::config($id);
						$price = MJPM_Methods::price_for($example, $cfg);
						$diff  = 'fixed' === $cfg['mode'] && MJPM_Methods::adjusts($cfg) ? ('discount' === $cfg['kind'] ? -1 : 1) * (float) $cfg['value'] : $price - $example;
						?>
						<tr>
							<td><?php echo esc_html(wp_strip_all_tags((string) $gateway->get_title())); ?><?php echo $s['base_method'] === $id ? ' <small>(precio base)</small>' : ''; ?><?php echo $s['display_method'] === $id ? ' <small>(por defecto)</small>' : ''; ?></td>
							<td><?php
								if (!MJPM_Methods::adjusts($cfg)) {
									echo '—';
								} else {
									echo esc_html(('discount' === $cfg['kind'] ? 'Descuento ' : 'Recargo ') . ('percent' === $cfg['mode'] ? MJPM_Methods::number($cfg['value']) . '%' : MJPM_Methods::money($cfg['value']) . ' por pedido'));
								}
							?></td>
							<td><strong><?php echo esc_html(MJPM_Methods::money($price)); ?></strong></td>
							<td><?php echo abs($diff) < 0.005 ? '—' : esc_html(MJPM_Methods::label($cfg) . ': ' . ($diff < 0 ? '− ' : '+ ') . MJPM_Methods::money(abs($diff))); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>

			<section class="mjpm-card mjpm-dev">
				<h2>Para desarrolladores (otros plugins MJ)</h2>
				<ul>
					<li><code>MJPM_Methods::config($id)</code> → ajuste del método; <code>MJPM_Methods::price_for($pesos, $cfg)</code> → precio con ese método; <code>MJPM_Methods::public_payload()</code> → config pública.</li>
					<li><code>MJPM_Cart::effective_method_id()</code> → método vigente en la sesión; <code>MJPM_Methods::calculate_for_cart($cart, $id)</code> → línea del carrito.</li>
					<li>Filtros: <code>mjpm_product_eligible($ok, $product)</code>, <code>mjpm_cart_adjustment($calc, $cart, $cfg)</code>. Acción: <code>mjpm_flush_caches</code>.</li>
					<li>REST: <code>GET /wp-json/mjpm/v1/methods</code>, <code>GET /wp-json/mjpm/v1/price?amount=100000&amp;method=bacs</code>. Store API: <code>extensions.mjpm</code> en producto, ítem y carrito; <code>POST /wc/store/v1/cart/extensions</code> con <code>{namespace:"mjpm", data:{payment_method}}</code>.</li>
					<li>Meta de pedido: <code>_mjpm_method</code>, <code>_mjpm_kind</code>, <code>_mjpm_value</code>, <code>_mjpm_amount</code>, <code>_mjpm_label</code>, <code>_mjpm_currency</code>, <code>_mjpm_rate</code>. Producto: <code>_mjpm_exclude</code>. Categoría: <code>mjpm_exclude</code>.</li>
				</ul>
			</section>
			<p class="mjpm-credito">MJ Medios de Pago <?php echo esc_html(MJPM_VERSION); ?> · Creado por MJ Importaciones</p>
		</div>
		<?php
	}
}
