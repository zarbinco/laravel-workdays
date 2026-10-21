# Documentation And Calculation Verification

[English guides](en/README.md) · [راهنمای فارسی](fa/README.md) · [Architecture](ARCHITECTURE.md)

Use this contributor reference when changing documentation or investigating a calculation boundary. Consumer examples belong in the task guides; this page explains how their source, navigation and output are checked.

## Capability-to-page map

Both languages cover the same package capabilities. Each topic links directly to its counterpart.

| Reader task and reference coverage | English | فارسی |
|---|---|---|
| Installation, supported environment, first complete Config calculation and exact output | [Getting started](en/getting-started.md) | [شروع کار](fa/getting-started.md) |
| Complete source selection, shared options, named profiles, inheritance/clearing and routing specificity/fallback | [Configuration](en/configuration.md) | [تنظیمات](fa/configuration.md) |
| Schema/connection setup, initial publication, revision edits/conflicts/no-op, raw transfer, annual Iran import and explicit-clear guard | [Database](en/database.md) | [پایگاه‌داده](fa/database.md) |
| Membership, weekends, three holiday calendars, custom/extra dates, next/previous/add/subtract/diff, include-start, explainability and result/skipped dates | [Business days](en/business-days.md) | [روزهای کاری](fa/business-days.md) |
| Ordinary/split/half-day/special/seasonal/overnight/full-day hours, navigation, exact consumption, aggregate truncation and timezone boundaries | [Working hours](en/working-hours.md) | [ساعت کاری](fa/working-hours.md) |
| Cutoff, work/handoff/transit stages, per-profile timezone, global horizon and latest receipt for a deadline | [Delivery](en/delivery.md) | [برنامه‌ریزی تحویل](fa/delivery.md) |
| Notice, grids, eligible intervals, local-date/count bounds, transition filtering and capacity responsibility | [Slots](en/delivery-slots.md) | [بازه‌های تحویل](fa/delivery-slots.md) |
| Working-minute targets, overlapping/open pauses, real resume events, due dates and exact breach status | [SLA](en/sla.md) | [زمان پاسخ‌گویی](fa/sla.md) |
| Gregorian/Jalali month arithmetic, weekly cycles, original anchors, short months, adjustment, collision and bounds | [Recurrence](en/recurring-schedules.md) | [برنامه‌های تکرارشونده](fa/recurring-schedules.md) |
| Ten operation schemas, typed results, limits, collect/throw, source failures, chunking and prefetch | [Bulk](en/bulk-calculations.md) | [محاسبات گروهی](fa/bulk-calculations.md) |
| Capture, offline restore, current comparison, integrity, numerical semantics and runtime limitations | [Snapshots](en/snapshots.md) | [تعهد ثبت‌شده تحویل](fa/snapshots.md) |
| Facade/manager, Carbon/CarbonImmutable macros, validation rules and application integration | [Laravel integration](en/laravel-integration.md) | [اتصال به لاراول](fa/laravel-integration.md) |
| Complete method inventory, option/result contracts, command signatures and error categories | [API reference](en/api-reference.md) | [مرجع API](fa/api-reference.md) |
| Source setup, missing hours, search exhaustion, date parsing, official data and operational mistakes | [Troubleshooting](en/troubleshooting.md) | [رفع اشکال](fa/troubleshooting.md) |

The [capability matrix](../FEATURE_PRESERVATION_MATRIX.md) maps these behaviors to exact existing regression methods. The [numerical history](../NUMERICAL_CHANGELOG.md) keeps corrected outputs and historical evidence separate from current documentation verification.

## Run documentation checks

Use the repository's installed development dependencies in an independent test environment:

```bash
php vendor/bin/phpunit tests/Feature/DocumentationStructureTest.php tests/Feature/DocumentationExamplesTest.php tests/Feature/ReleaseReadinessTest.php
composer test
composer validate --strict
composer format:test
git diff --check
```

The structure checks cover counterpart pages, relative files/anchors, source-based capability coverage, prohibited editorial wording, direction wrapper nesting, executable-text safety and code preservation through the installed GFM-compatible Markdown renderer. They avoid requiring a particular prose sentence or placing every chapter in one file.

Marked examples are extracted from the actual Markdown and executed against controlled Testbench settings. Paired programs and outputs are compared exactly, with line endings normalized to LF. Examples with explicit prerequisites use those documented fixtures. Unmarked reference fragments and application integration sketches are not presented as standalone executed programs.

The [working-time verification example](examples/working-time-verification.php) is a complete additional check of exact intervals, delivery, reverse planning, SLA, snapshots and source lifetime. Read its instructions before running it in Tinker. Its Database branch creates only a named independent in-memory SQLite connection and restores local configuration afterward; it does not use an application's selected database.

## Render and inspect

Render the actual Markdown using a GFM-compatible parser with raw direction markup allowed. The existing `league/commonmark` dependency is available in the development installation; no site framework is required. Check the generated HTML rather than rebuilding the prose as a separate HTML mockup.

Inspect desktop and narrow viewports. In Persian pages, paragraphs, headings, lists and descriptive tables must inherit `rtl`; code/output blocks must inherit `ltr`. Verify inline chains, filenames, dates, mixed table cells, punctuation, numbered lists and horizontal code scrolling. Blank lines around `<div>` boundaries allow Markdown tables and fences to remain Markdown.

Compare each rendered `pre > code` text node to the original fence contents. Do not insert directional Unicode controls into code, identifiers or URLs. Persian ZWNJ is valid typography and is not a directional override.

Local GFM parsing, browser DOM checks and screenshots establish local behavior only. GitHub sanitization and a hosted repository preview are separate checks; record them only if actually inspected. Keep review screenshots and generated HTML outside the package source. Do not upload private material or publish a commit merely to obtain a screenshot.

## Database and resource checks

Ordinary feature/example tests use disposable SQLite databases. Opt-in MySQL checks in [MySQL integration](../tests/Integration/MySqlDefinitionSourceTest.php) additionally require an explicitly provisioned empty `workdays_test_*` database and `WORKDAYS_RUN_MYSQL_TESTS=1`. Verify the server identity, selected connection and cleanup plan first. Never point the test at application data.

The [bulk resource fixture](../tests/Benchmarks/BulkCalculationsBenchmark.php) and [working-time resource fixture](../tests/Benchmarks/UnifiedWorkingTimeBenchmarkTest.php) are optional measurements. Read their opt-in guards and fixed workloads before execution. Query counts and numerical parity assertions differ from observed elapsed time and memory. Historical observations in the matrix are neither timing thresholds nor results newly executed for a documentation edit.

Current calculations do not implement application authorization, persistence of orders or events, queue execution, notifications, payments or capacity reservations. Tests of these package calculations do not establish those application behaviors.
