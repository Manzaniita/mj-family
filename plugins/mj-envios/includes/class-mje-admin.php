<?php
/**
 * wp-admin: WooCommerce → Envíos.
 *
 * Cinco pantallas, en el orden en el que se usan:
 *
 *   Métodos           la lista y el editor de cada método de envío.
 *   Datos del cliente qué se le pide (y campos propios que agregues vos).
 *   Códigos postales  la tabla propia: cuántos hay, importar un CSV, sumar uno.
 *   Correos           credenciales y URLs de cada correo, y sus sucursales.
 *   Ajustes           origen, peso, precio, textos, avisos.
 *
 * Toda la pantalla se dibuja con lo que declaran las clases: los tipos de
 * método, los campos de cada correo, el catálogo de datos del cliente. Sumar
 * una opción nueva es declararla ahí, no tocar este archivo.
 */

defined('ABSPATH') || exit;

class MJE_Admin {

	const SLUG = 'mj-envios';

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
		add_action('admin_notices', [__CLASS__, 'notices']);
		add_filter('plugin_action_links_' . plugin_basename(MJE_FILE), [__CLASS__, 'action_links']);

		add_action('admin_post_mje_save_method', [__CLASS__, 'save_method']);
		add_action('admin_post_mje_delete_method', [__CLASS__, 'delete_method']);
		add_action('admin_post_mje_duplicate_method', [__CLASS__, 'duplicate_method']);
		add_action('admin_post_mje_toggle_method', [__CLASS__, 'toggle_method']);
		add_action('admin_post_mje_save_fields', [__CLASS__, 'save_fields']);
		add_action('admin_post_mje_save_settings', [__CLASS__, 'save_settings']);
		add_action('admin_post_mje_save_carrier', [__CLASS__, 'save_carrier']);
		add_action('admin_post_mje_sync_branches', [__CLASS__, 'sync_branches']);
		add_action('admin_post_mje_save_branch', [__CLASS__, 'save_branch']);
		add_action('admin_post_mje_delete_branch', [__CLASS__, 'delete_branch']);
		add_action('admin_post_mje_import_geo', [__CLASS__, 'import_geo']);
		add_action('admin_post_mje_add_geo', [__CLASS__, 'add_geo']);
		add_action('admin_post_mje_clear_geo', [__CLASS__, 'clear_geo']);
		add_action('admin_post_mje_clear_quotes', [__CLASS__, 'clear_quotes']);

