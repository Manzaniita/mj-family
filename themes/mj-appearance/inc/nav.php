<?php
/**
 * Barra de navegación configurable (Apariencia → MJ Appearance → Barra de navegación).
 *
 * Antes el menú se armaba solo: la SPA juntaba los enlaces fijos del tema, las
 * categorías elegidas y lo que aportara cada plugin, siempre en el mismo orden
 * y con los mismos nombres. Servía, pero no había forma de sacar un enlace,
 * renombrarlo o mandarlo a otro lado sin tocar código.
 *
 * Acá vive el modelo que reemplaza eso:
 *
 *   - `mj_nav_sources()`  — QUÉ se puede poner en el menú: los enlaces del tema,
 *     cada categoría de producto, lo que declaren los plugins (filtro
 *     `mj_nav_sources`) y los enlaces sueltos que escriba el admin.
 *   - `mj_nav_rows()`     — CÓMO quedó configurado: orden, visibilidad, nombre,
 *     destino, a quién se le muestra y dónde (menú o botón del header).
 *   - `mj_nav_payload()`  — lo que finalmente ve la SPA (window.MJ.nav), ya
 *     filtrado por audiencia: si un ítem es solo para quien entra al ERP, al
 *     resto de los visitantes ni siquiera les llega.
 *
 * Sin nada configurado, `mj_nav_default_rows()` reproduce exactamente el menú
 * de siempre: actualizar el tema no cambia lo que ve el cliente.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Audiencias: a quién se le muestra cada ítem
 *
 * El tema solo sabe de sesión iniciada o no. Cualquier plugin puede sumar la
 * suya con el filtro `mj_nav_audiences` — MJ ERP agrega "quien puede entrar al
 * ERP", que es lo que hace que el acceso rápido aparezca únicamente para quien
 * tiene permiso y no para el cliente que está comprando.
 * ---------------------------------------------------------------------- */
function mj_nav_audiences() {
	$audiences = [
		'all'    => [
			'label' => 'Todo el mundo',
			'can'   => '__return_true',
		],
		'users'  => [
			'label' => 'Solo con la sesión iniciada',
			'can'   => 'is_user_logged_in',
		],
		'guests' => [
			'label' => 'Solo sin sesión iniciada',
			'can'   => function () {
				return !is_user_logged_in();
			},
		],
	];

	/**
	 * Audiencias disponibles para los ítems del menú.
	 *
	 * @param array $audiences key => [ 'label' => texto, 'can' => callable que devuelve bool ]
	 */
	$filtered = apply_filters('mj_nav_audiences', $audiences);
	return is_array($filtered) ? $filtered : $audiences;
}

/* ¿El visitante actual entra en esta audiencia? Una audiencia desconocida no oculta nada. */
function mj_nav_audience_allows($key) {
	$audiences = mj_nav_audiences();
	if (!isset($audiences[$key]['can'])) {
		return true;
	}
	$can = $audiences[$key]['can'];
	return is_callable($can) ? (bool) call_user_func($can) : true;
}

/* Dónde se dibuja el ítem. */
function mj_nav_placements() {
	return [
		'menu'   => 'En el menú',
		'header' => 'Botón en el header',
		'both'   => 'En los dos',
	];
}

/* -------------------------------------------------------------------------
 * Contexto
 *
 * Las etiquetas de los plugins (Turnos, Colegas) viajan dentro de window.MJ,
 * así que las fuentes del menú se leen de ahí. En el frontend el config ya está
 * armado cuando se calcula el menú (`mj_nav_prime()` lo deja a mano); en
 * wp-admin no hay ninguno, así que se arma uno para poder listar las opciones.
 * El guardia evita la recursión: mj_app_config() → mj_nav_payload() → contexto.
 * ---------------------------------------------------------------------- */
function mj_nav_prime(array $config) {
	mj_nav_context($config);
}

