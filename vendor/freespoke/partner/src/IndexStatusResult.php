<?php

declare (strict_types=1);
namespace FreespokeDeps\Freespoke\Partner;

/**
 * Status response for a previously submitted indexing job.
 */
class IndexStatusResult
{
    /** @var string|null */
    public ?string $job_id = '';
    /** @var string|null */
    public ?string $status = '';
    /** @var array */
    public array $error = [];
    /** @var array */
    public array $metadata = [];
    /** @var array */
    public array $result = [];
    /** @var \DateTimeImmutable|null */
    public ?\DateTimeImmutable $create_time = null;
    /** @var \DateTimeImmutable|null */
    public ?\DateTimeImmutable $update_time = null;
}
