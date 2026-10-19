# Changelog

All notable changes to `zarbinco/laravel-workdays` will be documented in this file.

## 1.0.0 - Unreleased

- Added opt-in recurring business schedules through `recurringSchedule()`, with direct original-anchor weekly and Gregorian/Jalali monthly cycles, strict date-only inputs, month-end handling, and no drift after holiday or short-month adjustments.
- Added bounded next/previous/skip business-date adjustment, explicit skipped cycles, collision rejection/allowance with chronological safeguards, and immutable date-only result objects with stable serialization using existing calendars, storage, and inherited profiles.
- Added focused recurrence/calendar/storage/timezone regression coverage and aligned English/Persian subscription examples, limits, application responsibilities, and seventeen-check local verification guides without automatic order creation, payments, or execution.
- Added opt-in reverse delivery scheduling through `reversePlanDelivery()`, finding the latest calendar-feasible order receipt for an inclusive completion deadline with an explicit bounded lookback and the existing forward-plan schema.
- Added immutable UTC `ReverseDeliveryPlanResult` objects with nullable infeasibility results, exact microsecond slack, separate receipt/planning/first-stage timestamps, and a verified nested forward result while preserving cutoff, calendar, timezone, and stage semantics.
- Added focused reverse-scheduling regression coverage and aligned English/Persian API, e-commerce integration, search limitations, and thirteen-check local verification guides without order placement, reservations, or persistence.
- Added opt-in business SLA evaluation through `evaluateSla()` with immutable UTC results, exact working-time budgets, consumed/paused/remaining/overdue metrics, and stable active/paused/breached statuses.
- Added closed and ongoing pause normalization, explicit resume handling, microsecond-aware deadlines and breach boundaries, and a shared start-date horizon while reusing existing calendars, inherited schedules, and overnight behavior.
- Added focused SLA/calendar/storage/timezone regression coverage and aligned English/Persian API, return-request integration, precision, limitations, and manual verification documentation without persistence or automatic monitoring.
- Added opt-in delivery-promise capture and comparison with portable immutable snapshots, bounded effective-calendar evidence, deterministic calendar fingerprints, and separate payload-integrity checks.
- Added frozen config/database/chain holiday materialization for original estimates, explicit shared horizons, inherited-profile and overnight/cutoff integration, runtime metadata, and observed calendar-day differences.
- Added unchanged/earlier/later/unresolvable comparison results without automatic promise updates or persistence, strict snapshot restoration, focused regression coverage, and aligned English/Persian usage and manual verification guides.
- Added optional hierarchical profile inheritance with bounded parent chains, normalized weekday overrides, explicit section-aware merge/clear rules, and selected-child identity across config/database/chain storage.
- Added deterministic context-based profile routing through `resolveProfile()` and `profileFor()`, with strict matching, specificity/configuration-order precedence, explicit fallback, and immutable `ResolvedProfile` results without raw context exposure.
- Integrated inherited cutoff, overnight, and exceptional schedules with existing delivery planning and slot generation, preserving lazy cutoff validation and legacy paths, with focused regression tests and aligned English/Persian configuration and manual guides.
- Added optional exact-date and inclusive seasonal working-hour overrides with explicit empty schedules, strict Gregorian validation, and deterministic calendar-first precedence.
- Added explicitly enabled overnight and full-day schedules, normalized daily carry-in windows, overlap-safe working-minute calculations, and strict local-boundary handling across timezone transitions while preserving the legacy profile path.
- Integrated enhanced schedules with existing delivery planning and single-day slot generation, including representable `24:00` slot endings, focused regression coverage, and aligned English/Persian configuration and manual verification guides.
- Added opt-in calendar-eligible delivery and pickup intervals through `Workday::deliverySlots()` and `WorkdayManager`, with immutable UTC `DeliverySlotsResult` and `DeliverySlot` objects and explicit local serialization.
- Added strict slot-policy validation, elapsed notice buffers, working-window-aligned grids, bounded local-date searches, result limits, and conservative timezone-transition handling using existing profile calendars and holiday providers.
- Added Phase 02 readiness integration, focused slot/calendar/storage/timezone and compatibility coverage, and aligned English/Persian usage, application responsibilities, limitations, and manual verification documentation.
- Added opt-in multi-stage delivery estimates through `Workday::planDelivery()` and `WorkdayManager`, with immutable UTC `DeliveryPlanResult` and `DeliveryStageResult` objects.
- Added profile-local working-minute stages, simultaneous two-profile handoffs, strictly future business-date lead times, optional reuse of order cut-off routing, and one global planning horizon.
- Added strict delivery-plan validation and timestamp handling, per-invocation calculator reuse, focused calendar/storage/timezone integration tests, and aligned English/Persian configuration, store integration, result, and manual verification documentation.
- Added opt-in per-profile order cut-off resolution through the default facade/manager and selected profile calculators, with an immutable `CutoffResult` and stable reason codes.
- Added strict timestamp normalization in a required policy timezone, exclusive microsecond-aware boundaries, weekday cut-off overrides, disabled weekdays, and bounded future business-date assignment independent of working hours.
- Added English/Persian cut-off configuration, integration and manual verification examples, plus focused calendar, storage, timezone, parsing, and compatibility tests.
- Added Laravel validation rule factory support.
- Added day-level validation rules for business days, non-working days, weekends, calendar holidays, custom holidays, and extra working days.
- Added business-time validation rules and inclusive relative business-day validation rules.
- Added README examples and tests for validation rules.
- Added optional Carbon and CarbonImmutable macro registration.
- Added workday-prefixed Carbon macro methods for day-level, explainability, and business-time APIs.
- Added optional short Carbon macro aliases with conflict-safe registration.
- Added README examples and tests for Carbon macro configuration, profile routing, and mutable/immutable return behavior.
- Added optional `working_hours` profile config for business-time calculations.
- Added business-time APIs for checking, navigating, adding, and diffing working minutes and hours.
- Added support for half-days, split working windows, and extra-working-day fallback hours.
- Added business-hours validation, README examples, and test coverage while preserving existing day-level APIs.
- Added opt-in Iran official yearly calendar dataset support with inactive/null defaults.
- Added the optional Iran 1405 official holiday dataset resource sourced from the University of Tehran Calendar Center official calendar.
- Added explicit `workdays:import-iran-calendar` support with dry-run, idempotent import, profile override, and force-update behavior.
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