function mj_nav_context(?array $set = null) {
	static $context = null;
	static $building = false;

	if (null !== $set) {
		$context = $set;
		return $context;
	}
	if (is_array($context)) {
		return $context;
	}
	if ($building || !function_exists('mj_app_config')) {
		return [];
	}
	/*
	 * En wp-admin no hay ningún config armado, así que se arma uno para poder
	 * listar lo que aportan los plugins. Si algo de esa cadena falla (un plugin
	 * que no esperaba correr en el escritorio), el panel se dibuja igual con lo
	 * del tema: es una lista de opciones, no vale tirar abajo la página.
	 */
	$building = true;
	try {
		$context = (array) mj_app_config();
	} catch (Throwable $e) {
		$context = [];
	}
	$building = false;

	return $context;
}

/* -------------------------------------------------------------------------
 * Fuentes: todo lo que se puede poner en el menú
 *
 * Cada fuente es la PROPUESTA del tema o del plugin (nombre, destino, si viene
 * activada de fábrica). Lo que el admin decida se guarda aparte, en las filas.
 * ---------------------------------------------------------------------- */
function mj_nav_source_defaults() {
	return [
		'label'     => '',
		'type'      => 'link',   // link | category
		'href'      => '',
		'slug'      => '',       // solo type=category
		'group'     => 'Tienda', // encabezado en el panel de ajustes
		'highlight' => false,
		'audience'  => 'all',
		'placement' => 'menu',
		'icon'      => '',
		'target'    => '_self',
		'default'   => false,    // ¿viene en el menú por defecto?
		'custom'    => false,    // enlace libre: el destino se escribe a mano
		'note'      => '',       // aclaración en el panel
	];
}

