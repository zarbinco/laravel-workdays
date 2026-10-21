# Getting started

[Documentation](README.md) · [فارسی](../fa/getting-started.md)

Use a named calendar to move dates around weekends and holidays. Add working hours only when you need time-of-day calculations.

## Install

The package requires PHP `^8.2` and supports Laravel 12 and 13 through its declared Illuminate dependencies. Laravel's own PHP requirements also apply. The current source is documented as unreleased `1.0.0` in the [changelog](../../CHANGELOG.md). These examples describe this checkout; a published version selected by Composer can have a different capability set. Use documentation matching your installed version.

```bash
composer require zarbinco/laravel-workdays
php artisan workdays:install
```

Laravel discovers the service provider and `Workday` facade automatically. The installer publishes `config/workdays.php`; it does not run migrations, initialize Database definitions, edit `.env`, or fetch holidays. Existing configuration is protected unless you deliberately request `--force`.

Alternatively, publish only the base configuration:

```bash
php artisan vendor:publish --tag=workdays-config
```

## A complete Config example

Run this in an isolated local `php artisan tinker` session in your Laravel application. It replaces only the session's calendar configuration. The `global` profile is defined here explicitly, so the result does not depend on a previously customized application calendar.

<!-- example: config-quick-start -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

config([
    'workdays.driver' => 'config',
    'workdays.max_scan_days' => 3660,
    'workdays.definitions' => [
        'default_profile' => 'global',
        'include_start_date' => false,
        'hijri' => ['method' => 'mathematical', 'adjustment' => 0],
        'profile_routing' => null,
        'profiles' => [
            'global' => [
                'weekends' => ['Saturday', 'Sunday'],
                'holidays' => [],
                'custom_holidays' => [],
                'extra_working_days' => [],
                'working_hours' => array_fill_keys(
                    ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                    [['09:00', '10:00'], ['11:00', '12:00']],
                ),
            ],
        ],
    ],
]);

echo Workday::profile('global')->nextBusinessDay('2026-11-06')->toDateString(), PHP_EOL;
```
```text
2026-11-09
```

November 6 is Friday. Saturday and Sunday are closed, so the next business date is Monday. Date-returning methods return `Carbon\CarbonImmutable`; the supplied input is not mutated.

The remaining task guides explicitly use this fixture as a prerequisite. Start a fresh local session or reapply this fixture before each guide; examples that change definitions affect subsequent calculations in that session. In an application, keep the equivalent `definitions` in `config/workdays.php` rather than replacing settings per request.

## Select a source

`workdays.driver` selects exactly one complete source:

| Driver | Where operational definitions come from |
|---|---|
| `config` | `workdays.definitions`; calculations need no Database connection or schema. |
| `database` | One explicitly published complete Database revision; file definitions are ignored. |

There is no mixed mode or fallback. The [Database guide](database.md) provides a separate schema, initialization, and publication path. `max_scan_days`, the connection, macro registration, and import-tool settings are bootstrap configuration outside the operational document.

## Iran calendars

```bash
php artisan workdays:install --preset=iran
```

`--persian` is an alias for `--preset=iran`. The preset chooses Thursday/Friday weekends and recurring Jalali/Hijri holidays. The base configuration also ships an Iran profile with Thursday/Friday weekends, and a Saturday/Sunday `global` profile. Review those choices for your organization.

A complete official annual calendar is not automatically bundled, downloaded, or imported. One-off closures, official announcements, and company exceptions need deliberate exact-date entries or a reviewed local dataset. See [holidays and import](configuration.md#holidays-and-iran-data).

Continue with [Business days](business-days.md), [Working hours](working-hours.md), or the [configuration reference](api-reference.md#configuration).
