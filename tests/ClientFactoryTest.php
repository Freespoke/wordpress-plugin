<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Freespoke\Partner\Client;
use Freespoke\Wordpress\ClientFactory;
use Freespoke\Wordpress\Settings;

class ClientFactoryTest extends TestCase
{
    public function testGetClientCachesInstance(): void
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->expects('getAuthMode')->andReturn('api_key');
        $settings->expects('getApiKey')->andReturn('test-key');
        $settings->expects('getPublisherUrl')->andReturn('https://api.example.com');

        $factory = new ClientFactory($settings);

        $client1 = $factory->getClient();
        $client2 = $factory->getClient();

        $this->assertInstanceOf(Client::class, $client1);
        $this->assertSame($client1, $client2);
    }

    public function testCreatesClientWithApiKeyAuth(): void
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->expects('getAuthMode')->andReturn('api_key');
        $settings->expects('getApiKey')->andReturn('my-api-key');
        $settings->expects('getPublisherUrl')->andReturn('https://api.example.com');

        $factory = new ClientFactory($settings);
        $client = $factory->getClient();

        $this->assertInstanceOf(Client::class, $client);
    }

    public function testCreatesClientWithClientCredentials(): void
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->expects('getAuthMode')->andReturn('client_credentials');
        $settings->expects('getClientId')->andReturn('cid');
        $settings->expects('getClientSecret')->andReturn('csecret');
        $settings->expects('getTokenUrl')->andReturn('https://token.example.com');
        $settings->expects('getPublisherUrl')->andReturn('https://api.example.com');

        $factory = new ClientFactory($settings);
        $client = $factory->getClient();

        $this->assertInstanceOf(Client::class, $client);
    }
}
