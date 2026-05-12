<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

class Settings
{
    private const DEFAULT_PUBLISHER_URL = 'https://api.partners.freespoke.com';
    private const DEFAULT_TOKEN_URL = 'https://accounts.freespoke.com/realms/freespoke/protocol/openid-connect/token';
    private const DEFAULT_NOTICE_EMAILS = ['technology@freespoke.com'];
    /** @var array<string, mixed> Values from wp-config.php constants, keyed by setting name */
    private array $overrides;
    /**
     * @param array<string, mixed> $overrides Constant overrides from the plugin entrypoint.
     *   Supported keys: client_id, client_secret, token_url, api_key, publisher_url,
     *   notice_emails, post_types, include_pages, content_meta_fields.
     *   Presence of a key means the value is locked (not editable in admin).
     */
    public function __construct(array $overrides = [])
    {
        $this->overrides = $overrides;
    }
    public function getAuthMode(): string
    {
        if ($this->getClientId() !== '' && $this->getClientSecret() !== '') {
            return 'client_credentials';
        }
        return 'api_key';
    }
    public function hasCredentials(): bool
    {
        if ($this->getAuthMode() === 'client_credentials') {
            return \true;
        }
        return $this->getApiKey() !== '';
    }
    public function getClientId(): string
    {
        if (isset($this->overrides['client_id'])) {
            return (string) $this->overrides['client_id'];
        }
        $option = get_option('freespoke_client_id', '');
        return is_string($option) ? $option : '';
    }
    public function isClientIdLocked(): bool
    {
        return isset($this->overrides['client_id']);
    }
    public function getClientSecret(): string
    {
        if (isset($this->overrides['client_secret'])) {
            return (string) $this->overrides['client_secret'];
        }
        $option = get_option('freespoke_client_secret', '');
        return is_string($option) ? $option : '';
    }
    public function isClientSecretLocked(): bool
    {
        return isset($this->overrides['client_secret']);
    }
    public function getTokenUrl(): string
    {
        if (isset($this->overrides['token_url'])) {
            return (string) $this->overrides['token_url'];
        }
        $option = get_option('freespoke_token_url', self::DEFAULT_TOKEN_URL);
        return is_string($option) && $option !== '' ? $option : self::DEFAULT_TOKEN_URL;
    }
    public function getApiKey(): string
    {
        if (isset($this->overrides['api_key'])) {
            return (string) $this->overrides['api_key'];
        }
        $option = get_option('freespoke_publisher_api_key', '');
        return is_string($option) ? $option : '';
    }
    public function isApiKeyLocked(): bool
    {
        return isset($this->overrides['api_key']);
    }
    public function getPublisherUrl(): string
    {
        if (isset($this->overrides['publisher_url'])) {
            return $this->normalizeBaseUrl((string) $this->overrides['publisher_url']);
        }
        // Check new option name first, fall back to legacy name.
        $option = get_option('freespoke_publisher_url', null);
        if ($option === null) {
            $option = get_option('freespoke_publisher_uri', self::DEFAULT_PUBLISHER_URL);
        }
        $url = is_string($option) ? $option : self::DEFAULT_PUBLISHER_URL;
        return $this->normalizeBaseUrl($url);
    }
    /**
     * @return string[]
     */
    public function getPostTypes(): array
    {
        // New override takes precedence.
        if (isset($this->overrides['post_types'])) {
            $types = $this->overrides['post_types'];
            if (is_string($types)) {
                $types = array_filter(array_map('trim', explode(',', $types)));
            }
            if (!is_array($types)) {
                $types = [];
            }
            return $this->normalizePostTypes($types);
        }
        // Legacy override support.
        if (isset($this->overrides['include_pages'])) {
            $types = ['post'];
            if (filter_var($this->overrides['include_pages'], \FILTER_VALIDATE_BOOLEAN)) {
                $types[] = 'page';
            }
            return $types;
        }
        // New option.
        $option = get_option('freespoke_post_types', \false);
        if (is_array($option)) {
            return $this->normalizePostTypes($option);
        }
        // Legacy option fallback.
        $types = ['post'];
        if (filter_var(get_option('freespoke_include_pages', \false), \FILTER_VALIDATE_BOOLEAN)) {
            $types[] = 'page';
        }
        return $types;
    }
    public function isPostTypesLocked(): bool
    {
        return isset($this->overrides['post_types']) || isset($this->overrides['include_pages']);
    }
    public function getNoticeEmails(): array
    {
        if (isset($this->overrides['notice_emails'])) {
            $emails = $this->overrides['notice_emails'];
        } else {
            $emails = get_option('freespoke_notice_emails', self::DEFAULT_NOTICE_EMAILS);
        }
        if (!is_array($emails)) {
            $emails = array_filter(array_map('trim', explode(',', (string) $emails)));
        }
        return array_values(array_filter(array_unique($emails)));
    }
    public function isNoticeEmailsLocked(): bool
    {
        return isset($this->overrides['notice_emails']);
    }
    public function save(array $input): void
    {
        if (!$this->isClientIdLocked() && isset($input['client_id'])) {
            update_option('freespoke_client_id', sanitize_text_field($input['client_id']));
        }
        if (!$this->isClientSecretLocked() && isset($input['client_secret'])) {
            update_option('freespoke_client_secret', wp_unslash((string) $input['client_secret']));
        }
        if (!$this->isApiKeyLocked() && isset($input['api_key'])) {
            update_option('freespoke_publisher_api_key', wp_unslash((string) $input['api_key']));
        }
        if (!$this->isNoticeEmailsLocked() && isset($input['notice_emails'])) {
            $emails = array_filter(array_map('trim', explode(',', (string) $input['notice_emails'])));
            update_option('freespoke_notice_emails', $emails);
        }
        if (!$this->isPostTypesLocked()) {
            $postTypes = isset($input['post_types']) && is_array($input['post_types']) ? array_map('sanitize_text_field', $input['post_types']) : [];
            update_option('freespoke_post_types', $this->normalizePostTypes($postTypes));
        }
        if (!$this->isContentMetaFieldsLocked() && isset($input['content_meta_fields'])) {
            update_option('freespoke_content_meta_fields', $this->normalizeContentMetaFields($input['content_meta_fields']));
        }
        if (isset($input['auth_mode'])) {
            update_option('freespoke_auth_mode', sanitize_text_field($input['auth_mode']));
        }
    }
    /**
     * Returns the constant name responsible for locking post types.
     */
    public function getPostTypesLockSource(): string
    {
        if (isset($this->overrides['post_types'])) {
            return 'FREESPOKE_POST_TYPES';
        }
        return 'FREESPOKE_INCLUDE_PAGES';
    }
    /**
     * Returns the configured map of post-meta field names to human-readable
     * descriptions. Publisher concatenates the values of these meta keys onto
     * the main content before submission. Descriptions are admin-UI metadata
     * only and are not sent to the indexer.
     *
     * @return array<string, string> field_name => description, insertion order preserved
     */
    public function getContentMetaFields(): array
    {
        if (isset($this->overrides['content_meta_fields'])) {
            return $this->normalizeContentMetaFields($this->overrides['content_meta_fields']);
        }
        $option = get_option('freespoke_content_meta_fields', []);
        return $this->normalizeContentMetaFields($option);
    }
    /**
     * @return string[] Just the meta-key field names, in configured order.
     */
    public function getContentMetaFieldKeys(): array
    {
        return array_keys($this->getContentMetaFields());
    }
    public function isContentMetaFieldsLocked(): bool
    {
        return isset($this->overrides['content_meta_fields']);
    }
    public function getContentMetaFieldsLockSource(): string
    {
        return 'FREESPOKE_CONTENT_META_FIELDS';
    }
    /**
     * Normalize a content-meta-fields value from either option storage or a
     * constant override into an associative map of field_name => description.
     *
     * Accepts two input shapes:
     *   - Associative map: ['field_name' => 'description', ...]
     *   - List of row maps: [['field_name' => 'x', 'description' => 'y'], ...]
     *     (used by the admin form submit path)
     *
     * Invalid entries (non-string field_name, empty field_name, etc.) are
     * silently dropped. Duplicate field_names: last description wins.
     *
     * @param mixed $input
     * @return array<string, string>
     */
    private function normalizeContentMetaFields($input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $out = [];
        foreach ($input as $k => $v) {
            if (is_int($k) && is_array($v)) {
                // Row-shape: ['field_name' => ..., 'description' => ...]
                $fieldName = $v['field_name'] ?? '';
                $description = $v['description'] ?? '';
            } else {
                // Map-shape: 'field_name' => 'description'
                $fieldName = $k;
                $description = $v;
            }
            if (!is_string($fieldName)) {
                continue;
            }
            $fieldName = str_replace("\x00", '', trim($fieldName));
            if ($fieldName === '') {
                continue;
            }
            if (strlen($fieldName) > 255) {
                $fieldName = substr($fieldName, 0, 255);
            }
            if (!is_string($description)) {
                $description = '';
            }
            $description = trim($description);
            $out[$fieldName] = $description;
        }
        return $out;
    }
    /**
     * Normalize, deduplicate, ensure 'post' is present, and exclude 'attachment'.
     *
     * @param mixed[] $types Raw type slugs (may contain non-strings, empties, whitespace)
     * @return string[]
     */
    private function normalizePostTypes(array $types): array
    {
        $types = array_map(static fn($v): string => trim((string) $v), $types);
        $types = array_filter($types, static fn(string $v): bool => $v !== '');
        return array_values(array_diff(array_unique(array_merge(['post'], $types)), ['attachment']));
    }
    /**
     * Strip trailing /v1/content from legacy saved values.
     * The Partner PHP client appends paths internally.
     */
    private function normalizeBaseUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $suffix = '/v1/content';
        if (str_ends_with($url, $suffix)) {
            $url = substr($url, 0, -strlen($suffix));
        }
        return $url !== '' ? $url : self::DEFAULT_PUBLISHER_URL;
    }
}
