# Recurring business schedules

[Documentation](README.md) · [فارسی](../fa/recurring-schedules.md)

Generate business dates for recurring billing or service cycles without shifting later cycles when one occurrence falls on a closure. The result is a schedule of dates; it does not create orders, charge payments, execute jobs, or send notifications.

Apply the [Config quick start](getting-started.md#a-complete-config-example). Hours are unnecessary for recurrence.

## Generate three weekly dates

<!-- example: recurring-schedule -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$result = Workday::recurringSchedule('2026-11-06', [
    'profile' => 'global',
    'timezone' => 'UTC',
    'frequency' => 'weekly',
    'interval' => 1,
    'count' => 3,
    'adjustment' => 'next',
]);
foreach ($result->occurrences as $occurrence) {
    echo $occurrence->scheduledDate, PHP_EOL;
}
```
```text
2026-11-06
2026-11-13
2026-11-20
```

Cycle zero is the anchor date. Each subsequent cycle comes from the original anchor plus whole weeks, before business-day adjustment.

## Options

| Key | Required/default | Accepted value |
|---|---|---|
| `profile` | Required | Existing profile. |
| `timezone` | Required | Explicit IANA timezone. |
| `frequency` | Required | `weekly` or `monthly`. |
| `interval` | Required | Integer `1–12` weeks or selected-calendar months. |
| `count` | Required | Integer `1–60`, including skipped cycles. |
| `adjustment` | Required | `next`, `previous`, or `skip`. |
| `calendar` | `gregorian` | `gregorian`; `jalali` only for monthly. |
| `month_anchor` | `day_of_month` | Monthly: `day_of_month` or `end_of_month`. |
| `short_month` | `clamp` | Monthly: `clamp` or `skip`. |
| `max_shift_days` | `7` | Integer `0–31`. |
| `collision_policy` | `reject` | `reject` or `allow`. |

Unknown keys, invalid values, numeric strings, floats, booleans, and explicit null are rejected. Weekly accepts explicit Gregorian calendar but rejects Jalali and supplied monthly-only keys even when they equal defaults.

String anchors are strict valid **Gregorian** `Y-m-d`, including for Jalali monthly recurrence. Date objects convert their instant to the explicit timezone and use that local date, discarding time without mutating input. Relative/datetime strings, impossible dates, and nonexistent civil-date anchors fail. Output dates remain Gregorian labels, rather than UTC-midnight instants.

## Original anchors and short months

Weekly cycle `i` uses original anchor plus `i × interval × 7` date labels. A holiday adjustment from November 13 to November 16 does not move the next nominal November 20 cycle.

Monthly cycle `i` starts from the original month and day. January 31, 2026 with clamp yields January 31, February 28, March 31, April 30: March recovers the original day. With short-month skip, the absent day produces one skipped occurrence with null nominal/scheduled dates and reason `invalid_month_day`. Its `nominalPeriod` and cycle index remain; no replacement cycle is generated.

`end_of_month` requires a month-end anchor in the selected calendar. Each cycle uses its own month end; January 31, 2024 advances to February 29. A mid-month anchor fails, and short-month policy has no effect in this mode.

Jalali monthly arithmetic converts the Gregorian anchor to Jalali, advances Jalali months, then converts nominal dates back for eligibility/output. Months 1–6 have 31 days, 7–11 have 30, and Esfand depends on its actual Jalali leap year. Verta conversion is validated by round-trip. Gregorian leap rules do not determine Esfand. Hijri monthly recurrence is unsupported; Hijri holidays can still close nominal dates.

## Adjustment, collisions, and bounds

An eligible nominal date remains unchanged. For a closed date, next/previous searches strictly in that direction; skip retains the nominal date with null scheduled date and reason `closed_day`. `max_shift_days` measures label distance from each nominal date, inclusive at the limit. Zero permits no movement. Closed/skipped labels consume budget. Previous adjustment may precede the original anchor within its own shift allowance.

One forward `max_scan_days` horizon is anchored to the original local Gregorian date. Every valid nominal date and forward adjusted destination must fit. A short-month skipped cycle's entire target month, through its last valid day, must fit too. Bounds are not silently extended or results truncated.

Two cycles resolving to the same date fail under `reject`. `allow` preserves both and marks every colliding occurrence `collides = true`; it does not combine orders. Results retain cycle order. Chronological reversal still fails under allow. Fully skipped timezone dates follow business-day adjustment; an existing date with a skipped midnight remains usable. Gregorian years are `0001–9999`; unsupported conversion ranges fail explicitly.

## Results and errors

`RecurringScheduleResult` exposes `profile`, `timezone`, `calendar`, `frequency`, `interval`, Gregorian `anchorDate`, `requestedCount`, and exactly that many `occurrences`.

| `RecurringOccurrence` property | Meaning |
|---|---|
| `cycleIndex` | Zero-based original cycle. |
| `nominalPeriod` | Monthly selected-calendar `YYYY-MM`; weekly nominal Gregorian date. |
| `nominalDate` | Gregorian date, null only for an invalid short-month day. |
| `scheduledDate` | Gregorian date or null when skipped. |
| `status` | `scheduled`, `shifted`, or `skipped`. |
| `reason` | `business_day`, `shifted_next`, `shifted_previous`, `closed_day`, `invalid_month_day`. |
| `shiftDays` | Zero, signed calendar-day movement, or null skipped. |
| `collides` | Whether same-date collision was explicitly allowed. |

Serialization is snake_case with real integers, booleans, nulls, and date strings. Invalid options use `WorkdayConfigurationException`; malformed/nonexistent anchor uses `InvalidArgumentException`; exhausted shifts/horizon/conversions, rejected collisions, and reversal use contextual `RuntimeException`.

A business date can have empty hours. Choose an actual operational timestamp separately before calling [delivery planning](delivery.md) or [slots](delivery-slots.md). A recurrence date alone is not an absolute timestamp for those methods. See [API reference](api-reference.md).
