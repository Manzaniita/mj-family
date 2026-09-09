/* global MJOI */
(function () {
	'use strict';

	var cfg = window.MJOI || {};
	var i18n = cfg.i18n || {};

	function qs(sel, ctx) {
		return (ctx || document).querySelector(sel);
	}

	function post(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (k) {
			fd.append(k, data[k]);
		});
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) {
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r.json();
		});
	}

	function fmtBytes(b) {
		b = Number(b) || 0;
		if (b < 1024) {
			return b + ' B';
		}
		var u = ['KB', 'MB', 'GB'];
		var i = -1;
		do {
			b /= 1024;
			i++;
		} while (b >= 1024 && i < u.length - 1);
		return b.toFixed(b < 10 ? 2 : 1) + ' ' + u[i];
	}

	function setStats(stats) {
		if (!stats) {
			return;
		}
		document.querySelectorAll('[data-mjoi-stat]').forEach(function (el) {
			var key = el.getAttribute('data-mjoi-stat');
			if (stats[key] !== undefined) {
				el.textContent = stats[key];
			}
		});
	}

	function setCounts(counts) {
		if (!counts) {
			return;
		}
		document.querySelectorAll('[data-mjoi-count]').forEach(function (el) {
			var key = el.getAttribute('data-mjoi-count');
			if (counts[key] !== undefined) {
				el.textContent = Number(counts[key]).toLocaleString();
			}
		});
	}

	/* ------------------------------------------------------------------
	 * Página de ajustes: proceso masivo
	 * ---------------------------------------------------------------- */

	var startBtn = qs('#mjoi-bulk-start');
	var errorsBtn = qs('#mjoi-bulk-errors');
	var stopBtn = qs('#mjoi-bulk-stop');
	var resetAllBtn = qs('#mjoi-reset-all');
	var progress = qs('#mjoi-progress');
	var bar = qs('#mjoi-progress-bar');
	var progressText = qs('#mjoi-progress-text');
	var logBox = qs('#mjoi-log');

	var running = false;
	var stopRequested = false;

	function log(msg, cls) {
		if (!logBox) {
			return;
		}
		logBox.hidden = false;
		var line = document.createElement('div');
		line.className = 'mjoi-log-line' + (cls ? ' mjoi-log-' + cls : '');
		line.textContent = msg;
		logBox.insertBefore(line, logBox.firstChild);
		while (logBox.children.length > 200) {
			logBox.removeChild(logBox.lastChild);
		}
	}

	function setRunning(state) {
		running = state;
		if (startBtn) {
			startBtn.disabled = state;
		}
		if (errorsBtn) {
			errorsBtn.disabled = state;
		}
		if (stopBtn) {
			stopBtn.disabled = !state;
		}
		if (resetAllBtn) {
			resetAllBtn.disabled = state;
		}
	}

	function updateProgress(done, total) {
		if (!progress) {
			return;
		}
		progress.hidden = false;
		var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 100;
		bar.style.width = pct + '%';
		progressText.textContent = pct + '% (' + done + '/' + total + ')';
	}

	function runLoop(includeErrors) {
		if (running) {
			return;
		}
		setRunning(true);
		stopRequested = false;
		var processed = 0;
		var total = 0;
		var retries = 0;

		function step() {
			if (stopRequested) {
				log(i18n.stopped || 'Detenido.');
				setRunning(false);
				return;
			}
			post('mjoi_bulk_run', { include_errors: includeErrors ? 1 : 0 })
				.then(function (res) {
					retries = 0;
					if (!res || !res.success) {
						log((i18n.error || 'Error') + ': ' + (res && res.data && res.data.message ? res.data.message : '?'), 'err');
						setRunning(false);
						return;
					}
					var d = res.data;
					processed += d.processed;
					if (!total) {
						total = processed + d.remaining;
					}
					updateProgress(processed, Math.max(total, processed + d.remaining));
					setStats(d.stats);
					setCounts(d.counts);
					(d.results || []).forEach(function (r) {
						if (r.status === 'done') {
							log('#' + r.id + ' ' + r.title + ': ' + fmtBytes(r.before) + ' → ' + fmtBytes(r.after) + ' (' + (i18n.saved || 'ahorro') + ' ' + r.percent + '%)' + (r.message ? ' · ' + r.message : ''), 'ok');
						} else {
							log('#' + r.id + ' ' + r.title + ': ' + (r.message || i18n.error), 'err');
						}
					});
					if (d.remaining <= 0 || d.processed === 0) {
						log(i18n.done || 'Terminado.');
						updateProgress(processed, processed);
						setRunning(false);
						return;
					}
					step();
				})
				.catch(function () {
					retries++;
					if (retries > 5) {
						log(i18n.error || 'Error', 'err');
						setRunning(false);
						return;
					}
					log(i18n.netError || 'Error de red, reintentando…', 'err');
					setTimeout(step, 3000 * retries);
				});
		}
		log(i18n.working || 'Procesando…');
		step();
	}

	if (startBtn) {
		startBtn.addEventListener('click', function () {
			runLoop(false);
		});
	}
	if (errorsBtn) {
		errorsBtn.addEventListener('click', function () {
			runLoop(true);
		});
	}
	if (stopBtn) {
		stopBtn.addEventListener('click', function () {
			stopRequested = true;
			stopBtn.disabled = true;
		});
	}
	if (resetAllBtn) {
		resetAllBtn.addEventListener('click', function () {
			if (!window.confirm(i18n.confirmReset || '¿Continuar?')) {
				return;
			}
			resetAllBtn.disabled = true;
			post('mjoi_reset', { only_errors: 0 })
				.then(function (res) {
					if (res && res.success) {
						setCounts(res.data.counts);
						log('OK: ' + res.data.reset);
					}
				})
				.catch(function () {
					log(i18n.error || 'Error', 'err');
				})
				.then(function () {
					resetAllBtn.disabled = false;
				});
		});
	}

	/* ------------------------------------------------------------------
	 * Biblioteca de medios: botones por imagen
	 * ---------------------------------------------------------------- */

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.mjoi-btn') : null;
		if (!btn) {
			return;
		}
		e.preventDefault();
		var cell = btn.closest('.mjoi-cell');
		if (!cell) {
			return;
		}
		var id = cell.getAttribute('data-id');
		var action = btn.getAttribute('data-action');
		var ajaxAction = action === 'restore' ? 'mjoi_restore_one' : 'mjoi_optimize_one';

		if (action === 'restore' && !window.confirm(i18n.confirmRest || '¿Restaurar?')) {
			return;
		}

		cell.classList.add('mjoi-busy');
		cell.querySelectorAll('.mjoi-btn').forEach(function (b) {
			b.disabled = true;
		});

		post(ajaxAction, { id: id })
			.then(function (res) {
				var data = res && res.data ? res.data : {};
				if (data.html) {
					cell.outerHTML = data.html;
				} else {
					cell.classList.remove('mjoi-busy');
					cell.querySelectorAll('.mjoi-btn').forEach(function (b) {
						b.disabled = false;
					});
					if (data.message) {
						window.alert(data.message);
					}
				}
				setStats(data.stats);
			})
			.catch(function () {
				cell.classList.remove('mjoi-busy');
				cell.querySelectorAll('.mjoi-btn').forEach(function (b) {
					b.disabled = false;
				});
				window.alert(i18n.error || 'Error');
			});
	});
})();
