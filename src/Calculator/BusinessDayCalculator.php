<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Calculator;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Holidays\ConfigHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\HolidayProviderInterface;
use Zarbinco\LaravelWorkdays\Support\DateNormalizer;
use Zarbinco\LaravelWorkdays\Support\WeekdayNormalizer;

final class BusinessDayCalculator
{
    /**
     * @var array<int, int>
     */
    private readonly array $weekendDays;

    private readonly JalaliCalendarAdapter $jalaliCalendar;

    private readonly HijriCalendarAdapter $hijriCalendar;

    private readonly HolidayProviderInterface $holidayProvider;

    /**
     * @param array<string, mixed> $profileConfig
     */
    public function __construct(
        private readonly string $profile,
        private readonly array $profileConfig,
        private readonly bool $includeStartDate = false,
        ?JalaliCalendarAdapter $jalaliCalendar = null,
        ?HijriCalendarAdapter $hijriCalendar = null,
        ?HolidayProviderInterface $holidayProvider = null,
    ) {
        $this->jalaliCalendar = $jalaliCalendar ?? new JalaliCalendarAdapter();
        $this->hijriCalendar = $hijriCalendar ?? new HijriCalendarAdapter();
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
        $date = DateNormalizer::toImmutable($date);

        if ($this->matchesExtraWorkingDay($date)) {
            return true;
        }

        return ! $this->isNonWorkingDate($date);
    }

    public function isHoliday(string|DateTimeInterface $date): bool
    {
        $date = DateNormalizer::toImmutable($date);

        if ($this->matchesExtraWorkingDay($date)) {
            return false;
        }

        return $this->isNonWorkingDate($date);
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
        $date = DateNormalizer::toImmutable($date)->addDay();

        while (! $this->isBusinessDay($date)) {
            $date = $date->addDay();
        }

        return $date;
    }

    public function previousBusinessDay(string|DateTimeInterface $date): CarbonImmutable
    {
        $date = DateNormalizer::toImmutable($date)->subDay();

        while (! $this->isBusinessDay($date)) {
            $date = $date->subDay();
        }

        return $date;
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
            $date = $direction === 1 ? $date->addDay() : $date->subDay();

            if ($this->isBusinessDay($date)) {
                $countedDays++;

                continue;
            }

            $skippedDates[] = $date;
        }

        return ['date' => $date, 'skippedDates' => $skippedDates];
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
