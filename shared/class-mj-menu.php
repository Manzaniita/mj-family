<?php
/**
 * MJ Menu — el menú único de la familia MJ en wp-admin.
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
 * Cada plugin de la familia colgaba su pantalla de donde le quedaba cómodo: MJ
 * Envíos y MJ Dólar abajo de WooCommerce, MJ ERP y Turnos como menú propio, MJ
 * Appearance abajo de Apariencia, MJ Core en Ajustes. Con ocho plugins
 * instalados, encontrar dónde se configuraba algo era una búsqueda.
 *
 * Esto junta todo abajo de un solo menú "MJ", sin que ningún plugin dependa de
 * otro: la clase viaja adentro de cada uno y la primera copia que se carga es
 * la que manda (class_exists). Un plugin solo, sin sus hermanos, arma igual su
 * menú MJ con su única pantalla adentro.
 *
 * ---------------------------------------------------------------------------
 * CÓMO SE USA
 *
 * Al cargar el plugin, una vez:
 *
 *     require_once __DIR__ . '/includes/compartido/class-mj-menu.php';
 *     MJ_Menu::init();
 *
 * Y donde antes decía el padre del menú:
 *
 *     add_submenu_page('woocommerce', 'MJ Envíos', 'Envíos', $cap, $slug, $cb);
 *     add_menu_page('MJ Turnos', 'Turnos', $cap, 'mjto', $cb, $icono, 57);
 *
 * ahora dice:
 *
 *     add_submenu_page(MJ_Menu::slug(), 'MJ Envíos', 'Envíos', $cap, $slug, $cb);
 *     add_submenu_page(MJ_Menu::slug(), 'MJ Turnos', 'Turnos', $cap, 'mjto', $cb);
 *
 * El plugin sigue registrando su pantalla en `admin_menu` como siempre, con su
 * propia capacidad: acá no se decide quién ve qué.
 *
 * ---------------------------------------------------------------------------
 * LO QUE NO HACE
 *
 * No toca los menús que no son de la familia (Andreani, Correo Argentino y
 * demás plugins de terceros siguen donde los pone su autor), ni mueve las
 * pantallas propias de WooCommerce.
 */

defined('ABSPATH') || exit;

if (!class_exists('MJ_Menu')) :

final class MJ_Menu {

	/** El slug del menú. No se cambia: es el padre de todas las pantallas. */
	const SLUG = 'mj';

	/** Nombre, ícono y posición: se guardan acá, no se escriben en el código. */
	const OPTION = 'mj_menu';

	private static $listo = false;

	/** Se puede llamar desde todos los plugins: solo el primero engancha. */
	public static function init() {
		if (self::$listo) {
			return;
		}
		self::$listo = true;

		/*
		 * Prioridad 9: el menú padre tiene que existir antes de que los plugins
		 * cuelguen sus pantallas, que es lo que hacen en la 10.
		 */
		add_action('admin_menu', [__CLASS__, 'register'], 9);
		/* Y en la 100, cuando ya están todas, se ordenan y se corrige el acceso. */
		add_action('admin_menu', [__CLASS__, 'tidy'], 100);
	}

	public static function slug() {
		return self::SLUG;
	}

	/* ------------------------------ Ajustes ------------------------------ */

	public static function defaults() {
		return [
			'title'    => 'MJ',
			'icon'     => 'dashicons-screenoptions',
			'position' => 56, // Abajo de WooCommerce, arriba de Apariencia.
		];
	}

	public static function config($key = null) {
		$saved  = get_option(self::OPTION, []);
		$config = wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
		if (null === $key) {
			return $config;
		}
		return isset($config[$key]) ? $config[$key] : null;
	}

	/**
	 * La capacidad con la que se registra el menú padre.
	 *
	 * Arranca con la de WooCommerce cuando está (para que el encargado de la
	 * tienda lo vea) y cae a la de administrador cuando no. En `tidy()` se
	 * corrige con la de las pantallas que de verdad quedaron adentro.
	 */
	public static function capability() {
		$cap = class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
		/**
		 * Quién ve el menú MJ.
		 *
		 * @param string $cap Capacidad de WordPress.
		 */
		return (string) apply_filters('mj_menu_capability', $cap);
	}

	/* ------------------------------- Menú -------------------------------- */

	public static function register() {
		$config = self::config();
		add_menu_page(
			(string) $config['title'],
			(string) $config['title'],
			self::capability(),
			self::SLUG,
			[__CLASS__, 'render'],
			(string) $config['icon'],
			(int) $config['position']
		);
	}

	/**
	 * Deja el menú prolijo, cuando ya están todas las pantallas.
	 *
	 * Tres cosas: le pone nombre a la primera entrada (WordPress repite ahí el
	 * título del padre), ordena las pantallas alfabéticamente para que no
	 * dependan del orden en que se activaron los plugins, y baja la capacidad
	 * del padre si adentro hay algo que este usuario sí puede ver.
	 */
	public static function tidy() {
		global $submenu;
		if (!isset($submenu[self::SLUG]) || !is_array($submenu[self::SLUG])) {
			return;
		}

		$propia = null;
		$otras  = [];
		foreach ($submenu[self::SLUG] as $item) {
			if (isset($item[2]) && self::SLUG === $item[2]) {
				$item[0] = self::index_title();
				$propia  = $item;
				continue;
			}
			$otras[] = $item;
		}

		usort($otras, function ($a, $b) {
			return strcasecmp(wp_strip_all_tags((string) $a[0]), wp_strip_all_tags((string) $b[0]));
		});

		$submenu[self::SLUG] = $propia ? array_merge([$propia], $otras) : $otras; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Es el menú propio, recién armado.

		self::fix_parent_capability($submenu[self::SLUG]);
	}

