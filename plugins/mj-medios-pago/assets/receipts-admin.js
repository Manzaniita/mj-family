/**
 * Bandeja de comprobantes (wp-admin).
 *
 * Lo mínimo para que no haya accidentes: confirmar antes de borrar y no dejar
 * rechazar sin motivo, porque el motivo es justo lo que el cliente va a leer.
 * Todo lo demás son formularios normales de WordPress.
 */
(function () {
	'use strict';

	var CFG = window.mjpmReceiptsAdmin || {};

	document.addEventListener('click', function (event) {
		/* Confirmación antes de borrar. */
		var danger = event.target.closest('[data-mjpm-confirm]');
		if (danger) {
			if (!window.confirm(CFG.confirmDelete || '¿Seguro?')) {
				event.preventDefault();
				event.stopPropagation();
			}
			return;
		}

		/* Abrir la caja del motivo del rechazo. */
		var open = event.target.closest('.mjpm-rc-reject__open');
		if (open) {
			var form = open.closest('[data-mjpm-reject]');
			var box = form && form.querySelector('.mjpm-rc-reject__box');
			if (box) {
				box.hidden = false;
				open.hidden = true;
				var input = box.querySelector('input[name="motivo"]');
				if (input) input.focus();
			}
		}
	});

	/* Sin motivo no se rechaza. */
	document.addEventListener('submit', function (event) {
		var form = event.target.closest('[data-mjpm-reject]');
		if (!form) return;
		var input = form.querySelector('input[name="motivo"]');
		if (input && '' === input.value.trim()) {
			event.preventDefault();
			window.alert(CFG.needReason || 'Escribí el motivo.');
			input.focus();
		}
	});
})();
