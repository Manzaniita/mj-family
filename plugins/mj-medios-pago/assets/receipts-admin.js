/**
 * Bandeja de comprobantes (wp-admin).
 *
 * Lo mínimo para que no haya accidentes: confirmar antes de borrar, no dejar
 * rechazar sin motivo (es justo lo que el cliente va a leer) y no aprobar una
 * parte del pago sin decir cuánto entró.
 *
 * En la bandeja son formularios normales. Dentro de la pantalla del pedido no
 * pueden serlo: esa pantalla ya es un <form> y el navegador ignora uno adentro
 * de otro. Ahí las cajas son <div data-mjpm-post> con campos data-name, y este
 * archivo arma el formulario de verdad al apretar.
 */
(function () {
	'use strict';

	var CFG = window.mjpmReceiptsAdmin || {};

	function field(box, name) {
		return box.querySelector('[name="' + name + '"]:not([type="radio"]), [data-name="' + name + '"]:not([type="radio"])');
	}

	/** ¿Se puede mandar? Si falta algo, avisa y deja el foco donde hay que escribir. */
	function valid(box) {
		if (box.matches('[data-mjpm-reject]')) {
			var reason = field(box, 'motivo');
			if (reason && '' === reason.value.trim()) {
				window.alert(CFG.needReason || 'Escribí el motivo.');
				reason.focus();
				return false;
			}
		}
		if (box.matches('[data-mjpm-approve]')) {
			var partial = box.querySelector('input[type="radio"][value="parcial"]');
			var amount = field(box, 'importe');
			if (partial && partial.checked && amount && '' === amount.value.trim()) {
				window.alert(CFG.needAmount || 'Poné el importe.');
				amount.focus();
				return false;
			}
		}
		return true;
	}

	/** Arma y manda el formulario de una caja <div data-mjpm-post>. */
	function post(box) {
		var form = document.createElement('form');
		form.method = 'post';
		form.action = box.getAttribute('data-mjpm-post');
		form.style.display = 'none';
		Array.prototype.forEach.call(box.querySelectorAll('input, select, textarea'), function (input) {
			var name = input.getAttribute('data-name') || input.name;
			if (!name || input.disabled) return;
			if (('radio' === input.type || 'checkbox' === input.type) && !input.checked) return;
			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = name;
			hidden.value = input.value;
			form.appendChild(hidden);
		});
		document.body.appendChild(form);
		form.submit();
	}

	document.addEventListener('click', function (event) {
		/* Confirmación antes de borrar. */
		var danger = event.target.closest('[data-mjpm-confirm]');
		if (danger && !window.confirm(CFG.confirmDelete || '¿Seguro?')) {
			event.preventDefault();
			event.stopPropagation();
			return;
		}

		/* Dentro del pedido: el botón no manda el pedido, manda la caja. */
		var submit = event.target.closest('button[type="submit"]');
		var box = submit && submit.closest('[data-mjpm-post]');
		if (box) {
			event.preventDefault();
			if (valid(box)) post(box);
			return;
		}

		/* Abrir la caja del motivo del rechazo. */
		var open = event.target.closest('.mjpm-rc-reject__open');
		if (open) {
			var reject = open.closest('[data-mjpm-reject]');
			var reasonBox = reject && reject.querySelector('.mjpm-rc-reject__box');
			if (reasonBox) {
				reasonBox.hidden = false;
				open.hidden = true;
				var input = field(reasonBox, 'motivo');
				if (input) input.focus();
			}
		}
	});

	/* Enter en un campo de una caja del pedido tampoco tiene que mandar el pedido. */
	document.addEventListener('keydown', function (event) {
		if ('Enter' !== event.key) return;
		var box = event.target.closest && event.target.closest('[data-mjpm-post]');
		if (!box || 'TEXTAREA' === event.target.tagName) return;
		event.preventDefault();
		var submit = box.querySelector('button[type="submit"]');
		if (submit) submit.click();
	});

	/* Aprobar: el importe solo se escribe cuando es una parte del pago. */
	document.addEventListener('change', function (event) {
		var radio = event.target.closest('[data-mjpm-approve] input[type="radio"]');
		if (!radio) return;
		var amount = field(radio.closest('[data-mjpm-approve]'), 'importe');
		if (!amount) return;
		amount.disabled = 'parcial' !== radio.value;
		if (!amount.disabled) amount.focus();
	});

	/* En la bandeja son formularios de verdad: se validan al mandar. */
	document.addEventListener('submit', function (event) {
		var form = event.target.closest('[data-mjpm-reject], [data-mjpm-approve]');
		if (form && !valid(form)) {
			event.preventDefault();
		}
	});
})();
