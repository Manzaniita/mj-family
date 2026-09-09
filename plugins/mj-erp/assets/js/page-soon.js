/**
 * MJ ERP — page-soon.js
 * MJ.pages.soon: "Próximamente" placeholder for Consignaciones / Caja / Reportes.
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;
	MJ.pages = MJ.pages || {};

	const h = function (v) { return MJ.ui.h(v); };

	const SECTIONS = {
		'/caja': {
			title: 'Caja',
			icon: 'cash',
			text: 'Un registro de movimientos de dinero por caja (pesos, dólares, cuentas): ingresos, egresos, transferencias entre cajas y el saldo actual de cada una. Los gastos de importación marcados como «Sale de» ya quedan listos para vincularse.',
			bullets: ['Saldo por caja y moneda', 'Movimientos vinculados a compras', 'Cierres y arqueos'],
		},
		'/reportes': {
			title: 'Reportes',
			icon: 'chart',
			text: 'Informes sobre lo que importaste: costos landed por producto, evolución del tipo de cambio de tus compras, margen real contra el precio de WooCommerce y ranking de proveedores.',
			bullets: ['Costo y margen por producto', 'Compras por período y proveedor', 'Exportación a planilla'],
		},
	};

	function sectionFor(route) {
		const path = route && route.path ? String(route.path).toLowerCase() : (MJ.router.current().path || '');
		const key = Object.keys(SECTIONS).find(function (k) { return path === k || path.indexOf(k + '/') === 0; });
		return key ? SECTIONS[key] : { title: 'Próximamente', icon: 'clock', text: 'Este módulo todavía no está disponible.', bullets: [] };
	}

	MJ.pages.soon = {
		title(params, query, route) { return sectionFor(route).title; },
		render(container, params, query, route) {
			const s = sectionFor(route);
			container.innerHTML =
				'<div class="mj-soon">' +
					'<div class="mj-empty">' +
						'<div class="mj-empty__icon">' + MJ.icon(s.icon) + '</div>' +
						'<div class="mj-badge mj-badge--info mj-mb">Próximamente</div>' +
						'<div class="mj-empty__title">' + h(s.title) + '</div>' +
						'<div class="mj-empty__text">' + h(s.text) + '</div>' +
						(s.bullets.length ? '<ul class="mj-soon__list mj-muted mj-small">' + s.bullets.map(function (b) { return '<li>' + MJ.icon('check') + '<span>' + h(b) + '</span></li>'; }).join('') + '</ul>' : '') +
						'<div class="mj-empty__action mj-row mj-row--center mj-row--wrap">' +
							'<a class="mj-btn mj-btn--primary" href="' + h(MJ.router.link('/compras')) + '">' + MJ.icon('cart') + '<span>Ir a Compras</span></a>' +
							'<a class="mj-btn mj-btn--ghost" href="' + h(MJ.router.link('/')) + '">' + MJ.icon('home') + '<span>Volver al inicio</span></a>' +
						'</div>' +
					'</div>' +
				'</div>';
		},
	};
})();
