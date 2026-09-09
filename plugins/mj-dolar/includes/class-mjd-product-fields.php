<?php
/**
 * Campos USD en wp-admin: ficha de producto, variaciones, edición rápida,
 * edición en lote, columna, orden y filtro en el listado de productos.
 *
 * Los nonces y permisos de todos los guardados los verifica WooCommerce antes
 * de disparar los hooks usados acá.
 */

defined('ABSPATH') || exit;

class MJD_Product_Fields {

	public static function init() {
		add_action('woocommerce_product_options_pricing', [__CLASS__, 'product_fields']);
		add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_product']);
		add_action('woocommerce_variation_options_pricing', [__CLASS__, 'variation_fields'], 10, 3);
		add_action('woocommerce_admin_process_variation_object', [__CLASS__, 'save_variation'], 10, 2);

		add_action('woocommerce_product_quick_edit_end', [__CLASS__, 'quick_edit_fields']);
		add_action('woocommerce_product_quick_edit_save', [__CLASS__, 'quick_edit_save']);
		add_action('woocommerce_product_bulk_edit_end', [__CLASS__, 'bulk_edit_fields']);
		add_action('woocommerce_product_bulk_edit_save', [__CLASS__, 'bulk_edit_save']);

		add_filter('manage_edit-product_columns', [__CLASS__, 'columns'], 20);
		add_action('manage_product_posts_custom_column', [__CLASS__, 'column'], 10, 2);
		add_filter('manage_edit-product_sortable_columns', [__CLASS__, 'sortable']);
		add_action('restrict_manage_posts', [__CLASS__, 'filter_dropdown']);
		add_action('pre_get_posts', [__CLASS__, 'pre_get_posts']);

		add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
	}

	/* ---------------------------------------------------------------------
	 * Ficha de producto (pestaña General → precios)
	 * ------------------------------------------------------------------ */

	/** Texto inicial del "En pesos" (product.js lo recalcula al escribir). */
	private static function preview_text($usd, $usd_sale = '') {
		if (!MJD_Rate::is_set()) {
			return 'Cargá el tipo de cambio en WooCommerce → Dólar para calcular el precio en pesos.';
		}
		if ('' === (string) $usd) {
			return 'Escribí un precio USD para ver el equivalente en pesos.';
		}
		$text = MJD_Rate::format_usd($usd) . ' × ' . MJD_Rate::format_rate() . ' = ' . MJD_Rate::format_ars(MJD_Rate::convert($usd));
		if ('' !== (string) $usd_sale && (float) $usd_sale < (float) $usd) {
			$text .= ' · Oferta: ' . MJD_Rate::format_ars(MJD_Rate::convert($usd_sale));
		}
		return $text;
	}

	public static function product_fields() {
		$post_id  = get_the_ID();
		$usd      = get_post_meta($post_id, MJD_Product::META_REGULAR, true);
		$usd_sale = get_post_meta($post_id, MJD_Product::META_SALE, true);

		echo '<div class="mjd-pricing" data-mjd-scope="product">';
		woocommerce_wp_text_input([
			'id'            => '_mjd_usd_regular',
			'label'         => 'Precio USD',
			'placeholder'   => wc_format_localized_price('0.00'),
			'data_type'     => 'price',
			'value'         => $usd,
			'desc_tip'      => true,
			'description'   => 'Precio en dólares. El precio normal en pesos se calcula solo con el TC vigente y se recalcula cada vez que lo cambiás. Dejalo vacío para vender este producto a un precio fijo en pesos.',
			'wrapper_class' => 'mjd-field',
		]);
		woocommerce_wp_text_input([
			'id'            => '_mjd_usd_sale',
			'label'         => 'Precio oferta USD',
			'placeholder'   => wc_format_localized_price('0.00'),
			'data_type'     => 'price',
			'value'         => $usd_sale,
			'desc_tip'      => true,
			'description'   => 'Oferta en dólares (tiene que ser menor al precio USD). Respeta las fechas de oferta programada.',
			'wrapper_class' => 'mjd-field',
		]);
		echo '<p class="form-field mjd-preview"><label>En pesos</label><span class="mjd-preview__text" data-mjd-preview>' . esc_html(self::preview_text($usd, $usd_sale)) . '</span></p>';
		echo '</div>';
	}

