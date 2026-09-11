/**
 * MJ ERP — app.js
 * Boot sequence, layout (sidebar, topbar, content), navigation, route table, global error handling.
 * Must be loaded last.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	const h = function (v) { return MJ.ui.h(v); };
	const qs = function (s, r) { return MJ.ui.qs(s, r); };
	const qsa = function (s, r) { return MJ.ui.qsa(s, r); };

	const ROOT_ID = 'mjerp-app';

	/* Route table: name → { pattern, page (MJ.pages key), nav (sidebar key) } */
	const ROUTES = [
		{ name: 'dashboard', pattern: '/', page: 'dashboard', nav: 'dashboard' },
		{ name: 'purchases', pattern: '/compras', page: 'purchases', nav: 'purchases' },
		{ name: 'purchase', pattern: '/compras/:id', page: 'purchase', nav: 'purchases' },
		{ name: 'customers', pattern: '/clientes', page: 'customers', nav: 'customers' },
		{ name: 'suppliers', pattern: '/proveedores', page: 'suppliers', nav: 'suppliers' },
		{ name: 'products', pattern: '/productos', page: 'products', nav: 'products' },
		{ name: 'settings', pattern: '/configuracion', page: 'settings', nav: 'settings' },
		// `/ventas/nueva` va antes que `/ventas/:id` porque el router se queda con el primer match.
		{ name: 'sales', pattern: '/ventas', page: 'sales', nav: 'sales' },
		{ name: 'saleNew', pattern: '/ventas/nueva', page: 'saleNew', nav: 'sales' },
		{ name: 'sale', pattern: '/ventas/:id', page: 'sale', nav: 'sales' },
		{ name: 'consignaciones', pattern: '/consignaciones', page: 'consignees', nav: 'consignaciones' },
		{ name: 'consignee', pattern: '/consignaciones/revendedor/:id', page: 'consignee', nav: 'consignaciones' },
		{ name: 'consignment', pattern: '/consignaciones/entrega/:id', page: 'consignment', nav: 'consignaciones' },
		{ name: 'caja', pattern: '/caja', page: 'soon', nav: 'caja' },
		{ name: 'reportes', pattern: '/reportes', page: 'soon', nav: 'reportes' },
	];

	const NAV = [
		{ key: 'dashboard', path: '/', label: 'Inicio', icon: 'home' },
		{ key: 'sales', path: '/ventas', label: 'Ventas', icon: 'cash', badge: 'salesUnpaid' },
		{ key: 'purchases', path: '/compras', label: 'Compras', icon: 'cart' },
		{ key: 'consignaciones', path: '/consignaciones', label: 'Consignaciones', icon: 'layers', badge: 'consignmentPending' },
		{ key: 'customers', path: '/clientes', label: 'Clientes', icon: 'user' },
		{ key: 'suppliers', path: '/proveedores', label: 'Proveedores', icon: 'users' },
		{ key: 'products', path: '/productos', label: 'Productos', icon: 'box' },
		{ group: 'Próximamente' },
		{ key: 'caja', path: '/caja', label: 'Caja', icon: 'dollar', soon: true },
		{ key: 'reportes', path: '/reportes', label: 'Reportes', icon: 'chart', soon: true },
		{ divider: true },
		{ key: 'settings', path: '/configuracion', label: 'Configuración', icon: 'settings' },
	];

	const PAGE_LABELS = {
		dashboard: 'Inicio', purchases: 'Compras', purchase: 'Compra', suppliers: 'Proveedores', products: 'Productos',
		customers: 'Clientes',
		settings: 'Configuración', consignees: 'Consignaciones', consignee: 'Revendedor', consignment: 'Entrega',
		sales: 'Ventas', sale: 'Venta', saleNew: 'Nueva venta',
		caja: 'Caja', reportes: 'Reportes',
	};

	/**
	 * Contadores que el menú muestra como globo: lo que espera una acción del dueño de la tienda —
	 * ventas de revendedores a confirmar, y ventas con saldo sin cobrar.
	 */
	function navBadge(key) {
		if (key === 'consignmentPending') {
			const info = MJ.state.bootstrap && MJ.state.bootstrap.consignment;
			if (!info) return 0;
			return (parseInt(info.pending_sales, 10) || 0) + (parseInt(info.declared_payments, 10) || 0);
		}
		if (key === 'salesUnpaid') {
			const info = MJ.state.bootstrap && MJ.state.bootstrap.sales;
			if (!info) return 0;
			return (parseInt(info.unpaid_count, 10) || 0) + (parseInt(info.declared_count, 10) || 0);
		}
		return 0;
	}

	let root = null;
	let refs = {};
	let currentCleanup = null;
	let renderSeq = 0;
	let current = { name: null, page: null };

	function appTitle() { return MJ.setting('app_title', 'MJ ERP') || 'MJ ERP'; }

	function initials(name) {
		const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
		if (!parts.length) return '?';
		return (parts[0].charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase();
	}

	function navHtml() {
		return NAV.map(function (item) {
			if (item.group) return '<div class="mj-nav__group-label">' + h(item.group) + '</div>';
			if (item.divider) return '<div class="mj-divider"></div>';
			const count = item.badge ? navBadge(item.badge) : 0;
			return '<a class="mj-nav__item' + (item.soon ? ' is-soon' : '') + '" href="' + h(MJ.router.link(item.path)) + '" data-nav="' + h(item.key) + '">' +
				MJ.icon(item.icon) + '<span class="mj-nav__label">' + h(item.label) + '</span>' +
				(item.soon ? '<span class="mj-nav__soon">Pronto</span>' : '') +
				(count > 0 ? '<span class="mj-nav__badge" title="Ventas esperando confirmación">' + h(count > 99 ? '99+' : count) + '</span>' : '') +
			'</a>';
		}).join('');
	}

	function footerHtml() {
		const user = MJ.state.user || {};
		const cfg = MJ.config || {};
		const avatar = user.avatar
			? '<img class="mj-avatar" src="' + h(user.avatar) + '" alt="" width="32" height="32" loading="lazy">'
			: '<span class="mj-avatar mj-avatar--initials" aria-hidden="true">' + h(initials(user.name)) + '</span>';
		return '<div class="mj-row mj-row--center mj-sidebar__user">' + avatar +
			'<div class="mj-sidebar__user-info"><div class="mj-strong mj-truncate" title="' + h(user.name || '') + '">' + h(user.name || 'Usuario') + '</div>' +
			(user.email ? '<div class="mj-small mj-muted mj-truncate" title="' + h(user.email) + '">' + h(user.email) + '</div>' : '') + '</div></div>' +
			'<div class="mj-sidebar__links mj-small">' +
				(cfg.homeUrl ? '<a class="mj-link" href="' + h(cfg.homeUrl) + '" target="_blank" rel="noopener">' + MJ.icon('store') + '<span>Ir a la tienda</span></a>' : '') +
				(cfg.adminUrl ? '<a class="mj-link" href="' + h(cfg.adminUrl) + '" target="_blank" rel="noopener">' + MJ.icon('external') + '<span>WP Admin</span></a>' : '') +
				(cfg.logoutUrl ? '<a class="mj-link mj-danger-text" href="' + h(cfg.logoutUrl) + '">' + MJ.icon('logout') + '<span>Salir</span></a>' : '') +
			'</div>';
	}

	function renderLayout() {
		root.innerHTML =
			'<div class="mj-app" id="mj-app">' +
				'<aside class="mj-sidebar" id="mj-sidebar" aria-label="Navegación principal">' +
					'<div class="mj-sidebar__brand"><a href="' + h(MJ.router.link('/')) + '" class="mj-sidebar__brand-link">' + MJ.icon('store') + '<span class="mj-sidebar__brand-text" data-brand>' + h(appTitle()) + '</span></a>' +
						'<button type="button" class="mj-btn mj-btn--ghost mj-btn--icon mj-sidebar__close" data-nav-close aria-label="Cerrar menú">' + MJ.icon('x') + '</button></div>' +
					'<nav class="mj-nav" id="mj-nav">' + navHtml() + '</nav>' +
					'<div class="mj-sidebar__footer" id="mj-sidebar-footer">' + footerHtml() + '</div>' +
				'</aside>' +
				'<div class="mj-sidebar-backdrop" data-nav-close hidden></div>' +
				'<div class="mj-main">' +
					'<header class="mj-topbar">' +
						'<button type="button" class="mj-menu-toggle mj-btn mj-btn--ghost mj-btn--icon" data-nav-toggle aria-label="Abrir menú" aria-expanded="false" aria-controls="mj-sidebar">' + MJ.icon('menu') + '</button>' +
						'<h1 class="mj-topbar__title" id="mj-topbar-title"></h1>' +
						'<div class="mj-topbar__actions" id="mj-topbar-actions"></div>' +
					'</header>' +
					'<main class="mj-content" id="mj-content" tabindex="-1"></main>' +
				'</div>' +
			'</div>';
		refs = {
			app: qs('#mj-app', root),
			sidebar: qs('#mj-sidebar', root),
			nav: qs('#mj-nav', root),
			footer: qs('#mj-sidebar-footer', root),
			title: qs('#mj-topbar-title', root),
			actions: qs('#mj-topbar-actions', root),
			content: qs('#mj-content', root),
			toggle: qs('[data-nav-toggle]', root),
			backdrop: qs('.mj-sidebar-backdrop', root),
		};
		bindLayout();
	}

	function setNavOpen(open) {
		if (!refs.app) return;
		refs.app.classList.toggle('is-nav-open', !!open);
		if (refs.toggle) refs.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (refs.backdrop) refs.backdrop.hidden = !open;
	}

	let layoutBound = false;

	function bindLayout() {
		if (layoutBound) return;
		layoutBound = true;
		MJ.ui.on(root, 'click', '[data-nav-toggle]', function (e) {
			e.preventDefault();
			setNavOpen(!refs.app.classList.contains('is-nav-open'));
		});
		MJ.ui.on(root, 'click', '[data-nav-close]', function (e) { e.preventDefault(); setNavOpen(false); });
		MJ.ui.on(root, 'click', '.mj-nav .mj-nav__item', function () { setNavOpen(false); });
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && refs.app && refs.app.classList.contains('is-nav-open') && !MJ.ui.modalCount()) setNavOpen(false);
		});
		window.addEventListener('resize', MJ.ui.debounce(function () {
			if (window.innerWidth > 900) setNavOpen(false);
		}, 150));
	}

	function setActiveNav(navKey) {
		qsa('.mj-nav__item', refs.nav).forEach(function (a) {
			const active = a.getAttribute('data-nav') === navKey;
			a.classList.toggle('is-active', active);
			if (active) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
		});
	}

	/**
	 * Repinta los globos del menú. Se llama cuando cambia algo que los mueve (confirmar una venta
	 * en consignación baja el contador) para que el número del menú no quede viejo.
	 */
	function refreshNavBadges() {
		NAV.forEach(function (item) {
			if (!item.badge || !item.key) return;
			const link = qs('[data-nav="' + MJ.ui.cssEscape(item.key) + '"]', refs.nav);
			if (!link) return;
			const count = navBadge(item.badge);
			let el = qs('.mj-nav__badge', link);
			if (count > 0) {
				if (!el) {
					el = document.createElement('span');
					el.className = 'mj-nav__badge';
					link.appendChild(el);
				}
				el.textContent = count > 99 ? '99+' : String(count);
			} else if (el && el.parentNode) {
				el.parentNode.removeChild(el);
			}
		});
	}

	function resolveTitle(page, fallback, params, query, route) {
		if (page && typeof page.title === 'function') {
			try { return page.title(params, query, route) || fallback; } catch (e) { return fallback; }
		}
		if (page && page.title) return String(page.title);
		return fallback;
	}

	function setTitle(title) {
		if (refs.title) refs.title.textContent = title || '';
		document.title = (title ? title + ' · ' : '') + appTitle();
	}

	function setActions(html) {
		if (refs.actions) refs.actions.innerHTML = html || '';
	}

	function runCleanup() {
		if (typeof currentCleanup === 'function') {
			try { currentCleanup(); } catch (e) { console.error('[MJ] page cleanup', e); }
		}
		currentCleanup = null;
	}

	function renderError(container, err, retry) {
		container.innerHTML =
			'<div class="mj-section">' +
				MJ.ui.alert(MJ.api.message(err, 'No se pudo cargar esta pantalla.'), 'danger') +
				'<div class="mj-row mj-mt">' +
					'<button type="button" class="mj-btn" data-retry>' + MJ.icon('refresh') + '<span>Reintentar</span></button>' +
					'<a class="mj-btn mj-btn--ghost" href="' + h(MJ.router.link('/')) + '">' + MJ.icon('home') + '<span>Ir al inicio</span></a>' +
				'</div>' +
			'</div>';
		const btn = qs('[data-retry]', container);
		if (btn) btn.addEventListener('click', function () { retry(); });
	}

	function showPage(def, params, query, route) {
		const seq = ++renderSeq;
		runCleanup();
		MJ.ui.closeAllModals();
		setNavOpen(false);
		setActiveNav(def.nav);
		setActions('');

		const page = MJ.pages[def.page];
		const fallbackTitle = PAGE_LABELS[def.name] || PAGE_LABELS[def.page] || appTitle();
		const title = resolveTitle(page, fallbackTitle, params, query, route);
		setTitle(title);
		current = { name: def.name, page: def.page, params: params, query: query, route: route };
		MJ.ui.scrollTop();

		const container = document.createElement('div');
		container.className = 'mj-page mj-page--' + def.page + (def.page !== def.name ? ' mj-page--' + def.name : '');
		container.setAttribute('data-page', def.page);
		refs.content.innerHTML = '';
		refs.content.appendChild(container);

		if (!page || typeof page.render !== 'function') {
			container.innerHTML =
				'<div class="mj-section">' +
					MJ.ui.alert('Módulo no cargado: la pantalla «' + title + '» (MJ.pages.' + def.page + ') no está disponible. Verificá que el archivo page-' + def.page + '.js se haya cargado correctamente.', 'warning') +
					'<div class="mj-row mj-mt"><a class="mj-btn" href="' + h(MJ.router.link('/')) + '">' + MJ.icon('home') + '<span>Ir al inicio</span></a>' +
					'<button type="button" class="mj-btn mj-btn--ghost" data-reload>' + MJ.icon('refresh') + '<span>Recargar la aplicación</span></button></div>' +
				'</div>';
			const reload = qs('[data-reload]', container);
			if (reload) reload.addEventListener('click', function () { window.location.reload(); });
			MJ.events.emit('page:rendered', current);
			return;
		}

		const retry = function () { if (seq === renderSeq) showPage(def, params, query, route); };
		let result;
		try {
			result = page.render(container, params, query, route);
		} catch (err) {
			console.error('[MJ] render error (' + def.page + ')', err);
			renderError(container, err, retry);
			return;
		}
		if (result && typeof result.then === 'function') {
			result.then(function (cleanup) {
				if (seq !== renderSeq) { if (typeof cleanup === 'function') { try { cleanup(); } catch (e) { /* noop */ } } return; }
				if (typeof cleanup === 'function') currentCleanup = cleanup;
				MJ.events.emit('page:rendered', current);
			}).catch(function (err) {
				if (seq !== renderSeq) return;
				if (err && err.handled) return;
				console.error('[MJ] render error (' + def.page + ')', err);
				renderError(container, err, retry);
			});
		} else {
			if (typeof result === 'function') currentCleanup = result;
			MJ.events.emit('page:rendered', current);
		}
	}

	function registerRoutes() {
		ROUTES.forEach(function (def) {
			MJ.router.register(def.name, def.pattern, function (params, query, route) {
				showPage(def, params, query, route);
			});
		});
	}

	function bootError(err) {
		const isAuth = err && (err.status === 401 || err.status === 403);
		const msg = MJ.api.message(err, 'No se pudo iniciar la aplicación.');
		root.innerHTML =
			'<div class="mj-boot mj-boot--error">' +
				'<div class="mj-card mj-boot__card">' +
					'<div class="mj-card__body">' +
						'<div class="mj-empty">' +
							'<div class="mj-empty__icon">' + MJ.icon(isAuth ? 'lock' : 'warning') + '</div>' +
							'<div class="mj-empty__title">' + (isAuth ? 'Sin acceso' : 'No se pudo cargar ' + h(appTitle())) + '</div>' +
							'<div class="mj-empty__text">' + h(msg) + (err && err.status ? ' <span class="mj-muted mj-small">(HTTP ' + h(err.status) + ')</span>' : '') + '</div>' +
							'<div class="mj-empty__action mj-row mj-row--center mj-row--wrap">' +
								'<button type="button" class="mj-btn mj-btn--primary" data-retry>' + MJ.icon('refresh') + '<span>Reintentar</span></button>' +
								(isAuth ? '<a class="mj-btn" href="' + h(MJ.api.loginUrl()) + '">' + MJ.icon('user') + '<span>Iniciar sesión</span></a>' : '') +
								(MJ.config.adminUrl ? '<a class="mj-btn mj-btn--ghost" href="' + h(MJ.config.adminUrl) + '">' + MJ.icon('external') + '<span>WP Admin</span></a>' : '') +
							'</div>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>';
		const btn = qs('[data-retry]', root);
		if (btn) btn.addEventListener('click', function () { MJ.ui.setLoading(btn, true); boot(); });
	}

	function boot() {
		root = document.getElementById(ROOT_ID);
		if (!root) {
			root = document.createElement('div');
			root.id = ROOT_ID;
			document.body.appendChild(root);
		}
		if (!window.MJERP || !MJ.config.restUrl) {
			bootError(new Error('Falta la configuración de la aplicación (window.MJERP). Recargá la página; si persiste, reactivá el plugin.'));
			return;
		}
		if (!qs('.mj-boot', root)) root.innerHTML = '<div class="mj-boot">' + MJ.ui.spinner() + '<span>Cargando ' + h(appTitle()) + '…</span></div>';

		MJ.bootstrap(true).then(function () {
			renderLayout();
			registerRoutes();
			MJ.router.start();
			MJ.events.emit('app:ready');
		}).catch(function (err) {
			console.error('[MJ] boot error', err);
			if (err && err.handled) return; // 401: redirect in progress
			bootError(err);
		});
	}

	/* React to settings changes (title, theme) */
	MJ.events.on('settings:changed', function () {
		const brand = qs('[data-brand]', root || document);
		if (brand) brand.textContent = appTitle();
		setTitle(refs.title ? refs.title.textContent : '');
	});
	MJ.events.on('bootstrap:loaded', function () {
		if (refs.footer) refs.footer.innerHTML = footerHtml();
		const brand = qs('[data-brand]', root || document);
		if (brand) brand.textContent = appTitle();
	});

	/* Global error handling */
	window.addEventListener('unhandledrejection', function (e) {
		const reason = e.reason;
		if (reason instanceof MJ.ApiError) {
			if (!reason.handled) { MJ.ui.toast(reason.message, 'error'); reason.handled = true; }
			e.preventDefault();
		} else if (reason && reason.name === 'AbortError') {
			e.preventDefault();
		}
	});
	window.addEventListener('error', function (e) {
		if (e && e.error) console.error('[MJ] uncaught', e.error);
	});

	/* Las pantallas de consignaciones avisan cuando cambió el pendiente y el menú se pone al día. */
	MJ.events.on('consignment:changed', function (info) {
		if (info && typeof info === 'object' && MJ.state.bootstrap && MJ.state.bootstrap.consignment) {
			MJ.state.bootstrap.consignment = Object.assign({}, MJ.state.bootstrap.consignment, info);
		}
		refreshNavBadges();
	});

	/* Lo mismo para las ventas: cobrar una baja el contador de «con saldo» sin recargar. */
	MJ.events.on('sales:changed', function (info) {
		if (info && typeof info === 'object' && MJ.state.bootstrap && MJ.state.bootstrap.sales) {
			MJ.state.bootstrap.sales = Object.assign({}, MJ.state.bootstrap.sales, info);
		}
		// El contador vive en el servidor: se vuelve a pedir sin bloquear la pantalla.
		MJ.api.get('sales/stats').then(function (stats) {
			if (MJ.state.bootstrap && MJ.state.bootstrap.sales) {
				MJ.state.bootstrap.sales.stats = stats;
				MJ.state.bootstrap.sales.unpaid_count = stats && stats.unpaid_count ? stats.unpaid_count : 0;
			}
			refreshNavBadges();
		}).catch(function () { refreshNavBadges(); });
	});

	MJ.app = {
		boot: boot,
		setTitle: setTitle,
		setActions: setActions,
		setNavOpen: setNavOpen,
		refreshNavBadges: refreshNavBadges,
		reload() { MJ.router.reload(); },
		current() { return Object.assign({}, current); },
		routes: ROUTES.slice(),
		nav: NAV.slice(),
		refs() { return refs; },
	};

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
