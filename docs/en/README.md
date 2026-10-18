# Laravel Workdays

[![Tests](https://github.com/zarbinco/laravel-workdays/actions/workflows/tests.yml/badge.svg)](https://github.com/zarbinco/laravel-workdays/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/zarbinco/laravel-workdays.svg?style=flat-square)](https://packagist.org/packages/zarbinco/laravel-workdays)
[![Total Downloads](https://img.shields.io/packagist/dt/zarbinco/laravel-workdays.svg?style=flat-square)](https://packagist.org/packages/zarbinco/laravel-workdays)
[![PHP Version](https://img.shields.io/packagist/php-v/zarbinco/laravel-workdays.svg?style=flat-square)](https://packagist.org/packages/zarbinco/laravel-workdays)
[![License](https://img.shields.io/packagist/l/zarbinco/laravel-workdays.svg?style=flat-square)](../../LICENSE)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-ff2d20?style=flat-square)](https://laravel.com)

Persian document: [../fa/README.md](../fa/README.md)

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
- It does not generate official year-by-year government calendars automatically.
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

## Explaining A Date

Use `explain()` when you need to know why a date is treated as a business day or non-working day:

```php
$info = Workday::profile('iran')->explain('2026-06-25');

$info->isBusinessDay;
$info->isWeekend;
$info->reasons;
$info->toArray();
```

The method returns a `Zarbinco\LaravelWorkdays\Data\DayInfo` value object. Reasons are `Zarbinco\LaravelWorkdays\Data\DayReason` objects for matches such as weekends, recurring Gregorian/Jalali/Hijri holidays, exact custom holidays, and exact extra working days.

Example array output:

```php
[
    'date' => '2026-06-25',
    'profile' => 'iran',
    'is_business_day' => false,
    'is_non_working_day' => true,
    'is_weekend' => true,
    'is_calendar_holiday' => false,
    'is_gregorian_holiday' => false,
    'is_jalali_holiday' => false,
    'is_hijri_holiday' => false,
    'is_custom_holiday' => false,
    'is_extra_working_day' => false,
    'reasons' => [
        [
            'type' => 'weekend',
            'title' => 'Weekend',
            'source' => 'profile',
            'calendar' => null,
            'key' => null,
            'overridden' => false,
            'overridden_by' => null,
        ],
    ],
];
```

`explain()` does not change calculation behavior. Extra working days still win over weekends and holidays; when that happens, other matching reasons can appear with `overridden` set to `true` and `overridden_by` set to `extra_working_day`.

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

## Official Iran Yearly Calendars

The config-based `iran` preset remains recurring and lightweight. Official yearly calendar import is optional, uses local dataset files, and does not silently replace your config.

In short, Iran 1405 is optional/import-only: it is not bundled with the package, not imported automatically, and not enabled by default. To import it, provide a local dataset directory containing PHP files named by Jalali year, for example `1405.php`.

Official yearly calendars are disabled by default:

```php
'iran_official' => [
    'enabled' => false,
    'year' => null,
    'profile' => null,
    'calendar_path' => null,
],
```

The package does not import, seed, activate, or fetch Iran 1405 automatically. A dataset only affects calculations after you explicitly import a local file into database storage.

Use `--calendar-path` for one command run, or set `workdays.iran_official.calendar_path` to the same local directory.

Publish and run the package migrations before importing a yearly dataset:

```bash
php artisan vendor:publish --tag=workdays-migrations
php artisan migrate
```

Preview the import without writing records:

```bash
php artisan workdays:import-iran-calendar 1405 --calendar-path=/absolute/path/to/calendars/iran --dry-run
```

Import into the default `iran` profile, or choose a separate profile:

```bash
php artisan workdays:import-iran-calendar 1405 --calendar-path=/absolute/path/to/calendars/iran
php artisan workdays:import-iran-calendar 1405 --profile=iran-official-1405 --calendar-path=/absolute/path/to/calendars/iran
```

The command writes exact Gregorian `holiday` rows to `workday_special_dates`. It is idempotent, skips existing profile/date titles by default, and only overwrites them with `--force`. The installer never runs this command for you.

Use database or chain storage to calculate with imported dates. The default `iran` profile already exists; custom profile names such as `iran-official-1405` must also be configured before calling `Workday::profile(...)`.

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$info = Workday::profile('iran-official-1405')->explain('2026-03-21');
```

Future years can be added as separate local dataset files. Emergency closures or later government changes are not updated automatically; add manual special dates when official changes occur after a dataset is published.

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

## Business Hours And Half-Days

Day-level methods such as `isBusinessDay()`, `addBusinessDays()`, and `explain()` keep their existing behavior. Business-time methods are opt-in and require `working_hours` on the profile you call them from.

Configure one or more working windows per weekday:

```php
'working_hours' => [
    'Saturday' => [['09:00', '17:00']],
    'Sunday' => [['09:00', '17:00']],
    'Monday' => [['09:00', '17:00']],
    'Tuesday' => [['09:00', '17:00']],
    'Wednesday' => [['09:00', '17:00']],
    'Thursday' => [['09:00', '13:00']],
    'Friday' => [],
],
```

Start times are inclusive and end times are exclusive. For example, `09:00` is business time, but `17:00` is not.

Half-days are just shorter windows. Lunch breaks or split shifts use multiple non-overlapping windows:

```php
'working_hours' => [
    'Monday' => [
        ['09:00', '12:00'],
        ['13:00', '17:00'],
    ],
],
```

Business-time methods respect weekends, recurring holidays, custom holidays, imported official holidays, and extra working days. Holidays close the date unless that same exact date is an extra working day.

If an extra working day falls on a weekday with no configured windows, you can provide fallback hours:

```php
'extra_working_day_hours' => [
    ['09:00', '17:00'],
],
```

The fallback is used only for extra working days whose weekday has no `working_hours` windows. Normal closed weekdays stay closed.

```php
Workday::profile('iran')->isBusinessTime('2026-06-29 10:30');
Workday::profile('iran')->workingWindowsFor('2026-06-29');
Workday::profile('iran')->nextBusinessTime('2026-06-29 08:00');
Workday::profile('iran')->previousBusinessTime('2026-06-29 17:00');
Workday::profile('iran')->addBusinessHours('2026-06-29 16:00', 2);
Workday::profile('iran')->diffBusinessMinutes('2026-06-29 09:00', '2026-06-29 17:00');
```

`previousBusinessTime()` returns the same datetime when it is inside a working window. When the datetime is exactly at a window end, it returns one second before that end, such as `16:59:59` for a `09:00`-`17:00` window.

Business-time calculations use minute precision for add/diff methods. `addBusinessHours()` converts hours to whole minutes, so `1.5` means 90 minutes and values that cannot convert to whole minutes are rejected.

String datetimes are parsed by Carbon using the application/default timezone. `DateTimeInterface` inputs preserve their timezone when converted to `CarbonImmutable`.

## Order Cut-Off Policy

`resolveCutoff()` assigns an order-received timestamp to a business date using the selected profile's calendar and cut-off. Same-day eligibility means that the local date is a business day and the timestamp is strictly before its enabled cut-off. The caller must supply a timestamp; there is no implicit `now()` fallback.

This is a date-assignment decision. The consuming application evaluates stock, capacity, payment approval, reservations, preparation duration, and actual shipping or delivery promises. This API does not calculate warehouse opening, processing start, carrier pickup, dispatch, or notification times.

### Opt-In Profile Configuration

Add a `cutoff` block to the profile in `config/workdays.php`. For example:

```php
'default_profile' => 'warehouse',
'include_start_date' => false,
'max_scan_days' => 3660,
'storage' => ['driver' => 'config'],
'profiles' => [
    'warehouse' => [
        'weekends' => ['Saturday', 'Sunday'],
        'holidays' => [
            'gregorian' => [],
            'jalali' => [],
            'hijri' => [],
        ],
        'custom_holidays' => [],
        'extra_working_days' => [],
        'cutoff' => [
            'timezone' => 'Asia/Tehran',
            'time' => '14:00',
            'weekdays' => [
                'Thursday' => '11:00',
                'Friday' => null,
            ],
        ],
    ],
],
```

`timezone` is required and must be a PHP timezone identifier such as `Asia/Tehran` or `UTC`; a numeric offset is not a policy timezone. `time` is required and must be a zero-padded 24-hour `HH:MM` string from `00:00` through `23:59`. `weekdays` is optional and defaults to an empty array.

Weekday keys follow `WeekdayNormalizer`: ISO integers `1`–`7` (Monday–Sunday), English names or short names, and supported Persian names. For example, `4`, `Thursday`, `thu`, and `پنج‌شنبه` refer to Thursday. Multiple aliases for the same weekday in one policy are rejected.

A missing override inherits `time`. A string replaces it. An explicit `null` disables eligibility on that weekday, including during future-date searches. It does not change `isBusinessDay()`, and an extra working day does not override it. The default config and Iran preset do not enable a cut-off automatically; the API never infers one from closing hours or another profile.

The policy is validated only when `resolveCutoff()` is called. Missing or malformed cut-off configuration throws `Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException`. Existing day/time APIs, `explain()`, and `calculate()` continue to work with an absent or malformed `cutoff` block.

### Usage And Accepted Timestamps

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

// Uses the configured default profile.
$result = Workday::resolveCutoff('2026-06-29 13:59:59.999999');

// Uses this profile's calendar and policy.
$result = Workday::profile('warehouse')->resolveCutoff(
    '2026-06-29T10:29:59.999999Z',
);
```

The method accepts `string|\DateTimeInterface`. `DateTimeInterface` inputs preserve their instant and are converted to the policy timezone without mutating the supplied object or its timezone. All returned date/time objects are `Carbon\CarbonImmutable`.

Supported string formats use Gregorian dates and this exact structure:

| Format | Examples |
|---|---|
| `Y-m-d H:i` | `2026-06-29 13:59` |
| `Y-m-d H:i:s`, optionally followed by a decimal fraction of 1–6 digits | `2026-06-29 13:59:59`, `2026-06-29 13:59:59.123456` |
| `Y-m-d\TH:i` or `Y-m-d\TH:i:s`, optionally with a 1–6 digit seconds fraction, followed by `Z` or `±HH:MM` | `2026-06-29T10:29Z`, `2026-06-29T13:59:59.123456+03:30` |

Date and time components are zero-padded. A fraction requires seconds. Explicit offsets use hours `00`–`23` and minutes `00`–`59`. An ISO string must include `Z` or an offset; a string without a timezone must use the space-separated format.

Space-separated strings are interpreted as local wall-clock times in the policy timezone, even when the application's timezone differs. Offset/`Z` strings identify an instant, which is converted before selecting its local date, weekday, and cut-off. Seconds and microseconds are preserved. Empty or date-only strings, relative expressions such as `now`, `tomorrow`, or `next Monday`, impossible dates, malformed times/offsets, timezone names in input strings, and other formats throw `InvalidArgumentException`; invalid dates are never silently normalized.

### Boundaries, Calendars, And Future Dates

The comparison is always `receivedAt < cutoffAt`. With a `14:00` cut-off:

| Local timestamp | Same-day eligible |
|---|---|
| `13:59:59.999999` | `true` |
| `14:00:00.000000` | `false` |
| `14:00:00.000001` | `false` |

There is no rounding and no inclusive-boundary option. A `00:00` cut-off is valid configuration but gives that date no eligibility window; it cannot qualify today and is skipped as a future candidate.

The existing calendar decides whether a date is a business day. Weekends, recurring Gregorian/Jalali/Hijri holidays, exact custom holidays, and config/database/chain storage keep their existing behavior and precedence. An extra working day can reopen a weekend or holiday, but cannot override a `null` or `00:00` cut-off.

Working hours are independent: a timestamp before warehouse opening or during a split-shift lunch break can qualify. A profile without `working_hours`, or a business date with empty working windows, can qualify too. The cut-off is not clamped to closing time. The merchant chooses a deadline suitable for its operations; this API does not call `nextBusinessTime()` or calculate eligibility through business-minute differences.

If the local date does not qualify, the resolver selects the earliest strictly later local date that is a business day and has an enabled cut-off with a non-empty window. `include_start_date` does not affect this rule. `max_scan_days` is one total future calendar-date budget: today is evaluated separately, `1` permits tomorrow, and a candidate exactly at the limit may succeed. A date beyond the limit is never examined. If no candidate qualifies, a `RuntimeException` reports the profile and search limit; no ineligible fallback date is returned.

Dates advance by local calendar days, and each cut-off is constructed as a local wall-clock time with seconds and microseconds set to zero. Across daylight-saving changes, an ordinary `14:00` cut-off stays at local `14:00` even when the day has 23 or 25 hours. For example, this applies across `America/New_York`'s March 2026 transition. A nonexistent naive input wall time is rejected with `InvalidArgumentException`; a nonexistent configured cut-off wall time, such as `02:30` on `2026-03-08` in that zone, throws `WorkdayConfigurationException`. Repeated fall-back wall times use PHP/Carbon's chosen occurrence; use an explicit-offset input to disambiguate the received instant. The cut-off itself follows PHP/Carbon's occurrence choice for an ambiguous wall time.

### Result And Serialization

The result is an immutable `Zarbinco\LaravelWorkdays\Data\CutoffResult` with these read-only properties:

| Property | Meaning |
|---|---|
| `profile` | Selected profile name (`string`). |
| `timezone` | Resolved policy timezone (`string`). |
| `receivedAt` | Supplied instant in the policy timezone, preserving seconds and microseconds (`CarbonImmutable`). |
| `cutoffAt` | Nominal cut-off on the input's local date (`CarbonImmutable`), or `null` when that weekday is disabled. |
| `effectiveBusinessDate` | Start of the assigned business date in the policy timezone (`CarbonImmutable`). |
| `sameDayEligible` | Whether the assigned date is the input's normalized local date (`bool`). |
| `reason` | Stable code for the current-date decision (`string`). |

`cutoffAt` always describes the input date, even after that deadline has passed. On a holiday it may hold the nominal deadline as diagnostic policy information; that does not make the holiday eligible. `effectiveBusinessDate` is a date assignment: its start time does not mean warehouse opening, pickup, or dispatch. It is normally midnight; when a timezone transition removes midnight, PHP/Carbon uses the first representable start of that date (for example, `01:00` in `America/Santiago` on `2026-09-06`). The cut-off wall time itself is still checked strictly and is rejected if nonexistent.

Reason precedence is:

| Priority | Code | Meaning |
|---|---|---|
| 1 | `non_business_day` | The existing calendar marks the input date non-working. |
| 2 | `cutoff_disabled` | The date is a business day, but its weekday override is `null`. |
| 3 | `before_cutoff` | The date qualifies and the timestamp is strictly before its cut-off. |
| 4 | `cutoff_reached` | The cut-off has been reached or passed, including a `00:00` cut-off. |

`toArray()` exposes every property using stable snake_case keys:

```php
[
    'profile' => 'warehouse',
    'timezone' => 'Asia/Tehran',
    'received_at' => '2026-06-29T13:59:59.999999+03:30',
    'cutoff_at' => '2026-06-29T14:00:00.000000+03:30',
    'effective_business_date' => '2026-06-29',
    'same_day_eligible' => true,
    'reason' => 'before_cutoff',
]
```

Timestamps use `Y-m-d\TH:i:s.uP`, with six microsecond digits and an explicit UTC offset (including `+00:00` for UTC). `effective_business_date` uses `Y-m-d`; booleans remain booleans and a disabled `cutoff_at` remains `null`.

### Laravel Integration Example

In an application service, pass the order's recorded receipt time, for example an existing `DateTimeInterface` model cast:

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$decision = Workday::profile('warehouse')->resolveCutoff($order->received_at);

// These are application-owned fields, not package-provided model attributes.
$order->business_date = $decision->effectiveBusinessDate->toDateString();
$order->cutoff_reason = $decision->reason;
$order->save();
```

Apply the application's stock, capacity, payment, preparation, and shipping checks before promising fulfillment. Reuse the recorded receipt timestamp when reevaluating a decision.

### Manual Verification With A Dedicated Profile

In a local Laravel application, open `php artisan tinker` and configure this isolated example for that session:

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles.cutoff-demo' => [
        'weekends' => ['Saturday', 'Sunday'],
        'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
        'custom_holidays' => ['2026-07-03' => 'Example holiday'],
        'extra_working_days' => ['2026-07-04' => 'Example extra working day'],
        'cutoff' => [
            'timezone' => 'UTC',
            'time' => '14:00',
            'weekdays' => ['Wednesday' => null, 'Thursday' => '11:00'],
        ],
    ],
]);

$calculator = Workday::profile('cutoff-demo');
$calculator->resolveCutoff('2026-06-29 13:59:59')->toArray();
```

Run each timestamp through that same calculator and compare these fields:

| Received timestamp (UTC) | `same_day_eligible` | `effective_business_date` | `reason` |
|---|---|---|---|
| `2026-06-29 13:59:59` | `true` | `2026-06-29` | `before_cutoff` |
| `2026-06-29 14:00:00` | `false` | `2026-06-30` | `cutoff_reached` |
| `2026-06-30 14:00:00` | `false` | `2026-07-02` | `cutoff_reached` |
| `2026-07-01 10:00:00` | `false` | `2026-07-02` | `cutoff_disabled` |
| `2026-07-02 10:59:59` | `true` | `2026-07-02` | `before_cutoff` |
| `2026-07-02 11:00:00` | `false` | `2026-07-04` | `cutoff_reached` |
| `2026-07-03 10:00:00` | `false` | `2026-07-04` | `non_business_day` |
| `2026-07-04 10:00:00` | `true` | `2026-07-04` | `before_cutoff` |

Checklist: verify the first row before cut-off, the second at the exact boundary, the holiday row, and the Saturday extra-working-day row. Also check that Wednesday's `cutoff_at` is `null`, that the holiday's nominal `cutoff_at` does not grant eligibility, and that adding `.999999` before the deadline preserves microseconds. These checks need no working-hours configuration or annual Iran dataset.

## Carbon Macros

Carbon macros are enabled by default for `Carbon\Carbon`, `Carbon\CarbonImmutable`, and naturally for `Illuminate\Support\Carbon`.

The package always registers workday-prefixed macro names when macros are enabled. These are safest for shared projects:

```php
use Carbon\CarbonImmutable;

$date = CarbonImmutable::parse('2026-06-29');

$date->workdayIsBusinessDay('iran');
$date->workdayAddBusinessDays(3, 'iran');
$date->workdayExplain('iran');

$datetime = CarbonImmutable::parse('2026-06-29 10:30');

$datetime->workdayIsBusinessTime('iran');
$datetime->workdayAddBusinessHours(2, 'iran');
$datetime->workdayDiffBusinessMinutesUntil('2026-06-30 12:00', 'iran');
```

Short aliases are also enabled by default where they do not conflict with native Carbon methods or existing macros:

```php
$date->isBusinessDay('iran');
$date->addBusinessDays(3, 'iran');
$date->explainWorkday('iran');
```

Disable macros or short aliases in config when another Carbon macro package owns similar names:

```php
'carbon_macros' => [
    'enabled' => true,
    'short_aliases' => false,
    'override_existing' => false,
],
```

By default, existing macros and native Carbon methods are not overridden. `override_existing` allows replacing existing macros, but native Carbon methods are still skipped.

Date-returning macros return a new date instance and do not mutate the original object. `CarbonImmutable` macros return `CarbonImmutable`; mutable `Carbon` and `Illuminate\Support\Carbon` macros return a new instance of the original class where possible.

## Validation Rules

Validation rules are Laravel-native wrappers around the existing Workday APIs. Use them in Form Requests, Livewire components, controllers, or manual validators.

Each rule accepts an optional profile. If you omit it, the configured default profile is used.

```php
use Zarbinco\LaravelWorkdays\Rules\WorkdayRule;

$request->validate([
    'delivery_date' => [
        'required',
        WorkdayRule::businessDay('iran'),
    ],
    'appointment_at' => [
        'required',
        WorkdayRule::businessTime('iran'),
    ],
    'due_date' => [
        'required',
        WorkdayRule::afterBusinessDays(3, now(), 'iran'),
    ],
]);
```

Livewire usage is the same shape:

```php
$this->validate([
    'deliveryDate' => ['required', WorkdayRule::businessDay('iran')],
]);
```

Available rule factories include:

```php
WorkdayRule::businessDay('iran');
WorkdayRule::nonWorkingDay('iran');
WorkdayRule::weekend('iran');
WorkdayRule::calendarHoliday('iran');
WorkdayRule::customHoliday('iran');
WorkdayRule::extraWorkingDay('iran');
WorkdayRule::notBusinessDay('iran');
WorkdayRule::notWeekend('iran');
WorkdayRule::notCalendarHoliday('iran');
WorkdayRule::businessTime('iran');
WorkdayRule::notBusinessTime('iran');
WorkdayRule::afterBusinessDays(3, now(), 'iran');
WorkdayRule::beforeBusinessDays(3, $deadline, 'iran');
```

Business-time rules require `working_hours` config. Missing working-hours config fails validation with a clear message instead of throwing a raw configuration exception.

Relative business-day rules are inclusive. `afterBusinessDays(3, $from)` means the value must be on or after the date returned by `addBusinessDays($from, 3)`. `beforeBusinessDays(3, $deadline)` means the value must be on or before the date returned by `subBusinessDays($deadline, 3)`.

The rule factory avoids an ambiguous `holiday()` helper. Use `calendarHoliday()`, `customHoliday()`, `nonWorkingDay()`, or `notCalendarHoliday()` depending on the exact package predicate you want.

Override a rule message when needed:

```php
WorkdayRule::businessDay('iran')->message('Please choose a working day.');
```

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
Workday::profile('global')->isBusinessTime($datetime);
Workday::profile('global')->workingWindowsFor($date);
Workday::profile('global')->nextBusinessTime($datetime);
Workday::profile('global')->previousBusinessTime($datetime);
Workday::profile('global')->addBusinessMinutes($datetime, 90);
Workday::profile('global')->addBusinessHours($datetime, 1.5);
Workday::profile('global')->diffBusinessMinutes($startDateTime, $endDateTime);
Workday::profile('global')->diffBusinessHours($startDateTime, $endDateTime);
Workday::profile('global')->addBusinessDays($date, 5);
Workday::profile('global')->subBusinessDays($date, 5);
Workday::profile('global')->nextBusinessDay($date);
Workday::profile('global')->previousBusinessDay($date);
Workday::profile('global')->diffBusinessDays($startDate, $endDate);
Workday::profile('global')->calculate($date, 5);
Workday::profile('warehouse')->resolveCutoff($receivedAt);
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
    'carbon_macros' => [
        'enabled' => true,
        'short_aliases' => true,
        'override_existing' => false,
    ],
    'iran_official' => [
        'enabled' => false,
        'year' => null,
        'profile' => null,
        'calendar_path' => null,
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
            'working_hours' => [
                'Monday' => [['09:00', '17:00']],
            ],
            'extra_working_day_hours' => [
                ['09:00', '17:00'],
            ],
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

Invalid calendar config fails fast with `InvalidArgumentException`. The package validates profile shape, weekend values, storage drivers, recurring holiday calendar keys, recurring `MM-DD` holiday keys, exact Gregorian `Y-m-d` date keys, and `max_scan_days`.

For example, `jallali` is rejected as an unknown calendar key; use `jalali`.

The optional `cutoff` policy is validated separately and only by `resolveCutoff()`, as described in [Order Cut-Off Policy](#order-cut-off-policy).

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

The MIT License (MIT). See [LICENSE](../../LICENSE).
