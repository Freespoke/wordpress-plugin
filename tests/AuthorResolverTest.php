<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Freespoke\Wordpress\AuthorResolver;

class AuthorResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('wp_strip_all_tags')->alias(fn(string $text): string => trim(strip_tags($text)));
    }

    private function user(int $id, string $displayName): object
    {
        return (object) ['ID' => $id, 'display_name' => $displayName];
    }

    private function stubNoAvatar(): void
    {
        Functions\when('get_avatar_url')->justReturn(false);
    }

    public function testEmptyQueryReturnsNoMatches(): void
    {
        Functions\expect('get_users')->never();

        $this->assertSame([], (new AuthorResolver())->resolve('   '));
    }

    public function testMatchesWhenEveryQueryWordAppearsInDisplayName(): void
    {
        Functions\expect('get_users')->once()->andReturn([$this->user(7, 'Elizabeth Troutman Mitchell')]);
        Functions\expect('get_author_posts_url')->once()->with(7)->andReturn('https://example.com/author/elizabeth-mitchell/');
        $this->stubNoAvatar();

        $matches = (new AuthorResolver())->resolve('elizabeth mitchell');

        $this->assertSame(
            [['name' => 'Elizabeth Troutman Mitchell', 'url' => 'https://example.com/author/elizabeth-mitchell/']],
            $matches,
        );
    }

    public function testIgnoresUsersMissingAQueryWord(): void
    {
        // Nobody matches on the longest word, so the next one is tried too.
        Functions\expect('get_users')->twice()->andReturn([$this->user(1, 'Elizabeth Smith')]);
        Functions\expect('get_author_posts_url')->never();

        $this->assertSame([], (new AuthorResolver())->resolve('elizabeth mitchell'));
    }

    public function testMatchesWholeWordsOnly(): void
    {
        Functions\expect('get_users')->twice()->andReturn([$this->user(1, 'Elizabeth Mitchell')]);
        Functions\expect('get_author_posts_url')->never();

        $this->assertSame([], (new AuthorResolver())->resolve('liz mitchell'));
    }

    public function testMatchIgnoresCaseAndPunctuation(): void
    {
        Functions\expect('get_users')->once()->andReturn([$this->user(2, 'J.D. Foster')]);
        Functions\expect('get_author_posts_url')->once()->with(2)->andReturn('https://example.com/author/jd-foster/');
        $this->stubNoAvatar();

        $matches = (new AuthorResolver())->resolve('JD FOSTER');

        $this->assertCount(1, $matches);
        $this->assertSame('J.D. Foster', $matches[0]['name']);
    }

    public function testSearchesMostPublishedAuthorsFirstByLongestQueryWord(): void
    {
        Functions\expect('get_users')
            ->once()
            ->with(\Mockery::on(function (array $args): bool {
                return $args['search'] === '*mitchell*'
                    && $args['search_columns'] === ['display_name']
                    && $args['has_published_posts'] === true
                    && $args['orderby'] === 'post_count'
                    && $args['order'] === 'DESC'
                    && $args['number'] === AuthorResolver::SEARCH_LIMIT;
            }))
            ->andReturn([$this->user(1, 'Liz Mitchell')]);
        Functions\expect('get_author_posts_url')->once()->andReturn('https://example.com/author/liz-mitchell/');
        $this->stubNoAvatar();

        (new AuthorResolver())->resolve('liz mitchell');
    }

    public function testCapsMatchesAtThree(): void
    {
        Functions\expect('get_users')->once()->andReturn([
            $this->user(1, 'Alex Mitchell'),
            $this->user(2, 'Bo Mitchell'),
            $this->user(3, 'Cy Mitchell'),
            $this->user(4, 'Di Mitchell'),
        ]);
        Functions\expect('get_author_posts_url')->times(3)->andReturnUsing(fn(int $id) => "https://example.com/author/{$id}/");
        $this->stubNoAvatar();

        $matches = (new AuthorResolver())->resolve('mitchell');

        $this->assertCount(AuthorResolver::MAX_MATCHES, $matches);
        $this->assertSame('Alex Mitchell', $matches[0]['name']);
        $this->assertSame('Cy Mitchell', $matches[2]['name']);
    }

    public function testSkipsUsersWithoutAuthorUrl(): void
    {
        Functions\expect('get_users')->once()->andReturn([
            $this->user(1, 'Alex Mitchell'),
            $this->user(2, 'Bo Mitchell'),
        ]);
        Functions\expect('get_author_posts_url')->twice()->andReturnUsing(fn(int $id) => $id === 1 ? '' : 'https://example.com/author/bo/');
        $this->stubNoAvatar();

        $matches = (new AuthorResolver())->resolve('mitchell');

        $this->assertSame([['name' => 'Bo Mitchell', 'url' => 'https://example.com/author/bo/']], $matches);
    }

    public function testIncludesAvatarWhenAuthorHasOne(): void
    {
        Functions\expect('get_users')->once()->andReturn([$this->user(7, 'Elizabeth Mitchell')]);
        Functions\expect('get_author_posts_url')->once()->with(7)->andReturn('https://example.com/author/elizabeth-mitchell/');
        Functions\expect('get_avatar_url')
            ->once()
            ->with(7, ['size' => AuthorResolver::AVATAR_SIZE, 'default' => '404'])
            ->andReturn('https://example.com/uploads/elizabeth.jpg');

        $matches = (new AuthorResolver())->resolve('elizabeth mitchell');

        $this->assertSame('https://example.com/uploads/elizabeth.jpg', $matches[0]['image']);
    }

    public function testOmitsAvatarWhenAuthorHasNone(): void
    {
        Functions\expect('get_users')->once()->andReturn([$this->user(7, 'Elizabeth Mitchell')]);
        Functions\expect('get_author_posts_url')->once()->with(7)->andReturn('https://example.com/author/elizabeth-mitchell/');
        Functions\expect('get_avatar_url')->once()->andReturn(false);

        $matches = (new AuthorResolver())->resolve('elizabeth mitchell');

        $this->assertArrayNotHasKey('image', $matches[0]);
    }

    public function testKeepsOnlyCandidatesWhoseNameHasEveryQueryWord(): void
    {
        Functions\expect('get_users')->once()->andReturn([
            $this->user(1, 'Elizabeth Smith'),
            $this->user(2, 'Elizabeth Troutman Mitchell'),
            $this->user(3, 'Mitchell Elizabeth'),
            $this->user(4, 'Mitch Elizabethson'),
        ]);
        Functions\expect('get_author_posts_url')->twice()->andReturnUsing(fn(int $id) => "https://example.com/author/{$id}/");
        $this->stubNoAvatar();

        $names = array_column((new AuthorResolver())->resolve('elizabeth mitchell'), 'name');

        $this->assertSame(['Elizabeth Troutman Mitchell', 'Mitchell Elizabeth'], $names);
    }

    public function testInitialsMatchWhetherOrNotTheyArePunctuated(): void
    {
        Functions\expect('get_users')->twice()->andReturn([$this->user(2, 'J.D. Foster')], [$this->user(3, 'JD Foster')]);
        Functions\expect('get_author_posts_url')->twice()->andReturn('https://example.com/author/jd-foster/');
        $this->stubNoAvatar();

        $this->assertCount(1, (new AuthorResolver())->resolve('jd foster'));
        $this->assertCount(1, (new AuthorResolver())->resolve('j. d. foster'));
    }

    public function testApostrophesDoNotBlockAMatch(): void
    {
        Functions\expect('get_users')->once()->andReturn([$this->user(5, "Sean O'Connor")]);
        Functions\expect('get_author_posts_url')->once()->andReturn('https://example.com/author/sean-oconnor/');
        $this->stubNoAvatar();

        $this->assertCount(1, (new AuthorResolver())->resolve("sean o'connor"));
    }

    public function testCandidateSearchUsesTheLongestUnpunctuatedRunOfTheQuery(): void
    {
        Functions\expect('get_users')
            ->once()
            ->with(\Mockery::on(fn(array $args): bool => $args['search'] === '*connor*'))
            ->andReturn([$this->user(5, "Sean O'Connor")]);
        Functions\expect('get_author_posts_url')->once()->andReturn('https://example.com/author/sean-oconnor/');
        $this->stubNoAvatar();

        (new AuthorResolver())->resolve("sean o'connor");
    }

    /**
     * Answers the user search for each anchor from a fixed directory, the way
     * WordPress's substring search would, and records the anchors tried.
     *
     * @param array<int, string> $directory user ID => display name
     * @param string[] $searched
     */
    private function userDirectory(array $directory, array &$searched): void
    {
        Functions\when('get_users')->alias(function (array $args) use ($directory, &$searched): array {
            $searched[] = $args['search'];
            $needle = trim($args['search'], '*');
            $found = [];
            foreach ($directory as $id => $name) {
                if (str_contains(strtolower($name), $needle)) {
                    $found[] = $this->user($id, $name);
                }
            }

            return $found;
        });
        Functions\when('get_author_posts_url')->alias(fn(int $id) => "https://example.com/author/{$id}/");
        $this->stubNoAvatar();
    }

    public function testPunctuatedNamesMatchWhenTypedWithoutThePunctuation(): void
    {
        $directory = [5 => "Sean O'Connor", 6 => 'Mary-Kate Smith', 7 => 'Sean Hannity'];

        foreach (['sean oconnor' => ["Sean O'Connor"], 'marykate smith' => ['Mary-Kate Smith'], "sean o'connor" => ["Sean O'Connor"]] as $query => $expected) {
            $searched = [];
            $this->userDirectory($directory, $searched);

            $this->assertSame($expected, array_column((new AuthorResolver())->resolve($query), 'name'), $query);
        }
    }

    public function testFallsBackToTheNextLongestQueryWordOnlyWhenTheFirstFindsNoOne(): void
    {
        $searched = [];
        $this->userDirectory([5 => "Sean O'Connor", 8 => 'Elizabeth Troutman Mitchell'], $searched);

        (new AuthorResolver())->resolve('sean oconnor');
        $this->assertSame(['*oconnor*', '*sean*'], $searched);

        $searched = [];
        (new AuthorResolver())->resolve('elizabeth mitchell');
        $this->assertSame(['*elizabeth*'], $searched);
    }

    public function testTriesAtMostTwoQueryWords(): void
    {
        $searched = [];
        $this->userDirectory([], $searched);

        (new AuthorResolver())->resolve('federal reserve interest rates');

        $this->assertSame(['*interest*', '*federal*'], $searched);
    }

    // ------------------------------------------------------------------
    // Caching
    // ------------------------------------------------------------------

    public function testResolvesEachQueryOncePerRequest(): void
    {
        Functions\expect('get_users')->once()->andReturn([$this->user(7, 'Elizabeth Mitchell')]);
        Functions\expect('get_author_posts_url')->once()->andReturn('https://example.com/author/elizabeth-mitchell/');
        $this->stubNoAvatar();
        Filters\expectApplied('freespoke_search_authors')->once()->andReturnFirstArg();

        $resolver = new AuthorResolver();

        $this->assertSame($resolver->resolve('elizabeth mitchell'), $resolver->resolve('elizabeth mitchell'));
    }

    public function testCachesTheMatchedIdsUnderTheNormalizedQuery(): void
    {
        Functions\when('get_users')->justReturn([$this->user(7, 'Elizabeth Mitchell'), $this->user(8, 'Elizabeth Smith')]);
        Functions\when('get_author_posts_url')->justReturn('https://example.com/author/elizabeth-mitchell/');
        $this->stubNoAvatar();
        $stored = [];
        Functions\when('wp_cache_set')->alias(function (string $key, array $ids, string $group, int $ttl) use (&$stored): bool {
            $stored[] = [$key, $ids, $group, $ttl];

            return true;
        });

        (new AuthorResolver())->resolve('Elizabeth  Mitchell');
        (new AuthorResolver())->resolve('elizabeth mitchell');

        $this->assertCount(2, $stored);
        $this->assertSame($stored[0], $stored[1]);
        $this->assertSame([[7], AuthorResolver::CACHE_GROUP, AuthorResolver::CACHE_TTL], array_slice($stored[0], 1));
    }

    public function testCachedMatchesSkipTheCandidateSearchButStillRunTheFilters(): void
    {
        Functions\when('wp_cache_get')->justReturn([7, 9]);
        Functions\expect('get_users')->never();
        Functions\when('get_user_by')->alias(fn(string $field, int $id) => $id === 7 ? $this->user(7, 'Elizabeth Mitchell') : false);
        Functions\expect('get_author_posts_url')->once()->with(7)->andReturn('https://example.com/author/elizabeth-mitchell/');
        $this->stubNoAvatar();
        Filters\expectApplied('freespoke_search_author')
            ->once()
            ->andReturnUsing(fn(array $author): array => $author + ['title' => 'White House Correspondent']);

        $matches = (new AuthorResolver())->resolve('elizabeth mitchell');

        $this->assertSame([[
            'name' => 'Elizabeth Mitchell',
            'url' => 'https://example.com/author/elizabeth-mitchell/',
            'title' => 'White House Correspondent',
        ]], $matches);
    }

    public function testCachedAuthorPostsNoLongerPublishedAreLeftOut(): void
    {
        $this->useAuthorPostType();
        Functions\when('wp_cache_get')->justReturn([1, 2]);
        Functions\expect('get_posts')->never();
        Functions\when('get_post')->alias(fn(int $id) => (object) [
            'ID' => $id,
            'post_title' => $id === 1 ? 'Dan Mitchell' : 'Amy Mitchell',
            'post_status' => $id === 1 ? 'draft' : 'publish',
            'post_excerpt' => '',
            'post_content' => '',
        ]);
        Functions\when('get_permalink')->alias(fn(object $post) => "https://example.com/author/{$post->ID}/");
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);

        $this->assertSame(['Amy Mitchell'], array_column((new AuthorResolver())->resolve('mitchell'), 'name'));
    }

    public function testWorksWithoutTheMbstringExtension(): void
    {
        Functions\expect('get_users')->once()->andReturn([$this->user(7, 'Elizabeth Mitchell')]);
        Functions\expect('get_author_posts_url')->once()->andReturn('https://example.com/author/elizabeth-mitchell/');
        $this->stubNoAvatar();

        $matches = (new AuthorResolver(false))->resolve('ELIZABETH Mitchell');

        $this->assertCount(1, $matches);
    }

    private function oneAuthor(int $id = 7, string $name = 'Elizabeth Troutman Mitchell'): void
    {
        Functions\expect('get_users')->once()->andReturn([$this->user($id, $name)]);
        Functions\expect('get_author_posts_url')->once()->andReturn('https://example.com/author/elizabeth-mitchell/');
        $this->stubNoAvatar();
    }

    public function testFilterReceivesTheDefaultRecordTheUserAndTheQuery(): void
    {
        $this->oneAuthor();
        Filters\expectApplied('freespoke_search_author')
            ->once()
            ->with(
                ['name' => 'Elizabeth Troutman Mitchell', 'url' => 'https://example.com/author/elizabeth-mitchell/'],
                \Mockery::on(fn(object $user): bool => $user->ID === 7),
                'elizabeth mitchell',
            )
            ->andReturnUsing(fn(array $author): array => $author);

        (new AuthorResolver())->resolve('elizabeth mitchell');
    }

    public function testFilterCanAddTitleDescriptionAndImage(): void
    {
        $this->oneAuthor();
        Filters\expectApplied('freespoke_search_author')->once()->andReturnUsing(fn(array $author): array => $author + [
            'title' => 'White House Correspondent',
            'description' => 'Elizabeth Troutman Mitchell is the White House correspondent for the Daily Signal.',
            'image' => 'https://static.example.com/MitchellElizabeth-150x150.jpg',
        ]);

        $matches = (new AuthorResolver())->resolve('elizabeth mitchell');

        $this->assertSame([[
            'name' => 'Elizabeth Troutman Mitchell',
            'url' => 'https://example.com/author/elizabeth-mitchell/',
            'image' => 'https://static.example.com/MitchellElizabeth-150x150.jpg',
            'title' => 'White House Correspondent',
            'description' => 'Elizabeth Troutman Mitchell is the White House correspondent for the Daily Signal.',
        ]], $matches);
    }

    public function testBioFromTheFilterIsAcceptedButNotSentToThePage(): void
    {
        $this->oneAuthor();
        Filters\expectApplied('freespoke_search_author')->once()->andReturnUsing(fn(array $author): array => $author + [
            'bio' => 'Mitchell grew up in Winter Park, Florida, and earned her degree at Hillsdale College.',
        ]);

        $matches = (new AuthorResolver())->resolve('elizabeth mitchell');

        $this->assertArrayNotHasKey('bio', $matches[0]);
    }

    public function testFilterIgnoresBlankAndNonStringExtras(): void
    {
        $this->oneAuthor();
        Filters\expectApplied('freespoke_search_author')->once()->andReturnUsing(fn(array $author): array => $author + [
            'title' => '   ',
            'description' => ['not', 'a', 'string'],
            'unknown' => 'dropped',
        ]);

        $matches = (new AuthorResolver())->resolve('elizabeth mitchell');

        $this->assertSame(['name', 'url'], array_keys($matches[0]));
    }

    public function testFilterReturningNullDropsTheAuthorAndFreesTheSlot(): void
    {
        Functions\expect('get_users')->once()->andReturn([
            $this->user(1, 'Alex Mitchell'),
            $this->user(2, 'Bo Mitchell'),
            $this->user(3, 'Cy Mitchell'),
            $this->user(4, 'Di Mitchell'),
        ]);
        Functions\expect('get_author_posts_url')->times(4)->andReturnUsing(fn(int $id) => "https://example.com/author/{$id}/");
        $this->stubNoAvatar();
        Filters\expectApplied('freespoke_search_author')
            ->times(4)
            ->andReturnUsing(fn(array $author, object $user) => $user->ID === 1 ? null : $author);

        $names = array_column((new AuthorResolver())->resolve('mitchell'), 'name');

        $this->assertSame(['Bo Mitchell', 'Cy Mitchell', 'Di Mitchell'], $names);
    }

    public function testFilterCannotBlankTheNameOrUrl(): void
    {
        $this->oneAuthor();
        Filters\expectApplied('freespoke_search_author')->once()->andReturnUsing(fn(array $author): array => ['name' => '', 'url' => ''] + $author);

        $this->assertSame([], (new AuthorResolver())->resolve('elizabeth mitchell'));
    }

    // ------------------------------------------------------------------
    // Author post type as the source of matches
    // ------------------------------------------------------------------

    private function authorPost(int $id, string $title, string $excerpt = '', string $content = ''): object
    {
        return (object) ['ID' => $id, 'post_title' => $title, 'post_excerpt' => $excerpt, 'post_content' => $content];
    }

    private function useAuthorPostType(): void
    {
        Filters\expectApplied('freespoke_search_author_post_type')->once()->andReturn('post-author');
        Functions\expect('get_users')->never();
    }

    public function testMatchesAuthorPostsInsteadOfUsersWhenAPostTypeIsConfigured(): void
    {
        $this->useAuthorPostType();
        Functions\expect('get_posts')
            ->once()
            ->with(\Mockery::on(function (array $args): bool {
                return $args['post_type'] === 'post-author'
                    && $args['post_status'] === 'publish'
                    && $args['s'] === 'troutman'
                    && $args['orderby'] === 'relevance'
                    && $args['posts_per_page'] === AuthorResolver::SEARCH_LIMIT;
            }))
            ->andReturn([$this->authorPost(4136, 'Elizabeth Troutman Mitchell', 'White House correspondent for the Daily Signal.', '<p>Grew up in Winter Park.</p>')]);
        Functions\expect('get_permalink')->once()->andReturn('https://example.com/author/elizabeth-mitchell/');
        Functions\expect('get_the_post_thumbnail_url')->once()->with(\Mockery::any(), 'thumbnail')->andReturn('https://static.example.com/MitchellElizabeth-150x150.jpg');

        $matches = (new AuthorResolver())->resolve('troutman');

        $this->assertSame([[
            'name' => 'Elizabeth Troutman Mitchell',
            'url' => 'https://example.com/author/elizabeth-mitchell/',
            'image' => 'https://static.example.com/MitchellElizabeth-150x150.jpg',
            'description' => 'White House correspondent for the Daily Signal.',
        ]], $matches);
    }

    public function testAuthorPostsMustCarryEveryQueryWordInTheTitle(): void
    {
        $this->useAuthorPostType();
        Functions\expect('get_posts')->once()->andReturn([
            $this->authorPost(1, 'Elizabeth Troutman Mitchell'),
            $this->authorPost(2, 'Elizabeth Smith'),
        ]);
        Functions\expect('get_permalink')->once()->andReturn('https://example.com/author/elizabeth-mitchell/');
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);

        $names = array_column((new AuthorResolver())->resolve('elizabeth mitchell'), 'name');

        $this->assertSame(['Elizabeth Troutman Mitchell'], $names);
    }

    public function testAuthorPostWithoutThumbnailOrExcerptSendsNameAndUrlOnly(): void
    {
        $this->useAuthorPostType();
        Functions\expect('get_posts')->once()->andReturn([$this->authorPost(1, 'Dan Mitchell')]);
        Functions\expect('get_permalink')->once()->andReturn('https://example.com/author/dan-mitchell/');
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);

        $matches = (new AuthorResolver())->resolve('dan mitchell');

        $this->assertSame([['name' => 'Dan Mitchell', 'url' => 'https://example.com/author/dan-mitchell/']], $matches);
    }

    public function testPerAuthorFilterReceivesTheAuthorPostAndTheBioWhenPostsAreTheSource(): void
    {
        $this->useAuthorPostType();
        Functions\expect('get_posts')->once()->andReturn([$this->authorPost(4136, 'Dan Mitchell', '', '<p>Dan writes about tax policy.</p>')]);
        Functions\expect('get_permalink')->once()->andReturn('https://example.com/author/dan-mitchell/');
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        Filters\expectApplied('freespoke_search_author')
            ->once()
            ->with(
                \Mockery::on(fn(array $author): bool => $author['bio'] === 'Dan writes about tax policy.'),
                \Mockery::on(fn(object $source): bool => $source->ID === 4136 && isset($source->post_title)),
                'dan mitchell',
            )
            ->andReturnUsing(fn(array $author): array => $author + ['title' => 'Contributor']);

        $matches = (new AuthorResolver())->resolve('dan mitchell');

        $this->assertSame('Contributor', $matches[0]['title']);
    }

    // ------------------------------------------------------------------
    // List-level filter
    // ------------------------------------------------------------------

    public function testListFilterReceivesTheMatchesAndTheQuery(): void
    {
        $this->oneAuthor();
        Filters\expectApplied('freespoke_search_authors')
            ->once()
            ->with(
                [['name' => 'Elizabeth Troutman Mitchell', 'url' => 'https://example.com/author/elizabeth-mitchell/']],
                'elizabeth mitchell',
            )
            ->andReturnUsing(fn(array $authors): array => $authors);

        (new AuthorResolver())->resolve('elizabeth mitchell');
    }

    public function testListFilterCanReplaceTheMatchesAndIsStillCappedAndCleaned(): void
    {
        $this->oneAuthor();
        Filters\expectApplied('freespoke_search_authors')->once()->andReturnUsing(fn(): array => [
            ['name' => 'A', 'url' => 'https://example.com/a/', 'bio' => 'dropped'],
            ['name' => 'B', 'url' => 'https://example.com/b/'],
            ['name' => '', 'url' => 'https://example.com/blank/'],
            ['name' => 'C', 'url' => 'https://example.com/c/'],
            ['name' => 'D', 'url' => 'https://example.com/d/'],
        ]);

        $matches = (new AuthorResolver())->resolve('elizabeth mitchell');

        $this->assertSame(['A', 'B', 'C'], array_column($matches, 'name'));
        $this->assertArrayNotHasKey('bio', $matches[0]);
    }

    public function testUnsafeUrlsFromTheFiltersAreDropped(): void
    {
        $this->oneAuthor();
        Functions\when('esc_url_raw')->alias(fn(string $url): string => str_starts_with($url, 'https://') ? $url : '');
        Filters\expectApplied('freespoke_search_authors')->once()->andReturn([
            ['name' => 'A', 'url' => 'javascript:alert(1)'],
            ['name' => 'B', 'url' => 'https://example.com/b/', 'image' => 'javascript:alert(1)'],
        ]);

        $this->assertSame([['name' => 'B', 'url' => 'https://example.com/b/']], (new AuthorResolver())->resolve('elizabeth mitchell'));
    }

    public function testListKeepsOneEntryPerUrl(): void
    {
        $this->oneAuthor();
        Filters\expectApplied('freespoke_search_authors')->once()->andReturnUsing(fn(array $authors): array => [
            ...$authors,
            ['name' => 'Liz Mitchell', 'url' => 'https://example.com/author/elizabeth-mitchell/'],
        ]);

        $this->assertSame(['Elizabeth Troutman Mitchell'], array_column((new AuthorResolver())->resolve('elizabeth mitchell'), 'name'));
    }

    public function testListFilterRunsEvenWhenNothingMatchedSoASiteCanSupplyItsOwn(): void
    {
        Functions\expect('get_users')->twice()->andReturn([]);
        Filters\expectApplied('freespoke_search_authors')
            ->once()
            ->with([], 'tax policy')
            ->andReturn([['name' => 'Tax Desk', 'url' => 'https://example.com/desk/tax/']]);

        $this->assertSame([['name' => 'Tax Desk', 'url' => 'https://example.com/desk/tax/']], (new AuthorResolver())->resolve('tax policy'));
    }

    // ------------------------------------------------------------------
    // Post meta mapping for author posts
    // ------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $meta_by_post post ID => meta key => value
     */
    private function postMeta(array $meta_by_post): void
    {
        Functions\when('get_post_meta')->alias(fn(int $id, string $key) => $meta_by_post[$id][$key] ?? '');
    }

    public function testMappedPostMetaSuppliesTitleDescriptionAndBioAndOutranksTheExcerpt(): void
    {
        $this->useAuthorPostType();
        Filters\expectApplied('freespoke_search_author_post_meta')->once()->andReturn([
            'title' => 'job_title',
            'description' => 'short_description',
            'bio' => 'biography',
        ]);
        Functions\expect('get_posts')->once()->andReturn([$this->authorPost(4136, 'Elizabeth Troutman Mitchell', 'Excerpt that the site does not use.')]);
        Functions\expect('get_permalink')->once()->andReturn('https://example.com/author/elizabeth-mitchell/');
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        $this->postMeta([4136 => [
            'job_title' => 'White House Correspondent',
            'short_description' => 'White House correspondent for the Daily Signal.',
            'biography' => 'Grew up in Winter Park.',
        ]]);
        Filters\expectApplied('freespoke_search_author')
            ->once()
            ->with(\Mockery::on(fn(array $author): bool => $author['bio'] === 'Grew up in Winter Park.'), \Mockery::any(), 'troutman')
            ->andReturnUsing(fn(array $author): array => $author);

        $matches = (new AuthorResolver())->resolve('troutman');

        $this->assertSame([[
            'name' => 'Elizabeth Troutman Mitchell',
            'url' => 'https://example.com/author/elizabeth-mitchell/',
            'title' => 'White House Correspondent',
            'description' => 'White House correspondent for the Daily Signal.',
        ]], $matches);
    }

    public function testMappedImageMetaMayBeAnAttachmentIdOrAUrl(): void
    {
        $this->useAuthorPostType();
        Filters\expectApplied('freespoke_search_author_post_meta')->once()->andReturn(['image' => 'headshot']);
        Functions\expect('get_posts')->once()->andReturn([$this->authorPost(1, 'Dan Mitchell'), $this->authorPost(2, 'Amy Mitchell')]);
        Functions\when('get_permalink')->alias(fn(object $post) => "https://example.com/author/{$post->ID}/");
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        $this->postMeta([1 => ['headshot' => '133218'], 2 => ['headshot' => 'https://static.example.com/amy-150x150.jpg']]);
        Functions\expect('wp_get_attachment_image_url')->once()->with(133218, 'thumbnail')->andReturn('https://static.example.com/dan-150x150.jpg');

        $images = array_column((new AuthorResolver())->resolve('mitchell'), 'image');

        $this->assertSame(['https://static.example.com/dan-150x150.jpg', 'https://static.example.com/amy-150x150.jpg'], $images);
    }

    public function testLinkedUsersPostCountOrdersAuthorPostsBeforeTheCap(): void
    {
        $this->useAuthorPostType();
        Filters\expectApplied('freespoke_search_author_post_meta')->once()->andReturn(['user' => 'author_user']);
        Functions\expect('get_posts')->once()->andReturn([
            $this->authorPost(1, 'Amy Mitchell'),
            $this->authorPost(2, 'Bo Mitchell'),
            $this->authorPost(3, 'Dan Mitchell'),
            $this->authorPost(4, 'Elizabeth Troutman Mitchell'),
        ]);
        // Bo's author post credits two users; their counts add up.
        $this->postMeta([1 => ['author_user' => '11'], 2 => ['author_user' => ['12', '13']], 3 => ['author_user' => ''], 4 => ['author_user' => '14']]);
        Functions\when('count_user_posts')->alias(fn(int $id) => [11 => 1, 12 => 2, 13 => 2, 14 => 40][$id] ?? 0);
        Functions\when('get_permalink')->alias(fn(object $post) => "https://example.com/author/{$post->ID}/");
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);

        $names = array_column((new AuthorResolver())->resolve('mitchell'), 'name');

        $this->assertSame(['Elizabeth Troutman Mitchell', 'Bo Mitchell', 'Amy Mitchell'], $names);
    }

    public function testWysiwygMetaIsSentAsPlainText(): void
    {
        $this->useAuthorPostType();
        // ACF stores each field as post meta under its field name; WYSIWYG fields keep their HTML.
        Filters\expectApplied('freespoke_search_author_post_meta')->once()->andReturn(['title' => 'title', 'description' => 'summary', 'bio' => 'bio']);
        Functions\expect('get_posts')->once()->andReturn([$this->authorPost(4136, 'Elizabeth Troutman Mitchell')]);
        Functions\expect('get_permalink')->once()->andReturn('https://example.com/author/elizabeth-mitchell/');
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        $this->postMeta([4136 => [
            'title' => 'White House Correspondent',
            'summary' => "<p>Covers the White House &amp; Congress\r\nfor <em>The Daily Signal</em>.</p>",
            'bio' => "<p>Grew up in Winter Park.</p>\r\n<p>Joined in 2023.</p>",
        ]]);
        Filters\expectApplied('freespoke_search_author')
            ->once()
            ->with(\Mockery::on(fn(array $author): bool => $author['bio'] === 'Grew up in Winter Park. Joined in 2023.'), \Mockery::any(), 'troutman')
            ->andReturnFirstArg();

        $matches = (new AuthorResolver())->resolve('troutman');

        $this->assertSame('Covers the White House & Congress for The Daily Signal.', $matches[0]['description']);
        $this->assertSame('White House Correspondent', $matches[0]['title']);
    }

    public function testLinkedUserMayBeALogin(): void
    {
        $this->useAuthorPostType();
        Filters\expectApplied('freespoke_search_author_post_meta')->once()->andReturn(['user' => 'user_login_temporary']);
        Functions\expect('get_posts')->once()->andReturn([$this->authorPost(1, 'Amy Mitchell'), $this->authorPost(2, 'Dan Mitchell')]);
        $this->postMeta([1 => ['user_login_temporary' => 'amy.mitchell'], 2 => ['user_login_temporary' => 'dan.mitchell']]);
        Functions\when('get_user_by')->alias(fn(string $field, string $login) => $field === 'login'
            ? (object) ['ID' => ['amy.mitchell' => 11, 'dan.mitchell' => 12][$login]]
            : false);
        Functions\when('count_user_posts')->alias(fn(int $id) => [11 => 1, 12 => 30][$id]);
        Functions\when('get_permalink')->alias(fn(object $post) => "https://example.com/author/{$post->ID}/");
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);

        $this->assertSame(['Dan Mitchell', 'Amy Mitchell'], array_column((new AuthorResolver())->resolve('mitchell'), 'name'));
    }
}
