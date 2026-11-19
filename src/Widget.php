<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

class Widget
{
    private static ?self $instance = null;
    private string $baseUrl;
    private string $jsBundleUrl;
    private string $cssBundleUrl;
    private bool $assetsEnqueued = \false;
    private \Freespoke\Wordpress\AuthorResolver $authorResolver;
    public function __construct(string $baseUrl, string $jsBundle, string $cssBundle)
    {
        $this->baseUrl = $baseUrl;
        $this->authorResolver = new \Freespoke\Wordpress\AuthorResolver();
        $this->jsBundleUrl = $baseUrl . $jsBundle;
        $this->cssBundleUrl = $cssBundle !== '' ? $baseUrl . $cssBundle : '';
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('wp_head', [$this, 'addWidgetStyles']);
        add_shortcode('freespoke_search', function ($atts = [], $content = null, $tag = '') {
            // Short-circuit in non-frontend contexts. The widget has no
            // useful output here (no wp_footer to emit the init script,
            // no frontend asset pipeline), and any side effect — a stray
            // PHP notice, wp_enqueue_script warnings, etc. — can corrupt
            // the JSON body of the REST response that Gutenberg parses
            // when loading a post for editing, which surfaces to the
            // user as WordPress's generic "critical error" page. Also
            // prevents widget markup from leaking into the content sent
            // to the indexer by Publisher::buildArticle, which runs
            // apply_filters('the_content', ...) on save.
            $isJsonRequest = function_exists('FreespokeDeps\wp_is_json_request') && wp_is_json_request();
            $isRestRequest = defined('REST_REQUEST') && REST_REQUEST;
            if (wp_doing_ajax() || $isJsonRequest || $isRestRequest || is_admin()) {
                return '<!-- freespoke_search -->';
            }
            $options = new \Freespoke\Wordpress\WidgetOptions();
            if ($atts instanceof \Freespoke\Wordpress\WidgetOptions) {
                $options = $atts;
            } elseif (is_array($atts)) {
                $options->overrideWithShortcodeAtts($atts);
            }
            return $this->renderShortcode($options);
        });
        add_action('wp_footer', [$this, 'addWidgetInitialization']);
    }
    public static function init(string $baseUrl, string $jsBundle, string $cssBundle): self
    {
        if (self::$instance === null) {
            self::$instance = new self($baseUrl, $jsBundle, $cssBundle);
        }
        return self::$instance;
    }
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('Widget not initialized. Call Widget::init() first.');
        }
        return self::$instance;
    }
    /**
     * @deprecated Use getInstance()
     */
    public static function get_instance(): self
    {
        return self::getInstance();
    }
    public function enqueueScripts(): void
    {
        global $post;
        if ($post && has_shortcode($post->post_content, 'freespoke_search')) {
            $this->ensureAssetsEnqueued();
        }
    }
    public function addWidgetStyles(): void
    {
        echo '<style>
            .freespoke-widget-container {
                position: relative;
            }
            .freespoke-widget .freespoke-widget * {
                box-sizing: border-box;
            }
        </style>';
    }
    public function renderWidget(\Freespoke\Wordpress\WidgetOptions $options): string
    {
        if (empty($options->client_id)) {
            return '<p style="color: red; font-weight: bold;">Freespoke Widget: client_id is required</p>';
        }
        $this->ensureAssetsEnqueued();
        $widgetConfig = ['clientId' => $options->client_id, 'container' => $options->container_id, 'theme' => $options->theme, 'placeholder' => $options->placeholder, 'redirectUrl' => $options->redirect_url, 'redirectTarget' => $options->redirect_target, 'embeddedSearch' => $options->embedded_search, 'autoSearch' => $options->auto_search, 'autoFocus' => $options->auto_focus, 'queryParam' => $options->query_param, 'baseUrl' => $this->baseUrl];
        $themeParams = [];
        $themeAttributes = ['primary_bg', 'primary_text', 'primary_border', 'secondary_bg', 'secondary_text', 'secondary_border', 'button_bg', 'button_text', 'button_hover_bg', 'button_hover_text', 'input_bg', 'input_text', 'input_border', 'input_placeholder', 'input_focus_border', 'link_text', 'link_hover_text', 'font_family', 'font_size', 'border_radius', 'light_primary_bg', 'light_primary_text', 'light_button_bg', 'light_button_text', 'light_input_bg', 'light_input_text', 'dark_primary_bg', 'dark_primary_text', 'dark_button_bg', 'dark_button_text', 'dark_input_bg', 'dark_input_text'];
        foreach ($themeAttributes as $attr) {
            if (!empty($options->{$attr})) {
                $camelCase = lcfirst(str_replace('_', '', ucwords($attr, '_')));
                $themeParams[$camelCase] = $options->{$attr};
            }
        }
        if (!empty($themeParams)) {
            $widgetConfig['themeParams'] = $themeParams;
        }
        $authors = $this->matchingAuthors($options);
        if ($authors !== []) {
            $widgetConfig['authors'] = $authors;
        }
        global $freespoke_widgets;
        if (!isset($freespoke_widgets)) {
            $freespoke_widgets = [];
        }
        $freespoke_widgets[] = $widgetConfig;
        $containerStyle = 'min-height: ' . esc_attr($options->min_height) . ';';
        return '<div id="' . esc_attr($options->container_id) . '" class="freespoke-widget-container" style="' . $containerStyle . '"></div>';
    }
    public function addWidgetInitialization(): void
    {
        global $freespoke_widgets;
        if (!empty($freespoke_widgets)) {
            echo '<script>
            (function() {
                function initializeFreespokeWidgets() {
                    if (typeof window.FreespokeWidget !== "undefined" && window.FreespokeWidget.init) {
                        var widgets = ' . wp_json_encode($freespoke_widgets) . ';
                        widgets.forEach(function(config) {
                            window.FreespokeWidget.init(config);
                        });
                    } else {
                        setTimeout(initializeFreespokeWidgets, 100);
                    }
                }
                if (document.readyState === "loading") {
                    document.addEventListener("DOMContentLoaded", initializeFreespokeWidgets);
                } else {
                    initializeFreespokeWidgets();
                }
            })();
            </script>';
        }
    }
    /**
     * @deprecated Use renderWidget()
     */
    public function render_widget(\Freespoke\Wordpress\WidgetOptions $options): string
    {
        trigger_error('render_widget() is deprecated, use renderWidget() instead', \E_USER_DEPRECATED);
        return $this->renderWidget($options);
    }
    /**
     * @deprecated Use renderShortcode() via the shortcode handler
     */
    public function render_shortcode(\Freespoke\Wordpress\WidgetOptions $options): string
    {
        trigger_error('render_shortcode() is deprecated, use the [freespoke_search] shortcode instead', \E_USER_DEPRECATED);
        return $this->renderShortcode($options);
    }
    private function renderShortcode(\Freespoke\Wordpress\WidgetOptions $options): string
    {
        return $this->renderWidget($options);
    }
    /**
     * Authors whose name matches the query this page was requested with.
     *
     * Only meaningful when the widget auto-searches the page's query
     * parameter and shows results itself: that is the one case where the
     * server knows what the visitor searched for and the widget has a
     * results area to put the cards in.
     *
     * @return array<int, array{name: string, url: string}>
     */
    private function matchingAuthors(\Freespoke\Wordpress\WidgetOptions $options): array
    {
        if (!$options->author_links || !$options->auto_search || !$options->embedded_search) {
            return [];
        }
        $raw = $this->requestQuery($options->query_param);
        if ($raw === null || $raw === '') {
            return [];
        }
        return $this->authorResolver->resolve(sanitize_text_field($raw));
    }
    /**
     * The value of a query parameter, taking the first when it is repeated,
     * as the widget's own auto-search does (URLSearchParams.get). PHP's $_GET
     * keeps the last, so the raw query string is read first.
     */
    private function requestQuery(string $param): ?string
    {
        $query_string = $_SERVER['QUERY_STRING'] ?? '';
        if (is_string($query_string) && $query_string !== '') {
            foreach (explode('&', $query_string) as $pair) {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                if (urldecode($key) === $param) {
                    return urldecode($value);
                }
            }
        }
        $raw = $_GET[$param] ?? null;
        return is_string($raw) ? wp_unslash($raw) : null;
    }
    private function ensureAssetsEnqueued(): void
    {
        if ($this->assetsEnqueued) {
            return;
        }
        wp_enqueue_script('freespoke-widget-bundle', $this->jsBundleUrl, [], '1.0.0', \true);
        if (!empty($this->cssBundleUrl)) {
            wp_enqueue_style('freespoke-widget-styles', $this->cssBundleUrl, [], '1.0.0');
        }
        $this->assetsEnqueued = \true;
    }
}
