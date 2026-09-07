<?php

defined( 'ABSPATH' ) || exit;

/**
 * Connects published WordPress content to the hosted Nimble Relay service.
 */
final class Refinery_Relay {
	private const OPTION = 'refinery_relay_settings';
	private const ROUTE_NAMESPACE = 'refinery-relay/api/relay';

	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'admin_post_refinery_relay_generate_token', array( $this, 'generate_token' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_footer', array( $this, 'render_widget' ), 100 );
	}

	public static function activate() {
		$settings = get_option( self::OPTION, array() );
		if ( empty( $settings['content_sources'] ) ) {
			$settings['content_sources'] = array( 'page', 'post' );
		}
		if ( empty( $settings['bearer_token'] ) ) {
			$settings['bearer_token'] = self::new_token();
		}
		update_option( self::OPTION, $settings, false );
		update_option( 'refinery_relay_version', REFINERY_RELAY_VERSION, false );
	}

	public static function uninstall() {
		if ( defined( 'REFINERY_RELAY_REMOVE_DATA' ) && REFINERY_RELAY_REMOVE_DATA ) {
			delete_option( self::OPTION );
			delete_option( 'refinery_relay_version' );
		}
	}

	private static function new_token() {
		return wp_generate_password( 64, false, false );
	}

	public function admin_menu() {
		add_options_page(
			__( 'Relay Settings', 'refinery-relay' ),
			__( 'Relay', 'refinery-relay' ),
			'manage_options',
			'refinery-relay',
			array( $this, 'settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'refinery_relay',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$current   = $this->settings();
		$available = array_keys( $this->content_sources() );
		$requested = isset( $input['content_sources'] ) ? (array) $input['content_sources'] : array();
		$sources   = array_values( array_intersect( $available, array_map( 'sanitize_key', $requested ) ) );

		return array(
			'content_sources' => $sources,
			'bearer_token'    => $current['bearer_token'],
			'widget_embed'    => $this->sanitize_widget_embed( $input['widget_embed'] ?? '' ),
		);
	}

	/**
	 * Only retain a Relay script and its nimble-relay-chat element.
	 */
	private function sanitize_widget_embed( $markup ) {
		$markup = trim( wp_unslash( (string) $markup ) );
		if ( '' === $markup ) {
			return '';
		}
		$script_count = preg_match_all( '/<script\b[^>]*>/i', $markup, $script_tags );
		$source_count = preg_match_all( '/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $markup, $script_sources );
		if ( ! $script_count || $script_count !== $source_count ) {
			add_settings_error( self::OPTION, 'invalid_relay_script', __( 'Paste the complete Relay embed code, including its hosted script URL.', 'refinery-relay' ) );
			return '';
		}

		foreach ( $script_sources[1] as $src ) {
			$src                = html_entity_decode( $src );
			$host               = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
			$scheme             = strtolower( (string) wp_parse_url( $src, PHP_URL_SCHEME ) );
			$is_relay_subdomain = strlen( $host ) > 16 && '.relay.niimble.io' === substr( $host, -16 );
			if ( 'https' !== $scheme || ( 'relay.niimble.io' !== $host && ! $is_relay_subdomain ) ) {
				add_settings_error( self::OPTION, 'invalid_relay_script', __( 'The widget script must use HTTPS and be hosted on relay.niimble.io.', 'refinery-relay' ) );
				return '';
			}
		}

		// Executable inline script is never needed by the Relay embed.
		$markup = preg_replace( '/(<script\b[^>]*>).*?(<\/script>)/is', '$1$2', $markup );

		$allowed = array(
			'script' => array( 'src' => true, 'defer' => true, 'async' => true, 'type' => true ),
			'nimble-relay-chat' => array(
				'relay-url'   => true,
				'widget-key'  => true,
				'storage-key' => true,
			),
		);
		return wp_kses( $markup, $allowed );
	}

	private function settings() {
		return wp_parse_args(
			get_option( self::OPTION, array() ),
			array(
				'content_sources' => array( 'page', 'post' ),
				'bearer_token'    => '',
				'widget_embed'    => '',
			)
		);
	}

	private function content_sources() {
		$sources = array();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( $post_types as $post_type => $object ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}
			$sources[ $post_type ] = array(
				'label'       => $object->labels->name,
				'description' => sprintf( __( 'Published %s content', 'refinery-relay' ), strtolower( $object->labels->singular_name ) ),
			);
		}

		return apply_filters( 'refinery_relay_content_sources', $sources );
	}

	public function generate_token() {
		check_admin_referer( 'refinery_relay_generate_token' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot do that.', 'refinery-relay' ), '', array( 'response' => 403 ) );
		}
		$settings                 = $this->settings();
		$settings['bearer_token'] = self::new_token();
		update_option( self::OPTION, $settings, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'refinery-relay', 'token-generated' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/documents',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'documents' ),
				'permission_callback' => array( $this, 'authorize_feed' ),
				'args'                => array(
					'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'default' => 100, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	public function authorize_feed( WP_REST_Request $request ) {
		$expected = $this->settings()['bearer_token'];
		$header   = $request->get_header( 'authorization' );
		$provided = preg_match( '/^Bearer\s+(.+)$/i', $header, $match ) ? trim( $match[1] ) : '';
		if ( ! $expected || ! $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error( 'refinery_relay_unauthorized', __( 'A valid Relay bearer token is required.', 'refinery-relay' ), array( 'status' => 401 ) );
		}
		return true;
	}

	public function documents( WP_REST_Request $request ) {
		$settings = $this->settings();
		$allowed  = array_keys( $this->content_sources() );
		$types    = array_values( array_intersect( $allowed, (array) $settings['content_sources'] ) );
		$page     = max( 1, (int) $request['page'] );
		$per_page = min( 100, max( 1, (int) $request['per_page'] ) );

		if ( ! $types ) {
			return rest_ensure_response( array( 'documents' => array(), 'page' => $page, 'total_pages' => 0, 'total' => 0 ) );
		}

		$query = new WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
			)
		);

		$documents = array_map( array( $this, 'format_document' ), $query->posts );
		$response  = rest_ensure_response(
			array(
				'documents'   => $documents,
				'page'        => $page,
				'total_pages' => (int) $query->max_num_pages,
				'total'       => (int) $query->found_posts,
			)
		);
		$response->header( 'Cache-Control', 'private, max-age=60' );
		return $response;
	}

	public function format_document( WP_Post $post ) {
		$content = apply_filters( 'the_content', $post->post_content );
		$document = array(
			'id'          => $post->post_type . ':' . $post->ID,
			'title'       => get_the_title( $post ),
			'content'     => wp_strip_all_tags( $content, true ),
			'url'         => get_permalink( $post ),
			'content_type'=> $post->post_type,
			'published_at'=> get_post_time( DATE_ATOM, true, $post ),
			'updated_at'  => get_post_modified_time( DATE_ATOM, true, $post ),
		);

		return apply_filters( 'refinery_relay_document', $document, $post );
	}

	public function render_widget() {
		$embed = $this->settings()['widget_embed'];
		if ( ! $embed || is_admin() ) {
			return;
		}
		echo "\n<!-- Refinery Relay widget -->\n" . $embed . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Saved through the strict Relay-only allowlist above.
	}

	public function admin_assets( $hook ) {
		if ( 'settings_page_refinery-relay' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'refinery-relay-admin', plugins_url( 'assets/admin.css', REFINERY_RELAY_FILE ), array(), REFINERY_RELAY_VERSION );
		wp_enqueue_script( 'refinery-relay-admin', plugins_url( 'assets/admin.js', REFINERY_RELAY_FILE ), array(), REFINERY_RELAY_VERSION, true );
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->settings();
		$sources  = $this->content_sources();
		$endpoint = rest_url( self::ROUTE_NAMESPACE . '/documents' );
		?>
		<div class="wrap relay-admin">
			<header class="relay-header">
				<div><span class="relay-eyebrow"><i></i><?php esc_html_e( 'Nimble Relay · WordPress', 'refinery-relay' ); ?></span><h1><?php esc_html_e( 'Relay Settings', 'refinery-relay' ); ?></h1><p><?php esc_html_e( 'Connect this WordPress site to Relay, choose the content it should understand, and place the chat widget where visitors need it.', 'refinery-relay' ); ?></p></div>
				<div class="relay-actions"><span class="relay-pill"><i></i><?php echo $settings['widget_embed'] ? esc_html__( 'Direct widget configured', 'refinery-relay' ) : esc_html__( 'Widget not configured', 'refinery-relay' ); ?></span><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><?php wp_nonce_field( 'refinery_relay_generate_token' ); ?><input type="hidden" name="action" value="refinery_relay_generate_token"><button class="button relay-token" type="submit"><?php esc_html_e( 'Generate bearer token', 'refinery-relay' ); ?></button></form></div>
			</header>
			<?php if ( isset( $_GET['token-generated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'A new bearer token was generated. Update the feed credentials in Relay.', 'refinery-relay' ); ?></p></div><?php endif; ?>
			<form action="options.php" method="post">
				<?php settings_fields( 'refinery_relay' ); ?>
				<section class="relay-panel"><div class="relay-panel-title"><span class="dashicons dashicons-database"></span><div><h2><?php esc_html_e( 'Content sources', 'refinery-relay' ); ?></h2><p><?php esc_html_e( 'Choose which WordPress content families Relay should ingest and make searchable.', 'refinery-relay' ); ?></p></div></div>
					<div class="relay-source-grid">
						<?php foreach ( $sources as $slug => $source ) : $checked = in_array( $slug, (array) $settings['content_sources'], true ); ?>
							<label class="relay-source<?php echo $checked ? ' is-selected' : ''; ?>"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[content_sources][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?>><span><strong><?php echo esc_html( $source['label'] ); ?></strong><small><?php echo esc_html( $source['description'] ); ?></small></span><b aria-hidden="true">✓</b></label>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Only published content is included. Sources added by other plugins appear automatically.', 'refinery-relay' ); ?></p>
					<div class="relay-endpoint-label"><strong><?php esc_html_e( 'Feed endpoint', 'refinery-relay' ); ?></strong><span>● <?php esc_html_e( 'HTTP feed', 'refinery-relay' ); ?></span></div>
					<div class="relay-endpoint"><code>GET</code><input id="relay-endpoint" readonly value="<?php echo esc_attr( $endpoint ); ?>"><button class="button relay-copy" type="button" data-copy="#relay-endpoint"><span class="dashicons dashicons-admin-page"></span><?php esc_html_e( 'Copy endpoint', 'refinery-relay' ); ?></button></div>
					<div class="relay-secret"><label for="relay-token"><strong><?php esc_html_e( 'Bearer token', 'refinery-relay' ); ?></strong></label><div><input id="relay-token" type="password" readonly value="<?php echo esc_attr( $settings['bearer_token'] ); ?>"><button class="button relay-reveal" type="button" data-reveal="#relay-token"><?php esc_html_e( 'Show', 'refinery-relay' ); ?></button><button class="button relay-copy" type="button" data-copy="#relay-token"><?php esc_html_e( 'Copy', 'refinery-relay' ); ?></button></div></div>
					<p class="description"><?php esc_html_e( 'Add this URL to Relay as an HTTP feed and use the bearer token for authentication.', 'refinery-relay' ); ?></p>
				</section>
				<section class="relay-panel"><div class="relay-panel-title"><span class="dashicons dashicons-editor-code"></span><div><h2><?php esc_html_e( 'Direct Relay widget', 'refinery-relay' ); ?></h2><p><?php esc_html_e( 'Paste the public widget mount markup copied from Relay’s Chat widget section.', 'refinery-relay' ); ?></p></div></div><label class="relay-widget-label" for="relay-widget"><?php esc_html_e( 'Relay widget embed code', 'refinery-relay' ); ?></label><textarea id="relay-widget" name="<?php echo esc_attr( self::OPTION ); ?>[widget_embed]" rows="10" spellcheck="false" placeholder="&lt;script src=&quot;https://relay.niimble.io/niimble-relay-widget.js&quot; defer&gt;&lt;/script&gt;&#10;&lt;nimble-relay-chat relay-url=&quot;https://relay.niimble.io&quot; widget-key=&quot;…&quot;&gt;&lt;/nimble-relay-chat&gt;"><?php echo esc_textarea( $settings['widget_embed'] ); ?></textarea><p class="description"><?php esc_html_e( 'The plugin loads the Relay widget script from the markup’s relay URL. Configure the site origin in Relay and never paste a private chat token here.', 'refinery-relay' ); ?></p></section>
				<footer class="relay-footer"><span><?php esc_html_e( 'Changes apply immediately to this WordPress site.', 'refinery-relay' ); ?></span><?php submit_button( __( 'Save Relay settings', 'refinery-relay' ), 'primary', 'submit', false ); ?></footer>
			</form>
		</div>
		<?php
	}
}
