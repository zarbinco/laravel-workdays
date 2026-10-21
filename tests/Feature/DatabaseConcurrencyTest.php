<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class DatabaseConcurrencyTest extends TestCase
{
    #[DataProvider('raceKinds')]
    public function test_two_processes_reading_the_same_file_revision_have_one_winner_and_no_orphans(bool $initialized): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'workdays-ref02-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $file = $directory.DIRECTORY_SEPARATOR.'definitions.sqlite';
        touch($file);
        $db = $this->app['db'];
        $connectionConfig = ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true];
        config()->set('database.connections.workdays_race', $connectionConfig);
        config()->set('workdays.database.connection', 'workdays_race');
        $processes = [];
        try {
            $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_20_000001_create_workday_definitions_tables.php';
            $migration->up();
            $connection = $db->connection('workdays_race');
            $connection->statement('PRAGMA journal_mode = WAL');
            $definitions = ['default_profile' => 'test', 'include_start_date' => false, 'hijri' => ['method' => 'mathematical', 'adjustment' => 0],
                'profile_routing' => null, 'profiles' => ['test' => ['weekends' => []]]];
            $repository = $this->app->make(DatabaseDefinitionRepository::class);
            $expected = $initialized ? $repository->publish($definitions, null)->revisionId : null;
            foreach (['a', 'b'] as $label) {
                $candidate = $definitions;
                $candidate['profiles']['test']['custom_holidays'] = ['2026-11-02' => 'Writer '.$label];
                $input = ['isolated_workdays_test' => true, 'connection' => $connectionConfig, 'definitions' => $candidate, 'expected' => $expected,
                    'barrier_after_state_read' => true, 'ready' => $directory.DIRECTORY_SEPARATOR.'ready-'.$label,
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
            $current = $repository->current();
            $this->assertContains($current->definitions['profiles']['test']['custom_holidays']['2026-11-02'], ['Writer a', 'Writer b']);
            $this->assertSame($initialized ? 2 : 1, $connection->table('workday_definition_revisions')->count());
            $this->assertSame($initialized ? 2 : 1, $connection->table('workday_definition_profiles')->count());
            $this->assertSame($current->revisionId, (int) $connection->table('workday_definition_state')->value('active_revision_id'));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            $db->purge('workdays_race');
            config()->set('workdays.database.connection', null);
            // Only exact files created inside this test's verified fresh directory are removed.
            foreach (['a.json', 'b.json', 'ready-a', 'ready-b', 'definitions.sqlite', 'definitions.sqlite-wal', 'definitions.sqlite-shm'] as $name) {
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
}
