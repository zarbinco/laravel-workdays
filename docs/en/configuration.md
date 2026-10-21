# Configuration, inheritance, and routing

[Documentation](README.md) · [فارسی](../fa/configuration.md)

Profiles let teams, locations, and services use different calendars. Inheritance shares common rules; routing selects a profile from explicit application context.

Use the [Config quick start](getting-started.md#a-complete-config-example) first. Config and Database use the same operational document and merge rules. Database additionally validates the complete candidate before publication.

## Add a child calendar

<!-- example: profile-routing -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

config([
    'workdays.definitions.profiles.local' => [
        'extends' => 'global',
        'custom_holidays' => ['2026-11-09' => 'Local closure'],
    ],
    'workdays.definitions.profile_routing' => [
        'fallback_profile' => 'global',
        'rules' => [
            ['id' => 'local-service', 'match' => ['region' => 'local'], 'profile' => 'local'],
        ],
    ],
]);

echo Workday::resolveProfile(['region' => 'local'])->profile, PHP_EOL;
echo Workday::profileFor(['region' => 'local'])->nextBusinessDay('2026-11-06')->toDateString(), PHP_EOL;
```
```text
local
2026-11-10
```

The child inherits weekends and hours, then adds Monday's closure. Routing returns `local`; Tuesday is its next business date. Routing does not discover an application's warehouse, tenant, model, or timezone.

## Source and operation lifetime

Select `config` or `database` in `workdays.driver`. One source supplies settings, profiles, routing, holidays, hours, and cutoff together. Config never reads Database definitions; Database never merges file definitions. Invalid drivers, `chain`, `hybrid`, `storage.driver`, and removed root-level operational keys fail explicitly. Put `default_profile`, `include_start_date`, `hijri`, `profile_routing`, and `profiles` inside `definitions`.

Each calculation uses the definitions visible when it starts. Later edits affect new operations, while a running operation keeps its original view. A calculator retained from `profile()` also keeps its original view: calling another method on that object does not refresh it. Request a new calculator to see edits. `profileFor()` resolves the route and constructs its calculator from the same view.

Config resolves selected profiles and ancestors as needed. Invalid unrelated routing is not consulted by ordinary APIs; cutoff is only required by cutoff calls or a plan that opts into it. Database publication validates every supplied profile, route, and capability before activation.

## Inheritance and clearing

`extends` must name an existing profile. Chains support at most 16 profiles including child and root; missing parents, cycles, self-reference, and excess depth fail. An omitted child key inherits. Explicit sections behave as follows:

| Section | Child behavior |
|---|---|
| `weekends` | Replace the list; `[]` removes all weekend days. |
| `holidays` | Merge by calendar and `MM-DD`; an empty calendar map clears that calendar, and `holidays => []` clears all inherited recurring holidays. String/null labels remain labels. |
| `custom_holidays`, `extra_working_days`, `special_working_hours` | Merge by Gregorian date; replace each supplied date's complete value. An empty whole map clears inherited entries. |
| `working_hours` | Merge normalized weekdays; replace each supplied weekday's full list. Empty weekday closes it; empty whole section clears inherited weekly hours. |
| `extra_working_day_hours` | Replace the complete list, including `[]`. |
| `seasonal_working_hours` | Replace the complete ordered list; `[]` clears it. |
| `allow_overnight_working_hours` | Inherit when omitted; otherwise replace with a boolean. Disabling while inherited hours still need permission fails. |
| `cutoff` | Inherit omitted keys; merge normalized weekday overrides and preserve null. `cutoff.weekdays => []` clears weekday overrides. |
| Other supported fields | Inherit omitted values; replace supplied values as a whole. |

There is no generic recursive merge of indexed arrays. A parent's `Monday` and child's `1` address the same weekday; duplicate aliases within one layer are rejected. Selected-child identity is retained after inheritance. Shared settings and timezones supplied to operations are outside the parent chain.

An empty exact-date or explicit seasonal weekday hours override suppresses extra-day fallback. An empty weekly entry alone does not suppress eligible `extra_working_day_hours`. See [schedule precedence](working-hours.md#special-seasonal-and-overnight-hours).

## Routing rules

`profile_routing` contains exactly `fallback_profile` and an ordered `rules` list, which may be empty. Each rule contains exactly `id`, `match`, and `profile`. IDs are unique nonblank strings; all fallback/rule targets must exist. `match` is a nonempty associative map.

Context and match keys use ASCII `[A-Za-z_][A-Za-z0-9_]*`. Values are integers or nonempty strings. Booleans, floats, arrays, objects, and null are rejected, including in unused context fields. Whitespace in strings is preserved; no trimming, casting, case folding, regex, wildcards, or callbacks apply.

A rule matches when every configured field exists and is strictly equal. `42` differs from `'42'`. Extra context fields are allowed. Most match fields wins; equal specificity uses the first configured rule. No match, including context `[]`, selects the explicit `fallback_profile`, which can differ from `default_profile`.

`resolveProfile()` returns immutable `ResolvedProfile` with `profile`, `source` (`rule`/`fallback`), and `ruleId`; `toArray()` uses `rule_id` and omits raw context. `profileFor()` returns the corresponding calculator. Plan stages use their explicit profile names and do not route automatically.

## Holidays and Iran data

Weekends accept ISO integers `1`–`7` (Monday–Sunday), English names/abbreviations, and supported Persian names. For example, `6`, `Saturday`, `sat`, and `شنبه` refer to Saturday. Duplicate normalized aliases are invalid.

Recurring holiday maps use calendar-specific `MM-DD` keys:

```php
'holidays' => [
    'gregorian' => ['01-01' => 'New Year'],
    'jalali' => ['01-01' => 'Nowruz'],
    'hijri' => ['01-10' => 'Ashura'],
],
'custom_holidays' => ['2026-11-10' => 'Company closure'],
'extra_working_days' => ['2026-11-14' => 'Extra Saturday'],
```

This is a configuration excerpt, not an official dataset. Labels are strings or null. Exact dates are Gregorian `Y-m-d`. An extra working day reopens a weekend or holiday; individual classification predicates still report their underlying matches. See [Business days](business-days.md).

Public date strings remain Gregorian. Jalali/Verta conversion resolves Jalali holidays and performs [Jalali monthly recurrence](recurring-schedules.md). Hijri adapters resolve recurring holidays; Hijri monthly recurrence is unsupported. Hijri methods are `mathematical`, `umm_al_qura`, `high_judiciary`, and `diyanet`; integer `adjustment` may be nonzero only with `mathematical`. Official moon-sighting dates may differ, so use reviewed exact dates when required.

The Iran preset supplies recurring Jalali/Hijri holidays and Thursday/Friday weekends. It does not supply a complete official annual dataset or automatic updates. Iran 1405 remains optional local import data. [Database import](database.md#import-a-local-iran-calendar) documents validation, conflicts, and the guard protecting explicitly cleared inherited holidays.

For all settings, defaults, and exception categories, use the [API reference](api-reference.md).
