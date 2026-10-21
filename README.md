# Laravel Workdays

[![Tests](https://github.com/zarbinco/laravel-workdays/actions/workflows/tests.yml/badge.svg)](https://github.com/zarbinco/laravel-workdays/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/zarbinco/laravel-workdays.svg)](https://packagist.org/packages/zarbinco/laravel-workdays)
[![License](https://img.shields.io/packagist/l/zarbinco/laravel-workdays.svg)](LICENSE)

Calculate business days, working time and calendar-aware delivery dates in Laravel. Define separate calendars for teams, warehouses or carriers, using Gregorian, Jalali and Hijri holidays.

Supports **PHP 8.2+ and Laravel 12 or 13**, subject to the selected Laravel version's own PHP requirements. The [changelog](CHANGELOG.md) labels the current repository changes **1.0.0 — Unreleased**; these guides describe this checkout, not a promise about an older published package.

## Install and calculate

```bash
composer require zarbinco/laravel-workdays
php artisan vendor:publish --tag=workdays-config
```

In a Laravel application with the shipped `global` profile unchanged and the Config source selected:

<!-- example:quick-start -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

echo Workday::profile('global')->nextBusinessDay('2026-11-06')->toDateString(), PHP_EOL;
```

```text
2026-11-09
```

Friday's next business day is Monday because this profile closes Saturday and Sunday. For a complete independent calendar definition, follow [Getting started](docs/en/getting-started.md).

## Documentation

[English](docs/en/README.md) · [فارسی](docs/fa/README.md)

- [Business days and holiday reasons](docs/en/business-days.md)
- [Working hours and exact time calculations](docs/en/working-hours.md)
- [Profiles, inheritance and routing](docs/en/configuration.md)
- [Database calendars and revision publication](docs/en/database.md)
- [Delivery planning, cutoff and reverse scheduling](docs/en/delivery.md)
- [Delivery and pickup slots](docs/en/delivery-slots.md)
- [SLA tracking](docs/en/sla.md), [recurring schedules](docs/en/recurring-schedules.md), and [bulk calculations](docs/en/bulk-calculations.md)
- [Saved delivery promises](docs/en/snapshots.md) and [Laravel integration](docs/en/laravel-integration.md)

For contributors: [architecture](docs/ARCHITECTURE.md), [capability and test matrix](FEATURE_PRESERVATION_MATRIX.md), [numerical history](NUMERICAL_CHANGELOG.md), and [verification](docs/verification.md).

Report issues through the [issue tracker](https://github.com/zarbinco/laravel-workdays/issues). Licensed under the [MIT License](LICENSE).
