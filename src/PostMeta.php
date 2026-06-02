<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

class PostMeta
{
    private const KEY_SUBMIT_TIME = '_freespoke_submit_time';
    private const KEY_JOB_ID = '_freespoke_job_id';
    private const KEY_DOC_ID = '_freespoke_doc_id';
    private const KEY_ERROR = '_freespoke_error';
    private const KEY_NO_RETRY = '_freespoke_no_retry';
    public function getSubmitTime(int $postId): ?int
    {
        $value = get_post_meta($postId, self::KEY_SUBMIT_TIME, \true);
        return is_numeric($value) ? (int) $value : null;
    }
    public function setSubmitTime(int $postId, int $time): void
    {
        update_post_meta($postId, self::KEY_SUBMIT_TIME, $time);
    }
    public function getJobId(int $postId): ?string
    {
        $value = get_post_meta($postId, self::KEY_JOB_ID, \true);
        return is_string($value) && $value !== '' ? $value : null;
    }
    public function setJobId(int $postId, string $jobId): void
    {
        update_post_meta($postId, self::KEY_JOB_ID, $jobId);
    }
    public function clearJobId(int $postId): void
    {
        delete_post_meta($postId, self::KEY_JOB_ID);
    }
    public function getDocId(int $postId): ?string
    {
        $value = get_post_meta($postId, self::KEY_DOC_ID, \true);
        return is_string($value) && $value !== '' ? $value : null;
    }
    public function setDocId(int $postId, string $docId): void
    {
        update_post_meta($postId, self::KEY_DOC_ID, $docId);
    }
    public function clearDocId(int $postId): void
    {
        delete_post_meta($postId, self::KEY_DOC_ID);
    }
    /**
     * @param string|string[] $postType
     */
    public function getPostsWithPendingJobs(int $limit = 50, string|array $postType = 'post'): array
    {
        $query = new \WP_Query(['post_type' => $postType, 'post_status' => 'publish', 'posts_per_page' => $limit, 'fields' => 'ids', 'meta_query' => [['key' => self::KEY_JOB_ID, 'compare' => 'EXISTS']]]);
        return $query->posts;
    }
    public function getError(int $postId): ?array
    {
        $raw = get_post_meta($postId, self::KEY_ERROR, \true);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, \true);
        if (!is_array($decoded) || empty($decoded['message'])) {
            return null;
        }
        // Default missing 'retryable' to true so pre-existing stored errors
        // behave the same as before this field was introduced.
        $retryable = array_key_exists('retryable', $decoded) ? (bool) $decoded['retryable'] : \true;
        return ['code' => (string) ($decoded['code'] ?? 'error'), 'message' => (string) $decoded['message'], 'timestamp' => (string) ($decoded['timestamp'] ?? ''), 'retryable' => $retryable];
    }
    public function setError(int $postId, string $code, string $message, bool $retryable = \true): void
    {
        $payload = ['code' => sanitize_text_field($code ?: 'error'), 'message' => sanitize_text_field(wp_strip_all_tags($message)), 'timestamp' => current_time('mysql'), 'retryable' => $retryable];
        update_post_meta($postId, self::KEY_ERROR, wp_json_encode($payload));
        if ($retryable) {
            delete_post_meta($postId, self::KEY_NO_RETRY);
        } else {
            update_post_meta($postId, self::KEY_NO_RETRY, '1');
        }
    }
    public function clearError(int $postId): void
    {
        delete_post_meta($postId, self::KEY_ERROR);
        delete_post_meta($postId, self::KEY_NO_RETRY);
    }
    /**
     * @param string|string[] $postType
     */
    public function getPostsWithErrors(int $limit = 50, string|array $postType = 'post'): array
    {
        $posts = get_posts(['post_type' => $postType, 'post_status' => ['publish', 'future', 'draft', 'pending', 'private'], 'posts_per_page' => $limit, 'meta_query' => [['key' => self::KEY_ERROR, 'compare' => 'EXISTS']]]);
        $results = [];
        foreach ($posts as $post) {
            $error = $this->getError($post->ID);
            $results[] = ['ID' => $post->ID, 'title' => get_the_title($post), 'code' => $error['code'] ?? '', 'message' => $error['message'] ?? ''];
        }
        return $results;
    }
    /**
     * @param string|string[] $postType
     */
    public function getPostsNeedingIndex(int $epoch, int $limit = 50, string|array $postType = 'post'): array
    {
        $query = new \WP_Query(['post_type' => $postType, 'post_status' => 'publish', 'posts_per_page' => $limit, 'fields' => 'ids', 'meta_query' => ['relation' => 'AND', ['key' => self::KEY_NO_RETRY, 'compare' => 'NOT EXISTS'], ['relation' => 'OR', ['key' => self::KEY_SUBMIT_TIME, 'compare' => 'NOT EXISTS'], ['key' => self::KEY_SUBMIT_TIME, 'value' => $epoch, 'type' => 'NUMERIC', 'compare' => '<']]]]);
        return $query->posts;
    }
}
