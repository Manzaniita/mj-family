<?php
/**
 * MJ Commerce Pro v1.5.0 - Motor Principal FSE + Compatibilidad MJ-Family + Visibilidad Universal + Bloques Dedicados
 *
 * @package MJ_Commerce_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MJ_PRO_VERSION', '1.5.0' );
define( 'MJ_PRO_DIR', get_template_directory() );
define( 'MJ_PRO_URI', get_template_directory_uri() );

function mj_pro_setup_theme() {
    add_theme_support( 'wp-block-styles' );
    add_theme_support( 'editor-styles' );
    add_theme_support( 'responsive-embeds' );
    add_theme_support( 'woocommerce' );
    add_theme_support( 'wc-product-gallery-zoom' );
    add_theme_support( 'wc-product-gallery-lightbox' );
    add_theme_support( 'wc-product-gallery-slider' );

    add_editor_style( 'assets/css/blocks-frontend.css' );
}
add_action( 'after_setup_theme', 'mj_pro_setup_theme' );

/**
 * Limpieza automática de plantillas cacheadas de versiones previas
 */
function mj_pro_upgrade_template_fix() {
    if ( get_option( 'mj_pro_template_fix_120' ) === 'done' ) {
        return;
    }
    $stale_templates = get_posts( array(
        'post_type'      => 'wp_template',
        'post_name__in'  => array( 'index', 'page' ),
        'posts_per_page' => -1,
        'post_status'    => array( 'publish', 'draft', 'auto-draft' ),
    ) );
    foreach ( $stale_templates as $tpl ) {
        if ( 'index' === $tpl->post_name && strpos( $tpl->post_content, 'mj-pro/hero-slider' ) !== false ) {
            wp_delete_post( $tpl->ID, true );
        }
    }
    flush_rewrite_rules( false );
    update_option( 'mj_pro_template_fix_120', 'done' );
}
add_action( 'init', 'mj_pro_upgrade_template_fix', 20 );

/**
 * Autocompletado inteligente para páginas internas (/mi-cuenta, /carrito, /finalizar-compra, /contacto)
 */
