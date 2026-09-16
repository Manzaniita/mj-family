<?php
/**
 * Lo que ve el cliente: el widget de subida y los tres lugares donde aparece.
 *
 *   1. Página de gracias y "ver pedido" del checkout clásico de WooCommerce.
 *   2. Mi cuenta de la tienda React (el tema lo pinta con los datos que agrega
 *      MJPM_Orders al filtro mj_rest_order_detail).
 *   3. Página propia para quien compró sin cuenta, con el link firmado:
 *      https://tienda.com/?mjpm_comprobante={token}
 *      Arriba del widget van las instrucciones y los datos para pagar del pedido.
 *
 * El widget es un único JS sin dependencias (assets/uploader.js) y se monta en
 * cualquier `<div data-mjpm-uploader>`. Eso es a propósito: así el portal de
 * reventas del ERP y la pantalla de seña de turnos usan exactamente el mismo
 * widget en lugar de reimplementar la subida tres veces.
 *
 *     echo MJPM_Receipts_Front::widget('order', $order_id);
 *     MJPM_Receipts_Front::enqueue();
 */

defined('ABSPATH') || exit;

class MJPM_Receipts_Front {

	const QUERY_VAR = 'mjpm_comprobante';

	/** @var bool Si ya se encoló el widget en esta carga. */
	private static $enqueued = false;

	public static function init() {
		add_action('template_redirect', [__CLASS__, 'maybe_render_page'], 5);
		add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue'], 20);

		/* Checkout clásico: página de gracias y detalle del pedido en Mi cuenta. */
		add_action('woocommerce_thankyou', [__CLASS__, 'after_thankyou'], 20, 1);
		add_action('woocommerce_view_order', [__CLASS__, 'after_view_order'], 20, 1);

		/* Aviso en Mi cuenta de WooCommerce cuando falta un comprobante. */
		add_action('woocommerce_account_orders_column_order-status', [__CLASS__, 'orders_column'], 20, 1);
	}

	/* ------------------------------ Assets -------------------------------- */

	/**
	 * El widget se encola en todo el front cuando la función está prendida.
	 *
	 * Es a propósito: la tienda es una SPA y el portal de reventas del ERP pintan
	 * el contenedor después, por JavaScript, cuando ya no hay forma de encolar
	 * nada. El script son unos pocos KB, no hace nada si no encuentra un
	 * contenedor, y quien quiera afinarlo tiene el filtro.
	 */
	public static function maybe_enqueue() {
		if (!MJPM_Receipts::enabled()) {
			return;
		}
		/**
		 * ¿Cargar el widget de comprobantes en esta página?
		 *
		 * @param bool $load Por defecto sí, en todo el front.
		 */
		if (!apply_filters('mjpm_enqueue_uploader', true)) {
			return;
		}
		self::enqueue();
	}

	/** Encola el widget. Se puede llamar varias veces. */
	public static function enqueue() {
		if (self::$enqueued) {
			return;
		}
		self::$enqueued = true;

		wp_enqueue_style('mjpm-uploader', MJPM_URL . 'assets/uploader.css', [], MJPM_VERSION);
		wp_enqueue_script('mjpm-uploader', MJPM_URL . 'assets/uploader.js', [], MJPM_VERSION, true);
		wp_localize_script('mjpm-uploader', 'mjpmUploader', self::config());
	}

	/**
	 * Hoja de estilos del widget, para plantillas que no llaman a wp_head()
	 * (el portal de reventas de MJ ERP es una de ellas).
	 *
	 * @return void
	 */
	public static function print_styles() {
		if (!MJPM_Receipts::enabled()) {
			return;
		}
		printf(
			'<link rel="stylesheet" href="%s">' . "\n",
			esc_url(add_query_arg('ver', MJPM_VERSION, MJPM_URL . 'assets/uploader.css'))
		);
	}

	/**
	 * Config y script del widget, para plantillas que no llaman a wp_footer().
	 * Va antes de cerrar el body.
	 *
	 * @return void
	 */
	public static function print_scripts() {
		if (!MJPM_Receipts::enabled()) {
			return;
		}
		printf(
			'<script>window.mjpmUploader = %s;</script>' . "\n",
			wp_json_encode(self::config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)
		);
		printf(
			'<script src="%s"></script>' . "\n",
			esc_url(add_query_arg('ver', MJPM_VERSION, MJPM_URL . 'assets/uploader.js'))
		);
	}

