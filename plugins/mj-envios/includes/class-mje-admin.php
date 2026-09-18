<?php
/**
 * wp-admin: WooCommerce → Envíos.
 *
 * Seis pantallas, en el orden en el que se usan:
 *
 *   Métodos           la lista y el editor de cada método de envío.
 *   Envíos            qué hay para despachar, y el seguimiento sin abrir el pedido.
 *   Datos del cliente qué se le pide (y campos propios que agregues vos).
 *   Códigos postales  la tabla propia: cuántos hay, importar un CSV, sumar uno.
 *   Correos           los conectados por su propio plugin, los que se configuran
 *                     acá, las sucursales y el estado de las conexiones.
 *   Ajustes           origen, peso, precio, cotización, textos y avisos.
 *
 * Cada bloque de configuración es una tarjeta con su título, su ayuda y su
 * estado a la vista, y las pantallas largas se parten en pestañas de segundo
 * nivel: la idea es que se lea de un vistazo qué está prendido y qué falta.
 *
 * Todo se dibuja con lo que declaran las clases: los tipos de método, los campos
 * de cada correo, el catálogo de datos del cliente, los estados de pedido de la
 * tienda. Sumar una opción nueva es declararla ahí, no tocar este archivo.
 */

defined('ABSPATH') || exit;

class MJE_Admin {

	const SLUG = 'mj-envios';

	/** Tope del CSV de códigos postales. Más que esto es un archivo equivocado. */
	const CSV_MAX_BYTES = 8388608;

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
		add_action('admin_notices', [__CLASS__, 'notices']);
		add_filter('plugin_action_links_' . plugin_basename(MJE_FILE), [__CLASS__, 'action_links']);

		add_action('admin_post_mje_save_method', [__CLASS__, 'save_method']);
		add_action('admin_post_mje_delete_method', [__CLASS__, 'delete_method']);
		add_action('admin_post_mje_duplicate_method', [__CLASS__, 'duplicate_method']);
		add_action('admin_post_mje_toggle_method', [__CLASS__, 'toggle_method']);
		add_action('admin_post_mje_save_group', [__CLASS__, 'save_group']);
		add_action('admin_post_mje_delete_group', [__CLASS__, 'delete_group']);
		add_action('admin_post_mje_save_fields', [__CLASS__, 'save_fields']);
		add_action('admin_post_mje_save_settings', [__CLASS__, 'save_settings']);
		add_action('admin_post_mje_save_carrier', [__CLASS__, 'save_carrier']);
		add_action('admin_post_mje_sync_branches', [__CLASS__, 'sync_branches']);
		add_action('admin_post_mje_add_to_zone', [__CLASS__, 'add_to_zone']);
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
				'group'    => 'Grupo guardado.',
				'group_deleted' => 'Grupo borrado. Los métodos que tenía adentro quedaron sueltos.',
				'toggled'  => 'Listo.',
				'fields'   => 'Datos del cliente guardados.',
				'settings' => 'Ajustes guardados.',
				'carrier'  => 'Correo guardado.',
				'branches' => 'Sucursales actualizadas.',
				'zone'     => 'Listo: el método del correo quedó agregado a la zona. Revisá su configuración en WooCommerce → Ajustes → Envío.',
				'branch'   => 'Sucursal guardada.',
				'geo'      => 'Códigos postales actualizados.',
				'quotes'   => 'Caché de cotizaciones vaciada.',
				'tracking' => sprintf(
					'%s envíos con seguimiento cargado.',
					number_format_i18n(isset($_GET['cuenta']) ? absint(wp_unslash($_GET['cuenta'])) : 0)
				),
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
		$pending = MJE_Shipments::pending_count();

