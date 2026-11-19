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
        Functions\expect('delete_post_meta')
            ->once()
            ->with(42, '_freespoke_no_retry');

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
        Functions\expect('delete_post_meta')
            ->once()
            ->with(42, '_freespoke_no_retry');

        $this->postMeta->setError(42, '', 'msg');
    }

    public function testSetErrorNonRetryableWritesNoRetryFlag(): void
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
                return $data['retryable'] === false;
            }));
        Functions\expect('update_post_meta')
            ->once()
            ->with(42, '_freespoke_no_retry', \Mockery::on(function (string $v) {
                // The flag stores the failure time so an epoch advance can
                // un-park the post.
                return ctype_digit($v) && abs((int) $v - time()) < 60;
            }));

        $this->postMeta->setError(42, 'api_error', 'bad request', false);
    }

    public function testGetErrorIncludesRetryableFlag(): void
    {
        Functions\expect('get_post_meta')
            ->once()
            ->andReturn(json_encode([
                'code' => 'api_error',
                'message' => 'permanent',
                'timestamp' => '2026-03-16 12:00:00',
                'retryable' => false,
            ]));

        $error = $this->postMeta->getError(42);
        $this->assertFalse($error['retryable']);
    }

    public function testGetErrorDefaultsRetryableTrueForLegacyPayload(): void
    {
        Functions\expect('get_post_meta')
            ->once()
            ->andReturn(json_encode([
                'code' => 'api_error',
                'message' => 'legacy error',
                'timestamp' => '2026-03-16 12:00:00',
            ]));

        $error = $this->postMeta->getError(42);
        $this->assertTrue($error['retryable']);
    }

    public function testClearError(): void
    {
        Functions\expect('delete_post_meta')
            ->once()
            ->with(42, '_freespoke_error');
        Functions\expect('delete_post_meta')
            ->once()
            ->with(42, '_freespoke_no_retry');

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

    public function testGetPostsNeedingIndexRetriesParkedPostsAfterEpoch(): void
    {
        \WP_Query::$stubPosts = [10];
        \WP_Query::$lastArgs = null;

        $this->postMeta->getPostsNeedingIndex(1000);

        $metaQuery = \WP_Query::$lastArgs['meta_query'];
        $noRetryGroup = $metaQuery[0];

        // The no-retry gate must be an OR of "never failed permanently" and
        // "failed before the current epoch" — a permanent failure parks the
        // post only until the partner epoch advances past it. Legacy '1'
        // values compare below any real epoch, so failures recorded by
        // older plugin versions are retried too.
        $this->assertSame('OR', $noRetryGroup['relation']);
        $this->assertSame('_freespoke_no_retry', $noRetryGroup[0]['key']);
        $this->assertSame('NOT EXISTS', $noRetryGroup[0]['compare']);
        $this->assertSame('_freespoke_no_retry', $noRetryGroup[1]['key']);
        $this->assertSame('<', $noRetryGroup[1]['compare']);
        $this->assertSame(1000, $noRetryGroup[1]['value']);
        $this->assertSame('NUMERIC', $noRetryGroup[1]['type']);

        \WP_Query::$stubPosts = [];
        \WP_Query::$lastArgs = null;
    }

    public function testSetGetClearDocId(): void
    {
        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_freespoke_doc_id', true)
            ->andReturn('');

        $this->assertNull($this->postMeta->getDocId(42));

        Functions\expect('update_post_meta')
            ->once()
            ->with(42, '_freespoke_doc_id', 'abc123');

        $this->postMeta->setDocId(42, 'abc123');

        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_freespoke_doc_id', true)
            ->andReturn('abc123');

        $this->assertSame('abc123', $this->postMeta->getDocId(42));

        Functions\expect('delete_post_meta')
            ->once()
            ->with(42, '_freespoke_doc_id');

        $this->postMeta->clearDocId(42);

        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_freespoke_doc_id', true)
            ->andReturn('');

        $this->assertNull($this->postMeta->getDocId(42));
    }
}
