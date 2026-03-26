<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Freespoke\Wordpress\Plugin;
use Freespoke\Wordpress\Widget;

class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset Widget singleton
        $ref = new \ReflectionClass(Widget::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);

        // UpdateChecker dependencies
        Functions\when('get_file_data')->justReturn(['Version' => '1.0.0']);
        Functions\when('plugin_basename')->justReturn('freespoke-search/freespoke-search.php');
    }

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'plugin_file' => '/var/www/plugin/freespoke-search.php',
            'plugin_dir' => '/var/www/plugin/',
            'plugin_url' => 'https://example.com/wp-content/plugins/freespoke/',
            'base_url' => 'https://freespoke.com',
            'js_bundle' => 'main.js',
            'css_bundle' => 'main.css',
            'overrides' => ['api_key' => 'test-key'],
        ], $overrides);
    }

    public function testInitRegistersPublisherHooksWhenCredentialsExist(): void
    {
        Functions\expect('get_option')->andReturn('');
        Functions\expect('register_activation_hook')->once();
        Functions\expect('register_deactivation_hook')->once();

        Actions\expectAdded('wp_after_insert_post')->once();
        Actions\expectAdded('transition_post_status')->once();

        Plugin::init($this->baseConfig());
    }

    public function testInitRegistersWarningWhenNoCredentials(): void
    {
        Functions\expect('get_option')->andReturn('');
        Functions\expect('register_activation_hook')->once();
        Functions\expect('register_deactivation_hook')->once();

        Actions\expectAdded('wp_after_insert_post')->never();
        Actions\expectAdded('transition_post_status')->never();
        Actions\expectAdded('admin_notices')->once();

        Plugin::init($this->baseConfig(['overrides' => []]));
    }

    public function testInitRegistersAllCoreHooks(): void
    {
        Functions\expect('get_option')->andReturn('');
        Functions\expect('register_activation_hook')->once();
        Functions\expect('register_deactivation_hook')->once();

        Actions\expectAdded('init')->once();
        Actions\expectAdded('freespoke_publisher_cron')->once();
        Actions\expectAdded('admin_menu')->once();
        Actions\expectAdded('rest_api_init')->once();
        Actions\expectAdded('enqueue_block_editor_assets')->once();

        Plugin::init($this->baseConfig());
    }

    public function testInitInitializesWidget(): void
    {
        Functions\expect('get_option')->andReturn('');
        Functions\expect('register_activation_hook')->zeroOrMoreTimes();
        Functions\expect('register_deactivation_hook')->zeroOrMoreTimes();

        Plugin::init($this->baseConfig());

        // Widget singleton should now be initialized
        $widget = Widget::getInstance();
        $this->assertInstanceOf(Widget::class, $widget);
    }
}