function mj_pro_smart_page_content_fallback( $content ) {
    if ( is_admin() || ! is_singular( 'page' ) ) {
        return $content;
    }
    global $post;
    if ( ! $post ) {
        return $content;
    }
    $slug    = $post->post_name;
    $trimmed = trim( wp_strip_all_tags( $content ) );

    if ( in_array( $slug, array( 'mi-cuenta', 'my-account' ), true ) && empty( $trimmed ) && strpos( $content, 'woocommerce_my_account' ) === false ) {
        return do_shortcode( '[woocommerce_my_account]' );
    }
    if ( in_array( $slug, array( 'carrito', 'cart' ), true ) && empty( $trimmed ) && strpos( $content, 'woocommerce' ) === false ) {
        return do_shortcode( '[woocommerce_cart]' );
    }
    if ( in_array( $slug, array( 'finalizar-compra', 'checkout' ), true ) && empty( $trimmed ) && strpos( $content, 'woocommerce' ) === false && strpos( $content, 'multistep' ) === false ) {
        return function_exists( 'mj_pro_render_multistep_checkout' ) ? mj_pro_render_multistep_checkout( array() ) : do_shortcode( '[woocommerce_checkout]' );
    }
    if ( in_array( $slug, array( 'contacto', 'contact' ), true ) && empty( $trimmed ) ) {
        $wa_num   = preg_replace( '/[^0-9]/', '', get_theme_mod( 'mj_whatsapp_number', get_option( 'mj_whatsapp_number', '' ) ) );
        $site_name = get_bloginfo( 'name' );
        $admin_email = get_option( 'admin_email' );
        ob_start();
        ?>
        <div class="mj-contact-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:2rem;margin-top:1.5rem;">
            <div style="background:var(--wp--preset--color--surface,#fff);border:1px solid var(--wp--preset--color--border,#e2e8f0);border-radius:16px;padding:2rem;">
                <h3 style="margin-top:0;">💬 Atención Directa y Ventas</h3>
                <p style="color:var(--wp--preset--color--muted,#475569);">Comunicate con nosotros para consultas sobre stock, envíos a todo el país o listas de precios.</p>
                <p><strong>🏢 <?php echo esc_html( $site_name ); ?></strong><br>
                <strong>✉️ Email:</strong> <?php echo esc_html( $admin_email ); ?></p>
                <?php if ( ! empty( $wa_num ) ) : ?>
                    <a href="https://wa.me/<?php echo esc_attr( $wa_num ); ?>" target="_blank" rel="noopener" class="mj-arg-wa-btn" style="margin-top:1rem;">💬 Hablar por WhatsApp Ahora</a>
                <?php endif; ?>
            </div>
            <div style="background:var(--wp--preset--color--surface,#fff);border:1px solid var(--wp--preset--color--border,#e2e8f0);border-radius:16px;padding:2rem;">
                <h3 style="margin-top:0;">✉️ Envianos tu Consulta</h3>
                <form onsubmit="event.preventDefault();alert('¡Gracias por tu mensaje! Te responderemos a la brevedad.');" style="display:flex;flex-direction:column;gap:1rem;">
                    <input type="text" placeholder="Tu Nombre" required style="padding:0.75rem 1rem;border:1px solid var(--wp--preset--color--border,#e2e8f0);border-radius:10px;" />
                    <input type="email" placeholder="Tu Email o WhatsApp" required style="padding:0.75rem 1rem;border:1px solid var(--wp--preset--color--border,#e2e8f0);border-radius:10px;" />
                    <textarea rows="4" placeholder="¿En qué podemos ayudarte?" required style="padding:0.75rem 1rem;border:1px solid var(--wp--preset--color--border,#e2e8f0);border-radius:10px;"></textarea>
                    <button type="submit" class="mj-btn mj-btn-primary" style="border:none;cursor:pointer;">Enviar Mensaje</button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    return $content;
}
add_filter( 'the_content', 'mj_pro_smart_page_content_fallback', 20 );

/**
 * Categoría propia de bloques MJ Commerce Pro
 */
function mj_pro_register_block_category( $categories ) {
    return array_merge(
        array(
            array(
                'slug'  => 'mj-commerce-pro',
                'title' => __( 'MJ Commerce Pro & Familia MJ', 'mj-commerce-pro' ),
                'icon'  => 'cart',
            ),
        ),
        $categories
    );
}
add_filter( 'block_categories_all', 'mj_pro_register_block_category', 10, 1 );

/**
 * Scripts y Estilos Frontend + Editor
 */
function mj_pro_enqueue_assets() {
    wp_enqueue_style(
        'mj-pro-blocks-css',
        MJ_PRO_URI . '/assets/css/blocks-frontend.css',
        array(),
        MJ_PRO_VERSION
    );

    wp_enqueue_script(
        'mj-pro-frontend-ajax',
        MJ_PRO_URI . '/assets/js/frontend-ajax.js',
        array(),
        MJ_PRO_VERSION,
        true
    );

    wp_localize_script(
        'mj-pro-frontend-ajax',
        'mjProAjax',
        array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'mj_pro_ajax_nonce' ),
            'currencySym'  => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$',
            'cartUrl'      => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '/carrito/',
            'checkoutUrl'  => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '/finalizar-compra/',
            'shopUrl'      => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '/tienda/',
        )
    );
}
add_action( 'wp_enqueue_scripts', 'mj_pro_enqueue_assets' );

