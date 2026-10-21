# Delivery-promise snapshots

[Documentation](README.md) · [فارسی](../fa/snapshots.md)

Capture a delivery estimate together with bounded calendar evidence, restore it later without a live source, and compare it with a new calculation. Snapshots preserve the original estimate; comparison does not overwrite it.

Apply the [Config quick start](getting-started.md#a-complete-config-example). This plan uses its explicit `global` calendar and UTC.

## Capture and restore

<!-- example: delivery-snapshot -->
```php
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Facades\Workday;

$plan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['global' => 'UTC'],
    'stages' => [
        ['id' => 'prepare', 'type' => 'work', 'profile' => 'global', 'minutes' => 60],
    ],
];
$snapshot = Workday::captureDeliveryPromise('2026-11-02T09:30:00Z', $plan, ['horizon_days' => 7]);
$payload = json_decode(json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$restored = DeliveryPromiseSnapshot::fromArray($payload);
$comparison = Workday::compareDeliveryPromise($restored);

echo $restored->originalResult->estimatedCompletionAt->format('Y-m-d H:i:s.uP'), PHP_EOL;
echo $comparison->direction, PHP_EOL;
echo $restored->runtimeMetadata['calculation_schema_version'], PHP_EOL;
```
```text
2026-11-02 11:30:00.000000+00:00
unchanged
2
```

Restoration validates the captured evidence. Comparison opens a new current source view but keeps the stored receipt, plan, selected profiles, and horizon. It does not reroute the plan.

## Capture bounds

Capture options contain only required integer `horizon_days` from 1 through 31, no greater than `max_scan_days`. At most eight distinct plan profiles are supported. Numeric strings, floats, booleans, null, unknown keys, and invalid plans fail.

Day zero is the receipt's date in the plan timezone; horizon 7 permits day zero through day seven, rather than seven total date labels. All stages share the bound, and comparison reuses it under the current safety limit. Evidence includes deterministic neighboring date labels for profile timezone differences and overnight ownership; those margins do not enlarge planning availability.

For each profile, evidence begins two labels before the converted day-zero boundary and ends two after the converted exclusive horizon boundary, inclusive. Provider inputs include one more preceding shift-owner date. Same-UTC horizon 31 observes 37 dates per profile. Relevant dated overrides/seasons are trimmed to provider coverage; selected recurring annual maps remain. Coverage and margins must fit Gregorian years `0001–9999`. Historical cutoff offsets containing seconds are rejected because nested cutoff timestamps use minute offsets.

## Schema and integrity

The outer snapshot and evidence schemas are version 1. Current calculation semantics are separately recorded as `runtime_metadata.calculation_schema_version = 2`. Unsupported older calculation semantics cannot be restored by changing a version or recomputing a hash. Regenerate such snapshots deliberately from their original inputs; hashes are not a migration mechanism.

| Snapshot property | Serialized key |
|---|---|
| `schemaVersion` | `schema_version` |
| `calendarVersion` | `calendar_version` |
| `receivedAt` | `received_at` |
| `plan` | `plan` |
| `horizonDays` | `horizon_days` |
| `originalResult` | `original_result` |
| `calendarEvidence` | `calendar_evidence` |
| `runtimeMetadata` | `runtime_metadata` |
| Payload checksum | `integrity_hash` |

Instants serialize with six microsecond digits and explicit offsets; new fields are UTC, nested cutoff fields retain policy-local offsets, and civil dates remain Gregorian `Y-m-d`. The snapshot representation is limited to 2 MiB (2,097,152 bytes), depth 32, and 100,000 nodes. Schema/fields/types, dates, profiles/stages, evidence, results, and checksums are validated. Invalid, oversized, unsupported-object, or tampered input throws rather than becoming a successful calendar-change result. Never use PHP `unserialize()` for transfer.

The calendar version is a deterministic SHA-256 content fingerprint, not a Database revision or capture timestamp. Canonical maps are sorted, meaningful list order and types/nulls remain, and weekday/date/instant forms are normalized. It covers relevant effective profiles, holiday inputs, timezones, hours/cutoff, Hijri settings, coverage, and observations. Unreferenced profiles, metadata timestamps, random IDs, administrative row/revision IDs, and capture time do not determine it.

The calendar fingerprint and separate whole-payload checksum are integrity checks, **not authentication or signatures**. Someone able to rewrite payload and hashes can replace them. Protect stored snapshots with application access/storage controls. Customer/order identifiers, addresses, and routing context are not automatically included; choose plan identifiers accordingly.

## Frozen replay and timezone history

Every restore validates current semantics, captured UTC work/handoff windows, and exact result/microsecond consistency, even with runtime-version drift. With matching tzdb it also replays frozen rules and the complete plan with the captured bound, independently of current Config/Database, routing, Hijri settings, or current `max_scan_days`.

With different tzdb, UTC work/handoff consistency checks remain; the installed runtime cannot prove historical civil-date transit decisions without that historic database. Comparison flags drift and recalculates using the current runtime. PHP/Carbon/Verta/Islamic version drift does not disable validation.

With matching tzdb, a captured window's complete positive extent must belong to its recorded civil date; its exclusive end can be the first instant of the next existing date. Valid daytime intervals around Apia's skipped date or Havana's repeated midnight need no unused hypothetical next `24:00`. Entirely skipped dates have no windows. Unsupported backwards/split-date histories still fail, and actually configured endpoints, including `24:00`, retain strict nonexistent/ambiguous-time rejection.

## Compare under current definitions

`DeliveryPromiseComparison` has status `resolved` and direction `unchanged`, `earlier`, or `later` when recalculation succeeds; ordinary unavailable calendar constraints can yield status/direction `unresolvable`. Invalid stored payloads and `DefinitionSourceException` source failures still throw.

| Property | Meaning |
|---|---|
| `originalCalendarVersion`, nullable `currentCalendarVersion` | Original/current fingerprints. |
| Nullable `versionChanged`, `estimateChanged` | Content and completion-instant comparisons, separately. |
| `direction`, `status` | Outcome codes above. |
| `originalEstimatedCompletionAt`, nullable `currentEstimatedCompletionAt` | Immutable UTC estimates. |
| Nullable `deltaSeconds` | Current minus original, potentially fractional; positive means later. |
| `changedCalendarDays` | Profile/date-sorted bounded differences with `changed_fields` and complete old/new rows. |
| `runtimeChanged` | Available runtime/calculation metadata differs. |
| Nullable `configurationChanged`, `holidayInputsChanged`, `databaseRulesChanged` | Effective-content diagnostics; Database holiday changes are not revision-ID tracking. |
| Nullable `error` | Useful recalculation failure detail. |

Serialization uses corresponding snake_case keys. Diagnostics are separately bounded to 5 MiB (5,242,880 bytes), depth 32, and 210,000 nodes; snapshot import remains at 2 MiB. A relevant change after completion can alter the version without changing the estimate. Changed calendar days are observed differences, not proof that each caused the movement.

Capture uses one pinned view for both the original estimate and evidence. Config reads no Database; Database pins one revision. Comparison takes a fresh view. Applications decide whether to store or accept an updated estimate. See [Database lifecycle](database.md), [Delivery planning](delivery.md), and [API reference](api-reference.md).
