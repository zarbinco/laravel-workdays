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

## Multi-Stage Delivery Planning

`Workday::planDelivery($receivedAt, $plan)` composes existing profile calendars and business-time calculations into an ordered delivery estimate. For example, a store can calculate warehouse preparation, a shared warehouse/carrier handoff, carrier business-date lead time, and final delivery processing.

The estimate is the completion instant of the configured final stage. The consuming store remains responsible for stock, payment approval, capacity, reservations, courier selection, and customer promises. Calling this API does not create or track a physical shipment, book a pickup, or contact a carrier.

The API is opt-in and available on the facade and `WorkdayManager`. It requires no new mandatory profile settings, tables, jobs, or Composer dependencies. Call it on the manager/facade rather than on a selected profile calculator, because one plan can use several profiles.

### Complete Profile And Plan Example

This deterministic UTC example can run in a local Laravel application or a `php artisan tinker` session. Merge these profiles into the application's existing `workdays` configuration; choose real operating calendars and timezones for production.

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$calendar = [
    'weekends' => ['Saturday', 'Sunday'],
    'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
    'custom_holidays' => [],
    'extra_working_days' => [],
];

$profiles = [
    'warehouse' => array_replace($calendar, [
        'working_hours' => array_fill_keys($weekdays, [
            ['09:00', '12:00'],
            ['13:00', '17:00'],
        ]),
        'cutoff' => ['timezone' => 'UTC', 'time' => '14:00'],
    ]),
    'carrier' => array_replace($calendar, [
        'working_hours' => array_fill_keys($weekdays, [['12:00', '18:00']]),
    ]),
    'last_mile' => array_replace($calendar, [
        'working_hours' => array_fill_keys($weekdays, [['09:00', '17:00']]),
    ]),
];

config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles' => array_replace(config('workdays.profiles', []), $profiles),
]);

