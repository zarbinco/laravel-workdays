# Bulk calculations

[Documentation](README.md) · [فارسی](../fa/bulk-calculations.md)

Evaluate an ordered batch through the existing APIs while sharing one consistent calendar view. Bulk preserves each API's types and calculation rules, and can collect individual domain failures.

Apply the [Config quick start](getting-started.md#a-complete-config-example).

## Calculate two dates

<!-- example: bulk-calculation -->
```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$result = Workday::bulkCalculate([
    ['id' => 'friday', 'operation' => 'is_business_day', 'profile' => 'global', 'date' => '2026-11-06'],
    ['id' => 'saturday', 'operation' => 'is_business_day', 'profile' => 'global', 'date' => '2026-11-07'],
]);
foreach ($result->items as $item) {
    echo $item->id, ':', json_encode($item->result, JSON_THROW_ON_ERROR), PHP_EOL;
}
echo $result->succeeded, '/', $result->failed, PHP_EOL;
```
```text
friday:true
saturday:false
2/0
```

A false business-day result is successful data, not a failed item. Results retain input order.

## Supported operations

Each item has `id`, `operation`, and exactly the listed argument keys. Nested options and argument types follow the corresponding standalone API.

| `operation` | Arguments | Typed `result` |
|---|---|---|
| `is_business_day` | `profile`, `date` | Boolean. |
| `working_windows` | `profile`, `date` | Array of `TimeWindow`. |
| `add_business_days` | `profile`, `date`, `days` | `CarbonImmutable`. |
| `add_business_minutes` | `profile`, `datetime`, `minutes` | `CarbonImmutable`. |
| `resolve_cutoff` | `profile`, `received_at` | `CutoffResult`. |
| `plan_delivery` | `received_at`, `plan` | `DeliveryPlanResult`. |
| `delivery_slots` | `ready_at`, `options` | `DeliverySlotsResult`. |
| `recurring_schedule` | `anchor_date`, `options` | `RecurringScheduleResult`. |
| `evaluate_sla` | `started_at`, `evaluated_at`, `options` | `SlaResult`. |
| `reverse_plan_delivery` | `desired_completion_at`, `plan`, `options` | `ReverseDeliveryPlanResult`. |

Operations must be a nonempty indexed list of at most 1,000 associative items. IDs are unique nonblank UTF-8 strings up to 128 characters. A batch can reference at most 50 distinct profiles, including nested plan/options references. Each copied argument structure has depth limit 32 and 100,000 nodes.

## Options and error handling

| Top-level option | Default | Meaning |
|---|---|---|
| `on_error` | `collect` | `collect` or `throw`. |
| `prefetch` | Omitted | Exactly `from` and `through`, strict Gregorian `Y-m-d`, ordered, up to 62 inclusive labels. |

Envelope mistakes reject the whole call before calculation: invalid lists/item shapes, missing/duplicate/invalid IDs, unknown operations/keys, cross-operation fields, invalid top-level options/ranges, and excess items/profiles. These use `WorkdayConfigurationException`.

Missing or incorrectly typed operation arguments, unknown selected profiles, nested option errors, and recognized bounded-calculation failures are item domain failures. With collect, independent later items continue. With throw, the first such failure raises a contextual `RuntimeException`, retaining the original exception as previous cause. There are no automatic retries.

Source-open/read failures (`DefinitionSourceException`), missing schema, PDO/query/connection failures, serious infrastructure failures, programming errors, and internal type errors propagate even with collect. They are not serialized into item errors or converted to Config fallback. Check exception causes rather than treating all `RuntimeException` values as safely collectable.

## Results

Immutable `BulkCalculationResult` exposes `total`, `succeeded`, `failed`, `items`, and `toArray()`. Each immutable `BulkCalculationItemResult` has `id`, `operation`, `success`, `result`, and `error`. Success retains the actual typed return and null error. Failure has null result and an error with original exception `type` and domain `message`, limited to 2,048 UTF-8 characters.

Errors do not attach stack traces, SQL/connection metadata, or database credentials; infrastructure cause chains propagate instead. Calendar labels/profile names can remain in domain messages, so apply your application's logging/privacy policy.

`toArray()` uses snake_case and JSON-compatible values. DTOs/windows use their own serialization. Carbon arithmetic timestamps retain their actual offset and six microsecond digits; Bulk does not force them all to UTC. Booleans/integers/null stay typed and recurrence output stays date-only.

## Source lifetime, prefetch, and chunking

The batch opens one detached source view. Effective profiles, ancestors, and calculators are reused within it. Later Config edits/publications affect a new batch, not a running batch. Malformed selected profiles in collect mode do not block unrelated valid items; mutable reverse-search state remains per operation.

Prefetch is an optional bounded hint, not a calculation range or source selector. Outside-range calls remain valid; it does not enlarge scan bounds. Config issues no holiday SQL. Database materializes a selected raw holiday definition once per view, so the hint is redundant there. Database pins a revision and caches present/absent profile reads rather than querying holidays per date.

For large application datasets, retrieve records in application-owned chunks and submit at most 1,000 calculations per call. Handle and release each result before the next chunk. Application query models, authorization, persistence, queue placement, and retry policies belong to the application. These calls execute synchronously; they do not create jobs. [Contributing](../../CONTRIBUTING.md) and the [feature matrix](../../FEATURE_PRESERVATION_MATRIX.md) describe measured checks without implying a universal performance guarantee. The [API reference](api-reference.md#exception-categories) covers boundary and error categories.