	public static function save_product($product) {
		if (!$product instanceof WC_Product) {
			return;
		}
		/* Variables y agrupados no tienen precio propio: el USD vive en las variaciones. */
		if ($product->is_type('variable') || $product->is_type('grouped')) {
			MJD_Product::set_usd($product, '', '');
			return;
		}
		if (!isset($_POST['_mjd_usd_regular']) || is_array($_POST['_mjd_usd_regular'])) {
			return; // El formulario no trajo los campos.
		}
		$regular = wp_unslash($_POST['_mjd_usd_regular']);
		$sale    = isset($_POST['_mjd_usd_sale']) && !is_array($_POST['_mjd_usd_sale']) ? wp_unslash($_POST['_mjd_usd_sale']) : '';

		MJD_Product::set_usd($product, $regular, $sale);
		MJD_Product::apply($product, null, false); // WooCommerce guarda el objeto después de este hook.
	}

	/* ---------------------------------------------------------------------
	 * Variaciones
	 * ------------------------------------------------------------------ */

	public static function variation_fields($loop, $variation_data, $variation) {
		$vid      = is_object($variation) && isset($variation->ID) ? (int) $variation->ID : 0;
		$usd      = $vid ? get_post_meta($vid, MJD_Product::META_REGULAR, true) : '';
		$usd_sale = $vid ? get_post_meta($vid, MJD_Product::META_SALE, true) : '';

		echo '<div class="mjd-pricing mjd-pricing--variation" data-mjd-scope="variation">';
		woocommerce_wp_text_input([
			'id'            => "mjd_variation_usd_regular_{$loop}",
			'name'          => "mjd_variation_usd_regular[{$loop}]",
			'label'         => 'Precio USD',
			'placeholder'   => wc_format_localized_price('0.00'),
			'data_type'     => 'price',
			'value'         => $usd,
			'wrapper_class' => 'form-row form-row-first mjd-field',
		]);
		woocommerce_wp_text_input([
			'id'            => "mjd_variation_usd_sale_{$loop}",
			'name'          => "mjd_variation_usd_sale[{$loop}]",
			'label'         => 'Precio oferta USD',
			'placeholder'   => wc_format_localized_price('0.00'),
			'data_type'     => 'price',
			'value'         => $usd_sale,
			'wrapper_class' => 'form-row form-row-last mjd-field',
		]);
		echo '<p class="form-row form-row-full mjd-preview"><span class="mjd-preview__text" data-mjd-preview>' . esc_html(self::preview_text($usd, $usd_sale)) . '</span></p>';
		echo '</div>';
	}

	public static function save_variation($variation, $i) {
		if (!$variation instanceof WC_Product) {
			return;
		}
		if (!isset($_POST['mjd_variation_usd_regular']) || !is_array($_POST['mjd_variation_usd_regular']) || !array_key_exists($i, $_POST['mjd_variation_usd_regular'])) {
			return;
		}
		$regular = wp_unslash($_POST['mjd_variation_usd_regular'][$i]);
		$sale    = isset($_POST['mjd_variation_usd_sale'][$i]) ? wp_unslash($_POST['mjd_variation_usd_sale'][$i]) : '';

		MJD_Product::set_usd($variation, $regular, $sale);
		MJD_Product::apply($variation, null, false); // WooCommerce guarda la variación después.
	}

	/* ---------------------------------------------------------------------
	 * Edición rápida
	 * ------------------------------------------------------------------ */

	public static function quick_edit_fields() {
		?>
		<div class="price_fields mjd-quick-fields">
			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title">Precio USD</span>
					<span class="input-text-wrap"><input type="text" name="_mjd_usd_regular" class="text wc_input_price mjd_usd_regular" placeholder="Precio USD" value=""></span>
				</label>
				<label class="alignright">
					<span class="title">Oferta USD</span>
					<span class="input-text-wrap"><input type="text" name="_mjd_usd_sale" class="text wc_input_price mjd_usd_sale" placeholder="Oferta USD" value=""></span>
				</label>
			</div>
			<p class="mjd-quick-help">Con precio USD cargado, el precio en pesos se calcula solo (lo que escribas en “Precio” se ignora).</p>
		</div>
		<?php
	}

