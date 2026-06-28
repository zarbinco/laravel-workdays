<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Calculator;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\DayInfo;
use Zarbinco\LaravelWorkdays\Data\DayReason;
use Zarbinco\LaravelWorkdays\Data\TimeWindow;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Holidays\ConfigHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\HolidayProviderInterface;
use Zarbinco\LaravelWorkdays\Support\DateNormalizer;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;
use Zarbinco\LaravelWorkdays\Support\WeekdayNormalizer;
use Zarbinco\LaravelWorkdays\Support\WorkingHours;

final class BusinessDayCalculator
{
    /**
     * @var array<int, int>
     */
    private readonly array $weekendDays;

    private readonly JalaliCalendarAdapter $jalaliCalendar;

    private readonly HijriCalendarAdapter $hijriCalendar;

    private readonly HolidayProviderInterface $holidayProvider;

    private ?WorkingHours $workingHours = null;

    /**
     * @param  array<string, mixed>  $profileConfig
     */
    public function __construct(
        private readonly string $profile,
        private readonly array $profileConfig,
        private readonly bool $includeStartDate = false,
        private readonly int $maxScanDays = 3660,
        ?JalaliCalendarAdapter $jalaliCalendar = null,
        ?HijriCalendarAdapter $hijriCalendar = null,
        ?HolidayProviderInterface $holidayProvider = null,
    ) {
        ProfileConfigValidator::validate($profile, $profileConfig);
        ProfileConfigValidator::validateMaxScanDays($maxScanDays);

        $this->jalaliCalendar = $jalaliCalendar ?? new JalaliCalendarAdapter;
        $this->hijriCalendar = $hijriCalendar ?? new HijriCalendarAdapter;
        $this->holidayProvider = $holidayProvider ?? new ConfigHolidayProvider([$profile => $profileConfig]);

        $weekends = $profileConfig['weekends'] ?? [];

        if (! is_array($weekends)) {
            throw new InvalidArgumentException(sprintf('The weekends config for profile [%s] must be an array.', $profile));
        }

        $this->weekendDays = array_values(array_unique(array_map(
            fn (mixed $weekday): int => $this->normalizeWeekend($weekday),
            $weekends,
        )));

        $this->recurringGregorianHolidays();
        $this->recurringJalaliHolidays();
        $this->recurringHijriHolidays();
    }

    public function isBusinessDay(string|DateTimeInterface $date): bool
    {
        return $this->isBusinessDate(DateNormalizer::toImmutable($date));
    }

    public function isHoliday(string|DateTimeInterface $date): bool
    {
        return $this->isHolidayDate(DateNormalizer::toImmutable($date));
    }

    public function isNonWorkingDay(string|DateTimeInterface $date): bool
    {
        return $this->isHoliday($date);
    }

    public function isWeekend(string|DateTimeInterface $date): bool
    {
        return $this->matchesWeekend(DateNormalizer::toImmutable($date));
    }

    public function isCalendarHoliday(string|DateTimeInterface $date): bool
    {
        return $this->matchesCalendarHoliday(DateNormalizer::toImmutable($date));
    }

    public function isGregorianHoliday(string|DateTimeInterface $date): bool
    {
        return $this->matchesRecurringGregorianHoliday(DateNormalizer::toImmutable($date));
    }

    public function isJalaliHoliday(string|DateTimeInterface $date): bool
    {
        return $this->matchesRecurringJalaliHoliday(DateNormalizer::toImmutable($date));
    }

    public function isHijriHoliday(string|DateTimeInterface $date): bool
    {
        return $this->matchesRecurringHijriHoliday(DateNormalizer::toImmutable($date));
    }

    public function isCustomHoliday(string|DateTimeInterface $date): bool
    {
        return $this->matchesCustomHoliday(DateNormalizer::toImmutable($date));
    }

    public function isExtraWorkingDay(string|DateTimeInterface $date): bool
    {
        return $this->matchesExtraWorkingDay(DateNormalizer::toImmutable($date));
    }

