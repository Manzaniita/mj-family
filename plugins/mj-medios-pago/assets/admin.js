/**
 * MJ Medios de Pago — página de configuración: muestra/oculta el valor según
 * el tipo de ajuste, calcula un ejemplo en vivo y confirma el borrado.
 */
(function ($) {
	'use strict';

	var cfg = window.mjpmAdmin || {};

	function parseNum(v) {
		v = String(v == null ? '' : v).trim();
		if (!v) return null;
		v = v.replace(/[^\d.,-]/g, '');
		var d = v.lastIndexOf('.'), c = v.lastIndexOf(',');
		if (d > -1 && c > -1) {
			v = c > d ? v.replace(/\./g, '').replace(',', '.') : v.replace(/,/g, '');
		} else if (c > -1) {
			v = (v.split(',').length > 2) ? v.replace(/,/g, '') : v.replace(',', '.');
		} else if (d > -1 && v.split('.').length > 2) {
			v = v.replace(/\./g, '');
		}
		var n = parseFloat(v);
		return isNaN(n) ? null : n;
	}

	function roundPrice(n) {
		var step = parseInt(cfg.rounding, 10) || 0;
		if (step <= 0) return Math.round(n * 100) / 100;
		var q = n / step;
		q = cfg.roundingMode === 'up' ? Math.ceil(q - 1e-9) : Math.round(q);
		return q * step;
	}

	function fmtMoney(n) {
		var decimals = Number.isInteger(n) ? 0 : (parseInt(cfg.decimals, 10) || 2);
		var parts = n.toFixed(decimals).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thousandSep || '.');
		var s = parts.length > 1 ? parts[0] + (cfg.decimalSep || ',') + parts[1] : parts[0];
		switch (cfg.position) {
			case 'right': return s + cfg.symbol;
			case 'right_space': return s + ' ' + cfg.symbol;
			case 'left': return cfg.symbol + s;
			default: return cfg.symbol + ' ' + s;
		}
	}

	function example() {
		var raw = $('.mjpm-preview-form input[name="ejemplo"]').val();
		var n = parseNum(raw);
		return n && n > 0 ? n : 100000;
	}

	function updateRow($row) {
		var kind = $row.find('[data-mjpm-kind]').val();
		var mode = $row.find('[data-mjpm-mode]').val();
		var value = parseNum($row.find('[data-mjpm-value]').val()) || 0;
		var $ex = $row.find('[data-mjpm-example]');
		var adjusts = (kind === 'discount' || kind === 'surcharge') && value > 0;
		$row.find('.mjpm-adjust__value').toggle(kind === 'discount' || kind === 'surcharge');
		if (!adjusts) { $ex.text(''); return; }
		var base = example();
		if (mode === 'percent') {
			var price = roundPrice(base * (kind === 'discount' ? 1 - value / 100 : 1 + value / 100));
			$ex.text(fmtMoney(base) + ' → ' + fmtMoney(price));
		} else {
			$ex.text((kind === 'discount' ? '−' : '+') + fmtMoney(value) + ' por pedido');
		}
	}

	function updateAll() {
		$('[data-mjpm-row]').each(function () { updateRow($(this)); });
	}

	$(document).on('change input', '[data-mjpm-row] select, [data-mjpm-row] input', function () {
		updateRow($(this).closest('[data-mjpm-row]'));
	});
	$(document).on('input', '.mjpm-preview-form input[name="ejemplo"]', updateAll);

	/* Elegir el precio base bloquea el ajuste de esa fila (se guarda como "sin ajuste"). */
	$(document).on('change', 'input[name="base_method"]', function () {
		$('[data-mjpm-row]').each(function () {
			var $row = $(this);
			var isBase = $row.find('input[name="base_method"]').is(':checked');
			$row.find('[data-mjpm-kind]').prop('disabled', isBase);
			if (isBase) $row.find('[data-mjpm-kind]').val('none');
			updateRow($row);
		});
	});

	$(document).on('click', '.mjpm-delete', function (e) {
		if (!window.confirm(cfg.confirmDelete || '¿Borrar?')) e.preventDefault();
	});

	$(updateAll);
})(jQuery);