function mj_nav_sources() {
	$copy    = function_exists('mj_copy') ? mj_copy() : [];
	$context = mj_nav_context();
	$label   = function ($key, $fallback) use ($copy) {
		$value = isset($copy[$key]) ? trim((string) $copy[$key]) : '';
		return '' !== $value ? $value : $fallback;
	};

	/*
	 * El orden de esta lista es el menú por defecto, y es el mismo de siempre:
	 * Inicio, Tienda, las categorías, Ofertas, Novedades, lo que traiga cada
	 * plugin, Marcas, Nosotros y Contacto. Quien nunca abra el panel no ve
	 * ningún cambio en su tienda.
	 */
	$sources = [
		'home' => ['label' => $label('nav_home', 'Inicio'), 'href' => '/', 'default' => true],
		'shop' => ['label' => $label('nav_shop', 'Tienda'), 'href' => '/tienda', 'default' => true],
	];

	/* Categorías de producto: cada una puede ir al menú con su panel de productos. */
	$chosen = [];
	if (function_exists('mj_get_options')) {
		$chosen = array_filter(explode(',', (string) mj_get_options()['menu_categories']));
	}
	/* Sin categorías elegidas a mano, el menú de siempre muestra las 4 con más productos. */
	$auto = 0;
	foreach (mj_nav_category_terms() as $term) {
		$default = $chosen
			? in_array($term->slug, $chosen, true)
			: $auto++ < 4;
		$sources['cat:' . $term->slug] = [
			'label'   => $term->name,
			'type'    => 'category',
			'slug'    => $term->slug,
			'href'    => '/categoria/' . $term->slug,
			'group'   => 'Categorías',
			'default' => $default,
			'note'    => 'Abre el panel con productos de la categoría.',
		];
	}

	$sources['sale'] = ['label' => $label('nav_sale', 'Ofertas'), 'href' => '/tienda?filtro=ofertas', 'highlight' => true, 'default' => true];
	$sources['new']  = ['label' => $label('nav_new', 'Novedades'), 'href' => '/tienda?filtro=novedades', 'default' => true];

	/* Plugin MJ Turnos Online: servicios y reserva (Turnos → Ajustes). */
	$turnos = isset($context['turnos']['menu']) ? $context['turnos']['menu'] : null;
	if (is_array($turnos)) {
		$slug          = isset($context['turnos']['slug']) ? (string) $context['turnos']['slug'] : 'turnos';
		$services_slug = isset($context['turnos']['servicesSlug']) ? (string) $context['turnos']['servicesSlug'] : 'servicios';
		if (!empty($turnos['servicesPageEnabled'])) {
			$sources['turnos-servicios'] = [
				'label'   => isset($turnos['servicesLabel']) ? (string) $turnos['servicesLabel'] : 'Servicios',
				'href'    => '/' . ltrim($services_slug, '/'),
				'group'   => 'MJ Turnos Online',
				'default' => !empty($turnos['servicesInMenu']),
			];
		}
		$sources['turnos'] = [
			'label'   => isset($turnos['label']) ? (string) $turnos['label'] : 'Turnos',
			'href'    => '/' . ltrim($slug, '/'),
			'group'   => 'MJ Turnos Online',
			'default' => !empty($turnos['showInMenu']),
		];
	}

	/* Plugin MJ Colegas: el proyecto colectivo. */
	$project = isset($context['colegas']['project']) ? $context['colegas']['project'] : null;
	if (is_array($project) && !empty($project['slug'])) {
		$sources['colegas'] = [
			'label'   => isset($project['menuLabel']) ? (string) $project['menuLabel'] : (string) $project['name'],
			'href'    => '/' . ltrim((string) $project['slug'], '/'),
			'group'   => 'MJ Colegas',
			'default' => !empty($project['showInMenu']),
		];
	}

	$brand_plural = function_exists('mj_brand_labels') ? mj_brand_labels()['plural'] : 'Marcas';
	$sources['brands']  = ['label' => $brand_plural, 'href' => '/marcas', 'default' => true];
	$sources['about']   = [
		'label'   => $label('nav_about', 'Nosotros'),
		'href'    => '/nosotros',
		'default' => '' !== trim((string) (isset($copy['nav_about']) ? $copy['nav_about'] : '')),
	];
	$sources['contact'] = ['label' => $label('nav_contact', 'Contacto'), 'href' => '/contacto', 'default' => true];

	/**
	 * Ítems que aportan los plugins.
	 *
	 * Cada uno con la misma forma que los del tema; lo mínimo es `label` y `href`.
	 * MJ ERP agrega acá su acceso rápido, con audiencia "erp" para que solo lo vea
	 * quien puede entrar.
	 *
	 * @param array $sources key => fuente
	 */
	$sources = apply_filters('mj_nav_sources', $sources);
	if (!is_array($sources)) {
		$sources = [];
	}

	$defaults = mj_nav_source_defaults();
	$out      = [];
	foreach ($sources as $key => $source) {
		$key = mj_nav_clean_key($key);
		if ('' === $key || !is_array($source)) {
			continue;
		}
		$source = array_merge($defaults, $source);
		if ('' === trim((string) $source['label'])) {
			continue;
		}
		if ('category' !== $source['type']) {
			$source['type'] = 'link';
		}
		$out[$key] = $source;
	}

	return $out;
}

/* Categorías de producto para el panel (sin la de "sin categorizar"). */
function mj_nav_category_terms() {
	if (!taxonomy_exists('product_cat')) {
		return [];
	}
	$terms = get_terms([
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
		'orderby'    => 'count',
		'order'      => 'DESC',
	]);
	if (is_wp_error($terms) || !is_array($terms)) {
		return [];
	}
	return array_values(array_filter($terms, function ($term) {
		return !in_array($term->slug, ['uncategorized', 'sin-categorizar'], true);
	}));
}

/*
 * Clave de un ítem. Se aceptan los dos puntos porque las categorías se guardan
 * como "cat:slug" y sanitize_key() los borraría, dejando todas las categorías
 * pisándose bajo la misma clave.
 */
function mj_nav_clean_key($key) {
	$key = strtolower(trim((string) $key));
	$key = preg_replace('/[^a-z0-9_:\-]/', '', $key);
	return (string) $key;
}

/* -------------------------------------------------------------------------
 * Filas: la configuración guardada
 * ---------------------------------------------------------------------- */
