<?php

/**
 * Plugin Name: Freespoke Search (dev loader)
 * Description: Boots the Freespoke Search plugin from its source checkout with a configurable widget base URL, and supplies dev author pictures. Local development only.
 */
declare (strict_types=1);
namespace FreespokeDeps;

if (!\defined('ABSPATH')) {
    exit;
}
$freespoke_dev_plugin_dir = \WP_PLUGIN_DIR . '/freespoke-search/';
$freespoke_dev_plugin_file = $freespoke_dev_plugin_dir . 'freespoke-search.php';
$freespoke_dev_autoload = $freespoke_dev_plugin_dir . 'vendor/autoload.php';
if (!\is_file($freespoke_dev_autoload)) {
    add_action('admin_notices', static function () use ($freespoke_dev_autoload): void {
        echo '<div class="notice notice-error"><p>Freespoke dev loader: ' . esc_html($freespoke_dev_autoload) . ' is missing. Run <code>composer install</code> in the plugin directory.</p></div>';
    });
    return;
}
require_once $freespoke_dev_autoload;
$freespoke_dev_base_url = \rtrim((string) (\getenv('FREESPOKE_BASE_URL') ?: 'http://localhost:8890'), '/');
\Freespoke\Wordpress\Plugin::init(['plugin_file' => $freespoke_dev_plugin_file, 'plugin_dir' => $freespoke_dev_plugin_dir, 'plugin_url' => plugins_url('', $freespoke_dev_plugin_file), 'base_url' => $freespoke_dev_base_url, 'js_bundle' => '/widgets/freespoke-search/static/widget-bundle.v1.js', 'css_bundle' => '', 'overrides' => []]);
/**
 * Optional: imitate a site whose authors are a post type rather than user
 * profiles, with the headshot as featured image, the one-line description as
 * excerpt, and the bio as content. Set FREESPOKE_AUTHOR_POST_TYPE=post-author
 * on the containers to turn it on. The plugin itself only needs the post type
 * name; everything in this block is harness scaffolding for that shape of
 * site and is not part of the plugin.
 */
$freespoke_dev_author_post_type = \trim((string) \getenv('FREESPOKE_AUTHOR_POST_TYPE'));
if ($freespoke_dev_author_post_type !== '') {
    add_action('init', static function () use ($freespoke_dev_author_post_type): void {
        register_post_type($freespoke_dev_author_post_type, [
            'label' => 'Authors',
            'public' => \true,
            'has_archive' => \false,
            'show_in_rest' => \true,
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'],
            // Author pages at /author/<slug>/, the URL shape a visitor expects.
            // The native author archive is moved to /writer/ below to make room.
            'rewrite' => ['slug' => 'author', 'with_front' => \false],
        ]);
    });
    add_action('init', static function (): void {
        global $wp_rewrite;
        $wp_rewrite->author_base = 'writer';
    }, 0);
    add_filter(\Freespoke\Wordpress\AuthorResolver::POST_TYPE_FILTER, static fn(): string => $freespoke_dev_author_post_type);
    // The job title, description, bio, and the link to the WordPress user
    // live in custom fields on the author post, named as in a real ACF field
    // group: the description and bio are WYSIWYG fields holding HTML, and the
    // user is linked by login rather than ID.
    add_filter(\Freespoke\Wordpress\AuthorResolver::POST_META_FILTER, static fn(): array => ['title' => 'title', 'description' => 'summary', 'bio' => 'bio', 'user' => 'user_login_temporary']);
}
/**
 * Dev stand-in for how a publisher fills in the author box from its own user
 * fields when authors are WordPress users.
 * Users carrying `freespoke_dev_title`, `freespoke_dev_description` or
 * `freespoke_dev_bio` meta get those values. A site can also return null here
 * to leave an author out.
 */
add_filter(\Freespoke\Wordpress\AuthorResolver::FILTER, static function (?array $author, object $user, string $query): ?array {
    if ($author === null) {
        return null;
    }
    foreach (['title', 'description', 'bio'] as $field) {
        $value = (string) get_user_meta((int) $user->ID, 'freespoke_dev_' . $field, \true);
        if ($value !== '') {
            $author[$field] = $value;
        }
    }
    return $author;
}, 10, 3);
/**
 * Dev stand-in for an avatar plugin: a user with `freespoke_dev_avatar` meta
 * gets that URL from get_avatar_url(), the way Simple Local Avatars and
 * similar plugins supply staff photos. Users without it fall through to
 * Gravatar, which with the resolver's 404 default yields no picture.
 */
add_filter('pre_get_avatar_data', static function (array $args, $id_or_email): array {
    $user_id = 0;
    if (\is_numeric($id_or_email)) {
        $user_id = (int) $id_or_email;
    } elseif ($id_or_email instanceof WP_User) {
        $user_id = $id_or_email->ID;
    }
    if ($user_id > 0) {
        $url = (string) get_user_meta($user_id, 'freespoke_dev_avatar', \true);
        if ($url !== '') {
            $args['url'] = $url;
            $args['found_avatar'] = \true;
        }
    }
    return $args;
}, 10, 2);
