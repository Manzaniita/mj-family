<?php
/**
 * Registro y renderizado del bloque de Instagram para el editor de WordPress (Gutenberg).
 *
 * Admite publicaciones, historias, destacados y perfil completo,
 * con diseños en grilla o carrusel y personalización total.
 *
 * @package MJ_Instagram
 */

defined('ABSPATH') || exit;

class MJ_Instagram_Block {

	/**
	 * Instancia de la API.
	 *
	 * @var MJ_Instagram_API
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param MJ_Instagram_API $api
	 */
	public function __construct(MJ_Instagram_API $api) {
		$this->api = $api;

		add_action('init', [$this, 'register_block']);
		add_filter('block_categories_all', [$this, 'register_category'], 10, 2);
	}

	/**
	 * Asegura que exista la categoría 'mj' o una específica en el insertador.
	 *
	 * @param array $categories
	 * @return array
	 */
	public function register_category($categories) {
		$has_mj = false;
		foreach ($categories as $cat) {
			if (isset($cat['slug']) && 'mj' === $cat['slug']) {
				$has_mj = true;
				break;
			}
		}

		if (!$has_mj) {
			array_unshift($categories, [
				'slug'  => 'mj',
				'title' => 'MJ',
				'icon'  => null,
			]);
		}

		return $categories;
	}

	/**
	 * Registra assets y el bloque Gutenberg.
	 *
	 * @return void
	 */
	public function register_block() {
		// Estilos y scripts para el frontend
		wp_register_style(
			'mj-instagram-frontend',
			MJ_INSTAGRAM_URL . 'assets/css/frontend.css',
			[],
			MJ_INSTAGRAM_VERSION
		);

		wp_register_script(
			'mj-instagram-frontend',
			MJ_INSTAGRAM_URL . 'assets/js/frontend.js',
			[],
			MJ_INSTAGRAM_VERSION,
			true
		);

		// Estilos y script para el editor
		wp_register_style(
			'mj-instagram-editor',
			MJ_INSTAGRAM_URL . 'assets/css/editor.css',
			['wp-edit-blocks', 'mj-instagram-frontend'],
			MJ_INSTAGRAM_VERSION
		);

		wp_register_script(
			'mj-instagram-editor',
			MJ_INSTAGRAM_URL . 'assets/js/block.js',
			['wp-blocks', 'wp-element', 'wp-editor', 'wp-block-editor', 'wp-components', 'wp-server-side-render'],
			MJ_INSTAGRAM_VERSION,
			true
		);

		// Datos iniciales para el editor
		wp_localize_script('mj-instagram-editor', 'MJInstagramBlockData', [
			'isConnected' => $this->api->is_connected(),
			'profile'     => $this->api->get_profile(),
			'settingsUrl' => admin_url('admin.php?page=mj-instagram'),
		]);

		register_block_type('mj-instagram/feed', [
			'api_version'     => 2,
			'title'           => 'Instagram (MJ)',
			'description'     => 'Mostrá publicaciones, historias, destacados o tu perfil de Instagram en cualquier página.',
			'category'        => 'mj',
			'icon'            => 'camera',
			'keywords'        => ['instagram', 'redes', 'feed', 'historias', 'posts', 'perfil'],
			'attributes'      => [
				'displayType'       => ['type' => 'string', 'default' => 'posts'],
				'layout'            => ['type' => 'string', 'default' => 'grid'],
				'columnsDesktop'    => ['type' => 'number', 'default' => 4],
				'columnsMobile'     => ['type' => 'number', 'default' => 2],
				'limit'             => ['type' => 'number', 'default' => 8],
				'aspectRatio'       => ['type' => 'string', 'default' => 'square'],
				'spacing'           => ['type' => 'string', 'default' => 'normal'],
				'showProfileHeader' => ['type' => 'boolean', 'default' => false],
				'showBio'           => ['type' => 'boolean', 'default' => true],
				'showFollowers'     => ['type' => 'boolean', 'default' => true],
				'showFollowButton'  => ['type' => 'boolean', 'default' => true],
				'followButtonText'  => ['type' => 'string', 'default' => 'Seguinos'],
				'customTitle'       => ['type' => 'string', 'default' => ''],
				'customSubtitle'    => ['type' => 'string', 'default' => ''],
				'mediaTypeFilter'   => ['type' => 'string', 'default' => 'all'],
				'openInNewTab'      => ['type' => 'boolean', 'default' => true],
				'showHoverOverlay'  => ['type' => 'boolean', 'default' => true],
				'showPostModal'     => ['type' => 'boolean', 'default' => true],
			],
			'supports'        => [
				'align'           => ['wide', 'full'],
				'customClassName' => true,
				'html'            => false,
			],
			'editor_script'   => 'mj-instagram-editor',
			'editor_style'    => 'mj-instagram-editor',
			'style'           => 'mj-instagram-frontend',
			'script'          => 'mj-instagram-frontend',
			'render_callback' => [$this, 'render_block'],
		]);
	}

