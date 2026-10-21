# Troubleshooting

[Documentation](README.md) · [فارسی](../fa/troubleshooting.md)

Start with the source, selected profile, input format, and explicit timezone. Reproduce a decision using a recorded timestamp and a small isolated calendar before changing operational data.

## A minimal diagnostic

Apply the [Config quick start](getting-started.md#a-complete-config-example), then inspect a closed date:

<!-- example: explain-date -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$info = Workday::profile('global')->explain('2026-11-07');
echo json_encode([
    'business' => $info->isBusinessDay,
    'weekend' => $info->isWeekend,
    'reason' => $info->reasons[0]->type,
], JSON_THROW_ON_ERROR), PHP_EOL;
```
```text
{"business":false,"weekend":true,"reason":"weekend"}
```

This checks calendar classification. To diagnose opening hours, inspect `workingWindowsFor()` with an explicit timezone-aware input; a business date can have no open windows.

## Source cannot open

Use exactly `workdays.driver = config` or `database`. Config requires operational settings under `workdays.definitions`; `storage.driver`, old operational root keys, `chain`, and `hybrid` are rejected rather than translated. Config needs no Database schema/connection and never reads Database definitions. Database ignores file definitions, including a conflicting file calendar.

For Database, check the explicit connection, published revision migration, state row, and first publication. Before schema installation the exception gives migration guidance; after schema but before publication it gives initialization guidance. Old holiday tables are insufficient. Boot/config caching/command discovery do not initialize data. Use [the setup path](database.md), never a destructive schema reset as a repair shortcut.

`DefinitionSourceException` is not ordinary calendar unavailability. It propagates from Bulk and snapshot comparison too; there is no Config fallback. Repair setup/corruption and start a new operation. Do not manually edit immutable SQL rows or recompute hashes to conceal corruption.

## A calendar edit is invisible

A running operation and a retained calculator keep their original definitions. Later method calls on that calculator do not refresh it. Request a fresh `Workday::profile()` or manager operation. Database views pin their revision/connection even before a lazy parent read. Caller-owned transactions can delay visibility until their outer commit. No Redis/process-cache flush is required.

If publication raises `DefinitionConflictException`, fetch current raw definitions and reconcile the complete intended document against the new revision. Reusing a stale token, even for identical content, is rejected. Omitting a profile deletes it and can invalidate default, inheritance, or routing references.

## Invalid date or unexpected holiday

Day strings and exact keys are Gregorian `Y-m-d`. Recurrence also uses a Gregorian anchor when its selected monthly calendar is Jalali. Recurring holiday keys are valid `MM-DD` in the named calendar; `jallali` is not `jalali`. General APIs do not accept Jalali/Persian calendar strings automatically.

`isHoliday()` is final non-working status, including weekends/custom holidays. `isCalendarHoliday()` means recurring Gregorian/Jalali/Hijri only. Extra working days override final closure but preserve underlying classifications/reasons. See [Business days](business-days.md).

Iran Thursday/Friday weekends are a configurable choice. Recurring preset values do not constitute a complete official annual calendar. Hijri conversion can differ from local moon-sighting announcements. Review exact Gregorian overrides or a trusted local dataset; no dataset is downloaded/imported automatically.

## No date or time found within the bound

Navigation/addition counts closed calendar labels against `max_scan_days`. Check weekend choices, closures, extra days, empty hours, schedule precedence, and explicit cutoff-disabled weekdays. Increasing a per-call horizon cannot enlarge the configured safety bound. Whole requested working-time differences fail rather than return partial totals; date-only `diffBusinessDays()` instead traverses its requested range without this scan bound.

Forward planning, SLA, recurrence, slots, reverse search, and snapshots have distinct anchors/counting rules. Stages/pauses do not restart a horizon. Slot `days = 7` counts seven labels including day zero; snapshot horizon 7 includes day zero plus seven future labels. See [limits and horizons](api-reference.md#limits-and-horizons).

Zero core minutes preserve a closed input, while zero-minute delivery work normalizes to opening. Previous business time at closing is one microsecond before the end. A work completion at closing does not permit handoff there. See [Working hours](working-hours.md).

## DST or midnight errors

Use explicit timezone-aware objects for core calls and explicit offsets for repeated advanced input times. Advanced parsers reject nonexistent/ambiguous naive clocks, except cutoff's documented ambiguous-occurrence choice. Configured working-window endpoints must exist uniquely; invalid `24:00` cannot be rescued by changing hashes or choosing an arbitrary occurrence.

Valid offset-spanning work counts actual elapsed time. A skipped civil date contributes no phantom time or extra search allowance. Unsupported backwards/split-date histories fail explicitly. Slots intentionally skip ambiguous/nonexistent/cross-transition candidates. Daytime snapshot restoration does not require an unused next midnight, but actual configured boundaries remain strict. Use the [working-time](working-hours.md#timezones-dst-and-bounds), [slot](delivery-slots.md#timezone-transitions), and [snapshot](snapshots.md#frozen-replay-and-timezone-history) policies separately.

## Import rejects cleared inherited holidays

An empty child `custom_holidays` map clears parent dates. Adding an imported date can otherwise reactivate them. The importer rejects any effective changes beyond actual planned writes; skipped entries authorize no change. `--force` only permits differing holiday values and cannot bypass explicit-clear safety, stale tokens, or validation. Resolve the intended inheritance policy first. See [import safety](database.md#import-a-local-iran-calendar).

## Snapshot cannot restore or compare

Check payload/schema/size/types/integrity and calculation semantics. Current outer/evidence schema is 1 and calculation semantics is 2. Unsupported earlier semantics require deliberate regeneration from original inputs; editing version/hash is not migration. Fingerprints are not authentication. Restoring uses frozen evidence without live sources; comparison needs a working current source and never rewrites the original. See [Snapshots](snapshots.md).

## Before reporting a problem

Provide installed package/PHP/Laravel/Carbon/calendar-library/tzdb versions, selected source, a minimal nonprivate calendar, exact inputs/timezones, actual result/exception, and expected behavior. Exclude credentials, customer data, and connection strings. An isolated reproducible example is more useful than an entire application. [Contributing](../../CONTRIBUTING.md) describes tests and executable local checks.
