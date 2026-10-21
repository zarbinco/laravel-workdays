<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionConflictException;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

/** Explicit opt-in only. Requires an empty, separately provisioned workdays_test_* database. */
final class MySqlDefinitionSourceTest extends TestCase
{
    private bool $ownsSchema = false;

    private array $connectionConfiguration = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('WORKDAYS_RUN_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Set WORKDAYS_RUN_MYSQL_TESTS=1 with an empty dedicated workdays_test_* MySQL database.');
        }
        $database = getenv('WORKDAYS_MYSQL_TEST_DATABASE');
        $user = getenv('WORKDAYS_MYSQL_TEST_USER');
        if (! is_string($database) || preg_match('/\Aworkdays_test_[A-Za-z0-9_]+\z/', $database) !== 1 || ! is_string($user) || $user === '') {
            $this->fail('Explicit WORKDAYS_MYSQL_TEST_DATABASE (workdays_test_* only) and WORKDAYS_MYSQL_TEST_USER are required.');
        }
        $this->connectionConfiguration = [
            'driver' => 'mysql', 'host' => getenv('WORKDAYS_MYSQL_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('WORKDAYS_MYSQL_TEST_PORT') ?: '3306', 'database' => $database,
            'username' => $user, 'password' => getenv('WORKDAYS_MYSQL_TEST_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ];
        config()->set('database.connections.workdays_mysql_test', $this->connectionConfiguration);
        config()->set('workdays.database.connection', 'workdays_mysql_test');
        $connection = $this->connection();
        $this->assertSame($database, $connection->selectOne('select database() as name')->name);
        $this->assertSame([], $connection->getSchemaBuilder()->getTableListing(), 'The dedicated MySQL test database must be completely empty; no existing tables are modified.');
        $this->ownsSchema = true;
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_20_000001_create_workday_definitions_tables.php';
        $migration->up();
        config()->set('workdays.driver', 'database');
        $metadata = $connection->selectOne('select version() as server_version, @@collation_connection as connection_collation');
        if (is_string($path = getenv('WORKDAYS_MYSQL_TEST_REPORT')) && $path !== '') {
            file_put_contents($path, json_encode(['server_version' => $metadata->server_version, 'connection_collation' => $metadata->connection_collation, 'database' => $database, 'php' => PHP_VERSION], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->ownsSchema) {
                config()->set('workdays.database.connection', 'workdays_mysql_test');
                $actual = $this->connection()->selectOne('select database() as name')->name;
                if ($actual !== $this->connectionConfiguration['database'] || ! str_starts_with($actual, 'workdays_test_')) {
                    throw new \RuntimeException('Refusing test cleanup outside the explicitly verified disposable database.');
                }
                $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_20_000001_create_workday_definitions_tables.php';
                $migration->down();
                $this->assertSame([], $this->connection()->getSchemaBuilder()->getTableListing());
                $this->app['db']->purge('workdays_mysql_test');
            }
        } finally {
            config()->set('workdays.database.connection', null);
            parent::tearDown();
        }
    }

    public function test_exact_case_accent_and_numeric_profile_identity_survives_actual_insensitive_mysql_collation(): void
    {
        $definitions = $this->definitions();
        $definitions['profiles'] = [
            'warehouse' => ['weekends' => []], 'Warehouse' => ['weekends' => ['Monday']],
            'café' => ['weekends' => ['Monday']], 'cafe' => ['weekends' => []],
            '0' => ['weekends' => []], '01' => ['weekends' => ['Monday']], '1' => ['weekends' => []],
        ];
        $published = $this->repository()->publish($definitions, null);
        $collation = $this->connection()->select('show full columns from workday_definition_profiles');
        $nameColumn = array_values(array_filter($collation, static fn (object $row): bool => $row->Field === 'profile_name'))[0];
        $this->assertStringEndsWith('_ci', $nameColumn->Collation);
        foreach (['warehouse' => true, 'Warehouse' => false, 'café' => false, 'cafe' => true, '0' => true, '01' => false, '1' => true] as $name => $expected) {
            $this->assertSame($expected, Workday::profile((string) $name)->isBusinessDay('2026-11-02'));
        }
        $this->assertSame($definitions, $this->repository()->current()->definitions);
        $noOp = $this->repository()->publish($definitions, $published->revisionId);
        $this->assertFalse($noOp->changed);
        $next = $definitions;
        $next['profiles']['warehouse']['custom_holidays'] = ['2026-11-02' => 'Changed'];
        $this->repository()->publish($next, $published->revisionId);
        $this->expectException(DefinitionConflictException::class);
        $this->repository()->publish($definitions, $published->revisionId);
    }

    public function test_configured_connection_and_old_lazy_view_ignore_conflicting_default_database_and_later_head(): void
    {
        $definitions = $this->definitions();
        $first = $this->repository()->publish($definitions, null);
        $view = $this->repository()->openView();
        // The application's unrelated default is an isolated SQLite test database.
        $defaultSchema = $this->app['db']->connection('testing')->getSchemaBuilder();
        $defaultSchema->create('workday_definition_state', static function ($table): void {
            $table->integer('id');
            $table->integer('active_revision_id');
        });
        $this->app['db']->connection('testing')->table('workday_definition_state')->insert(['id' => 1, 'active_revision_id' => 999]);
        $next = $definitions;
        $next['profiles']['parent']['custom_holidays'] = ['2026-11-02' => 'New MySQL holiday'];
        $second = $this->repository()->publish($next, $first->revisionId);
        $this->assertSame($first->revisionId, $view->revisionId);
        $this->assertSame([], $view->profile('parent')['custom_holidays']);
        $this->assertSame('parent', $view->profile('warehouse')['extends']);
        $this->assertSame($second->revisionId, $this->repository()->openView()->revisionId);
        $this->assertFalse(Workday::isBusinessDay('2026-11-02'));
        $this->assertSame(999, $this->app['db']->connection('testing')->table('workday_definition_state')->value('active_revision_id'));
    }

    #[DataProvider('publicationFailurePoints')]
    public function test_actual_mysql_publication_failure_rolls_back_candidate_rows_and_pointer(string $point): void
    {
        $definitions = $this->definitions();
        $first = $this->repository()->publish($definitions, null);
        $next = $definitions;
        $next['profiles']['parent']['custom_holidays'] = ['2026-11-02' => 'Never published'];
        $armed = true;
        $connection = $this->connection();
        $connection->listen(static function (QueryExecuted $query) use ($point, &$armed): void {
            if ($armed && str_starts_with($query->sql, $point)) {
                $armed = false;
                throw new \RuntimeException('Injected MySQL publication failure.');
            }
        });
        try {
            $this->repository()->publish($next, $first->revisionId);
            $this->fail('The injected MySQL failure must escape publication.');
        } catch (DefinitionSourceException $error) {
            $this->assertSame('Injected MySQL publication failure.', $error->getPrevious()->getMessage());
            $this->assertFalse($armed);
            $this->assertSame($first->revisionId, $this->repository()->current()->revisionId);
            $this->assertSame($definitions, $this->repository()->current()->definitions);
            $this->assertSame(1, $connection->table('workday_definition_revisions')->count());
            $this->assertSame(2, $connection->table('workday_definition_profiles')->count());
            $this->assertSame(0, $connection->transactionLevel());
        }
    }

    public static function publicationFailurePoints(): array
    {
        return ['profile insert' => ['insert into `workday_definition_profiles`'], 'pointer switch' => ['update `workday_definition_state`']];
    }

    public function test_unified_fractional_work_and_dst_results_match_config_using_actual_mysql_definitions(): void
    {
        $definitions = $this->definitions();
        $definitions['profiles'] = [
            'warehouse' => ['weekends' => [], 'holidays' => [], 'custom_holidays' => [], 'extra_working_days' => [],
                'working_hours' => ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]]],
            'dst' => ['weekends' => [], 'holidays' => [], 'custom_holidays' => [], 'extra_working_days' => [],
                'working_hours' => ['Sunday' => [['00:00', '04:00']], 'Monday' => [['09:00', '17:00']]]],
        ];
        $this->repository()->publish($definitions, null);
        config()->set('workdays.definitions', $definitions);
        $outputs = [];
        foreach (['config', 'database'] as $source) {
            config()->set('workdays.driver', $source);
            $connection = $this->connection();
            $connection->enableQueryLog();
            $connection->flushQueryLog();
            $calculator = Workday::profile('warehouse');
            $start = '2026-11-02T09:59:30.123456Z';
            $added = $calculator->addBusinessMinutes($start, 1);
            $this->assertSame('2026-11-02T11:00:30.123456+00:00', $added->format('Y-m-d\TH:i:s.uP'));
            $this->assertSame(1, $calculator->diffBusinessMinutes($start, $added));
            $this->assertSame('09:59:59.999999', $calculator->previousBusinessTime('2026-11-02T10:00:00Z')->format('H:i:s.u'));
            $plan = ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC'],
                'stages' => [['id' => 'work', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 1]]];
            $forward = Workday::planDelivery($start, $plan);
            $sla = Workday::evaluateSla($start, '2026-11-02T11:01:00Z', ['profile' => 'warehouse', 'timezone' => 'UTC', 'target_minutes' => 1]);
            $snapshot = Workday::captureDeliveryPromise($start, $plan, ['horizon_days' => 2]);
            $this->assertTrue($added->equalTo($forward->estimatedCompletionAt));
            $this->assertTrue($added->equalTo($sla->dueAt));
            $this->assertSame(2, $snapshot->runtimeMetadata['calculation_schema_version']);
            $this->assertSame($forward->toArray(), $snapshot->originalResult->toArray());
            $bulk = Workday::bulkCalculate([
                ['id' => 'core', 'operation' => 'add_business_minutes', 'profile' => 'warehouse', 'datetime' => $start, 'minutes' => 1],
                ['id' => 'forward', 'operation' => 'plan_delivery', 'received_at' => $start, 'plan' => $plan],
            ]);
            $this->assertTrue($added->equalTo($bulk->items[0]->result));
            $this->assertSame($forward->toArray(), $bulk->items[1]->result->toArray());
            $dst = Workday::profile('dst');
            $spring = new \DateTimeImmutable('2026-03-08T03:30:00-04:00');
            $spring = $spring->setTimezone(new \DateTimeZone('America/New_York'));
            $this->assertSame('2026-03-09T09:30:00.000000-04:00', $dst->addBusinessMinutes($spring, 60)->format('Y-m-d\TH:i:s.uP'));
            $this->assertSame(180, $dst->diffBusinessMinutes(new \DateTimeImmutable('2026-03-08 00:00', new \DateTimeZone('America/New_York')), new \DateTimeImmutable('2026-03-08T04:00:00-04:00')));
            $this->assertSame(300, $dst->diffBusinessMinutes(new \DateTimeImmutable('2026-11-01 00:00', new \DateTimeZone('America/New_York')), new \DateTimeImmutable('2026-11-01T04:00:00-05:00')));
            $outputs[$source] = [$added->format('Y-m-d\TH:i:s.uP'), $forward->toArray(), $sla->toArray(), $bulk->toArray()];
            if ($source === 'config') {
                $this->assertSame([], $connection->getQueryLog(), 'Config calculation must not query the real MySQL definition database.');
            }
            $connection->disableQueryLog();
        }
        $this->assertSame($outputs['config'], $outputs['database']);
    }

    #[DataProvider('raceKinds')]
    public function test_independent_mysql_processes_have_one_publication_winner_without_orphans(bool $initialized): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'workdays-mysql-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $definitions = $this->definitions();
        $expected = $initialized ? $this->repository()->publish($definitions, null)->revisionId : null;
        $processes = [];
        try {
            foreach (['a', 'b'] as $label) {
                $candidate = $definitions;
                $candidate['profiles']['parent']['custom_holidays'] = ['2026-11-02' => 'Writer '.$label];
                $input = ['isolated_workdays_test' => true, 'connection' => $this->connectionConfiguration, 'definitions' => $candidate, 'expected' => $expected,
                    'barrier_after_state_read' => false, 'ready' => $directory.DIRECTORY_SEPARATOR.'ready-'.$label,
                    'other_ready' => $directory.DIRECTORY_SEPARATOR.'ready-'.($label === 'a' ? 'b' : 'a')];
                $path = $directory.DIRECTORY_SEPARATOR.$label.'.json';
                file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
                $processes[$label] = new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/database-publication-worker.php', $path], timeout: 20);
            }
            foreach ($processes as $process) {
                $process->start();
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $outcomes = array_column($results, 'outcome');
            sort($outcomes);
            $this->assertSame(['conflict', 'published'], $outcomes, json_encode($results));
            $count = $initialized ? 2 : 1;
            $this->assertSame($count, $this->connection()->table('workday_definition_revisions')->count());
            $this->assertSame($count * 2, $this->connection()->table('workday_definition_profiles')->count());
            $current = $this->repository()->current();
            $this->assertContains($current->definitions['profiles']['parent']['custom_holidays']['2026-11-02'], ['Writer a', 'Writer b']);
            $this->assertSame($current->revisionId, (int) $this->connection()->table('workday_definition_state')->value('active_revision_id'));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (['a.json', 'b.json', 'ready-a', 'ready-b'] as $name) {
                $path = $directory.DIRECTORY_SEPARATOR.$name;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    public static function raceKinds(): array
    {
        return ['initialization' => [false], 'existing revision' => [true]];
    }

    private function repository(): DatabaseDefinitionRepository
    {
        return $this->app->make(DatabaseDefinitionRepository::class);
    }

    private function connection(): Connection
    {
        return $this->app['db']->connection('workdays_mysql_test');
    }

    private function definitions(): array
    {
        return ['default_profile' => 'warehouse', 'include_start_date' => false, 'hijri' => ['method' => 'mathematical', 'adjustment' => 0], 'profile_routing' => null,
            'profiles' => ['parent' => ['weekends' => [], 'custom_holidays' => []], 'warehouse' => ['extends' => 'parent']]];
    }
}
