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
    public function __construct(string $baseUrl, string $jsBundle, string $cssBundle)
    {
        $this->baseUrl = $baseUrl;
        $this->jsBundleUrl = $baseUrl . $jsBundle;
        $this->cssBundleUrl = $cssBundle !== '' ? $baseUrl . $cssBundle : '';
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('wp_head', [$this, 'addWidgetStyles']);
        add_shortcode('freespoke_search', function ($atts = [], $content = null, $tag = '') {
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
        $widgetConfig = ['clientId' => $options->client_id, 'container' => $options->container_id, 'theme' => $options->theme, 'placeholder' => $options->placeholder, 'redirectUrl' => $options->redirect_url, 'redirectTarget' => $options->redirect_target, 'embeddedSearch' => $options->embedded_search, 'autoSearch' => $options->auto_search, 'queryParam' => $options->query_param, 'baseUrl' => $this->baseUrl];
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
