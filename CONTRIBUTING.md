# Contributing

Thanks for helping improve `zarbinco/laravel-workdays`.

## Local Setup

```bash
composer install
```

## Tests

```bash
composer validate --strict
composer test
composer format:test
```

Run the formatter before opening a pull request:

```bash
composer format
```

## Scope

This package is a Laravel-first workday and business-day calculation engine. Calendar adapters resolve recurring holidays; the Jalali adapter also supports monthly recurrence arithmetic. Public date labels remain Gregorian unless an API explicitly describes a calendar-specific operation.

Please avoid adding admin UI, routes, controllers, views, online holiday update services, or external API integrations without prior discussion.

See [verification](docs/verification.md) for documentation examples, renderer checks, isolated database tests and optional resource measurements. Keep executable snippets aligned between the [English](docs/en/README.md) and [Persian](docs/fa/README.md) guides.
