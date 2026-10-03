<?php
/**
 * Conexión y peticiones a la API de Instagram (Graph API).
 *
 * Compatible con Instagram Graph API y tokens de larga duración.
 * Gestiona caché en transitorios, renovación de tokens y datos de prueba.
 *
 * @package MJ_Instagram
 */

defined('ABSPATH') || exit;

class MJ_Instagram_API {

	/**
	 * URL base de la Graph API de Instagram.
	 */
	const API_URL = 'https://graph.instagram.com/';

	/**
	 * Prefijo para transitorios de caché.
	 */
	const CACHE_PREFIX = 'mj_ig_cache_';

	/**
	 * Opciones guardadas del plugin.
	 *
	 * @var array
	 */
	private $options = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->options = get_option('mj_instagram_settings', []);
		if (!is_array($this->options)) {
			$this->options = [];
		}
	}

	/**
	 * Obtiene el token de acceso configurado.
	 * Permite pisarlo con la constante MJ_INSTAGRAM_TOKEN en wp-config.php.
	 *
	 * @return string
	 */
	public function get_access_token() {
		if (defined('MJ_INSTAGRAM_TOKEN') && constant('MJ_INSTAGRAM_TOKEN')) {
			return (string) constant('MJ_INSTAGRAM_TOKEN');
		}
		return !empty($this->options['access_token']) ? trim((string) $this->options['access_token']) : '';
	}

	/**
	 * Obtiene el ID de usuario de Instagram configurado (o 'me').
	 *
	 * @return string
	 */
	public function get_user_id() {
		if (defined('MJ_INSTAGRAM_USER_ID') && constant('MJ_INSTAGRAM_USER_ID')) {
			return (string) constant('MJ_INSTAGRAM_USER_ID');
		}
		return !empty($this->options['user_id']) ? trim((string) $this->options['user_id']) : 'me';
	}

	/**
	 * Duración de la caché en segundos (por defecto 2 horas).
	 *
	 * @return int
	 */
	public function get_cache_ttl() {
		$ttl = !empty($this->options['cache_ttl']) ? absint($this->options['cache_ttl']) : 7200;
		return max(300, $ttl); // Mínimo 5 minutos.
	}

	/**
	 * Comprueba si hay una conexión configurada.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== $this->get_access_token();
	}

	/**
	 * Realiza una llamada HTTP a la API de Instagram.
	 *
	 * @param string $endpoint Endpoint relativo.
	 * @param array  $params   Parámetros query adicionales.
	 * @return array|WP_Error
	 */
	private function request($endpoint, $params = []) {
		$token = $this->get_access_token();
		if ('' === $token) {
			return new WP_Error('no_token', __('No se configuró un token de acceso de Instagram.', 'mj-instagram'));
		}

		$params['access_token'] = $token;
		$url                    = add_query_arg($params, self::API_URL . ltrim($endpoint, '/'));

		$response = wp_remote_get($url, [
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => [
				'Accept' => 'application/json',
			],
		]);

		if (is_wp_error($response)) {
			if (class_exists('MJ_Log')) {
				MJ_Log::error('Instagram API: error de red al consultar ' . $endpoint . ': ' . $response->get_error_message(), 'instagram');
			}
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code < 200 || $code >= 300 || !is_array($body)) {
			/* translators: %d: código de respuesta HTTP de error. */
			$msg = isset($body['error']['message']) ? $body['error']['message'] : sprintf(__('Error de respuesta de Instagram (código %d).', 'mj-instagram'), $code);
			if (class_exists('MJ_Log')) {
				MJ_Log::error('Instagram API fallo HTTP ' . $code . ': ' . $msg, 'instagram');
			}
			return new WP_Error('api_error', $msg, ['status' => $code, 'response' => $body]);
		}

		return $body;
	}

	/**
	 * Obtiene los datos del perfil de Instagram.
	 *
	 * @param bool $force_refresh Si true, saltea el caché.
	 * @return array|WP_Error
	 */
	public function get_profile($force_refresh = false) {
		if (!$this->is_connected()) {
			return $this->get_mock_profile();
		}

		$cache_key = self::CACHE_PREFIX . 'profile_' . md5($this->get_access_token());
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if (false !== $cached && is_array($cached)) {
				return $cached;
			}
		}

		$userId   = $this->get_user_id();
		$endpoint = ('me' === $userId) ? 'me' : $userId;
		$fields   = 'id,username,name,account_type,media_count,profile_picture_url';

		$res = $this->request($endpoint, ['fields' => $fields]);
		if (is_wp_error($res)) {
			// Si falla pero tenemos datos guardados en options de la última verificación, usamos eso de respaldo.
			if (!empty($this->options['cached_profile']) && is_array($this->options['cached_profile'])) {
				return $this->options['cached_profile'];
			}
			return $res;
		}

		$profile = [
			'id'              => isset($res['id']) ? sanitize_text_field($res['id']) : '',
			'username'        => isset($res['username']) ? sanitize_text_field($res['username']) : '',
			'name'            => isset($res['name']) ? sanitize_text_field($res['name']) : '',
			'account_type'    => isset($res['account_type']) ? sanitize_text_field($res['account_type']) : '',
			'media_count'     => isset($res['media_count']) ? absint($res['media_count']) : 0,
			'profile_picture' => isset($res['profile_picture_url']) ? esc_url_raw($res['profile_picture_url']) : '',
			'bio'             => !empty($this->options['custom_bio']) ? sanitize_textarea_field($this->options['custom_bio']) : '',
			'followers_count' => !empty($this->options['custom_followers']) ? absint($this->options['custom_followers']) : 0,
		];

		set_transient($cache_key, $profile, $this->get_cache_ttl());

		// Guardar copia local de respaldo
		$this->options['cached_profile'] = $profile;
		update_option('mj_instagram_settings', $this->options);

		return $profile;
	}

	/**
	 * Obtiene publicaciones recientes del perfil.
	 *
	 * @param int    $limit        Cantidad máxima.
	 * @param string $media_type   'all' | 'IMAGE' | 'VIDEO' | 'CAROUSEL_ALBUM'.
	 * @param bool   $force_refresh Si true, saltea el caché.
	 * @return array
	 */
	public function get_media($limit = 12, $media_type = 'all', $force_refresh = false) {
		if (!$this->is_connected()) {
			return $this->get_mock_media($limit, $media_type);
		}

		$limit     = max(1, min(40, absint($limit)));
		$cache_key = self::CACHE_PREFIX . 'media_' . md5($this->get_access_token() . '_' . $limit . '_' . $media_type);

		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if (false !== $cached && is_array($cached)) {
				return $cached;
			}
		}

		$userId   = $this->get_user_id();
		$endpoint = ('me' === $userId) ? 'me/media' : $userId . '/media';
		$fields   = 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,children{id,media_type,media_url,thumbnail_url}';

		// Pedimos un poco más si hay filtro de tipo
		$fetch_limit = ('all' === $media_type) ? $limit : min(40, $limit * 2);
		$res         = $this->request($endpoint, [
			'fields' => $fields,
			'limit'  => $fetch_limit,
		]);

		if (is_wp_error($res) || empty($res['data']) || !is_array($res['data'])) {
			return $this->get_mock_media($limit, $media_type);
		}

		$items = [];
		foreach ($res['data'] as $item) {
			$type = isset($item['media_type']) ? sanitize_text_field($item['media_type']) : 'IMAGE';

			if ('all' !== $media_type && $type !== $media_type) {
				continue;
			}

			$imageUrl = '';
			if ('VIDEO' === $type) {
				$imageUrl = !empty($item['thumbnail_url']) ? esc_url_raw($item['thumbnail_url']) : (!empty($item['media_url']) ? esc_url_raw($item['media_url']) : '');
			} else {
				$imageUrl = !empty($item['media_url']) ? esc_url_raw($item['media_url']) : '';
			}

			if ('' === $imageUrl) {
				continue;
			}

			$items[] = [
				'id'            => sanitize_text_field($item['id']),
				'type'          => $type,
				'caption'       => isset($item['caption']) ? sanitize_text_field($item['caption']) : '',
				'image_url'     => $imageUrl,
				'permalink'     => isset($item['permalink']) ? esc_url_raw($item['permalink']) : 'https://instagram.com',
				'timestamp'     => isset($item['timestamp']) ? sanitize_text_field($item['timestamp']) : '',
				'thumbnail_url' => !empty($item['thumbnail_url']) ? esc_url_raw($item['thumbnail_url']) : $imageUrl,
			];

			if (count($items) >= $limit) {
				break;
			}
		}

		set_transient($cache_key, $items, $this->get_cache_ttl());
		return $items;
	}

	/**
	 * Obtiene historias activas (stories).
	 *
	 * @param bool $force_refresh Si true, saltea el caché.
	 * @return array
	 */
	public function get_stories($force_refresh = false) {
		if (!$this->is_connected()) {
			return $this->get_mock_stories();
		}

		$cache_key = self::CACHE_PREFIX . 'stories_' . md5($this->get_access_token());
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if (false !== $cached && is_array($cached)) {
				return $cached;
			}
		}

		$userId   = $this->get_user_id();
		$endpoint = ('me' === $userId) ? 'me/stories' : $userId . '/stories';
		$fields   = 'id,media_type,media_url,permalink,thumbnail_url,timestamp';

		$res = $this->request($endpoint, ['fields' => $fields]);

		if (is_wp_error($res) || empty($res['data']) || !is_array($res['data'])) {
			// Si la cuenta o el token no admiten lectura de stories, devolvemos historias mock/personalizadas
			return $this->get_mock_stories();
		}

		$stories = [];
		foreach ($res['data'] as $item) {
			$type     = isset($item['media_type']) ? sanitize_text_field($item['media_type']) : 'IMAGE';
			$imageUrl = ('VIDEO' === $type && !empty($item['thumbnail_url'])) ? esc_url_raw($item['thumbnail_url']) : esc_url_raw($item['media_url']);

			if ('' === $imageUrl) {
				continue;
			}

			$stories[] = [
				'id'        => sanitize_text_field($item['id']),
				'type'      => $type,
				'image_url' => $imageUrl,
				'media_url' => esc_url_raw($item['media_url']),
				'permalink' => isset($item['permalink']) ? esc_url_raw($item['permalink']) : 'https://instagram.com',
				'timestamp' => isset($item['timestamp']) ? sanitize_text_field($item['timestamp']) : '',
			];
		}

		set_transient($cache_key, $stories, 1800); // 30 minutos de caché para historias
		return $stories;
	}

	/**
	 * Obtiene destacados (highlights).
	 * Permite usar destacados configurados a mano en los ajustes del plugin
	 * o simulados si la API no tiene alcance de highlights.
	 *
	 * @return array
	 */
	public function get_highlights() {
		// Destacados manuales configurados en wp-admin
		if (!empty($this->options['custom_highlights']) && is_array($this->options['custom_highlights'])) {
			$curated = [];
			foreach ($this->options['custom_highlights'] as $item) {
				if (!empty($item['title'])) {
					$curated[] = [
						'id'        => !empty($item['id']) ? sanitize_text_field($item['id']) : 'hl_' . sanitize_title($item['title']),
						'title'     => sanitize_text_field($item['title']),
						'cover_url' => !empty($item['cover_url']) ? esc_url_raw($item['cover_url']) : '',
						'link'      => !empty($item['link']) ? esc_url_raw($item['link']) : '',
					];
				}
			}
			if (!empty($curated)) {
				return $curated;
			}
		}

		return $this->get_mock_highlights();
	}

	/**
	 * Limpia toda la caché de transitorios del plugin.
	 *
	 * @return void
	 */
	public function clear_cache() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . self::CACHE_PREFIX . '%',
				'_transient_timeout_' . self::CACHE_PREFIX . '%'
			)
		);
	}

	/**
	 * Prueba la conexión con un token específico.
	 *
	 * @param string $test_token   Token a probar.
	 * @param string $test_user_id ID opcional.
	 * @return array|WP_Error
	 */
	public function test_connection($test_token, $test_user_id = 'me') {
		if ('' === trim($test_token)) {
			return new WP_Error('empty_token', __('Por favor ingresá un token de acceso.', 'mj-instagram'));
		}

		$test_user_id = ('' === trim($test_user_id)) ? 'me' : trim($test_user_id);
		$url          = add_query_arg([
			'fields'       => 'id,username,name,account_type,media_count,profile_picture_url',
			'access_token' => $test_token,
		], self::API_URL . ltrim($test_user_id, '/'));

		$res = wp_remote_get($url, ['timeout' => 15]);
		if (is_wp_error($res)) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code($res);
		$body = json_decode(wp_remote_retrieve_body($res), true);

		if ($code < 200 || $code >= 300 || !is_array($body)) {
			/* translators: %d: código de respuesta HTTP. */
			$msg = isset($body['error']['message']) ? $body['error']['message'] : sprintf(__('Error al verificar conexión (%d).', 'mj-instagram'), $code);
			return new WP_Error('api_error', $msg);
		}

		return [
			'id'              => isset($body['id']) ? sanitize_text_field($body['id']) : '',
			'username'        => isset($body['username']) ? sanitize_text_field($body['username']) : '',
			'name'            => isset($body['name']) ? sanitize_text_field($body['name']) : '',
			'account_type'    => isset($body['account_type']) ? sanitize_text_field($body['account_type']) : '',
			'media_count'     => isset($body['media_count']) ? absint($body['media_count']) : 0,
			'profile_picture' => isset($body['profile_picture_url']) ? esc_url_raw($body['profile_picture_url']) : '',
		];
	}

	/**
	 * Refresca el token de larga duración si está por expirar.
	 *
	 * @return bool|WP_Error
	 */
	public function refresh_long_lived_token() {
		$token = $this->get_access_token();
		if ('' === $token) {
			return false;
		}

		$url = add_query_arg([
			'grant_type'   => 'ig_refresh_token',
			'access_token' => $token,
		], self::API_URL . 'refresh_access_token');

		$res = wp_remote_get($url, ['timeout' => 15]);
		if (is_wp_error($res)) {
			return $res;
		}

		$body = json_decode(wp_remote_retrieve_body($res), true);
		if (!empty($body['access_token'])) {
			$this->options['access_token']  = sanitize_text_field($body['access_token']);
			$this->options['token_expires'] = time() + (isset($body['expires_in']) ? absint($body['expires_in']) : 5184000);
			update_option('mj_instagram_settings', $this->options);
			return true;
		}

		return false;
	}

	/* -------------------------------------------------------------------------
	 * Datos de prueba / demostración
	 * ---------------------------------------------------------------------- */

	/**
	 * Perfil de prueba para demostración y previsualización en el editor.
	 *
	 * @return array
	 */
	public function get_mock_profile() {
		$handle = !empty($this->options['fallback_handle']) ? sanitize_text_field($this->options['fallback_handle']) : 'mi_tienda';
		return [
			'id'              => 'mock_profile',
			'username'        => ltrim($handle, '@'),
			'name'            => !empty($this->options['fallback_name']) ? sanitize_text_field($this->options['fallback_name']) : 'Mi Tienda',
			'account_type'    => 'BUSINESS',
			'media_count'     => 128,
			'profile_picture' => !empty($this->options['fallback_avatar']) ? esc_url_raw($this->options['fallback_avatar']) : '',
			'bio'             => !empty($this->options['custom_bio']) ? sanitize_textarea_field($this->options['custom_bio']) : 'Seguinos para novedades, promociones exclusivas y lanzamientos.',
			'followers_count' => !empty($this->options['custom_followers']) ? absint($this->options['custom_followers']) : 3450,
		];
	}

	/**
	 * Publicaciones de prueba para demostración y previsualización.
	 *
	 * @param int    $limit Cantidad.
	 * @param string $type  Tipo de medio.
	 * @return array
	 */
	public function get_mock_media($limit = 12, $type = 'all') {
		$limit = max(1, min(24, absint($limit)));
		$posts = [];

		$stock_photos = [
			['https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=600&q=80', 'Nueva colección ya disponible en tienda ✨', 'IMAGE'],
			['https://images.unsplash.com/photo-1512496015851-a90fb38ba796?w=600&q=80', 'Detalles que marcan la diferencia 🌿', 'IMAGE'],
			['https://images.unsplash.com/photo-1492707892479-7bc8d5a4ee93?w=600&q=80', 'Preparando pedidos de esta semana con mucho amor 📦', 'VIDEO'],
			['https://images.unsplash.com/photo-1534452203293-494d7ddbf7e0?w=600&q=80', 'Los favoritos que nunca fallan. Conseguilos online ✨', 'IMAGE'],
			['https://images.unsplash.com/photo-1483985988355-763728e1935b?w=600&q=80', 'Behind the scenes de la nueva sesión 📸', 'CAROUSEL_ALBUM'],
			['https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?w=600&q=80', 'Estilo y calidad pensados para vos 🤍', 'IMAGE'],
			['https://images.unsplash.com/photo-1469334031218-e382a71b716b?w=600&q=80', 'Elegí tus preferidos antes de que se agoten', 'IMAGE'],
			['https://images.unsplash.com/photo-1445205170230-053b83016050?w=600&q=80', 'Tendencias de esta temporada que te van a encantar', 'VIDEO'],
			['https://images.unsplash.com/photo-1485230895905-ec40ba36b9bc?w=600&q=80', 'Inspiración del día para tu rutina diaria', 'IMAGE'],
			['https://images.unsplash.com/photo-1509631179647-0177331693ae?w=600&q=80', 'Envíos a todo el país todas las semanas 🚚', 'IMAGE'],
			['https://images.unsplash.com/photo-1487412720507-e7ab37603c6f?w=600&q=80', 'Amamos ver cómo combinan nuestros productos 💕', 'IMAGE'],
			['https://images.unsplash.com/photo-1529139574466-a303027c1d8b?w=600&q=80', 'Gracias por elegirnos y acompañarnos siempre 🙌', 'CAROUSEL_ALBUM'],
		];

		$user = $this->get_profile();
		$url  = !empty($user['username']) ? 'https://instagram.com/' . $user['username'] : 'https://instagram.com';

		$idx         = 0;
		$total_posts = count($posts);
		while ($total_posts < $limit) {
			$sample = $stock_photos[$idx % count($stock_photos)];
			if ('all' === $type || $sample[2] === $type) {
				$posts[] = [
					'id'            => 'mock_' . ($total_posts + 1),
					'type'          => $sample[2],
					'caption'       => $sample[1],
					'image_url'     => $sample[0],
					'permalink'     => $url,
					'timestamp'     => gmdate('Y-m-d\TH:i:s\Z', time() - ($total_posts * 86400)),
					'thumbnail_url' => $sample[0],
				];
				$total_posts++;
			}
			$idx++;
			if ($idx > 40) {
				break;
			}
		}

		return $posts;
	}

	/**
	 * Historias de prueba para demostración y previsualización.
	 *
	 * @return array
	 */
	public function get_mock_stories() {
		$user = $this->get_profile();
		$url  = !empty($user['username']) ? 'https://instagram.com/' . $user['username'] : 'https://instagram.com';

		return [
			[
				'id'        => 'mock_story_1',
				'type'      => 'IMAGE',
				'image_url' => 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=500&q=80',
				'permalink' => $url,
				'timestamp' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
			],
			[
				'id'        => 'mock_story_2',
				'type'      => 'IMAGE',
				'image_url' => 'https://images.unsplash.com/photo-1512496015851-a90fb38ba796?w=500&q=80',
				'permalink' => $url,
				'timestamp' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200),
			],
			[
				'id'        => 'mock_story_3',
				'type'      => 'IMAGE',
				'image_url' => 'https://images.unsplash.com/photo-1492707892479-7bc8d5a4ee93?w=500&q=80',
				'permalink' => $url,
				'timestamp' => gmdate('Y-m-d\TH:i:s\Z', time() - 14400),
			],
		];
	}

	/**
	 * Destacados de prueba para demostración y previsualización.
	 *
	 * @return array
	 */
	public function get_mock_highlights() {
		$user = $this->get_profile();
		$url  = !empty($user['username']) ? 'https://instagram.com/' . $user['username'] : 'https://instagram.com';

		return [
			[
				'id'        => 'hl_1',
				'title'     => 'Novedades',
				'cover_url' => 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=300&q=80',
				'link'      => $url,
			],
			[
				'id'        => 'hl_2',
				'title'     => 'Clientas',
				'cover_url' => 'https://images.unsplash.com/photo-1534452203293-494d7ddbf7e0?w=300&q=80',
				'link'      => $url,
			],
			[
				'id'        => 'hl_3',
				'title'     => 'Envíos',
				'cover_url' => 'https://images.unsplash.com/photo-1509631179647-0177331693ae?w=300&q=80',
				'link'      => $url,
			],
			[
				'id'        => 'hl_4',
				'title'     => 'Preguntas',
				'cover_url' => 'https://images.unsplash.com/photo-1512496015851-a90fb38ba796?w=300&q=80',
				'link'      => $url,
			],
			[
				'id'        => 'hl_5',
				'title'     => 'Colección',
				'cover_url' => 'https://images.unsplash.com/photo-1483985988355-763728e1935b?w=300&q=80',
				'link'      => $url,
			],
		];
	}
}