function mj_pro_enqueue_block_editor_assets() {
    wp_enqueue_script(
        'mj-pro-editor-blocks',
        MJ_PRO_URI . '/assets/js/editor-blocks.js',
        array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n', 'wp-hooks', 'wp-compose' ),
        MJ_PRO_VERSION,
        true
    );

    // Obtener todos los roles registrados en WordPress para el editor visual
    $roles_list = array();
    if ( function_exists( 'wp_roles' ) ) {
        $wp_roles = wp_roles();
        if ( ! empty( $wp_roles->roles ) ) {
            foreach ( $wp_roles->roles as $role_key => $role_info ) {
                $role_name = function_exists( 'translate_user_role' ) ? translate_user_role( $role_info['name'] ) : $role_info['name'];
                $roles_list[] = array(
                    'value' => $role_key,
                    'label' => $role_name,
                );
            }
        }
    }

    // Capacidades estándares y de la familia MJ (ERP, Turnos, WooCommerce)
    $caps_list = array(
        array( 'value' => 'manage_options', 'label' => 'Administrador (manage_options)' ),
        array( 'value' => 'manage_woocommerce', 'label' => 'Gestor de Tienda (manage_woocommerce)' ),
        array( 'value' => 'manage_mjerp', 'label' => 'Operador MJ ERP (manage_mjerp)' ),
        array( 'value' => 'mjerp_consignee', 'label' => 'Consignatario MJ ERP (mjerp_consignee)' ),
        array( 'value' => 'mjto_manage', 'label' => 'Gestor de Turnos MJ (mjto_manage)' ),
        array( 'value' => 'edit_posts', 'label' => 'Editor / Autor (edit_posts)' ),
        array( 'value' => 'read', 'label' => 'Cualquier usuario conectado (read)' ),
    );

    wp_localize_script(
        'mj-pro-editor-blocks',
        'mjProEditorData',
        array(
            'roles'        => $roles_list,
            'caps'         => $caps_list,
            'hasMjDolar'   => class_exists( 'MJD_Rate' ),
            'hasMjErp'     => class_exists( 'MJERP_Plugin' ) || function_exists( 'mjerp' ),
            'hasMjMedios'  => class_exists( 'MJ_Medios_Pago' ) || defined( 'MJMP_VERSION' ),
            'hasMjTurnos'  => defined( 'MJTO_VERSION' ),
            'dolarRate'    => class_exists( 'MJD_Rate' ) ? (float) MJD_Rate::get() : 0,
            'defaultPhone' => get_theme_mod( 'mj_whatsapp_number', get_option( 'mj_whatsapp_number', '' ) ),
        )
    );

    wp_enqueue_style(
        'mj-pro-editor-css',
        MJ_PRO_URI . '/assets/css/blocks-frontend.css',
        array(),
        MJ_PRO_VERSION
    );
}
add_action( 'enqueue_block_editor_assets', 'mj_pro_enqueue_block_editor_assets' );

/**
 * MOTOR UNIVERSAL DE VISIBILIDAD (PANTALLAS + PERMISOS Y ROLES DE WordPress)
 * 1. Control de Permisos en Servidor (PHP): Si el usuario no cumple los permisos,
 *    se cancela la renderización (HTML vacío), protegiendo datos y widgets sensibles.
 * 2. Control de Pantallas (Responsive): Inyecta clases mj-hide-mobile, mj-hide-tablet
 *    y mj-hide-desktop procesadas por CSS media-queries.
 */
function mj_pro_apply_universal_block_visibility( $block_content, $block ) {
    // No intervenir en el editor de bloques ni en peticiones internas de guardado de plantillas
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo lectura de contexto de renderización
    $is_rest_edit = defined( 'REST_REQUEST' ) && REST_REQUEST && ! empty( $_GET['context'] ) && 'edit' === sanitize_text_field( wp_unslash( $_GET['context'] ) );
    if ( is_admin() || $is_rest_edit ) {
        return $block_content;
    }

    $attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();

    // 1. CHEQUEO DE PERMISOS / ROLES (SERVER-SIDE STRIPPING)
    $vis_mode = ! empty( $attrs['mjVisibilityMode'] ) ? $attrs['mjVisibilityMode'] : 'all';

    if ( 'all' !== $vis_mode ) {
        $is_logged = is_user_logged_in();

        if ( 'logged_in' === $vis_mode && ! $is_logged ) {
            return '';
        }
        if ( 'guests' === $vis_mode && $is_logged ) {
            return '';
        }
        if ( 'roles' === $vis_mode ) {
            if ( ! $is_logged ) {
                return '';
            }
            $allowed_roles = ! empty( $attrs['mjVisibilityRoles'] ) ? (array) $attrs['mjVisibilityRoles'] : array();
            if ( ! empty( $allowed_roles ) ) {
                $user = wp_get_current_user();
                $user_roles = (array) $user->roles;
                $has_role   = (bool) array_intersect( $allowed_roles, $user_roles );
                // Los administradores siempre tienen permiso para no bloquear la tienda al dueño
                if ( ! $has_role && ! current_user_can( 'manage_options' ) ) {
                    return '';
                }
            }
        }
        if ( 'capability' === $vis_mode ) {
            $cap = ! empty( $attrs['mjVisibilityCap'] ) ? sanitize_text_field( $attrs['mjVisibilityCap'] ) : '';
            if ( ! empty( $cap ) && ! current_user_can( $cap ) && ! current_user_can( 'manage_options' ) ) {
                return '';
            }
        }
    }

    // 2. CHEQUEO DE RESPONSIVE (CLASES CSS EN DOM)
    $hide_mobile  = ! empty( $attrs['mjHideMobile'] );
    $hide_tablet  = ! empty( $attrs['mjHideTablet'] );
    $hide_desktop = ! empty( $attrs['mjHideDesktop'] );

    if ( ! $hide_mobile && ! $hide_tablet && ! $hide_desktop ) {
        return $block_content;
    }

    $classes = array();
    if ( $hide_mobile ) {
        $classes[] = 'mj-hide-mobile';
    }
    if ( $hide_tablet ) {
        $classes[] = 'mj-hide-tablet';
    }
    if ( $hide_desktop ) {
        $classes[] = 'mj-hide-desktop';
    }

    if ( empty( $classes ) || empty( trim( $block_content ) ) ) {
        return $block_content;
    }

    if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
        $processor = new WP_HTML_Tag_Processor( $block_content );
        if ( $processor->next_tag() ) {
            foreach ( $classes as $cls ) {
                $processor->add_class( $cls );
            }
            return $processor->get_updated_html();
        }
    }

    return sprintf(
        '<div class="%s">%s</div>',
        esc_attr( implode( ' ', $classes ) ),
        $block_content
    );
}
add_filter( 'render_block', 'mj_pro_apply_universal_block_visibility', 5, 2 );

