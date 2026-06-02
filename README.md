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
