# Freespoke Search WordPress Plugin

Embed the Freespoke Search Widget on your WordPress site and automatically publish your content to Freespoke's search index.

**Requires PHP 8.1+** and **WordPress 6.0+**.

## Installation

1. Download the latest release zip from the [releases page](https://github.com/Freespoke/wordpress-plugin/releases).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate **Freespoke Search**.

The plugin checks for updates automatically via GitHub releases.

## Configuration

### Client Credentials (recommended)

Add your OAuth2 credentials to `wp-config.php`:

```php
define('FREESPOKE_CLIENT_ID', 'your-client-id');
define('FREESPOKE_CLIENT_SECRET', 'your-client-secret');
```

### API Key

Alternatively, authenticate with an API key:

```php
define('FREESPOKE_PUBLISHER_API_KEY', 'your-api-key-here');
```

### All Configuration Constants

All constants are optional. When defined in `wp-config.php`, the corresponding field is locked in the admin UI.

| Constant | Description |
| --- | --- |
| `FREESPOKE_PUBLISHER_API_KEY` | API key for Partner API authentication |
| `FREESPOKE_CLIENT_ID` | OAuth2 client ID |
| `FREESPOKE_CLIENT_SECRET` | OAuth2 client secret |
| `FREESPOKE_TOKEN_URL` | Custom OAuth2 token endpoint (not shown in admin UI) |
| `FREESPOKE_PUBLISHER_URL` | Custom Partner API base URL (not shown in admin UI) |
| `FREESPOKE_NOTICE_EMAILS` | Comma-separated emails for failure notifications |

Settings can also be managed from **Tools → Freespoke Publisher** in the WordPress admin.

## Search Widget

### Shortcode

Add the widget anywhere in the WordPress editor:

```
[freespoke_search client_id="YOUR_CLIENT_ID" theme="light" placeholder="Search the news..."]
```

### PHP

Render the widget directly in templates or custom blocks:

```php
use Freespoke\Wordpress\Widget;
use Freespoke\Wordpress\WidgetOptions;

$widget = Widget::getInstance();

$options = new WidgetOptions([
    'client_id' => 'YOUR_CLIENT_ID',
    'embedded_search' => true,
    'theme' => 'dark',
    'min_height' => '400px',
]);

echo $widget->renderWidget($options);
```

Assets (JS + CSS) are enqueued automatically on first render.

### Widget Options

To control or modify the widget behavior or styling, see our [docs](https://freespoke.com/docs/widgets/freespoke-search).

### Author Page Links

When a widget uses both `auto_search` and `embedded_search` and the page is
requested with a search query
(for example `/search/?q=jane+doe`), the plugin looks up authors on your site
whose display name contains every word of the query and passes their name,
author archive URL, and profile picture to the widget. The widget shows those
authors as links above the embedded results, so a visitor searching for a
writer can jump straight to their page.

The picture comes from `get_avatar_url()`, so a local avatar plugin or a
Gravatar both work. An author with no picture shows name and link only. To
supply photos, job titles, and descriptions from your own user fields, see
the filter below.

Only authors with published posts are considered, and at most three are shown.
The lookup happens on the server when the page renders, so it reflects the
query in the URL; it is not repeated for searches typed into the widget without
a page reload. The cards show alongside that query's results and go away when
the visitor runs a different search. Widgets without `embedded_search` (a
header search box that redirects to the results page, for example) skip the
lookup, since they have nowhere to show the cards.

Matched authors are kept in the WordPress object cache for ten minutes per
query, so with a persistent object cache repeated searches skip the database
lookup. Changes made through the filters below still apply immediately.

To turn this off for a widget:

```
[freespoke_search client_id="YOUR_CLIENT_ID" embedded_search="true" auto_search="true" author_links="false"]
```

#### Filling in the author box from your own fields

Many sites keep staff photos, job titles, and bios in their own user fields
rather than the WordPress avatar. The `freespoke_search_author` filter runs
once per matching author and lets you supply those. It receives the record the
plugin built (`name`, `url`, and `image` when WordPress has an avatar), the
`WP_User`, and the search query. Return the record with more fields filled in,
or `null` to leave that author out.

```php
add_filter('freespoke_search_author', function (?array $author, WP_User $user, string $query): ?array {
    $author['image']       = get_field('headshot', 'user_' . $user->ID)['sizes']['thumbnail'] ?? $author['image'] ?? null;
    $author['title']       = get_field('job_title', 'user_' . $user->ID);
    $author['description'] = get_field('short_bio', 'user_' . $user->ID);
    $author['bio']         = get_field('biography', 'user_' . $user->ID);
    return $author;
}, 10, 3);
```

| Field         | Shown as |
| ------------- | -------- |
| `name`        | the author's name (required) |
| `url`         | where the card links (required) |
| `image`       | a 40px circle; return a thumbnail-sized URL rather than the original |
| `title`       | its own line under the name, e.g. "White House Correspondent" |
| `description` | one or two lines under the name |
| `bio`         | accepted now so you can wire it once; not shown or sent anywhere yet |

#### Authors kept as a post type

Some sites keep authors as posts rather than user profiles: an "Authors"
section in the admin, each author a post with the headshot as featured image,
a one-line description as excerpt, and the bio as content, while the user
accounts only carry bylines. Name that post type and the plugin matches the
query against the post titles instead of user display names, which is usually
the fuller public name:

```php
// wp-config.php
define('FREESPOKE_AUTHOR_POST_TYPE', 'post-author');

// or from a theme or plugin
add_filter('freespoke_search_author_post_type', fn() => 'post-author');
```

In this mode the card's link is the post's permalink, the picture is the
featured image at thumbnail size, the description is the excerpt, and the bio
is the content. Sites that keep some of these in custom fields name the meta
keys instead, and may also name the field that links the author post to its
WordPress user:

```php
add_filter('freespoke_search_author_post_meta', fn() => [
    'title'       => 'job_title',          // shown under the name
    'description' => 'short_description',  // replaces the excerpt
    'bio'         => 'biography',          // replaces the content
    'image'       => 'headshot',           // attachment ID or URL; replaces the featured image
    'user'        => 'author_user',        // one user ID or login, or several
]);
```

Any subset works. With Advanced Custom Fields, the meta key is the field's
**Name** as shown in the field group, since ACF stores each field as post
meta under that name. Fields that hold HTML, such as ACF's WYSIWYG fields,
are reduced to plain text before they reach the widget. When `user` is mapped, authors are ordered by those users'
published post counts, so a surname shared by more authors than the widget
shows keeps the most prolific ones. Without it, WordPress's search relevance
decides. `freespoke_search_author` still runs on each author afterwards, with
the author post as its second argument instead of a `WP_User`, and
`freespoke_search_authors` below can reorder the final list.

#### Replacing or reordering the list

`freespoke_search_authors` runs on the finished list, with the query, even
when nothing matched. Return a different list to reorder, add to, or replace
the matches entirely, for example from your own author index. Entries are
cleaned the same way as above and at most three are shown.

```php
add_filter('freespoke_search_authors', function (array $authors, string $query): array {
    return $authors ?: my_site_author_lookup($query);
}, 10, 2);
```

## Content Publishing

When credentials are configured, the plugin automatically submits posts to Freespoke's search index:

- **On publish** — posts are submitted immediately when saved or scheduled.
- **Hourly cron** — re-indexes posts that haven't been submitted since the current epoch, and polls pending job statuses.
- **Failure notifications** — emails are sent to configured recipients when submissions fail.

Submission status is visible per-post in the block editor and on the **Tools → Freespoke Publisher** page.

## Contributing

This repository is a read-only mirror of Freespoke’s internal monorepo. All development occurs internally and is periodically synced here by squashing changes into a single commit and force-pushing updates.

We welcome issues and pull requests. If you submit a pull request and it is accepted, the changes will be applied to our internal repository and later reflected here through the sync process.

Because of this workflow, individual commits and authorship are not preserved in this repository. Contributions will not appear with original author attribution in the public commit history.

## Support

For API access or questions, contact your Freespoke partner representative or email help@freespoke.com.

## License

MIT
