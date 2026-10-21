# Database definitions and publication

[Documentation](README.md) · [فارسی](../fa/database.md)

Store the complete operational calendar document in immutable revisions when definitions need controlled edits. Database supplies settings, profiles, inheritance, routing, holidays, hours, and cutoff together. It ignores file definitions and never falls back to Config.

## Try a complete isolated example

This local Tinker example requires Laravel, the installed package, and PDO SQLite. It creates a fresh named `:memory:` connection, runs only the package schema there, publishes a small document, and discards the connection in `finally`. It neither uses the application's default database nor needs a previously configured profile. The reflected path works in a Composer dependency or source checkout.

<!-- example: database-publication -->
```php
use Illuminate\Support\Facades\DB;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionConflictException;
use Zarbinco\LaravelWorkdays\Facades\Workday;

$connection = 'workdays_documentation';
if (config()->has('database.connections.'.$connection)) {
    throw new LogicException('Choose an unused example connection name.');
}
$originalWorkdays = config('workdays');
$originalConnections = config('database.connections');
$definitions = [
    'default_profile' => 'global',
    'include_start_date' => false,
    'hijri' => ['method' => 'mathematical', 'adjustment' => 0],
    'profile_routing' => null,
    'profiles' => ['global' => [
        'weekends' => ['Saturday', 'Sunday'],
        'holidays' => [], 'custom_holidays' => [], 'extra_working_days' => [],
    ]],
];

try {
    config([
        'database.connections.'.$connection => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ],
        'workdays.database.connection' => $connection,
    ]);
    $packageRoot = dirname((new ReflectionClass(DatabaseDefinitionRepository::class))->getFileName(), 3);
    $migration = require $packageRoot.'/database/migrations/2026_10_20_000001_create_workday_definitions_tables.php';
    $migration->up();

    $repository = app(DatabaseDefinitionRepository::class);
    $first = $repository->publish($definitions, expectedRevisionId: null);
    $noop = $repository->publish($definitions, expectedRevisionId: $first->revisionId);
    $definitions['profiles']['global']['custom_holidays']['2026-11-09'] = 'Inventory';
    $edit = $repository->publish($definitions, expectedRevisionId: $first->revisionId);
    echo json_encode([
        $first->revisionId, $first->changed,
        $noop->revisionId, $noop->changed,
        $edit->revisionId, $edit->changed,
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    try {
        $repository->publish($definitions, expectedRevisionId: $first->revisionId);
    } catch (DefinitionConflictException) {
        echo 'stale revision rejected', PHP_EOL;
    }
    config(['workdays.driver' => 'database']);
    echo Workday::profile('global')->nextBusinessDay('2026-11-06')->toDateString(), PHP_EOL;
} finally {
    DB::purge($connection);
    config(['database.connections' => $originalConnections, 'workdays' => $originalWorkdays]);
}
```
```text
[1,true,1,false,2,true]
stale revision rejected
2026-11-10
```

The first publication initializes revision 1. Identical content with the current token is a no-op. The holiday edit publishes revision 2; a stale revision 1 token is rejected even when the submitted content is identical to revision 2. New calculations see Monday's closure.

## Set up an application connection

```bash
php artisan workdays:install --driver=database
```

This publishes config and the revision-schema migration, without running it, initializing definitions, switching the runtime source, importing a dataset, or editing `.env`. An Iran preset can be selected with `--preset=iran`. Use a reviewed application connection and backup policy. Set `database.connection` explicitly in `config/workdays.php`; null chooses the application's default connection when an operation opens. Invalid explicit connections fail.

Run the published migration deliberately with Laravel's `migrate --database=<connection> --path=<published-migration-path>`, using the actual published filename (its timestamp may be changed by Laravel). This command is a template: replace both placeholders with reviewed values. The schema contains `workday_definition_state`, `workday_definition_revisions`, and `workday_definition_profiles`. Older holiday tables do not initialize it. No schema work occurs at package boot.

Initialize through `publish($definitions, expectedRevisionId: null)` or explicit JSON transfer below. Select `workdays.driver = database` yourself after inspecting the published document. Config calculations remain isolated from missing Database schema/connection; Database calculations require both schema and initial publication.

## Edit safely

`DatabaseDefinitionRepository::current()` returns a complete raw `DefinitionRevision`, or null only for an existing uninitialized schema. `revision($positiveId)` loads a historical document. `validate($definitions)` returns a validated `DefinitionDocument` without connecting. `publish($definitions, $expectedRevisionId, $metadata = [])` requires an explicit null initialization token or current positive integer token, returning `DefinitionPublication` (`revisionId`, `changed`, `contentHash`).

**Publication replaces the complete logical document.** Omission deletes data. Removing a parent/default/routing target can invalidate inheritance or routing and fails validation. Fetch current definitions, deliberately reconcile the complete candidate, validate, and publish with its exact revision token. A stale editor gets `DefinitionConflictException`; there is no automatic overwrite/rebase. Identical content can be a no-op only after the token check. Publish historical content with the current token to restore it as a new revision.

