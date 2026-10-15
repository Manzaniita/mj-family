/**
 * MJ Commerce Pro v1.2.0 - Motor Interactivo Frontend
 * 1) Motor Universal Sticky Top / Sticky Bottom con Spacer (funciona incluso dentro de <header> o grupos anidados)
 * 2) Buscador en Vivo AJAX Pro (Categorías, Marcas, SKU, EAN, Precios y Botón Agregar al Carrito Rápido)
 * 3) Agregar al Carrito Rápido por AJAX con actualización en vivo del Mini-Carrito y Barra Móvil
 * 4) Filtrado Instantáneo de Catálogo y Reloj Regresivo
 */
document.addEventListener('DOMContentLoaded', function () {
    const ajaxConfig = window.mjProAjax || { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: '' };

    // =========================================================================
    // 1. MOTOR UNIVERSAL STICKY ARRIBA / ABAJO (CSS + JS HYBRID CON SPACER)
    // =========================================================================
    function getAdminBarOffset() {
        const adminBar = document.getElementById('wpadminbar');
        if (!adminBar) return 0;
        const style = window.getComputedStyle(adminBar);
        if (style.position === 'fixed') {
            return adminBar.offsetHeight || 0;
        }
        return 0;
    }

    function isDeviceMatch(deviceRule) {
        const w = window.innerWidth;
        if (deviceRule === 'desktop') return w > 960;
        if (deviceRule === 'mobile') return w <= 960;
        return true;
    }

    const stickyElements = Array.from(document.querySelectorAll('.mj-has-sticky'));
    const managedStickies = [];

    stickyElements.forEach(function (el) {
        const mode = el.dataset.mjStickyMode || 'none';
        if (mode === 'none') return;

        const offsetPx = parseInt(el.dataset.mjStickyOffset || '0', 10);
        const zIndex = parseInt(el.dataset.mjStickyZ || '90', 10);
        const device = el.dataset.mjStickyDevice || 'all';
        const isColumn = el.classList.contains('wp-block-column');

        const isInsideHeader = !!el.closest('header, .wp-block-template-part');
        const effectiveZIndex = isInsideHeader ? Math.max(1050, zIndex) : Math.min(40, zIndex);

        // Si es una columna dentro de wp-block-columns, usamos sticky nativo asegurándonos que quede DEBAJO del header fijo y con menor z-index
        if (isColumn && (mode === 'sticky-top' || mode === 'sticky-bottom')) {
            const parentCols = el.closest('.wp-block-columns');
            if (parentCols) {
                parentCols.style.alignItems = 'flex-start';
                parentCols.style.overflow = 'visible';
            }
            el.style.zIndex = '25';
            if (mode === 'sticky-top') {
                const headerEl = document.querySelector('header.wp-block-template-part, header');
                const headerH = headerEl ? headerEl.offsetHeight : 84;
                const safeTop = Math.max(offsetPx, headerH + getAdminBarOffset() + 18);
                el.style.top = safeTop + 'px';
            }
            return;
        }

        // Para Filas, Grupos, Grillas o Headers (incluyendo bloques dentro de <header class="wp-block-template-part">),
        // creamos un spacer dinámico para fijarlos con precisión milimétrica sin saltos de página.
        const spacer = document.createElement('div');
        spacer.className = 'mj-sticky-spacer';
        spacer.style.display = 'none';
        spacer.style.width = '100%';
        spacer.style.pointerEvents = 'none';
        el.parentNode.insertBefore(spacer, el);

        managedStickies.push({
            el: el,
            spacer: spacer,
            mode: mode,
            offsetPx: offsetPx,
            zIndex: effectiveZIndex,
            device: device,
            isPinned: false,
            naturalTop: 0,
            naturalLeft: 0,
            naturalWidth: 0,
            naturalHeight: 0
        });
    });

    function measureStickies() {
        managedStickies.forEach(function (item) {
            const wasPinned = item.isPinned;
            if (wasPinned) {
                unpinStickyItem(item);
            }
            const rect = item.el.getBoundingClientRect();
            item.naturalTop = rect.top + window.scrollY;
            item.naturalLeft = rect.left;
            item.naturalWidth = rect.width;
            item.naturalHeight = rect.height;
            if (wasPinned) {
                updateStickyItem(item);
            }
        });
    }

    function pinStickyItem(item, topOrBottomProp, pxVal) {
        const rect = item.isPinned ? item.spacer.getBoundingClientRect() : item.el.getBoundingClientRect();
        item.naturalLeft = rect.left;
        item.naturalWidth = rect.width;
        item.naturalHeight = item.el.offsetHeight;

        item.spacer.style.display = 'block';
        item.spacer.style.height = item.naturalHeight + 'px';

        item.el.classList.add('is-mj-pinned');
        item.el.style.position = 'fixed';
        item.el.style.left = item.naturalLeft + 'px';
        item.el.style.width = item.naturalWidth + 'px';
        item.el.style.zIndex = String(item.zIndex);
        item.el.style.boxSizing = 'border-box';

        if (topOrBottomProp === 'top') {
            item.el.style.top = pxVal + 'px';
            item.el.style.bottom = 'auto';
        } else {
            item.el.style.bottom = pxVal + 'px';
            item.el.style.top = 'auto';
        }
        item.isPinned = true;
    }

    function unpinStickyItem(item) {
        item.spacer.style.display = 'none';
        item.spacer.style.height = '0px';
        item.el.classList.remove('is-mj-pinned');
        item.el.style.position = '';
        item.el.style.top = '';
        item.el.style.bottom = '';
        item.el.style.left = '';
        item.el.style.width = '';
        item.isPinned = false;
    }

    function updateStickyItem(item) {
        if (!isDeviceMatch(item.device)) {
            if (item.isPinned) unpinStickyItem(item);
            return;
        }

        const scrollY = window.scrollY || window.pageYOffset;
        const adminOffset = getAdminBarOffset();

        if (item.mode === 'fixed-top') {
            pinStickyItem(item, 'top', item.offsetPx + adminOffset);
        } else if (item.mode === 'fixed-bottom') {
            pinStickyItem(item, 'bottom', item.offsetPx);
        } else if (item.mode === 'sticky-top') {
            const targetTop = item.offsetPx + adminOffset;
            if (!item.isPinned) {
                const currentRect = item.el.getBoundingClientRect();
                item.naturalTop = currentRect.top + scrollY;
            }
            if (scrollY + targetTop >= item.naturalTop) {
                pinStickyItem(item, 'top', targetTop);
            } else if (item.isPinned) {
                unpinStickyItem(item);
            }
        } else if (item.mode === 'sticky-bottom') {
            const viewportBottom = scrollY + window.innerHeight;
            if (!item.isPinned) {
                const currentRect = item.el.getBoundingClientRect();
                item.naturalTop = currentRect.top + scrollY;
            }
            if (viewportBottom - item.offsetPx <= item.naturalTop + item.naturalHeight) {
                pinStickyItem(item, 'bottom', item.offsetPx);
            } else if (item.isPinned) {
                unpinStickyItem(item);
            }
        }
    }

    function onScrollAndResize() {
        managedStickies.forEach(updateStickyItem);
    }

    if (managedStickies.length > 0) {
        measureStickies();
        onScrollAndResize();
        window.addEventListener('scroll', onScrollAndResize, { passive: true });
        window.addEventListener('resize', function () {
            measureStickies();
            onScrollAndResize();
        });
    }

    // =========================================================================
    // 2. BARRA MÓVIL INFERIOR Y MODAL BUSCADOR
    // =========================================================================
    if (document.querySelector('.mj-mobile-bottom-nav')) {
        document.body.classList.add('has-mj-mobile-nav');
    }

    const openSearchBtns = document.querySelectorAll('.js-mj-open-mobile-search');
    const closeSearchBtns = document.querySelectorAll('.js-mj-close-mobile-search');
    const searchModal = document.querySelector('.mj-mobile-search-modal');

    if (searchModal) {
        openSearchBtns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                searchModal.hidden = false;
                const input = searchModal.querySelector('.mj-ajax-search-input');
                if (input) setTimeout(function () { input.focus(); }, 60);
            });
        });

        closeSearchBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                searchModal.hidden = true;
            });
        });
    }

    // =========================================================================
    // 3. FUNCIÓN GLOBAL: AGREGAR AL CARRITO RÁPIDO CON AJAX (BUSCADOR + TARJETAS)
    // =========================================================================
    function triggerQuickAddToCart(buttonEl) {
        if (!buttonEl || buttonEl.disabled) return;
        const productId = buttonEl.dataset.productId;
        const qty = buttonEl.dataset.qty || '1';
        if (!productId) return;

        const originalHtml = buttonEl.innerHTML;
        buttonEl.disabled = true;
        buttonEl.classList.add('is-loading');
        buttonEl.innerHTML = '⏳ Agregando...';

        const formData = new FormData();
        formData.append('action', 'mj_pro_quick_add_to_cart');
        formData.append('nonce', ajaxConfig.nonce);
        formData.append('productId', productId);
        formData.append('quantity', qty);

        fetch(ajaxConfig.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            buttonEl.classList.remove('is-loading');
            if (res.success) {
                buttonEl.classList.add('is-added');
                buttonEl.innerHTML = '✓ ¡Agregado!';

                // Actualizar contadores del carrito en Barra Móvil y Mini-Cart de WooCommerce
                const count = res.data.cartCount || 1;
                document.querySelectorAll('.js-mj-cart-badge, .wc-block-mini-cart__badge').forEach(function (badge) {
                    badge.textContent = String(count);
                    badge.hidden = false;
                });
                if (res.data.cartTotal) {
                    document.querySelectorAll('.wc-block-mini-cart__amount').forEach(function (amt) {
                        amt.textContent = res.data.cartTotal;
                    });
                }
                // Disparar evento nativo de WooCommerce Blocks para sincronizar el drawer del Mini-Cart
                document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart', { bubbles: true }));

                setTimeout(function () {
                    buttonEl.disabled = false;
                    buttonEl.classList.remove('is-added');
                    buttonEl.innerHTML = originalHtml;
                }, 1800);
            } else {
                buttonEl.disabled = false;
                buttonEl.innerHTML = originalHtml;
            }
        })
        .catch(function () {
            buttonEl.disabled = false;
            buttonEl.classList.remove('is-loading');
            buttonEl.innerHTML = originalHtml;
        });
    }

    document.addEventListener('click', function (e) {
        const quickBtn = e.target.closest('.js-mj-quick-add');
        if (quickBtn) {
            e.preventDefault();
            e.stopPropagation();
            triggerQuickAddToCart(quickBtn);
        }
    });

    // =========================================================================
    // 4. BUSCADOR EN VIVO AJAX PRO (CATEGORÍAS, MARCAS, SKU, EAN, PRECIOS Y QUICK ADD)
    // =========================================================================
    const searchWrappers = document.querySelectorAll('.mj-ajax-search-wrap');
    searchWrappers.forEach(function (wrapper) {
        const input = wrapper.querySelector('.mj-ajax-search-input');
        const clearBtn = wrapper.querySelector('.mj-search-clear-btn');
        const resultsBox = wrapper.querySelector('.mj-ajax-search-results');
        if (!input || !resultsBox) return;

        let debounceTimer = null;

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                input.value = '';
                clearBtn.hidden = true;
                resultsBox.hidden = true;
                resultsBox.innerHTML = '';
                input.focus();
            });
        }

        input.addEventListener('input', function () {
            const term = input.value.trim();
            if (clearBtn) clearBtn.hidden = term.length === 0;
            clearTimeout(debounceTimer);

            if (term.length < 2) {
                resultsBox.hidden = true;
                resultsBox.innerHTML = '';
                return;
            }

            debounceTimer = setTimeout(function () {
                runLiveSearchQuery();
            }, 220);
        });

            function runLiveSearchQuery() {
                const currentTerm = input.value.trim();
                if (currentTerm.length < 2) return;
                const onlyStockFlag = wrapper.dataset.onlyStock !== '0' ? '1' : '0';

                const formData = new FormData();
                formData.append('action', 'mj_pro_live_search');
                formData.append('nonce', ajaxConfig.nonce);
                formData.append('term', currentTerm);
                formData.append('maxResults', wrapper.dataset.maxResults || '8');
                formData.append('transferPct', wrapper.dataset.transferPct || '15');
                formData.append('onlyStock', onlyStockFlag);

                resultsBox.hidden = false;
                resultsBox.innerHTML = '<div class="mj-search-loading"><span class="mj-spinner"></span> Buscando productos, marcas, categorías o SKU...</div>';

                fetch(ajaxConfig.ajaxUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) {
                        resultsBox.hidden = true;
                        return;
                    }

                    const showCats = wrapper.dataset.showCats !== '0';
                    const showPrice = wrapper.dataset.showPrice !== '0';
                    const showTransfer = wrapper.dataset.showTransfer !== '0';
                    const showSku = wrapper.dataset.showSku !== '0';
                    const showThumb = wrapper.dataset.showThumb !== '0';
                    const showQuickAdd = wrapper.dataset.showQuickAdd !== '0';

                    const termsList = (showCats && res.data.terms) ? res.data.terms : [];
                    const itemsList = res.data.items || [];

                    if (!termsList.length && !itemsList.length) {
                        const stockCheckedAttrEmpty = wrapper.dataset.onlyStock !== '0' ? 'checked' : '';
                        resultsBox.innerHTML = '<div class="mj-search-top-filter"><span>Sin coincidencias</span><label class="mj-search-stock-Check"><input type="checkbox" class="js-mj-search-stock-toggle" ' + stockCheckedAttrEmpty + ' /> Solo con stock</label></div><div class="mj-search-empty">' + (res.data.skippedOosCount > 0 ? 'Hay <strong>' + res.data.skippedOosCount + ' productos sin stock</strong> para "' + currentTerm + '". Destildá <em>Solo con stock</em> arriba para verlos.' : 'No encontramos productos disponibles para <strong>"' + currentTerm + '"</strong>') + '</div>';
                        const stEl = resultsBox.querySelector('.js-mj-search-stock-toggle');
                        if (stEl) {
                            stEl.addEventListener('change', function () {
                                wrapper.dataset.onlyStock = stEl.checked ? '1' : '0';
                                runLiveSearchQuery();
                            });
                        }
                        return;
                    }

                    let html = '';

                    // Sección 1: Categorías y Marcas encontradas
                    if (termsList.length > 0) {
                        html += '<div class="mj-search-tax-section">';
                        html += '<div class="mj-search-section-label">Categorías y Marcas</div>';
                        html += '<div class="mj-search-tax-chips">';
                        termsList.forEach(function (t) {
                            html += '<a href="' + t.url + '" class="mj-search-tax-chip">' +
                                '<span class="mj-chip-type">' + t.type + ':</span> ' +
                                '<strong>' + t.name + '</strong> ' +
                                '<span class="mj-chip-count">(' + t.count + ')</span>' +
                            '</a>';
                        });
                        html += '</div></div>';
                    }

                    // Sección 2: Productos encontrados
                    if (itemsList.length === 0 && res.data.skippedOosCount > 0) {
                        html += '<div class="mj-search-empty">Hay <strong>' + res.data.skippedOosCount + ' productos sin stock</strong> en esta búsqueda. Destildá <em>"Solo con stock"</em> arriba a la derecha para verlos.</div>';
                    }
                    if (itemsList.length > 0) {
                        html += '<div class="mj-search-items-section">';
                        html += '<div class="mj-search-section-label">Productos encontrados</div>';

                        itemsList.forEach(function (item) {
                            const codes = [];
                            if (showSku && item.sku) codes.push('SKU: ' + item.sku);
                            if (showSku && item.ean) codes.push('EAN: ' + item.ean);
                            const taxMeta = [item.brand, item.category].filter(Boolean).join(' · ');

                            html += '<div class="mj-search-item-row">';
                            html += '<a class="mj-search-item-link" href="' + item.url + '">';
                            if (showThumb) {
                                if (item.thumb) {
                                    html += '<img src="' + item.thumb + '" alt="" class="mj-search-thumb" />';
                                } else {
                                    html += '<span class="mj-search-thumb-placeholder">📦</span>';
                                }
                            }
                            html += '<div class="mj-search-meta">';
                            if (taxMeta || !item.inStock || item.isOnSale) {
                                html += '<div class="mj-search-badges-line">';
                                if (taxMeta) html += '<span class="mj-search-cat-tag">' + taxMeta + '</span>';
                                if (!item.inStock) html += '<span class="mj-search-stock-tag is-oos">SIN STOCK</span>';
                                else if (item.isOnSale) html += '<span class="mj-search-stock-tag is-sale">OFERTA</span>';
                                html += '</div>';
                            }
                            html += '<span class="mj-search-title">' + item.title + '</span>';
                            if (codes.length > 0) {
                                html += '<span class="mj-search-sku">' + codes.join(' | ') + '</span>';
                            }
                            html += '</div>';
                            html += '</a>';

                            html += '<div class="mj-search-right-col">';
                            if (showPrice && item.priceClean) {
                                html += '<div class="mj-search-price">' + item.priceClean + '</div>';
                            }
                            if (showTransfer && item.transferPrice) {
                                html += '<div class="mj-search-transfer">' + item.transferPrice + ' <span>Transf.</span></div>';
                            }
                            if (showQuickAdd) {
                                if (item.canQuickAdd) {
                                    html += '<button type="button" class="mj-search-quickadd-btn js-mj-quick-add" data-product-id="' + item.id + '" data-qty="1">+ Carrito</button>';
                                } else if (!item.inStock) {
                                    html += '<span class="mj-search-oos-label">Agotado</span>';
                                }
                            }
                            html += '</div>';
                            html += '</div>';
                        });

                        html += '</div>';
                    }

                    const stockCheckedAttr = wrapper.dataset.onlyStock !== '0' ? 'checked' : '';
                    const filterBarHtml = '<div class="mj-search-top-filter">' +
                        '<span>Resultados para <strong>"' + currentTerm + '"</strong></span>' +
                        '<label class="mj-search-stock-Check"><input type="checkbox" class="js-mj-search-stock-toggle" ' + stockCheckedAttr + ' /> Solo con stock</label>' +
                    '</div>';

                    html = filterBarHtml + html + '<a class="mj-search-view-all" href="/?s=' + encodeURIComponent(currentTerm) + '&post_type=product">Ver todos los resultados para "' + currentTerm + '" →</a>';
                    resultsBox.innerHTML = html;

                    const stockToggleEl = resultsBox.querySelector('.js-mj-search-stock-toggle');
                    if (stockToggleEl) {
                        stockToggleEl.addEventListener('change', function () {
                            wrapper.dataset.onlyStock = stockToggleEl.checked ? '1' : '0';
                            runLiveSearchQuery();
                        });
                    }
                })
                .catch(function () {
                    resultsBox.hidden = true;
                });
            }

        document.addEventListener('click', function (e) {
            if (!wrapper.contains(e.target)) {
                resultsBox.hidden = true;
            }
        });
    });

    // =========================================================================
    // 5. RELOJ CONTADOR REGRESIVO Y FILTRO DE CATÁLOGO AJAX
    // =========================================================================
    const timers = document.querySelectorAll('.mj-countdown-timer');
    timers.forEach(function (timer) {
        const hoursAttr = parseInt(timer.dataset.hours || '12', 10);
        let remainingSeconds = hoursAttr * 3600 + 45 * 60;
        const elH = timer.querySelector('[data-unit="hours"]');
        const elM = timer.querySelector('[data-unit="minutes"]');
        const elS = timer.querySelector('[data-unit="seconds"]');

        setInterval(function () {
            if (remainingSeconds <= 0) remainingSeconds = hoursAttr * 3600;
            remainingSeconds--;
            const h = Math.floor(remainingSeconds / 3600);
            const m = Math.floor((remainingSeconds % 3600) / 60);
            const s = remainingSeconds % 60;
            if (elH) elH.textContent = String(h).padStart(2, '0') + 'h';
            if (elM) elM.textContent = String(m).padStart(2, '0') + 'm';
            if (elS) elS.textContent = String(s).padStart(2, '0') + 's';
        }, 1000);
    });

    const catalogWraps = document.querySelectorAll('.mj-ajax-catalog-wrap');
    catalogWraps.forEach(function (wrap) {
        const pills = wrap.querySelectorAll('.mj-cat-pill');
        const sortSelect = wrap.querySelector('.mj-ajax-sort-select');
        const saleCheckbox = wrap.querySelector('.mj-ajax-sale-checkbox');
        const stockCheckbox = wrap.querySelector('.mj-ajax-stock-checkbox');
        const resultsContainer = wrap.querySelector('.mj-ajax-catalog-results');

        let activeCat = '';

        function triggerCatalogAjax() {
            if (!resultsContainer) return;
            const formData = new FormData();
            formData.append('action', 'mj_pro_filter_catalog');
            formData.append('nonce', ajaxConfig.nonce);
            formData.append('category', activeCat);
            formData.append('sort', sortSelect ? sortSelect.value : 'date');
            formData.append('onlySale', saleCheckbox && saleCheckbox.checked ? '1' : '0');
            formData.append('onlyStock', !stockCheckbox || stockCheckbox.checked ? '1' : '0');
            formData.append('perPage', wrap.dataset.perPage || '8');
            formData.append('cardConfig', wrap.dataset.cardConfig || '{}');

            resultsContainer.style.opacity = '0.5';

            fetch(ajaxConfig.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                resultsContainer.style.opacity = '1';
                if (res.success && res.data.html) {
                    resultsContainer.innerHTML = res.data.html;
                }
            })
            .catch(function () {
                resultsContainer.style.opacity = '1';
            });
        }

        pills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                pills.forEach(function (p) { p.classList.remove('is-active'); });
                pill.classList.add('is-active');
                activeCat = pill.dataset.cat || '';
                triggerCatalogAjax();
            });
        });

        if (sortSelect) sortSelect.addEventListener('change', triggerCatalogAjax);
        if (saleCheckbox) saleCheckbox.addEventListener('change', triggerCatalogAjax);
        if (stockCheckbox) stockCheckbox.addEventListener('change', triggerCatalogAjax);
    });

    // =========================================================================
    // 6. MOTOR NATIVO: CHECKOUT EN 3 PASOS MODULAR (100% CONFIGURABLE)
    // =========================================================================
    const checkoutWrap = document.querySelector('.mj-multistep-checkout-wrap');
    if (checkoutWrap) {
        initMjMultistepCheckout(checkoutWrap);
    }

    function initMjMultistepCheckout(wrap) {
        const form = wrap.querySelector('form.checkout.woocommerce-checkout');
        if (!form) return;

        let currentStep = 1;

        const s1Title = wrap.dataset.step1Title || 'Tus Datos y Entrega';
        const s1Subtitle = wrap.dataset.step1Subtitle || 'Ingresá tu contacto y dirección para calcular el envío';
        const s1Icon = wrap.dataset.step1Icon || '👤';

        const s2Title = wrap.dataset.step2Title || 'Método de Envío';
        const s2Subtitle = wrap.dataset.step2Subtitle || 'Seleccioná la opción de entrega que prefieras';
        const s2Icon = wrap.dataset.step2Icon || '🚚';

        const s3Title = wrap.dataset.step3Title || 'Pago y Confirmación';
        const s3Subtitle = wrap.dataset.step3Subtitle || 'Elegí cómo abonar y finalizá tu pedido';
        const s3Icon = wrap.dataset.step3Icon || '💳';

        const showSidebar = wrap.dataset.showSidebar !== '0';

        const layoutGrid = document.createElement('div');
        layoutGrid.className = 'mj-checkout-layout-grid' + (showSidebar ? ' has-sidebar' : '');

        const mainCol = document.createElement('div');
        mainCol.className = 'mj-checkout-main-col';

        const sideCol = document.createElement('aside');
        sideCol.className = 'mj-checkout-side-col';

        form.parentNode.insertBefore(layoutGrid, form);
        mainCol.appendChild(form);
        layoutGrid.appendChild(mainCol);
        if (showSidebar) {
            layoutGrid.appendChild(sideCol);
        }

        const panel1 = document.createElement('div');
        panel1.className = 'mj-step-panel is-step-1 is-active';
        panel1.dataset.step = '1';
        panel1.innerHTML = `
            <div class="mj-panel-header">
                <span class="mj-panel-icon">${s1Icon}</span>
                <div>
                    <h3 class="mj-panel-title">1. ${s1Title}</h3>
                    ${s1Subtitle ? `<p class="mj-panel-subtitle">${s1Subtitle}</p>` : ''}
                </div>
            </div>
            <div class="mj-panel-body js-mj-panel-1-body"></div>
            <div class="mj-step-nav-footer">
                <button type="button" class="mj-btn mj-btn-primary js-mj-next-step" data-target="2">
                    Continuar a Métodos de Envío →
                </button>
            </div>
        `;

        const panel2 = document.createElement('div');
        panel2.className = 'mj-step-panel is-step-2';
        panel2.dataset.step = '2';
        panel2.innerHTML = `
            <div class="mj-panel-header">
                <span class="mj-panel-icon">${s2Icon}</span>
                <div>
                    <h3 class="mj-panel-title">2. ${s2Title}</h3>
                    ${s2Subtitle ? `<p class="mj-panel-subtitle">${s2Subtitle}</p>` : ''}
                </div>
            </div>
            <div class="mj-panel-body js-mj-panel-2-body">
                <div class="mj-shipping-loading-placeholder" style="text-align:center;padding:1.5rem;color:#0284c7;">
                    <span class="mj-spinner"></span> Calculando opciones de envío disponibles...
                </div>
                <div class="mj-shipping-methods-container"></div>
            </div>
            <div class="mj-step-nav-footer">
                <button type="button" class="mj-btn mj-btn-outline js-mj-prev-step" data-target="1">
                    ← Volver a Datos
                </button>
                <button type="button" class="mj-btn mj-btn-primary js-mj-next-step" data-target="3">
                    Continuar al Pago →
                </button>
            </div>
        `;

        const panel3 = document.createElement('div');
        panel3.className = 'mj-step-panel is-step-3';
        panel3.dataset.step = '3';
        panel3.innerHTML = `
            <div class="mj-panel-header">
                <span class="mj-panel-icon">${s3Icon}</span>
                <div>
                    <h3 class="mj-panel-title">3. ${s3Title}</h3>
                    ${s3Subtitle ? `<p class="mj-panel-subtitle">${s3Subtitle}</p>` : ''}
                </div>
            </div>
            <div class="mj-panel-body js-mj-panel-3-body">
                <div class="mj-payment-gateways-container"></div>
            </div>
            <div class="mj-step-nav-footer">
                <button type="button" class="mj-btn mj-btn-outline js-mj-prev-step" data-target="2">
                    ← Volver a Envíos
                </button>
            </div>
        `;

        const customerDetails = form.querySelector('#customer_details');
        const p1Body = panel1.querySelector('.js-mj-panel-1-body');
        if (customerDetails) {
            p1Body.appendChild(customerDetails);
        } else {
            const billingFields = form.querySelector('.woocommerce-billing-fields');
            if (billingFields) p1Body.appendChild(billingFields);
            const shippingFields = form.querySelector('.woocommerce-shipping-fields');
            if (shippingFields) p1Body.appendChild(shippingFields);
        }

        form.appendChild(panel1);
        form.appendChild(panel2);
        form.appendChild(panel3);

        if (showSidebar) {
            sideCol.innerHTML = `
                <div class="mj-checkout-summary-card">
                    <h4 class="mj-summary-title">🛒 Resumen de Compra</h4>
                    <div class="mj-summary-items-list js-mj-summary-items"></div>
                    <div class="mj-summary-totals-breakdown js-mj-summary-totals"></div>
                    <div class="mj-summary-secure-badge">
                        <span>🔒 Pago Seguro y Encriptado SSL</span>
                    </div>
                </div>
            `;
        }

        function goToStep(targetStep) {
            if (targetStep < 1 || targetStep > 3) return;

            if (targetStep > currentStep) {
                if (currentStep === 1 && !validateStep1()) {
                    return;
                }
                if (currentStep === 2 && !validateStep2()) {
                    return;
                }
            }

            currentStep = targetStep;

            panel1.classList.toggle('is-active', currentStep === 1);
            panel2.classList.toggle('is-active', currentStep === 2);
            panel3.classList.toggle('is-active', currentStep === 3);

            wrap.querySelectorAll('.mj-step-pill').forEach(function(pill) {
                const s = parseInt(pill.dataset.step, 10);
                pill.classList.toggle('is-active', s === currentStep);
                pill.classList.toggle('is-completed', s < currentStep);
            });

            if (currentStep === 2) {
                syncShippingMethods();
                if (window.jQuery) {
                    window.jQuery(document.body).trigger('update_checkout');
                }
            }
            if (currentStep === 3) {
                syncPaymentMethods();
                if (window.jQuery) {
                    window.jQuery(document.body).trigger('update_checkout');
                }
            }

            wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function validateStep1() {
            let isValid = true;
            let firstInvalidEl = null;

            const requiredFields = panel1.querySelectorAll('.validate-required input, .validate-required select, .validate-required textarea, input[required], select[required]');

            requiredFields.forEach(function(input) {
                if (input.offsetParent === null) return;

                const val = input.value.trim();
                const parentGroup = input.closest('.form-row') || input.parentElement;

                if (!val) {
                    isValid = false;
                    parentGroup.classList.add('woocommerce-invalid', 'mj-field-error');
                    if (!firstInvalidEl) firstInvalidEl = input;
                } else {
                    parentGroup.classList.remove('woocommerce-invalid', 'mj-field-error');
                }
            });

            const emailInput = panel1.querySelector('input[type="email"], #billing_email');
            if (emailInput && emailInput.value.trim()) {
                const emailVal = emailInput.value.trim();
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(emailVal)) {
                    isValid = false;
                    const p = emailInput.closest('.form-row') || emailInput.parentElement;
                    p.classList.add('woocommerce-invalid', 'mj-field-error');
                    if (!firstInvalidEl) firstInvalidEl = emailInput;
                }
            }

            if (!isValid && firstInvalidEl) {
                firstInvalidEl.focus();
                firstInvalidEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            return isValid;
        }

        function validateStep2() {
            const shippingRadio = form.querySelector('input[name^="shipping_method"]:checked, input.shipping_method:checked');
            if (!shippingRadio && form.querySelectorAll('input[name^="shipping_method"]').length > 0) {
                alert('Por favor seleccioná un método de envío para continuar.');
                return false;
            }
            return true;
        }

        function syncShippingMethods() {
            const orderReview = form.querySelector('#order_review');
            const targetContainer = panel2.querySelector('.mj-shipping-methods-container');
            const placeholder = panel2.querySelector('.mj-shipping-loading-placeholder');

            if (!orderReview || !targetContainer) return;

            const shippingRows = orderReview.querySelectorAll('.woocommerce-shipping-totals, .shipping, #shipping_method');
            if (shippingRows.length > 0) {
                if (placeholder) placeholder.style.display = 'none';

                let html = '<div class="mj-shipping-card-grid">';
                const radios = orderReview.querySelectorAll('input[name^="shipping_method"], input.shipping_method');

                if (radios.length > 0) {
                    radios.forEach(function(radio) {
                        const labelEl = orderReview.querySelector(`label[for="${radio.id}"]`) || radio.closest('label') || radio.parentElement;
                        const labelText = labelEl ? labelEl.textContent.trim() : radio.value;
                        const isChecked = radio.checked;

                        html += `
                            <label class="mj-shipping-option-card ${isChecked ? 'is-selected' : ''}" for="${radio.id}">
                                <div class="mj-shipping-radio-wrap">
                                    <input type="radio"
                                           name="mj_virtual_shipping"
                                           data-real-id="${radio.id}"
                                           ${isChecked ? 'checked' : ''} />
                                </div>
                                <div class="mj-shipping-info">
                                    <span class="mj-shipping-title">${labelText}</span>
                                </div>
                            </label>
                        `;
                    });
                    html += '</div>';
                    targetContainer.innerHTML = html;

                    targetContainer.querySelectorAll('input[name="mj_virtual_shipping"]').forEach(function(vRadio) {
                        vRadio.addEventListener('change', function() {
                            const realId = this.dataset.realId;
                            const realInput = orderReview.querySelector(`#${realId}`);
                            if (realInput) {
                                realInput.checked = true;
                                if (window.jQuery) {
                                    window.jQuery(realInput).trigger('change');
                                    window.jQuery(document.body).trigger('update_checkout');
                                }
                            }
                            targetContainer.querySelectorAll('.mj-shipping-option-card').forEach(function(c) {
                                c.classList.remove('is-selected');
                            });
                            this.closest('.mj-shipping-option-card').classList.add('is-selected');
                        });
                    });
                } else {
                    targetContainer.innerHTML = '<p class="mj-empty-rates">No hay métodos de envío disponibles para el destino ingresado. Por favor revisá tu código postal en el Paso 1.</p>';
                }
            }
        }

        function syncPaymentMethods() {
            const paymentBox = form.querySelector('#payment');
            const target = panel3.querySelector('.mj-payment-gateways-container');
            if (paymentBox && target && paymentBox.parentElement !== target) {
                target.appendChild(paymentBox);
            }
        }

        function syncSidebarSummary() {
            if (!showSidebar) return;
            const totalsTarget = sideCol.querySelector('.js-mj-summary-totals');
            const orderReview = form.querySelector('#order_review');
            if (!orderReview) return;

            const totalRows = orderReview.querySelectorAll('.cart-subtotal, .shipping, .fee, .order-total');
            if (totalsTarget && totalRows.length > 0) {
                let totalsHtml = '<ul class="mj-summary-lines">';
                totalRows.forEach(function(row) {
                    const th = row.querySelector('th') ? row.querySelector('th').textContent.trim() : '';
                    const td = row.querySelector('td') ? row.querySelector('td').innerHTML.trim() : '';
                    const isTotal = row.classList.contains('order-total');
                    totalsHtml += `<li class="mj-summary-line ${isTotal ? 'is-total' : ''}">
                        <span class="mj-line-label">${th}</span>
                        <span class="mj-line-val">${td}</span>
                    </li>`;
                });
                totalsHtml += '</ul>';
                totalsTarget.innerHTML = totalsHtml;
            }
        }

        wrap.addEventListener('click', function(e) {
            const nextBtn = e.target.closest('.js-mj-next-step');
            if (nextBtn) {
                e.preventDefault();
                goToStep(parseInt(nextBtn.dataset.target, 10));
                return;
            }
            const prevBtn = e.target.closest('.js-mj-prev-step');
            if (prevBtn) {
                e.preventDefault();
                goToStep(parseInt(prevBtn.dataset.target, 10));
                return;
            }
            const pillBtn = e.target.closest('.mj-step-pill');
            if (pillBtn) {
                e.preventDefault();
                const target = parseInt(pillBtn.dataset.step, 10);
                if (target <= currentStep || (target === 2 && validateStep1()) || (target === 3 && validateStep1() && validateStep2())) {
                    goToStep(target);
                }
            }
        });

        if (window.jQuery) {
            window.jQuery(document.body).on('updated_checkout', function() {
                syncShippingMethods();
                syncPaymentMethods();
                syncSidebarSummary();
            });
        }

        panel1.querySelectorAll('#billing_postcode, #shipping_postcode, #billing_state, #shipping_state, #billing_city').forEach(function(input) {
            input.addEventListener('change', function() {
                if (window.jQuery) {
                    window.jQuery(document.body).trigger('update_checkout');
                }
            });
        });

        syncPaymentMethods();
        syncSidebarSummary();
    }
});
