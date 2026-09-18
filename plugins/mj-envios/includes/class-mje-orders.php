<?php
/**
 * El envío en el pedido: lo que se guarda al confirmar, lo que se ve en el
 * admin, lo que viaja al email y a Mi cuenta, y lo que se le cuenta al ERP.
 *
 * Todo lo del envío queda en el pedido, no en la sesión: un pedido de hace tres
 * meses tiene que poder decir a qué sucursal iba y con qué DNI, aunque el método
 * se haya borrado o cambiado de precio desde entonces.
 */

defined('ABSPATH') || exit;

class MJE_Orders {

	public static function init() {
		/* Guardar el envío en el pedido: checkout clásico y Store API. */
		add_action('woocommerce_checkout_create_order', [__CLASS__, 'stamp'], 20, 1);
		/* La línea de envío guarda lo que el plugin del correo busca en ella. */
		add_action('woocommerce_checkout_create_order_shipping_item', [__CLASS__, 'stamp_shipping_item'], 20, 4);
		add_action('woocommerce_store_api_checkout_update_order_meta', [__CLASS__, 'stamp'], 20, 1);
		add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'stamp_and_save'], 20, 1);
		add_action('woocommerce_checkout_order_created', [__CLASS__, 'stamp_and_save'], 20, 1);

		/* Lo que se ve del envío en el pedido del admin y en los emails. */
		add_action('add_meta_boxes', [__CLASS__, 'meta_box']);
		add_action('save_post_shop_order', [__CLASS__, 'save_meta_box'], 20, 1);
		add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_meta_box'], 20, 1);
		add_action('woocommerce_email_order_meta', [__CLASS__, 'email_block'], 20, 3);
		add_filter('woocommerce_get_order_item_totals', [__CLASS__, 'order_totals'], 20, 3);

		/* Mi cuenta de la tienda React. */
		add_filter('mj_rest_order_detail', [__CLASS__, 'rest_detail'], 20, 2);

		/* MJ ERP: qué forma de entrega le corresponde a este pedido. */
		add_filter('mjerp_sale_delivery_from_order', [__CLASS__, 'erp_delivery'], 10, 3);
	}

	/* ------------------------------ Guardado ----------------------------- */

	/**
	 * Copia al pedido el envío elegido.
	 *
	 * Lo que se guarda sale de dos lados: la tarifa que eligió el cliente (de
	 * ahí el método, el costo y el plazo) y la sesión (la sucursal y los datos
	 * del formulario).
	 */
	public static function stamp($order) {
		if (!$order instanceof WC_Order) {
			return;
		}
		$def = MJE_Cart::chosen_method();
		if (!$def) {
			return;
		}
		$data   = MJE_Cart::session_data();
		$fields = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [];

		$order->update_meta_data('_mje_method', (string) $def['slug']);
		$order->update_meta_data('_mje_title', MJE_Methods::name($def));
		$order->update_meta_data('_mje_type', (string) $def['type']);
		$order->update_meta_data('_mje_destination', (string) $def['destination']);
		$order->update_meta_data('_mje_carrier', (string) $def['carrier']);
		$order->update_meta_data('_mje_service', (string) $def['service']);

		$eta = MJE_Methods::eta($def);
		if ('' !== $eta) {
			$order->update_meta_data('_mje_eta', $eta);
		}
		if ('' !== (string) $def['instructions']) {
			$order->update_meta_data('_mje_instructions', (string) $def['instructions']);
		}
		if ('' !== (string) $def['erp_delivery']) {
			$order->update_meta_data('_mje_erp_delivery', (string) $def['erp_delivery']);
		}

		/* Sucursal, con el nombre completo resuelto para que se lea en el pedido. */
		$branch = isset($fields['branch']) && '' !== $fields['branch'] ? (string) $fields['branch'] : (string) $data['branch'];
		if ('' !== $branch) {
			$order->update_meta_data('_mje_branch', $branch);
			$order->update_meta_data('_mje_branch_name', MJE_Carriers::branch_name(MJE_Methods::branch_source($def), $branch));
		}

		/* Lo que cobra el correo, antes del margen: el costo real del envío. */
		$base = self::rate_meta($order, '_mje_base');
		if ('' !== $base && MJE_Settings::get('erp_cost')) {
			$order->update_meta_data('_mje_carrier_cost', (string) MJE_Settings::decimal($base));
		}
		$source = self::rate_meta($order, '_mje_source');
		if ('' !== $source) {
			$order->update_meta_data('_mje_source', $source);
		}
		if ('1' === (string) self::rate_meta($order, '_mje_coordinate')) {
			$order->update_meta_data('_mje_coordinate', '1');
		}

		/* Los datos del formulario: a la dirección los que son dirección, el resto como dato del envío. */
		$extra = [];
		foreach (MJE_Fields::resolve($def) as $field) {
			$key   = $field['key'];
			$value = isset($fields[$key]) ? (string) $fields[$key] : '';
			if ('' === $value) {
				continue;
			}
			if ('shipping' === $field['target'] || 'billing' === $field['target']) {
				self::fill_address($order, $field['target'], $key, $value);
				continue;
			}
			$extra[$key] = ['label' => $field['label'], 'value' => $value];
		}
		if ($extra) {
			$order->update_meta_data('_mje_fields', $extra);
		}

		/* Lo que el plugin del correo necesita para reconocer el pedido como suyo. */
		self::stamp_native($order, $def);

		/* Con el retiro en el local no hay dirección de envío que valga: queda la del local. */
		if ('local' === (string) $def['destination']) {
			$origin = MJE_Settings::origin();
			$order->update_meta_data('_mje_pickup', [
				'name'     => $origin['name'],
				'address'  => trim($origin['address_1'] . ' ' . $origin['address_2']),
				'city'     => $origin['city'],
				'state'    => $origin['state'],
				'postcode' => $origin['postcode'],
				'phone'    => $origin['phone'],
			]);
		}
	}

	/**
	 * El pedido, visto por el plugin del correo.
	 *
	 * Cuando el método elegido cotizó con el plugin oficial del correo, el
	 * pedido sale con el método de MJ Envíos y ese plugin no lo reconocería: no
	 * se le podría dar el alta ni imprimir el rótulo desde su propia pantalla.
	 * Acá se le deja escrito lo que busca —el id de su tarifa y la sucursal
	 * elegida—, cada adaptador en las claves que usa su plugin.
	 */
	private static function stamp_native($order, array $def) {
		if ('nativo' !== (string) $def['type']) {
			return;
		}
		$plugin = MJE_Native::get((string) $def['native_plugin']);
		if (!$plugin) {
			return;
		}
		$rate_id = self::rate_meta($order, '_mje_native_rate');
		$code    = (string) $order->get_meta('_mje_branch');
		$branch  = '' !== $code ? MJE_Native::branch($plugin->id(), $code) : [];
		if ('' !== $code && empty($branch['code'])) {
			/* La sucursal todavía no está en la tabla: al menos viaja el código. */
			$branch = ['code' => $code, 'name' => (string) $order->get_meta('_mje_branch_name')];
		}

		$order->update_meta_data('_mje_native_plugin', $plugin->id());
		if ('' !== $rate_id) {
			$order->update_meta_data('_mje_native_rate', $rate_id);
		}
		$plugin->stamp_order($order, $rate_id, (string) $def['native_service'], $branch);
	}

	/**
	 * La línea de envío, vista por el plugin del correo.
	 *
	 * Varios plugins guardan el id de su tarifa en la línea y lo leen de ahí
	 * para saber con qué servicio se despachó. Se escribe al crearla, que es
	 * cuando la tarifa elegida todavía está a mano.
	 */
	public static function stamp_shipping_item($item, $package_key, $package, $order) {
		if (!$item instanceof WC_Order_Item_Shipping) {
			return;
		}
		$rate_id = (string) $item->get_meta('_mje_native_rate');
		$plugin  = MJE_Native::get((string) $item->get_meta('_mje_native_plugin'));
		if ('' === $rate_id || !$plugin) {
			return;
		}
		$plugin->stamp_item($item, $rate_id);
	}

	public static function stamp_and_save($order) {
		if (!$order instanceof WC_Order) {
			return;
		}
		self::stamp($order);
		$order->save();
	}

	/**
	 * Un dato de la tarifa elegida.
	 *
	 * Se lee de las líneas de envío del pedido si ya están, y si no, de las
	 * tarifas del carrito: según el hook, el pedido todavía puede no tenerlas.
	 */
	private static function rate_meta($order, $key) {
		foreach ($order->get_items('shipping') as $item) {
			if (!$item instanceof WC_Order_Item_Shipping) {
				continue;
			}
			$meta = $item->get_meta($key);
			if ('' !== (string) $meta && null !== $meta) {
				return (string) $meta;
			}
		}
		if (!function_exists('WC') || !WC()->session) {
			return '';
		}
		/*
		 * Las tarifas calculadas viven en la sesión, una entrada por paquete, y
		 * cada entrada es un array con 'rates' adentro.
		 */
		$chosen = (array) WC()->session->get('chosen_shipping_methods', []);
		$stored = WC()->session->get('shipping_for_package_0');
		if (!is_array($stored) || !isset($stored['rates']) || !is_array($stored['rates'])) {
			return '';
		}
		foreach ($stored['rates'] as $rate_id => $rate) {
			if (!in_array($rate_id, $chosen, true) || !$rate instanceof WC_Shipping_Rate) {
				continue;
			}
			$meta = $rate->get_meta_data();
			if (isset($meta[$key]) && '' !== (string) $meta[$key]) {
				return (string) $meta[$key];
			}
		}
		return '';
	}

	/** Escribe un campo de dirección solo si el pedido no lo trae ya. */
	private static function fill_address($order, $target, $key, $value) {
		$getter = 'get_' . $target . '_' . $key;
		$setter = 'set_' . $target . '_' . $key;
		if (!method_exists($order, $setter)) {
			return;
		}
		if (method_exists($order, $getter) && '' !== trim((string) $order->$getter())) {
			return;
		}
		$order->$setter($value);
	}

	/* ------------------------------- Lectura ----------------------------- */

	/**
	 * El envío guardado en un pedido, listo para mostrar.
	 *
	 * → ['method','title','type','destination','carrier','branch','fields',
	 *    'eta','tracking','tracking_url','cost','carrier_cost','coordinate','pickup']
	 */
	public static function shipment($order) {
		if (is_numeric($order)) {
			$order = wc_get_order($order);
		}
		if (!$order instanceof WC_Order) {
			return [];
		}
		$method = (string) $order->get_meta('_mje_method');
		if ('' === $method) {
			return [];
		}
		$fields = $order->get_meta('_mje_fields');
		return [
			'method'       => $method,
			'title'        => (string) $order->get_meta('_mje_title'),
			'type'         => (string) $order->get_meta('_mje_type'),
			'destination'  => (string) $order->get_meta('_mje_destination'),
			'carrier'      => (string) $order->get_meta('_mje_carrier'),
			'service'      => (string) $order->get_meta('_mje_service'),
			'branch'       => (string) $order->get_meta('_mje_branch'),
			'branch_name'  => (string) $order->get_meta('_mje_branch_name'),
			'fields'       => is_array($fields) ? $fields : [],
			'eta'          => (string) $order->get_meta('_mje_eta'),
			'instructions' => (string) $order->get_meta('_mje_instructions'),
			'tracking'     => (string) $order->get_meta('_mje_tracking'),
			'tracking_url' => MJE_Tracking::url($order),
			'cost'         => (float) $order->get_shipping_total(),
			'carrier_cost' => (float) MJE_Settings::decimal($order->get_meta('_mje_carrier_cost')),
			'coordinate'   => '1' === (string) $order->get_meta('_mje_coordinate'),
			'pickup'       => is_array($order->get_meta('_mje_pickup')) ? $order->get_meta('_mje_pickup') : [],
			'shipped_at'   => (string) $order->get_meta('_mje_shipped_at'),
		];
	}

	/* ------------------------------- wp-admin ---------------------------- */

	public static function meta_box() {
		$screens = ['shop_order', 'woocommerce_page_wc-orders'];
		foreach ($screens as $screen) {
			add_meta_box('mje-shipment', '📦 Envío', [__CLASS__, 'render_meta_box'], $screen, 'side', 'default');
		}
	}

	public static function render_meta_box($post) {
		$order = $post instanceof WC_Order ? $post : wc_get_order(is_object($post) ? $post->ID : $post);
		if (!$order instanceof WC_Order) {
			return;
		}
		$shipment = self::shipment($order);
		wp_nonce_field('mje_save_shipment', 'mje_nonce');

		if (!$shipment) {
			echo '<p>Este pedido no se hizo con un método de MJ Envíos.</p>';
			echo '<p><label>Seguimiento<br><input type="text" name="mje_tracking" value="' . esc_attr((string) $order->get_meta('_mje_tracking')) . '" class="widefat"></label></p>';
			return;
		}

		echo '<p><strong>' . esc_html($shipment['title']) . '</strong>';
		if ('' !== $shipment['eta']) {
			echo '<br><span class="description">' . esc_html($shipment['eta']) . '</span>';
		}
		echo '</p>';

		if ('' !== $shipment['branch_name']) {
			echo '<p><strong>' . esc_html((string) MJE_Settings::get('branch_label')) . ':</strong><br>' . esc_html($shipment['branch_name']) . '</p>';
		}
		if ($shipment['pickup']) {
			echo '<p><strong>' . esc_html((string) MJE_Settings::get('pickup_label')) . '</strong><br>' . esc_html(trim($shipment['pickup']['address'] . ' ' . $shipment['pickup']['city'])) . '</p>';
		}
		if ($shipment['fields']) {
			echo '<ul style="margin:0 0 12px">';
			foreach ($shipment['fields'] as $field) {
				echo '<li><strong>' . esc_html($field['label']) . ':</strong> ' . esc_html($field['value']) . '</li>';
			}
			echo '</ul>';
		}
		if ($shipment['coordinate']) {
			echo '<p><em>' . esc_html((string) MJE_Settings::get('coordinate_text')) . '</em></p>';
		}
		if ($shipment['carrier_cost'] > 0) {
			echo '<p class="description">Cobrado: ' . esc_html(MJE_Settings::money($shipment['cost'])) . ' · Costo del correo: ' . esc_html(MJE_Settings::money($shipment['carrier_cost'])) . '</p>';
		}

		echo '<p><label>Seguimiento<br><input type="text" name="mje_tracking" value="' . esc_attr($shipment['tracking']) . '" class="widefat" placeholder="Número del correo"></label></p>';
		if ('' !== $shipment['tracking'] && '' !== $shipment['tracking_url']) {
			echo '<p><a href="' . esc_url($shipment['tracking_url']) . '" target="_blank" rel="noopener">Ver el seguimiento</a></p>';
		}
		$whatsapp = MJE_Tracking::whatsapp_link($order);
		if ('' !== $whatsapp && '' !== $shipment['tracking']) {
			echo '<p><a class="button button-secondary" href="' . esc_url($whatsapp) . '" target="_blank" rel="noopener">Avisar por WhatsApp</a></p>';
		}
		if (MJE_Settings::get('track_mail')) {
			echo '<p><label><input type="checkbox" name="mje_notify" value="1" checked> Avisarle al cliente cuando guarde</label></p>';
		}
	}

	public static function save_meta_box($order_id) {
		if (!isset($_POST['mje_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mje_nonce'])), 'mje_save_shipment')) {
			return;
		}
		if (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce')) {
			return;
		}
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			return;
		}
		$code = isset($_POST['mje_tracking']) ? mb_substr(trim(sanitize_text_field(wp_unslash($_POST['mje_tracking']))), 0, 120) : '';
		$notify = !empty($_POST['mje_notify']);
		MJE_Tracking::save($order, $code, $notify);
	}

	/* ------------------------------- Emails ------------------------------ */

	/** Bloque del envío en el email del pedido. */
	public static function email_block($order, $sent_to_admin = false, $plain_text = false) {
		$shipment = self::shipment($order);
		if (!$shipment) {
			return;
		}
		$lines = [];
		if ('' !== $shipment['eta']) {
			$lines[] = $shipment['eta'];
		}
		if ('' !== $shipment['branch_name']) {
			$lines[] = MJE_Settings::get('branch_label') . ': ' . $shipment['branch_name'];
		}
		if ($shipment['pickup']) {
			$lines[] = MJE_Settings::get('pickup_label') . ': ' . trim($shipment['pickup']['address'] . ' ' . $shipment['pickup']['city']);
		}
		foreach ($shipment['fields'] as $field) {
			$lines[] = $field['label'] . ': ' . $field['value'];
		}
		if ($shipment['coordinate']) {
			$lines[] = (string) MJE_Settings::get('coordinate_text');
		}
		if ('' !== $shipment['instructions']) {
			$lines[] = $shipment['instructions'];
		}
		if ('' !== $shipment['tracking']) {
			$lines[] = 'Seguimiento: ' . $shipment['tracking'] . ('' !== $shipment['tracking_url'] ? ' ' . $shipment['tracking_url'] : '');
		}
		if (!$lines) {
			return;
		}

		if ($plain_text) {
			echo "\n" . esc_html($shipment['title']) . "\n";
			foreach ($lines as $line) {
				echo esc_html($line) . "\n";
			}
			return;
		}
		echo '<h2>' . esc_html($shipment['title']) . '</h2><ul style="margin:0 0 16px;padding-left:18px">';
		foreach ($lines as $line) {
			echo '<li>' . esc_html($line) . '</li>';
		}
		echo '</ul>';
	}

	/** El plazo, al lado del envío, en la tabla de totales del pedido. */
	public static function order_totals($totals, $order, $tax_display = '') {
		$shipment = self::shipment($order);
		if (!$shipment || !isset($totals['shipping'])) {
			return $totals;
		}
		if ($shipment['coordinate']) {
			$totals['shipping']['value'] = esc_html((string) MJE_Settings::get('coordinate_text'));
		} elseif ('' !== $shipment['eta']) {
			$totals['shipping']['value'] .= '<br><small>' . esc_html($shipment['eta']) . '</small>';
		}
		return $totals;
	}

	/* -------------------------------- REST ------------------------------- */

	/** Mi cuenta de la tienda React: el envío y su seguimiento. */
	public static function rest_detail($data, $order) {
		$shipment = self::shipment($order);
		if (!$shipment) {
			return $data;
		}
		$data['envio'] = [
			'metodo'       => $shipment['title'],
			'plazo'        => $shipment['eta'],
			'sucursal'     => $shipment['branch_name'],
			'aCoordinar'   => $shipment['coordinate'],
			'instrucciones' => $shipment['instructions'],
			'retiro'       => $shipment['pickup'],
			'datos'        => array_values(array_map(function ($field) {
				return ['etiqueta' => $field['label'], 'valor' => $field['value']];
			}, $shipment['fields'])),
			'seguimiento'  => MJE_Settings::get('track_account') ? [
				'codigo' => $shipment['tracking'],
				'link'   => $shipment['tracking_url'],
				'desde'  => $shipment['shipped_at'],
			] : null,
		];
		return $data;
	}

	/* -------------------------------- MJ ERP ----------------------------- */

	/**
	 * Qué forma de entrega del ERP le corresponde al pedido.
	 *
	 * Cada método de envío declara su equivalente (igual que MJ Medios de Pago
	 * con las cajas). Sin equivalencia declarada, el ERP sigue con la que tiene
	 * configurada para la tienda.
	 */
	public static function erp_delivery($method, $order, $default = '') {
		if (!$order instanceof WC_Order) {
			return $method;
		}
		$own = (string) $order->get_meta('_mje_erp_delivery');
		if ('' !== $own) {
			return $own;
		}
		$slug = (string) $order->get_meta('_mje_method');
		$def  = '' !== $slug ? MJE_Methods::get($slug) : null;
		if ($def && '' !== (string) $def['erp_delivery']) {
			return (string) $def['erp_delivery'];
		}
		return $method;
	}
}
