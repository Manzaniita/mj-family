<?php
/**
 * Punto de enfoque (focal point / encuadre) para imágenes de productos y banners.
 *
 * Permite definir qué parte de la imagen debe permanecer visible y centrada
 * en tarjetas cuadradas (1:1), carruseles y banners apaisados (16:9), usando
 * object-position / background-position sin recortar ni modificar el archivo original.
 *
 * PHP compatible con 7.4.
 */

defined('ABSPATH') || exit;

/**
 * Sanea y normaliza un punto de enfoque {x: float, y: float}.
 *
 * Valores normalizados entre 0.0 y 1.0 (donde 0.5 es el centro exacto).
 *
 * @param mixed $focal Array o string JSON con claves 'x' e 'y'.
 * @return array{x:float, y:float}|null
 */
function mj_sanitize_focal_point($focal) {
	if (is_string($focal)) {
		$decoded = json_decode($focal, true);
		if (is_array($decoded)) {
			$focal = $decoded;
		}
	}
	if (!is_array($focal) || !isset($focal['x'], $focal['y'])) {
		return null;
	}
	$x = (float) $focal['x'];
	$y = (float) $focal['y'];

	// Si vino en porcentaje 0..100 en lugar de 0..1:
	if ($x > 1.0 || $y > 1.0) {
		$x = $x / 100.0;
		$y = $y / 100.0;
	}

	$x = max(0.0, min(1.0, round($x, 4)));
	$y = max(0.0, min(1.0, round($y, 4)));

	return ['x' => $x, 'y' => $y];
}

/**
 * Obtiene el punto de enfoque {x: float, y: float} para una imagen/adjunto y un producto opcional.
 *
 * Prioridad:
 * 1. Meta específico del producto para este adjunto (_mj_gallery_focal[$attachment_id]).
 * 2. Meta del producto si es la foto principal (_mj_focal_point).
 * 3. Meta del propio adjunto (_mj_focal_point en el attachment).
 *
 * @param int $attachment_id ID del adjunto de WordPress.
 * @param int $product_id    ID del producto (opcional).
 * @return array{x:float, y:float}|null
 */
function mj_get_image_focal_point($attachment_id, $product_id = 0) {
	$attachment_id = absint($attachment_id);
	if (!$attachment_id) {
		return null;
	}

	$product_id = absint($product_id);
	if ($product_id > 0) {
		// 1. Galería específica del producto
		$gallery_focals = get_post_meta($product_id, '_mj_gallery_focal', true);
		if (is_array($gallery_focals) && isset($gallery_focals[$attachment_id])) {
			$clean = mj_sanitize_focal_point($gallery_focals[$attachment_id]);
			if ($clean) {
				return $clean;
			}
		}

		// 2. Foto destacada del producto
		$thumb_id = absint(get_post_thumbnail_id($product_id));
		if ($thumb_id === $attachment_id) {
			$prod_focal = get_post_meta($product_id, '_mj_focal_point', true);
			$clean = mj_sanitize_focal_point($prod_focal);
			if ($clean) {
				return $clean;
			}
		}
	}

	// 3. Meta guardado en el propio adjunto
	$att_focal = get_post_meta($attachment_id, '_mj_focal_point', true);
	$clean = mj_sanitize_focal_point($att_focal);
	if ($clean) {
		return $clean;
	}

	return null;
}

/* -------------------------------------------------------------------------
 * WooCommerce Store API — expone focal y gallery_focal en cada producto
 * ---------------------------------------------------------------------- */
add_action('woocommerce_init', function () {
	if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
		return;
	}
	woocommerce_store_api_register_endpoint_data([
		'endpoint'        => 'product',
		'namespace'       => 'mj',
		'data_callback'   => function ($product) {
			if (!$product instanceof WC_Product) {
				return [
					'focal'         => null,
					'gallery_focal' => [],
				];
			}
			$pid    = $product->get_id();
			$img_id = $product->get_image_id();
			$focal  = $img_id ? mj_get_image_focal_point($img_id, $pid) : null;

			$gallery_focal = [];
			foreach ($product->get_gallery_image_ids() as $gid) {
				$gf = mj_get_image_focal_point($gid, $pid);
				if ($gf) {
					$gallery_focal[(int) $gid] = $gf;
				}
			}

			return [
				'focal'         => $focal,
				'gallery_focal' => (object) $gallery_focal,
			];
		},
		'schema_callback' => function () {
			return [
				'focal'         => [
					'description' => 'Punto de enfoque de la foto principal {x, y}',
					'type'        => ['object', 'null'],
					'context'     => ['view', 'embed'],
					'readonly'    => true,
				],
				'gallery_focal' => [
					'description' => 'Puntos de enfoque de las fotos de galería {attachment_id: {x, y}}',
					'type'        => ['object', 'null'],
					'context'     => ['view', 'embed'],
					'readonly'    => true,
				],
			];
		},
		'schema_type'     => ARRAY_A,
	]);
});

