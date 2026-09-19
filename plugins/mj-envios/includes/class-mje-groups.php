<?php
/**
 * Grupos: cómo se junta lo que el cliente ve en el checkout.
 *
 * Sin grupos, cada método es una fila suelta. Con un correo conectado eso se
 * vuelve una lista larga de opciones parecidas —"estándar", "sucursal",
 * "expreso"— que no le dicen nada a nadie. Un grupo junta varias abajo de un
 * solo título ("Envío a todo el país") y recién al elegirlo se abren las
 * tarjetas de quién lo lleva, con su plazo y su precio.
 *
 * El título del grupo no está escrito en el código: se crea desde el panel, o
 * lo crea solo la adopción de un correo usando **el nombre de la zona de envío
 * de WooCommerce** donde está ese correo. Después se renombra como cualquier
 * cosa. Si borrás un grupo, sus métodos vuelven a verse sueltos: no se pierde
 * ninguno.
 */

defined('ABSPATH') || exit;

class MJE_Groups {

	const OPTION = 'mje_groups';

	private static $cache = null;

	public static function defaults() {
		return [
			'slug'        => '',
			'title'       => '',
			'description' => '', // Una línea abajo del título en el checkout.
			'icon'        => '',
			'order'       => 0,
			'enabled'     => 1,
		];
	}

	/** Todos los grupos guardados, ordenados: slug => definición. */
	public static function all() {
		if (null !== self::$cache) {
			return self::$cache;
		}
		$saved       = get_option(self::OPTION, []);
		self::$cache = [];
		if (is_array($saved)) {
			foreach ($saved as $slug => $def) {
				$slug = sanitize_key($slug);
				if ('' === $slug || !is_array($def)) {
					continue;
				}
				$def                = wp_parse_args($def, self::defaults());
				$def['slug']        = $slug;
				self::$cache[$slug] = $def;
			}
		}
		uasort(self::$cache, function ($a, $b) {
			if ((int) $a['order'] === (int) $b['order']) {
				return strcasecmp((string) $a['title'], (string) $b['title']);
			}
			return (int) $a['order'] < (int) $b['order'] ? -1 : 1;
		});
		return self::$cache;
	}

	public static function enabled() {
		return array_filter(self::all(), function ($def) {
			return !empty($def['enabled']);
		});
	}

	public static function get($slug) {
		$all  = self::all();
		$slug = sanitize_key((string) $slug);
		return isset($all[$slug]) ? $all[$slug] : null;
	}

	/** slug => título, para los desplegables del panel. */
	public static function choices() {
		$out = [];
		foreach (self::all() as $slug => $def) {
			$out[$slug] = (string) $def['title'];
		}
		return $out;
	}

	public static function flush() {
		self::$cache = null;
	}

	/* ------------------------------ Guardado ----------------------------- */

	public static function sanitize($in) {
		$d   = self::defaults();
		$in  = is_array($in) ? $in : [];
		$out = $d;

		$out['slug']        = isset($in['slug']) ? sanitize_key($in['slug']) : '';
		$out['title']       = isset($in['title']) ? mb_substr(trim(sanitize_text_field($in['title'])), 0, 120) : '';
		$out['description'] = isset($in['description']) ? sanitize_textarea_field($in['description']) : '';
		$out['icon']        = isset($in['icon']) ? mb_substr(trim(sanitize_text_field($in['icon'])), 0, 60) : '';
		$out['order']       = isset($in['order']) ? max(0, min(9999, (int) $in['order'])) : 0;
		$out['enabled']     = empty($in['enabled']) ? 0 : 1;

		if ('' === $out['title']) {
			$out['title'] = 'Envíos';
		}
		return $out;
	}

	/** Guarda un grupo (lo crea si no existe). Devuelve el slug. */
	public static function save($slug, $def, $flush = true) {
		$all  = self::all();
		$def  = self::sanitize($def);
		$slug = sanitize_key('' !== $slug ? $slug : $def['slug']);
		if ('' === $slug) {
			$slug = self::unique_slug($def['title']);
		}
		$def['slug'] = $slug;
		$all[$slug]  = $def;
		self::store($all, $flush);
		return $slug;
	}

