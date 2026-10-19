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

## Profile Inheritance And Context Routing

Shared profiles avoid copying calendars and schedules for every warehouse, seller, or carrier. A child profile can inherit a common definition and replace only local differences. Separately, application-supplied context can select one configured profile through explicit routing rules. Neither feature discovers warehouses, sellers, carriers, regions, or timezones from application models or external services.

Existing `Workday::profile($name)` calls support inherited profiles. The new `Workday::resolveProfile($context)` returns an immutable selection; `Workday::profileFor($context)` applies the same selection rules and returns the existing `BusinessDayCalculator`. Both methods are available on the facade and `WorkdayManager`. Routing is opt-in, and shipped configuration does not enable it automatically.

### Complete E-Commerce Configuration

This dedicated local `php artisan tinker` fixture defines the profiles and rules used below. In an application, put the equivalent definitions in `config/workdays.php`. It uses config storage and creates no database rows, bookings, reservations, or notifications. Preserve the session's original configuration for restoration.

```php
use Carbon\CarbonImmutable;
use Zarbinco\LaravelWorkdays\Facades\Workday;

$routingOriginalConfig = config('workdays');
$routingWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$routingProfiles = [
    'route_base' => [
        'weekends' => ['Saturday', 'Sunday'],
        'holidays' => ['gregorian' => ['01-01' => 'New Year'], 'jalali' => [], 'hijri' => []],
        'custom_holidays' => [],
        'extra_working_days' => [],
        'working_hours' => array_fill_keys($routingWeekdays, [['09:00', '17:00']]),
        'cutoff' => [
            'timezone' => 'Asia/Tehran',
            'time' => '14:00',
            'weekdays' => ['Friday' => null],
        ],
    ],
    'route_regional' => [
        'extends' => 'route_base',
        'custom_holidays' => ['2026-11-04' => 'Regional closure'],
    ],
    'route_tehran' => [
        'extends' => 'route_regional',
        'working_hours' => ['thu' => [['09:00', '12:00']]],
        'special_working_hours' => ['2026-11-10' => [['10:00', '13:00']]],
        'cutoff' => ['time' => '13:00'],
    ],
    'route_isfahan' => [
        'extends' => 'route_base',
        'custom_holidays' => ['2026-11-12' => 'Local closure'],
    ],
    'route_seller' => ['extends' => 'route_base', 'weekends' => []],
    'route_carrier' => [
        'extends' => 'route_base',
        'working_hours' => array_fill_keys($routingWeekdays, [['12:00', '18:00']]),
    ],
    'route_night_base' => [
        'extends' => 'route_base',
        'allow_overnight_working_hours' => true,
        'working_hours' => [
            'Monday' => [['22:00', '06:00']],
            'Tuesday' => [], 'Wednesday' => [], 'Thursday' => [], 'Friday' => [],
        ],
    ],
    'route_night' => ['extends' => 'route_night_base'],
];

config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles' => array_replace(config('workdays.profiles', []), $routingProfiles),
    'workdays.profile_routing' => [
        'fallback_profile' => 'route_base',
        'rules' => [
            ['id' => 'general_warehouse', 'match' => ['kind' => 'warehouse'], 'profile' => 'route_base'],
            ['id' => 'tehran_region', 'match' => ['kind' => 'warehouse', 'region' => 'tehran'], 'profile' => 'route_tehran'],
            ['id' => 'tehran_warehouse', 'match' => ['kind' => 'warehouse', 'region' => 'tehran', 'warehouse_id' => 42], 'profile' => 'route_tehran'],
            ['id' => 'seller_calendar', 'match' => ['kind' => 'seller', 'seller_id' => 7], 'profile' => 'route_seller'],
            ['id' => 'carrier_express', 'match' => ['kind' => 'carrier', 'carrier' => 'express'], 'profile' => 'route_carrier'],
        ],
    ],
]);

$warehouseContext = ['kind' => 'warehouse', 'warehouse_id' => 42, 'region' => 'tehran'];
$warehouse = Workday::resolveProfile($warehouseContext);
$warehouse->toArray();
// ['profile' => 'route_tehran', 'source' => 'rule', 'rule_id' => 'tehran_warehouse']

$selectedCalculator = Workday::profileFor($warehouseContext);
$directCalculator = Workday::profile($warehouse->profile);
$local = static fn (string $value): CarbonImmutable => CarbonImmutable::parse($value, 'Asia/Tehran');

$selectedCalculator->isHoliday($local('2026-01-01')); // true: inherited config holiday.
$selectedCalculator->workingWindowsFor($local('2026-11-05')); // Thursday 09:00–12:00.
Workday::resolveProfile(['kind' => 'seller', 'seller_id' => 7])->profile; // route_seller.
Workday::resolveProfile(['kind' => 'carrier', 'carrier' => 'express'])->profile; // route_carrier.

// The same selection API without the facade:
app(\Zarbinco\LaravelWorkdays\WorkdayManager::class)->resolveProfile($warehouseContext);
```

### Inheritance And Exact Merge Rules

`extends` must be a non-empty string naming an existing profile. Multi-level chains are supported, with at most 16 profiles including the selected child and root. Missing parents, invalid definitions, self-reference, cycles, and excessive depth fail with useful profile/chain context. Only the selected profile and necessary ancestors are resolved; unrelated profiles are not eagerly resolved. Global Laravel configuration is not changed.

An omitted child key inherits its parent. An explicit value follows the section-specific rules below; generic recursive array merging is not used.

| Section | Child override behavior |
|---|---|
| `weekends` | Replace the complete list; `[]` means no weekend days. |
| `holidays` | Merge by calendar, then holiday key. An empty calendar map clears that calendar; `holidays => []` clears all inherited recurring calendars. Existing supported `null` labels remain labels, not deletions. |
| `custom_holidays`, `extra_working_days`, `special_working_hours` | Merge by exact Gregorian date. Replace the complete value for a supplied date; do not merge its window-list elements. An empty whole section clears inherited entries. A special date with `[]` still means no new shifts. |
| `working_hours` | Merge by normalized ISO weekday. Each child weekday replaces the complete inherited window list; an empty weekday clears its weekly windows. An empty whole section clears all inherited weekly hours. Existing extra-working-day fallback still applies where eligible. |
| `extra_working_day_hours` | Replace the entire window list, including an explicit empty list. |
| `seasonal_working_hours` | Replace the entire ordered list; `[]` clears inherited seasons. Phase 04 season validation still applies. |
| `allow_overnight_working_hours` | Inherit when omitted; otherwise replace the strict boolean. Disabling it while inherited windows still require overnight support is an error. `false` alone retains the Microfix 01 legacy path. |
| `cutoff` | Merge supported policy keys. Inherit omitted `timezone`/`time`; merge `weekdays` by normalized weekday, preserving explicit `null`. An empty `cutoff.weekdays` map clears inherited weekday overrides. |
| Other existing profile fields | Inherit omitted fields; a supplied child value replaces the entire value. Arbitrary indexed arrays are never merged recursively. |

For example, a parent `Monday` and child integer key `1` identify the same weekday. The child replaces Monday's windows, even when it uses a different accepted alias. Duplicate normalized aliases within one layer are rejected; the same rule applies to cutoff weekday maps when the cutoff policy is used. Empty weekly windows close an ordinary date, but an explicitly reopened extra working day can still use `extra_working_day_hours`. Only exact-date or explicit seasonal weekday empty overrides suppress that fallback, preserving Phase 04 precedence. Hours still obey existing validation after merging. Cutoff validation stays lazy: malformed cutoff configuration does not become a new dependency of ordinary day/time calls.

Inheritance does not inherit top-level `default_profile`, storage settings, or timezone decisions. It preserves the selected child's original name in results, explanations, and database lookups. The fixture chain `route_base → route_regional → route_tehran` inherits the base holiday, regional closure, and all unmodified weekdays while replacing Thursday and cutoff time.

### Strict Routing, Specificity, And Fallback

`profile_routing` must contain exactly `fallback_profile` and an ordered `rules` list, which may be empty. Fallback and all rule targets must name configured profiles. Each rule has exactly `id`, `match`, and `profile`; IDs are unique non-empty strings, and `match` is a non-empty associative map.

Match/context keys are application-defined ASCII identifiers matching `[A-Za-z_][A-Za-z0-9_]*`; they are not limited to the example fields. Values must be integers or strings other than `''`. Booleans, floats, arrays, objects, and `null` are rejected. Empty context `[]` is valid and selects fallback. All supplied context fields are validated, including fields unused by a rule.

A rule matches only when every configured field exists and is strictly equal. Extra context fields are allowed, but partial matches do not qualify. `42 !== '42'`, and `'tehran' !== 'Tehran'`; no trimming, case folding, casting, or transliteration occurs, and whitespace in string values is preserved. There are no wildcard, regex, callable, or executable-expression conditions.

When several rules match, the rule with the most match fields wins. Equal specificity uses configuration order: the first rule wins. No match uses the explicit `fallback_profile`, not `default_profile`. In the fixture, all three warehouse rules match the full Tehran context, so the three-field `tehran_warehouse` wins even though it appears later. Changing `warehouse_id` to the string `'42'` makes that rule fail and selects the two-field `tehran_region` instead.

Missing or malformed routing configuration throws `WorkdayConfigurationException` only when the routing API is explicitly used. Invalid supplied context throws `InvalidArgumentException`. Inheritance hierarchy failures use `WorkdayConfigurationException`; validation of a selected non-inherited profile retains its existing exception types. Invalid unrelated routing settings do not break ordinary APIs that never route, and routing does not create calculators for every possible target.

### Selection Result And Storage Identity

`Zarbinco\LaravelWorkdays\Data\ResolvedProfile` is immutable. It exposes `profile`, `source` (`rule` or `fallback`), and `ruleId` (the selected ID, or `null` for fallback). Its `toArray()` uses only the stable keys `profile`, `source`, and `rule_id`; raw context is not included. Keep any application audit context separately under your own privacy policy.

