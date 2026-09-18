<?php
/**
 * Plugin Name: MJ Fotos Rápidas
 * Description: Cargá las fotos de tus productos desde el celular en dos toques y publicalos ahí mismo. Sin FTP, sin renombrar archivos, sin carpetas. Achica las imágenes solo antes de subirlas.
 * Version: 1.3.0
 * Author: MJ Importaciones
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: mj-fotos-rapidas
 *
 * v1.3: constante de versión, uninstall.php, los ajustes globales los cambia
 * solo quien administra la tienda, lo subido tiene que ser una imagen, y el
 * buscador busca también por SKU (antes lo prometía y buscaba solo por nombre).
 * v1.2: el archivo principal ya no lleva la versión en el nombre (así el zip
 * nuevo actualiza el plugin en lugar de instalar uno distinto al lado), y el
 * tamaño y la calidad con que se achican las fotos son configurables.
 * v1.1: botón "Publicar" por producto y publicación en lote de todos los
 * borradores que ya tengan fotos.
 */

defined('ABSPATH') || exit;

define('MJFR_VERSION', '1.3.0');

class MJ_Fotos_Rapidas {

    const SLUG    = 'mj-fotos-rapidas';
    const NONCE   = 'mj_fotos_rapidas';

    /**
     * Quién puede cambiar los ajustes globales (tamaño y calidad del achique).
     * Subir fotos lo hace cualquiera que edite productos; cambiar cómo se
     * achican para todos, solo quien administra la tienda.
     */
    public static function capacidad_ajustes() {
        return (string) apply_filters('mj_fotos_rapidas_capacidad_ajustes', 'manage_woocommerce');
    }

    /* Valores por defecto del achique previo a la subida (ver ajustes()). */
    const MAX_PX  = 1600;
    const CALIDAD = 0.82;
    const OPCION  = 'mj_fotos_rapidas';