	/**
	 * Todo lo que el widget necesita saber. Un solo lugar, así la plantilla
	 * autosuficiente del portal y el encolado normal no se desincronizan.
	 *
	 * @return array
	 */
	public static function config() {
		return [
			'rest'  => esc_url_raw(rest_url(MJPM_Receipts_REST::NS . '/comprobantes')),
			'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
			'noun'  => MJPM_Receipts::noun(),
			'texts' => self::texts(),
		];
	}

	/**
	 * Textos del widget: los de Comprobantes → Ajustes → Textos que ve el cliente
	 * (o los de fábrica), y después el filtro mjpm_uploader_texts.
	 *
	 * @return array
	 */
	public static function texts() {
		/**
		 * Textos del widget de comprobantes.
		 *
		 * @param array $texts clave => texto (con marcadores entre llaves).
		 */
		$texts = [];
		foreach (array_keys(MJPM_Settings::receipt_texts()) as $key) {
			$texts[$key] = MJPM_Settings::receipt_text($key);
		}
		return (array) apply_filters('mjpm_uploader_texts', $texts);
	}

	/* ------------------------------ Widget -------------------------------- */

	/**
	 * HTML del widget. El JS lo rellena con lo que diga la REST.
	 *
	 * @param string $context   Contexto.
	 * @param int    $object_id Objeto.
	 * @param string $token     Token del link firmado ('' si hay sesión).
	 * @param array  $args      compact => versión compacta; title => encabezado.
	 * @return string
	 */
	public static function widget($context, $object_id, $token = '', array $args = []) {
		if (!MJPM_Receipts::enabled()) {
			return '';
		}
		$req = MJPM_Receipts::requirement($context, $object_id);
		if (!$req['asks']) {
			return '';
		}
		self::enqueue();

		$args = wp_parse_args($args, ['compact' => false, 'title' => '']);
		$title = '' !== (string) $args['title']
			? (string) $args['title']
			: MJPM_Receipts::noun(false, true) . ' de pago';

		return sprintf(
			'<div class="mjpm-uploader%s" data-mjpm-uploader data-context="%s" data-objeto="%d" data-token="%s" data-title="%s"><p class="mjpm-uploader__loading">Cargando…</p></div>',
			$args['compact'] ? ' is-compact' : '',
			esc_attr($context),
			(int) $object_id,
			esc_attr($token),
			esc_attr($title)
		);
	}

	/* -------------------------- Checkout clásico --------------------------- */

