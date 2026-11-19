<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

use FreespokeDeps\Freespoke\Partner\Client;
class ClientFactory
{
    private \Freespoke\Wordpress\Settings $settings;
    private ?Client $client = null;
    public function __construct(\Freespoke\Wordpress\Settings $settings)
    {
        $this->settings = $settings;
    }
    public function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = $this->createClient();
        }
        return $this->client;
    }
    /**
     * Build a temporary client from explicit credentials (used by test button
     * before settings are saved).
     */
    public function createFromCredentials(string $authMode, string $clientId, string $clientSecret, string $apiKey): Client
    {
        if ($authMode === 'client_credentials' && $clientId !== '' && $clientSecret !== '') {
            return Client::createWithClientCredentials($clientId, $clientSecret, $this->settings->getTokenUrl(), $this->settings->getPublisherUrl());
        }
        return Client::create($apiKey, $this->settings->getPublisherUrl());
    }
    private function createClient(): Client
    {
        if ($this->settings->getAuthMode() === 'client_credentials') {
            return Client::createWithClientCredentials($this->settings->getClientId(), $this->settings->getClientSecret(), $this->settings->getTokenUrl(), $this->settings->getPublisherUrl());
        }
        return Client::create($this->settings->getApiKey(), $this->settings->getPublisherUrl());
    }
}