    /**
     * Tamaño y calidad con que el celular achica cada foto ANTES de subirla.
     * Salen de la opción del plugin; se pueden cambiar sin tocar el código
     * (o con el filtro `mj_fotos_rapidas_ajustes`).
     *
     * @return array{max_px:int, calidad:float}
     */
    public static function ajustes() {
        $guardado = get_option(self::OPCION, array());
        if (!is_array($guardado)) {
            $guardado = array();
        }
        $max_px  = isset($guardado['max_px']) ? (int) $guardado['max_px'] : self::MAX_PX;
        $calidad = isset($guardado['calidad']) ? (float) $guardado['calidad'] : self::CALIDAD;

        /* Rangos con sentido: nada de fotos de 2 px ni calidad 0. */
        $ajustes = array(
            'max_px'  => max(320, min(4096, $max_px)),
            'calidad' => max(0.3, min(1.0, $calidad)),
        );
        return (array) apply_filters('mj_fotos_rapidas_ajustes', $ajustes);
    }

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('wp_ajax_mj_fotos_subir',    array($this, 'ajax_subir'));
        add_action('wp_ajax_mj_fotos_quitar',   array($this, 'ajax_quitar'));
        add_action('wp_ajax_mj_fotos_publicar', array($this, 'ajax_publicar'));
        add_action('admin_post_mj_fotos_ajustes', array($this, 'guardar_ajustes'));
    }

    /** Guarda el tamaño y la calidad del achique (formulario de la pantalla). */
    public function guardar_ajustes() {
        if (!current_user_can(self::capacidad_ajustes())) {
            wp_die('Sin permisos.');
        }
        check_admin_referer(self::NONCE . '_ajustes');

        update_option(self::OPCION, array(
            'max_px'  => isset($_POST['max_px']) ? absint($_POST['max_px']) : self::MAX_PX,
            'calidad' => isset($_POST['calidad']) ? (float) $_POST['calidad'] / 100 : self::CALIDAD,
        ));

        wp_safe_redirect(add_query_arg(
            array('post_type' => 'product', 'page' => self::SLUG, 'guardado' => '1'),
            admin_url('edit.php')
        ));
        exit;
    }

    /* ------------------------------------------------------------------ */

    public function menu() {
        add_submenu_page(
            'edit.php?post_type=product',
            'Fotos rápidas',
            '📷 Fotos rápidas',
            'edit_products',
            self::SLUG,
            array($this, 'pantalla')
        );
    }

    /**
     * Cuántos productos muestra la pantalla. Se cambia con el filtro
     * `mj_fotos_rapidas_por_pagina`.
     */
    private function por_pagina() {
        return max(1, min(200, (int) apply_filters('mj_fotos_rapidas_por_pagina', 40)));
    }

    private function productos($filtro, $busqueda) {
        $args = array(
            'post_type'      => 'product',
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => $this->por_pagina(),
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        /*
         * "Buscar por nombre o SKU": get_posts con `s` busca solo en el título y
         * el texto. El SKU se busca aparte en la tabla de búsqueda de WooCommerce
         * y los dos resultados se juntan en un solo listado.
         */
        if ($busqueda !== '') {
            $args['s'] = $busqueda;
            $por_sku = $this->ids_por_sku($busqueda);
            if ($por_sku) {
                $por_nombre = get_posts(array_merge($args, array('fields' => 'ids')));
                unset($args['s']);
                $args['post__in'] = array_values(array_unique(array_merge($por_sku, (array) $por_nombre)));
            }
        }

        if ($filtro === 'sin') {
            $args['meta_query'] = array(
                array('key' => '_thumbnail_id', 'compare' => 'NOT EXISTS')
            );
        } elseif ($filtro === 'borradores') {
            $args['post_status'] = array('draft', 'pending');
        }
        return get_posts($args);
    }

    /**
     * IDs de productos y variaciones cuyo SKU contiene el texto, con el padre de
     * cada variación (la pantalla muestra productos). Lee wc_product_meta_lookup,
     * que es donde WooCommerce indexa el SKU.
     *
     * @return int[]
     */
    private function ids_por_sku($busqueda) {
        global $wpdb;
        $tabla = $wpdb->prefix . 'wc_product_meta_lookup';
        $like  = '%' . $wpdb->esc_like($busqueda) . '%';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombres de tabla de WordPress y WooCommerce, no del usuario.
        $filas = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT l.product_id, p.post_parent FROM {$tabla} l INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
                 WHERE l.sku LIKE %s AND p.post_type IN ('product', 'product_variation') LIMIT %d",
                $like,
                $this->por_pagina()
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = array();
        foreach ((array) $filas as $fila) {
            $ids[] = (int) $fila['post_parent'] > 0 ? (int) $fila['post_parent'] : (int) $fila['product_id'];
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private function fotos_de($product_id) {
        $ids = array();
        $destacada = get_post_thumbnail_id($product_id);
        if ($destacada) $ids[] = (int) $destacada;

        $galeria = get_post_meta($product_id, '_product_image_gallery', true);
        if ($galeria) {
            foreach (explode(',', $galeria) as $g) {
                $g = (int) trim($g);
                if ($g) $ids[] = $g;
            }
        }
        return $ids;
    }

    /* ------------------------------------------------------------------ */

    public function pantalla() {
        if (!current_user_can('edit_products')) wp_die('Sin permisos.');

        $filtro    = isset($_GET['filtro']) ? sanitize_key($_GET['filtro']) : 'sin';
        $busqueda  = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $productos = $this->productos($filtro, $busqueda);
        $puede_publicar = current_user_can('publish_products');
        ?>
        <div class="wrap mjfr">

            <h1 class="mjfr-titulo">Fotos rápidas</h1>
            <p class="mjfr-bajada">Elegí las fotos desde la galería del celular. Se achican y se suben solas: la primera queda como imagen principal y el resto va a la galería. Cuando el producto está listo, lo publicás desde acá.</p>

            <?php if (!empty($_GET['guardado'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Ajustes guardados.</p></div>
            <?php endif; ?>

            <?php $mjfr_ajustes = self::ajustes(); ?>
            <?php if (current_user_can(self::capacidad_ajustes())) : ?>
            <details style="margin:0 0 16px;">
                <summary style="cursor:pointer;">Ajustes de las fotos</summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; margin-top:10px;">
                    <input type="hidden" name="action" value="mj_fotos_ajustes">
                    <?php wp_nonce_field(self::NONCE . '_ajustes'); ?>
                    <label>Lado más largo (px)<br>
                        <input type="number" name="max_px" value="<?php echo esc_attr((string) $mjfr_ajustes['max_px']); ?>" min="320" max="4096" step="10">
                    </label>
                    <label>Calidad (%)<br>
                        <input type="number" name="calidad" value="<?php echo esc_attr((string) round($mjfr_ajustes['calidad'] * 100)); ?>" min="30" max="100" step="1">
                    </label>
                    <button type="submit" class="button">Guardar</button>
                    <span class="description">Más tamaño y más calidad = fotos más lindas pero más pesadas y más lentas de subir desde el celular.</span>
                </form>
            </details>
            <?php endif; ?>

            <form method="get" class="mjfr-barra">
                <input type="hidden" name="post_type" value="product">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <input type="search" name="q" value="<?php echo esc_attr($busqueda); ?>" placeholder="Buscar por nombre o SKU" class="mjfr-buscar">
                <select name="filtro" class="mjfr-select">
                    <option value="sin"        <?php selected($filtro, 'sin'); ?>>Sin imagen principal</option>
                    <option value="borradores" <?php selected($filtro, 'borradores'); ?>>Borradores</option>
                    <option value="todos"      <?php selected($filtro, 'todos'); ?>>Todos los productos</option>
                </select>
                <button class="button button-primary">Buscar</button>
            </form>

            <?php if ($puede_publicar && !empty($productos)) : ?>
                <div class="mjfr-lote">
                    <button type="button" class="button" id="mjfr-publicar-lote">Publicar los que ya tienen fotos</button>
                    <span class="mjfr-lote-aviso"></span>
                </div>
            <?php endif; ?>

            <?php if (empty($productos)) : ?>
                <div class="mjfr-vacio">
                    <?php if ($filtro === 'sin' && $busqueda === '') : ?>
                        Todos los productos tienen imagen principal. Pasá a “Borradores” para publicar los que quedaron pendientes.
                    <?php elseif ($filtro === 'borradores' && $busqueda === '') : ?>
                        No quedan borradores. Está todo publicado.
                    <?php else : ?>
                        No hay productos que coincidan. Probá con el SKU completo o cambiá el filtro.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php foreach ($productos as $p) :
                $producto  = wc_get_product($p->ID);
                $sku       = $producto ? $producto->get_sku() : '';
                $fotos     = $this->fotos_de($p->ID);
                $publicado = ($p->post_status === 'publish');
            ?>
            <div class="mjfr-item<?php echo $publicado ? ' es-publicado' : ''; ?>"
                 data-id="<?php echo (int) $p->ID; ?>"
                 data-estado="<?php echo esc_attr($p->post_status); ?>">

                <div class="mjfr-cabecera">
                    <div class="mjfr-datos">
                        <a class="mjfr-nombre" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>"><?php echo esc_html($p->post_title); ?></a>
                        <div class="mjfr-meta">
                            <span class="mjfr-sku"><?php echo $sku ? esc_html($sku) : 'sin SKU'; ?></span>
                            <span class="mjfr-estado"><?php echo $publicado ? 'Publicado' : 'Borrador'; ?></span>
                        </div>
                    </div>
                    <div class="mjfr-contador"><?php echo count($fotos); ?></div>
                </div>

                <div class="mjfr-tiras">
                    <?php foreach ($fotos as $i => $att) :
                        $src = wp_get_attachment_image_url($att, 'thumbnail'); ?>
                        <figure class="mjfr-foto<?php echo $i === 0 ? ' es-principal' : ''; ?>" data-att="<?php echo (int) $att; ?>">
                            <img src="<?php echo esc_url($src); ?>" alt="">
                            <button type="button" class="mjfr-quitar" title="Quitar del producto">×</button>
                        </figure>
                    <?php endforeach; ?>
                </div>

                <div class="mjfr-acciones">
                    <label class="mjfr-boton">
                        <input type="file" accept="image/*" multiple hidden>
                        <span>Agregar fotos</span>
                    </label>
                    <?php if ($puede_publicar) : ?>
                        <button type="button" class="mjfr-publicar"<?php echo $publicado ? ' hidden' : ''; ?>>Publicar</button>
                    <?php endif; ?>
                </div>

                <div class="mjfr-progreso" hidden><div class="mjfr-barra-int"></div></div>
                <div class="mjfr-aviso" hidden></div>
            </div>
            <?php endforeach; ?>

            <p class="mjfr-credito">Creado por MJ Importaciones</p>
        </div>

        <style>
        .mjfr { max-width: 720px; }
        .mjfr-titulo { margin-bottom: 4px; }
        .mjfr-bajada { color:#50575e; max-width: 62ch; margin-top:0; }

        .mjfr-barra { display:flex; gap:8px; margin:18px 0 14px; flex-wrap:wrap; }
        .mjfr-buscar { flex:1 1 200px; min-height:38px; }
        .mjfr-select, .mjfr-barra .button { min-height:38px; }

        .mjfr-lote { display:flex; align-items:center; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
        .mjfr-lote .button { min-height:36px; }
        .mjfr-lote-aviso { font-size:13px; color:#50575e; }

        .mjfr-vacio { background:#fff; border:1px solid #dcdcde; border-left:4px solid #2271b1;
                      padding:16px 18px; color:#50575e; max-width:62ch; }

        .mjfr-item { background:#fff; border:1px solid #dcdcde; border-radius:6px;
                     padding:14px; margin-bottom:14px; }
        .mjfr-item.es-publicado { border-left:4px solid #00a32a; }

        .mjfr-cabecera { display:flex; align-items:flex-start; gap:12px; }
        .mjfr-datos { flex:1; min-width:0; }
        .mjfr-nombre { display:block; font-size:15px; font-weight:600; line-height:1.35;
                       text-decoration:none; color:#1d2327; }
        .mjfr-meta { display:flex; gap:10px; align-items:center; margin-top:3px; font-size:12px; }
        .mjfr-sku { color:#787c82; }
        .mjfr-estado { color:#996800; }
        .es-publicado .mjfr-estado { color:#00733a; }

        .mjfr-contador { flex:0 0 auto; min-width:28px; height:28px; border-radius:14px;
                         background:#f0f0f1; color:#50575e; font-size:13px; font-weight:600;
                         display:flex; align-items:center; justify-content:center; padding:0 8px; }
        .mjfr-contador.tiene { background:#2271b1; color:#fff; }

        .mjfr-tiras { display:flex; gap:8px; overflow-x:auto; padding:12px 0 4px; }
        .mjfr-tiras:empty { display:none; }
        .mjfr-foto { position:relative; flex:0 0 auto; width:72px; height:72px; margin:0;
                     border-radius:4px; overflow:hidden; background:#f6f7f7; }
        .mjfr-foto img { width:100%; height:100%; object-fit:cover; display:block; }
        .mjfr-foto.es-principal { outline:2px solid #2271b1; outline-offset:-2px; }
        .mjfr-quitar { position:absolute; top:2px; right:2px; width:20px; height:20px;
                       border:0; border-radius:10px; background:rgba(0,0,0,.62); color:#fff;
                       font-size:14px; line-height:1; cursor:pointer; padding:0; }
        .mjfr-quitar:focus-visible { outline:2px solid #fff; }

        .mjfr-acciones { display:flex; gap:8px; margin-top:10px; }
        .mjfr-boton { flex:1; text-align:center; cursor:pointer; border:1px dashed #8c8f94;
                      border-radius:5px; padding:14px; color:#2271b1; font-weight:600;
                      font-size:15px; background:#fbfbfc; }
        .mjfr-boton:active { background:#f0f6fc; }
        .mjfr-boton:focus-within { border-color:#2271b1; outline:2px solid #2271b1; outline-offset:2px; }

        .mjfr-publicar { flex:0 0 auto; border:1px solid #00733a; border-radius:5px;
                         background:#00a32a; color:#fff; font-weight:600; font-size:15px;
                         padding:0 22px; cursor:pointer; }
        .mjfr-publicar:hover { background:#00893a; }
        .mjfr-publicar[disabled] { opacity:.55; cursor:default; }

        .mjfr-progreso { height:4px; background:#f0f0f1; border-radius:2px; margin-top:10px; overflow:hidden; }
        .mjfr-barra-int { height:100%; width:0; background:#2271b1; transition:width .18s ease; }
        .mjfr-aviso { margin-top:8px; font-size:13px; color:#b32d2e; }

        .mjfr-credito { margin-top:28px; color:#a7aaad; font-size:12px; }

        @media (max-width:600px) {
            .mjfr-acciones { flex-direction:column; }
            .mjfr-boton { padding:18px; font-size:16px; }
            .mjfr-publicar { padding:16px; }
            .mjfr-foto { width:64px; height:64px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .mjfr-barra-int { transition:none; }
        }
        </style>

        <script>
        (function () {
            const AJAX    = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const NONCE   = <?php echo wp_json_encode(wp_create_nonce(self::NONCE)); ?>;
            const MAX_PX  = <?php echo (int) self::ajustes()['max_px']; ?>;
            const CALIDAD = <?php echo (float) self::ajustes()['calidad']; ?>;

            function pedir(datos) {
                datos.append('nonce', NONCE);
                return fetch(AJAX, { method: 'POST', body: datos, credentials: 'same-origin' })
                    .then(r => r.json());
            }

            function avisar(item, texto) {
                const aviso = item.querySelector('.mjfr-aviso');
                aviso.hidden = !texto;
                aviso.textContent = texto || '';
            }

            function refrescarContador(item) {
                const n = item.querySelector('.mjfr-tiras').children.length;
                const c = item.querySelector('.mjfr-contador');
                c.textContent = n;
                c.classList.toggle('tiene', n > 0);
            }

            // Achica la imagen en el navegador antes de subirla.
            function achicar(archivo) {
                return new Promise(function (resolver) {
                    if (!/^image\/(jpeg|png|webp)$/i.test(archivo.type)) return resolver(archivo);
                    const img = new Image();
                    const url = URL.createObjectURL(archivo);
                    img.onload = function () {
                        const escala = Math.min(1, MAX_PX / Math.max(img.width, img.height));
                        if (escala === 1 && archivo.size < 400000) { URL.revokeObjectURL(url); return resolver(archivo); }
                        const lienzo = document.createElement('canvas');
                        lienzo.width  = Math.round(img.width  * escala);
                        lienzo.height = Math.round(img.height * escala);
                        lienzo.getContext('2d').drawImage(img, 0, 0, lienzo.width, lienzo.height);
                        lienzo.toBlob(function (blob) {
                            URL.revokeObjectURL(url);
                            if (!blob) return resolver(archivo);
                            resolver(new File([blob], archivo.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }));
                        }, 'image/jpeg', CALIDAD);
                    };
                    img.onerror = function () { URL.revokeObjectURL(url); resolver(archivo); };
                    img.src = url;
                });
            }

            async function subir(item, archivos) {
                const progreso = item.querySelector('.mjfr-progreso');
                const barra    = item.querySelector('.mjfr-barra-int');
                const tiras    = item.querySelector('.mjfr-tiras');

                avisar(item, '');
                progreso.hidden = false;
                barra.style.width = '0%';

                let hechas = 0;
                for (const original of archivos) {
                    try {
                        const archivo = await achicar(original);
                        const datos = new FormData();
                        datos.append('action', 'mj_fotos_subir');
                        datos.append('producto', item.dataset.id);
                        datos.append('archivo', archivo, archivo.name);

                        const j = await pedir(datos);
                        if (!j.success) throw new Error(j.data || 'Error desconocido');

                        const fig = document.createElement('figure');
                        fig.className = 'mjfr-foto' + (j.data.principal ? ' es-principal' : '');
                        fig.dataset.att = j.data.id;
                        fig.innerHTML = '<img alt=""><button type="button" class="mjfr-quitar" title="Quitar del producto">×</button>';
                        fig.querySelector('img').src = j.data.thumb;
                        tiras.appendChild(fig);
                        refrescarContador(item);
                    } catch (e) {
                        avisar(item, 'No se pudo subir ' + original.name + ': ' + e.message);
                    }
                    hechas++;
                    barra.style.width = Math.round(hechas / archivos.length * 100) + '%';
                }
                setTimeout(function () { progreso.hidden = true; }, 700);
            }

            async function publicar(item) {
                const boton = item.querySelector('.mjfr-publicar');
                if (boton) { boton.disabled = true; boton.textContent = 'Publicando…'; }

                const datos = new FormData();
                datos.append('action', 'mj_fotos_publicar');
                datos.append('producto', item.dataset.id);

                const j = await pedir(datos);
                if (!j.success) {
                    avisar(item, j.data || 'No se pudo publicar.');
                    if (boton) { boton.disabled = false; boton.textContent = 'Publicar'; }
                    return false;
                }

                avisar(item, '');
                item.dataset.estado = 'publish';
                item.classList.add('es-publicado');
                item.querySelector('.mjfr-estado').textContent = 'Publicado';
                if (boton) boton.hidden = true;
                return true;
            }

            document.addEventListener('change', function (e) {
                if (!e.target.matches('.mjfr-boton input[type=file]')) return;
                const item = e.target.closest('.mjfr-item');
                const archivos = Array.from(e.target.files || []);
                e.target.value = '';
                if (archivos.length) subir(item, archivos);
            });

            document.addEventListener('click', async function (e) {

                // Publicar uno
                if (e.target.matches('.mjfr-publicar')) {
                    const item = e.target.closest('.mjfr-item');
                    if (!item.querySelector('.mjfr-tiras').children.length &&
                        !confirm('Este producto no tiene fotos. ¿Lo publicás igual?')) return;
                    publicar(item);
                    return;
                }

                // Publicar en lote
                if (e.target.id === 'mjfr-publicar-lote') {
                    const boton = e.target;
                    const aviso = document.querySelector('.mjfr-lote-aviso');
                    const items = Array.from(document.querySelectorAll('.mjfr-item')).filter(function (it) {
                        return it.dataset.estado !== 'publish' &&
                               it.querySelector('.mjfr-tiras').children.length > 0;
                    });
                    if (!items.length) { aviso.textContent = 'No hay borradores con fotos en esta pantalla.'; return; }
                    if (!confirm('Se van a publicar ' + items.length + ' productos. ¿Seguimos?')) return;

                    boton.disabled = true;
                    let ok = 0;
                    for (const item of items) {
                        if (await publicar(item)) ok++;
                        aviso.textContent = ok + ' de ' + items.length + ' publicados…';
                    }
                    aviso.textContent = ok + ' productos publicados.';
                    boton.disabled = false;
                    return;
                }

                // Quitar una foto
                const quitar = e.target.closest('.mjfr-quitar');
                if (!quitar) return;
                const fig  = quitar.closest('.mjfr-foto');
                const item = quitar.closest('.mjfr-item');
                const datos = new FormData();
                datos.append('action', 'mj_fotos_quitar');
                datos.append('producto', item.dataset.id);
                datos.append('adjunto', fig.dataset.att);

                const j = await pedir(datos);
                if (!j.success) { avisar(item, j.data || 'No se pudo quitar la foto.'); return; }

                fig.remove();
                const tiras = item.querySelector('.mjfr-tiras');
                if (tiras.children.length) tiras.children[0].classList.add('es-principal');
                refrescarContador(item);
            });

            document.querySelectorAll('.mjfr-item').forEach(refrescarContador);
        })();
        </script>
        <?php
    }

    /* ---------------------------- AJAX -------------------------------- */

    private function verificar($id) {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!$id || get_post_type($id) !== 'product' || !current_user_can('edit_post', $id)) {
            wp_send_json_error('No tenés permiso sobre este producto.');
        }
    }

    public function ajax_subir() {
        $id = isset($_POST['producto']) ? (int) $_POST['producto'] : 0;
        $this->verificar($id);

        if (empty($_FILES['archivo'])) wp_send_json_error('No llegó ningún archivo.');

        /*
         * Solo imágenes. media_handle_upload() acepta cualquier tipo que WordPress
         * permita subir (PDF, ZIP…); acá se mira el contenido real del archivo,
         * no la extensión ni lo que declara el navegador.
         */
        $archivo = $_FILES['archivo']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- verificar() ya validó el nonce; el archivo se comprueba abajo.
        $tmp     = isset($archivo['tmp_name']) ? (string) $archivo['tmp_name'] : '';
        if ('' === $tmp || !is_uploaded_file($tmp)) {
            wp_send_json_error('El archivo no llegó completo. Probá de nuevo.');
        }
        $tipo = wp_check_filetype_and_ext($tmp, isset($archivo['name']) ? sanitize_file_name((string) $archivo['name']) : 'foto.jpg');
        $mime = !empty($tipo['type']) ? (string) $tipo['type'] : '';
        $permitidos = (array) apply_filters('mj_fotos_rapidas_mimes', array('image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'));
        if ('' === $mime || !in_array($mime, $permitidos, true) || false === @getimagesize($tmp)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            wp_send_json_error('Solo se pueden subir fotos (JPG, PNG, WebP, GIF o AVIF).');
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $adjunto = media_handle_upload('archivo', $id);
        if (is_wp_error($adjunto)) wp_send_json_error($adjunto->get_error_message());

        $titulo = get_the_title($id);
        update_post_meta($adjunto, '_wp_attachment_image_alt', $titulo);
        wp_update_post(array('ID' => $adjunto, 'post_title' => $titulo));

        $principal = false;
        if (!has_post_thumbnail($id)) {
            set_post_thumbnail($id, $adjunto);
            $principal = true;
        } else {
            $galeria = get_post_meta($id, '_product_image_gallery', true);
            $ids = $galeria ? array_filter(array_map('intval', explode(',', $galeria))) : array();
            if (!in_array((int) $adjunto, $ids, true)) $ids[] = (int) $adjunto;
            update_post_meta($id, '_product_image_gallery', implode(',', $ids));
        }

        wp_send_json_success(array(
            'id'        => (int) $adjunto,
            'thumb'     => wp_get_attachment_image_url($adjunto, 'thumbnail'),
            'principal' => $principal,
        ));
    }

    /** Desvincula la foto del producto. El archivo sigue en la biblioteca de medios. */
    public function ajax_quitar() {
        $id = isset($_POST['producto']) ? (int) $_POST['producto'] : 0;
        $this->verificar($id);

        $adjunto = isset($_POST['adjunto']) ? (int) $_POST['adjunto'] : 0;
        if (!$adjunto) wp_send_json_error('Falta la foto.');

        $galeria = get_post_meta($id, '_product_image_gallery', true);
        $ids = $galeria ? array_filter(array_map('intval', explode(',', $galeria))) : array();

        if ((int) get_post_thumbnail_id($id) === $adjunto) {
            delete_post_thumbnail($id);
            if ($ids) {
                set_post_thumbnail($id, array_shift($ids));
                update_post_meta($id, '_product_image_gallery', implode(',', $ids));
            }
        } else {
            $ids = array_values(array_diff($ids, array($adjunto)));
            update_post_meta($id, '_product_image_gallery', implode(',', $ids));
        }

        wp_send_json_success(true);
    }

    public function ajax_publicar() {
        $id = isset($_POST['producto']) ? (int) $_POST['producto'] : 0;
        $this->verificar($id);

        if (!current_user_can('publish_products')) {
            wp_send_json_error('Tu usuario no puede publicar productos.');
        }
        if (get_post_status($id) === 'publish') {
            wp_send_json_success(array('estado' => 'publish'));
        }

        $r = wp_update_post(array('ID' => $id, 'post_status' => 'publish'), true);
        if (is_wp_error($r)) wp_send_json_error($r->get_error_message());

        wp_send_json_success(array('estado' => 'publish', 'url' => get_permalink($id)));
    }
}

new MJ_Fotos_Rapidas();
