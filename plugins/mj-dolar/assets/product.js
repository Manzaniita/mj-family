/**
 * MJ Dólar — ficha de producto, variaciones, edición rápida y en lote.
 *
 * Con precio USD cargado, los campos de pesos se completan solos (USD × TC
 * redondeado) y quedan bloqueados: el servidor los recalcula al guardar.
 */
(function ($) {
	'use strict';

	var cfg = window.mjdProduct || {};
	var rate = parseFloat(cfg.rate) || 0;
	var lockTitle = 'Se calcula desde el precio USD × TC. Para editarlo a mano, borrá el precio USD.';

	/* ---------------------------- Utilidades --------------------------- */

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

	function roundArs(n) {
		var step = parseInt(cfg.rounding, 10) || 0;
		if (step <= 0) return Math.round(n * 100) / 100;
		var q = n / step;
		q = cfg.roundingMode === 'up' ? Math.ceil(q - 1e-9) : Math.round(q);
		return q * step;
	}

	function fmtPlain(n, forceDecimals) {
		var decimals = Number.isInteger(n) && !forceDecimals ? 0 : (parseInt(cfg.decimals, 10) || 2);
		var parts = n.toFixed(decimals).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thousandSep || '.');
		return parts.length > 1 ? parts[0] + (cfg.decimalSep || ',') + parts[1] : parts[0];
	}

	function fmtMoney(n) {
		var s = fmtPlain(n);
		switch (cfg.position) {
			case 'right': return s + cfg.symbol;
			case 'right_space': return s + ' ' + cfg.symbol;
			case 'left': return cfg.symbol + s;
			default: return cfg.symbol + ' ' + s;
		}
	}

	function fmtUsd(n) {
		return 'USD ' + fmtPlain(n);
	}

	/* Valor para inputs .wc_input_price: sin miles, con el separador decimal de la tienda. */
	function toInput(n) {
		if (!isFinite(n)) return '';
		var s = Number.isInteger(n) ? String(n) : n.toFixed(parseInt(cfg.decimals, 10) || 2);
		return s.replace('.', cfg.decimalSep || ',');
	}

	/* ------------------------ Ficha y variaciones ---------------------- */

	function fields($scope) {
		var isVariation = $scope.attr('data-mjd-scope') === 'variation';
		var $row = isVariation ? $scope.closest('.woocommerce_variation') : $();
		return {
			usd: isVariation ? $scope.find('input[name^="mjd_variation_usd_regular"]').first() : $('#_mjd_usd_regular'),
			usdSale: isVariation ? $scope.find('input[name^="mjd_variation_usd_sale"]').first() : $('#_mjd_usd_sale'),
			ars: isVariation ? $row.find('input[name^="variable_regular_price["]').first() : $('#_regular_price'),
			arsSale: isVariation ? $row.find('input[name^="variable_sale_price["]').first() : $('#_sale_price'),
			preview: $scope.find('[data-mjd-preview]')
		};
	}

	function lock($inputs, locked) {
		$inputs.prop('readonly', locked).toggleClass('mjd-readonly', locked);
		if (locked) $inputs.attr('title', lockTitle); else $inputs.removeAttr('title');
	}

	function update($scope) {
		var f = fields($scope);
		var usd = parseNum(f.usd.val());
		var usdSale = parseNum(f.usdSale.val());

		if (!usd || usd <= 0) {
			lock(f.ars.add(f.arsSale), false);
			f.preview.text(rate > 0 ? cfg.empty : cfg.noRate);
			return;
		}
		if (rate <= 0) {
			lock(f.ars.add(f.arsSale), false);
			f.preview.text(cfg.noRate);
			return;
		}

		var ars = roundArs(usd * rate);
		var text = fmtUsd(usd) + ' × ' + cfg.rateFormatted + ' = ' + fmtMoney(ars);
		f.ars.val(toInput(ars));

		if (usdSale && usdSale > 0 && usdSale < usd) {
			var sale = roundArs(usdSale * rate);
			if (sale < ars) {
				f.arsSale.val(toInput(sale));
				text += ' · Oferta: ' + fmtMoney(sale);
			} else {
				f.arsSale.val('');
			}
		} else {
			f.arsSale.val('');
		}
		lock(f.ars.add(f.arsSale), true);
		f.preview.text(text);
	}

	function initAll() {
		$('.mjd-pricing').each(function () { update($(this)); });
	}

	$(document).on('input change', '.mjd-pricing input', function () {
		update($(this).closest('.mjd-pricing'));
	});
	$(initAll);
	$('#woocommerce-product-data').on('woocommerce_variations_loaded woocommerce_variations_added', initAll);

	/* --------------------------- Edición rápida ------------------------- */

	if (window.inlineEditPost && typeof window.inlineEditPost.edit === 'function') {
		var wpEdit = window.inlineEditPost.edit;
		window.inlineEditPost.edit = function (id) {
			wpEdit.apply(this, arguments);
			var postId = 0;
			if (typeof id === 'object') postId = parseInt(this.getId(id), 10);
			else postId = parseInt(id, 10);
			if (!postId) return;
			var $row = $('#edit-' + postId);
			var $data = $('#mjd_inline_' + postId);
			$row.find('input[name="_mjd_usd_regular"]').val($data.find('.usd_regular').text());
			$row.find('input[name="_mjd_usd_sale"]').val($data.find('.usd_sale').text());
		};
	}

	/* --------------------------- Edición en lote ------------------------ */

	$(document).on('change', '.mjd-bulk select.change_to', function () {
		var v = $(this).val();
		$(this).closest('.mjd-bulk').find('.change-input').toggle(v === '1' || v === '2' || v === '3' || v === '5');
	});
})(jQuery);
