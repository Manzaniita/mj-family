<?php
/**
 * wp-admin: la bandeja de comprobantes y su pantalla de ajustes.
 *
 *   WooCommerce → Comprobantes            lo que falta revisar, con el contador en el menú
 *   WooCommerce → Comprobantes → Ajustes  regla general, regla por medio de pago y
 *                                         equivalencias con MJ ERP
 *
 * Además mete la caja de revisión dentro de la pantalla del pedido, así se puede
 * aprobar al paso sin salir de donde ya estabas.
 */

defined('ABSPATH') || exit;

class MJPM_Receipts_Admin {

	const SLUG = 'mj-comprobantes';

	/**
	 * @var bool Se está dibujando dentro de la pantalla del pedido. Esa pantalla
	 *           ya es un <form> y el navegador ignora un <form> adentro de otro:
	 *           los botones mandarían el pedido. Ahí las cajas van como <div> y
	 *           receipts-admin.js arma el formulario al apretar.
	 */
	private static $nested = false;

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu'], 20);
		add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);

		add_action('admin_post_mjpm_receipt_review', [__CLASS__, 'handle_review']);
		add_action('admin_post_mjpm_receipt_object', [__CLASS__, 'handle_object']);
		add_action('admin_post_mjpm_receipt_delete', [__CLASS__, 'handle_delete']);
		add_action('admin_post_mjpm_save_receipts', [__CLASS__, 'handle_save_settings']);
		add_action('admin_post_mjpm_save_receipt_methods', [__CLASS__, 'handle_save_methods']);
		add_action('admin_post_mjpm_save_mapping', [__CLASS__, 'handle_save_mapping']);

		/* Caja en la pantalla del pedido y columna en la lista de pedidos. */
		add_action('add_meta_boxes', [__CLASS__, 'order_metabox'], 30);
		add_filter('manage_edit-shop_order_columns', [__CLASS__, 'order_column'], 20);
		add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'order_column'], 20);
		add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'order_column_content'], 20, 2);
		add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'order_column_content_hpos'], 20, 2);

		/* Los comprobantes se van con el pedido. */
		add_action('woocommerce_delete_order', [__CLASS__, 'on_order_deleted'], 10, 1);
		add_action('woocommerce_trash_order', [__CLASS__, 'on_order_deleted'], 10, 1);
	}

	public static function page_url($args = []) {
		return add_query_arg($args, admin_url('admin.php?page=' . self::SLUG));
	}

	/* -------------------------------- Menú --------------------------------- */

	public static function menu() {
		if (!MJPM_Receipts::can_review()) {
			return;
		}
		$pending = MJPM_Install::table_exists() ? MJPM_Receipts::count_pending() : 0;
		$plural  = MJPM_Receipts::noun(true, true);
		$title   = '🧾 ' . $plural;
		if ($pending > 0) {
			$title .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $pending . '</span></span>';
		}

		/* Con WooCommerce va bajo su menú; sin WooCommerce (una tienda de solo
		   turnos) necesita el suyo, o no habría forma de llegar a la bandeja. */
		if (class_exists('WooCommerce')) {
			add_submenu_page('woocommerce', $plural . ' de pago', $title, self::capability(), self::SLUG, [__CLASS__, 'render']);
			return;
		}
		add_menu_page($plural . ' de pago', $title, self::capability(), self::SLUG, [__CLASS__, 'render'], 'dashicons-media-text', 57);
	}

	/**
	 * Capability mínima del menú. El permiso fino lo decide
	 * MJPM_Receipts::can_review(), que mira los ajustes; acá solo hace falta un
	 * valor que deje pasar a quien ya autorizamos.
	 */
	private static function capability() {
		if (current_user_can('manage_options')) {
			return 'manage_options';
		}
		if (MJPM_Settings::receipts('cap_shop') && current_user_can('manage_woocommerce')) {
			return 'manage_woocommerce';
		}
		if (MJPM_Settings::receipts('cap_erp') && current_user_can('manage_mjerp')) {
			return 'manage_mjerp';
		}
		return 'manage_options';
	}

	public static function assets($hook) {
		/* El hook cambia según dónde terminó el menú (bajo WooCommerce o propio). */
		$ours = in_array($hook, ['woocommerce_page_' . self::SLUG, 'toplevel_page_' . self::SLUG], true);
		if (!$ours && !self::is_order_screen()) {
			return;
		}
		/* El widget de subida: acá también se puede adjuntar un comprobante a mano. */
		if (MJPM_Receipts::enabled()) {
			MJPM_Receipts_Front::enqueue();
		}

		wp_enqueue_style('mjpm-receipts-admin', MJPM_URL . 'assets/receipts-admin.css', [], MJPM_VERSION);
		wp_enqueue_script('mjpm-receipts-admin', MJPM_URL . 'assets/receipts-admin.js', [], MJPM_VERSION, true);
		wp_localize_script('mjpm-receipts-admin', 'mjpmReceiptsAdmin', [
			'confirmDelete' => sprintf('¿Borrar este %s? Se borra también el archivo y no se puede deshacer.', MJPM_Receipts::noun()),
			'needReason'    => 'Escribí el motivo del rechazo: es lo que ve el cliente.',
			'needAmount'    => 'Poné cuánto entró con este pago.',
		]);
	}

	private static function is_order_screen() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen) {
			return false;
		}
		return in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders', 'edit-shop_order'], true);
	}

	/* ------------------------------ Guardado ------------------------------- */

	private static function guard($action) {
		check_admin_referer($action);
		if (!MJPM_Receipts::can_review()) {
			wp_die('No tenés permiso para revisar comprobantes.');
		}
	}

	private static function back($args, $url = '') {
		wp_safe_redirect('' !== $url ? add_query_arg($args, $url) : self::page_url($args));
		exit;
	}

	/** Vuelve a donde estabas (bandeja o pantalla del pedido). */
	private static function back_to_origin($args) {
		$origin = isset($_POST['_mjpm_origin']) ? esc_url_raw(wp_unslash($_POST['_mjpm_origin'])) : '';
		if ('' !== $origin) {
			self::back($args, $origin);
		}
		self::back($args);
	}

	/** Decisión sobre un archivo. */
	public static function handle_review() {
		self::guard('mjpm_receipt_review');
		$id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
		$decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
		$reason   = isset($_POST['motivo']) ? sanitize_text_field(wp_unslash($_POST['motivo'])) : '';

		$result = MJPM_Receipts::review($id, $decision, $reason);
		self::back_to_origin(['mjpm_msg' => is_wp_error($result) ? 'error' : 'revisado']);
	}

	/** Decisión sobre la compra. Al aprobar con saldo: todo lo que falta o una parte. */
	public static function handle_object() {
		self::guard('mjpm_receipt_object');
		$context   = isset($_POST['context']) ? sanitize_key(wp_unslash($_POST['context'])) : '';
		$object_id = isset($_POST['objeto']) ? (int) $_POST['objeto'] : 0;
		$decision  = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
		$reason    = isset($_POST['motivo']) ? sanitize_text_field(wp_unslash($_POST['motivo'])) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- El nonce lo verifica self::guard(), arriba.
		$pago = isset($_POST['pago']) ? sanitize_key(wp_unslash($_POST['pago'])) : '';

		$amount = null;
		if (MJPM_Receipts::STATUS_OK === $decision && 'parcial' === $pago) {
			$decision = MJPM_Receipts::STATUS_PARTIAL;
			$amount   = isset($_POST['importe']) ? MJPM_Settings::decimal(sanitize_text_field(wp_unslash($_POST['importe']))) : 0.0;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = MJPM_Receipts::review_object($context, $object_id, $decision, $reason, true, $amount);
		if (is_wp_error($result)) {
			self::back_to_origin(['mjpm_msg' => 'mjpm_no_amount' === $result->get_error_code() ? 'sin_importe' : 'error']);
		}
		/* El mensaje sale del estado final: una "parte" que cubre el saldo queda como pago total. */
		$messages = [
			MJPM_Receipts::STATUS_OK      => 'aprobada',
			MJPM_Receipts::STATUS_PARTIAL => 'parcial',
			MJPM_Receipts::STATUS_NO      => 'rechazada',
		];
		$state = MJPM_Receipts::state($context, $object_id);
		self::back_to_origin(['mjpm_msg' => isset($messages[$state]) ? $messages[$state] : 'revisado']);
	}

	public static function handle_delete() {
		self::guard('mjpm_receipt_delete');
		$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
		MJPM_Receipts::delete($id);
		self::back_to_origin(['mjpm_msg' => 'borrado']);
	}

	public static function handle_save_settings() {
		check_admin_referer('mjpm_save_receipts');
		if (!current_user_can('manage_options')) {
			wp_die('Sin permisos.');
		}
		$in = isset($_POST['receipts']) && is_array($_POST['receipts']) ? wp_unslash($_POST['receipts']) : [];

		/* Las casillas ausentes son ceros explícitos: el formulario manda todo. */
		foreach (['enabled', 'ask_amount', 'ask_reference', 'ask_note', 'optimize', 'attach', 'show_account', 'guest_links', 'cap_shop', 'cap_erp', 'mail_new', 'mail_ok', 'mail_no', 'whatsapp', 'mail_partial', 'mail_summary'] as $flag) {
			$in[$flag] = empty($in[$flag]) ? 0 : 1;
		}
		$in['types'] = isset($_POST['receipt_types']) && is_array($_POST['receipt_types'])
			? implode(',', array_map('sanitize_key', wp_unslash($_POST['receipt_types'])))
			: '';

		MJPM_Settings::update_receipts($in);
		MJPM_Install::schedule();
		self::back(['tab' => 'ajustes', 'mjpm_msg' => 'ajustes']);
	}

	public static function handle_save_methods() {
		check_admin_referer('mjpm_save_receipt_methods');
		if (!current_user_can('manage_options')) {
			wp_die('Sin permisos.');
		}
		$posted = isset($_POST['methods']) && is_array($_POST['methods']) ? wp_unslash($_POST['methods']) : [];
		$all    = MJPM_Settings::methods();

		foreach (MJPM_Methods::gateways() as $id => $gateway) {
			if (!isset($posted[$id]) || !is_array($posted[$id])) {
				continue;
			}
			/* Se conserva todo lo que no es del comprobante (ajuste de precio, etiquetas…). */
			$current      = isset($all[$id]) ? $all[$id] : MJPM_Settings::method_defaults();
			$current      = array_merge($current, array_intersect_key($posted[$id], MJPM_Settings::method_defaults()));
			$all[$id]     = MJPM_Settings::sanitize_method($current);
		}
		MJPM_Settings::update_methods($all);
		self::back(['tab' => 'ajustes', 'mjpm_msg' => 'metodos']);
	}

	public static function handle_save_mapping() {
		check_admin_referer('mjpm_save_mapping');
		if (!current_user_can('manage_options')) {
			wp_die('Sin permisos.');
		}
		$map = isset($_POST['mapping']) && is_array($_POST['mapping']) ? wp_unslash($_POST['mapping']) : [];
		MJPM_Mapping::update($map);
		self::back(['tab' => 'ajustes', 'mjpm_msg' => 'mapeo']);
	}

	/* -------------------------- Pedido: caja y columna --------------------- */

	public static function order_metabox() {
		if (!MJPM_Receipts::enabled() || !MJPM_Receipts::can_review()) {
			return;
		}
		/* Pedidos en posts (clásico) y en tablas propias (HPOS): la caja va en los dos. */
		$screens = ['shop_order'];
		if (function_exists('wc_get_page_screen_id')) {
			$screens[] = wc_get_page_screen_id('shop-order');
		}

		foreach (array_unique(array_filter($screens)) as $id) {
			add_meta_box(
				'mjpm-receipts',
				MJPM_Receipts::noun(true, true) . ' de pago',
				[__CLASS__, 'render_order_box'],
				$id,
				'normal',
				'high'
			);
		}
	}

	public static function render_order_box($post_or_order) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
		if (!$order instanceof WC_Order) {
			return;
		}
		$req = MJPM_Receipts::requirement('order', $order->get_id());
		$rows = MJPM_Receipts::for_object('order', $order->get_id());

		if (!$req['asks'] && !$rows) {
			printf(
				'<p class="mjpm-rc-empty">Este medio de pago no pide %s. <a href="%s">Cambiar la regla</a></p>',
				esc_html(MJPM_Receipts::noun()),
				esc_url(self::page_url(['tab' => 'ajustes']))
			);
			return;
		}
		self::render_object_panel('order', $order->get_id(), $rows, true);
	}

	public static function order_column($columns) {
		if (!is_array($columns) || !MJPM_Receipts::enabled()) {
			return $columns;
		}
		$out = [];
		foreach ($columns as $key => $label) {
			$out[$key] = $label;
			if ('order_status' === $key) {
				$out['mjpm_receipt'] = MJPM_Receipts::noun(false, true);
			}
		}
		if (!isset($out['mjpm_receipt'])) {
			$out['mjpm_receipt'] = MJPM_Receipts::noun(false, true);
		}
		return $out;
	}

	public static function order_column_content($column, $post_id) {
		if ('mjpm_receipt' !== $column) {
			return;
		}
		self::print_order_state(wc_get_order((int) $post_id));
	}

	public static function order_column_content_hpos($column, $order) {
		if ('mjpm_receipt' !== $column) {
			return;
		}
		self::print_order_state($order instanceof WC_Order ? $order : wc_get_order($order));
	}

	private static function print_order_state($order) {
		if (!$order instanceof WC_Order) {
			return;
		}
		$state = MJPM_Receipts::state('order', $order->get_id());
		if ('' === $state) {
			echo '<span class="mjpm-rc-dash">—</span>';
			return;
		}
		$count = count(MJPM_Receipts::for_object('order', $order->get_id()));
		printf(
			'<a class="mjpm-rc-pill is-%s" href="%s">%s</a>%s',
			esc_attr($state),
			esc_url($order->get_edit_order_url()),
			esc_html(MJPM_Receipts::status_label($state)),
			$count > 1 ? ' <small>×' . (int) $count . '</small>' : ''
		);
	}

	public static function on_order_deleted($order_id) {
		MJPM_Receipts::delete_for_object('order', (int) $order_id);
	}

	/* ------------------------------- Página -------------------------------- */

	public static function render() {
		if (!MJPM_Receipts::can_review()) {
			wp_die('No tenés permiso para revisar comprobantes.');
		}
		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'bandeja';
		$msg = isset($_GET['mjpm_msg']) ? sanitize_key(wp_unslash($_GET['mjpm_msg'])) : '';

		$messages = [
			'revisado'  => 'Decisión guardada.',
			'aprobada'  => 'Pago aprobado: el pedido quedó pagado completo.',
			'parcial'   => 'Pago parcial registrado. El cliente ve cuánto pagó y cuánto falta abonar.',
			'sin_importe' => 'Para un pago parcial poné cuánto entró.',
			'rechazada' => 'Compra rechazada. El cliente recibe el aviso con el motivo.',
			'borrado'   => 'Comprobante borrado.',
			'ajustes'   => 'Ajustes guardados.',
			'metodos'   => 'Regla por medio de pago guardada.',
			'mapeo'     => 'Equivalencias guardadas.',
			'error'     => 'No se pudo guardar. Probá de nuevo.',
		];
		?>
		<div class="wrap mjpm-rc-wrap">
			<h1 class="mjpm-rc-title">🧾 <?php echo esc_html(MJPM_Receipts::noun(true, true) . ' de pago'); ?></h1>

			<?php if (isset($messages[$msg])) : ?>
				<div class="notice <?php echo 'error' === $msg ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($messages[$msg]); ?></p></div>
			<?php endif; ?>

			<?php if (!MJPM_Install::table_exists()) : ?>
				<div class="notice notice-error"><p><strong>Falta la tabla de comprobantes.</strong> Desactivá y volvé a activar MJ Medios de Pago para crearla.</p></div>
			<?php elseif (!MJPM_Settings::receipts('enabled')) : ?>
				<div class="notice notice-warning"><p><strong>Los comprobantes están apagados.</strong> Prendelos en <a href="<?php echo esc_url(self::page_url(['tab' => 'ajustes'])); ?>">Ajustes</a>.</p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper mjpm-rc-tabs">
				<a class="nav-tab <?php echo 'ajustes' === $tab ? '' : 'nav-tab-active'; ?>" href="<?php echo esc_url(self::page_url()); ?>">
					A revisar
					<?php $pending = MJPM_Install::table_exists() ? MJPM_Receipts::count_pending() : 0; ?>
					<?php if ($pending > 0) : ?><span class="mjpm-rc-count"><?php echo (int) $pending; ?></span><?php endif; ?>
				</a>
				<?php if (current_user_can('manage_options')) : ?>
					<a class="nav-tab <?php echo 'ajustes' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::page_url(['tab' => 'ajustes'])); ?>">Ajustes</a>
				<?php endif; ?>
			</nav>

			<?php
			if ('ajustes' === $tab && current_user_can('manage_options')) {
				MJPM_Receipts_Settings_Screen::render();
			} else {
				self::render_inbox();
			}
			?>
		</div>
		<?php
	}

	/* ------------------------------- Bandeja ------------------------------- */

	private static function render_inbox() {
		if (!MJPM_Install::table_exists()) {
			return;
		}
		$status  = isset($_GET['estado']) ? sanitize_key(wp_unslash($_GET['estado'])) : MJPM_Receipts::STATUS_PENDING;
		$context = isset($_GET['contexto']) ? sanitize_key(wp_unslash($_GET['contexto'])) : '';
		$search  = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
		$page    = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

		if ('todos' === $status) {
			$status = '';
		}
		$summary  = MJPM_Receipts::summary($context);
		$result   = MJPM_Receipts::query([
			'status'   => $status,
			'context'  => $context,
			'search'   => $search,
			'page'     => $page,
			'per_page' => 20,
		]);
		$contexts = MJPM_Receipts::contexts();
		?>
		<ul class="subsubsub mjpm-rc-filters">
			<?php
			$filters = ['todos' => 'Todos'] + MJPM_Receipts::statuses();
			$i       = 0;
			foreach ($filters as $key => $label) :
				$current = ('todos' === $key && '' === $status) || $key === $status;
				$count   = isset($summary[$key]) ? (int) $summary[$key] : 0;
				?>
				<li>
					<a href="<?php echo esc_url(self::page_url(array_filter(['estado' => $key, 'contexto' => $context]))); ?>" class="<?php echo $current ? 'current' : ''; ?>">
						<?php echo esc_html($label); ?> <span class="count">(<?php echo (int) $count; ?>)</span>
					</a>
					<?php echo ++$i < count($filters) ? ' |' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<form method="get" class="mjpm-rc-search">
			<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
			<input type="hidden" name="estado" value="<?php echo esc_attr('' === $status ? 'todos' : $status); ?>">
			<?php if (count($contexts) > 1) : ?>
				<select name="contexto">
					<option value="">Todo</option>
					<?php foreach ($contexts as $key => $ctx) : ?>
						<option value="<?php echo esc_attr($key); ?>" <?php selected($context, $key); ?>><?php echo esc_html($ctx['label']); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Nº de pedido, referencia…">
			<?php submit_button('Buscar', 'secondary', '', false); ?>
		</form>

		<?php if (!$result['rows']) : ?>
			<div class="mjpm-rc-blank">
				<p><strong>No hay nada para revisar acá.</strong></p>
				<p>Cuando un cliente suba un <?php echo esc_html(MJPM_Receipts::noun()); ?>, aparece en esta lista.</p>
			</div>
			<?php
			return;
		endif;

		/* Se agrupan por compra: una transferencia partida en dos se decide junta. */
		$groups = [];
		foreach ($result['rows'] as $row) {
			$key = $row['context'] . ':' . $row['object_id'];
			if (!isset($groups[$key])) {
				$groups[$key] = ['context' => $row['context'], 'object_id' => $row['object_id'], 'rows' => []];
			}
			$groups[$key]['rows'][] = $row;
		}

		foreach ($groups as $group) {
			self::render_object_panel($group['context'], $group['object_id'], $group['rows'], false);
		}

		if ($result['pages'] > 1) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(paginate_links([
				'base'    => self::page_url(['estado' => '' === $status ? 'todos' : $status, 'contexto' => $context, 's' => $search, 'paged' => '%#%']),
				'format'  => '',
				'current' => $page,
				'total'   => (int) $result['pages'],
			]));
			echo '</div></div>';
		}
	}

	/**
	 * Panel de una compra: ficha, archivos y las dos decisiones.
	 *
	 * @param string $context   Contexto.
	 * @param int    $object_id Objeto.
	 * @param array  $rows      Comprobantes.
	 * @param bool   $inline    true dentro de la pantalla del pedido (sin ficha).
	 * @return void
	 */
	public static function render_object_panel($context, $object_id, array $rows, $inline = false) {
		$nested       = self::$nested;
		self::$nested = (bool) $inline;
		$object       = MJPM_Receipts::object($context, $object_id);
		$state  = MJPM_Receipts::state($context, $object_id);
		$origin = self::current_url();
		$noun   = MJPM_Receipts::noun();
		?>
		<div class="mjpm-rc-panel <?php echo $inline ? 'is-inline' : ''; ?>">
			<?php if (!$inline) : ?>
				<div class="mjpm-rc-panel__head">
					<div>
						<p class="mjpm-rc-panel__who">
							<strong><?php echo esc_html($object ? $object['customer'] : 'Sin datos'); ?></strong>
							<?php if ($object && '' !== $object['email']) : ?>
								<span class="mjpm-rc-panel__mail"><?php echo esc_html($object['email']); ?></span>
							<?php endif; ?>
						</p>
						<p class="mjpm-rc-panel__what">
							<?php echo esc_html(MJPM_Receipts::context_label($context)); ?>
							<?php if ($object && '' !== $object['admin_url']) : ?>
								<a href="<?php echo esc_url($object['admin_url']); ?>"><?php echo esc_html($object['title']); ?></a>
							<?php else : ?>
								<strong><?php echo esc_html($object ? $object['title'] : '#' . $object_id); ?></strong>
							<?php endif; ?>
							<?php if ($object && (float) $object['total'] > 0) : ?>
								· <?php echo esc_html(MJPM_Receipts::money((float) $object['total'])); ?>
							<?php endif; ?>
							<?php if ($object && '' !== $object['method_label']) : ?>
								· <?php echo esc_html($object['method_label']); ?>
							<?php endif; ?>
							<?php if ($object && '' !== $object['status_label']) : ?>
								· <?php echo esc_html($object['status_label']); ?>
							<?php endif; ?>
						</p>
					</div>
					<?php if ('' !== $state) : ?>
						<span class="mjpm-rc-pill is-<?php echo esc_attr($state); ?>"><?php echo esc_html(MJPM_Receipts::status_label($state)); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if (!$rows) : ?>
				<p class="mjpm-rc-empty">
					<?php printf('Todavía no subió el %s.', esc_html($noun)); ?>
					<?php $link = MJPM_Receipts::link($context, $object_id); ?>
					<?php if ('' !== $link) : ?>
						<a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener">Ver el link que le toca</a>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<ul class="mjpm-rc-files">
					<?php foreach ($rows as $row) : ?>
						<li class="mjpm-rc-file is-<?php echo esc_attr($row['status']); ?>">
							<div class="mjpm-rc-file__thumb">
								<?php if ($row['available'] && $row['is_image']) : ?>
									<a href="<?php echo esc_url(MJPM_Receipts::file_url($row)); ?>" target="_blank" rel="noopener">
										<img src="<?php echo esc_url(MJPM_Receipts::file_url($row)); ?>" alt="<?php echo esc_attr($row['file_name']); ?>">
									</a>
								<?php elseif ($row['available']) : ?>
									<a class="mjpm-rc-file__doc" href="<?php echo esc_url(MJPM_Receipts::file_url($row)); ?>" target="_blank" rel="noopener">PDF</a>
								<?php else : ?>
									<span class="mjpm-rc-file__doc is-gone" title="El archivo ya no está guardado">—</span>
								<?php endif; ?>
							</div>

							<div class="mjpm-rc-file__body">
								<p class="mjpm-rc-file__name">
									<?php echo esc_html($row['file_name']); ?>
									<span class="mjpm-rc-pill is-<?php echo esc_attr($row['status']); ?>"><?php echo esc_html($row['status_label']); ?></span>
								</p>
								<p class="mjpm-rc-file__meta">
									<?php if ('' !== $row['amount_text']) : ?>
										<span>Declaró <strong><?php echo esc_html($row['amount_text']); ?></strong></span>
									<?php endif; ?>
									<?php if ('' !== $row['reference']) : ?>
										<span>Ref. <?php echo esc_html($row['reference']); ?></span>
									<?php endif; ?>
									<?php if ('' !== $row['size_text']) : ?>
										<span><?php echo esc_html($row['size_text']); ?></span>
									<?php endif; ?>
									<?php if ('' !== $row['created_at']) : ?>
										<span><?php echo esc_html(date_i18n('j M Y H:i', strtotime($row['created_at']))); ?></span>
									<?php endif; ?>
									<?php if ($row['purged']) : ?>
										<span class="mjpm-rc-gone">Archivo borrado por retención</span>
									<?php endif; ?>
								</p>
								<?php if ('' !== $row['note']) : ?>
									<p class="mjpm-rc-file__note"><?php echo esc_html($row['note']); ?></p>
								<?php endif; ?>
								<?php if ('' !== $row['reason']) : ?>
									<p class="mjpm-rc-file__reason">Motivo: <?php echo esc_html($row['reason']); ?></p>
								<?php endif; ?>

								<div class="mjpm-rc-file__actions">
									<?php if (MJPM_Receipts::STATUS_OK !== $row['status']) : ?>
										<?php self::action_form('mjpm_receipt_review', ['id' => $row['id'], 'decision' => MJPM_Receipts::STATUS_OK], 'Aprobar este archivo', 'button', $origin); ?>
									<?php endif; ?>
									<?php if (MJPM_Receipts::STATUS_NO !== $row['status']) : ?>
										<?php self::reject_form('mjpm_receipt_review', ['id' => $row['id'], 'decision' => MJPM_Receipts::STATUS_NO], 'Rechazar este archivo', $origin); ?>
									<?php endif; ?>
									<?php if ($row['available']) : ?>
										<a class="button-link" href="<?php echo esc_url(MJPM_Receipts::file_url($row, true)); ?>">Descargar</a>
									<?php endif; ?>
									<?php self::action_form('mjpm_receipt_delete', ['id' => $row['id']], 'Borrar', 'button-link mjpm-rc-danger', $origin, true); ?>
								</div>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ($inline) : ?>
				<?php
				/* Adjuntar a mano lo que el cliente mandó por otro lado. */
				$req = MJPM_Receipts::requirement($context, $object_id);
				if ($req['asks'] && count($rows) < (int) $req['files']) :
					echo MJPM_Receipts_Front::widget($context, $object_id, '', [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						'compact' => true,
						'title'   => sprintf('Adjuntar un %s', $noun),
					]);
				endif;
				?>
			<?php endif; ?>

			<div class="mjpm-rc-decide">
				<p class="mjpm-rc-decide__title">
					<?php printf('¿Entró la plata de est%s %s?', esc_html(MJPM_Receipts::singular_gender($context)), esc_html(MJPM_Receipts::singular($context))); ?>
				</p>
				<p class="mjpm-rc-decide__help">Esta es la decisión que mueve la compra y le avisa al cliente. Los archivos de arriba se marcan solos con lo mismo.</p>
				<?php $balance = MJPM_Receipts::balance($context, $object_id); ?>
				<?php if ($balance) : ?>
					<?php self::balance_block($context, $object_id, $balance, $origin); ?>
				<?php endif; ?>
				<div class="mjpm-rc-decide__actions">
					<?php if (!$balance) : ?>
						<?php self::action_form('mjpm_receipt_object', ['context' => $context, 'objeto' => $object_id, 'decision' => MJPM_Receipts::STATUS_OK], 'Aprobar el pago', 'button button-primary', $origin); ?>
					<?php endif; ?>
					<?php self::reject_form('mjpm_receipt_object', ['context' => $context, 'objeto' => $object_id, 'decision' => MJPM_Receipts::STATUS_NO], 'Rechazar el pago', $origin); ?>
					<?php
					/* El mensaje de WhatsApp acompaña lo último que se decidió (parcial o rechazo); si no, el de aprobado. */
					$wa_state = in_array($state, [MJPM_Receipts::STATUS_PARTIAL, MJPM_Receipts::STATUS_NO], true) ? $state : MJPM_Receipts::STATUS_OK;
					$wa_last  = $balance && $balance['history'] ? end($balance['history']) : null;
					$whatsapp = MJPM_Receipts_Mail::whatsapp_url($context, $object_id, $wa_state, MJPM_Receipts::state_reason($context, $object_id), $wa_last ? $wa_last['amount'] : 0.0);
					if ('' !== $whatsapp) :
						?>
						<a class="button mjpm-rc-wa" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">WhatsApp</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		self::$nested = $nested;
	}

	/**
	 * Abre una caja que manda a admin-post.php: <form>, o <div> dentro del pedido.
	 *
	 * @param string $class Clases.
	 * @param array  $data  Atributos data-* (nombre => valor).
	 */
	private static function form_open($class, array $data = []) {
		$attrs = '';
		foreach ($data as $name => $value) {
			$attrs .= sprintf(' data-%s="%s"', esc_attr($name), esc_attr($value));
		}
		if (self::$nested) {
			printf('<div class="%s" data-mjpm-post="%s"%s>', esc_attr($class), esc_url(admin_url('admin-post.php')), $attrs); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs sale escapado de arriba.
			return;
		}
		printf('<form method="post" action="%s" class="%s"%s>', esc_url(admin_url('admin-post.php')), esc_attr($class), $attrs); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs sale escapado de arriba.
	}

	private static function form_close() {
		echo self::$nested ? '</div>' : '</form>';
	}

	/**
	 * Un campo oculto. Dentro del pedido va con data-name: con name viajaría
	 * también al guardar el pedido y pisaría sus campos (action, _wpnonce, id).
	 *
	 * @param string $name  Nombre.
	 * @param string $value Valor.
	 */
	private static function hidden($name, $value) {
		if (self::$nested) {
			printf('<input type="hidden" data-name="%s" value="%s">', esc_attr($name), esc_attr($value));
			return;
		}
		printf('<input type="hidden" name="%s" value="%s">', esc_attr($name), esc_attr($value));
	}

	/** Atributo de nombre de un campo visible: name, o data-name dentro del pedido. */
	private static function name_attr($name) {
		if (self::$nested) {
			printf('data-name="%s"', esc_attr($name));
			return;
		}
		printf('name="%s"', esc_attr($name));
	}

	/** Lo que necesita cada caja para llegar a admin-post.php: acción, nonce, datos y a dónde volver. */
	private static function base_fields($action, array $fields, $origin) {
		self::hidden('action', $action);
		self::hidden('_wpnonce', wp_create_nonce($action));
		foreach ($fields as $name => $value) {
			self::hidden((string) $name, (string) $value);
		}
		self::hidden('_mjpm_origin', (string) $origin);
	}

	/**
	 * Saldo de la compra y el formulario para aprobar: pagó todo lo que falta o
	 * una parte, con un mensaje opcional para el cliente.
	 *
	 * @param string $context   Contexto.
	 * @param int    $object_id Objeto.
	 * @param array  $balance   MJPM_Receipts::balance().
	 * @param string $origin    A dónde volver.
	 */
	private static function balance_block($context, $object_id, array $balance, $origin) {
		$req      = MJPM_Receipts::requirement($context, $object_id);
		$declared = MJPM_Receipts::declared_pending($context, $object_id);
		$move     = function ($status) {
			return '' !== (string) $status && function_exists('wc_get_order_status_name')
				? sprintf('pasa a «%s»', wc_get_order_status_name($status))
				: 'no cambia de estado';
		};
		?>
		<div class="mjpm-rc-balance">
			<span>Total <strong><?php echo esc_html($balance['totalText']); ?></strong></span>
			<span>Pagado <strong><?php echo esc_html($balance['paidText']); ?></strong></span>
			<span class="<?php echo $balance['due'] > 0 ? 'is-due' : 'is-paid'; ?>">
				<?php echo $balance['due'] > 0 ? 'Falta abonar' : 'Pagado completo'; ?>
				<?php if ($balance['due'] > 0) : ?>
					<strong><?php echo esc_html($balance['dueText']); ?></strong>
				<?php endif; ?>
			</span>
			<?php if ($declared > 0) : ?>
				<span class="mjpm-rc-balance__declared">El cliente declaró <strong><?php echo esc_html(MJPM_Receipts::money($declared)); ?></strong></span>
			<?php endif; ?>
		</div>

		<?php if ($balance['history']) : ?>
			<ul class="mjpm-rc-history">
				<?php foreach ($balance['history'] as $entry) : ?>
					<li>
						<strong><?php echo esc_html($entry['amountText']); ?></strong>
						· <?php echo esc_html($entry['kindLabel']); ?>
						<?php if ('' !== $entry['date']) : ?>
							· <?php echo esc_html(date_i18n('j M Y H:i', strtotime($entry['date']))); ?>
						<?php endif; ?>
						<?php if ('' !== $entry['note']) : ?>
							<span class="mjpm-rc-history__note">“<?php echo esc_html($entry['note']); ?>”</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ($balance['due'] > 0) : ?>
			<?php
			self::form_open('mjpm-rc-approve', ['mjpm-approve' => '1']);
			self::base_fields('mjpm_receipt_object', ['context' => $context, 'objeto' => $object_id, 'decision' => MJPM_Receipts::STATUS_OK], $origin);
			/* Los radios necesitan un name para excluirse; dentro del pedido, uno propio que no choca con nada. */
			$group = self::$nested ? 'mjpm_pago_' . $object_id : 'pago';
			?>
				<label class="mjpm-rc-approve__option">
					<input type="radio" name="<?php echo esc_attr($group); ?>" data-name="pago" value="total" checked>
					Pagó todo lo que falta: <strong><?php echo esc_html($balance['dueText']); ?></strong>
					<span class="mjpm-rc-approve__hint">El pedido <?php echo esc_html($move($req['on_ok'])); ?>, listo para armar y enviar.</span>
				</label>
				<label class="mjpm-rc-approve__option">
					<input type="radio" name="<?php echo esc_attr($group); ?>" data-name="pago" value="parcial">
					Pagó una parte:
					<input type="text" <?php self::name_attr('importe'); ?> inputmode="decimal" class="mjpm-rc-approve__amount" value="<?php echo $declared > 0 && $declared < $balance['due'] ? esc_attr(MJPM_Methods::number($declared)) : ''; ?>" placeholder="Importe" aria-label="Importe que entró" disabled>
					<span class="mjpm-rc-approve__hint">El pedido <?php echo esc_html($move($req['on_partial'])); ?> y el cliente ve cuánto le falta abonar.</span>
				</label>
				<p class="mjpm-rc-approve__row">
					<input type="text" <?php self::name_attr('motivo'); ?> maxlength="255" class="mjpm-rc-approve__message" placeholder="Mensaje para el cliente (opcional)">
					<button type="submit" class="button button-primary">Aprobar el pago</button>
				</p>
			<?php self::form_close(); ?>
		<?php endif; ?>
		<?php
	}

	/** Formulario de un botón. */
	private static function action_form($action, array $fields, $label, $class = 'button', $origin = '', $confirm = false) {
		?>
		<?php self::form_open('mjpm-rc-form'); ?>
			<?php self::base_fields($action, $fields, $origin); ?>
			<button type="submit" class="<?php echo esc_attr($class); ?>" <?php echo $confirm ? 'data-mjpm-confirm="1"' : ''; ?>><?php echo esc_html($label); ?></button>
		<?php self::form_close(); ?>
		<?php
	}

	/** Rechazo: el motivo es obligatorio porque es lo que ve el cliente. */
	private static function reject_form($action, array $fields, $label, $origin = '') {
		?>
		<?php self::form_open('mjpm-rc-form mjpm-rc-reject', ['mjpm-reject' => '1']); ?>
			<?php self::base_fields($action, $fields, $origin); ?>
			<button type="button" class="button mjpm-rc-reject__open"><?php echo esc_html($label); ?></button>
			<span class="mjpm-rc-reject__box" hidden>
				<input type="text" <?php self::name_attr('motivo'); ?> maxlength="255" placeholder="Motivo (lo ve el cliente)" class="mjpm-rc-reject__input">
				<button type="submit" class="button"><?php echo esc_html($label); ?></button>
			</span>
		<?php self::form_close(); ?>
		<?php
	}

	/** La pantalla donde estás, para volver al mismo lugar después de decidir. */
	private static function current_url() {
		global $pagenow;
		$args = [];
		foreach ($_GET as $key => $value) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if (is_scalar($value) && 'mjpm_msg' !== $key) {
				$args[sanitize_key($key)] = sanitize_text_field(wp_unslash($value));
			}
		}
		return add_query_arg($args, admin_url($pagenow ? $pagenow : 'admin.php'));
	}
}