$plan = [
    'timezone' => 'UTC',
    'profile_timezones' => [
        'warehouse' => 'UTC',
        'carrier' => 'UTC',
        'last_mile' => 'UTC',
    ],
    'cutoff_profile' => 'warehouse',
    'stages' => [
        ['id' => 'preparation', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 120],
        ['id' => 'pickup', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']],
        ['id' => 'transit', 'type' => 'business_days', 'profile' => 'carrier', 'days' => 2],
        ['id' => 'final_delivery', 'type' => 'work', 'profile' => 'last_mile', 'minutes' => 60],
    ],
];

$result = Workday::planDelivery('2026-06-29T13:30:00Z', $plan);
$result->estimatedCompletionAt; // 2026-07-01 16:30:00 UTC
$result->stages;
$result->cutoffResult;
$result->toArray();

// The same API is available without the facade:
app(\Zarbinco\LaravelWorkdays\WorkdayManager::class)
    ->planDelivery('2026-06-29T13:30:00Z', $plan);
```

`timezone`, `profile_timezones`, and a non-empty ordered `stages` list are required. Every referenced profile must already exist in `workdays.profiles` and have an explicit timezone in `profile_timezones`. Use IANA timezone identifiers such as `Asia/Tehran`, `Asia/Dubai`, or `UTC`; numeric offsets are not configuration timezones. Plans support at most 32 stages. IDs must be non-empty and unique; keep them stable so that stored results and stage errors remain understandable.

Only the documented plan and stage keys are supported. Unknown keys, unsupported types, missing fields, unknown profiles, and malformed duration values throw `WorkdayConfigurationException`. `minutes` and `days` must be non-negative PHP integers: `0` is valid, while `'120'`, `120.0`, negative values, and booleans are rejected. A handoff requires an ordered list of exactly two different profiles.

### Stage Semantics

| Type | Required stage keys | Calculation |
|---|---|---|
| `work` | `id`, `type`, `profile`, `minutes` | Convert to the profile timezone, normalize to the next working instant with `nextBusinessTime()`, then consume positive minutes with `addBusinessMinutes()`. Zero minutes still normalizes to an open instant. |
| `handoff` | `id`, `type`, `profiles` | Find the earliest instant at or after the incoming cursor when both profiles are open in their own timezones. Start and completion are the same instant. |
| `business_days` | `id`, `type`, `profile`, `days` | Count exactly this many strictly future business calendar dates in the profile timezone, preserving the local clock time. Zero days preserves the cursor. Working hours are not required. |

Work and handoff reuse existing weekend, holiday, half-day, split-shift, and extra-working-day behavior, including config/database/chain providers and `extra_working_day_hours` fallback. A missing working-hours configuration or an extra working day without usable hours is an error. A stage is never silently skipped.

Business minutes consume working time and inherit the existing whole-minute accounting. Lunch breaks and closed dates do not consume work minutes. Business days advance date labels: they do not consume working hours, normalize to opening time, or simulate physical parcel movement. `include_start_date` does not change this stage's strictly future-date rule. Its result may be outside working hours; a following work or handoff stage performs its own normalization.

Working intervals are half-open: `[start, end)`. Handoff at a shared opening is valid; handoff at a closing is not. A work stage may finish exactly at closing, but the next stage must find its own valid start. For example, preparation finishing at warehouse closing does not imply an immediate pickup. The handoff search compares UTC instants, advances monotonically, and confirms both profiles are open at the candidate; it never assumes that one profile's next opening suits the other.

### Input, Timezones, And Cut-Off Routing

`$receivedAt` accepts `string|\DateTimeInterface`, using the strict absolute formats documented for `resolveCutoff()`: space-separated `Y-m-d H:i[:s[.microseconds]]` for a naive time, or an ISO `T` timestamp with `Z`/`±HH:MM`. Fractions contain 1–6 digits and require seconds. A timezone-aware input preserves its instant; a naive timestamp uses the plan's `timezone`. Date-only, relative, malformed, invalid-date, and nonexistent local wall-clock inputs are rejected. An ambiguous naive time during a repeated clock hour is also rejected by the planner; provide an explicit offset to select its occurrence. Existing `resolveCutoff()` parsing behavior remains unchanged.

The planner converts the current instant into each stage profile's `profile_timezones` entry before calling that profile's existing APIs. It compares instants in UTC and does not infer a profile timezone from Laravel's application timezone. Caller-provided mutable date objects are never mutated; the new result timestamps are `CarbonImmutable` UTC instants. Seconds and microseconds are retained where the existing business-time calculation permits.

For `business_days`, skipped civil dates are not counted. If the requested target local clock time does not exist after a timezone transition, the stage fails explicitly instead of shifting it. Repeated target clock times follow PHP/Carbon's occurrence choice. Configure policies and test transition dates appropriate to the operating timezones.

`cutoff_profile` is optional. Omit the key to start at the received instant without validating or depending on any cutoff configuration. When present, it must name the first stage's profile, that stage must be `work`, and the profile's configured cutoff timezone must exactly match its `profile_timezones` value.

The existing `resolveCutoff()` is called once. If it permits the received business date, planning starts at the original order instant. Otherwise, planning starts at the beginning of its `effectiveBusinessDate` in the cutoff timezone. That date anchor is then normalized by the first work stage to the next actual opening; midnight does not mean warehouse opening. Cutoff is never reapplied to later stages. Existing exclusive boundaries, weekday overrides, holiday precedence, and future-date eligibility-window checks remain in effect. The embedded `CutoffResult` keeps its original local timezone and serialization semantics.

### One Global Horizon

`workdays.max_scan_days` bounds the entire plan. The anchor is the received timestamp's date in the plan timezone, with that date as day zero. A limit of `1` permits the next calendar date; the last permitted date is inclusive. Every stage result is converted back to the plan timezone before its date is checked. Later stages do not receive a fresh horizon, and skipped civil dates do not extend the date-label boundary.

For example, with a received date of June 29 and a limit of `2`, a result on July 1 is permitted, while a result on July 2 fails even if each individual stage could find availability. The planner throws a stage/profile-specific `RuntimeException` when it cannot finish within the limit. It returns no partial successful plan.

### Results And Serialization

`Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult` and `DeliveryStageResult` are immutable result objects.

| Plan property | Meaning |
|---|---|
| `timezone` | Plan input/horizon timezone. |
| `receivedAt` | Original input instant in UTC. |
| `planningStartAt` | Initial cursor after optional cutoff routing, in UTC. |
| `cutoffResult` | The original `CutoffResult`, or `null` when cutoff is omitted. |
| `stages` | Ordered array of `DeliveryStageResult` objects. |
| `estimatedCompletionAt` | The final stage's completion instant in UTC. |

| Stage property | Meaning |
|---|---|
| `id`, `type` | The supplied stable ID and supported stage type. |
| `profiles` | One profile for work/business days, two for handoff. |
| `readyAt` | Incoming UTC cursor. |
| `startedAt` | Actual working/transfer start; the incoming instant for business days. |
| `completedAt` | Completion instant passed to the next stage. |

Every stage satisfies `readyAt <= startedAt <= completedAt`. Handoff and zero-minute work have identical start/completion instants. Zero-day business days have all three instants equal.

`toArray()` uses stable snake_case keys. All new timestamps use `Y-m-d\TH:i:s.uP` with six microsecond digits and `+00:00`; the embedded cutoff result retains Phase 01's policy-local output. The first example produces:

```php
[
    'timezone' => 'UTC',
    'received_at' => '2026-06-29T13:30:00.000000+00:00',
    'planning_start_at' => '2026-06-29T13:30:00.000000+00:00',
    'cutoff_result' => [
        'profile' => 'warehouse',
        'timezone' => 'UTC',
        'received_at' => '2026-06-29T13:30:00.000000+00:00',
        'cutoff_at' => '2026-06-29T14:00:00.000000+00:00',
        'effective_business_date' => '2026-06-29',
        'same_day_eligible' => true,
        'reason' => 'before_cutoff',
    ],
    'stages' => [
        [
            'id' => 'preparation', 'type' => 'work', 'profiles' => ['warehouse'],
            'ready_at' => '2026-06-29T13:30:00.000000+00:00',
            'started_at' => '2026-06-29T13:30:00.000000+00:00',
            'completed_at' => '2026-06-29T15:30:00.000000+00:00',
        ],
        [
            'id' => 'pickup', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier'],
            'ready_at' => '2026-06-29T15:30:00.000000+00:00',
            'started_at' => '2026-06-29T15:30:00.000000+00:00',
            'completed_at' => '2026-06-29T15:30:00.000000+00:00',
        ],
        [
            'id' => 'transit', 'type' => 'business_days', 'profiles' => ['carrier'],
            'ready_at' => '2026-06-29T15:30:00.000000+00:00',
            'started_at' => '2026-06-29T15:30:00.000000+00:00',
            'completed_at' => '2026-07-01T15:30:00.000000+00:00',
        ],
        [
            'id' => 'final_delivery', 'type' => 'work', 'profiles' => ['last_mile'],
            'ready_at' => '2026-07-01T15:30:00.000000+00:00',
            'started_at' => '2026-07-01T15:30:00.000000+00:00',
            'completed_at' => '2026-07-01T16:30:00.000000+00:00',
        ],
    ],
    'estimated_completion_at' => '2026-07-01T16:30:00.000000+00:00',
]
```

### Store Application Integration And Errors

Select the plan explicitly in the application's checkout or fulfillment service, after its stock/payment checks. Use a recorded receipt timestamp so recalculation is repeatable:

```php
$estimate = Workday::planDelivery($order->received_at, $plan);

// Application-owned model fields; this package provides no shipment/order model.
$order->estimated_delivery_completion_at = $estimate->estimatedCompletionAt;
$order->delivery_calculation = $estimate->toArray();
$order->save();
```

Configure the corresponding datetime/JSON casts in the consuming application. Store the applied policy/profile version alongside the calculation if historical reproducibility is needed: changing calendars or policies can change a future recalculation. Recheck application-owned inventory, capacity, payment, and booking requirements before communicating a delivery promise.

| Exception | Typical cause |
|---|---|
| `Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException` | Invalid plan/profile settings, unknown keys/profiles, missing hours, or missing/mismatched requested cutoff. Stage errors include the stage ID or index. |
| `InvalidArgumentException` | Unsupported or invalid received timestamp, including nonexistent or ambiguous naive wall time. |
| `RuntimeException` | Exhausted global horizon, no shared handoff availability, no usable working time, or a nonexistent business-day target wall time. Stage failures include applicable stage/profile context. |

Use the existing database/chain setup and migrations when those holiday providers are selected. Planning adds no database schema or persistent cache, and existing API paths do not activate it.

Capacity, reservations, carrier integrations, automatic courier/address routing, real-time availability, and physical shipment creation/tracking remain application responsibilities. This phase does not add overnight working shifts or one-off date-specific opening-hour schedules. The result describes only completion of the configured final stage; the stage name `final_delivery` does not prove physical arrival.

### Manual Verification Checklist

Use the UTC fixture above with its 14-day horizon. All dates below are in 2026; compare UTC instants, including the ordered stage trace.

| Check | Received time | Preparation | Pickup | Transit completion | Final processing / estimate |
|---|---|---|---|---|---|
| A: before cutoff | June 29, 13:30 | June 29, 13:30–15:30 | June 29, 15:30 | July 1, 15:30 | July 1, 15:30–16:30 |
| B: exact cutoff | June 29, 14:00 | June 30, 09:00–11:00 | June 30, 13:00 | July 2, 13:00 | July 2, 13:00–14:00 |
| C: holiday/weekend | July 2, 14:00 | July 6, 09:00–11:00 | July 6, 13:00 | July 8, 13:00 | July 8, 13:00–14:00 |

Before C, add `['2026-07-03' => 'Example holiday']` to each fixture profile's `custom_holidays`. B's `planningStartAt` is June 30, 00:00 UTC; C's is July 6, 00:00 UTC. Neither anchor is the preparation start.

In B and C, the carrier opens at 12:00 when the warehouse closes for lunch. The half-open warehouse window excludes 12:00, so their first shared instant is 13:00 and final processing ends at 14:00.

- D: use a handoff-only plan without cutoff. Set warehouse timezone to `Asia/Tehran` with Monday–Friday 09:00–17:00, and carrier timezone to `Asia/Dubai` with Monday–Friday 10:00–18:00. Keep the plan timezone UTC. Input `2026-06-29T05:45:00Z` must hand off at `2026-06-29T06:00:00.000000+00:00`.
- E: reset both profile timezones to UTC and use Monday–Friday 09:00–12:00 for warehouse and 13:00–17:00 for carrier. A handoff-only plan must throw within the global horizon.
- F: restore the original UTC fixture and use a handoff-only plan. Input June 29 at 13:00 hands off immediately; 12:00 waits until 13:00 because the warehouse is at lunch; 17:00 rolls to June 30 at 13:00. A 60-minute warehouse work stage starting at 16:00 may complete at 17:00, but its following pickup must also wait until June 30 at 13:00.
- G: add July 4 as an `extra_working_days` date to warehouse and carrier, with `extra_working_day_hours => [['09:00', '17:00']]` on both. Without cutoff, a plan containing 60 warehouse work minutes then handoff, received July 4 at 08:00 UTC, starts at 09:00 and completes both work and handoff at 10:00. Remove the fixture overrides after checking.

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
Workday::planDelivery($receivedAt, $plan);
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