	public static function quick_edit_save($product) {
		if (!$product instanceof WC_Product) {
			return;
		}
		if (!isset($_REQUEST['_mjd_usd_regular']) || is_array($_REQUEST['_mjd_usd_regular'])) {
			return;
		}
		if (!$product->is_type('simple') && !$product->is_type('external')) {
			return;
		}
		$regular = wp_unslash($_REQUEST['_mjd_usd_regular']);
		$sale    = isset($_REQUEST['_mjd_usd_sale']) && !is_array($_REQUEST['_mjd_usd_sale']) ? wp_unslash($_REQUEST['_mjd_usd_sale']) : '';

		$before = [(string) $product->get_meta(MJD_Product::META_REGULAR), (string) $product->get_meta(MJD_Product::META_SALE)];
		MJD_Product::set_usd($product, $regular, $sale);
		$applied = MJD_Product::apply($product, null, false);
		$after   = [(string) $product->get_meta(MJD_Product::META_REGULAR), (string) $product->get_meta(MJD_Product::META_SALE)];

		/* WooCommerce ya guardó: solo volvemos a guardar si cambió algo nuestro. */
		if ($applied || $before !== $after) {
			$product->save();
		}
	}

	/* ---------------------------------------------------------------------
	 * Edición en lote
	 * ------------------------------------------------------------------ */

	public static function bulk_edit_fields() {
		?>
		<div class="inline-edit-group mjd-bulk">
			<label class="alignleft">
				<span class="title">Precio USD</span>
				<span class="input-text-wrap">
					<select class="change_mjd_usd change_to" name="change_mjd_usd">
						<option value="">— Sin cambios —</option>
						<option value="1">Cambiar a:</option>
						<option value="2">Aumentar el USD existente en (monto o %):</option>
						<option value="3">Reducir el USD existente en (monto o %):</option>
						<option value="4">Quitar el precio USD (vuelve a pesos fijos)</option>
					</select>
				</span>
			</label>
			<label class="change-input">
				<input type="text" name="_mjd_usd_regular_bulk" class="text wc_input_price" placeholder="Valor o %" value="">
			</label>
		</div>
		<div class="inline-edit-group mjd-bulk">
			<label class="alignleft">
				<span class="title">Oferta USD</span>
				<span class="input-text-wrap">
					<select class="change_mjd_usd_sale change_to" name="change_mjd_usd_sale">
						<option value="">— Sin cambios —</option>
						<option value="1">Cambiar a:</option>
						<option value="2">Aumentar la oferta USD existente en (monto o %):</option>
						<option value="3">Reducir la oferta USD existente en (monto o %):</option>
						<option value="5">Fijar como % de descuento sobre el precio USD:</option>
						<option value="4">Quitar la oferta USD</option>
					</select>
				</span>
			</label>
			<label class="change-input">
				<input type="text" name="_mjd_usd_sale_bulk" class="text wc_input_price" placeholder="Valor o %" value="">
			</label>
		</div>
		<p class="mjd-bulk-help">En productos variables se aplica a cada variación.</p>
		<?php
	}

	/**
	 * Nuevo valor según el modo elegido en el lote.
	 *
	 * @return float|string|null null = sin cambio, '' = quitar, float = valor nuevo.
	 */
	private static function bulk_value($current, $mode, $raw) {
		$raw    = trim((string) $raw);
		$is_pct = '' !== $raw && '%' === substr($raw, -1);
		$num    = MJD_Product::parse_decimal(rtrim($raw, '%'));
		$num    = '' === $num ? null : (float) $num;

		switch ((int) $mode) {
			case 1:
				return null === $num ? null : $num;
			case 2:
				if (null === $current || null === $num) {
					return null;
				}
				return $is_pct ? $current * (1 + $num / 100) : $current + $num;
			case 3:
				if (null === $current || null === $num) {
					return null;
				}
				return max(0, $is_pct ? $current * (1 - $num / 100) : $current - $num);
			case 4:
				return '';
		}
		return null;
	}

