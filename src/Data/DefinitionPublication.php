<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

/** Provisional when called inside an application-owned transaction, until its outer commit. */
final readonly class DefinitionPublication
{
    public function __construct(public int $revisionId, public bool $changed, public string $contentHash) {}
}
