# Delivery and pickup slots

[Documentation](README.md) · [فارسی](../fa/delivery-slots.md)

Offer fixed-duration calendar intervals after an explicit readiness instant. Slots are available calendar choices; they do not reserve capacity or confirm a carrier booking.

Apply the [Config quick start](getting-started.md#a-complete-config-example). Its two separate one-hour windows provide two choices:

<!-- example: delivery-slots -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$result = Workday::deliverySlots('2026-11-02T08:00:00Z', [
    'profile' => 'global',
    'timezone' => 'UTC',
    'days' => 1,
    'duration_minutes' => 60,
    'step_minutes' => 60,
]);
foreach ($result->slots as $slot) {
    echo $slot->startsAt->format('H:i'), '-', $slot->endsAt->format('H:i'), PHP_EOL;
}
```
```text
09:00-10:00
11:00-12:00
```

Each slot occupies one complete working window. The lunch break is excluded. Increasing duration to 120 would return no slots for this fixture.

## Options

| Key | Required/default | Type and range |
|---|---|---|
| `profile` | Required | Nonblank existing profile with valid hours. |
| `timezone` | Required | IANA service-calendar timezone. |
| `days` | Required | Integer `1–31`; `days - 1 <= max_scan_days`. |
| `duration_minutes` | Required | Integer `1–1439`; actual elapsed slot length. |
| `step_minutes` | Required | Integer `1–1439`; start-grid spacing. |
| `min_notice_minutes` | `0` | Non-negative integer elapsed minutes after readiness. |
| `max_slots` | `50` | Integer `1–200`. |

Unknown keys, numeric strings, floats, booleans, explicit null, and invalid profile/hours/timezone values fail with `WorkdayConfigurationException`. Invalid timestamps use `InvalidArgumentException`. Omit optional values to select defaults.

## Readiness and alignment

Input uses [strict absolute timestamp formats](api-reference.md#dates-and-timezones); naive strings use the required timezone. Ambiguous/nonexistent naive clocks fail. Offset-bearing inputs/date objects preserve their instant; caller objects are not mutated. The generator does not substitute `now()`.

`eligibleFromAt = readyAt + min_notice_minutes` uses elapsed UTC time, including closed hours, once. It is not preparation in working minutes. Representable large notice beyond the requested dates returns no slots; arithmetic overflow fails. Seconds/microseconds are compared exactly: readiness one microsecond after a grid start excludes it.

`days` counts local date labels including the readiness date, rather than business dates or dates after the notice buffer. Seven means day zero through day six. Weekends, holidays, and fully skipped civil dates consume labels without extending the range.

Candidate starts follow each window's opening plus `n × step_minutes`. The grid restarts at an afternoon opening; readiness filters it rather than shifting it. Step shorter than duration permits overlapping alternative choices. Results are unique, chronological, and stop at `max_slots`.

Each `[start, end)` slot must fit one window on one requested date. It can end exactly at closing or a representable `24:00`; it cannot bridge lunch, join touching independent windows, or occupy positive time on a second date. Overnight shifts use effective daily fragments: `22:00–24:00` can be offered, but a single `23:00–01:00` slot cannot. Carry-in begins its own grid at `00:00`.

## Timezone transitions

Candidate local boundaries must exist uniquely. Nonexistent/ambiguous starts or ends are skipped; slots crossing offset transitions are skipped. Remaining actual elapsed duration must equal the requested duration. Valid candidates before or after the transition remain eligible. This conservative policy can offer fewer choices even where core working-time arithmetic can consume a valid larger interval.

Calendar rules, special/seasonal hours, inheritance, and extra-day precedence come from the same source context. Missing hours for an extra working day without usable fallback remains a configuration error. Closed dates/windows too short for the duration legitimately return an empty list.

## Use delivery readiness

A [delivery plan](delivery.md) can end after preparation or expose a readiness stage's `completedAt`. Pass that actual instant to `deliverySlots()` using the service calendar's timezone. A plan ending after final delivery is not automatically a readiness plan. Your application chooses the meaningful stage; the package does not infer it.

## Results

`DeliverySlotsResult` has `profile`, `timezone`, UTC `readyAt`, `eligibleFromAt`, integer `requestedDays`, and `slots`. Each `DeliverySlot` has UTC `startsAt` and `endsAt`. `toArray()` additionally gives each slot's `local_starts_at` and `local_ends_at` in the requested timezone. Result keys are snake_case; timestamps use `Y-m-d\TH:i:s.uP` with six microsecond digits and an explicit offset.

Use the [API reference](api-reference.md) for related methods and [Working hours](working-hours.md) for effective window ownership.