/* -------------------------------------------------------------------------
 * WooCommerce REST API — enriquece $response->data['images'] con focal
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_rest_prepare_product_object', function ($response, $product) {
	if (isset($response->data['images']) && is_array($response->data['images'])) {
		$pid = $product->get_id();
		foreach ($response->data['images'] as &$img) {
			$aid = isset($img['id']) ? absint($img['id']) : 0;
			if ($aid > 0) {
				$focal = mj_get_image_focal_point($aid, $pid);
				if ($focal) {
					$img['focal'] = $focal;
				}
			}
		}
	}
	return $response;
}, 10, 2);

/* -------------------------------------------------------------------------
 * Meta Box en la pantalla de edición de Producto (WooCommerce)
 * ---------------------------------------------------------------------- */
add_action('add_meta_boxes', function () {
	add_meta_box(
		'mj_product_focal_box',
		'Encuadre de foto (Punto de enfoque)',
		'mj_render_product_focal_box',
		'product',
		'side',
		'low'
	);
});

/**
 * Renderiza la caja de encuadre en la barra lateral del producto.
 *
 * @param WP_Post $post
 */
function mj_render_product_focal_box($post) {
	wp_nonce_field('mj_save_product_focal_action', 'mj_product_focal_nonce');

	$pid           = $post->ID;
	$main_id       = absint(get_post_thumbnail_id($pid));
	$gallery_csv   = (string) get_post_meta($pid, '_product_image_gallery', true);
	$gallery_ids   = array_filter(array_map('absint', explode(',', $gallery_csv)));

	$main_focal    = mj_get_image_focal_point($main_id, $pid);
	if (!$main_focal) {
		$main_focal = ['x' => 0.5, 'y' => 0.5];
	}
	$gallery_focals = get_post_meta($pid, '_mj_gallery_focal', true);
	if (!is_array($gallery_focals)) {
		$gallery_focals = [];
	}

	// Construir lista de imágenes disponibles
	$images = [];
	if ($main_id > 0) {
		$full_url  = wp_get_attachment_image_url($main_id, 'full');
		$thumb_url = wp_get_attachment_image_url($main_id, 'medium');
		if ($full_url) {
			$images[] = [
				'id'       => $main_id,
				'url'      => $full_url,
				'thumb'    => $thumb_url ? $thumb_url : $full_url,
				'focal'    => $main_focal,
				'isMain'   => true,
				'label'    => 'Foto principal',
			];
		}
	}

	$idx = 1;
	foreach ($gallery_ids as $gid) {
		$full_url  = wp_get_attachment_image_url($gid, 'full');
		$thumb_url = wp_get_attachment_image_url($gid, 'medium');
		if ($full_url) {
			$gf = mj_get_image_focal_point($gid, $pid);
			if (!$gf) {
				$gf = ['x' => 0.5, 'y' => 0.5];
			}
			$images[] = [
				'id'       => $gid,
				'url'      => $full_url,
				'thumb'    => $thumb_url ? $thumb_url : $full_url,
				'focal'    => $gf,
				'isMain'   => false,
				'label'    => 'Galería ' . $idx,
			];
			$idx++;
		}
	}

	$has_images = !empty($images);
	?>
	<div id="mj-focal-wrapper" style="font-size:12px;">
		<p style="margin:0 0 10px; color:#50575e; line-height:1.4;">
			Elegí qué parte de la imagen se muestra centrada en las <strong>tarjetas cuadradas</strong> y <strong>banners</strong>, sin recortar el archivo original.
		</p>

		<div id="mj-focal-empty" style="<?php echo $has_images ? 'display:none;' : ''; ?> padding:10px; background:#f6f7f7; border:1px dashed #c3c4c7; border-radius:6px; text-align:center; color:#646970;">
			Asigná una <strong>Imagen del producto</strong> arriba para ajustar el encuadre.
		</div>

		<div id="mj-focal-content" style="<?php echo !$has_images ? 'display:none;' : ''; ?>">
			<?php if (count($images) > 1) : ?>
				<div id="mj-focal-tabs" style="display:flex; gap:6px; margin-bottom:10px; overflow-x:auto; padding-bottom:4px;">
					<?php foreach ($images as $k => $im) : ?>
						<button type="button" class="button button-small mj-focal-tab <?php echo 0 === $k ? 'button-primary' : ''; ?>"
							data-index="<?php echo esc_attr($k); ?>"
							style="display:flex; align-items:center; gap:4px; padding:2px 8px;">
							<img src="<?php echo esc_url($im['thumb']); ?>" style="width:16px; height:16px; object-fit:cover; border-radius:2px;" alt="" />
							<span><?php echo esc_html($im['label']); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Contenedor del selector interactivo -->
			<div id="mj-focal-picker" style="position:relative; width:100%; border-radius:8px; overflow:hidden; background:#000; cursor:crosshair; user-select:none; margin-bottom:10px; border:1px solid #c3c4c7;">
				<img id="mj-focal-img" src="<?php echo $has_images ? esc_url($images[0]['url']) : ''; ?>" alt="" style="display:block; width:100%; height:auto; opacity:0.9;" />
				<div id="mj-focal-pin" style="position:absolute; width:22px; height:22px; border:2px solid #fff; border-radius:50%; box-shadow:0 0 0 1px rgba(0,0,0,0.6), 0 2px 6px rgba(0,0,0,0.5); transform:translate(-50%, -50%); pointer-events:none; left:<?php echo $has_images ? esc_attr((string) ($images[0]['focal']['x'] * 100)) : '50'; ?>%; top:<?php echo $has_images ? esc_attr((string) ($images[0]['focal']['y'] * 100)) : '50'; ?>%; background:rgba(110,107,255,0.4);">
					<div style="position:absolute; top:50%; left:50%; width:4px; height:4px; background:#fff; border-radius:50%; transform:translate(-50%, -50%);"></div>
				</div>
			</div>

			<!-- Indicador de coordenadas y presets rápidos -->
			<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; color:#50575e;">
				<span>Foco: <strong>X: <span id="mj-focal-x-label"><?php echo $has_images ? esc_html((string) round($images[0]['focal']['x'] * 100)) : '50'; ?></span>%</strong> · <strong>Y: <span id="mj-focal-y-label"><?php echo $has_images ? esc_html((string) round($images[0]['focal']['y'] * 100)) : '50'; ?></span>%</strong></span>
				<button type="button" class="button button-small" id="mj-focal-btn-center" style="font-size:11px;">Centrar</button>
			</div>

			<!-- Botones de presets rápidos -->
			<div style="display:flex; gap:4px; margin-bottom:12px; flex-wrap:wrap;">
				<button type="button" class="button button-small mj-focal-preset" data-x="0.5" data-y="0.15" style="font-size:11px;">Arriba</button>
				<button type="button" class="button button-small mj-focal-preset" data-x="0.5" data-y="0.5" style="font-size:11px;">Medio</button>
				<button type="button" class="button button-small mj-focal-preset" data-x="0.5" data-y="0.85" style="font-size:11px;">Abajo</button>
				<button type="button" class="button button-small mj-focal-preset" data-x="0.2" data-y="0.5" style="font-size:11px;">Izquierda</button>
				<button type="button" class="button button-small mj-focal-preset" data-x="0.8" data-y="0.5" style="font-size:11px;">Derecha</button>
			</div>

			<!-- Vistas previas en vivo -->
			<div style="border-top:1px solid #dcdcde; padding-top:10px; margin-top:10px;">
				<span style="font-weight:600; display:block; margin-bottom:6px; color:#1d2327;">Vista previa en la tienda:</span>
				<div style="display:flex; gap:10px; align-items:flex-start;">
					<!-- Tarjeta cuadrada (1:1) -->
					<div style="flex:1;">
						<div style="font-size:10px; color:#646970; margin-bottom:3px;">Tarjeta (1:1)</div>
						<div style="width:100%; aspect-ratio:1/1; position:relative; overflow:hidden; border-radius:8px; border:1px solid #dcdcde; background:#f0f0f1;">
							<img id="mj-prev-card-img" src="<?php echo $has_images ? esc_url($images[0]['url']) : ''; ?>" alt="" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:<?php echo $has_images ? esc_attr(($images[0]['focal']['x'] * 100) . '% ' . ($images[0]['focal']['y'] * 100) . '%') : '50% 50%'; ?>;" />
						</div>
					</div>
					<!-- Banner apaisado (16:9) -->
					<div style="flex:1.4;">
						<div style="font-size:10px; color:#646970; margin-bottom:3px;">Banner (16:9)</div>
						<div style="width:100%; aspect-ratio:16/9; position:relative; overflow:hidden; border-radius:8px; border:1px solid #dcdcde; background:#f0f0f1;">
							<img id="mj-prev-banner-img" src="<?php echo $has_images ? esc_url($images[0]['url']) : ''; ?>" alt="" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:<?php echo $has_images ? esc_attr(($images[0]['focal']['x'] * 100) . '% ' . ($images[0]['focal']['y'] * 100) . '%') : '50% 50%'; ?>;" />
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Inputs ocultos para persistir -->
		<input type="hidden" id="mj_product_focal_input" name="mj_product_focal" value="<?php echo esc_attr(wp_json_encode($main_focal)); ?>" />
		<input type="hidden" id="mj_product_gallery_focal_input" name="mj_product_gallery_focal" value="<?php echo esc_attr(wp_json_encode($gallery_focals)); ?>" />
	</div>

	<script>
	(function () {
		var images = <?php echo wp_json_encode($images); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var activeIndex = 0;
		var galleryFocalMap = <?php echo wp_json_encode((object) $gallery_focals); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var mainFocal = <?php echo wp_json_encode($main_focal); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;

		var wrapper = document.getElementById('mj-focal-wrapper');
		if (!wrapper) return;

		var picker = document.getElementById('mj-focal-picker');
		var imgEl = document.getElementById('mj-focal-img');
		var pin = document.getElementById('mj-focal-pin');
		var xLabel = document.getElementById('mj-focal-x-label');
		var yLabel = document.getElementById('mj-focal-y-label');
		var cardImg = document.getElementById('mj-prev-card-img');
		var bannerImg = document.getElementById('mj-prev-banner-img');
		var inputMain = document.getElementById('mj_product_focal_input');
		var inputGallery = document.getElementById('mj_product_gallery_focal_input');

		function updateActiveFocal(x, y) {
			x = Math.max(0, Math.min(1, Math.round(x * 10000) / 10000));
			y = Math.max(0, Math.min(1, Math.round(y * 10000) / 10000));

			var pctX = Math.round(x * 100);
			var pctY = Math.round(y * 100);

			if (pin) {
				pin.style.left = (x * 100) + '%';
				pin.style.top = (y * 100) + '%';
			}
			if (xLabel) xLabel.textContent = pctX;
			if (yLabel) yLabel.textContent = pctY;

			var pos = pctX + '% ' + pctY + '%';
			if (cardImg) cardImg.style.objectPosition = pos;
			if (bannerImg) bannerImg.style.objectPosition = pos;

			if (images[activeIndex]) {
				images[activeIndex].focal = { x: x, y: y };
				if (images[activeIndex].isMain) {
					mainFocal = { x: x, y: y };
					inputMain.value = JSON.stringify(mainFocal);
				} else {
					galleryFocalMap[images[activeIndex].id] = { x: x, y: y };
					inputGallery.value = JSON.stringify(galleryFocalMap);
				}
			}
		}

		function handlePointer(e) {
			if (!picker) return;
			var rect = picker.getBoundingClientRect();
			var clientX = e.touches ? e.touches[0].clientX : e.clientX;
			var clientY = e.touches ? e.touches[0].clientY : e.clientY;
			var x = (clientX - rect.left) / rect.width;
			var y = (clientY - rect.top) / rect.height;
			updateActiveFocal(x, y);
		}

		var isDragging = false;
		if (picker) {
			picker.addEventListener('mousedown', function (e) {
				isDragging = true;
				handlePointer(e);
			});
			window.addEventListener('mousemove', function (e) {
				if (isDragging) handlePointer(e);
			});
			window.addEventListener('mouseup', function () {
				isDragging = false;
			});
			picker.addEventListener('touchstart', function (e) {
				isDragging = true;
				handlePointer(e);
			}, { passive: true });
			window.addEventListener('touchmove', function (e) {
				if (isDragging) handlePointer(e);
			}, { passive: true });
			window.addEventListener('touchend', function () {
				isDragging = false;
			});
		}

		// Presets
		document.querySelectorAll('.mj-focal-preset').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var px = parseFloat(btn.getAttribute('data-x'));
				var py = parseFloat(btn.getAttribute('data-y'));
				updateActiveFocal(px, py);
			});
		});

		var centerBtn = document.getElementById('mj-focal-btn-center');
		if (centerBtn) {
			centerBtn.addEventListener('click', function () {
				updateActiveFocal(0.5, 0.5);
			});
		}

		// Tabs para alternar entre foto principal y galería
		var tabs = document.querySelectorAll('.mj-focal-tab');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				tabs.forEach(function (t) { t.classList.remove('button-primary'); });
				tab.classList.add('button-primary');
				activeIndex = parseInt(tab.getAttribute('data-index'), 10);
				var cur = images[activeIndex];
				if (cur) {
					imgEl.src = cur.url;
					if (cardImg) cardImg.src = cur.url;
					if (bannerImg) bannerImg.src = cur.url;
					updateActiveFocal(cur.focal.x, cur.focal.y);
				}
			});
		});

		// Detectar cambios en la imagen destacada desde WooCommerce
		var thumbInput = document.getElementById('_thumbnail_id');
		if (thumbInput) {
			var observer = new MutationObserver(function () {
				var newId = parseInt(thumbInput.value, 10);
				if (!newId || newId <= 0) {
					document.getElementById('mj-focal-empty').style.display = 'block';
					document.getElementById('mj-focal-content').style.display = 'none';
				}
			});
			observer.observe(thumbInput, { attributes: true, attributeFilter: ['value'] });
		}
	})();
	</script>
	<?php
}

