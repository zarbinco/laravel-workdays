<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

final readonly class ResolvedProfile
{
    public function __construct(
        public string $profile,
        public string $source,
        public ?string $ruleId,
    ) {}

    /** @return array{profile: string, source: string, rule_id: string|null} */
    public function toArray(): array
    {
        return ['profile' => $this->profile, 'source' => $this->source, 'rule_id' => $this->ruleId];
    }
}
