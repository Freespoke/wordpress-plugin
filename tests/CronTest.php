<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Brain\Monkey\Functions;
use Freespoke\Partner\Client;
use Freespoke\Partner\IndexStatusResult;
use Freespoke\Wordpress\ClientFactory;
use Freespoke\Wordpress\Cron;
use Freespoke\Wordpress\PostMeta;
use Freespoke\Wordpress\Publisher;
use Freespoke\Wordpress\Settings;

class CronTest extends TestCase
{
    private Cron $cron;
    private Publisher|\Mockery\MockInterface $publisher;
    private PostMeta|\Mockery\MockInterface $postMeta;
    private ClientFactory|\Mockery\MockInterface $factory;
    private Client|\Mockery\MockInterface $client;
    private Settings|\Mockery\MockInterface $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = \Mockery::mock(Publisher::class);
        $this->postMeta = \Mockery::mock(PostMeta::class);
        $this->client = \Mockery::mock(Client::class);
        $this->factory = \Mockery::mock(ClientFactory::class);
        $this->factory->allows('getClient')->andReturn($this->client);
        $this->settings = \Mockery::mock(Settings::class);
        $this->settings->allows('hasCredentials')->andReturn(true);
        $this->settings->allows('getPostTypes')->andReturn(['post']);

        $this->cron = new Cron($this->publisher, $this->postMeta, $this->factory, $this->settings);
    }

    private function makePost(int $id): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_type = 'post';
        $post->post_status = 'publish';
        return $post;
    }

    public function testScheduleCreatesEventWhenNotScheduled(): void
    {
        Functions\expect('wp_next_scheduled')
            ->once()
            ->with('freespoke_publisher_cron')
            ->andReturn(false);
        Functions\expect('wp_schedule_event')
            ->once()
            ->with(\Mockery::type('int'), 'freespoke_every_five_minutes', 'freespoke_publisher_cron');

        $this->cron->schedule();
    }

    public function testScheduleSkipsWhenAlreadyScheduled(): void
    {
        Functions\expect('wp_next_scheduled')
            ->once()
            ->andReturn(1710600000);

        $event = (object) ['schedule' => 'freespoke_every_five_minutes'];
        Functions\expect('wp_get_scheduled_event')
            ->once()
            ->with('freespoke_publisher_cron')
            ->andReturn($event);
        Functions\expect('wp_schedule_event')->never();

        $this->cron->schedule();
    }

    public function testScheduleReschedulesFromHourly(): void
    {
        Functions\expect('wp_next_scheduled')
            ->once()
            ->andReturn(1710600000);

        $event = (object) ['schedule' => 'hourly'];
        Functions\expect('wp_get_scheduled_event')
            ->once()
            ->with('freespoke_publisher_cron')
            ->andReturn($event);
        Functions\expect('wp_clear_scheduled_hook')
            ->once()
            ->with('freespoke_publisher_cron');
        Functions\expect('wp_schedule_event')
            ->once()
            ->with(\Mockery::type('int'), 'freespoke_every_five_minutes', 'freespoke_publisher_cron');

        $this->cron->schedule();
    }

    public function testScheduleSkipsWhenNoCredentials(): void
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->allows('hasCredentials')->andReturn(false);
        $cron = new Cron($this->publisher, $this->postMeta, $this->factory, $settings);

        Functions\expect('wp_next_scheduled')->never();
        Functions\expect('wp_schedule_event')->never();

        $cron->schedule();
    }

    public function testOnActivateSchedulesAndFlushes(): void
    {
        Functions\expect('wp_next_scheduled')->andReturn(false);
        Functions\expect('wp_schedule_event')
            ->once()
            ->with(\Mockery::type('int'), 'freespoke_every_five_minutes', 'freespoke_publisher_cron');
        Functions\expect('flush_rewrite_rules')->once();

        $this->cron->onActivate();
    }

    public function testOnDeactivateClearsAndFlushes(): void
    {
        Functions\expect('wp_clear_scheduled_hook')
            ->once()
            ->with('freespoke_publisher_cron');
        Functions\expect('flush_rewrite_rules')->once();

        $this->cron->onDeactivate();
    }

    public function testRunCallsReindexAndPoll(): void
    {
        $this->publisher->expects('getEpoch')->once()->andReturn(new \WP_Error('fail', 'fail'));
        $this->postMeta->expects('getPostsWithPendingJobs')->once()->andReturn([]);

        $this->cron->run();
    }

    public function testRunSkipsWhenNoCredentials(): void
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->allows('hasCredentials')->andReturn(false);
        $cron = new Cron($this->publisher, $this->postMeta, $this->factory, $settings);

        $this->publisher->expects('getEpoch')->never();
        $this->postMeta->expects('getPostsWithPendingJobs')->never();

        $cron->run();
    }

    public function testReindexSubmitsPostsNeedingIndex(): void
    {
        $this->publisher->expects('getEpoch')->once()->andReturn(100);

        $this->postMeta->expects('getPostsNeedingIndex')
            ->once()
            ->with(100, 50, ['post'])
            ->andReturn([1, 2]);

        Functions\expect('get_post')
            ->twice()
            ->andReturnUsing(fn($id) => $this->makePost($id));

        $this->publisher->expects('submit')->twice();
        $this->postMeta->expects('getPostsWithPendingJobs')->andReturn([]);

        $this->cron->run();
    }

    public function testReindexSkipsNullPost(): void
    {
        $this->publisher->expects('getEpoch')->once()->andReturn(100);
        $this->postMeta->expects('getPostsNeedingIndex')->andReturn([99]);
        Functions\expect('get_post')->once()->andReturn(null);

        $this->publisher->expects('submit')->never();
        $this->postMeta->expects('getPostsWithPendingJobs')->andReturn([]);

        $this->cron->run();
    }

    public function testPollCompletedJobClearsJobAndError(): void
    {
        $this->publisher->expects('getEpoch')->andReturn(new \WP_Error('fail', 'fail'));

        $this->postMeta->expects('getPostsWithPendingJobs')->andReturn([42]);
        $this->postMeta->expects('getJobId')->with(42)->andReturn('job-done');

        $status = new IndexStatusResult();
        $status->status = 'JOB_STATUS_COMPLETE';
        $this->client->expects('getIndexStatus')->with('job-done')->andReturn($status);

        $this->postMeta->expects('clearJobId')->once()->with(42);
        $this->postMeta->expects('clearError')->once()->with(42);

        $this->cron->run();
    }

    public function testPollFailedJobSetsErrorAndNotifies(): void
    {
        $this->publisher->expects('getEpoch')->andReturn(new \WP_Error('fail', 'fail'));

        $this->postMeta->expects('getPostsWithPendingJobs')->andReturn([42]);
        $this->postMeta->expects('getJobId')->with(42)->andReturn('job-fail');

        $status = new IndexStatusResult();
        $status->status = 'JOB_STATUS_FAILED';
        $status->error = ['message' => 'bad content'];
        $this->client->expects('getIndexStatus')->with('job-fail')->andReturn($status);

        $this->postMeta->expects('setError')
            ->once()
            ->with(42, 'job_failed', \Mockery::type('string'));
        $this->postMeta->expects('clearJobId')->once()->with(42);
        $this->publisher->expects('notifyFailure')
            ->once()
            ->with(42, 'job_failed', \Mockery::type('string'));

        $this->cron->run();
    }

    public function testPollPendingJobLeavesJobId(): void
    {
        $this->publisher->expects('getEpoch')->andReturn(new \WP_Error('fail', 'fail'));

        $this->postMeta->expects('getPostsWithPendingJobs')->andReturn([42]);
        $this->postMeta->expects('getJobId')->with(42)->andReturn('job-pending');

        $status = new IndexStatusResult();
        $status->status = 'JOB_STATUS_PENDING';
        $this->client->expects('getIndexStatus')->andReturn($status);

        $this->postMeta->expects('clearJobId')->never();
        $this->postMeta->expects('clearError')->never();
        $this->postMeta->expects('setError')->never();

        $this->cron->run();
    }

    public function testPollSkipsOnApiException(): void
    {
        $this->publisher->expects('getEpoch')->andReturn(new \WP_Error('fail', 'fail'));

        $this->postMeta->expects('getPostsWithPendingJobs')->andReturn([42]);
        $this->postMeta->expects('getJobId')->with(42)->andReturn('job-x');

        $this->client->expects('getIndexStatus')->andThrow(new \RuntimeException('timeout'));

        $this->postMeta->expects('clearJobId')->never();

        $this->cron->run();
    }

    public function testPollSkipsNullJobId(): void
    {
        $this->publisher->expects('getEpoch')->andReturn(new \WP_Error('fail', 'fail'));

        $this->postMeta->expects('getPostsWithPendingJobs')->andReturn([42]);
        $this->postMeta->expects('getJobId')->with(42)->andReturn(null);

        $this->client->expects('getIndexStatus')->never();

        $this->cron->run();
    }

    public function testPollSkipsNullStatus(): void
    {
        $this->publisher->expects('getEpoch')->andReturn(new \WP_Error('fail', 'fail'));

        $this->postMeta->expects('getPostsWithPendingJobs')->andReturn([42]);
        $this->postMeta->expects('getJobId')->with(42)->andReturn('job-x');

        $this->client->expects('getIndexStatus')->andReturn(null);

        $this->postMeta->expects('clearJobId')->never();

        $this->cron->run();
    }
}
