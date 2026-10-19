<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

final readonly class RecurringScheduleResult
{
    /** @var list<RecurringOccurrence> */
    public array $occurrences;

    /** @param list<RecurringOccurrence> $occurrences */
    public function __construct(
        public string $profile,
        public string $timezone,
        public string $calendar,
        public string $frequency,
        public int $interval,
        public string $anchorDate,
        public int $requestedCount,
        array $occurrences,
    ) {
        // Detach caller array entries that may contain PHP references to mutable variables.
        $this->occurrences = array_values(array_map(static fn (RecurringOccurrence $occurrence): RecurringOccurrence => $occurrence, $occurrences));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'timezone' => $this->timezone,
            'calendar' => $this->calendar,
            'frequency' => $this->frequency,
            'interval' => $this->interval,
            'anchor_date' => $this->anchorDate,
            'requested_count' => $this->requestedCount,
            'occurrences' => array_map(static fn (RecurringOccurrence $occurrence): array => $occurrence->toArray(), $this->occurrences),
        ];
    }
}