	/**
	 * Que el menú no desaparezca por una capacidad de más.
	 *
	 * Si el padre pide una capacidad que este usuario no tiene, WordPress
	 * esconde el menú entero aunque adentro haya pantallas que sí podría abrir.
	 * Acá se le pone la de la primera que pueda ver.
	 */
	private static function fix_parent_capability(array $paginas) {
		global $menu;
		if (!is_array($menu) || current_user_can(self::capability())) {
			return;
		}
		foreach ($paginas as $pagina) {
			if (isset($pagina[1]) && current_user_can($pagina[1])) {
				foreach ($menu as $index => $item) {
					if (isset($item[2]) && self::SLUG === $item[2]) {
						$menu[$index][1] = $pagina[1]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Es la entrada propia.
						return;
					}
				}
				return;
			}
		}
	}

	private static function index_title() {
		/**
		 * Cómo se llama la primera entrada del menú MJ.
		 *
		 * @param string $title
		 */
		return (string) apply_filters('mj_menu_index_title', 'Todo MJ');
	}

	/* ------------------------------ Portada ------------------------------ */

	/**
	 * La portada del menú: qué hay instalado y dónde se configura cada cosa.
	 *
	 * Es la respuesta a "¿dónde estaba esto?", que es justo el problema que el
	 * menú viene a resolver.
	 */
	public static function render() {
		if (!current_user_can(self::capability()) && !current_user_can('manage_options')) {
			wp_die('Sin permisos.');
		}
		global $submenu;
		$paginas = isset($submenu[self::SLUG]) && is_array($submenu[self::SLUG]) ? $submenu[self::SLUG] : [];
		$config  = self::config();

		echo '<div class="wrap">';
		printf('<h1>%s</h1>', esc_html($config['title']));
		echo '<p>' . esc_html('Todo lo que se configura de la familia MJ, en un solo lugar.') . '</p>';

		echo '<div class="mj-hub-cards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin:20px 0;max-width:1100px">';
		foreach ($paginas as $pagina) {
			if (!isset($pagina[2]) || self::SLUG === $pagina[2] || !current_user_can($pagina[1])) {
				continue;
			}
			printf(
				'<a href="%s" style="display:block;padding:16px 18px;border:1px solid #dcdcde;border-radius:8px;background:#fff;text-decoration:none;color:#1d2327"><strong>%s</strong></a>',
				esc_url(self::url($pagina[2])),
				esc_html(wp_strip_all_tags((string) $pagina[0]))
			);
		}
		echo '</div>';

		self::render_settings($config);
		echo '</div>';
	}

	/**
	 * El link de una pantalla.
	 *
	 * Un slug puede ser tres cosas: una página propia ('mjto'), una ruta de
	 * WordPress ('edit.php?post_type=…') o una dirección completa —así se suma
	 * al menú un link a la tienda, como el "Abrir el panel" de Turnos—.
	 */
	private static function url($slug) {
		$slug = (string) $slug;
		if (false !== strpos($slug, '://')) {
			return $slug;
		}
		if (false !== strpos($slug, '.php')) {
			return admin_url($slug);
		}
		return admin_url('admin.php?page=' . $slug);
	}

	/** Cómo se llama el menú, con qué ícono y dónde va. Nada de eso es fijo. */
	private static function render_settings(array $config) {
		if (!current_user_can('manage_options')) {
			return;
		}
		echo '<h2>' . esc_html('El menú') . '</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('mj_menu_save');
		echo '<input type="hidden" name="action" value="mj_menu_save">';
		echo '<table class="form-table">';
		printf(
			'<tr><th scope="row"><label for="mj-menu-title">%s</label></th><td><input type="text" id="mj-menu-title" name="title" value="%s" class="regular-text"></td></tr>',
			esc_html('Cómo se llama'),
			esc_attr($config['title'])
		);
		printf(
			'<tr><th scope="row"><label for="mj-menu-icon">%s</label></th><td><input type="text" id="mj-menu-icon" name="icon" value="%s" class="regular-text"><p class="description">%s</p></td></tr>',
			esc_html('Ícono'),
			esc_attr($config['icon']),
			esc_html('Un nombre de Dashicon (dashicons-screenoptions, dashicons-store…) o la URL de una imagen.')
		);
		printf(
			'<tr><th scope="row"><label for="mj-menu-position">%s</label></th><td><input type="number" id="mj-menu-position" name="position" value="%d" class="small-text"><p class="description">%s</p></td></tr>',
			esc_html('Posición'),
			(int) $config['position'],
			esc_html('Más chico, más arriba en la barra lateral. WooCommerce está en 56.')
		);
		echo '</table>';
		submit_button('Guardar');
		echo '</form>';
	}

	/* ------------------------------ Guardado ----------------------------- */

	public static function save() {
		check_admin_referer('mj_menu_save');
		if (!current_user_can('manage_options')) {
			wp_die('Sin permisos.');
		}
		$defaults = self::defaults();
		$title    = isset($_POST['title']) ? trim(sanitize_text_field(wp_unslash($_POST['title']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Lo verifica check_admin_referer() arriba.
		$icon     = isset($_POST['icon']) ? trim(sanitize_text_field(wp_unslash($_POST['icon']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Ídem.
		$position = isset($_POST['position']) ? absint(wp_unslash($_POST['position'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Ídem.

		update_option(self::OPTION, [
			'title'    => '' !== $title ? mb_substr($title, 0, 40) : $defaults['title'],
			'icon'     => '' !== $icon ? $icon : $defaults['icon'],
			'position' => $position > 0 ? min(300, $position) : $defaults['position'],
		]);

		wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&actualizado=1'));
		exit;
	}
}

add_action('admin_post_mj_menu_save', ['MJ_Menu', 'save']);

endif;
