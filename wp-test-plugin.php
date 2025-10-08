<?php
/**
 * Plugin Name: WP Test Plugin
 * Plugin URI: https://github.com/urlund/wp-test-plugin
 * Description: A simple WordPress plugin boilerplate for testing and development
 * Version: 1.0.6
 * Author: Henrik Urlund
 * Author URI: https://github.com/urlund
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-test-plugin
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

require_once __DIR__ . '/vendor/autoload.php';

// Initialize the GitHub updater
Urlund\WordPress\PluginUpdater\GitHubRepository::getInstance('wp-test-plugin/wp-test-plugin.php', 'urlund/wp-test-plugin', array(
    // 'auth'           => 'your-github-token', // Optional: GitHub token for private repos or higher rate limits
    // 'slug'           => 'custom-slug',       // Optional: Custom plugin slug
    // 'prefer_json'    => true,                // Optional: Prefer JSON metadata over ZIP parsing (default: true)
    // 'cache_duration' => 21600,               // Optional: Cache duration in seconds (default: 6 hours)
    // 'timeout'        => 30,                  // Optional: HTTP request timeout (default: 30 seconds)
    // 'max_file_size'  => 52428800,            // Optional: Maximum ZIP file size in bytes (default: 50MB)
));

/**
 * Currently plugin version.
 */
define( 'WP_TEST_PLUGIN_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 */
function activate_wp_test_plugin() {
    // Activation code here
    flush_rewrite_rules();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_wp_test_plugin() {
    // Deactivation code here
    flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'activate_wp_test_plugin' );
register_deactivation_hook( __FILE__, 'deactivate_wp_test_plugin' );

/**
 * Initialize the plugin.
 */
function wp_test_plugin_init() {
    // Load plugin text domain for translations
    load_plugin_textdomain( 'wp-test-plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wp_test_plugin_init' );

/**
 * Add a simple shortcode example.
 * Usage: [wp_test_hello name="World"]
 */
function wp_test_plugin_hello_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'name' => 'World',
        ),
        $atts,
        'wp_test_hello'
    );

    return '<p class="wp-test-hello">Hello, ' . esc_html( $atts['name'] ) . '!</p>';
}
add_shortcode( 'wp_test_hello', 'wp_test_plugin_hello_shortcode' );

/**
 * Enqueue plugin styles.
 */
function wp_test_plugin_enqueue_styles() {
    wp_enqueue_style(
        'wp-test-plugin',
        plugin_dir_url( __FILE__ ) . 'css/wp-test-plugin.css',
        array(),
        WP_TEST_PLUGIN_VERSION,
        'all'
    );
}
add_action( 'wp_enqueue_scripts', 'wp_test_plugin_enqueue_styles' );

/**
 * Enqueue plugin scripts.
 */
function wp_test_plugin_enqueue_scripts() {
    wp_enqueue_script(
        'wp-test-plugin',
        plugin_dir_url( __FILE__ ) . 'js/wp-test-plugin.js',
        array( 'jquery' ),
        WP_TEST_PLUGIN_VERSION,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'wp_test_plugin_enqueue_scripts' );

