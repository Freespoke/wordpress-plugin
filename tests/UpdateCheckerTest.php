<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Brain\Monkey\Functions;
use Freespoke\Wordpress\UpdateChecker;

class UpdateCheckerTest extends TestCase
{
    private UpdateChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('plugin_basename')->justReturn('freespoke-search/freespoke-search.php');

        $this->checker = new UpdateChecker('/var/www/plugin/freespoke-search.php', '1.0.0');
    }

    public function testRegisterAddsFilters(): void
    {
        $this->checker->register();

        $this->assertNotFalse(
            has_filter('site_transient_update_plugins', 'Freespoke\Wordpress\UpdateChecker->checkForUpdate()')
        );
        $this->assertNotFalse(
            has_filter('plugins_api', 'Freespoke\Wordpress\UpdateChecker->pluginInfo()')
        );
    }

    public function testCheckForUpdateAddsResponseWhenNewerVersion(): void
    {
        $updateData = [
            'version' => '2.0.0',
            'download_url' => 'https://github.com/Freespoke/wordpress-plugin/releases/download/v2.0.0/freespoke-search.zip',
            'requires_php' => '8.1',
        ];

        Functions\expect('get_transient')->once()->andReturn($updateData);

        $transient = new \stdClass();
        $transient->response = [];

        $result = $this->checker->checkForUpdate($transient);

        $this->assertArrayHasKey('freespoke-search/freespoke-search.php', $result->response);
        $entry = $result->response['freespoke-search/freespoke-search.php'];
        $this->assertSame('2.0.0', $entry->new_version);
        $this->assertSame($updateData['download_url'], $entry->package);
    }

    public function testCheckForUpdateSkipsWhenCurrentVersion(): void
    {
        $updateData = [
            'version' => '1.0.0',
            'download_url' => 'https://example.com/plugin.zip',
        ];

        Functions\expect('get_transient')->once()->andReturn($updateData);

        $transient = new \stdClass();
        $transient->response = [];

        $result = $this->checker->checkForUpdate($transient);

        $this->assertEmpty($result->response);
    }

    public function testCheckForUpdateSkipsWhenOlderVersion(): void
    {
        $updateData = [
            'version' => '0.9.0',
            'download_url' => 'https://example.com/plugin.zip',
        ];

        Functions\expect('get_transient')->once()->andReturn($updateData);

        $transient = new \stdClass();
        $transient->response = [];

        $result = $this->checker->checkForUpdate($transient);

        $this->assertEmpty($result->response);
    }

    public function testCheckForUpdateHandlesNonObjectTransient(): void
    {
        $result = $this->checker->checkForUpdate(false);
        $this->assertFalse($result);
    }

    public function testCheckForUpdateFetchesFromRemoteOnCacheMiss(): void
    {
        Functions\expect('get_transient')->once()->andReturn(false);

        $responseBody = json_encode([
            'version' => '2.0.0',
            'download_url' => 'https://example.com/plugin.zip',
        ]);

        Functions\expect('wp_remote_get')->once()->andReturn(['body' => $responseBody]);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn(200);
        Functions\expect('wp_remote_retrieve_body')->once()->andReturn($responseBody);
        Functions\expect('set_transient')->once();

        $transient = new \stdClass();
        $transient->response = [];

        $result = $this->checker->checkForUpdate($transient);

        $this->assertArrayHasKey('freespoke-search/freespoke-search.php', $result->response);
    }

    public function testCheckForUpdateHandlesHttpError(): void
    {
        Functions\expect('get_transient')->once()->andReturn(false);
        Functions\expect('wp_remote_get')->once()->andReturn(new \WP_Error('http_error', 'timeout'));

        $transient = new \stdClass();
        $transient->response = [];

        $result = $this->checker->checkForUpdate($transient);

        $this->assertEmpty($result->response);
    }

    public function testCheckForUpdateHandlesNon200Response(): void
    {
        Functions\expect('get_transient')->once()->andReturn(false);
        Functions\expect('wp_remote_get')->once()->andReturn(['body' => '']);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn(404);

        $transient = new \stdClass();
        $transient->response = [];

        $result = $this->checker->checkForUpdate($transient);

        $this->assertEmpty($result->response);
    }

    public function testPluginInfoReturnsDataForMatchingSlug(): void
    {
        $updateData = [
            'version' => '2.0.0',
            'download_url' => 'https://example.com/plugin.zip',
            'requires' => '6.0',
            'requires_php' => '8.1',
            'tested' => '6.7',
        ];

        Functions\expect('get_transient')->once()->andReturn($updateData);

        $args = (object) ['slug' => 'freespoke-search'];

        $result = $this->checker->pluginInfo(false, 'plugin_information', $args);

        $this->assertIsObject($result);
        $this->assertSame('2.0.0', $result->version);
        $this->assertSame('Freespoke Search', $result->name);
        $this->assertSame($updateData['download_url'], $result->download_link);
    }

    public function testPluginInfoIgnoresWrongAction(): void
    {
        $args = (object) ['slug' => 'freespoke-search'];

        $result = $this->checker->pluginInfo(false, 'hot_tags', $args);

        $this->assertFalse($result);
    }

    public function testPluginInfoIgnoresWrongSlug(): void
    {
        $args = (object) ['slug' => 'some-other-plugin'];

        $result = $this->checker->pluginInfo(false, 'plugin_information', $args);

        $this->assertFalse($result);
    }
}
