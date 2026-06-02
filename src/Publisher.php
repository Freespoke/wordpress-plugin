<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

use FreespokeDeps\Freespoke\Partner\Article;
use FreespokeDeps\Freespoke\Partner\IndexResult;
use FreespokeDeps\Freespoke\Partner\Person;
use FreespokeDeps\GuzzleHttp\Exception\BadResponseException;
class Publisher
{
    private \Freespoke\Wordpress\ClientFactory $factory;
    private \Freespoke\Wordpress\PostMeta $postMeta;
    private \Freespoke\Wordpress\Settings $settings;
    /** @var array<int,true> */
    private static array $submittedThisRequest = [];
    public function __construct(\Freespoke\Wordpress\ClientFactory $factory, \Freespoke\Wordpress\PostMeta $postMeta, \Freespoke\Wordpress\Settings $settings)
    {
        $this->factory = $factory;
        $this->postMeta = $postMeta;
        $this->settings = $settings;
    }
    public function onPostSave(int $postId, \WP_Post $post): void
    {
        // Gutenberg can fire wp_after_insert_post more than once per "publish"
        // (post body + meta + terms saves), and other plugins commonly call
        // wp_update_post() from save_post handlers. Collapse those into a
        // single submission per request.
        if (isset(self::$submittedThisRequest[$postId])) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }
        if (!$this->shouldIndex($post)) {
            return;
        }
        self::$submittedThisRequest[$postId] = \true;
        $result = $this->submit($postId, $post);
        if (is_wp_error($result)) {
            $this->addAdminWarningNotice($result->get_error_message());
        }
    }
    /**
     * @return IndexResult|\WP_Error
     */
    public function submit(int $postId, \WP_Post $post): IndexResult|\WP_Error
    {
        $article = $this->buildArticle($postId, $post);
        try {
            $result = $this->factory->getClient()->index($article);
        } catch (\Throwable $e) {
            $message = sprintf(__('Freespoke Publisher was unable to index this post. Error: %s.', 'freespoke-widget'), $e->getMessage());
            $retryable = $this->isRetryableException($e);
            $this->postMeta->setError($postId, 'freespoke_api_error', $message, $retryable);
            $this->notifyFailure($postId, 'freespoke_api_error', $message);
            return new \WP_Error('freespoke_api_error', $message, ['status' => $e->getCode()]);
        }
        if ($result === null) {
            $message = __('Freespoke Publisher was unable to publish this post. The remote server returned an unexpected response.', 'freespoke-widget');
            $this->postMeta->setError($postId, 'freespoke_api_error', $message);
            $this->notifyFailure($postId, 'freespoke_api_error', $message);
            return new \WP_Error('freespoke_api_error', $message);
        }
        $this->postMeta->setJobId($postId, $result->job_id);
        $this->postMeta->setSubmitTime($postId, time());
        $this->postMeta->clearDocId($postId);
        $this->postMeta->clearError($postId);
        return $result;
    }
    public function onTrashOrDelete(int $postId): void
    {
        if (!current_user_can('delete_post', $postId)) {
            return;
        }
        $post = get_post($postId);
        if (!$post) {
            return;
        }
        if (!in_array($post->post_type, $this->settings->getPostTypes(), \true)) {
            return;
        }
        $this->delete($postId, $post);
    }
    public function delete(int $postId, \WP_Post $post): void
    {
        $url = get_permalink($postId);
        if (!is_string($url) || $url === '') {
            return;
        }
        $docId = $this->postMeta->getDocId($postId);
        if (!is_string($docId) || !preg_match('/^[0-9a-fA-F]{40}$/', $docId)) {
            $docId = null;
        }
        try {
            $this->factory->getClient()->delete($url, $docId);
        } catch (\Throwable $e) {
            $message = sprintf(__('Freespoke Publisher was unable to delete this post. Error: %s.', 'freespoke-widget'), $e->getMessage());
            $this->postMeta->setError($postId, 'freespoke_api_error', $message);
            $this->notifyFailure($postId, 'freespoke_api_error', $message);
            return;
        }
        $this->postMeta->clearDocId($postId);
        $this->postMeta->clearJobId($postId);
        $this->postMeta->clearError($postId);
    }
    /**
     * @return int|\WP_Error
     */
    public function getEpoch(): int|\WP_Error
    {
        $cached = get_transient('freespoke_publisher_epoch');
        if ($cached !== \false) {
            return (int) $cached;
        }
        try {
            $epoch = $this->factory->getClient()->getEpoch();
        } catch (\Throwable $e) {
            return new \WP_Error('freespoke_epoch_unavailable', __('Unable to retrieve the Freespoke epoch.', 'freespoke-widget'));
        }
        set_transient('freespoke_publisher_epoch', $epoch, DAY_IN_SECONDS);
        return $epoch;
    }
    public function notifyFailure(int $postId, string $code, string $message): void
    {
        $noticeEmails = $this->settings->getNoticeEmails();
        $noticeEmails[] = get_option('admin_email');
        $recipients = array_filter(array_unique($noticeEmails));
        if (empty($recipients)) {
            return;
        }
        $post = get_post($postId);
        $postTitle = $post ? get_the_title($post) : sprintf(__('Post ID %d', 'freespoke-widget'), $postId);
        $editLink = $post ? get_edit_post_link($post, '') : '';
        $subject = sprintf(__('Freespoke Publisher error for "%s"', 'freespoke-widget'), $postTitle);
        $lines = [sprintf(__('Post: %s (ID: %d)', 'freespoke-widget'), $postTitle, $postId), sprintf(__('Error Code: %s', 'freespoke-widget'), $code ?: 'error'), sprintf(__('Message: %s', 'freespoke-widget'), $message)];
        if ($editLink) {
            $lines[] = sprintf(__('Edit Link: %s', 'freespoke-widget'), $editLink);
        }
        wp_mail($recipients, $subject, implode("\n", $lines));
    }
    public function shouldIndex(\WP_Post $post): bool
    {
        if (wp_is_post_revision($post->ID)) {
            return \false;
        }
        if (!in_array($post->post_type, $this->settings->getPostTypes(), \true)) {
            return \false;
        }
        if ($post->post_status !== 'publish') {
            return \false;
        }
        if (!empty($post->post_password)) {
            return \false;
        }
        return \true;
    }
    private function buildArticle(int $postId, \WP_Post $post): Article
    {
        $article = new Article();
        $article->test_mode = $this->settings->getTestMode();
        $article->url = get_permalink($postId) ?: '';
        $article->title = $post->post_title;
        $article->description = get_the_excerpt($postId);
        $article->content = apply_filters('the_content', $post->post_content);
        foreach ($this->settings->getContentMetaFieldKeys() as $key) {
            $v = get_post_meta($postId, $key, \true);
            if (is_string($v) && trim($v) !== '') {
                $article->content .= "\n\n" . wp_kses_post($v);
            }
        }
        $article->content = ltrim($article->content);
        $article->image_url = get_the_post_thumbnail_url($postId, 'full') ?: null;
        $dateStr = get_the_date(\DATE_RFC3339_EXTENDED, $postId);
        $article->publish_time = $dateStr ? new \DateTimeImmutable($dateStr) : new \DateTimeImmutable();
        $tags = get_the_tags($postId);
        if ($tags) {
            $article->keywords = array_map(fn($t) => $t->name, $tags);
        }
        $authorId = $post->post_author;
        $person = new Person();
        $person->name = get_the_author_meta('display_name', $authorId);
        $person->url = get_author_posts_url($authorId) ?: null;
        $person->twitter_id = get_the_author_meta('twitter', $authorId) ?: null;
        $person->facebook_id = get_the_author_meta('facebook', $authorId) ?: null;
        $article->setAuthors($person);
        return $article;
    }
    private function addAdminWarningNotice(string $message): void
    {
        add_action('admin_notices', static function () use ($message) {
            echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
        });
    }
    /**
     * Classify a thrown exception as transient (retry on the cron schedule)
     * or permanent (wait for the post to be modified before retrying).
     *
     * HTTP 408 Request Timeout, 429 Too Many Requests, and 5xx are retryable.
     * Other 4xx responses are treated as permanent — re-issuing the same
     * request won't change the outcome until the post itself changes.
     *
     * Non-HTTP errors (network failures, JSON parse errors, etc.) are
     * treated as retryable.
     */
    private function isRetryableException(\Throwable $e): bool
    {
        if (!$e instanceof BadResponseException) {
            return \true;
        }
        $response = $e->getResponse();
        if ($response === null) {
            return \true;
        }
        $status = $response->getStatusCode();
        if ($status === 408 || $status === 429) {
            return \true;
        }
        if ($status >= 500 && $status < 600) {
            return \true;
        }
        return $status < 400;
    }
}
