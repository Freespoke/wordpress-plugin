<?php

declare (strict_types=1);
namespace FreespokeDeps;

/**
 * Legacy class-name aliases.
 *
 * Using spl_autoload_register ensures the aliases resolve on first use
 * regardless of load order (e.g. a theme referencing FreespokeSearchWidget
 * before the plugin's init hook fires).
 */
\spl_autoload_register(static function (string $class): void {
    static $aliases = ['FreespokeSearchWidget' => \Freespoke\Wordpress\Widget::class, 'FreespokeSearchWidgetOptions' => \Freespoke\Wordpress\WidgetOptions::class];
    if (isset($aliases[$class])) {
        \class_alias($aliases[$class], $class);
    }
});
