/**
 * Widget de subida de comprobantes (MJ Medios de Pago).
 *
 * Sin dependencias y autónomo a propósito: lo usan la página de gracias de
 * WooCommerce, la tienda React, el portal de reventas del ERP y la pantalla de
 * seña de turnos. Un solo lugar donde arreglar la subida.
 *
 * USO
 *   <div data-mjpm-uploader data-context="order" data-objeto="123" data-token=""></div>
 *
 * y desde JS, cuando el contenedor aparece después (SPA):
 *   MJPM.uploader.mount(el);          // lee los data-*
 *   MJPM.uploader.mountAll();         // todos los que falten
 *   MJPM.uploader.refresh(el);        // volver a pedir el estado
 *
 * Todo el texto viene de PHP (mjpmUploader.texts): nada escrito acá.
 */
(function () {
	'use strict';

	var CFG = window.mjpmUploader || {};
	var T = CFG.texts || {};

	/* --------------------------------- DOM --------------------------------- */

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			if ('class' === key) {
				node.className = attrs[key];
			} else if ('text' === key) {
				node.textContent = attrs[key];
			} else if ('html' === key) {
				node.innerHTML = attrs[key];
			} else if (0 === key.indexOf('on') && typeof attrs[key] === 'function') {
				node.addEventListener(key.slice(2).toLowerCase(), attrs[key]);
			} else if (null !== attrs[key] && undefined !== attrs[key] && false !== attrs[key]) {
				node.setAttribute(key, attrs[key]);
			}
		});
		(children || []).forEach(function (child) {
			if (!child) return;
			node.appendChild('string' === typeof child ? document.createTextNode(child) : child);
		});
		return node;
	}

	function fill(template, values) {
		return String(template || '').replace(/\{(\w+)\}/g, function (match, key) {
			return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match;
		});
	}

	/** Tamaño legible, para decirle cuánto pesa lo que eligió. */
	function humanSize(bytes) {
		var units = ['B', 'KB', 'MB', 'GB'];
		var value = Number(bytes) || 0;
		var i = 0;
		while (value >= 1024 && i < units.length - 1) { value /= 1024; i++; }
		return (i >= 2 ? Math.round(value) : Math.round(value * 10) / 10) + ' ' + units[i];
	}

	function formatDate(iso) {
		if (!iso) return '';
		var date = new Date(iso);
		if (isNaN(date.getTime())) return '';
		try {
			return date.toLocaleDateString(document.documentElement.lang || 'es-AR', {
				day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit'
			});
		} catch (e) {
			return date.toLocaleString();
		}
	}

	/* --------------------------------- REST -------------------------------- */

	function url(path, params) {
		var base = (CFG.rest || '') + (path || '');
		var query = Object.keys(params || {})
			.filter(function (key) { return params[key] !== '' && params[key] !== null && params[key] !== undefined; })
			.map(function (key) { return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]); })
			.join('&');
		return query ? base + (base.indexOf('?') === -1 ? '?' : '&') + query : base;
	}

	function headers(extra) {
		var out = extra || {};
		if (CFG.nonce) out['X-WP-Nonce'] = CFG.nonce;
		return out;
	}

	function request(method, path, params, body, onProgress) {
		return new Promise(function (resolve, reject) {
			var xhr = new XMLHttpRequest();
			xhr.open(method, url(path, params), true);
			var hdrs = headers({});
			Object.keys(hdrs).forEach(function (key) { xhr.setRequestHeader(key, hdrs[key]); });

			if (onProgress && xhr.upload) {
				xhr.upload.addEventListener('progress', function (event) {
					if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100));
				});
			}
			xhr.onload = function () {
				var data = null;
				try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
				if (xhr.status >= 200 && xhr.status < 300) {
					resolve(data);
					return;
				}
				reject(new Error((data && data.message) || T.error));
			};
			xhr.onerror = function () { reject(new Error(T.offline)); };
			xhr.send(body || null);
		});
	}

	/* ------------------------------- Instancia ----------------------------- */

	function Uploader(node) {
		this.node = node;
		this.context = node.getAttribute('data-context') || 'order';
		this.objeto = node.getAttribute('data-objeto') || '';
		this.token = node.getAttribute('data-token') || '';
		this.title = node.getAttribute('data-title') || '';
		this.state = null;
		this.busy = false;
		this.error = '';
		node.mjpmUploader = this;
	}

	Uploader.prototype.params = function (extra) {
		var out = { context: this.context, objeto: this.objeto, token: this.token };
		Object.keys(extra || {}).forEach(function (key) { out[key] = extra[key]; });
		return out;
	};

	Uploader.prototype.load = function () {
		var self = this;
		return request('GET', '/config', this.params())
			.then(function (data) {
				self.state = data;
				self.error = '';
				self.render();
			})
			.catch(function (err) {
				self.state = null;
				self.error = err.message || T.error;
				self.render();
			});
	};

	Uploader.prototype.upload = function (file, extra, onProgress) {
		var self = this;
		var body = new FormData();
		body.append('archivo', file);
		Object.keys(extra || {}).forEach(function (key) {
			if (extra[key] !== '' && extra[key] !== null) body.append(key, extra[key]);
		});
		return request('POST', '', this.params(), body, onProgress).then(function (data) {
			self.state = data;
			self.error = '';
			self.render();
		});
	};

	Uploader.prototype.remove = function (id) {
		var self = this;
		return request('DELETE', '/' + id, this.params()).then(function (data) {
			self.state = data;
			self.render();
		});
	};

	/* -------------------------------- Render ------------------------------- */

	Uploader.prototype.render = function () {
		var node = this.node;
		node.textContent = '';

		if (this.error && !this.state) {
			node.appendChild(el('p', { class: 'mjpm-uploader__error', text: this.error }));
			return;
		}
		if (!this.state) {
			node.appendChild(el('p', { class: 'mjpm-uploader__loading', text: T.uploading || '…' }));
			return;
		}
		var s = this.state;
		if (!s.asks) {
			node.textContent = '';
			return;
		}

		var card = el('div', { class: 'mjpm-uploader__card' });

		/* Encabezado y estado de la compra. */
		var head = el('div', { class: 'mjpm-uploader__head' }, [
			el('h3', { class: 'mjpm-uploader__title', text: this.title || s.noun })
		]);
		if (s.state) {
			head.appendChild(el('span', {
				class: 'mjpm-badge is-' + s.state,
				text: s.stateLabel
			}));
		}
		card.appendChild(head);

		/* Qué se le pide. */
		var intro = s.text || fill(s.required ? T.required : T.optional, { comprobante: s.noun });
		card.appendChild(el('p', { class: 'mjpm-uploader__intro', text: intro }));

		if ('aprobado' === s.state) {
			card.appendChild(el('p', { class: 'mjpm-uploader__ok', text: T.stateOk }));
		} else if ('parcial' === s.state) {
			card.appendChild(el('p', { class: 'mjpm-uploader__partial', text: fill(T.statePartial, { comprobante: s.noun }) }));
		} else if ('rechazado' === s.state) {
			card.appendChild(el('p', { class: 'mjpm-uploader__no', text: T.stateNo }));
		} else if ('pendiente' === s.state && s.files && s.files.length) {
			card.appendChild(el('p', { class: 'mjpm-uploader__note', text: T.statePending }));
		}

		/* Lo que se le dijo con la decisión: el motivo del rechazo o el mensaje al aprobar. */
		if (s.message) {
			card.appendChild(el('p', {
				class: 'mjpm-uploader__message' + ('rechazado' === s.state ? ' is-no' : ''),
				text: fill('rechazado' === s.state ? T.reason : T.message, { texto: s.message })
			}));
		}

		/* Cuánto pagó y cuánto falta, en cuanto entró algo de plata. */
		if (s.balance && (s.balance.paid > 0 || 'parcial' === s.state)) {
			card.appendChild(this.balance(s.balance));
		}

		if (s.deadline && 'aprobado' !== s.state) {
			card.appendChild(el('p', {
				class: 'mjpm-uploader__deadline',
				text: fill(T.deadline, { fecha: formatDate(s.deadline) })
			}));
		}

		/* Lo ya subido. */
		if (s.files && s.files.length) {
			card.appendChild(this.fileList(s));
		}

		/* Formulario. */
		if (s.canUpload) {
			card.appendChild(this.form(s));
		} else if (s.files && s.files.length >= s.maxFiles) {
			card.appendChild(el('p', { class: 'mjpm-uploader__note', text: T.maxed }));
		}

		if (this.error) {
			card.appendChild(el('p', { class: 'mjpm-uploader__error', text: this.error }));
		}

		node.appendChild(card);
	};

	Uploader.prototype.balance = function (b) {
		var rows = el('dl', { class: 'mjpm-uploader__balanceRows' }, [
			el('div', {}, [el('dt', { text: T.total }), el('dd', { text: b.totalText })]),
			el('div', {}, [el('dt', { text: T.paid }), el('dd', { text: b.paidText })]),
			b.due > 0
				? el('div', { class: 'is-due' }, [el('dt', { text: T.due }), el('dd', { text: b.dueText })])
				: el('div', { class: 'is-paid' }, [el('dt', { text: T.paidInFull }), el('dd', { text: '' })])
		]);
		var box = el('div', { class: 'mjpm-uploader__balance' }, [rows]);

		if (b.history && b.history.length) {
			var list = el('ul', { class: 'mjpm-uploader__history' });
			b.history.forEach(function (entry) {
				var line = [entry.amountText, entry.kindLabel, formatDate(entry.date)].filter(Boolean).join(' · ');
				list.appendChild(el('li', {}, [
					el('span', { text: line }),
					entry.note ? el('span', { class: 'mjpm-uploader__historyNote', text: entry.note }) : null
				]));
			});
			box.appendChild(el('p', { class: 'mjpm-uploader__historyTitle', text: T.history }));
			box.appendChild(list);
		}
		return box;
	};

	Uploader.prototype.fileList = function (s) {
		var self = this;
		var list = el('ul', { class: 'mjpm-uploader__list' });

		s.files.forEach(function (file) {
			var meta = [];
			if (file.amountText) meta.push(file.amountText);
			if (file.reference) meta.push(file.reference);
			if (file.size) meta.push(file.size);

			var row = el('li', { class: 'mjpm-uploader__item is-' + file.status }, [
				el('div', { class: 'mjpm-uploader__itemMain' }, [
					el('span', { class: 'mjpm-uploader__itemName', text: file.name }),
					meta.length ? el('span', { class: 'mjpm-uploader__itemMeta', text: meta.join(' · ') }) : null,
					file.note ? el('span', { class: 'mjpm-uploader__itemNote', text: file.note }) : null,
					file.reason ? el('span', { class: 'mjpm-uploader__itemReason', text: file.reason }) : null
				]),
				el('div', { class: 'mjpm-uploader__itemSide' }, [
					el('span', { class: 'mjpm-badge is-' + file.status, text: file.statusLabel }),
					file.url ? el('a', {
						class: 'mjpm-uploader__link',
						href: file.url,
						target: '_blank',
						rel: 'noopener',
						text: T.view
					}) : null,
					file.canDelete ? el('button', {
						type: 'button',
						class: 'mjpm-uploader__remove',
						text: T.remove,
						onClick: function () {
							if (!window.confirm(T.confirmRemove)) return;
							self.error = '';
							self.remove(file.id).catch(function (err) {
								self.error = err.message || T.error;
								self.render();
							});
						}
					}) : null
				])
			]);
			list.appendChild(row);
		});

		return list;
	};

	Uploader.prototype.form = function (s) {
		var self = this;
		var form = el('form', { class: 'mjpm-uploader__form', novalidate: 'novalidate' });
		var picked = null;

		var input = el('input', {
			type: 'file',
			class: 'mjpm-uploader__file',
			accept: s.accept || '',
			id: 'mjpm-file-' + s.context + '-' + s.objectId
		});

		var fileName = el('span', { class: 'mjpm-uploader__picked' });
		var progress = el('div', { class: 'mjpm-uploader__progress', hidden: 'hidden' }, [
			el('div', { class: 'mjpm-uploader__bar' })
		]);

		var label = el('label', { class: 'mjpm-uploader__drop', for: input.id }, [
			el('span', { class: 'mjpm-uploader__dropMain', text: s.files && s.files.length ? T.pickMore : T.pick }),
			el('span', { class: 'mjpm-uploader__dropHint', text: T.drop })
		]);

		/* Campos opcionales, según lo que el admin pidió. */
		var fields = el('div', { class: 'mjpm-uploader__fields' });
		var amount = null;
		var reference = null;
		var note = null;

		if (s.askAmount) {
			amount = el('input', { type: 'text', inputmode: 'decimal', class: 'mjpm-uploader__input', name: 'importe', placeholder: T.amount });
			fields.appendChild(el('label', { class: 'mjpm-uploader__field' }, [
				el('span', { text: T.amount }), amount
			]));
		}
		if (s.askReference) {
			reference = el('input', { type: 'text', class: 'mjpm-uploader__input', name: 'referencia', maxlength: '190', placeholder: T.reference });
			fields.appendChild(el('label', { class: 'mjpm-uploader__field' }, [
				el('span', { text: T.reference }), reference
			]));
		}
		if (s.askNote) {
			note = el('input', { type: 'text', class: 'mjpm-uploader__input', name: 'nota', maxlength: '255', placeholder: T.note });
			fields.appendChild(el('label', { class: 'mjpm-uploader__field mjpm-uploader__field--wide' }, [
				el('span', { text: T.note }), note
			]));
		}

		var submit = el('button', { type: 'submit', class: 'mjpm-uploader__submit', text: T.send, disabled: 'disabled' });

		var setPicked = function (file) {
			picked = file || null;
			fileName.textContent = picked ? picked.name : '';
			if (picked) {
				submit.removeAttribute('disabled');
			} else {
				submit.setAttribute('disabled', 'disabled');
			}
		};

		input.addEventListener('change', function () {
			setPicked(input.files && input.files[0]);
		});

		/* Arrastrar y soltar. */
		['dragenter', 'dragover'].forEach(function (name) {
			label.addEventListener(name, function (event) {
				event.preventDefault();
				label.classList.add('is-over');
			});
		});
		['dragleave', 'drop'].forEach(function (name) {
			label.addEventListener(name, function (event) {
				event.preventDefault();
				label.classList.remove('is-over');
			});
		});
		label.addEventListener('drop', function (event) {
			var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
			if (file) {
				try { input.files = event.dataTransfer.files; } catch (e) { /* Safari viejo. */ }
				setPicked(file);
			}
		});

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (!picked || self.busy) return;

			if (s.maxBytes && picked.size > s.maxBytes) {
				self.error = fill(T.tooBig, { peso: humanSize(picked.size), tamano: s.maxSizeText });
				self.render();
				return;
			}

			self.busy = true;
			self.error = '';
			submit.setAttribute('disabled', 'disabled');
			submit.textContent = T.sending;
			progress.removeAttribute('hidden');
			var bar = progress.firstChild;

			self.upload(
				picked,
				{
					importe: amount ? amount.value : '',
					referencia: reference ? reference.value : '',
					nota: note ? note.value : ''
				},
				function (percent) { bar.style.width = percent + '%'; }
			).catch(function (err) {
				self.error = err.message || T.error;
				self.render();
			}).then(function () {
				self.busy = false;
			});
		});

		form.appendChild(label);
		form.appendChild(input);
		form.appendChild(fileName);
		if (fields.childNodes.length) form.appendChild(fields);
		form.appendChild(progress);
		form.appendChild(submit);
		form.appendChild(el('p', {
			class: 'mjpm-uploader__limits',
			text: fill(T.limits, { tamano: s.maxSizeText, tipos: s.typesText })
		}));

		return form;
	};

	/* ---------------------------------- API -------------------------------- */

	function mount(node) {
		if (!node || node.mjpmUploader) return node && node.mjpmUploader;
		var instance = new Uploader(node);
		instance.load();
		return instance;
	}

	function mountAll(root) {
		var scope = root || document;
		Array.prototype.forEach.call(scope.querySelectorAll('[data-mjpm-uploader]'), mount);
	}

	function refresh(node) {
		if (node && node.mjpmUploader) node.mjpmUploader.load();
	}

	window.MJPM = window.MJPM || {};
	window.MJPM.uploader = { mount: mount, mountAll: mountAll, refresh: refresh };

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', function () { mountAll(); });
	} else {
		mountAll();
	}
})();
