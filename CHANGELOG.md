# Changelog

All notable changes to `zarbinco/laravel-workdays` will be documented in this file.

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