| Storage driver | Calendar behavior for a selected child |
|---|---|
| `config` | The existing config provider sees fully inherited recurring holidays, exact holidays, and extra working days under the child's actual name. |
| `database` | Database rows use the selected child's name. Parent database-only holidays are **not inherited**. Config inheritance still supplies profile settings such as weekends and schedules. |
| `chain` | Inherited config holidays under the child name combine with that child's database rules using existing chain precedence and source reporting. Parent database rows are not added. |

No synthetic profile name, new model, migration, or importer behavior is introduced. Existing holiday/provider precedence and database-provider caching semantics remain unchanged. Routing merely selects a name and constructs no holiday provider or database query for that selection.

### Cutoff, Delivery Plans, And Slots

After stock, payment, authorization, and tenant checks in your application, explicitly resolve the configured warehouse and carrier calendars. Place the resulting names into the existing plan schema; the planner does not automatically route its stages. The profiles and routing rules in this example must already be configured, as in the fixture above.

```php
$carrier = Workday::resolveProfile(['kind' => 'carrier', 'carrier' => 'express']);
$seller = Workday::resolveProfile(['kind' => 'seller', 'seller_id' => 7]);

$cutoffDecision = $selectedCalculator->resolveCutoff('2026-11-02 12:59:59.999999');
// Same-day eligible; inherited timezone Asia/Tehran, overridden cutoff 13:00.

$routedPlan = [
    'timezone' => 'Asia/Tehran',
    'profile_timezones' => [$warehouse->profile => 'Asia/Tehran', $carrier->profile => 'Asia/Tehran'],
    'cutoff_profile' => $warehouse->profile,
    'stages' => [
        ['id' => 'preparation', 'type' => 'work', 'profile' => $warehouse->profile, 'minutes' => 120],
        ['id' => 'handoff', 'type' => 'handoff', 'profiles' => [$warehouse->profile, $carrier->profile]],
    ],
];
$routedReadiness = Workday::planDelivery('2026-11-02 10:00', $routedPlan);
// Preparation and handoff complete at 12:00 Tehran / 08:30 UTC.

$routedSlotOptions = [
    'profile' => $warehouse->profile,
    'timezone' => 'Asia/Tehran',
    'days' => 2,
    'duration_minutes' => 120,
    'step_minutes' => 60,
    'min_notice_minutes' => 0,
    'max_slots' => 20,
];
$routedSlots = Workday::deliverySlots($routedReadiness->estimatedCompletionAt, $routedSlotOptions);
// First choice: 12:00–14:00 Tehran; no preparation or cutoff is reapplied.

$routedSpecialSlots = Workday::deliverySlots('2026-11-10 09:00', array_replace(
    $routedSlotOptions,
    ['days' => 1],
)); // Child's special date: 10:00–12:00 and 11:00–13:00 Tehran.

$inheritedNight = Workday::profile('route_night');
$inheritedNight->addBusinessMinutes($local('2026-11-02 23:00'), 180);
// November 3, 02:00 Tehran, using inherited overnight support and Monday hours.
```

The plan intentionally ends at readiness/handoff rather than final customer delivery. Inherited cutoff and overnight metadata are respected without changing Phase 01–04 contracts. Exact-date/seasonal precedence, empty schedules, carry-in, `24:00`, slot grids, DST handling, and bounded horizons remain in force. The separate `$cutoffDecision` is an illustration; the plan performs its own single cutoff decision when `cutoff_profile` is included.

Routing chooses a profile, not a timezone. It never infers one from warehouse IDs, region names, server location, or context values. Cutoff uses its effective configured policy timezone. Direct business-time calls retain their supplied datetime timezone; plans and slots still require their existing explicit timezone settings.

### Isolation, Errors, And Eleven Manual Checks

Only the chosen inheritance chain is merged for calculator creation, and its effective configuration is reused within that operation. Inheritance is not repeated per date or minute. There is no process-wide or persistent resolution cache, cross-context selection cache, or mutation of global configuration. Configuration changes are seen by a fresh operation/calculator rather than a stale process-wide snapshot. No extra routing work is added to unrelated legacy calls.

Use the dedicated Tinker fixture; all manual dates below are in 2026 and direct datetime examples use Tehran explicitly.

| Check | Call / observation | Expected result |
|---|---|---|
| 1. Ordinary warehouse | `Workday::resolveProfile(['kind' => 'warehouse', 'warehouse_id' => 99, 'region' => 'qom'])` | `route_base`, source `rule`, ID `general_warehouse`. |
| 2. Specific Tehran rule | Inspect `$warehouse->toArray()` | `route_tehran`, source `rule`, ID `tehran_warehouse`; the most-specific rule wins. |
| 3. Fallback | `Workday::resolveProfile([])->toArray()` | `route_base`, source `fallback`, `rule_id => null`. |
| 4. Parent holiday | `$selectedCalculator->isHoliday($local('2026-01-01'))` | `true`; inherited config holiday remains visible under child identity. |
| 5. Child Thursday | `$selectedCalculator->workingWindowsFor($local('2026-11-05'))` | 09:00–12:00. |
| 6. Other child Thursday | `Workday::profile('route_isfahan')->workingWindowsFor($local('2026-11-05'))` | Parent's 09:00–17:00; no cross-profile state leakage. |
| 7. Inherited cutoff | Inspect `$cutoffDecision`; resolve again at `2026-11-02 13:00` | Inherited timezone and 13:00 child cutoff; before-boundary eligible, exact-boundary ineligible. |
| 8. Inherited night | The `route_night` addition above | November 3, 02:00; Tuesday has the incoming `00:00–06:00` fragment. |
| 9. Routed plan | Inspect `$routedReadiness->stages` and `estimatedCompletionAt` | Warehouse and carrier names are explicit; completion 12:00 Tehran / 08:30 UTC. |
| 10. Routed slots | Inspect `$routedSlots` and `$routedSpecialSlots` | First readiness slot 12:00–14:00; special-date choices 10:00–12:00 and 11:00–13:00. |
| 11. Side effects | Inspect application state after these config-only calculations | No database write, reservation, booking, notification, or external request. |

Restore the session with `config(['workdays' => $routingOriginalConfig]);`. Use test calendars rather than changing production holiday rows. The application must supply trusted context, configure routing policy, enforce authorization and tenant isolation, and manage actual carrier selection, capacity, transactional reservations, and booking. Selecting a carrier's calendar does not choose or book a physical carrier service.

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

## Special Hours, Seasonal Schedules, And Overnight Shifts

Profiles can opt into exact-date hours, seasonal weekday schedules, and overnight shifts without a new facade API. Existing business-time methods, `planDelivery()`, and `deliverySlots()` use the selected profile's effective schedule automatically. All new settings are optional; shipped profiles and profiles without these settings retain their existing behavior and execution path.

### Complete Configuration And Tinker Fixture

Use this dedicated fixture in a local `php artisan tinker` session. It uses config-only calendars, preserves the original configuration for restoration, and creates no bookings, database records, notifications, or external requests.

```php
use Carbon\CarbonImmutable;
use Zarbinco\LaravelWorkdays\Facades\Workday;

$scheduleOriginalConfig = config('workdays');
$scheduleWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$scheduleCalendar = [
    'weekends' => ['Saturday', 'Sunday'],
    'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
    'custom_holidays' => [],
    'extra_working_days' => [],
];
$scheduleProfile = array_replace($scheduleCalendar, [
    'working_hours' => array_fill_keys($scheduleWeekdays, [['09:00', '17:00']]),
    'allow_overnight_working_hours' => true,
    'special_working_hours' => [
        '2026-11-02' => [['10:00', '12:00']],
        '2026-11-03' => [],
    ],
    'seasonal_working_hours' => [
        [
            'from' => '2026-11-01',
            'through' => '2026-11-30',
            'weekdays' => [
                'Monday' => [['08:00', '20:00']],
                'Friday' => [],
            ],
        ],
    ],
]);
$nightProfile = array_replace($scheduleCalendar, [
    'allow_overnight_working_hours' => true,
    'working_hours' => [
        'Monday' => [['22:00', '06:00']],
        'Friday' => [['22:00', '06:00']],
    ],
    'special_working_hours' => ['2026-11-07' => []],
]);
$nightCarrierProfile = array_replace($scheduleCalendar, [
    'working_hours' => ['Tuesday' => [['01:00', '04:00']]],
]);

config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles.hours-demo' => $scheduleProfile,
    'workdays.profiles.night-demo' => $nightProfile,
    'workdays.profiles.night-carrier-demo' => $nightCarrierProfile,
]);

$utc = static fn (string $value): CarbonImmutable => CarbonImmutable::parse($value, 'UTC');
$day = Workday::profile('hours-demo');
$night = Workday::profile('night-demo');

$day->workingWindowsFor($utc('2026-11-02')); // 10:00–12:00: exact-date override.
$night->workingWindowsFor($utc('2026-11-03')); // 00:00–06:00: Monday carry-in.
$night->addBusinessMinutes($utc('2026-11-02 23:00'), 180); // November 3, 02:00 UTC.
$night->diffBusinessMinutes($utc('2026-11-02 23:00'), $utc('2026-11-03 03:00')); // 240.
$night->isBusinessDay($utc('2026-11-07')); // false: Saturday.
$night->isBusinessTime($utc('2026-11-07 02:00')); // true: Friday's valid shift.
```

Use the same optional keys in your application's profile configuration. `special_working_hours` is an associative map of strictly valid Gregorian `Y-m-d` dates to window arrays; no Jalali-key conversion occurs. `seasonal_working_hours` is an ordered list with exactly `from`, `through`, and `weekdays` per entry. Boundaries are inclusive, may cross a Gregorian year boundary, and must satisfy `from <= through`. These are exact date ranges, not annually recurring seasons. Overlapping ranges are rejected even if they override different weekdays.

Seasonal weekday keys follow the existing `WeekdayNormalizer` names and ISO integers. Aliases for the same weekday cannot appear twice. A missing weekday inherits the regular weekly schedule; an explicit `[]` means no shifts start on that weekday during the season.

