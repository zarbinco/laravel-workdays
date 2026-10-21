# Cutoff and delivery planning

[Documentation](README.md) · [فارسی](../fa/delivery.md)

Build an estimate from work, transfer, and business-date transit stages. Reverse planning answers the related question: what is the latest calendar-feasible order receipt for an inclusive deadline?

Apply the [Config quick start](getting-started.md#a-complete-config-example). The first example needs only its `global` profile and explicit UTC timestamps.

## Plan one work stage

<!-- example: delivery-plan -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$plan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['global' => 'UTC'],
    'stages' => [
        ['id' => 'prepare', 'type' => 'work', 'profile' => 'global', 'minutes' => 1],
    ],
];
$result = Workday::planDelivery('2026-11-02T09:59:30.123456Z', $plan);
echo $result->estimatedCompletionAt->format('Y-m-d H:i:s.uP'), PHP_EOL;
```
```text
2026-11-02 11:00:30.123456+00:00
```

Preparation consumes 29.876544 seconds before closing, then the remainder after reopening. Seconds and microseconds survive every work stage. Estimates describe calendar feasibility; stock, capacity, and physical delivery guarantees require application checks.

## Plan options and stage types

| Plan key | Requirement |
|---|---|
| `timezone` | Required IANA timezone for naive input and the global horizon. |
| `profile_timezones` | Required map supplying an IANA timezone for every referenced profile. |
| `stages` | Ordered list of 1–32 stages with unique nonblank `id` values. |
| `cutoff_profile` | Optional; must name the first stage's profile, whose type must be `work`. Its cutoff timezone must exactly equal its mapped timezone. |

| Stage `type` | Required keys beyond `id`, `type` | Behavior |
|---|---|---|
| `work` | `profile`, `minutes` | Normalize to the next open instant, then consume integer working minutes. Zero still normalizes. |
| `handoff` | `profiles` | Ordered list of exactly two different profiles; find the earliest common open instant. Start equals completion. |
| `business_days` | `profile`, `days` | Count strictly future business dates, preserving the local clock; zero is identity. Hours are unnecessary. |

Durations are non-negative PHP integers, not numeric strings, floats, or booleans. Unknown keys/types/profiles and missing required settings fail. Profiles must exist in the selected source. Stages do not invoke context routing automatically.

Intervals are `[start, end)`. A common opening permits handoff; closing does not. Work may finish at closing, but its next stage must find its own opening. Transit does not consume hours or apply `include_start_date`; its result can be outside working hours. Fully skipped dates are not transit days; nonexistent target local clocks fail, while repeated target clocks follow PHP/Carbon's chosen occurrence.

Inputs use [strict absolute timestamps](api-reference.md#dates-and-timezones): naive space-separated values use the plan timezone, ISO values require `Z`/offset, and ambiguous/nonexistent naive times fail. `DateTimeInterface` preserves its instant. Result instants are immutable UTC. A profile's explicit mapped zone controls its own calendar decisions.

The plan shares one `max_scan_days` horizon, anchored to the receipt's date in the plan timezone. Day zero and the last allowed future date are included. Each stage is checked in that timezone; stages do not restart the budget. Failure throws a stage/profile-specific error and returns no partial successful plan.

## Assign a business date with cutoff

Cutoff is independent of working hours. Add an explicit policy, then resolve a recorded receipt:

<!-- example: cutoff -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

config()->set('workdays.definitions.profiles.global.cutoff', [
    'timezone' => 'UTC',
    'time' => '14:00',
    'weekdays' => ['Friday' => null],
]);
$decision = Workday::profile('global')->resolveCutoff('2026-11-02T14:00:00Z');
echo $decision->effectiveBusinessDate->toDateString(), PHP_EOL;
echo $decision->reason, PHP_EOL;
```
```text
2026-11-03
cutoff_reached
```

`receivedAt < cutoffAt` is required; equality is too late. `time` is required `HH:MM` from `00:00` through `23:59`; `timezone` is a required IANA identifier. Optional `weekdays` uses normalized weekday keys. Omitted override inherits, a clock replaces, null disables. A `00:00` policy leaves no eligible window. Disabled or zero-window dates are skipped even when reopened by an extra working day.

