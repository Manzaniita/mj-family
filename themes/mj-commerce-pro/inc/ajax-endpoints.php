<?php
/**
 * Endpoints AJAX Enterprise v1.2.0:
 * 1) Buscador en Vivo Avanzado (Categorías, Marcas, SKU, EAN/GTIN, Títulos, Precios y Stock)
 * 2) Agregar al Carrito Rápido con 1 Clic (Desde el Buscador y desde las Tarjetas de Producto)
 * 3) Filtrado Instantáneo de Catálogo respetando la configuración de colores/precios/badges de las tarjetas
 *
 * @package MJ_Commerce_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Endpoint 1: Buscador Pro en Vivo (Categorías + Marcas + SKU + EAN + Título)
 */
function mj_pro_ajax_live_search_handler() {
    global $wpdb;

    $term          = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
    $max_results   = max( 1, min( 20, intval( $_POST['maxResults'] ?? 8 ) ) );
    $transfer_pct  = floatval( $_POST['transferPct'] ?? 15 );
    $only_stock    = ! empty( $_POST['onlyStock'] ) && '1' === $_POST['onlyStock'];

    if ( strlen( $term ) < 2 ) {
        wp_send_json_success( array( 'terms' => array(), 'items' => array() ) );
    }

    $like_term = '%' . $wpdb->esc_like( $term ) . '%';

    // A) Buscar Categorías, Marcas y Atributos directamente por SQL (encuentra cualquier categoría como iPhone o marca como Elfbar sin depender de hooks)
    $matched_terms  = array();
    $matched_tt_ids = array();

    $raw_terms = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.count, tt.term_taxonomy_id
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             WHERE (t.name LIKE %s OR t.slug LIKE %s)
               AND tt.taxonomy NOT IN ('nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'product_type', 'product_visibility', 'product_shipping_class')
             ORDER BY tt.count DESC
             LIMIT 8",
            $like_term,
            $like_term
        )
    );

    if ( ! empty( $raw_terms ) ) {
        foreach ( $raw_terms as $t_row ) {
            $matched_tt_ids[] = intval( $t_row->term_taxonomy_id );
            $t_link = get_term_link( intval( $t_row->term_id ), $t_row->taxonomy );
            $is_brand = ( strpos( $t_row->taxonomy, 'brand' ) !== false || strpos( $t_row->taxonomy, 'marca' ) !== false );
            $type_label = 'Categoría';
            if ( $is_brand ) {
                $type_label = 'Marca';
            } elseif ( 'product_tag' === $t_row->taxonomy ) {
                $type_label = 'Etiqueta';
            } elseif ( strpos( $t_row->taxonomy, 'pa_' ) === 0 ) {
                $type_label = 'Atributo';
            }
            $matched_terms[] = array(
                'id'    => intval( $t_row->term_id ),
                'name'  => $t_row->name,
                'count' => intval( $t_row->count ),
                'type'  => $type_label,
                'url'   => is_wp_error( $t_link ) ? '/?s=' . rawurlencode( $t_row->name ) . '&post_type=product' : $t_link,
            );
        }
    }

    // B) Buscar IDs de productos por:
    // 1. Coincidencia directa en Título, Slug, Extracto o Contenido (SQL LIKE directo para que prefijos de 2-3 letras como "iph" funcionen siempre)
    $by_title_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT ID
             FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status = 'publish'
               AND (post_title LIKE %s OR post_name LIKE %s OR post_excerpt LIKE %s OR post_content LIKE %s)
             LIMIT %d",
            $like_term,
            $like_term,
            $like_term,
            $like_term,
            $max_results * 2
        )
    );

    // 2. Meta fields: SKU (_sku) y Códigos EAN / GTIN / Barcode (_global_unique_id, _ean, _gtin, _barcode, _alg_ean)
    $meta_product_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status = 'publish'
               AND pm.meta_key IN ('_sku', '_global_unique_id', '_ean', '_gtin', '_barcode', '_alg_ean', '_wpm_gtin_code')
               AND pm.meta_value LIKE %s
             LIMIT %d",
            $like_term,
            $max_results * 2
        )
    );

    $normalized_meta_ids = array();
    if ( ! empty( $meta_product_ids ) ) {
        foreach ( $meta_product_ids as $pid ) {
            $parent_id = wp_get_post_parent_id( $pid );
            $normalized_meta_ids[] = $parent_id ? intval( $parent_id ) : intval( $pid );
        }
    }

    // 3. Productos que pertenecen a las Categorías o Marcas encontradas (ej. categoría "iPhone") via SQL directo libre de errores de tax_query
    $by_tax_ids = array();
    if ( ! empty( $matched_tt_ids ) ) {
        $tt_placeholders = implode( ',', array_map( 'intval', $matched_tt_ids ) );
        $by_tax_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                 WHERE p.post_type = 'product'
                   AND p.post_status = 'publish'
                   AND tr.term_taxonomy_id IN ($tt_placeholders)
                 LIMIT %d",
                $max_results * 2
            )
        );
    }

    $all_ids = array_values( array_unique( array_merge(
        is_array( $normalized_meta_ids ) ? $normalized_meta_ids : array(),
        is_array( $by_title_ids ) ? $by_title_ids : array(),
        is_array( $by_tax_ids ) ? $by_tax_ids : array()
    ) ) );
    $all_ids = array_map( 'intval', $all_ids );

    $items = array();
    $skipped_oos_count = 0;
    if ( function_exists( 'wc_get_product' ) && ! empty( $all_ids ) ) {
        foreach ( $all_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }
            $thumb_url = get_the_post_thumbnail_url( $product_id, 'woocommerce_thumbnail' );
            $price     = (float) $product->get_price();
            $transfer  = $price * ( 1 - ( $transfer_pct / 100 ) );
            $sku       = $product->get_sku();

            // EAN / GTIN
            $ean = '';
            if ( method_exists( $product, 'get_global_unique_id' ) ) {
                $ean = (string) $product->get_global_unique_id();
            }
            if ( empty( $ean ) ) {
                foreach ( array( '_global_unique_id', '_ean', '_gtin', '_barcode', '_alg_ean' ) as $ean_key ) {
                    $val = get_post_meta( $product_id, $ean_key, true );
                    if ( ! empty( $val ) ) {
                        $ean = (string) $val;
                        break;
                    }
                }
            }

            // Categoría principal y Marca
            $cat_names = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
            $cat_label = ( ! is_wp_error( $cat_names ) && ! empty( $cat_names ) ) ? $cat_names[0] : '';

            $brand_label = '';
            foreach ( array( 'product_brand', 'pa_marca', 'pwb-brand', 'yith_product_brand' ) as $b_tax ) {
                if ( taxonomy_exists( $b_tax ) ) {
                    $b_names = wp_get_post_terms( $product_id, $b_tax, array( 'fields' => 'names' ) );
                    if ( ! is_wp_error( $b_names ) && ! empty( $b_names ) ) {
                        $brand_label = $b_names[0];
                        break;
                    }
                }
            }

            $in_stock     = $product->is_in_stock();
            if ( $only_stock && ! $in_stock ) {
                $skipped_oos_count++;
                continue;
            }
            $can_quickadd = $in_stock && $product->is_type( 'simple' ) && $product->is_purchasable();

            $items[] = array(
                'id'            => $product_id,
                'title'         => $product->get_name(),
                'url'           => get_permalink( $product_id ),
                'priceHtml'     => $product->get_price_html(),
                'priceClean'    => html_entity_decode( wp_strip_all_tags( $product->get_price_html() ) ),
                'transferPrice' => $price > 0 ? '$' . number_format( $transfer, 0, ',', '.' ) : '',
                'transferPct'   => $transfer_pct,
                'sku'           => $sku ? $sku : '',
                'ean'           => $ean ? $ean : '',
                'category'      => $cat_label,
                'brand'         => $brand_label,
                'thumb'         => $thumb_url ? $thumb_url : '',
                'inStock'       => $in_stock,
                'isOnSale'      => $product->is_on_sale(),
                'canQuickAdd'   => $can_quickadd,
            );
            if ( count( $items ) >= $max_results ) {
                break;
            }
        }
    }

    wp_send_json_success( array(
        'terms'           => $matched_terms,
        'items'           => $items,
        'skippedOosCount' => $skipped_oos_count,
    ) );
}
add_action( 'wp_ajax_mj_pro_live_search', 'mj_pro_ajax_live_search_handler' );
add_action( 'wp_ajax_nopriv_mj_pro_live_search', 'mj_pro_ajax_live_search_handler' );

