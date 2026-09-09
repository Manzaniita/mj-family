<?php
/**
 * Pedidos: cada línea queda sellada con quién la hizo y la comisión vigente
 * en el momento de la compra. Así las liquidaciones no cambian si después
 * se modifica un porcentaje o se reasigna un producto.
 *
 * Meta de la línea (oculta para el cliente):
 *   _mjc_colega_id    → id del término (0 = producto propio)
 *   _mjc_colega_name  → nombre en ese momento
 *   _mjc_commission   → % en ese momento
 *   _mjc_settled      → id de la liquidación que la incluyó (ver report.php)
 */

defined('ABSPATH') || exit;

/* Sella una línea de pedido (si todavía no lo está). */
function mjc_stamp_item($item, $force = false) {
	if (!$item instanceof WC_Order_Item_Product) {
		return false;
	}
	if (!$force && '' !== (string) $item->get_meta('_mjc_colega_id', true)) {
		return false;
	}
	$term = mjc_product_colega($item->get_product_id());
	if ($term) {
		$item->update_meta_data('_mjc_colega_id', (int) $term->term_id);
		$item->update_meta_data('_mjc_colega_name', $term->name);
		$item->update_meta_data('_mjc_commission', mjc_colega_commission($term->term_id));
	} else {
		$item->update_meta_data('_mjc_colega_id', 0);
		$item->delete_meta_data('_mjc_colega_name');
		$item->delete_meta_data('_mjc_commission');
	}
	return true;
}

/* Checkout (clásico y Store API): al crear cada línea. */
add_action('woocommerce_checkout_create_order_line_item', function ($item) {
	mjc_stamp_item($item);
}, 10, 1);

/* Red de seguridad: pedidos creados por otras vías (admin, API, plugins). */
function mjc_stamp_order($order) {
	if (!$order instanceof WC_Order) {
		$order = wc_get_order($order);
	}
	if (!$order) {
		return;
	}
	$changed = false;
	foreach ($order->get_items('line_item') as $item) {
		if (mjc_stamp_item($item)) {
			$item->save();
			$changed = true;
		}
	}
	if ($changed) {
		$order->save();
	}
}
add_action('woocommerce_checkout_order_created', 'mjc_stamp_order');
add_action('woocommerce_store_api_checkout_order_processed', 'mjc_stamp_order');
add_action('woocommerce_saved_order_items', function ($order_id) {
	mjc_stamp_order($order_id);
});

/* -------------------------------------------------------------------------
 * Cálculo por línea (lo usa el reporte y el detalle del pedido)
 * ---------------------------------------------------------------------- */

/*
 * Info de una línea: quién, %, base, comisión y a pagar.
 * Líneas sin sellar (pedidos anteriores al plugin) se resuelven con la
 * asignación y el porcentaje actuales, marcadas como `live`.
 */
function mjc_item_info($item) {
	$colega_id = (string) $item->get_meta('_mjc_colega_id', true);
	$live      = '' === $colega_id;

	if ($live) {
		$term       = mjc_product_colega($item->get_product_id());
		$colega_id  = $term ? (int) $term->term_id : 0;
		$name       = $term ? $term->name : '';
		$commission = $term ? mjc_colega_commission($term->term_id) : 0;
	} else {
		$colega_id  = (int) $colega_id;
		$name       = (string) $item->get_meta('_mjc_colega_name', true);
		$commission = (float) $item->get_meta('_mjc_commission', true);
		// Nombre actual si la colega cambió de nombre.
		if ($colega_id) {
			$term = get_term($colega_id, MJC_TAX);
			if ($term && !is_wp_error($term)) {
				$name = $term->name;
			}
		}
	}

	$base = 'subtotal' === mjc_opt('commission_base')
		? (float) $item->get_subtotal() + (float) $item->get_subtotal_tax()
		: (float) $item->get_total() + (float) $item->get_total_tax();

	$commission_amount = $colega_id ? round($base * $commission / 100, 2) : 0.0;

	return [
		'colega_id'  => $colega_id,
		'name'       => $colega_id ? $name : mjc_house_name(),
		'house'      => 0 === $colega_id,
		'commission' => $colega_id ? $commission : 0.0,
		'base'       => round($base, 2),
		'amount'     => $commission_amount,
		'payout'     => $colega_id ? round($base - $commission_amount, 2) : round($base, 2),
		'settled'    => (int) $item->get_meta('_mjc_settled', true),
		'live'       => $live,
	];
}

/* -------------------------------------------------------------------------
 * Admin del pedido: quién hizo cada línea y el resumen en los totales
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_hidden_order_itemmeta', function ($hidden) {
	return array_merge($hidden, ['_mjc_colega_id', '_mjc_colega_name', '_mjc_commission', '_mjc_settled']);
});

add_action('woocommerce_after_order_itemmeta', function ($item_id, $item) {
	if (!$item instanceof WC_Order_Item_Product) {
		return;
	}
	$info = mjc_item_info($item);
	if ($info['house']) {
		return;
	}
	printf(
		'<div class="mjc-item" style="margin-top:4px; font-size:12px; color:#50575e;"><span class="dashicons dashicons-groups" style="font-size:14px; width:14px; height:14px; vertical-align:text-bottom;"></span> %s · comisión %s%% = %s · a pagar %s%s</div>',
		esc_html($info['name']),
		esc_html(rtrim(rtrim(number_format($info['commission'], 2, '.', ''), '0'), '.')),
		wp_kses_post(wc_price($info['amount'])),
		wp_kses_post(wc_price($info['payout'])),
		$info['settled'] ? ' · <strong style="color:#00a32a;">liquidado</strong>' : ''
	);
}, 10, 2);

add_action('woocommerce_admin_order_totals_after_total', function ($order_id) {
	$order = wc_get_order($order_id);
	if (!$order) {
		return;
	}
	$commission = 0.0;
	$payout     = 0.0;
	$names      = [];
	foreach ($order->get_items('line_item') as $item) {
		$info = mjc_item_info($item);
		if ($info['house']) {
			continue;
		}
		$commission += $info['amount'];
		$payout     += $info['payout'];
		$names[$info['name']] = true;
	}
	if (!$names) {
		return;
	}
	?>
	<tr>
		<td class="label"><?php echo esc_html(mjc_opt('label_plural')); ?> (<?php echo esc_html(implode(', ', array_keys($names))); ?>):</td>
		<td width="1%"></td>
		<td class="total">a pagar <?php echo wp_kses_post(wc_price($payout)); ?> · tu comisión <?php echo wp_kses_post(wc_price($commission)); ?></td>
	</tr>
	<?php
});
