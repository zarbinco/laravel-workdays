# Business dates and explanations

[Documentation](README.md) · [فارسی](../fa/business-days.md)

Use business-date methods for due dates that depend on whole calendar dates, without requiring opening hours. Use `calculate()` when you also need the dates skipped on the way.

Apply the [Config quick start](getting-started.md#a-complete-config-example) in a fresh local session before this example.

## Calculate a date with an audit trail

<!-- example: day-results -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

config()->set('workdays.definitions.profiles.global.custom_holidays', ['2026-11-10' => 'Inventory']);
config()->set('workdays.definitions.profiles.global.holidays', ['gregorian' => ['11-11' => 'Annual closure']]);
config()->set('workdays.definitions.profiles.global.extra_working_days', ['2026-11-14' => 'Extra Saturday']);

$calendar = Workday::profile('global');
echo json_encode($calendar->calculate('2026-11-06', 3)->toArray(), JSON_THROW_ON_ERROR), PHP_EOL;
echo json_encode([
    $calendar->isWeekend('2026-11-14'),
    $calendar->isCalendarHoliday('2026-11-14'),
    $calendar->isCustomHoliday('2026-11-14'),
    $calendar->isHoliday('2026-11-14'),
], JSON_THROW_ON_ERROR), PHP_EOL;
```
```text
{"start_date":"2026-11-06","result_date":"2026-11-13","requested_business_days":3,"calendar_days":7,"skipped_dates":["2026-11-07","2026-11-08","2026-11-10","2026-11-11"],"profile":"global"}
[true,false,false,false]
```

The three counted dates are November 9, 12, and 13. `skipped_dates` lists the two weekend dates and two closures encountered. November 14 is still a Saturday, but its exact extra-working-day entry makes `isHoliday()` false.

## Choose the right predicate

| Method | Meaning |
|---|---|
| `isBusinessDay()` | Eligible calendar date after extra-working-day precedence. |
| `isHoliday()`, `isNonWorkingDay()` | Non-working date: weekend, recurring holiday, or exact custom holiday, unless reopened. |
| `isWeekend()` | Weekend membership alone. |
| `isCalendarHoliday()` | Recurring Gregorian/Jalali/Hijri match, excluding weekend/custom-only closure. |
| `isGregorianHoliday()`, `isJalaliHoliday()`, `isHijriHoliday()` | Match in that recurring calendar alone. |
| `isCustomHoliday()` | Exact Gregorian custom-holiday match. |
| `isExtraWorkingDay()` | Exact Gregorian reopening entry. |

Classification predicates expose matches even when an extra working day wins the final decision. A business date can have no working-time windows; `isBusinessDay()` does not prove that a particular time is open.

## Navigate, add, subtract, and compare

All methods accept a Gregorian `Y-m-d` string or `DateTimeInterface`. Date objects use their own local date and are normalized to start of day; strings are trimmed and checked without accepting relative dates or impossible date normalization. Return dates are immutable.

| Method | Behavior |
|---|---|
| `nextBusinessDay($date)` | First business date strictly after the input. |
| `previousBusinessDay($date)` | First business date strictly before it. |
| `addBusinessDays($date, $days)` | Move forward by a non-negative integer budget. |
| `subBusinessDays($date, $days)` | Move backward by a non-negative integer budget. |
| `diffBusinessDays($start, $end)` | Signed count in the chronological range, including its end and optionally its start. |
| `calculate($date, $days)` | Forward addition with `BusinessDayResult`. |

`include_start_date` defaults to `false`. When true, addition/subtraction counts an eligible starting date as the first day; a closed start appears in `calculate()->skippedDates`. Zero-day movement is identity after date normalization. This setting does not change next/previous methods or delivery transit's strictly future-date counting.

With the quick-start calendar and no additional holidays, `diffBusinessDays('2026-11-06', '2026-11-09')` is `1`; with start inclusion it is `2`. Reversed inputs negate the chronological count. Equal inputs return `0`, or `1` when start inclusion is enabled and that date is a business day.

Next/previous, addition/subtraction, and `calculate()` bound traversal by `max_scan_days`, counting closed calendar dates too. They throw `RuntimeException` rather than return an ineligible fallback. `diffBusinessDays()` counts the complete requested range and does not use this scan bound; choose reasonable application ranges.

## Inspect reasons with explain()

`explain($date)` returns immutable `DayInfo`. Its `toArray()` includes `date`, `profile`, `is_business_day`, `is_non_working_day`, `is_weekend`, `is_calendar_holiday`, the three calendar-specific flags, `is_custom_holiday`, `is_extra_working_day`, and `reasons`.

Each immutable `DayReason` has `type`, nullable `title`, `source`, `calendar`, `key`, boolean `overridden`, and nullable `overriddenBy` (serialized `overridden_by`). Types identify `weekend`, `gregorian_holiday`, `jalali_holiday`, `hijri_holiday`, `custom_holiday`, and `extra_working_day`. A reopened date retains other reasons with `overridden = true` and `overridden_by = extra_working_day`. Explanations do not alter calculations.

## BusinessDayResult

| PHP property | `toArray()` key | Meaning |
|---|---|---|
| `startDate` | `start_date` | Normalized starting date. |
| `resultDate` | `result_date` | Calculated result date. |
| `requestedBusinessDays` | `requested_business_days` | Requested non-negative integer budget. |
| `calendarDays` | `calendar_days` | Calendar-day distance to the result. |
| `skippedDates` | `skipped_dates` | Ordered skipped `CarbonImmutable` dates; serialized as `Y-m-d`. |
| `profile` | `profile` | Selected profile name. |

Use [Working hours](working-hours.md) for exact time consumption, [Configuration](configuration.md#holidays-and-iran-data) for holiday sources, and the [API reference](api-reference.md) for signatures and errors.
