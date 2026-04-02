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
 * Activation: flush rewrite rules for REST routes.
 */
register_activation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

/**
 * Deactivation: clean up scheduled events.
 */
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'gbsocial_token_refresh' );
} );