`allow_overnight_working_hours` is a strict boolean, defaulting to `false`. Exact-date and seasonal daytime overrides do not require it to be `true`. Enabling it permits overnight windows in weekly, exact-date, seasonal, and `extra_working_day_hours` schedules. It also permits `['00:00', '24:00']` as an explicit full-day window. `24:00` is only an end boundary, representing the next local midnight; it cannot be a start. Equal boundaries, malformed windows, invalid times such as `25:00`, and overlapping windows within a configured schedule are rejected. A smaller end clock means one shift ending on the next date, never a multi-day shift longer than 24 hours. Without overnight opt-in, existing end-before-start and `24:00` validation remains unchanged.

### Selection Order And Explicit Empty Hours

The schedule selects shifts that **start** on a date in this order:

1. Apply existing calendar eligibility, including holiday-provider and extra-working-day precedence.
2. Use an exact-date override when its key exists, replacing regular and seasonal hours completely.
3. Otherwise, use an explicit weekday entry from the matching season.
4. Otherwise, inherit regular weekly hours.
5. Apply the existing extra-working-day fallback where applicable.

A holiday or weekend does not gain new shifts merely because special or seasonal hours exist. Reopen it with the existing `extra_working_days` mechanism. Calendar data continues to come from the chosen config/database/chain provider; schedule overlays stay in profile configuration and need no new table or model.

An exact-date `[]` or explicit seasonal weekday `[]` prevents all new shifts on that date, including extra-working-day fallback. An omitted seasonal weekday inherits weekly hours and their existing fallback behavior. A calendar business date with empty hours remains a business date: `isBusinessDay()`, `isHoliday()`, `isWeekend()`, and `resolveCutoff()` retain their meanings. Opening hours neither change cutoff nor turn a calendar-date `business_days` stage into working-hour arithmetic.

### Overnight Ownership And Effective Daily Windows

A 22:00–06:00 shift belongs to its **start date**. A valid Friday shift can continue into Saturday, even when Saturday is a weekend, holiday, or an exact-date empty-hours override. Saturday can therefore have `isBusinessDay() === false` and `isBusinessTime(02:00) === true`. A holiday on Friday prevents that shift unless Friday is reopened as an extra working day. To cancel an incoming overnight shift, change or remove it on its start date; closing the receiving date does not truncate it.

`workingWindowsFor()` still returns ordered, non-overlapping `TimeWindow` objects for the requested civil date. It returns effective daily fragments, rather than the unsplit overnight configuration:

| Configured shift | Effective windows |
|---|---|
| Monday 22:00–Tuesday 06:00 | Monday `22:00–24:00`; Tuesday `00:00–06:00`. |
| Valid carry-in `00:00–06:00` plus a same-day `05:00–09:00` shift | One union `00:00–09:00`; overlap is not counted twice. |
| Carry-in `00:00–06:00` plus independently configured `06:00–09:00` | Two touching windows remain separate. |

The previous date is evaluated using its own exact-date, seasonal, weekly, and calendar rules. No carry-in is fabricated from an ineligible start date. A same-date final fragment may expose `TimeWindow('22:00', '24:00')`; the public method does not expose `TimeWindow('22:00', '06:00')`.

Working windows remain half-open: opening is included and closing is excluded. For the Monday night fixture, Monday 21:59:59 is closed, 22:00 is open, Tuesday 05:59:59 is open, and 06:00 is closed. `nextBusinessTime()` and `previousBusinessTime()` traverse carry-in and exceptional schedules within their existing bounds. The previous-time convention at closing remains one second before the boundary. Minute/hour addition and differences consume effective working time across midnight without duplication, preserve signed reverse differences, retain existing hour conversion rules, and retain zero-duration behavior.

### Timezones, DST, And Compatibility

There is no new global or profile timezone setting for direct business-time calls. The calculator uses the timezone of the supplied datetime; existing strings still use Carbon's application/default parsing behavior. Prefer an explicit timezone-aware `DateTimeInterface` value, as in the UTC fixture. Phase 02 continues to convert through `profile_timezones`, and Phase 03 continues to require its explicit `timezone` option. These distinct contracts remain unchanged.

For enhanced schedules, local boundaries are constructed on the relevant local dates. A nonexistent or ambiguous configured boundary fails with an error identifying the profile, date, and boundary; it is never silently shifted or assigned an arbitrary occurrence. Valid unambiguous boundaries across an offset change use actual elapsed time. A valid 22:00–06:00 New York shift spanning spring-forward has 420 business minutes; the corresponding fall-back shift has 540, provided its start date is calendar-eligible.

When a civil date begins after skipped midnight, a synthetic `00:00` daily fragment uses that date's first actual instant, as in `America/Santiago`. This exception applies only to nonexistent day-start midnight; ambiguous synthetic midnight boundaries still fail with a clear profile/date/boundary error. Historical folds that repeat midnight, such as `America/Goose_Bay` in 1988, can therefore reject a daily split even when the original overnight shift's start and end are unambiguous. A fully skipped civil date contributes no invented time and does not extend the bounded date-label search. Phase 03 retains its stricter candidate-boundary and transition policy: it does not fabricate a slot ending at a nonexistent or ambiguous midnight, even when business-time fragments are usable on either side.

Invalid configuration raises `WorkdayConfigurationException`, including malformed exact dates or seasons, unsupported season keys, reversed/overlapping ranges, duplicate normalized weekdays, invalid window shapes, non-boolean overnight enablement, and disallowed boundaries. Evaluating invalid scheduled local boundaries also fails explicitly with profile/date/boundary context. Profiles with none of the three new keys keep the legacy validation, parsing, precision, provider lookup behavior, and calculation path.

Normalized schedule definitions are reused per calculator where practical. Date traversal remains bounded; no minute-by-minute provider loading, new provider construction inside loops, global mutable cache, or persistent cache is introduced. Holiday-provider behavior remains authoritative: the existing `DatabaseHolidayProvider` memoizes recurring rules and queried profile/date special dates for the calculator/provider lifetime. Obtain a fresh calculator to observe edits to already-read database dates/rules or changed profile configuration. No effective-window cache is introduced; the enhanced config-provider path caches only successful validation of static profile settings.

### Existing Delivery Planning And Slot APIs

The same Phase 02 API can prepare overnight and hand off after midnight. This plan ends at readiness/transfer, not physical delivery:

```php
$overnightPlan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['night-demo' => 'UTC', 'night-carrier-demo' => 'UTC'],
    'stages' => [
        ['id' => 'ready_for_handoff', 'type' => 'work', 'profile' => 'night-demo', 'minutes' => 180],
        ['id' => 'handoff', 'type' => 'handoff', 'profiles' => ['night-demo', 'night-carrier-demo']],
    ],
];
$overnightResult = Workday::planDelivery('2026-11-02T23:00:00Z', $overnightPlan);
// Work completes and both calendars permit handoff on November 3 at 02:00 UTC.

$nightSlotOptions = [
    'profile' => 'night-demo',
    'timezone' => 'UTC',
    'days' => 2,
    'duration_minutes' => 120,
    'step_minutes' => 60,
    'min_notice_minutes' => 0,
    'max_slots' => 20,
];
$nightSlots = Workday::deliverySlots('2026-11-02T21:00:00Z', $nightSlotOptions);
// November 2: 22:00–24:00.
// November 3: 00:00–02:00, 01:00–03:00, 02:00–04:00, 03:00–05:00, 04:00–06:00.

$specialSlots = Workday::deliverySlots('2026-11-02T08:00:00Z', array_replace(
    $nightSlotOptions,
    ['profile' => 'hours-demo', 'days' => 1],
)); // Exactly 10:00–12:00.
```

Phase 01 cutoff remains independent. Phase 02 work/handoff uses effective windows automatically, while `business_days` remains calendar-date based. Phase 03 uses exact-date/seasonal windows and normalized overnight fragments, preserving fixed duration, per-window grids, notice, UTC output, date limits, and result limits.

Slots still occupy one daily window: 22:00–24:00 is permitted when midnight is representable, including on the final requested date because no positive time occupies the next date. A single 23:00–01:00 slot is not offered. Early-morning carry-in starts its own grid at `00:00`; separate touching windows are not joined to create a longer slot. Existing conservative DST filtering remains in effect. Slots are calendar-eligible choices, not capacity guarantees or reservations.

### Ten Manual Checks

Use the complete dedicated UTC fixture above. All dates below are in 2026. Inspect window objects with `array_map(static fn ($window) => $window->toArray(), $calculator->workingWindowsFor($date))` when needed.

| Check | Tinker call / setup | Expected result |
|---|---|---|
| 1. Weekly schedule | `$day->workingWindowsFor($utc('2026-12-07'))` | Regular Monday 09:00–17:00. |
| 2. Short exact date | `$day->workingWindowsFor($utc('2026-11-02'))` | 10:00–12:00, taking precedence over seasonal Monday hours. |
| 3. Empty exact date | `$day->workingWindowsFor($utc('2026-11-03'))`; `$day->isBusinessDay($utc('2026-11-03'))` | No new windows; still a business date. The next working instant after 08:00 is November 4, 09:00. |
| 4. Extended season | `$day->workingWindowsFor($utc('2026-11-09'))` | Monday 08:00–20:00. Friday November 6 has no new shifts because its seasonal entry is `[]`. |
| 5. After season | `$day->workingWindowsFor($utc('2026-12-07'))` | Return to 09:00–17:00. |
| 6. Overnight fragments | Inspect `$night` on November 2 and 3; check 22:00 and 06:00 | `22:00–24:00`, then `00:00–06:00`; opening included, closing excluded. |
| 7. Midnight arithmetic | The `addBusinessMinutes()` / `diffBusinessMinutes()` calls above | November 3, 02:00 for 180 minutes; difference to 03:00 is 240 minutes. |
| 8. Closed receiving date | `$night->isBusinessDay($utc('2026-11-07'))`; `$night->isBusinessTime($utc('2026-11-07 02:00'))` | `false`, then `true`; Saturday's empty override does not cancel Friday's incoming shift. |
| 9. Overnight planning | Inspect `$overnightResult->stages` | Preparation completes and handoff occurs November 3, 02:00; both profiles are open. |
| 10. Exceptional/overnight slots | Inspect `$specialSlots->toArray()` and `$nightSlots->toArray()` | One 10:00–12:00 special-date slot; six night-fragment slots listed above; no 23:00–01:00 slot. |

