# Freespoke Partner API PHP Client

A lightweight PHP client for the Freespoke Partner API REST gateway.

## Requirements

- PHP 8.0+
- Guzzle 7.10+
- league/oauth2-client 2.7+ (for client credentials auth)

## Installation

```bash
composer require freespoke/partner
```

If installing from GitHub, add a VCS repository entry and require it:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:Freespoke/partner-api-php.git"
    }
  ],
  "require": {
    "freespoke/partner": "dev-master"
  }
}
```

## Quickstart

```php
<?php

use Freespoke\Partner\Article;
use Freespoke\Partner\Client;
use Freespoke\Partner\Person;

$apiKey = getenv('PARTNERS_API_TOKEN');
$client = Client::create($apiKey, 'https://api.partners.freespoke.com');

$article = new Article();
$article->url = 'https://example.com/article';
$article->title = 'Example Title';
$article->description = 'Short summary';
$article->content = '<p>Hello world</p>';
$article->keywords = ['news', 'example'];
$article->publish_time = new DateTimeImmutable('2024-01-01T00:00:00Z');
$article->image_url = 'https://example.com/image.jpg';

$author = new Person();
$author->id = 'author-1';
$author->name = 'John Doe';
$author->url = 'https://example.com/authors/john';
$author->twitter_id = 'john';
$author->facebook_id = 'john.fb';

$article->setAuthors($author);

$result = $client->index($article);
if ($result === null) {
    // The API returned an error message or an incomplete response body.
    exit(1);
}

$status = $client->getIndexStatus($result->job_id);
$epoch = $client->getEpoch();
```

## Client construction

### API key (static token)

Use the static helper:

```php
$client = Client::create($apiKey, 'https://api.partners.freespoke.com');
```

Or inject your own Guzzle instance (for custom timeouts, retries, proxies, etc):

```php
use GuzzleHttp\Client as GuzzleClient;
use Freespoke\Partner\Client;

$http = new GuzzleClient([
    'base_uri' => 'https://api.partners.freespoke.com/',
    'timeout' => 10,
]);

$client = new Client($http, $apiKey);
```

### OAuth2 client credentials

For service accounts, use `createWithClientCredentials()`. The client will exchange the client ID and secret for an access token automatically and refresh it when it expires.

```php
use Freespoke\Partner\Client;

// Uses the default Freespoke token endpoint (https://accounts.freespoke.com/realms/freespoke/...).
$client = Client::createWithClientCredentials(
    clientId:     getenv('PARTNER_CLIENT_ID'),
    clientSecret: getenv('PARTNER_CLIENT_SECRET'),
);

// Use exactly like an API-key client — token management is transparent.
$result = $client->index($article);
```

Both `tokenURL` and `baseURL` can be overridden if needed (e.g. for testing or local development).

## Authentication

Both authentication modes produce the same HTTP header on every request:

```
Authorization: Bearer <token>
```

With `Client::create()`, you provide the token directly. With `Client::createWithClientCredentials()`, the token is obtained (and refreshed) from the OAuth2 token endpoint automatically.

## Data model

### Article

Required fields:

- `url` (string)
- `title` (string)
- `content` (string)
- `publish_time` (`DateTimeInterface`)
- `authors` (set via `setAuthors()`)

Optional fields:

- `description` (string)
- `keywords` (string[])
- `image_url` (string)

### Person

Optional fields:

- `id` (string)
- `name` (string)
- `url` (string)
- `bias` (float)
- `twitter_id` (string)
- `facebook_id` (string)

## API methods

### Index content

Submit an article to Freespoke for indexing. The API responds with a job token you can poll for completion.

```php
$result = $client->index($article);
```

Returns `IndexResult` with `job_id` and `workflow_id` when successful. Returns `null` if the API responds with `errorMessage`/`error_message` or if required fields are missing in the response body.

### Check indexing status

Poll the status of a previously submitted indexing job.

```php
$status = $client->getIndexStatus($jobId);
```

Returns `IndexStatusResult` or `null` if the response body does not include a `job` object. The `status` field maps to the Partner API job status values (`JOB_STATUS_PENDING`, `JOB_STATUS_COMPLETE`, `JOB_STATUS_FAILED`).

### Epoch

Check whether the indexing requirements have changed so you can decide when to re-submit content.

```php
$epoch = $client->getEpoch();
```

Returns the API epoch as an `int`. If the response body is missing the field, the client returns `0`.

An epoch is a unix timestamp. If the most recent moment at which a URL was published to the Freespoke API is before the epoch value, the URL should be resubmitted.

## Error handling

The client does not swallow HTTP or network errors. Guzzle will throw a `RequestException` for non-2xx responses and transport failures. Wrap calls in `try/catch` if you want to handle these explicitly.

## Further Reading

Visit the [Integration Guide](https://docs.freespoke.com/developers/partner-api/) for more information.

## Testing

```bash
composer install
./vendor/bin/phpunit -c phpunit.xml
```

## License

MIT
