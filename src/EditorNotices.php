<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

class EditorNotices
{
    private const REST_NAMESPACE = 'freespoke/v1';
    private const REST_ROUTE = 'publisher-latest-error';
    private \Freespoke\Wordpress\PostMeta $postMeta;
    private string $pluginDir;
    private string $pluginUrl;
    public function __construct(\Freespoke\Wordpress\PostMeta $postMeta, string $pluginDir, string $pluginUrl)
    {
        $this->postMeta = $postMeta;
        $this->pluginDir = $pluginDir;
        $this->pluginUrl = $pluginUrl;
    }
    public function registerRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_ROUTE, ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'handleRequest'], 'permission_callback' => static function (\WP_REST_Request $request): bool {
            $postId = (int) ($request['id'] ?? 0);
            return $postId > 0 && current_user_can('edit_post', $postId);
        }, 'args' => ['id' => ['required' => \true, 'type' => 'integer']]]);
    }
    public function enqueueScript(): void
    {
        $relativePath = 'assets/js/publisher-notices.js';
        $scriptPath = $this->pluginDir . $relativePath;
        if (!file_exists($scriptPath)) {
            return;
        }
        wp_enqueue_script('freespoke-publisher-editor-notices', $this->pluginUrl . $relativePath, ['wp-data', 'wp-url', 'wp-api-fetch'], filemtime($scriptPath), \true);
        wp_add_inline_script('freespoke-publisher-editor-notices', sprintf('window.freespokePublisherNoticePath = %s;', wp_json_encode('/' . self::REST_NAMESPACE . '/' . self::REST_ROUTE)), 'before');
    }
    public function handleRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        $postId = (int) ($request['id'] ?? 0);
        if ($postId <= 0) {
            return rest_ensure_response(['code' => 'missing_id']);
        }
        $error = $this->postMeta->getError($postId);
        if ($error === null) {
            return rest_ensure_response(['code' => 'OK']);
        }
        return rest_ensure_response(['code' => $error['code'], 'message' => $error['message']]);
    }
}