Restore the session using `config(['workdays' => $scheduleOriginalConfig]);`. These calculations do not reserve capacity or write records. Do not modify production holiday data for testing. Annual recurring seasons, schedule administration, holiday synchronization, automatic regional timezone assignment, reservations, and capacity management remain application responsibilities or outside this feature.

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

Capacity, reservations, carrier integrations, automatic courier/address routing, real-time availability, and physical shipment creation/tracking remain application responsibilities. Opted-in profiles also use the special, seasonal, and normalized overnight schedules described above. The result describes only completion of the configured final stage; the stage name `final_delivery` does not prove physical arrival.

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

## Calendar Versions And Delivery Promise Snapshots

`captureDeliveryPromise()` records a delivery estimate together with a bounded, immutable view of the calendar rules used to calculate it. `compareDeliveryPromise()` later recalculates the same input and plan against current rules, while preserving the original result. A store can keep its original customer promise separately from a changing operational estimate and decide what action a comparison warrants.

These APIs are opt-in on `WorkdayManager` and the facade. They do not persist snapshots, update orders or shipments, make reservations, notify customers, or schedule future comparisons. The application owns storage, authorization, identifying affected orders, deciding whether to revise a promise, and customer communication. Existing `planDelivery()` results and Phase 01–05 APIs retain their public contracts.

### Complete Local Capture And Comparison Example

Use this dedicated config-only fixture in a local `php artisan tinker` session. The warehouse inherits its calendar, hours, and UTC cutoff from a parent. The plan finishes after one carrier business-date lead time; its final stage defines the estimate, rather than proving physical delivery.

```php
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Facades\Workday;

$promiseOriginalConfig = config('workdays');
$promiseWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$promiseProfiles = [
    'promise_base' => [
        'weekends' => ['Saturday', 'Sunday'],
        'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
        'custom_holidays' => [],
        'extra_working_days' => [],
        'working_hours' => array_fill_keys($promiseWeekdays, [['09:00', '17:00']]),
        'cutoff' => ['timezone' => 'UTC', 'time' => '14:00'],
    ],
    'promise_warehouse' => ['extends' => 'promise_base'],
    'promise_carrier' => ['extends' => 'promise_base'],
];
config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 31,
    'workdays.profiles' => array_replace(config('workdays.profiles', []), $promiseProfiles),
]);

$deliveryPlan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['promise_warehouse' => 'UTC', 'promise_carrier' => 'UTC'],
    'cutoff_profile' => 'promise_warehouse',
    'stages' => [
        ['id' => 'preparation', 'type' => 'work', 'profile' => 'promise_warehouse', 'minutes' => 120],
        ['id' => 'transit', 'type' => 'business_days', 'profile' => 'promise_carrier', 'days' => 1],
    ],
];
$snapshot = Workday::captureDeliveryPromise(
    '2026-11-02T10:00:00Z',
    $deliveryPlan,
    ['horizon_days' => 14],
);
$snapshot->calendarVersion;
$snapshot->originalResult->estimatedCompletionAt; // November 3, 12:00 UTC.

// Persist this JSON using the application's own storage when needed.
$payload = $snapshot->toArray();
$storedJson = json_encode($payload, JSON_THROW_ON_ERROR);
$restored = DeliveryPromiseSnapshot::fromArray(
    json_decode($storedJson, true, 32, JSON_THROW_ON_ERROR),
);
$unchanged = Workday::compareDeliveryPromise($restored);
// Also accepts $snapshot directly, or the serialized $payload array.
// versionChanged=false, estimateChanged=false, direction=unchanged, deltaSeconds=0.
```

The capture resolves the effective inherited configuration and materializes holiday inputs first. It calculates both the original plan and date evidence from that same frozen in-memory view, using the existing planner, cutoff rules, and working-time engine. It does not attach a later calendar read to an earlier live estimate.

Change only these test settings to observe an impact:

```php
config([
    'workdays.profiles.promise_carrier.custom_holidays' => ['2026-11-03' => 'Test holiday'],
]);
$later = Workday::compareDeliveryPromise($payload);
// Current completion: November 4, 12:00 UTC; direction=later; deltaSeconds=86400.
// $snapshot->originalResult still completes on November 3 at 12:00 UTC.

config([
    'workdays.profiles.promise_carrier.custom_holidays' => [],
    'workdays.profiles.promise_base.special_working_hours' => [
        '2026-11-02' => [['09:00', '11:00']],
    ],
]);
$changedHours = Workday::compareDeliveryPromise($payload);
// Preparation now finishes November 3 at 10:00; transit completes November 4 at 10:00.
$changedHours->changedCalendarDays;

config([
    'workdays.profiles.promise_base.working_hours' => [],
    'workdays.profiles.promise_base.special_working_hours' => [],
]);
$unresolvable = Workday::compareDeliveryPromise($payload);
// status/direction=unresolvable; current estimate, estimateChanged and deltaSeconds are null.
// The original result remains available; inspect $unresolvable->error for the constraint.
```

### Horizon, Profiles, And Captured Dates

Capture requires `['horizon_days' => N]`, with a strict PHP integer from `1` through `31`, not exceeding `workdays.max_scan_days`. No other option keys are supported. Numeric strings, floats, zero, negative values, and values outside the limits are invalid. At most eight distinct referenced profiles are allowed. These limits apply only to capture/comparison and do not replace Phase 02's existing defaults.

Day zero is the received instant's date in the plan timezone. `horizon_days = 14` allows that date and fourteen future calendar dates, through day 14 inclusive; it is not Phase 03's count of fourteen dates. All stages share this horizon. Neither capture nor comparison extends it or resets it per stage. Comparison reuses the original horizon and remains subject to the configured safety limit.

Evidence coverage includes deterministic neighboring dates for differing profile timezones and overnight ownership. Convert the plan's day-zero midnight instant and its horizon's exclusive ending-midnight instant to each profile timezone. The observed range starts two date labels before the first converted date and ends two labels after the latter converted date, inclusive. Provider inputs additionally include one preceding shift-owner date. This date-label margin does not extend the permitted planning horizon.

For the same-UTC example, the plan may finish through November 16, 2026. Observed evidence covers October 31 through November 19 inclusive: day −2 through day 17, twenty dates per profile. Dated provider inputs additionally cover October 30. A same-timezone horizon of 31 has 37 observed dates per profile. Relevant dated overrides and seasons are trimmed to provider coverage; active recurring annual holiday maps for selected profiles are retained. Entirely skipped civil dates create no fabricated working time.

Capture requires the covered Gregorian range, including margins, to fit years `0001`–`9999` and rejects historical cutoff offsets containing seconds because nested cutoff timestamps use `HH:MM` offsets.

### Portable Schema And Integrity

`Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot` is immutable and restores through `fromArray()` after a normal JSON round-trip.

| Property | Serialized key | Meaning |
|---|---|---|
| `schemaVersion` | `schema_version` | Snapshot format version, initially integer `1`. |
| `calendarVersion` | `calendar_version` | Deterministic SHA-256 calendar-content fingerprint. |
| `receivedAt` | `received_at` | Original received instant as immutable UTC. |
| `plan` | `plan` | Validated original plan with its actual selected profile names. |
| `horizonDays` | `horizon_days` | Original explicit future-date horizon. |
| `originalResult` | `original_result` | Complete original `DeliveryPlanResult`, including ordered stages and optional cutoff result. |
| `calendarEvidence` | `calendar_evidence` | Bounded effective configuration, provider inputs, coverage, and observed date decisions. |
| `runtimeMetadata` | `runtime_metadata` | Available implementation/runtime versions and relevant calendar metadata. |
| Integrity checksum | `integrity_hash` | Separate checksum covering the stored payload, rather than just the calendar version. |

New instant fields serialize in UTC with six microsecond digits and an explicit `+00:00` offset. Existing nested result contracts remain intact, including the policy-local cutoff result's offsets. Date labels use Gregorian `Y-m-d`. Evidence records profile/date identity, business-day/holiday/extra-working-day decisions, effective working windows where needed, and holiday reasons/source information. Profiles used exclusively for business-date transit do not require working hours just to obtain evidence.

Validation limits the canonical JSON representation to **2 MiB (2,097,152 bytes)**, depth **32**, and **100,000 nodes**. Schema, fields, types, dates, timestamps, stage definitions, profiles, evidence, and checksums are validated. Missing required fields, unsupported schema versions, malformed or oversized content, unsupported objects, and fingerprint mismatches are invalid input, not a successful calendar-change comparison. JSON-compatible values retain their numeric/boolean types; imported data is never restored with unsafe PHP `unserialize()` or evaluated as code.

Comparison date differences retain full previous/current rows from two bounded calendar views. These combined diagnostics have a separate output limit of **5 MiB (5,242,880 bytes)**, depth **32**, and **210,000 nodes**. Snapshot imports retain the limits above.

Structural validation and checksum verification always apply. Additional consistency checks replay the captured rules/result when runtime metadata matches and the current `max_scan_days` can accommodate the original horizon. A different runtime or reduced safety limit does not supply a historical execution environment; the stored original remains available and current recalculation reports its own outcome.

Exact local anchor/window/horizon checks require matching timezone-database versions; otherwise bounded structural/integrity checks remain and `runtimeChanged` flags recalculation under the current timezone database, without proving historical local replay.