/**
 * Endpoint 2: Agregar al Carrito Rápido por AJAX (Desde el Buscador o Tarjetas de Producto)
 */
function mj_pro_ajax_quick_add_to_cart_handler() {
    $product_id = intval( $_POST['productId'] ?? 0 );
    $qty        = max( 1, intval( $_POST['quantity'] ?? 1 ) );

    if ( ! $product_id || ! function_exists( 'WC' ) || ! WC()->cart ) {
        wp_send_json_error( array( 'message' => 'Carrito no disponible.' ) );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product || ! $product->is_in_stock() ) {
        wp_send_json_error( array( 'message' => 'Producto sin stock.' ) );
    }

    $added = WC()->cart->add_to_cart( $product_id, $qty );
    if ( ! $added ) {
        wp_send_json_error( array( 'message' => 'No se pudo agregar al carrito.' ) );
    }

    $cart_count = intval( WC()->cart->get_cart_contents_count() );
    $cart_total = html_entity_decode( wp_strip_all_tags( WC()->cart->get_cart_subtotal() ) );

    wp_send_json_success( array(
        'cartCount' => $cart_count,
        'cartTotal' => $cart_total,
        'message'   => '¡Agregado al carrito!',
    ) );
}
add_action( 'wp_ajax_mj_pro_quick_add_to_cart', 'mj_pro_ajax_quick_add_to_cart_handler' );
add_action( 'wp_ajax_nopriv_mj_pro_quick_add_to_cart', 'mj_pro_ajax_quick_add_to_cart_handler' );

