<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigResolver;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class ImportIranCalendarCommandTest extends TestCase
{
    #[DataProvider('unsupportedWriteDrivers')]
    public function test_non_database_writes_are_rejected_without_touching_database(mixed $driver): void
    {
        config()->set('workdays.driver', $driver);
        $this->makeDatabaseUnusable();
        $queries = $this->recordQueries();
        $this->assertSame(1, Artisan::call('workdays:import-iran-calendar', ['year' => 1405]));
        $this->assertStringContainsString($driver === 'config' ? 'writes require workdays.driver=database' : 'requires workdays.driver=config or database', Artisan::output());
        $this->assertSame([], $queries->queries);
    }

    public static function unsupportedWriteDrivers(): array
    {
        return [['config'], ['chain'], ['hybrid'], [null]];
    }

    public function test_config_dry_run_validates_only_dataset_without_database_or_target_lookup(): void
    {
        $this->makeDatabaseUnusable();
        $queries = $this->recordQueries();
        $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', $this->arguments(['--profile' => 'not-configured', '--dry-run' => true])));
        $output = Artisan::output();
        $this->assertStringContainsString('1 holiday entries validated', $output);
        $this->assertStringContainsString('Dataset-only validation. No database records or import conflicts were inspected; no records were written.', $output);
        $this->assertSame([], $queries->queries);
    }

    public function test_missing_dataset_and_configured_or_explicit_paths_are_checked(): void
    {
        config()->set('workdays.iran_official.calendar_path', $this->createTemporaryIranOfficialCalendarFixture(1404));
        $this->assertSame(1, Artisan::call('workdays:import-iran-calendar', ['year' => 1405, '--dry-run' => true]));
        $this->assertStringContainsString('dataset for year [1405] is not available', Artisan::output());
        $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', $this->arguments(['--dry-run' => true])));
        config()->set('workdays.iran_official.calendar_path', $this->createTemporaryIranOfficialCalendarFixture());
        $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', ['year' => 1405, '--dry-run' => true]));
    }

    #[DataProvider('invalidInputs')]
    public function test_malformed_command_inputs_are_rejected(array $options, string $message): void
    {
        $this->assertSame(1, Artisan::call('workdays:import-iran-calendar', $this->arguments(['--dry-run' => true, ...$options])));
        $this->assertStringContainsString($message, Artisan::output());
    }

    public static function invalidInputs(): array
    {
        return [[['year' => 'not-a-year'], 'Invalid Iran official calendar year'], [['year' => '1405.0'], 'Invalid Iran official calendar year'],
            [['year' => "1405\n"], 'Invalid Iran official calendar year'], [['--profile' => ' '], 'must be a non-empty string']];
    }

    public function test_database_dry_run_reads_one_revision_but_writes_nothing(): void
    {
        $repository = $this->initializeDatabase();
        $before = $repository->current();
        $queries = $this->recordQueries();
        $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', $this->arguments(['--dry-run' => true])));
        $output = Artisan::output();
        $this->assertStringContainsString('Revision '.$before->revisionId.', profile [iran]: 1 inserts, 0 updates, 0 skips, 0 conflicts', $output);
        $this->assertStringContainsString('No rows were written', $output);
        $this->assertSame([], array_values(array_filter($queries->queries, static fn (string $sql): bool => preg_match('/\A\s*(insert|update|delete|replace)\b/i', $sql) === 1)));
        $this->assertSame($before->revisionId, $repository->current()->revisionId);
        $this->assertSame($before->definitions, $repository->current()->definitions);
    }

    public function test_database_import_publishes_then_identical_import_is_no_op_and_preserves_other_data(): void
    {
        $repository = $this->initializeDatabase();
        $before = $repository->current();
        $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', $this->arguments(['--expected-revision' => (string) $before->revisionId])));
        $current = $repository->current();
        $this->assertGreaterThan($before->revisionId, $current->revisionId);
        $this->assertSame('عید سعید فطر و آغاز نوروز', $current->definitions['profiles']['iran']['custom_holidays']['2026-03-21']);
        $this->assertSame($before->definitions['profiles']['other'], $current->definitions['profiles']['other']);
        $this->assertSame($before->definitions['profiles']['iran']['extra_working_days'], $current->definitions['profiles']['iran']['extra_working_days']);
        $this->assertTrue(Workday::profile('iran')->isBusinessDay('2026-03-21'));
        $this->assertSame('Laravel Workdays Test Fixture', $repository->revision($current->revisionId)->metadata['import']['source']['name']);
        $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', $this->arguments(['--expected-revision' => (string) $current->revisionId])));
        $output = Artisan::output();
        $this->assertStringContainsString('0 inserts, 0 updates, 1 skips, 0 conflicts', $output);
        $this->assertStringContainsString('unchanged (no-op)', $output);
        $this->assertSame($current->revisionId, $repository->current()->revisionId);
    }

    public function test_conflicts_abort_whole_import_force_overwrites_and_stale_force_still_fails(): void
    {
        $repository = $this->initializeDatabase();
        $current = $repository->current();
        $definitions = $current->definitions;
        $definitions['profiles']['iran']['custom_holidays']['2026-03-21'] = 'Existing different title';
        $repository->publish($definitions, $current->revisionId);
        $current = $repository->current();
        $args = $this->arguments(['--expected-revision' => (string) $current->revisionId]);
        $this->assertSame(1, Artisan::call('workdays:import-iran-calendar', $args));
        $this->assertStringContainsString('0 inserts, 0 updates, 0 skips, 1 conflicts', Artisan::output());
        $this->assertSame($current->revisionId, $repository->current()->revisionId);
        $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', [...$args, '--force' => true]));
        $this->assertStringContainsString('0 inserts, 1 updates, 0 skips, 0 conflicts', Artisan::output());
        $after = $repository->current();
        $this->assertSame(1, Artisan::call('workdays:import-iran-calendar', [...$args, '--force' => true]));
        $this->assertStringContainsString('revision conflict', Artisan::output());
        $this->assertSame($after->revisionId, $repository->current()->revisionId);
    }

    public function test_inherited_exact_and_recurring_holidays_skip_without_child_ownership(): void
    {
        foreach (['custom_holidays', 'holidays'] as $section) {
            $repository = $this->initializeDatabase($section);
            $before = $repository->current();
            $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', $this->arguments(['--expected-revision' => (string) $before->revisionId])));
            $this->assertStringContainsString('0 inserts, 0 updates, 1 skips, 0 conflicts', Artisan::output());
            $this->assertSame($before->revisionId, $repository->current()->revisionId);
            $this->assertArrayNotHasKey('custom_holidays', $repository->current()->definitions['profiles']['iran']);
        }
    }

    public function test_inherited_conflict_force_adds_child_override_without_changing_parent(): void
    {
        $repository = $this->initializeDatabase('custom_holidays');
        $current = $repository->current();
        $definitions = $current->definitions;
        $definitions['profiles']['parent']['custom_holidays']['2026-03-21'] = 'Parent ownership';
        $repository->publish($definitions, $current->revisionId);
        $before = $repository->current();
        $args = $this->arguments(['--expected-revision' => (string) $before->revisionId]);
        $this->assertSame(1, Artisan::call('workdays:import-iran-calendar', $args));
        $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', [...$args, '--force' => true]));
        $this->assertSame($before->definitions['profiles']['parent'], $repository->current()->definitions['profiles']['parent']);
        $this->assertSame('عید سعید فطر و آغاز نوروز', $repository->current()->definitions['profiles']['iran']['custom_holidays']['2026-03-21']);
    }

    public function test_database_write_requires_token_and_existing_profile(): void
    {
        $repository = $this->initializeDatabase();
        $this->assertSame(1, Artisan::call('workdays:import-iran-calendar', $this->arguments()));
        $this->assertStringContainsString('positive --expected-revision', Artisan::output());
        $this->assertSame(1, Artisan::call('workdays:import-iran-calendar', $this->arguments(['--profile' => 'missing', '--expected-revision' => (string) $repository->current()->revisionId])));
        $this->assertStringContainsString('target profile [missing] is not configured', Artisan::output());
    }

    public function test_invalid_correspondence_and_late_transaction_failure_preserve_active_release(): void
    {
        $repository = $this->initializeDatabase();
        $before = $repository->current();
        $args = $this->arguments(['--expected-revision' => (string) $before->revisionId]);
        $path = $args['--calendar-path'].DIRECTORY_SEPARATOR.'1405.php';
        $dataset = require $path;
        $dataset['holidays'][0]['gregorian_date'] = '2026-03-22';
        File::put($path, '<?php return '.var_export($dataset, true).';');
        $this->assertSame(1, Artisan::call('workdays:import-iran-calendar', $args));
        $this->assertStringContainsString('date correspondence', Artisan::output());
        $this->assertSame($before->revisionId, $repository->current()->revisionId);
        $this->app['db']->connection()->statement("CREATE TRIGGER fail_iran_publication BEFORE INSERT ON workday_definition_profiles BEGIN SELECT RAISE(ABORT, 'injected write failure'); END");
        $this->assertSame(1, Artisan::call('workdays:import-iran-calendar', $this->arguments(['--expected-revision' => (string) $before->revisionId])));
        $this->assertStringContainsString('Database publication failed', Artisan::output());
        $this->assertSame($before->revisionId, $repository->current()->revisionId);
        $this->assertSame($before->definitions, $repository->current()->definitions);
        $this->assertSame(1, $this->app['db']->table('workday_definition_revisions')->count());
    }

    public function test_help_exposes_force_and_revision_precondition(): void
    {
        $definition = Artisan::all()['workdays:import-iran-calendar']->getDefinition();
        $this->assertTrue($definition->hasOption('force'));
        $this->assertTrue($definition->hasOption('expected-revision'));
        $this->assertStringContainsString('never bypasses revision checks', $definition->getOption('force')->getDescription());
    }

    public function test_database_dry_run_checks_the_same_provenance_budget_as_publication(): void
    {
        $repository = $this->initializeDatabase();
        $before = $repository->current();
        $args = $this->arguments(['--expected-revision' => (string) $before->revisionId]);
        $path = $args['--calendar-path'].DIRECTORY_SEPARATOR.'1405.php';
        $dataset = require $path;
        $dataset['source']['additional_notes'] = str_repeat('x', 20000);
        File::put($path, '<?php return '.var_export($dataset, true).';');
        foreach ([true, false] as $dryRun) {
            $this->assertSame(1, Artisan::call('workdays:import-iran-calendar', [...$args, '--dry-run' => $dryRun]));
            $this->assertStringContainsString('Publication metadata exceeds 16 KiB', Artisan::output());
            $this->assertSame($before->revisionId, $repository->current()->revisionId);
            $this->assertSame($before->definitions, $repository->current()->definitions);
        }
        config()->set('workdays.driver', 'config');
        $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', [...$args, '--dry-run' => true]));
        $this->assertStringContainsString('Dataset-only validation', Artisan::output());
    }

    #[DataProvider('explicitClearImportModes')]
    public function test_explicit_clear_rejects_unintended_parent_holidays_without_any_writes(bool $dryRun, bool $force, ?string $parentTitle): void
    {
        $repository = $this->initializeInheritanceDatabase(['2026-11-02' => $parentTitle]);
        $before = $this->databaseState($repository);
        $this->assertSame([], $this->effectiveCustomHolidays($before['definitions']));
        $this->assertTrue($before['november_business_day']);
        $queries = $this->recordQueries();

        $exitCode = Artisan::call('workdays:import-iran-calendar', $this->arguments([
            '--dry-run' => $dryRun,
            '--force' => $force,
            '--expected-revision' => (string) $before['revision_id'],
        ]));
        $output = Artisan::output();
        $after = $this->databaseState($repository);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('unintended effective custom holidays for profile [iran]', $output);
        $this->assertStringContainsString('2026-11-02', $output);
        $this->assertStringContainsString('inheritance policy', $output);
        $this->assertStringNotContainsString('Dry-run completed', $output);
        $this->assertSame($before, $after);
        $this->assertSame([], $this->writeQueries($queries));
    }

    public static function explicitClearImportModes(): array
    {
        $cases = [];
        foreach ([true, false] as $dryRun) {
            foreach ([false, true] as $force) {
                foreach (['Parent warehouse closure', null] as $parentTitle) {
                    $name = ($dryRun ? 'dry-run' : 'write').($force ? ' forced' : ' unforced').($parentTitle === null ? ' null exact value' : ' titled exact value');
                    $cases[$name] = [$dryRun, $force, $parentTitle];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('importModes')]
    public function test_skipped_recurring_holiday_does_not_authorize_restoring_excluded_exact_value(bool $dryRun, bool $force): void
    {
        $repository = $this->initializeInheritanceDatabase(
            ['2026-03-21' => 'Excluded parent exact title'],
            annualJalali: ['01-01' => 'عید سعید فطر و آغاز نوروز'],
        );
        $before = $this->databaseState($repository);
        $this->assertSame([], $this->effectiveCustomHolidays($before['definitions']));
        $this->assertFalse($before['march_business_day']);
        $arguments = $this->twoDateArguments([
            '--dry-run' => $dryRun,
            '--force' => $force,
            '--expected-revision' => (string) $before['revision_id'],
        ]);
        $queries = $this->recordQueries();

        $exitCode = Artisan::call('workdays:import-iran-calendar', $arguments);
        $output = Artisan::output();
        $after = $this->databaseState($repository);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('unintended effective custom holidays for profile [iran]', $output);
        $this->assertStringContainsString('2026-03-21', $output);
        $this->assertStringContainsString('inheritance policy', $output);
        $this->assertSame($before, $after);
        $this->assertSame([], $this->writeQueries($queries));
    }

    public static function importModes(): array
    {
        return [
            'dry-run unforced' => [true, false],
            'dry-run forced' => [true, true],
            'write unforced' => [false, false],
            'write forced' => [false, true],
        ];
    }

    #[DataProvider('representableInheritanceImports')]
    public function test_representable_child_import_preserves_exact_effective_map(string $scenario, bool $dryRun, bool $force): void
    {
        $title = 'عید سعید فطر و آغاز نوروز';
        $parentHolidays = match ($scenario) {
            'inherits' => ['2026-11-02' => 'Parent warehouse closure'],
            'empty parent' => [],
            'restores intended writes' => ['2026-03-22' => 'Excluded second parent title', '2026-03-21' => 'Excluded first parent title'],
        };
        $repository = $this->initializeInheritanceDatabase($parentHolidays, explicitClear: $scenario !== 'inherits');
        $before = $this->databaseState($repository);
        $expected = $this->effectiveCustomHolidays($before['definitions']);
        $expected['2026-03-21'] = $title;
        $options = ['--dry-run' => $dryRun, '--force' => $force, '--expected-revision' => (string) $before['revision_id']];
        $arguments = $scenario === 'restores intended writes' ? $this->twoDateArguments($options) : $this->arguments($options);
        if ($scenario === 'restores intended writes') {
            $expected['2026-03-22'] = 'Second dataset holiday';
        }
        $queries = $this->recordQueries();

        $exitCode = Artisan::call('workdays:import-iran-calendar', $arguments);
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $after = $this->databaseState($repository);
        $this->assertStringContainsString(($scenario === 'restores intended writes' ? '2' : '1').' inserts, 0 updates, 0 skips, 0 conflicts', $output);
        if ($dryRun) {
            $this->assertStringContainsString('Dry-run completed', $output);
            $this->assertSame($before, $after);
            $this->assertSame([], $this->writeQueries($queries));

            return;
        }
        $this->assertGreaterThan($before['revision_id'], $after['revision_id']);
        $this->assertSame($before['revision_rows'] + 1, $after['revision_rows']);
        $this->assertSame($before['profile_rows'] + count($before['definitions']['profiles']), $after['profile_rows']);
        $this->assertSame($after['revision_id'], (int) $after['active_pointer']);
        $expectedDefinitions = $before['definitions'];
        $expectedDefinitions['profiles']['iran']['custom_holidays'] = ['2026-03-21' => $title];
        if ($scenario === 'restores intended writes') {
            $expectedDefinitions['profiles']['iran']['custom_holidays']['2026-03-22'] = 'Second dataset holiday';
        }
        $this->assertSame($expectedDefinitions, $after['definitions']);
        $effective = $this->effectiveCustomHolidays($after['definitions']);
        ksort($expected);
        ksort($effective);
        $this->assertSame($expected, $effective);
        $this->assertFalse($after['march_business_day']);
        $this->assertSame($before['november_business_day'], $after['november_business_day']);
    }

    public static function representableInheritanceImports(): array
    {
        $cases = [];
        foreach (['inherits', 'empty parent', 'restores intended writes'] as $scenario) {
            foreach (self::importModes() as $name => [$dryRun, $force]) {
                $cases[$scenario.' '.$name] = [$scenario, $dryRun, $force];
            }
        }

        return $cases;
    }

    private function initializeInheritanceDatabase(array $parentHolidays, bool $explicitClear = true, array $annualJalali = []): DatabaseDefinitionRepository
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
        $child = ['extends' => 'base'];
        if ($explicitClear) {
            $child['custom_holidays'] = [];
        }
        $repository = app(DatabaseDefinitionRepository::class);
        $repository->publish([
            'default_profile' => 'iran',
            'include_start_date' => false,
            'hijri' => ['method' => 'mathematical', 'adjustment' => 0],
            'profile_routing' => null,
            'profiles' => [
                'base' => [
                    'weekends' => [],
                    'holidays' => ['gregorian' => [], 'jalali' => $annualJalali, 'hijri' => []],
                    'custom_holidays' => $parentHolidays,
                    'extra_working_days' => [],
                ],
                'iran' => $child,
            ],
        ], expectedRevisionId: null);
        config()->set('workdays.driver', 'database');

        return $repository;
    }

    private function databaseState(DatabaseDefinitionRepository $repository): array
    {
        $current = $repository->current();

        return [
            'revision_id' => $current->revisionId,
            'definitions' => $current->definitions,
            'active_pointer' => $this->app['db']->table('workday_definition_state')->where('id', 1)->value('active_revision_id'),
            'revision_rows' => $this->app['db']->table('workday_definition_revisions')->count(),
            'profile_rows' => $this->app['db']->table('workday_definition_profiles')->count(),
            'march_business_day' => Workday::profile('iran')->isBusinessDay('2026-03-21'),
            'november_business_day' => Workday::profile('iran')->isBusinessDay('2026-11-02'),
        ];
    }

    private function effectiveCustomHolidays(array $definitions): array
    {
        return (new ProfileConfigResolver(static fn (string $name): array => $definitions['profiles'][$name]))->resolve('iran')['custom_holidays'];
    }

    private function twoDateArguments(array $options): array
    {
        $arguments = $this->arguments($options);
        $path = $arguments['--calendar-path'].DIRECTORY_SEPARATOR.'1405.php';
        $dataset = require $path;
        $dataset['holidays'][] = [
            ...$dataset['holidays'][0],
            'date' => '1405-01-02',
            'gregorian_date' => '2026-03-22',
            'title' => 'Second dataset holiday',
        ];
        File::put($path, '<?php return '.var_export($dataset, true).';');

        return $arguments;
    }

    private function writeQueries(object $queries): array
    {
        return array_values(array_filter($queries->queries, static fn (string $sql): bool => preg_match('/\A\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $sql) === 1));
    }

    private function initializeDatabase(?string $inherited = null): DatabaseDefinitionRepository
    {
        if (! $this->app['db']->connection()->getSchemaBuilder()->hasTable('workday_definition_state')) {
            $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
        }
        $profile = ['weekends' => ['Friday'], 'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []], 'custom_holidays' => [], 'extra_working_days' => ['2026-03-21' => 'Explicit reopening']];
        $profiles = ['iran' => $profile, 'other' => $profile];
        if ($inherited !== null) {
            $profiles['parent'] = $profile;
            $profiles['iran'] = ['extends' => 'parent'];
            if ($inherited === 'custom_holidays') {
                $profiles['parent']['custom_holidays']['2026-03-21'] = 'عید سعید فطر و آغاز نوروز';
            } else {
                $profiles['parent']['holidays']['jalali']['01-01'] = 'عید سعید فطر و آغاز نوروز';
            }
        }
        $repository = app(DatabaseDefinitionRepository::class);
        $repository->publish(['default_profile' => 'iran', 'include_start_date' => false, 'hijri' => ['method' => 'umm_al_qura', 'adjustment' => 0], 'profile_routing' => null, 'profiles' => $profiles], $repository->current()?->revisionId);
        config()->set('workdays.driver', 'database');

        return $repository;
    }

    private function arguments(array $options = []): array
    {
        return ['year' => 1405, '--calendar-path' => $this->createTemporaryIranOfficialCalendarFixture(), ...$options];
    }

    private function makeDatabaseUnusable(): void
    {
        config()->set('database.default', 'unusable');
        config()->set('database.connections.unusable', ['driver' => 'workdays_database_must_not_be_used']);
    }

    private function recordQueries(): object
    {
        $record = new class
        {
            public array $queries = [];
        };
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use ($record): void {
            $record->queries[] = $query->sql;
        });

        return $record;
    }
}
