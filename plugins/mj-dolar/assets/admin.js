/**
 * MJ Dólar — página WooCommerce → Dólar y widget del escritorio.
 * Guardado del TC por AJAX, seguimiento del recálculo y cotización sugerida.
 */
(function ($) {
	'use strict';

	var cfg = window.mjdAdmin || {};
	var i18n = cfg.i18n || {};

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

	function ajax(action, data) {
		return $.post(cfg.ajaxUrl, $.extend({ action: action, _ajax_nonce: cfg.nonce }, data || {}));
	}

	function feedback($el, text, isError) {
		if (!$el.length) return;
		$el.text(text || '').prop('hidden', !text).toggleClass('is-error', !!isError).toggleClass('is-ok', !isError && !!text);
	}

	/* --------------------------- Formulario TC -------------------------- */

	$(document).on('input', '[data-mjd-rate-form] input[name="rate"]', function () {
		var $form = $(this).closest('[data-mjd-rate-form]');
		var $preview = $form.find('[data-mjd-rate-preview]');
		var rate = parseNum(this.value);
		if (!rate || rate <= 0) { $preview.text(''); return; }
		$preview.text(i18n.example + ' USD 100 → ' + fmtMoney(roundArs(100 * rate)) + ' · USD 15 → ' + fmtMoney(roundArs(15 * rate)));
	});

	$(document).on('submit', '[data-mjd-rate-form]', function (e) {
		e.preventDefault();
		var $form = $(this);
		var $btn = $form.find('button[type="submit"]');
		var $fb = $form.find('[data-mjd-rate-feedback]');
		var rate = $form.find('input[name="rate"]').val();
		var note = $form.find('input[name="note"]').val() || '';

		if (!parseNum(rate) || parseNum(rate) <= 0) { feedback($fb, i18n.invalid, true); return; }

		$btn.prop('disabled', true);
		feedback($fb, i18n.saving, false);

		ajax('mjd_update_rate', { rate: rate, note: note })
			.done(function (res) {
				if (!res || !res.success) {
					feedback($fb, (res && res.data && res.data.message) || i18n.error, true);
					$btn.prop('disabled', false);
					return;
				}
				feedback($fb, i18n.saved, false);
				// Recarga: header, historial, barra de admin y estadísticas al día. La página retoma el progreso.
				window.location.href = cfg.pageUrl + '&mjd_updated=1';
			})
			.fail(function () {
				feedback($fb, i18n.error, true);
				$btn.prop('disabled', false);
			});
	});

	/* ------------------------------ Recálculo --------------------------- */

	var $sync = $('[data-mjd-sync]');
	var failures = 0;

	function renderSync(state) {
		if (!$sync.length || !state) return;
		$sync.prop('hidden', false).attr('data-status', state.status);
		var pct = state.percent != null ? state.percent : (state.total ? Math.floor(state.done * 100 / state.total) : 100);
		$sync.find('[data-mjd-sync-fill]').css('width', pct + '%');
		var text = '';
		if (state.status === 'running') text = i18n.syncing + ' ' + state.done + ' / ' + state.total;
		else if (state.status === 'done') text = i18n.syncDone + ' (' + state.total + ')';
		$sync.find('[data-mjd-sync-text]').text(text);
		if (state.counts) {
			$.each(state.counts, function (key, value) { $('[data-mjd-count="' + key + '"]').text(value); });
		}
	}

	function drive() {
		ajax('mjd_sync_step')
			.done(function (res) {
				if (!res || !res.success) {
					if (++failures < 5) setTimeout(drive, 3000);
					return;
				}
				failures = 0;
				renderSync(res.data);
				if (res.data.status === 'running') setTimeout(drive, res.data.locked ? 2000 : 120);
			})
			.fail(function () {
				if (++failures < 5) setTimeout(drive, 3000);
			});
	}

	if (cfg.syncRunning) {
		renderSync({ status: 'running', done: cfg.syncDone || 0, total: cfg.syncTotal || 0 });
		drive();
	}

	$(document).on('click', '[data-mjd-sync-start]', function () {
		if (!window.confirm(i18n.confirmSync)) return;
		var $btn = $(this).prop('disabled', true);
		ajax('mjd_sync_start')
			.done(function (res) {
				if (res && res.success) {
					renderSync(res.data);
					if (res.data.status === 'running') drive();
				} else if (res && res.data && res.data.message) {
					window.alert(res.data.message);
				}
			})
			.always(function () { $btn.prop('disabled', false); });
	});

	/* ---------------------------- Sugerido ------------------------------ */

	var $suggest = $('[data-mjd-suggest]');

	function loadSuggest(force) {
		if (!$suggest.length) return;
		var $value = $suggest.find('[data-mjd-suggest-value]');
		var $meta = $suggest.find('[data-mjd-suggest-meta]');
		var $alert = $suggest.find('[data-mjd-suggest-alert]');
		var $use = $suggest.find('[data-mjd-suggest-use]');
		$value.text(i18n.loading);
		ajax('mjd_suggest', { force: force ? 1 : 0 })
			.done(function (res) {
				if (!res || !res.success) {
					$value.text((res && res.data && res.data.message) || i18n.suggestFail);
					$use.prop('disabled', true);
					return;
				}
				var d = res.data;
				$value.text(d.formatted);
				$meta.text(d.label + ' · ' + d.side + ' · compra ' + fmtMoney(d.compra) + ' / venta ' + fmtMoney(d.venta) + ' · ' + i18n.ago.replace('%s', d.fetchedAgo));
				$use.prop('disabled', false).attr('data-value', d.input);
				if (d.alert) {
					$alert.text(i18n.deviation.replace('%s', (d.deviation > 0 ? '+' : '') + d.deviation)).prop('hidden', false);
				} else {
					$alert.prop('hidden', true).text('');
				}
			})
			.fail(function () {
				$value.text(i18n.suggestFail);
				$use.prop('disabled', true);
			});
	}

	$(document).on('click', '[data-mjd-suggest-refresh]', function () { loadSuggest(true); });
	$(document).on('click', '[data-mjd-suggest-use]', function () {
		var value = $(this).attr('data-value');
		if (!value) return;
		$('[data-mjd-rate-form][data-context="page"] input[name="rate"]').val(value).trigger('input').trigger('focus');
	});

	if ($suggest.length) loadSuggest(false);
})(jQuery);