Publication validates all raw profiles and effective descendants, routes, dates, timezones, hours, and supplied cutoff policies. Raw `extends`, omitted sections, empty maps, null labels/overrides, aliases, ownership, flags, and list order remain meaningful; export does not flatten inheritance or inject optional schedules.

One transaction inserts the immutable revision/profile set and atomically changes the pointer. Failures roll back the candidate. Reads use the primary/write PDO and pin a connection/revision for the operation, including later lazy parent reads and absent-name caches. A new operation sees a later publication; a retained calculator does not refresh. In caller-owned transactions, visibility and final commit remain the caller's responsibility; the package does not commit or roll back the outer transaction.

Each changed publication stores a full raw profile set. Storage grows with size and publication count; no automatic pruning or pointer reset is provided. Applications own authorized administration, backups, retention/storage monitoring, and edit frequency. Manual SQL changes are unsupported and integrity checks can expose corruption.

## Transfer explicitly with JSON

These commands are templates for reviewed local paths and revision IDs:

```bash
php artisan workdays:definitions:export /absolute/path/definitions.json --source=config
php artisan workdays:definitions:import /absolute/path/definitions.json --initialize --dry-run
php artisan workdays:definitions:import /absolute/path/definitions.json --initialize
php artisan workdays:definitions:export /absolute/path/current.json --source=database
php artisan workdays:definitions:export /absolute/path/prior.json --source=database --revision=1
php artisan workdays:definitions:import /absolute/path/edited.json --expected-revision=2 --dry-run
```

Export requires explicit `--source=config|database`; `--revision` is Database-only. An existing destination requires deliberate `--force`. Import always targets Database, even while runtime selects Config, and requires exactly one of `--initialize` or positive `--expected-revision`, including dry-run. Dry-run validates the candidate and precondition without seeding state, writing definitions, or switching the driver. Submit without dry-run only after review.

The envelope is strict JSON with exactly `format_version: 1` and `definitions`. It excludes bootstrap connection/secrets/bounds. `DefinitionDocument::fromJson()` decodes it; for Database-to-Config transfer deliberately place the resulting raw `definitions` under the file's existing `definitions` key and select Config yourself. Do not execute imported definitions as PHP or use `unserialize()`.

Document limits are 4 MiB (4,194,304 bytes) including the compact envelope, depth 32, 100,000 nodes, 1–10,000 profiles, and UTF-8 profile names up to 255 bytes. Optional metadata supports nonblank `actor` up to 255 bytes, `reason` up to 1,000 bytes, JSON-array `import` provenance, and a combined 16 KiB limit. No authenticated actor is inferred. Content hashes exclude metadata/revision IDs/timestamps and provide integrity, not authentication.

## Import a local Iran calendar

No complete annual dataset is automatically shipped, fetched, or activated. Supply reviewed trusted local PHP files named by Jalali year. They are executable local datasets, unlike strict JSON definition transfer. `iran_official.calendar_path` selects their directory; other `iran_official` fields do not automatically activate runtime data.

```bash
php artisan workdays:import-iran-calendar 1405 --calendar-path=/absolute/path/calendars/iran --dry-run
php artisan workdays:import-iran-calendar 1405 --profile=iran --calendar-path=/absolute/path/calendars/iran --expected-revision=2
```

These are templates; the second requires selected initialized Database, a configured target, a reviewed dataset, and the actual current revision. Config supports dataset-only dry-run without Database reads/writes or transfer. Database dry-run previews insert/update/skip/conflict counts against one pinned revision. Differing effective values conflict unless deliberate `--force` authorizes replacement; force cannot bypass stale tokens, invalid data, source/profile checks, or atomic validation. Identical import is a no-op.

Datasets require integer Jalali `year` (1–3177), `country = IR`, `calendar = jalali`, source `name`/absolute HTTP(S) `url`/Gregorian `retrieved_at`, and a holiday list. Source strings are nonblank and at most 4,096 bytes. Each entry requires corresponding actual Jalali `date`, Gregorian `gregorian_date`, nonblank `title` (up to 4,096 bytes), `calendar = jalali`, `type = official_holiday`, and `is_official_holiday = true`. Validation checks correspondence, year membership, duplicates, and a 4 MiB size bound. Inspect [the reader contract](../../src/Calendars/IranOfficialCalendar.php) before supplying local files.

**Explicit inherited holiday clears are protected.** An omitted child map inherits; `custom_holidays => []` clears. Adding an entry can otherwise reactivate excluded parent dates. Both Database preview and publication compare the resolved candidate with the original effective map plus only actual planned inserts/updates. Skipped entries authorize no change. Unexpected restored dates/values reject the operation with bounded diagnostics and no revision change; `--force` cannot bypass this guard. Deliberately resolve inheritance or select a suitable profile before retrying. Safe representable imports remain allowed.

See [Configuration](configuration.md), [Snapshots](snapshots.md), [Troubleshooting](troubleshooting.md), and the [API reference](api-reference.md#database-administration-and-commands) for lifecycle, command, boundary, and error distinctions.
