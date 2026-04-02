<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Brain\Monkey\Functions;
use Freespoke\Wordpress\Widget;
use Freespoke\Wordpress\WidgetOptions;

class WidgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset singleton between tests
        $ref = new \ReflectionClass(Widget::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);

        // Reset widget config global
        $GLOBALS['freespoke_widgets'] = null;
    }

    private function makeWidget(): Widget
    {
        return Widget::init(
            'https://freespoke.com',
            '/widgets/freespoke-search/static/main.abc123.js',
            '/widgets/freespoke-search/static/main.abc123.css',
        );
    }

    public function testInitCreatesSingleton(): void
    {
        $widget = $this->makeWidget();
        $this->assertSame($widget, Widget::getInstance());
    }

    public function testInitReturnsSameInstanceOnSecondCall(): void
    {
        $first = $this->makeWidget();
        $second = Widget::init('https://other.com', 'x.js', 'x.css');
        $this->assertSame($first, $second);
    }

    public function testGetInstanceThrowsBeforeInit(): void
    {
        $this->expectException(\LogicException::class);
        Widget::getInstance();
    }

    public function testGetInstanceDeprecatedAlias(): void
    {
        $this->makeWidget();
        $this->assertSame(Widget::getInstance(), Widget::get_instance());
    }

    public function testRenderWidgetRequiresClientId(): void
    {
        $widget = $this->makeWidget();
        $opts = new WidgetOptions();

        $html = $widget->renderWidget($opts);
        $this->assertStringContainsString('client_id is required', $html);
    }

    public function testRenderWidgetOutputsContainer(): void
    {
        $widget = $this->makeWidget();

        Functions\expect('wp_enqueue_script')->zeroOrMoreTimes();
        Functions\expect('wp_enqueue_style')->zeroOrMoreTimes();
        Functions\expect('esc_attr')->andReturnFirstArg();

        $opts = new WidgetOptions(['client_id' => 'test-id']);

        $html = $widget->renderWidget($opts);
        $this->assertStringContainsString('freespoke-widget-container', $html);
        $this->assertStringContainsString($opts->container_id, $html);
        $this->assertStringContainsString('min-height: 60px', $html);
    }

    public function testRenderWidgetEnqueuesAssets(): void
    {
        $widget = $this->makeWidget();

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'freespoke-widget-bundle',
                'https://freespoke.com/widgets/freespoke-search/static/main.abc123.js',
                [],
                '1.0.0',
                true,
            );
        Functions\expect('wp_enqueue_style')
            ->once()
            ->with(
                'freespoke-widget-styles',
                'https://freespoke.com/widgets/freespoke-search/static/main.abc123.css',
                [],
                '1.0.0',
            );
        Functions\expect('esc_attr')->andReturnFirstArg();

        $opts = new WidgetOptions(['client_id' => 'test-id']);
        $widget->renderWidget($opts);
    }

    public function testRenderWidgetSkipsCssWhenEmpty(): void
    {
        $ref = new \ReflectionClass(Widget::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);

        $widget = Widget::init('https://freespoke.com', 'main.js', '');

        Functions\expect('wp_enqueue_script')->once();
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('esc_attr')->andReturnFirstArg();

        $opts = new WidgetOptions(['client_id' => 'test-id']);
        $widget->renderWidget($opts);
    }

    public function testRenderWidgetOnlyEnqueuesOnce(): void
    {
        $widget = $this->makeWidget();

        Functions\expect('wp_enqueue_script')->once();
        Functions\expect('wp_enqueue_style')->once();
        Functions\expect('esc_attr')->andReturnFirstArg();

        $opts = new WidgetOptions(['client_id' => 'test-id']);
        $widget->renderWidget($opts);
        $widget->renderWidget($opts);
    }

    public function testRenderWidgetCollectsWidgetConfig(): void
    {
        $widget = $this->makeWidget();

        Functions\expect('wp_enqueue_script')->zeroOrMoreTimes();
        Functions\expect('wp_enqueue_style')->zeroOrMoreTimes();
        Functions\expect('esc_attr')->andReturnFirstArg();

        $opts = new WidgetOptions([
            'client_id' => 'test-id',
            'theme' => 'dark',
            'placeholder' => 'Type here',
        ]);
        $widget->renderWidget($opts);

        global $freespoke_widgets;
        $this->assertCount(1, $freespoke_widgets);
        $this->assertSame('test-id', $freespoke_widgets[0]['clientId']);
        $this->assertSame('dark', $freespoke_widgets[0]['theme']);
        $this->assertSame('Type here', $freespoke_widgets[0]['placeholder']);
        $this->assertSame('https://freespoke.com', $freespoke_widgets[0]['baseUrl']);
    }

    public function testRenderWidgetIncludesThemeParams(): void
    {
        $widget = $this->makeWidget();

        Functions\expect('wp_enqueue_script')->zeroOrMoreTimes();
        Functions\expect('wp_enqueue_style')->zeroOrMoreTimes();
        Functions\expect('esc_attr')->andReturnFirstArg();

        $opts = new WidgetOptions([
            'client_id' => 'test-id',
            'primary_bg' => '#ffffff',
            'button_text' => '#000000',
        ]);
        $widget->renderWidget($opts);

        global $freespoke_widgets;
        $this->assertArrayHasKey('themeParams', $freespoke_widgets[0]);
        $this->assertSame('#ffffff', $freespoke_widgets[0]['themeParams']['primaryBg']);
        $this->assertSame('#000000', $freespoke_widgets[0]['themeParams']['buttonText']);
    }

    public function testEnqueueScriptsChecksShortcode(): void
    {
        $widget = $this->makeWidget();

        $post = (object) ['post_content' => '[freespoke_search client_id="x"]'];

        $GLOBALS['post'] = $post;

        Functions\expect('has_shortcode')
            ->once()
            ->with('[freespoke_search client_id="x"]', 'freespoke_search')
            ->andReturn(true);
        Functions\expect('wp_enqueue_script')->once();
        Functions\expect('wp_enqueue_style')->once();

        $widget->enqueueScripts();
        unset($GLOBALS['post']);
    }

    public function testAddWidgetInitializationOutputsScript(): void
    {
        $widget = $this->makeWidget();

        Functions\expect('wp_enqueue_script')->zeroOrMoreTimes();
        Functions\expect('wp_enqueue_style')->zeroOrMoreTimes();
        Functions\expect('esc_attr')->andReturnFirstArg();
        Functions\expect('wp_json_encode')->andReturnUsing(fn($v) => json_encode($v));

        $opts = new WidgetOptions(['client_id' => 'test-id']);
        $widget->renderWidget($opts);

        ob_start();
        $widget->addWidgetInitialization();
        $output = ob_get_clean();

        $this->assertStringContainsString('FreespokeWidget.init', $output);
        $this->assertStringContainsString('test-id', $output);
    }

    public function testAddWidgetInitializationNoOutputWithoutWidgets(): void
    {
        // Fresh state — no widgets rendered
        $GLOBALS['freespoke_widgets'] = null;

        $widget = $this->makeWidget();

        ob_start();
        $widget->addWidgetInitialization();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }
}
