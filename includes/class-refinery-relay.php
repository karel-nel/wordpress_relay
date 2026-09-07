<?php

defined( 'ABSPATH' ) || exit;

final class Refinery_Relay {
	private static $instance;
	private $conversations;
	private $messages;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->conversations = $wpdb->prefix . 'relay_conversations';
		$this->messages      = $wpdb->prefix . 'relay_messages';
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_relay_update', array( $this, 'update_conversation' ) );
		add_shortcode( 'refinery_relay', array( $this, 'shortcode' ) );
		add_action( 'wp_footer', array( $this, 'floating_widget' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}relay_conversations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id varchar(36) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL,
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'open',
			page_url text NULL,
			user_agent text NULL,
			ip_hash varchar(64) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			KEY email (email),
			KEY status_updated (status,updated_at)
		) $charset;" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}relay_messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			author_type varchar(20) NOT NULL DEFAULT 'visitor',
			author_id bigint(20) unsigned NULL,
			body longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation (conversation_id,created_at)
		) $charset;" );
		add_option( 'refinery_relay_version', REFINERY_RELAY_VERSION );
	}

	public static function uninstall() {
		if ( ! defined( 'REFINERY_RELAY_REMOVE_DATA' ) || ! REFINERY_RELAY_REMOVE_DATA ) {
			return;
		}
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}relay_messages" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}relay_conversations" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_option( 'refinery_relay_settings' );
		delete_option( 'refinery_relay_version' );
	}

	public function register_routes() {
		register_rest_route( 'refinery-relay/v1', '/conversations', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'create_conversation' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'name'    => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
				'email'   => array( 'sanitize_callback' => 'sanitize_email', 'required' => true ),
				'subject' => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
				'message' => array( 'sanitize_callback' => 'sanitize_textarea_field', 'required' => true ),
			),
		) );
	}

	public function create_conversation( WP_REST_Request $request ) {
		global $wpdb;
		$email   = sanitize_email( $request['email'] );
		$message = trim( (string) $request['message'] );
		if ( $request->get_param( 'website' ) ) {
			return new WP_REST_Response( array( 'success' => true ), 201 );
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message );
		if ( ! is_email( $email ) || '' === $message || $length > 10000 ) {
			return new WP_Error( 'relay_invalid', __( 'Enter a valid email address and message.', 'refinery-relay' ), array( 'status' => 422 ) );
		}
		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rate_key = 'relay_rate_' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
		if ( get_transient( $rate_key ) ) {
			return new WP_Error( 'relay_rate_limited', __( 'Please wait before sending another message.', 'refinery-relay' ), array( 'status' => 429 ) );
		}
		set_transient( $rate_key, 1, MINUTE_IN_SECONDS );
		$now       = current_time( 'mysql', true );
		$public_id = wp_generate_uuid4();
		$inserted  = $wpdb->insert( $this->conversations, array(
			'public_id'  => $public_id,
			'name'       => sanitize_text_field( $request['name'] ),
			'email'      => $email,
			'subject'    => sanitize_text_field( $request['subject'] ),
			'status'     => 'open',
			'page_url'   => esc_url_raw( $request->get_param( 'page_url' ) ),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'ip_hash'    => hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ),
			'created_at' => $now,
			'updated_at' => $now,
		), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		if ( ! $inserted ) {
			return new WP_Error( 'relay_database', __( 'The message could not be saved.', 'refinery-relay' ), array( 'status' => 500 ) );
		}
		$conversation_id = (int) $wpdb->insert_id;
		$wpdb->insert( $this->messages, array( 'conversation_id' => $conversation_id, 'author_type' => 'visitor', 'body' => $message, 'created_at' => $now ), array( '%d', '%s', '%s', '%s' ) );
		$settings = $this->settings();
		wp_mail( $settings['notification_email'], sprintf( '[%s] %s', get_bloginfo( 'name' ), $request['subject'] ?: __( 'New Relay message', 'refinery-relay' ) ), $message );
		do_action( 'refinery_relay_conversation_created', $conversation_id, $request );
		return new WP_REST_Response( array( 'success' => true, 'id' => $public_id, 'message' => $settings['success_message'] ), 201 );
	}

	public function register_assets() {
		wp_register_style( 'refinery-relay', plugins_url( 'assets/relay.css', REFINERY_RELAY_FILE ), array(), REFINERY_RELAY_VERSION );
		wp_register_script( 'refinery-relay', plugins_url( 'assets/relay.js', REFINERY_RELAY_FILE ), array(), REFINERY_RELAY_VERSION, true );
	}

	public function shortcode( $atts = array() ) {
		wp_enqueue_style( 'refinery-relay' );
		wp_enqueue_script( 'refinery-relay' );
		wp_localize_script( 'refinery-relay', 'refineryRelay', array( 'endpoint' => rest_url( 'refinery-relay/v1/conversations' ), 'error' => __( 'Something went wrong. Please try again.', 'refinery-relay' ) ) );
		return $this->form( false );
	}

	public function floating_widget() {
		$settings = $this->settings();
		if ( empty( $settings['widget_enabled'] ) || is_admin() ) {
			return;
		}
		wp_enqueue_style( 'refinery-relay' );
		wp_enqueue_script( 'refinery-relay' );
		wp_localize_script( 'refinery-relay', 'refineryRelay', array( 'endpoint' => rest_url( 'refinery-relay/v1/conversations' ), 'error' => __( 'Something went wrong. Please try again.', 'refinery-relay' ) ) );
		printf( '<div class="relay-dock relay-dock--%s" style="--relay-accent:%s"><button class="relay-launcher" type="button" aria-expanded="false" aria-controls="relay-panel">%s</button><div id="relay-panel" class="relay-panel" hidden>%s</div></div>', esc_attr( $settings['position'] ), esc_attr( $settings['accent_color'] ), esc_html( $settings['button_label'] ), $this->form( true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function form( $compact ) {
		$s = $this->settings();
		ob_start(); ?>
		<section class="relay-card<?php echo $compact ? ' relay-card--compact' : ''; ?>">
			<h2><?php echo esc_html( $s['title'] ); ?></h2><p><?php echo esc_html( $s['prompt'] ); ?></p>
			<form class="relay-form"><label><?php esc_html_e( 'Name', 'refinery-relay' ); ?><input name="name" autocomplete="name"></label><label><?php esc_html_e( 'Email', 'refinery-relay' ); ?><input type="email" name="email" autocomplete="email" required></label><label><?php esc_html_e( 'Subject', 'refinery-relay' ); ?><input name="subject"></label><label><?php esc_html_e( 'How can we help?', 'refinery-relay' ); ?><textarea name="message" rows="5" maxlength="10000" required></textarea></label><input class="relay-hp" name="website" tabindex="-1" autocomplete="off"><input type="hidden" name="page_url" value="<?php echo esc_url( $this->current_url() ); ?>"><button type="submit"><?php echo esc_html( $s['submit_label'] ); ?></button><p class="relay-result" role="status" aria-live="polite"></p></form>
		</section><?php
		return ob_get_clean();
	}

	private function current_url() {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return $scheme . $host . $uri;
	}

	public function admin_menu() {
		add_menu_page( __( 'Relay Inbox', 'refinery-relay' ), __( 'Relay', 'refinery-relay' ), 'manage_options', 'refinery-relay', array( $this, 'inbox_page' ), 'dashicons-email-alt', 58 );
		add_submenu_page( 'refinery-relay', __( 'Relay Settings', 'refinery-relay' ), __( 'Settings', 'refinery-relay' ), 'manage_options', 'refinery-relay-settings', array( $this, 'settings_page' ) );
	}

	public function inbox_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb;
		$id = isset( $_GET['conversation'] ) ? absint( $_GET['conversation'] ) : 0;
		echo '<div class="wrap"><h1>' . esc_html__( 'Relay Inbox', 'refinery-relay' ) . '</h1>';
		if ( $id ) {
			$c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->conversations} WHERE id=%d", $id ) );
			if ( ! $c ) { echo '<p>' . esc_html__( 'Conversation not found.', 'refinery-relay' ) . '</p></div>'; return; }
			$messages = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->messages} WHERE conversation_id=%d ORDER BY created_at", $id ) );
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=refinery-relay' ) ) . '">&larr; ' . esc_html__( 'Back', 'refinery-relay' ) . '</a></p><h2>' . esc_html( $c->subject ?: __( '(No subject)', 'refinery-relay' ) ) . '</h2><p><strong>' . esc_html( $c->name ) . '</strong> &lt;<a href="mailto:' . esc_attr( $c->email ) . '">' . esc_html( $c->email ) . '</a>&gt;</p>';
			foreach ( $messages as $m ) { echo '<div class="notice notice-info inline"><p>' . nl2br( esc_html( $m->body ) ) . '</p><small>' . esc_html( $m->created_at ) . '</small></div>'; }
			echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><input type="hidden" name="action" value="relay_update"><input type="hidden" name="conversation_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'relay_update_' . $id ); echo '<label for="status"><strong>' . esc_html__( 'Status', 'refinery-relay' ) . '</strong></label> <select id="status" name="status">';
			foreach ( array( 'open', 'pending', 'closed' ) as $status ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $status ), selected( $c->status, $status, false ), esc_html( ucfirst( $status ) ) ); } echo '</select> '; submit_button( __( 'Update', 'refinery-relay' ), 'secondary', 'submit', false ); echo '</form>';
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$this->conversations} ORDER BY updated_at DESC LIMIT 100" );
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'From', 'refinery-relay' ) . '</th><th>' . esc_html__( 'Subject', 'refinery-relay' ) . '</th><th>' . esc_html__( 'Status', 'refinery-relay' ) . '</th><th>' . esc_html__( 'Updated', 'refinery-relay' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) { $url = add_query_arg( array( 'page' => 'refinery-relay', 'conversation' => $row->id ), admin_url( 'admin.php' ) ); echo '<tr><td>' . esc_html( $row->name ?: $row->email ) . '</td><td><a href="' . esc_url( $url ) . '">' . esc_html( $row->subject ?: __( '(No subject)', 'refinery-relay' ) ) . '</a></td><td>' . esc_html( ucfirst( $row->status ) ) . '</td><td>' . esc_html( $row->updated_at ) . '</td></tr>'; }
			if ( ! $rows ) { echo '<tr><td colspan="4">' . esc_html__( 'No conversations yet.', 'refinery-relay' ) . '</td></tr>'; } echo '</tbody></table>';
		} echo '</div>';
	}

	public function update_conversation() {
		$id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;
		check_admin_referer( 'relay_update_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot do that.', 'refinery-relay' ), '', array( 'response' => 403 ) );
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';
		if ( in_array( $status, array( 'open', 'pending', 'closed' ), true ) ) {
			global $wpdb; $wpdb->update( $this->conversations, array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'refinery-relay', 'conversation' => $id, 'updated' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function register_settings() {
		register_setting( 'refinery_relay', 'refinery_relay_settings', array( 'sanitize_callback' => array( $this, 'sanitize_settings' ), 'default' => array() ) );
	}

	public function sanitize_settings( $input ) {
		$defaults = $this->defaults();
		$out = array();
		foreach ( array( 'title', 'prompt', 'button_label', 'submit_label', 'success_message' ) as $key ) { $out[ $key ] = sanitize_text_field( $input[ $key ] ?? $defaults[ $key ] ); }
		$out['notification_email'] = is_email( $input['notification_email'] ?? '' ) ? sanitize_email( $input['notification_email'] ) : get_option( 'admin_email' );
		$out['accent_color'] = sanitize_hex_color( $input['accent_color'] ?? '' ) ?: $defaults['accent_color'];
		$out['position'] = in_array( $input['position'] ?? '', array( 'left', 'right' ), true ) ? $input['position'] : 'right';
		$out['widget_enabled'] = empty( $input['widget_enabled'] ) ? 0 : 1;
		return $out;
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; } $s = $this->settings(); ?>
		<div class="wrap"><h1><?php esc_html_e( 'Relay Settings', 'refinery-relay' ); ?></h1><form method="post" action="options.php"><?php settings_fields( 'refinery_relay' ); ?><table class="form-table"><tbody><?php
		$fields = array( 'title' => __( 'Title', 'refinery-relay' ), 'prompt' => __( 'Prompt', 'refinery-relay' ), 'button_label' => __( 'Button label', 'refinery-relay' ), 'submit_label' => __( 'Submit label', 'refinery-relay' ), 'success_message' => __( 'Success message', 'refinery-relay' ), 'notification_email' => __( 'Notification email', 'refinery-relay' ) );
		foreach ( $fields as $key => $label ) { echo '<tr><th><label for="relay-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="relay-' . esc_attr( $key ) . '" name="refinery_relay_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[ $key ] ) . '"></td></tr>'; }
		?><tr><th><?php esc_html_e( 'Accent color', 'refinery-relay' ); ?></th><td><input type="color" name="refinery_relay_settings[accent_color]" value="<?php echo esc_attr( $s['accent_color'] ); ?>"></td></tr><tr><th><?php esc_html_e( 'Position', 'refinery-relay' ); ?></th><td><select name="refinery_relay_settings[position]"><option value="right" <?php selected( $s['position'], 'right' ); ?>><?php esc_html_e( 'Right', 'refinery-relay' ); ?></option><option value="left" <?php selected( $s['position'], 'left' ); ?>><?php esc_html_e( 'Left', 'refinery-relay' ); ?></option></select></td></tr><tr><th><?php esc_html_e( 'Floating widget', 'refinery-relay' ); ?></th><td><label><input type="checkbox" name="refinery_relay_settings[widget_enabled]" value="1" <?php checked( $s['widget_enabled'] ); ?>> <?php esc_html_e( 'Show on the public site', 'refinery-relay' ); ?></label><p class="description"><?php esc_html_e( 'You can instead place [refinery_relay] on any page.', 'refinery-relay' ); ?></p></td></tr></tbody></table><?php submit_button(); ?></form></div><?php
	}

	private function defaults() { return array( 'title' => __( 'Send us a message', 'refinery-relay' ), 'prompt' => __( 'Tell us what you need and we will get back to you by email.', 'refinery-relay' ), 'button_label' => __( 'Contact us', 'refinery-relay' ), 'submit_label' => __( 'Send message', 'refinery-relay' ), 'success_message' => __( 'Thanks! Your message has been received.', 'refinery-relay' ), 'notification_email' => get_option( 'admin_email' ), 'accent_color' => '#2563eb', 'position' => 'right', 'widget_enabled' => 1 ); }
	private function settings() { return wp_parse_args( get_option( 'refinery_relay_settings', array() ), $this->defaults() ); }

	public function register_exporter( $exporters ) { $exporters['refinery-relay'] = array( 'exporter_friendly_name' => __( 'Refinery Relay conversations', 'refinery-relay' ), 'callback' => array( $this, 'export_data' ) ); return $exporters; }
	public function export_data( $email, $page = 1 ) {
		global $wpdb; $items = array(); $rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.*, m.body, m.created_at message_date FROM {$this->conversations} c LEFT JOIN {$this->messages} m ON m.conversation_id=c.id WHERE c.email=%s ORDER BY c.id LIMIT 100 OFFSET %d", $email, ( max( 1, $page ) - 1 ) * 100 ) );
		foreach ( $rows as $r ) { $items[] = array( 'group_id' => 'refinery-relay', 'group_label' => __( 'Relay conversations', 'refinery-relay' ), 'item_id' => 'relay-' . $r->id, 'data' => array( array( 'name' => __( 'Subject', 'refinery-relay' ), 'value' => $r->subject ), array( 'name' => __( 'Message', 'refinery-relay' ), 'value' => $r->body ), array( 'name' => __( 'Date', 'refinery-relay' ), 'value' => $r->message_date ) ) ); }
		return array( 'data' => $items, 'done' => count( $rows ) < 100 );
	}
	public function register_eraser( $erasers ) { $erasers['refinery-relay'] = array( 'eraser_friendly_name' => __( 'Refinery Relay conversations', 'refinery-relay' ), 'callback' => array( $this, 'erase_data' ) ); return $erasers; }
	public function erase_data( $email, $page = 1 ) {
		global $wpdb; $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->conversations} WHERE email=%s LIMIT 100", $email ) );
		foreach ( $ids as $id ) { $wpdb->delete( $this->messages, array( 'conversation_id' => $id ), array( '%d' ) ); $wpdb->delete( $this->conversations, array( 'id' => $id ), array( '%d' ) ); }
		return array( 'items_removed' => ! empty( $ids ), 'items_retained' => false, 'messages' => array(), 'done' => count( $ids ) < 100 );
	}
}
