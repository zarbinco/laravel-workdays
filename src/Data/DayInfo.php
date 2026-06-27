<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;

final readonly class DayInfo
{
    /**
     * @param  array<int, DayReason>  $reasons
     */
    public function __construct(
        public CarbonImmutable $date,
        public string $profile,
        public bool $isBusinessDay,
        public bool $isNonWorkingDay,
        public bool $isWeekend,
        public bool $isCalendarHoliday,
        public bool $isGregorianHoliday,
        public bool $isJalaliHoliday,
        public bool $isHijriHoliday,
        public bool $isCustomHoliday,
        public bool $isExtraWorkingDay,
        public array $reasons = [],
    ) {}

    /**
     * @return array{
     *     date: string,
     *     profile: string,
     *     is_business_day: bool,
     *     is_non_working_day: bool,
     *     is_weekend: bool,
     *     is_calendar_holiday: bool,
     *     is_gregorian_holiday: bool,
     *     is_jalali_holiday: bool,
     *     is_hijri_holiday: bool,
     *     is_custom_holiday: bool,
     *     is_extra_working_day: bool,
     *     reasons: array<int, array{type: string, title: string|null, source: string|null, calendar: string|null, key: string|null, overridden: bool, overridden_by: string|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'profile' => $this->profile,
            'is_business_day' => $this->isBusinessDay,
            'is_non_working_day' => $this->isNonWorkingDay,
            'is_weekend' => $this->isWeekend,
            'is_calendar_holiday' => $this->isCalendarHoliday,
            'is_gregorian_holiday' => $this->isGregorianHoliday,
            'is_jalali_holiday' => $this->isJalaliHoliday,
            'is_hijri_holiday' => $this->isHijriHoliday,
            'is_custom_holiday' => $this->isCustomHoliday,
            'is_extra_working_day' => $this->isExtraWorkingDay,
            'reasons' => array_map(
                static fn (DayReason $reason): array => $reason->toArray(),
                $this->reasons,
            ),
        ];
    }
}
