# Laravel Workdays

`zarbinco/laravel-workdays` is a Laravel-first workday and business-day calculation engine.

It is not a calendar package. Calendars are only intended to resolve holiday definitions into Gregorian dates in later phases. Phase 1 uses `CarbonImmutable` and internal Gregorian `Y-m-d` dates only.

## Installation

Installation will be available after the package is published:

```bash
composer require zarbinco/laravel-workdays
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=workdays-config
```

## Basic Usage

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

Workday::profile('iran')->isBusinessDay('2026-06-24');
Workday::profile('iran')->isHoliday('2026-06-25');
Workday::profile('iran')->isNonWorkingDay('2026-06-25');
Workday::profile('iran')->isWeekend('2026-06-25');
Workday::profile('global')->isCalendarHoliday('2026-12-25');
Workday::profile('global')->isCustomHoliday('2026-06-25');
Workday::profile('global')->isExtraWorkingDay('2026-06-27');
Workday::profile('iran')->addBusinessDays('2026-06-24', 2);
Workday::profile('iran')->subBusinessDays('2026-06-28', 2);
Workday::profile('iran')->nextBusinessDay('2026-06-25');
Workday::profile('iran')->previousBusinessDay('2026-06-28');
Workday::profile('iran')->diffBusinessDays('2026-06-24', '2026-06-28');
```

The configured default profile can be used directly:

```php
Workday::addBusinessDays('2026-06-24', 2);
```

Date-returning methods return `Carbon\CarbonImmutable`.

## Day Predicates

In this package, `isHoliday()` means the date is non-working for the selected profile. It returns `true` for configured weekly closed days, recurring Gregorian calendar holidays, and custom exact-date holidays.

Use the narrower methods when you need a specific reason:

```php
Workday::profile('iran')->isWeekend('2026-06-25');
Workday::profile('global')->isCalendarHoliday('2026-12-25');
Workday::profile('global')->isCustomHoliday('2026-06-25');
Workday::profile('global')->isExtraWorkingDay('2026-06-27');
```

`isNonWorkingDay()` is an alias of `isHoliday()` for callers who prefer less ambiguous wording.

Extra working days override weekends and holidays:

```php
Workday::profile('global')->isWeekend('2026-06-27'); // true
Workday::profile('global')->isExtraWorkingDay('2026-06-27'); // true
Workday::profile('global')->isBusinessDay('2026-06-27'); // true
Workday::profile('global')->isHoliday('2026-06-27'); // false
```

## Profiles

Profiles live in `config/workdays.php`:

```php
'default_profile' => 'iran',

'profiles' => [
    'iran' => [
        'weekends' => ['Thursday', 'Friday'],
        'holidays' => [
            'gregorian' => [],
        ],
        'custom_holidays' => [],
        'extra_working_days' => [],
    ],
],
```

## Weekend Names

Weekends are configured with readable weekday names, not ISO numbers:

```php
'weekends' => ['Thursday', 'Friday'],
'weekends' => ['Saturday', 'Sunday'],
```

Supported inputs include ISO numbers, English full names, English short names, and Persian weekday names:

```php
'Monday', 'mon', 'Thursday', 'thu', 'Sunday', 'sun'
'شنبه', 'یکشنبه', 'یک‌شنبه', 'سه شنبه', 'سه‌شنبه', 'پنجشنبه', 'پنج‌شنبه', 'جمعه'
```

Internally these are normalized to ISO weekday numbers where Monday is `1` and Sunday is `7`.

## Gregorian Holidays

Recurring Gregorian holidays use `MM-DD` keys and apply every year:

```php
'holidays' => [
    'gregorian' => [
        '01-01' => 'New Year',
        '12-25' => 'Christmas',
    ],
],
```

## Custom Holidays

Exact Gregorian holiday dates use `Y-m-d` keys:

```php
'custom_holidays' => [
    '2026-06-25' => 'Company holiday',
],
```

## Extra Working Days

Extra working days use `Y-m-d` keys and override weekends and holidays:

```php
'extra_working_days' => [
    '2026-06-26' => 'Compensation working day',
],
```

## Phase 1 Limitations

- Jalali calendars are not implemented yet.
- Hijri calendars are not implemented yet.
- Database-backed holiday storage is not implemented yet.
- Iran official holiday presets are not implemented yet.