The calendar fingerprint and whole-payload integrity checksum provide deterministic integrity checks. They are not signatures or authentication against someone who can rewrite both the payload and its hashes, and do not establish regulatory compliance. Protect stored snapshots using the application's own access controls and storage policy. Customer IDs, addresses, phone numbers, order IDs, and routing context are not automatically included; avoid placing private data in application-supplied plan identifiers.

### What A Calendar Version Means

A calendar version is a content fingerprint, not a sequential database revision or capture timestamp. Canonical JSON recursively sorts associative-map keys, preserves meaningful list order and value types, retains meaningful explicit nulls, and normalizes dates, instants, and supported weekday aliases. A fixed schema/domain prefix separates the hash input from unrelated uses of SHA-256.

The version covers relevant effective profile settings after inheritance, calendar-provider inputs, profile timezones and coverage, cutoff settings where applicable, working/exceptional/seasonal/overnight schedules, Hijri calculation settings, and observed calendar decisions. Equivalent unordered holiday-map insertion order and irrelevant database row IDs do not alter it. Capture time, random IDs, object identity, unrelated configuration, and unreferenced profiles are not version inputs.

`versionChanged` and `estimateChanged` answer different questions. A relevant calendar change after the plan's completion can change the version while leaving the estimate identical. Conversely, runtime/calendar-library or timezone-database changes are surfaced separately and can affect interpretation. Available PHP, calendar-library, timezone-database, and calculation-schema metadata is recorded; unavailable versions are represented explicitly rather than invented. A stored result remains an audit artifact, not a promise of identical recomputation across different runtimes or historical database restoration.

### Comparison Results And Observed Differences

`Zarbinco\LaravelWorkdays\Data\DeliveryPromiseComparison` is immutable. A successful recalculation has `status = resolved` and direction `unchanged`, `earlier`, or `later`; failure to calculate within current constraints has `status = unresolvable` and direction `unresolvable`.

| Property / serialized field | Meaning |
|---|---|
| `originalCalendarVersion` / `original_calendar_version` | Stored original calendar fingerprint. |
| `currentCalendarVersion` / `current_calendar_version` | Current fingerprint when current evidence can be obtained. |
| `versionChanged` / `version_changed` | Whether current and original calendar content differ, when comparable. |
| `estimateChanged` / `estimate_changed` | Whether completion instants differ; `null` if unresolvable. |
| `direction` / `direction` | `unchanged`, `earlier`, `later`, or `unresolvable`. |
| `originalEstimatedCompletionAt` / `original_estimated_completion_at` | Original immutable UTC completion instant. |
| `currentEstimatedCompletionAt` / `current_estimated_completion_at` | Recalculated immutable UTC completion, or `null` if unresolvable. |
| `deltaSeconds` / `delta_seconds` | Current minus original completion: positive is later, negative earlier, zero unchanged; may include fractional seconds for microsecond differences; `null` if unresolvable. |
| `changedCalendarDays` / `changed_calendar_days` | Bounded observed differences sorted by profile and date, with `profile`, `date`, and `changed_fields`. |
| `runtimeChanged` / `runtime_changed` | Whether recorded runtime/algorithm metadata differs. |
| `configurationChanged` / `configuration_changed` | Nullable diagnostic for effective settings, Hijri configuration, and effective config-source holiday inputs. |
| `holidayInputsChanged` / `holiday_inputs_changed` | Nullable diagnostic for merged effective holiday-provider input changes across sources. |
| `databaseRulesChanged` / `database_rules_changed` | Nullable diagnostic for effective database-source holiday input changes. |
| `error` / `error` | Useful recalculation failure details, or `null` on success. |

For an unchanged comparison, `version_changed = false`, `estimate_changed = false`, direction is `unchanged`, and `delta_seconds = 0`. A version-only change can instead have `version_changed = true` with those same estimate fields. Removing a holiday that affected the original plan can move the estimate earlier and produce a negative delta. Changed-date evidence shows observed calendar/window/reason differences; it does not prove that every listed date caused the movement. Policy or runtime differences need not appear as a date-level working-window change.

The input diagnostics compare effective captured content, not raw config-file revisions or database-table revision counters. Deactivating a database override can reveal an inherited config rule and therefore change both source-specific diagnostics. `holiday_inputs_changed` covers the merged effective input across sources. These flags distinguish observed content changes without establishing which edit caused an ETA movement.

Comparison validates and preserves the stored result, original received instant, plan, selected profile names, and horizon. It materializes current effective rules and reruns the existing planner. It does not resolve routing again or substitute a newly routed warehouse/carrier. An unresolvable comparison retains the original completion and reports the failed constraint without fabricating a current estimate. Invalid stored input throws `InvalidArgumentException` instead of returning an apparently valid `unresolvable` comparison.

### Storage Consistency, Performance, And Responsibilities

Config storage captures effective inherited definitions. Database storage reads active recurring and bounded dated rules for each selected profile's actual name; parent database rows are not inherited. Chain storage combines inherited config data with the child's database rules using existing precedence, titles, and source reporting. Cutoff and enhanced working-time behavior are reused without a second planning or holiday engine.

Once provider inputs are materialized, the original calculation and evidence share that frozen view. Initial database reads are not automatically one transactionally consistent cross-query snapshot: an application needing strict consistency must supply an appropriate read transaction/isolation policy. The package does not lock holiday records or start a transaction that changes application write behavior, and capture/comparison perform no database writes.

Database/chain materialization uses two batched SELECTs per selected profile, for recurring rules and dated exceptions, independent of horizon length or candidate minutes. Config-only capture needs no holiday database queries. Effective configuration and providers are reused within an operation, date coverage and profile count are bounded, and no global/persistent cache or unrelated legacy-path work is added. The 2 MiB validation bound remains relevant when profiles contain large definitions or reason titles.

Measured compact JSON sizes for this UTC config fixture on PHP 8.3.30 / Carbon 3.13.0 are below. The one-profile variation keeps only the warehouse preparation stage and its cutoff. Sizes vary with definitions, names, titles, results, and runtime metadata.

| Referenced profiles | Horizon | Observed dates per profile | JSON bytes |
|---|---|---|---|
| One | 1 | 7 | 3,964 |
| One | 14 | 20 | 7,676 |
| One | 31 | 37 | 12,556 |
| Two, as above | 14 | 20 | 12,785 |

### Ten Manual Checks

Use only the dedicated fixture above; all dates are in 2026 and times are UTC.

| Check | Action | Expected result |
|---|---|---|
| 1. Capture | Create `$snapshot` with the explicit fourteen-day horizon. | Original completion November 3, 12:00; a non-empty SHA-256 calendar version. |
| 2. Serialize | Encode `$payload`, decode JSON, restore `$restored`. | Same calendar version and original result; portable JSON-compatible fields. |
| 3. Unchanged | Inspect `$unchanged->toArray()`. | Resolved, both change flags false, direction `unchanged`, delta `0`. |
| 4. Test holiday | Add the carrier's November 3 config holiday as shown. | Only current test configuration changes; stored evidence remains unchanged. |
| 5. Compare holiday | Inspect `$later`. | November 4, 12:00; `later`, `86400` seconds; affected child/date appears in differences. |
| 6. Preserve original | Reinspect `$snapshot->originalResult` and stored JSON. | Still November 3, 12:00 with the original evidence and version. |
| 7. Shorter hours | Remove the test holiday and set the parent's November 2 hours to 09:00–11:00. | Calendar business date remains valid; preparation carries into November 3. |
| 8. Compare hours | Inspect `$changedHours` and its changed-date list. | November 4, 10:00; working-window differences are visible. |
| 9. Inherited profile | Inspect selected names/effective evidence; inspect `$unresolvable` after clearing hours. | Child identities retained; inherited settings respected; no-availability comparison is explicitly unresolvable. |
| 10. Side effects | Inspect application state after these config-only calls. | No order update, database write, reservation, notification, or network request. |

Restore the session with `config(['workdays' => $promiseOriginalConfig]);`. Persist or communicate a revised promise only through explicit application logic. Do not test by changing production holiday rows, and do not treat this audit feature as capacity management, booking, automatic notification, or a scheduled monitoring service.

## Delivery And Pickup Time Slots

`Workday::deliverySlots($readyAt, $options)` generates customer-selectable delivery, collection, or return drop-off intervals from one serving profile's calendar and working hours. For an online store, calculate when an item will be ready, generate eligible checkout intervals, then apply your application's capacity and booking checks.

A generated slot is **calendar-eligible**, not guaranteed booking availability. Selecting one does not create an order, appointment, or reservation. Revalidate calendar eligibility before booking; capacity, transactional reservation, concurrent booking protection, slot IDs, booking references, and appointment records belong to the consuming application. There is no automatic carrier API integration.

The API is opt-in, available on the facade and `WorkdayManager`, and requires no cutoff policy, new profile keys, database tables, or Composer dependencies. Call it on the manager/facade; each call evaluates one explicit profile. It does not add a profile-calculator alias or Carbon macro.

### Complete Usage And Local Test Profile

Run this dedicated fixture in a local Laravel application with `php artisan tinker`. These session-only settings use config storage and do not write holiday or booking records. Keep a copy of the existing config to restore after the checks.

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$slotOriginalConfig = config('workdays');
$slotWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$slotHours = array_fill_keys($slotWeekdays, [
    ['09:00', '12:00'],
    ['13:00', '17:00'],
]);
$slotCalendar = [
    'weekends' => ['Saturday', 'Sunday'],
    'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
    'custom_holidays' => [],
    'extra_working_days' => [],
];
$slotProfile = array_replace($slotCalendar, ['working_hours' => $slotHours]);

config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles.slots-demo' => $slotProfile,
]);

$options = [
    'profile' => 'slots-demo',
    'timezone' => 'UTC',
    'days' => 2,
    'duration_minutes' => 120,
    'step_minutes' => 60,
    'min_notice_minutes' => 0,
    'max_slots' => 10,
];

$result = Workday::deliverySlots('2026-06-29T10:30:00Z', $options);
$result->readyAt;
$result->eligibleFromAt;
$result->slots;
$result->toArray();

