<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Bulk\BulkCalculator;
use Zarbinco\LaravelWorkdays\Calendar\CalendarFingerprint;
use Zarbinco\LaravelWorkdays\Configuration\CalculationContextFactory;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Configuration\DefinitionDocument;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class DatabaseContextTest extends TestCase
{
    private DatabaseDefinitionRepository $repository;

    private array $definitions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        $this->repository = $this->app->make(DatabaseDefinitionRepository::class);
        $this->definitions = [
            'default_profile' => 'child', 'include_start_date' => false,
            'hijri' => ['method' => 'mathematical', 'adjustment' => 0],
            'profile_routing' => ['fallback_profile' => 'child', 'rules' => [['id' => 'vip', 'match' => ['kind' => 'vip'], 'profile' => 'child']]],
            'profiles' => [
                'parent' => [
                    'weekends' => ['Saturday', 'Sunday'],
                    'working_hours' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], [['09:00', '17:00']]),
                    'custom_holidays' => ['2026-11-05' => 'Unchanged holiday'],
                    'cutoff' => ['timezone' => 'UTC', 'time' => '16:00'],
                ],
                'child' => ['extends' => 'parent'], 'unrelated' => ['weekends' => []],
            ],
        ];
        $this->repository->publish($this->definitions, expectedRevisionId: null);
        config()->set('workdays.driver', 'database');
        config()->set('workdays.max_scan_days', 62);
    }

    protected function tearDown(): void
    {
        // Testbench rolls the disposable schema back after each test; restore that connection first.
        config()->set('workdays.database.connection', null);
        parent::tearDown();
    }

    public function test_pinned_view_loads_parent_and_child_for_first_time_after_publication_and_negative_lookups_are_cached(): void
    {
        $factory = $this->app->make(CalculationContextFactory::class);
        $old = $factory->open();
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $this->assertFalse($old->hasProfile('new_profile'));
        $this->assertFalse($old->hasProfile('new_profile'));
        $this->assertCount(1, $connection->getQueryLog());
        $connection->disableQueryLog();
        $current = $this->repository->current();
        $next = $current->definitions;
        $next['profiles']['parent']['working_hours']['Monday'] = [['09:00', '16:00']];
        $next['profiles']['new_profile'] = ['weekends' => []];
        $published = $this->repository->publish($next, $current->revisionId);

        $this->assertSame($current->revisionId, $old->definitions->revisionId);
        $this->assertSame('database', $old->definitions->source);
        $this->assertSame('2026-11-02 17:00:00.000000', $old->calculator('child')->addBusinessMinutes('2026-11-02T15:00:00Z', 120)->format('Y-m-d H:i:s.u'));
        $this->assertFalse($old->hasProfile('new_profile'));
        $fresh = $factory->open();
        $this->assertSame($published->revisionId, $fresh->definitions->revisionId);
        $this->assertTrue($fresh->hasProfile('new_profile'));
        $this->assertSame('2026-11-03 10:00:00.000000', $fresh->calculator('child')->addBusinessMinutes('2026-11-02T15:00:00Z', 120)->format('Y-m-d H:i:s.u'));
    }

    public function test_long_lived_manager_and_retained_calculator_follow_operation_lifetimes_and_source_switches(): void
    {
        $manager = $this->app->make(WorkdayManager::class);
        $old = $manager->profileFor(['kind' => 'vip']);
        $current = $this->repository->current();
        $next = $current->definitions;
        $next['profiles']['parent']['working_hours']['Monday'] = [['09:00', '16:00']];
        $this->repository->publish($next, $current->revisionId);
        $this->assertSame('2026-11-02 17:00', $old->addBusinessMinutes('2026-11-02T15:00:00Z', 120)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-03 10:00', $manager->addBusinessMinutes('2026-11-02T15:00:00Z', 120)->format('Y-m-d H:i'));
        config()->set('workdays.definitions', ['invalid' => new \stdClass]);
        $this->assertTrue($manager->isBusinessDay('2026-11-02'));
        config()->set('workdays.driver', 'config');
        config()->set('workdays.database.connection', new \stdClass);
        config()->set('workdays.definitions', $this->definitions);
        $this->assertSame('2026-11-02 17:00', $manager->addBusinessMinutes('2026-11-02T15:00:00Z', 120)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-02 17:00', $old->addBusinessMinutes('2026-11-02T15:00:00Z', 120)->format('Y-m-d H:i'));
    }

    public function test_batch_pins_driver_revision_and_lazily_loaded_profiles_during_publication(): void
    {
        $published = false;
        $batch = new BulkCalculator($this->app->make(CalculationContextFactory::class), function (string $kind) use (&$published): void {
            if ($kind !== 'calculator' || $published) {
                return;
            }
            $published = true;
            $current = $this->repository->current();
            $next = $current->definitions;
            $next['profiles']['parent']['custom_holidays']['2026-11-02'] = 'New closure';
            $next['profiles']['unrelated']['weekends'] = range(1, 7);
            $this->repository->publish($next, $current->revisionId);
            config()->set('workdays.driver', 'hybrid');
            config()->set('workdays.max_scan_days', 1);
        });
        $operations = [
            ['id' => 'first', 'operation' => 'is_business_day', 'profile' => 'child', 'date' => '2026-11-02'],
            ['id' => 'lazy', 'operation' => 'is_business_day', 'profile' => 'unrelated', 'date' => '2026-11-02'],
            ['id' => 'last', 'operation' => 'add_business_minutes', 'profile' => 'child', 'datetime' => '2026-11-02T15:00:00Z', 'minutes' => 120],
        ];
        $result = $batch->calculate($operations);
        $this->assertTrue($published);
        $this->assertSame(3, $result->succeeded);
        $this->assertTrue($result->items[0]->result);
        $this->assertTrue($result->items[1]->result);
        $this->assertSame('2026-11-02 17:00', $result->items[2]->result->format('Y-m-d H:i'));
        config()->set('workdays.driver', 'database');
        $next = Workday::bulkCalculate(array_slice($operations, 0, 2));
        $this->assertFalse($next->items[0]->result);
        $this->assertFalse($next->items[1]->result);
    }

    #[DataProvider('relevantChanges')]
    public function test_database_snapshot_comparison_reports_precise_flags_and_retains_original(string $change, string $completion, bool $configuration, bool $holidays, bool $databaseRules): void
    {
        $snapshot = Workday::captureDeliveryPromise('2026-11-02T15:00:00Z', $this->plan(), ['horizon_days' => 7]);
        $original = $snapshot->toArray();
        $current = $this->repository->current();
        $next = $current->definitions;
        match ($change) {
            'hours' => $next['profiles']['parent']['working_hours']['Monday'] = [['09:00', '16:00']],
            'holiday' => $next['profiles']['parent']['custom_holidays']['2026-11-02'] = 'New holiday',
            'cutoff' => $next['profiles']['parent']['cutoff']['time'] = '14:00',
            'unrelated' => $next['profiles']['unrelated']['weekends'] = range(1, 7),
        };
        $this->repository->publish($next, $current->revisionId);
        $comparison = Workday::compareDeliveryPromise($snapshot);
        $this->assertSame($completion, $comparison->currentEstimatedCompletionAt->format('Y-m-d H:i'));
        $this->assertSame($configuration, $comparison->configurationChanged);
        $this->assertSame($holidays, $comparison->holidayInputsChanged);
        $this->assertSame($databaseRules, $comparison->databaseRulesChanged);
        $this->assertSame($change !== 'unrelated', $comparison->versionChanged);
        $this->assertSame($change !== 'unrelated', $comparison->estimateChanged);
        $this->assertSame($original, $snapshot->toArray());
        $this->assertSame('2026-11-02 17:00', $snapshot->originalResult->estimatedCompletionAt->format('Y-m-d H:i'));
    }

    public static function relevantChanges(): array
    {
        return [
            ['hours', '2026-11-03 10:00', true, false, false],
            ['holiday', '2026-11-03 11:00', false, true, true],
            ['cutoff', '2026-11-03 11:00', true, false, false],
            ['unrelated', '2026-11-02 17:00', false, false, false],
        ];
    }

    public function test_database_snapshot_json_restores_offline_with_broken_live_config_and_rejects_tampering(): void
    {
        $payload = Workday::captureDeliveryPromise('2026-11-02T15:00:00Z', $this->plan(), ['horizon_days' => 7])->toArray();
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        config()->set('workdays.database.connection', 'offline');
        config()->set('database.connections.offline', ['driver' => 'unavailable']);
        config()->set('workdays.definitions', ['malformed' => new \stdClass]);
        config()->set('workdays.max_scan_days', null);
        $this->assertSame($payload, DeliveryPromiseSnapshot::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR))->toArray());
        $payload['plan']['stages'][0]['minutes']++;
        unset($payload['integrity_hash']);
        $payload['integrity_hash'] = CalendarFingerprint::integrity($payload);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('original result is inconsistent');
        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public function test_missing_database_profile_is_an_item_error_and_infrastructure_failures_escape_collect_and_comparison(): void
    {
        config()->set('workdays.definitions.profiles.file_only', ['weekends' => []]);
        $result = Workday::bulkCalculate([
            ['id' => 'unknown', 'operation' => 'is_business_day', 'profile' => 'file_only', 'date' => '2026-11-02'],
            ['id' => 'valid', 'operation' => 'is_business_day', 'profile' => 'child', 'date' => '2026-11-02'],
        ]);
        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->succeeded);
        $snapshot = Workday::captureDeliveryPromise('2026-11-02T15:00:00Z', $this->plan(), ['horizon_days' => 7]);
        config()->set('workdays.database.connection', 'offline');
        foreach (['collect', 'throw'] as $mode) {
            try {
                Workday::bulkCalculate([['id' => 'read', 'operation' => 'is_business_day', 'profile' => 'child', 'date' => '2026-11-02']], ['on_error' => $mode]);
                $this->fail('Source failures must escape '.$mode.' mode.');
            } catch (DefinitionSourceException $error) {
                $this->assertStringContainsString('Database', $error->getMessage());
            }
        }
        $this->expectException(DefinitionSourceException::class);
        Workday::compareDeliveryPromise($snapshot);
    }

    public function test_persisted_invalid_schedule_is_a_source_failure_even_if_its_row_checksum_is_recomputed(): void
    {
        $current = $this->repository->current();
        $profile = $current->definitions['profiles']['parent'];
        $profile['working_hours']['Monday'] = [['17:00', '09:00']];
        $this->app['db']->connection()->table('workday_definition_profiles')
            ->where('revision_id', $current->revisionId)->where('name_hash', hash('sha256', 'parent'))
            ->update(['definition_json' => DefinitionDocument::encode($profile), 'content_hash' => DefinitionDocument::hash($profile, 'profile')]);

        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('Persisted Database profile [child] is invalid');
        Workday::bulkCalculate([['id' => 'corrupt', 'operation' => 'is_business_day', 'profile' => 'child', 'date' => '2026-11-02']]);
    }

    public function test_database_bulk_200_operations_five_profiles_and_1000_item_batch_load_only_referenced_documents_once(): void
    {
        $current = $this->repository->current();
        $next = $current->definitions;
        foreach (range(0, 4) as $index) {
            $next['profiles']['profile_'.$index] = ['extends' => 'parent'];
        }
        foreach (range(0, 99) as $index) {
            $next['profiles']['unreferenced_'.$index] = ['weekends' => [], 'custom_holidays' => ['2026-11-02' => str_repeat('Unreferenced', 20)]];
        }
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $started = hrtime(true);
        $publication = $this->repository->publish($next, $current->revisionId);
        $publicationMs = (hrtime(true) - $started) / 1e6;
        $publicationQueries = $connection->getQueryLog();
        $connection->flushQueryLog();
        $operations = [];
        foreach (range(0, 199) as $index) {
            $date = CarbonImmutable::parse('2026-11-01', 'UTC')->addDays($index % 30)->toDateString();
            $profile = 'profile_'.($index % 5);
            $operations[] = match ($index % 4) {
                0 => ['id' => (string) $index, 'operation' => 'is_business_day', 'profile' => $profile, 'date' => $date],
                1 => ['id' => (string) $index, 'operation' => 'working_windows', 'profile' => $profile, 'date' => $date],
                2 => ['id' => (string) $index, 'operation' => 'add_business_days', 'profile' => $profile, 'date' => $date, 'days' => 2],
                3 => ['id' => (string) $index, 'operation' => 'add_business_minutes', 'profile' => $profile, 'datetime' => $date.'T15:00:12.123456Z', 'minutes' => 120],
            };
        }
        $manager = $this->app->make(WorkdayManager::class);
        $standalone = [];
        foreach ($operations as $item) {
            $calculator = $manager->profile($item['profile']);
            $value = match ($item['operation']) {
                'is_business_day' => $calculator->isBusinessDay($item['date']),
                'working_windows' => $calculator->workingWindowsFor($item['date']),
                'add_business_days' => $calculator->addBusinessDays($item['date'], $item['days']),
                'add_business_minutes' => $calculator->addBusinessMinutes($item['datetime'], $item['minutes']),
            };
            $standalone[] = $value instanceof CarbonImmutable ? $value->format('Y-m-d\TH:i:s.uP') : (is_array($value) ? array_map(static fn ($window): array => $window->toArray(), $value) : $value);
        }
        $standaloneQueries = count($connection->getQueryLog());
        $results = [];
        foreach ([[], ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30']], ['prefetch' => ['from' => '2030-01-01', 'through' => '2030-01-02']]] as $options) {
            $connection->flushQueryLog();
            $started = hrtime(true);
            $batch = $manager->bulkCalculate($operations, $options);
            $elapsed = (hrtime(true) - $started) / 1e6;
            $queries = $connection->getQueryLog();
            $this->assertSame(200, $batch->succeeded);
            $this->assertSame($standalone, array_column($batch->toArray()['items'], 'result'));
            $this->assertCount(7, $queries, 'One head plus five selected profiles and their shared parent.');
            $loads = array_values(array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'select * from "workday_definition_profiles"')));
            $this->assertCount(6, $loads);
            foreach ($loads as $query) {
                $this->assertContains($publication->revisionId, $query['bindings']);
                $this->assertStringContainsString('name_hash', $query['query']);
            }
            $results[] = ['queries' => count($queries), 'milliseconds' => $elapsed, 'result' => $batch->toArray()];
        }
        $this->assertSame($results[0]['result'], $results[1]['result']);
        $this->assertSame($results[0]['result'], $results[2]['result']);
        $thousand = [];
        foreach (range(0, 999) as $index) {
            $thousand[] = ['id' => (string) $index, 'operation' => 'is_business_day', 'profile' => 'profile_'.($index % 5), 'date' => CarbonImmutable::parse('2026-11-01', 'UTC')->addDays($index % 30)->toDateString()];
        }
        $connection->flushQueryLog();
        $memoryBefore = memory_get_usage(true);
        $started = hrtime(true);
        $large = $manager->bulkCalculate($thousand);
        $largeMs = (hrtime(true) - $started) / 1e6;
        $this->assertSame(1000, $large->succeeded);
        $this->assertCount(7, $connection->getQueryLog());
        $largeQueries = count($connection->getQueryLog());
        $growth = memory_get_usage(true) - $memoryBefore;
        $started = hrtime(true);
        $export = DefinitionDocument::fromArray($this->repository->current()->definitions)->toJson();
        $exportMs = (hrtime(true) - $started) / 1e6;
        $report = ['standalone_200_queries' => $standaloneQueries, 'bulk_200' => array_map(static fn (array $row): array => ['queries' => $row['queries'], 'milliseconds' => $row['milliseconds']], $results), 'batch_1000_queries' => $largeQueries, 'batch_1000_milliseconds' => $largeMs, 'batch_1000_allocated_growth_bytes' => $growth, 'process_peak_bytes' => memory_get_peak_usage(true), 'publication_profiles' => count($next['profiles']), 'publication_milliseconds' => $publicationMs, 'publication_sql_statements' => count($publicationQueries), 'publication_write_statements' => count(array_filter($publicationQueries, static fn (array $query): bool => preg_match('/\A(?:insert|update)\b/i', ltrim($query['query'])) === 1)), 'export_bytes' => strlen($export), 'export_milliseconds' => $exportMs, 'publication_profile_json_bytes' => (int) $connection->table('workday_definition_profiles')->where('revision_id', $publication->revisionId)->selectRaw('sum(length(definition_json)) as bytes')->value('bytes')];
        $connection->disableQueryLog();
        if (is_string($path = getenv('WORKDAYS_RESOURCE_REPORT')) && $path !== '') {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    private function plan(): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => ['child' => 'UTC'], 'cutoff_profile' => 'child', 'stages' => [['id' => 'support', 'type' => 'work', 'profile' => 'child', 'minutes' => 120]]];
    }
}
