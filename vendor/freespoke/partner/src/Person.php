<?php

declare (strict_types=1);
namespace FreespokeDeps\Freespoke\Partner;

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
