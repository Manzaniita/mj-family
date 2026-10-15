/**
 * MJ Commerce Pro v1.2.0 - Motor React en el Editor de Bloques (Gutenberg / FSE)
 * Incluye:
 *  A) Extensión Universal "📌 MJ · Posición Sticky (Arriba / Abajo)" para Filas, Columnas, Grillas, Grupos y Partes de Plantilla
 *  B) Panel Reutilizable de Configuración de Tarjetas (Badges Sin Stock / Oferta + Precios por Método de Pago + Colores)
 *  C) Los 8 Bloques Personalizados en React
 */
( function( blocks, element, blockEditor, components, serverSideRender, hooks, compose ) {
    const el = element.createElement;
    const Fragment = element.Fragment;
    const InspectorControls = blockEditor.InspectorControls;
    const useBlockProps = blockEditor.useBlockProps;
    const PanelBody = components.PanelBody;
    const TextControl = components.TextControl;
    const RangeControl = components.RangeControl;
    const ToggleControl = components.ToggleControl;
    const SelectControl = components.SelectControl;
    const ServerSideRender = serverSideRender;
    const addFilter = hooks.addFilter;
    const createHigherOrderComponent = compose.createHigherOrderComponent;

    // =========================================================================
    // A) EXTENSIÓN UNIVERSAL REACT: STICKY ARRIBA / ABAJO PARA FILAS, COLUMNAS Y GRILLAS
    // =========================================================================
    const STICKY_SUPPORTED_BLOCKS = [
        'core/group',
        'core/columns',
        'core/column',
        'core/template-part',
        'core/cover',
        'mj-pro/ajax-live-search',
        'mj-pro/hero-slider',
        'mj-pro/trust-benefits-bar',
        'mj-pro/arg-payment-calculator',
        'mj-pro/flash-deals-carousel',
        'mj-pro/category-grid-pro',
        'mj-pro/ajax-catalog-filter',
        'mj-pro/multistep-checkout',
        'mj-pro/dolar-ticker',
        'mj-pro/erp-launcher',
        'mj-pro/payment-methods-grid',
        'mj-pro/shipping-calculator',
        'mj-pro/turnos-embed'
    ];

    function mjAddStickyAttributes( settings, name ) {
        if ( STICKY_SUPPORTED_BLOCKS.indexOf( name ) === -1 ) {
            return settings;
        }
        settings.attributes = Object.assign( {}, settings.attributes, {
            mjStickyMode:     { type: 'string', default: 'none' },
            mjStickyOffset:   { type: 'number', default: 0 },
            mjStickyZIndex:   { type: 'number', default: 90 },
            mjStickyDevice:   { type: 'string', default: 'all' },
            mjStickyBackdrop: { type: 'boolean', default: false }
        } );
        return settings;
    }
    addFilter( 'blocks.registerBlockType', 'mj-pro/sticky-attributes', mjAddStickyAttributes );

    const mjWithStickyControls = createHigherOrderComponent( function( BlockEdit ) {
        return function( props ) {
            if ( STICKY_SUPPORTED_BLOCKS.indexOf( props.name ) === -1 ) {
                return el( BlockEdit, props );
            }

            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const mode = attrs.mjStickyMode || 'none';

            return el(
                Fragment,
                {},
                el( BlockEdit, props ),
                el(
                    InspectorControls,
                    {},
                    el(
                        PanelBody,
                        {
                            title: '📌 MJ · Posición Sticky (Arriba / Abajo)',
                            initialOpen: mode !== 'none'
                        },
                        el( SelectControl, {
                            label: 'Comportamiento al hacer Scroll',
                            value: mode,
                            options: [
                                { label: 'Normal (Sin fijar)', value: 'none' },
                                { label: '📌 Sticky Arriba (Se pega al borde superior)', value: 'sticky-top' },
                                { label: '📌 Sticky Abajo (Se pega al borde inferior)', value: 'sticky-bottom' },
                                { label: '🔒 Fijo Permanente Arriba (Fixed Top)', value: 'fixed-top' },
                                { label: '🔒 Fijo Permanente Abajo (Fixed Bottom)', value: 'fixed-bottom' }
                            ],
                            onChange: function( val ) { setAttributes( { mjStickyMode: val } ); }
                        } ),
                        mode !== 'none' && el( RangeControl, {
                            label: 'Distancia del borde (Offset en px)',
                            value: typeof attrs.mjStickyOffset === 'number' ? attrs.mjStickyOffset : 0,
                            min: 0,
                            max: 160,
                            step: 2,
                            onChange: function( val ) { setAttributes( { mjStickyOffset: val } ); }
                        } ),
                        mode !== 'none' && el( SelectControl, {
                            label: 'Dispositivos donde aplica',
                            value: attrs.mjStickyDevice || 'all',
                            options: [
                                { label: '📱 + 💻 Todos (PC y Celular)', value: 'all' },
                                { label: '💻 Solo en PC (Escritorio > 960px)', value: 'desktop' },
                                { label: '📱 Solo en Celular y Tablet (< 960px)', value: 'mobile' }
                            ],
                            onChange: function( val ) { setAttributes( { mjStickyDevice: val } ); }
                        } ),
                        mode !== 'none' && el( ToggleControl, {
                            label: 'Efecto Fondo Cristal (Blur) + Sombra Suave',
                            checked: !!attrs.mjStickyBackdrop,
                            onChange: function( val ) { setAttributes( { mjStickyBackdrop: val } ); }
                        } ),
                        mode !== 'none' && el( RangeControl, {
                            label: 'Capa de Superposición (Z-Index)',
                            value: typeof attrs.mjStickyZIndex === 'number' ? attrs.mjStickyZIndex : 90,
                            min: 10,
                            max: 999,
                            step: 5,
                            onChange: function( val ) { setAttributes( { mjStickyZIndex: val } ); }
                        } )
                    )
                )
            );
        };
    }, 'mjWithStickyControls' );
    addFilter( 'editor.BlockEdit', 'mj-pro/with-sticky-controls', mjWithStickyControls );

    // =========================================================================
    // A2) EXTENSIÓN UNIVERSAL REACT: VISIBILIDAD POR PANTALLA Y ROLES / PERMISOS
    // =========================================================================
    function mjAddUniversalVisibilityAttributes( settings ) {
        settings.attributes = Object.assign( {}, settings.attributes, {
            mjHideMobile:       { type: 'boolean', default: false },
            mjHideTablet:       { type: 'boolean', default: false },
            mjHideDesktop:      { type: 'boolean', default: false },
            mjVisibilityMode:   { type: 'string', default: 'all' },
            mjVisibilityRoles:  { type: 'array', default: [] },
            mjVisibilityCap:    { type: 'string', default: '' }
        } );
        return settings;
    }
    addFilter( 'blocks.registerBlockType', 'mj-pro/universal-visibility-attributes', mjAddUniversalVisibilityAttributes );

    const mjWithVisibilityControls = createHigherOrderComponent( function( BlockEdit ) {
        return function( props ) {
            const attrs = props.attributes || {};
            const setAttributes = props.setAttributes;
            const visMode = attrs.mjVisibilityMode || 'all';
            const hideMobile = !!attrs.mjHideMobile;
            const hideTablet = !!attrs.mjHideTablet;
            const hideDesktop = !!attrs.mjHideDesktop;
            const hasDeviceRules = hideMobile || hideTablet || hideDesktop;
            const hasPermRules = visMode !== 'all';

            const editorData = window.mjProEditorData || {};
            const rolesList = editorData.roles || [
                { label: 'Administrador', value: 'administrator' },
                { label: 'Gestor de la tienda', value: 'shop_manager' },
                { label: 'Cliente', value: 'customer' },
                { label: 'Suscriptor', value: 'subscriber' }
            ];

            const capsList = editorData.caps || [
                { label: 'Administrador (manage_options)', value: 'manage_options' },
                { label: 'Gestor de Tienda (manage_woocommerce)', value: 'manage_woocommerce' },
                { label: 'Operador MJ ERP (manage_mjerp)', value: 'manage_mjerp' },
                { label: 'Consignatario MJ ERP (mjerp_consignee)', value: 'mjerp_consignee' },
                { label: 'Gestor de Turnos MJ (mjto_manage)', value: 'mjto_manage' }
            ];

            const currentRoles = Array.isArray( attrs.mjVisibilityRoles ) ? attrs.mjVisibilityRoles : [];

            return el(
                Fragment,
                {},
                el( BlockEdit, props ),
                el(
                    InspectorControls,
                    {},
                    // 1. PANEL DISPOSITIVOS / PANTALLA
                    el(
                        PanelBody,
                        {
                            title: '📱 MJ · Visibilidad en Pantallas',
                            initialOpen: hasDeviceRules
                        },
                        el( 'p', { style: { fontSize: '12px', color: '#64748b', marginTop: 0 } },
                            'Elegí en qué tipos de pantallas mostrar u ocultar este bloque:'
                        ),
                        el( ToggleControl, {
                            label: 'Ocultar en Celular (< 768px)',
                            checked: hideMobile,
                            onChange: function( val ) { setAttributes( { mjHideMobile: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Ocultar en Tablet (768px - 1024px)',
                            checked: hideTablet,
                            onChange: function( val ) { setAttributes( { mjHideTablet: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Ocultar en PC / Escritorio (> 1024px)',
                            checked: hideDesktop,
                            onChange: function( val ) { setAttributes( { mjHideDesktop: val } ); }
                        } )
                    ),
                    // 2. PANEL PERMISOS Y ROLES
                    el(
                        PanelBody,
                        {
                            title: '🔒 MJ · Permisos y Roles de Usuario',
                            initialOpen: hasPermRules
                        },
                        el( 'p', { style: { fontSize: '12px', color: '#64748b', marginTop: 0 } },
                            'Controlá quién puede ver este contenido. El filtrado se aplica en el servidor (el HTML no se envía si el usuario no tiene acceso).'
                        ),
                        el( SelectControl, {
                            label: 'Condición de Acceso',
                            value: visMode,
                            options: [
                                { label: 'Todos los usuarios (Público)', value: 'all' },
                                { label: 'Solo usuarios conectados', value: 'logged_in' },
                                { label: 'Solo visitantes (No conectados)', value: 'guests' },
                                { label: 'Por Roles de WordPress', value: 'roles' },
                                { label: 'Por Capacidad / Permiso (Capability)', value: 'capability' }
                            ],
                            onChange: function( val ) { setAttributes( { mjVisibilityMode: val } ); }
                        } ),
                        visMode === 'roles' && el( 'div', { style: { marginTop: '10px', marginBottom: '10px' } },
                            el( 'label', { style: { display: 'block', fontWeight: 600, fontSize: '12px', marginBottom: '6px' } }, 'Roles con acceso permitido:' ),
                            rolesList.map( function( r ) {
                                const isChecked = currentRoles.indexOf( r.value ) !== -1;
                                return el( ToggleControl, {
                                    key: r.value,
                                    label: r.label + ' (' + r.value + ')',
                                    checked: isChecked,
                                    onChange: function( checked ) {
                                        let next = currentRoles.slice();
                                        if ( checked ) {
                                            if ( next.indexOf( r.value ) === -1 ) { next.push( r.value ); }
                                        } else {
                                            next = next.filter( function( item ) { return item !== r.value; } );
                                        }
                                        setAttributes( { mjVisibilityRoles: next } );
                                    }
                                } );
                            } )
                        ),
                        visMode === 'capability' && el( Fragment, {},
                            el( SelectControl, {
                                label: 'Capacidad común',
                                value: capsList.some( function( c ) { return c.value === attrs.mjVisibilityCap; } ) ? attrs.mjVisibilityCap : 'custom',
                                options: capsList.concat( [ { label: 'Otra capacidad personalizada...', value: 'custom' } ] ),
                                onChange: function( val ) {
                                    if ( val !== 'custom' ) {
                                        setAttributes( { mjVisibilityCap: val } );
                                    }
                                }
                            } ),
                            el( TextControl, {
                                label: 'Nombre de la Capacidad (Capability)',
                                help: 'Ej: manage_mjerp, mjerp_consignee, mjto_manage, manage_options',
                                value: attrs.mjVisibilityCap || '',
                                onChange: function( val ) { setAttributes( { mjVisibilityCap: val } ); }
                            } )
                        )
                    )
                )
            );
        };
    }, 'mjWithVisibilityControls' );
    addFilter( 'editor.BlockEdit', 'mj-pro/universal-visibility-controls', mjWithVisibilityControls );

    // =========================================================================
    // B) HELPER REACT: PANELES DE TARJETAS DE PRODUCTO (BADGES + PRECIOS MULTIMÉTODO + COLORES)
    // =========================================================================
    function renderProductCardConfigPanels( attrs, setAttributes ) {
        return el(
            Fragment,
            {},
            el( PanelBody, { title: '🏷️ Badges: Sin Stock y Oferta', initialOpen: true },
                el( ToggleControl, {
                    label: 'Mostrar Badge cuando es SIN STOCK',
                    checked: attrs.showOutOfStockBadge !== false,
                    onChange: function( v ) { setAttributes( { showOutOfStockBadge: v } ); }
                } ),
                attrs.showOutOfStockBadge !== false && el( TextControl, {
                    label: 'Texto del Badge Sin Stock',
                    value: attrs.outOfStockText || 'SIN STOCK',
                    onChange: function( v ) { setAttributes( { outOfStockText: v } ); }
                } ),
                attrs.showOutOfStockBadge !== false && el( TextControl, {
                    label: 'Color de Fondo Sin Stock (HEX)',
                    value: attrs.outOfStockBgColor || '#dc2626',
                    onChange: function( v ) { setAttributes( { outOfStockBgColor: v } ); }
                } ),
                attrs.showOutOfStockBadge !== false && el( TextControl, {
                    label: 'Color de Texto Sin Stock (HEX)',
                    value: attrs.outOfStockTextColor || '#ffffff',
                    onChange: function( v ) { setAttributes( { outOfStockTextColor: v } ); }
                } ),
                el( ToggleControl, {
                    label: 'Mostrar Badge cuando está en OFERTA',
                    checked: attrs.showSaleBadge !== false,
                    onChange: function( v ) { setAttributes( { showSaleBadge: v } ); }
                } ),
                attrs.showSaleBadge !== false && el( TextControl, {
                    label: 'Texto del Badge Oferta',
                    value: attrs.saleBadgeText || 'OFERTA',
                    onChange: function( v ) { setAttributes( { saleBadgeText: v } ); }
                } ),
                attrs.showSaleBadge !== false && el( ToggleControl, {
                    label: 'Calcular y agregar % de ahorro automático (ej. -20%)',
                    checked: attrs.saleBadgeShowPct !== false,
                    onChange: function( v ) { setAttributes( { saleBadgeShowPct: v } ); }
                } ),
                attrs.showSaleBadge !== false && el( TextControl, {
                    label: 'Color de Fondo Badge Oferta (HEX)',
                    value: attrs.saleBadgeBgColor || '#ea580c',
                    onChange: function( v ) { setAttributes( { saleBadgeBgColor: v } ); }
                } ),
                attrs.showSaleBadge !== false && el( TextControl, {
                    label: 'Color de Texto Badge Oferta (HEX)',
                    value: attrs.saleBadgeTextColor || '#ffffff',
                    onChange: function( v ) { setAttributes( { saleBadgeTextColor: v } ); }
                } )
            ),
            el( PanelBody, { title: '💸 Método de Pago 1: Transferencia / Bancario', initialOpen: true },
                el( ToggleControl, {
                    label: 'Mostrar Precio con Descuento por Transferencia',
                    checked: attrs.showTransferPrice !== false,
                    onChange: function( v ) { setAttributes( { showTransferPrice: v } ); }
                } ),
                attrs.showTransferPrice !== false && el( RangeControl, {
                    label: '% de Descuento Transferencia',
                    value: typeof attrs.transferDiscountPct === 'number' ? attrs.transferDiscountPct : 15,
                    min: 0,
                    max: 50,
                    onChange: function( v ) { setAttributes( { transferDiscountPct: v } ); }
                } ),
                attrs.showTransferPrice !== false && el( TextControl, {
                    label: 'Etiqueta del método (ej: con Transferencia / Efectivo)',
                    value: attrs.transferLabel || 'con Transferencia / Efectivo',
                    onChange: function( v ) { setAttributes( { transferLabel: v } ); }
                } ),
                attrs.showTransferPrice !== false && el( TextControl, {
                    label: 'Color del Precio y Badge (HEX)',
                    value: attrs.transferTextColor || '#15803d',
                    onChange: function( v ) { setAttributes( { transferTextColor: v } ); }
                } ),
                attrs.showTransferPrice !== false && el( TextControl, {
                    label: 'Color de Fondo del recuadro (HEX)',
                    value: attrs.transferBgColor || '#dcfce7',
                    onChange: function( v ) { setAttributes( { transferBgColor: v } ); }
                } )
            ),
            el( PanelBody, { title: '💵 Método de Pago 2: Efectivo / Mayorista (Opcional)', initialOpen: false },
                el( ToggleControl, {
                    label: 'Mostrar 2do Precio con Descuento (ej. Efectivo en local)',
                    checked: !!attrs.showCashPrice,
                    onChange: function( v ) { setAttributes( { showCashPrice: v } ); }
                } ),
                !!attrs.showCashPrice && el( RangeControl, {
                    label: '% de Descuento Efectivo / 2do método',
                    value: typeof attrs.cashDiscountPct === 'number' ? attrs.cashDiscountPct : 20,
                    min: 0,
                    max: 50,
                    onChange: function( v ) { setAttributes( { cashDiscountPct: v } ); }
                } ),
                !!attrs.showCashPrice && el( TextControl, {
                    label: 'Etiqueta 2do método',
                    value: attrs.cashLabel || 'abonando en Efectivo en local',
                    onChange: function( v ) { setAttributes( { cashLabel: v } ); }
                } ),
                !!attrs.showCashPrice && el( TextControl, {
                    label: 'Color del Precio (HEX)',
                    value: attrs.cashTextColor || '#0369a1',
                    onChange: function( v ) { setAttributes( { cashTextColor: v } ); }
                } ),
                !!attrs.showCashPrice && el( TextControl, {
                    label: 'Color de Fondo (HEX)',
                    value: attrs.cashBgColor || '#e0f2fe',
                    onChange: function( v ) { setAttributes( { cashBgColor: v } ); }
                } )
            ),
            el( PanelBody, { title: '💳 Método de Pago 3: Cuotas con Tarjeta', initialOpen: false },
                el( ToggleControl, {
                    label: 'Mostrar cálculo de Cuotas',
                    checked: attrs.showInstallments !== false,
                    onChange: function( v ) { setAttributes( { showInstallments: v } ); }
                } ),
                attrs.showInstallments !== false && el( RangeControl, {
                    label: 'Cantidad de Cuotas',
                    value: typeof attrs.installmentsCount === 'number' ? attrs.installmentsCount : 3,
                    min: 1,
                    max: 18,
                    onChange: function( v ) { setAttributes( { installmentsCount: v } ); }
                } ),
                attrs.showInstallments !== false && el( RangeControl, {
                    label: '% de Recargo Total (0 = Sin Interés)',
                    value: typeof attrs.installmentsSurcharge === 'number' ? attrs.installmentsSurcharge : 0,
                    min: 0,
                    max: 100,
                    onChange: function( v ) { setAttributes( { installmentsSurcharge: v, installmentsSurchargePct: v } ); }
                } ),
                attrs.showInstallments !== false && el( TextControl, {
                    label: 'Texto de Cuotas',
                    value: attrs.installmentsLabel || 'cuotas sin interés de',
                    onChange: function( v ) { setAttributes( { installmentsLabel: v } ); }
                } ),
                attrs.showInstallments !== false && el( TextControl, {
                    label: 'Color del texto de Cuotas (HEX)',
                    value: attrs.installmentsTextColor || '#334155',
                    onChange: function( v ) { setAttributes( { installmentsTextColor: v } ); }
                } )
            )
        );
    }

    // =========================================================================
    // C) REGISTRO DE LOS 8 BLOQUES PERSONALIZADOS EN REACT
    // =========================================================================

    // 1. Buscador Pro en Vivo con AJAX v2
    blocks.registerBlockType( 'mj-pro/ajax-live-search', {
        title: 'MJ · Buscador en Vivo AJAX Pro (SKU, EAN, Marcas, Categorías y Carrito)',
        icon: 'search',
        category: 'mj-commerce-pro',
        description: 'Encuentra categorías, marcas, SKU, EAN y productos en tiempo real con precios y botón de agregar al carrito rápido.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: 'Configuración del Buscador AJAX Pro', initialOpen: true },
                        el( TextControl, {
                            label: 'Texto del Placeholder',
                            value: attrs.placeholder,
                            onChange: function( val ) { setAttributes( { placeholder: val } ); }
                        } ),
                        el( TextControl, {
                            label: 'Texto del Botón',
                            value: attrs.buttonLabel,
                            onChange: function( val ) { setAttributes( { buttonLabel: val } ); }
                        } ),
                        el( RangeControl, {
                            label: 'Cantidad máxima de productos sugeridos',
                            value: attrs.maxResults,
                            min: 4,
                            max: 16,
                            onChange: function( val ) { setAttributes( { maxResults: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar Categorías y Marcas encontradas arriba',
                            checked: attrs.showCategoriesBrands !== false,
                            onChange: function( val ) { setAttributes( { showCategoriesBrands: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar Precio de Lista / Oferta',
                            checked: attrs.showPrice !== false,
                            onChange: function( val ) { setAttributes( { showPrice: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar Precio con Descuento por Transferencia',
                            checked: attrs.showTransferPrice !== false,
                            onChange: function( val ) { setAttributes( { showTransferPrice: val } ); }
                        } ),
                        attrs.showTransferPrice !== false && el( RangeControl, {
                            label: '% Descuento Transferencia en el Buscador',
                            value: typeof attrs.transferDiscountPct === 'number' ? attrs.transferDiscountPct : 15,
                            min: 0,
                            max: 50,
                            onChange: function( val ) { setAttributes( { transferDiscountPct: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar códigos SKU y EAN en resultados',
                            checked: attrs.showSkuEan !== false,
                            onChange: function( val ) { setAttributes( { showSkuEan: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar Miniatura de Foto',
                            checked: attrs.showThumb !== false,
                            onChange: function( val ) { setAttributes( { showThumb: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar solo productos CON STOCK por defecto en el buscador',
                            checked: attrs.onlyInStock !== false,
                            onChange: function( val ) { setAttributes( { onlyInStock: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Permitir Agregar al Carrito Rápido (+ Carrito) desde el buscador',
                            checked: attrs.showQuickAddBtn !== false,
                            onChange: function( val ) { setAttributes( { showQuickAddBtn: val } ); }
                        } ),
                        el( TextControl, {
                            label: 'Color Principal del Buscador (HEX)',
                            value: attrs.accentColor,
                            onChange: function( val ) { setAttributes( { accentColor: val } ); }
                        } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, {
                        block: 'mj-pro/ajax-live-search',
                        attributes: attrs
                    } )
                )
            );
        },
        save: function() { return null; }
    } );

    // 2. Hero Slider / Banner Adaptable
    blocks.registerBlockType( 'mj-pro/hero-slider', {
        title: 'MJ · Hero Banner Pro Adaptable',
        icon: 'cover-image',
        category: 'mj-commerce-pro',
        description: 'Portada principal de alto impacto con etiqueta superior, título, subtítulo, doble botón y altura regulable.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: 'Textos y Enlaces del Hero', initialOpen: true },
                        el( TextControl, { label: 'Etiqueta Superior (Badge)', value: attrs.badgeText, onChange: function( val ) { setAttributes( { badgeText: val } ); } } ),
                        el( TextControl, { label: 'Título Principal', value: attrs.title, onChange: function( val ) { setAttributes( { title: val } ); } } ),
                        el( TextControl, { label: 'Subtítulo Descriptivo', value: attrs.subtitle, onChange: function( val ) { setAttributes( { subtitle: val } ); } } ),
                        el( TextControl, { label: 'Texto Botón Primario', value: attrs.primaryBtnText, onChange: function( val ) { setAttributes( { primaryBtnText: val } ); } } ),
                        el( TextControl, { label: 'URL Botón Primario', value: attrs.primaryBtnUrl, onChange: function( val ) { setAttributes( { primaryBtnUrl: val } ); } } ),
                        el( TextControl, { label: 'Texto Botón Secundario', value: attrs.secondaryBtnText, onChange: function( val ) { setAttributes( { secondaryBtnText: val } ); } } ),
                        el( TextControl, { label: 'URL Botón Secundario', value: attrs.secondaryBtnUrl, onChange: function( val ) { setAttributes( { secondaryBtnUrl: val } ); } } ),
                        el( TextControl, { label: 'Fondo CSS (Color o Gradiente)', value: attrs.bgGradient, onChange: function( val ) { setAttributes( { bgGradient: val } ); } } ),
                        el( RangeControl, { label: 'Altura Mínima (px)', value: attrs.minHeightPx, min: 260, max: 720, step: 20, onChange: function( val ) { setAttributes( { minHeightPx: val } ); } } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/hero-slider', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // 3. Barra de Confianza y Beneficios
    blocks.registerBlockType( 'mj-pro/trust-benefits-bar', {
        title: 'MJ · Barra de Beneficios (Envíos / Cuotas / Confianza)',
        icon: 'awards',
        category: 'mj-commerce-pro',
        description: 'Tira horizontal de 4 beneficios clave totalmente editables desde el panel lateral.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: 'Editar los 4 Beneficios', initialOpen: true },
                        el( TextControl, { label: 'Beneficio 1: Título', value: attrs.item1Title, onChange: function( v ) { setAttributes( { item1Title: v } ); } } ),
                        el( TextControl, { label: 'Beneficio 1: Bajada', value: attrs.item1Desc, onChange: function( v ) { setAttributes( { item1Desc: v } ); } } ),
                        el( TextControl, { label: 'Beneficio 2: Título', value: attrs.item2Title, onChange: function( v ) { setAttributes( { item2Title: v } ); } } ),
                        el( TextControl, { label: 'Beneficio 2: Bajada', value: attrs.item2Desc, onChange: function( v ) { setAttributes( { item2Desc: v } ); } } ),
                        el( TextControl, { label: 'Beneficio 3: Título', value: attrs.item3Title, onChange: function( v ) { setAttributes( { item3Title: v } ); } } ),
                        el( TextControl, { label: 'Beneficio 3: Bajada', value: attrs.item3Desc, onChange: function( v ) { setAttributes( { item3Desc: v } ); } } ),
                        el( TextControl, { label: 'Beneficio 4: Título', value: attrs.item4Title, onChange: function( v ) { setAttributes( { item4Title: v } ); } } ),
                        el( TextControl, { label: 'Beneficio 4: Bajada', value: attrs.item4Desc, onChange: function( v ) { setAttributes( { item4Desc: v } ); } } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/trust-benefits-bar', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // 4. Calculador Argentino + Badges de Stock/Oferta + Precios Multimétodo
    blocks.registerBlockType( 'mj-pro/arg-payment-calculator', {
        title: 'MJ · Precios por Método de Pago, Badges y WhatsApp',
        icon: 'money-alt',
        category: 'mj-commerce-pro',
        description: 'Muestra y permite configurar Badges de Sin Stock / Oferta, precios por Transferencia, Efectivo, Cuotas con colores personalizados y botón a WhatsApp.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    renderProductCardConfigPanels( attrs, setAttributes ),
                    el( PanelBody, { title: '💬 Botón de WhatsApp y Modo de Vista', initialOpen: false },
                        el( ToggleControl, {
                            label: 'Modo Compacto (para tarjetas de catálogo)',
                            checked: !!attrs.compactMode,
                            onChange: function( val ) { setAttributes( { compactMode: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar Botón de WhatsApp',
                            checked: !!attrs.showWhatsappBtn,
                            onChange: function( val ) { setAttributes( { showWhatsappBtn: val } ); }
                        } ),
                        el( TextControl, {
                            label: 'Número de WhatsApp (ej: 549223...)',
                            value: attrs.whatsappPhone,
                            onChange: function( val ) { setAttributes( { whatsappPhone: val } ); }
                        } ),
                        el( TextControl, {
                            label: 'Texto del Botón de WhatsApp',
                            value: attrs.whatsappBtnText,
                            onChange: function( val ) { setAttributes( { whatsappBtnText: val } ); }
                        } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/arg-payment-calculator', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // 5. Carrusel de Ofertas Relámpago con Tarjetas Configurables
    blocks.registerBlockType( 'mj-pro/flash-deals-carousel', {
        title: 'MJ · Ofertas Relámpago + Tarjetas Configurables',
        icon: 'clock',
        category: 'mj-commerce-pro',
        description: 'Grilla de productos con reloj regresivo y tarjetas configurables en badges de stock/oferta y precios por medio de pago.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: '⚡ Ajustes de la Sección y Reloj', initialOpen: true },
                        el( TextControl, { label: 'Título de la Sección', value: attrs.sectionTitle, onChange: function( val ) { setAttributes( { sectionTitle: val } ); } } ),
                        el( RangeControl, { label: 'Horas del Contador Regresivo', value: attrs.countdownHours, min: 1, max: 72, onChange: function( val ) { setAttributes( { countdownHours: val } ); } } ),
                        el( RangeControl, { label: 'Cantidad de Productos', value: attrs.productsCount, min: 2, max: 16, onChange: function( val ) { setAttributes( { productsCount: val } ); } } ),
                        el( RangeControl, { label: 'Columnas en Escritorio', value: attrs.columns, min: 2, max: 6, onChange: function( val ) { setAttributes( { columns: val } ); } } ),
                        el( ToggleControl, { label: 'Mostrar solo productos CON STOCK (Siempre tildado)', checked: attrs.onlyInStock !== false, onChange: function( val ) { setAttributes( { onlyInStock: val } ); } } ),
                        el( ToggleControl, { label: 'Mostrar solo productos en oferta', checked: !!attrs.onlyOnSale, onChange: function( val ) { setAttributes( { onlyOnSale: val } ); } } ),
                        el( ToggleControl, { label: 'Botón Agregar al Carrito Rápido AJAX', checked: attrs.enableAjaxQuickAdd !== false, onChange: function( val ) { setAttributes( { enableAjaxQuickAdd: val } ); } } )
                    ),
                    renderProductCardConfigPanels( attrs, setAttributes )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/flash-deals-carousel', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // 6. Grilla Pro de Categorías
    blocks.registerBlockType( 'mj-pro/category-grid-pro', {
        title: 'MJ · Grilla Pro de Categorías de WooCommerce',
        icon: 'grid-view',
        category: 'mj-commerce-pro',
        description: 'Grilla adaptable de categorías de productos con contador opcional.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: 'Ajustes de Grilla de Categorías', initialOpen: true },
                        el( TextControl, { label: 'Título de la Sección', value: attrs.sectionTitle, onChange: function( val ) { setAttributes( { sectionTitle: val } ); } } ),
                        el( RangeControl, { label: 'Columnas', value: attrs.columns, min: 2, max: 6, onChange: function( val ) { setAttributes( { columns: val } ); } } ),
                        el( ToggleControl, { label: 'Ocultar categorías sin productos', checked: !!attrs.hideEmpty, onChange: function( val ) { setAttributes( { hideEmpty: val } ); } } ),
                        el( ToggleControl, { label: 'Mostrar cantidad de productos', checked: !!attrs.showCount, onChange: function( val ) { setAttributes( { showCount: val } ); } } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/category-grid-pro', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // 7. Catálogo Interactivo con Filtros Rápidos AJAX + Tarjetas Configurables
    blocks.registerBlockType( 'mj-pro/ajax-catalog-filter', {
        title: 'MJ · Catálogo con Filtros AJAX + Tarjetas Configurables',
        icon: 'filter',
        category: 'mj-commerce-pro',
        description: 'Filtra por categoría, ofertas y precio en vivo sin recargar, con control total sobre los badges y precios de las tarjetas.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: '🔍 Configuración General del Catálogo AJAX', initialOpen: true },
                        el( TextControl, { label: 'Título del Bloque', value: attrs.title, onChange: function( val ) { setAttributes( { title: val } ); } } ),
                        el( RangeControl, { label: 'Productos a mostrar', value: attrs.productsPerPage, min: 4, max: 24, step: 4, onChange: function( val ) { setAttributes( { productsPerPage: val } ); } } ),
                        el( RangeControl, { label: 'Columnas en Escritorio', value: attrs.columns || 4, min: 2, max: 6, onChange: function( val ) { setAttributes( { columns: val } ); } } ),
                        el( ToggleControl, { label: 'Mostrar selector de ordenamiento por precio', checked: attrs.showSortSelector !== false, onChange: function( val ) { setAttributes( { showSortSelector: val } ); } } ),
                        el( ToggleControl, { label: 'Mostrar filtro "Solo con Stock" en la barra', checked: attrs.showStockToggle !== false, onChange: function( val ) { setAttributes( { showStockToggle: val } ); } } ),
                        el( ToggleControl, { label: 'Dejar "Solo con Stock" SIEMPRE TILDADO por defecto', checked: attrs.defaultOnlyInStock !== false, onChange: function( val ) { setAttributes( { defaultOnlyInStock: val } ); } } ),
                        el( ToggleControl, { label: 'Mostrar switch de Solo en Oferta', checked: attrs.showSaleToggle !== false, onChange: function( val ) { setAttributes( { showSaleToggle: val } ); } } ),
                        el( ToggleControl, { label: 'Botón Agregar al Carrito Rápido AJAX', checked: attrs.enableAjaxQuickAdd !== false, onChange: function( val ) { setAttributes( { enableAjaxQuickAdd: val } ); } } )
                    ),
                    renderProductCardConfigPanels( attrs, setAttributes )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/ajax-catalog-filter', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // 8. Barra de Navegación Inferior Celular (Mobile Sticky Bottom Nav Bar)
    blocks.registerBlockType( 'mj-pro/mobile-bottom-nav', {
        title: 'MJ · Barra Inferior Celular (Sticky Bottom App Bar)',
        icon: 'smartphone',
        category: 'mj-commerce-pro',
        description: 'Barra fija inferior estilo App para celulares con hasta 5 botones configurables, buscador modal AJAX y contador de carrito.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: 'Diseño General de la Barra Móvil', initialOpen: true },
                        el( ToggleControl, { label: 'Mostrar también en PC (para probar o dejar fija siempre)', checked: !!attrs.showOnDesktop, onChange: function( v ) { setAttributes( { showOnDesktop: v } ); } } ),
                        el( ToggleControl, { label: 'Efecto Cristal (Blur translúcido)', checked: !!attrs.glassEffect, onChange: function( v ) { setAttributes( { glassEffect: v } ); } } ),
                        el( TextControl, { label: 'Color de Fondo (HEX)', value: attrs.bgColor, onChange: function( v ) { setAttributes( { bgColor: v } ); } } ),
                        el( TextControl, { label: 'Color de Texto e Íconos (HEX)', value: attrs.textColor, onChange: function( v ) { setAttributes( { textColor: v } ); } } ),
                        el( TextControl, { label: 'Color de Acento / Contador Carrito (HEX)', value: attrs.accentColor, onChange: function( v ) { setAttributes( { accentColor: v } ); } } )
                    ),
                    el( PanelBody, { title: 'Botón 1 (Ej: Inicio)', initialOpen: false },
                        el( ToggleControl, { label: 'Mostrar Botón 1', checked: !!attrs.item1Show, onChange: function( v ) { setAttributes( { item1Show: v } ); } } ),
                        el( TextControl, { label: 'Ícono / Emoji', value: attrs.item1Icon, onChange: function( v ) { setAttributes( { item1Icon: v } ); } } ),
                        el( TextControl, { label: 'Etiqueta', value: attrs.item1Label, onChange: function( v ) { setAttributes( { item1Label: v } ); } } ),
                        el( TextControl, { label: 'URL de destino', value: attrs.item1Url, onChange: function( v ) { setAttributes( { item1Url: v } ); } } )
                    ),
                    el( PanelBody, { title: 'Botón 2 (Ej: Tienda / Categorías)', initialOpen: false },
                        el( ToggleControl, { label: 'Mostrar Botón 2', checked: !!attrs.item2Show, onChange: function( v ) { setAttributes( { item2Show: v } ); } } ),
                        el( TextControl, { label: 'Ícono / Emoji', value: attrs.item2Icon, onChange: function( v ) { setAttributes( { item2Icon: v } ); } } ),
                        el( TextControl, { label: 'Etiqueta', value: attrs.item2Label, onChange: function( v ) { setAttributes( { item2Label: v } ); } } ),
                        el( TextControl, { label: 'URL de destino', value: attrs.item2Url, onChange: function( v ) { setAttributes( { item2Url: v } ); } } )
                    ),
                    el( PanelBody, { title: 'Botón 3 (Ej: Buscador AJAX)', initialOpen: false },
                        el( ToggleControl, { label: 'Mostrar Botón 3', checked: !!attrs.item3Show, onChange: function( v ) { setAttributes( { item3Show: v } ); } } ),
                        el( ToggleControl, { label: 'Abrir Buscador AJAX Flotante al tocar', checked: !!attrs.item3OpenSearch, onChange: function( v ) { setAttributes( { item3OpenSearch: v } ); } } ),
                        el( TextControl, { label: 'Ícono / Emoji', value: attrs.item3Icon, onChange: function( v ) { setAttributes( { item3Icon: v } ); } } ),
                        el( TextControl, { label: 'Etiqueta', value: attrs.item3Label, onChange: function( v ) { setAttributes( { item3Label: v } ); } } ),
                        el( TextControl, { label: 'URL alternativa', value: attrs.item3Url, onChange: function( v ) { setAttributes( { item3Url: v } ); } } )
                    ),
                    el( PanelBody, { title: 'Botón 4 (Ej: Carrito con Contador)', initialOpen: false },
                        el( ToggleControl, { label: 'Mostrar Botón 4', checked: !!attrs.item4Show, onChange: function( v ) { setAttributes( { item4Show: v } ); } } ),
                        el( ToggleControl, { label: 'Mostrar burbuja con cantidad del carrito', checked: !!attrs.item4ShowBadge, onChange: function( v ) { setAttributes( { item4ShowBadge: v } ); } } ),
                        el( TextControl, { label: 'Ícono / Emoji', value: attrs.item4Icon, onChange: function( v ) { setAttributes( { item4Icon: v } ); } } ),
                        el( TextControl, { label: 'Etiqueta', value: attrs.item4Label, onChange: function( v ) { setAttributes( { item4Label: v } ); } } ),
                        el( TextControl, { label: 'URL de destino', value: attrs.item4Url, onChange: function( v ) { setAttributes( { item4Url: v } ); } } )
                    ),
                    el( PanelBody, { title: 'Botón 5 (Ej: Mi Cuenta / WhatsApp)', initialOpen: false },
                        el( ToggleControl, { label: 'Mostrar Botón 5', checked: !!attrs.item5Show, onChange: function( v ) { setAttributes( { item5Show: v } ); } } ),
                        el( TextControl, { label: 'Ícono / Emoji', value: attrs.item5Icon, onChange: function( v ) { setAttributes( { item5Icon: v } ); } } ),
                        el( TextControl, { label: 'Etiqueta', value: attrs.item5Label, onChange: function( v ) { setAttributes( { item5Label: v } ); } } ),
                        el( TextControl, { label: 'URL de destino (ej. /mi-cuenta/ o https://wa.me/...)', value: attrs.item5Url, onChange: function( v ) { setAttributes( { item5Url: v } ); } } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/mobile-bottom-nav', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // 9. Checkout Modular por Pasos (100% Configurable)
    blocks.registerBlockType( 'mj-pro/multistep-checkout', {
        title: 'MJ · Checkout Modular por Pasos (100% Configurable)',
        icon: 'cart',
        category: 'mj-commerce-pro',
        description: 'Organiza el proceso de compra en 3 pasos con cálculo dinámico de envíos (MJ Envíos, Andreani, Correo Argentino), validación y resumen.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: 'Paso 1: Datos y Entrega', initialOpen: true },
                        el( TextControl, { label: 'Título del Paso 1', value: attrs.step1Title || 'Tus Datos y Entrega', onChange: function( v ) { setAttributes( { step1Title: v } ); } } ),
                        el( TextControl, { label: 'Bajada / Ayuda', value: attrs.step1Subtitle || '', onChange: function( v ) { setAttributes( { step1Subtitle: v } ); } } ),
                        el( TextControl, { label: 'Ícono / Emoji', value: attrs.step1Icon || '👤', onChange: function( v ) { setAttributes( { step1Icon: v } ); } } )
                    ),
                    el( PanelBody, { title: 'Paso 2: Métodos de Envío', initialOpen: false },
                        el( TextControl, { label: 'Título del Paso 2', value: attrs.step2Title || 'Método de Envío', onChange: function( v ) { setAttributes( { step2Title: v } ); } } ),
                        el( TextControl, { label: 'Bajada / Ayuda', value: attrs.step2Subtitle || '', onChange: function( v ) { setAttributes( { step2Subtitle: v } ); } } ),
                        el( TextControl, { label: 'Ícono / Emoji', value: attrs.step2Icon || '🚚', onChange: function( v ) { setAttributes( { step2Icon: v } ); } } )
                    ),
                    el( PanelBody, { title: 'Paso 3: Pago y Confirmación', initialOpen: false },
                        el( TextControl, { label: 'Título del Paso 3', value: attrs.step3Title || 'Pago y Confirmación', onChange: function( v ) { setAttributes( { step3Title: v } ); } } ),
                        el( TextControl, { label: 'Bajada / Ayuda', value: attrs.step3Subtitle || '', onChange: function( v ) { setAttributes( { step3Subtitle: v } ); } } ),
                        el( TextControl, { label: 'Ícono / Emoji', value: attrs.step3Icon || '💳', onChange: function( v ) { setAttributes( { step3Icon: v } ); } } )
                    ),
                    el( PanelBody, { title: '🎨 Estilos y Confianza', initialOpen: false },
                        el( TextControl, { label: 'Texto de Confianza / Seguridad', value: attrs.trustBadgeText || '', onChange: function( v ) { setAttributes( { trustBadgeText: v } ); } } ),
                        el( ToggleControl, { label: 'Mostrar Resumen Flotante de Compra a la derecha', checked: attrs.showSidebarSummary !== false, onChange: function( v ) { setAttributes( { showSidebarSummary: v } ); } } ),
                        el( TextControl, { label: 'Color Azul Primario (HEX)', value: attrs.accentColor || '#0284c7', onChange: function( v ) { setAttributes( { accentColor: v } ); } } ),
                        el( TextControl, { label: 'Color Cian de Acento (HEX)', value: attrs.cyanColor || '#06b6d4', onChange: function( v ) { setAttributes( { cyanColor: v } ); } } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/multistep-checkout', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // =========================================================================
    // BLOQUE 10: MJ DÓLAR — COTIZACIÓN Y TICKER EN VIVO
    // =========================================================================
    blocks.registerBlockType( 'mj-pro/dolar-ticker', {
        title: 'MJ Dólar — Cotización en Vivo',
        icon: 'money-alt',
        category: 'mj-commerce-pro',
        description: 'Muestra la cotización del dólar en vivo conectada con MJ Dólar o tasa personalizada. Configurable como barra, píldora o tarjeta.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: '💵 Ajustes de Cotización', initialOpen: true },
                        el( SelectControl, {
                            label: 'Diseño Visual',
                            value: attrs.styleMode || 'bar',
                            options: [
                                { label: 'Barra Completa (Superior o Inline)', value: 'bar' },
                                { label: 'Píldora Compacta (Header o Menú)', value: 'pill' },
                                { label: 'Tarjeta Destacada (Card)', value: 'card' }
                            ],
                            onChange: function( v ) { setAttributes( { styleMode: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Texto del Título',
                            value: attrs.titleText || 'Cotización USD:',
                            onChange: function( v ) { setAttributes( { titleText: v } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar etiqueta de tipo de cambio',
                            checked: attrs.showRateType !== false,
                            onChange: function( v ) { setAttributes( { showRateType: v } ); }
                        } ),
                        attrs.showRateType !== false && el( TextControl, {
                            label: 'Etiqueta de Tipo de Cambio',
                            value: attrs.rateTypeLabel || 'Tipo de Cambio',
                            onChange: function( v ) { setAttributes( { rateTypeLabel: v } ); }
                        } ),
                        el( RangeControl, {
                            label: 'Cotización manual (0 = Usar cotización del plugin MJ Dólar)',
                            value: typeof attrs.customRate === 'number' ? attrs.customRate : 0,
                            min: 0,
                            max: 5000,
                            step: 10,
                            onChange: function( v ) { setAttributes( { customRate: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Enlace al hacer click (Opcional)',
                            value: attrs.linkUrl || '',
                            onChange: function( v ) { setAttributes( { linkUrl: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Color de Acento / Tasa (HEX)',
                            value: attrs.accentColor || '#16a34a',
                            onChange: function( v ) { setAttributes( { accentColor: v } ); }
                        } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/dolar-ticker', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // =========================================================================
    // BLOQUE 11: MJ ERP — ACCESO RÁPIDO Y PANEL DE CONTROL
    // =========================================================================
    blocks.registerBlockType( 'mj-pro/erp-launcher', {
        title: 'MJ ERP — Acceso y Panel',
        icon: 'database',
        category: 'mj-commerce-pro',
        description: 'Botón o tarjeta de acceso directo al sistema MJ ERP o al portal de consignaciones, protegido por permisos.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: '📦 Configuración de Acceso al ERP', initialOpen: true },
                        el( TextControl, {
                            label: 'Texto del Botón / Título',
                            value: attrs.buttonLabel || 'Acceder a MJ ERP',
                            onChange: function( v ) { setAttributes( { buttonLabel: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Subtexto / Descripción',
                            value: attrs.subText || 'Ventas, stock y caja',
                            onChange: function( v ) { setAttributes( { subText: v } ); }
                        } ),
                        el( SelectControl, {
                            label: 'Destino del Acceso',
                            value: attrs.target || 'admin',
                            options: [
                                { label: 'Panel Administrativo MJ ERP (/wp-admin/admin.php?page=mj-erp)', value: 'admin' },
                                { label: 'Portal de Consignatario (/portal-consignaciones/)', value: 'portal' },
                                { label: 'URL personalizada', value: 'custom' }
                            ],
                            onChange: function( v ) { setAttributes( { target: v } ); }
                        } ),
                        attrs.target === 'custom' && el( TextControl, {
                            label: 'URL Destino Personalizada',
                            value: attrs.customUrl || '',
                            onChange: function( v ) { setAttributes( { customUrl: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Texto del Badge (Ej: Operadores, Revendedor)',
                            value: attrs.badgeText || 'Operadores',
                            onChange: function( v ) { setAttributes( { badgeText: v } ); }
                        } ),
                        el( SelectControl, {
                            label: 'Diseño',
                            value: attrs.layoutMode || 'button',
                            options: [
                                { label: 'Botón Moderno', value: 'button' },
                                { label: 'Tarjeta Destacada (Card)', value: 'card' }
                            ],
                            onChange: function( v ) { setAttributes( { layoutMode: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Capacidad Requerida (Capability)',
                            help: 'manage_mjerp para operadores o mjerp_consignee para revendedores',
                            value: attrs.requireCap || 'manage_mjerp',
                            onChange: function( v ) { setAttributes( { requireCap: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Color de Fondo (HEX)',
                            value: attrs.bgColor || '#0f172a',
                            onChange: function( v ) { setAttributes( { bgColor: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Color de Acento (HEX)',
                            value: attrs.accentColor || '#3b82f6',
                            onChange: function( v ) { setAttributes( { accentColor: v } ); }
                        } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/erp-launcher', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // =========================================================================
    // BLOQUE 12: MJ MEDIOS DE PAGO — GRILLA DE MÉTODOS Y PROMOCIONES
    // =========================================================================
    blocks.registerBlockType( 'mj-pro/payment-methods-grid', {
        title: 'MJ Medios de Pago — Grilla de Métodos',
        icon: 'tickets-alt',
        category: 'mj-commerce-pro',
        description: 'Grilla informativa con descuentos por transferencia, efectivo y cuotas sin interés.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: '💳 Configuración de Medios de Pago', initialOpen: true },
                        el( TextControl, {
                            label: 'Título Principal',
                            value: attrs.title || 'Medios de Pago y Promociones',
                            onChange: function( v ) { setAttributes( { title: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Bajada / Subtítulo',
                            value: attrs.subtitle || 'Aboná con todas las opciones disponibles',
                            onChange: function( v ) { setAttributes( { subtitle: v } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Usar ajustes automáticos del plugin MJ Medios de Pago',
                            checked: attrs.usePluginSettings !== false,
                            onChange: function( v ) { setAttributes( { usePluginSettings: v } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar Transferencia / Débito',
                            checked: attrs.showTransfer !== false,
                            onChange: function( v ) { setAttributes( { showTransfer: v } ); }
                        } ),
                        attrs.showTransfer !== false && el( RangeControl, {
                            label: '% Descuento Transferencia',
                            value: typeof attrs.transferDiscountPct === 'number' ? attrs.transferDiscountPct : 15,
                            min: 0,
                            max: 50,
                            step: 1,
                            onChange: function( v ) { setAttributes( { transferDiscountPct: v } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar Efectivo en Local',
                            checked: attrs.showCash !== false,
                            onChange: function( v ) { setAttributes( { showCash: v } ); }
                        } ),
                        attrs.showCash !== false && el( RangeControl, {
                            label: '% Descuento Efectivo',
                            value: typeof attrs.cashDiscountPct === 'number' ? attrs.cashDiscountPct : 20,
                            min: 0,
                            max: 50,
                            step: 1,
                            onChange: function( v ) { setAttributes( { cashDiscountPct: v } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar Cuotas sin Interés',
                            checked: attrs.showInstallments !== false,
                            onChange: function( v ) { setAttributes( { showInstallments: v } ); }
                        } ),
                        attrs.showInstallments !== false && el( RangeControl, {
                            label: 'Cantidad de Cuotas sin Interés',
                            value: typeof attrs.installmentsCount === 'number' ? attrs.installmentsCount : 3,
                            min: 1,
                            max: 24,
                            step: 1,
                            onChange: function( v ) { setAttributes( { installmentsCount: v } ); }
                        } ),
                        el( ToggleControl, {
                            label: 'Mostrar Mercado Pago y Billeteras',
                            checked: attrs.showCards !== false,
                            onChange: function( v ) { setAttributes( { showCards: v } ); }
                        } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/payment-methods-grid', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // =========================================================================
    // BLOQUE 13: MJ ENVÍOS — CALCULADOR RÁPIDO POR CÓDIGO POSTAL
    // =========================================================================
    blocks.registerBlockType( 'mj-pro/shipping-calculator', {
        title: 'MJ Envíos — Calculador por Código Postal',
        icon: 'car',
        category: 'mj-commerce-pro',
        description: 'Campo rápido de cotización de envíos por CP para la ficha de producto o carrito.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: '🚚 Ajustes de Envío', initialOpen: true },
                        el( TextControl, {
                            label: 'Título',
                            value: attrs.title || 'Calculá el costo de envío',
                            onChange: function( v ) { setAttributes( { title: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Placeholder del Input',
                            value: attrs.placeholder || 'Ingresá tu código postal...',
                            onChange: function( v ) { setAttributes( { placeholder: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Texto del Botón',
                            value: attrs.buttonLabel || 'Calcular Envío',
                            onChange: function( v ) { setAttributes( { buttonLabel: v } ); }
                        } ),
                        el( RangeControl, {
                            label: 'Monto para Envío Gratis ($)',
                            value: typeof attrs.freeShippingThreshold === 'number' ? attrs.freeShippingThreshold : 0,
                            min: 0,
                            max: 200000,
                            step: 1000,
                            onChange: function( v ) { setAttributes( { freeShippingThreshold: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Texto de Aviso de Envío Gratis',
                            value: attrs.freeShippingNotice || '¡Envío gratis a todo el país superando este monto!',
                            onChange: function( v ) { setAttributes( { freeShippingNotice: v } ); }
                        } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/shipping-calculator', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

    // =========================================================================
    // BLOQUE 14: MJ TURNOS ONLINE — AGENDA Y RESERVA DE SERVICIOS
    // =========================================================================
    blocks.registerBlockType( 'mj-pro/turnos-embed', {
        title: 'MJ Turnos Online — Agenda y Reserva',
        icon: 'calendar-alt',
        category: 'mj-commerce-pro',
        description: 'Incrusta el sistema interactivo de turnos online de MJ Turnos Online en cualquier página.',
        edit: function( props ) {
            const attrs = props.attributes;
            const setAttributes = props.setAttributes;
            const blockProps = useBlockProps();

            return el( Fragment, {},
                el( InspectorControls, {},
                    el( PanelBody, { title: '📅 Ajustes de Turnos', initialOpen: true },
                        el( TextControl, {
                            label: 'Título de la Sección',
                            value: attrs.title || 'Reservá tu Turno Online',
                            onChange: function( v ) { setAttributes( { title: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Subtítulo',
                            value: attrs.subtitle || 'Elegí servicio, fecha y horario disponible',
                            onChange: function( v ) { setAttributes( { subtitle: v } ); }
                        } ),
                        el( TextControl, {
                            label: 'Color de Acento (HEX)',
                            value: attrs.accentColor || '#ec4899',
                            onChange: function( v ) { setAttributes( { accentColor: v } ); }
                        } )
                    )
                ),
                el( 'div', blockProps,
                    el( ServerSideRender, { block: 'mj-pro/turnos-embed', attributes: attrs } )
                )
            );
        },
        save: function() { return null; }
    } );

} )(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.serverSideRender,
    window.wp.hooks,
    window.wp.compose
);
