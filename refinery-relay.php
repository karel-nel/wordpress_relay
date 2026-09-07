<?php
/**
 * Plugin Name: Refinery Relay
 * Description: A small, self-hosted support inbox for WordPress.
 * Version: 1.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Refinery Relay contributors
 * License: MIT
 * Text Domain: refinery-relay
 */

defined( 'ABSPATH' ) || exit;

define( 'REFINERY_RELAY_VERSION', '1.1.0' );
define( 'REFINERY_RELAY_FILE', __FILE__ );
define( 'REFINERY_RELAY_DIR', plugin_dir_path( __FILE__ ) );

require_once REFINERY_RELAY_DIR . 'includes/class-refinery-relay.php';

register_activation_hook( __FILE__, array( 'Refinery_Relay', 'activate' ) );
register_uninstall_hook( __FILE__, array( 'Refinery_Relay', 'uninstall' ) );

Refinery_Relay::instance();