/**
 * MOTOR UNIVERSAL STICKY ARRIBA / ABAJO / FIXED (v1.2.0)
 * Inyecta tanto clases CSS como atributos data-mj-sticky-* para que el motor híbrido JS+CSS
 * fije cualquier Fila, Grupo, Columna, Grilla o Header aunque esté dentro de una Parte de Plantilla (<header>).
 */
function mj_pro_apply_sticky_attributes_to_block( $block_content, $block ) {
    if ( empty( $block['attrs']['mjStickyMode'] ) || 'none' === $block['attrs']['mjStickyMode'] ) {
        return $block_content;
    }

    $mode     = sanitize_key( $block['attrs']['mjStickyMode'] ); // sticky-top | sticky-bottom | fixed-top | fixed-bottom
    $offset   = isset( $block['attrs']['mjStickyOffset'] ) ? intval( $block['attrs']['mjStickyOffset'] ) : 0;
    $z_index  = isset( $block['attrs']['mjStickyZIndex'] ) ? intval( $block['attrs']['mjStickyZIndex'] ) : 90;
    $device   = ! empty( $block['attrs']['mjStickyDevice'] ) ? sanitize_key( $block['attrs']['mjStickyDevice'] ) : 'all';
    $backdrop = ! empty( $block['attrs']['mjStickyBackdrop'] );

    $classes = array(
        'mj-has-sticky',
        'mj-pos-' . $mode,
        'mj-sticky-device-' . $device,
    );
    if ( $backdrop ) {
        $classes[] = 'mj-sticky-glass';
    }

    $style_vars = sprintf(
        '--mj-sticky-offset:%dpx;--mj-sticky-z:%d;',
        $offset,
        $z_index
    );

    if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
        $processor = new WP_HTML_Tag_Processor( $block_content );
        if ( $processor->next_tag() ) {
            foreach ( $classes as $cls ) {
                $processor->add_class( $cls );
            }
            $processor->set_attribute( 'data-mj-sticky-mode', $mode );
            $processor->set_attribute( 'data-mj-sticky-offset', (string) $offset );
            $processor->set_attribute( 'data-mj-sticky-z', (string) $z_index );
            $processor->set_attribute( 'data-mj-sticky-device', $device );
            $processor->set_attribute( 'data-mj-sticky-glass', $backdrop ? '1' : '0' );

            $existing_style = $processor->get_attribute( 'style' );
            $new_style      = $existing_style ? rtrim( $existing_style, ';' ) . ';' . $style_vars : $style_vars;
            $processor->set_attribute( 'style', $new_style );
            return $processor->get_updated_html();
        }
    }

    return sprintf(
        '<div class="%s" data-mj-sticky-mode="%s" data-mj-sticky-offset="%d" data-mj-sticky-z="%d" data-mj-sticky-device="%s" data-mj-sticky-glass="%s" style="%s">%s</div>',
        esc_attr( implode( ' ', $classes ) ),
        esc_attr( $mode ),
        $offset,
        $z_index,
        esc_attr( $device ),
        $backdrop ? '1' : '0',
        esc_attr( $style_vars ),
        $block_content
    );
}
add_filter( 'render_block', 'mj_pro_apply_sticky_attributes_to_block', 10, 2 );

require_once MJ_PRO_DIR . '/inc/render-blocks.php';
require_once MJ_PRO_DIR . '/inc/ajax-endpoints.php';

add_shortcode( 'mj_multistep_checkout', 'mj_pro_render_multistep_checkout' );
