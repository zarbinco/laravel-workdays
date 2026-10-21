<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Calendar\CalendarFingerprint;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class UnifiedSnapshotSlaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        config()->set('app.timezone', 'Pacific/Honolulu');
        config()->set('workdays.max_scan_days', 7);
    }

    #[DataProvider('sameScheduleCases')]
    public function test_core_forward_no_pause_sla_snapshot_and_bulk_share_exact_intervals(string $source, array $flags, string $timezone, array $hours, string $received, string $expected, array $windows): void
    {
        $this->definitions($source, array_replace($this->profile($hours), $flags));
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $instant = CarbonImmutable::parse($received)->setTimezone($timezone);
        $plan = $this->plan($timezone);
        $options = ['profile' => 'warehouse', 'timezone' => $timezone, 'target_minutes' => 1];
        $minutes = $timezone === 'UTC' ? 1 : 60;
        $plan['stages'][0]['minutes'] = $options['target_minutes'] = $minutes;

        $core = Workday::profile('warehouse')->addBusinessMinutes($instant, $minutes);
        $this->assertSame($expected, $this->format($core));
        $this->assertSame($timezone, $core->getTimezone()->getName());
        $forward = Workday::planDelivery($instant, $plan);
        $sla = Workday::evaluateSla($instant, $instant, $options);
        $snapshot = Workday::captureDeliveryPromise($instant, $plan, ['horizon_days' => 2]);
        $this->assertSame($expected, $this->format($forward->estimatedCompletionAt));
        $this->assertSame($expected, $this->format($sla->dueAt));
        $this->assertSame($forward->toArray(), $snapshot->originalResult->toArray());
        $this->assertSame(2, $snapshot->runtimeMetadata['calculation_schema_version']);
        $this->assertSame(1, $snapshot->schemaVersion);
        $this->assertSame(1, $snapshot->calendarEvidence['schema_version']);
        $day = array_values(array_filter($snapshot->calendarEvidence['profiles']['warehouse']['days'], static fn (array $row): bool => $row['date'] === $instant->toDateString()))[0];
        $this->assertSame($windows, array_map(static fn (array $window): array => ['start' => $window['start'], 'end' => $window['end']], $day['effective_working_windows']));
        $payload = json_decode(json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($payload, DeliveryPromiseSnapshot::fromArray($payload)->toArray());
        $comparison = Workday::compareDeliveryPromise($snapshot);
        $this->assertFalse($comparison->estimateChanged);
        $this->assertFalse($comparison->versionChanged);
        $this->assertFalse($comparison->runtimeChanged);

        $operations = [
            ['id' => 'core', 'operation' => 'add_business_minutes', 'profile' => 'warehouse', 'datetime' => $instant, 'minutes' => $minutes],
            ['id' => 'forward', 'operation' => 'plan_delivery', 'received_at' => $instant, 'plan' => $plan],
            ['id' => 'sla', 'operation' => 'evaluate_sla', 'started_at' => $instant, 'evaluated_at' => $instant, 'options' => $options],
        ];
        foreach ([[], ['prefetch' => ['from' => $instant->toDateString(), 'through' => $instant->addDays(2)->toDateString()]]] as $bulkOptions) {
            $bulk = Workday::bulkCalculate($operations, $bulkOptions);
            $this->assertSame(3, $bulk->succeeded);
            $this->assertSame(0, $bulk->failed);
            $this->assertSame(get_debug_type($core), get_debug_type($bulk->items[0]->result));
            $this->assertSame($core->format('Y-m-d\TH:i:s.uP'), $bulk->items[0]->result->format('Y-m-d\TH:i:s.uP'));
            $this->assertSame($forward->toArray(), $bulk->items[1]->result->toArray());
            $this->assertSame($sla->toArray(), $bulk->items[2]->result->toArray());
        }
        if ($source === 'config') {
            $this->assertSame([], $connection->getQueryLog());
        }
    }

    public static function sameScheduleCases(): array
    {
        $newYork = ['Sunday' => [['00:00', '04:00']], 'Monday' => [['09:00', '17:00']]];
        $cases = [
            'split fractions' => ['UTC', ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]], '2026-11-02T09:59:30.123456Z', '2026-11-02T11:00:30.123456+00:00', [['start' => '2026-11-02T09:00:00.000000+00:00', 'end' => '2026-11-02T10:00:00.000000+00:00'], ['start' => '2026-11-02T11:00:00.000000+00:00', 'end' => '2026-11-02T12:00:00.000000+00:00']]],
            'spring daytime' => ['America/New_York', $newYork, '2026-03-08T03:30:00-04:00', '2026-03-09T13:30:00.000000+00:00', [['start' => '2026-03-08T05:00:00.000000+00:00', 'end' => '2026-03-08T08:00:00.000000+00:00']]],
            'fall daytime' => ['America/New_York', $newYork, '2026-11-01T02:30:00-05:00', '2026-11-01T08:30:00.000000+00:00', [['start' => '2026-11-01T04:00:00.000000+00:00', 'end' => '2026-11-01T09:00:00.000000+00:00']]],
            'skipped midnight daytime' => ['America/Santiago', ['Sunday' => [['09:00', '17:00']]], '2026-09-06T09:00:00-03:00', '2026-09-06T13:00:00.000000+00:00', [['start' => '2026-09-06T12:00:00.000000+00:00', 'end' => '2026-09-06T20:00:00.000000+00:00']]],
        ];
        $result = [];
        foreach (['config', 'database'] as $source) {
            foreach (['plain' => [], 'false' => ['allow_overnight_working_hours' => false], 'true' => ['allow_overnight_working_hours' => true], 'unused overlays' => ['special_working_hours' => [], 'seasonal_working_hours' => []]] as $variant => $flags) {
                foreach ($cases as $case => $arguments) {
                    $result[$source.' '.$variant.' '.$case] = [$source, $flags, ...$arguments];
                }
            }
        }

        return $result;
    }

    #[DataProvider('daytimeCivilDateCases')]
    public function test_daytime_snapshot_round_trip_and_immediate_comparison_do_not_require_the_next_midnight(string $source, string $timezone, string $received, string $expected): void
    {
        $this->definitions($source, $this->profile(array_fill_keys(range(1, 7), [['09:00', '17:00']])));
        $plan = $this->plan($timezone);
        $plan['stages'][0] = ['id' => 'preparation', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 60];
        $snapshot = Workday::captureDeliveryPromise($received, $plan, ['horizon_days' => 1]);

        $this->assertSame($expected, $this->format($snapshot->originalResult->estimatedCompletionAt));
        $this->assertSame(2, $snapshot->runtimeMetadata['calculation_schema_version']);
        $this->assertSame(1, $snapshot->horizonDays);
        $this->assertSame(1, $snapshot->calendarEvidence['horizon_days']);
        $this->assertArrayNotHasKey('allow_overnight_working_hours', $snapshot->calendarEvidence['profiles']['warehouse']['config']);
        $this->assertArrayNotHasKey('special_working_hours', $snapshot->calendarEvidence['profiles']['warehouse']['config']);
        if ($timezone === 'Pacific/Apia') {
            $skipped = $this->evidenceDay($snapshot->toArray(), '2011-12-30');
            $this->assertFalse($skipped['exists']);
            $this->assertFalse($skipped['is_business_day']);
            $this->assertSame([], $skipped['effective_working_windows']);
            $this->assertSame([], $skipped['holiday_reasons']);
        }
        $payload = json_decode(json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = DeliveryPromiseSnapshot::fromArray($payload);

        $this->assertSame($payload, $restored->toArray());
        $this->assertSame($snapshot->originalResult->toArray(), $restored->originalResult->toArray());
        foreach ([$snapshot, $restored, $payload] as $input) {
            $comparison = Workday::compareDeliveryPromise($input);
            $this->assertSame('resolved', $comparison->status);
            $this->assertSame('unchanged', $comparison->direction);
            $this->assertSame(0, $comparison->deltaSeconds);
            $this->assertFalse($comparison->estimateChanged);
            $this->assertFalse($comparison->versionChanged);
            $this->assertFalse($comparison->runtimeChanged);
            $this->assertSame([], $comparison->changedCalendarDays);
            $this->assertNull($comparison->error);
            $this->assertSame($expected, $this->format($comparison->currentEstimatedCompletionAt));
        }
        $this->assertSame($payload, $snapshot->toArray());
        $this->assertSame($payload, $restored->toArray());
    }

    public static function daytimeCivilDateCases(): iterable
    {
        $cases = [
            'Apia before skipped date' => ['Pacific/Apia', '2011-12-29T09:00:00-10:00', '2011-12-29T20:00:00.000000+00:00'],
            'Apia after skipped date' => ['Pacific/Apia', '2011-12-31T09:00:00+14:00', '2011-12-30T20:00:00.000000+00:00'],
            'Havana before repeated midnight' => ['America/Havana', '2026-10-31T09:00:00-04:00', '2026-10-31T14:00:00.000000+00:00'],
            'Havana after repeated midnight' => ['America/Havana', '2026-11-01T09:00:00-05:00', '2026-11-01T15:00:00.000000+00:00'],
            'UTC control' => ['UTC', '2026-11-02T09:00:00Z', '2026-11-02T10:00:00.000000+00:00'],
        ];
        foreach (['config', 'database'] as $source) {
            foreach ($cases as $case => $arguments) {
                yield $source.' '.$case => [$source, ...$arguments];
            }
        }
    }

    #[DataProvider('daytimeCivilDateCases')]
    public function test_daytime_snapshot_restoration_remains_independent_of_unavailable_live_sources(string $source, string $timezone, string $received, string $expected): void
    {
        $this->definitions($source, $this->profile(array_fill_keys(range(1, 7), [['09:00', '17:00']])));
        $plan = $this->plan($timezone);
        $plan['stages'][0]['minutes'] = 60;
        $snapshot = Workday::captureDeliveryPromise($received, $plan, ['horizon_days' => 1]);
        $payload = $snapshot->toArray();
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $definitions = config('workdays.definitions');
        $databaseConnection = config('workdays.database.connection');
        config()->set('workdays.definitions', null);
        config()->set('workdays.database.connection', 'unavailable-snapshot-test-connection');

        try {
            $restored = DeliveryPromiseSnapshot::fromArray($payload);

            $this->assertSame($payload, $restored->toArray());
            $this->assertSame($expected, $this->format($restored->originalResult->estimatedCompletionAt));
            $this->assertSame($snapshot->runtimeMetadata, $restored->runtimeMetadata);
            $this->assertSame([], $connection->getQueryLog());
            $this->expectException(DefinitionSourceException::class);
            Workday::compareDeliveryPromise($restored);
        } finally {
            config()->set('workdays.definitions', $definitions);
            config()->set('workdays.database.connection', $databaseConnection);
        }
    }

    #[DataProvider('daytimeCivilDateCases')]
    public function test_rehashed_daytime_evidence_cannot_extend_one_microsecond_into_another_civil_date(string $source, string $timezone, string $received, string $expected): void
    {
        $this->definitions($source, $this->profile(array_fill_keys(range(1, 7), [['09:00', '17:00']])));
        $payload = Workday::captureDeliveryPromise($received, $this->plan($timezone), ['horizon_days' => 1])->toArray();
        $local = CarbonImmutable::parse($received)->setTimezone($timezone);
        $date = $local->toDateString();
        $nextDateStart = $local->addDay()->startOfDay();
        foreach ($payload['calendar_evidence']['profiles']['warehouse']['days'] as &$row) {
            if ($row['date'] === $date) {
                $row['effective_working_windows'][0]['end'] = $this->format($nextDateStart->addMicrosecond());
            }
        }
        unset($row);
        $payload['calendar_version'] = CalendarFingerprint::hash($payload['calendar_evidence']);
        $payload = $this->rehash($payload);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds its effective profile-date interval');

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    #[DataProvider('daytimeCivilDateCases')]
    public function test_rehashed_daytime_result_cannot_move_by_one_microsecond(string $source, string $timezone, string $received, string $expected): void
    {
        $this->definitions($source, $this->profile(array_fill_keys(range(1, 7), [['09:00', '17:00']])));
        $plan = $this->plan($timezone);
        $plan['stages'][0]['minutes'] = 60;
        $payload = Workday::captureDeliveryPromise($received, $plan, ['horizon_days' => 1])->toArray();
        $payload['original_result']['estimated_completion_at'] = $payload['original_result']['stages'][0]['completed_at'] = $this->format(CarbonImmutable::parse($expected)->addMicrosecond());
        $payload = $this->rehash($payload);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inconsistent with its captured UTC working intervals');

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    #[DataProvider('sources')]
    public function test_rehashed_evidence_cannot_attribute_a_real_window_to_a_wholly_skipped_date(string $source): void
    {
        $this->definitions($source, $this->profile(array_fill_keys(range(1, 7), [['09:00', '17:00']])));
        $payload = Workday::captureDeliveryPromise('2011-12-31T09:00:00+14:00', $this->plan('Pacific/Apia'), ['horizon_days' => 1])->toArray();
        $skipped = $this->evidenceDay($payload, '2011-12-30');
        $this->assertFalse($skipped['exists']);
        foreach ($payload['calendar_evidence']['profiles']['warehouse']['days'] as &$row) {
            if ($row['date'] === '2011-12-30') {
                $row['exists'] = $row['is_business_day'] = true;
                $row['is_holiday'] = false;
                $row['effective_working_windows'] = [['start' => '2011-12-30T19:00:00.000000+00:00', 'end' => '2011-12-30T20:00:00.000000+00:00']];
            }
        }
        unset($row);
        $payload['calendar_version'] = CalendarFingerprint::hash($payload['calendar_evidence']);
        $payload = $this->rehash($payload);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('skipped local date cannot contain a working window');

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    #[DataProvider('configuredBoundaryCases')]
    public function test_snapshot_capture_still_rejects_genuinely_invalid_or_ambiguous_configured_boundaries(string $source, string $timezone, string $received, array $window, bool $allowOvernight, string $boundary, string $failure): void
    {
        $profile = $this->profile(array_fill_keys(range(1, 7), [$window]));
        if ($allowOvernight) {
            $profile['allow_overnight_working_hours'] = true;
        }
        $this->definitions($source, $profile);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('boundary ['.$boundary.']');
        $this->expectExceptionMessage($failure);

        Workday::captureDeliveryPromise($received, $this->plan($timezone), ['horizon_days' => 1]);
    }

    public static function configuredBoundaryCases(): iterable
    {
        $cases = [
            'configured nonexistent 24' => ['Pacific/Apia', '2011-12-29T09:00:00-10:00', ['09:00', '24:00'], true, '24:00', 'does not exist'],
            'configured ambiguous 24' => ['America/Havana', '2026-10-31T09:00:00-04:00', ['09:00', '24:00'], true, '24:00', 'is ambiguous'],
            'configured ambiguous midnight' => ['America/Havana', '2026-11-01T09:00:00-05:00', ['00:00', '17:00'], false, '00:00', 'is ambiguous'],
            'configured nonexistent opening' => ['America/New_York', '2026-03-08T00:00:00-05:00', ['02:30', '17:00'], false, '02:30', 'does not exist'],
            'configured ambiguous closing' => ['America/New_York', '2026-11-01T00:00:00-04:00', ['00:00', '01:30'], false, '01:30', 'is ambiguous'],
        ];
        foreach (['config', 'database'] as $source) {
            foreach ($cases as $case => $arguments) {
                yield $source.' '.$case => [$source, ...$arguments];
            }
        }
    }

    #[DataProvider('sources')]
    public function test_snapshot_round_trip_retains_a_valid_representable_exclusive_24_schedule_end(string $source): void
    {
        $profile = $this->profile(array_fill_keys(range(1, 7), [['09:00', '24:00']]));
        $profile['allow_overnight_working_hours'] = true;
        $this->definitions($source, $profile);
        $plan = $this->plan('UTC');
        $plan['stages'][0]['minutes'] = 60;
        $snapshot = Workday::captureDeliveryPromise('2026-11-02T23:00:00Z', $plan, ['horizon_days' => 1]);
        $payload = json_decode(json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = DeliveryPromiseSnapshot::fromArray($payload);

        $this->assertSame('2026-11-03T00:00:00.000000+00:00', $this->format($restored->originalResult->estimatedCompletionAt));
        $this->assertSame('2026-11-03T00:00:00.000000+00:00', $this->evidenceDay($payload, '2026-11-02')['effective_working_windows'][0]['end']);
        $this->assertSame($payload, $restored->toArray());
        $comparison = Workday::compareDeliveryPromise($payload);
        $this->assertSame('resolved', $comparison->status);
        $this->assertFalse($comparison->estimateChanged);
        $this->assertSame(0, $comparison->deltaSeconds);
    }

    #[DataProvider('splitCivilDateZones')]
    public function test_rehashed_snapshot_evidence_still_rejects_backwards_split_civil_date_histories(string $source, string $timezone): void
    {
        $this->definitions($source, $this->profile(array_fill_keys(range(1, 7), [['09:00', '17:00']])));
        $payload = Workday::captureDeliveryPromise('1988-10-30T09:00:00Z', $this->plan('UTC'), ['horizon_days' => 1])->toArray();
        $payload['plan']['timezone'] = $payload['plan']['profile_timezones']['warehouse'] = $timezone;
        $payload['original_result']['timezone'] = $timezone;
        $payload['calendar_evidence']['plan_timezone'] = $payload['calendar_evidence']['profiles']['warehouse']['timezone'] = $timezone;
        $this->assertTrue($this->evidenceDay($payload, '1988-10-29')['exists']);
        $payload['calendar_version'] = CalendarFingerprint::hash($payload['calendar_evidence']);
        $payload = $this->rehash($payload);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('moves backward across a civil-date label');

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public static function splitCivilDateZones(): iterable
    {
        foreach (['config', 'database'] as $source) {
            foreach (['America/St_Johns', 'America/Goose_Bay'] as $timezone) {
                yield $source.' '.$timezone => [$source, $timezone];
            }
        }
    }

    public function test_new_capture_identifies_current_semantics_without_changing_outer_schemas(): void
    {
        $this->definitions('config', $this->profile(['Monday' => [['09:00', '17:00']]]));
        $snapshot = Workday::captureDeliveryPromise('2026-11-02T10:00:00Z', $this->plan('UTC'), ['horizon_days' => 1]);
        $this->assertSame(2, $snapshot->runtimeMetadata['calculation_schema_version']);
        $this->assertSame(1, $snapshot->schemaVersion);
        $this->assertSame(1, $snapshot->calendarEvidence['schema_version']);
    }

    public function test_genuine_pre_refactor_semantics_one_snapshot_is_rejected_despite_valid_hashes(): void
    {
        $fixture = dirname(__DIR__).'/Fixtures/refactor02-semantics-1-snapshot.json';
        $this->assertSame('5103d41752d6d25809dfb42873f81ee5bde1b55c36dc02df4193a6458b04b48c', hash_file('sha256', $fixture));
        $payload = json_decode(file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $payload['runtime_metadata']['calculation_schema_version']);
        $this->assertSame('2026-11-02T11:01:00.000000+00:00', $payload['original_result']['estimated_completion_at']);
        $this->assertSame(CalendarFingerprint::hash($payload['calendar_evidence']), $payload['calendar_version']);
        $unsigned = $payload;
        unset($unsigned['integrity_hash']);
        $this->assertSame(CalendarFingerprint::integrity($unsigned), $payload['integrity_hash']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported old calculation semantics');

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    #[DataProvider('unsupportedSemantics')]
    public function test_rehashed_unsupported_or_coerced_calculation_semantics_are_rejected(mixed $version): void
    {
        $this->definitions('config', $this->profile(['Monday' => [['09:00', '17:00']]]));
        $payload = Workday::captureDeliveryPromise('2026-11-02T10:00:00Z', $this->plan('UTC'), ['horizon_days' => 1])->toArray();
        $payload['runtime_metadata']['calculation_schema_version'] = $version;
        unset($payload['integrity_hash']);
        $payload['integrity_hash'] = CalendarFingerprint::integrity($payload);
        $this->expectException(InvalidArgumentException::class);

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public static function unsupportedSemantics(): array
    {
        return [[1], ['2'], [2.0], [null], [3], [true]];
    }

    #[DataProvider('phpDriftTamperingCases')]
    public function test_rehashed_php_version_drift_cannot_disable_current_semantics_consistency_checks(string $source, string $tampering): void
    {
        $this->definitions($source, $this->profile(['Monday' => [['09:00', '17:00']]]));
        $payload = Workday::captureDeliveryPromise('2026-11-02T10:00:00Z', $this->plan('UTC'), ['horizon_days' => 1])->toArray();
        $payload['runtime_metadata']['php_version'] = '8.2.0';
        if ($tampering === 'result') {
            $payload['original_result']['estimated_completion_at'] = $payload['original_result']['stages'][0]['completed_at'] = '2026-11-02T10:02:00.000000+00:00';
        } else {
            foreach ($payload['calendar_evidence']['profiles']['warehouse']['days'] as &$day) {
                if ($day['date'] === '2026-11-02') {
                    $day['effective_working_windows'][0]['end'] = '2026-11-02T18:00:00.000000+00:00';
                }
            }
            unset($day);
        }
        $payload['calendar_version'] = CalendarFingerprint::hash($payload['calendar_evidence']);
        unset($payload['integrity_hash']);
        $payload['integrity_hash'] = CalendarFingerprint::integrity($payload);
        $this->expectException(InvalidArgumentException::class);

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public static function phpDriftTamperingCases(): array
    {
        return [['config', 'result'], ['config', 'evidence'], ['database', 'result'], ['database', 'evidence']];
    }

    #[DataProvider('portableRuntimeVersions')]
    public function test_runtime_drift_cannot_hide_one_microsecond_work_result_corruption(string $source, string $field): void
    {
        $this->definitions($source, $this->profile(['Monday' => [['09:00', '17:00']]]));
        $payload = Workday::captureDeliveryPromise('2026-11-02T10:00:00Z', $this->plan('UTC'), ['horizon_days' => 1])->toArray();
        $payload['runtime_metadata'][$field] = 'historical-runtime';
        $payload['original_result']['estimated_completion_at'] = $payload['original_result']['stages'][0]['completed_at'] = '2026-11-02T10:01:00.000001+00:00';
        $payload = $this->rehash($payload);
        $this->expectException(InvalidArgumentException::class);

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    #[DataProvider('portableRuntimeVersions')]
    public function test_valid_current_semantics_work_snapshot_remains_portable_across_runtime_metadata_drift(string $source, string $field): void
    {
        $this->definitions($source, $this->profile(['Monday' => [['09:00', '17:00']]]));
        $payload = Workday::captureDeliveryPromise('2026-11-02T10:00:00Z', $this->plan('UTC'), ['horizon_days' => 1])->toArray();
        $payload['runtime_metadata'][$field] = 'historical-runtime';
        $payload = $this->rehash($payload);

        $this->assertSame($payload, DeliveryPromiseSnapshot::fromArray($payload)->toArray());
    }

    public static function portableRuntimeVersions(): iterable
    {
        foreach (['config', 'database'] as $source) {
            foreach (['carbon_version', 'verta_version', 'islamic_calendar_version', 'timezone_database_version'] as $field) {
                yield $source.' '.$field => [$source, $field];
            }
        }
    }

    #[DataProvider('portableStageTypes')]
    public function test_historical_tzdb_handoff_and_day_transit_keep_strict_portable_result_checks(string $source, string $type): void
    {
        $profile = $this->profile(['Monday' => [['09:00', '17:00']], 'Tuesday' => [['09:00', '17:00']]]);
        $this->definitions($source, $profile, ['carrier' => $profile]);
        $plan = $this->plan('UTC');
        $plan['profile_timezones']['carrier'] = 'UTC';
        $plan['stages'] = [$type === 'handoff'
            ? ['id' => 'handoff', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']]
            : ['id' => 'transit', 'type' => 'business_days', 'profile' => 'warehouse', 'days' => 1]];
        $payload = Workday::captureDeliveryPromise('2026-11-02T10:00:00Z', $plan, ['horizon_days' => 2])->toArray();
        $payload['runtime_metadata']['timezone_database_version'] = 'historical-tzdb';
        $payload = $this->rehash($payload);
        $this->assertSame($payload, DeliveryPromiseSnapshot::fromArray($payload)->toArray());
        $completion = $type === 'handoff' ? '2026-11-02T10:00:00.000001+00:00' : '2026-11-03T10:00:00.000001+00:00';
        $payload['original_result']['estimated_completion_at'] = $payload['original_result']['stages'][0]['completed_at'] = $completion;
        if ($type === 'handoff') {
            $payload['original_result']['stages'][0]['started_at'] = $completion;
        }
        $payload = $this->rehash($payload);
        $this->expectException(InvalidArgumentException::class);

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public static function portableStageTypes(): array
    {
        return [['config', 'handoff'], ['database', 'handoff'], ['config', 'business_days'], ['database', 'business_days']];
    }

    #[DataProvider('sources')]
    public function test_sla_pause_subtraction_retains_subminute_precision_across_the_break(string $source): void
    {
        $this->definitions($source, $this->profile(['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]]));
        $result = Workday::evaluateSla('2026-11-02T09:59:30.123456Z', '2026-11-02T11:00:30.123456Z', [
            'profile' => 'warehouse', 'timezone' => 'UTC', 'target_minutes' => 1,
            'pauses' => [['started_at' => '2026-11-02T09:59:45.123456Z', 'ended_at' => '2026-11-02T11:00:15.123456Z']],
        ]);
        $this->assertSame('2026-11-02T11:01:00.123456+00:00', $this->format($result->dueAt));
        $this->assertSame(0, $result->consumedWorkingMinutes);
        $this->assertSame(0, $result->pausedWorkingMinutes);
        $this->assertSame(1, $result->remainingWorkingMinutes);
        $this->assertFalse($result->breached);
    }

    public static function sources(): array
    {
        return [['config'], ['database']];
    }

    private function definitions(string $source, array $profile, array $additionalProfiles = []): void
    {
        $definitions = ['default_profile' => 'warehouse', 'include_start_date' => false, 'hijri' => ['method' => 'mathematical', 'adjustment' => 0], 'profile_routing' => null, 'profiles' => ['warehouse' => $profile] + $additionalProfiles];
        config()->set('workdays.definitions', $definitions);
        config()->set('workdays.driver', $source);
        if ($source === 'database') {
            $this->app->make(DatabaseDefinitionRepository::class)->publish($definitions, expectedRevisionId: null);
            config()->set('workdays.definitions.profiles', ['ignored' => ['extends' => 'missing']]);
        }
    }

    private function profile(array $hours): array
    {
        return ['weekends' => [], 'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []], 'custom_holidays' => [], 'extra_working_days' => [], 'working_hours' => $hours];
    }

    private function plan(string $timezone): array
    {
        return ['timezone' => $timezone, 'profile_timezones' => ['warehouse' => $timezone], 'stages' => [['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 1]]];
    }

    private function format(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d\TH:i:s.uP');
    }

    private function evidenceDay(array $payload, string $date): array
    {
        $rows = array_column($payload['calendar_evidence']['profiles']['warehouse']['days'], null, 'date');
        $this->assertArrayHasKey($date, $rows);

        return $rows[$date];
    }

    private function rehash(array $payload): array
    {
        unset($payload['integrity_hash']);
        $payload['integrity_hash'] = CalendarFingerprint::integrity($payload);

        return $payload;
    }
}
