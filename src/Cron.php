<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

class Cron
{
    private const HOOK = 'freespoke_publisher_cron';
    private const INTERVAL = 'freespoke_every_five_minutes';
    private \Freespoke\Wordpress\Publisher $publisher;
    private \Freespoke\Wordpress\PostMeta $postMeta;
    private \Freespoke\Wordpress\ClientFactory $factory;
    private \Freespoke\Wordpress\Settings $settings;
    public function __construct(\Freespoke\Wordpress\Publisher $publisher, \Freespoke\Wordpress\PostMeta $postMeta, \Freespoke\Wordpress\ClientFactory $factory, \Freespoke\Wordpress\Settings $settings)
    {
        $this->publisher = $publisher;
        $this->postMeta = $postMeta;
        $this->factory = $factory;
        $this->settings = $settings;
    }
    public function registerSchedules(array $schedules): array
    {
        $schedules[self::INTERVAL] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => __('Every Five Minutes', 'freespoke-widget')];
        return $schedules;
    }
    public function schedule(): void
    {
        if (!$this->settings->hasCredentials()) {
            return;
        }
        $next = wp_next_scheduled(self::HOOK);
        if ($next) {
            // Reschedule if still on the old hourly interval.
            $event = wp_get_scheduled_event(self::HOOK);
            if ($event && $event->schedule !== self::INTERVAL) {
                wp_clear_scheduled_hook(self::HOOK);
                $next = \false;
            }
        }
        if (!$next) {
            wp_schedule_event(time(), self::INTERVAL, self::HOOK);
        }
    }
    public function onActivate(): void
    {
        $this->schedule();
        flush_rewrite_rules();
    }
    public function onDeactivate(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        flush_rewrite_rules();
    }
    public function run(): void
    {
        if (!$this->settings->hasCredentials()) {
            return;
        }
        $this->reindexPosts();
        $this->pollPendingJobs();
    }
    private function reindexPosts(): void
    {
        $epoch = $this->publisher->getEpoch();
        if (is_wp_error($epoch)) {
            return;
        }
        $postIds = $this->postMeta->getPostsNeedingIndex($epoch, 50, $this->settings->getPostTypes());
        if (empty($postIds)) {
            return;
        }
        foreach ($postIds as $postId) {
            $post = get_post($postId);
            if (!$post) {
                continue;
            }
            $this->publisher->submit($postId, $post);
        }
    }
    private function pollPendingJobs(): void
    {
        $postIds = $this->postMeta->getPostsWithPendingJobs(50, $this->settings->getPostTypes());
        foreach ($postIds as $postId) {
            $jobId = $this->postMeta->getJobId($postId);
            if (!$jobId) {
                continue;
            }
            try {
                $status = $this->factory->getClient()->getIndexStatus($jobId);
            } catch (\Throwable $e) {
                continue;
                // retry next tick
            }
            if ($status === null) {
                continue;
            }
            if ($status->status === 'JOB_STATUS_COMPLETE') {
                $this->postMeta->clearJobId($postId);
                $this->postMeta->clearError($postId);
            } elseif ($status->status === 'JOB_STATUS_FAILED') {
                $errorMsg = $this->extractErrorMessage($status->error);
                $this->postMeta->setError($postId, 'job_failed', $errorMsg);
                $this->postMeta->clearJobId($postId);
                $this->publisher->notifyFailure($postId, 'job_failed', $errorMsg);
            }
            // JOB_STATUS_PENDING — leave job_id in place, poll again next tick
        }
    }
    /**
     * Extract a human-readable message from a job error object.
     *
     * The error field is a google.rpc.Status-shaped array:
     *   {"@type": "...", "code": 2, "message": "failed language; was not in allowed languages [en]"}
     */
    private function extractErrorMessage(array $error): string
    {
        if (!empty($error['message']) && is_string($error['message'])) {
            return $error['message'];
        }
        if (!empty($error)) {
            return (string) json_encode($error);
        }
        return 'Job failed';
    }
}
