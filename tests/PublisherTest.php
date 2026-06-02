<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Brain\Monkey\Functions;
use Freespoke\Partner\Client;
use Freespoke\Partner\IndexResult;
use Freespoke\Wordpress\ClientFactory;
use Freespoke\Wordpress\PostMeta;
use Freespoke\Wordpress\Publisher;
use Freespoke\Wordpress\Settings;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;

class PublisherTest extends TestCase
{
    private Publisher $publisher;
    private ClientFactory|\Mockery\MockInterface $factory;
    private PostMeta|\Mockery\MockInterface $postMeta;
    private Settings|\Mockery\MockInterface $settings;
    private Client|\Mockery\MockInterface $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = \Mockery::mock(Client::class);
        $this->factory = \Mockery::mock(ClientFactory::class);
        $this->factory->allows('getClient')->andReturn($this->client);
        $this->postMeta = \Mockery::mock(PostMeta::class);
        $this->settings = \Mockery::mock(Settings::class);
        $this->settings->allows('getNoticeEmails')->andReturn([]);
        $this->settings->allows('getPostTypes')->andReturn(['post']);
        $this->settings->allows('getContentMetaFieldKeys')->andReturn([]);
        $this->settings->allows('getTestMode')->andReturn(false);

        $this->publisher = new Publisher($this->factory, $this->postMeta, $this->settings);