	/**
	 * Borra un grupo.
	 *
	 * Los métodos que estaban adentro quedan sueltos, nunca se borran: un grupo
	 * es una forma de mostrar, no un contenedor del que dependa nada.
	 */
	public static function delete($slug) {
		$all  = self::all();
		$slug = sanitize_key((string) $slug);
		if (!isset($all[$slug])) {
			return false;
		}
		unset($all[$slug]);
		self::store($all);

		foreach (MJE_Methods::all() as $method_slug => $def) {
			if ((string) $def['group'] === $slug) {
				$def['group'] = '';
				MJE_Methods::save($method_slug, $def);
			}
		}
		return true;
	}

	private static function store(array $all, $flush = true) {
		$clean = [];
		foreach ($all as $slug => $def) {
			$slug = sanitize_key($slug);
			if ('' !== $slug) {
				$def['slug']  = $slug;
				$clean[$slug] = $def;
			}
		}
		update_option(self::OPTION, $clean);
		self::$cache = null;
		if ($flush) {
			MJE_Settings::flush_caches();
		}
	}

	private static function unique_slug($title) {
		$base = sanitize_key(sanitize_title((string) $title));
		if ('' === $base) {
			$base = 'grupo';
		}
		$all  = self::all();
		$slug = $base;
		$i    = 2;
		while (isset($all[$slug])) {
			$slug = $base . '-' . $i;
			$i++;
		}
		return $slug;
	}

	/* ------------------------------ Adopción ----------------------------- */

	/**
	 * El grupo que corresponde a un título, creándolo si no existe.
	 *
	 * Es lo que usa la adopción de un correo con el nombre de su zona de envío:
	 * la primera vez crea el grupo "Envío a todo el país" (o como se llame esa
	 * zona), y de ahí en más lo reusa. Buscar por título comparable evita
	 * duplicar el grupo porque la zona cambió una mayúscula.
	 */
	public static function ensure($title, $flush = true) {
		$title = trim((string) $title);
		if ('' === $title) {
			return '';
		}
		$key = MJE_Settings::key_of($title);
		foreach (self::all() as $slug => $def) {
			if (MJE_Settings::key_of($def['title']) === $key) {
				return $slug;
			}
		}
		return self::save('', [
			'title'   => $title,
			'order'   => 50,
			'enabled' => 1,
		], $flush);
	}

	/** Los métodos de un grupo, en su orden. */
	public static function methods($slug) {
		$slug = sanitize_key((string) $slug);
		return array_filter(MJE_Methods::all(), function ($def) use ($slug) {
			return (string) $def['group'] === $slug;
		});
	}

	/* ------------------------------ Mudanza ------------------------------ */

	const OPTION_MIGRATED = 'mje_groups_migrated';

	public static function init() {
		add_action('woocommerce_init', [__CLASS__, 'migrate_natives']);
	}

	/**
	 * Junta en un grupo los métodos de correo que ya estaban adoptados sueltos.
	 *
	 * Corre una sola vez, para las tiendas que adoptaron correos antes de que
	 * existieran los grupos: sin esto, sus métodos seguirían apareciendo al mismo
	 * nivel que el retiro en el local hasta que alguien los moviera a mano. El
	 * nombre del grupo sale de la zona de envío donde está ese correo.
	 */
	public static function migrate_natives() {
		if (false !== get_option(self::OPTION_MIGRATED, false)) {
			return;
		}
		add_option(self::OPTION_MIGRATED, '1', '', false);

		if (!class_exists('MJE_Native') || !MJE_Native::any()) {
			return;
		}
		foreach (MJE_Methods::all() as $slug => $def) {
			if ('nativo' !== (string) $def['type'] || '' !== (string) $def['group']) {
				continue;
			}
			$plugin = MJE_Native::get((string) $def['native_plugin']);
			if (!$plugin) {
				continue;
			}
			$zone = '';
			$instances = (array) MJE_Native::ask($plugin->id() . ' · zonas', function () use ($plugin) {
				return $plugin->zone_instances();
			}, []);
			foreach ($instances as $instance) {
				if ($instance['enabled'] && '' !== $instance['zone']) {
					$zone = (string) $instance['zone'];
					break;
				}
			}
			if ('' === $zone) {
				continue;
			}
			$def['group'] = self::ensure($zone);
			MJE_Methods::save($slug, $def);
		}
	}

	/* --------------------------- Config pública -------------------------- */

	/** Lo que la tienda necesita para dibujar los grupos. */
	public static function public_payload() {
		$out = [];
		foreach (self::enabled() as $slug => $def) {
			$out[] = [
				'slug'        => $slug,
				'title'       => (string) $def['title'],
				'description' => (string) $def['description'],
				'icon'        => (string) $def['icon'],
			];
		}
		return $out;
	}
}
