<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Freespoke\Wordpress\WidgetOptions;

class WidgetOptionsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $opts = new WidgetOptions();

        $this->assertSame('', $opts->client_id);
        $this->assertSame('auto', $opts->theme);
        $this->assertSame('Search...', $opts->placeholder);
        $this->assertSame('_self', $opts->redirect_target);
        $this->assertFalse($opts->embedded_search);
        $this->assertFalse($opts->auto_search);
        $this->assertSame('q', $opts->query_param);
        $this->assertSame('60px', $opts->min_height);
        $this->assertStringStartsWith('freespoke-widget-', $opts->container_id);
    }

    public function testConstructorAppliesConfig(): void
    {
        $opts = new WidgetOptions([
            'client_id' => 'test-id',
            'theme' => 'dark',
            'placeholder' => 'Find stuff',
        ]);

        $this->assertSame('test-id', $opts->client_id);
        $this->assertSame('dark', $opts->theme);
        $this->assertSame('Find stuff', $opts->placeholder);
    }

    public function testApplyIgnoresUnknownKeys(): void
    {
        $opts = new WidgetOptions(['nonexistent_key' => 'value']);
        $this->assertSame('', $opts->client_id); // unchanged
    }

    public function testApplyConvertsBooleanStrings(): void
    {
        $opts = new WidgetOptions([
            'embedded_search' => 'true',
            'auto_search' => '1',
        ]);

        $this->assertTrue($opts->embedded_search);
        $this->assertTrue($opts->auto_search);
    }

    public function testApplyConvertsBooleanFalseStrings(): void
    {
        $opts = new WidgetOptions([
            'embedded_search' => 'false',
            'auto_search' => '0',
        ]);

        $this->assertFalse($opts->embedded_search);
        $this->assertFalse($opts->auto_search);
    }

    public function testApplyConvertsBooleanValues(): void
    {
        $opts = new WidgetOptions([
            'embedded_search' => true,
            'auto_search' => false,
        ]);

        $this->assertTrue($opts->embedded_search);
        $this->assertFalse($opts->auto_search);
    }

    public function testApplyCastsNonStringToString(): void
    {
        $opts = new WidgetOptions(['client_id' => 123]);
        $this->assertSame('123', $opts->client_id);
    }

    public function testOverrideWithShortcodeAtts(): void
    {
        $opts = new WidgetOptions();
        $opts->overrideWithShortcodeAtts(['client_id' => 'shortcode-id', 'theme' => 'light']);

        $this->assertSame('shortcode-id', $opts->client_id);
        $this->assertSame('light', $opts->theme);
    }

    public function testDeprecatedOverrideMethod(): void
    {
        $opts = new WidgetOptions();
        $opts->override_with_shortcode_atts(['client_id' => 'deprecated-id']);

        $this->assertSame('deprecated-id', $opts->client_id);
    }

    public function testContainerIdIsUnique(): void
    {
        $a = new WidgetOptions();
        $b = new WidgetOptions();

        $this->assertNotSame($a->container_id, $b->container_id);
    }

    public function testThemeColorProperties(): void
    {
        $opts = new WidgetOptions([
            'primary_bg' => '#fff',
            'primary_text' => '#000',
            'button_bg' => '#0066cc',
            'dark_primary_bg' => '#111',
        ]);

        $this->assertSame('#fff', $opts->primary_bg);
        $this->assertSame('#000', $opts->primary_text);
        $this->assertSame('#0066cc', $opts->button_bg);
        $this->assertSame('#111', $opts->dark_primary_bg);
    }

    public function testBoolYesOnVariants(): void
    {
        $opts = new WidgetOptions(['embedded_search' => 'yes']);
        $this->assertTrue($opts->embedded_search);

        $opts2 = new WidgetOptions(['embedded_search' => 'on']);
        $this->assertTrue($opts2->embedded_search);
    }
}
