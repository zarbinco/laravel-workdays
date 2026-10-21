# Changelog

Changes to `zarbinco/laravel-workdays`. Entries marked **Unreleased** describe repository work and do not claim a published package version or release date.

## 1.0.0 - Unreleased

### Calendar definitions and storage

- Provide complete, exclusive `config` and `database` sources for shared settings, named profiles, inheritance, routing, holidays, working hours and cutoff. A calculation uses one source without field-level mixing or silent fallback.
- Use root `driver` with operational settings under `definitions`: `default_profile`, `include_start_date`, `hijri`, `profile_routing` and `profiles`. Reject old root settings, `storage.driver`, `chain` and `hybrid` with explicit guidance.
- Store Database definitions as validated immutable revisions, raw profile documents and an active-revision pointer. Require an expected revision for edits, reject stale writes, support identical-content no-ops and publish historical content as a new revision.
- Pin revision and connection per operation; reuse selected profiles, ancestors and negative lookups. A retained calculator keeps its original view. New operations observe later committed definitions.
- Publish complete logical documents atomically, with deterministic content integrity, rollback on failure and caller-owned outer transactions. Preserve exact case/accent/numeric profile names and raw omission, aliases, nulls, false flags and empty sections.
- Add strict versioned JSON export/import with explicit source selection, initialization/current-revision preconditions, protected output files and zero-write dry runs. No migration, initialization, dataset fetch, source switch or data conversion occurs automatically.
- Retire partial holiday tables/models/provider internals. Removing package migration files does not drop application tables or remove already published migration copies.
- Reject duplicate weekend aliases, unsupported holiday-title types, malformed stored values, corrupt profile documents, duplicate JSON members and publication/transfer budget violations.

### Business days and working time

- Preserve business-day predicates, next/previous dates, addition/subtraction, differences, include-start policy, `calculate()` and explainable `DayInfo`/`DayReason` results.
- Support Gregorian, Jalali and Hijri recurring holidays, exact custom holidays, extra working days, Iran presets and explicit local official-calendar imports.
- Guard calendar imports against reactivating parent holidays excluded by an explicitly cleared child map. `--force` cannot bypass that guard, a stale revision or date validation. Identical imports remain no-ops.
- Provide one effective schedule and microsecond interval engine for ordinary, split, half-day, special, seasonal, overnight and full-day schedules. Preserve calendar-first precedence, owner-date carry-in, overlap union and independent slot windows.
- Preserve seconds and microseconds when consuming integer-minute budgets. Aggregate exact overlap before truncating a public minute difference once; public hour differences remain minutes divided by 60.
- Return the last included microsecond when navigating before an exclusive close. Materialize configured local wall-clock boundaries correctly across daylight-saving changes and skipped midnights.
- Keep fixed search budgets, explicit supported date ranges and guarded duration/hour overflow. Unsupported backwards/split civil-date histories fail explicitly; ordinary repeated-hour periods remain representable.

### Delivery, schedules and tracking

- Add profile-local cutoff resolution with exclusive boundaries, weekday overrides, disabled weekdays and bounded future-date assignment. Skip dates whose actual acceptance window is empty.
- Add multi-stage delivery planning with exact work consumption, simultaneous two-profile handoff, strictly future business-day transit, optional cutoff routing and one global horizon.
- Add delivery/pickup slot grids, notice buffers, local-date bounds, limits and immutable UTC/local serialization. Slots describe calendar eligibility and do not reserve capacity.
- Add reverse scheduling with an inclusive completion deadline, bounded lookback, exact latest receipt and a verified forward result. Correct the split 120-minute lunch example to `11:00:00.000000`; retain legitimate exclusive handoff/cutoff microseconds.
- Add delivery-promise capture, offline restoration, calendar fingerprints, payload integrity, current comparison and immutable original results. Fingerprints detect content differences and are not authentication.
- Use calculation semantics **2**, separately from outer/evidence schema **1**. Reject unsupported older semantics, rehashed corruption and inconsistent current results. Matching timezone data enables complete frozen replay; runtime drift retains captured UTC checks with documented civil-date limitations.
- Restore valid daytime snapshots around Apia's skipped date and Havana's repeated midnight without requiring an unused hypothetical next `24:00`. Continue rejecting invalid actual configured endpoints, skipped-date evidence, cross-date positive time and unsupported split-date history.
- Add SLA working-time targets, overlapping pause union, open pause/resume handling, exact deadlines and breach boundaries, and immutable active/paused/breached results. Applications supply events and perform notifications.
- Add original-anchor weekly and Gregorian/Jalali monthly recurrence, short-month policies, holiday adjustment, explicit skipped cycles, collision policies and bounded chronological safeguards. Recurrence calculates dates without creating orders, payments or jobs.
- Add ordered bulk calculation for ten operation types, typed result retention, bounded batches, collect/throw error handling, context reuse and bounded prefetch hints. Source, database and programming failures remain visible.

### Laravel integration and documentation

- Add day/time/relative-business-day validation rules and conflict-safe Carbon/CarbonImmutable macros, with optional short aliases and mutable/immutable return behavior.
- Add explicit installer presets/source selection and publishing tags. Keep annual Iran data optional: a complete official annual dataset is neither automatically bundled, downloaded nor imported.
- Maintain PHP/Laravel compatibility and opt-in disposable MySQL integration coverage. Historical test and resource evidence is recorded in the [capability matrix](FEATURE_PRESERVATION_MATRIX.md).
- Organize English and Persian task guides, reference material and executable examples; preserve left-to-right code inside right-to-left Persian pages. Correct precision, calendar scope, publication and result-object explanations.

## 0.5.1 - Unreleased

- Declared Laravel console, database and filesystem component dependencies.
- Standardized storage driver validation and missing-migration diagnostics.

## 0.5.0 - Unreleased

- Introduced config/database/chain holiday storage, rule/special-date migrations and models, a holiday provider layer, migration publishing and installer storage selection.
- Preserved extra-working-day precedence and documented storage setup. These partial-storage interfaces are historical; current source selection and schema are described above.

## 0.4.0 - Unreleased

- Added the Iran preset, Persian installation option, installer command and Iran-config publishing tag.
- Documented preset limitations and override behavior.

## 0.3.0 - Unreleased

- Added Hijri recurring holidays, holiday predicates, configurable calculation method and adjustment, and calendar-key validation.

## 0.2.0 - Unreleased

- Added Verta-backed Jalali recurring holidays and calendar-specific predicates.

## 0.1.1 - Unreleased

- Defined `isHoliday()` as non-working-day membership and added distinct weekend, calendar-holiday, custom-holiday and extra-working-day predicates.
- Added checks for invalid weekend values, negative inputs, dates, include-start behavior and `BusinessDayResult`.

## 0.1.0 - Unreleased

- Added the package foundation, named Config calendars, Gregorian business-day calculations with `CarbonImmutable`, weekday aliases, recurring/exact holidays and extra working days.
- Added PHPUnit/Testbench coverage, MIT license, contributor/security notes, formatting scripts and the test workflow.