		/* Peso por defecto del producto y de la categoría. */
		add_action('woocommerce_product_options_shipping_product_data', [__CLASS__, 'product_field']);
		add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_product_field']);
		add_action('product_cat_add_form_fields', [__CLASS__, 'category_add_field']);
		add_action('product_cat_edit_form_fields', [__CLASS__, 'category_edit_field']);
		add_action('created_product_cat', [__CLASS__, 'save_category_field']);
		add_action('edited_product_cat', [__CLASS__, 'save_category_field']);
	}

	/* -------------------------------- Menú ------------------------------- */

	public static function menu() {
		add_submenu_page('woocommerce', 'MJ Envíos', '📦 Envíos', 'manage_woocommerce', self::SLUG, [__CLASS__, 'render']);
	}

	public static function page_url($args = []) {
		return add_query_arg($args, admin_url('admin.php?page=' . self::SLUG));
	}

	public static function action_links($links) {
		array_unshift($links, '<a href="' . esc_url(self::page_url()) . '">Configurar</a>');
		return $links;
	}

	public static function assets($hook) {
		if ('woocommerce_page_' . self::SLUG !== $hook) {
			return;
		}
		wp_enqueue_style('mje-admin', MJE_URL . 'assets/admin.css', [], MJE_VERSION);
		wp_enqueue_script('mje-admin', MJE_URL . 'assets/admin.js', [], MJE_VERSION, true);
		wp_localize_script('mje-admin', 'mjeAdmin', [
			'confirmDelete' => '¿Borrar este método? Los pedidos ya hechos no se tocan.',
			'confirmClear'  => '¿Borrar todos los códigos postales guardados? Se pueden volver a importar.',
		]);
	}

	public static function notices() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}
		if (get_transient('mje_activated')) {
			delete_transient('mje_activated');
			echo '<div class="notice notice-success is-dismissible"><p><strong>MJ Envíos activado.</strong> Todavía no cambió nada en la tienda: los métodos de ejemplo están apagados. Cargá desde dónde despachás y encendé los que quieras usar en <a href="' . esc_url(self::page_url()) . '">WooCommerce → Envíos</a>.</p></div>';
		}
		$screen = get_current_screen();
		if (!$screen || 'woocommerce_page_' . self::SLUG !== $screen->id) {
			return;
		}
		if (!MJE_Settings::origin_ready()) {
			echo '<div class="notice notice-warning"><p><strong>Falta el origen del envío.</strong> Sin el código postal de dónde despachás, ningún correo puede cotizar. Cargalo en la pestaña Ajustes.</p></div>';
		}
		$saved = isset($_GET['saved']) ? sanitize_key(wp_unslash($_GET['saved'])) : '';
		if ('' !== $saved) {
			$messages = [
				'method'   => 'Método guardado.',
				'deleted'  => 'Método borrado.',
				'copied'   => 'Método duplicado. Está apagado hasta que lo revises.',
				'toggled'  => 'Listo.',
				'fields'   => 'Datos del cliente guardados.',
				'settings' => 'Ajustes guardados.',
				'carrier'  => 'Correo guardado.',
				'branches' => 'Sucursales actualizadas.',
				'branch'   => 'Sucursal guardada.',
				'geo'      => 'Códigos postales actualizados.',
				'quotes'   => 'Caché de cotizaciones vaciada.',
			];
			if (isset($messages[$saved])) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$saved]) . '</p></div>';
			}
		}
		$error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
		if ('' !== $error) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
		}
	}

	/* -------------------------------- Marco ------------------------------ */

	private static function tabs() {
		return [
			'metodos' => 'Métodos',
			'datos'   => 'Datos del cliente',
			'cp'      => 'Códigos postales',
			'correos' => 'Correos',
			'ajustes' => 'Ajustes',
		];
	}

	public static function render() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Sin permisos.');
		}
		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'metodos';
		if (!array_key_exists($tab, self::tabs())) {
			$tab = 'metodos';
		}

		echo '<div class="wrap mje-wrap"><h1>MJ Envíos</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach (self::tabs() as $slug => $label) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url(self::page_url(['tab' => $slug])),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html($label)
			);
		}
		echo '</nav>';

		switch ($tab) {
			case 'datos':
				self::screen_fields();
				break;
			case 'cp':
				self::screen_geo();
				break;
			case 'correos':
				self::screen_carriers();
				break;
			case 'ajustes':
				self::screen_settings();
				break;
			default:
				$edit = isset($_GET['metodo']) ? sanitize_key(wp_unslash($_GET['metodo'])) : '';
				if ('' !== $edit || isset($_GET['nuevo'])) {
					self::screen_method($edit);
				} else {
					self::screen_methods();
				}
		}
		echo '</div>';
	}

	/* ---------------------------- Campos base ---------------------------- */

	/**
	 * Una fila del formulario.
	 *
	 * $when marca la fila para que solo se vea con ciertos tipos de método
	 * (lo hace admin.js: "Precio fijo" no tiene sentido en un método que cotiza
	 * con un correo).
	 */
	private static function row($label, $html, $help = '', $when = '') {
		echo '<tr' . ('' !== $when ? ' data-when="' . esc_attr($when) . '"' : '') . '><th scope="row">' . esc_html($label) . '</th><td>' . $html;
		if ('' !== $help) {
			echo '<p class="description">' . wp_kses_post($help) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function text_input($name, $value, $attrs = []) {
		$attrs = wp_parse_args($attrs, ['type' => 'text', 'class' => 'regular-text', 'placeholder' => '']);
		$out   = '<input';
		foreach ($attrs as $key => $attr_value) {
			if ('' === $attr_value && in_array($key, ['placeholder'], true)) {
				continue;
			}
			$out .= sprintf(' %s="%s"', esc_attr($key), esc_attr($attr_value));
		}
		return $out . sprintf(' name="%s" value="%s">', esc_attr($name), esc_attr($value));
	}

	private static function textarea($name, $value, $rows = 3) {
		return sprintf('<textarea name="%s" rows="%d" class="large-text">%s</textarea>', esc_attr($name), (int) $rows, esc_textarea($value));
	}

	private static function select_input($name, array $options, $current, $attrs = '') {
		$out = sprintf('<select name="%s" %s>', esc_attr($name), $attrs);
		foreach ($options as $value => $label) {
			$out .= sprintf('<option value="%s"%s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
		}
		return $out . '</select>';
	}

	private static function checkbox_input($name, $checked, $label) {
		return sprintf(
			'<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
			esc_attr($name),
			checked((bool) $checked, true, false),
			esc_html($label)
		);
	}

	/* ----------------------------- Métodos ------------------------------- */

	private static function screen_methods() {
		$methods = MJE_Methods::all();
		echo '<p class="mje-intro">Cada método es una forma de recibir la compra. El orden es el que ve el cliente en el checkout.</p>';
		echo '<p><a href="' . esc_url(self::page_url(['tab' => 'metodos', 'nuevo' => 1])) . '" class="button button-primary">Crear un método</a></p>';

		if (!$methods) {
			echo '<div class="mje-empty"><p>Todavía no hay ningún método de envío. Creá el primero: puede ser tan simple como "Retiro en el local".</p></div>';
			return;
		}

		echo '<table class="widefat striped mje-methods"><thead><tr>';
		echo '<th>Método</th><th>Cómo cobra</th><th>Entrega</th><th>Condiciones</th><th>Estado</th><th></th>';
		echo '</tr></thead><tbody>';

		foreach ($methods as $slug => $def) {
			$types  = MJE_Methods::types();
			$dests  = MJE_Methods::destinations();
			$price  = '';
			if ('fijo' === $def['type'] || 'retiro' === $def['type']) {
				$price = (float) $def['price'] > 0 ? MJE_Settings::money($def['price']) : 'Sin costo';
			} elseif ('tabla' === $def['type']) {
				$price = sprintf('%d fila%s de precios', count((array) $def['table']), 1 === count((array) $def['table']) ? '' : 's');
			} elseif ('correo' === $def['type']) {
				$carrier = MJE_Carriers::get((string) $def['carrier']);
				$price   = $carrier ? $carrier->label() . ($carrier->connected() ? '' : ' (sin conectar)') : 'Correo sin elegir';
			}

			echo '<tr>';
			printf(
				'<td><strong><a href="%s">%s</a></strong>%s</td>',
				esc_url(self::page_url(['tab' => 'metodos', 'metodo' => $slug])),
				esc_html(MJE_Methods::name($def)),
				'' !== (string) $def['description'] ? '<br><span class="description">' . esc_html($def['description']) . '</span>' : ''
			);
			printf('<td>%s<br><span class="description">%s</span></td>', esc_html(isset($types[$def['type']]) ? $types[$def['type']] : $def['type']), esc_html($price));
			printf('<td>%s</td>', esc_html(isset($dests[$def['destination']]) ? $dests[$def['destination']] : $def['destination']));
			printf('<td>%s</td>', esc_html(self::conditions_summary($def)));
			printf(
				'<td>%s</td>',
				$def['enabled'] ? '<span class="mje-on">Activo</span>' : '<span class="mje-off">Apagado</span>'
			);
			echo '<td class="mje-actions">';
			printf('<a href="%s">Editar</a> · ', esc_url(self::page_url(['tab' => 'metodos', 'metodo' => $slug])));
			printf(
				'<a href="%s">%s</a> · ',
				esc_url(wp_nonce_url(admin_url('admin-post.php?action=mje_toggle_method&metodo=' . $slug), 'mje_toggle_' . $slug)),
				$def['enabled'] ? 'Apagar' : 'Encender'
			);
			printf('<a href="%s">Duplicar</a> · ', esc_url(wp_nonce_url(admin_url('admin-post.php?action=mje_duplicate_method&metodo=' . $slug), 'mje_duplicate_' . $slug)));
			printf(
				'<a href="%s" class="mje-delete">Borrar</a>',
				esc_url(wp_nonce_url(admin_url('admin-post.php?action=mje_delete_method&metodo=' . $slug), 'mje_delete_' . $slug))
			);
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<p class="description">El orden se cambia con el número de orden de cada método, dentro de su ficha.</p>';
	}

	/** Resumen en una línea de las condiciones de un método. */
	private static function conditions_summary(array $def) {
		$parts = [];
		if ('' !== (string) $def['states']) {
			$parts[] = 'provincias: ' . $def['states'];
		}
		if ('' !== (string) $def['cp_include']) {
			$parts[] = 'CP: ' . $def['cp_include'];
		}
		if ('' !== (string) $def['cp_exclude']) {
			$parts[] = 'menos CP: ' . $def['cp_exclude'];
		}
		if ((float) $def['min_total'] > 0) {
			$parts[] = 'desde ' . MJE_Settings::money($def['min_total']);
		}
		if ((float) $def['max_total'] > 0) {
			$parts[] = 'hasta ' . MJE_Settings::money($def['max_total']);
		}
		if ((float) $def['max_weight'] > 0) {
			$parts[] = 'hasta ' . $def['max_weight'] . ' de peso';
		}
		if ('todos' !== (string) $def['payments_mode'] && '' !== (string) $def['payments']) {
			$parts[] = ('solo' === $def['payments_mode'] ? 'solo con ' : 'nunca con ') . $def['payments'];
		}
		if ('todos' !== (string) $def['users']) {
			$choices = MJE_Methods::user_choices();
			$parts[] = strtolower(isset($choices[$def['users']]) ? $choices[$def['users']] : $def['users']);
		}
		return $parts ? implode(' · ', $parts) : 'Sin condiciones: aparece siempre';
	}

	/** Ficha de un método (nuevo o existente). */
	private static function screen_method($slug) {
		$def      = '' !== $slug ? MJE_Methods::get($slug) : null;
		$def      = $def ? $def : MJE_Methods::defaults();
		$is_new   = '' === $slug;
		$carriers = MJE_Carriers::choices();

		echo '<h2>' . ($is_new ? 'Nuevo método de envío' : esc_html(MJE_Methods::name($def))) . '</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mje-form">';
		wp_nonce_field('mje_save_method');
		echo '<input type="hidden" name="action" value="mje_save_method">';
		echo '<input type="hidden" name="slug" value="' . esc_attr($slug) . '">';

		/* --- Lo básico --- */
		echo '<h3>Lo básico</h3><table class="form-table">';
		self::row('Nombre', self::text_input('def[title]', $def['title'], ['placeholder' => 'Envío a domicilio']), 'Es lo que lee el cliente en el checkout.');
		self::row('Nombre corto', self::text_input('def[short]', $def['short'], ['class' => 'regular-text', 'placeholder' => 'Domicilio']), 'Para el resumen del pedido. Vacío = el nombre completo.');
		self::row('Descripción', self::textarea('def[description]', $def['description'], 2), 'Una línea abajo del nombre.');
		self::row('Emoji', self::text_input('def[icon]', $def['icon'], ['class' => 'small-text', 'placeholder' => '🚚']));
		self::row('Estado', self::checkbox_input('def[enabled]', $def['enabled'], 'Se ofrece en la tienda'));
		self::row('Orden', self::text_input('def[order]', $def['order'], ['type' => 'number', 'class' => 'small-text']), 'Más chico, más arriba.');
		echo '</table>';

		/* --- Precio --- */
		echo '<h3>Cómo se cobra</h3><table class="form-table">';
		self::row('Tipo', self::select_input('def[type]', MJE_Methods::types(), $def['type'], 'data-mje-type="1"'));
		self::row('A dónde entrega', self::select_input('def[destination]', MJE_Methods::destinations(), $def['destination']), 'Define qué datos se le piden al cliente: una entrega a domicilio pide calle y número, una a sucursal pide la sucursal, un retiro no pide dirección.');
		self::row('Precio', self::text_input('def[price]', $def['price'], ['class' => 'small-text']), 'Lo que se cobra por este envío. 0 = sin costo.', 'fijo retiro');
		echo '</table>';

		/* Correo */
		echo '<div class="mje-when" data-when="correo"><table class="form-table">';
		self::row(
			'Correo',
			self::select_input('def[carrier]', ['' => '— Elegir —'] + $carriers, $def['carrier']),
			$carriers ? 'Sus credenciales se cargan en la pestaña Correos.' : 'Todavía no hay correos disponibles.'
		);
		$services = [];
		foreach ($carriers as $carrier_id => $label) {
			foreach (MJE_Carriers::services($carrier_id) as $key => $name) {
				$services[$key] = $name;
			}
		}
		$service_codes = array_map(function ($key) {
			return '<code>' . esc_html($key) . '</code>';
		}, array_keys($services));
		self::row(
			'Servicio',
			self::text_input('def[service]', $def['service'], ['class' => 'regular-text', 'placeholder' => 'domicilio']),
			$service_codes
				? 'Servicios disponibles: ' . implode(', ', $service_codes) . '. Vacío = el que corresponda al destino.'
				: 'Vacío = el que corresponda al destino.'
		);
		echo '</table></div>';

		/* Tabla de precios */
		echo '<div class="mje-when" data-when="tabla">';
		echo '<h4>Tabla de precios</h4>';
		echo '<p class="description">Se usa la primera fila que coincida con el destino, de la más específica a la más general. Si el paquete pasa el tope de peso, se cobra el precio más el valor por kilo extra.</p>';
		echo '<table class="widefat mje-table" id="mje-price-table"><thead><tr><th>Aplica a</th><th>Destinos</th><th>Hasta (peso)</th><th>Precio</th><th>Por kilo extra</th><th></th></tr></thead><tbody>';
		$rows = (array) $def['table'];
		$rows[] = ['scope' => 'todos', 'match' => '', 'weight_max' => '', 'price' => '', 'per_kg' => ''];
		foreach ($rows as $index => $row) {
			$row = wp_parse_args(is_array($row) ? $row : [], ['scope' => 'todos', 'match' => '', 'weight_max' => '', 'price' => '', 'per_kg' => '']);
			echo '<tr>';
			echo '<td>' . self::select_input(sprintf('def[table][%d][scope]', $index), MJE_Methods::scopes(), $row['scope']) . '</td>';
			echo '<td>' . self::text_input(sprintf('def[table][%d][match]', $index), $row['match'], ['class' => 'large-text', 'placeholder' => '1000-1999, 5000 · Córdoba · Villa Nueva']) . '</td>';
			echo '<td>' . self::text_input(sprintf('def[table][%d][weight_max]', $index), $row['weight_max'], ['class' => 'small-text', 'placeholder' => '0']) . '</td>';
			echo '<td>' . self::text_input(sprintf('def[table][%d][price]', $index), $row['price'], ['class' => 'small-text', 'placeholder' => '0']) . '</td>';
			echo '<td>' . self::text_input(sprintf('def[table][%d][per_kg]', $index), $row['per_kg'], ['class' => 'small-text', 'placeholder' => '0']) . '</td>';
			echo '<td><button type="button" class="button-link mje-row-remove" title="Quitar la fila">✕</button></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="mje-add-row">Agregar fila</button></p>';
		echo '</div>';

		/* Ajustes de precio */
		echo '<h4>Ajustes del precio</h4>';
		echo '<p class="description">Vacío = lo que diga la pestaña Ajustes. Es lo que deja subir el margen general sin tocar cada método.</p>';
		echo '<table class="form-table">';
		self::row('Margen (%)', self::text_input('def[markup_pct]', $def['markup_pct'] < 0 ? '' : $def['markup_pct'], ['class' => 'small-text', 'placeholder' => 'general']));
		self::row('Margen fijo', self::text_input('def[markup_fixed]', $def['markup_fixed'] < 0 ? '' : $def['markup_fixed'], ['class' => 'small-text', 'placeholder' => 'general']));
		self::row('Seguro (%)', self::text_input('def[insurance_pct]', $def['insurance_pct'] < 0 ? '' : $def['insurance_pct'], ['class' => 'small-text', 'placeholder' => 'general']), 'Sobre el valor declarado.');
		self::row('Precio mínimo', self::text_input('def[min_cost]', $def['min_cost'] < 0 ? '' : $def['min_cost'], ['class' => 'small-text', 'placeholder' => 'general']));
		self::row('Envío gratis', self::checkbox_input('def[free_allowed]', $def['free_allowed'], 'Este método puede quedar gratis'));
		self::row('Gratis desde', self::text_input('def[free_min]', $def['free_min'] < 0 ? '' : $def['free_min'], ['class' => 'regular-text', 'placeholder' => 'general']), 'Monto del carrito desde el que no se cobra. 0 = nunca por monto.');
		echo '</table>';

		/* --- Condiciones --- */
		echo '<h3>Cuándo aparece</h3>';
		echo '<p class="description">Todo lo que quede vacío o en 0 no filtra nada.</p>';
		echo '<table class="form-table">';
		self::row('Países', self::text_input('def[countries]', $def['countries'], ['placeholder' => 'AR']), 'Códigos separados por coma. Vacío = todos.');
		self::row('Solo estas provincias', self::text_input('def[states]', $def['states'], ['class' => 'large-text', 'placeholder' => 'Córdoba, Santa Fe']));
		self::row('Nunca estas provincias', self::text_input('def[states_exclude]', $def['states_exclude'], ['class' => 'large-text']));
		self::row('Solo estos CP', self::text_input('def[cp_include]', $def['cp_include'], ['class' => 'large-text', 'placeholder' => '1000-1999, 5000, 5900']), 'Códigos, rangos con guion o comodines con *.');
		self::row('Nunca estos CP', self::text_input('def[cp_exclude]', $def['cp_exclude'], ['class' => 'large-text']));
		self::row('Solo estas localidades', self::text_input('def[cities]', $def['cities'], ['class' => 'large-text']));
		self::row('Nunca estas localidades', self::text_input('def[cities_exclude]', $def['cities_exclude'], ['class' => 'large-text']));
		self::row('Monto del carrito', self::text_input('def[min_total]', $def['min_total'], ['class' => 'small-text', 'placeholder' => 'desde']) . ' ' . self::text_input('def[max_total]', $def['max_total'], ['class' => 'small-text', 'placeholder' => 'hasta']));
		self::row('Peso', self::text_input('def[min_weight]', $def['min_weight'], ['class' => 'small-text', 'placeholder' => 'desde']) . ' ' . self::text_input('def[max_weight]', $def['max_weight'], ['class' => 'small-text', 'placeholder' => 'hasta']));
		self::row('Cantidad de productos', self::text_input('def[min_qty]', $def['min_qty'], ['type' => 'number', 'class' => 'small-text', 'placeholder' => 'desde']) . ' ' . self::text_input('def[max_qty]', $def['max_qty'], ['type' => 'number', 'class' => 'small-text', 'placeholder' => 'hasta']));
		self::row('Solo con estos productos', self::text_input('def[products]', $def['products'], ['class' => 'large-text', 'placeholder' => 'IDs separados por coma']));
		self::row('Nunca con estos productos', self::text_input('def[products_exclude]', $def['products_exclude'], ['class' => 'large-text']));
		self::row('Solo con estas categorías', self::text_input('def[cats]', $def['cats'], ['class' => 'large-text', 'placeholder' => 'slugs separados por coma']));
		self::row('Nunca con estas categorías', self::text_input('def[cats_exclude]', $def['cats_exclude'], ['class' => 'large-text']));
		self::row('Quién lo ve', self::select_input('def[users]', MJE_Methods::user_choices(), $def['users']) . ' ' . self::text_input('def[roles]', $def['roles'], ['class' => 'regular-text', 'placeholder' => 'roles separados por coma']));
		echo '</table>';

		/* Medios de pago */
		echo '<h4>Medios de pago</h4>';
		echo '<p class="description">Filtra en los dos sentidos: el método no aparece si el cliente eligió un medio que no corresponde, y si eligió este método, en el checkout solo quedan los medios permitidos.</p>';
		echo '<table class="form-table">';
		self::row('Regla', self::select_input('def[payments_mode]', MJE_Methods::payment_modes(), $def['payments_mode']));
		self::row('Medios', self::payment_checklist($def), 'Los medios activos de la tienda, incluidos los propios de MJ Medios de Pago.');
		echo '</table>';

		/* --- Qué se promete --- */
		echo '<h3>Qué se le promete al cliente</h3><table class="form-table">';
		self::row('Plazo (días hábiles)', self::text_input('def[days_min]', $def['days_min'], ['type' => 'number', 'class' => 'small-text', 'placeholder' => 'de']) . ' ' . self::text_input('def[days_max]', $def['days_max'], ['type' => 'number', 'class' => 'small-text', 'placeholder' => 'a']));
		self::row('Texto del plazo', self::text_input('def[eta_text]', $def['eta_text'], ['class' => 'large-text', 'placeholder' => (string) MJE_Settings::get('eta_text')]), 'Marcas: <code>{min}</code> y <code>{max}</code>. Vacío = el texto general.');
		self::row('Instrucciones', self::textarea('def[instructions]', $def['instructions'], 3), 'Se muestra al terminar la compra y va en el email.');
		self::row('Link de seguimiento', self::text_input('def[tracking_url]', $def['tracking_url'], ['class' => 'large-text', 'placeholder' => 'https://...{codigo}']), 'Marca: <code>{codigo}</code>. Vacío = el del correo.');
		echo '</table>';

		/* --- Datos que pide --- */
		echo '<h3>Qué datos pide este método</h3>';
		echo '<p class="description">"Heredar" sigue lo que diga la pestaña Datos del cliente. El destino del método ya esconde lo que no tiene sentido: un retiro en el local no pide dirección aunque el general la pida.</p>';
		self::fields_table('def[fields]', $def, true);

		/* --- ERP --- */
		$erp = self::erp_delivery_choices();
		if ($erp) {
			echo '<h3>MJ ERP</h3><table class="form-table">';
			self::row('Forma de entrega equivalente', self::select_input('def[erp_delivery]', ['' => '— La configurada para la tienda —'] + $erp, $def['erp_delivery']), 'La venta que el ERP capture de este pedido va a quedar con esta forma de entrega. Así un retiro en el local no cuenta como envío pendiente.');
			echo '</table>';
		}

		submit_button($is_new ? 'Crear método' : 'Guardar cambios');
		echo ' <a href="' . esc_url(self::page_url(['tab' => 'metodos'])) . '" class="button-link">Volver a la lista</a>';
		echo '</form>';
	}

	/** Casillas con los medios de pago activos de la tienda. */
	private static function payment_checklist(array $def) {
		$selected = MJE_Settings::to_array($def['payments']);
		$gateways = [];
		if (function_exists('WC') && WC()->payment_gateways()) {
			foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
				if ($gateway instanceof WC_Payment_Gateway && 'yes' === $gateway->enabled) {
					$gateways[$gateway->id] = wp_strip_all_tags((string) $gateway->get_title());
				}
			}
		}
		if (!$gateways) {
			return '<p class="description">No hay medios de pago activos en la tienda.</p>';
		}
		$out = '<div class="mje-checklist">';
		foreach ($gateways as $id => $title) {
			$out .= sprintf(
				'<label><input type="checkbox" name="def[payments][]" value="%s"%s> %s <code>%s</code></label>',
				esc_attr($id),
				checked(in_array($id, $selected, true), true, false),
				esc_html($title),
				esc_html($id)
			);
		}
		return $out . '</div>';
	}

	/** Formas de entrega del ERP, si está instalado. */
	private static function erp_delivery_choices() {
		if (!class_exists('MJERP_Settings') || !is_callable(['MJERP_Settings', 'sale_delivery_methods'])) {
			return [];
		}
		$out = [];
		foreach ((array) MJERP_Settings::sale_delivery_methods() as $row) {
			if (is_array($row) && !empty($row['key'])) {
				$out[(string) $row['key']] = (string) (isset($row['label']) ? $row['label'] : $row['key']);
			}
		}
		return $out;
	}

	/**
	 * Tabla de configuración de los datos del cliente.
	 *
	 * La misma para el ajuste general y para cada método; lo único que cambia es
	 * si se ofrece "Heredar".
	 */
	private static function fields_table($name, $def, $allow_inherit) {
		$general = MJE_Fields::general();
		$per     = is_array($def) && isset($def['fields']) && is_array($def['fields']) ? $def['fields'] : [];

		echo '<table class="widefat striped mje-fields"><thead><tr>';
		echo '<th>Dato</th><th>Se pide</th><th>Cómo se llama</th><th>Ayuda</th><th>Orden</th>';
		echo '</tr></thead><tbody>';

		foreach (MJE_Fields::catalog() as $key => $meta) {
			$saved = $allow_inherit
				? (isset($per[$key]) ? wp_parse_args($per[$key], MJE_Fields::field_defaults()) : MJE_Fields::field_defaults())
				: (isset($general[$key]) ? $general[$key] : MJE_Fields::field_defaults());

			echo '<tr>';
			printf(
				'<td><strong>%s</strong>%s<br><code>%s</code></td>',
				esc_html($meta['label']),
				!empty($meta['custom']) ? ' <span class="description">(propio)</span>' : '',
				esc_html($key)
			);
			printf('<td>%s</td>', self::select_input(sprintf('%s[%s][mode]', $name, $key), MJE_Fields::modes($allow_inherit), $saved['mode']));
			printf(
				'<td>%s</td>',
				self::text_input(sprintf('%s[%s][label]', $name, $key), $saved['label'], ['class' => 'regular-text', 'placeholder' => $allow_inherit && isset($general[$key]['label']) && '' !== $general[$key]['label'] ? $general[$key]['label'] : $meta['label']])
			);
			printf(
				'<td>%s</td>',
				self::text_input(sprintf('%s[%s][help]', $name, $key), $saved['help'], ['class' => 'regular-text', 'placeholder' => isset($meta['help']) ? $meta['help'] : ''])
			);
			printf('<td>%s</td>', self::text_input(sprintf('%s[%s][order]', $name, $key), $saved['order'], ['type' => 'number', 'class' => 'small-text']));
			echo '</tr>';

			if ('select' === $meta['type']) {
				printf(
					'<tr class="mje-subrow"><td></td><td colspan="4"><label>Opciones (una por línea)<br>%s</label></td></tr>',
					self::textarea(sprintf('%s[%s][options]', $name, $key), $saved['options'], 2)
				);
			}
		}
		echo '</tbody></table>';
	}

	/* -------------------------- Datos del cliente ------------------------ */

	private static function screen_fields() {
		echo '<p class="mje-intro">Lo que se le pide al cliente para poder enviarle la compra. Esto es el ajuste general: cada método puede pedir más o menos desde su propia ficha.</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('mje_save_fields');
		echo '<input type="hidden" name="action" value="mje_save_fields">';

		self::fields_table('fields', [], false);

		echo '<h3>Campos propios</h3>';
		echo '<p class="description">Para lo que tu tienda necesite y no esté en la lista. Se guardan en el pedido y se ven en el admin y en el email.</p>';
		echo '<table class="widefat mje-table" id="mje-custom-fields"><thead><tr><th>Clave</th><th>Cómo se llama</th><th>Tipo</th><th>Opciones (una por línea)</th><th></th></tr></thead><tbody>';
		$custom   = MJE_Fields::custom();
		$custom[] = ['label' => '', 'type' => 'text', 'options' => ''];
		$index    = 0;
		foreach ($custom as $key => $field) {
			$field = wp_parse_args(is_array($field) ? $field : [], ['label' => '', 'type' => 'text', 'options' => '']);
			echo '<tr>';
			echo '<td>' . self::text_input(sprintf('custom[%d][key]', $index), is_string($key) ? $key : '', ['class' => 'regular-text', 'placeholder' => 'entre_calles']) . '</td>';
			echo '<td>' . self::text_input(sprintf('custom[%d][label]', $index), $field['label'], ['class' => 'regular-text', 'placeholder' => 'Entre calles']) . '</td>';
			echo '<td>' . self::select_input(sprintf('custom[%d][type]', $index), MJE_Fields::types(), $field['type']) . '</td>';
			echo '<td>' . self::textarea(sprintf('custom[%d][options]', $index), $field['options'], 2) . '</td>';
			echo '<td><button type="button" class="button-link mje-row-remove" title="Quitar">✕</button></td>';
			echo '</tr>';
			$index++;
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="mje-add-custom">Agregar campo propio</button></p>';
		echo '<p class="description">Para borrar un campo propio, vaciá su clave y guardá.</p>';

		submit_button('Guardar datos del cliente');
		echo '</form>';
	}

	/* --------------------------- Códigos postales ------------------------ */

	private static function screen_geo() {
		$count = MJE_Geo::count();
		echo '<p class="mje-intro">La tabla propia de códigos postales. Es la que hace que el checkout complete provincia y localidad al instante, sin depender de nadie.</p>';

		echo '<div class="mje-cards">';
		printf('<div class="mje-card"><strong>%s</strong><span>códigos postales con su localidad</span></div>', esc_html(number_format_i18n($count)));
		printf('<div class="mje-card"><strong>%s</strong><span>proveedores activos, en orden</span></div>', esc_html(implode(' → ', MJE_Geo::active_providers())));
		echo '</div>';

		echo '<h3>Importar un CSV</h3>';
		echo '<p class="description">Columnas: código postal, localidad y provincia. Se leen los encabezados, así que el orden no importa; si el archivo no los tiene, se asume ese orden. Importar dos veces no duplica: actualiza.</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
		wp_nonce_field('mje_import_geo');
		echo '<input type="hidden" name="action" value="mje_import_geo">';
		echo '<input type="file" name="csv" accept=".csv,text/csv" required> ';
		submit_button('Importar', 'secondary', 'submit', false);
		echo '</form>';

		echo '<h3>Agregar uno a mano</h3>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mje-inline">';
		wp_nonce_field('mje_add_geo');
		echo '<input type="hidden" name="action" value="mje_add_geo">';
		echo self::text_input('cp', '', ['class' => 'small-text', 'placeholder' => 'CP']) . ' ';
		echo self::text_input('localidad', '', ['class' => 'regular-text', 'placeholder' => 'Localidad']) . ' ';
		echo self::select_input('provincia', ['' => '— Provincia —'] + MJE_Geo::states(), '') . ' ';
		submit_button('Agregar', 'secondary', 'submit', false);
		echo '</form>';

		echo '<h3>Últimos guardados</h3>';
		self::geo_table();

		echo '<h3>Limpiar</h3>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mje-inline">';
		wp_nonce_field('mje_clear_geo');
		echo '<input type="hidden" name="action" value="mje_clear_geo">';
		echo '<button type="submit" class="button mje-clear">Borrar todos los códigos postales</button>';
		echo '</form>';
	}

	private static function geo_table() {
		if (!MJE_Install::tables_exist()) {
			echo '<p>Las tablas del plugin todavía no están creadas.</p>';
			return;
		}
		global $wpdb;
		$table = MJE_Install::table_geo();
		$rows  = $wpdb->get_results("SELECT postcode, city, state, source, hits FROM {$table} ORDER BY updated_at DESC LIMIT 25", ARRAY_A); // phpcs:ignore WordPress.DB
		if (!$rows) {
			echo '<p>Todavía no hay ninguno. Importá un CSV, o dejá que se llenen solos con lo que elijan los clientes.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>CP</th><th>Localidad</th><th>Provincia</th><th>De dónde salió</th><th>Usos</th></tr></thead><tbody>';
		foreach ($rows as $row) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html($row['postcode']),
				esc_html($row['city']),
				esc_html($row['state']),
				esc_html($row['source']),
				esc_html($row['hits'])
			);
		}
		echo '</tbody></table>';
	}

	/* ------------------------------- Correos ----------------------------- */

	private static function screen_carriers() {
		echo '<p class="mje-intro">Las credenciales de cada correo. Un correo sin credenciales queda apagado, y los métodos que lo usan cotizan con su tabla propia.</p>';

		foreach (MJE_Carriers::all() as $id => $carrier) {
			$connected = $carrier->connected();
			printf(
				'<h3>%s %s</h3>',
				esc_html($carrier->label()),
				$connected ? '<span class="mje-on">conectado</span>' : '<span class="mje-off">sin conectar</span>'
			);
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			wp_nonce_field('mje_save_carrier');
			echo '<input type="hidden" name="action" value="mje_save_carrier">';
			echo '<input type="hidden" name="carrier" value="' . esc_attr($id) . '">';
			echo '<table class="form-table">';
			foreach ($carrier->fields() as $key => $field) {
				$value = $carrier->config($key);
				$type  = isset($field['type']) ? $field['type'] : 'text';
				if ('select' === $type) {
					$html = self::select_input(sprintf('config[%s]', $key), isset($field['options']) ? $field['options'] : [], $value);
				} elseif ('checkbox' === $type) {
					$html = self::checkbox_input(sprintf('config[%s]', $key), $value, 'Sí');
				} else {
					$html = self::text_input(sprintf('config[%s]', $key), $value, [
						'type'        => 'password' === $type ? 'password' : 'text',
						'class'       => 'regular-text',
						'placeholder' => isset($field['default']) ? (string) $field['default'] : '',
					]);
				}
				$label = (string) $field['label'] . (!empty($field['required']) ? ' *' : '');
				self::row($label, $html, isset($field['help']) ? $field['help'] : '');
			}
			echo '</table>';
			submit_button('Guardar ' . $carrier->label(), 'secondary', 'submit', false);
			echo '</form>';

			/* Sucursales guardadas de este correo. */
			$branches = MJE_Carriers::stored_branches($id);
			printf('<p><strong>%s</strong> sucursales guardadas.', esc_html(number_format_i18n(count($branches))));
			if ($connected) {
				printf(
					' <a href="%s" class="button button-small">Traer las sucursales del correo</a>',
					esc_url(wp_nonce_url(admin_url('admin-post.php?action=mje_sync_branches&carrier=' . $id), 'mje_sync_' . $id))
				);
			}
			echo '</p>';

			if ($branches) {
				echo '<table class="widefat striped"><thead><tr><th>Código</th><th>Nombre</th><th>Dirección</th><th>Localidad</th><th>CP</th><th></th></tr></thead><tbody>';
				foreach (array_slice($branches, 0, 20) as $branch) {
					printf(
						'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" class="mje-delete">Borrar</a></td></tr>',
						esc_html($branch['code']),
						esc_html($branch['name']),
						esc_html($branch['address']),
						esc_html($branch['city']),
						esc_html($branch['postcode']),
						esc_url(wp_nonce_url(admin_url('admin-post.php?action=mje_delete_branch&carrier=' . $id . '&code=' . rawurlencode($branch['code'])), 'mje_branch_' . $id))
					);
				}
				echo '</tbody></table>';
				if (count($branches) > 20) {
					printf('<p class="description">Se muestran 20 de %s.</p>', esc_html(number_format_i18n(count($branches))));
				}
			}

			echo '<h4>Agregar una sucursal a mano</h4>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mje-inline">';
			wp_nonce_field('mje_save_branch');
			echo '<input type="hidden" name="action" value="mje_save_branch">';
			echo '<input type="hidden" name="carrier" value="' . esc_attr($id) . '">';
			echo self::text_input('branch[code]', '', ['class' => 'small-text', 'placeholder' => 'Código']) . ' ';
			echo self::text_input('branch[name]', '', ['class' => 'regular-text', 'placeholder' => 'Nombre']) . ' ';
			echo self::text_input('branch[address]', '', ['class' => 'regular-text', 'placeholder' => 'Dirección']) . ' ';
			echo self::text_input('branch[city]', '', ['class' => 'regular-text', 'placeholder' => 'Localidad']) . ' ';
			echo self::text_input('branch[postcode]', '', ['class' => 'small-text', 'placeholder' => 'CP']) . ' ';
			submit_button('Agregar', 'secondary', 'submit', false);
			echo '</form><hr>';
		}

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mje-inline">';
		wp_nonce_field('mje_clear_quotes');
		echo '<input type="hidden" name="action" value="mje_clear_quotes">';
		echo '<button type="submit" class="button">Vaciar la caché de cotizaciones</button>';
		echo '</form>';

		$log = get_option('mje_log', []);
		if (is_array($log) && $log) {
			echo '<h3>Últimos errores</h3><table class="widefat striped"><thead><tr><th>Cuándo</th><th>Dónde</th><th>Qué pasó</th></tr></thead><tbody>';
			foreach (array_reverse(array_slice($log, -15)) as $line) {
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html(isset($line['when']) ? $line['when'] : ''),
					esc_html(isset($line['scope']) ? $line['scope'] : ''),
					esc_html(isset($line['message']) ? $line['message'] : '')
				);
			}
			echo '</tbody></table>';
		}
	}

	/* ------------------------------- Ajustes ----------------------------- */

	private static function screen_settings() {
		$s = MJE_Settings::get();
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('mje_save_settings');
		echo '<input type="hidden" name="action" value="mje_save_settings">';

		echo '<h3>General</h3><table class="form-table">';
		self::row('Envíos', self::checkbox_input('settings[enabled]', $s['enabled'], 'MJ Envíos calcula los envíos de la tienda'));
		self::row('Zonas de WooCommerce', self::checkbox_input('settings[replace_native]', $s['replace_native'], 'Cuando hay métodos propios, no mostrar las tarifas de las zonas de WooCommerce'), 'Apagado, las dos listas se muestran juntas.');
		echo '</table>';

		echo '<h3>Desde dónde despachás</h3>';
		echo '<p class="description">Sin el código postal de origen, ningún correo puede cotizar.</p>';
		echo '<table class="form-table">';
		self::row('Nombre o razón social', self::text_input('settings[origin_name]', $s['origin_name']));
		self::row('Dirección', self::text_input('settings[origin_address]', $s['origin_address'], ['class' => 'large-text']));
		self::row('Piso, depto o referencia', self::text_input('settings[origin_extra]', $s['origin_extra']));
		self::row('Localidad', self::text_input('settings[origin_city]', $s['origin_city']));
		self::row('Provincia', self::select_input('settings[origin_state]', ['' => '— Elegir —'] + MJE_Geo::states(), $s['origin_state']));
		self::row('Código postal', self::text_input('settings[origin_postcode]', $s['origin_postcode'], ['class' => 'small-text']));
		self::row('País', self::text_input('settings[origin_country]', $s['origin_country'], ['class' => 'small-text', 'placeholder' => 'AR']), 'Vacío = el país base de la tienda.');
		self::row('Teléfono', self::text_input('settings[origin_phone]', $s['origin_phone']));
		self::row('Email', self::text_input('settings[origin_email]', $s['origin_email']));
		self::row('Sucursal de despacho', self::text_input('settings[origin_branch]', $s['origin_branch']), 'Si el correo la pide.');
		echo '</table>';

		echo '<h3>Peso y medidas</h3>';
		echo '<p class="description">Se usa el peso cargado en cada producto. Esto es para lo que no lo tenga: también se puede poner un peso por defecto por producto y por categoría.</p>';
		echo '<table class="form-table">';
		self::row('Peso por defecto', self::text_input('settings[weight_default]', $s['weight_default'], ['class' => 'small-text']), 'Por unidad, en ' . esc_html(get_option('woocommerce_weight_unit', 'kg')) . '.');
		self::row('Peso del embalaje', self::text_input('settings[weight_extra]', $s['weight_extra'], ['class' => 'small-text']), 'Se suma una vez por paquete.');
		self::row('Peso mínimo del paquete', self::text_input('settings[weight_min]', $s['weight_min'], ['class' => 'small-text']));
		self::row('Peso máximo por bulto', self::text_input('settings[weight_max]', $s['weight_max'], ['class' => 'small-text']), 'Arriba de esto se cotiza en varios bultos. 0 = sin tope.');
		self::row('Medidas del bulto (cm)', self::text_input('settings[dim_length]', $s['dim_length'], ['class' => 'small-text', 'placeholder' => 'largo']) . ' ' . self::text_input('settings[dim_width]', $s['dim_width'], ['class' => 'small-text', 'placeholder' => 'ancho']) . ' ' . self::text_input('settings[dim_height]', $s['dim_height'], ['class' => 'small-text', 'placeholder' => 'alto']), 'Para los productos sin medidas cargadas.');
		self::row('Con varios productos', self::select_input('settings[dim_stack]', ['alto' => 'Se apilan: suma el alto', 'mayor' => 'Se toma la medida más grande'], $s['dim_stack']));
		self::row('Divisor de peso volumétrico', self::text_input('settings[volumetric]', $s['volumetric'], ['class' => 'small-text']), 'Si el correo cobra por volumen: largo × ancho × alto ÷ este número. 0 = no se usa.');
		echo '</table>';

		echo '<h3>Precio</h3><table class="form-table">';
		self::row('Margen (%)', self::text_input('settings[markup_pct]', $s['markup_pct'], ['class' => 'small-text']), 'Se suma al costo del correo: manipuleo, embalaje.');
		self::row('Margen fijo', self::text_input('settings[markup_fixed]', $s['markup_fixed'], ['class' => 'small-text']));
		self::row('Seguro (%)', self::text_input('settings[insurance_pct]', $s['insurance_pct'], ['class' => 'small-text']), 'Sobre el valor declarado.');
		self::row('Valor declarado', self::select_input('settings[declared_value]', MJE_Settings::declared_choices(), $s['declared_value']) . ' ' . self::text_input('settings[declared_pct]', $s['declared_pct'], ['class' => 'small-text']));
		self::row('Precio mínimo', self::text_input('settings[min_cost]', $s['min_cost'], ['class' => 'small-text']));
		self::row('Redondeo', self::select_input('settings[rounding]', MJE_Settings::rounding_choices(), $s['rounding']) . ' ' . self::select_input('settings[rounding_mode]', ['nearest' => 'Al más cercano', 'up' => 'Siempre para arriba'], $s['rounding_mode']));
		echo '</table>';

		echo '<h3>Envío gratis</h3><table class="form-table">';
		self::row('Envío gratis por monto', self::checkbox_input('settings[free_enabled]', $s['free_enabled'], 'Activado'));
		self::row('Desde', self::text_input('settings[free_min]', $s['free_min'], ['class' => 'regular-text']), 'Subtotal del carrito. Es el número que anuncia la barra de la tienda.');
		self::row('Solo estos métodos', self::text_input('settings[free_methods]', $s['free_methods'], ['class' => 'large-text', 'placeholder' => 'slugs separados por coma']), 'Vacío = todos los que lo permitan.');
		self::row('Solo estas provincias', self::text_input('settings[free_states]', $s['free_states'], ['class' => 'large-text']));
		self::row('Cómo se llama', self::text_input('settings[free_label]', $s['free_label'], ['class' => 'small-text']));
		echo '</table>';

		echo '<h3>Cotización</h3><table class="form-table">';
		self::row('Guardar las cotizaciones', self::text_input('settings[cache_hours]', $s['cache_hours'], ['type' => 'number', 'class' => 'small-text']) . ' horas', 'El mismo destino y peso no se pregunta dos veces. 0 = siempre preguntar.');
		self::row('Espera máxima', self::text_input('settings[timeout]', $s['timeout'], ['type' => 'number', 'class' => 'small-text']) . ' segundos');
		self::row('Si el correo no responde', self::select_input('settings[on_error]', MJE_Settings::error_choices(), $s['on_error']));
		self::row('Registrar los errores', self::checkbox_input('settings[log_errors]', $s['log_errors'], 'Guardar las últimas fallas para poder mirarlas'));
		echo '</table>';

		echo '<h3>Código postal, provincia y localidad</h3><table class="form-table">';
		$providers = '<div class="mje-checklist">';
		$active    = MJE_Geo::active_providers();
		foreach (MJE_Geo::providers() as $slug => $label) {
			$providers .= sprintf(
				'<label><input type="checkbox" name="settings[geo_providers][]" value="%s"%s> %s</label>',
				esc_attr($slug),
				checked(in_array($slug, $active, true), true, false),
				esc_html($label)
			);
		}
		$providers .= '</div>';
		self::row('Proveedores', $providers, 'Se preguntan en este orden: ' . esc_html(implode(' → ', $active)) . '. Para cambiar el orden, escribilos abajo.');
		self::row('Orden exacto', self::text_input('settings[geo_providers_order]', implode(',', $active), ['class' => 'regular-text']), 'Opcional. Separados por coma; manda sobre las casillas.');
		self::row('URL de Georef', self::text_input('settings[geo_url]', $s['geo_url'], ['class' => 'large-text']), 'La API pública del Estado. Vacío = no se consulta.');
		self::row('Guardar las consultas', self::text_input('settings[geo_cache_days]', $s['geo_cache_days'], ['type' => 'number', 'class' => 'small-text']) . ' días');
		self::row('Largo del código postal', self::text_input('settings[cp_min_len]', $s['cp_min_len'], ['type' => 'number', 'class' => 'small-text', 'placeholder' => 'min']) . ' a ' . self::text_input('settings[cp_max_len]', $s['cp_max_len'], ['type' => 'number', 'class' => 'small-text', 'placeholder' => 'max']));
		self::row('Exigencias', self::checkbox_input('settings[cp_required]', $s['cp_required'], 'El código postal es obligatorio') . '<br>' . self::checkbox_input('settings[cp_strict]', $s['cp_strict'], 'Además tiene que resolver a una localidad conocida'));
		self::row('Aprender', self::checkbox_input('settings[geo_learn]', $s['geo_learn'], 'Guardar lo que elija el cliente para la próxima vez'));
		echo '</table>';

		echo '<h3>Textos de la tienda</h3><table class="form-table">';
		self::row('Título del paso', self::text_input('settings[step_title]', $s['step_title']));
		self::row('Bajada del paso', self::text_input('settings[step_intro]', $s['step_intro'], ['class' => 'large-text']));
		self::row('Código postal', self::text_input('settings[cp_label]', $s['cp_label']));
		self::row('Ayuda del código postal', self::text_input('settings[cp_help]', $s['cp_help'], ['class' => 'large-text']));
		self::row('Antes de cargar el CP', self::text_input('settings[cp_ask]', $s['cp_ask'], ['class' => 'large-text']));
		self::row('CP que no reconocemos', self::text_input('settings[cp_invalid]', $s['cp_invalid'], ['class' => 'large-text']));
		self::row('Sin envíos para el destino', self::textarea('settings[none_text]', $s['none_text'], 2));
		self::row('Envío a coordinar', self::textarea('settings[coordinate_text]', $s['coordinate_text'], 2));
		self::row('No se pudo cotizar', self::textarea('settings[error_text]', $s['error_text'], 2));
		self::row('Plazo (de X a Y)', self::text_input('settings[eta_text]', $s['eta_text'], ['class' => 'large-text']), 'Marcas: <code>{min}</code> <code>{max}</code>.');
		self::row('Plazo (un solo número)', self::text_input('settings[eta_text_one]', $s['eta_text_one'], ['class' => 'large-text']));
		self::row('Sucursal', self::text_input('settings[branch_label]', $s['branch_label']));
		self::row('Ayuda de la sucursal', self::text_input('settings[branch_help]', $s['branch_help'], ['class' => 'large-text']));
		self::row('Retiro en el local', self::text_input('settings[pickup_label]', $s['pickup_label']));
		echo '</table>';

		echo '<h3>Seguimiento</h3><table class="form-table">';
		self::row('En Mi cuenta', self::checkbox_input('settings[track_account]', $s['track_account'], 'El cliente ve el seguimiento en su pedido'));
		self::row('Avisar por email', self::checkbox_input('settings[track_mail]', $s['track_mail'], 'Mandar un email cuando se carga el número de seguimiento'));
		self::row('Asunto', self::text_input('settings[track_subject]', $s['track_subject'], ['class' => 'large-text']));
		self::row('Cuerpo', self::textarea('settings[track_body]', $s['track_body'], 6), 'Marcas: <code>{cliente}</code> <code>{numero}</code> <code>{codigo}</code> <code>{link}</code> <code>{metodo}</code> <code>{tienda}</code>.');
		self::row('Mensaje de WhatsApp', self::textarea('settings[track_whatsapp]', $s['track_whatsapp'], 3), 'Mismas marcas. Se usa con el botón del pedido.');
		echo '</table>';

		echo '<h3>Listo para retirar</h3><table class="form-table">';
		self::row('Estado propio', self::checkbox_input('settings[ready_enabled]', $s['ready_enabled'], 'Sumar el estado de pedido «Listo para retirar»'), 'Para lo que se retira en el local o en un punto a coordinar: lo elegís en el pedido o en lote desde la lista. Cuenta como pagado. El cliente lo ve en Mi cuenta con este nombre.');
		self::row('Nombre del estado', self::text_input('settings[ready_label]', $s['ready_label'], ['class' => 'regular-text']));
		self::row('Avisar por email', self::checkbox_input('settings[ready_mail]', $s['ready_mail'], 'Mandar un email cuando el pedido pasa a ese estado'));
		self::row('Asunto', self::text_input('settings[ready_subject]', $s['ready_subject'], ['class' => 'large-text']));
		self::row('Cuerpo', self::textarea('settings[ready_body]', $s['ready_body'], 6), 'Marcas: <code>{cliente}</code> <code>{numero}</code> <code>{metodo}</code> <code>{instrucciones}</code> (las del método de envío del pedido) <code>{tienda}</code>.');
		self::row('Mensaje de WhatsApp', self::textarea('settings[ready_whatsapp]', $s['ready_whatsapp'], 3), 'Mismas marcas.');
		echo '</table>';

		echo '<h3>MJ ERP</h3><table class="form-table">';
		self::row('Costo del correo', self::checkbox_input('settings[erp_cost]', $s['erp_cost'], 'Guardar en el pedido lo que cobró el correo, además de lo que pagó el cliente'), 'Es la diferencia entre lo que cobrás por el envío y lo que te cuesta.');
		echo '</table>';

		submit_button('Guardar ajustes');
		echo '</form>';
	}

	/* ------------------------------ Guardado ----------------------------- */

	private static function guard($action) {
		check_admin_referer($action);
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Sin permisos.');
		}
	}

	private static function back($args) {
		wp_safe_redirect(self::page_url($args));
		exit;
	}

	public static function save_method() {
		self::guard('mje_save_method');
		$slug = isset($_POST['slug']) ? sanitize_key(wp_unslash($_POST['slug'])) : '';
		$def  = isset($_POST['def']) && is_array($_POST['def']) ? wp_unslash($_POST['def']) : [];
		$saved = MJE_Methods::save($slug, $def);
		self::back(['tab' => 'metodos', 'metodo' => $saved, 'saved' => 'method']);
	}

	public static function delete_method() {
		$slug = isset($_GET['metodo']) ? sanitize_key(wp_unslash($_GET['metodo'])) : '';
		self::guard('mje_delete_' . $slug);
		MJE_Methods::delete($slug);
		self::back(['tab' => 'metodos', 'saved' => 'deleted']);
	}

	public static function duplicate_method() {
		$slug = isset($_GET['metodo']) ? sanitize_key(wp_unslash($_GET['metodo'])) : '';
		self::guard('mje_duplicate_' . $slug);
		$copy = MJE_Methods::duplicate($slug);
		self::back(['tab' => 'metodos', 'metodo' => $copy, 'saved' => 'copied']);
	}

	public static function toggle_method() {
		$slug = isset($_GET['metodo']) ? sanitize_key(wp_unslash($_GET['metodo'])) : '';
		self::guard('mje_toggle_' . $slug);
		$def = MJE_Methods::get($slug);
		if ($def) {
			$def['enabled'] = empty($def['enabled']) ? 1 : 0;
			MJE_Methods::save($slug, $def);
		}
		self::back(['tab' => 'metodos', 'saved' => 'toggled']);
	}

	public static function save_fields() {
		self::guard('mje_save_fields');
		$fields = isset($_POST['fields']) && is_array($_POST['fields']) ? wp_unslash($_POST['fields']) : [];
		$custom = isset($_POST['custom']) && is_array($_POST['custom']) ? wp_unslash($_POST['custom']) : [];
		/* Los campos propios primero: el general se guarda contra el catálogo ya completo. */
		MJE_Fields::update_custom($custom);
		MJE_Fields::update_general($fields);
		self::back(['tab' => 'datos', 'saved' => 'fields']);
	}

	public static function save_settings() {
		self::guard('mje_save_settings');
		$in = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];

		/* Proveedores de CP: el orden escrito manda sobre las casillas. */
		$order = isset($in['geo_providers_order']) ? (string) $in['geo_providers_order'] : '';
		if ('' !== trim($order)) {
			$in['geo_providers'] = $order;
		} elseif (isset($in['geo_providers']) && is_array($in['geo_providers'])) {
			$in['geo_providers'] = implode(',', $in['geo_providers']);
		} else {
			$in['geo_providers'] = '';
		}
		unset($in['geo_providers_order']);

		MJE_Settings::update($in);
		MJE_Settings::flush_caches();
		self::back(['tab' => 'ajustes', 'saved' => 'settings']);
	}

	public static function save_carrier() {
		self::guard('mje_save_carrier');
		$id      = isset($_POST['carrier']) ? sanitize_key(wp_unslash($_POST['carrier'])) : '';
		$carrier = MJE_Carriers::get($id);
		if (!$carrier) {
			self::back(['tab' => 'correos', 'error' => 'Ese correo no existe.']);
		}
		$carrier->update_config(isset($_POST['config']) && is_array($_POST['config']) ? wp_unslash($_POST['config']) : []);
		/* Credenciales nuevas: el token guardado ya no sirve. */
		delete_transient('mje_token_' . $id);
		self::back(['tab' => 'correos', 'saved' => 'carrier']);
	}

	public static function sync_branches() {
		$id = isset($_GET['carrier']) ? sanitize_key(wp_unslash($_GET['carrier'])) : '';
		self::guard('mje_sync_' . $id);
		$before = count(MJE_Carriers::stored_branches($id));
		MJE_Carriers::branches($id, '', true);
		$after  = count(MJE_Carriers::stored_branches($id));
		if ($after === $before && 0 === $after) {
			self::back(['tab' => 'correos', 'error' => 'El correo no devolvió ninguna sucursal. Revisá las credenciales o cargalas a mano.']);
		}
		self::back(['tab' => 'correos', 'saved' => 'branches']);
	}

	public static function save_branch() {
		self::guard('mje_save_branch');
		$id     = isset($_POST['carrier']) ? sanitize_key(wp_unslash($_POST['carrier'])) : '';
		$branch = isset($_POST['branch']) && is_array($_POST['branch']) ? wp_unslash($_POST['branch']) : [];
		MJE_Carriers::save_branch($id, $branch, 'manual');
		self::back(['tab' => 'correos', 'saved' => 'branch']);
	}

	public static function delete_branch() {
		$id = isset($_GET['carrier']) ? sanitize_key(wp_unslash($_GET['carrier'])) : '';
		self::guard('mje_branch_' . $id);
		MJE_Carriers::delete_branch($id, isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '');
		self::back(['tab' => 'correos', 'saved' => 'branch']);
	}

	public static function import_geo() {
		self::guard('mje_import_geo');
		if (!isset($_FILES['csv']) || !is_array($_FILES['csv']) || empty($_FILES['csv']['tmp_name'])) {
			self::back(['tab' => 'cp', 'error' => 'No llegó ningún archivo.']);
		}
		$file = $_FILES['csv']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if (!empty($file['error'])) {
			self::back(['tab' => 'cp', 'error' => 'El archivo no se pudo subir (error ' . (int) $file['error'] . ').']);
		}
		$result = MJE_Geo::import_csv((string) $file['tmp_name']);
		self::back([
			'tab'   => 'cp',
			'saved' => 'geo',
			'error' => $result['errores'] > 0 ? sprintf('%d filas del archivo no se pudieron leer. Se importaron %d.', $result['errores'], $result['filas']) : '',
		]);
	}

	public static function add_geo() {
		self::guard('mje_add_geo');
		$cp    = isset($_POST['cp']) ? sanitize_text_field(wp_unslash($_POST['cp'])) : '';
		$city  = isset($_POST['localidad']) ? sanitize_text_field(wp_unslash($_POST['localidad'])) : '';
		$state = isset($_POST['provincia']) ? sanitize_text_field(wp_unslash($_POST['provincia'])) : '';
		if ('' === $cp || '' === $city) {
			self::back(['tab' => 'cp', 'error' => 'Hacen falta el código postal y la localidad.']);
		}
		MJE_Geo::remember($cp, $city, $state, 'manual');
		self::back(['tab' => 'cp', 'saved' => 'geo']);
	}

	public static function clear_geo() {
		self::guard('mje_clear_geo');
		MJE_Geo::truncate();
		self::back(['tab' => 'cp', 'saved' => 'geo']);
	}

	public static function clear_quotes() {
		self::guard('mje_clear_quotes');
		MJE_Carriers::flush_quotes();
		self::back(['tab' => 'correos', 'saved' => 'quotes']);
	}

	/* ----------------------- Peso por producto y rubro -------------------- */

	public static function product_field() {
		global $post;
		$value = $post ? get_post_meta($post->ID, '_mje_weight', true) : '';
		woocommerce_wp_text_input([
			'id'          => '_mje_weight',
			'label'       => 'Peso por defecto (MJ Envíos)',
			'desc_tip'    => true,
			'description' => 'Solo se usa si el producto no tiene peso cargado arriba. Vacío = el peso por defecto general.',
			'value'       => $value,
			'data_type'   => 'decimal',
		]);
	}

	public static function save_product_field($product) {
		if (!$product instanceof WC_Product) {
			return;
		}
		$raw = isset($_POST['_mje_weight']) ? sanitize_text_field(wp_unslash($_POST['_mje_weight'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ('' === trim($raw)) {
			$product->delete_meta_data('_mje_weight');
			return;
		}
		$product->update_meta_data('_mje_weight', (string) max(0, MJE_Settings::decimal($raw)));
	}

	public static function category_add_field() {
		echo '<div class="form-field"><label for="mje_weight">Peso por defecto (MJ Envíos)</label>';
		echo '<input type="text" name="mje_weight" id="mje_weight" value="">';
		echo '<p>Para los productos de esta categoría que no tengan peso cargado.</p></div>';
	}

	public static function category_edit_field($term) {
		$value = get_term_meta($term->term_id, 'mje_weight', true);
		echo '<tr class="form-field"><th scope="row"><label for="mje_weight">Peso por defecto (MJ Envíos)</label></th><td>';
		echo '<input type="text" name="mje_weight" id="mje_weight" value="' . esc_attr($value) . '">';
		echo '<p class="description">Para los productos de esta categoría que no tengan peso cargado.</p></td></tr>';
	}

	public static function save_category_field($term_id) {
		if (!isset($_POST['mje_weight'])) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$raw = sanitize_text_field(wp_unslash($_POST['mje_weight'])); // phpcs:ignore WordPress.Security.NonceVerification
		if ('' === trim($raw)) {
			delete_term_meta($term_id, 'mje_weight');
			return;
		}
		update_term_meta($term_id, 'mje_weight', (string) max(0, MJE_Settings::decimal($raw)));
	}
}