// The same API without the facade:
app(\Zarbinco\LaravelWorkdays\WorkdayManager::class)
    ->deliverySlots('2026-06-29T10:30:00Z', $options);
```

This returns eight slots: June 29 at 13:00–15:00, 14:00–16:00, and 15:00–17:00; June 30 at 09:00–11:00, 10:00–12:00, 13:00–15:00, 14:00–16:00, and 15:00–17:00. Dates and times in this fixture are UTC.

### Options And Validation

| Key | Requirement / default | Accepted value |
|---|---|---|
| `profile` | Required | Non-empty name of an existing configured profile with valid `working_hours`. |
| `timezone` | Required | IANA timezone identifier such as `Asia/Tehran`, `America/New_York`, or `UTC`; a numeric offset is not a profile timezone. |
| `days` | Required | PHP integer `1`–`31`, additionally requiring `days - 1 <= workdays.max_scan_days`. |
| `duration_minutes` | Required | PHP integer `1`–`1439`; actual elapsed length of each slot. |
| `step_minutes` | Required | PHP integer `1`–`1439`; spacing between candidate starts within each window. |
| `min_notice_minutes` | Optional; `0` | Non-negative PHP integer; additional elapsed minutes after readiness. |
| `max_slots` | Optional; `50` | PHP integer `1`–`200`; maximum returned intervals. |

Unknown keys and profiles, missing required keys, malformed hours, invalid timezones, and invalid values throw `Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException`. Integer options are strict: numeric strings, floats, booleans, and explicit `null` are rejected. Omit an optional key to use its default. `workdays.max_scan_days` must retain its existing positive-integer configuration. Slot-specific options are validated only when this API is called; ordinary calculators do not require slot configuration.

### Readiness, Notice, And Calendar Dates

Always supply `readyAt` explicitly; the generator never substitutes `now()`. It is the earliest operationally ready instant provided by your application. It may come from your own calculation, a meaningful Phase 02 readiness stage's `completedAt`, or `estimatedCompletionAt` from a plan intentionally ending at readiness. A plan ending after final delivery processing is not automatically an appropriate readiness plan.

The accepted type is `string|\DateTimeInterface`. Strict absolute string formats follow the cutoff timestamp formats: space-separated `Y-m-d H:i[:s[.microseconds]]`, or ISO `T` timestamps with `Z`/`±HH:MM`. A fraction has 1–6 digits and requires seconds. Naive strings use the supplied profile `timezone`; offset-bearing timestamps and date objects preserve their actual instant. Empty, relative, date-only, impossible-date, invalid-offset, nonexistent local, and ambiguous naive local timestamps throw `InvalidArgumentException`. Supply an explicit offset or a `DateTimeInterface` instant to disambiguate a repeated local hour. Caller-provided mutable objects are not changed.

`eligibleFromAt = readyAt + min_notice_minutes` on the UTC timeline. The buffer is applied once, using elapsed minutes, including closed hours. It neither counts business minutes nor repeats preparation. If preparation must consume business time, perform it in Phase 02 first. A candidate start must be at or after this threshold; seconds and microseconds are compared without rounding backward. Readiness at 09:00 permits a 09:00 start; readiness at 09:00:00.000001 excludes it.

There is no arbitrary policy maximum for this non-negative integer buffer, but the resulting timestamp must fit the runtime's DateTime and integer range. An unrepresentable buffer throws `WorkdayConfigurationException`; a representable large buffer beyond the requested dates retains its exact eligibility threshold and returns no slots.

`days` counts local calendar-date labels anchored to `readyAt`, including its initial date. Seven means day 0 through day 6, regardless of weekends, holidays, or the notice buffer. The request never extends to collect seven business dates. A buffer pushing eligibility outside these dates produces an empty list. Entirely skipped civil dates consume a requested date label and provide no slots; they do not extend the range.

Supply the actual service calendar's timezone consistently with your Phase 02 `profile_timezones` mapping. The generator never infers it from Laravel's application timezone. All comparisons and ordering use UTC instants; the requested date range uses that explicit profile timezone.

### Boundaries, Alignment, And Calendars

Every slot is half-open, `[start, end)`, and fits wholly inside one working window on one requested local date. It can start at opening and end exactly at closing; it cannot start at closing, bridge lunch, cross into the next window, or occupy positive time on a second local date. An opted-in effective window may end at `24:00`, allowing a slot to end exactly at next midnight. With 09:00–12:00 and 13:00–17:00, two-hour slots 10:00–12:00 and 15:00–17:00 are valid; 11:00–13:00, 12:00–14:00, and 16:00–18:00 are invalid.

Candidate starts follow each window's own opening plus `n * step_minutes`. The grid restarts at an afternoon opening. Readiness filters this fixed grid; it does not shift the grid to arbitrary input seconds. For example, a 90-minute notice after 08:00 gives 09:30 eligibility and a first two-hour slot of 10:00–12:00. Different requests seconds apart retain the same aligned starts. Step may be shorter than duration, so returned choices can overlap each other; they are alternative intervals, not reserved appointments.

The generator reuses `workingWindowsFor()` and one calculator per call. Existing weekend, recurring Gregorian/Jalali/Hijri holiday, exact holiday, config/database/chain, half-day, split-window, and extra-working-day precedence is preserved. An extra working day uses its weekday hours, or `extra_working_day_hours` when that weekday has none; missing both remains a configuration error. `isBusinessDay()` keeps its day-level meaning independent of opening hours.

At most `days` date labels are considered, windows are retrieved once per existing scanned date, and candidates advance by the configured step. Results are unique and chronological, and generation stops at `max_slots`. No global or persistent cache is added. Valid searches containing only holidays, closed dates, or windows shorter than the duration return an empty `slots` list.

### Conservative Timezone Transition Policy

Candidate boundaries are formed from local wall-clock dates and times, then checked before conversion to immutable UTC. Nonexistent or ambiguous slot start/end boundaries are skipped; a missing 02:30 is never silently shifted to 03:30. Slots crossing a timezone offset transition are skipped. Valid unambiguous candidates before or after the transition remain usable, provided their actual elapsed duration equals `duration_minutes` and they stay inside the window. A fully skipped civil date, such as `Pacific/Apia` on December 30, 2011, generates no phantom slots.

This conservative policy intentionally offers fewer choices around transitions. Opted-in overnight schedules are consumed as daily fragments; individual slots still do not cross midnight, and no generalized DST scheduling engine is provided. Results are deterministic for the same inputs, calendar data, and timezone database.

### Phase 02 Readiness Integration

This local example intentionally ends when warehouse preparation is complete. It does not include transit or final delivery processing. Both serving calendars use Tehran time; no additional notice or cutoff is applied by the slot generator.

```php
config([
    'workdays.profiles.readiness-demo' => $slotProfile,
    'workdays.profiles.pickup-demo' => $slotProfile,
]);

$readinessStagesPlan = [
    'timezone' => 'Asia/Tehran',
    'profile_timezones' => ['readiness-demo' => 'Asia/Tehran'],
    'stages' => [
        ['id' => 'ready_for_collection', 'type' => 'work', 'profile' => 'readiness-demo', 'minutes' => 120],
    ],
];

$readinessPlan = Workday::planDelivery(
    '2026-06-29 10:30',
    $readinessStagesPlan,
);
// Preparation pauses at lunch and finishes at 13:30 Tehran / 10:00 UTC.