If today is ineligible, cutoff finds the earliest strictly later business date with a nonempty enabled cutoff window. Today is checked separately; `max_scan_days = 1` permits tomorrow. Hours, lunch breaks, and `include_start_date` do not affect eligibility. Policies are validated only when used by Config calculations; the shipped configuration/preset does not enable one.

Cutoff's strict parser rejects nonexistent naive time but follows PHP/Carbon's chosen occurrence for an ambiguous naive time. A nonexistent configured cutoff fails; ambiguous configured cutoff uses that occurrence choice. The other advanced APIs reject ambiguous naive input. Prefer explicit-offset receipt timestamps for repeated hours.

With plan `cutoff_profile`, cutoff runs once. Eligible same-day input starts at the original instant; otherwise the cursor moves to the assigned date's beginning and the first work stage normalizes to opening. Cutoff is not reapplied later. The date anchor is not a pickup time.

## Find the latest order receipt

Using the same quick-start calendar, this is a complete separate example:

<!-- example: reverse-delivery -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$plan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['global' => 'UTC'],
    'stages' => [
        ['id' => 'prepare', 'type' => 'work', 'profile' => 'global', 'minutes' => 60],
    ],
];
$result = Workday::reversePlanDelivery('2026-11-02T12:00:00Z', $plan, ['lookback_days' => 1]);
echo $result->latestOrderAt?->format('Y-m-d H:i:s.uP'), PHP_EOL;
echo $result->estimatedCompletionAt?->format('Y-m-d H:i:s.uP'), PHP_EOL;
```
```text
2026-11-02 11:00:00.000000+00:00
2026-11-02 12:00:00.000000+00:00
```

`lookback_days` is required integer `1–31`, no greater than `max_scan_days`. It includes the deadline's local date plus that many prior labels. Search begins at the earliest included date's first real instant and ends at the inclusive deadline. Each candidate also has its own unchanged forward horizon.

The solver returns the greatest feasible microsecond and verifies it with the forward planner. A 120-minute task using `09:00–12:00`/`13:00–17:00` with a `14:00` deadline has latest receipt `11:00:00.000000`; the next microsecond finishes late. Exclusive cutoff/handoff boundaries can legitimately produce `.999999` answers. Repeated-hour transit can create disconnected feasible regions; results do not rely on one global monotonicity assumption.

Safety budgets are 65,536 forward-prefix evaluations and 32,768 calendar cells. Unsupported ambiguous split/backwards civil-date histories or inability to prove exactness raise errors, rather than approximate results. Valid absence of availability can return `feasible = false`; malformed input/source errors are not ordinary infeasibility.

## Results and errors

`DeliveryPlanResult` exposes `timezone`, UTC `receivedAt`, `planningStartAt`, nullable policy-local `cutoffResult`, ordered `stages`, and UTC `estimatedCompletionAt`. Each `DeliveryStageResult` exposes `id`, `type`, `profiles`, UTC `readyAt`, `startedAt`, and `completedAt`, with `readyAt <= startedAt <= completedAt`.

`CutoffResult` has `profile`, `timezone`, policy-local `receivedAt`, nullable `cutoffAt`, `effectiveBusinessDate`, `sameDayEligible`, and `reason`. Reason priority is `non_business_day`, `cutoff_disabled`, `before_cutoff`, then `cutoff_reached`. `cutoffAt` describes the input date even after it has passed. Its date assignment may start after skipped midnight; it is not an opening time.

`ReverseDeliveryPlanResult` has `timezone`, `targetCompletionAt`, `searchStartAt`, `feasible`, nullable `latestOrderAt`, `planningStartAt`, `firstStageStartsAt`, `estimatedCompletionAt`, `slackMicroseconds`, and verified `forwardResult`. When infeasible, all six solution fields are null. Slack is an integer microsecond difference.

All `toArray()` methods use snake_case. Timestamp fields use six microsecond digits and explicit offsets; new plan/reverse fields are UTC, nested cutoff fields retain their policy zone, and cutoff's business date is `Y-m-d`. Configuration errors use `WorkdayConfigurationException`, malformed timestamps use `InvalidArgumentException`, and exhausted calculation/search bounds use `RuntimeException`. Source failures propagate separately.

Continue with [readiness slots](delivery-slots.md), [snapshots](snapshots.md), and [application integration](laravel-integration.md).
