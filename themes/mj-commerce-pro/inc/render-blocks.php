<?php
/**
 * Registro de Bloques Personalizados React y Renderizado Dinámico en Servidor (v1.2.0)
 *
 * @package MJ_Commerce_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function mj_pro_register_custom_blocks() {

    $sticky_attrs = array(
        'mjStickyMode'     => array( 'type' => 'string', 'default' => 'none' ),
        'mjStickyOffset'   => array( 'type' => 'number', 'default' => 0 ),
        'mjStickyZIndex'   => array( 'type' => 'number', 'default' => 90 ),
        'mjStickyDevice'   => array( 'type' => 'string', 'default' => 'all' ),
        'mjStickyBackdrop' => array( 'type' => 'boolean', 'default' => false ),
    );

    // Atributos compartidos de Tarjetas de Producto (Badges Sin Stock / Oferta + Precios por Método de Pago + Colores)
    $card_config_attrs = array(
        'showOutOfStockBadge'   => array( 'type' => 'boolean', 'default' => true ),
        'outOfStockText'        => array( 'type' => 'string', 'default' => 'SIN STOCK' ),
        'outOfStockBgColor'     => array( 'type' => 'string', 'default' => '#dc2626' ),
        'outOfStockTextColor'   => array( 'type' => 'string', 'default' => '#ffffff' ),
        'showSaleBadge'         => array( 'type' => 'boolean', 'default' => true ),
        'saleBadgeText'         => array( 'type' => 'string', 'default' => 'OFERTA' ),
        'saleBadgeShowPct'      => array( 'type' => 'boolean', 'default' => true ),
        'saleBadgeBgColor'      => array( 'type' => 'string', 'default' => '#ea580c' ),
        'saleBadgeTextColor'    => array( 'type' => 'string', 'default' => '#ffffff' ),
        'showTransferPrice'     => array( 'type' => 'boolean', 'default' => true ),
        'transferDiscountPct'   => array( 'type' => 'number', 'default' => 15 ),
        'transferLabel'         => array( 'type' => 'string', 'default' => 'con Transferencia / Efectivo' ),
        'transferTextColor'     => array( 'type' => 'string', 'default' => '#15803d' ),
        'transferBgColor'       => array( 'type' => 'string', 'default' => '#dcfce7' ),
        'showCashPrice'         => array( 'type' => 'boolean', 'default' => false ),
        'cashDiscountPct'       => array( 'type' => 'number', 'default' => 20 ),
        'cashLabel'             => array( 'type' => 'string', 'default' => 'abonando en Efectivo en local' ),
        'cashTextColor'         => array( 'type' => 'string', 'default' => '#0369a1' ),
        'cashBgColor'           => array( 'type' => 'string', 'default' => '#e0f2fe' ),
        'showInstallments'      => array( 'type' => 'boolean', 'default' => true ),
        'installmentsCount'     => array( 'type' => 'number', 'default' => 3 ),
        'installmentsSurcharge' => array( 'type' => 'number', 'default' => 0 ),
        'installmentsLabel'     => array( 'type' => 'string', 'default' => 'cuotas sin interés de' ),
        'installmentsTextColor' => array( 'type' => 'string', 'default' => '#334155' ),
        'enableAjaxQuickAdd'    => array( 'type' => 'boolean', 'default' => true ),
        'onlyInStock'           => array( 'type' => 'boolean', 'default' => true ),
        'showStockToggle'       => array( 'type' => 'boolean', 'default' => true ),
        'defaultOnlyInStock'    => array( 'type' => 'boolean', 'default' => true ),
    );

    // BLOQUE 1: Buscador Pro en Vivo con AJAX v2 (Categorías, Marcas, SKU, EAN, Precios y Agregar al Carrito Rápido)
    register_block_type( 'mj-pro/ajax-live-search', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, array(
            'placeholder'         => array( 'type' => 'string', 'default' => 'Buscar por producto, categoría, marca, SKU o código EAN...' ),
            'maxResults'          => array( 'type' => 'number', 'default' => 8 ),
            'showCategoriesBrands'=> array( 'type' => 'boolean', 'default' => true ),
            'showPrice'           => array( 'type' => 'boolean', 'default' => true ),
            'showTransferPrice'   => array( 'type' => 'boolean', 'default' => true ),
            'transferDiscountPct' => array( 'type' => 'number', 'default' => 15 ),
            'showSkuEan'          => array( 'type' => 'boolean', 'default' => true ),
            'showThumb'           => array( 'type' => 'boolean', 'default' => true ),
            'showQuickAddBtn'     => array( 'type' => 'boolean', 'default' => true ),
            'onlyInStock'         => array( 'type' => 'boolean', 'default' => true ),
            'buttonLabel'         => array( 'type' => 'string', 'default' => 'Buscar' ),
            'accentColor'         => array( 'type' => 'string', 'default' => '#2563eb' ),
        ) ),
        'render_callback' => 'mj_pro_render_ajax_live_search',
    ) );

    // BLOQUE 2: Hero Slider / Banner Adaptable Desktop & Mobile
    register_block_type( 'mj-pro/hero-slider', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, array(
            'badgeText'       => array( 'type' => 'string', 'default' => 'ENVÍOS A TODO EL PAÍS · MAYORISTA Y MINORISTA' ),
            'title'           => array( 'type' => 'string', 'default' => 'Tecnología, Importados y Descartables al Mejor Precio' ),
            'subtitle'        => array( 'type' => 'string', 'default' => 'Aprovechá descuentos exclusivos abonando por transferencia y cuotas sin interés en todo el catálogo.' ),
            'primaryBtnText'  => array( 'type' => 'string', 'default' => 'Ver Catálogo Completo' ),
            'primaryBtnUrl'   => array( 'type' => 'string', 'default' => '/tienda/' ),
            'secondaryBtnText'=> array( 'type' => 'string', 'default' => 'Ofertas Relámpago' ),
            'secondaryBtnUrl' => array( 'type' => 'string', 'default' => '#ofertas-relampago' ),
            'bgGradient'      => array( 'type' => 'string', 'default' => 'linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%)' ),
            'minHeightPx'     => array( 'type' => 'number', 'default' => 420 ),
        ) ),
        'render_callback' => 'mj_pro_render_hero_slider',
    ) );

    // BLOQUE 3: Barra de Confianza / Beneficios Configurables
    register_block_type( 'mj-pro/trust-benefits-bar', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, array(
            'item1Title'      => array( 'type' => 'string', 'default' => 'Envíos a Todo el País' ),
            'item1Desc'       => array( 'type' => 'string', 'default' => 'Despachamos en el día por Correo y Expresos' ),
            'item2Title'      => array( 'type' => 'string', 'default' => 'Descuento por Transferencia' ),
            'item2Desc'       => array( 'type' => 'string', 'default' => 'Precio especial automático en cada producto' ),
            'item3Title'      => array( 'type' => 'string', 'default' => 'Cuotas y Medios de Pago' ),
            'item3Desc'       => array( 'type' => 'string', 'default' => 'Aceptamos Mercado Pago, tarjetas y efectivo' ),
            'item4Title'      => array( 'type' => 'string', 'default' => 'Atención Personalizada' ),
            'item4Desc'       => array( 'type' => 'string', 'default' => 'Asesoramiento directo por WhatsApp' ),
        ) ),
        'render_callback' => 'mj_pro_render_trust_benefits_bar',
    ) );

    // BLOQUE 4: Calculador Argentino + Badges Sin Stock / Oferta + Precios Multimétodo (Para Tarjetas y Ficha de Producto)
    register_block_type( 'mj-pro/arg-payment-calculator', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, $card_config_attrs, array(
            'installmentsSurchargePct' => array( 'type' => 'number', 'default' => 0 ),
            'showWhatsappBtn'     => array( 'type' => 'boolean', 'default' => true ),
            'whatsappPhone'       => array( 'type' => 'string', 'default' => '5491100000000' ),
            'whatsappBtnText'     => array( 'type' => 'string', 'default' => 'Consultar por WhatsApp' ),
            'compactMode'         => array( 'type' => 'boolean', 'default' => false ),
            'fallbackPrice'       => array( 'type' => 'number', 'default' => 25000 ),
        ) ),
        'render_callback' => 'mj_pro_render_arg_payment_calculator',
    ) );

    // BLOQUE 5: Carrusel / Grilla de Ofertas Relámpago con Tarjetas Configurables
    register_block_type( 'mj-pro/flash-deals-carousel', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, $card_config_attrs, array(
            'sectionTitle'    => array( 'type' => 'string', 'default' => '⚡ Ofertas Relámpago de la Semana' ),
            'countdownHours'  => array( 'type' => 'number', 'default' => 12 ),
            'productsCount'   => array( 'type' => 'number', 'default' => 4 ),
            'columns'         => array( 'type' => 'number', 'default' => 4 ),
            'onlyOnSale'      => array( 'type' => 'boolean', 'default' => false ),
        ) ),
        'render_callback' => 'mj_pro_render_flash_deals_carousel',
    ) );

    // BLOQUE 6: Grilla Pro de Categorías de WooCommerce
    register_block_type( 'mj-pro/category-grid-pro', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, array(
            'sectionTitle'    => array( 'type' => 'string', 'default' => 'Explorá por Categoría' ),
            'columns'         => array( 'type' => 'number', 'default' => 4 ),
            'hideEmpty'       => array( 'type' => 'boolean', 'default' => false ),
            'showCount'       => array( 'type' => 'boolean', 'default' => true ),
        ) ),
        'render_callback' => 'mj_pro_render_category_grid_pro',
    ) );

    // BLOQUE 7: Catálogo Interactivo con Filtros Rápidos AJAX y Tarjetas 100% Configurables
    register_block_type( 'mj-pro/ajax-catalog-filter', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, $card_config_attrs, array(
            'title'           => array( 'type' => 'string', 'default' => 'Catálogo con Filtrado Instantáneo' ),
            'productsPerPage' => array( 'type' => 'number', 'default' => 8 ),
            'columns'         => array( 'type' => 'number', 'default' => 4 ),
            'showSortSelector'=> array( 'type' => 'boolean', 'default' => true ),
            'showSaleToggle'  => array( 'type' => 'boolean', 'default' => true ),
        ) ),
        'render_callback' => 'mj_pro_render_ajax_catalog_filter',
    ) );

    // BLOQUE 8: Barra de Navegación Inferior Celular (Sticky Bottom App Bar)
    register_block_type( 'mj-pro/mobile-bottom-nav', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, array(
            'showOnDesktop'   => array( 'type' => 'boolean', 'default' => false ),
            'bgColor'         => array( 'type' => 'string', 'default' => '#ffffff' ),
            'textColor'       => array( 'type' => 'string', 'default' => '#0f172a' ),
            'accentColor'     => array( 'type' => 'string', 'default' => '#2563eb' ),
            'glassEffect'     => array( 'type' => 'boolean', 'default' => true ),
            'item1Show'       => array( 'type' => 'boolean', 'default' => true ),
            'item1Icon'       => array( 'type' => 'string', 'default' => '🏠' ),
            'item1Label'      => array( 'type' => 'string', 'default' => 'Inicio' ),
            'item1Url'        => array( 'type' => 'string', 'default' => '/' ),
            'item2Show'       => array( 'type' => 'boolean', 'default' => true ),
            'item2Icon'       => array( 'type' => 'string', 'default' => '📦' ),
            'item2Label'      => array( 'type' => 'string', 'default' => 'Tienda' ),
            'item2Url'        => array( 'type' => 'string', 'default' => '/tienda/' ),
            'item3Show'       => array( 'type' => 'boolean', 'default' => true ),
            'item3Icon'       => array( 'type' => 'string', 'default' => '🔍' ),
            'item3Label'      => array( 'type' => 'string', 'default' => 'Buscar' ),
            'item3OpenSearch' => array( 'type' => 'boolean', 'default' => true ),
            'item3Url'        => array( 'type' => 'string', 'default' => '/?s=&post_type=product' ),
            'item4Show'       => array( 'type' => 'boolean', 'default' => true ),
            'item4Icon'       => array( 'type' => 'string', 'default' => '🛒' ),
            'item4Label'      => array( 'type' => 'string', 'default' => 'Carrito' ),
            'item4Url'        => array( 'type' => 'string', 'default' => '/carrito/' ),
            'item4ShowBadge'  => array( 'type' => 'boolean', 'default' => true ),
            'item5Show'       => array( 'type' => 'boolean', 'default' => true ),
            'item5Icon'       => array( 'type' => 'string', 'default' => '👤' ),
            'item5Label'      => array( 'type' => 'string', 'default' => 'Mi Cuenta' ),
            'item5Url'        => array( 'type' => 'string', 'default' => '/mi-cuenta/' ),
        ) ),
        'render_callback' => 'mj_pro_render_mobile_bottom_nav',
    ) );

    // BLOQUE 9: Checkout Modular por Pasos (100% Configurable, con recálculo dinámico de envíos y pagos)
    register_block_type( 'mj-pro/multistep-checkout', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, array(
            'step1Title'         => array( 'type' => 'string', 'default' => 'Tus Datos y Entrega' ),
            'step1Subtitle'      => array( 'type' => 'string', 'default' => 'Ingresá tu contacto y dirección para calcular el envío' ),
            'step1Icon'          => array( 'type' => 'string', 'default' => '👤' ),
            'step2Title'         => array( 'type' => 'string', 'default' => 'Método de Envío' ),
            'step2Subtitle'      => array( 'type' => 'string', 'default' => 'Seleccioná la opción de entrega que prefieras' ),
            'step2Icon'          => array( 'type' => 'string', 'default' => '🚚' ),
            'step3Title'         => array( 'type' => 'string', 'default' => 'Pago y Confirmación' ),
            'step3Subtitle'      => array( 'type' => 'string', 'default' => 'Elegí cómo abonar y finalizá tu pedido' ),
            'step3Icon'          => array( 'type' => 'string', 'default' => '💳' ),
            'trustBadgeText'     => array( 'type' => 'string', 'default' => '🔒 Compra 100% Segura y Protegida · Envíos a todo el país' ),
            'showSidebarSummary' => array( 'type' => 'boolean', 'default' => true ),
            'accentColor'        => array( 'type' => 'string', 'default' => '#0284c7' ),
            'cyanColor'          => array( 'type' => 'string', 'default' => '#06b6d4' ),
        ) ),
        'render_callback' => 'mj_pro_render_multistep_checkout',
    ) );

    // BLOQUE 10: MJ Dólar — Cotización y Ticker en Vivo
    register_block_type( 'mj-pro/dolar-ticker', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, array(
            'styleMode'        => array( 'type' => 'string', 'default' => 'bar' ),
            'titleText'        => array( 'type' => 'string', 'default' => 'Cotización USD:' ),
            'rateTypeLabel'    => array( 'type' => 'string', 'default' => 'Tipo de Cambio' ),
            'showRateType'     => array( 'type' => 'boolean', 'default' => true ),
            'customRate'       => array( 'type' => 'number', 'default' => 0 ),
            'linkUrl'          => array( 'type' => 'string', 'default' => '' ),
            'bgColor'          => array( 'type' => 'string', 'default' => '' ),
            'textColor'        => array( 'type' => 'string', 'default' => '' ),
            'accentColor'      => array( 'type' => 'string', 'default' => '#16a34a' ),
        ) ),
        'render_callback' => 'mj_pro_render_dolar_ticker',
    ) );

    // BLOQUE 11: MJ ERP — Acceso Rápido y Panel de Control
    register_block_type( 'mj-pro/erp-launcher', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, array(
            'target'        => array( 'type' => 'string', 'default' => 'admin' ),
            'customUrl'     => array( 'type' => 'string', 'default' => '' ),
            'buttonLabel'   => array( 'type' => 'string', 'default' => 'Acceder a MJ ERP' ),
            'subText'       => array( 'type' => 'string', 'default' => 'Ventas, stock y caja' ),
            'showIcon'      => array( 'type' => 'boolean', 'default' => true ),
            'badgeText'     => array( 'type' => 'string', 'default' => 'Operadores' ),
            'layoutMode'    => array( 'type' => 'string', 'default' => 'button' ),
            'bgColor'       => array( 'type' => 'string', 'default' => '#0f172a' ),
            'textColor'     => array( 'type' => 'string', 'default' => '#ffffff' ),
            'accentColor'   => array( 'type' => 'string', 'default' => '#3b82f6' ),
            'requireCap'    => array( 'type' => 'string', 'default' => 'manage_mjerp' ),
        ) ),
        'render_callback' => 'mj_pro_render_erp_launcher',
    ) );

    // BLOQUE 12: MJ Medios de Pago — Grilla de Métodos y Promociones
    register_block_type( 'mj-pro/payment-methods-grid', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, array(
            'usePluginSettings'   => array( 'type' => 'boolean', 'default' => true ),
            'title'               => array( 'type' => 'string', 'default' => 'Medios de Pago y Promociones' ),
            'subtitle'            => array( 'type' => 'string', 'default' => 'Aboná con todas las opciones disponibles' ),
            'showTransfer'        => array( 'type' => 'boolean', 'default' => true ),
            'transferDiscountPct' => array( 'type' => 'number', 'default' => 15 ),
            'transferAlias'       => array( 'type' => 'string', 'default' => '' ),
            'showCash'            => array( 'type' => 'boolean', 'default' => true ),
            'cashDiscountPct'     => array( 'type' => 'number', 'default' => 20 ),
            'showInstallments'    => array( 'type' => 'boolean', 'default' => true ),
            'installmentsCount'   => array( 'type' => 'number', 'default' => 3 ),
            'showCards'           => array( 'type' => 'boolean', 'default' => true ),
            'columns'             => array( 'type' => 'number', 'default' => 3 ),
        ) ),
        'render_callback' => 'mj_pro_render_payment_methods_grid',
    ) );

    // BLOQUE 13: MJ Envíos — Calculador Rápido por Código Postal
    register_block_type( 'mj-pro/shipping-calculator', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, array(
            'title'                 => array( 'type' => 'string', 'default' => 'Calculá el costo de envío' ),
            'placeholder'           => array( 'type' => 'string', 'default' => 'Ingresá tu código postal...' ),
            'buttonLabel'           => array( 'type' => 'string', 'default' => 'Calcular Envío' ),
            'freeShippingThreshold' => array( 'type' => 'number', 'default' => 0 ),
            'freeShippingNotice'    => array( 'type' => 'string', 'default' => '¡Envío gratis a todo el país superando este monto!' ),
        ) ),
        'render_callback' => 'mj_pro_render_shipping_calculator',
    ) );

    // BLOQUE 14: MJ Turnos Online — Reserva Directa de Servicios
    register_block_type( 'mj-pro/turnos-embed', array(
        'api_version'     => 2,
        'category'        => 'mj-commerce-pro',
        'attributes'      => array_merge( $sticky_attrs, array(
            'title'       => array( 'type' => 'string', 'default' => 'Reservá tu Turno Online' ),
            'subtitle'    => array( 'type' => 'string', 'default' => 'Elegí servicio, fecha y horario disponible' ),
            'accentColor' => array( 'type' => 'string', 'default' => '#ec4899' ),
        ) ),
        'render_callback' => 'mj_pro_render_turnos_embed',
    ) );
}
add_action( 'init', 'mj_pro_register_custom_blocks' );

/**
 * Helper: Obtener objeto WC_Product actual
 */
