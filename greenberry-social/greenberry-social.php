<?php
/**
 * Plugin Name: Greenberry Social
 * Plugin URI:  https://greenberry.ie
 * Description: Auto-share WordPress posts to Bluesky, Mastodon, Facebook, LinkedIn, Threads & Tumblr. Zero dependencies, no Jetpack required.
 * Version:     1.0.0
 * Author:      Greenberry
 * Author URI:  https://greenberry.ie
 * License:     MIT
 * Text Domain: greenberry-social
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GBSOCIAL_VERSION', '1.0.0' );
define( 'GBSOCIAL_FILE', __FILE__ );
define( 'GBSOCIAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'GBSOCIAL_URL', plugin_dir_url( __FILE__ ) );
// Broker URL: can be overridden in wp-config.php or from the settings page.
if ( ! defined( 'GBSOCIAL_BROKER_URL' ) ) {
	$_gbsocial_broker = get_option( 'gbsocial_broker_url', 'https://social-oauth.greenberry.ie' );
	define( 'GBSOCIAL_BROKER_URL', rtrim( $_gbsocial_broker, '/' ) );
}

/**
 * PSR-4-style autoloader — no Composer needed.
 */
spl_autoload_register( function ( $class ) {
	$prefix = 'Greenberry\\Social\\';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$parts    = explode( '\\', $relative );
	$file     = array_pop( $parts );

	// Convert CamelCase class name to kebab-case filename.
	// Also replace underscores with hyphens (e.g. Provider_Registry → provider-registry).
	$file = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $file ) );
	$file = 'class-' . str_replace( '_', '-', $file ) . '.php';

	$path = GBSOCIAL_DIR . 'includes/';
	if ( ! empty( $parts ) ) {
		$path .= strtolower( implode( '/', $parts ) ) . '/';
	}
	$path .= $file;

	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

/**
 * Boot the plugin.
 */
add_action( 'plugins_loaded', function () {
	\Greenberry\Social\Plugin::instance();
} );

/**
 * Activation: register with the OAuth broker and flush rewrite rules.
 */
register_activation_hook( __FILE__, function () {
	flush_rewrite_rules();
	\Greenberry\Social\Broker::register_site();
} );

/**
 * Deactivation: clean up scheduled events.
 */
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'gbsocial_token_refresh' );
} );
