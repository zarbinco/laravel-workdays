# Laravel integration

[Documentation](README.md) · [فارسی](../fa/laravel-integration.md)

Use the facade, injected manager, Carbon macros, or validation rules to apply the same calendars in an application. These wrappers do not create application models, routes, queues, reservations, or notifications.

Apply the [Config quick start](getting-started.md#a-complete-config-example). Laravel discovers the provider automatically.

## Validate a business date

<!-- example: validation-rule -->
```php
use Illuminate\Support\Facades\Validator;
use Zarbinco\LaravelWorkdays\Rules\WorkdayRule;

$validator = Validator::make(
    ['delivery_date' => '2026-11-09'],
    ['delivery_date' => ['required', WorkdayRule::businessDay('global')]],
);
echo json_encode($validator->passes(), JSON_THROW_ON_ERROR), PHP_EOL;
```
```text
true
```

The same rule objects work in Form Requests, controller validation, and Livewire validation. Business-time rules require working hours; missing capability produces a useful validation message rather than an exposed configuration exception.

## Rule factories

All factories accept an optional profile; omission selects the configured default. Use explicit classifications rather than an ambiguous holiday rule.

| Factory | Predicate |
|---|---|
| `businessDay()`, `notBusinessDay()` | Eligible business date / its negation. |
| `nonWorkingDay()` | Non-working date. |
| `weekend()`, `notWeekend()` | Weekend membership / its negation. |
| `calendarHoliday()`, `notCalendarHoliday()` | Recurring calendar holiday / its negation. |
| `customHoliday()`, `extraWorkingDay()` | Exact holiday / reopening entry. |
| `businessTime()`, `notBusinessTime()` | Open working instant / its negation. |
| `afterBusinessDays($days, $from = null, $profile = null)` | Value on or after forward calculated date. |
| `beforeBusinessDays($days, $from = null, $profile = null)` | Value on or before backward calculated date. |

Relative rules are inclusive at the calculated boundary. An omitted relative anchor uses the rule's default current-date behavior; supply a recorded deterministic anchor for reproducible decisions. Customize any rule's message with `->message('Please choose a working day.')`.

## Carbon macros

Macros register by default on `Carbon\Carbon`, `Carbon\CarbonImmutable`, and naturally `Illuminate\Support\Carbon`. The prefixed names avoid ambiguity with other libraries:

<!-- example: carbon-macro -->
```php
use Carbon\CarbonImmutable;

$date = CarbonImmutable::parse('2026-11-06T00:00:00Z');
echo $date->workdayNextBusinessDay('global')->toDateString(), PHP_EOL;
echo $date->toDateString(), PHP_EOL;
```
```text
2026-11-09
2026-11-06
```

Date-returning macros create a new object. Immutable inputs return immutable dates; mutable Carbon inputs return a new instance of their original class where possible. The original input is not changed.

Prefixed macros cover day predicates, next/previous/add/sub/difference, explanation, working windows, business-time membership/navigation/addition/difference. The [API reference](api-reference.md#carbon-macro-names) lists exact names and aliases. The profile is the final optional argument; difference macros take the end value first, and addition macros take the amount first.

`carbon_macros.enabled` and `short_aliases` default true; `override_existing` defaults false. Existing macros are preserved unless override is enabled. Native Carbon methods are always preserved. Short names only register when available; use prefixed names when another package owns similar aliases. Configuration changes after provider boot do not retroactively unregister macros.

## Inject the manager

The facade resolves `Zarbinco\LaravelWorkdays\WorkdayManager`; it is also available as container key `workdays`. Inject that class into an application service, then call `$manager->profile('global')` or a default-profile method. Managers open a new view for each public operation; retaining a calculator retains its original view. See [Configuration](configuration.md#source-and-operation-lifetime).

## Application service sketch

The following is an integration sketch, not a standalone runnable example. The application supplies its `Order` model, stored receipt cast, stock/payment checks, profile setup, fields, transaction policy, and authorization:

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$decision = Workday::profile('warehouse')->resolveCutoff($order->received_at);
$order->business_date = $decision->effectiveBusinessDate->toDateString();
$order->cutoff_reason = $decision->reason;
$order->save();
```

Saving is an application decision. Calendar APIs themselves do not save records. Resolve [context profiles](configuration.md#routing-rules) after tenant/authorization checks, choose explicit timezones, and reuse recorded timestamps rather than substituting a new `now()` when reviewing an old decision.

Use [slots](delivery-slots.md) after a meaningful readiness stage, [snapshots](snapshots.md) for immutable estimate evidence, and [SLA](sla.md) for recorded pause timelines. Applications schedule reevaluation and notifications themselves.
