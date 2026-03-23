<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Brain\Monkey\Functions;
use Freespoke\Wordpress\PostMeta;

class PostMetaTest extends TestCase
{
    private PostMeta $postMeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postMeta = new PostMeta();
    }

    public function testGetSubmitTimeReturnsInt(): void
    {
        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_freespoke_submit_time', true)
            ->andReturn('1710600000');

        $this->assertSame(1710600000, $this->postMeta->getSubmitTime(42));
    }

    public function testGetSubmitTimeReturnsNullForNonNumeric(): void
    {
        Functions\expect('get_post_meta')
            ->once()
            ->andReturn('');

        $this->assertNull($this->postMeta->getSubmitTime(42));
    }

    public function testSetSubmitTime(): void
    {
        Functions\expect('update_post_meta')
            ->once()
            ->with(42, '_freespoke_submit_time', 1710600000);

        $this->postMeta->setSubmitTime(42, 1710600000);
    }

    public function testGetJobIdReturnsString(): void
    {
        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_freespoke_job_id', true)
            ->andReturn('job-123');

        $this->assertSame('job-123', $this->postMeta->getJobId(42));
    }

    public function testGetJobIdReturnsNullForEmpty(): void
    {
        Functions\expect('get_post_meta')
            ->once()
            ->andReturn('');

        $this->assertNull($this->postMeta->getJobId(42));
    }

    public function testSetJobId(): void
    {
        Functions\expect('update_post_meta')
            ->once()
            ->with(42, '_freespoke_job_id', 'job-123');

        $this->postMeta->setJobId(42, 'job-123');
    }

    public function testClearJobId(): void
    {
        Functions\expect('delete_post_meta')
            ->once()
            ->with(42, '_freespoke_job_id');

        $this->postMeta->clearJobId(42);
    }

    public function testGetErrorReturnsDecodedArray(): void
    {
        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_freespoke_error', true)
            ->andReturn(json_encode([
                'code' => 'api_error',
                'message' => 'Something failed',
                'timestamp' => '2026-03-16 12:00:00',
            ]));

        $error = $this->postMeta->getError(42);
        $this->assertSame('api_error', $error['code']);
        $this->assertSame('Something failed', $error['message']);
        $this->assertSame('2026-03-16 12:00:00', $error['timestamp']);
    }

    public function testGetErrorReturnsNullForEmpty(): void
    {
        Functions\expect('get_post_meta')
            ->once()
            ->andReturn('');

        $this->assertNull($this->postMeta->getError(42));
    }

    public function testGetErrorReturnsNullForInvalidJson(): void
    {
        Functions\expect('get_post_meta')
            ->once()
            ->andReturn('not-json');

        $this->assertNull($this->postMeta->getError(42));
    }

    public function testGetErrorReturnsNullForMissingMessage(): void
    {
        Functions\expect('get_post_meta')
            ->once()
            ->andReturn(json_encode(['code' => 'err']));

        $this->assertNull($this->postMeta->getError(42));
    }

    public function testSetError(): void
    {
        Functions\expect('sanitize_text_field')->andReturnFirstArg();
        Functions\expect('wp_strip_all_tags')->andReturnFirstArg();
        Functions\expect('current_time')
            ->once()
            ->with('mysql')
            ->andReturn('2026-03-16 12:00:00');
        Functions\expect('wp_json_encode')->andReturnUsing(function ($v) {
            return json_encode($v);
        });
        Functions\expect('update_post_meta')
            ->once()
            ->with(42, '_freespoke_error', \Mockery::type('string'));

        $this->postMeta->setError(42, 'api_error', 'Something failed');
    }

    public function testSetErrorDefaultsCodeToError(): void
    {
        Functions\expect('sanitize_text_field')->andReturnFirstArg();
        Functions\expect('wp_strip_all_tags')->andReturnFirstArg();
        Functions\expect('current_time')->andReturn('2026-03-16 12:00:00');
        Functions\expect('wp_json_encode')->andReturnUsing(function ($v) {
            return json_encode($v);
        });
        Functions\expect('update_post_meta')
            ->once()
            ->with(42, '_freespoke_error', \Mockery::on(function (string $json) {
                $data = json_decode($json, true);
                return $data['code'] === 'error';
            }));

        $this->postMeta->setError(42, '', 'msg');
    }

    public function testClearError(): void
    {
        Functions\expect('delete_post_meta')
            ->once()
            ->with(42, '_freespoke_error');

        $this->postMeta->clearError(42);
    }

    public function testGetPostsWithPendingJobs(): void
    {
        \WP_Query::$stubPosts = [1, 2, 3];

        $result = $this->postMeta->getPostsWithPendingJobs();
        $this->assertSame([1, 2, 3], $result);

        \WP_Query::$stubPosts = [];
    }

    public function testGetPostsNeedingIndex(): void
    {
        \WP_Query::$stubPosts = [10, 20];

        $result = $this->postMeta->getPostsNeedingIndex(1000);
        $this->assertSame([10, 20], $result);

        \WP_Query::$stubPosts = [];
    }
}
