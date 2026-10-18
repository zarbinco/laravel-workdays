<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;

final readonly class CutoffResult
{
    public function __construct(
        public string $profile,
        public string $timezone,
        public CarbonImmutable $receivedAt,
        public ?CarbonImmutable $cutoffAt,
        public CarbonImmutable $effectiveBusinessDate,
        public bool $sameDayEligible,
        public string $reason,
    ) {}

    /**
     * @return array{
     *     profile: string,
     *     timezone: string,
     *     received_at: string,
     *     cutoff_at: string|null,
     *     effective_business_date: string,
     *     same_day_eligible: bool,
     *     reason: string
     * }
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'timezone' => $this->timezone,
            'received_at' => $this->receivedAt->format('Y-m-d\TH:i:s.uP'),
            'cutoff_at' => $this->cutoffAt?->format('Y-m-d\TH:i:s.uP'),
            'effective_business_date' => $this->effectiveBusinessDate->toDateString(),
            'same_day_eligible' => $this->sameDayEligible,
            'reason' => $this->reason,
        ];
    }
}
