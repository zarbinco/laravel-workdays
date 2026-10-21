# SLA deadlines, pauses, and resumption

[Documentation](README.md) · [فارسی](../fa/sla.md)

Evaluate how much working time a service request has consumed and when its target expires. Supply the recorded start, evaluation instant, and pauses; the evaluator does not persist state, monitor requests, or send notifications.

Apply the [Config quick start](getting-started.md#a-complete-config-example). Its Monday windows open `09:00–10:00` and `11:00–12:00`.

## Evaluate a one-hour target

<!-- example: sla-deadline -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$result = Workday::evaluateSla('2026-11-02T09:30:00Z', '2026-11-02T11:00:00Z', [
    'profile' => 'global',
    'timezone' => 'UTC',
    'target_minutes' => 60,
]);
echo $result->dueAt?->format('Y-m-d H:i:s.uP'), PHP_EOL;
echo $result->consumedWorkingMinutes, '/', $result->remainingWorkingMinutes, PHP_EOL;
echo $result->status, PHP_EOL;
```
```text
2026-11-02 11:30:00.000000+00:00
30/30
active
```

Thirty working minutes elapsed before lunch. The remaining thirty finish at `11:30`, assuming no future pauses.

## Options and timestamps

| Key | Required/default | Accepted value |
|---|---|---|
| `profile` | Required | Existing profile with valid working hours. |
| `timezone` | Required | Explicit IANA service-calendar timezone. |
| `target_minutes` | Required | Integer `1–1,000,000`. |
| `pauses` | `[]` | List of at most 1,000 submitted arrays, each exactly `started_at` and `ended_at`. |

Unknown keys, numeric strings, floats, booleans, null options, or invalid containers fail with `WorkdayConfigurationException`. Only pause `ended_at` may be null. Main/pause timestamps use [strict absolute formats](api-reference.md#dates-and-timezones); naive strings use `timezone`. Nonexistent/ambiguous naive clocks fail. UTC/offset inputs preserve instants and caller objects remain unchanged.

Both main instants are required; evaluation must be at or after start. Pauses begin between start and evaluation. Closed pauses end strictly after their start and no later than evaluation. Future/zero-length pauses and multiple open pauses fail with `InvalidArgumentException`. The 1,000 limit applies before unioning, including duplicates.

## Closed and overlapping pauses

Pause intervals are `[started_at, ended_at)`. Their start suspends consumption; their end permits it. Unsorted, duplicate, overlapping, and touching pauses are unioned once. `10:00–10:45` and `10:30–11:00` exclude one hour, rather than 75 minutes.

Only overlap with actual working windows contributes paused working time. A pause entirely after closing contributes zero. With `09:00–12:00`/`13:00–17:00`, a pause `11:30–13:30` removes 60 working minutes.

The due instant is the first time cumulative eligible unpaused work reaches the budget. Wall-clock pause duration is not simply appended to an old deadline. Once the budget was reached, later pauses cannot move its known due instant or undo a breach; paused metrics still describe eligible pause intersections through evaluation.

## Open pauses and resumption

`ended_at => null` means the request remains paused at evaluation. Its elapsed portion is excluded, but the future resume is unknown. If the target was not reached, `dueAt` is null. Resume by closing that same pause with the actual end instant and evaluate again; the package does not infer a resume time.

For the example's start at `09:30`, an open pause beginning `09:45` and evaluation at `11:00` leaves 15 consumed, 15 paused working minutes, 45 remaining, and unknown due time. Closing it at `11:00` produces due `11:45` when reevaluated at that instant. A pause beginning exactly at an already reached deadline preserves that deadline.

## Breach and rounding

At exactly `dueAt`, `breached` is false and remaining is zero. One microsecond later breach is true, even outside working hours when overdue working minutes remain zero. Status priority is `breached`, then `paused`, then `active`; paused and breached flags can both be true. There is no package completed/cancelled ticket status.

| Metric | Final rounding |
|---|---|
| `consumedWorkingMinutes` | Floor all active eligible time through evaluation, including overage. |
| `pausedWorkingMinutes` | Floor all eligible pause intersections. |
| `remainingWorkingMinutes` | Ceiling of positive unconsumed budget; minimum zero. |
| `overdueWorkingMinutes` | Floor positive active overage; minimum zero. |

Exact microseconds are accumulated on 64-bit PHP before final rounding. Windows/pauses are not rounded individually. A true breach can therefore have zero overdue whole minutes; a positive fractional remainder becomes one remaining minute. Public minute differences are presentation values, not deadline capacity.

## Horizon and calendars

One `max_scan_days` horizon starts on the SLA's profile-local start date, with that date at day zero and the last allowed date inclusive. Evaluation and any finite deadline must fit it. Pauses/resumption/projection do not reset it. Fully skipped dates consume labels. A `24:00` deadline on the midnight after the final allowed date is outside this horizon, even where core addition allows the exclusive endpoint. A genuine ongoing pause can yield unknown due time without an unbounded search, provided evaluation is in range.

Hours, holidays, special/seasonal rules, inheritance, extra days, and overnight ownership use one source view. Valid incoming overnight time can be consumed on a receiving holiday/weekend. Cutoff is not automatically applied. Configured boundaries use strict wall clocks; invalid/ambiguous endpoints fail, while valid offset-spanning intervals consume actual elapsed time. See [Working hours](working-hours.md).

## Result

Immutable `SlaResult` exposes `profile`, `timezone`, UTC `startedAt`, `evaluatedAt`, `targetMinutes`, the four metrics above, nullable UTC `dueAt`, `paused`, `breached`, and `status`. `toArray()` uses corresponding snake_case keys; instants have six microsecond digits and `+00:00`. Unavailable finite capacity or exhausted horizon throws `RuntimeException`; source failures propagate.

Applications store ticket and pause records, enforce authorization, choose evaluation frequency, and deliver any notifications. See [Laravel integration](laravel-integration.md) and the [API reference](api-reference.md).