	/**
	 * Renderiza el bloque en el servidor para el frontend y ServerSideRender del editor.
	 *
	 * @param array $attrs Atributos del bloque.
	 * @return string HTML renderizado.
	 */
	public function render_block($attrs) {
		$attrs = wp_parse_args($attrs, [
			'displayType'       => 'posts',
			'layout'            => 'grid',
			'columnsDesktop'    => 4,
			'columnsMobile'     => 2,
			'limit'             => 8,
			'aspectRatio'       => 'square',
			'spacing'           => 'normal',
			'showProfileHeader' => false,
			'showBio'           => true,
			'showFollowers'     => true,
			'showFollowButton'  => true,
			'followButtonText'  => 'Seguinos',
			'customTitle'       => '',
			'customSubtitle'    => '',
			'mediaTypeFilter'   => 'all',
			'openInNewTab'      => true,
			'showHoverOverlay'  => true,
			'showPostModal'     => true,
		]);

		$displayType = sanitize_key($attrs['displayType']);
		$layout      = in_array($attrs['layout'], ['grid', 'carousel'], true) ? $attrs['layout'] : 'grid';
		$colsDesktop = max(2, min(6, absint($attrs['columnsDesktop'])));
		$colsMobile  = max(1, min(3, absint($attrs['columnsMobile'])));
		$limit       = max(1, min(24, absint($attrs['limit'])));
		$ratio       = in_array($attrs['aspectRatio'], ['square', 'portrait'], true) ? $attrs['aspectRatio'] : 'square';
		$spacing     = in_array($attrs['spacing'], ['compact', 'normal', 'relaxed'], true) ? $attrs['spacing'] : 'normal';

		$profile    = $this->api->get_profile();
		$username   = !empty($profile['username']) ? $profile['username'] : '';
		$profileUrl = !empty($username) ? 'https://instagram.com/' . $username : 'https://instagram.com';
		$target     = !empty($attrs['openInNewTab']) ? ' target="_blank" rel="noopener noreferrer"' : '';

		ob_start();
		?>
		<div class="mj-ig-wrap mj-ig-spacing--<?php echo esc_attr($spacing); ?>">
			<?php
			// Título y bajada opcionales de la sección
			if (!empty($attrs['customTitle']) || !empty($attrs['customSubtitle'])) :
				?>
				<div class="mj-ig-section-header">
					<?php if (!empty($username)) : ?>
						<span class="mj-ig-section-eyebrow">@<?php echo esc_html($username); ?></span>
					<?php endif; ?>
					<?php if (!empty($attrs['customTitle'])) : ?>
						<h2 class="mj-ig-section-title"><?php echo esc_html($attrs['customTitle']); ?></h2>
					<?php endif; ?>
					<?php if (!empty($attrs['customSubtitle'])) : ?>
						<p class="mj-ig-section-subtitle"><?php echo esc_html($attrs['customSubtitle']); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php
			// Cabecera de perfil (si está activada o si se eligió modo perfil / all)
			if (!empty($attrs['showProfileHeader']) || 'profile' === $displayType) :
				$name      = !empty($profile['name']) ? $profile['name'] : (!empty($username) ? '@' . $username : 'Instagram');
				$avatar    = !empty($profile['profile_picture']) ? $profile['profile_picture'] : '';
				$bio       = !empty($profile['bio']) ? $profile['bio'] : '';
				$followers = !empty($profile['followers_count']) ? $profile['followers_count'] : 0;
				?>
				<div class="mj-ig-profile-card">
					<div class="mj-ig-profile-avatar-wrap">
						<a href="<?php echo esc_url($profileUrl); ?>"<?php echo $target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="mj-ig-avatar-ring">
							<?php if (!empty($avatar)) : ?>
								<img src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($name); ?>" class="mj-ig-avatar-img">
							<?php else : ?>
								<div class="mj-ig-avatar-placeholder">
									<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
								</div>
							<?php endif; ?>
						</a>
					</div>

					<div class="mj-ig-profile-info">
						<div class="mj-ig-profile-names">
							<a href="<?php echo esc_url($profileUrl); ?>"<?php echo $target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="mj-ig-profile-username">
								@<?php echo esc_html($username); ?>
								<svg class="mj-ig-verified" width="16" height="16" viewBox="0 0 24 24" fill="#3897f0"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
							</a>
							<span class="mj-ig-profile-fullname"><?php echo esc_html($name); ?></span>
						</div>

						<?php if (!empty($attrs['showBio']) && !empty($bio)) : ?>
							<p class="mj-ig-profile-bio"><?php echo esc_html($bio); ?></p>
						<?php endif; ?>

						<div class="mj-ig-profile-meta">
							<?php if (!empty($attrs['showFollowers']) && $followers > 0) : ?>
								<span class="mj-ig-followers">
									<strong><?php echo esc_html(number_format_i18n($followers)); ?></strong> <?php esc_html_e('seguidores', 'mj-instagram'); ?>
								</span>
							<?php endif; ?>
							<?php if (!empty($profile['media_count'])) : ?>
								<span class="mj-ig-posts-count">
									<strong><?php echo esc_html(number_format_i18n($profile['media_count'])); ?></strong> <?php esc_html_e('publicaciones', 'mj-instagram'); ?>
								</span>
							<?php endif; ?>
						</div>
					</div>

					<?php if (!empty($attrs['showFollowButton'])) : ?>
						<div class="mj-ig-profile-cta">
							<a href="<?php echo esc_url($profileUrl); ?>"<?php echo $target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="mj-ig-btn-follow">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
								<?php echo esc_html(!empty($attrs['followButtonText']) ? $attrs['followButtonText'] : 'Seguinos'); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php
			// Historias activas (Stories)
			if ('stories' === $displayType || 'all' === $displayType) :
				$stories = $this->api->get_stories();
				if (!empty($stories)) :
					?>
					<div class="mj-ig-stories-section">
						<h3 class="mj-ig-subheading"><?php esc_html_e('Historias', 'mj-instagram'); ?></h3>
						<div class="mj-ig-stories-list">
							<?php foreach ($stories as $story) : ?>
								<a href="<?php echo esc_url($story['permalink']); ?>"<?php echo $target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="mj-ig-story-item" data-media-url="<?php echo esc_url($story['media_url']); ?>">
									<div class="mj-ig-story-ring">
										<img src="<?php echo esc_url($story['image_url']); ?>" alt="Historia" class="mj-ig-story-thumb">
									</div>
									<span class="mj-ig-story-label"><?php esc_html_e('Historia', 'mj-instagram'); ?></span>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
					<?php
				endif;
			endif;
			?>

			<?php
			// Destacados (Highlights)
			if ('highlights' === $displayType || 'all' === $displayType) :
				$highlights = $this->api->get_highlights();
				if (!empty($highlights)) :
					?>
					<div class="mj-ig-highlights-section">
						<h3 class="mj-ig-subheading"><?php esc_html_e('Destacados', 'mj-instagram'); ?></h3>
						<div class="mj-ig-highlights-list">
							<?php foreach ($highlights as $hl) : ?>
								<?php
								$hlLink = !empty($hl['link']) ? $hl['link'] : $profileUrl;
								?>
								<a href="<?php echo esc_url($hlLink); ?>"<?php echo $target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="mj-ig-hl-item">
									<div class="mj-ig-hl-circle">
										<?php if (!empty($hl['cover_url'])) : ?>
											<img src="<?php echo esc_url($hl['cover_url']); ?>" alt="<?php echo esc_attr($hl['title']); ?>" class="mj-ig-hl-cover">
										<?php else : ?>
											<div class="mj-ig-hl-placeholder">
												<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
											</div>
										<?php endif; ?>
									</div>
									<span class="mj-ig-hl-title"><?php echo esc_html($hl['title']); ?></span>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
					<?php
				endif;
			endif;
			?>

			<?php
			// Publicaciones (Posts / Feed)
			if ('posts' === $displayType || 'profile' === $displayType || 'all' === $displayType) :
				$media = $this->api->get_media($limit, $attrs['mediaTypeFilter']);
				if (!empty($media)) :
					$grid_classes = [
						'mj-ig-feed',
						'mj-ig-feed--' . $layout,
						'mj-ig-feed--cols-d-' . $colsDesktop,
						'mj-ig-feed--cols-m-' . $colsMobile,
						'mj-ig-feed--ratio-' . $ratio,
					];
					?>
					<div class="mj-ig-feed-container" data-mj-carousel="<?php echo 'carousel' === $layout ? 'true' : 'false'; ?>">
						<?php if ('carousel' === $layout) : ?>
							<button type="button" class="mj-ig-nav-btn mj-ig-nav-prev" aria-label="<?php esc_attr_e('Anterior', 'mj-instagram'); ?>">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
							</button>
						<?php endif; ?>

						<div class="<?php echo esc_attr(implode(' ', $grid_classes)); ?>">
							<?php foreach ($media as $item) : ?>
								<div class="mj-ig-item group" data-post-id="<?php echo esc_attr($item['id']); ?>" data-permalink="<?php echo esc_url($item['permalink']); ?>">
									<a href="<?php echo esc_url($item['permalink']); ?>"<?php echo $target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="mj-ig-item-link">
										<div class="mj-ig-media-wrap">
											<img src="<?php echo esc_url($item['image_url']); ?>" alt="<?php echo esc_attr($item['caption']); ?>" loading="lazy" class="mj-ig-img">

											<?php if ('VIDEO' === $item['type']) : ?>
												<span class="mj-ig-badge-video" title="<?php esc_attr_e('Video / Reel', 'mj-instagram'); ?>">
													<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
												</span>
											<?php elseif ('CAROUSEL_ALBUM' === $item['type']) : ?>
												<span class="mj-ig-badge-carousel" title="<?php esc_attr_e('Álbum', 'mj-instagram'); ?>">
													<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="m3 9 18 0"/><path d="m9 21 0-18"/></svg>
												</span>
											<?php endif; ?>

											<?php if (!empty($attrs['showHoverOverlay'])) : ?>
												<div class="mj-ig-overlay">
													<svg class="mj-ig-overlay-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
													<?php if (!empty($item['caption'])) : ?>
														<p class="mj-ig-overlay-caption"><?php echo esc_html(wp_trim_words($item['caption'], 15, '…')); ?></p>
													<?php endif; ?>
													<span class="mj-ig-overlay-cta"><?php esc_html_e('Ver en Instagram', 'mj-instagram'); ?></span>
												</div>
											<?php endif; ?>
										</div>
									</a>
								</div>
							<?php endforeach; ?>
						</div>

						<?php if ('carousel' === $layout) : ?>
							<button type="button" class="mj-ig-nav-btn mj-ig-nav-next" aria-label="<?php esc_attr_e('Siguiente', 'mj-instagram'); ?>">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
							</button>
						<?php endif; ?>
					</div>
					<?php
				endif;
			endif;
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
