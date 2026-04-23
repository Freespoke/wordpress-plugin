<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

class Plugin
{
    /**
     * @param array{
     *   plugin_file: string,
     *   plugin_dir: string,
     *   plugin_url: string,
     *   base_url: string,
     *   js_bundle: string,
     *   css_bundle: string,
     *   overrides?: array<string, mixed>,
     * } $config
     */
    public static function init(array $config): void
    {
        $settings = new \Freespoke\Wordpress\Settings($config['overrides'] ?? []);
        $postMeta = new \Freespoke\Wordpress\PostMeta();
        $factory = new \Freespoke\Wordpress\ClientFactory($settings);
        $publisher = new \Freespoke\Wordpress\Publisher($factory, $postMeta, $settings);
        $cron = new \Freespoke\Wordpress\Cron($publisher, $postMeta, $factory, $settings);
        $admin = new \Freespoke\Wordpress\Admin($settings, $postMeta, $publisher, $factory);
        $editorNotices = new \Freespoke\Wordpress\EditorNotices($postMeta, $config['plugin_dir'], $config['plugin_url']);
        // Publisher hooks
        if ($settings->hasCredentials()) {
            add_action('wp_after_insert_post', [$publisher, 'onPostSave'], 10, 2);
            add_action('transition_post_status', [$publisher, 'onStatusTransition'], 10, 3);
        } else {
            add_action('admin_notices', [$admin, 'renderMissingCredentialsNotice']);
        }
        // Cron hooks
        add_filter('cron_schedules', [$cron, 'registerSchedules']);
        add_action('init', [$cron, 'schedule']);
        add_action('freespoke_publisher_cron', [$cron, 'run']);
        // Admin hooks
        add_action('admin_menu', [$admin, 'registerPage']);
        add_action('wp_ajax_freespoke_test_auth', [$admin, 'handleTestAuth']);
        add_action('wp_ajax_freespoke_resubmit', [$admin, 'handleResubmit']);
        // Editor notices
        add_action('rest_api_init', [$editorNotices, 'registerRoutes']);
        add_action('enqueue_block_editor_assets', [$editorNotices, 'enqueueScript']);
        // Widget
        \Freespoke\Wordpress\Widget::init($config['base_url'], $config['js_bundle'], $config['css_bundle']);
        // Self-hosted update checker
        $pluginData = get_file_data($config['plugin_file'], ['Version' => 'Version']);
        $updateChecker = new \Freespoke\Wordpress\UpdateChecker($config['plugin_file'], $pluginData['Version'] ?? '0.0.0');
        $updateChecker->register();
        // Activation/deactivation
        register_activation_hook($config['plugin_file'], [$cron, 'onActivate']);
        register_deactivation_hook($config['plugin_file'], [$cron, 'onDeactivate']);
    }
}
