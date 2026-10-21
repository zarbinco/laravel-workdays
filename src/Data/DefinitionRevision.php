<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

/** Administrative full raw read; calculations use a pinned lazy definition view instead. */
final readonly class DefinitionRevision
{
    public function __construct(public int $revisionId, public array $definitions, public string $contentHash, public array $metadata) {}
}
