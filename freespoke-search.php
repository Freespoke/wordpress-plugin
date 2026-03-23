<?php
/**
 * Plugin Name: Freespoke Search
 * Plugin URI: https://freespoke.com/widgets
 * Description: Embed the Freespoke Search Widget and automatically publish your content to Freespoke's search index
 * Version: 1.2.0
 * Author: Freespoke
 * Author URI: https://freespoke.com
 * License: MIT
 * Requires PHP: 8.1
 * Text Domain: freespoke-widget
 *
 * Widget Usage: [freespoke_search client_id="YOUR_CLIENT_ID" theme="light" placeholder="Search..."]
 * Publisher: Configure credentials in Tools → Freespoke Publisher to enable auto-publishing
 */

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html(sprintf('Freespoke Search requires PHP 8.1 or later. You are running PHP %s.', PHP_VERSION));
        echo '</p></div>';
    });
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Read optional wp-config.php constants into overrides.
// Using constant() with string names avoids IDE errors for conditionally-defined constants.
$overrides = [];
foreach ([
    'client_id'     => 'FREESPOKE_CLIENT_ID',
    'client_secret' => 'FREESPOKE_CLIENT_SECRET',
    'token_url'     => 'FREESPOKE_TOKEN_URL',
    'api_key'       => 'FREESPOKE_PUBLISHER_API_KEY',
    'publisher_url' => 'FREESPOKE_PUBLISHER_URL',
    'notice_emails' => 'FREESPOKE_NOTICE_EMAILS',
] as $key => $constName) {
    if (defined($constName)) {
        $overrides[$key] = constant($constName);
    }
}

Freespoke\Wordpress\Plugin::init([
    'plugin_file' => __FILE__,
    'plugin_dir'  => plugin_dir_path(__FILE__),
    'plugin_url'  => plugin_dir_url(__FILE__),
    'base_url'    => 'https://freespoke.com',
    'js_bundle'   => 'widgets/freespoke-search/static/widget-bundle.v1.js',
    'css_bundle'  => '',
    'overrides'   => $overrides,
]);