$pickupSlots = Workday::deliverySlots($readinessPlan->estimatedCompletionAt, [
    'profile' => 'pickup-demo',
    'timezone' => 'Asia/Tehran',
    'days' => 7,
    'duration_minutes' => 120,
    'step_minutes' => 60,
    'min_notice_minutes' => 0,
    'max_slots' => 20,
]);
// First choice: 14:00–16:00 Tehran / 10:30–12:30 UTC.
```

No slot begins before the supplied preparation completion. `planDelivery()` and `deliverySlots()` retain separate result contracts. If your readiness plan explicitly uses cutoff routing, it happens there; slot generation does not reapply it or add the work stage again.

### Results And Serialization

`Zarbinco\LaravelWorkdays\Data\DeliverySlotsResult` and `DeliverySlot` are immutable value objects. The former exposes:

| Property | Serialized key | Meaning |
|---|---|---|
| `profile` | `profile` | Selected configured profile. |
| `timezone` | `timezone` | Captured local service timezone. |
| `readyAt` | `ready_at` | Original readiness instant as UTC `CarbonImmutable`. |
| `eligibleFromAt` | `eligible_from_at` | UTC `CarbonImmutable` after the elapsed notice buffer. |
| `requestedDays` | `requested_days` | Requested local calendar-date count. |
| `slots` | `slots` | Ordered list of immutable `DeliverySlot` objects, or `[]`. |

Each slot exposes UTC `CarbonImmutable` properties `startsAt` and `endsAt`. Its `toArray()` derives local boundaries using the captured service timezone. For the first UTC fixture slot:

```php
$result->slots[0]->toArray();
// [
//     'starts_at' => '2026-06-29T13:00:00.000000+00:00',
//     'ends_at' => '2026-06-29T15:00:00.000000+00:00',
//     'local_starts_at' => '2026-06-29T13:00:00.000000+00:00',
//     'local_ends_at' => '2026-06-29T15:00:00.000000+00:00',
// ]
```

All serialized timestamps use `Y-m-d\TH:i:s.uP`, six microsecond digits, and explicit offsets, including `+00:00` for UTC. Local fields include their actual offset; the Tehran integration's first `local_starts_at` is `2026-06-29T14:00:00.000000+03:30`. Result `toArray()` uses the table's stable snake_case keys and serializes every slot. Empty valid results still include readiness, eligibility, profile, timezone, requested days, and `'slots' => []`. Later caller-object or config changes do not mutate an existing result.

### Manual Verification And Responsibilities

Use the dedicated Tinker fixture above, restoring `workdays.profiles.slots-demo` to `$slotProfile` and `$options` to the original values between cases. All dates below are in 2026 and all times are UTC unless stated.

| Check | Change / input | Expected outcome |
|---|---|---|
| A: split windows | Original fixture; June 29, 10:30 | First choices 13:00–15:00, 14:00–16:00, 15:00–17:00; eight total across two dates; none bridge lunch. |
| B: notice | June 29, 08:00; set `min_notice_minutes` to `90` | Eligibility 09:30; first choice 10:00–12:00. |
| C: exact boundaries | June 29, 09:00, then 09:00:00.000001 | 09:00–11:00 appears only for the first input; 10:00–12:00 and 15:00–17:00 may end at closing, and no start equals a closing. |
| D: holiday/weekend | Set `custom_holidays` to `['2026-07-03' => 'Test holiday']`; input July 2, 17:00; `days = 5` | No Friday/weekend choices; first slot is Monday July 6, 09:00–11:00. |
| E: half-day | Replace Thursday hours with `[['09:00', '12:00']]`; input July 2, 08:00; `days = 1` | Exactly 09:00–11:00 and 10:00–12:00. |
| F: extra working day | Set `extra_working_days` to `['2026-07-04' => 'Test working day']` and `extra_working_day_hours` to `[['10:00', '14:00']]`; input July 4, 08:00; `days = 1` | 10:00–12:00, 11:00–13:00, 12:00–14:00. Removing fallback hours yields the existing missing-hours configuration error. |
| G: short window | Replace Monday hours with `[['09:00', '10:00']]`; input June 29, 08:00; `days = 1` | Empty `slots`, with no exception. |

Run the readiness integration and confirm every slot begins at or after its plan's completion. Inspect UTC and local fields, and confirm these calls create no booking, reservation, database write, or notification. Restore the session's settings with `config(['workdays' => $slotOriginalConfig]);`. Use test-only config and calendars rather than modifying production holiday records.

The application must revalidate eligibility and remaining capacity during booking, enforce its own transactional reservation and concurrency rules, and decide what customer promise it can make. This phase evaluates one serving profile per call; whole-interval availability intersection across multiple profiles is outside its scope. Special/seasonal hours and overnight shifts are available through optional profile settings; slots remain inside their effective daily windows. No frontend, routes, controllers, notifications, background jobs, order models, shipment models, or booking storage are added.

## Business SLA Evaluation And Pause/Resume

`Workday::evaluateSla($startedAt, $evaluatedAt, $options)` calculates an SLA's consumed working time, remaining budget, deadline, and breach status. For example, a store can allow four working hours to review a customer's return request while excluding an approved wait for customer documents. Working minutes consume eligible opening hours; calendar minutes also include lunch, closed evenings, weekends, and holidays.

This is a pure calculation, not a stored ticket/workflow state. The application decides when an SLA starts, which pauses are allowed and why, when each pause ends, whether a ticket is completed, and what escalation or notification to send. The package does not monitor tickets, start timers, schedule reminders, persist pause records, or change return requests.

The opt-in API is available on the facade and `WorkdayManager`, returning an immutable `Zarbinco\LaravelWorkdays\Data\SlaResult`. A configured profile with valid working hours needs no SLA-specific profile settings. No aliases, Carbon macros, tables, jobs, or Composer dependencies are added.

### Dedicated Tinker Fixture And Complete Usage

Run this config-only fixture in a local `php artisan tinker` session. It defines ordinary, inherited, split-window, and overnight support calendars and preserves the original config for restoration. All example dates are in 2026.

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$slaOriginalConfig = config('workdays');
$slaWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$slaBase = [
    'weekends' => ['Saturday', 'Sunday'],
    'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
    'custom_holidays' => [],
    'extra_working_days' => [],
    'working_hours' => array_fill_keys($slaWeekdays, [['09:00', '17:00']]),
];
config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles' => array_replace(config('workdays.profiles', []), [
        'sla_support_base' => $slaBase,
        'sla_support' => [
            'extends' => 'sla_support_base',
            'working_hours' => ['Thursday' => [['09:00', '20:00']]],
        ],
        'customer_support' => ['extends' => 'sla_support'],
        'sla_split' => [
            'extends' => 'sla_support_base',
            'working_hours' => array_fill_keys($slaWeekdays, [['09:00', '12:00'], ['13:00', '17:00']]),
        ],
        'sla_night' => [
            'extends' => 'sla_support_base',
            'allow_overnight_working_hours' => true,
            'working_hours' => [
                'Monday' => [['22:00', '06:00']],
                'Tuesday' => [], 'Wednesday' => [], 'Thursday' => [],
                'Friday' => [['22:00', '06:00']],
            ],
        ],
    ]),
]);

$slaStartedAt = '2026-11-02T15:00:00Z';
$slaOptions = ['profile' => 'sla_support', 'timezone' => 'UTC', 'target_minutes' => 240];
$withoutPause = Workday::evaluateSla($slaStartedAt, '2026-11-03T10:30:00Z', $slaOptions);
// dueAt November 3, 11:00 UTC; consumed=210, remaining=30, status=active.

$closedPauses = [['started_at' => '2026-11-03T10:00:00Z', 'ended_at' => '2026-11-03T11:00:00Z']];
$withPause = Workday::evaluateSla($slaStartedAt, '2026-11-03T11:30:00Z', array_replace(
    $slaOptions,
    ['pauses' => $closedPauses],
));
// dueAt November 3, 12:00 UTC; consumed=210, paused=60, remaining=30.
$withPause->toArray();

// The same API without the facade:
app(\Zarbinco\LaravelWorkdays\WorkdayManager::class)
    ->evaluateSla($slaStartedAt, '2026-11-03T11:30:00Z', array_replace($slaOptions, ['pauses' => $closedPauses]));
```

### Options, Timestamps, And Errors

| Option | Requirement / default | Accepted value |
|---|---|---|
| `profile` | Required | Non-empty configured profile name with valid working hours; inheritance is supported. |
| `timezone` | Required | Explicit IANA service-calendar timezone, such as `UTC` or `Asia/Tehran`; no inference from the server or application timezone. |
| `target_minutes` | Required | Strict PHP integer `1`–`1,000,000`, representing an exact working-time budget. |
| `pauses` | Optional; `[]` | Ordered-list structure containing at most `1,000` submitted pause arrays; input order may be arbitrary. Each array has exactly `started_at` and `ended_at`. |

Unknown options, missing required values, numeric strings, floats, booleans, explicit null option values, malformed containers/shapes, and invalid profile settings are rejected with `WorkdayConfigurationException`. Only a pause's `ended_at` may be null. Invalid timestamps, interval chronology, or multiple ongoing pauses throw `InvalidArgumentException`. A finite calculation that cannot fit the configured horizon throws a profile/limit-specific `RuntimeException`.

Both main timestamps are mandatory; the engine never substitutes `now()`. `evaluatedAt` must be at or after `startedAt`. Main and pause timestamps accept `string|\DateTimeInterface` using the existing strict absolute formats: naive space-separated `Y-m-d H:i[:s[.microseconds]]`, or ISO `T` timestamps with `Z`/`±HH:MM`; a fraction has 1–6 digits and requires seconds. Naive strings use the explicit `timezone`. Offset-bearing inputs/date objects preserve their instant, and mutable caller objects are not modified. Internally and in results, instants are immutable UTC values with seconds and microseconds preserved.

Empty or relative strings, date-only input, impossible Gregorian dates, malformed offsets, nonexistent local times, and ambiguous naive DST-fold times are rejected. Supply an explicit offset or `DateTimeInterface` instant to select a repeated local-time occurrence. These SLA parsing rules do not change the older APIs' parsing contracts.

Every pause must start at or after the SLA start and no later than evaluation. A closed pause must end strictly after its start and no later than evaluation; future scheduled pauses and zero-length closed pauses are invalid. At most one pause may remain open. The `1,000` limit applies before normalization, including duplicate submitted intervals.

### Working Windows And Pause Rules

The selected calculator's `workingWindowsFor()` remains authoritative. SLA accounting respects config/database/chain precedence, Gregorian/Jalali/Hijri and exact holidays, extra working days, half-days, lunch breaks, special-date/seasonal hours, inherited settings, and overnight fragments. Calendar predicates keep their existing meanings. A valid overnight carry-in may consume SLA time on a receiving weekend/holiday even though that date is not a business date. No cutoff policy or additional warehouse preparation is automatically applied.

Pauses are half-open `[started_at, ended_at)`: their start stops eligible consumption, and their end permits it again. Unsorted, duplicate, overlapping, and touching pauses are normalized into their union once. For example, 10:00–10:45 and 10:30–11:00 exclude one 10:00–11:00 interval, not 75 minutes. Equivalent offset-bearing instants remain the same instants.

Only intersections with working windows contribute `pausedWorkingMinutes`. A pause from 18:00–22:00 has no effect when work ends at 17:00. With 09:00–12:00 and 13:00–17:00 windows, an 11:30–13:30 pause excludes 60 working minutes: 30 before lunch and 30 after it. A weekend-spanning pause similarly excludes only otherwise eligible working time.

The deadline is the first instant at which cumulative eligible, unpaused time reaches the target. It is calculated chronologically through working intervals; wall-clock pause lengths are not simply added to an old deadline. Once that budget has been reached, a later pause cannot move the established deadline or undo the breach. Paused working-time metrics still include all eligible pause intersections through evaluation, including those after the deadline.

### Ongoing Pauses And Resuming

An `ended_at => null` pause remains active at evaluation. Its elapsed portion is excluded through `evaluatedAt`, but its future end is unknown. If the budget has not already been reached, `dueAt` is null; evaluation does not silently resume the SLA. The application resumes it by closing that same pause with the actual end instant and evaluating again.

