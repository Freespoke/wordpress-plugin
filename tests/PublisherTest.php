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

        $this->publisher = new Publisher($this->factory, $this->postMeta, $this->settings);
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

    public function testOnStatusTransitionOnlyFiresForFutureToPublish(): void
    {
        $post = $this->makePost();

        $this->client->expects('index')->never();

        // draft -> publish should not trigger
        $this->publisher->onStatusTransition('publish', 'draft', $post);
    }

    public function testOnStatusTransitionFiresForFutureToPublish(): void
    {
        $post = $this->makePost();
        $result = new IndexResult();
        $result->job_id = 'job-x';
        $result->workflow_id = 'wf-x';

        Functions\expect('wp_is_post_revision')->andReturn(false);
        $this->stubBuildArticle();

        $this->client->expects('index')->once()->andReturn($result);
        $this->postMeta->expects('setJobId')->once();
        $this->postMeta->expects('setSubmitTime')->once();
        $this->postMeta->expects('clearError')->once();

        $this->publisher->onStatusTransition('publish', 'future', $post);
    }

    public function testNotifyFailureSendsEmail(): void
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->allows('getNoticeEmails')->andReturn(['ops@example.com']);
        $settings->allows('getPostTypes')->andReturn(['post']);
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
        $this->postMeta->allows('clearError');

        $this->publisher->submit(1, $post);
    }
}