function mj_pro_get_current_product() {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return null;
    }
    global $product;
    if ( is_object( $product ) && method_exists( $product, 'get_price' ) ) {
        return $product;
    }
    $id = get_the_ID();
    if ( $id ) {
        $p = wc_get_product( $id );
        if ( is_object( $p ) ) {
            return $p;
        }
    }
    return null;
}

/**
 * Helper Maestro: Renderizar una Tarjeta de Producto Configurable (Stock, Oferta, Precios por Método de Pago, Colores y Quick Add AJAX)
 */
function mj_pro_render_single_product_card_html( $product, $attributes ) {
    if ( ! $product || ! is_object( $product ) ) {
        return '';
    }

    $product_id   = $product->get_id();
    $is_in_stock  = $product->is_in_stock();
    $is_on_sale   = $product->is_on_sale();
    $price        = (float) $product->get_price();
    $reg_price    = (float) $product->get_regular_price();

    // Config Badges
    $show_oos_badge = $attributes['showOutOfStockBadge'] ?? true;
    $oos_text       = $attributes['outOfStockText'] ?? 'SIN STOCK';
    $oos_bg         = $attributes['outOfStockBgColor'] ?? '#dc2626';
    $oos_color      = $attributes['outOfStockTextColor'] ?? '#ffffff';

    $show_sale_badge = $attributes['showSaleBadge'] ?? true;
    $sale_text       = $attributes['saleBadgeText'] ?? 'OFERTA';
    $sale_show_pct   = $attributes['saleBadgeShowPct'] ?? true;
    $sale_bg         = $attributes['saleBadgeBgColor'] ?? '#ea580c';
    $sale_color      = $attributes['saleBadgeTextColor'] ?? '#ffffff';

    // Config Precios por Método de Pago
    $show_transfer   = $attributes['showTransferPrice'] ?? true;
    $transfer_pct    = floatval( $attributes['transferDiscountPct'] ?? 15 );
    $transfer_label  = $attributes['transferLabel'] ?? 'con Transferencia / Efectivo';
    $transfer_color  = $attributes['transferTextColor'] ?? '#15803d';
    $transfer_bg     = $attributes['transferBgColor'] ?? '#dcfce7';

    $show_cash       = ! empty( $attributes['showCashPrice'] );
    $cash_pct        = floatval( $attributes['cashDiscountPct'] ?? 20 );
    $cash_label      = $attributes['cashLabel'] ?? 'abonando en Efectivo en local';
    $cash_color      = $attributes['cashTextColor'] ?? '#0369a1';
    $cash_bg         = $attributes['cashBgColor'] ?? '#e0f2fe';

    $show_inst       = $attributes['showInstallments'] ?? true;
    $inst_count      = max( 1, intval( $attributes['installmentsCount'] ?? 3 ) );
    $inst_surcharge  = floatval( $attributes['installmentsSurcharge'] ?? ( $attributes['installmentsSurchargePct'] ?? 0 ) );
    $inst_label      = $attributes['installmentsLabel'] ?? 'cuotas sin interés de';
    $inst_color      = $attributes['installmentsTextColor'] ?? '#334155';

    $quick_add       = $attributes['enableAjaxQuickAdd'] ?? true;

    $sale_pct_saved = 0;
    if ( $is_on_sale && $reg_price > 0 && $price < $reg_price ) {
        $sale_pct_saved = round( ( ( $reg_price - $price ) / $reg_price ) * 100 );
    }

    $transfer_price = $price * ( 1 - ( $transfer_pct / 100 ) );
    $cash_price     = $price * ( 1 - ( $cash_pct / 100 ) );
    $inst_price     = ( $price * ( 1 + ( $inst_surcharge / 100 ) ) ) / $inst_count;

    $sku = $product->get_sku();
    $cats = wc_get_product_category_list( $product_id, ', ' );

    ob_start();
    ?>
    <article class="mj-product-card <?php echo ! $is_in_stock ? 'is-out-of-stock' : ''; ?>">
        <div class="mj-card-media-wrap">
            <div class="mj-card-badges">
                <?php if ( ! $is_in_stock && $show_oos_badge ) : ?>
                    <span class="mj-badge mj-badge-oos" style="background:<?php echo esc_attr( $oos_bg ); ?>;color:<?php echo esc_attr( $oos_color ); ?>;">
                        <?php echo esc_html( $oos_text ); ?>
                    </span>
                <?php elseif ( $is_on_sale && $show_sale_badge ) : ?>
                    <span class="mj-badge mj-badge-sale" style="background:<?php echo esc_attr( $sale_bg ); ?>;color:<?php echo esc_attr( $sale_color ); ?>;">
                        <?php
                        echo esc_html( $sale_text );
                        if ( $sale_show_pct && $sale_pct_saved > 0 ) {
                            echo ' -' . intval( $sale_pct_saved ) . '%';
                        }
                        ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>" class="mj-card-thumb">
                <?php echo $product->get_image( 'woocommerce_thumbnail' ); ?>
            </a>
        </div>

        <div class="mj-card-body">
            <?php if ( $cats || $sku ) : ?>
                <div class="mj-card-eyebrow">
                    <?php if ( $cats ) : ?>
                        <span class="mj-card-cat"><?php echo wp_kses_post( wp_strip_all_tags( $cats ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $sku ) : ?>
                        <span class="mj-card-sku">SKU: <?php echo esc_html( $sku ); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h3 class="mj-card-title">
                <a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>">
                    <?php echo esc_html( $product->get_name() ); ?>
                </a>
            </h3>

            <div class="mj-card-price"><?php echo $product->get_price_html(); ?></div>

            <div class="mj-card-payment-methods">
                <?php if ( $price > 0 && $show_transfer && $transfer_pct > 0 ) : ?>
                    <div class="mj-card-pay-pill" style="background:<?php echo esc_attr( $transfer_bg ); ?>;color:<?php echo esc_attr( $transfer_color ); ?>;">
                        <span class="mj-pay-pct">-<?php echo esc_html( $transfer_pct ); ?>%</span>
                        <span class="mj-pay-text">
                            <strong>$<?php echo number_format( $transfer_price, 0, ',', '.' ); ?></strong> <?php echo esc_html( $transfer_label ); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ( $price > 0 && $show_cash && $cash_pct > 0 ) : ?>
                    <div class="mj-card-pay-pill" style="background:<?php echo esc_attr( $cash_bg ); ?>;color:<?php echo esc_attr( $cash_color ); ?>;">
                        <span class="mj-pay-pct">-<?php echo esc_html( $cash_pct ); ?>%</span>
                        <span class="mj-pay-text">
                            <strong>$<?php echo number_format( $cash_price, 0, ',', '.' ); ?></strong> <?php echo esc_html( $cash_label ); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ( $price > 0 && $show_inst && $inst_count > 1 ) : ?>
                    <div class="mj-card-inst-line" style="color:<?php echo esc_attr( $inst_color ); ?>;">
                        💳 <?php echo intval( $inst_count ); ?> <?php echo esc_html( $inst_label ); ?> <strong>$<?php echo number_format( $inst_price, 0, ',', '.' ); ?></strong>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mj-card-actions">
                <?php if ( ! $is_in_stock ) : ?>
                    <button type="button" class="mj-btn mj-btn-card is-disabled" disabled>
                        <?php echo esc_html( $oos_text ); ?>
                    </button>
                <?php elseif ( $quick_add && $product->is_type( 'simple' ) && $product->is_purchasable() ) : ?>
                    <button type="button"
                            class="mj-btn mj-btn-card js-mj-quick-add"
                            data-product-id="<?php echo esc_attr( $product_id ); ?>"
                            data-qty="1">
                        🛒 Agregar al carrito
                    </button>
                <?php else : ?>
                    <a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>" class="mj-btn mj-btn-card">
                        Ver opciones
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

/**
 * Render 1: Buscador Pro en Vivo con AJAX v2 (Categorías, Marcas, SKU, EAN, Precios y Quick Add)
 */
function mj_pro_render_ajax_live_search( $attributes ) {
    $placeholder     = esc_attr( $attributes['placeholder'] ?? 'Buscar por producto, categoría, marca, SKU o EAN...' );
    $max_results     = intval( $attributes['maxResults'] ?? 8 );
    $show_cats       = ! isset( $attributes['showCategoriesBrands'] ) || ! empty( $attributes['showCategoriesBrands'] ) ? '1' : '0';
    $show_price      = ! isset( $attributes['showPrice'] ) || ! empty( $attributes['showPrice'] ) ? '1' : '0';
    $show_transfer   = ! isset( $attributes['showTransferPrice'] ) || ! empty( $attributes['showTransferPrice'] ) ? '1' : '0';
    $transfer_pct    = floatval( $attributes['transferDiscountPct'] ?? 15 );
    $show_sku_ean    = ! isset( $attributes['showSkuEan'] ) || ! empty( $attributes['showSkuEan'] ) ? '1' : '0';
    $show_thumb      = ! isset( $attributes['showThumb'] ) || ! empty( $attributes['showThumb'] ) ? '1' : '0';
    $show_quick_add  = ! isset( $attributes['showQuickAddBtn'] ) || ! empty( $attributes['showQuickAddBtn'] ) ? '1' : '0';
    $only_in_stock   = ! isset( $attributes['onlyInStock'] ) || ! empty( $attributes['onlyInStock'] ) ? '1' : '0';
    $btn_label       = esc_html( $attributes['buttonLabel'] ?? 'Buscar' );
    $accent          = esc_attr( $attributes['accentColor'] ?? '#2563eb' );

    ob_start();
    ?>
    <div class="mj-ajax-search-wrap"
         data-max-results="<?php echo esc_attr( $max_results ); ?>"
         data-show-cats="<?php echo esc_attr( $show_cats ); ?>"
         data-show-price="<?php echo esc_attr( $show_price ); ?>"
         data-show-transfer="<?php echo esc_attr( $show_transfer ); ?>"
         data-transfer-pct="<?php echo esc_attr( $transfer_pct ); ?>"
         data-show-sku="<?php echo esc_attr( $show_sku_ean ); ?>"
         data-show-thumb="<?php echo esc_attr( $show_thumb ); ?>"
         data-show-quick-add="<?php echo esc_attr( $show_quick_add ); ?>"
         data-only-stock="<?php echo esc_attr( $only_in_stock ); ?>"
         style="--mj-search-accent: <?php echo $accent; ?>;">
        <form class="mj-ajax-search-form" role="search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
            <input type="hidden" name="post_type" value="product" />
            <span class="mj-search-icon-prefix" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="search"
                   name="s"
                   class="mj-ajax-search-input"
                   placeholder="<?php echo $placeholder; ?>"
                   autocomplete="off" />
            <button type="button" class="mj-search-clear-btn" aria-label="Limpiar búsqueda" hidden>✕</button>
            <button type="submit" class="mj-ajax-search-btn"><?php echo $btn_label; ?></button>
        </form>
        <div class="mj-ajax-search-results" aria-live="polite" hidden></div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render 2: Hero Slider / Banner
 */
function mj_pro_render_hero_slider( $attributes ) {
    $badge    = esc_html( $attributes['badgeText'] ?? '' );
    $title    = esc_html( $attributes['title'] ?? '' );
    $subtitle = esc_html( $attributes['subtitle'] ?? '' );
    $btn1_txt = esc_html( $attributes['primaryBtnText'] ?? 'Ver Catálogo' );
    $btn1_url = esc_url( $attributes['primaryBtnUrl'] ?? '/tienda/' );
    $btn2_txt = esc_html( $attributes['secondaryBtnText'] ?? '' );
    $btn2_url = esc_url( $attributes['secondaryBtnUrl'] ?? '#' );
    $bg       = esc_attr( $attributes['bgGradient'] ?? 'linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%)' );
    $height   = intval( $attributes['minHeightPx'] ?? 420 );

    ob_start();
    ?>
    <section class="mj-hero-banner" style="background: <?php echo $bg; ?>; min-height: <?php echo $height; ?>px;">
        <div class="mj-hero-inner">
            <?php if ( $badge ) : ?>
                <span class="mj-hero-badge"><?php echo $badge; ?></span>
            <?php endif; ?>
            <h1 class="mj-hero-title"><?php echo $title; ?></h1>
            <?php if ( $subtitle ) : ?>
                <p class="mj-hero-subtitle"><?php echo $subtitle; ?></p>
            <?php endif; ?>
            <div class="mj-hero-actions">
                <?php if ( $btn1_txt ) : ?>
                    <a href="<?php echo $btn1_url; ?>" class="mj-btn mj-btn-primary"><?php echo $btn1_txt; ?></a>
                <?php endif; ?>
                <?php if ( $btn2_txt ) : ?>
                    <a href="<?php echo $btn2_url; ?>" class="mj-btn mj-btn-outline"><?php echo $btn2_txt; ?></a>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/**
 * Render 3: Barra de Confianza / Beneficios
 */
function mj_pro_render_trust_benefits_bar( $attributes ) {
    $items = array(
        array( 'icon' => '🚚', 'title' => $attributes['item1Title'] ?? 'Envíos a Todo el País', 'desc' => $attributes['item1Desc'] ?? '' ),
        array( 'icon' => '💸', 'title' => $attributes['item2Title'] ?? 'Descuento por Transferencia', 'desc' => $attributes['item2Desc'] ?? '' ),
        array( 'icon' => '💳', 'title' => $attributes['item3Title'] ?? 'Cuotas y Medios de Pago', 'desc' => $attributes['item3Desc'] ?? '' ),
        array( 'icon' => '💬', 'title' => $attributes['item4Title'] ?? 'Atención por WhatsApp', 'desc' => $attributes['item4Desc'] ?? '' ),
    );

    ob_start();
    ?>
    <div class="mj-trust-bar">
        <?php foreach ( $items as $item ) : ?>
            <div class="mj-trust-item">
                <span class="mj-trust-icon" aria-hidden="true"><?php echo esc_html( $item['icon'] ); ?></span>
                <div class="mj-trust-text">
                    <strong><?php echo esc_html( $item['title'] ); ?></strong>
                    <span><?php echo esc_html( $item['desc'] ); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render 4: Calculador Argentino + Badges Sin Stock / Oferta + Precios por Método de Pago (En Tarjetas y Ficha de Producto)
 */
function mj_pro_render_arg_payment_calculator( $attributes ) {
    $product      = mj_pro_get_current_product();
    $fallback     = floatval( $attributes['fallbackPrice'] ?? 25000 );
    $base_price   = ( $product && $product->get_price() > 0 ) ? (float) $product->get_price() : $fallback;
    $reg_price    = ( $product && $product->get_regular_price() > 0 ) ? (float) $product->get_regular_price() : $base_price;
    $is_in_stock  = $product ? $product->is_in_stock() : true;
    $is_on_sale   = $product ? $product->is_on_sale() : false;

    // Badges
    $show_oos_badge  = $attributes['showOutOfStockBadge'] ?? true;
    $oos_text        = $attributes['outOfStockText'] ?? 'SIN STOCK';
    $oos_bg          = $attributes['outOfStockBgColor'] ?? '#dc2626';
    $oos_color       = $attributes['outOfStockTextColor'] ?? '#ffffff';

    $show_sale_badge = $attributes['showSaleBadge'] ?? true;
    $sale_text       = $attributes['saleBadgeText'] ?? 'OFERTA';
    $sale_show_pct   = $attributes['saleBadgeShowPct'] ?? true;
    $sale_bg         = $attributes['saleBadgeBgColor'] ?? '#ea580c';
    $sale_color      = $attributes['saleBadgeTextColor'] ?? '#ffffff';

    // Métodos de pago (con integración dinámica a MJ Medios de Pago)
    $show_transfer   = $attributes['showTransferPrice'] ?? true;
    $discount_pct    = floatval( $attributes['transferDiscountPct'] ?? 15 );
    $transfer_label  = $attributes['transferLabel'] ?? 'Abonando por Transferencia / Efectivo';
    $transfer_color  = $attributes['transferTextColor'] ?? '#15803d';
    $transfer_bg     = $attributes['transferBgColor'] ?? '#dcfce7';

    $show_cash       = ! empty( $attributes['showCashPrice'] );
    $cash_pct        = floatval( $attributes['cashDiscountPct'] ?? 20 );
    $cash_label      = $attributes['cashLabel'] ?? 'abonando en Efectivo en local';
    $cash_color      = $attributes['cashTextColor'] ?? '#0369a1';
    $cash_bg         = $attributes['cashBgColor'] ?? '#e0f2fe';

    // Leer ajustes de MJ Medios de Pago si existen y no hay valor manual específico
    $mjmp_opts = get_option( 'mjmp_settings', array() );
    if ( ! empty( $mjmp_opts['transfer_discount'] ) && ! isset( $attributes['transferDiscountPct'] ) ) {
        $discount_pct = floatval( $mjmp_opts['transfer_discount'] );
    }
    if ( ! empty( $mjmp_opts['cash_discount'] ) && ! isset( $attributes['cashDiscountPct'] ) ) {
        $cash_pct = floatval( $mjmp_opts['cash_discount'] );
    }

    $show_inst       = $attributes['showInstallments'] ?? true;
    $inst_count      = max( 1, intval( $attributes['installmentsCount'] ?? 3 ) );
    $inst_surcharge  = floatval( $attributes['installmentsSurcharge'] ?? ( $attributes['installmentsSurchargePct'] ?? 0 ) );
    $inst_label      = $attributes['installmentsLabel'] ?? 'cuotas sin interés de';
    $inst_color      = $attributes['installmentsTextColor'] ?? '#334155';

    // Integración con MJ Dólar: buscar precio en USD del producto
    $usd_price = null;
    if ( $product ) {
        $usd_sale = get_post_meta( $product->get_id(), '_mjd_usd_sale', true );
        $usd_reg  = get_post_meta( $product->get_id(), '_mjd_usd_regular', true );
        if ( ! empty( $usd_sale ) && floatval( $usd_sale ) > 0 ) {
            $usd_price = floatval( $usd_sale );
        } elseif ( ! empty( $usd_reg ) && floatval( $usd_reg ) > 0 ) {
            $usd_price = floatval( $usd_reg );
        }
    }

    $default_phone   = get_theme_mod( 'mj_whatsapp_number', get_option( 'mj_whatsapp_number', '' ) );
    $configured_phone= ! empty( $attributes['whatsappPhone'] ) ? $attributes['whatsappPhone'] : $default_phone;
    $wa_phone        = preg_replace( '/[^0-9]/', '', $configured_phone );
    $show_wa         = ! empty( $attributes['showWhatsappBtn'] ) && ! empty( $wa_phone );
    $wa_text         = $attributes['whatsappBtnText'] ?? 'Consultar por WhatsApp';
    $compact         = ! empty( $attributes['compactMode'] );

    $transfer_price  = $base_price * ( 1 - ( $discount_pct / 100 ) );
    $cash_price      = $base_price * ( 1 - ( $cash_pct / 100 ) );
    $total_financed  = $base_price * ( 1 + ( $inst_surcharge / 100 ) );
    $inst_value      = $total_financed / $inst_count;

    $sale_pct_saved = 0;
    if ( $is_on_sale && $reg_price > 0 && $base_price < $reg_price ) {
        $sale_pct_saved = round( ( ( $reg_price - $base_price ) / $reg_price ) * 100 );
    }

    $product_title = get_the_title() ? get_the_title() : 'este producto';
    $wa_message    = rawurlencode( 'Hola! Quiero consultar por ' . $product_title . ' (' . get_permalink() . ')' );
    $wa_url        = 'https://wa.me/' . $wa_phone . '?text=' . $wa_message;

    ob_start();
    ?>
    <div class="mj-arg-calc <?php echo $compact ? 'is-compact' : 'is-full'; ?>">
        <?php if ( ( ! $is_in_stock && $show_oos_badge ) || ( $is_on_sale && $show_sale_badge ) || $usd_price ) : ?>
            <div class="mj-calc-inline-badges">
                <?php if ( ! $is_in_stock && $show_oos_badge ) : ?>
                    <span class="mj-badge mj-badge-oos" style="background:<?php echo esc_attr( $oos_bg ); ?>;color:<?php echo esc_attr( $oos_color ); ?>;">
                        <?php echo esc_html( $oos_text ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( $is_on_sale && $show_sale_badge ) : ?>
                    <span class="mj-badge mj-badge-sale" style="background:<?php echo esc_attr( $sale_bg ); ?>;color:<?php echo esc_attr( $sale_color ); ?>;">
                        <?php
                        echo esc_html( $sale_text );
                        if ( $sale_show_pct && $sale_pct_saved > 0 ) {
                            echo ' -' . intval( $sale_pct_saved ) . '%';
                        }
                        ?>
                    </span>
                <?php endif; ?>
                <?php if ( $usd_price ) : ?>
                    <span class="mj-badge" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;">
                        💵 USD $<?php echo number_format( $usd_price, 2, ',', '.' ); ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $show_transfer && $discount_pct > 0 ) : ?>
            <div class="mj-arg-transfer-box" style="background:<?php echo esc_attr( $transfer_bg ); ?>;border-color:<?php echo esc_attr( $transfer_color ); ?>40;">
                <span class="mj-arg-discount-badge" style="background:<?php echo esc_attr( $transfer_color ); ?>;color:#fff;">
                    -<?php echo esc_html( $discount_pct ); ?>% OFF
                </span>
                <div class="mj-arg-transfer-details">
                    <span class="mj-arg-transfer-price" style="color:<?php echo esc_attr( $transfer_color ); ?>;">
                        $<?php echo number_format( $transfer_price, 0, ',', '.' ); ?>
                    </span>
                    <span class="mj-arg-transfer-label"><?php echo esc_html( $transfer_label ); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ( $show_cash && $cash_pct > 0 ) : ?>
            <div class="mj-arg-transfer-box" style="background:<?php echo esc_attr( $cash_bg ); ?>;border-color:<?php echo esc_attr( $cash_color ); ?>40;">
                <span class="mj-arg-discount-badge" style="background:<?php echo esc_attr( $cash_color ); ?>;color:#fff;">
                    -<?php echo esc_html( $cash_pct ); ?>% OFF
                </span>
                <div class="mj-arg-transfer-details">
                    <span class="mj-arg-transfer-price" style="color:<?php echo esc_attr( $cash_color ); ?>;">
                        $<?php echo number_format( $cash_price, 0, ',', '.' ); ?>
                    </span>
                    <span class="mj-arg-transfer-label"><?php echo esc_html( $cash_label ); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ( $show_inst && $inst_count > 1 ) : ?>
            <div class="mj-arg-installments-box" style="color:<?php echo esc_attr( $inst_color ); ?>;">
                💳 Hasta <strong><?php echo intval( $inst_count ); ?> <?php echo esc_html( $inst_label ); ?> $<?php echo number_format( $inst_value, 0, ',', '.' ); ?></strong>
            </div>
        <?php endif; ?>

        <?php if ( $show_wa && ! $compact ) : ?>
            <a href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer" class="mj-arg-wa-btn">
                💬 <?php echo esc_html( $wa_text ); ?>
            </a>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render 5: Carrusel / Grilla de Ofertas Relámpago con Tarjetas Configurables
 */
function mj_pro_render_flash_deals_carousel( $attributes ) {
    $title     = esc_html( $attributes['sectionTitle'] ?? '⚡ Ofertas Relámpago' );
    $hours     = max( 1, intval( $attributes['countdownHours'] ?? 12 ) );
    $count     = max( 1, intval( $attributes['productsCount'] ?? 4 ) );
    $cols      = max( 1, min( 6, intval( $attributes['columns'] ?? 4 ) ) );
    $only_sale = ! empty( $attributes['onlyOnSale'] );

    $query_args = array(
        'post_type'      => 'product',
        'posts_per_page' => $count,
        'post_status'    => 'publish',
    );
    $only_in_stock = ! isset( $attributes['onlyInStock'] ) || ! empty( $attributes['onlyInStock'] );
    if ( $only_in_stock ) {
        $query_args['meta_query'] = array(
            array(
                'key'     => '_stock_status',
                'value'   => 'instock',
                'compare' => '=',
            ),
        );
    }
    if ( $only_stock ) {
        $args['meta_query'] = array(
            array(
                'key'     => '_stock_status',
                'value'   => 'instock',
                'compare' => '=',
            ),
        );
    }

    if ( $only_sale && function_exists( 'wc_get_product_ids_on_sale' ) ) {
        $sale_ids = wc_get_product_ids_on_sale();
        $query_args['post__in'] = ! empty( $sale_ids ) ? $sale_ids : array( 0 );
    }

    $products = get_posts( $query_args );

    ob_start();
    ?>
    <section id="ofertas-relampago" class="mj-flash-deals-section">
        <?php if ( $title ) : ?>
            <div class="mj-flash-header">
                <h2 class="mj-section-title"><?php echo $title; ?></h2>
                <div class="mj-countdown-timer" data-hours="<?php echo esc_attr( $hours ); ?>">
                    <span class="mj-countdown-label">Termina en:</span>
                    <span class="mj-cd-box" data-unit="hours"><?php echo str_pad( $hours, 2, '0', STR_PAD_LEFT ); ?>h</span> :
                    <span class="mj-cd-box" data-unit="minutes">45m</span> :
                    <span class="mj-cd-box" data-unit="seconds">00s</span>
                </div>
            </div>
        <?php endif; ?>
        <div class="mj-products-grid" style="--mj-grid-cols: <?php echo esc_attr( $cols ); ?>;">
            <?php if ( ! empty( $products ) && function_exists( 'wc_get_product' ) ) : ?>
                <?php foreach ( $products as $post_item ) :
                    $product = wc_get_product( $post_item->ID );
                    if ( ! $product ) continue;
                    echo mj_pro_render_single_product_card_html( $product, $attributes );
                endforeach; ?>
            <?php else : ?>
                <p class="mj-empty-results">No hay productos para mostrar en esta sección.</p>
            <?php endif; ?>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/**
 * Render 6: Grilla Pro de Categorías de WooCommerce
 */
function mj_pro_render_category_grid_pro( $attributes ) {
    $title      = esc_html( $attributes['sectionTitle'] ?? 'Explorá por Categoría' );
    $cols       = max( 1, min( 6, intval( $attributes['columns'] ?? 4 ) ) );
    $hide_empty = ! empty( $attributes['hideEmpty'] );
    $show_count = ! empty( $attributes['showCount'] );

    $terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => $hide_empty,
        'number'     => 8,
    ) );

    ob_start();
    ?>
    <section class="mj-category-grid-section">
        <h2 class="mj-section-title"><?php echo $title; ?></h2>
        <div class="mj-category-grid" style="--mj-grid-cols: <?php echo esc_attr( $cols ); ?>;">
            <?php if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) : ?>
                <?php foreach ( $terms as $term ) :
                    $link = get_term_link( $term );
                    ?>
                    <a href="<?php echo esc_url( is_wp_error( $link ) ? '#' : $link ); ?>" class="mj-cat-card">
                        <span class="mj-cat-name"><?php echo esc_html( $term->name ); ?></span>
                        <?php if ( $show_count ) : ?>
                            <span class="mj-cat-count"><?php echo intval( $term->count ); ?> productos</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/**
 * Render 7: Catálogo Interactivo con Filtros Rápidos AJAX y Configuración de Tarjetas
 */
function mj_pro_render_ajax_catalog_filter( $attributes ) {
    $title     = esc_html( $attributes['title'] ?? 'Catálogo con Filtrado Instantáneo' );
    $per_page  = max( 1, intval( $attributes['productsPerPage'] ?? 8 ) );
    $cols      = max( 1, min( 6, intval( $attributes['columns'] ?? 4 ) ) );
    $show_sort          = ! isset( $attributes['showSortSelector'] ) || ! empty( $attributes['showSortSelector'] );
    $show_sale          = ! isset( $attributes['showSaleToggle'] ) || ! empty( $attributes['showSaleToggle'] );
    $show_stock_toggle  = ! isset( $attributes['showStockToggle'] ) || ! empty( $attributes['showStockToggle'] );
    $default_only_stock = ! isset( $attributes['defaultOnlyInStock'] ) || ! empty( $attributes['defaultOnlyInStock'] );

    $terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'number'     => 12,
    ) );

    $card_config_json = wp_json_encode( array(
        'columns'               => $cols,
        'showOutOfStockBadge'   => $attributes['showOutOfStockBadge'] ?? true,
        'outOfStockText'        => $attributes['outOfStockText'] ?? 'SIN STOCK',
        'outOfStockBgColor'     => $attributes['outOfStockBgColor'] ?? '#dc2626',
        'outOfStockTextColor'   => $attributes['outOfStockTextColor'] ?? '#ffffff',
        'showSaleBadge'         => $attributes['showSaleBadge'] ?? true,
        'saleBadgeText'         => $attributes['saleBadgeText'] ?? 'OFERTA',
        'saleBadgeShowPct'      => $attributes['saleBadgeShowPct'] ?? true,
        'saleBadgeBgColor'      => $attributes['saleBadgeBgColor'] ?? '#ea580c',
        'saleBadgeTextColor'    => $attributes['saleBadgeTextColor'] ?? '#ffffff',
        'showTransferPrice'     => $attributes['showTransferPrice'] ?? true,
        'transferDiscountPct'   => $attributes['transferDiscountPct'] ?? 15,
        'transferLabel'         => $attributes['transferLabel'] ?? 'con Transferencia / Efectivo',
        'transferTextColor'     => $attributes['transferTextColor'] ?? '#15803d',
        'transferBgColor'       => $attributes['transferBgColor'] ?? '#dcfce7',
        'showCashPrice'         => $attributes['showCashPrice'] ?? false,
        'cashDiscountPct'       => $attributes['cashDiscountPct'] ?? 20,
        'cashLabel'             => $attributes['cashLabel'] ?? 'abonando en Efectivo en local',
        'cashTextColor'         => $attributes['cashTextColor'] ?? '#0369a1',
        'cashBgColor'           => $attributes['cashBgColor'] ?? '#e0f2fe',
        'showInstallments'      => $attributes['showInstallments'] ?? true,
        'installmentsCount'     => $attributes['installmentsCount'] ?? 3,
        'installmentsSurcharge' => $attributes['installmentsSurcharge'] ?? 0,
        'installmentsLabel'     => $attributes['installmentsLabel'] ?? 'cuotas sin interés de',
        'installmentsTextColor' => $attributes['installmentsTextColor'] ?? '#334155',
        'enableAjaxQuickAdd'    => $attributes['enableAjaxQuickAdd'] ?? true,
    ) );

    ob_start();
    ?>
    <section class="mj-ajax-catalog-wrap"
             data-per-page="<?php echo esc_attr( $per_page ); ?>"
             data-card-config="<?php echo esc_attr( $card_config_json ); ?>">
        <div class="mj-ajax-catalog-toolbar">
            <?php if ( $title ) : ?>
                <h2 class="mj-section-title"><?php echo $title; ?></h2>
            <?php endif; ?>
            <div class="mj-ajax-filter-controls">
                <div class="mj-cat-pills">
                    <button type="button" class="mj-cat-pill is-active" data-cat="">Todos</button>
                    <?php if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) : ?>
                        <?php foreach ( $terms as $term ) : ?>
                            <button type="button" class="mj-cat-pill" data-cat="<?php echo esc_attr( $term->slug ); ?>">
                                <?php echo esc_html( $term->name ); ?>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="mj-extra-filters">
                    <?php if ( $show_stock_toggle ) : ?>
                        <label class="mj-sale-toggle mj-stock-toggle">
                            <input type="checkbox" class="mj-ajax-stock-checkbox" <?php checked( $default_only_stock, true ); ?> />
                            <span>Solo con Stock</span>
                        </label>
                    <?php else : ?>
                        <input type="checkbox" class="mj-ajax-stock-checkbox" <?php checked( $default_only_stock, true ); ?> hidden />
                    <?php endif; ?>
                    <?php if ( $show_sale ) : ?>
                        <label class="mj-sale-toggle">
                            <input type="checkbox" class="mj-ajax-sale-checkbox" />
                            <span>Solo en Oferta</span>
                        </label>
                    <?php endif; ?>
                    <?php if ( $show_sort ) : ?>
                        <select class="mj-ajax-sort-select" aria-label="Ordenar productos">
                            <option value="date">Más recientes</option>
                            <option value="price-asc">Menor precio</option>
                            <option value="price-desc">Mayor precio</option>
                        </select>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="mj-ajax-catalog-results">
            <?php
            $initial_attrs = array_merge( $attributes, array(
                'sectionTitle'  => '',
                'productsCount' => $per_page,
                'columns'       => $cols,
                'onlyOnSale'    => false,
                'onlyInStock'   => $default_only_stock,
            ) );
            echo mj_pro_render_flash_deals_carousel( $initial_attrs );
            ?>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/**
 * Render 8: Barra de Navegación Inferior Celular (Sticky Bottom App Bar)
 */
function mj_pro_render_mobile_bottom_nav( $attributes ) {
    $show_desktop = ! empty( $attributes['showOnDesktop'] );
    $bg_color     = esc_attr( $attributes['bgColor'] ?? '#ffffff' );
    $text_color   = esc_attr( $attributes['textColor'] ?? '#0f172a' );
    $accent_color = esc_attr( $attributes['accentColor'] ?? '#2563eb' );
    $glass        = ! empty( $attributes['glassEffect'] );

    $cart_count = 0;
    if ( function_exists( 'WC' ) && WC()->cart ) {
        $cart_count = intval( WC()->cart->get_cart_contents_count() );
    }

    $items = array(
        array(
            'show'   => $attributes['item1Show'] ?? true,
            'icon'   => $attributes['item1Icon'] ?? '🏠',
            'label'  => $attributes['item1Label'] ?? 'Inicio',
            'url'    => $attributes['item1Url'] ?? '/',
            'action' => 'link',
            'badge'  => 0,
        ),
        array(
            'show'   => $attributes['item2Show'] ?? true,
            'icon'   => $attributes['item2Icon'] ?? '📦',
            'label'  => $attributes['item2Label'] ?? 'Tienda',
            'url'    => $attributes['item2Url'] ?? '/tienda/',
            'action' => 'link',
            'badge'  => 0,
        ),
        array(
            'show'   => $attributes['item3Show'] ?? true,
            'icon'   => $attributes['item3Icon'] ?? '🔍',
            'label'  => $attributes['item3Label'] ?? 'Buscar',
            'url'    => $attributes['item3Url'] ?? '/?s=&post_type=product',
            'action' => ! empty( $attributes['item3OpenSearch'] ) ? 'search-modal' : 'link',
            'badge'  => 0,
        ),
        array(
            'show'   => $attributes['item4Show'] ?? true,
            'icon'   => $attributes['item4Icon'] ?? '🛒',
            'label'  => $attributes['item4Label'] ?? 'Carrito',
            'url'    => $attributes['item4Url'] ?? '/carrito/',
            'action' => 'link',
            'badge'  => ! empty( $attributes['item4ShowBadge'] ) ? $cart_count : 0,
        ),
        array(
            'show'   => $attributes['item5Show'] ?? true,
            'icon'   => $attributes['item5Icon'] ?? '👤',
            'label'  => $attributes['item5Label'] ?? 'Mi Cuenta',
            'url'    => $attributes['item5Url'] ?? '/mi-cuenta/',
            'action' => 'link',
            'badge'  => 0,
        ),
    );

    $classes = array( 'mj-mobile-bottom-nav' );
    if ( $show_desktop ) {
        $classes[] = 'is-visible-desktop';
    }
    if ( $glass ) {
        $classes[] = 'is-glass';
    }

    ob_start();
    ?>
    <nav class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
         aria-label="Navegación rápida móvil"
         style="--mj-nav-bg:<?php echo $bg_color; ?>;--mj-nav-text:<?php echo $text_color; ?>;--mj-nav-accent:<?php echo $accent_color; ?>;">
        <div class="mj-mobile-bottom-nav-inner">
            <?php foreach ( $items as $item ) :
                if ( empty( $item['show'] ) ) continue;
                $is_search_trigger = ( 'search-modal' === $item['action'] );
                $is_cart_item      = ( 'Carrito' === $item['label'] || strpos( $item['url'], 'carrito' ) !== false );
                ?>
                <a href="<?php echo esc_url( $item['url'] ); ?>"
                   class="mj-mobile-nav-item <?php echo $is_search_trigger ? 'js-mj-open-mobile-search' : ''; ?>">
                    <span class="mj-mobile-nav-icon-wrap">
                        <span class="mj-mobile-nav-icon" aria-hidden="true"><?php echo esc_html( $item['icon'] ); ?></span>
                        <?php if ( $is_cart_item ) : ?>
                            <span class="mj-mobile-nav-badge js-mj-cart-badge" <?php echo $cart_count > 0 ? '' : 'hidden'; ?>><?php echo intval( $cart_count ); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="mj-mobile-nav-label"><?php echo esc_html( $item['label'] ); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>

    <div class="mj-mobile-search-modal" hidden>
        <div class="mj-mobile-search-modal-backdrop js-mj-close-mobile-search"></div>
        <div class="mj-mobile-search-modal-sheet">
            <div class="mj-mobile-search-modal-head">
                <strong>Buscar productos, categorías, marcas o SKU</strong>
                <button type="button" class="mj-mobile-search-close js-mj-close-mobile-search" aria-label="Cerrar">✕</button>
            </div>
            <?php echo mj_pro_render_ajax_live_search( array(
                'placeholder'          => 'Escribí producto, marca, categoría, SKU o EAN...',
                'maxResults'           => 8,
                'showCategoriesBrands' => true,
                'showPrice'            => true,
                'showTransferPrice'    => true,
                'transferDiscountPct'  => 15,
                'showSkuEan'           => true,
                'showThumb'            => true,
                'showQuickAddBtn'      => true,
                'buttonLabel'          => 'Ir',
                'accentColor'          => $accent_color,
            ) ); ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render 9: Checkout Modular por Pasos (100% Configurable, con recálculo dinámico de envíos y pagos)
 */
function mj_pro_render_multistep_checkout( $attributes ) {
    if ( ! function_exists( 'WC' ) ) {
        return '<p class="mj-empty-results">WooCommerce no está activo.</p>';
    }

    $step1_title    = esc_html( $attributes['step1Title'] ?? 'Tus Datos y Entrega' );
    $step1_subtitle = esc_html( $attributes['step1Subtitle'] ?? 'Ingresá tu contacto y dirección para calcular el envío' );
    $step1_icon     = esc_html( $attributes['step1Icon'] ?? '👤' );

    $step2_title    = esc_html( $attributes['step2Title'] ?? 'Método de Envío' );
    $step2_subtitle = esc_html( $attributes['step2Subtitle'] ?? 'Seleccioná la opción de entrega que prefieras' );
    $step2_icon     = esc_html( $attributes['step2Icon'] ?? '🚚' );

    $step3_title    = esc_html( $attributes['step3Title'] ?? 'Pago y Confirmación' );
    $step3_subtitle = esc_html( $attributes['step3Subtitle'] ?? 'Elegí cómo abonar y finalizá tu pedido' );
    $step3_icon     = esc_html( $attributes['step3Icon'] ?? '💳' );

    $trust_text     = esc_html( $attributes['trustBadgeText'] ?? '🔒 Compra 100% Segura y Protegida · Envíos a todo el país' );
    $accent         = esc_attr( $attributes['accentColor'] ?? '#0284c7' );
    $cyan           = esc_attr( $attributes['cyanColor'] ?? '#06b6d4' );
    $show_sidebar   = ! isset( $attributes['showSidebarSummary'] ) || ! empty( $attributes['showSidebarSummary'] );

    // En el editor de bloques (admin / REST) mostramos el esqueleto visual editable
    if ( is_admin() && ! wp_doing_ajax() ) {
        ob_start();
        ?>
        <div class="mj-multistep-checkout-wrap is-editor-preview" style="--mj-co-accent:<?php echo $accent; ?>;--mj-co-cyan:<?php echo $cyan; ?>;">
            <div class="mj-checkout-steps-bar">
                <div class="mj-step-pill is-active">
                    <span class="mj-step-badge"><?php echo $step1_icon; ?></span>
                    <div class="mj-step-meta"><span class="mj-step-kicker">Paso 1</span><strong class="mj-step-name"><?php echo $step1_title; ?></strong></div>
                </div>
                <div class="mj-step-arrow">→</div>
                <div class="mj-step-pill">
                    <span class="mj-step-badge"><?php echo $step2_icon; ?></span>
                    <div class="mj-step-meta"><span class="mj-step-kicker">Paso 2</span><strong class="mj-step-name"><?php echo $step2_title; ?></strong></div>
                </div>
                <div class="mj-step-arrow">→</div>
                <div class="mj-step-pill">
                    <span class="mj-step-badge"><?php echo $step3_icon; ?></span>
                    <div class="mj-step-meta"><span class="mj-step-kicker">Paso 3</span><strong class="mj-step-name"><?php echo $step3_title; ?></strong></div>
                </div>
            </div>
            <?php if ( $trust_text ) : ?>
                <div class="mj-checkout-trust-banner">
                    <span><?php echo $trust_text; ?></span>
                </div>
            <?php endif; ?>
            <div style="background:#f0f9ff;border:2px dashed #0284c7;border-radius:16px;padding:2rem;text-align:center;margin-top:1.5rem;">
                <h4 style="margin:0 0 0.5rem;color:#0369a1;">✨ Bloque Checkout en Pasos MJ Commerce Pro</h4>
                <p style="margin:0;color:#64748b;font-size:0.9rem;">En la tienda online, este bloque organiza automáticamente el checkout en 3 pasos con validación, cálculo de envíos dinámico en vivo (MJ Envíos, Andreani, Correo Argentino) y resumen flotante.</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    if ( WC()->cart && WC()->cart->is_empty() ) {
        ob_start();
        ?>
        <div class="mj-empty-checkout-card">
            <div class="mj-empty-checkout-icon">🛒</div>
            <h2>Tu carrito está vacío</h2>
            <p>Parece que todavía no agregaste ningún producto a tu carrito de compras.</p>
            <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="mj-btn mj-btn-primary">
                Explorar Catálogo de Productos →
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    ob_start();
    ?>
    <div class="mj-multistep-checkout-wrap"
         style="--mj-co-accent:<?php echo $accent; ?>;--mj-co-cyan:<?php echo $cyan; ?>;"
         data-step1-title="<?php echo $step1_title; ?>"
         data-step1-subtitle="<?php echo $step1_subtitle; ?>"
         data-step1-icon="<?php echo $step1_icon; ?>"
         data-step2-title="<?php echo $step2_title; ?>"
         data-step2-subtitle="<?php echo $step2_subtitle; ?>"
         data-step2-icon="<?php echo $step2_icon; ?>"
         data-step3-title="<?php echo $step3_title; ?>"
         data-step3-subtitle="<?php echo $step3_subtitle; ?>"
         data-step3-icon="<?php echo $step3_icon; ?>"
         data-show-sidebar="<?php echo $show_sidebar ? '1' : '0'; ?>">

        <!-- Barra de Progreso de Pasos Superior -->
        <nav class="mj-checkout-steps-bar" aria-label="Progreso de Checkout">
            <button type="button" class="mj-step-pill is-active" data-step="1">
                <span class="mj-step-badge"><span class="mj-step-icon"><?php echo $step1_icon; ?></span><span class="mj-step-check">✓</span></span>
                <span class="mj-step-meta">
                    <span class="mj-step-kicker">Paso 1</span>
                    <strong class="mj-step-name"><?php echo $step1_title; ?></strong>
                </span>
            </button>
            <span class="mj-step-arrow" aria-hidden="true">→</span>
            <button type="button" class="mj-step-pill" data-step="2">
                <span class="mj-step-badge"><span class="mj-step-icon"><?php echo $step2_icon; ?></span><span class="mj-step-check">✓</span></span>
                <span class="mj-step-meta">
                    <span class="mj-step-kicker">Paso 2</span>
                    <strong class="mj-step-name"><?php echo $step2_title; ?></strong>
                </span>
            </button>
            <span class="mj-step-arrow" aria-hidden="true">→</span>
            <button type="button" class="mj-step-pill" data-step="3">
                <span class="mj-step-badge"><span class="mj-step-icon"><?php echo $step3_icon; ?></span><span class="mj-step-check">✓</span></span>
                <span class="mj-step-meta">
                    <span class="mj-step-kicker">Paso 3</span>
                    <strong class="mj-step-name"><?php echo $step3_title; ?></strong>
                </span>
            </button>
        </nav>

        <?php if ( $trust_text ) : ?>
            <div class="mj-checkout-trust-banner">
                <span><?php echo $trust_text; ?></span>
            </div>
        <?php endif; ?>

        <!-- Formulario Nativo de Checkout de WooCommerce -->
        <div class="mj-checkout-form-container">
            <?php echo do_shortcode( '[woocommerce_checkout]' ); ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render 10: MJ Dólar — Cotización y Ticker en Vivo
 */
function mj_pro_render_dolar_ticker( $attributes ) {
    $style_mode     = ! empty( $attributes['styleMode'] ) ? sanitize_key( $attributes['styleMode'] ) : 'bar';
    $title_text     = ! empty( $attributes['titleText'] ) ? esc_html( $attributes['titleText'] ) : 'Cotización USD:';
    $rate_type      = ! empty( $attributes['rateTypeLabel'] ) ? esc_html( $attributes['rateTypeLabel'] ) : 'Tipo de Cambio';
    $show_rate_type = ! empty( $attributes['showRateType'] );
    $custom_rate    = floatval( $attributes['customRate'] ?? 0 );
    $link_url       = ! empty( $attributes['linkUrl'] ) ? esc_url( $attributes['linkUrl'] ) : '';
    $accent_color   = ! empty( $attributes['accentColor'] ) ? esc_attr( $attributes['accentColor'] ) : '#16a34a';

    $rate = 0;
    if ( class_exists( 'MJD_Rate' ) ) {
        $rate = (float) MJD_Rate::get();
    }
    if ( $rate <= 0 && $custom_rate > 0 ) {
        $rate = $custom_rate;
    }
    if ( $rate <= 0 ) {
        $rate = 1250;
    }

    $rate_formatted = '$ ' . number_format( $rate, 2, ',', '.' );

    $tag  = $link_url ? 'a' : 'div';
    $href = $link_url ? ' href="' . $link_url . '"' : '';

    $custom_style = '';
    if ( ! empty( $attributes['bgColor'] ) ) {
        $custom_style .= 'background-color:' . esc_attr( $attributes['bgColor'] ) . ';';
    }
    if ( ! empty( $attributes['textColor'] ) ) {
        $custom_style .= 'color:' . esc_attr( $attributes['textColor'] ) . ';';
    }
    $style_attr = $custom_style ? ' style="' . esc_attr( $custom_style ) . '"' : '';

    ob_start();
    ?>
    <<?php echo $tag . $href . $style_attr; ?> class="mj-dolar-ticker-wrap is-<?php echo esc_attr( $style_mode ); ?>">
        <span>💵 <?php echo $title_text; ?></span>
        <span class="mj-dolar-rate-val" style="color:<?php echo $accent_color; ?>;"><?php echo esc_html( $rate_formatted ); ?></span>
        <?php if ( $show_rate_type && $rate_type ) : ?>
            <span style="opacity:0.75;font-size:0.82em;">(<?php echo $rate_type; ?>)</span>
        <?php endif; ?>
    </<?php echo $tag; ?>>
    <?php
    return ob_get_clean();
}

/**
 * Render 11: MJ ERP — Acceso Rápido y Panel de Control
 */
function mj_pro_render_erp_launcher( $attributes ) {
    $target       = ! empty( $attributes['target'] ) ? sanitize_key( $attributes['target'] ) : 'admin';
    $custom_url   = ! empty( $attributes['customUrl'] ) ? esc_url( $attributes['customUrl'] ) : '';
    $label        = ! empty( $attributes['buttonLabel'] ) ? esc_html( $attributes['buttonLabel'] ) : 'Acceder a MJ ERP';
    $subtext      = ! empty( $attributes['subText'] ) ? esc_html( $attributes['subText'] ) : 'Ventas, stock y caja';
    $badge        = ! empty( $attributes['badgeText'] ) ? esc_html( $attributes['badgeText'] ) : 'Operadores';
    $layout       = ! empty( $attributes['layoutMode'] ) ? sanitize_key( $attributes['layoutMode'] ) : 'button';
    $bg_color     = ! empty( $attributes['bgColor'] ) ? esc_attr( $attributes['bgColor'] ) : '#0f172a';
    $text_color   = ! empty( $attributes['textColor'] ) ? esc_attr( $attributes['textColor'] ) : '#ffffff';
    $accent_color = ! empty( $attributes['accentColor'] ) ? esc_attr( $attributes['accentColor'] ) : '#3b82f6';
    $require_cap  = ! empty( $attributes['requireCap'] ) ? sanitize_text_field( $attributes['requireCap'] ) : 'manage_mjerp';

    if ( ! is_user_logged_in() || ( ! current_user_can( $require_cap ) && ! current_user_can( 'manage_options' ) ) ) {
        return '';
    }

    $url = admin_url( 'admin.php?page=mj-erp' );
    if ( 'portal' === $target ) {
        $url = home_url( '/portal-consignaciones/' );
    } elseif ( 'custom' === $target && $custom_url ) {
        $url = $custom_url;
    }

    ob_start();
    ?>
    <div class="mj-erp-launcher-wrap">
        <?php if ( 'card' === $layout ) : ?>
            <a href="<?php echo esc_url( $url ); ?>" class="mj-erp-launcher-card" style="background:<?php echo $bg_color; ?>;color:<?php echo $text_color; ?>;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:1.75rem;">📦</span>
                    <?php if ( $badge ) : ?><span class="mj-erp-badge" style="background:<?php echo $accent_color; ?>;color:#fff;"><?php echo $badge; ?></span><?php endif; ?>
                </div>
                <div>
                    <strong style="font-size:1.15rem;display:block;"><?php echo $label; ?></strong>
                    <span style="opacity:0.85;font-size:0.88rem;"><?php echo $subtext; ?></span>
                </div>
            </a>
        <?php else : ?>
            <a href="<?php echo esc_url( $url ); ?>" class="mj-erp-launcher-btn" style="background:<?php echo $bg_color; ?>;color:<?php echo $text_color; ?>;">
                <span>📦</span>
                <span><?php echo $label; ?></span>
                <?php if ( $badge ) : ?>
                    <span class="mj-erp-badge" style="background:<?php echo $accent_color; ?>;color:#fff;"><?php echo $badge; ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render 12: MJ Medios de Pago — Grilla de Métodos y Promociones
 */
function mj_pro_render_payment_methods_grid( $attributes ) {
    $title         = ! empty( $attributes['title'] ) ? esc_html( $attributes['title'] ) : 'Medios de Pago y Promociones';
    $subtitle      = ! empty( $attributes['subtitle'] ) ? esc_html( $attributes['subtitle'] ) : 'Aboná con todas las opciones disponibles';
    $show_transfer = ! empty( $attributes['showTransfer'] );
    $transfer_pct  = floatval( $attributes['transferDiscountPct'] ?? 15 );
    $show_cash     = ! empty( $attributes['showCash'] );
    $cash_pct      = floatval( $attributes['cashDiscountPct'] ?? 20 );
    $show_inst     = ! empty( $attributes['showInstallments'] );
    $inst_count    = intval( $attributes['installmentsCount'] ?? 3 );
    $show_cards    = ! empty( $attributes['showCards'] );

    if ( ! empty( $attributes['usePluginSettings'] ) ) {
        $mjmp = get_option( 'mjmp_settings', array() );
        if ( ! empty( $mjmp['transfer_discount'] ) ) {
            $transfer_pct = floatval( $mjmp['transfer_discount'] );
        }
        if ( ! empty( $mjmp['cash_discount'] ) ) {
            $cash_pct = floatval( $mjmp['cash_discount'] );
        }
    }

    ob_start();
    ?>
    <div class="mj-payment-grid-wrap">
        <?php if ( $title || $subtitle ) : ?>
            <div class="mj-payment-grid-header">
                <?php if ( $title ) : ?><h3>💳 <?php echo $title; ?></h3><?php endif; ?>
                <?php if ( $subtitle ) : ?><p><?php echo $subtitle; ?></p><?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="mj-payment-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));">
            <?php if ( $show_transfer ) : ?>
                <div class="mj-payment-card">
                    <span class="mj-payment-card-icon">🏦</span>
                    <div>
                        <h4 class="mj-payment-card-title">Transferencia / Débito</h4>
                        <p class="mj-payment-card-desc">Transferí desde cualquier banco o billetera virtual con acreditación instantánea.</p>
                        <?php if ( $transfer_pct > 0 ) : ?>
                            <span class="mj-payment-card-badge" style="background:#dcfce7;color:#15803d;">🔥 <?php echo $transfer_pct; ?>% de Descuento directo</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $show_cash ) : ?>
                <div class="mj-payment-card">
                    <span class="mj-payment-card-icon">💵</span>
                    <div>
                        <h4 class="mj-payment-card-title">Efectivo en Local</h4>
                        <p class="mj-payment-card-desc">Retirá y aboná en efectivo en nuestro punto de entrega con descuento especial.</p>
                        <?php if ( $cash_pct > 0 ) : ?>
                            <span class="mj-payment-card-badge" style="background:#e0f2fe;color:#0369a1;">🔥 <?php echo $cash_pct; ?>% de Descuento en efectivo</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $show_inst && $inst_count > 1 ) : ?>
                <div class="mj-payment-card">
                    <span class="mj-payment-card-icon">💳</span>
                    <div>
                        <h4 class="mj-payment-card-title">Cuotas sin Interés</h4>
                        <p class="mj-payment-card-desc">Hasta <?php echo $inst_count; ?> cuotas fijas sin interés con tarjetas bancarias seleccionadas.</p>
                        <span class="mj-payment-card-badge" style="background:#fef3c7;color:#b45309;">✨ <?php echo $inst_count; ?> Cuotas sin Interés</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $show_cards ) : ?>
                <div class="mj-payment-card">
                    <span class="mj-payment-card-icon">⚡</span>
                    <div>
                        <h4 class="mj-payment-card-title">Mercado Pago y Billeteras</h4>
                        <p class="mj-payment-card-desc">Aceptamos dinero en cuenta, tarjetas prepagas y saldo de Mercado Pago / Modo.</p>
                        <span class="mj-payment-card-badge" style="background:#f1f5f9;color:#475569;">Acreditación Inmediata</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render 13: MJ Envíos — Calculador Rápido por Código Postal
 */
function mj_pro_render_shipping_calculator( $attributes ) {
    $title     = ! empty( $attributes['title'] ) ? esc_html( $attributes['title'] ) : 'Calculá el costo de envío';
    $ph        = ! empty( $attributes['placeholder'] ) ? esc_attr( $attributes['placeholder'] ) : 'Ingresá tu código postal...';
    $btn       = ! empty( $attributes['buttonLabel'] ) ? esc_html( $attributes['buttonLabel'] ) : 'Calcular Envío';
    $free_th   = floatval( $attributes['freeShippingThreshold'] ?? 0 );
    $free_text = ! empty( $attributes['freeShippingNotice'] ) ? esc_html( $attributes['freeShippingNotice'] ) : '';

    ob_start();
    ?>
    <div class="mj-shipping-calc-card">
        <h4 style="margin:0 0 0.5rem;font-size:1.05rem;font-weight:700;">🚚 <?php echo $title; ?></h4>
        <?php if ( $free_th > 0 && $free_text ) : ?>
            <p style="font-size:0.85rem;color:#15803d;margin:0 0 0.5rem;font-weight:600;">✨ <?php echo $free_text; ?> (en compras mayores a $<?php echo number_format($free_th, 0, ',', '.'); ?>)</p>
        <?php endif; ?>
        <form class="mj-shipping-calc-form" onsubmit="event.preventDefault();const cp=this.querySelector('input').value;if(!cp){alert('Ingresá tu código postal');return;}const res=this.nextElementSibling;res.style.display='block';res.innerHTML='🔍 Calculando opciones de envío para CP '+cp+'...';setTimeout(()=>{res.innerHTML='📦 <strong>Opciones de envío estimadas para CP '+cp+':</strong><br>• Correo Argentino / Andreani: sucursal o domicilio.<br>• Cadetería express / retiro.<br><small style=\'color:#64748b;\'>El valor final exacto se confirma en el paso de entrega del checkout.</small>';},400);">
            <input type="text" class="mj-shipping-calc-input" placeholder="<?php echo $ph; ?>" maxlength="8" required />
            <button type="submit" class="mj-shipping-calc-btn"><?php echo $btn; ?></button>
        </form>
        <div class="mj-shipping-results" style="display:none;margin-top:0.85rem;padding:0.75rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:0.88rem;line-height:1.5;"></div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render 14: MJ Turnos Online — Reserva Directa de Servicios
 */
function mj_pro_render_turnos_embed( $attributes ) {
    $title    = ! empty( $attributes['title'] ) ? esc_html( $attributes['title'] ) : 'Reservá tu Turno Online';
    $subtitle = ! empty( $attributes['subtitle'] ) ? esc_html( $attributes['subtitle'] ) : 'Elegí servicio, fecha y horario disponible';
    $accent   = ! empty( $attributes['accentColor'] ) ? esc_attr( $attributes['accentColor'] ) : '#ec4899';

    ob_start();
    ?>
    <div class="mj-turnos-embed-wrap">
        <?php if ( $title || $subtitle ) : ?>
            <div style="margin-bottom:1.25rem;">
                <?php if ( $title ) : ?><h3 style="margin:0 0 0.35rem;font-size:1.35rem;font-weight:800;color:<?php echo $accent; ?>;">📅 <?php echo $title; ?></h3><?php endif; ?>
                <?php if ( $subtitle ) : ?><p style="margin:0;color:#64748b;font-size:0.92rem;"><?php echo $subtitle; ?></p><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
        if ( shortcode_exists( 'mj_turnos' ) ) {
            echo do_shortcode( '[mj_turnos]' );
        } else {
            ?>
            <div style="padding:2rem;text-align:center;background:#fdf2f8;border:1px dashed #f472b6;border-radius:12px;">
                <p style="margin:0 0 0.75rem;font-weight:700;color:#9d174d;">📅 Sistema de Turnos Online</p>
                <p style="margin:0 0 1rem;font-size:0.88rem;color:#64748b;">El plugin <strong>MJ Turnos Online</strong> se encuentra listo para vincular o programar reservas.</p>
                <a href="<?php echo esc_url( home_url( '/turnos/' ) ); ?>" class="mj-btn" style="background:<?php echo $accent; ?>;color:#fff;border-radius:8px;padding:0.6rem 1.25rem;text-decoration:none;font-weight:700;">Ir a la Agenda de Turnos</a>
            </div>
            <?php
        }
        ?>
    </div>
    <?php
    return ob_get_clean();
}