function mj_nav_row_defaults() {
	return [
		'key'       => '',
		'label'     => '',   // vacío = el nombre que propone la fuente
		'href'      => '',   // solo enlaces libres
		'visible'   => 1,
		'highlight' => '',  // vacío = lo que proponga la fuente
		'placement' => '',   // vacío = lo que proponga la fuente
		'audience'  => '',   // vacío = lo que proponga la fuente
		'target'    => '',   // vacío = _self
		'icon'      => '',
		'custom'    => 0,
		'custom_label' => '', // nombre del enlace libre (no hay fuente que lo aporte)
	];
}

/* El menú de siempre: qué ítems trae y en qué orden, cuando no hay nada guardado. */
function mj_nav_default_rows(array $sources) {
	$rows = [];
	foreach ($sources as $key => $source) {
		$rows[] = array_merge(mj_nav_row_defaults(), [
			'key'     => $key,
			'visible' => !empty($source['default']) ? 1 : 0,
		]);
	}
	return $rows;
}

/**
 * Filas efectivas: lo guardado, más las fuentes nuevas que todavía no estaban.
 *
 * Una categoría recién creada o un plugin recién activado aparecen solos al
 * final con la visibilidad que propongan, igual que antes de este panel. Las
 * filas que apuntan a una fuente que ya no existe (categoría borrada, plugin
 * desactivado) se descartan.
 */
function mj_nav_rows(?array $sources = null) {
	if (null === $sources) {
		$sources = mj_nav_sources();
	}
	$saved = function_exists('mj_get_options') ? mj_get_options()['nav_items'] : [];
	$saved = is_array($saved) ? $saved : [];

	if (!$saved) {
		return mj_nav_default_rows($sources);
	}

	$defaults = mj_nav_row_defaults();
	$rows     = [];
	$seen     = [];
	foreach ($saved as $row) {
		if (!is_array($row)) {
			continue;
		}
		$row = array_merge($defaults, $row);
		$key = mj_nav_clean_key($row['key']);
		if ('' === $key || isset($seen[$key])) {
			continue;
		}
		if (!isset($sources[$key]) && empty($row['custom'])) {
			continue;
		}
		$row['key']  = $key;
		$seen[$key]  = true;
		$rows[]      = $row;
	}

	foreach ($sources as $key => $source) {
		if (isset($seen[$key])) {
			continue;
		}
		$rows[] = array_merge($defaults, [
			'key'     => $key,
			'visible' => !empty($source['default']) ? 1 : 0,
		]);
	}

	return $rows;
}

/* Fuente de una fila: la declarada, o la del propio enlace libre. */
function mj_nav_row_source(array $row, array $sources) {
	if (isset($sources[$row['key']])) {
		return $sources[$row['key']];
	}
	return array_merge(mj_nav_source_defaults(), [
		'label'  => (string) $row['custom_label'],
		'href'   => (string) $row['href'],
		'group'  => 'Enlaces propios',
		'custom' => true,
	]);
}

/* -------------------------------------------------------------------------
 * Lo que ve la SPA (window.MJ.nav)
 * ---------------------------------------------------------------------- */
function mj_nav_payload() {
	$sources = mj_nav_sources();
	$items   = [];

	foreach (mj_nav_rows($sources) as $row) {
		if (empty($row['visible'])) {
			continue;
		}
		$source = mj_nav_row_source($row, $sources);

		$audience = '' !== $row['audience'] ? $row['audience'] : $source['audience'];
		if (!mj_nav_audience_allows($audience)) {
			continue;
		}

		$label = '' !== trim((string) $row['label']) ? trim((string) $row['label']) : (string) $source['label'];
		$href  = !empty($source['custom']) || '' !== trim((string) $row['href'])
			? trim((string) $row['href'])
			: (string) $source['href'];
		if ('' === $label || ('category' !== $source['type'] && '' === $href)) {
			continue;
		}

		$placement = '' !== $row['placement'] ? $row['placement'] : $source['placement'];
		if (!isset(mj_nav_placements()[$placement])) {
			$placement = 'menu';
		}

		$items[] = [
			'key'       => $row['key'],
			'type'      => $source['type'],
			'label'     => $label,
			'href'      => $href,
			'slug'      => (string) $source['slug'],
			'highlight' => '' !== (string) $row['highlight'] ? (bool) $row['highlight'] : (bool) $source['highlight'],
			'placement' => $placement,
			'target'    => '_blank' === $row['target'] ? '_blank' : ('_blank' === $source['target'] ? '_blank' : '_self'),
			'icon'      => '' !== trim((string) $row['icon']) ? trim((string) $row['icon']) : (string) $source['icon'],
		];
	}

	/**
	 * Ítems finales del menú, ya filtrados por audiencia.
	 *
	 * @param array $items
	 */
	$items = apply_filters('mj_nav_items', $items);

	return ['items' => is_array($items) ? array_values($items) : []];
}

