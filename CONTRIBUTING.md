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

This package is a Laravel-first workday and business-day calculation engine. Calendars are used internally to resolve recurring holiday definitions.

Please avoid adding admin UI, routes, controllers, views, online holiday update services, or external API integrations without prior discussion.