/**
 * Guarda los puntos de enfoque al actualizar el producto.
 */
add_action('save_post_product', function ($post_id) {
	if (!isset($_POST['mj_product_focal_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mj_product_focal_nonce'])), 'mj_save_product_focal_action')) {
		return;
	}
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	if (!current_user_can('edit_post', $post_id)) {
		return;
	}

	// 1. Guardar foco de la imagen principal
	if (isset($_POST['mj_product_focal'])) {
		$raw = wp_unslash($_POST['mj_product_focal']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$clean = mj_sanitize_focal_point($raw);
		if ($clean) {
			update_post_meta($post_id, '_mj_focal_point', $clean);
			$thumb_id = absint(get_post_thumbnail_id($post_id));
			if ($thumb_id > 0) {
				update_post_meta($thumb_id, '_mj_focal_point', $clean);
			}
		} else {
			delete_post_meta($post_id, '_mj_focal_point');
		}
	}

	// 2. Guardar focos de la galería
	if (isset($_POST['mj_product_gallery_focal'])) {
		$raw_gallery = wp_unslash($_POST['mj_product_gallery_focal']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$decoded = json_decode($raw_gallery, true);
		if (is_array($decoded)) {
			$clean_map = [];
			foreach ($decoded as $aid => $fval) {
				$clean_val = mj_sanitize_focal_point($fval);
				if ($clean_val) {
					$clean_map[absint($aid)] = $clean_val;
					update_post_meta(absint($aid), '_mj_focal_point', $clean_val);
				}
			}
			update_post_meta($post_id, '_mj_gallery_focal', $clean_map);
		}
	}
});

