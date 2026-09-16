<?php
/**
 * wp-admin: página WooCommerce → Medios de pago (tabla de ajustes, editor de
 * cada medio con sus textos y datos para pagar, métodos propios, ajustes
 * generales, vista previa), exclusión por producto y por categoría.
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
		add_action('admin_post_mjpm_save_medio', [__CLASS__, 'save_medio']);
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
			'detailsMax'    => MJPM_Settings::DETAILS_MAX,
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
			/*
			 * Se pisa solo lo que trae la tabla. El comprobante y los datos para
			 * pagar se guardan en otros formularios sobre la misma opción: sin
			 * fusionar, guardar la tabla los borraba.
			 */
			$current  = isset($all[$id]) ? $all[$id] : MJPM_Settings::method_defaults();
			$all[$id] = MJPM_Settings::sanitize_method(array_merge($current, array_intersect_key($in, self::table_fields())));
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

	/** Lo que se edita en la tabla de ajustes (el resto de la config del medio no se toca ahí). */
	private static function table_fields() {
		return array_flip(['kind', 'mode', 'value', 'label', 'badge', 'short', 'show', 'min_amount']);
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
			'details_title'   => isset($in['details_title']) ? sanitize_text_field($in['details_title']) : '',
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

	/** Crea un método propio. Después se edita como cualquier medio, desde su editor. */
	public static function save_custom() {
		self::guard('mjpm_save_custom');
		$in  = isset($_POST['custom']) && is_array($_POST['custom']) ? wp_unslash($_POST['custom']) : [];
		$def = MJPM_Settings::sanitize_custom($in);
		if ('' === $def['title']) {
			self::back(['error' => 'title']);
		}
		$all        = MJPM_Settings::custom();
		$slug       = self::unique_slug($def['title'], $all);
		$def['id']  = MJPM_Settings::gateway_id($slug);
		$all[$slug] = $def;
		MJPM_Settings::update_custom($all);
		self::save_enabled($def['id'], !empty($in['enabled']));

		MJPM_Settings::flush_caches();
		/* Directo a su editor: lo próximo que hace falta son sus datos para pagar. */
		self::back(['saved' => 'custom', 'medio' => $def['id']]);
	}

	/** "Activar" de un método propio vive en la opción de su gateway (así funciona el interruptor de WooCommerce). */
	private static function save_enabled($gateway_id, $on) {
		$option = get_option(self::option_name($gateway_id), []);
		$option = is_array($option) ? $option : [];

		$option['enabled'] = $on ? 'yes' : 'no';
		update_option(self::option_name($gateway_id), $option);
	}

	/** ¿El gateway tiene el interruptor "Activar" de WooCommerce? */
	private static function has_enabled($gateway) {
		$form = method_exists($gateway, 'get_form_fields') ? (array) $gateway->get_form_fields() : [];
		return isset($form['enabled']);
	}

	/** Slug del método propio detrás de un gateway, o ''. */
	private static function custom_slug($gateway_id) {
		foreach (MJPM_Settings::custom() as $slug => $def) {
			if ($def['id'] === $gateway_id) {
				return (string) $slug;
			}
		}
		return '';
	}

	/**
	 * Textos que un gateway de WooCommerce (o de otro plugin) declara en sus
	 * ajustes y que se pueden editar desde acá: clave => tipo de campo.
	 *
	 * Solo los que el gateway tiene. Si uno no declara instrucciones, no se
	 * inventan: se editan en sus propios ajustes.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway.
	 * @return array
	 */
	private static function native_fields($gateway) {
		$form = method_exists($gateway, 'get_form_fields') ? (array) $gateway->get_form_fields() : [];
		$out  = [];
		foreach (['title', 'description', 'instructions'] as $key) {
			$type = isset($form[$key]['type']) ? (string) $form[$key]['type'] : '';
			if (in_array($type, ['text', 'safe_text', 'textarea'], true)) {
				$out[$key] = $type;
			}
		}
		return $out;
	}

	/**
	 * Guarda el editor de un medio: sus textos (en la opción del gateway o del
	 * método propio, según de quién sea) y sus datos para pagar.
	 */
	public static function save_medio() {
		self::guard('mjpm_save_medio');
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- El nonce lo verifica self::guard(), arriba.
		$id = isset($_POST['medio']) ? sanitize_key(wp_unslash($_POST['medio'])) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cada campo se limpia al usarlo (sanitize_custom, sanitize_method, native_fields).
		$in = isset($_POST['medio_data']) && is_array($_POST['medio_data']) ? wp_unslash($_POST['medio_data']) : [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$gateway = '' !== $id ? MJPM_Methods::gateway($id) : null;
		if (!$gateway) {
			self::back(['error' => 'missing']);
		}

		$slug = self::custom_slug($id);
		if ('' !== $slug) {
			$all = MJPM_Settings::custom();
			$def = MJPM_Settings::sanitize_custom(array_merge($all[$slug], array_intersect_key($in, MJPM_Settings::custom_defaults())));
			if ('' === $def['title']) {
				self::back(['error' => 'title', 'medio' => $id]);
			}
			$all[$slug] = $def;
			MJPM_Settings::update_custom($all);
			self::save_enabled($id, !empty($in['enabled']));
		} else {
			/*
			 * En la opción que el gateway declara, como si se guardara desde
			 * WooCommerce. Se parte de lo guardado; si nunca se guardó, de sus
			 * valores por defecto.
			 */
			$key    = $gateway->get_option_key();
			$option = get_option($key, null);
			$option = is_array($option) ? $option : (is_array($gateway->settings) ? $gateway->settings : []);
			foreach (self::native_fields($gateway) as $field => $type) {
				if (!isset($in[$field])) {
					continue;
				}
				$value = 'textarea' === $type ? wp_kses_post(trim((string) $in[$field])) : sanitize_text_field((string) $in[$field]);
				if ('title' === $field && '' === $value) {
					self::back(['error' => 'title', 'medio' => $id]);
				}
				$option[$field] = $value;
			}
			if (self::has_enabled($gateway)) {
				$option['enabled'] = empty($in['enabled']) ? 'no' : 'yes';
			}
			update_option($key, $option);
		}

		/* Los datos para pagar, sobre la config del medio: el ajuste y el comprobante no se tocan. */
		$all     = MJPM_Settings::methods();
		$current = isset($all[$id]) ? $all[$id] : MJPM_Settings::method_defaults();

		$current['details']          = isset($in['details']) ? $in['details'] : [];
		$current['details_title']    = isset($in['details_title']) ? $in['details_title'] : '';
		$current['details_checkout'] = empty($in['details_checkout']) ? 0 : 1;

		$all[$id] = MJPM_Settings::sanitize_method($current);
		MJPM_Settings::update_methods($all);

		MJPM_Settings::flush_caches();
		self::back(['saved' => 'medio', 'medio' => $id]);
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

	/* ------------------------------- Editor ------------------------------ */

	/**
	 * Campos de un método propio. Los usan el alta y el editor, con distinto
	 * prefijo de nombre.
	 *
	 * @param string $prefix  Prefijo del name (custom | medio_data).
	 * @param array  $d       Definición.
	 * @param bool   $on      Activo.
	 * @param bool   $has_mjd MJ Dólar activo.
	 */
	private static function custom_fields($prefix, array $d, $on, $has_mjd) {
		?>
		<p><label>Título <span class="description">(lo ve el cliente en el checkout)</span><br><input type="text" name="<?php echo esc_attr($prefix); ?>[title]" value="<?php echo esc_attr($d['title']); ?>" class="widefat" required placeholder="Pago en dólares en el local"></label></p>
		<p><label>Descripción en el checkout<br><textarea name="<?php echo esc_attr($prefix); ?>[description]" rows="2" class="widefat" placeholder="Pagás en efectivo en dólares al retirar tu pedido."><?php echo esc_textarea($d['description']); ?></textarea></label></p>
		<p><label>Instrucciones <span class="description">(confirmación del pedido, Mi cuenta y email)</span><br><textarea name="<?php echo esc_attr($prefix); ?>[instructions]" rows="3" class="widefat" placeholder="Te esperamos en Av. Siempreviva 123, de lunes a viernes de 9 a 18. Traé el número de pedido."><?php echo esc_textarea($d['instructions']); ?></textarea></label></p>
		<p><label>Estado del pedido al confirmar<br>
			<select name="<?php echo esc_attr($prefix); ?>[status]">
				<?php foreach (MJPM_Settings::statuses() as $value => $label) : ?>
					<option value="<?php echo esc_attr($value); ?>" <?php selected($d['status'], $value); ?>><?php echo esc_html($label); ?></option>
				<?php endforeach; ?>
			</select></label></p>
		<p><label>Moneda en que se cobra<br>
			<select name="<?php echo esc_attr($prefix); ?>[currency]">
				<option value="ARS" <?php selected($d['currency'], 'ARS'); ?>>Pesos (moneda de la tienda)</option>
				<option value="USD" <?php selected($d['currency'], 'USD'); ?> <?php disabled(!$has_mjd); ?>>Dólares<?php echo $has_mjd ? ' (muestra el total en USD con el TC de MJ Dólar)' : ' (requiere MJ Dólar activo)'; ?></option>
			</select></label></p>
		<p><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[enabled]" value="1" <?php checked($on); ?>> Activo en el checkout</label></p>
		<?php
	}

	/**
	 * Una fila de la lista de datos para pagar. Con $i = '__i__' sale la
	 * plantilla que usa el JS para agregar filas.
	 *
	 * @param int|string $i   Índice.
	 * @param array      $row label, value, copy.
	 */
	private static function detail_row($i, array $row) {
		$name = 'medio_data[details][' . $i . ']';
		?>
		<tr data-mjpm-detail>
			<td><input type="text" name="<?php echo esc_attr($name); ?>[label]" value="<?php echo esc_attr($row['label']); ?>" maxlength="60" placeholder="Ej.: Alias" aria-label="Dato"></td>
			<td><input type="text" name="<?php echo esc_attr($name); ?>[value]" value="<?php echo esc_attr($row['value']); ?>" maxlength="200" placeholder="Ej.: mi.tienda.mp" aria-label="Valor"></td>
			<td class="mjpm-center"><input type="checkbox" name="<?php echo esc_attr($name); ?>[copy]" value="1" <?php checked(!empty($row['copy'])); ?> aria-label="Botón para copiar"></td>
			<td class="mjpm-details__actions">
				<button type="button" class="button-link" data-mjpm-detail-up aria-label="Subir">↑</button>
				<button type="button" class="button-link" data-mjpm-detail-down aria-label="Bajar">↓</button>
				<button type="button" class="button-link mjpm-delete-row" data-mjpm-detail-remove aria-label="Quitar">Quitar</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Editor de un medio de pago: sus textos y sus datos para pagar.
	 *
	 * Los textos de un método propio viven en MJ Medios de Pago; los de un
	 * gateway de WooCommerce se escriben en su propia opción, así que da lo
	 * mismo editarlos acá o en WooCommerce → Ajustes → Pagos.
	 *
	 * @param string             $id      Id del gateway.
	 * @param WC_Payment_Gateway $gateway Gateway.
	 * @param bool               $has_mjd MJ Dólar activo.
	 */
	private static function render_editor($id, $gateway, $has_mjd) {
		$slug    = self::custom_slug($id);
		$custom  = '' !== $slug ? MJPM_Settings::custom()[$slug] : null;
		$cfg     = MJPM_Settings::method($id);
		$fields  = $custom ? [] : self::native_fields($gateway);
		$title   = wp_strip_all_tags((string) $gateway->get_title());
		$on      = 'yes' === $gateway->enabled;
		$names   = ['title' => 'título', 'description' => 'descripción', 'instructions' => 'instrucciones'];
		$missing = $custom ? [] : array_values(array_diff_key($names, $fields));
		$rows    = $cfg['details'] ? $cfg['details'] : [['label' => '', 'value' => '', 'copy' => 1]];
		$bacs    = 'bacs' === $id ? get_option('woocommerce_bacs_accounts', []) : [];
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="mjpm-medio" class="mjpm-card mjpm-editor">
			<input type="hidden" name="action" value="mjpm_save_medio">
			<input type="hidden" name="medio" value="<?php echo esc_attr($id); ?>">
			<?php wp_nonce_field('mjpm_save_medio'); ?>

			<div class="mjpm-editor__head">
				<h2>Editar “<?php echo esc_html($title); ?>”</h2>
				<small><?php echo esc_html($id); ?> · <?php echo $custom ? 'método propio' : 'medio de WooCommerce'; ?></small>
			</div>

			<div class="mjpm-editor__grid">
				<section>
					<h3>Cómo se ve en el checkout</h3>
					<?php if ($custom) : ?>
						<?php self::custom_fields('medio_data', $custom, $on, $has_mjd); ?>
					<?php else : ?>
						<?php if (isset($fields['title'])) : ?>
							<p><label>Título <span class="description">(lo ve el cliente en el checkout)</span><br><input type="text" name="medio_data[title]" value="<?php echo esc_attr((string) $gateway->get_option('title')); ?>" class="widefat" required></label></p>
						<?php endif; ?>
						<?php if (isset($fields['description'])) : ?>
							<p><label>Descripción en el checkout<br><textarea name="medio_data[description]" rows="2" class="widefat"><?php echo esc_textarea((string) $gateway->get_option('description')); ?></textarea></label></p>
						<?php endif; ?>
						<?php if (isset($fields['instructions'])) : ?>
							<p><label>Instrucciones <span class="description">(confirmación del pedido, Mi cuenta y email)</span><br><textarea name="medio_data[instructions]" rows="3" class="widefat" placeholder="Transferí el total y subí el comprobante. Despachamos cuando se acredita."><?php echo esc_textarea((string) $gateway->get_option('instructions')); ?></textarea></label></p>
						<?php endif; ?>
						<?php if (self::has_enabled($gateway)) : ?>
							<p><label><input type="checkbox" name="medio_data[enabled]" value="1" <?php checked($on); ?>> Activo en el checkout</label></p>
						<?php endif; ?>
						<?php if ($missing) : ?>
							<p class="description">
								Este medio no tiene <?php echo esc_html(implode(' ni ', $missing)); ?> propios para editar desde acá.
								<a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $id)); ?>">Ver sus ajustes en WooCommerce</a>.
							</p>
						<?php else : ?>
							<p class="description">Se guardan en los ajustes del medio en WooCommerce: es lo mismo editarlos acá o allá.</p>
						<?php endif; ?>
					<?php endif; ?>
				</section>

				<section>
					<h3>Datos para pagar</h3>
					<p class="description">Lo que el cliente necesita para pagarte: alias, CBU o CVU, titular, CUIT, banco… Cada fila es un dato y la etiqueta la escribís vos. Las filas vacías no se guardan.</p>
					<p><label>Encabezado<br><input type="text" name="medio_data[details_title]" value="<?php echo esc_attr($cfg['details_title']); ?>" maxlength="80" class="regular-text" placeholder="<?php echo esc_attr((string) MJPM_Settings::get('details_title')); ?>"></label></p>

					<table class="widefat mjpm-details">
						<thead><tr><th>Dato</th><th>Valor</th><th class="mjpm-center">Copiar</th><th></th></tr></thead>
						<tbody data-mjpm-details data-next="<?php echo esc_attr((string) count($rows)); ?>">
							<?php foreach (array_values($rows) as $i => $row) : ?>
								<?php self::detail_row($i, $row); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
					<template id="mjpm-detail-template"><?php self::detail_row('__i__', ['label' => '', 'value' => '', 'copy' => 1]); ?></template>
					<p><button type="button" class="button" data-mjpm-detail-add>+ Agregar dato</button></p>
					<p class="description">“Copiar” le pone al lado un botón para copiarlo con un toque (útil en alias y CBU).</p>

					<p><label><input type="checkbox" name="medio_data[details_checkout]" value="1" <?php checked((int) $cfg['details_checkout'], 1); ?>> Mostrarlos también en el checkout, al elegir este medio</label></p>
					<p class="description">Después de comprar se ven siempre, mientras el pedido espere el pago: en la confirmación, en Mi cuenta, en el email y en el link para subir el comprobante.</p>

					<?php if (is_array($bacs) && $bacs) : ?>
						<p class="mjpm-editor__warning">WooCommerce tiene además <?php echo esc_html(1 === count($bacs) ? '1 cuenta cargada' : count($bacs) . ' cuentas cargadas'); ?> en los ajustes de Transferencia. Esas salen solo en el email y en la página de gracias clásica, no en la tienda: conviene pasarlas acá y <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=bacs')); ?>">borrarlas allá</a> para que no se vean repetidas.</p>
					<?php endif; ?>
				</section>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary">Guardar medio de pago</button>
				<a class="button" href="<?php echo esc_url(self::page_url()); ?>">Cerrar</a>
			</p>
		</form>
		<?php
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
		$medio    = isset($_GET['medio']) ? sanitize_key($_GET['medio']) : '';
		$example  = isset($_GET['ejemplo']) ? MJPM_Settings::decimal(wp_unslash($_GET['ejemplo'])) : 100000;
		if ($example <= 0) {
			$example = 100000;
		}
		$has_mjd  = class_exists('MJD_Rate');
		$messages = [
			'methods'  => 'Ajustes por medio de pago guardados.',
			'settings' => 'Ajustes generales guardados.',
			'custom'   => 'Método propio creado. Ya aparece en el checkout y en la tabla de ajustes: cargale acá sus datos para pagar.',
			'medio'    => 'Medio de pago guardado. Los cambios ya se ven en la tienda.',
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
				<div class="notice notice-error"><p>El medio de pago necesita un título: es lo que ve el cliente en el checkout.</p></div>
			<?php elseif ('missing' === $error) : ?>
				<div class="notice notice-error"><p>Ese medio de pago ya no existe.</p></div>
			<?php endif; ?>
			<?php if ('' === $s['base_method']) : ?>
				<div class="notice notice-warning"><p><strong>Falta elegir el precio base.</strong> Marcá en la tabla qué medio de pago corresponde al precio cargado en WooCommerce.</p></div>
			<?php endif; ?>

			<?php
			if ('' !== $medio && isset($gateways[$medio])) {
				self::render_editor($medio, $gateways[$medio], $has_mjd);
			}
			?>

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
						$n_data  = count($cfg['details']);
						?>
						<tr class="<?php echo $enabled ? '' : 'mjpm-row--off'; ?><?php echo $medio === $id ? ' mjpm-row--editing' : ''; ?>" data-mjpm-row>
							<td class="mjpm-method">
								<strong><?php echo esc_html(wp_strip_all_tags((string) $gateway->get_title())); ?></strong>
								<small><?php echo esc_html($id); ?><?php echo $is_own ? ' · propio' : ''; ?><?php echo $enabled ? '' : ' · desactivado'; ?></small>
								<small><a class="mjpm-edit-link" href="<?php echo esc_url(self::page_url(['medio' => $id]) . '#mjpm-medio'); ?>">Editar textos y datos para pagar</a><?php echo $n_data ? ' · ' . esc_html(1 === $n_data ? '1 dato' : $n_data . ' datos') : ''; ?></small>
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
										<a href="<?php echo esc_url(self::page_url(['medio' => $def['id']]) . '#mjpm-medio'); ?>">Editar</a> ·
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
						<h3>Nuevo método propio</h3>
						<?php self::custom_fields('custom', MJPM_Settings::custom_defaults(), true, $has_mjd); ?>
						<p class="submit"><button type="submit" class="button button-primary">Crear método</button></p>
						<p class="description">Después de crearlo se abre su editor para cargarle los datos para pagar. El descuento o recargo se asigna en la tabla de arriba.</p>
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
							<tr>
								<th scope="row"><label for="mjpm_details_title">Datos para pagar</label></th>
								<td>
									<input type="text" id="mjpm_details_title" name="settings[details_title]" value="<?php echo esc_attr($s['details_title']); ?>" maxlength="80" class="regular-text">
									<p class="description">Encabezado de los datos de cada medio (alias, CBU…) en la tienda y el email, salvo que el medio tenga el suyo.</p>
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
					<li>Cómo pagar: <code>MJPM_Payment_Info::for_method($id)</code>, <code>MJPM_Payment_Info::for_order($order)</code>. Filtros <code>mjpm_payment_info($info, $id)</code> y <code>mjpm_payment_info_statuses($estados, $order)</code>. REST <code>GET /wp-json/mjpm/v1/como-pagar?pedido=ID&amp;clave=CLAVE</code>.</li>
					<li>REST: <code>GET /wp-json/mjpm/v1/methods</code>, <code>GET /wp-json/mjpm/v1/price?amount=100000&amp;method=bacs</code>. Store API: <code>extensions.mjpm</code> en producto, ítem y carrito; <code>POST /wc/store/v1/cart/extensions</code> con <code>{namespace:"mjpm", data:{payment_method}}</code>.</li>
					<li>Meta de pedido: <code>_mjpm_method</code>, <code>_mjpm_kind</code>, <code>_mjpm_value</code>, <code>_mjpm_amount</code>, <code>_mjpm_label</code>, <code>_mjpm_currency</code>, <code>_mjpm_rate</code>. Producto: <code>_mjpm_exclude</code>. Categoría: <code>mjpm_exclude</code>.</li>
				</ul>
			</section>
			<p class="mjpm-credito">MJ Medios de Pago <?php echo esc_html(MJPM_VERSION); ?> · Creado por MJ Importaciones</p>
		</div>
		<?php
	}
}
