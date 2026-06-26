<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Calculator;

use Carbon\CarbonImmutable;

final readonly class BusinessDayResult
{
    /**
     * @param array<int, CarbonImmutable> $skippedDates
     */
    public function __construct(
        public CarbonImmutable $startDate,
        public CarbonImmutable $resultDate,
        public int $requestedBusinessDays,
        public int $calendarDays,
        public array $skippedDates,
        public string $profile,
    ) {
    }

    /**
     * @return array{
     *     start_date: string,
     *     result_date: string,
     *     requested_business_days: int,
     *     calendar_days: int,
     *     skipped_dates: array<int, string>,
     *     profile: string
     * }
     */
    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate->toDateString(),
            'result_date' => $this->resultDate->toDateString(),
            'requested_business_days' => $this->requestedBusinessDays,
            'calendar_days' => $this->calendarDays,
            'skipped_dates' => array_map(
                static fn (CarbonImmutable $date): string => $date->toDateString(),
                $this->skippedDates,
            ),
            'profile' => $this->profile,
        ];
    }
}
