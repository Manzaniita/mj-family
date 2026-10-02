/**
 * MJ Popup — Lógica del frontend.
 *
 * Muestra el aviso emergente con imagen según la frecuencia configurada
 * (una sola vez por sesión, cada X días o permanente hasta nuevo aviso).
 */
(function () {
	'use strict';

	function initMjPopup() {
		var config = window.mjPopupConfig;
		if (!config || !config.activo) {
			return;
		}

		var overlay = document.getElementById('mj-popup-overlay');
		if (!overlay) {
			return;
		}

		var avisoId = config.aviso_id || '1';
		var storageKey = 'mj_popup_visto_' + avisoId;
		var ahora = Date.now();

		// Comprobar si ya fue visto según la frecuencia
		if (!config.es_preview && config.frecuencia !== 'always') {
			if (config.frecuencia === 'session') {
				try {
					if (window.sessionStorage && window.sessionStorage.getItem(storageKey)) {
						return;
					}
				} catch (e) {
					// Fallback si cookies/storage están bloqueados
				}
			} else {
				try {
					if (window.localStorage) {
						var guardado = window.localStorage.getItem(storageKey);
						if (guardado) {
							if (config.frecuencia === 'once') {
								return;
							}

							var timestamp = parseInt(guardado, 10);
							if (!isNaN(timestamp)) {
								var limites = {
									'1_day': 24 * 60 * 60 * 1000,
									'3_days': 3 * 24 * 60 * 60 * 1000,
									'7_days': 7 * 24 * 60 * 60 * 1000,
									'30_days': 30 * 24 * 60 * 60 * 1000
								};
								var ventana = limites[config.frecuencia] || limites['1_day'];
								if (ahora - timestamp < ventana) {
									return;
								}
							}
						}
					}
				} catch (e) {
					// Fallback silencioso
				}
			}
		}

		function marcarVisto() {
			try {
				if (config.frecuencia === 'session') {
					if (window.sessionStorage) {
						window.sessionStorage.setItem(storageKey, '1');
					}
				} else if (window.localStorage) {
					window.localStorage.setItem(storageKey, ahora.toString());
				}
			} catch (e) {
				// Ignorar errores de almacenamiento restringido
			}
		}

		function cerrarPopup() {
			overlay.classList.remove('mj-popup-visible');
			overlay.setAttribute('aria-hidden', 'true');
			marcarVisto();
			setTimeout(function () {
				document.body.classList.remove('mj-popup-open');
			}, 300);
		}

		function abrirPopup() {
			overlay.setAttribute('aria-hidden', 'false');
			overlay.classList.add('mj-popup-visible');
			document.body.classList.add('mj-popup-open');
			marcarVisto();

			// Dar foco al botón de cierre para accesibilidad
			var closeBtn = overlay.querySelector('.mj-popup-close-btn');
			if (closeBtn && typeof closeBtn.focus === 'function') {
				closeBtn.focus();
			}
		}

		// Botón de cierre (cruz)
		var closeBtn = overlay.querySelector('.mj-popup-close-btn');
		if (closeBtn) {
			closeBtn.addEventListener('click', function (e) {
				e.preventDefault();
				cerrarPopup();
			});
		}

		// Botón inferior de continuar
		var actionBtn = overlay.querySelector('.mj-popup-action-btn');
		if (actionBtn) {
			actionBtn.addEventListener('click', function (e) {
				e.preventDefault();
				cerrarPopup();
			});
		}

		// Cerrar al hacer clic en el fondo oscuro exterior
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				cerrarPopup();
			}
		});

		// Cerrar al pulsar tecla Escape
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && overlay.classList.contains('mj-popup-visible')) {
				cerrarPopup();
			}
		});

		// Retardo agradable para no invadir de inmediato al cargar la página
		var delay = parseInt(config.retardo_ms, 10);
		if (isNaN(delay) || delay < 0) {
			delay = 400;
		}

		setTimeout(abrirPopup, delay);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initMjPopup);
	} else {
		initMjPopup();
	}
})();
