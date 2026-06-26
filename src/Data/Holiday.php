<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;

final readonly class Holiday
{
    public function __construct(
        public CarbonImmutable $date,
        public string $name,
        public string $source = 'custom',
    ) {
    }
}
