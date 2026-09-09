<?php
/**
 * Colegas → Liquidaciones.
 *
 * Reporte por período y por colega: cuánto se vendió de cada una, cuánto es
 * tu comisión y cuánto hay que pagarle. "Marcar como liquidado" deja
 * registro (fecha, monto, pedidos incluidos) y saca esas líneas de lo
 * pendiente. Exporta a CSV. Se puede deshacer una liquidación.
 *
 * Las liquidaciones viven en la opción `mjc_settlements`; cada línea de
 * pedido incluida lleva _mjc_settled = id de la liquidación.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Datos
 * ---------------------------------------------------------------------- */

/* Pedidos del período en los estados que cuentan como venta. */
function mjc_orders_in_range($from, $to) {
	if (!function_exists('wc_get_orders')) {
		return [];
	}
	/* Timestamps en la zona horaria del sitio: sirven igual con pedidos en posts o en tablas propias (HPOS). */
	try {
		$start = (new DateTime($from . ' 00:00:00', wp_timezone()))->getTimestamp();
		$end   = (new DateTime($to . ' 23:59:59', wp_timezone()))->getTimestamp();
	} catch (Exception $e) {
		return [];
	}
	return wc_get_orders([
		'limit'        => -1,
		'type'         => 'shop_order',
		'status'       => mjc_sale_statuses(),
		'date_created' => $start . '...' . $end,
		'orderby'      => 'date',
		'order'        => 'ASC',
	]);
}

/*
 * Líneas del período → [{order, item, info}] filtradas.
 * $colega: 0 = todas (incluye propios), -1 = solo colegas, id = una.
 * $pending_only: excluye las ya liquidadas.
 */
function mjc_collect_lines($from, $to, $colega = -1, $pending_only = true) {
	$lines = [];
	foreach (mjc_orders_in_range($from, $to) as $order) {
		foreach ($order->get_items('line_item') as $item) {
			$info = mjc_item_info($item);
			if (-1 === $colega && $info['house']) {
				continue;
			}
			if ($colega > 0 && $info['colega_id'] !== $colega) {
				continue;
			}
			if ($pending_only && $info['settled']) {
				continue;
			}
			$lines[] = ['order' => $order, 'item' => $item, 'info' => $info];
		}
	}
	return $lines;
}

