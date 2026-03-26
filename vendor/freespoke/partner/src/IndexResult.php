<?php

declare (strict_types=1);
namespace FreespokeDeps\Freespoke\Partner;

/**
 * Response data for a successful indexing request.
 */
class IndexResult
{
    /** @var string|null */
    public ?string $job_id = '';
    /** @var string|null */
    public ?string $workflow_id = '';
}