/* -------------------------------------------------------------------------
 * Guardado (lo llama mj_sanitize_options)
 *
 * El orden llega en un CSV de claves que arma el JS al arrastrar, igual que el
 * de las secciones de la portada: sin él, el orden dependería de cómo el
 * navegador serialice el formulario.
 * ---------------------------------------------------------------------- */
function mj_nav_sanitize($input) {
	$rows_in = isset($input['nav']) && is_array($input['nav']) ? $input['nav'] : [];
	if (!$rows_in) {
		return [];
	}

	$order = isset($input['nav_order']) ? array_filter(array_map('mj_nav_clean_key', explode(',', (string) $input['nav_order']))) : [];
	$keys  = array_keys($rows_in);
	$keys  = array_values(array_unique(array_merge(
		array_values(array_intersect($order, array_map('mj_nav_clean_key', $keys))),
		array_map('mj_nav_clean_key', $keys)
	)));

	$placements = mj_nav_placements();
	$audiences  = mj_nav_audiences();
	$defaults   = mj_nav_row_defaults();
	$out        = [];

	foreach ($keys as $key) {
		$raw = null;
		foreach ($rows_in as $raw_key => $value) {
			if (mj_nav_clean_key($raw_key) === $key && is_array($value)) {
				$raw = $value;
				break;
			}
		}
		if (null === $raw || '' === $key) {
			continue;
		}

		$custom = !empty($raw['custom']);
		$row    = [
			'key'          => $key,
			'label'        => sanitize_text_field((string) (isset($raw['label']) ? $raw['label'] : '')),
			'href'         => mj_nav_sanitize_href(isset($raw['href']) ? $raw['href'] : ''),
			'visible'      => !empty($raw['visible']) ? 1 : 0,
			'highlight'    => !empty($raw['highlight']) ? 1 : 0,
			'placement'    => isset($raw['placement'], $placements[$raw['placement']]) ? (string) $raw['placement'] : '',
			'audience'     => isset($raw['audience'], $audiences[$raw['audience']]) ? (string) $raw['audience'] : '',
			'target'       => isset($raw['target']) && '_blank' === $raw['target'] ? '_blank' : '_self',
			'icon'         => sanitize_key((string) (isset($raw['icon']) ? $raw['icon'] : '')),
			'custom'       => $custom ? 1 : 0,
			'custom_label' => sanitize_text_field((string) (isset($raw['custom_label']) ? $raw['custom_label'] : '')),
		];

		/* Un enlace libre sin nombre ni destino es una fila vacía: se descarta. */
		if ($custom && ('' === trim($row['custom_label']) || '' === $row['href'])) {
			continue;
		}

		$out[] = array_merge($defaults, $row);
	}

	return $out;
}

/*
 * Destino de un enlace: una ruta de la tienda ("/tienda?filtro=ofertas") o una
 * URL completa. esc_url_raw() solo se usa con las segundas: aplicado a una ruta
 * relativa con query la deja igual, pero a una URL externa le agrega el esquema
 * y valida el protocolo, que es lo que importa.
 */
