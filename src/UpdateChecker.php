<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

class UpdateChecker
{
    private const UPDATE_URL = 'https://freespoke.github.io/wordpress-plugin/update.json';
    private const TRANSIENT_KEY = 'freespoke_update_check';
    private const CACHE_TTL = 43200;
    // 12 hours
    private string $pluginFile;
    private string $pluginSlug;
    private string $currentVersion;
    public function __construct(string $pluginFile, string $currentVersion)
    {
        $this->pluginFile = $pluginFile;
        $this->pluginSlug = plugin_basename($pluginFile);
        $this->currentVersion = $currentVersion;
    }
    public function register(): void
    {
        add_filter('site_transient_update_plugins', [$this, 'checkForUpdate']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 10, 3);
    }
    /**
     * @param mixed $transient
     * @return mixed
     */
    public function checkForUpdate($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $remote = $this->fetchUpdateInfo();
        if ($remote === null) {
            return $transient;
        }
        if (version_compare($this->currentVersion, $remote['version'], '<')) {
            $transient->response[$this->pluginSlug] = (object) ['slug' => dirname($this->pluginSlug), 'plugin' => $this->pluginSlug, 'new_version' => $remote['version'], 'package' => $remote['download_url'], 'url' => $remote['homepage'] ?? 'https://freespoke.com/widgets', 'requires' => $remote['requires'] ?? '', 'requires_php' => $remote['requires_php'] ?? '8.1', 'tested' => $remote['tested'] ?? ''];
        }
        return $transient;
    }
    /**
     * @param mixed $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function pluginInfo($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!isset($args->slug) || $args->slug !== dirname($this->pluginSlug)) {
            return $result;
        }
        $remote = $this->fetchUpdateInfo();
        if ($remote === null) {
            return $result;
        }
        return (object) ['name' => 'Freespoke Search', 'slug' => dirname($this->pluginSlug), 'version' => $remote['version'], 'author' => '<a href="https://freespoke.com">Freespoke</a>', 'homepage' => $remote['homepage'] ?? 'https://freespoke.com/widgets', 'requires' => $remote['requires'] ?? '', 'requires_php' => $remote['requires_php'] ?? '8.1', 'tested' => $remote['tested'] ?? '', 'download_link' => $remote['download_url'], 'sections' => ['description' => $remote['description'] ?? 'Embed the Freespoke Search Widget and automatically publish your content to Freespoke\'s search index.', 'changelog' => $remote['changelog'] ?? '']];
    }
    /**
     * @return array{version: string, download_url: string, requires?: string, requires_php?: string, tested?: string, homepage?: string, description?: string, changelog?: string}|null
     */
    private function fetchUpdateInfo(): ?array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $response = wp_remote_get(self::UPDATE_URL, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, \true);
        if (!is_array($data) || !isset($data['version'], $data['download_url'])) {
            return null;
        }
        set_transient(self::TRANSIENT_KEY, $data, self::CACHE_TTL);
        return $data;
    }
}