	private static function bulk_apply($product, $mode, $raw, $sale_mode, $raw_sale) {
		$usd      = MJD_Product::usd_regular($product);
		$usd_sale = MJD_Product::usd_sale($product);

		$new     = self::bulk_value($usd, $mode, $raw);
		$regular = null === $new ? $usd : ('' === $new ? null : (float) $new);
		if (null !== $regular && $regular <= 0) {
			$regular = null;
		}

		$sale = null;
		if (null !== $regular) {
			if (5 === (int) $sale_mode) {
				$pct  = MJD_Product::parse_decimal(rtrim(trim((string) $raw_sale), '%'));
				$pct  = '' === $pct ? 0 : (float) $pct;
				$sale = $pct > 0 && $pct < 100 ? $regular * (1 - $pct / 100) : $usd_sale;
			} else {
				$new_sale = self::bulk_value($usd_sale, $sale_mode, $raw_sale);
				$sale     = null === $new_sale ? $usd_sale : ('' === $new_sale ? null : (float) $new_sale);
			}
		}

		if ($regular === $usd && $sale === $usd_sale) {
			return; // Nada que cambiar.
		}

		MJD_Product::set_usd(
			$product,
			null === $regular ? '' : (string) round($regular, 2),
			null === $sale ? '' : (string) round($sale, 2)
		);
		MJD_Product::apply($product, null, false);
		$product->save();
	}

	public static function bulk_edit_save($product) {
		if (!$product instanceof WC_Product) {
			return;
		}
		$mode      = isset($_REQUEST['change_mjd_usd']) ? absint($_REQUEST['change_mjd_usd']) : 0;
		$sale_mode = isset($_REQUEST['change_mjd_usd_sale']) ? absint($_REQUEST['change_mjd_usd_sale']) : 0;
		if (!$mode && !$sale_mode) {
			return;
		}
		$raw      = isset($_REQUEST['_mjd_usd_regular_bulk']) ? wp_unslash($_REQUEST['_mjd_usd_regular_bulk']) : '';
		$raw_sale = isset($_REQUEST['_mjd_usd_sale_bulk']) ? wp_unslash($_REQUEST['_mjd_usd_sale_bulk']) : '';

		if ($product->is_type('variable')) {
			foreach ($product->get_children() as $child_id) {
				$variation = wc_get_product($child_id);
				if ($variation) {
					self::bulk_apply($variation, $mode, $raw, $sale_mode, $raw_sale);
				}
			}
			WC_Product_Variable::sync($product->get_id());
			MJD_Product::sync_parent_usd($product->get_id());
			return;
		}

		if ($product->is_type('simple') || $product->is_type('external')) {
			self::bulk_apply($product, $mode, $raw, $sale_mode, $raw_sale);
		}
	}

	/* ---------------------------------------------------------------------
	 * Listado de productos: columna, orden y filtro
	 * ------------------------------------------------------------------ */

	public static function columns($columns) {
		$out = [];
		foreach ($columns as $key => $label) {
			$out[$key] = $label;
			if ('price' === $key) {
				$out['mjd_usd'] = 'USD';
			}
		}
		if (!isset($out['mjd_usd'])) {
			$out['mjd_usd'] = 'USD';
		}
		return $out;
	}

	public static function column($column, $post_id) {
		if ('name' === $column) {
			/* Datos para la edición rápida (product.js los copia al formulario). */
			printf(
				'<div class="hidden mjd-inline" id="mjd_inline_%d"><div class="usd_regular">%s</div><div class="usd_sale">%s</div></div>',
				(int) $post_id,
				esc_html(wc_format_localized_price(get_post_meta($post_id, MJD_Product::META_REGULAR, true))),
				esc_html(wc_format_localized_price(get_post_meta($post_id, MJD_Product::META_SALE, true)))
			);
			return;
		}
		if ('mjd_usd' !== $column) {
			return;
		}

		$product = wc_get_product($post_id);
		if (!$product) {
			echo '<span class="mjd-col-empty">—</span>';
			return;
		}

		if ($product->is_type('variable')) {
			$min = (string) get_post_meta($post_id, MJD_Product::META_INDEX, true);
			$max = (string) get_post_meta($post_id, MJD_Product::META_MAX, true);
			if ('' === $min) {
				echo '<span class="mjd-col-empty">—</span>';
				return;
			}
			if ('' === $max || (float) $max <= (float) $min) {
				echo esc_html(MJD_Rate::format_usd($min));
			} else {
				echo esc_html(MJD_Rate::format_usd($min) . ' – ' . MJD_Rate::format_usd($max));
			}
			return;
		}

		$usd = MJD_Product::usd_regular($product);
		if (null === $usd) {
			echo '<span class="mjd-col-empty">—</span>';
			return;
		}
		$usd_sale = MJD_Product::usd_sale($product);
		if (null !== $usd_sale && $usd_sale < $usd) {
			echo '<del>' . esc_html(MJD_Rate::format_usd($usd)) . '</del> <ins>' . esc_html(MJD_Rate::format_usd($usd_sale)) . '</ins>';
		} else {
			echo esc_html(MJD_Rate::format_usd($usd));
		}

		$applied = (string) $product->get_meta(MJD_Product::META_APPLIED);
		if (MJD_Rate::is_set() && $applied !== MJD_Rate::normalize(MJD_Rate::get())) {
			echo '<br><small class="mjd-col-stale" title="Los pesos guardados se calcularon con otro TC. Se actualizan en segundo plano; mientras tanto el cliente ya ve el precio con el TC vigente.">'
				. ('' !== $applied ? 'guardado a ' . esc_html(MJD_Rate::format_rate($applied)) : 'pendiente de recálculo')
				. '</small>';
		}
	}