function mj_nav_sanitize_href($value) {
	$value = trim((string) $value);
	if ('' === $value) {
		return '';
	}
	if (preg_match('#^(?:https?:)?//#i', $value) || preg_match('#^(?:mailto|tel):#i', $value)) {
		return esc_url_raw($value);
	}
	if ('/' !== $value[0] && '#' !== $value[0]) {
		$value = '/' . $value;
	}
	return sanitize_text_field($value);
}

/* -------------------------------------------------------------------------
 * Panel de ajustes
 * ---------------------------------------------------------------------- */
function mj_render_nav_settings() {
	$sources    = mj_nav_sources();
	$rows       = mj_nav_rows($sources);
	$placements = mj_nav_placements();
	$audiences  = mj_nav_audiences();
	?>
	<h2>Barra de navegación</h2>
	<p>
		Lo que ve el cliente arriba de la tienda. Arrastrá para cambiar el orden, destildá lo que no quieras mostrar y
		escribí el nombre que prefieras (vacío = el que se ve en gris). "Agregar enlace" sirve para mandar a cualquier
		página, tuya o de afuera.
	</p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Ítems del menú</th>
			<td>
				<ul id="mj-nav-items" class="mj-nav-items" style="margin:0; max-width:760px;">
					<?php foreach ($rows as $row) :
						mj_render_nav_row($row, mj_nav_row_source($row, $sources), $placements, $audiences);
					endforeach; ?>
				</ul>
				<input type="hidden" id="mj_nav_order" name="mj_options[nav_order]" value="<?php echo esc_attr(implode(',', wp_list_pluck($rows, 'key'))); ?>" />
				<p style="margin-top:10px;">
					<button type="button" class="button mj-nav-add">Agregar enlace</button>
				</p>
				<p class="description">
					"Botón en el header" lo saca del menú y lo pone al lado del carrito, con su ícono
					(<a href="https://lucide.dev/icons" target="_blank" rel="noreferrer">lucide.dev/icons</a>).
					La audiencia decide quién lo ve: lo que es solo para vos no se le manda al navegador del cliente.
				</p>
			</td>
		</tr>
	</table>

	<?php /* Molde de los enlaces propios: el JS lo clona con una clave nueva. */ ?>
	<script type="text/template" id="mj-nav-template">
		<?php
		mj_render_nav_row(
			array_merge(mj_nav_row_defaults(), ['key' => '__KEY__', 'custom' => 1, 'visible' => 1]),
			array_merge(mj_nav_source_defaults(), ['group' => 'Enlaces propios', 'custom' => true]),
			$placements,
			$audiences
		);
		?>
	</script>
	<?php
}