/**
 * Endpoint 3: Filtrado Instantáneo de Catálogo sin Recargar Página
 */
function mj_pro_ajax_filter_catalog_handler() {
    $cat         = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );
    $sort        = sanitize_text_field( wp_unslash( $_POST['sort'] ?? 'date' ) );
    $only_sale   = ! empty( $_POST['onlySale'] ) && '1' === $_POST['onlySale'];
    $only_stock  = ! isset( $_POST['onlyStock'] ) || '1' === $_POST['onlyStock'];
    $per_page    = max( 1, min( 24, intval( $_POST['perPage'] ?? 8 ) ) );
    $raw_config  = wp_unslash( $_POST['cardConfig'] ?? '{}' );
    $card_config = json_decode( $raw_config, true );
    if ( ! is_array( $card_config ) ) {
        $card_config = array();
    }

    $cols = max( 1, min( 6, intval( $card_config['columns'] ?? 4 ) ) );

    $args = array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
    );

    if ( ! empty( $cat ) ) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $cat,
            ),
        );
    }

    if ( $only_sale && function_exists( 'wc_get_product_ids_on_sale' ) ) {
        $sale_ids         = wc_get_product_ids_on_sale();
        $args['post__in'] = ! empty( $sale_ids ) ? $sale_ids : array( 0 );
    }

    if ( 'price-asc' === $sort ) {
        $args['meta_key'] = '_price';
        $args['orderby']  = 'meta_value_num';
        $args['order']    = 'ASC';
    } elseif ( 'price-desc' === $sort ) {
        $args['meta_key'] = '_price';
        $args['orderby']  = 'meta_value_num';
        $args['order']    = 'DESC';
    } else {
        $args['orderby'] = 'date';
        $args['order']   = 'DESC';
    }

    $products = get_posts( $args );

    ob_start();
    ?>
    <div class="mj-products-grid" style="--mj-grid-cols: <?php echo esc_attr( $cols ); ?>;">
        <?php if ( ! empty( $products ) && function_exists( 'wc_get_product' ) ) : ?>
            <?php foreach ( $products as $post_item ) :
                $product = wc_get_product( $post_item->ID );
                if ( ! $product ) continue;
                echo mj_pro_render_single_product_card_html( $product, $card_config );
            endforeach; ?>
        <?php else : ?>
            <p class="mj-empty-results">No se encontraron productos con esos filtros.</p>
        <?php endif; ?>
    </div>
    <?php
    $html = ob_get_clean();
    wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_mj_pro_filter_catalog', 'mj_pro_ajax_filter_catalog_handler' );
add_action( 'wp_ajax_nopriv_mj_pro_filter_catalog', 'mj_pro_ajax_filter_catalog_handler' );
