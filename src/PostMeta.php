<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

class PostMeta
{
    private const KEY_SUBMIT_TIME = '_freespoke_submit_time';
    private const KEY_JOB_ID = '_freespoke_job_id';
    private const KEY_ERROR = '_freespoke_error';
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
        return ['code' => (string) ($decoded['code'] ?? 'error'), 'message' => (string) $decoded['message'], 'timestamp' => (string) ($decoded['timestamp'] ?? '')];
    }
    public function setError(int $postId, string $code, string $message): void
    {
        $payload = ['code' => sanitize_text_field($code ?: 'error'), 'message' => sanitize_text_field(wp_strip_all_tags($message)), 'timestamp' => current_time('mysql')];
        update_post_meta($postId, self::KEY_ERROR, wp_json_encode($payload));
    }
    public function clearError(int $postId): void
    {
        delete_post_meta($postId, self::KEY_ERROR);
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
        $query = new \WP_Query(['post_type' => $postType, 'post_status' => 'publish', 'posts_per_page' => $limit, 'fields' => 'ids', 'meta_query' => ['relation' => 'OR', ['key' => self::KEY_SUBMIT_TIME, 'compare' => 'NOT EXISTS'], ['key' => self::KEY_SUBMIT_TIME, 'value' => $epoch, 'type' => 'NUMERIC', 'compare' => '<']]]);
        return $query->posts;
    }
}
