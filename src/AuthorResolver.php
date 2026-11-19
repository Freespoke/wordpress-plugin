<?php

declare (strict_types=1);
namespace Freespoke\Wordpress;

/**
 * Finds the site's authors whose name matches a search query, so the widget
 * can offer a card linking to the author's page alongside the results.
 *
 * WordPress is the only place that knows the canonical author URL, so this
 * lookup runs on the server at render time rather than in the widget bundle.
 *
 * Authors come from one of two sources. By default they are WordPress users
 * with published posts, matched on display name. A site that keeps its
 * authors as a post type (an "Authors" section in the admin, each author a
 * post with the headshot as featured image and the bio as content) names
 * that post type through the `freespoke_search_author_post_type` filter or
 * the FREESPOKE_AUTHOR_POST_TYPE constant, and authors are then matched on
 * post title instead, which is usually the fuller public name.
 */
class AuthorResolver
{
    /** Upper bound on author cards the widget is asked to show for one query. */
    public const MAX_MATCHES = 3;
    /** How many candidates to pull from WordPress before filtering in PHP. */
    public const SEARCH_LIMIT = 50;
    /**
     * How many of the query's words are tried as the database search term,
     * longest first, before giving up. A second try catches names whose
     * stored form breaks the longest word with punctuation ("oconnor" typed
     * for "O'Connor"); further tries would mostly cost queries on searches
     * that are not for an author at all.
     */
    public const MAX_ANCHORS = 2;
    /**
     * Seconds the matched authors for a query stay in the object cache. On
     * sites without a persistent object cache this only spans one request,
     * which still lets several widgets on a page share one lookup.
     */
    public const CACHE_TTL = 600;
    public const CACHE_GROUP = 'freespoke';
    /** Pixel size requested for the author's avatar; the widget shows it at 40px on 2x screens. */
    public const AVATAR_SIZE = 96;
    /**
     * Filter applied to each matching author before it is sent to the widget.
     *
     *   apply_filters('freespoke_search_author', ?array $author, object $source, string $query): ?array
     *
     * $author carries `name` and `url`, plus `image`, `description` and `bio`
     * when the source has them. $source is the WP_User, or the author post
     * when a post type is configured. A site may fill in or replace `image`,
     * `title`, `description`, `bio` and `url` from its own fields, or return
     * null to leave the author out. Only the fields the widget renders reach
     * the page.
     */
    public const FILTER = 'freespoke_search_author';
    /**
     * Filter applied to the whole list after matching, even when nothing
     * matched, so a site can reorder, add to, or replace the matches.
     *
     *   apply_filters('freespoke_search_authors', array $authors, string $query): array
     */
    public const LIST_FILTER = 'freespoke_search_authors';
    /**
     * Filter naming the post type that holds the site's authors. Defaults to
     * the FREESPOKE_AUTHOR_POST_TYPE constant, or empty for WordPress users.
     *
     *   apply_filters('freespoke_search_author_post_type', string $post_type): string
     */
    public const POST_TYPE_FILTER = 'freespoke_search_author_post_type';
    /**
     * Filter naming the post meta keys that hold an author post's fields, for
     * sites that keep the job title, description, bio, headshot, or the link
     * to the WordPress user in custom fields rather than the excerpt, content
     * and featured image. Any subset may be given.
     *
     *   apply_filters('freespoke_search_author_post_meta', array $keys): array
     *   // e.g. ['title' => 'job_title', 'description' => 'short_description',
     *   //       'bio' => 'biography', 'image' => 'headshot', 'user' => 'author_user']
     *
     * Text fields may hold HTML, as WYSIWYG fields do; it is reduced to
     * plain text. `image` may hold an attachment ID or a URL. `user` may hold
     * one user ID or login, or several; when it is mapped, authors are
     * ordered by those users' published post counts before the cap, as
     * user-based matching is.
     */
    public const POST_META_FILTER = 'freespoke_search_author_post_meta';
    /** Fields sent to the widget, in the order they are emitted. */
    private const PAGE_FIELDS = ['name', 'url', 'image', 'title', 'description'];
    /** Author post fields a site may map to post meta, besides `user`. */
    private const POST_META_FIELDS = ['title', 'description', 'bio', 'image'];
    /** Fields that hold URLs, and are sanitized as such before reaching the page. */
    private const URL_FIELDS = ['url', 'image'];
    private bool $multibyte;
    /** @var array<string, array<int, array<string, string>>> query => resolved list, for this request */
    private array $resolved = [];
    /**
     * @param bool|null $multibyte Whether the mbstring extension is available. WordPress
     *                             recommends it but does not require it, and it polyfills
     *                             only a few mb_* functions, so lower-casing falls back to
     *                             ASCII when it is missing. Null detects it.
     */
    public function __construct(?bool $multibyte = null)
    {
        $this->multibyte = $multibyte ?? function_exists('mb_strtolower');
    }
    /**
     * Authors for a query, resolved once per request however many widgets
     * on the page ask.
     *
     * @return array<int, array{name: string, url: string, image?: string, title?: string, description?: string}>
     */
    public function resolve(string $query): array
    {
        if (!array_key_exists($query, $this->resolved)) {
            $this->resolved[$query] = $this->pageList(apply_filters(self::LIST_FILTER, $this->matches($query), $query));
        }
        return $this->resolved[$query];
    }
    /**
     * @return array<int, array<string, string>>
     */
    private function matches(string $query): array
    {
        $query_words = $this->words($query);
        if ($query_words === []) {
            return [];
        }
        $post_type = $this->authorPostType();
        $meta_keys = $post_type !== '' ? $this->postMetaKeys() : [];
        $matches = [];
        foreach ($this->matchedSources($query, $query_words, $post_type, $meta_keys['user'] ?? null) as $source) {
            // Records are built only for names that matched: avatar and
            // permalink lookups are per-candidate queries on many hosts.
            $author = $post_type !== '' ? $this->postRecord($source, $meta_keys) : $this->userRecord($source);
            if ($author === null) {
                continue;
            }
            $author = $this->pageRecord(apply_filters(self::FILTER, $author, $source, $query));
            if ($author === null) {
                continue;
            }
            $matches[] = $author;
            if (count($matches) === self::MAX_MATCHES) {
                break;
            }
        }
        return $matches;
    }
    /**
     * The users or author posts whose name has every query word, in the
     * order they should be shown.
     *
     * The candidate search is the expensive part (a post count per candidate
     * for users), so the matched IDs are cached. Records and filters are not:
     * they run on every render, so a site's changes to its filters or fields
     * show at once.
     *
     * @param string[] $query_words
     * @return object[]
     */
    private function matchedSources(string $query, array $query_words, string $post_type, ?string $user_meta_key): array
    {
        $cache_key = 'author-matches:' . md5(serialize([$post_type, $user_meta_key, $this->lower(implode(' ', preg_split('/\s+/u', trim($query)) ?: []))]));
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $this->sourcesById($cached, $post_type);
        }
        $query_variants = $this->queryVariants($query_words);
        $matched = [];
        foreach ($this->anchors($query) as $anchor) {
            $candidates = $post_type !== '' ? $this->postCandidates($post_type, $anchor) : $this->userCandidates($anchor);
            foreach ($candidates as [$matched_name, $source]) {
                if ($this->matchesAnyVariant($matched_name, $query_variants)) {
                    $matched[] = $source;
                }
            }
            if ($matched !== []) {
                break;
            }
        }
        if ($user_meta_key !== null) {
            $matched = $this->orderByLinkedUsersPostCount($matched, $user_meta_key);
        }
        wp_cache_set($cache_key, array_map(static fn(object $source): int => (int) $source->ID, $matched), self::CACHE_GROUP, self::CACHE_TTL);
        return $matched;
    }
    /**
     * Cached IDs back to the objects the filters receive. Anything deleted or
     * unpublished since the lookup is dropped.
     *
     * @param mixed[] $ids
     * @return object[]
     */
    private function sourcesById(array $ids, string $post_type): array
    {
        $sources = [];
        foreach ($ids as $id) {
            $source = $post_type !== '' ? get_post((int) $id) : get_user_by('id', (int) $id);
            if (!is_object($source)) {
                continue;
            }
            if ($post_type !== '' && ($source->post_status ?? '') !== 'publish') {
                continue;
            }
            $sources[] = $source;
        }
        return $sources;
    }
    /**
     * WordPress users with published posts whose display name contains the
     * anchor, one word of the query. WordPress's user search is a single
     * substring match, which cannot express "every word, in any order", so
     * the whole-word test runs in PHP afterwards.
     *
     * @return array<int, array{0: string, 1: object}> display name and user
     */
    private function userCandidates(string $anchor): array
    {
        $users = get_users([
            'search' => '*' . $anchor . '*',
            'search_columns' => ['display_name'],
            'has_published_posts' => \true,
            // When more authors match than MAX_MATCHES allows, keep the ones
            // with the most published work rather than the first by login.
            'orderby' => 'post_count',
            'order' => 'DESC',
            'number' => self::SEARCH_LIMIT,
        ]);
        return array_map(static fn(object $user): array => [(string) ($user->display_name ?? ''), $user], $users);
    }
    /**
     * @return array<string, string>|null null when the user has no author archive
     */
    private function userRecord(object $user): ?array
    {
        $name = (string) ($user->display_name ?? '');
        $url = (string) (get_author_posts_url((int) $user->ID) ?: '');
        if ($name === '' || $url === '') {
            return null;
        }
        $author = ['name' => $name, 'url' => $url];
        $image = $this->avatarUrl((int) $user->ID);
        if ($image !== null) {
            $author['image'] = $image;
        }
        return $author;
    }
    /**
     * Published posts of the author post type whose title contains the
     * anchor, one word of the query. The headshot is the featured
     * image at thumbnail size, the one-line description is the excerpt, and
     * the bio is the content; a job title has no standard home and is left
     * to the per-author filter.
     *
     * @return array<int, array{0: string, 1: object}> post title and post
     */
    private function postCandidates(string $post_type, string $anchor): array
    {
        $posts = get_posts([
            'post_type' => $post_type,
            'post_status' => 'publish',
            's' => $anchor,
            // Title matches rank above body matches. There is no post count
            // to prefer prolific authors by, as there is for users; a site
            // that wants a better order has the freespoke_search_authors
            // filter.
            'orderby' => 'relevance',
            'posts_per_page' => self::SEARCH_LIMIT,
        ]);
        return array_map(static fn(object $post): array => [trim((string) ($post->post_title ?? '')), $post], $posts);
    }
    /**
     * Featured image, excerpt and content by default; a mapped meta key
     * replaces the default for that field.
     *
     * @param array<string, string> $meta_keys
     * @return array<string, string>|null null when the post has no permalink
     */
    private function postRecord(object $post, array $meta_keys): ?array
    {
        $name = trim((string) ($post->post_title ?? ''));
        $url = (string) (get_permalink($post) ?: '');
        if ($name === '' || $url === '') {
            return null;
        }
        $author = ['name' => $name, 'url' => $url];
        $image = get_the_post_thumbnail_url($post, 'thumbnail');
        if (is_string($image) && $image !== '') {
            $author['image'] = $image;
        }
        $description = $this->plainText($post->post_excerpt ?? '');
        if ($description !== '') {
            $author['description'] = $description;
        }
        $bio = $this->plainText($post->post_content ?? '');
        if ($bio !== '') {
            $author['bio'] = $bio;
        }
        foreach (self::POST_META_FIELDS as $field) {
            if (!isset($meta_keys[$field])) {
                continue;
            }
            $value = get_post_meta((int) $post->ID, $meta_keys[$field], \true);
            $value = $field === 'image' ? $this->imageFromMeta($value) : $this->plainText($value);
            if (is_string($value) && trim($value) !== '') {
                $author[$field] = trim($value);
            }
        }
        return $author;
    }
    /**
     * Text for the widget from a field that may hold HTML, such as post
     * content or a WYSIWYG custom field: tags removed, entities decoded, and
     * whitespace collapsed, since the widget shows it as plain text.
     *
     * @param mixed $value
     */
    private function plainText($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $text = html_entity_decode(wp_strip_all_tags($value, \true), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
    /**
     * An image custom field holds either an attachment ID (as ACF and most
     * field plugins store it) or a URL.
     *
     * @param mixed $value
     */
    private function imageFromMeta($value): ?string
    {
        if (is_numeric($value)) {
            $url = wp_get_attachment_image_url((int) $value, 'thumbnail');
            return is_string($url) && $url !== '' ? $url : null;
        }
        return is_string($value) ? $value : null;
    }
    /**
     * Most-published authors first, judged by the WordPress users the author
     * post credits, so a surname shared by more authors than the widget shows
     * keeps the ones a reader most likely means. Posts without a linked user
     * keep their search order after those with one.
     *
     * @param object[] $posts
     * @return object[]
     */
    private function orderByLinkedUsersPostCount(array $posts, string $user_meta_key): array
    {
        $counts = [];
        foreach ($posts as $index => $post) {
            $users = get_post_meta((int) $post->ID, $user_meta_key, \true);
            $users = is_array($users) ? $users : [$users];
            $total = 0;
            foreach ($users as $user) {
                $user_id = $this->linkedUserId($user);
                if ($user_id !== null) {
                    $total += (int) count_user_posts($user_id);
                }
            }
            $counts[$index] = $total;
        }
        $order = array_keys($posts);
        usort($order, static fn(int $a, int $b): int => $counts[$b] <=> $counts[$a] ?: $a <=> $b);
        return array_map(static fn(int $index): object => $posts[$index], $order);
    }
    /**
     * A user link field holds a user ID, or a login when the site copied
     * accounts over by name.
     *
     * @param mixed $value
     */
    private function linkedUserId($value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $user = get_user_by('login', trim($value));
        return is_object($user) ? (int) $user->ID : null;
    }
    /**
     * @return array<string, string> field => post meta key
     */
    private function postMetaKeys(): array
    {
        $keys = apply_filters(self::POST_META_FILTER, []);
        if (!is_array($keys)) {
            return [];
        }
        $clean = [];
        foreach (array_merge(self::POST_META_FIELDS, ['user']) as $field) {
            if (isset($keys[$field]) && is_string($keys[$field]) && trim($keys[$field]) !== '') {
                $clean[$field] = trim($keys[$field]);
            }
        }
        return $clean;
    }
    private function authorPostType(): string
    {
        $default = defined('FREESPOKE_AUTHOR_POST_TYPE') ? (string) constant('FREESPOKE_AUTHOR_POST_TYPE') : '';
        $post_type = apply_filters(self::POST_TYPE_FILTER, $default);
        return is_string($post_type) ? trim($post_type) : '';
    }
    /**
     * Reduce a filtered author to the fields the widget renders, with blanks,
     * anything that is not a string, and unsafe URLs dropped. Null when the
     * filter removed the author or left it without a name or URL.
     *
     * @param mixed $filtered
     * @return array<string, string>|null
     */
    private function pageRecord($filtered): ?array
    {
        if (!is_array($filtered)) {
            return null;
        }
        $record = [];
        foreach (self::PAGE_FIELDS as $field) {
            $value = $filtered[$field] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if (in_array($field, self::URL_FIELDS, \true)) {
                $value = esc_url_raw($value);
            }
            if ($value !== '') {
                $record[$field] = $value;
            }
        }
        if (!isset($record['name'], $record['url'])) {
            return null;
        }
        return $record;
    }
    /**
     * The list filter may hand back anything, so every entry goes through the
     * same cleaning as a single author, a second entry for the same URL is
     * dropped, and the cap applies again.
     *
     * @param mixed $filtered
     * @return array<int, array<string, string>>
     */
    private function pageList($filtered): array
    {
        if (!is_array($filtered)) {
            return [];
        }
        $list = [];
        $seen = [];
        foreach ($filtered as $entry) {
            $record = $this->pageRecord($entry);
            if ($record === null || isset($seen[$record['url']])) {
                continue;
            }
            $seen[$record['url']] = \true;
            $list[] = $record;
            if (count($list) === self::MAX_MATCHES) {
                break;
            }
        }
        return $list;
    }
    /**
     * The author's profile picture, or null when they have none.
     *
     * Asking Gravatar for a 404 default means a user without a Gravatar
     * yields a URL that fails to load instead of a generic placeholder, so
     * the widget can leave the picture column empty. Avatar plugins that
     * store photos locally hook the same function and return their own URL.
     */
    private function avatarUrl(int $user_id): ?string
    {
        $url = get_avatar_url($user_id, ['size' => self::AVATAR_SIZE, 'default' => '404']);
        return is_string($url) && $url !== '' ? $url : null;
    }
    /**
     * The query as typed plus, when it contains spelled-out initials such as
     * "j. d. foster", a variant with those initials joined ("jd foster"), so
     * that an author stored as "JD Foster" still matches.
     *
     * @param string[] $query_words
     * @return string[][]
     */
    private function queryVariants(array $query_words): array
    {
        $joined = [];
        $initials = '';
        foreach ($query_words as $word) {
            if (strlen($word) === 1) {
                $initials .= $word;
                continue;
            }
            if ($initials !== '') {
                $joined[] = $initials;
                $initials = '';
            }
            $joined[] = $word;
        }
        if ($initials !== '') {
            $joined[] = $initials;
        }
        return $joined === $query_words ? [$query_words] : [$query_words, $joined];
    }
    /**
     * @param string[][] $query_variants
     */
    private function matchesAnyVariant(string $name, array $query_variants): bool
    {
        foreach ($query_variants as $query_words) {
            if ($this->containsEveryWord($name, $query_words)) {
                return \true;
            }
        }
        return \false;
    }
    /**
     * Every query word must appear as a whole word of the name. The name is
     * tokenized both with punctuation dropped ("J.D." -> "jd") and with
     * punctuation treated as a separator ("J.D." -> "j", "d"), so a visitor
     * can type initials either way and still match.
     *
     * @param string[] $query_words
     */
    private function containsEveryWord(string $name, array $query_words): bool
    {
        $name_words = array_merge($this->words($name), $this->words($name, \true));
        foreach ($query_words as $word) {
            if (!in_array($word, $name_words, \true)) {
                return \false;
            }
        }
        return \true;
    }
    /**
     * Lower-cased words of a string. Punctuation is either dropped, so that
     * "J.D. Foster" yields "jd foster", or treated as a word break, so that it
     * yields "j d foster".
     *
     * @return string[]
     */
    private function words(string $value, bool $split_on_punctuation = \false): array
    {
        $lowered = $this->lower($value);
        $collapsed = preg_replace('/[^\p{L}\p{N}\s]+/u', $split_on_punctuation ? ' ' : '', $lowered) ?? '';
        $parts = preg_split('/\s+/u', trim($collapsed)) ?: [];
        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }
    /**
     * Database search terms to try, in order: the query's unbroken runs of
     * letters and digits as typed, longest (most selective) first. Runs are
     * taken before punctuation is stripped, so "o'connor" searches for
     * "connor", which the stored name contains, rather than "oconnor". When
     * the visitor leaves out punctuation the stored name has ("oconnor" for
     * "O'Connor"), the longest run finds nothing and the next one is tried.
     *
     * @return string[]
     */
    private function anchors(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $query, $found);
        $runs = array_values(array_unique(array_map(fn(string $run): string => $this->lower($run), $found[0])));
        usort($runs, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return array_slice($runs, 0, self::MAX_ANCHORS);
    }
    private function lower(string $value): string
    {
        return $this->multibyte ? mb_strtolower($value) : strtolower($value);
    }
}
