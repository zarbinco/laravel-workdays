<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

final readonly class RecurringOccurrence
{
    public function __construct(
        public int $cycleIndex,
        public string $nominalPeriod,
        public ?string $nominalDate,
        public ?string $scheduledDate,
        public string $status,
        public string $reason,
        public ?int $shiftDays,
        public bool $collides = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cycle_index' => $this->cycleIndex,
            'nominal_period' => $this->nominalPeriod,
            'nominal_date' => $this->nominalDate,
            'scheduled_date' => $this->scheduledDate,
            'status' => $this->status,
            'reason' => $this->reason,
            'shift_days' => $this->shiftDays,
            'collides' => $this->collides,
        ];
    }
}