/* Resumen por colega: [colega_id => {name, lines, orders, base, amount, payout}]. */
function mjc_summarize($lines) {
	$summary = [];
	foreach ($lines as $line) {
		$id = $line['info']['colega_id'];
		if (!isset($summary[$id])) {
			$summary[$id] = [
				'colega_id' => $id,
				'name'      => $line['info']['name'],
				'house'     => $line['info']['house'],
				'lines'     => 0,
				'orders'    => [],
				'base'      => 0.0,
				'amount'    => 0.0,
				'payout'    => 0.0,
			];
		}
		$summary[$id]['lines']++;
		$summary[$id]['orders'][$line['order']->get_id()] = true;
		$summary[$id]['base']   += $line['info']['base'];
		$summary[$id]['amount'] += $line['info']['amount'];
		$summary[$id]['payout'] += $line['info']['payout'];
	}
	uasort($summary, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
	return $summary;
}

function mjc_settlements() {
	$list = get_option('mjc_settlements', []);
	return is_array($list) ? $list : [];
}

/* -------------------------------------------------------------------------
 * Acciones: liquidar, deshacer, exportar
 * ---------------------------------------------------------------------- */
add_action('admin_post_mjc_settle', function () {
	if (!current_user_can('manage_woocommerce')) {
		wp_die('Sin permisos.');
	}
	check_admin_referer('mjc_settle');

	$colega = isset($_POST['colega']) ? (int) $_POST['colega'] : 0;
	$from   = isset($_POST['from']) ? sanitize_text_field(wp_unslash($_POST['from'])) : '';
	$to     = isset($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : '';
	$note   = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
	if ($colega <= 0 || !mjc_valid_date($from) || !mjc_valid_date($to)) {
		wp_safe_redirect(add_query_arg(['page' => 'mjc-liquidaciones', 'mjc_msg' => 'invalid'], admin_url('admin.php')));
		exit;
	}

	$lines = mjc_collect_lines($from, $to, $colega, true);
	if (!$lines) {
		wp_safe_redirect(add_query_arg(['page' => 'mjc-liquidaciones', 'from' => $from, 'to' => $to, 'mjc_msg' => 'empty'], admin_url('admin.php')));
		exit;
	}

	$settlements = mjc_settlements();
	$id          = (int) get_option('mjc_settlement_seq', 0) + 1;
	update_option('mjc_settlement_seq', $id, false);

	$record = [
		'id'        => $id,
		'colega_id' => $colega,
		'name'      => $lines[0]['info']['name'],
		'from'      => $from,
		'to'        => $to,
		'created'   => current_time('mysql'),
		'user'      => get_current_user_id(),
		'note'      => $note,
		'base'      => 0.0,
		'amount'    => 0.0,
		'payout'    => 0.0,
		'lines'     => [],
	];
	foreach ($lines as $line) {
		$line['item']->update_meta_data('_mjc_settled', $id);
		$line['item']->save();
		$record['base']   += $line['info']['base'];
		$record['amount'] += $line['info']['amount'];
		$record['payout'] += $line['info']['payout'];
		$record['lines'][] = [
			'order_id' => $line['order']->get_id(),
			'item_id'  => $line['item']->get_id(),
			'name'     => $line['item']->get_name(),
			'qty'      => $line['item']->get_quantity(),
			'base'     => $line['info']['base'],
			'amount'   => $line['info']['amount'],
			'payout'   => $line['info']['payout'],
		];
	}
	$record['base']   = round($record['base'], 2);
	$record['amount'] = round($record['amount'], 2);
	$record['payout'] = round($record['payout'], 2);

	$settlements[$id] = $record;
	update_option('mjc_settlements', $settlements, false);

	wp_safe_redirect(add_query_arg(['page' => 'mjc-liquidaciones', 'from' => $from, 'to' => $to, 'mjc_msg' => 'settled', 'mjc_id' => $id], admin_url('admin.php')));
	exit;
});

add_action('admin_post_mjc_unsettle', function () {
	if (!current_user_can('manage_woocommerce')) {
		wp_die('Sin permisos.');
	}
	check_admin_referer('mjc_unsettle');
	$id          = isset($_POST['id']) ? (int) $_POST['id'] : 0;
	$settlements = mjc_settlements();
	if ($id && isset($settlements[$id])) {
		foreach ($settlements[$id]['lines'] as $line) {
			$order = wc_get_order((int) $line['order_id']);
			if (!$order) {
				continue;
			}
			$item = $order->get_item((int) $line['item_id']);
			if ($item && (int) $item->get_meta('_mjc_settled', true) === $id) {
				$item->delete_meta_data('_mjc_settled');
				$item->save();
			}
		}
		unset($settlements[$id]);
		update_option('mjc_settlements', $settlements, false);
	}
	wp_safe_redirect(add_query_arg(['page' => 'mjc-liquidaciones', 'mjc_msg' => 'unsettled'], admin_url('admin.php')));
	exit;
});

add_action('admin_post_mjc_export', function () {
	if (!current_user_can('manage_woocommerce')) {
		wp_die('Sin permisos.');
	}
	check_admin_referer('mjc_export');
	$args   = mjc_report_args();
	$lines  = mjc_collect_lines($args['from'], $args['to'], $args['colega'], $args['pending']);
	$plural = mjc_opt('label_plural');

	nocache_headers();
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="liquidacion-' . sanitize_title($plural) . '-' . $args['from'] . '-' . $args['to'] . '.csv"');
	$out = fopen('php://output', 'w');
	fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel abre bien los acentos.
	fputcsv($out, ['Pedido', 'Fecha', 'Estado', 'Cliente', mjc_opt('label_singular'), 'Producto', 'Cantidad', 'Base', 'Comisión %', 'Comisión', 'A pagar', 'Liquidación'], ';');
	foreach ($lines as $line) {
		$order = $line['order'];
		$info  = $line['info'];
		fputcsv($out, [
			$order->get_order_number(),
			$order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : '',
			wc_get_order_status_name($order->get_status()),
			$order->get_formatted_billing_full_name(),
			$info['name'],
			$line['item']->get_name(),
			$line['item']->get_quantity(),
			number_format($info['base'], 2, ',', ''),
			number_format($info['commission'], 2, ',', ''),
			number_format($info['amount'], 2, ',', ''),
			number_format($info['payout'], 2, ',', ''),
			$info['settled'] ? '#' . $info['settled'] : '',
		], ';');
	}
	fclose($out);
	exit;
});

/* -------------------------------------------------------------------------
 * Página
 * ---------------------------------------------------------------------- */
function mjc_valid_date($date) {
	return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) && false !== strtotime($date);
}

/* Filtros de la página (GET), con defaults: mes en curso, solo colegas, solo pendientes. */
function mjc_report_args() {
	$from    = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
	$to      = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';
	$colega  = isset($_GET['colega']) ? (int) $_GET['colega'] : -1;
	$pending = !isset($_GET['mostrar']) || 'todas' !== sanitize_key(wp_unslash($_GET['mostrar']));
	return [
		'from'    => mjc_valid_date($from) ? $from : wp_date('Y-m-01'),
		'to'      => mjc_valid_date($to) ? $to : wp_date('Y-m-d'),
		'colega'  => $colega,
		'pending' => $pending,
	];
}

function mjc_render_report_page() {
	if (!current_user_can('manage_woocommerce')) {
		return;
	}
	$args     = mjc_report_args();
	$plural   = mjc_opt('label_plural');
	$singular = mjc_opt('label_singular');
	$lines    = mjc_collect_lines($args['from'], $args['to'], $args['colega'], $args['pending']);
	$summary  = mjc_summarize($lines);
	$colegas  = mjc_all_colegas();
	$msg      = isset($_GET['mjc_msg']) ? sanitize_key(wp_unslash($_GET['mjc_msg'])) : '';
	$export   = wp_nonce_url(add_query_arg([
		'action'  => 'mjc_export',
		'from'    => $args['from'],
		'to'      => $args['to'],
		'colega'  => $args['colega'],
		'mostrar' => $args['pending'] ? 'pendientes' : 'todas',
	], admin_url('admin-post.php')), 'mjc_export');
	$pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.') . '%';
	?>
	<div class="wrap">
		<h1>Liquidaciones — <?php echo esc_html($plural); ?></h1>

		<?php if ('settled' === $msg) : ?>
			<div class="notice notice-success is-dismissible"><p>Liquidación #<?php echo isset($_GET['mjc_id']) ? (int) $_GET['mjc_id'] : ''; ?> registrada. Esas ventas ya no aparecen como pendientes.</p></div>
		<?php elseif ('unsettled' === $msg) : ?>
			<div class="notice notice-success is-dismissible"><p>Liquidación deshecha: sus ventas vuelven a estar pendientes.</p></div>
		<?php elseif ('empty' === $msg) : ?>
			<div class="notice notice-warning is-dismissible"><p>No había ventas pendientes para liquidar en ese período.</p></div>
		<?php elseif ('invalid' === $msg) : ?>
			<div class="notice notice-error is-dismissible"><p>Datos inválidos: revisá la <?php echo esc_html(strtolower($singular)); ?> y las fechas.</p></div>
		<?php endif; ?>

		<form method="get" style="display:flex; flex-wrap:wrap; gap:10px 16px; align-items:flex-end; margin:16px 0 24px; padding:14px 16px; background:#fff; border:1px solid #c3c4c7; border-radius:6px;">
			<input type="hidden" name="page" value="mjc-liquidaciones" />
			<label>Desde<br><input type="date" name="from" value="<?php echo esc_attr($args['from']); ?>" /></label>
			<label>Hasta<br><input type="date" name="to" value="<?php echo esc_attr($args['to']); ?>" /></label>
			<label><?php echo esc_html($singular); ?><br>
				<select name="colega">
					<option value="-1" <?php selected($args['colega'], -1); ?>>Todas las <?php echo esc_html(strtolower($plural)); ?></option>
					<option value="0" <?php selected($args['colega'], 0); ?>>Todo, incluidos los propios</option>
					<?php foreach ($colegas as $term) : ?>
						<option value="<?php echo (int) $term->term_id; ?>" <?php selected($args['colega'], (int) $term->term_id); ?>><?php echo esc_html($term->name); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>Mostrar<br>
				<select name="mostrar">
					<option value="pendientes" <?php selected($args['pending'], true); ?>>Solo pendientes de liquidar</option>
					<option value="todas" <?php selected($args['pending'], false); ?>>Todas (incluye liquidadas)</option>
				</select>
			</label>
			<button class="button button-primary">Ver</button>
			<a class="button" href="<?php echo esc_url($export); ?>">Exportar CSV</a>
			<span class="description" style="flex-basis:100%;">Pedidos en estado: <?php echo esc_html(implode(', ', array_map(fn ($s) => wc_get_order_status_name($s), mjc_sale_statuses()))); ?>. La comisión se calcula sobre <?php echo 'subtotal' === mjc_opt('commission_base') ? 'el precio de lista' : 'lo que pagó el cliente por el producto'; ?>; el envío no entra.</span>
		</form>

		<h2>Resumen por <?php echo esc_html(strtolower($singular)); ?></h2>
		<?php if (!$summary) : ?>
			<p>No hay ventas <?php echo $args['pending'] ? 'pendientes' : ''; ?> en este período con los filtros elegidos.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th><?php echo esc_html($singular); ?></th>
						<th style="text-align:right;">Pedidos</th>
						<th style="text-align:right;">Líneas</th>
						<th style="text-align:right;">Vendido</th>
						<th style="text-align:right;">Tu comisión</th>
						<th style="text-align:right;">A pagar</th>
						<th>Cómo pagarle</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$tot = ['base' => 0.0, 'amount' => 0.0, 'payout' => 0.0];
					foreach ($summary as $row) :
						$tot['base']   += $row['base'];
						$tot['amount'] += $row['amount'];
						$tot['payout'] += $row['payout'];
						$payout_info    = $row['house'] ? '' : (string) get_term_meta($row['colega_id'], 'mjc_payout', true);
						?>
						<tr>
							<td><strong><?php echo esc_html($row['name']); ?></strong><?php echo $row['house'] ? ' <span style="color:#787c82;">(propios, sin comisión)</span>' : ''; ?></td>
							<td style="text-align:right;"><?php echo count($row['orders']); ?></td>
							<td style="text-align:right;"><?php echo (int) $row['lines']; ?></td>
							<td style="text-align:right;"><?php echo wp_kses_post(wc_price($row['base'])); ?></td>
							<td style="text-align:right;"><?php echo $row['house'] ? '—' : wp_kses_post(wc_price($row['amount'])); ?></td>
							<td style="text-align:right;"><strong><?php echo $row['house'] ? '—' : wp_kses_post(wc_price($row['payout'])); ?></strong></td>
							<td style="white-space:pre-line; font-size:12px;"><?php echo esc_html($payout_info); ?></td>
							<td>
								<?php if (!$row['house'] && $args['pending'] && $row['payout'] > 0) : ?>
									<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('¿Registrar la liquidación de <?php echo esc_js($row['name']); ?> por <?php echo esc_js(wp_strip_all_tags(wc_price($row['payout']))); ?>? Esas ventas dejan de estar pendientes.');" style="display:flex; gap:6px; align-items:center;">
										<?php wp_nonce_field('mjc_settle'); ?>
										<input type="hidden" name="action" value="mjc_settle" />
										<input type="hidden" name="colega" value="<?php echo (int) $row['colega_id']; ?>" />
										<input type="hidden" name="from" value="<?php echo esc_attr($args['from']); ?>" />
										<input type="hidden" name="to" value="<?php echo esc_attr($args['to']); ?>" />
										<input type="text" name="note" placeholder="Nota (ej. transferencia 12/09)" style="width:170px;" />
										<button class="button">Marcar como liquidado</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th>Total</th>
						<th></th>
						<th></th>
						<th style="text-align:right;"><?php echo wp_kses_post(wc_price($tot['base'])); ?></th>
						<th style="text-align:right;"><?php echo wp_kses_post(wc_price($tot['amount'])); ?></th>
						<th style="text-align:right;"><?php echo wp_kses_post(wc_price($tot['payout'])); ?></th>
						<th></th>
						<th></th>
					</tr>
				</tfoot>
			</table>
		<?php endif; ?>

		<?php if ($lines) : ?>
			<h2 style="margin-top:32px;">Detalle</h2>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th>Pedido</th>
						<th>Fecha</th>
						<th>Estado</th>
						<th><?php echo esc_html($singular); ?></th>
						<th>Producto</th>
						<th style="text-align:right;">Cant.</th>
						<th style="text-align:right;">Base</th>
						<th style="text-align:right;">%</th>
						<th style="text-align:right;">Comisión</th>
						<th style="text-align:right;">A pagar</th>
						<th>Liquidación</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($lines as $line) :
						$order = $line['order'];
						$info  = $line['info'];
						?>
						<tr>
							<td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a></td>
							<td><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/Y') : ''); ?></td>
							<td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
							<td><?php echo esc_html($info['name']); ?><?php echo $info['live'] ? ' <span title="Pedido anterior al plugin: se calcula con la asignación y el porcentaje actuales" style="color:#787c82;">*</span>' : ''; ?></td>
							<td><?php echo esc_html($line['item']->get_name()); ?></td>
							<td style="text-align:right;"><?php echo (int) $line['item']->get_quantity(); ?></td>
							<td style="text-align:right;"><?php echo wp_kses_post(wc_price($info['base'])); ?></td>
							<td style="text-align:right;"><?php echo $info['house'] ? '—' : esc_html($pct($info['commission'])); ?></td>
							<td style="text-align:right;"><?php echo $info['house'] ? '—' : wp_kses_post(wc_price($info['amount'])); ?></td>
							<td style="text-align:right;"><?php echo $info['house'] ? '—' : wp_kses_post(wc_price($info['payout'])); ?></td>
							<td><?php echo $info['settled'] ? '<span style="color:#00a32a;">#' . (int) $info['settled'] . '</span>' : '<span style="color:#787c82;">pendiente</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">* Pedido anterior al plugin: se calcula con la asignación y el porcentaje actuales.</p>
		<?php endif; ?>

		<?php $history = array_reverse(mjc_settlements(), true); ?>
		<h2 style="margin-top:32px;">Historial de liquidaciones</h2>
		<?php if (!$history) : ?>
			<p>Todavía no registraste ninguna liquidación.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th>#</th>
						<th>Fecha</th>
						<th><?php echo esc_html($singular); ?></th>
						<th>Período</th>
						<th style="text-align:right;">Vendido</th>
						<th style="text-align:right;">Comisión</th>
						<th style="text-align:right;">Pagado</th>
						<th>Nota</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($history as $rec) : ?>
						<tr>
							<td><?php echo (int) $rec['id']; ?></td>
							<td><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($rec['created']))); ?></td>
							<td><?php echo esc_html($rec['name']); ?></td>
							<td><?php echo esc_html(date_i18n('d/m/Y', strtotime($rec['from'])) . ' – ' . date_i18n('d/m/Y', strtotime($rec['to']))); ?> · <?php echo count($rec['lines']); ?> líneas</td>
							<td style="text-align:right;"><?php echo wp_kses_post(wc_price($rec['base'])); ?></td>
							<td style="text-align:right;"><?php echo wp_kses_post(wc_price($rec['amount'])); ?></td>
							<td style="text-align:right;"><strong><?php echo wp_kses_post(wc_price($rec['payout'])); ?></strong></td>
							<td><?php echo esc_html($rec['note']); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('¿Deshacer la liquidación #<?php echo (int) $rec['id']; ?>? Sus ventas vuelven a estar pendientes.');">
									<?php wp_nonce_field('mjc_unsettle'); ?>
									<input type="hidden" name="action" value="mjc_unsettle" />
									<input type="hidden" name="id" value="<?php echo (int) $rec['id']; ?>" />
									<button class="button-link" style="color:#b32d2e;">Deshacer</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
