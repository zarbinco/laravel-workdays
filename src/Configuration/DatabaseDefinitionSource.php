<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Configuration;

final readonly class DatabaseDefinitionSource implements DefinitionSource
{
    public function __construct(private DatabaseDefinitionRepository $repository) {}

    public function open(): WorkdayDefinitions
    {
        return $this->repository->openView();
    }
}