		return [
			'metodos' => 'Métodos',
			'envios'  => $pending > 0 ? sprintf('Envíos (%s)', number_format_i18n($pending)) : 'Envíos',
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
			case 'envios':
				self::screen_shipments();
				break;
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
					break;
				}
				$views = ['metodos' => 'Métodos', 'grupos' => 'Grupos'];
				$view  = self::current_view($views);
				self::subnav('metodos', $views, $view);
				if ('grupos' === $view) {
					self::screen_groups();
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

	/* ------------------------------- Envíos ------------------------------ */

	/**
	 * Qué hay para despachar.
	 *
	 * Una sola pantalla con los pedidos que esperan salir, con qué correo va
	 * cada uno y a dónde, y el campo para cargarle el seguimiento sin abrir el
	 * pedido. Se cargan los que tengas a mano y se guarda una vez.
	 */
	private static function screen_shipments() {
		$views = MJE_Shipments::filters();
		$view  = self::current_view($views);
		self::subnav('envios', $views, $view);

		$carrier = isset($_GET['correo']) ? sanitize_key(wp_unslash($_GET['correo'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Solo filtra la lista.
		$method  = isset($_GET['metodo']) ? sanitize_key(wp_unslash($_GET['metodo'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$page    = isset($_GET['pagina']) ? absint(wp_unslash($_GET['pagina'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification

		self::shipment_filters($view, $carrier, $method);

		$result = MJE_Shipments::query($view, $page, $carrier, $method);
		if (!$result['orders']) {
			echo '<div class="mje-empty"><p>';
			echo 'pendientes' === $view
				? esc_html('No hay nada esperando salir. Todo lo que está para despachar ya tiene su número de seguimiento.')
				: esc_html('No hay envíos con esos filtros.');
			echo '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('mje_save_tracking');
		echo '<input type="hidden" name="action" value="mje_save_tracking">';
		echo '<input type="hidden" name="vista" value="' . esc_attr($view) . '">';

		echo '<table class="widefat striped mje-shipments"><thead><tr>';
		echo '<th>Pedido</th><th>Cliente</th><th>Cómo va</th><th>A dónde</th><th>Estado</th><th>Espera</th><th>Seguimiento</th>';
		echo '</tr></thead><tbody>';

		foreach ($result['orders'] as $order) {
			if (!$order instanceof WC_Order) {
				continue;
			}
			$row = MJE_Shipments::row($order);
			echo '<tr>';

			printf(
				'<td><a href="%s"><strong>#%s</strong></a><span class="mje-dest">%s</span></td>',
				esc_url($order->get_edit_order_url()),
				esc_html($order->get_order_number()),
				esc_html(wc_format_datetime($order->get_date_created(), 'd/m/Y'))
			);

			printf(
				'<td>%s<span class="mje-dest">%s</span></td>',
				esc_html(trim($order->get_formatted_billing_full_name())),
				esc_html($order->get_billing_phone())
			);

			printf(
				'<td>%s<span class="mje-dest">%s</span></td>',
				esc_html($row['method']),
				esc_html($row['carrier'])
			);

			printf(
				'<td>%s%s</td>',
				esc_html($row['destination']),
				'' !== $row['branch'] ? '<span class="mje-dest">' . esc_html($row['branch']) . '</span>' : ''
			);

			printf('<td>%s</td>', esc_html(wc_get_order_status_name($order->get_status())));

			printf(
				'<td class="mje-age%s">%s</td>',
				MJE_Shipments::is_late($row) ? ' is-late' : '',
				esc_html(0 === $row['days'] ? 'hoy' : sprintf('%d días', $row['days']))
			);

			echo '<td>';
			if ('' !== $row['tracking']) {
				printf('<code>%s</code>', esc_html($row['tracking']));
				if ('' !== $row['link']) {
					printf(' <a href="%s" target="_blank" rel="noopener">Ver</a>', esc_url($row['link']));
				}
			} else {
				printf(
					'<input type="text" name="tracking[%d]" value="" placeholder="%s">',
					(int) $order->get_id(),
					esc_attr('Número del correo')
				);
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<p>';
		echo self::checkbox_input('notify', 1, 'Avisarle al cliente por email cuando se guarde'); // phpcs:ignore WordPress.Security.EscapeOutput -- Ya escapado.
		echo '</p>';
		submit_button('Guardar los seguimientos');
		echo '</form>';

		self::shipment_pagination($result, $view, $carrier, $method, $page);
	}

	/** Filtros de la lista de envíos: por correo y por método. */
	private static function shipment_filters($view, $carrier, $method) {
		$carriers = ['' => 'Todos los correos'];
		foreach (MJE_Native::all() as $id => $plugin) {
			$carriers[$id] = $plugin->label();
		}
		foreach (MJE_Carriers::all() as $id => $own) {
			if (!isset($carriers[$id])) {
				$carriers[$id] = $own->label();
			}
		}

		$methods = ['' => 'Todos los métodos'];
		foreach (MJE_Methods::all() as $slug => $def) {
			$methods[$slug] = MJE_Methods::name($def);
		}

		echo '<form method="get" class="mje-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
		echo '<input type="hidden" name="tab" value="envios">';
		echo '<input type="hidden" name="vista" value="' . esc_attr($view) . '">';
		echo '<div><label for="mje-f-correo">Correo</label>' . self::select_input('correo', $carriers, $carrier, 'id="mje-f-correo"') . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
		echo '<div><label for="mje-f-metodo">Método</label>' . self::select_input('metodo', $methods, $method, 'id="mje-f-metodo"') . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
		echo '<div><button type="submit" class="button">Filtrar</button></div>';
		echo '</form>';
	}

	private static function shipment_pagination(array $result, $view, $carrier, $method, $page) {
		if ($result['pages'] < 2) {
			return;
		}
		$links = paginate_links([
			'base'      => self::page_url(['tab' => 'envios', 'vista' => $view, 'correo' => $carrier, 'metodo' => $method, 'pagina' => '%#%']),
			'format'    => '',
			'current'   => max(1, (int) $page),
			'total'     => (int) $result['pages'],
			'prev_text' => '‹',
			'next_text' => '›',
		]);
		if ($links) {
			printf(
				'<div class="tablenav"><div class="tablenav-pages">%s <span class="displaying-num">%s envíos</span></div></div>',
				wp_kses_post($links),
				esc_html(number_format_i18n($result['total']))
			);
		}
	}

	/* ------------------------------- Grupos ------------------------------ */

	/**
	 * Los grupos del checkout.
	 *
	 * Un grupo junta varios métodos abajo de un solo título, y recién al
	 * elegirlo el cliente ve las tarjetas de adentro con su plazo y su precio.
	 * Es lo que evita que un correo conectado llene la lista de opciones
	 * parecidas ("estándar", "sucursal", "expreso") al mismo nivel que el retiro
	 * en el local.
	 *
	 * El primero lo crea sola la adopción de un correo, con el nombre de la zona
	 * de envío donde está ese correo. Desde acá se renombra, se reordena o se
	 * borra: borrar un grupo no borra ningún método, los deja sueltos.
	 */
	private static function screen_groups() {
		$groups = MJE_Groups::all();

		echo '<p class="mje-intro">Un grupo junta varias formas de envío abajo de un solo título. El cliente elige el grupo y ahí se le abren las opciones de adentro, con quién lo lleva, cuánto tarda y cuánto sale.</p>';

		if (!$groups) {
			echo '<div class="mje-empty"><p>Todavía no hay ningún grupo. Se crea uno solo la primera vez que un correo conectado cotiza, con el nombre de su zona de envío — o lo creás vos acá abajo.</p></div>';
		} else {
			echo '<table class="widefat striped mje-methods"><thead><tr><th>Grupo</th><th>Qué tiene adentro</th><th>Orden</th><th>Estado</th><th></th></tr></thead><tbody>';
			foreach ($groups as $slug => $def) {
				$inside = MJE_Groups::methods($slug);
				$names  = [];
				foreach ($inside as $method) {
					$names[] = MJE_Methods::name($method) . (empty($method['enabled']) ? ' (apagado)' : '');
				}

				echo '<tr><td>';
				printf('<strong>%s</strong>', esc_html(trim((string) $def['icon'] . ' ' . (string) $def['title'])));
				if ('' !== (string) $def['description']) {
					printf('<br><span class="mje-note">%s</span>', esc_html($def['description']));
				}
				echo '</td><td>';
				echo $names
					? esc_html(implode(' · ', $names))
					: '<span class="mje-note">' . esc_html('Nada todavía. Se asigna desde la ficha de cada método.') . '</span>';
				echo '</td>';
				printf('<td>%d</td>', (int) $def['order']);
				printf('<td>%s</td>', empty($def['enabled']) ? self::pill('apagado', 'off') : self::pill('activo', 'ok')); // phpcs:ignore WordPress.Security.EscapeOutput -- pill() ya escapa.
				printf(
					'<td class="mje-actions"><a href="%s" class="mje-delete">Borrar</a></td>',
					esc_url(wp_nonce_url(admin_url('admin-post.php?action=mje_delete_group&grupo=' . rawurlencode($slug)), 'mje_group_' . $slug))
				);
				echo '</tr>';

				/* La fila de edición, abierta: son cuatro campos, no vale la pena una pantalla aparte. */
				echo '<tr class="mje-subrow"><td colspan="5">';
				echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mje-inline">';
				wp_nonce_field('mje_save_group');
				echo '<input type="hidden" name="action" value="mje_save_group">';
				echo '<input type="hidden" name="slug" value="' . esc_attr($slug) . '">';
				echo self::text_input('grupo[title]', $def['title'], ['class' => 'regular-text', 'placeholder' => 'Nombre']) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
				echo self::text_input('grupo[description]', $def['description'], ['class' => 'regular-text', 'placeholder' => 'Descripción']) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
				echo self::text_input('grupo[icon]', $def['icon'], ['class' => 'small-text', 'placeholder' => '📦']) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
				echo self::text_input('grupo[order]', $def['order'], ['type' => 'number', 'class' => 'small-text']) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
				echo self::checkbox_input('grupo[enabled]', $def['enabled'], 'Activo') . ' '; // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
				submit_button('Guardar', 'secondary', 'submit', false);
				echo '</form></td></tr>';
			}
			echo '</tbody></table>';
		}

		self::panel('Crear un grupo', 'El nombre es lo que lee el cliente arriba de las opciones.');
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mje-inline">';
		wp_nonce_field('mje_save_group');
		echo '<input type="hidden" name="action" value="mje_save_group">';
		echo '<input type="hidden" name="slug" value="">';
		echo self::text_input('grupo[title]', '', ['class' => 'regular-text', 'placeholder' => 'Envío a todo el país']) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
		echo self::text_input('grupo[description]', '', ['class' => 'regular-text', 'placeholder' => 'Elegí con qué correo']) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
		echo self::text_input('grupo[icon]', '', ['class' => 'small-text', 'placeholder' => '📦']) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
		echo '<input type="hidden" name="grupo[enabled]" value="1">';
		submit_button('Crear grupo', 'primary', 'submit', false);
		echo '</form>';
		self::panel_end();
	}

	/* ---------------------------- Tarjetas ------------------------------- */

	/**
	 * Abre una tarjeta.
	 *
	 * Cada bloque de configuración es una tarjeta con su título, su ayuda y su
	 * estado a la vista. Es lo que hace que la pantalla se lea de un vistazo en
	 * lugar de ser una tira de títulos y tablas.
	 */
	private static function panel($title, $subtitle = '', $aside = '', $class = '') {
		printf('<section class="mje-panel%s"><div class="mje-panel-head"><h2>%s</h2>', '' !== $class ? ' ' . esc_attr($class) : '', esc_html($title));
		if ('' !== $aside) {
			echo '<div class="mje-panel-aside">' . wp_kses_post($aside) . '</div>';
		}
		if ('' !== $subtitle) {
			echo '<p class="mje-panel-sub">' . wp_kses_post($subtitle) . '</p>';
		}
		echo '</div><div class="mje-panel-body">';
	}

	/** Cierra la tarjeta, con un pie opcional para los botones. */
	private static function panel_end($footer = '') {
		echo '</div>';
		if ('' !== $footer) {
			echo '<div class="mje-panel-foot">' . $footer . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML ya escapado por quien lo arma.
		}
		echo '</section>';
	}

	/** Una etiqueta de estado: ok, warn, bad, info u off. */
	private static function pill($text, $tone = 'off') {
		return sprintf('<span class="mje-pill is-%s">%s</span>', esc_attr($tone), esc_html($text));
	}

	/** Datos de solo lectura, en dos columnas: etiqueta arriba, valor abajo. */
	private static function facts(array $rows) {
		if (!$rows) {
			return;
		}
		echo '<dl class="mje-facts">';
		foreach ($rows as $row) {
			printf('<div><dt>%s</dt><dd>%s</dd></div>', esc_html($row['label']), esc_html($row['value']));
		}
		echo '</dl>';
	}

	/** Pestañas de segundo nivel dentro de una pestaña. */
	private static function subnav($tab, array $views, $current) {
		echo '<nav class="mje-subnav">';
		foreach ($views as $slug => $label) {
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url(self::page_url(['tab' => $tab, 'vista' => $slug])),
				$slug === $current ? 'is-active' : '',
				esc_html($label)
			);
		}
		echo '</nav>';
	}

	/** La vista elegida dentro de una pestaña, con la primera como default. */
	private static function current_view(array $views) {
		$view = isset($_GET['vista']) ? sanitize_key(wp_unslash($_GET['vista'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Solo elige qué se muestra.
		return isset($views[$view]) ? $view : (string) key($views);
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

		$groups = MJE_Groups::choices();
		echo '<table class="widefat striped mje-methods"><thead><tr>';
		echo '<th>Método</th><th>Cómo cobra</th><th>Entrega</th><th>Se muestra en</th><th>Condiciones</th><th>Estado</th><th></th>';
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
			} elseif ('nativo' === $def['type']) {
				$price = MJE_Native::label_of((string) $def['native_plugin']);
			}

			$group = isset($groups[$def['group']]) ? $groups[$def['group']] : '';

			echo '<tr>';
			printf(
				'<td><strong><a href="%s">%s</a></strong>%s</td>',
				esc_url(self::page_url(['tab' => 'metodos', 'metodo' => $slug])),
				esc_html(MJE_Methods::name($def)),
				'' !== (string) $def['description'] ? '<br><span class="description">' . esc_html($def['description']) . '</span>' : ''
			);
			printf('<td>%s<br><span class="description">%s</span></td>', esc_html(isset($types[$def['type']]) ? $types[$def['type']] : $def['type']), esc_html($price));
			printf('<td>%s</td>', esc_html(isset($dests[$def['destination']]) ? $dests[$def['destination']] : $def['destination']));
			printf(
				'<td>%s</td>',
				'' !== $group ? esc_html($group) : '<span class="mje-note">' . esc_html('La lista, suelto') . '</span>'
			);
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
		self::row(
			'Grupo',
			self::select_input('def[group]', ['' => '— Se muestra suelto —'] + MJE_Groups::choices(), $def['group']),
			'Los métodos de un mismo grupo se muestran juntos: el cliente elige el grupo y ahí se le abren las tarjetas de adentro. Los grupos se crean en la pestaña Grupos.'
		);
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

		/* Correo con su propio plugin */
		echo '<div class="mje-when" data-when="nativo"><table class="form-table">';
		$natives = MJE_Native::choices();
		self::row(
			'Correo conectado',
			self::select_input('def[native_plugin]', ['' => '— Elegir —'] + $natives, $def['native_plugin']),
			$natives
				? 'El precio lo pone su plugin, con la cuenta que ya tenés conectada ahí. MJ Envíos no le vuelve a preguntar nada a su API.'
				: 'No hay ningún correo con plugin propio instalado en esta tienda.'
		);
		self::row(
			'Servicio',
			self::text_input('def[native_service]', $def['native_service'], ['class' => 'regular-text', 'placeholder' => 'Envío a Domicilio']),
			self::native_service_help($def)
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

	/**
	 * La ayuda del campo "Servicio" de un correo con plugin.
	 *
	 * Los modos de entrega no son una lista fija: cada correo devuelve los suyos
	 * según el contrato. Se muestran los que se vieron cotizar de verdad, que es
	 * lo único que se puede afirmar sin inventar.
	 */
	private static function native_service_help(array $def) {
		$plugin_id = (string) $def['native_plugin'];
		$services  = '' !== $plugin_id ? MJE_Native::seen_services($plugin_id) : [];

		$codes = [];
		foreach (array_keys($services) as $service) {
			if ('' !== (string) $service) {
				$codes[] = '<code>' . esc_html($service) . '</code>';
			}
		}
		if ($codes) {
			return 'Servicios vistos cotizar: ' . implode(', ', $codes) . '. Vacío = el primero que ese correo devuelva.';
		}
		return 'Vacío = el primero que ese correo devuelva. Los nombres los pone el correo según tu contrato: se listan acá en cuanto cotice una vez.';
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
		$views = [];
		if (MJE_Native::any()) {
			$views['conectados'] = 'Conectados por su plugin';
		}
		$views['propios']    = 'Configurados acá';
		$views['sucursales'] = 'Sucursales';
		$views['estado']     = 'Estado';

		$view = self::current_view($views);
		self::subnav('correos', $views, $view);

		switch ($view) {
			case 'propios':
				self::screen_carriers_own();
				break;
			case 'sucursales':
				self::screen_branches();
				break;
			case 'estado':
				self::screen_carriers_status();
				break;
			default:
				self::screen_carriers_native();
		}
	}

	/* ------------------- Correos con su propio plugin -------------------- */

	/**
	 * Los correos que ya tienen su plugin oficial instalado.
	 *
	 * Acá no se pide ni una credencial: se muestra la cuenta que ya está
	 * conectada en el plugin del correo y se avisa lo único que puede fallar,
	 * que es que su método no esté en ninguna zona de envío. Sin eso su tarifa
	 * no llega nunca al carrito y no hay nada que MJ Envíos pueda hacer.
	 */
	private static function screen_carriers_native() {
		echo '<p class="mje-intro">Estos correos ya están conectados desde su propio plugin. MJ Envíos no les vuelve a pedir usuario ni clave: usa esa conexión, se queda con la tarifa que calculan y le aplica tus reglas.</p>';

		foreach (MJE_Native::all() as $id => $plugin) {
			$connected = $plugin->connected();
			$in_zone   = $plugin->in_any_zone();
			$missing   = $connected ? $plugin->missing() : [];
			$aside     = $connected
				? ($in_zone ? ($missing ? self::pill('le falta configuración', 'warn') : self::pill('conectado', 'ok')) : self::pill('fuera de las zonas', 'warn'))
				: self::pill('sin conectar', 'bad');

			$link = $plugin->settings_url();
			self::panel(
				$plugin->label(),
				'' !== $link ? sprintf('Se configura en <a href="%s">su propia pantalla</a>.', esc_url($link)) : '',
				$aside,
				($missing || ($connected && !$in_zone)) ? 'is-warning' : ''
			);

			if (!$connected) {
				echo '<p>Su plugin está instalado pero la cuenta todavía no está conectada. Cargá sus credenciales en la pantalla del correo y volvé acá.</p>';
				self::panel_end('' !== $link ? sprintf('<a href="%s" class="button button-primary">Ir a configurarlo</a>', esc_url($link)) : '');
				continue;
			}

			self::facts($plugin->summary());

			/* Dónde está su método: es lo que decide si su tarifa llega al carrito. */
			$instances = $plugin->zone_instances();
			if ($instances) {
				echo '<p><strong>Zonas de envío:</strong></p><ul class="mje-note">';
				foreach ($instances as $instance) {
					printf(
						'<li>%s — %s · <a href="%s">Ver</a></li>',
						esc_html('' !== $instance['zone'] ? $instance['zone'] : 'Resto del mundo'),
						$instance['enabled'] ? 'encendido' : 'apagado',
						esc_url($instance['url'])
					);
				}
				echo '</ul>';
			}

			if (!$in_zone) {
				echo '<p><strong>Su método no está en ninguna zona encendida.</strong> Hasta que lo agregues, este correo no cotiza y sus métodos caen al respaldo que elegiste en Ajustes.</p>';
				self::zone_form($id);
			}

			/* Qué métodos de MJ Envíos están usando este correo. */
			$links = [];
			foreach (MJE_Methods::all() as $def) {
				if ('nativo' !== (string) $def['type'] || (string) $def['native_plugin'] !== $id) {
					continue;
				}
				$links[] = sprintf(
					'<a href="%s">%s</a>%s',
					esc_url(self::page_url(['tab' => 'metodos', 'metodo' => $def['slug']])),
					esc_html(MJE_Methods::name($def)),
					empty($def['enabled']) ? ' ' . self::pill('apagado', 'off') : ''
				);
			}
			if ($links) {
				echo '<p><strong>Métodos que lo usan:</strong> ' . wp_kses_post(implode(' · ', $links)) . '</p>';
			} else {
				echo '<p class="mje-note">Todavía ningún método usa este correo. Se crean solos la primera vez que cotiza, si dejaste encendida la adopción automática en Ajustes.</p>';
			}

			/* Lo que le falta para poder cotizar, antes que cualquier otra cosa. */
			foreach ($plugin->missing() as $falta) {
				printf('<p class="mje-falta"><strong>%s</strong> %s</p>', esc_html('Falta completar:'), esc_html($falta));
			}

			/* Lo último que dijo el correo cuando no pudo cotizar: es el diagnóstico más útil. */
			$excuse = MJE_Native::excuse($id);
			if ('' !== $excuse) {
				printf('<p><strong>%s</strong> %s</p>', esc_html('La última vez no pudo cotizar:'), esc_html($excuse));
			}

			$count = count(MJE_Carriers::stored_branches(MJE_Native::carrier_key($id)));
			self::panel_end(sprintf(
				'<span class="mje-note">%s sucursales guardadas de este correo.</span>',
				esc_html(number_format_i18n($count))
			));
		}
	}

	/** Agregar el método del correo a una zona de envío, sin salir de acá. */
	private static function zone_form($plugin_id) {
		if (!class_exists('WC_Shipping_Zones')) {
			return;
		}
		$zones = [];
		foreach (WC_Shipping_Zones::get_zones() as $zone) {
			$zones[(int) $zone['id']] = (string) $zone['zone_name'];
		}
		if (!$zones) {
			echo '<p class="mje-note">Todavía no hay ninguna zona de envío creada en WooCommerce.</p>';
			return;
		}
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mje-inline">';
		wp_nonce_field('mje_add_to_zone');
		echo '<input type="hidden" name="action" value="mje_add_to_zone">';
		echo '<input type="hidden" name="plugin" value="' . esc_attr($plugin_id) . '">';
		echo self::select_input('zone', $zones, (string) key($zones)); // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
		submit_button('Agregarlo a esta zona', 'secondary', 'submit', false);
		echo '</form>';
	}

	/* --------------------- Correos configurados acá ---------------------- */

	private static function screen_carriers_own() {
		echo '<p class="mje-intro">Para los correos que no tienen plugin propio. Un correo sin credenciales queda apagado, y los métodos que lo usan cotizan con su tabla propia.</p>';

		foreach (MJE_Carriers::all() as $id => $carrier) {
			$connected = $carrier->connected();
			$native    = MJE_Native::get($id);

			self::panel(
				$carrier->label(),
				$native ? 'Este correo ya está conectado por su plugin oficial: no hace falta cargar nada acá, salvo que quieras usar otra cuenta.' : '',
				$connected ? self::pill('conectado', 'ok') : self::pill('sin conectar', 'off'),
				$native ? 'is-warning' : ''
			);

			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			wp_nonce_field('mje_save_carrier');
			echo '<input type="hidden" name="action" value="mje_save_carrier">';
			echo '<input type="hidden" name="carrier" value="' . esc_attr($id) . '">';
			echo '<table class="form-table">';
			foreach ($carrier->fields() as $key => $field) {
				$value = $carrier->config($key);
				$type  = isset($field['type']) ? $field['type'] : 'text';
				$help  = isset($field['help']) ? (string) $field['help'] : '';

				if ('select' === $type) {
					$html = self::select_input(sprintf('config[%s]', $key), isset($field['options']) ? $field['options'] : [], $value);
				} elseif ('checkbox' === $type) {
					$html = self::checkbox_input(sprintf('config[%s]', $key), $value, 'Sí');
				} elseif ('password' === $type) {
					/*
					 * Una clave guardada NO se reimprime en el HTML: viajaría en
					 * claro en cada carga de la pantalla. Se muestra vacía, y
					 * dejarla vacía conserva la que ya está (ver save_carrier).
					 */
					$saved = '' !== (string) $value;
					$html  = self::text_input(sprintf('config[%s]', $key), '', [
						'type'         => 'password',
						'class'        => 'regular-text',
						'autocomplete' => 'new-password',
						'placeholder'  => $saved ? 'Guardada' : '',
					]);
					if ($saved) {
						$help = trim($help . ' Ya hay una clave guardada: dejalo vacío para no cambiarla.');
					}
				} else {
					$html = self::text_input(sprintf('config[%s]', $key), $value, [
						'type'        => 'text',
						'class'       => 'regular-text',
						'placeholder' => isset($field['default']) ? (string) $field['default'] : '',
					]);
				}
				self::row((string) $field['label'] . (!empty($field['required']) ? ' *' : ''), $html, $help);
			}
			echo '</table></div><div class="mje-panel-foot">';
			submit_button('Guardar ' . $carrier->label(), 'secondary', 'submit', false);
			echo '</form></div></section>';
		}
	}

	/* ------------------------------ Sucursales --------------------------- */

	private static function screen_branches() {
		echo '<p class="mje-intro">Las sucursales que se le ofrecen al cliente cuando el envío se retira por el correo. Se traen una vez y quedan guardadas: el checkout nunca depende de que el correo esté funcionando.</p>';

		$sources = self::branch_sources();
		if (!$sources) {
			echo '<div class="mje-empty"><p>Todavía no hay ningún correo del que traer sucursales.</p></div>';
			return;
		}

		self::panel('Traer sucursales', 'Andreani y Correo Argentino las publican por zona, así que hace falta un código postal para pedirlas.');
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mje-inline">';
		wp_nonce_field('mje_sync_branches');
		echo '<input type="hidden" name="action" value="mje_sync_branches">';
		echo self::select_input('carrier', $sources, (string) key($sources)); // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
		echo self::text_input('cp', '', ['class' => 'small-text', 'placeholder' => 'CP']); // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
		echo self::select_input('provincia', ['' => '— Provincia (Correo Argentino las pide así) —'] + MJE_Geo::states(), ''); // phpcs:ignore WordPress.Security.EscapeOutput -- Lo arman los ayudantes de arriba, que ya escapan.
		submit_button('Traerlas', 'secondary', 'submit', false);
		echo '</form>';
		self::panel_end();

		foreach ($sources as $carrier_key => $label) {
			$branches = MJE_Carriers::stored_branches($carrier_key);
			self::panel(
				$label,
				'',
				self::pill(sprintf('%s guardadas', number_format_i18n(count($branches))), $branches ? 'info' : 'off')
			);

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
						esc_url(wp_nonce_url(admin_url('admin-post.php?action=mje_delete_branch&carrier=' . rawurlencode($carrier_key) . '&code=' . rawurlencode($branch['code'])), 'mje_branch_' . $carrier_key))
					);
				}
				echo '</tbody></table>';
				if (count($branches) > 20) {
					printf('<p class="description">Se muestran 20 de %s.</p>', esc_html(number_format_i18n(count($branches))));
				}
			} else {
				echo '<p class="mje-note">Todavía no hay ninguna guardada.</p>';
			}

			echo '<h4>Agregar una a mano</h4>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mje-inline">';
			wp_nonce_field('mje_save_branch');
			echo '<input type="hidden" name="action" value="mje_save_branch">';
			echo '<input type="hidden" name="carrier" value="' . esc_attr($carrier_key) . '">';
			echo self::text_input('branch[code]', '', ['class' => 'small-text', 'placeholder' => 'Código']) . ' ';
			echo self::text_input('branch[name]', '', ['class' => 'regular-text', 'placeholder' => 'Nombre']) . ' ';
			echo self::text_input('branch[address]', '', ['class' => 'regular-text', 'placeholder' => 'Dirección']) . ' ';
			echo self::text_input('branch[city]', '', ['class' => 'regular-text', 'placeholder' => 'Localidad']) . ' ';
			echo self::text_input('branch[postcode]', '', ['class' => 'small-text', 'placeholder' => 'CP']) . ' ';
			submit_button('Agregar', 'secondary', 'submit', false);
			echo '</form>';
			self::panel_end();
		}
	}

	/** De dónde pueden salir sucursales: los correos con plugin y los propios. */
	private static function branch_sources() {
		$sources = [];
		foreach (MJE_Native::all() as $id => $plugin) {
			$sources[MJE_Native::carrier_key($id)] = $plugin->label() . ' (por su plugin)';
		}
		foreach (MJE_Carriers::all() as $id => $carrier) {
			$sources[$id] = $carrier->label();
		}
		return $sources;
	}

	/* -------------------------------- Estado ----------------------------- */

	private static function screen_carriers_status() {
		self::panel('Caché de cotizaciones', 'El mismo destino y peso no se le pregunta dos veces al correo. Vaciala si cambiaste precios o contratos y querés ver el efecto ya.');
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mje-inline">';
		wp_nonce_field('mje_clear_quotes');
		echo '<input type="hidden" name="action" value="mje_clear_quotes">';
		echo '<button type="submit" class="button">Vaciar la caché</button>';
		echo '</form>';
		self::panel_end();

		$log = get_option('mje_log', []);
		$log = is_array($log) ? $log : [];
		self::panel('Últimos errores', 'Lo que contestaron los correos cuando algo falló.', self::pill(count($log) . ' anotados', $log ? 'warn' : 'off'));
		if ($log) {
			echo '<table class="widefat striped"><thead><tr><th>Cuándo</th><th>Dónde</th><th>Qué pasó</th></tr></thead><tbody>';
			foreach (array_reverse(array_slice($log, -15)) as $line) {
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html(isset($line['when']) ? $line['when'] : ''),
					esc_html(isset($line['scope']) ? $line['scope'] : ''),
					esc_html(isset($line['message']) ? $line['message'] : '')
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="mje-note">Ningún error anotado.</p>';
		}
		self::panel_end();
	}

	/* ------------------------------- Ajustes ----------------------------- */

	private static function screen_settings() {
		$views = [
			'general'    => 'General',
			'origen'     => 'Origen',
			'paquete'    => 'Peso y medidas',
			'precio'     => 'Precio',
			'cotizacion' => 'Cotización',
			'cp'         => 'Código postal',
			'textos'     => 'Textos',
			'avisos'     => 'Avisos',
		];
		$view = self::current_view($views);
		self::subnav('ajustes', $views, $view);

		$s = MJE_Settings::get();
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('mje_save_settings');
		echo '<input type="hidden" name="action" value="mje_save_settings">';
		echo '<input type="hidden" name="vista" value="' . esc_attr($view) . '">';

		switch ($view) {
			case 'origen':
				self::settings_origin($s);
				break;
			case 'paquete':
				self::settings_package($s);
				break;
			case 'precio':
				self::settings_price($s);
				break;
			case 'cotizacion':
				self::settings_quoting($s);
				break;
			case 'cp':
				self::settings_geo($s);
				break;
			case 'textos':
				self::settings_texts($s);
				break;
			case 'avisos':
				self::settings_notices($s);
				break;
			default:
				self::settings_general($s);
		}

		submit_button('Guardar ajustes');
		echo '</form>';
	}

	/** Abre una tarjeta con una tabla de formulario adentro. */
	private static function settings_panel($title, $subtitle = '', $aside = '') {
		self::panel($title, $subtitle, $aside);
		echo '<table class="form-table">';
	}

	private static function settings_panel_end() {
		echo '</table>';
		self::panel_end();
	}

	/* ------------------------------- General ----------------------------- */

	private static function settings_general(array $s) {
		self::settings_panel(
			'Quién calcula los envíos',
			'',
			$s['enabled'] ? self::pill('encendido', 'ok') : self::pill('apagado', 'off')
		);
		self::row('Envíos', self::checkbox_input('settings[enabled]', $s['enabled'], 'MJ Envíos calcula los envíos de la tienda'));
		self::row(
			'Zonas de WooCommerce',
			self::checkbox_input('settings[replace_native]', $s['replace_native'], 'Cuando hay métodos propios, no mostrar las tarifas de las zonas'),
			'Las zonas se siguen usando para que los correos con plugin propio coticen; lo que cambia es qué ve el cliente. Apagado, las dos listas se muestran juntas (menos las tarifas que ya quedaron envueltas en un método propio, que nunca se duplican).'
		);
		self::settings_panel_end();

		$natives = MJE_Native::choices();
		self::settings_panel(
			'Correos conectados por su plugin',
			$natives
				? 'Instalados en esta tienda: ' . esc_html(implode(', ', $natives)) . '.'
				: 'Todavía no hay ninguno instalado. Cuando instales el plugin oficial de un correo, aparece acá solo.',
			$natives ? self::pill(count($natives) . ' conectados', 'info') : self::pill('ninguno', 'off')
		);
		self::row(
			'Precio $0 del correo',
			self::checkbox_input('settings[native_allow_zero]', $s['native_allow_zero'], 'Aceptar $0 como precio real'),
			'Apagado (lo recomendado): un envío que el correo devuelve en $0 no se ofrece. Varios plugins de correo, cuando no pueden cotizar, en vez de no devolver nada devuelven una tarifa vacía de $0 — ofrecerla sería regalar el envío. Prendelo solo si de verdad tenés un acuerdo de envío sin cargo con el correo.'
		);
		self::row(
			'Adopción automática',
			self::checkbox_input('settings[native_adopt]', $s['native_adopt'], 'Cada servicio del correo que cotiza queda como método propio'),
			'Es lo que hace que conectar un correo alcance: sus modos de entrega aparecen solos en la lista de métodos, con tus reglas ya aplicadas, y desde ahí les cambiás el nombre, el orden o las condiciones. Apagado, los métodos se cargan a mano.'
		);
		self::settings_panel_end();

		self::settings_panel('Carteles de la lista', 'Lo que se le marca al cliente en el checkout para que elija sin pensar.');
		self::row('Mostrarlos', self::checkbox_input('settings[badges_enabled]', $s['badges_enabled'], 'Marcar el envío más barato y el más rápido'), 'Solo aparecen cuando hay con qué comparar y hay un ganador claro: con dos envíos al mismo precio no se marca nada.');
		self::row('Más barato', self::text_input('settings[badge_cheapest]', $s['badge_cheapest'], ['class' => 'regular-text']));
		self::row('Más rápido', self::text_input('settings[badge_fastest]', $s['badge_fastest'], ['class' => 'regular-text']));
		self::settings_panel_end();

		self::settings_panel('MJ ERP');
		self::row('Costo del correo', self::checkbox_input('settings[erp_cost]', $s['erp_cost'], 'Guardar en el pedido lo que cobró el correo, además de lo que pagó el cliente'), 'Es la diferencia entre lo que cobrás por el envío y lo que te cuesta.');
		self::settings_panel_end();
	}

	/* -------------------------------- Origen ----------------------------- */

	private static function settings_origin(array $s) {
		self::settings_panel(
			'Desde dónde despachás',
			'Sin el código postal de origen, ningún correo puede cotizar.',
			MJE_Settings::origin_ready() ? self::pill('completo', 'ok') : self::pill('falta el CP', 'warn')
		);
		self::row('Nombre o razón social', self::text_input('settings[origin_name]', $s['origin_name']));
		self::row('Dirección', self::text_input('settings[origin_address]', $s['origin_address'], ['class' => 'large-text']));
		self::row('Piso, depto o referencia', self::text_input('settings[origin_extra]', $s['origin_extra']));
		self::row('Localidad', self::text_input('settings[origin_city]', $s['origin_city']));
		self::row('Provincia', self::select_input('settings[origin_state]', ['' => '— Elegir —'] + MJE_Geo::states(), $s['origin_state']));
		self::row('Código postal', self::text_input('settings[origin_postcode]', $s['origin_postcode'], ['class' => 'small-text']));
		self::row('País', self::text_input('settings[origin_country]', $s['origin_country'], ['class' => 'small-text', 'placeholder' => 'AR']), 'Vacío = el país base de la tienda.');
		self::row('Teléfono', self::text_input('settings[origin_phone]', $s['origin_phone']));
		self::row('Email', self::text_input('settings[origin_email]', $s['origin_email']));
		self::row('Sucursal de despacho', self::text_input('settings[origin_branch]', $s['origin_branch']), 'Si el correo la pide. Los correos con plugin propio usan la que esté configurada en su pantalla.');
		self::settings_panel_end();
	}

	/* ----------------------------- Peso y medidas ------------------------ */

	private static function settings_package(array $s) {
		self::settings_panel('Peso y medidas', 'Se usa el peso cargado en cada producto. Esto es para lo que no lo tenga: también se puede poner un peso por defecto por producto y por categoría.');
		self::row('Peso por defecto', self::text_input('settings[weight_default]', $s['weight_default'], ['class' => 'small-text']), 'Por unidad, en ' . esc_html(get_option('woocommerce_weight_unit', 'kg')) . '.');
		self::row('Peso del embalaje', self::text_input('settings[weight_extra]', $s['weight_extra'], ['class' => 'small-text']), 'Se suma una vez por paquete.');
		self::row('Peso mínimo del paquete', self::text_input('settings[weight_min]', $s['weight_min'], ['class' => 'small-text']));
		self::row('Peso máximo por bulto', self::text_input('settings[weight_max]', $s['weight_max'], ['class' => 'small-text']), 'Arriba de esto se cotiza en varios bultos. 0 = sin tope.');
		self::row('Medidas del bulto (cm)', self::text_input('settings[dim_length]', $s['dim_length'], ['class' => 'small-text', 'placeholder' => 'largo']) . ' ' . self::text_input('settings[dim_width]', $s['dim_width'], ['class' => 'small-text', 'placeholder' => 'ancho']) . ' ' . self::text_input('settings[dim_height]', $s['dim_height'], ['class' => 'small-text', 'placeholder' => 'alto']), 'Para los productos sin medidas cargadas.');
		self::row('Con varios productos', self::select_input('settings[dim_stack]', ['alto' => 'Se apilan: suma el alto', 'mayor' => 'Se toma la medida más grande'], $s['dim_stack']));
		self::row('Divisor de peso volumétrico', self::text_input('settings[volumetric]', $s['volumetric'], ['class' => 'small-text']), 'Si el correo cobra por volumen: largo × ancho × alto ÷ este número. 0 = no se usa.');
		self::settings_panel_end();
	}

	/* -------------------------------- Precio ----------------------------- */

	private static function settings_price(array $s) {
		self::settings_panel('Precio final', 'Se aplica en este orden sobre lo que salga del correo o de la tabla, y vale también para los correos con plugin propio.');
		self::row('Margen (%)', self::text_input('settings[markup_pct]', $s['markup_pct'], ['class' => 'small-text']), 'Se suma al costo del correo: manipuleo, embalaje.');
		self::row('Margen fijo', self::text_input('settings[markup_fixed]', $s['markup_fixed'], ['class' => 'small-text']));
		self::row('Seguro (%)', self::text_input('settings[insurance_pct]', $s['insurance_pct'], ['class' => 'small-text']), 'Sobre el valor declarado.');
		self::row('Valor declarado', self::select_input('settings[declared_value]', MJE_Settings::declared_choices(), $s['declared_value']) . ' ' . self::text_input('settings[declared_pct]', $s['declared_pct'], ['class' => 'small-text']));
		self::row('Precio mínimo', self::text_input('settings[min_cost]', $s['min_cost'], ['class' => 'small-text']));
		self::row('Redondeo', self::select_input('settings[rounding]', MJE_Settings::rounding_choices(), $s['rounding']) . ' ' . self::select_input('settings[rounding_mode]', ['nearest' => 'Al más cercano', 'up' => 'Siempre para arriba'], $s['rounding_mode']));
		self::settings_panel_end();

		self::settings_panel(
			'Envío gratis',
			'Vale para todos los métodos que lo permitan, incluidos los de los correos conectados.',
			$s['free_enabled'] ? self::pill('activado', 'ok') : self::pill('apagado', 'off')
		);
		self::row('Envío gratis por monto', self::checkbox_input('settings[free_enabled]', $s['free_enabled'], 'Activado'));
		self::row('Desde', self::text_input('settings[free_min]', $s['free_min'], ['class' => 'regular-text']), 'Subtotal del carrito. Es el número que anuncia la barra de la tienda.');
		self::row('Solo estos métodos', self::text_input('settings[free_methods]', $s['free_methods'], ['class' => 'large-text', 'placeholder' => 'slugs separados por coma']), 'Vacío = todos los que lo permitan.');
		self::row('Solo estas provincias', self::text_input('settings[free_states]', $s['free_states'], ['class' => 'large-text']));
		self::row('Cómo se llama', self::text_input('settings[free_label]', $s['free_label'], ['class' => 'small-text']));
		self::settings_panel_end();
	}

	/* ------------------------------ Cotización --------------------------- */

	private static function settings_quoting(array $s) {
		self::settings_panel('Cuando se le pregunta al correo');
		self::row('Guardar las cotizaciones', self::text_input('settings[cache_hours]', $s['cache_hours'], ['type' => 'number', 'class' => 'small-text']) . ' horas', 'El mismo destino y peso no se pregunta dos veces. 0 = siempre preguntar. Los correos con plugin propio tienen además su propia caché.');
		self::row('Espera máxima', self::text_input('settings[timeout]', $s['timeout'], ['type' => 'number', 'class' => 'small-text']) . ' segundos');
		self::row('Si el correo no responde', self::select_input('settings[on_error]', MJE_Settings::error_choices(), $s['on_error']), 'Vale igual para un correo propio que no contesta y para uno con plugin propio que no cotizó ese destino.');
		self::row('Registrar los errores', self::checkbox_input('settings[log_errors]', $s['log_errors'], 'Guardar las últimas fallas para poder mirarlas'));
		self::settings_panel_end();

		self::settings_panel('Sucursales', 'Cómo se le ofrecen al cliente las sucursales del correo.');
		self::row('Orden', self::select_input('settings[branch_sort]', MJE_Settings::branch_sort_choices(), $s['branch_sort']), 'Por cercanía se usa la distancia real cuando el correo publica las coordenadas de sus sucursales; si no, primero las del mismo código postal y después las de la misma localidad.');
		self::row('Cuántas mostrar', self::text_input('settings[branch_max]', $s['branch_max'], ['type' => 'number', 'class' => 'small-text']), '0 = todas las que haya.');
		self::settings_panel_end();
	}

	/* ---------------------------- Código postal -------------------------- */

	private static function settings_geo(array $s) {
		self::settings_panel('De dónde sale la localidad', 'El cliente pone el código postal y la tienda completa provincia y localidad sola. Siempre las puede corregir a mano.');
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
		self::settings_panel_end();
	}

	/* -------------------------------- Textos ----------------------------- */

	private static function settings_texts(array $s) {
		self::settings_panel('El paso de envío');
		self::row('Título del paso', self::text_input('settings[step_title]', $s['step_title']));
		self::row('Bajada del paso', self::text_input('settings[step_intro]', $s['step_intro'], ['class' => 'large-text']));
		self::row('Sin envíos para el destino', self::textarea('settings[none_text]', $s['none_text'], 2));
		self::row('Envío a coordinar', self::textarea('settings[coordinate_text]', $s['coordinate_text'], 2));
		self::row('No se pudo cotizar', self::textarea('settings[error_text]', $s['error_text'], 2));
		self::row('Plazo (de X a Y)', self::text_input('settings[eta_text]', $s['eta_text'], ['class' => 'large-text']), 'Marcas: <code>{min}</code> <code>{max}</code>.');
		self::row('Plazo (un solo número)', self::text_input('settings[eta_text_one]', $s['eta_text_one'], ['class' => 'large-text']));
		self::row('Plazo (un solo día)', self::text_input('settings[eta_text_day]', $s['eta_text_day'], ['class' => 'large-text']), 'Se usa cuando es exactamente un día: «1 días hábiles» no lo escribe nadie.');
		self::settings_panel_end();

		self::settings_panel(
			'Cotizador en la ficha del producto',
			'El cliente pone su código postal en la página del producto y ve cuánto sale y cuándo llega, sin armar el carrito.',
			$s['quote_product'] ? self::pill('encendido', 'ok') : self::pill('apagado', 'off')
		);
		self::row('Mostrarlo', self::checkbox_input('settings[quote_product]', $s['quote_product'], 'Mostrar el cotizador en la ficha del producto'));
		self::row('Título', self::text_input('settings[quote_title]', $s['quote_title'], ['class' => 'large-text']));
		self::row('Ayuda', self::text_input('settings[quote_help]', $s['quote_help'], ['class' => 'large-text']));
		self::row('Botón', self::text_input('settings[quote_button]', $s['quote_button'], ['class' => 'regular-text']));
		self::settings_panel_end();

		self::settings_panel('Código postal y destino');
		self::row('Código postal', self::text_input('settings[cp_label]', $s['cp_label']));
		self::row('Ayuda del código postal', self::text_input('settings[cp_help]', $s['cp_help'], ['class' => 'large-text']));
		self::row('Antes de cargar el CP', self::text_input('settings[cp_ask]', $s['cp_ask'], ['class' => 'large-text']));
		self::row('CP que no reconocemos', self::text_input('settings[cp_invalid]', $s['cp_invalid'], ['class' => 'large-text']));
		self::row('Sucursal', self::text_input('settings[branch_label]', $s['branch_label']));
		self::row('Ayuda de la sucursal', self::text_input('settings[branch_help]', $s['branch_help'], ['class' => 'large-text']));
		self::row('Retiro en el local', self::text_input('settings[pickup_label]', $s['pickup_label']));
		self::settings_panel_end();
	}

	/* -------------------------------- Avisos ----------------------------- */

	private static function settings_notices(array $s) {
		$statuses = '<div class="mje-checklist">';
		$active   = MJE_Shipments::statuses();
		foreach (MJE_Shipments::status_choices() as $key => $label) {
			$statuses .= sprintf(
				'<label><input type="checkbox" name="settings[ship_statuses][]" value="%s"%s> %s</label>',
				esc_attr($key),
				checked(in_array($key, $active, true), true, false),
				esc_html($label)
			);
		}
		$statuses .= '</div>';

		self::settings_panel('Pantalla de envíos', 'Qué aparece en la lista de pendientes de despacho.');
		self::row('Estados listos para despachar', $statuses, 'Un pedido entra en la lista cuando está en uno de estos estados y todavía no tiene seguimiento.');
		self::row('Marcar atrasado a los', self::text_input('settings[ship_late_days]', $s['ship_late_days'], ['type' => 'number', 'class' => 'small-text']) . ' días', '0 = no marcar nada.');
		self::settings_panel_end();

		self::settings_panel(
			'Seguimiento',
			'',
			$s['track_mail'] ? self::pill('avisa por email', 'ok') : self::pill('sin aviso', 'off')
		);
		self::row('En Mi cuenta', self::checkbox_input('settings[track_account]', $s['track_account'], 'El cliente ve el seguimiento en su pedido'));
		self::row('Avisar por email', self::checkbox_input('settings[track_mail]', $s['track_mail'], 'Mandar un email cuando se carga el número de seguimiento'));
		self::row('Asunto', self::text_input('settings[track_subject]', $s['track_subject'], ['class' => 'large-text']));
		self::row('Cuerpo', self::textarea('settings[track_body]', $s['track_body'], 6), 'Marcas: <code>{cliente}</code> <code>{numero}</code> <code>{codigo}</code> <code>{link}</code> <code>{metodo}</code> <code>{tienda}</code>.');
		self::row('Mensaje de WhatsApp', self::textarea('settings[track_whatsapp]', $s['track_whatsapp'], 3), 'Mismas marcas. Se usa con el botón del pedido.');
		self::settings_panel_end();

		self::settings_panel(
			'Listo para retirar',
			'Para lo que se retira en el local o en un punto a coordinar. Es un estado de pedido propio: lo elegís en el pedido o en lote desde la lista, y cuenta como pagado.',
			$s['ready_enabled'] ? self::pill('encendido', 'ok') : self::pill('apagado', 'off')
		);
		self::row('Estado propio', self::checkbox_input('settings[ready_enabled]', $s['ready_enabled'], 'Sumar el estado de pedido «Listo para retirar»'), 'El cliente lo ve en Mi cuenta con este nombre.');
		self::row('Nombre del estado', self::text_input('settings[ready_label]', $s['ready_label'], ['class' => 'regular-text']));
		self::row('Avisar por email', self::checkbox_input('settings[ready_mail]', $s['ready_mail'], 'Mandar un email cuando el pedido pasa a ese estado'));
		self::row('Asunto', self::text_input('settings[ready_subject]', $s['ready_subject'], ['class' => 'large-text']));
		self::row('Cuerpo', self::textarea('settings[ready_body]', $s['ready_body'], 6), 'Marcas: <code>{cliente}</code> <code>{numero}</code> <code>{metodo}</code> <code>{instrucciones}</code> (las del método de envío del pedido) <code>{tienda}</code>.');
		self::row('Mensaje de WhatsApp', self::textarea('settings[ready_whatsapp]', $s['ready_whatsapp'], 3), 'Mismas marcas.');
		self::settings_panel_end();
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

	public static function save_group() {
		self::guard('mje_save_group');
		$slug = isset($_POST['slug']) ? sanitize_key(wp_unslash($_POST['slug'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce lo verifica self::guard().
		$def  = isset($_POST['grupo']) && is_array($_POST['grupo']) ? wp_unslash($_POST['grupo']) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- Nonce en guard(); lo sanea MJE_Groups::sanitize().
		MJE_Groups::save($slug, $def);
		self::back(['tab' => 'metodos', 'vista' => 'grupos', 'saved' => 'group']);
	}

	public static function delete_group() {
		$slug = isset($_GET['grupo']) ? sanitize_key(wp_unslash($_GET['grupo'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- El nonce se verifica en la línea siguiente.
		self::guard('mje_group_' . $slug);
		MJE_Groups::delete($slug);
		self::back(['tab' => 'metodos', 'vista' => 'grupos', 'saved' => 'group_deleted']);
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

		/*
		 * Proveedores de CP: el orden escrito manda sobre las casillas. Solo se
		 * tocan si esa sección viajó en el formulario; si no, quedan como están
		 * (guardar otra pestaña no puede dejar la tienda sin proveedores).
		 */
		if (array_key_exists('geo_providers_order', $in) || array_key_exists('geo_providers', $in)) {
			$order = isset($in['geo_providers_order']) ? (string) $in['geo_providers_order'] : '';
			if ('' !== trim($order)) {
				$in['geo_providers'] = $order;
			} elseif (isset($in['geo_providers']) && is_array($in['geo_providers'])) {
				$in['geo_providers'] = implode(',', $in['geo_providers']);
			} else {
				$in['geo_providers'] = '';
			}
			unset($in['geo_providers_order']);
		}

		MJE_Settings::update($in);
		MJE_Settings::flush_caches();
		self::back([
			'tab'   => 'ajustes',
			'vista' => isset($_POST['vista']) ? sanitize_key(wp_unslash($_POST['vista'])) : 'general', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce lo verifica self::guard().
			'saved' => 'settings',
		]);
	}

	public static function save_carrier() {
		self::guard('mje_save_carrier');
		$id      = isset($_POST['carrier']) ? sanitize_key(wp_unslash($_POST['carrier'])) : '';
		$carrier = MJE_Carriers::get($id);
		if (!$carrier) {
			self::back(['tab' => 'correos', 'error' => 'Ese correo no existe.']);
		}
		$config = isset($_POST['config']) && is_array($_POST['config']) ? wp_unslash($_POST['config']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Lo sanea update_config().

		/*
		 * La pantalla nunca reimprime una clave guardada, así que un campo de
		 * clave vacío significa "no la toques", no "borrala". Sin esto, guardar
		 * cualquier otro dato del correo dejaba la tienda sin credenciales.
		 */
		foreach ($carrier->fields() as $key => $field) {
			$is_password = isset($field['type']) && 'password' === $field['type'];
			if ($is_password && '' === trim((string) (isset($config[$key]) ? $config[$key] : ''))) {
				$config[$key] = $carrier->config($key);
			}
		}

		$carrier->update_config($config);
		/* Credenciales nuevas: el token guardado ya no sirve. */
		delete_transient('mje_token_' . $id);
		self::back(['tab' => 'correos', 'saved' => 'carrier']);
	}

	/**
	 * Trae las sucursales de un correo.
	 *
	 * Sirve igual para un correo con plugin propio (la clave viene con prefijo
	 * `nativo_`) que para uno configurado acá. El código postal es lo que la
	 * mayoría necesita para contestar: sin él, varios devuelven la lista vacía.
	 */
	public static function sync_branches() {
		self::guard('mje_sync_branches');
		$key  = isset($_POST['carrier']) ? sanitize_key(wp_unslash($_POST['carrier'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce lo verifica self::guard().
		$cp   = isset($_POST['cp']) ? MJE_Geo::normalize_cp(wp_unslash($_POST['cp'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce lo verifica self::guard().
		$back = ['tab' => 'correos', 'vista' => 'sucursales'];

		if ('' === $key) {
			self::back($back + ['error' => 'Elegí de qué correo traerlas.']);
		}

		$before = count(MJE_Carriers::stored_branches($key));
		if (0 === strpos($key, 'nativo_')) {
			MJE_Native::branches(substr($key, strlen('nativo_')), $cp, true, isset($_POST['provincia']) ? sanitize_text_field(wp_unslash($_POST['provincia'])) : ''); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce lo verifica self::guard().
		} else {
			MJE_Carriers::branches($key, $cp, true);
		}
		$after = count(MJE_Carriers::stored_branches($key));

		if ($after === $before) {
			self::back($back + ['error' => '' === $cp
				? 'El correo no devolvió ninguna sucursal nueva. Probá con un código postal: la mayoría las publica por zona.'
				: sprintf('El correo no devolvió sucursales para el CP %s. Revisá la conexión o cargalas a mano.', $cp),
			]);
		}
		self::back($back + ['saved' => 'branches']);
	}

	/**
	 * Agrega el método de un correo a una zona de envío de WooCommerce.
	 *
	 * Es el atajo para el error más común: el correo está conectado pero su
	 * método no está en ninguna zona, así que su tarifa no llega nunca y MJ
	 * Envíos no tiene qué mostrar.
	 */
	public static function add_to_zone() {
		self::guard('mje_add_to_zone');
		$back = ['tab' => 'correos', 'vista' => 'conectados'];

		$plugin = MJE_Native::get(isset($_POST['plugin']) ? sanitize_key(wp_unslash($_POST['plugin'])) : ''); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce lo verifica self::guard().
		if (!$plugin) {
			self::back($back + ['error' => 'Ese correo no está instalado.']);
		}
		if (!class_exists('WC_Shipping_Zones')) {
			self::back($back + ['error' => 'WooCommerce no tiene zonas de envío disponibles.']);
		}

		$zone = WC_Shipping_Zones::get_zone(isset($_POST['zone']) ? absint(wp_unslash($_POST['zone'])) : 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce lo verifica self::guard().
		if (!$zone) {
			self::back($back + ['error' => 'Esa zona de envío no existe.']);
		}

		$instance_id = $zone->add_shipping_method($plugin->method_id());
		if (!$instance_id) {
			self::back($back + ['error' => 'WooCommerce no pudo agregar el método a esa zona.']);
		}
		$zone->save();
		self::back($back + ['saved' => 'zone']);
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
		$file = $_FILES['csv']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Se valida entero acá abajo.
		if (!empty($file['error'])) {
			self::back(['tab' => 'cp', 'error' => 'El archivo no se pudo subir (error ' . (int) $file['error'] . ').']);
		}

		/*
		 * Tres redes antes de abrir nada: que el archivo venga de verdad de una
		 * subida (y no sea una ruta del servidor puesta a mano), que pese algo
		 * razonable y que sea un CSV.
		 */
		$tmp = (string) $file['tmp_name'];
		if (!is_uploaded_file($tmp)) {
			self::back(['tab' => 'cp', 'error' => 'Ese archivo no llegó por el formulario.']);
		}
		if ((int) $file['size'] > self::CSV_MAX_BYTES) {
			self::back(['tab' => 'cp', 'error' => sprintf('El archivo pesa más de %d MB. Partilo en varios.', self::CSV_MAX_BYTES / MB_IN_BYTES)]);
		}
		$checked = wp_check_filetype_and_ext($tmp, (string) $file['name'], ['csv' => 'text/csv', 'txt' => 'text/plain']);
		if (empty($checked['ext']) || !in_array($checked['ext'], ['csv', 'txt'], true)) {
			self::back(['tab' => 'cp', 'error' => 'Tiene que ser un archivo .csv.']);
		}

		$result = MJE_Geo::import_csv($tmp);
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
