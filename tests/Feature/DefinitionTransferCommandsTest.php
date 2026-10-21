<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Configuration\DefinitionDocument;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class DefinitionTransferCommandsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'workdays-definition-transfer-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_export_requires_explicit_source_and_protects_existing_files(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'definitions.json';
        File::put($path, 'application-owned');
        $this->assertSame(1, Artisan::call('workdays:definitions:export', ['path' => $path]));
        $this->assertStringContainsString('explicit --source', Artisan::output());
        $this->assertSame(1, Artisan::call('workdays:definitions:export', ['path' => $path, '--source' => 'config']));
        $this->assertSame('application-owned', File::get($path));
        $this->assertSame(0, Artisan::call('workdays:definitions:export', ['path' => $path, '--source' => 'config', '--force' => true]));
        $this->assertSame(config('workdays.definitions'), DefinitionDocument::fromJson(File::get($path))->definitions);
        $this->assertArrayNotHasKey('database', json_decode(File::get($path), true));
        $this->assertArrayNotHasKey('driver', json_decode(File::get($path), true));
    }

    public function test_config_export_does_not_connect_to_database_or_accept_a_revision(): void
    {
        config()->set('database.default', 'unusable');
        config()->set('database.connections.unusable', ['driver' => 'must_not_connect']);
        $queries = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $path = $this->directory.DIRECTORY_SEPARATOR.'config.json';
        $this->assertSame(1, Artisan::call('workdays:definitions:export', ['path' => $path, '--source' => 'config', '--revision' => '1']));
        $this->assertSame(0, Artisan::call('workdays:definitions:export', ['path' => $path, '--source' => 'config']));
        $this->assertSame([], $queries);
    }

    public function test_explicit_config_to_database_transfer_and_historical_database_export_preserve_raw_values(): void
    {
        $repository = $this->repository();
        $definitions = $this->rawDefinitions();
        config()->set('workdays.definitions', $definitions);
        $path = $this->directory.DIRECTORY_SEPARATOR.'raw.json';
        $this->assertSame(0, Artisan::call('workdays:definitions:export', ['path' => $path, '--source' => 'config']));
        $exported = File::get($path);
        $this->assertSame(['format_version', 'definitions'], array_keys(json_decode($exported, true)));
        $before = $this->tableCounts();
        $this->assertSame(0, Artisan::call('workdays:definitions:import', ['path' => $path, '--initialize' => true, '--dry-run' => true]));
        $this->assertSame($before, $this->tableCounts());
        $this->assertNull($repository->current());
        $this->assertSame(0, Artisan::call('workdays:definitions:import', ['path' => $path, '--initialize' => true]));
        $first = $repository->current();
        $this->assertSame($definitions, $first->definitions);
        $this->assertSame('config', config('workdays.driver'));
        $this->assertSame(1, Artisan::call('workdays:definitions:import', ['path' => $path, '--initialize' => true]));
        $this->assertStringContainsString('conflict', Artisan::output());
        $this->assertSame(0, Artisan::call('workdays:definitions:import', ['path' => $path, '--expected-revision' => (string) $first->revisionId]));
        $this->assertStringContainsString('unchanged (no-op)', Artisan::output());
        $this->assertSame($first->revisionId, $repository->current()->revisionId);
        $edited = $definitions;
        $edited['profiles']['child']['custom_holidays'] = ['2026-11-03' => 'New holiday'];
        $repository->publish($edited, $first->revisionId);
        $historical = $this->directory.DIRECTORY_SEPARATOR.'history.json';
        $this->assertSame(0, Artisan::call('workdays:definitions:export', ['path' => $historical, '--source' => 'database', '--revision' => (string) $first->revisionId]));
        $this->assertSame($exported, File::get($historical));
        $currentPath = $this->directory.DIRECTORY_SEPARATOR.'database.json';
        $this->assertSame(0, Artisan::call('workdays:definitions:export', ['path' => $currentPath, '--source' => 'database']));
        $this->assertSame($edited, DefinitionDocument::fromJson(File::get($currentPath))->definitions);
    }

    public function test_stale_import_and_dry_run_fail_without_rebasing_or_writes(): void
    {
        $repository = $this->repository();
        $definitions = $this->rawDefinitions();
        $first = $repository->publish($definitions, null);
        $path = $this->directory.DIRECTORY_SEPARATOR.'definitions.json';
        File::put($path, $repository->validate($definitions)->toJson());
        $definitions['profiles']['child']['custom_holidays'] = ['2026-11-04' => 'Editor two'];
        $second = $repository->publish($definitions, $first->revisionId);
        foreach ([false, true] as $dryRun) {
            $before = $this->tableCounts();
            $this->assertSame(1, Artisan::call('workdays:definitions:import', ['path' => $path, '--expected-revision' => (string) $first->revisionId, '--dry-run' => $dryRun]));
            $this->assertStringContainsString('conflict', Artisan::output());
            $this->assertSame($before, $this->tableCounts());
            $this->assertSame($second->revisionId, $repository->current()->revisionId);
        }
    }

    public function test_transfer_tools_use_selected_connection_with_conflicting_default_release(): void
    {
        $repository = $this->repository();
        $defaultDefinitions = $this->rawDefinitions();
        $defaultDefinitions['profiles']['child']['custom_holidays'] = ['2026-11-02' => 'Default connection only'];
        $repository->publish($defaultDefinitions, null);
        config()->set('database.connections.workdays_selected', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('workdays.database.connection', 'workdays_selected');
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_20_000001_create_workday_definitions_tables.php';
        $migration->up();
        $definitions = $this->rawDefinitions();
        $path = $this->directory.DIRECTORY_SEPARATOR.'selected.json';
        File::put($path, $repository->validate($definitions)->toJson());
        $this->assertSame(0, Artisan::call('workdays:definitions:import', ['path' => $path, '--initialize' => true]));
        $this->assertSame($definitions, $repository->current()->definitions);
        $this->assertSame(1, $this->app['db']->connection('testing')->table('workday_definition_revisions')->count());
        $this->assertSame(1, $this->app['db']->connection('workdays_selected')->table('workday_definition_revisions')->count());
        $export = $this->directory.DIRECTORY_SEPARATOR.'selected-export.json';
        $this->assertSame(0, Artisan::call('workdays:definitions:export', ['path' => $export, '--source' => 'database']));
        $this->assertSame($definitions, DefinitionDocument::fromJson(File::get($export))->definitions);
        config()->set('workdays.database.connection', null);
        $this->assertSame($defaultDefinitions, $repository->current()->definitions);
    }

    #[DataProvider('invalidPreconditions')]
    public function test_import_precondition_must_be_explicit_and_unambiguous(array $options): void
    {
        $this->assertSame(1, Artisan::call('workdays:definitions:import', ['path' => 'missing.json', ...$options]));
        $this->assertStringNotContainsString('Cannot read definition JSON', Artisan::output());
    }

    public static function invalidPreconditions(): array
    {
        return [[[]], [['--dry-run' => true]], [['--initialize' => true, '--expected-revision' => '1']], [['--expected-revision' => '0']], [['--expected-revision' => '-1']], [['--expected-revision' => '1.0']], [['--expected-revision' => '999999999999999999999999']]];
    }

    #[DataProvider('unsafeFiles')]
    public function test_import_rejects_non_json_unknown_versions_and_oversized_input(string $contents): void
    {
        $repository = $this->repository();
        $path = $this->directory.DIRECTORY_SEPARATOR.'invalid.json';
        File::put($path, $contents);
        $this->assertSame(1, Artisan::call('workdays:definitions:import', ['path' => $path, '--initialize' => true]));
        $this->assertNull($repository->current());
        $this->assertSame(0, $this->app['db']->table('workday_definition_revisions')->count());
    }

    public static function unsafeFiles(): array
    {
        return [['<?php throw new RuntimeException("must never execute");'], ['O:8:"stdClass":0:{}'], ['{"format_version":2,"definitions":{}}'], ['{"format_version":1,"definitions":'], [str_repeat(' ', 4194305)]];
    }

    private function repository(): DatabaseDefinitionRepository
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');

        return app(DatabaseDefinitionRepository::class);
    }

    private function tableCounts(): array
    {
        return array_map(fn (string $table): int => $this->app['db']->table($table)->count(), ['workday_definition_state', 'workday_definition_revisions', 'workday_definition_profiles']);
    }

    private function rawDefinitions(): array
    {
        return ['default_profile' => 'child', 'include_start_date' => false, 'hijri' => ['method' => 'umm_al_qura', 'adjustment' => 0], 'profile_routing' => null,
            'profiles' => ['parent' => ['weekends' => ['Saturday', 'Sunday'], 'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []], 'custom_holidays' => [], 'extra_working_days' => [],
                'working_hours' => ['Monday' => [['09:00', '17:00']]], 'allow_overnight_working_hours' => false, 'cutoff' => ['timezone' => 'UTC', 'time' => '15:00', 'weekdays' => ['Tuesday' => null]]],
                'child' => ['extends' => 'parent', 'special_working_hours' => [], 'working_hours' => ['Wednesday' => []]]]];
    }
}
