/**
 * Panel de MJ Envíos.
 *
 * Tres comodidades, nada más: mostrar de la ficha del método solo lo que
 * corresponde a su tipo, agregar y quitar filas de la tabla de precios y de los
 * campos propios, y confirmar lo que no se puede deshacer.
 *
 * Sin JavaScript la pantalla sigue funcionando: todo se ve, y guardar guarda.
 */
(function () {
	'use strict';

	var config = window.mjeAdmin || {};

	/* --------------------- Mostrar lo que corresponde -------------------- */

	/**
	 * Cada bloque o fila con data-when="tabla fijo" se muestra solo cuando el
	 * tipo elegido está en esa lista.
	 */
	function applyType() {
		var select = document.querySelector('[data-mje-type]');
		if (!select) {
			return;
		}
		var type = select.value;
		document.querySelectorAll('[data-when]').forEach(function (el) {
			var types = (el.getAttribute('data-when') || '').split(/\s+/).filter(Boolean);
			el.hidden = types.length > 0 && types.indexOf(type) === -1;
		});
	}

	/* ----------------------------- Repetidores --------------------------- */

	/**
	 * Clona la última fila de una tabla, vacía sus valores y renumera los
	 * nombres para que no se pisen al guardar.
	 */
	function addRow(tableId) {
		var table = document.getElementById(tableId);
		if (!table) {
			return;
		}
		var body = table.querySelector('tbody');
		var rows = body ? body.querySelectorAll('tr') : [];
		if (!rows.length) {
			return;
		}
		var last = rows[rows.length - 1];
		var copy = last.cloneNode(true);
		var next = rows.length;

		copy.querySelectorAll('input, select, textarea').forEach(function (field) {
			var name = field.getAttribute('name') || '';
			field.setAttribute('name', name.replace(/\[(\d+)\]/, '[' + next + ']'));
			if (field.tagName === 'SELECT') {
				field.selectedIndex = 0;
			} else {
				field.value = '';
			}
		});
		body.appendChild(copy);
	}

	function bindRemove() {
		document.addEventListener('click', function (event) {
			var button = event.target.closest('.mje-row-remove');
			if (!button) {
				return;
			}
			event.preventDefault();
			var row = button.closest('tr');
			var body = row ? row.parentNode : null;
			if (!row || !body) {
				return;
			}
			/* La última fila no se borra: se vacía, así siempre queda una libre para cargar. */
			if (body.querySelectorAll('tr').length <= 1) {
				row.querySelectorAll('input, textarea').forEach(function (field) {
					field.value = '';
				});
				return;
			}
			row.remove();
		});
	}

	/* ---------------------------- Confirmaciones ------------------------- */

	function bindConfirm() {
		document.addEventListener('click', function (event) {
			var remove = event.target.closest('.mje-delete');
			if (remove && !window.confirm(config.confirmDelete || '¿Borrar?')) {
				event.preventDefault();
				return;
			}
			var clear = event.target.closest('.mje-clear');
			if (clear && !window.confirm(config.confirmClear || '¿Borrar todo?')) {
				event.preventDefault();
			}
		});
	}

	/* -------------------------------- Arranque --------------------------- */

	document.addEventListener('DOMContentLoaded', function () {
		var type = document.querySelector('[data-mje-type]');
		if (type) {
			type.addEventListener('change', applyType);
			applyType();
		}

		var addPrice = document.getElementById('mje-add-row');
		if (addPrice) {
			addPrice.addEventListener('click', function () {
				addRow('mje-price-table');
			});
		}
		var addCustom = document.getElementById('mje-add-custom');
		if (addCustom) {
			addCustom.addEventListener('click', function () {
				addRow('mje-custom-fields');
			});
		}

		bindRemove();
		bindConfirm();
	});
})();