    public function isBusinessTime(string|DateTimeInterface $datetime): bool
    {
        $datetime = $this->toDateTime($datetime);

        foreach ($this->workingWindowsFor($datetime) as $window) {
            $start = $this->windowStart($datetime, $window);
            $end = $this->windowEnd($datetime, $window);

            if ($datetime->greaterThanOrEqualTo($start) && $datetime->lessThan($end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, TimeWindow>
     */
    public function workingWindowsFor(string|DateTimeInterface $date): array
    {
        $date = $this->toDateTime($date);
        $workingHours = $this->workingHours();
        $isExtraWorkingDay = $this->matchesExtraWorkingDay($date);

        if (! $this->isBusinessDate($date)) {
            return [];
        }

        $windows = $workingHours->windowsForIsoWeekday($date->isoWeekday());

        if ($windows !== [] || ! $isExtraWorkingDay) {
            return $windows;
        }

        $fallbackWindows = $workingHours->extraWorkingDayWindows();

        if ($fallbackWindows !== []) {
            return $fallbackWindows;
        }

        throw new WorkdayConfigurationException($workingHours->missingExtraWorkingDayHoursMessage());
    }

    public function nextBusinessTime(string|DateTimeInterface $datetime): CarbonImmutable
    {
        $datetime = $this->toDateTime($datetime);

        if ($this->isBusinessTime($datetime)) {
            return $datetime;
        }

        $date = $datetime->startOfDay();

        for ($scannedDays = 0; $scannedDays <= $this->maxScanDays; $scannedDays++) {
            foreach ($this->workingWindowsFor($date) as $window) {
                $start = $this->windowStart($date, $window);
                $end = $this->windowEnd($date, $window);

                if ($scannedDays > 0 || $datetime->lessThanOrEqualTo($start)) {
                    return $start;
                }

                if ($datetime->lessThan($end)) {
                    return $datetime;
                }
            }

            $date = $date->addDay();
        }

        $this->throwUnableToResolveBusinessTime();
    }

    public function previousBusinessTime(string|DateTimeInterface $datetime): CarbonImmutable
    {
        $datetime = $this->toDateTime($datetime);

        if ($this->isBusinessTime($datetime)) {
            return $datetime;
        }

        $date = $datetime->startOfDay();

        for ($scannedDays = 0; $scannedDays <= $this->maxScanDays; $scannedDays++) {
            $windows = array_reverse($this->workingWindowsFor($date));

            foreach ($windows as $window) {
                $start = $this->windowStart($date, $window);
                $end = $this->windowEnd($date, $window);

                if ($scannedDays > 0 || $datetime->greaterThanOrEqualTo($end)) {
                    return $end->subSecond();
                }

                if ($datetime->greaterThan($start)) {
                    return $datetime;
                }
            }

            $date = $date->subDay();
        }

        $this->throwUnableToResolveBusinessTime();
    }

    public function addBusinessMinutes(string|DateTimeInterface $datetime, int $minutes): CarbonImmutable
    {
        $this->workingHours();

        if ($minutes < 0) {
            throw new InvalidArgumentException('Business minutes must be zero or greater.');
        }

        $datetime = $this->toDateTime($datetime);

        if ($minutes === 0) {
            return $datetime;
        }

        $current = $this->isBusinessTime($datetime) ? $datetime : $this->nextBusinessTime($datetime);
        $remainingMinutes = $minutes;

        while ($remainingMinutes > 0) {
            $windowEnd = $this->currentWindowEnd($current);
            $availableMinutes = $this->diffWholeMinutes($current, $windowEnd);

            if ($availableMinutes >= $remainingMinutes) {
                return $current->addMinutes($remainingMinutes);
            }

            if ($availableMinutes > 0) {
                $remainingMinutes -= $availableMinutes;
            }

            $current = $this->nextBusinessTime($windowEnd);
        }

        return $current;
    }

    public function addBusinessHours(string|DateTimeInterface $datetime, int|float $hours): CarbonImmutable
    {
        if ($hours < 0) {
            throw new InvalidArgumentException('Business hours must be zero or greater.');
        }

        $minutes = $hours * 60;
        $roundedMinutes = (int) round($minutes);

        if (abs($minutes - $roundedMinutes) > 0.000001) {
            throw new InvalidArgumentException('Business hours must convert to whole minutes.');
        }

        return $this->addBusinessMinutes($datetime, $roundedMinutes);
    }

    public function diffBusinessMinutes(string|DateTimeInterface $start, string|DateTimeInterface $end): int
    {
        $this->workingHours();

        $start = $this->toDateTime($start);
        $end = $this->toDateTime($end);

        if ($start->equalTo($end)) {
            return 0;
        }

        if ($start->greaterThan($end)) {
            return -$this->diffBusinessMinutes($end, $start);
        }

        $minutes = 0;
        $date = $start->startOfDay();
        $lastDate = $end->startOfDay();

        while ($date->lessThanOrEqualTo($lastDate)) {
            foreach ($this->workingWindowsFor($date) as $window) {
                $windowStart = $this->windowStart($date, $window);
                $windowEnd = $this->windowEnd($date, $window);
                $segmentStart = $start->greaterThan($windowStart) ? $start : $windowStart;
                $segmentEnd = $end->lessThan($windowEnd) ? $end : $windowEnd;

                if ($segmentEnd->greaterThan($segmentStart)) {
                    $minutes += $this->diffWholeMinutes($segmentStart, $segmentEnd);
                }
            }

            $date = $date->addDay();
        }

        return $minutes;
    }

    public function diffBusinessHours(string|DateTimeInterface $start, string|DateTimeInterface $end): float
    {
        return $this->diffBusinessMinutes($start, $end) / 60;
    }

    public function explain(string|DateTimeInterface $date): DayInfo
    {
        $date = DateNormalizer::toImmutable($date);
        $reasons = [];
        $extraWorkingDay = $this->holidayProvider->extraWorkingDay($this->profile, $date);
        $customHoliday = $this->holidayProvider->customHoliday($this->profile, $date);
        $overridden = $extraWorkingDay !== null;
        $overriddenBy = $overridden ? 'extra_working_day' : null;
        $isWeekend = $this->matchesWeekend($date);
        $gregorianHolidays = $this->recurringGregorianHolidays();
        $gregorianKey = $date->format('m-d');
        $isGregorianHoliday = array_key_exists($gregorianKey, $gregorianHolidays);
        $jalaliHolidays = $this->recurringJalaliHolidays();
        $jalaliKey = $jalaliHolidays === [] ? null : $this->jalaliCalendar->monthDayFromGregorian($date);
        $isJalaliHoliday = $jalaliKey !== null && array_key_exists($jalaliKey, $jalaliHolidays);
        $hijriHolidays = $this->recurringHijriHolidays();
        $hijriKey = $hijriHolidays === [] ? null : $this->hijriCalendar->monthDayFromGregorian($date);
        $isHijriHoliday = $hijriKey !== null && array_key_exists($hijriKey, $hijriHolidays);
        $isCustomHoliday = $customHoliday !== null;
        $isExtraWorkingDay = $extraWorkingDay !== null;
        $isCalendarHoliday = $isGregorianHoliday || $isJalaliHoliday || $isHijriHoliday;

        if ($isWeekend) {
            $reasons[] = new DayReason(
                type: 'weekend',
                title: 'Weekend',
                source: 'profile',
                overridden: $overridden,
                overriddenBy: $overriddenBy,
            );
        }

        if ($isGregorianHoliday) {
            $reasons[] = $this->recurringHolidayReason('gregorian_holiday', 'gregorian', $gregorianKey, $gregorianHolidays[$gregorianKey], $overridden, $overriddenBy);
        }

        if ($isJalaliHoliday) {
            $reasons[] = $this->recurringHolidayReason('jalali_holiday', 'jalali', $jalaliKey, $jalaliHolidays[$jalaliKey], $overridden, $overriddenBy);
        }

        if ($isHijriHoliday) {
            $reasons[] = $this->recurringHolidayReason('hijri_holiday', 'hijri', $hijriKey, $hijriHolidays[$hijriKey], $overridden, $overriddenBy);
        }

        if ($customHoliday !== null) {
            $reasons[] = new DayReason(
                type: 'custom_holiday',
                title: $customHoliday->name,
                source: $customHoliday->source,
                key: $date->toDateString(),
                overridden: $overridden,
                overriddenBy: $overriddenBy,
            );
        }

        if ($extraWorkingDay !== null) {
            $reasons[] = new DayReason(
                type: 'extra_working_day',
                title: $extraWorkingDay->name,
                source: $extraWorkingDay->source,
                key: $date->toDateString(),
            );
        }

        return new DayInfo(
            date: $date,
            profile: $this->profile,
            isBusinessDay: $this->isBusinessDate($date),
            isNonWorkingDay: $this->isHolidayDate($date),
            isWeekend: $isWeekend,
            isCalendarHoliday: $isCalendarHoliday,
            isGregorianHoliday: $isGregorianHoliday,
            isJalaliHoliday: $isJalaliHoliday,
            isHijriHoliday: $isHijriHoliday,
            isCustomHoliday: $isCustomHoliday,
            isExtraWorkingDay: $isExtraWorkingDay,
            reasons: $reasons,
        );
    }

    public function addBusinessDays(string|DateTimeInterface $date, int $days): CarbonImmutable
    {
        return $this->moveBusinessDays($date, $days, 1)['date'];
    }

    public function subBusinessDays(string|DateTimeInterface $date, int $days): CarbonImmutable
    {
        return $this->moveBusinessDays($date, $days, -1)['date'];
    }

    public function nextBusinessDay(string|DateTimeInterface $date): CarbonImmutable
    {
        $date = DateNormalizer::toImmutable($date);

        for ($scannedDays = 0; $scannedDays < $this->maxScanDays; $scannedDays++) {
            $date = $date->addDay();

            if ($this->isBusinessDay($date)) {
                return $date;
            }
        }

        $this->throwUnableToResolveBusinessDay();
    }

    public function previousBusinessDay(string|DateTimeInterface $date): CarbonImmutable
    {
        $date = DateNormalizer::toImmutable($date);

        for ($scannedDays = 0; $scannedDays < $this->maxScanDays; $scannedDays++) {
            $date = $date->subDay();

            if ($this->isBusinessDay($date)) {
                return $date;
            }
        }

        $this->throwUnableToResolveBusinessDay();
    }

    public function diffBusinessDays(string|DateTimeInterface $startDate, string|DateTimeInterface $endDate): int
    {
        $startDate = DateNormalizer::toImmutable($startDate);
        $endDate = DateNormalizer::toImmutable($endDate);

        if ($startDate->equalTo($endDate)) {
            return $this->includeStartDate && $this->isBusinessDay($startDate) ? 1 : 0;
        }

        if ($startDate->greaterThan($endDate)) {
            return -$this->countBusinessDaysForward($endDate, $startDate);
        }

        return $this->countBusinessDaysForward($startDate, $endDate);
    }

    public function calculate(string|DateTimeInterface $date, int $businessDays): BusinessDayResult
    {
        $startDate = DateNormalizer::toImmutable($date);
        $result = $this->moveBusinessDays($startDate, $businessDays, 1);

        return new BusinessDayResult(
            startDate: $startDate,
            resultDate: $result['date'],
            requestedBusinessDays: $businessDays,
            calendarDays: (int) $startDate->diffInDays($result['date']),
            skippedDates: $result['skippedDates'],
            profile: $this->profile,
        );
    }

    private function matchesWeekend(CarbonImmutable $date): bool
    {
        return in_array($date->isoWeekday(), $this->weekendDays, true);
    }

    private function toDateTime(string|DateTimeInterface $datetime): CarbonImmutable
    {
        if ($datetime instanceof DateTimeInterface) {
            return CarbonImmutable::instance($datetime);
        }

        $datetime = trim($datetime);

        if ($datetime === '') {
            throw new InvalidArgumentException('Date/time cannot be empty. Expected a Gregorian date/time string.');
        }

        try {
            return CarbonImmutable::parse($datetime);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(sprintf('Invalid date/time [%s]. Expected a Gregorian date/time string.', $datetime), previous: $exception);
        }
    }

    private function isBusinessDate(CarbonImmutable $date): bool
    {
        if ($this->matchesExtraWorkingDay($date)) {
            return true;
        }

        return ! $this->isNonWorkingDate($date);
    }

    private function isHolidayDate(CarbonImmutable $date): bool
    {
        if ($this->matchesExtraWorkingDay($date)) {
            return false;
        }

        return $this->isNonWorkingDate($date);
    }

    private function isNonWorkingDate(CarbonImmutable $date): bool
    {
        return $this->matchesWeekend($date)
            || $this->matchesCustomHoliday($date)
            || $this->matchesCalendarHoliday($date);
    }

    private function matchesCustomHoliday(CarbonImmutable $date): bool
    {
        return $this->holidayProvider->hasCustomHoliday($this->profile, $date);
    }

    private function matchesRecurringGregorianHoliday(CarbonImmutable $date): bool
    {
        return array_key_exists($date->format('m-d'), $this->recurringGregorianHolidays());
    }

    private function matchesRecurringJalaliHoliday(CarbonImmutable $date): bool
    {
        $jalaliHolidays = $this->recurringJalaliHolidays();

        if ($jalaliHolidays === []) {
            return false;
        }

        return array_key_exists($this->jalaliCalendar->monthDayFromGregorian($date), $jalaliHolidays);
    }

    private function matchesRecurringHijriHoliday(CarbonImmutable $date): bool
    {
        $hijriHolidays = $this->recurringHijriHolidays();

        if ($hijriHolidays === []) {
            return false;
        }

        return array_key_exists($this->hijriCalendar->monthDayFromGregorian($date), $hijriHolidays);
    }

    private function matchesCalendarHoliday(CarbonImmutable $date): bool
    {
        return $this->matchesRecurringGregorianHoliday($date)
            || $this->matchesRecurringJalaliHoliday($date)
            || $this->matchesRecurringHijriHoliday($date);
    }

    /**
     * @return array<string, mixed>
     */
    private function recurringGregorianHolidays(): array
    {
        return $this->recurringHolidays('gregorian');
    }

    /**
     * @return array<string, mixed>
     */
    private function recurringJalaliHolidays(): array
    {
        return $this->recurringHolidays('jalali');
    }

    /**
     * @return array<string, mixed>
     */
    private function recurringHijriHolidays(): array
    {
        return $this->recurringHolidays('hijri');
    }

    /**
     * @return array<string, mixed>
     */
    private function recurringHolidays(string $calendar): array
    {
        return $this->holidayProvider->recurringHolidays($this->profile, $calendar);
    }

    private function matchesExtraWorkingDay(CarbonImmutable $date): bool
    {
        return $this->holidayProvider->hasExtraWorkingDay($this->profile, $date);
    }

    private function workingHours(): WorkingHours
    {
        return $this->workingHours ??= WorkingHours::fromProfileConfig($this->profile, $this->profileConfig);
    }

    private function windowStart(CarbonImmutable $date, TimeWindow $window): CarbonImmutable
    {
        return $date->startOfDay()->addMinutes($window->startMinutes());
    }

    private function windowEnd(CarbonImmutable $date, TimeWindow $window): CarbonImmutable
    {
        return $date->startOfDay()->addMinutes($window->endMinutes());
    }

    private function currentWindowEnd(CarbonImmutable $datetime): CarbonImmutable
    {
        foreach ($this->workingWindowsFor($datetime) as $window) {
            $start = $this->windowStart($datetime, $window);
            $end = $this->windowEnd($datetime, $window);

            if ($datetime->greaterThanOrEqualTo($start) && $datetime->lessThan($end)) {
                return $end;
            }
        }

        return $this->windowEnd($datetime, $this->workingWindowsFor($datetime)[0]);
    }

    private function diffWholeMinutes(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return intdiv(max(0, $end->getTimestamp() - $start->getTimestamp()), 60);
    }

    private function recurringHolidayReason(
        string $type,
        string $calendar,
        string $key,
        mixed $title,
        bool $overridden,
        ?string $overriddenBy,
    ): DayReason {
        return new DayReason(
            type: $type,
            title: is_string($title) ? $title : null,
            source: 'holiday_provider',
            calendar: $calendar,
            key: $key,
            overridden: $overridden,
            overriddenBy: $overriddenBy,
        );
    }

    private function normalizeWeekend(mixed $weekday): int
    {
        if (! is_int($weekday) && ! is_string($weekday)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid weekend value for profile [%s]. Expected an integer ISO weekday or weekday name string.',
                $this->profile,
            ));
        }

        return WeekdayNormalizer::toIso($weekday);
    }

    /**
     * @return array{date: CarbonImmutable, skippedDates: array<int, CarbonImmutable>}
     */
    private function moveBusinessDays(string|DateTimeInterface $date, int $days, int $direction): array
    {
        if ($days < 0) {
            throw new InvalidArgumentException('Business days must be zero or greater. Use the opposite direction method for backwards calculations.');
        }

        if (! in_array($direction, [-1, 1], true)) {
            throw new InvalidArgumentException('Business day movement direction must be either -1 or 1.');
        }

        $date = DateNormalizer::toImmutable($date);
        $skippedDates = [];

        if ($days === 0) {
            return ['date' => $date, 'skippedDates' => $skippedDates];
        }

        $countedDays = 0;
        $scannedDays = 0;

        if ($this->includeStartDate) {
            if ($this->isBusinessDay($date)) {
                $countedDays++;
            } else {
                $skippedDates[] = $date;
            }

            if ($countedDays >= $days) {
                return ['date' => $date, 'skippedDates' => $skippedDates];
            }
        }

        while ($countedDays < $days) {
            if ($scannedDays >= $this->maxScanDays) {
                $this->throwUnableToResolveBusinessDay();
            }

            $date = $direction === 1 ? $date->addDay() : $date->subDay();
            $scannedDays++;

            if ($this->isBusinessDay($date)) {
                $countedDays++;

                continue;
            }

            $skippedDates[] = $date;
        }

        return ['date' => $date, 'skippedDates' => $skippedDates];
    }

    private function throwUnableToResolveBusinessDay(): never
    {
        throw new RuntimeException(sprintf(
            'Unable to resolve a business day within [%d] calendar days for profile [%s]. Check weekends, holidays, extra working days, and max_scan_days config.',
            $this->maxScanDays,
            $this->profile,
        ));
    }

    private function throwUnableToResolveBusinessTime(): never
    {
        throw new RuntimeException(sprintf(
            'Unable to resolve a business time within [%d] calendar days for profile [%s]. Check working_hours, holidays, extra working days, and max_scan_days config.',
            $this->maxScanDays,
            $this->profile,
        ));
    }

    private function countBusinessDaysForward(CarbonImmutable $startDate, CarbonImmutable $endDate): int
    {
        $businessDays = 0;
        $date = $startDate;

        if ($this->includeStartDate && $this->isBusinessDay($date)) {
            $businessDays++;
        }

        while ($date->lessThan($endDate)) {
            $date = $date->addDay();

            if ($this->isBusinessDay($date)) {
                $businessDays++;
            }
        }

        return $businessDays;
    }
}