/* -------------------------------------------------------------------------
 * Campos de adjunto en la Biblioteca de Medios (WordPress)
 * ---------------------------------------------------------------------- */
add_filter('attachment_fields_to_edit', function ($form_fields, $post) {
	if (!wp_attachment_is_image($post->ID)) {
		return $form_fields;
	}
	$focal = mj_get_image_focal_point($post->ID);
	if (!$focal) {
		$focal = ['x' => 0.5, 'y' => 0.5];
	}
	$val_x = (int) round($focal['x'] * 100);
	$val_y = (int) round($focal['y'] * 100);

	$html = sprintf(
		'<div style="display:flex; align-items:center; gap:8px;">' .
		'<span>X: <input type="number" name="attachments[%1$d][_mj_focal_x]" value="%2$d" min="0" max="100" style="width:60px;" />%%</span>' .
		'<span>Y: <input type="number" name="attachments[%1$d][_mj_focal_y]" value="%3$d" min="0" max="100" style="width:60px;" />%%</span>' .
		'</div><p class="description" style="margin:4px 0 0;">Punto de enfoque (0%% a 100%%). Se usa para encuadrar en tarjetas y banners sin recortar.</p>',
		$post->ID,
		$val_x,
		$val_y
	);

	$form_fields['_mj_focal_point'] = [
		'label' => 'Encuadre (foco)',
		'input' => 'html',
		'html'  => $html,
	];
	return $form_fields;
}, 10, 2);

add_filter('attachment_fields_to_save', function ($post, $attachment) {
	if (isset($attachment['_mj_focal_x'], $attachment['_mj_focal_y'])) {
		$x = floatval($attachment['_mj_focal_x']) / 100.0;
		$y = floatval($attachment['_mj_focal_y']) / 100.0;
		$clean = mj_sanitize_focal_point(['x' => $x, 'y' => $y]);
		if ($clean) {
			update_post_meta($post['ID'], '_mj_focal_point', $clean);
		}
	}
	return $post;
}, 10, 2);
