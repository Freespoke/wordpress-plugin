<?php

declare (strict_types=1);
namespace FreespokeDeps\Freespoke\Partner;

/**
 * Article payload for Partner API indexing.
 */
class Article
{
    /** @var string|null */
    public ?string $url = '';
    /** @var string|null */
    public ?string $title = '';
    /** @var string|null */
    public ?string $description = '';
    /** @var string|null */
    public ?string $content = '';
    /** @var string|null */
    public ?string $image_url = '';
    /** @var string[] */
    public array $keywords = [];
    /** @var \DateTimeInterface */
    public \DateTimeInterface $publish_time;
    /** @var Person[] */
    private array $authors = [];
    /**
     * @param Person ...$authors
     * @return void
     */
    public function setAuthors(Person ...$authors): void
    {
        $this->authors = $authors;
    }
    /**
     * @return Person[]
     */
    public function getAuthors(): array
    {
        return $this->authors;
    }
}
/**
 * Person metadata used for article authors.
 */
class Person
{
    /** @var string|null */
    public ?string $id;
    /** @var string|null */
    public ?string $name;
    /** @var string|null */
    public ?string $url;
    /** @var float|null */
    public ?float $bias;
    /** @var string|null */
    public ?string $twitter_id;
    /** @var string|null */
    public ?string $facebook_id;
}
