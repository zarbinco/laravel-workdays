# Changelog

All notable changes to `zarbinco/laravel-workdays` will be documented in this file.

## 1.0.0 - Unreleased

- Added optional Carbon and CarbonImmutable macro registration.
- Added workday-prefixed Carbon macro methods for day-level, explainability, and business-time APIs.
- Added optional short Carbon macro aliases with conflict-safe registration.
- Added README examples and tests for Carbon macro configuration, profile routing, and mutable/immutable return behavior.
- Added optional `working_hours` profile config for business-time calculations.
- Added business-time APIs for checking, navigating, adding, and diffing working minutes and hours.
- Added support for half-days, split working windows, and extra-working-day fallback hours.
- Added business-hours validation, README examples, and test coverage while preserving existing day-level APIs.
- Added opt-in Iran official yearly calendar dataset support.
- Added the Iran 1405 official holiday dataset sourced from the University of Tehran Calendar Center official calendar.
- Added `workdays:import-iran-calendar` with dry-run, idempotent import, profile override, and force-update support.
- Added README documentation and tests for official Iran yearly datasets and import behavior.
- Added `DayInfo` and `DayReason` value objects for date explainability.
- Added `explain()` on the facade/default profile and profile calculators.
- Added reason reporting for weekends, recurring Gregorian/Jalali/Hijri holidays, custom holidays, and extra working days.
- Added override reporting so extra working days can show which weekend or holiday reasons they superseded.
- Added explainability tests and README examples.
- Hardened the v1.0.0 release surface with an explicit Laravel/PHP CI matrix.
- Narrowed declared Laravel support to Laravel 12 and 13 so Composer metadata matches secure, installable CI coverage.
- Improved migration publishing to use Laravel's package migration publishing helper when available.
- Tightened config validation for unknown holiday calendars, invalid recurring holiday keys, invalid weekend values, storage drivers, profile shape, and `max_scan_days`.
- Polished README badges, compatibility notes, storage documentation, migration publishing notes, and Iran preset limitations.
- Added the core Laravel-first workday and business-day calculation engine.
- Added config-backed workday profiles with default profile support.
- Added Gregorian, Jalali, and Hijri recurring holiday support.
- Added exact custom holidays and extra working day overrides.
- Added Iran preset config and installer support.
- Added config, database, and chain storage drivers.
- Added publishable database migrations and Eloquent models for database storage.
- Added Artisan installer and publish tags for config, Iran preset, and migrations.
- Added max scan protection for impossible business-day profiles.
- Added stricter config, date-key, storage-driver, and missing-migration validation.
- Added release documentation, MIT license, contribution/security notes, formatting scripts, and GitHub Actions test workflow.

## 0.5.1 - Unreleased

- Declared Laravel console, database, and filesystem component dependencies.
- Standardized storage driver validation.
- Improved database storage error message when migrations are missing.

## 0.5.0 - Unreleased

- Added `config`, `database`, and `chain` holiday storage drivers.
- Added database migrations for recurring holiday rules and exact special dates.
- Added Eloquent models for `workday_holiday_rules` and `workday_special_dates`.
- Added a holiday provider layer to keep the public Workday API unchanged across storage modes.
- Added `workdays-migrations` publish tag.
- Updated `workdays:install` to support `--storage=config|database|chain`.
- Preserved extra working day precedence over weekends and holidays in every storage mode.
- Documented database storage setup, schema, and limitations.

## 0.4.0 - Unreleased

- Added Iran preset config.
- Added Persian install option.
- Added `workdays:install` command.
- Added dedicated publish tag for Iran config.
- Documented Iran preset limitations and override strategy.

## 0.3.0 - Unreleased

- Added Hijri recurring holiday support.
- Added configurable Hijri calculation method.
- Added Hijri holiday detection.
- Updated calendar holiday detection to include Gregorian, Jalali, and Hijri holidays.
- Added Hijri holiday key validation.

## 0.2.0 - Unreleased

- Added Jalali recurring holiday support through Verta.
- Added Jalali holiday detection.
- Added Gregorian/Jalali-specific calendar holiday predicates.
- Added holiday key validation.

## 0.1.1 - Unreleased

- Hardened `isHoliday()` semantics so it means non-working day.
- Added public predicates for weekend, calendar holiday, custom holiday, extra working day, and non-working day checks.
- Added validation for invalid weekend config value types.
- Added tests for predicate semantics, negative inputs, invalid dates, include-start behavior, and `BusinessDayResult`.

## 0.1.0 - Unreleased

- Added Phase 1 foundation package structure.
- Added config-backed workday profiles.
- Added Gregorian business-day calculations using `CarbonImmutable`.
- Added weekday normalization for English and Persian aliases.
- Added support for weekly weekends, recurring Gregorian holidays, custom exact-date holidays, and extra working days.
- Added PHPUnit/Testbench test coverage.
