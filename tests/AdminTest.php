<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Brain\Monkey\Functions;
use Freespoke\Partner\IndexResult;
use Freespoke\Wordpress\Admin;
use Freespoke\Wordpress\ClientFactory;
use Freespoke\Wordpress\PostMeta;
use Freespoke\Wordpress\Publisher;
use Freespoke\Wordpress\Settings;

/**
 * Simulates the die() that wp_send_json_* would normally trigger.
 */
class JsonSentException extends \RuntimeException
{
}

class AdminTest extends TestCase
{
    private Admin $admin;
    private Publisher|\Mockery\MockInterface $publisher;
    private PostMeta|\Mockery\MockInterface $postMeta;
    private Settings|\Mockery\MockInterface $settings;
    private ClientFactory|\Mockery\MockInterface $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = \Mockery::mock(Settings::class);
        $this->postMeta = \Mockery::mock(PostMeta::class);
        $this->publisher = \Mockery::mock(Publisher::class);
        $this->factory = \Mockery::mock(ClientFactory::class);

        $this->admin = new Admin($this->settings, $this->postMeta, $this->publisher, $this->factory);
    }

    protected function tearDown(): void
    {
        unset($_POST['post_id']);
        parent::tearDown();
    }

    private function makePost(int $id = 1): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_type = 'post';
        $post->post_status = 'publish';
        $post->post_password = '';
        $post->post_title = 'Test Post';
        $post->post_content = '<p>Content</p>';
        $post->post_author = 1;

        return $post;
    }

    public function testHandleResubmitSuccess(): void
    {
        Functions\expect('check_ajax_referer')->once()->with('freespoke_resubmit');
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);

        $_POST['post_id'] = '42';

        $post = $this->makePost(42);
        Functions\expect('get_post')->once()->with(42)->andReturn($post);

        $this->publisher->expects('shouldIndex')->once()->with($post)->andReturn(true);

        $result = new IndexResult();
        $result->job_id = 'job-resubmit';
        $result->workflow_id = 'wf-resubmit';
        $this->publisher->expects('submit')->once()->with(42, $post)->andReturn($result);

        Functions\expect('wp_send_json_success')->once()->andThrow(new JsonSentException());

        $this->expectException(JsonSentException::class);
        $this->admin->handleResubmit();
    }

    public function testHandleResubmitDeniesWithoutPermission(): void
    {
        Functions\expect('check_ajax_referer')->once();
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);
        Functions\expect('__')->andReturnFirstArg();
        Functions\expect('wp_send_json_error')->once()->with('Permission denied.')->andThrow(new JsonSentException());

        $this->expectException(JsonSentException::class);
        $this->admin->handleResubmit();
    }

    public function testHandleResubmitRejectsInvalidPostId(): void
    {
        Functions\expect('check_ajax_referer')->once();
        Functions\expect('current_user_can')->once()->andReturn(true);
        Functions\expect('__')->andReturnFirstArg();
        Functions\expect('wp_send_json_error')->once()->with('Invalid post ID.')->andThrow(new JsonSentException());

        $_POST['post_id'] = '0';

        $this->expectException(JsonSentException::class);
        $this->admin->handleResubmit();
    }

    public function testHandleResubmitRejectsMissingPostId(): void
    {
        Functions\expect('check_ajax_referer')->once();
        Functions\expect('current_user_can')->once()->andReturn(true);
        Functions\expect('__')->andReturnFirstArg();
        Functions\expect('wp_send_json_error')->once()->with('Invalid post ID.')->andThrow(new JsonSentException());

        $this->expectException(JsonSentException::class);
        $this->admin->handleResubmit();
    }

    public function testHandleResubmitRejectsNonexistentPost(): void
    {
        Functions\expect('check_ajax_referer')->once();
        Functions\expect('current_user_can')->once()->andReturn(true);
        Functions\expect('__')->andReturnFirstArg();

        $_POST['post_id'] = '999';
        Functions\expect('get_post')->once()->with(999)->andReturn(null);
        Functions\expect('wp_send_json_error')->once()->with('Post not found.')->andThrow(new JsonSentException());

        $this->expectException(JsonSentException::class);
        $this->admin->handleResubmit();
    }

    public function testHandleResubmitRejectsIneligiblePost(): void
    {
        Functions\expect('check_ajax_referer')->once();
        Functions\expect('current_user_can')->once()->andReturn(true);
        Functions\expect('__')->andReturnFirstArg();

        $_POST['post_id'] = '42';

        $post = $this->makePost(42);
        $post->post_status = 'draft';
        Functions\expect('get_post')->once()->with(42)->andReturn($post);

        $this->publisher->expects('shouldIndex')->once()->with($post)->andReturn(false);
        $this->publisher->expects('submit')->never();
        Functions\expect('wp_send_json_error')
            ->once()
            ->with('This post is not eligible for indexing. It must be published, not password-protected, and an enabled content type.')
            ->andThrow(new JsonSentException());

        $this->expectException(JsonSentException::class);
        $this->admin->handleResubmit();
    }

    public function testHandleResubmitReturnsErrorOnSubmitFailure(): void
    {
        Functions\expect('check_ajax_referer')->once();
        Functions\expect('current_user_can')->once()->andReturn(true);

        $_POST['post_id'] = '42';

        $post = $this->makePost(42);
        Functions\expect('get_post')->once()->with(42)->andReturn($post);

        $this->publisher->expects('shouldIndex')->once()->with($post)->andReturn(true);

        $error = new \WP_Error('freespoke_api_error', 'Something broke');
        $this->publisher->expects('submit')->once()->with(42, $post)->andReturn($error);
        Functions\expect('wp_send_json_error')->once()->with('Something broke')->andThrow(new JsonSentException());

        $this->expectException(JsonSentException::class);
        $this->admin->handleResubmit();
    }
}
