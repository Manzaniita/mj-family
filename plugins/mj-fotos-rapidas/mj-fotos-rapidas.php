<?php
/**
 * Plugin Name: MJ Fotos Rápidas
 * Description: Cargá las fotos de tus productos desde el celular en dos toques y publicalos ahí mismo. Sin FTP, sin renombrar archivos, sin carpetas. Achica las imágenes solo antes de subirlas.
 * Version: 1.5.0
 * Author: MJ Importaciones
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: mj-fotos-rapidas
 *
 * v1.5: ajuste automático para encastrar fotos en marco 1:1 (contener con fondo o rellenar) y editor interactivo con zoom (+ / - / slider / gestos), encuadre y rotación.
 * v1.4: soporte de recorte previo a la subida y ajustes ampliados.
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

define('MJFR_VERSION', '1.5.0');

/*
 * El menú único de la familia MJ en wp-admin. La clase viaja adentro del
 * plugin: si está solo, arma igual el menú con esta pantalla adentro.
 */
require_once __DIR__ . '/compartido/class-mj-menu.php';
MJ_Menu::init();

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
     * Tamaño y calidad con que el celular achica cada foto ANTES de subirla,
     * modo de ajuste (interactivo, auto_encastrar, auto_rellenar, original) y color de fondo.
     * Salen de la opción del plugin; se pueden cambiar sin tocar el código
     * (o con el filtro `mj_fotos_rapidas_ajustes`).
     *
     * @return array{max_px:int, calidad:float, modo:string, fondo_color:string, recortar:bool}
     */
    public static function ajustes() {
        $guardado = get_option(self::OPCION, array());
        if (!is_array($guardado)) {
            $guardado = array();
        }
        $max_px  = isset($guardado['max_px']) ? (int) $guardado['max_px'] : self::MAX_PX;
        $calidad = isset($guardado['calidad']) ? (float) $guardado['calidad'] : self::CALIDAD;

        $modo = isset($guardado['modo']) ? sanitize_key($guardado['modo']) : '';
        if ('' === $modo) {
            $modo = !empty($guardado['recortar']) ? 'interactivo' : 'interactivo';
        }
        if (!in_array($modo, array('interactivo', 'auto_encastrar', 'auto_rellenar', 'original'), true)) {
            $modo = 'interactivo';
        }

        $fondo = isset($guardado['fondo_color']) ? sanitize_hex_color($guardado['fondo_color']) : '#ffffff';
        if (!$fondo) {
            $fondo = '#ffffff';
        }

        /* Rangos con sentido: nada de fotos de 2 px ni calidad 0. */
        $ajustes = array(
            'max_px'      => max(320, min(4096, $max_px)),
            'calidad'     => max(0.3, min(1.0, $calidad)),
            'modo'        => $modo,
            'fondo_color' => $fondo,
            'recortar'    => ($modo === 'interactivo'),
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

    /** Guarda el tamaño, calidad y modo de ajuste de las fotos (formulario de la pantalla). */
    public function guardar_ajustes() {
        if (!current_user_can(self::capacidad_ajustes())) {
            wp_die('Sin permisos.');
        }
        check_admin_referer(self::NONCE . '_ajustes');

        $modo = isset($_POST['modo']) ? sanitize_key(wp_unslash($_POST['modo'])) : 'interactivo';
        if (!in_array($modo, array('interactivo', 'auto_encastrar', 'auto_rellenar', 'original'), true)) {
            $modo = 'interactivo';
        }

        $fondo = isset($_POST['fondo_color']) ? sanitize_hex_color(wp_unslash($_POST['fondo_color'])) : '#ffffff';
        if (!$fondo) {
            $fondo = '#ffffff';
        }

        $calidad_raw = isset($_POST['calidad']) ? sanitize_text_field(wp_unslash($_POST['calidad'])) : '';
        $calidad_num = '' !== $calidad_raw ? floatval($calidad_raw) / 100 : self::CALIDAD;

        update_option(self::OPCION, array(
            'max_px'      => isset($_POST['max_px']) ? absint(wp_unslash($_POST['max_px'])) : self::MAX_PX,
            'calidad'     => $calidad_num,
            'modo'        => $modo,
            'fondo_color' => $fondo,
            'recortar'    => ($modo === 'interactivo'),
        ));

        wp_safe_redirect(add_query_arg(
            array('page' => self::SLUG, 'guardado' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    /* ------------------------------------------------------------------ */

    public function menu() {
        add_submenu_page(
            MJ_Menu::slug(),
            'Fotos rápidas',
            'Fotos rápidas',
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
        $mjfr_ajustes   = self::ajustes();
        ?>
        <div class="wrap mjfr">

            <h1 class="mjfr-titulo">Fotos rápidas</h1>
            <p class="mjfr-bajada">Elegí las fotos desde la galería del celular. Se ajustan automáticamente o te permiten encuadrar y hacer zoom a gusto. La primera queda como imagen principal y el resto va a la galería. Cuando el producto está listo, lo publicás acá mismo.</p>

            <?php if (!empty($_GET['guardado'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Ajustes guardados.</p></div>
            <?php endif; ?>

            <?php if (current_user_can(self::capacidad_ajustes())) : ?>
            <details style="margin:0 0 16px;">
                <summary style="cursor:pointer; font-weight:600;">Ajustes globales de las fotos</summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; margin-top:10px;">
                    <input type="hidden" name="action" value="mj_fotos_ajustes">
                    <?php wp_nonce_field(self::NONCE . '_ajustes'); ?>
                    <label>Lado más largo (px)<br>
                        <input type="number" name="max_px" value="<?php echo esc_attr((string) $mjfr_ajustes['max_px']); ?>" min="320" max="4096" step="10">
                    </label>
                    <label>Calidad (%)<br>
                        <input type="number" name="calidad" value="<?php echo esc_attr((string) round($mjfr_ajustes['calidad'] * 100)); ?>" min="30" max="100" step="1">
                    </label>
                    <label>Modo de ajuste predeterminado<br>
                        <select name="modo">
                            <option value="interactivo" <?php selected($mjfr_ajustes['modo'], 'interactivo'); ?>>Interactivo (acercar, alejar y recortar a gusto)</option>
                            <option value="auto_encastrar" <?php selected($mjfr_ajustes['modo'], 'auto_encastrar'); ?>>Automático: Encastrar entera (con fondo)</option>
                            <option value="auto_rellenar" <?php selected($mjfr_ajustes['modo'], 'auto_rellenar'); ?>>Automático: Rellenar marco 1:1</option>
                            <option value="original" <?php selected($mjfr_ajustes['modo'], 'original'); ?>>Original (sin ajustar proporciones)</option>
                        </select>
                    </label>
                    <label>Color de fondo para encastrar<br>
                        <input type="color" name="fondo_color" value="<?php echo esc_attr($mjfr_ajustes['fondo_color']); ?>" style="height:38px; width:50px; padding:2px;">
                    </label>
                    <button type="submit" class="button">Guardar ajustes</button>
                    <span class="description" style="width: 100%;">El modo interactivo te permite acercar, alejar y mover la foto con los dedos antes de subirla. El modo automático achica o agranda para que encastre perfecto en las tarjetas cuadradas sin tener que tocar nada.</span>
                </form>
            </details>
            <?php endif; ?>

            <div class="mjfr-modo-barra" role="group" aria-label="Ajuste de fotos al agregar">
                <span class="mjfr-modo-titulo">Ajuste al agregar fotos:</span>
                <label class="mjfr-modo-opcion">
                    <input type="radio" name="mjfr_modo_ui" value="interactivo" <?php checked($mjfr_ajustes['modo'], 'interactivo'); ?>>
                    <span>Interactivo (zoom y encuadre a gusto)</span>
                </label>
                <label class="mjfr-modo-opcion">
                    <input type="radio" name="mjfr_modo_ui" value="auto_encastrar" <?php checked($mjfr_ajustes['modo'], 'auto_encastrar'); ?>>
                    <span>Auto: Encastrar entera (fondo blanco)</span>
                </label>
                <label class="mjfr-modo-opcion">
                    <input type="radio" name="mjfr_modo_ui" value="auto_rellenar" <?php checked($mjfr_ajustes['modo'], 'auto_rellenar'); ?>>
                    <span>Auto: Rellenar marco 1:1</span>
                </label>
                <label class="mjfr-modo-opcion">
                    <input type="radio" name="mjfr_modo_ui" value="original" <?php checked($mjfr_ajustes['modo'], 'original'); ?>>
                    <span>Original (sin ajustar)</span>
                </label>
            </div>

            <form method="get" class="mjfr-barra">
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

            <!-- Modal de Ajuste y Recorte Interactivo -->
            <div class="mjfr-modal" id="mjfr-editor-modal" role="dialog" aria-modal="true" aria-labelledby="mjfr-modal-titulo" hidden>
                <div class="mjfr-modal-panel">
                    <div class="mjfr-modal-cabecera">
                        <div class="mjfr-modal-titulos">
                            <h2 id="mjfr-modal-titulo" class="mjfr-modal-titulo">Ajustar foto para el producto</h2>
                            <span class="mjfr-modal-contador" id="mjfr-editor-contador"></span>
                        </div>
                        <button type="button" class="mjfr-modal-cerrar" id="mjfr-editor-cerrar" title="Cerrar y saltear foto">×</button>
                    </div>

                    <div class="mjfr-modal-cuerpo">
                        <div class="mjfr-viewport-contenedor">
                            <div class="mjfr-viewport" id="mjfr-viewport">
                                <div class="mjfr-stage" id="mjfr-stage">
                                    <img id="mjfr-editor-img" class="mjfr-editor-img" src="" alt="">
                                </div>
                                <div class="mjfr-viewport-guia">
                                    <div class="mjfr-guia-linea mjfr-guia-v1"></div>
                                    <div class="mjfr-guia-linea mjfr-guia-v2"></div>
                                    <div class="mjfr-guia-linea mjfr-guia-h1"></div>
                                    <div class="mjfr-guia-linea mjfr-guia-h2"></div>
                                </div>
                            </div>
                            <div class="mjfr-ayuda-gestos">Arrastrá para encuadrar • Pellizcá o usá el zoom para acercar y alejar</div>
                        </div>

                        <div class="mjfr-controles">
                            <div class="mjfr-zoom-fila">
                                <span class="mjfr-control-label">Zoom:</span>
                                <button type="button" class="button mjfr-btn-zoom" id="mjfr-zoom-menos" title="Alejar foto" aria-label="Alejar">−</button>
                                <input type="range" class="mjfr-slider" id="mjfr-zoom-slider" min="30" max="300" step="1" value="100" aria-label="Nivel de zoom">
                                <button type="button" class="button mjfr-btn-zoom" id="mjfr-zoom-mas" title="Acercar foto" aria-label="Acercar">+</button>
                                <span class="mjfr-zoom-indicador" id="mjfr-zoom-num">100%</span>
                            </div>

                            <div class="mjfr-botones-rapidos">
                                <button type="button" class="button" id="mjfr-btn-encastrar" title="Achicar para mostrar el producto entero con fondo blanco">⛶ Encastrar entera</button>
                                <button type="button" class="button" id="mjfr-btn-rellenar" title="Agrandar para cubrir todo el marco 1:1 sin bordes">⬛ Rellenar marco</button>
                                <button type="button" class="button" id="mjfr-btn-centrar" title="Centrar la foto en el marco">🎯 Centrar</button>
                                <button type="button" class="button" id="mjfr-btn-rotar" title="Girar 90 grados">🔄 Girar</button>
                            </div>
                        </div>
                    </div>

                    <div class="mjfr-modal-pie">
                        <button type="button" class="button mjfr-btn-lote" id="mjfr-btn-todas" hidden>Ajustar todas y subir</button>
                        <div class="mjfr-pie-derecha">
                            <button type="button" class="button mjfr-btn-secundario" id="mjfr-btn-saltar">Subir original</button>
                            <button type="button" class="button button-primary mjfr-btn-primario" id="mjfr-btn-listo">Listo y subir</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .mjfr { max-width: 720px; }
        .mjfr-titulo { margin-bottom: 4px; }
        .mjfr-bajada { color:#50575e; max-width: 62ch; margin-top:0; }

        /* Barra de modo rápido */
        .mjfr-modo-barra {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }
        .mjfr-modo-titulo {
            font-size: 13px;
            font-weight: 600;
            color: #1d2327;
        }
        .mjfr-modo-opcion {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            color: #2c3338;
            cursor: pointer;
        }
        .mjfr-modo-opcion input {
            margin: 0;
        }

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

        /* Modal interactivo */
        .mjfr-modal {
            position: fixed;
            inset: 0;
            z-index: 100000;
            background: rgba(15, 23, 42, 0.78);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 12px;
            box-sizing: border-box;
        }
        .mjfr-modal[hidden] {
            display: none !important;
        }
        .mjfr-modal-panel {
            background: #ffffff;
            border-radius: 12px;
            width: 100%;
            max-width: 440px;
            max-height: 94vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        .mjfr-modal-cabecera {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .mjfr-modal-titulos {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .mjfr-modal-titulo {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }
        .mjfr-modal-contador {
            font-size: 12px;
            font-weight: 600;
            background: #e2e8f0;
            color: #475569;
            padding: 2px 7px;
            border-radius: 10px;
        }
        .mjfr-modal-cerrar {
            background: transparent;
            border: none;
            font-size: 24px;
            line-height: 1;
            color: #64748b;
            cursor: pointer;
            padding: 0 4px;
        }
        .mjfr-modal-cerrar:hover {
            color: #0f172a;
        }

        .mjfr-modal-cuerpo {
            padding: 14px 16px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        .mjfr-viewport-contenedor {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }
        .mjfr-viewport {
            position: relative;
            width: 320px;
            height: 320px;
            max-width: 76vw;
            max-height: 76vw;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 0 0 1px rgba(0,0,0,0.1);
            overflow: hidden;
            cursor: grab;
            user-select: none;
            touch-action: none;
        }
        .mjfr-viewport.is-dragging {
            cursor: grabbing;
        }
        .mjfr-stage {
            position: absolute;
            inset: 0;
            pointer-events: none;
        }
        .mjfr-editor-img {
            position: absolute;
            top: 50%;
            left: 50%;
            transform-origin: center center;
            pointer-events: none;
            user-select: none;
            max-width: none;
            max-height: none;
            will-change: transform;
        }
        .mjfr-viewport-guia {
            position: absolute;
            inset: 0;
            pointer-events: none;
            border: 1px solid rgba(34, 113, 177, 0.4);
        }
        .mjfr-guia-linea {
            position: absolute;
            background: rgba(255, 255, 255, 0.5);
            box-shadow: 0 0 1px rgba(0,0,0,0.5);
        }
        .mjfr-guia-v1 { left: 33.333%; top: 0; bottom: 0; width: 1px; }
        .mjfr-guia-v2 { left: 66.666%; top: 0; bottom: 0; width: 1px; }
        .mjfr-guia-h1 { top: 33.333%; left: 0; right: 0; height: 1px; }
        .mjfr-guia-h2 { top: 66.666%; left: 0; right: 0; height: 1px; }

        .mjfr-ayuda-gestos {
            font-size: 11px;
            color: #64748b;
            text-align: center;
        }

        .mjfr-controles {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .mjfr-zoom-fila {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f1f5f9;
            padding: 6px 10px;
            border-radius: 8px;
        }
        .mjfr-control-label {
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }
        .mjfr-btn-zoom {
            width: 32px;
            height: 32px;
            min-height: 32px;
            padding: 0 !important;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px !important;
            font-weight: bold;
            line-height: 1;
        }
        .mjfr-slider {
            flex: 1;
            margin: 0;
            cursor: pointer;
        }
        .mjfr-zoom-indicador {
            font-size: 12px;
            font-weight: 600;
            min-width: 44px;
            text-align: right;
            color: #1e293b;
        }

        .mjfr-botones-rapidos {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .mjfr-botones-rapidos .button {
            font-size: 12px !important;
            padding: 4px 8px !important;
            height: auto !important;
            line-height: 1.4 !important;
        }

        .mjfr-modal-pie {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 12px 16px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .mjfr-pie-derecha {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }
        .mjfr-btn-primario {
            background: #00a32a !important;
            border-color: #00733a !important;
            color: #fff !important;
            font-weight: 600 !important;
            padding: 6px 16px !important;
        }
        .mjfr-btn-primario:hover {
            background: #00893a !important;
        }

        @media (max-width:600px) {
            .mjfr-acciones { flex-direction:column; }
            .mjfr-boton { padding:18px; font-size:16px; }
            .mjfr-publicar { padding:16px; }
            .mjfr-foto { width:64px; height:64px; }
            .mjfr-viewport { width:280px; height:280px; }
            .mjfr-modal-pie { flex-direction: column; align-items: stretch; }
            .mjfr-pie-derecha { width: 100%; justify-content: space-between; margin-left: 0; }
        }
        @media (prefers-reduced-motion: reduce) {
            .mjfr-barra-int { transition:none; }
        }
        </style>

        <script>
        (function () {
            const AJAX         = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const NONCE        = <?php echo wp_json_encode(wp_create_nonce(self::NONCE)); ?>;
            const MAX_PX       = <?php echo (int) $mjfr_ajustes['max_px']; ?>;
            const CALIDAD      = <?php echo (float) $mjfr_ajustes['calidad']; ?>;
            const MODO_DEF     = <?php echo wp_json_encode($mjfr_ajustes['modo']); ?>;
            const FONDO_COLOR  = <?php echo wp_json_encode($mjfr_ajustes['fondo_color']); ?>;

            // Selector rápido de modo guardado en localStorage
            function obtenerModoActivo() {
                const guardado = localStorage.getItem('mjfr_modo_ui');
                if (guardado && ['interactivo', 'auto_encastrar', 'auto_rellenar', 'original'].includes(guardado)) {
                    return guardado;
                }
                return MODO_DEF || 'interactivo';
            }

            function sincronizarModoUI() {
                const modoActual = obtenerModoActivo();
                const radios = document.querySelectorAll('input[name="mjfr_modo_ui"]');
                radios.forEach(function (r) {
                    r.checked = (r.value === modoActual);
                    r.addEventListener('change', function () {
                        if (r.checked) {
                            localStorage.setItem('mjfr_modo_ui', r.value);
                        }
                    });
                });
            }
            sincronizarModoUI();

            function pedir(datos) {
                datos.append('nonce', NONCE);
                return fetch(AJAX, { method: 'POST', body: datos, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); });
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

            // Achica la imagen manteniendo aspecto original antes de subirla
            function achicar(archivo) {
                return new Promise(function (resolver) {
                    if (!/^image\/(jpeg|png|webp|gif|avif)$/i.test(archivo.type)) return resolver(archivo);
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

            // Ajuste automático directo (encastrar completa o rellenar 1:1) sin requerir interacción
            function ajustarAutomatico(archivo, modo, fondo) {
                return new Promise(function (resolver) {
                    if (!/^image\/(jpeg|png|webp|gif|avif)$/i.test(archivo.type)) return resolver(archivo);
                    const img = new Image();
                    const url = URL.createObjectURL(archivo);
                    img.onload = function () {
                        const nw = img.naturalWidth || img.width;
                        const nh = img.naturalHeight || img.height;
                        const outSize = Math.max(800, Math.min(MAX_PX, Math.max(nw, nh)));

                        let scale;
                        if (modo === 'auto_encastrar') {
                            scale = outSize / Math.max(nw, nh); // Contener entera con fondo
                        } else {
                            scale = outSize / Math.min(nw, nh); // Rellenar 1:1
                        }

                        const lienzo = document.createElement('canvas');
                        lienzo.width = outSize;
                        lienzo.height = outSize;
                        const ctx = lienzo.getContext('2d');
                        ctx.imageSmoothingEnabled = true;
                        ctx.imageSmoothingQuality = 'high';

                        ctx.fillStyle = fondo || '#ffffff';
                        ctx.fillRect(0, 0, outSize, outSize);

                        const drawW = nw * scale;
                        const drawH = nh * scale;
                        const drawX = (outSize - drawW) / 2;
                        const drawY = (outSize - drawH) / 2;
                        ctx.drawImage(img, drawX, drawY, drawW, drawH);

                        lienzo.toBlob(function (blob) {
                            URL.revokeObjectURL(url);
                            if (!blob) return resolver(archivo);
                            const nuevo = new File([blob], archivo.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
                            nuevo.focalX = 0.5;
                            nuevo.focalY = 0.5;
                            resolver(nuevo);
                        }, 'image/jpeg', CALIDAD);
                    };
                    img.onerror = function () { URL.revokeObjectURL(url); resolver(archivo); };
                    img.src = url;
                });
            }

            // Editor interactivo con zoom (+ / - / slider / pinch / wheel), encuadre y rotación
            function mostrarEditor(archivo, index, total) {
                return new Promise(function (resolve) {
                    const modal = document.getElementById('mjfr-editor-modal');
                    const imgEl = document.getElementById('mjfr-editor-img');
                    const viewport = document.getElementById('mjfr-viewport');
                    const contadorEl = document.getElementById('mjfr-editor-contador');
                    const btnCerrar = document.getElementById('mjfr-editor-cerrar');
                    const btnListo = document.getElementById('mjfr-btn-listo');
                    const btnSaltar = document.getElementById('mjfr-btn-saltar');
                    const btnTodas = document.getElementById('mjfr-btn-todas');
                    const slider = document.getElementById('mjfr-zoom-slider');
                    const zoomNum = document.getElementById('mjfr-zoom-num');
                    const btnZoomMenos = document.getElementById('mjfr-zoom-menos');
                    const btnZoomMas = document.getElementById('mjfr-zoom-mas');
                    const btnEncastrar = document.getElementById('mjfr-btn-encastrar');
                    const btnRellenar = document.getElementById('mjfr-btn-rellenar');
                    const btnCentrar = document.getElementById('mjfr-btn-centrar');
                    const btnRotar = document.getElementById('mjfr-btn-rotar');

                    const url = URL.createObjectURL(archivo);
                    imgEl.src = url;

                    if (total > 1) {
                        contadorEl.textContent = 'Foto ' + (index + 1) + ' de ' + total;
                        contadorEl.hidden = false;
                        btnTodas.hidden = (index === total - 1);
                    } else {
                        contadorEl.textContent = '';
                        contadorEl.hidden = true;
                        btnTodas.hidden = true;
                    }

                    modal.hidden = false;

                    let nw = 1, nh = 1;
                    let stageSize = 320;
                    let scaleContain = 1;
                    let scaleCover = 1;
                    let scale = 1;
                    let posX = 0;
                    let posY = 0;
                    let rotation = 0;

                    function calcDimensiones() {
                        stageSize = viewport.clientWidth || 320;
                        const ew = (rotation % 180 === 0) ? nw : nh;
                        const eh = (rotation % 180 === 0) ? nh : nw;
                        scaleContain = stageSize / Math.max(ew, eh);
                        scaleCover   = stageSize / Math.min(ew, eh);
                    }

                    function actualizarVista() {
                        imgEl.style.transform = 'translate(-50%, -50%) translate(' + posX + 'px, ' + posY + 'px) rotate(' + rotation + 'deg) scale(' + scale + ')';
                        const pct = Math.round((scale / scaleContain) * 100);
                        slider.value = pct;
                        zoomNum.textContent = pct + '%';
                    }

                    imgEl.onload = function () {
                        nw = imgEl.naturalWidth || imgEl.width || 1000;
                        nh = imgEl.naturalHeight || imgEl.height || 1000;
                        calcDimensiones();

                        // Por defecto encastra completa para que el producto se vea entero sin recortes
                        scale = scaleContain;
                        posX = 0;
                        posY = 0;
                        rotation = 0;

                        slider.min = Math.max(20, Math.round((scaleContain * 0.35 / scaleContain) * 100));
                        slider.max = Math.max(300, Math.round((scaleCover * 3.0 / scaleContain) * 100));
                        actualizarVista();
                    };

                    // Drag con PointerEvents (mouse y touch)
                    let isDragging = false;
                    let startX = 0, startY = 0;

                    function onPointerDown(e) {
                        isDragging = true;
                        startX = e.clientX;
                        startY = e.clientY;
                        viewport.classList.add('is-dragging');
                        viewport.setPointerCapture(e.pointerId);
                    }

                    function onPointerMove(e) {
                        if (!isDragging) return;
                        const dx = e.clientX - startX;
                        const dy = e.clientY - startY;
                        startX = e.clientX;
                        startY = e.clientY;
                        posX += dx;
                        posY += dy;
                        actualizarVista();
                    }

                    function onPointerUp(e) {
                        if (isDragging) {
                            isDragging = false;
                            viewport.classList.remove('is-dragging');
                            try { viewport.releasePointerCapture(e.pointerId); } catch (_) {}
                        }
                    }

                    viewport.addEventListener('pointerdown', onPointerDown);
                    viewport.addEventListener('pointermove', onPointerMove);
                    viewport.addEventListener('pointerup', onPointerUp);
                    viewport.addEventListener('pointercancel', onPointerUp);

                    // Pellizco para zoom táctil (2 dedos)
                    let pinchDist = null;
                    function onTouchMove(e) {
                        if (e.touches.length === 2) {
                            e.preventDefault();
                            const d = Math.hypot(
                                e.touches[0].clientX - e.touches[1].clientX,
                                e.touches[0].clientY - e.touches[1].clientY
                            );
                            if (pinchDist) {
                                const factor = d / pinchDist;
                                scale = Math.max(scaleContain * 0.25, Math.min(scaleCover * 4.0, scale * factor));
                                actualizarVista();
                            }
                            pinchDist = d;
                        }
                    }
                    function onTouchEnd() { pinchDist = null; }
                    viewport.addEventListener('touchmove', onTouchMove, { passive: false });
                    viewport.addEventListener('touchend', onTouchEnd);

                    // Rueda del ratón
                    function onWheel(e) {
                        e.preventDefault();
                        const factor = e.deltaY < 0 ? 1.08 : 0.92;
                        scale = Math.max(scaleContain * 0.25, Math.min(scaleCover * 4.0, scale * factor));
                        actualizarVista();
                    }
                    viewport.addEventListener('wheel', onWheel, { passive: false });

                    // Slider de zoom y botones + / -
                    function onSliderInput() {
                        const pct = parseFloat(slider.value) || 100;
                        scale = (pct / 100) * scaleContain;
                        actualizarVista();
                    }
                    slider.addEventListener('input', onSliderInput);

                    function zoomDelta(delta) {
                        const actualPct = parseFloat(slider.value) || 100;
                        const nuevoPct = Math.max(parseFloat(slider.min), Math.min(parseFloat(slider.max), actualPct + delta));
                        scale = (nuevoPct / 100) * scaleContain;
                        actualizarVista();
                    }
                    function onZoomMenos() { zoomDelta(-15); }
                    function onZoomMas() { zoomDelta(15); }
                    btnZoomMenos.addEventListener('click', onZoomMenos);
                    btnZoomMas.addEventListener('click', onZoomMas);

                    // Botones rápidos
                    function onEncastrar() {
                        calcDimensiones();
                        scale = scaleContain;
                        posX = 0;
                        posY = 0;
                        actualizarVista();
                    }
                    function onRellenar() {
                        calcDimensiones();
                        scale = scaleCover;
                        posX = 0;
                        posY = 0;
                        actualizarVista();
                    }
                    function onCentrar() {
                        posX = 0;
                        posY = 0;
                        actualizarVista();
                    }
                    function onRotar() {
                        rotation = (rotation + 90) % 360;
                        calcDimensiones();
                        scale = scaleContain;
                        posX = 0;
                        posY = 0;
                        actualizarVista();
                    }
                    btnEncastrar.addEventListener('click', onEncastrar);
                    btnRellenar.addEventListener('click', onRellenar);
                    btnCentrar.addEventListener('click', onCentrar);
                    btnRotar.addEventListener('click', onRotar);

                    function limpiarEventos() {
                        viewport.removeEventListener('pointerdown', onPointerDown);
                        viewport.removeEventListener('pointermove', onPointerMove);
                        viewport.removeEventListener('pointerup', onPointerUp);
                        viewport.removeEventListener('pointercancel', onPointerUp);
                        viewport.removeEventListener('touchmove', onTouchMove);
                        viewport.removeEventListener('touchend', onTouchEnd);
                        viewport.removeEventListener('wheel', onWheel);
                        slider.removeEventListener('input', onSliderInput);
                        btnZoomMenos.removeEventListener('click', onZoomMenos);
                        btnZoomMas.removeEventListener('click', onZoomMas);
                        btnEncastrar.removeEventListener('click', onEncastrar);
                        btnRellenar.removeEventListener('click', onRellenar);
                        btnCentrar.removeEventListener('click', onCentrar);
                        btnRotar.removeEventListener('click', onRotar);
                        btnCerrar.removeEventListener('click', onCerrar);
                        btnSaltar.removeEventListener('click', onSaltar);
                        btnListo.removeEventListener('click', onListo);
                        btnTodas.removeEventListener('click', onTodas);
                        modal.hidden = true;
                        URL.revokeObjectURL(url);
                    }

                    function onCerrar() {
                        limpiarEventos();
                        resolve({ cancelado: true });
                    }
                    btnCerrar.addEventListener('click', onCerrar);

                    function onSaltar() {
                        limpiarEventos();
                        achicar(archivo).then(function (res) {
                            resolve({ archivo: res, cancelado: false, aplicarATodas: false });
                        });
                    }
                    btnSaltar.addEventListener('click', onSaltar);

                    function generarArchivoFinal() {
                        const ew = (rotation % 180 === 0) ? nw : nh;
                        const eh = (rotation % 180 === 0) ? nh : nw;
                        const outSize = Math.max(800, Math.min(MAX_PX, Math.max(ew, eh)));
                        const ratio = outSize / stageSize;

                        const lienzo = document.createElement('canvas');
                        lienzo.width = outSize;
                        lienzo.height = outSize;
                        const ctx = lienzo.getContext('2d');
                        ctx.imageSmoothingEnabled = true;
                        ctx.imageSmoothingQuality = 'high';

                        ctx.fillStyle = FONDO_COLOR || '#ffffff';
                        ctx.fillRect(0, 0, outSize, outSize);

                        ctx.save();
                        ctx.translate(outSize / 2 + posX * ratio, outSize / 2 + posY * ratio);
                        ctx.rotate((rotation * Math.PI) / 180);
                        ctx.scale(scale * ratio, scale * ratio);
                        ctx.drawImage(imgEl, -nw / 2, -nh / 2, nw, nh);
                        ctx.restore();

                        // Cálculo del punto focal en coordenadas normalizadas (0 a 1)
                        const rad = (-rotation * Math.PI) / 180;
                        const cos = Math.cos(rad);
                        const sin = Math.sin(rad);
                        const unrotX = -posX * cos - -posY * sin;
                        const unrotY = -posX * -sin + -posY * cos;
                        const natDx = unrotX / scale;
                        const natDy = unrotY / scale;
                        const fx = Math.max(0, Math.min(1, 0.5 + natDx / nw));
                        const fy = Math.max(0, Math.min(1, 0.5 + natDy / nh));

                        return new Promise(function (res) {
                            lienzo.toBlob(function (blob) {
                                if (!blob) return res(archivo);
                                const file = new File([blob], archivo.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
                                file.focalX = fx;
                                file.focalY = fy;
                                res(file);
                            }, 'image/jpeg', CALIDAD);
                        });
                    }

                    function onListo() {
                        generarArchivoFinal().then(function (finalFile) {
                            limpiarEventos();
                            resolve({ archivo: finalFile, cancelado: false, aplicarATodas: false });
                        });
                    }
                    btnListo.addEventListener('click', onListo);

                    function onTodas() {
                        const modoLote = (scale >= scaleCover * 0.95) ? 'auto_rellenar' : 'auto_encastrar';
                        generarArchivoFinal().then(function (finalFile) {
                            limpiarEventos();
                            resolve({ archivo: finalFile, cancelado: false, aplicarATodas: true, modoFiltro: modoLote });
                        });
                    }
                    btnTodas.addEventListener('click', onTodas);
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
                let loteModoAutomatico = null;

                for (let i = 0; i < archivos.length; i++) {
                    const original = archivos[i];
                    try {
                        let finalFile = original;
                        const modoActivo = obtenerModoActivo();

                        if (modoActivo === 'auto_encastrar' || modoActivo === 'auto_rellenar') {
                            finalFile = await ajustarAutomatico(original, modoActivo, FONDO_COLOR);
                        } else if (modoActivo === 'interactivo' && /^image\/(jpeg|png|webp|gif|avif)$/i.test(original.type)) {
                            if (loteModoAutomatico) {
                                finalFile = await ajustarAutomatico(original, loteModoAutomatico, FONDO_COLOR);
                            } else {
                                const resEditor = await mostrarEditor(original, i, archivos.length);
                                if (resEditor.cancelado) {
                                    break;
                                }
                                if (resEditor.aplicarATodas) {
                                    loteModoAutomatico = resEditor.modoFiltro || 'auto_encastrar';
                                }
                                finalFile = resEditor.archivo;
                            }
                        } else {
                            finalFile = await achicar(original);
                        }

                        const datos = new FormData();
                        datos.append('action', 'mj_fotos_subir');
                        datos.append('producto', item.dataset.id);
                        datos.append('archivo', finalFile, finalFile.name);
                        if (typeof finalFile.focalX === 'number' && typeof finalFile.focalY === 'number') {
                            datos.append('focal_x', finalFile.focalX.toFixed(3));
                            datos.append('focal_y', finalFile.focalY.toFixed(3));
                        }

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

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verificar() ya validó el nonce al inicio del método.
        if (isset($_POST['focal_x'], $_POST['focal_y'])) {
            $fx = max(0.0, min(1.0, floatval(sanitize_text_field(wp_unslash($_POST['focal_x'])))));
            $fy = max(0.0, min(1.0, floatval(sanitize_text_field(wp_unslash($_POST['focal_y'])))));
            $focal_data = array('x' => $fx, 'y' => $fy);
            update_post_meta($adjunto, '_mj_focal_point', $focal_data);
            if ($principal) {
                update_post_meta($id, '_mj_focal_point', $focal_data);
            } else {
                $gal_focal = get_post_meta($id, '_mj_gallery_focal', true);
                if (!is_array($gal_focal)) {
                    $gal_focal = array();
                }
                $gal_focal[(int) $adjunto] = $focal_data;
                update_post_meta($id, '_mj_gallery_focal', $gal_focal);
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

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
