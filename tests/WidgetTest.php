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

    /**
     * Capture the shortcode callback registered by Widget::__construct.
     * The stub add_shortcode in tests/stubs.php stores callbacks in the
     * $GLOBALS['test_shortcodes'] map so tests can reach them.
     */
    private function captureShortcodeCallback(): callable
    {
        unset($GLOBALS['test_shortcodes']);
        $this->makeWidget();
        $cb = $GLOBALS['test_shortcodes']['freespoke_search'] ?? null;
        $this->assertIsCallable($cb, 'freespoke_search shortcode was not registered');

        return $cb;
    }

    public function testShortcodeShortCircuitsInAdminContext(): void
    {
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('is_admin')->justReturn(true);

        $cb = $this->captureShortcodeCallback();

        // No enqueue calls should fire in admin context.
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();

        $this->assertSame('<!-- freespoke_search -->', $cb(['client_id' => 'x']));
    }

    public function testShortcodeShortCircuitsInRestContext(): void
    {
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(true);
        Functions\when('is_admin')->justReturn(false);

        $cb = $this->captureShortcodeCallback();

        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();

        $this->assertSame('<!-- freespoke_search -->', $cb(['client_id' => 'x']));
    }

    public function testShortcodeShortCircuitsInAjaxContext(): void
    {
        Functions\when('wp_doing_ajax')->justReturn(true);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('is_admin')->justReturn(false);

        $cb = $this->captureShortcodeCallback();

        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_enqueue_style')->never();

        $this->assertSame('<!-- freespoke_search -->', $cb(['client_id' => 'x']));
    }

    public function testShortcodeRendersOnFrontend(): void
    {
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('is_admin')->justReturn(false);

        Functions\expect('wp_enqueue_script')->zeroOrMoreTimes();
        Functions\expect('wp_enqueue_style')->zeroOrMoreTimes();
        Functions\expect('esc_attr')->andReturnFirstArg();

        $cb = $this->captureShortcodeCallback();
        $html = $cb(['client_id' => 'test-id']);

        $this->assertStringContainsString('freespoke-widget-container', $html);
        $this->assertStringContainsString('test-id', $GLOBALS['freespoke_widgets'][0]['clientId']);
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

    /**
     * Render a widget as if the page were requested with ?q=<query>, with
     * the user lookup answering with the given WordPress users. Passing null
     * for the users asserts that no lookup happens at all.
     */
    private function renderWithQuery(array $optionOverrides, ?string $query, ?array $users): array
    {
        $widget = $this->makeWidget();

        Functions\expect('wp_enqueue_script')->zeroOrMoreTimes();
        Functions\expect('wp_enqueue_style')->zeroOrMoreTimes();
        Functions\expect('esc_attr')->andReturnFirstArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        if ($users === null) {
            Functions\expect('get_users')->never();
        } else {
            // A second, shorter query word is tried when the first finds no one.
            Functions\expect('get_users')->between(1, 2)->andReturn($users);
        }
        Functions\when('get_author_posts_url')->alias(fn(int $id) => "https://example.com/author/{$id}/");
        Functions\when('get_avatar_url')->alias(fn(int $id) => "https://example.com/avatars/{$id}.jpg");
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('esc_url_raw')->returnArg();

        unset($_SERVER['QUERY_STRING']);
        if ($query === null) {
            unset($_GET['q'], $_GET['s']);
        } else {
            $_GET[$optionOverrides['query_param'] ?? 'q'] = $query;
        }

        try {
            $widget->renderWidget(new WidgetOptions(array_merge(['client_id' => 'test-id'], $optionOverrides)));
        } finally {
            unset($_GET['q'], $_GET['s']);
        }

        return $GLOBALS['freespoke_widgets'][0];
    }

    /** Options for the widget that shows results, the only one author cards appear in. */
    private const RESULTS_WIDGET = ['auto_search' => true, 'embedded_search' => true];

    public function testRenderWidgetIncludesAuthorsWhenQueryMatchesAnAuthor(): void
    {
        $config = $this->renderWithQuery(
            self::RESULTS_WIDGET,
            'elizabeth mitchell',
            [(object) ['ID' => 7, 'display_name' => 'Elizabeth Troutman Mitchell']],
        );

        $this->assertSame(
            [['name' => 'Elizabeth Troutman Mitchell', 'url' => 'https://example.com/author/7/', 'image' => 'https://example.com/avatars/7.jpg']],
            $config['authors'],
        );
    }

    public function testRenderWidgetOmitsAuthorsWhenNothingMatches(): void
    {
        $config = $this->renderWithQuery(self::RESULTS_WIDGET, 'tax policy', []);

        $this->assertArrayNotHasKey('authors', $config);
    }

    public function testRenderWidgetOmitsAuthorsWhenAuthorLinksDisabled(): void
    {
        $config = $this->renderWithQuery(
            self::RESULTS_WIDGET + ['author_links' => false],
            'elizabeth mitchell',
            null,
        );

        $this->assertArrayNotHasKey('authors', $config);
    }

    public function testRenderWidgetOmitsAuthorsWhenAutoSearchOff(): void
    {
        $config = $this->renderWithQuery(
            ['embedded_search' => true],
            'elizabeth mitchell',
            null,
        );

        $this->assertArrayNotHasKey('authors', $config);
    }

    public function testRenderWidgetSkipsTheLookupForAWidgetWithoutEmbeddedResults(): void
    {
        $config = $this->renderWithQuery(
            ['auto_search' => true, 'redirect_url' => '/search/?q={query}'],
            'elizabeth mitchell',
            null,
        );

        $this->assertArrayNotHasKey('authors', $config);
    }

    public function testRenderWidgetUsesTheFirstOfARepeatedQueryParamAsTheWidgetDoes(): void
    {
        $widget = $this->makeWidget();
        Functions\expect('wp_enqueue_script')->zeroOrMoreTimes();
        Functions\expect('wp_enqueue_style')->zeroOrMoreTimes();
        Functions\expect('esc_attr')->andReturnFirstArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('get_author_posts_url')->alias(fn(int $id) => "https://example.com/author/{$id}/");
        Functions\when('get_avatar_url')->justReturn(false);
        Functions\expect('get_users')
            ->once()
            ->with(\Mockery::on(fn(array $args): bool => $args['search'] === '*elizabeth*'))
            ->andReturn([(object) ['ID' => 7, 'display_name' => 'Elizabeth Mitchell']]);

        $_SERVER['QUERY_STRING'] = 'q=elizabeth+mitchell&q=tax%20policy';
        $_GET['q'] = 'tax policy';
        try {
            $widget->renderWidget(new WidgetOptions(['client_id' => 'test-id'] + self::RESULTS_WIDGET));
        } finally {
            unset($_SERVER['QUERY_STRING'], $_GET['q']);
        }

        $this->assertSame('Elizabeth Mitchell', $GLOBALS['freespoke_widgets'][0]['authors'][0]['name']);
    }

    public function testWidgetsOnOnePageShareOneAuthorLookup(): void
    {
        $widget = $this->makeWidget();
        Functions\expect('wp_enqueue_script')->zeroOrMoreTimes();
        Functions\expect('wp_enqueue_style')->zeroOrMoreTimes();
        Functions\expect('esc_attr')->andReturnFirstArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('get_author_posts_url')->alias(fn(int $id) => "https://example.com/author/{$id}/");
        Functions\when('get_avatar_url')->justReturn(false);
        Functions\expect('get_users')->once()->andReturn([(object) ['ID' => 7, 'display_name' => 'Elizabeth Mitchell']]);

        unset($_SERVER['QUERY_STRING']);
        $_GET['q'] = 'elizabeth mitchell';
        try {
            $widget->renderWidget(new WidgetOptions(['client_id' => 'test-id'] + self::RESULTS_WIDGET));
            $widget->renderWidget(new WidgetOptions(['client_id' => 'test-id'] + self::RESULTS_WIDGET));
        } finally {
            unset($_GET['q']);
        }

        $this->assertSame($GLOBALS['freespoke_widgets'][0]['authors'], $GLOBALS['freespoke_widgets'][1]['authors']);
    }

    public function testRenderWidgetOmitsAuthorsWithoutAQueryInTheRequest(): void
    {
        $config = $this->renderWithQuery(self::RESULTS_WIDGET, null, null);

        $this->assertArrayNotHasKey('authors', $config);
    }

    public function testRenderWidgetReadsTheConfiguredQueryParam(): void
    {
        $config = $this->renderWithQuery(
            self::RESULTS_WIDGET + ['query_param' => 's'],
            'elizabeth mitchell',
            [(object) ['ID' => 7, 'display_name' => 'Elizabeth Mitchell']],
        );

        $this->assertSame('Elizabeth Mitchell', $config['authors'][0]['name']);
    }
}