/* Una fila del panel. */
function mj_render_nav_row(array $row, array $source, array $placements, array $audiences) {
	$key       = (string) $row['key'];
	$name      = 'mj_options[nav][' . $key . ']';
	$custom    = !empty($source['custom']);
	$placement = '' !== $row['placement'] ? $row['placement'] : $source['placement'];
	$audience  = '' !== $row['audience'] ? $row['audience'] : $source['audience'];
	$highlight = '' !== (string) $row['highlight'] ? (bool) $row['highlight'] : (bool) $source['highlight'];
	?>
	<li class="mj-nav-item" data-key="<?php echo esc_attr($key); ?>" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px; margin:0 0 8px; padding:10px 12px; border:1px solid #c3c4c7; border-radius:6px; background:#fff;">
		<span class="mj-nav-handle dashicons dashicons-menu" title="Arrastrá para cambiar el orden" style="cursor:move; color:#787c82;"></span>

		<label style="display:flex; align-items:center; gap:6px; margin:0;" title="Mostrar este ítem">
			<input type="hidden" name="<?php echo esc_attr($name); ?>[visible]" value="0" />
			<input type="checkbox" name="<?php echo esc_attr($name); ?>[visible]" value="1" <?php checked((int) $row['visible'], 1); ?> />
			<span class="screen-reader-text">Mostrar</span>
		</label>

		<input type="hidden" name="<?php echo esc_attr($name); ?>[custom]" value="<?php echo $custom ? '1' : '0'; ?>" />

		<?php if ($custom) : ?>
			<input type="text" name="<?php echo esc_attr($name); ?>[custom_label]" value="<?php echo esc_attr($row['custom_label']); ?>" class="regular-text" style="flex:1 1 180px; min-width:160px;" placeholder="Nombre del enlace" aria-label="Nombre del enlace" />
			<input type="text" name="<?php echo esc_attr($name); ?>[href]" value="<?php echo esc_attr($row['href']); ?>" class="regular-text" style="flex:1 1 200px; min-width:180px;" placeholder="/mi-pagina o https://…" aria-label="Destino" />
		<?php else : ?>
			<input type="text" name="<?php echo esc_attr($name); ?>[label]" value="<?php echo esc_attr($row['label']); ?>" class="regular-text" style="flex:1 1 180px; min-width:160px;" placeholder="<?php echo esc_attr($source['label']); ?>" aria-label="Nombre en el menú" />
			<?php if ('category' === $source['type']) : ?>
				<span class="mj-nav-dest description" style="flex:1 1 200px; min-width:180px;">Categoría · <code><?php echo esc_html($source['slug']); ?></code></span>
			<?php else : ?>
				<input type="text" name="<?php echo esc_attr($name); ?>[href]" value="<?php echo esc_attr($row['href']); ?>" class="regular-text" style="flex:1 1 200px; min-width:180px;" placeholder="<?php echo esc_attr($source['href']); ?>" aria-label="Destino" />
			<?php endif; ?>
		<?php endif; ?>

		<select name="<?php echo esc_attr($name); ?>[placement]" aria-label="Dónde se muestra" style="max-width:170px;">
			<?php foreach ($placements as $value => $text) : ?>
				<option value="<?php echo esc_attr($value); ?>" <?php selected($placement, $value); ?>><?php echo esc_html($text); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="<?php echo esc_attr($name); ?>[audience]" aria-label="Quién lo ve" style="max-width:210px;">
			<?php foreach ($audiences as $value => $audience_def) : ?>
				<option value="<?php echo esc_attr($value); ?>" <?php selected($audience, $value); ?>><?php echo esc_html(isset($audience_def['label']) ? $audience_def['label'] : $value); ?></option>
			<?php endforeach; ?>
		</select>

		<input type="text" name="<?php echo esc_attr($name); ?>[icon]" value="<?php echo esc_attr('' !== $row['icon'] ? $row['icon'] : $source['icon']); ?>" style="width:110px;" placeholder="ícono" aria-label="Ícono (nombre de Lucide)" />

		<label style="display:flex; align-items:center; gap:6px; margin:0; white-space:nowrap;">
			<input type="hidden" name="<?php echo esc_attr($name); ?>[highlight]" value="0" />
			<input type="checkbox" name="<?php echo esc_attr($name); ?>[highlight]" value="1" <?php checked($highlight, true); ?> />
			Destacado
		</label>

		<label style="display:flex; align-items:center; gap:6px; margin:0; white-space:nowrap;">
			<input type="hidden" name="<?php echo esc_attr($name); ?>[target]" value="_self" />
			<input type="checkbox" name="<?php echo esc_attr($name); ?>[target]" value="_blank" <?php checked('_blank', '' !== $row['target'] ? $row['target'] : $source['target']); ?> />
			Nueva pestaña
		</label>

		<?php if ($custom) : ?>
			<button type="button" class="button-link mj-nav-remove" style="color:#d63638;" aria-label="Quitar este enlace">Quitar</button>
		<?php endif; ?>

		<?php if ('' !== trim((string) $source['group']) || '' !== trim((string) $source['note'])) : ?>
			<span class="description" style="flex-basis:100%; margin:0;">
				<?php echo esc_html(trim($source['group'] . ('' !== trim((string) $source['note']) ? ' — ' . $source['note'] : ''))); ?>
			</span>
		<?php endif; ?>
	</li>
	<?php
}
