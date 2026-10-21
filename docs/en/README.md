# Laravel Workdays documentation

[Package](../../README.md) · [فارسی](../fa/README.md)

Calculate business dates, consume working time, and plan calendar-based delivery with named calendars. Laravel Workdays uses Gregorian input dates, with Gregorian, Jalali, and Hijri holiday rules and optional Jalali monthly recurrence.

These guides describe the current source checkout. The changelog identifies `1.0.0` as unreleased; do not assume an older installed package contains every API documented here. Check your installed version and its matching documentation.

## Start here

1. [Install and make your first calculation](getting-started.md).
2. [Configure calendars, inheritance, and routing](configuration.md).
3. [Choose business dates](business-days.md) or [consume working time](working-hours.md).
4. Choose [Database storage](database.md) when calendars need explicit revisioned publication.

## Task guides

| Task | Guide |
|---|---|
| Configure weekends, holidays, profiles, and context selection | [Configuration](configuration.md) |
| Publish, edit, transfer, or restore complete calendar definitions | [Database](database.md) |
| Find dates, count days, and explain calendar decisions | [Business days](business-days.md) |
| Apply split, special, seasonal, half-day, or overnight schedules | [Working hours](working-hours.md) |
| Apply cutoff, plan stages, or find the latest acceptable receipt | [Delivery planning](delivery.md) |
| Offer delivery or pickup intervals | [Delivery slots](delivery-slots.md) |
| Evaluate deadlines with pauses and resumption | [SLA](sla.md) |
| Generate weekly or Gregorian/Jalali monthly dates | [Recurring schedules](recurring-schedules.md) |
| Calculate ordered batches and handle item errors | [Bulk calculations](bulk-calculations.md) |
| Capture, restore, and compare a delivery estimate | [Snapshots](snapshots.md) |
| Use Carbon macros, validation rules, and application services | [Laravel integration](laravel-integration.md) |

## Reference and support

- [API reference](api-reference.md): method signatures, configuration, result fields, CLI options, types, and limits.
- [Troubleshooting](troubleshooting.md): source setup, date parsing, search limits, timezone errors, and calendar data.
- [Architecture](../ARCHITECTURE.md), [feature matrix](../../FEATURE_PRESERVATION_MATRIX.md), and [numerical history](../../NUMERICAL_CHANGELOG.md): implementation and verification details.
- [Contributing](../../CONTRIBUTING.md) and [MIT license](../../LICENSE).

Calendar calculations do not reserve stock or capacity. Applications own persistence, authorization, jobs, notifications, and customer promises. Specific operational constraints appear beside the relevant API.

## Links from earlier documentation

<a id="installation"></a>
<a id="basic-usage"></a>

Installation and the first example are in [Getting started](getting-started.md).

<a id="profiles"></a>
<a id="profile-inheritance-and-context-routing"></a>
<a id="config-reference"></a>

Profile and source settings are in [Configuration](configuration.md) and the [API reference](api-reference.md).

<a id="result-object--calculate"></a>
<a id="explaining-a-date"></a>

Date results and reason objects are in [Business days](business-days.md).

<a id="business-hours-and-half-days"></a>
<a id="special-hours-seasonal-schedules-and-overnight-shifts"></a>

Time calculations are in [Working hours](working-hours.md).

<a id="order-cut-off-policy"></a>
<a id="multi-stage-delivery-planning"></a>
<a id="reverse-delivery-scheduling-and-latest-order-time"></a>

Cutoff, stages, and reverse search are in [Delivery planning](delivery.md).

<a id="calendar-versions-and-delivery-promise-snapshots"></a>
<a id="delivery-and-pickup-time-slots"></a>

See [Snapshots](snapshots.md) and [Delivery slots](delivery-slots.md).

<a id="business-sla-evaluation-and-pauseresume"></a>
<a id="recurring-business-schedules-and-subscription-cycles"></a>
<a id="bulk-calculations-and-holiday-prefetching"></a>

See [SLA](sla.md), [Recurring schedules](recurring-schedules.md), and [Bulk calculations](bulk-calculations.md).

<a id="carbon-macros"></a>
<a id="validation-rules"></a>
<a id="public-api-reference"></a>

See [Laravel integration](laravel-integration.md) and the [API reference](api-reference.md).