```php
$openPauses = [['started_at' => '2026-11-03T10:00:00Z', 'ended_at' => null]];
$pausedSla = Workday::evaluateSla($slaStartedAt, '2026-11-03T11:30:00Z', array_replace(
    $slaOptions,
    ['pauses' => $openPauses],
));
// dueAt=null; consumed=180, paused=90, remaining=60; status=paused.

$openPauses[0]['ended_at'] = '2026-11-03T11:30:00Z';
$resumedSla = Workday::evaluateSla($slaStartedAt, '2026-11-03T12:00:00Z', array_replace(
    $slaOptions,
    ['pauses' => $openPauses],
));
// Real resume at 11:30: dueAt=12:30; consumed=210, paused=90, remaining=30.

$atDeadline = Workday::evaluateSla($slaStartedAt, '2026-11-03T11:00:00.000000Z', $slaOptions);
$afterDeadline = Workday::evaluateSla($slaStartedAt, '2026-11-03T11:00:00.000001Z', $slaOptions);
// Exactly due: breached=false, remaining=0. One microsecond later: breached=true.

$latePause = Workday::evaluateSla($slaStartedAt, '2026-11-03T12:00:00Z', array_replace(
    $slaOptions,
    ['target_minutes' => 60, 'pauses' => [['started_at' => '2026-11-03T10:00:00Z', 'ended_at' => null]]],
));
// dueAt remains November 2, 16:00; paused=true, breached=true, status=breached.
```

A pause starting exactly when the budget is reached also retains that known deadline. With no ongoing pause, a not-yet-expired SLA projects remaining working time forward assuming no new future pauses. Later genuine pause records can change that projection. An ongoing pause may legitimately return an unknown due date without scanning indefinitely; an already-known deadline remains available even if the SLA is now paused.

### Exact Breach Status And Minute Precision

The stable statuses have this priority: `breached` when `evaluatedAt > dueAt`; otherwise `paused` when an open pause is active; otherwise `active`. There is no package-level completed/cancelled ticket status. `paused` and `breached` are separate flags and can both be true.

At exactly `dueAt`, breach is false and remaining minutes are zero. One microsecond later breach is true, including when the deadline was exactly at closing and no further working time has elapsed. For example, a 120-minute SLA starting Monday at 15:00 is due at 17:00; evaluation at 17:00:00.000001 is breached even though overdue working minutes are still zero.

The evaluator accumulates exact eligible microseconds on 64-bit PHP, unions pauses, and applies rounding only to final totals:

| Result duration | Rounding / meaning |
|---|---|
| Consumed working minutes | Floor all active eligible time through evaluation, including time beyond the budget. |
| Paused working minutes | Floor all paused eligible time through evaluation. |
| Remaining working minutes | Ceiling of positive unconsumed budget; never negative. |
| Overdue working minutes | Floor positive active-time overage beyond the budget; zero outside additional working time. |

Individual windows and pauses are not rounded independently. A fraction of a minute can therefore produce one remaining minute, or a true breach with zero overdue whole minutes. Breach uses the actual deadline instant, not rounded totals. Existing legacy minute/hour calculations retain their historical precision and are not rewritten for this API.

### Shared Horizon And Timezone Boundaries

`workdays.max_scan_days` is the number of allowed future profile-local calendar-date labels measured from the SLA's start date, day zero. Its final date is inclusive. Pauses, resumption, later windows, and projection do not restart the horizon. Skipped civil dates consume labels without extending it. Evaluation outside the permitted local dates is an error, even if a pause is open.

A finite deadline must land on an allowed date. A `24:00` working-window end is next local midnight, so a deadline at midnight after the final allowed date is outside this SLA horizon. If no finite deadline is available within the bound, the evaluator throws instead of inventing one. A valid ongoing pause with an unknown future resume can return `dueAt = null`, provided evaluation itself is within the bound.

Window boundaries use actual profile-local dates and UTC instants, not fixed 86,400-second days. Existing enhanced-schedule policies for nonexistent/ambiguous boundaries, skipped midnight, and skipped civil dates remain in force. Valid spring-forward/fall-back overnight shifts consume their real elapsed time. Use an explicit service-calendar timezone consistently; routing selects a profile name and does not infer its timezone.

### Results And Serialization

`SlaResult` exposes the following immutable values and stable snake_case fields. In the one-hour closed-pause example, the target is 240, consumed 210, paused 60, remaining 30, overdue 0, and the deadline is Tuesday 12:00 UTC.

| Property | Serialized key | Meaning |
|---|---|---|
| `profile` | `profile` | Selected configured profile name. |
| `timezone` | `timezone` | Explicit local calendar timezone. |
| `startedAt` | `started_at` | Original start as UTC `CarbonImmutable`. |
| `evaluatedAt` | `evaluated_at` | Evaluation instant as UTC `CarbonImmutable`. |
| `targetMinutes` | `target_minutes` | Exact integer working-minute budget. |
| `consumedWorkingMinutes` | `consumed_working_minutes` | Floored active working minutes. |
| `pausedWorkingMinutes` | `paused_working_minutes` | Floored paused working minutes. |
| `remainingWorkingMinutes` | `remaining_working_minutes` | Ceiled non-negative remainder. |
| `overdueWorkingMinutes` | `overdue_working_minutes` | Floored non-negative active overage. |
| `dueAt` | `due_at` | UTC `CarbonImmutable`, or null only for a genuinely unknown ongoing-pause deadline. |
| `paused` | `paused` | Whether an ongoing pause exists at evaluation. |
| `breached` | `breached` | Whether evaluation is strictly after a known deadline. |
| `status` | `status` | `active`, `paused`, or `breached`, using the priority above. |

`toArray()` is JSON-compatible. All non-null timestamps use `Y-m-d\TH:i:s.uP`, six microsecond digits, and explicit `+00:00`; for example, `2026-11-03T12:00:00.000000+00:00`. The result does not retain mutable caller objects or add customer-specific data. Identical inputs and effective calendar rules produce identical results.

### Return-Request Integration And Responsibilities

This application integration example assumes the named profile already exists. The application explicitly chooses the evaluation instant; passing `now()` here is its decision, while deterministic recalculation can use a recorded instant instead.

```php
$result = Workday::evaluateSla(
    $returnRequest->created_at,
    now(),
    [
        'profile' => 'customer_support',
        'timezone' => 'Asia/Tehran',
        'target_minutes' => 240,
        'pauses' => [['started_at' => $pauseStartedAt, 'ended_at' => $pauseEndedAt]],
    ],
);
if ($result->breached) {
    // The application decides whether to escalate or notify; this call sends nothing.
}
```

The application records allowed pause reasons and timestamps, controls access, and manages completed/cancelled states. It may resolve a profile first with `resolveProfile($context)` and pass `$selection->profile`; `evaluateSla()` does not reroute context internally. Inheritance and child-specific database identity follow Phase 05: parent database holiday rows are not automatically inherited. Config/database/chain provider precedence remains unchanged.

One calculator/effective configuration is reused per evaluation, pauses are normalized once, and scanning advances through bounded dates/windows and pause boundaries rather than every minute. Date windows are reused within the operation; providers are not reconstructed inside scanning loops or queried for every pause fragment. Deadline search stops when the budget is reached, while accounting continues through evaluation as needed for consumed/overdue metrics. No global or persistent cache is introduced, and ordinary API performance is unchanged.

The SLA engine runs only when explicitly called. It does not change existing calculators, timestamp parsers, result objects, or Phase 06 snapshot schemas/fingerprints. Recalculation uses the supplied pauses and effective calendar rules; storing historical SLA results or applying escalation policies remains application work.

### Twelve Manual Checks

Use the dedicated UTC fixture above, keeping production records untouched. Restore a profile's test config between calendar variants.

| Check | Action | Expected result |
|---|---|---|
| 1. Support hours | Inspect the base/inherited fixture. | Monday–Friday 09:00–17:00; the child explicitly extends Thursday to 20:00. |
| 2. Start | Use `$slaStartedAt`. | Monday November 2, 15:00 UTC; explicitly supplied. |
| 3. Budget | Use `$slaOptions`. | Exactly 240 working minutes. |
| 4. Original deadline | Inspect `$withoutPause`. | Tuesday November 3, 11:00; at 10:30 consumed 210 and remaining 30. |
| 5. Closed pause | Add `$closedPauses`. | Tuesday 10:00–11:00, wholly historical at evaluation. |
| 6. Adjusted deadline | Inspect `$withPause->dueAt`. | Tuesday 12:00; only eligible paused time changes it. |
| 7. Remaining time | Inspect `$withPause` at 11:30; optionally supply overlapping 10:00–10:45 / 10:30–11:00 pauses. | Consumed 210, paused 60, remaining 30; overlap gives the same result. |
| 8. Exact boundary | Inspect `$atDeadline` / `$afterDeadline`; repeat the 120-minute closing example. | Exactly due is not breached; one microsecond later is breached, including just after closing. |
| 9. Ongoing pause | Inspect `$pausedSla`. | Null due date; consumed 180, paused 90, remaining 60; status `paused`. |
| 10. Real resume | Inspect `$resumedSla`; inspect `$latePause` too. | Actual resume gives 12:30; late pause keeps the old deadline and breach despite `paused=true`. |
| 11. Calendar variants | Add base `custom_holidays => ['2026-11-03' => 'Test holiday']`, then evaluate the no-pause case; restore it and evaluate `sla_night` from Monday 23:00 with target 180 and pause 23:30–Tuesday 00:30. | Holiday moves due time to Wednesday 11:00; the overnight pause gives Tuesday 03:00. Evaluate after each closed pause has ended. |
| 12. Side effects | Inspect application state after these config-only calls. | No database write, job, notification, reservation, or ticket-state mutation. |

For the split-window profile, also verify that an 11:30–13:30 pause counts only 60 paused working minutes. Restore the session with `config(['workdays' => $slaOriginalConfig]);`. The package supplies calculations; your application decides whether any workflow transition, reminder, or escalation should follow.

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
Workday::resolveProfile($context);
Workday::profileFor($context);
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
Workday::captureDeliveryPromise($receivedAt, $plan, ['horizon_days' => 14]);
Workday::compareDeliveryPromise($snapshotOrPayload);
Workday::deliverySlots($readyAt, $options);
Workday::evaluateSla($startedAt, $evaluatedAt, $slaOptions);
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
