<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

use FreespokeDeps\Freespoke\Partner\Article;
use FreespokeDeps\Freespoke\Partner\IndexResult;
use FreespokeDeps\Freespoke\Partner\Person;
class Publisher
{
    private \Freespoke\Wordpress\ClientFactory $factory;
    private \Freespoke\Wordpress\PostMeta $postMeta;
    private \Freespoke\Wordpress\Settings $settings;
    public function __construct(\Freespoke\Wordpress\ClientFactory $factory, \Freespoke\Wordpress\PostMeta $postMeta, \Freespoke\Wordpress\Settings $settings)
    {
        $this->factory = $factory;
        $this->postMeta = $postMeta;
        $this->settings = $settings;
    }
    public function onPostSave(int $postId, \WP_Post $post): void
    {
        if (!current_user_can('edit_post', $postId)) {
            return;
        }
        if (!$this->shouldIndex($post)) {
            return;
        }
        $result = $this->submit($postId, $post);
        if (is_wp_error($result)) {
            $this->addAdminWarningNotice($result->get_error_message());
        }
    }
    public function onStatusTransition(string $new, string $old, \WP_Post $post): void
    {
        if ($new !== 'publish' || $old !== 'future') {
            return;
        }
        if (!$this->shouldIndex($post)) {
            return;
        }
        $result = $this->submit($post->ID, $post);
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
            $this->postMeta->setError($postId, 'freespoke_api_error', $message);
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
        $this->postMeta->clearError($postId);
        return $result;
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
    private function shouldIndex(\WP_Post $post): bool
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
        $article->url = get_permalink($postId) ?: '';
        $article->title = $post->post_title;
        $article->description = get_the_excerpt($postId);
        $article->content = apply_filters('the_content', $post->post_content);
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
}
