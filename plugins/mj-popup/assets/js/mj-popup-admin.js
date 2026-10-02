/**
 * MJ Popup — JavaScript del panel de administración (wp-admin).
 */
(function ($) {
	'use strict';

	$(function () {
		var mediaFrame;
		var $selectBtn = $('#mj-popup-select-img');
		var $removeBtn = $('#mj-popup-remove-img');
		var $inputUrl = $('#mj-popup-img-url');
		var $inputId = $('#mj-popup-img-id');
		var $previewContainer = $('#mj-popup-preview-box');

		if ($selectBtn.length) {
			$selectBtn.on('click', function (e) {
				e.preventDefault();

				if (mediaFrame) {
					mediaFrame.open();
					return;
				}

				mediaFrame = wp.media({
					title: 'Seleccionar imagen para el aviso',
					button: {
						text: 'Usar esta imagen'
					},
					multiple: false,
					library: {
						type: 'image'
					}
				});

				mediaFrame.on('select', function () {
					var attachment = mediaFrame.state().get('selection').first().toJSON();
					$inputId.val(attachment.id);
					$inputUrl.val(attachment.url);

					$previewContainer.html('<img src="' + attachment.url + '" class="mj-popup-preview-img" alt="Vista previa" />');
					$removeBtn.show();
					$selectBtn.text('Cambiar imagen');
				});

				mediaFrame.open();
			});
		}

		if ($removeBtn.length) {
			$removeBtn.on('click', function (e) {
				e.preventDefault();
				$inputId.val('');
				$inputUrl.val('');
				$previewContainer.html('<span class="mj-popup-no-image-text">No hay imagen seleccionada</span>');
				$removeBtn.hide();
				$selectBtn.text('Seleccionar imagen');
			});
		}

		// Confirmación al reiniciar visualizaciones
		var $btnReset = $('#mj-popup-btn-reset');
		if ($btnReset.length) {
			$btnReset.on('click', function (e) {
				if (!confirm('¿Seguro que querés reiniciar las visualizaciones?\n\nEsto hará que el aviso vuelva a mostrarse una vez más a todos los visitantes que ya lo hayan visto.')) {
					e.preventDefault();
				}
			});
		}
	});
})(jQuery);
