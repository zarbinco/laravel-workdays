# API and configuration reference

[Documentation](README.md) · [فارسی](../fa/api-reference.md)

For a first calculation, use [Getting started](getting-started.md). This page collects signatures, defaults, limits, and links to complete feature contracts. The facade forwards to `WorkdayManager`; use the same methods on an injected manager.

## Date and profile methods

`Date` below means `string|DateTimeInterface`. Returned dates are `Carbon\CarbonImmutable`. All methods operate on the configured default profile unless you first call `profile($name)`, which returns a retained `BusinessDayCalculator`.

| Signature | Return |
|---|---|
| `profile(string $profile)` | `BusinessDayCalculator` |
| `resolveProfile(array $context)` | `Data\ResolvedProfile` |
| `profileFor(array $context)` | `BusinessDayCalculator` |
| `isBusinessDay(Date $date)` | `bool` |
| `isHoliday(Date $date)`, `isNonWorkingDay(Date $date)` | `bool` |
| `isWeekend(Date $date)`, `isCalendarHoliday(Date $date)` | `bool` |
| `isGregorianHoliday(Date $date)`, `isJalaliHoliday(Date $date)`, `isHijriHoliday(Date $date)` | `bool` |
| `isCustomHoliday(Date $date)`, `isExtraWorkingDay(Date $date)` | `bool` |
| `explain(Date $date)` | `Data\DayInfo` |
| `nextBusinessDay(Date $date)`, `previousBusinessDay(Date $date)` | `CarbonImmutable` |
| `addBusinessDays(Date $date, int $days)`, `subBusinessDays(Date $date, int $days)` | `CarbonImmutable` |
| `diffBusinessDays(Date $startDate, Date $endDate)` | `int` |
| `calculate(Date $date, int $businessDays)` | `Calculator\BusinessDayResult` |

Day movement budgets are non-negative integers; use subtraction for backwards movement. `include_start_date` controls addition/subtraction/difference as described in [Business days](business-days.md). It does not change next/previous, cutoff, transit, or recurrence. `isHoliday()` means final non-working date, while calendar/weekend/custom predicates report distinct matches.

## Working-time methods

| Signature | Return |
|---|---|
| `isBusinessTime(Date $datetime)` | `bool` |
| `workingWindowsFor(Date $date)` | Ordered array of `Data\TimeWindow` |
| `nextBusinessTime(Date $datetime)`, `previousBusinessTime(Date $datetime)` | `CarbonImmutable` |
| `addBusinessMinutes(Date $datetime, int $minutes)` | `CarbonImmutable` |
| `addBusinessHours(Date $datetime, int\|float $hours)` | `CarbonImmutable` |
| `diffBusinessMinutes(Date $startDate, Date $endDate)` | `int` |
| `diffBusinessHours(Date $startDate, Date $endDate)` | `float` |

Minutes are non-negative integer budgets consumed exactly, retaining seconds/microseconds. Hours must be finite, non-negative, safely representable, and convert to whole minutes within `0.000001` minute tolerance. Public minute differences truncate total exact overlap once toward zero; hour differences divide that minute value by 60. `TimeWindow` has string `start`/`end`, `startMinutes()`/`endMinutes()`, and `toArray()` with `start`, `end`.

See [Working hours](working-hours.md) for zero-minute identity, one-microsecond previous boundaries, original-first-argument timezone, split/overnight ownership, and configured wall-clock validation.

## Planning methods