	public static function after_thankyou($order_id) {
		$order = wc_get_order((int) $order_id);
		if (!$order instanceof WC_Order) {
			return;
		}
		$token = (int) $order->get_customer_id() > 0 ? '' : MJPM_Receipts::upload_token('order', $order->get_id());
		echo self::widget('order', $order->get_id(), $token); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function after_view_order($order_id) {
		$order = wc_get_order((int) $order_id);
		if (!$order instanceof WC_Order || (int) $order->get_customer_id() !== get_current_user_id()) {
			return;
		}
		echo self::widget('order', $order->get_id()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Marquita en la lista de pedidos de Mi cuenta cuando falta el comprobante. */
	public static function orders_column($order) {
		if (!$order instanceof WC_Order || !MJPM_Receipts::enabled()) {
			return;
		}
		$state = MJPM_Receipts::state('order', $order->get_id());
		if (MJPM_Receipts::STATUS_PENDING !== $state) {
			return;
		}
		$pending = MJPM_Receipts::for_object('order', $order->get_id());
		echo '<small class="mjpm-order-flag">' . esc_html(
			$pending
				? sprintf('%s en revisión', MJPM_Receipts::noun(false, true))
				: sprintf('Falta el %s', MJPM_Receipts::noun())
		) . '</small>';
	}

	/* ------------------------ Página del link firmado ---------------------- */

	/**
	 * Datos para pagar de un pedido, arriba del widget: quien entra por el link
	 * casi siempre todavía tiene que transferir.
	 *
	 * @param string $context   Contexto.
	 * @param int    $object_id Objeto.
	 * @return string
	 */
	private static function payment_info($context, $object_id) {
		if ('order' !== $context || !class_exists('MJPM_Payment_Info') || !function_exists('wc_get_order')) {
			return '';
		}
		$info = MJPM_Payment_Info::for_order(wc_get_order((int) $object_id));
		if (!$info) {
			return '';
		}
		$html = '';
		if ('' !== $info['instructions']) {
			$html .= '<div class="mjpm-page__instructions">' . wp_kses_post(wpautop(esc_html($info['instructions']))) . '</div>';
		}
		return $html . MJPM_Payment_Info::html($info);
	}

	/** ¿La URL trae un token de comprobante? Entonces servimos nuestra página. */
	public static function maybe_render_page() {
		$token = isset($_GET[self::QUERY_VAR]) ? sanitize_text_field(wp_unslash($_GET[self::QUERY_VAR])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ('' === $token) {
			return;
		}
		if (!MJPM_Receipts::enabled() || !MJPM_Settings::receipts('guest_links')) {
			self::render_page(null, 'Los comprobantes no están habilitados en este momento.');
			return;
		}
		$payload = MJPM_Receipts::verify_token($token);
		if (!$payload) {
			self::render_page(null, 'Este link venció o no es válido. Escribinos y te mandamos uno nuevo.');
			return;
		}
		$object = MJPM_Receipts::object($payload['context'], $payload['object_id']);
		if (!$object) {
			self::render_page(null, 'No encontramos esa compra.');
			return;
		}
		self::render_page($payload + ['token' => $token, 'object' => $object], '');
	}

	/**
	 * Página mínima y autosuficiente: no depende del tema, así funciona igual en
	 * la tienda React y en cualquier skin.
	 *
	 * @param array|null $data  Contexto, objeto y token.
	 * @param string     $error Mensaje de error (si hay, no se muestra el widget).
	 * @return void
	 */
	private static function render_page($data, $error = '') {
		$noun  = MJPM_Receipts::noun(false, true);
		$title = '' !== $error ? $noun : sprintf('%s de %s', $noun, $data['object']['title']);

		status_header('' !== $error ? 404 : 200);
		nocache_headers();
		header('X-Robots-Tag: noindex, nofollow');

		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo('charset'); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex, nofollow">
			<title><?php echo esc_html($title . ' · ' . get_bloginfo('name')); ?></title>
			<?php
			/*
			 * Sin wp_head() a propósito: esta página no tiene que depender del tema.
			 * La tienda es una SPA que se monta sola al cargar sus scripts, y acá lo
			 * único que hace falta es el widget. Así la página funciona igual en las
			 * tres skins, en el checkout clásico y con cualquier tema de terceros.
			 */
			self::print_styles();
			?>
		</head>
		<body class="mjpm-page">
			<main class="mjpm-page__inner">
				<header class="mjpm-page__head">
					<?php
					$logo = get_theme_mod('custom_logo') ? wp_get_attachment_image_url((int) get_theme_mod('custom_logo'), 'medium') : '';
					if ($logo) :
						?>
						<img class="mjpm-page__logo" src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
					<?php else : ?>
						<p class="mjpm-page__brand"><?php echo esc_html(get_bloginfo('name')); ?></p>
					<?php endif; ?>
				</header>

				<?php if ('' !== $error) : ?>
					<div class="mjpm-page__card">
						<h1><?php echo esc_html($noun); ?></h1>
						<p class="mjpm-page__error"><?php echo esc_html($error); ?></p>
						<p><a href="<?php echo esc_url(home_url('/')); ?>">Volver a la tienda</a></p>
					</div>
				<?php else : ?>
					<div class="mjpm-page__card">
						<h1><?php echo esc_html(sprintf('%s de pago', $noun)); ?></h1>
						<p class="mjpm-page__meta">
							<?php echo esc_html(MJPM_Receipts::context_label($data['context'])); ?>
							<strong><?php echo esc_html($data['object']['title']); ?></strong>
							<?php if ((float) $data['object']['total'] > 0) : ?>
								· <?php echo esc_html(MJPM_Receipts::money((float) $data['object']['total'])); ?>
							<?php endif; ?>
						</p>
						<?php echo self::payment_info($data['context'], $data['object_id']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo self::widget($data['context'], $data['object_id'], $data['token']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
			</main>
			<?php self::print_scripts(); ?>
		</body>
		</html>
		<?php
		exit;
	}
}