        // The in-request dedup guard is a static; clear it between tests.
        $prop = (new \ReflectionClass(Publisher::class))->getProperty('submittedThisRequest');
        $prop->setValue(null, []);
    }

    private function makePost(int $id = 1, string $status = 'publish', string $type = 'post', string $password = ''): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_type = $type;
        $post->post_status = $status;
        $post->post_password = $password;
        $post->post_title = 'Test Post';
        $post->post_content = '<p>Test content</p>';
        $post->post_author = 1;

        return $post;
    }

    private function stubBuildArticle(): void
    {
        Functions\expect('get_permalink')->andReturn('https://example.com/post');
        Functions\expect('get_the_excerpt')->andReturn('Excerpt');
        Functions\expect('apply_filters')->andReturnUsing(fn($tag, $content) => $content);
        Functions\expect('get_the_post_thumbnail_url')->andReturn('https://example.com/image.jpg');
        Functions\expect('get_the_date')->andReturn('2026-03-16T12:00:00+00:00');
        Functions\expect('get_the_tags')->andReturn(false);
        Functions\expect('get_the_author_meta')->andReturn('');
        Functions\expect('get_author_posts_url')->andReturn('');
    }

    private function stubNotifyFailure(): void
    {
        Functions\expect('__')->andReturnFirstArg();
        Functions\expect('get_option')->andReturn('admin@example.com');
        Functions\expect('get_post')->andReturn($this->makePost());
        Functions\expect('get_the_title')->andReturn('Test Post');
        Functions\expect('get_edit_post_link')->andReturn('');
        Functions\expect('wp_mail')->andReturn(true);
    }

    public function testSubmitSuccess(): void
    {
        $post = $this->makePost();
        $result = new IndexResult();
        $result->job_id = 'job-abc';
        $result->workflow_id = 'wf-123';

        $this->stubBuildArticle();

        $this->client->expects('index')->once()->andReturn($result);
        $this->postMeta->expects('setJobId')->once()->with(1, 'job-abc');
        $this->postMeta->expects('setSubmitTime')->once()->with(1, \Mockery::type('int'));
        $this->postMeta->expects('clearDocId')->once()->with(1);
        $this->postMeta->expects('clearError')->once()->with(1);

        $actual = $this->publisher->submit(1, $post);
        $this->assertInstanceOf(IndexResult::class, $actual);
        $this->assertSame('job-abc', $actual->job_id);
    }

    public function testSubmitReturnsWpErrorOnException(): void
    {
        $post = $this->makePost();

        $this->stubBuildArticle();
        $this->stubNotifyFailure();

        $this->client->expects('index')->once()->andThrow(new \RuntimeException('Network error'));
        $this->postMeta->expects('setError')->once();

        $actual = $this->publisher->submit(1, $post);
        $this->assertInstanceOf(\WP_Error::class, $actual);
    }

    public function testSubmitClassifiesBadRequestAsPermanent(): void
    {
        $post = $this->makePost();

        $this->stubBuildArticle();
        $this->stubNotifyFailure();

        $response = new GuzzleResponse(400, [], '{"code":3,"message":"validation error"}');
        $request = new GuzzleRequest('POST', 'https://api.example.test/v1/content');
        $this->client
            ->expects('index')
            ->once()
            ->andThrow(new ClientException('400 Bad Request', $request, $response));

        $this->postMeta
            ->expects('setError')
            ->once()
            ->with(1, 'freespoke_api_error', \Mockery::type('string'), false);

        $actual = $this->publisher->submit(1, $post);
        $this->assertInstanceOf(\WP_Error::class, $actual);
    }

    public function testSubmitClassifies500AsRetryable(): void
    {
        $post = $this->makePost();

        $this->stubBuildArticle();
        $this->stubNotifyFailure();

        $response = new GuzzleResponse(500, [], '');
        $request = new GuzzleRequest('POST', 'https://api.example.test/v1/content');
        $this->client
            ->expects('index')
            ->once()
            ->andThrow(new ServerException('500 Internal Server Error', $request, $response));

        $this->postMeta
            ->expects('setError')
            ->once()
            ->with(1, 'freespoke_api_error', \Mockery::type('string'), true);

        $actual = $this->publisher->submit(1, $post);
        $this->assertInstanceOf(\WP_Error::class, $actual);
    }

    public function testSubmitClassifies429AsRetryable(): void
    {
        $post = $this->makePost();

        $this->stubBuildArticle();
        $this->stubNotifyFailure();

        $response = new GuzzleResponse(429, [], '');
        $request = new GuzzleRequest('POST', 'https://api.example.test/v1/content');
        $this->client
            ->expects('index')
            ->once()
            ->andThrow(new ClientException('429 Too Many Requests', $request, $response));

        $this->postMeta
            ->expects('setError')
            ->once()
            ->with(1, 'freespoke_api_error', \Mockery::type('string'), true);

        $this->publisher->submit(1, $post);
    }

    public function testSubmitClassifiesConnectErrorAsRetryable(): void
    {
        $post = $this->makePost();

        $this->stubBuildArticle();
        $this->stubNotifyFailure();

        $request = new GuzzleRequest('POST', 'https://api.example.test/v1/content');
        $this->client
            ->expects('index')
            ->once()
            ->andThrow(new ConnectException('connection refused', $request));

        $this->postMeta
            ->expects('setError')
            ->once()
            ->with(1, 'freespoke_api_error', \Mockery::type('string'), true);

        $this->publisher->submit(1, $post);
    }

    public function testSubmitClassifiesGenericThrowableAsRetryable(): void
    {
        $post = $this->makePost();

        $this->stubBuildArticle();
        $this->stubNotifyFailure();

        $this->client->expects('index')->once()->andThrow(new \RuntimeException('boom'));

        $this->postMeta
            ->expects('setError')
            ->once()
            ->with(1, 'freespoke_api_error', \Mockery::type('string'), true);

        $this->publisher->submit(1, $post);
    }

    public function testSubmitReturnsWpErrorOnNullResult(): void
    {
        $post = $this->makePost();

        $this->stubBuildArticle();
        $this->stubNotifyFailure();

        $this->client->expects('index')->once()->andReturn(null);
        $this->postMeta->expects('setError')->once();

        $actual = $this->publisher->submit(1, $post);
        $this->assertInstanceOf(\WP_Error::class, $actual);
    }

    public function testGetEpochCached(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->with('freespoke_publisher_epoch')
            ->andReturn(42);

        $result = $this->publisher->getEpoch();
        $this->assertSame(42, $result);
    }

    public function testGetEpochFromApi(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->andReturn(false);
        Functions\expect('set_transient')
            ->once()
            ->with('freespoke_publisher_epoch', 99, \Mockery::type('int'));

        $this->client->expects('getEpoch')->once()->andReturn(99);

        $result = $this->publisher->getEpoch();
        $this->assertSame(99, $result);
    }

    public function testGetEpochReturnsWpErrorOnFailure(): void
    {
        Functions\expect('get_transient')->andReturn(false);
        Functions\expect('__')->andReturnFirstArg();

        $this->client->expects('getEpoch')->once()->andThrow(new \RuntimeException('fail'));

        $result = $this->publisher->getEpoch();
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testOnPostSaveSubmitsValidPost(): void
    {
        $post = $this->makePost();
        $result = new IndexResult();
        $result->job_id = 'j';
        $result->workflow_id = 'w';

        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('wp_is_post_revision')->andReturn(false);
        $this->stubBuildArticle();

        $this->client->expects('index')->once()->andReturn($result);
        $this->postMeta->allows('setJobId');
        $this->postMeta->allows('setSubmitTime');
        $this->postMeta->allows('clearDocId');
        $this->postMeta->allows('clearError');

        $this->publisher->onPostSave(1, $post);
    }

    public function testOnPostSaveShowsWarningOnError(): void
    {
        $post = $this->makePost();

        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('wp_is_post_revision')->andReturn(false);
        $this->stubBuildArticle();
        $this->stubNotifyFailure();

        $this->client->expects('index')->once()->andThrow(new \RuntimeException('fail'));
        $this->postMeta->expects('setError')->once();
        Functions\expect('add_action')->once()->with('admin_notices', \Mockery::type('Closure'));

        $this->publisher->onPostSave(1, $post);
    }

    public function testOnPostSaveSkipsRevision(): void
    {
        $post = $this->makePost();
        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('wp_is_post_revision')->once()->andReturn(true);

        $this->client->expects('index')->never();

        $this->publisher->onPostSave(1, $post);
    }

    public function testOnPostSaveSkipsNonPostType(): void
    {
        $post = $this->makePost(1, 'publish', 'page');
        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('wp_is_post_revision')->andReturn(false);

        $this->client->expects('index')->never();

        $this->publisher->onPostSave(1, $post);
    }

    public function testOnPostSaveSubmitsPageWhenIncluded(): void
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->allows('getNoticeEmails')->andReturn([]);
        $settings->allows('getPostTypes')->andReturn(['post', 'page']);
        $settings->allows('getContentMetaFieldKeys')->andReturn([]);
        $settings->allows('getTestMode')->andReturn(false);
        $publisher = new Publisher($this->factory, $this->postMeta, $settings);

        $post = $this->makePost(1, 'publish', 'page');
        $result = new IndexResult();
        $result->job_id = 'j';
        $result->workflow_id = 'w';

        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('wp_is_post_revision')->andReturn(false);
        $this->stubBuildArticle();

        $this->client->expects('index')->once()->andReturn($result);
        $this->postMeta->allows('setJobId');
        $this->postMeta->allows('setSubmitTime');
        $this->postMeta->allows('clearDocId');
        $this->postMeta->allows('clearError');

        $publisher->onPostSave(1, $post);
    }

    public function testOnPostSaveSkipsDraftStatus(): void
    {
        $post = $this->makePost(1, 'draft');
        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('wp_is_post_revision')->andReturn(false);

        $this->client->expects('index')->never();

        $this->publisher->onPostSave(1, $post);
    }

    public function testOnPostSaveSkipsPasswordProtected(): void
    {
        $post = $this->makePost(1, 'publish', 'post', 'secret');
        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('wp_is_post_revision')->andReturn(false);

        $this->client->expects('index')->never();

        $this->publisher->onPostSave(1, $post);
    }

    public function testOnPostSaveSkipsWithoutPermission(): void
    {
        $post = $this->makePost();
        Functions\expect('current_user_can')->andReturn(false);

        $this->client->expects('index')->never();

        $this->publisher->onPostSave(1, $post);
    }

    public function testNotifyFailureSendsEmail(): void
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->allows('getNoticeEmails')->andReturn(['ops@example.com']);
        $settings->allows('getPostTypes')->andReturn(['post']);
        $settings->allows('getContentMetaFieldKeys')->andReturn([]);
        $publisher = new Publisher($this->factory, $this->postMeta, $settings);

        $post = $this->makePost();
        Functions\expect('get_option')->with('admin_email')->andReturn('admin@example.com');
        Functions\expect('get_post')->andReturn($post);
        Functions\expect('get_the_title')->andReturn('Test Post');
        Functions\expect('get_edit_post_link')->andReturn('https://example.com/wp-admin/post.php?post=1');
        Functions\expect('__')->andReturnFirstArg();
        Functions\expect('wp_mail')
            ->once()
            ->with(
                \Mockery::on(fn($r) => in_array('ops@example.com', $r) && in_array('admin@example.com', $r)),
                \Mockery::type('string'),
                \Mockery::type('string'),
            )
            ->andReturn(true);

        $publisher->notifyFailure(1, 'test_error', 'Something broke');
    }

    public function testNotifyFailureSkipsWhenNoRecipients(): void
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->allows('getNoticeEmails')->andReturn([]);
        $settings->allows('getPostTypes')->andReturn(['post']);
        $publisher = new Publisher($this->factory, $this->postMeta, $settings);

        Functions\expect('get_option')->with('admin_email')->andReturn('');
        Functions\expect('wp_mail')->never();

        $publisher->notifyFailure(1, 'err', 'msg');
    }

    public function testOnPostSaveSubmitsCustomPostTypeWhenIncluded(): void
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->allows('getNoticeEmails')->andReturn([]);
        $settings->allows('getPostTypes')->andReturn(['post', 'page', 'recipe']);
        $settings->allows('getContentMetaFieldKeys')->andReturn([]);
        $settings->allows('getTestMode')->andReturn(false);
        $publisher = new Publisher($this->factory, $this->postMeta, $settings);

        $post = $this->makePost(1, 'publish', 'recipe');
        $result = new IndexResult();
        $result->job_id = 'j';
        $result->workflow_id = 'w';

        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('wp_is_post_revision')->andReturn(false);
        $this->stubBuildArticle();

        $this->client->expects('index')->once()->andReturn($result);
        $this->postMeta->allows('setJobId');
        $this->postMeta->allows('setSubmitTime');
        $this->postMeta->allows('clearDocId');
        $this->postMeta->allows('clearError');

        $publisher->onPostSave(1, $post);
    }

    public function testOnPostSaveSkipsCustomPostTypeWhenNotIncluded(): void
    {
        $post = $this->makePost(1, 'publish', 'recipe');
        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('wp_is_post_revision')->andReturn(false);

        $this->client->expects('index')->never();

        $this->publisher->onPostSave(1, $post);
    }

    public function testOnPostSaveSkipsAttachment(): void
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->allows('getNoticeEmails')->andReturn([]);
        $settings->allows('getPostTypes')->andReturn(['post']);
        $publisher = new Publisher($this->factory, $this->postMeta, $settings);

        $post = $this->makePost(1, 'publish', 'attachment');
        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('wp_is_post_revision')->andReturn(false);

        $this->client->expects('index')->never();

        $publisher->onPostSave(1, $post);
    }

    public function testBuildArticleHandlesPermalinkFalse(): void
    {
        $post = $this->makePost();
        $result = new IndexResult();
        $result->job_id = 'j';
        $result->workflow_id = 'w';

        Functions\expect('get_permalink')->andReturn(false);
        Functions\expect('get_the_excerpt')->andReturn('');
        Functions\expect('apply_filters')->andReturnUsing(fn($tag, $content) => $content);
        Functions\expect('get_the_post_thumbnail_url')->andReturn(false);
        Functions\expect('get_the_date')->andReturn(false);
        Functions\expect('get_the_tags')->andReturn(false);
        Functions\expect('get_the_author_meta')->andReturn('');
        Functions\expect('get_author_posts_url')->andReturn('');

        $this->client->expects('index')
            ->once()
            ->with(\Mockery::on(function ($article) {
                return $article->url === '';
            }))
            ->andReturn($result);

        $this->postMeta->allows('setJobId');
        $this->postMeta->allows('setSubmitTime');
        $this->postMeta->allows('clearDocId');
        $this->postMeta->allows('clearError');

        $this->publisher->submit(1, $post);
    }

    /**
     * Helper: stub build-article WP function calls used by the meta-append tests.
     * Returns the last constructed article from $this->client->index(...).
     */
    private function captureArticleFromSubmit(\WP_Post $post): object
    {
        Functions\expect('get_permalink')->andReturn('https://example.com/p');
        Functions\expect('get_the_excerpt')->andReturn('');
        Functions\expect('apply_filters')->andReturnUsing(fn($tag, $content) => $content);
        Functions\expect('get_the_post_thumbnail_url')->andReturn(false);
        Functions\expect('get_the_date')->andReturn(false);
        Functions\expect('get_the_tags')->andReturn(false);
        Functions\expect('get_the_author_meta')->andReturn('');
        Functions\expect('get_author_posts_url')->andReturn('');
        Functions\expect('wp_kses_post')->andReturnUsing(fn($s) => $s);

        $captured = new \stdClass();
        $result = new IndexResult();
        $result->job_id = 'j';
        $result->workflow_id = 'w';

        $this->client->expects('index')
            ->once()
            ->with(\Mockery::on(function ($article) use ($captured) {
                $captured->article = $article;
                return true;
            }))
            ->andReturn($result);

        $this->postMeta->allows('setJobId');
        $this->postMeta->allows('setSubmitTime');
        $this->postMeta->allows('clearDocId');
        $this->postMeta->allows('clearError');

        $this->publisher->submit($post->ID, $post);
        return $captured->article;
    }

    public function testBuildArticleAppendsMetaValueWhenContentEmpty(): void
    {
        $post = $this->makePost();
        $post->post_content = '';
        $this->settings = \Mockery::mock(Settings::class);
        $this->settings->allows('getNoticeEmails')->andReturn([]);
        $this->settings->allows('getPostTypes')->andReturn(['post']);
        $this->settings->allows('getContentMetaFieldKeys')->andReturn(['acf-body']);
        $this->settings->allows('getTestMode')->andReturn(false);
        $this->publisher = new Publisher($this->factory, $this->postMeta, $this->settings);

        Functions\expect('get_post_meta')->with(1, 'acf-body', true)->andReturn('the body text');

        $article = $this->captureArticleFromSubmit($post);
        $this->assertSame('the body text', $article->content);
    }

    public function testBuildArticleAppendsMultipleMetaValuesInOrder(): void
    {
        $post = $this->makePost();
        $post->post_content = 'Main content';
        $this->settings = \Mockery::mock(Settings::class);
        $this->settings->allows('getNoticeEmails')->andReturn([]);
        $this->settings->allows('getPostTypes')->andReturn(['post']);
        $this->settings->allows('getContentMetaFieldKeys')->andReturn(['field_a', 'field_b']);
        $this->settings->allows('getTestMode')->andReturn(false);
        $this->publisher = new Publisher($this->factory, $this->postMeta, $this->settings);

        Functions\expect('get_post_meta')->andReturnUsing(function ($id, $key) {
            return ['field_a' => 'Alpha', 'field_b' => 'Beta'][$key] ?? '';
        });

        $article = $this->captureArticleFromSubmit($post);
        $this->assertSame("Main content\n\nAlpha\n\nBeta", $article->content);
    }

    public function testBuildArticleSkipsMissingMetaKey(): void
    {
        $post = $this->makePost();
        $post->post_content = 'Only main';
        $this->settings = \Mockery::mock(Settings::class);
        $this->settings->allows('getNoticeEmails')->andReturn([]);
        $this->settings->allows('getPostTypes')->andReturn(['post']);
        $this->settings->allows('getContentMetaFieldKeys')->andReturn(['nope']);
        $this->settings->allows('getTestMode')->andReturn(false);
        $this->publisher = new Publisher($this->factory, $this->postMeta, $this->settings);

        Functions\expect('get_post_meta')->with(1, 'nope', true)->andReturn('');

        $article = $this->captureArticleFromSubmit($post);
        $this->assertSame('Only main', $article->content);
    }

    public function testBuildArticleSkipsArrayMetaValue(): void
    {
        $post = $this->makePost();
        $post->post_content = 'Only main';
        $this->settings = \Mockery::mock(Settings::class);
        $this->settings->allows('getNoticeEmails')->andReturn([]);
        $this->settings->allows('getPostTypes')->andReturn(['post']);
        $this->settings->allows('getContentMetaFieldKeys')->andReturn(['serialized']);
        $this->settings->allows('getTestMode')->andReturn(false);
        $this->publisher = new Publisher($this->factory, $this->postMeta, $this->settings);

        Functions\expect('get_post_meta')->with(1, 'serialized', true)->andReturn(['a', 'b']);

        $article = $this->captureArticleFromSubmit($post);
        $this->assertSame('Only main', $article->content);
    }

    public function testBuildArticleSetsTestModeFromSettings(): void
    {
        $post = $this->makePost();
        $settings = \Mockery::mock(Settings::class);
        $settings->allows('getNoticeEmails')->andReturn([]);
        $settings->allows('getPostTypes')->andReturn(['post']);
        $settings->allows('getContentMetaFieldKeys')->andReturn([]);
        $settings->allows('getTestMode')->andReturn(true);
        $publisher = new Publisher($this->factory, $this->postMeta, $settings);

        $this->stubBuildArticle();

        $result = new IndexResult();
        $result->job_id = 'j';
        $result->workflow_id = 'w';

        $this->client->expects('index')
            ->once()
            ->with(\Mockery::on(function ($article) {
                return $article->test_mode === true;
            }))
            ->andReturn($result);

        $this->postMeta->allows('setJobId');
        $this->postMeta->allows('setSubmitTime');
        $this->postMeta->allows('clearDocId');
        $this->postMeta->allows('clearError');

        $publisher->submit(1, $post);
    }

    public function testBuildArticleSkipsWhitespaceOnlyMeta(): void
    {
        $post = $this->makePost();
        $post->post_content = 'Only main';
        $this->settings = \Mockery::mock(Settings::class);
        $this->settings->allows('getNoticeEmails')->andReturn([]);
        $this->settings->allows('getPostTypes')->andReturn(['post']);
        $this->settings->allows('getContentMetaFieldKeys')->andReturn(['blank']);
        $this->settings->allows('getTestMode')->andReturn(false);
        $this->publisher = new Publisher($this->factory, $this->postMeta, $this->settings);

        Functions\expect('get_post_meta')->with(1, 'blank', true)->andReturn("   \n\t  ");

        $article = $this->captureArticleFromSubmit($post);
        $this->assertSame('Only main', $article->content);
    }

    public function testDeleteUsesStoredDocIdWhenAvailable(): void
    {
        $post = $this->makePost();
        $docId = str_repeat('a', 40);

        $this->postMeta->allows('getDocId')->with(1)->andReturn($docId);

        $this->client->expects('delete')
            ->once()
            ->with('https://example.com/post', $docId);

        Functions\expect('get_permalink')->with(1)->andReturn('https://example.com/post');

        $this->postMeta->expects('clearDocId')->once()->with(1);
        $this->postMeta->expects('clearJobId')->once()->with(1);
        $this->postMeta->expects('clearError')->once()->with(1);

        $this->publisher->delete(1, $post);
    }

    public function testDeleteFallsBackToURLOnlyWhenStoredDocIdIsInvalid(): void
    {
        $post = $this->makePost();

        $this->postMeta->allows('getDocId')->with(1)->andReturn('legacy-not-hex');

        $this->client->expects('delete')
            ->once()
            ->with('https://example.com/post', null);

        Functions\expect('get_permalink')->with(1)->andReturn('https://example.com/post');

        $this->postMeta->expects('clearDocId')->once()->with(1);
        $this->postMeta->expects('clearJobId')->once()->with(1);
        $this->postMeta->expects('clearError')->once()->with(1);

        $this->publisher->delete(1, $post);
    }

    public function testDeleteFallsBackToURLOnly(): void
    {
        $post = $this->makePost();

        $this->postMeta->allows('getDocId')->with(1)->andReturn(null);

        $this->client->expects('delete')
            ->once()
            ->with('https://example.com/post', null);

        Functions\expect('get_permalink')->with(1)->andReturn('https://example.com/post');

        $this->postMeta->expects('clearDocId')->once()->with(1);
        $this->postMeta->expects('clearJobId')->once()->with(1);
        $this->postMeta->expects('clearError')->once()->with(1);

        $this->publisher->delete(1, $post);
    }

    public function testOnTrashOrDeleteSkipsWhenCapabilityDenied(): void
    {
        Functions\expect('current_user_can')->with('delete_post', 1)->andReturn(false);

        $this->client->expects('delete')->never();

        $this->publisher->onTrashOrDelete(1);
    }

    public function testOnTrashOrDeleteSkipsWhenPostNotFound(): void
    {
        Functions\expect('current_user_can')->with('delete_post', 1)->andReturn(true);
        Functions\expect('get_post')->with(1)->andReturn(null);

        $this->client->expects('delete')->never();

        $this->publisher->onTrashOrDelete(1);
    }

    public function testOnTrashOrDeleteSkipsWrongPostType(): void
    {
        $post = $this->makePost(1, 'publish', 'page');

        Functions\expect('current_user_can')->with('delete_post', 1)->andReturn(true);
        Functions\expect('get_post')->with(1)->andReturn($post);

        $this->client->expects('delete')->never();

        $this->publisher->onTrashOrDelete(1);
    }

    public function testOnTrashOrDeleteInvokesDelete(): void
    {
        $post = $this->makePost(1, 'publish', 'post');

        Functions\expect('current_user_can')->with('delete_post', 1)->andReturn(true);
        Functions\expect('get_post')->with(1)->andReturn($post);
        Functions\expect('get_permalink')->with(1)->andReturn('https://example.com/post');

        $this->postMeta->allows('getDocId')->with(1)->andReturn(null);

        $this->client->expects('delete')
            ->once()
            ->with('https://example.com/post', null);

        $this->postMeta->expects('clearDocId')->once()->with(1);
        $this->postMeta->expects('clearJobId')->once()->with(1);
        $this->postMeta->expects('clearError')->once()->with(1);

        $this->publisher->onTrashOrDelete(1);
    }
}
