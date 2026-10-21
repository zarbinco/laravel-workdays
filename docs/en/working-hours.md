# Working hours and exact time

[Documentation](README.md) · [فارسی](../fa/working-hours.md)

Use working-time methods when a task consumes opening hours rather than whole business dates. Split shifts, half-days, exceptional schedules, and valid overnight carry-in all contribute to the same effective schedule.

Apply the [Config quick start](getting-started.md#a-complete-config-example), whose weekdays open `09:00–10:00` and `11:00–12:00`.

## Carry a partial minute across a break

<!-- example: fractional-time -->
```php
use Carbon\CarbonImmutable;
use Zarbinco\LaravelWorkdays\Facades\Workday;

$calendar = Workday::profile('global');
$start = CarbonImmutable::parse('2026-11-02T09:59:30.123456Z');
$end = $calendar->addBusinessMinutes($start, 1);

echo $end->format('H:i:s.u'), PHP_EOL;
echo $calendar->diffBusinessMinutes($start, $end), PHP_EOL;
echo $calendar->previousBusinessTime(
    CarbonImmutable::parse('2026-11-02T10:00:00Z'),
)->format('H:i:s.u'), PHP_EOL;
```
```text
11:00:30.123456
1
09:59:59.999999
```

Minute budgets are integers; consumption preserves seconds and microseconds. The unconsumed part of the minute continues after lunch. `diffBusinessMinutes()` sums actual overlap first, then truncates the complete signed total toward zero once. It does not round each fragment separately: two 40-second fragments total one minute, while 59.999999 seconds totals zero. `diffBusinessHours()` is that integer-minute result divided by 60.

## Membership, navigation, and zero

Working windows are half-open, `[start, end)`. Opening is included; closing is excluded. Positive work can finish exactly at closing, although work or handoff cannot start there.

`nextBusinessTime()` returns the earliest open instant at or after input. `previousBusinessTime()` returns input unchanged when open, otherwise the latest earlier open instant. At an isolated closing it returns `end - 1 microsecond`, such as `09:59:59.999999` above.

Core `addBusinessMinutes($datetime, 0)` returns the input instant after validating the input and working-hours capability, even if closed. A zero-minute delivery `work` stage first normalizes to an open instant. `addBusinessHours()` accepts finite non-negative integer/float hours that convert to whole minutes within `0.000001` minute tolerance; `1.5` means 90 minutes. Invalid conversion and integer overflow fail explicitly.

Direct core datetime strings use Carbon parsing in the application/default timezone; timezone-aware `DateTimeInterface` values preserve their timezone. Prefer explicit objects for deterministic calls. A profile name does not select a timezone. `diffBusinessMinutes($a, $b)` evaluates the schedule in the original first argument's timezone before ordering instants, converting the second into that zone. Reversing inputs within the same evaluation timezone reverses the sign; swapping different timezone representations can select another schedule timezone.

## Special, seasonal, and overnight hours

The following is a configuration excerpt to add to a profile; it is not a standalone PHP program:

```php
'working_hours' => [
    'Monday' => [['09:00', '12:00'], ['13:00', '17:00']],
    'Thursday' => [['09:00', '13:00']],
],
'special_working_hours' => [
    '2026-11-02' => [['10:00', '12:00']],
    '2026-11-03' => [],
],
'seasonal_working_hours' => [
    ['from' => '2026-12-01', 'through' => '2026-12-31',
        'weekdays' => ['Monday' => [['08:00', '18:00']], 'Friday' => []]],
],
'extra_working_day_hours' => [['09:00', '12:00']],
'allow_overnight_working_hours' => false,
```

Calendar eligibility is checked before new shifts start. Extra working days reopen weekends/holidays. On an eligible start date, hours are selected in this order:

1. Exact `special_working_hours` date, including explicit `[]`.
2. Matching season's explicit weekday, including `[]`.
3. Regular `working_hours` weekday.
4. `extra_working_day_hours` only for an extra working day with no weekly windows and no explicit exact/seasonal override.

An empty exact or seasonal override means no shifts start there; it does not change `isBusinessDay()`. Seasonal entries contain exactly Gregorian `from`, `through`, and `weekdays`; ranges are inclusive, ordered, non-overlapping, and may span a year boundary. They are not annually recurring seasons. Missing seasonal weekdays use weekly hours.

Hours use zero-padded `HH:MM` endpoints and non-overlapping window lists. Half-days are shorter windows. `allow_overnight_working_hours` is a boolean defaulting to false; true permits `22:00–06:00` and end-only `24:00`, including full-day `00:00–24:00`. Equal clocks, invalid times, overlapping windows, and disallowed overnight syntax fail. End before start means one next-date ending, never a multi-day shift.

An overnight shift belongs to its start date. Eligible Friday `22:00–06:00` can remain open Saturday at `02:00`, although Saturday is not a business date. Closing the receiving date does not cancel incoming time; edit its owner date instead. `workingWindowsFor()` returns effective daily `TimeWindow` objects: Monday `22:00–24:00`, then Tuesday `00:00–06:00`. Overlapping carry-in and same-day windows are unioned to avoid double counting. Independently touching windows remain separate, which matters for [slots](delivery-slots.md).

## Timezones, DST, and bounds

Configured endpoints are constructed as local wall-clock times on the actual date. A nonexistent or ambiguous configured boundary fails with profile/date/timezone context, including an explicitly configured `24:00`. Valid endpoints spanning an offset change count real elapsed time: New York Sunday `00:00–04:00` consumes 180 minutes at spring-forward and 300 at fall-back; a valid `22:00–06:00` overnight shift consumes 420 or 540.

A synthetic fragment beginning at nonexistent midnight may use the same date's first real instant. Ambiguous synthetic midnight or unsupported backwards/split-date history fails explicitly. A completely skipped civil date contributes no invented time. [Slots](delivery-slots.md#timezone-transitions) apply a more conservative candidate policy.

Navigation examines the original local date and at most `max_scan_days` date-label offsets. Positive addition uses one forward budget anchored before normalization; breaks and new dates do not reset it. Differences validate their complete requested span and do not return partial totals. Skipped date labels still consume the bound. Supported core instants fit UTC years `0001–9999`; unsafe integer conversions fail before duration-proportional work.

A final allowed daily slice may finish at its exclusive `24:00` endpoint without occupying positive time on the next date. Navigation cannot return that closed instant. Delivery and SLA separately require their result date to fit their horizon, so the same midnight can fail there. See [API limits](api-reference.md#limits-and-horizons).

The [executable calendar checks](../examples/working-time-verification.php) exercise both Config and a private SQLite connection. Their setup and cleanup are intended for a local Tinker session; read the file before running it. See [Contributing](../../CONTRIBUTING.md) for verification procedures.
