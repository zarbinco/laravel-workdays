# Laravel Workdays

[![Tests](https://github.com/zarbinco/laravel-workdays/actions/workflows/tests.yml/badge.svg)](https://github.com/zarbinco/laravel-workdays/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/zarbinco/laravel-workdays.svg?style=flat-square)](https://packagist.org/packages/zarbinco/laravel-workdays)
[![Total Downloads](https://img.shields.io/packagist/dt/zarbinco/laravel-workdays.svg?style=flat-square)](https://packagist.org/packages/zarbinco/laravel-workdays)
[![PHP Version](https://img.shields.io/packagist/php-v/zarbinco/laravel-workdays.svg?style=flat-square)](https://packagist.org/packages/zarbinco/laravel-workdays)
[![License](https://img.shields.io/packagist/l/zarbinco/laravel-workdays.svg?style=flat-square)](LICENSE)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-ff2d20?style=flat-square)](https://laravel.com)

`zarbinco/laravel-workdays` is a Laravel-first workday and business-day calculation engine.

It answers questions like "is this date a business day?", "what is the next business day?", and "how many business days are between these dates?" using configurable profiles, weekends, holidays, and exact working-day overrides.

## What This Package Is

- A Laravel package for workday and business-day calculations.
- A profile-based engine for different countries, regions, teams, or companies.
- A config/database-backed holiday definition reader.
- A Gregorian-date calculation engine built around `CarbonImmutable`.
- A package with optional recurring Gregorian, Jalali, and Hijri holiday definitions.

## What This Package Is Not

- It is not a general calendar package.
- It does not generate official year-by-year government calendars.
- It does not include admin UI, routes, controllers, or views.
- It does not call external APIs or update holidays online.
- It does not guarantee that the Iran preset matches every official bridge holiday or government decision.

Calendars are used internally only to resolve recurring holiday definitions against Gregorian calculation dates.

## Installation

```bash
composer require zarbinco/laravel-workdays
```

## Compatibility

This release supports PHP `^8.2` and Laravel 12 and 13 through the Illuminate components declared in `composer.json`.

Laravel's own PHP requirements still apply. The CI matrix tests Laravel 12 on PHP 8.2, 8.3, and 8.4, and Laravel 13 on PHP 8.3 and 8.4.

## Config Publishing

Publish the default config:

```bash
php artisan vendor:publish --tag=workdays-config
```

Or use the installer:

```bash
php artisan workdays:install
```

Install the Iran preset:

```bash
php artisan workdays:install --preset=iran
php artisan workdays:install --persian
```

`--persian` is an alias for `--preset=iran`.

## Basic Usage

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

Workday::profile('iran')->isBusinessDay('2026-06-24');
Workday::profile('iran')->isHoliday('2026-06-25');
Workday::profile('iran')->nextBusinessDay('2026-06-25');
Workday::profile('iran')->addBusinessDays('2026-06-24', 2);
Workday::profile('global')->diffBusinessDays('2026-06-24', '2026-06-28');
```

The configured default profile can be used directly:

```php
Workday::addBusinessDays('2026-06-24', 2);
```

Date-returning methods return `Carbon\CarbonImmutable`.

## Profiles

Profiles live in `config/workdays.php`:

```php
'default_profile' => 'iran',

'include_start_date' => false,

'max_scan_days' => 3660,

'storage' => [
    'driver' => 'config',
],

'profiles' => [
    'iran' => [
        'weekends' => ['Thursday', 'Friday'],
        'holidays' => [
            'gregorian' => [],
            'jalali' => [],
            'hijri' => [],
        ],
        'custom_holidays' => [],
        'extra_working_days' => [],
    ],
],
```

`max_scan_days` protects business-day scans from running forever when a profile has no possible business days. It must be a positive integer.

## Weekend Names

Weekends can be configured with ISO weekday numbers, English names, English short names, or Persian weekday names:

```php
'weekends' => ['Thursday', 'Friday'],
'weekends' => ['Saturday', 'Sunday'],
'weekends' => [6, 7],
```

Supported examples include:

```php
'Monday', 'mon', 'Thursday', 'thu', 'Sunday', 'sun'
'شنبه', 'یکشنبه', 'یک‌شنبه', 'سه شنبه', 'سه‌شنبه', 'پنجشنبه', 'پنج‌شنبه', 'جمعه'
```

Internally these normalize to ISO weekday numbers where Monday is `1` and Sunday is `7`.

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

## Jalali Holidays

Jalali recurring holidays use Jalali `MM-DD` keys and are resolved to Gregorian calculation dates through Verta:

```php
'holidays' => [
    'jalali' => [
        '01-01' => 'Nowruz',
        '01-02' => 'Nowruz Holiday',
    ],
],
```

Use the Jalali-specific predicate when you need that exact source:

```php
Workday::profile('iran')->isJalaliHoliday('2026-03-21');
```

## Hijri Holidays

Hijri recurring holidays use Hijri `MM-DD` keys and are resolved to Gregorian calculation dates through `islamic-network/calendar`:

```php
'holidays' => [
    'hijri' => [
        '01-09' => "Tasu'a",
        '01-10' => 'Ashura',
    ],
],
```

Hijri calculation settings are configurable:

```php
'hijri' => [
    'method' => 'umm_al_qura',
    'adjustment' => 0,
],
```

Supported methods are `mathematical`, `umm_al_qura`, `high_judiciary`, and `diyanet`. Non-zero `adjustment` is supported only with the `mathematical` method.

Hijri dates may differ by method, country, and moon-sighting rules. Use `custom_holidays` for exact official or organization-specific overrides.

## Iran Preset

Install the Iran preset with:

```bash
php artisan workdays:install --preset=iran
```

The preset includes:

- Iran default profile with `['Thursday', 'Friday']` weekends.
- Recurring Jalali holidays such as Nowruz, Nature Day, Islamic Revolution Victory Day, and Oil Nationalization Day.
- Recurring Hijri holidays such as Tasu'a, Ashura, Arbaeen, Eid al-Fitr, Eid al-Adha, and Eid al-Ghadir.
- A global Saturday/Sunday profile with New Year and Christmas.

The Iran preset is useful, but it is not an exact official calendar generator. Government bridge holidays and one-off official decisions are not generated automatically. Add exact Gregorian dates to `custom_holidays` or `extra_working_days` when you need official, company, or government overrides.

## Database Storage Mode

Set the storage driver to `database` to read recurring holiday rules and exact special dates from package tables:

```php
'storage' => [
    'driver' => 'database',
],
```

Publish and run migrations before using database storage:

```bash
php artisan vendor:publish --tag=workdays-migrations
php artisan migrate
```

When the installed Laravel version supports package migration publishing, migration filenames are timestamped at publish time. Older framework fallbacks publish the bundled migration filenames.

The installer can publish config and migrations together:

```bash
php artisan workdays:install --storage=database
```

It does not run migrations automatically.

Database mode does not include admin UI.

## Chain Storage Mode

Set the storage driver to `chain` to combine config and database definitions:

```php
'storage' => [
    'driver' => 'chain',
],
```

Chain mode reads config holidays and database holidays together. Database recurring rules override config recurring rules with the same profile, calendar type, month, and day. Exact holidays and exact working days are merged.

When `storage.driver` is `database` or `chain`, migrations must be published and run.

## Migrations

The package publishes two tables:

- `workday_holiday_rules`: recurring holidays by `profile`, `calendar_type`, `month`, and `day`.
- `workday_special_dates`: exact Gregorian dates by `profile`, `date`, and `type`.

```bash
php artisan vendor:publish --tag=workdays-migrations
php artisan migrate
```

Create recurring database holidays with `WorkdayHolidayRule`:

```php
use Zarbinco\LaravelWorkdays\Models\WorkdayHolidayRule;

WorkdayHolidayRule::create([
    'profile' => 'global',
    'calendar_type' => 'gregorian',
    'month' => 6,
    'day' => 29,
    'title' => 'Database recurring holiday',
    'is_active' => true,
]);
```

Create exact database dates with `WorkdaySpecialDate`:

```php
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;

WorkdaySpecialDate::create([
    'profile' => 'global',
    'date' => '2026-06-29',
    'type' => 'holiday',
    'title' => 'Company holiday',
    'is_active' => true,
]);
```

`type` may be `holiday` or `working_day`.

## Exact Custom Holidays

Exact custom holidays use Gregorian `Y-m-d` keys:

```php
'custom_holidays' => [
    '2026-06-25' => 'Company holiday',
],
```

Keys must be valid Gregorian `Y-m-d` dates. Invalid keys such as `2026-02-31`, `2026-13-01`, or `2026/01/01` throw `InvalidArgumentException`.

## Extra Working Days

Extra working days use Gregorian `Y-m-d` keys and override weekends and holidays:

```php
'extra_working_days' => [
    '2026-06-26' => 'Compensation working day',
],
```

Extra working days always win:

- Working day override wins over weekend.
- Working day override wins over config holiday.
- Working day override wins over database holiday.
- If the same exact date is both `holiday` and `working_day`, `working_day` wins.

## Public API Reference

```php
Workday::profile('global')->isBusinessDay($date);
Workday::profile('global')->isHoliday($date);
Workday::profile('global')->isNonWorkingDay($date);
Workday::profile('global')->isWeekend($date);
Workday::profile('global')->isCalendarHoliday($date);
Workday::profile('global')->isGregorianHoliday($date);
Workday::profile('global')->isJalaliHoliday($date);
Workday::profile('global')->isHijriHoliday($date);
Workday::profile('global')->isCustomHoliday($date);
Workday::profile('global')->isExtraWorkingDay($date);
Workday::profile('global')->addBusinessDays($date, 5);
Workday::profile('global')->subBusinessDays($date, 5);
Workday::profile('global')->nextBusinessDay($date);
Workday::profile('global')->previousBusinessDay($date);
Workday::profile('global')->diffBusinessDays($startDate, $endDate);
Workday::profile('global')->calculate($date, 5);
```

`isHoliday()` means non-working day. It includes weekends, recurring calendar holidays, and exact custom holidays unless an extra working day override exists.

`isCalendarHoliday()` checks recurring Gregorian, Jalali, and Hijri holidays only. It does not include weekends or exact custom holidays.

## Result Object / calculate()

`calculate()` returns `Zarbinco\LaravelWorkdays\Calculator\BusinessDayResult`:

```php
$result = Workday::profile('global')->calculate('2026-06-26', 1);

$result->toArray();
```

Example array:

```php
[
    'start_date' => '2026-06-26',
    'result_date' => '2026-06-29',
    'requested_business_days' => 1,
    'calendar_days' => 3,
    'skipped_dates' => [
        '2026-06-27',
        '2026-06-28',
    ],
    'profile' => 'global',
]
```

## Config Reference

```php
return [
    'default_profile' => 'iran',
    'include_start_date' => false,
    'max_scan_days' => 3660,
    'storage' => [
        'driver' => 'config',
    ],
    'hijri' => [
        'method' => 'umm_al_qura',
        'adjustment' => 0,
    ],
    'profiles' => [
        'profile-name' => [
            'weekends' => ['Saturday', 'Sunday'],
            'holidays' => [
                'gregorian' => [],
                'jalali' => [],
                'hijri' => [],
            ],
            'custom_holidays' => [],
            'extra_working_days' => [],
        ],
    ],
];
```

## Storage Driver Reference

Supported storage drivers:

- `config`: reads recurring holidays, exact custom holidays, and exact extra working days from config.
- `database`: reads recurring holidays and exact special dates from database tables.
- `chain`: combines config and database storage.

The default storage driver is `config`.

## Config Validation

Invalid config fails fast with `InvalidArgumentException`. The package validates profile shape, weekend values, storage drivers, recurring holiday calendar keys, recurring `MM-DD` holiday keys, exact Gregorian `Y-m-d` date keys, and `max_scan_days`.

For example, `jallali` is rejected as an unknown calendar key; use `jalali`.

## Troubleshooting

### Missing Database Tables

If `storage.driver` is `database` or `chain`, publish and run the migrations:

```bash
php artisan vendor:publish --tag=workdays-migrations
php artisan migrate
```

Otherwise the package throws:

```text
Workdays database storage is enabled, but the workday tables are not available. Publish and run the workdays migrations.
```

### Invalid Storage Driver

Supported drivers are `config`, `database`, and `chain`.

Invalid drivers throw:

```text
Unsupported workdays storage driver [redis]. Supported drivers are: config, database, chain.
```

### Invalid Holiday Keys

Recurring holiday keys must be valid `MM-DD` values for their calendar:

- Gregorian: `01-01`
- Jalali: `01-01`
- Hijri: `01-09`

Exact custom holidays and extra working days must use valid Gregorian `Y-m-d` keys.

### No business day found within max_scan_days

If all dates in the scan window are weekends or holidays, methods such as `nextBusinessDay()`, `previousBusinessDay()`, `addBusinessDays()`, and `calculate()` throw:

```text
Unable to resolve a business day within [3660] calendar days for profile [iran]. Check weekends, holidays, extra working days, and max_scan_days config.
```

Check weekends, recurring holidays, exact holidays, extra working days, and `max_scan_days`.

### Hijri Official Date Differences

Hijri dates may differ by method, country, and moon-sighting rules. Use exact Gregorian `custom_holidays` for official or organization-specific dates.

## Testing

```bash
composer validate --strict
composer install
composer test
composer format:test
```

## License

The MIT License (MIT). See [LICENSE](LICENSE).