| Signature | Return and complete contract |
|---|---|
| `resolveCutoff(Date $receivedAt)` | `Data\CutoffResult`; [cutoff](delivery.md#assign-a-business-date-with-cutoff) |
| `planDelivery(Date $receivedAt, array $plan)` | `Data\DeliveryPlanResult`; [plan keys/stages](delivery.md#plan-options-and-stage-types) |
| `reversePlanDelivery(Date $desiredCompletionAt, array $plan, array $options)` | `Data\ReverseDeliveryPlanResult`; [lookback](delivery.md#find-the-latest-order-receipt) |
| `deliverySlots(Date $readyAt, array $options)` | `Data\DeliverySlotsResult`; [slot options](delivery-slots.md#options) |
| `evaluateSla(Date $startedAt, Date $evaluatedAt, array $options)` | `Data\SlaResult`; [SLA options](sla.md#options-and-timestamps) |
| `recurringSchedule(Date $anchorDate, array $options)` | `Data\RecurringScheduleResult`; [recurrence options](recurring-schedules.md#options) |
| `captureDeliveryPromise(Date $receivedAt, array $plan, array $options)` | `Data\DeliveryPromiseSnapshot`; [capture bounds](snapshots.md#capture-bounds) |
| `compareDeliveryPromise(DeliveryPromiseSnapshot\|array $snapshot)` | `Data\DeliveryPromiseComparison`; [comparison](snapshots.md#compare-under-current-definitions) |
| `bulkCalculate(array $operations, array $options = [])` | `Data\BulkCalculationResult`; [item schemas/options](bulk-calculations.md) |

Advanced methods belong to the manager/facade; `resolveCutoff()` also belongs to a profile calculator. Results are immutable. `toArray()` gives snake_case and JSON-compatible native types; timestamp conventions and nullable fields are listed beside each feature. No method schedules jobs or persists calculation results.

## Dates and timezones

| Input family | String contract |
|---|---|
| Day APIs, exact-date keys | Valid Gregorian `Y-m-d`; day APIs trim surrounding whitespace. |
| Direct core business-time APIs | Carbon parsing in application/default timezone; prefer explicit timezone-aware objects. |
| Cutoff/forward/reverse/slots/SLA/snapshot receipt | Strict absolute formats below; caller supplies an instant. |
| Recurrence anchor | Strict Gregorian `Y-m-d`, including Jalali monthly recurrence. |
| Recurring holiday maps | `MM-DD` in their named Gregorian/Jalali/Hijri calendar. |

Advanced absolute strings accept space-separated `Y-m-d H:i` or `Y-m-d H:i:s` with optional 1–6 fractional digits after seconds. Naive input uses the explicit operation/policy timezone. ISO `Y-m-d\TH:i[:s[.fraction]]` requires `Z` or `±HH:MM`. Components are zero-padded; fractions require seconds. Offset hours are `00–23`, minutes `00–59`.

Empty/relative/date-only strings, impossible dates, invalid clocks/offsets, named zones inside strings, and nonexistent naive local times are rejected. Planner/slots/SLA/reverse also reject ambiguous naive times; supply offset/`DateTimeInterface` to choose the repeated instant. Cutoff follows PHP/Carbon's chosen occurrence for ambiguous naive input/configured cutoff. Configured working-window endpoints are stricter: nonexistent or ambiguous endpoints fail.

IANA identifiers, including `UTC`, are required for policy/operation timezone settings; numeric offsets are not configuration zones. Date objects preserve instants without mutation. Direct core calls use their input timezone, not a profile timezone. Jalali month arithmetic is supported only in monthly recurrence; Hijri is used for holidays, not monthly recurrence or general Persian-string parsing.

## Configuration

These defaults are from [the shipped configuration](../../config/workdays.php). A profile needs no working hours/cutoff for day-only calls.

| Bootstrap key | Default | Contract |
|---|---|---|
| `driver` | `config` (environment-backed) | Exactly `config` or `database`. |
| `database.connection` | null (environment-backed) | Explicit connection name or current application default, pinned on operation-open. |
| `max_scan_days` | `3660` | Positive PHP integer safety bound; per-call bounds may narrow it, never enlarge it. |
| `carbon_macros.enabled` | true | Register macros. |
| `carbon_macros.short_aliases` | true | Register available aliases. |
| `carbon_macros.override_existing` | false | May replace macros, never native Carbon methods. |
| `iran_official.enabled` | false | Import-tool setting; no automatic runtime dataset activation. |
| `iran_official.year`, `.profile`, `.calendar_path` | null | Local import-tool settings; command options and reviewed local files govern imports. |

| Operational `definitions` key | Shipped default/type |
|---|---|
| `default_profile` | Required nonblank string; shipped `iran`. |
| `include_start_date` | Required boolean; false. Strings/integers are not boolean substitutes. |
| `hijri.method` | Explicit string; `umm_al_qura`. Methods: `mathematical`, `umm_al_qura`, `high_judiciary`, `diyanet`. |
| `hijri.adjustment` | Explicit integer; 0. Nonzero only with `mathematical`. |
| `profile_routing` | null or a supported routing definition. |
| `profiles` | Named raw profile map; shipped `iran` and `global`. |

| Profile key | Shape/default |
|---|---|
| `extends` | Optional nonblank configured parent name; chain maximum 16 profiles. |
| `weekends` | Required array after inheritance; use `[]` for no weekends. ISO integer/English/Persian aliases. |
| `holidays` | Maps `gregorian`, `jalali`, `hijri` to valid `MM-DD` string/null labels; omitted calendars empty. |
| `custom_holidays`, `extra_working_days` | Exact Gregorian date-to-string/null-label maps; default empty. |
| `working_hours` | Optional normalized weekday-to-window lists; required for time capabilities. |
| `extra_working_day_hours` | Optional fallback window list. |
| `special_working_hours` | Exact Gregorian date-to-window lists; optional empty map. |
| `seasonal_working_hours` | Optional ordered `from`/`through`/`weekdays` lists; no overlapping inclusive ranges. |
| `allow_overnight_working_hours` | Boolean false; enables overnight/end-only `24:00` syntax. |
| `cutoff` | Optional `timezone`, `time`, optional `weekdays`; required only when cutoff is used. |

Window lists contain `[start, end]` zero-padded `HH:MM` pairs. Without overnight permission, end must follow start within the date and be before `24:00`. With permission, end can precede start for one next-day shift or be `24:00`; start cannot be `24:00`, equal clocks remain invalid, and overlap is rejected. [Configuration](configuration.md#inheritance-and-clearing) defines section-specific inheritance and explicit empty/null clearing. [Routing rules](configuration.md#routing-rules) define strict scalar matching and specificity.

## Limits and horizons

| Capability | Bounds and anchor |
|---|---|
| Day navigation/addition/`calculate()` | `max_scan_days` traversed labels; starting date separate. `diffBusinessDays()` traverses its full requested range without this bound. |
| Core working-time | Original local input date plus at most the configured label offsets; addition does not restart after normalization; differences require their full span. |
| Forward plan | One horizon anchored to receipt date in plan timezone; at most 32 stages. |
| Reverse | Required lookback 1–31 and no greater than safety bound; deadline date plus preceding labels; each candidate retains its own forward horizon. 65,536 prefix/32,768 cell safety budgets. |
| Slots | Days 1–31 (including readiness date); duration/step 1–1439; max slots default 50, limit 200; non-negative elapsed notice. |
| SLA | Target 1–1,000,000; pauses ≤1,000 submitted; one horizon anchored to service-local start date. |
| Recurrence | Interval 1–12; count 1–60; shift default 7, range 0–31; one original-anchor forward horizon. |
| Snapshot | Horizon 1–31 future labels after day zero; ≤8 profiles; 2 MiB/depth32/100,000nodes. Comparison diagnostics 5 MiB/depth32/210,000nodes. |
| Bulk | 1–1,000 items; ≤50 profiles; unique IDs≤128UTF-8characters; prefetch≤62inclusive labels; each copied argument depth32/100,000nodes. |
| Definition document | 4MiB including envelope; depth32/100,000nodes; 1–10,000 profiles; name≤255UTF-8bytes; metadata≤16KiB. |

Integer options use native PHP integers unless a signature explicitly accepts float hours. Numeric strings, floats, booleans, explicit null, and unknown keys are rejected by strict option schemas. Skipped civil-date labels do not extend horizons. Core exclusive midnight completion and plan/SLA result-date limits differ; see [Working hours](working-hours.md#timezones-dst-and-bounds).

## Result-field index

Each linked guide lists meanings, nullable fields, and serialization rules. All result classes below are in `Data\` except `Calculator\BusinessDayResult`.

| Object | Public fields |
|---|---|
| `BusinessDayResult` | `startDate`, `resultDate`, `requestedBusinessDays`, `calendarDays`, `skippedDates`, `profile`; [details](business-days.md#businessdayresult). |
| `DayInfo`, `DayReason` | Date/profile/classification flags/reasons; reason type/title/source/calendar/key/override fields; [details](business-days.md#inspect-reasons-with-explain). |
| `ResolvedProfile` | `profile`, `source`, `ruleId`; [details](configuration.md#routing-rules). |
| `CutoffResult` | `profile`, `timezone`, `receivedAt`, `cutoffAt`, `effectiveBusinessDate`, `sameDayEligible`, `reason`. |
| `DeliveryPlanResult` | `timezone`, `receivedAt`, `planningStartAt`, `cutoffResult`, `stages`, `estimatedCompletionAt`. |
| `DeliveryStageResult` | `id`, `type`, `profiles`, `readyAt`, `startedAt`, `completedAt`. |
| `ReverseDeliveryPlanResult` | `timezone`, `targetCompletionAt`, `searchStartAt`, `feasible`, `latestOrderAt`, `planningStartAt`, `firstStageStartsAt`, `estimatedCompletionAt`, `slackMicroseconds`, `forwardResult`; [delivery details](delivery.md#results-and-errors). |
| `DeliverySlotsResult`, `DeliverySlot` | Result profile/timezone/readyAt/eligibleFromAt/requestedDays/slots; slot startsAt/endsAt with local serialized equivalents; [details](delivery-slots.md#results). |
| `SlaResult` | Profile/timezone/start/evaluation/target, consumed/paused/remaining/overdue working minutes, dueAt/paused/breached/status; [details](sla.md#result). |
| `RecurringScheduleResult`, `RecurringOccurrence` | Schedule settings/anchor/count/occurrences; cycleIndex/nominalPeriod/nominalDate/scheduledDate/status/reason/shiftDays/collides; [details](recurring-schedules.md#results-and-errors). |
| `DeliveryPromiseSnapshot`, `DeliveryPromiseComparison` | [Snapshot schema](snapshots.md#schema-and-integrity) and [comparison diagnostics](snapshots.md#compare-under-current-definitions). |
| `BulkCalculationResult`, `BulkCalculationItemResult` | Summary total/succeeded/failed/items; item id/operation/success/result/error; [details](bulk-calculations.md#results). |
| `DefinitionRevision`, `DefinitionPublication` | Revision revisionId/definitions/contentHash/metadata; publication revisionId/changed/contentHash; [details](database.md#edit-safely). |

## Database administration and commands

Repository namespace: `Configuration\DatabaseDefinitionRepository`. Methods: `validate(array $definitions): DefinitionDocument`, `current(): ?DefinitionRevision`, `revision(int $revisionId): DefinitionRevision`, `publish(array $definitions, ?int $expectedRevisionId, array $metadata = []): DefinitionPublication`. `DefinitionDocument::fromArray()` validates raw definitions; `fromJson()`/`toJson()` use the strict `format_version = 1` envelope. Full replacement, optimistic conflicts, raw inheritance, source isolation, and pinning are described in [Database](database.md).

| Command | Arguments/options |
|---|---|
| `workdays:install` | `--preset=` (only `iran`), `--persian` alias, `--force` deliberate config overwrite, `--driver=config` (`config`/`database`; Database publishes schema only). |
| `workdays:definitions:export` | Required `path`; required `--source=config\|database`; optional Database-only `--revision=`; `--force` permits existing destination overwrite. |
| `workdays:definitions:import` | Required `path`; exactly one `--initialize` or positive `--expected-revision=`; optional `--dry-run`. Always targets Database. |
| `workdays:import-iran-calendar` | Required Jalali `year`; `--profile=iran`; `--calendar-path=`; `--dry-run`; write requires `--expected-revision=`; `--force` only permits differing holiday values. |

Publish tags are `workdays-config`, `workdays-config-iran`, and `workdays-migrations`. No installer command runs migrations, changes `.env`, fetches data, initializes a source, or switches drivers automatically. Import dry-run/force and explicit-clear safeguards are in the [Database import section](database.md#import-a-local-iran-calendar).

## Carbon macro names

| Prefixed macro | Optional short alias |
|---|---|
| `workdayIsBusinessDay`, `workdayIsNonWorkingDay` | `isBusinessDay`, `isNonWorkingDay` |
| `workdayIsHoliday`, `workdayIsWeekend` | `isWorkdayHoliday`, `isWorkdayWeekend` |
| `workdayIsCalendarHoliday`, `workdayIsExtraWorkingDay` | `isCalendarHoliday`, `isExtraWorkingDay` |
| `workdayNextBusinessDay`, `workdayPreviousBusinessDay` | `nextBusinessDay`, `previousBusinessDay` |
| `workdayAddBusinessDays`, `workdaySubBusinessDays` | `addBusinessDays`, `subBusinessDays` |
| `workdayDiffBusinessDaysUntil`, `workdayExplain` | `diffBusinessDaysUntil`, `explainWorkday` |
| `workdayIsBusinessTime`, `workdayWorkingWindows` | `isBusinessTime`, `workingWindowsForWorkday` |
| `workdayNextBusinessTime`, `workdayPreviousBusinessTime` | `nextBusinessTime`, `previousBusinessTime` |
| `workdayAddBusinessMinutes`, `workdayAddBusinessHours` | `addBusinessMinutes`, `addBusinessHours` |
| `workdayDiffBusinessMinutesUntil`, `workdayDiffBusinessHoursUntil` | `diffBusinessMinutesUntil`, `diffBusinessHoursUntil` |

Profiles are the last optional argument; default profile applies when omitted. Native methods are never overridden and aliases can be skipped when occupied. [Laravel integration](laravel-integration.md) lists every validation factory and behavior.

## Exception categories

| Category | Common cause and handling |
|---|---|
| `InvalidArgumentException` | Invalid date/instant/chronology or direct calculation budget; selected non-inherited profile validation can retain this category. |
| `Exceptions\WorkdayConfigurationException` | Invalid strict options, missing capability, malformed schedules/routing/inheritance, unsupported timezone boundaries. |
| `Exceptions\WorkingTimeSearchException` / `RuntimeException` | Bounded search/capacity/representation cannot resolve; no partial successful answer. |
| `Exceptions\DefinitionConflictException` | Stale publication token; reload and reconcile deliberately. |
| `Exceptions\DefinitionSourceException` | Source setup/read/integrity failure; fix source and start a new operation. No fallback, collected item, or ordinary unresolved comparison. |

Unexpected programming/infrastructure exceptions propagate. Bulk only collects recognized domain failures; invalid snapshot payload is not a valid comparison outcome. See [Troubleshooting](troubleshooting.md).
