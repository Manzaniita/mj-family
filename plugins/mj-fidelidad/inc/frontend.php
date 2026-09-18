<?php
/**
 * Lo que ve la clienta: su tarjeta.
 *
 *   GET /wp-json/mjfid/v1/me   la tarjeta de la cuenta de la sesión.
 *   [mj_fidelidad]             la tarjeta en cualquier página (cualquier tema).
 *   MJ Appearance              la suma a "Mi cuenta" (filtro mj_rest_account,
 *                              clave "fidelidad") para que el tema la dibuje.
 *
 * Solo con sesión: la tarjeta es de una cuenta (y de las fichas de MJ Turnos
 * vinculadas a ella). Nadie ve la tarjeta de otra persona.
 *
 * @package MJ_Fidelidad
 */

defined('ABSPATH') || exit;

/** Tarjeta de la cuenta de la sesión (null sin sesión o apagado). */
function mjfid_current_card() {
	if (!is_user_logged_in() || !mjfid_enabled()) {
		return null;
	}
	return mjfid_card(['user', (int) get_current_user_id()]);
}

add_action('rest_api_init', function () {
	register_rest_route('mjfid/v1', '/me', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function () {
			$card = mjfid_current_card();
			return rest_ensure_response($card ? $card : ['enabled' => mjfid_enabled()]);
		},
		'permission_callback' => function () {
			if (!is_user_logged_in()) {
				return new WP_Error('mjfid_not_logged_in', (string) mjfid_opt('card_login'), ['status' => 401]);
			}
			return true;
		},
	]);
});

/* MJ Appearance: la tarjeta viaja con "Mi cuenta" (GET /mj/v1/account). */
add_filter('mj_rest_account', function ($data, $user_id) {
	if (is_array($data) && (int) $user_id > 0 && mjfid_enabled()) {
		$data['fidelidad'] = mjfid_card(['user', (int) $user_id]);
	}
	return $data;
}, 10, 2);

/* -------------------------------------------------------------------------
 * Shortcode [mj_fidelidad]
 * ---------------------------------------------------------------------- */

add_shortcode('mj_fidelidad', function () {
	if (!mjfid_enabled()) {
		return '';
	}
	if (!is_user_logged_in()) {
		return '<div class="mjfid-card"><p>' . esc_html((string) mjfid_opt('card_login')) . ' <a href="' . esc_url(wp_login_url(get_permalink() ? (string) get_permalink() : home_url('/'))) . '">' . esc_html((string) mjfid_opt('card_login_link')) . '</a></p></div>';
	}
	$card = mjfid_current_card();
	ob_start();
	?>
	<div class="mjfid-card" style="border:1px solid currentColor; border-radius:16px; padding:20px; max-width:480px;">
		<p style="margin:0 0 8px; font-weight:600; font-size:1.1em;"><?php echo esc_html($card['texts']['title']); ?></p>
		<div role="img" aria-label="<?php echo esc_attr($card['progressText']); ?>" style="display:grid; grid-template-columns:repeat(5, 1fr); gap:8px; margin:12px 0;">
			<?php for ($i = 1; $i <= $card['perCard']; $i++) : ?>
				<span style="aspect-ratio:1; border-radius:50%; border:2px solid currentColor; display:grid; place-items:center; opacity:<?php echo $i <= $card['current'] ? '1' : '0.35'; ?>;"><?php echo $i <= $card['current'] ? '&#9733;' : ''; ?></span>
			<?php endfor; ?>
		</div>
		<p style="margin:0;"><strong><?php echo esc_html($card['progressText']); ?></strong></p>
		<p style="margin:4px 0 0;"><?php echo esc_html($card['texts']['rewardLabel'] . ' ' . $card['rewardText']); ?></p>
		<?php if (0 === (int) $card['total'] && !$card['rewards']) : ?>
			<p style="margin:12px 0 0; opacity:.8;"><?php echo esc_html($card['texts']['empty']); ?></p>
		<?php endif; ?>
		<?php if ($card['rewards']) : ?>
			<p style="margin:16px 0 6px; font-weight:600;"><?php echo esc_html($card['texts']['rewardsTitle']); ?></p>
			<ul style="margin:0; padding-left:1.2em;">
				<?php foreach ($card['rewards'] as $reward) : ?>
					<li><?php echo esc_html($reward['text'] . ' — ' . $reward['statusLabel']); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
});
