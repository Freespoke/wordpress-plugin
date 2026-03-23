<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

class WidgetOptions
{
    public string $client_id = '';
    public string $container_id;
    public string $theme = 'auto';
    public string $placeholder = 'Search...';
    public string $redirect_url = '';
    public string $redirect_target = '_self';
    public bool $embedded_search = \false;
    public bool $auto_search = \false;
    public string $query_param = 'q';
    public string $min_height = '60px';
    public string $primary_bg = '';
    public string $primary_text = '';
    public string $primary_border = '';
    public string $secondary_bg = '';
    public string $secondary_text = '';
    public string $secondary_border = '';
    public string $button_bg = '';
    public string $button_text = '';
    public string $button_hover_bg = '';
    public string $button_hover_text = '';
    public string $input_bg = '';
    public string $input_text = '';
    public string $input_border = '';
    public string $input_placeholder = '';
    public string $input_focus_border = '';
    public string $link_text = '';
    public string $link_hover_text = '';
    public string $font_family = '';
    public string $font_size = '';
    public string $border_radius = '';
    public string $light_primary_bg = '';
    public string $light_primary_text = '';
    public string $light_button_bg = '';
    public string $light_button_text = '';
    public string $light_input_bg = '';
    public string $light_input_text = '';
    public string $dark_primary_bg = '';
    public string $dark_primary_text = '';
    public string $dark_button_bg = '';
    public string $dark_button_text = '';
    public string $dark_input_bg = '';
    public string $dark_input_text = '';
    public function __construct(array $config = [])
    {
        $this->container_id = 'freespoke-widget-' . uniqid();
        $this->apply($config);
    }
    public function apply(array $config): void
    {
        foreach ($config as $key => $value) {
            if (!property_exists($this, $key)) {
                continue;
            }
            if (in_array($key, ['embedded_search', 'auto_search'], \true)) {
                $this->{$key} = $this->toBool($value);
                continue;
            }
            $this->{$key} = is_string($value) ? $value : (string) $value;
        }
    }
    public function overrideWithShortcodeAtts(array $atts): void
    {
        $this->apply($atts);
    }
    /**
     * @deprecated Use overrideWithShortcodeAtts()
     */
    public function override_with_shortcode_atts(array $atts): void
    {
        $this->overrideWithShortcodeAtts($atts);
    }
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], \true);
        }
        return (bool) $value;
    }
}