	public static function sortable($columns) {
		$columns['mjd_usd'] = 'mjd_usd';
		return $columns;
	}

	public static function filter_dropdown($post_type) {
		if ('product' !== $post_type) {
			return;
		}
		$current = isset($_GET['mjd_filter']) ? sanitize_key($_GET['mjd_filter']) : '';
		echo '<select name="mjd_filter" id="mjd_filter">';
		echo '<option value="">Precio USD: todos</option>';
		echo '<option value="with"' . selected($current, 'with', false) . '>Con precio USD</option>';
		echo '<option value="without"' . selected($current, 'without', false) . '>Sin precio USD</option>';
		echo '</select>';
	}

	public static function pre_get_posts($query) {
		if (!is_admin() || !$query->is_main_query() || 'product' !== $query->get('post_type')) {
			return;
		}
		$meta_query = $query->get('meta_query');
		if (!is_array($meta_query)) {
			$meta_query = [];
		}

		$filter = isset($_GET['mjd_filter']) ? sanitize_key($_GET['mjd_filter']) : '';
		if ('with' === $filter) {
			$meta_query[] = [
				'key'     => MJD_Product::META_INDEX,
				'value'   => '',
				'compare' => '!=',
			];
		} elseif ('without' === $filter) {
			$meta_query[] = [
				'relation' => 'OR',
				['key' => MJD_Product::META_INDEX, 'compare' => 'NOT EXISTS'],
				['key' => MJD_Product::META_INDEX, 'value' => '', 'compare' => '='],
			];
		}

		if ('mjd_usd' === $query->get('orderby')) {
			$meta_query[] = [
				'relation'       => 'OR',
				'mjd_usd_clause' => ['key' => MJD_Product::META_INDEX, 'compare' => 'EXISTS', 'type' => 'DECIMAL(18,4)'],
				['key' => MJD_Product::META_INDEX, 'compare' => 'NOT EXISTS'],
			];
			$query->set('orderby', ['mjd_usd_clause' => 'ASC' === strtoupper((string) $query->get('order')) ? 'ASC' : 'DESC']);
		}

		if ($meta_query) {
			$query->set('meta_query', $meta_query);
		}
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	public static function assets($hook) {
		if (!in_array($hook, ['post.php', 'post-new.php', 'edit.php'], true)) {
			return;
		}
		$screen = get_current_screen();
		if (!$screen || 'product' !== $screen->post_type) {
			return;
		}
		wp_enqueue_style('mjd-admin', MJD_URL . 'assets/admin.css', [], MJD_VERSION);
		wp_enqueue_script('mjd-product', MJD_URL . 'assets/product.js', ['jquery'], MJD_VERSION, true);
		wp_localize_script('mjd-product', 'mjdProduct', [
			'rate'          => MJD_Rate::get(),
			'rateFormatted' => MJD_Rate::is_set() ? MJD_Rate::format_rate() : '',
			'rounding'      => (int) MJD_Settings::get('rounding'),
			'roundingMode'  => (string) MJD_Settings::get('rounding_mode'),
			'decimals'      => (int) wc_get_price_decimals(),
			'decimalSep'    => wc_get_price_decimal_separator(),
			'thousandSep'   => wc_get_price_thousand_separator(),
			'symbol'        => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
			'position'      => get_option('woocommerce_currency_pos', 'left_space'),
			'noRate'        => 'Cargá el tipo de cambio en WooCommerce → Dólar para calcular el precio en pesos.',
			'empty'         => 'Escribí un precio USD para ver el equivalente en pesos.',
		]);
	}
}
