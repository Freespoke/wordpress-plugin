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
     *   Supported keys: client_id, client_secret, token_url, api_key, publisher_url, notice_emails.
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
        if (isset($input['auth_mode'])) {
            update_option('freespoke_auth_mode', sanitize_text_field($input['auth_mode']));
        }
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
