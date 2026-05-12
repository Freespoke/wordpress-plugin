<?php

declare (strict_types=1);
namespace FreespokeDeps\Freespoke\Partner\Tests;

use FreespokeDeps\Freespoke\Partner\APIKeyAuthProvider;
use FreespokeDeps\Freespoke\Partner\Article;
use FreespokeDeps\Freespoke\Partner\Client as PartnerClient;
use FreespokeDeps\Freespoke\Partner\Person;
use FreespokeDeps\Freespoke\Partner\TokenAuthProvider;
use FreespokeDeps\GuzzleHttp\Client as GuzzleClient;
use FreespokeDeps\GuzzleHttp\Exception\RequestException;
use FreespokeDeps\GuzzleHttp\Handler\MockHandler;
use FreespokeDeps\GuzzleHttp\HandlerStack;
use FreespokeDeps\GuzzleHttp\Middleware;
use FreespokeDeps\GuzzleHttp\Psr7\Request;
use FreespokeDeps\GuzzleHttp\Psr7\Response;
use FreespokeDeps\League\OAuth2\Client\Provider\GenericProvider;
use FreespokeDeps\League\OAuth2\Client\Token\AccessTokenInterface;
use FreespokeDeps\PHPUnit\Framework\TestCase;
class FreespokeTest extends TestCase
{
    private function buildClient(array $responses, array &$history): PartnerClient
    {
        $mock = new MockHandler($responses);
        $history = [];
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $httpClient = new GuzzleClient(['handler' => $handlerStack, 'base_uri' => 'https://api.example.test/']);
        return new PartnerClient($httpClient, new APIKeyAuthProvider('test-token'));
    }
    private function makeArticle(string $imageUrl = 'https://example.com/image.jpg'): Article
    {
        $article = new Article();
        $article->url = 'https://example.com/article';
        $article->title = 'Example Title';
        $article->description = 'Example description';
        $article->content = 'Example content';
        $article->image_url = $imageUrl;
        $article->keywords = ['alpha', 'beta'];
        $article->publish_time = new \DateTimeImmutable('2024-01-02T03:04:05Z');
        $author = new Person();
        $author->id = 'author-1';
        $author->name = 'Jane Doe';
        $author->url = 'https://example.com/authors/jane';
        $author->bias = 0.25;
        $author->twitter_id = 'jane';
        $author->facebook_id = 'jane.fb';
        $article->setAuthors($author);
        return $article;
    }
    public function testIndexSendsExpectedPayloadAndAuthHeaders(): void
    {
        $history = [];
        $client = $this->buildClient([new Response(200, ['Content-Type' => 'application/json'], json_encode(['job_id' => 'job-123', 'workflow_id' => 'workflow-456'], \JSON_UNESCAPED_SLASHES))], $history);
        $result = $client->index($this->makeArticle());
        $this->assertNotNull($result);
        $this->assertSame('job-123', $result->job_id);
        $this->assertSame('workflow-456', $result->workflow_id);
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/content', $request->getUri()->getPath());
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $payload = json_decode((string) $request->getBody(), \true);
        $this->assertSame('https://example.com/article', $payload['url']);
        $this->assertSame('Example Title', $payload['title']);
        $this->assertSame('Example description', $payload['description']);
        $this->assertSame('Example content', $payload['content']);
        $this->assertSame(['alpha', 'beta'], $payload['keywords']);
        $this->assertSame('2024-01-02T03:04:05+00:00', $payload['publish_time']);
        $this->assertSame('https://example.com/image.jpg', $payload['image_url']);
        $this->assertCount(1, $payload['authors']);
        $this->assertSame('author-1', $payload['authors'][0]['id']);
        $this->assertSame('Jane Doe', $payload['authors'][0]['name']);
        $this->assertSame('https://example.com/authors/jane', $payload['authors'][0]['url']);
        $this->assertSame(0.25, $payload['authors'][0]['bias']);
        $this->assertSame('jane', $payload['authors'][0]['twitter_id']);
        $this->assertSame('jane.fb', $payload['authors'][0]['facebook_id']);
    }
    public function testIndexOmitsEmptyImageUrl(): void
    {
        $history = [];
        $client = $this->buildClient([new Response(200, ['Content-Type' => 'application/json'], json_encode(['jobId' => 'job-123', 'workflowId' => 'workflow-456'], \JSON_UNESCAPED_SLASHES))], $history);
        $result = $client->index($this->makeArticle(''));
        $this->assertNotNull($result);
        $payload = json_decode((string) $history[0]['request']->getBody(), \true);
        $this->assertArrayNotHasKey('image_url', $payload);
    }
    public function testIndexReturnsNullWhenApiErrorMessagePresent(): void
    {
        $history = [];
        $client = $this->buildClient([new Response(200, ['Content-Type' => 'application/json'], json_encode(['errorMessage' => 'bad request'], \JSON_UNESCAPED_SLASHES))], $history);
        $result = $client->index($this->makeArticle());
        $this->assertNull($result);
    }
    public function testIndexReturnsNullWhenMissingWorkflowId(): void
    {
        $history = [];
        $client = $this->buildClient([new Response(200, ['Content-Type' => 'application/json'], json_encode(['job_id' => 'job-123'], \JSON_UNESCAPED_SLASHES))], $history);
        $result = $client->index($this->makeArticle());
        $this->assertNull($result);
    }
    public function testGetEpochReturnsIntFromNumericString(): void
    {
        $history = [];
        $client = $this->buildClient([new Response(200, ['Content-Type' => 'application/json'], json_encode(['epoch' => '42'], \JSON_UNESCAPED_SLASHES))], $history);
        $this->assertSame(42, $client->getEpoch());
    }
    public function testGetEpochReturnsZeroWhenMissing(): void
    {
        $history = [];
        $client = $this->buildClient([new Response(200, ['Content-Type' => 'application/json'], json_encode([], \JSON_UNESCAPED_SLASHES))], $history);
        $this->assertSame(0, $client->getEpoch());
    }
    public function testGetEpochRethrowsRequestException(): void
    {
        $history = [];
        $client = $this->buildClient([new RequestException('boom', new Request('GET', 'https://api.example.test/v1/content/epoch'))], $history);
        $this->expectException(RequestException::class);
        $client->getEpoch();
    }
    public function testGetIndexStatusParsesJob(): void
    {
        $history = [];
        $client = $this->buildClient([new Response(200, ['Content-Type' => 'application/json'], json_encode(['job' => ['job_id' => 'job-abc', 'status' => 'JOB_STATUS_COMPLETE', 'error' => ['code' => 0, 'message' => '', 'details' => []], 'metadata' => ['@type' => 'type.googleapis.com/foo', 'value' => 'abc'], 'result' => ['@type' => 'type.googleapis.com/bar', 'value' => 'xyz'], 'create_time' => '2024-01-02T03:04:05Z', 'update_time' => '2024-01-03T04:05:06Z']], \JSON_UNESCAPED_SLASHES))], $history);
        $result = $client->getIndexStatus('job-abc');
        $this->assertNotNull($result);
        $this->assertSame('job-abc', $result->job_id);
        $this->assertSame('JOB_STATUS_COMPLETE', $result->status);
        $this->assertSame(['code' => 0, 'message' => '', 'details' => []], $result->error);
        $this->assertSame(['@type' => 'type.googleapis.com/foo', 'value' => 'abc'], $result->metadata);
        $this->assertSame(['@type' => 'type.googleapis.com/bar', 'value' => 'xyz'], $result->result);
        $this->assertSame('2024-01-02T03:04:05+00:00', $result->create_time?->format(\DATE_RFC3339));
        $this->assertSame('2024-01-03T04:05:06+00:00', $result->update_time?->format(\DATE_RFC3339));
        $request = $history[0]['request'];
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('/v1/job/job-abc', $request->getUri()->getPath());
    }
    public function testGetIndexStatusRethrowsRequestException(): void
    {
        $history = [];
        $client = $this->buildClient([new RequestException('boom', new Request('GET', 'https://api.example.test/v1/job/job-abc'))], $history);
        $this->expectException(RequestException::class);
        $client->getIndexStatus('job-abc');
    }
    // --- OAuth2 client credentials tests ---
    private function buildOAuthClient(array $apiResponses, array &$history, GenericProvider $provider): PartnerClient
    {
        $mock = new MockHandler($apiResponses);
        $history = [];
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $httpClient = new GuzzleClient(['handler' => $handlerStack, 'base_uri' => 'https://api.example.test/']);
        return new PartnerClient($httpClient, new TokenAuthProvider($provider));
    }
    private function mockProvider(string $token, bool $expired = \false): GenericProvider
    {
        $accessToken = $this->createMock(AccessTokenInterface::class);
        $accessToken->method('getToken')->willReturn($token);
        $accessToken->method('hasExpired')->willReturn($expired);
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects($this->once())->method('getAccessToken')->with('client_credentials')->willReturn($accessToken);
        return $provider;
    }
    public function testOAuthClientSendsExchangedTokenAsBearer(): void
    {
        $history = [];
        $client = $this->buildOAuthClient([new Response(200, ['Content-Type' => 'application/json'], json_encode(['epoch' => 7]))], $history, $this->mockProvider('oauth-access-token-xyz'));
        $epoch = $client->getEpoch();
        $this->assertSame(7, $epoch);
        $this->assertCount(1, $history);
        $this->assertSame('Bearer oauth-access-token-xyz', $history[0]['request']->getHeaderLine('Authorization'));
    }
    public function testOAuthClientCachesTokenAcrossRequests(): void
    {
        // The provider mock expects exactly one call to getAccessToken,
        // but we make two API requests — proving the token is cached.
        $accessToken = $this->createMock(AccessTokenInterface::class);
        $accessToken->method('getToken')->willReturn('cached-token');
        $accessToken->method('hasExpired')->willReturn(\false);
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects($this->once())->method('getAccessToken')->with('client_credentials')->willReturn($accessToken);
        $history = [];
        $client = $this->buildOAuthClient([new Response(200, ['Content-Type' => 'application/json'], json_encode(['epoch' => 1])), new Response(200, ['Content-Type' => 'application/json'], json_encode(['epoch' => 2]))], $history, $provider);
        $client->getEpoch();
        $client->getEpoch();
        $this->assertCount(2, $history);
        $this->assertSame('Bearer cached-token', $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer cached-token', $history[1]['request']->getHeaderLine('Authorization'));
    }
    public function testOAuthClientRefreshesExpiredToken(): void
    {
        $expiredToken = $this->createMock(AccessTokenInterface::class);
        $expiredToken->method('getToken')->willReturn('old-token');
        // On the second getToken() call the token is checked and found expired,
        // triggering a refresh.  (The first call short-circuits at the null check,
        // so hasExpired() is only ever invoked once.)
        $expiredToken->method('hasExpired')->willReturn(\true);
        $freshToken = $this->createMock(AccessTokenInterface::class);
        $freshToken->method('getToken')->willReturn('new-token');
        $freshToken->method('hasExpired')->willReturn(\false);
        $provider = $this->createMock(GenericProvider::class);
        $provider->expects($this->exactly(2))->method('getAccessToken')->with('client_credentials')->willReturnOnConsecutiveCalls($expiredToken, $freshToken);
        $history = [];
        $client = $this->buildOAuthClient([new Response(200, ['Content-Type' => 'application/json'], json_encode(['epoch' => 1])), new Response(200, ['Content-Type' => 'application/json'], json_encode(['epoch' => 2]))], $history, $provider);
        $client->getEpoch();
        $client->getEpoch();
        $this->assertCount(2, $history);
        $this->assertSame('Bearer old-token', $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer new-token', $history[1]['request']->getHeaderLine('Authorization'));
    }
}
