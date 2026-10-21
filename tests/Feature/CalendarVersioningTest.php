<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use DateTime;
use DateTimeZone;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Calendar\CalendarFingerprint;
use Zarbinco\LaravelWorkdays\Configuration\CalculationContextFactory;
use Zarbinco\LaravelWorkdays\Configuration\ConfigDefinitionSource;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Configuration\DefinitionSource;
use Zarbinco\LaravelWorkdays\Configuration\WorkdayDefinitions;
use Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseComparison;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class CalendarVersioningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Pacific/Honolulu');
        config()->set('workdays.definitions.default_profile', 'warehouse');
        config()->set('workdays.driver', 'config');
        config()->set('workdays.max_scan_days', 31);
        config()->set('workdays.definitions.hijri', ['method' => 'mathematical', 'adjustment' => 0]);
        config()->set('workdays.definitions.profiles', ['warehouse' => $this->profile(), 'carrier' => $this->profile()]);
    }

    public function test_a_unchanged_calendar_keeps_version_original_result_and_estimate(): void
    {
        $before = config('workdays');
        $snapshot = $this->makeSnapshot();
        $original = $snapshot->toArray();
        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertInstanceOf(DeliveryPromiseSnapshot::class, $snapshot);
        $this->assertInstanceOf(DeliveryPlanResult::class, $snapshot->originalResult);
        $this->assertInstanceOf(DeliveryPromiseComparison::class, $comparison);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $snapshot->calendarVersion);
        $this->assertSame('2026-11-03 16:00:00', $snapshot->originalResult->estimatedCompletionAt->toDateTimeString());
        $this->assertSame('resolved', $comparison->status);
        $this->assertFalse($comparison->versionChanged);
        $this->assertFalse($comparison->estimateChanged);
        $this->assertFalse($comparison->runtimeChanged);
        $this->assertFalse($comparison->configurationChanged);
        $this->assertFalse($comparison->holidayInputsChanged);
        $this->assertFalse($comparison->databaseRulesChanged);
        $this->assertSame('unchanged', $comparison->direction);
        $this->assertSame(0, $comparison->deltaSeconds);
        $this->assertSame([], $comparison->changedCalendarDays);
        $this->assertSame($snapshot->calendarVersion, $comparison->originalCalendarVersion);
        $this->assertSame($snapshot->calendarVersion, $comparison->currentCalendarVersion);
        $this->assertSame($this->format($comparison->originalEstimatedCompletionAt), $this->format($comparison->currentEstimatedCompletionAt));
        $this->assertSame($original, $snapshot->toArray());
        $this->assertSame($before, config('workdays'));
    }

    public function test_b_new_carrier_holiday_moves_delivery_later_and_records_the_observed_day(): void
    {
        $snapshot = $this->makeSnapshot();
        $original = $snapshot->toArray();
        config()->set('workdays.definitions.profiles.carrier.custom_holidays', ['2026-11-03' => 'New carrier closure']);

        $comparison = Workday::compareDeliveryPromise($original);

        $this->assertTrue($comparison->versionChanged);
        $this->assertTrue($comparison->estimateChanged);
        $this->assertTrue($comparison->configurationChanged);
        $this->assertTrue($comparison->holidayInputsChanged);
        $this->assertFalse($comparison->databaseRulesChanged);
        $this->assertSame('later', $comparison->direction);
        $this->assertSame(86400, $comparison->deltaSeconds);
        $this->assertSame('2026-11-04 16:00:00', $comparison->currentEstimatedCompletionAt->toDateTimeString());
        $change = $this->changedDay($comparison, 'carrier', '2026-11-03');
        $this->assertContains('is_business_day', $change['changed_fields']);
        $this->assertSame($original, $snapshot->toArray());
    }

    public function test_c_removed_holiday_moves_delivery_earlier_with_negative_delta(): void
    {
        config()->set('workdays.definitions.profiles.carrier.custom_holidays', ['2026-11-03' => 'Original closure']);
        $snapshot = $this->makeSnapshot();
        config()->set('workdays.definitions.profiles.carrier.custom_holidays', []);

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertSame('2026-11-04 16:00:00', $comparison->originalEstimatedCompletionAt->toDateTimeString());
        $this->assertSame('2026-11-03 16:00:00', $comparison->currentEstimatedCompletionAt->toDateTimeString());
        $this->assertTrue($comparison->versionChanged);
        $this->assertTrue($comparison->estimateChanged);
        $this->assertSame('earlier', $comparison->direction);
        $this->assertSame(-86400, $comparison->deltaSeconds);
    }

    public function test_d_changed_hours_preserve_business_date_but_change_working_evidence_and_eta(): void
    {
        $snapshot = $this->makeSnapshot($this->workPlan(120), '2026-11-02T11:00:00Z');
        config()->set('workdays.definitions.profiles.warehouse.special_working_hours', ['2026-11-02' => [['09:00', '12:00']]]);

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertTrue(Workday::profile('warehouse')->isBusinessDay('2026-11-02'));
        $this->assertSame('2026-11-02 13:00:00', $comparison->originalEstimatedCompletionAt->toDateTimeString());
        $this->assertSame('2026-11-03 10:00:00', $comparison->currentEstimatedCompletionAt->toDateTimeString());
        $this->assertSame(75600, $comparison->deltaSeconds);
        $this->assertContains('effective_working_windows', $this->changedDay($comparison, 'warehouse', '2026-11-02')['changed_fields']);
        $this->assertTrue($this->evidenceDay($snapshot, 'warehouse', '2026-11-02')['is_business_day']);
    }

    public function test_e_relevant_later_date_change_can_change_version_without_moving_eta(): void
    {
        $snapshot = $this->makeSnapshot();
        config()->set('workdays.definitions.profiles.warehouse.special_working_hours', ['2026-11-06' => [['09:00', '12:00']]]);

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertTrue($comparison->versionChanged);
        $this->assertFalse($comparison->estimateChanged);
        $this->assertSame('unchanged', $comparison->direction);
        $this->assertSame(0, $comparison->deltaSeconds);
        $this->assertContains('effective_working_windows', $this->changedDay($comparison, 'warehouse', '2026-11-06')['changed_fields']);
    }

    public function test_unreferenced_profiles_application_metadata_and_routing_do_not_change_version(): void
    {
        $snapshot = $this->makeSnapshot();
        config()->set('workdays.definitions.profiles.unrelated', ['extends' => 'missing_parent']);
        config()->set('workdays.definitions.profiles.warehouse.application_label', 'An unrelated display label');
        config()->set('workdays.definitions.profile_routing', ['invalid' => true]);
        config()->set('app.name', 'A renamed application');

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertFalse($comparison->versionChanged);
        $this->assertFalse($comparison->estimateChanged);
    }

    public function test_dates_outside_captured_profile_coverage_are_not_hashed_or_copied(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2035-01-02' => 'Outside the snapshot']);
        $snapshot = $this->makeSnapshot();
        $this->assertStringNotContainsString('2035-01-02', json_encode($snapshot->toArray()['calendar_evidence'], JSON_THROW_ON_ERROR));
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2036-01-02' => 'A different irrelevant holiday']);

        $this->assertFalse(Workday::compareDeliveryPromise($snapshot)->versionChanged);
    }

    public function test_f_logical_holiday_update_changes_current_view_without_mutating_stored_snapshot(): void
    {
        $snapshot = $this->makeSnapshot();
        $before = $snapshot->toArray();
        config()->set('workdays.definitions.profiles.carrier.custom_holidays', ['2026-11-03' => 'Stored new closure']);

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertSame('later', $comparison->direction);
        $this->assertSame(86400, $comparison->deltaSeconds);
        $this->assertTrue($comparison->versionChanged);
        $this->assertSame($before, $snapshot->toArray());
        $this->assertSame('config', $this->evidenceDay($this->makeSnapshot(), 'carrier', '2026-11-03')['holiday_reasons'][0]['source']);
    }

    public function test_g_logical_reopening_and_later_removal_preserve_extra_day_precedence(): void
    {
        config()->set('workdays.definitions.profiles.carrier.custom_holidays', ['2026-11-03' => 'Config closure']);
        config()->set('workdays.definitions.profiles.carrier.extra_working_days', ['2026-11-03' => 'Logical reopening']);
        $snapshot = $this->makeSnapshot();
        $day = $this->evidenceDay($snapshot, 'carrier', '2026-11-03');
        $this->assertTrue($day['is_business_day']);
        $this->assertTrue($day['is_extra_working_day']);
        config()->set('workdays.definitions.profiles.carrier.extra_working_days', []);

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertSame('later', $comparison->direction);
        $this->assertSame(86400, $comparison->deltaSeconds);
        $this->changedDay($comparison, 'carrier', '2026-11-03');
    }

    public function test_recurring_logical_rules_are_frozen_and_current_updates_are_observed(): void
    {
        $snapshot = $this->makeSnapshot();
        config()->set('workdays.definitions.profiles.carrier.holidays.gregorian', ['11-03' => 'Annual carrier closure']);

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertSame('later', $comparison->direction);
        $this->assertSame(86400, $comparison->deltaSeconds);
        $this->assertTrue($comparison->holidayInputsChanged);
        $this->assertTrue($comparison->configurationChanged);
        $this->assertFalse($comparison->databaseRulesChanged);
        $this->assertSame('2026-11-03 16:00:00', $snapshot->originalResult->estimatedCompletionAt->toDateTimeString());
    }

    public function test_historical_database_labelled_evidence_restores_without_enabling_a_live_database_source(): void
    {
        config()->set('workdays.definitions.profiles.carrier.custom_holidays', ['2026-11-03' => 'Historical provider closure']);
        $payload = $this->makeSnapshot()->toArray();
        $databaseLabels = function (array $value) use (&$databaseLabels): array {
            foreach ($value as $key => $entry) {
                $value[$key] = is_array($entry) ? $databaseLabels($entry) : $entry;
            }
            if (($value['source'] ?? null) === 'config') {
                $value['source'] = 'database';
            }

            return $value;
        };
        $payload['calendar_evidence'] = $databaseLabels($payload['calendar_evidence']);
        $payload = $this->rehash($payload);
        $restored = DeliveryPromiseSnapshot::fromArray($payload);

        $this->assertSame($payload, $restored->toArray());
        $this->assertSame('database', $restored->calendarEvidence['profiles']['carrier']['holiday_inputs']['custom']['2026-11-03']['source']);
        $comparison = Workday::compareDeliveryPromise($restored);
        $this->assertFalse($comparison->estimateChanged);
        $this->assertTrue($comparison->holidayInputsChanged);
        $this->assertTrue($comparison->databaseRulesChanged);
        $this->assertSame('config', config('workdays.driver'));
    }

    public function test_runtime_metadata_drift_is_reported_separately_from_calendar_and_eta_changes(): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $payload['runtime_metadata']['php_version'] = '8.2.0';
        $restored = DeliveryPromiseSnapshot::fromArray($this->rehash($payload));

        $comparison = Workday::compareDeliveryPromise($restored);

        $this->assertTrue($comparison->runtimeChanged);
        $this->assertFalse($comparison->versionChanged);
        $this->assertFalse($comparison->estimateChanged);
        $this->assertSame('unchanged', $comparison->direction);
        $this->assertSame(0, $comparison->deltaSeconds);
    }

    public function test_timezone_database_drift_restores_portably_and_is_reported_without_changing_the_estimate(): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $payload['runtime_metadata']['timezone_database_version'] = 'historical-test-tzdb';
        $restored = DeliveryPromiseSnapshot::fromArray($this->rehash($payload));

        $comparison = Workday::compareDeliveryPromise($restored);

        $this->assertTrue($comparison->runtimeChanged);
        $this->assertFalse($comparison->versionChanged);
        $this->assertFalse($comparison->estimateChanged);
        $this->assertSame('unchanged', $comparison->direction);
        $this->assertSame(0, $comparison->deltaSeconds);
    }

    public function test_changed_timezone_database_can_restore_a_historical_anchor_on_an_adjacent_date_label(): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $payload['runtime_metadata']['timezone_database_version'] = 'historical-test-tzdb';
        $payload['calendar_evidence']['anchor_date'] = '2026-11-03';

        $restored = DeliveryPromiseSnapshot::fromArray($this->rehash($payload));

        $this->assertSame('2026-11-03', $restored->calendarEvidence['anchor_date']);
        $this->assertSame('2026-11-03 16:00:00', $restored->originalResult->estimatedCompletionAt->toDateTimeString());
        $this->assertTrue(Workday::compareDeliveryPromise($restored)->runtimeChanged);
    }

    public function test_fractional_eta_movement_from_changed_windows_preserves_microseconds_and_signed_delta_seconds(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.working_hours', ['Monday' => [['09:00', '09:59'], ['11:00', '12:00']]]);
        $snapshot = $this->makeSnapshot($this->workPlan(1), '2026-11-02T09:59:30.123456Z', 1);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]]);

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertSame('2026-11-02T11:01:00.000000+00:00', $this->format($comparison->originalEstimatedCompletionAt));
        $this->assertSame('2026-11-02T11:00:30.123456+00:00', $this->format($comparison->currentEstimatedCompletionAt));
        $this->assertSame('earlier', $comparison->direction);
        $this->assertTrue($comparison->estimateChanged);
        $this->assertEqualsWithDelta(-29.876544, $comparison->deltaSeconds, 0.000001);
    }

    public function test_h_inherited_parent_change_is_observed_under_the_original_child_identity(): void
    {
        config()->set('workdays.definitions.profiles.parent', $this->profile());
        config()->set('workdays.definitions.profiles.warehouse', ['extends' => 'parent']);
        $snapshot = $this->makeSnapshot($this->workPlan(120), '2026-11-02T11:00:00Z');
        config()->set('workdays.definitions.profiles.parent.special_working_hours', ['2026-11-02' => [['09:00', '12:00']]]);

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertSame('later', $comparison->direction);
        $this->assertSame('warehouse', $this->changedDay($comparison, 'warehouse', '2026-11-02')['profile']);
        $this->assertArrayNotHasKey('parent', $snapshot->toArray()['calendar_evidence']['profiles']);
        $this->assertSame('2026-11-02 13:00:00', $snapshot->originalResult->estimatedCompletionAt->toDateTimeString());
    }

    public function test_inherited_logical_holidays_are_frozen_under_the_selected_child_identity(): void
    {
        config()->set('workdays.definitions.profiles.parent', $this->profile());
        config()->set('workdays.definitions.profiles.warehouse', ['extends' => 'parent']);
        config()->set('workdays.definitions.profiles.parent.custom_holidays', ['2026-11-02' => 'Parent logical holiday']);
        $snapshot = $this->makeSnapshot($this->workPlan(120), '2026-11-02T10:00:00Z');

        $this->assertSame('2026-11-03 11:00:00', $snapshot->originalResult->estimatedCompletionAt->toDateTimeString());
        $this->assertFalse($this->evidenceDay($snapshot, 'warehouse', '2026-11-02')['is_business_day']);
        $this->assertArrayNotHasKey('parent', $snapshot->calendarEvidence['profiles']);
        $this->assertSame('Parent logical holiday', $this->evidenceDay($snapshot, 'warehouse', '2026-11-02')['holiday_reasons'][0]['title']);
    }

    public function test_i_cutoff_change_recalculates_current_plan_and_preserves_original_cutoff_result(): void
    {
        $plan = $this->workPlan(120);
        $plan['cutoff_profile'] = 'warehouse';
        $snapshot = $this->makeSnapshot($plan, '2026-11-02T13:00:00Z');
        config()->set('workdays.definitions.profiles.warehouse.cutoff.time', '12:00');

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertSame('later', $comparison->direction);
        $this->assertSame('2026-11-02 15:00:00', $comparison->originalEstimatedCompletionAt->toDateTimeString());
        $this->assertSame('2026-11-03 11:00:00', $comparison->currentEstimatedCompletionAt->toDateTimeString());
        $this->assertSame(72000, $comparison->deltaSeconds);
        $this->assertTrue($snapshot->originalResult->cutoffResult->sameDayEligible);
        $this->assertSame('14:00', $snapshot->originalResult->cutoffResult->cutoffAt->format('H:i'));
    }

    public function test_non_utc_cutoff_with_disabled_weekday_survives_frozen_json_round_trip(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.cutoff', ['timezone' => 'Asia/Tehran', 'time' => '14:00', 'weekdays' => ['Monday' => null]]);
        $plan = $this->workPlan(60);
        $plan['timezone'] = 'Asia/Tehran';
        $plan['profile_timezones']['warehouse'] = 'Asia/Tehran';
        $plan['cutoff_profile'] = 'warehouse';
        $normal = Workday::planDelivery('2026-11-02T13:00:00+03:30', $plan);
        $snapshot = $this->makeSnapshot($plan, '2026-11-02T13:00:00+03:30', 1);
        $restored = DeliveryPromiseSnapshot::fromArray(json_decode(json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        $this->assertSame($normal->toArray(), $snapshot->originalResult->toArray());
        $this->assertSame($normal->toArray(), $restored->originalResult->toArray());
        $this->assertSame('cutoff_disabled', $restored->originalResult->cutoffResult->reason);
        $this->assertSame('2026-11-03T06:30:00.000000+00:00', $this->format($restored->originalResult->estimatedCompletionAt));
        $this->assertFalse(Workday::compareDeliveryPromise($restored)->estimateChanged);
    }

    #[DataProvider('timezoneDatabaseDriftModes')]
    public function test_restored_cutoff_business_midnight_uses_the_destination_date_dst_offset(bool $timezoneDatabaseChanged): void
    {
        config()->set('workdays.definitions.profiles.warehouse.cutoff', ['timezone' => 'America/New_York', 'time' => '14:00']);
        $plan = $this->workPlan(0);
        $plan['timezone'] = 'America/New_York';
        $plan['profile_timezones']['warehouse'] = 'America/New_York';
        $plan['cutoff_profile'] = 'warehouse';
        $snapshot = $this->makeSnapshot($plan, '2026-10-30T15:00:00-04:00', 7);
        $payload = $snapshot->toArray();
        if ($timezoneDatabaseChanged) {
            $payload['runtime_metadata']['timezone_database_version'] = 'historical-test-tzdb';
            $payload = $this->rehash($payload);
        }

        $restored = DeliveryPromiseSnapshot::fromArray($payload);

        $this->assertSame('2026-11-02T05:00:00.000000+00:00', $this->format($snapshot->originalResult->cutoffResult->effectiveBusinessDate->utc()));
        $this->assertSame('2026-11-02T05:00:00.000000+00:00', $this->format($restored->originalResult->cutoffResult->effectiveBusinessDate->utc()));
        $this->assertSame('America/New_York', $restored->originalResult->cutoffResult->timezone);
        $this->assertSame($timezoneDatabaseChanged, Workday::compareDeliveryPromise($restored)->runtimeChanged);
        if (! $timezoneDatabaseChanged) {
            $this->assertSame('America/New_York', $restored->originalResult->cutoffResult->effectiveBusinessDate->timezoneName);
        }
    }

    public static function timezoneDatabaseDriftModes(): array
    {
        return [[false], [true]];
    }

    public function test_j_overnight_owner_change_affects_receiving_day_without_double_counting(): void
    {
        config()->set('workdays.definitions.profiles.warehouse', array_replace($this->profile(), ['allow_overnight_working_hours' => true, 'working_hours' => ['Friday' => [['22:00', '06:00']]], 'custom_holidays' => ['2026-11-07' => 'Receiving holiday']]));
        $snapshot = $this->makeSnapshot($this->workPlan(180), '2026-11-06T23:00:00Z', 10);
        $day = $this->evidenceDay($snapshot, 'warehouse', '2026-11-07');
        $this->assertFalse($day['is_business_day']);
        $this->assertNotEmpty($day['effective_working_windows']);
        $this->assertSame('2026-11-07 02:00:00', $snapshot->originalResult->estimatedCompletionAt->toDateTimeString());
        config()->set('workdays.definitions.profiles.warehouse.special_working_hours', ['2026-11-06' => []]);

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertSame('later', $comparison->direction);
        $this->assertSame('2026-11-14 01:00:00', $comparison->currentEstimatedCompletionAt->toDateTimeString());
        $this->changedDay($comparison, 'warehouse', '2026-11-07');
    }

    public function test_k_json_round_trip_restores_the_same_immutable_snapshot_and_original_result(): void
    {
        $snapshot = $this->makeSnapshot($this->workPlan(1), '2026-11-02T10:00:12.123456Z');
        $payload = $snapshot->toArray();
        $decoded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = DeliveryPromiseSnapshot::fromArray($decoded);

        $this->assertSame($payload, $restored->toArray());
        $this->assertSame($snapshot->calendarVersion, $restored->calendarVersion);
        $this->assertSame('2026-11-02T10:01:12.123456+00:00', $this->format($restored->originalResult->estimatedCompletionAt));
        $this->assertSame('2026-11-02T10:00:12.123456+00:00', $this->format($restored->receivedAt));
        $this->assertFalse(Workday::compareDeliveryPromise($restored)->versionChanged);
    }

    public function test_restore_uses_frozen_inputs_even_after_current_profiles_and_hijri_config_are_gone(): void
    {
        $snapshot = $this->makeSnapshot();
        $payload = $snapshot->toArray();
        config()->set('workdays.definitions.profiles', []);
        config()->set('workdays.definitions.hijri', ['method' => 'invalid', 'adjustment' => 'bad']);
        config()->set('workdays.max_scan_days', 1);

        $restored = DeliveryPromiseSnapshot::fromArray($payload);

        $this->assertSame($payload, $restored->toArray());
        $this->assertSame('2026-11-03 16:00:00', $restored->originalResult->estimatedCompletionAt->toDateTimeString());
        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('Invalid Config source operational settings');
        Workday::compareDeliveryPromise($restored);
    }

    public function test_missing_current_selected_profiles_are_unresolvable_after_successful_source_open(): void
    {
        $snapshot = $this->makeSnapshot();
        config()->set('workdays.definitions.profiles', []);
        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertSame('unresolvable', $comparison->status);
        $this->assertSame('unresolvable', $comparison->direction);
        $this->assertNull($comparison->currentEstimatedCompletionAt);
        $this->assertSame('2026-11-03 16:00:00', $comparison->originalEstimatedCompletionAt->toDateTimeString());
    }

    public function test_unused_profile_timezone_mappings_do_not_capture_or_require_that_profiles_calendar(): void
    {
        config()->set('workdays.definitions.profiles.unused', $this->profile());
        $plan = $this->workPlan(60);
        $plan['profile_timezones']['unused'] = 'Asia/Tehran';
        $snapshot = $this->makeSnapshot($plan, '2026-11-02T10:00:00Z');
        $payload = $snapshot->toArray();
        $this->assertArrayNotHasKey('unused', $payload['calendar_evidence']['profiles']);
        $profiles = config('workdays.definitions.profiles');
        unset($profiles['unused']);
        config()->set('workdays.definitions.profiles', $profiles);

        $restored = DeliveryPromiseSnapshot::fromArray($payload);

        $this->assertSame($payload, $restored->toArray());
        $this->assertFalse(Workday::compareDeliveryPromise($restored)->estimateChanged);
    }

    public function test_snapshot_and_comparison_properties_and_nested_lists_are_readonly(): void
    {
        $snapshot = $this->makeSnapshot();
        $comparison = Workday::compareDeliveryPromise($snapshot);
        $this->assertInstanceOf(CarbonImmutable::class, $snapshot->receivedAt);
        $this->assertSame(0, $snapshot->receivedAt->getOffset());
        $snapshot->receivedAt->addDay();
        $this->assertSame('2026-11-02T13:00:00.000000+00:00', $this->format($snapshot->receivedAt));
        $this->assertInstanceOf(CarbonImmutable::class, $comparison->currentEstimatedCompletionAt);
        $this->expectException(Error::class);

        $snapshot->plan['timezone'] = 'Asia/Tehran';
    }

    public function test_comparison_is_readonly_and_serialization_uses_stable_snake_case_fields(): void
    {
        $comparison = Workday::compareDeliveryPromise($this->makeSnapshot());
        $serialized = $comparison->toArray();

        foreach (['status', 'original_calendar_version', 'current_calendar_version', 'version_changed', 'estimate_changed', 'direction', 'original_estimated_completion_at', 'current_estimated_completion_at', 'delta_seconds', 'changed_calendar_days', 'runtime_changed', 'error'] as $key) {
            $this->assertArrayHasKey($key, $serialized);
        }
        $this->assertSame('2026-11-03T16:00:00.000000+00:00', $serialized['current_estimated_completion_at']);
        $this->expectException(Error::class);

        $comparison->direction = 'later';
    }

    public function test_mutable_received_timestamp_and_mutable_returned_payload_do_not_change_snapshot(): void
    {
        $input = new DateTime('2026-11-02 09:00:12.123456', new DateTimeZone('Asia/Tehran'));
        $before = $input->format('Y-m-d H:i:s.uP');
        $snapshot = Workday::captureDeliveryPromise($input, $this->workPlan(1), ['horizon_days' => 1]);
        $stored = $snapshot->toArray();
        $payload = $snapshot->toArray();
        $payload['plan']['stages'][0]['minutes'] = 999;
        $this->assertSame($before, $input->format('Y-m-d H:i:s.uP'));
        $input->modify('+10 days');

        $this->assertSame('2026-11-02 09:00:12.123456+03:30', $before);
        $this->assertSame('2026-11-02T05:30:12.123456+00:00', $this->format($snapshot->receivedAt));
        $this->assertSame($stored, $snapshot->toArray());
    }

    public function test_external_php_array_references_cannot_modify_captured_readonly_plan(): void
    {
        $timezone = 'UTC';
        $plan = $this->workPlan(1);
        $plan['timezone'] = &$timezone;
        $snapshot = $this->makeSnapshot($plan, '2026-11-02T10:00:00Z', 1);
        $timezone = 'Asia/Tehran';

        $this->assertSame('UTC', $snapshot->plan['timezone']);
        $this->assertSame('UTC', $snapshot->toArray()['plan']['timezone']);
        $this->assertSame('UTC', $snapshot->originalResult->timezone);
    }

    public function test_m_reordered_associative_maps_and_weekday_aliases_do_not_change_calendar_version(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2026-11-06' => 'Friday', '2026-11-05' => 'Thursday']);
        $snapshot = $this->makeSnapshot($this->workPlan(60), '2026-11-02T10:00:00Z');
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2026-11-05' => 'Thursday', '2026-11-06' => 'Friday']);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', [5 => [['09:00', '17:00']], 4 => [['09:00', '17:00']], 3 => [['09:00', '17:00']], 2 => [['09:00', '17:00']], 1 => [['09:00', '17:00']]]);

        $this->assertFalse(Workday::compareDeliveryPromise($snapshot)->versionChanged);
        $this->assertSame(CalendarFingerprint::hash(['b' => ['y' => 2, 'x' => 1], 'a' => 0]), CalendarFingerprint::hash(['a' => 0, 'b' => ['x' => 1, 'y' => 2]]));
        $this->assertNotSame(CalendarFingerprint::hash(['ordered' => [1, 2]]), CalendarFingerprint::hash(['ordered' => [2, 1]]));
        $this->assertNotSame(CalendarFingerprint::hash(['value' => 1]), CalendarFingerprint::hash(['value' => '1']));
        $this->assertNotSame(CalendarFingerprint::hash(['value' => null]), CalendarFingerprint::hash([]));
    }

    public function test_n_unresolvable_current_calendar_returns_null_current_estimate_and_useful_error(): void
    {
        $snapshot = $this->makeSnapshot($this->workPlan(120), '2026-11-02T10:00:00Z', 1);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', []);

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertSame('unresolvable', $comparison->status);
        $this->assertSame('unresolvable', $comparison->direction);
        $this->assertNull($comparison->estimateChanged);
        $this->assertNull($comparison->currentEstimatedCompletionAt);
        $this->assertNull($comparison->deltaSeconds);
        $this->assertNotEmpty($comparison->error);
        $this->assertSame('2026-11-02 12:00:00', $comparison->originalEstimatedCompletionAt->toDateTimeString());
    }

    #[DataProvider('invalidHorizons')]
    public function test_o_horizon_is_mandatory_strict_and_bounded(mixed $horizon): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('horizon');

        Workday::captureDeliveryPromise('2026-11-02T10:00:00Z', $this->workPlan(1), ['horizon_days' => $horizon]);
    }

    public static function invalidHorizons(): array
    {
        return [[0], [-1], [32], ['7'], [7.0], [true], [false], [null], [[]], [new \stdClass]];
    }

    public function test_o_missing_horizon_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('horizon');

        Workday::captureDeliveryPromise('2026-11-02T10:00:00Z', $this->workPlan(1), []);
    }

    public function test_o_horizon_cannot_exceed_global_scan_limit(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $this->expectException(InvalidArgumentException::class);

        $this->makeSnapshot($this->workPlan(1), '2026-11-02T10:00:00Z', 2);
    }

    public function test_o_one_future_date_is_included_and_two_future_dates_do_not_silently_fit(): void
    {
        $snapshot = $this->makeSnapshot($this->dayPlan(1), '2026-11-02T10:00:00Z', 1);
        $this->assertSame('2026-11-03 10:00:00', $snapshot->originalResult->estimatedCompletionAt->toDateTimeString());
        $this->assertSame('2026-11-04 10:00:00', Workday::planDelivery('2026-11-02T10:00:00Z', $this->dayPlan(2))->estimatedCompletionAt->toDateTimeString());
        $this->expectException(RuntimeException::class);

        $this->makeSnapshot($this->dayPlan(2), '2026-11-02T10:00:00Z', 1);
    }

    public function test_o_thirty_one_future_dates_are_supported_without_requiring_working_hours(): void
    {
        config()->set('workdays.definitions.profiles.carrier', ['weekends' => [], 'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []], 'custom_holidays' => [], 'extra_working_days' => []]);
        $snapshot = $this->makeSnapshot($this->dayPlan(31), '2026-11-02T10:00:00Z', 31);

        $this->assertSame('2026-12-03 10:00:00', $snapshot->originalResult->estimatedCompletionAt->toDateTimeString());
        $this->assertSame(31, $snapshot->horizonDays);
        foreach ($snapshot->toArray()['calendar_evidence']['profiles']['carrier']['days'] as $day) {
            $this->assertNull($day['effective_working_windows']);
        }
    }

    public function test_o_eight_distinct_profiles_are_supported_and_nine_remains_only_a_snapshot_limit(): void
    {
        $profiles = [];
        $timezones = [];
        $stages = [];
        foreach (range(1, 9) as $number) {
            $name = 'calendar_'.$number;
            $profiles[$name] = $this->profile();
            $timezones[$name] = 'UTC';
            $stages[] = ['id' => 'stage_'.$number, 'type' => 'work', 'profile' => $name, 'minutes' => 0];
        }
        config()->set('workdays.definitions.profiles', $profiles);
        $plan = ['timezone' => 'UTC', 'profile_timezones' => $timezones, 'stages' => array_slice($stages, 0, 8)];
        $snapshot = $this->makeSnapshot($plan, '2026-11-02T09:00:00Z', 1);
        $this->assertCount(8, $snapshot->toArray()['calendar_evidence']['profiles']);
        $plan['stages'] = $stages;
        $this->assertCount(9, Workday::planDelivery('2026-11-02T09:00:00Z', $plan)->stages);
        $this->expectException(InvalidArgumentException::class);

        $this->makeSnapshot($plan, '2026-11-02T09:00:00Z', 1);
    }

    public function test_skipped_apia_civil_date_does_not_extend_the_global_horizon(): void
    {
        config()->set('workdays.definitions.profiles.carrier', array_replace($this->profile(), ['weekends' => []]));
        $plan = $this->dayPlan(1);
        $plan['timezone'] = 'Pacific/Apia';
        $plan['profile_timezones']['carrier'] = 'Pacific/Apia';
        $snapshot = $this->makeSnapshot($plan, '2011-12-29T10:00:00-10:00', 2);
        $this->assertSame('2011-12-31 10:00:00+14:00', $snapshot->originalResult->estimatedCompletionAt->setTimezone('Pacific/Apia')->format('Y-m-d H:i:sP'));
        $this->assertFalse($this->evidenceDay($snapshot, 'carrier', '2011-12-30')['exists']);
        $this->expectException(RuntimeException::class);

        $this->makeSnapshot($plan, '2011-12-29T10:00:00-10:00', 1);
    }

    #[DataProvider('dstOvernightPlans')]
    public function test_dst_overnight_snapshot_reuses_actual_elapsed_working_minutes(string $received, int $minutes, string $expected): void
    {
        config()->set('workdays.definitions.profiles.warehouse', array_replace($this->profile(), ['weekends' => [], 'allow_overnight_working_hours' => true, 'working_hours' => ['Saturday' => [['22:00', '06:00']]]]));
        $plan = $this->workPlan($minutes);
        $plan['timezone'] = 'America/New_York';
        $plan['profile_timezones']['warehouse'] = 'America/New_York';
        $snapshot = $this->makeSnapshot($plan, $received, 1);

        $this->assertSame($expected, $this->format($snapshot->originalResult->estimatedCompletionAt));
        $this->assertSame($minutes * 60, $snapshot->originalResult->estimatedCompletionAt->getTimestamp() - $snapshot->receivedAt->getTimestamp());
        $this->assertFalse(Workday::compareDeliveryPromise($snapshot)->estimateChanged);
    }

    public static function dstOvernightPlans(): array
    {
        return [
            ['2026-03-07T22:00:00-05:00', 420, '2026-03-08T10:00:00.000000+00:00'],
            ['2026-10-31T22:00:00-04:00', 540, '2026-11-01T11:00:00.000000+00:00'],
        ];
    }

    public function test_profile_timezone_coverage_contains_neighboring_dates_for_overnight_ownership(): void
    {
        config()->set('workdays.definitions.profiles.warehouse', array_replace($this->profile(), ['weekends' => [], 'allow_overnight_working_hours' => true, 'working_hours' => array_fill_keys(range(1, 7), [['22:00', '06:00']])]));
        $plan = $this->workPlan(60);
        $plan['profile_timezones']['warehouse'] = 'Pacific/Kiritimati';
        $snapshot = $this->makeSnapshot($plan, '2026-11-02T11:00:00Z', 1);
        $profile = $snapshot->toArray()['calendar_evidence']['profiles']['warehouse'];

        $this->assertSame('Pacific/Kiritimati', $profile['timezone']);
        $this->assertLessThanOrEqual('2026-11-02', $profile['coverage']['from']);
        $this->assertLessThan($profile['coverage']['from'], $profile['coverage']['provider_from']);
        $this->assertTrue($this->evidenceDay($snapshot, 'warehouse', '2026-11-03')['is_business_day']);
        $this->assertSame('2026-11-02 12:00:00', $snapshot->originalResult->estimatedCompletionAt->toDateTimeString());
    }

    #[DataProvider('batchHorizons')]
    public function test_config_capture_and_compare_issue_no_database_queries_at_either_horizon(int $horizon): void
    {
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $snapshot = $this->makeSnapshot(null, '2026-11-02T13:00:00Z', $horizon);
        $this->assertSame([], $connection->getQueryLog());
        $connection->flushQueryLog();
        $comparison = Workday::compareDeliveryPromise($snapshot);
        $this->assertFalse($comparison->estimateChanged);
        $this->assertSame([], $connection->getQueryLog());
        $connection->disableQueryLog();
    }

    public static function batchHorizons(): array
    {
        return [[1], [31]];
    }

    public function test_conflicting_database_publications_and_their_ids_do_not_affect_the_config_source(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        $snapshot = $this->makeSnapshot();
        $repository = $this->app->make(DatabaseDefinitionRepository::class);
        $current = $repository->current();
        $definitions = $current->definitions;
        $definitions['profiles']['warehouse']['custom_holidays'] = ['2026-11-06' => 'Database-only closure', '2035-11-03' => 'Outside range'];
        $published = $repository->publish($definitions, $current->revisionId);

        $this->assertNotSame($current->revisionId, $published->revisionId);
        $this->assertFalse(Workday::compareDeliveryPromise($snapshot)->versionChanged);
    }

    public function test_config_materialization_and_original_result_use_one_view_despite_concurrent_config_edit(): void
    {
        $source = new class(new ConfigDefinitionSource($this->app['config'])) implements DefinitionSource
        {
            public bool $injected = false;

            public function __construct(private ConfigDefinitionSource $source) {}

            public function open(): WorkdayDefinitions
            {
                $view = $this->source->open();

                if (! $this->injected) {
                    $this->injected = true;
                    config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2026-11-02' => 'Concurrent fixture update']);
                }

                return $view;
            }
        };
        $this->app->instance(DefinitionSource::class, $source);
        $this->app->forgetInstance(CalculationContextFactory::class);
        $this->app->forgetInstance(WorkdayManager::class);
        Workday::clearResolvedInstance('workdays');

        $snapshot = $this->makeSnapshot($this->workPlan(120), '2026-11-02T10:00:00Z', 1);

        $this->assertTrue($source->injected);
        $this->assertTrue($this->evidenceDay($snapshot, 'warehouse', '2026-11-02')['is_business_day']);
        $this->assertSame('2026-11-02 12:00:00', $snapshot->originalResult->estimatedCompletionAt->toDateTimeString());
        $comparison = Workday::compareDeliveryPromise($snapshot);
        $this->assertSame('later', $comparison->direction);
        $this->assertSame('2026-11-03 11:00:00', $comparison->currentEstimatedCompletionAt->toDateTimeString());
    }

    public function test_missing_database_schema_fails_capture_with_setup_guidance(): void
    {
        config()->set('workdays.driver', 'database');
        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('definitions migration');

        $this->makeSnapshot();
    }

    public function test_routing_changes_do_not_select_a_different_profile_during_comparison(): void
    {
        config()->set('workdays.definitions.profile_routing', ['fallback_profile' => 'warehouse', 'rules' => []]);
        $selected = Workday::resolveProfile([]);
        $snapshot = $this->makeSnapshot($this->workPlan(60, $selected->profile), '2026-11-02T10:00:00Z');
        config()->set('workdays.definitions.profile_routing', ['fallback_profile' => 'carrier', 'rules' => []]);
        config()->set('workdays.definitions.profiles.carrier.custom_holidays', ['2026-11-02' => 'Would delay rerouting']);

        $comparison = Workday::compareDeliveryPromise($snapshot);

        $this->assertFalse($comparison->versionChanged);
        $this->assertFalse($comparison->estimateChanged);
        $this->assertSame('warehouse', $snapshot->plan['stages'][0]['profile']);
    }

    public function test_manager_and_facade_capture_and_compare_the_same_result(): void
    {
        $manager = $this->app->make(WorkdayManager::class);
        $snapshot = $this->makeSnapshot();

        $this->assertSame($snapshot->toArray(), $manager->captureDeliveryPromise('2026-11-02T13:00:00Z', $this->referencePlan(), ['horizon_days' => 7])->toArray());
        $this->assertSame(Workday::compareDeliveryPromise($snapshot)->toArray(), $manager->compareDeliveryPromise($snapshot->toArray())->toArray());
    }

    public function test_ordinary_delivery_results_and_global_configuration_remain_unchanged(): void
    {
        $before = Workday::planDelivery('2026-11-02T13:00:00Z', $this->referencePlan())->toArray();
        $config = config('workdays');
        $snapshot = $this->makeSnapshot();
        Workday::compareDeliveryPromise($snapshot);

        $this->assertSame($before, $snapshot->originalResult->toArray());
        $this->assertSame($before, Workday::planDelivery('2026-11-02T13:00:00Z', $this->referencePlan())->toArray());
        $this->assertSame($config, config('workdays'));
    }

    #[DataProvider('missingSnapshotFields')]
    public function test_l_missing_snapshot_fields_are_rejected(string $key): void
    {
        $payload = $this->makeSnapshot()->toArray();
        unset($payload[$key]);
        $this->expectException(InvalidArgumentException::class);

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public static function missingSnapshotFields(): array
    {
        return array_map(static fn (string $key): array => [$key], ['schema_version', 'calendar_version', 'received_at', 'plan', 'horizon_days', 'original_result', 'calendar_evidence', 'runtime_metadata', 'integrity_hash']);
    }

    #[DataProvider('invalidSchemaVersions')]
    public function test_l_unsupported_or_coerced_schema_versions_are_rejected(mixed $version): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $payload['schema_version'] = $version;
        $payload = $this->rehash($payload);
        $this->expectException(InvalidArgumentException::class);

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public static function invalidSchemaVersions(): array
    {
        return [[0], [2], ['1'], [1.0], [true], [null]];
    }

    #[DataProvider('unhashedTampering')]
    public function test_l_changes_to_any_snapshot_interpretation_field_fail_integrity(string $field): void
    {
        $payload = $this->makeSnapshot()->toArray();
        switch ($field) {
            case 'evidence': $payload['calendar_evidence']['profiles']['warehouse']['days'][0]['is_business_day'] = ! $payload['calendar_evidence']['profiles']['warehouse']['days'][0]['is_business_day'];
                break;
            case 'result': $payload['original_result']['estimated_completion_at'] = '2026-11-05T16:00:00.000000+00:00';
                break;
            case 'plan': $payload['plan']['stages'][0]['minutes'] = 300;
                break;
            case 'received': $payload['received_at'] = '2026-11-03T13:00:00.000000+00:00';
                break;
            case 'runtime': $payload['runtime_metadata']['php_version'] = '8.2.0';
                break;
            case 'hash': $payload['calendar_version'] = str_repeat('0', 64);
                break;
            case 'integrity': $payload['integrity_hash'] = str_repeat('0', 64);
                break;
        }
        $this->expectException(InvalidArgumentException::class);

        Workday::compareDeliveryPromise($payload);
    }

    public static function unhashedTampering(): array
    {
        return [['evidence'], ['result'], ['plan'], ['received'], ['runtime'], ['hash'], ['integrity']];
    }

    #[DataProvider('malformedSnapshotMutations')]
    public function test_l_rehashed_malformed_snapshots_are_still_rejected(string $mutation): void
    {
        $payload = $this->makeSnapshot()->toArray();
        switch ($mutation) {
            case 'unknown top key': $payload['automatic_order_update'] = true;
                break;
            case 'invalid received': $payload['received_at'] = 'tomorrow';
                break;
            case 'impossible received': $payload['received_at'] = '2026-02-30T10:00:00.000000+00:00';
                break;
            case 'bad plan stage': $payload['plan']['stages'][0]['minutes'] = '120';
                break;
            case 'unknown plan profile': $payload['plan']['stages'][0]['profile'] = 'not_captured';
                break;
            case 'invalid result timestamp': $payload['original_result']['estimated_completion_at'] = 'now';
                break;
            case 'result mismatch': $payload['original_result']['estimated_completion_at'] = '2026-11-04T16:00:00.000000+00:00';
                break;
            case 'forged result': $payload['original_result']['estimated_completion_at'] = $payload['original_result']['stages'][2]['completed_at'] = '2026-11-03T17:00:00.000000+00:00';
                break;
            case 'valid changed plan': $payload['plan']['stages'][0]['minutes'] = 180;
                break;
            case 'invalid profile timezone': $payload['calendar_evidence']['profiles']['warehouse']['timezone'] = 'Mars/Olympus';
                break;
            case 'invalid profile config': $payload['calendar_evidence']['profiles']['warehouse']['config']['weekends'] = false;
                break;
            case 'invalid profile identity': $payload['calendar_evidence']['profiles']['warehouse']['days'][0]['profile'] = 'other';
                break;
            case 'impossible evidence date': $payload['calendar_evidence']['profiles']['warehouse']['days'][0]['date'] = '2026-02-30';
                break;
            case 'boolean coercion': $payload['calendar_evidence']['profiles']['warehouse']['days'][0]['is_business_day'] = 'false';
                break;
            case 'inconsistent decision': $payload['calendar_evidence']['profiles']['warehouse']['days'][0]['is_business_day'] = ! $payload['calendar_evidence']['profiles']['warehouse']['days'][0]['is_business_day'];
                break;
            case 'invalid window list': $payload['calendar_evidence']['profiles']['warehouse']['days'][0]['effective_working_windows'] = '09:00-17:00';
                break;
            case 'missing evidence date': array_pop($payload['calendar_evidence']['profiles']['warehouse']['days']);
                break;
            case 'invalid provider date': $payload['calendar_evidence']['profiles']['warehouse']['holiday_inputs']['custom']['2026-02-30'] = ['title' => null, 'source' => 'database'];
                break;
            case 'reversed coverage': $payload['calendar_evidence']['profiles']['warehouse']['coverage']['from'] = '2027-01-01';
                break;
            case 'horizon mismatch': $payload['calendar_evidence']['horizon_days'] = 8;
                break;
            case 'anchor mismatch': $payload['calendar_evidence']['anchor_date'] = '2026-11-03';
                break;
            case 'runtime drift outside horizon':
                $payload['runtime_metadata']['php_version'] = '8.2.0';
                $payload['original_result']['estimated_completion_at'] = $payload['original_result']['stages'][2]['completed_at'] = '2026-11-12T16:00:00.000000+00:00';
                break;
            case 'runtime drift window wrong date':
                $payload['runtime_metadata']['php_version'] = '8.2.0';
                $wrongDate = CarbonImmutable::parse($payload['calendar_evidence']['profiles']['warehouse']['days'][0]['date'], 'UTC')->addDay()->toDateString();
                $payload['calendar_evidence']['profiles']['warehouse']['days'][0]['effective_working_windows'] = [['start' => $wrongDate.'T09:00:00.000000+00:00', 'end' => $wrongDate.'T10:00:00.000000+00:00']];
                break;
            case 'runtime drift positive transit no-op':
                $payload['runtime_metadata']['php_version'] = '8.2.0';
                $payload['original_result']['stages'][1]['completed_at'] = $payload['original_result']['stages'][1]['started_at'];
                $payload['original_result']['stages'][2]['started_at'] = $payload['original_result']['stages'][1]['started_at'];
                $payload['original_result']['estimated_completion_at'] = $payload['original_result']['stages'][2]['completed_at'] = '2026-11-02T16:00:00.000000+00:00';
                break;
            case 'missing referenced profile': unset($payload['calendar_evidence']['profiles']['carrier']);
                break;
        }
        $payload = $this->rehash($payload);
        $this->expectException(InvalidArgumentException::class);

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public static function malformedSnapshotMutations(): array
    {
        return array_map(static fn (string $name): array => [$name], [
            'unknown top key', 'invalid received', 'impossible received', 'bad plan stage', 'unknown plan profile',
            'invalid result timestamp', 'result mismatch', 'forged result', 'valid changed plan', 'invalid profile timezone',
            'invalid profile config', 'invalid profile identity', 'impossible evidence date', 'boolean coercion', 'inconsistent decision',
            'invalid window list', 'missing evidence date', 'invalid provider date', 'reversed coverage', 'horizon mismatch', 'missing referenced profile',
            'anchor mismatch', 'runtime drift outside horizon', 'runtime drift window wrong date', 'runtime drift positive transit no-op',
        ]);
    }

    public function test_l_oversized_snapshot_and_php_objects_are_rejected(): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $payload['runtime_metadata']['php_version'] = str_repeat('x', 2097153);
        $this->assertGreaterThan(2097152, strlen(json_encode($payload, JSON_THROW_ON_ERROR)));
        $this->expectException(InvalidArgumentException::class);

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public function test_l_php_objects_cannot_enter_portable_snapshot_payloads(): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $payload['plan']['stages'][0]['minutes'] = new \stdClass;
        $this->expectException(InvalidArgumentException::class);

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public function test_l_rehashed_snapshot_rejects_unknown_active_cutoff_policy_fields(): void
    {
        $plan = $this->workPlan(60);
        $plan['cutoff_profile'] = 'warehouse';
        $payload = $this->makeSnapshot($plan)->toArray();
        $payload['calendar_evidence']['profiles']['warehouse']['config']['cutoff']['future_override'] = '00:00';
        $payload = $this->rehash($payload);
        $this->expectException(InvalidArgumentException::class);

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public function test_l_cyclic_php_arrays_hit_a_bounded_validation_error(): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $cycle = [];
        $cycle['self'] = &$cycle;
        $payload['runtime_metadata']['php_version'] = $cycle;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('depth');

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public function test_l_deeply_nested_arrays_hit_the_documented_depth_limit(): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $nested = 'leaf';
        foreach (range(1, 33) as $depth) {
            $nested = ['nested' => $nested];
        }
        $payload['runtime_metadata']['php_version'] = $nested;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('depth');

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public function test_l_large_node_counts_hit_the_documented_node_limit(): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $payload['runtime_metadata']['php_version'] = array_fill(0, 100001, 0);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('node');

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    #[DataProvider('nonfiniteNumbers')]
    public function test_l_nonfinite_numbers_are_not_portable_snapshot_values(float $value): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $payload['plan']['stages'][0]['minutes'] = $value;
        $this->expectException(InvalidArgumentException::class);

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public static function nonfiniteNumbers(): array
    {
        return [[INF], [-INF], [NAN]];
    }

    public function test_l_resources_are_rejected_without_reading_or_serializing_them(): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $resource = fopen('php://memory', 'r+');
        try {
            $payload['plan']['stages'][0]['minutes'] = $resource;
            $this->expectException(InvalidArgumentException::class);

            DeliveryPromiseSnapshot::fromArray($payload);
        } finally {
            fclose($resource);
        }
    }

    public function test_l_repeated_shared_large_strings_hit_the_byte_budget_before_schema_validation(): void
    {
        $payload = $this->makeSnapshot()->toArray();
        $shared = str_repeat('x', 400000);
        $payload['runtime_metadata']['php_version'] = array_fill(0, 6, $shared);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('size limit');

        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public function test_oversized_materialized_calendar_evidence_is_rejected_during_capture(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2026-11-03' => str_repeat('x', 1100000)]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('size limit');

        $this->makeSnapshot($this->workPlan(60), '2026-11-02T10:00:00Z', 31);
    }

    public function test_capture_rejects_coverage_that_would_require_year_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/coverage|year/i');

        $this->makeSnapshot($this->dayPlan(0), '0001-01-01T10:00:00Z', 1);
    }

    public function test_capture_rejects_historical_cutoff_offsets_with_non_minute_seconds(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.weekends', []);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], [['09:00', '17:00']]));
        config()->set('workdays.definitions.profiles.warehouse.cutoff', ['timezone' => 'Asia/Tehran', 'time' => '14:00']);
        $plan = $this->workPlan(0);
        $plan['timezone'] = 'Asia/Tehran';
        $plan['profile_timezones']['warehouse'] = 'Asia/Tehran';
        $plan['cutoff_profile'] = 'warehouse';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/offset|minute/i');

        $this->makeSnapshot($plan, '1880-01-01T07:00:00Z', 1);
    }

    public function test_large_valid_snapshots_restore_and_compare_with_a_separate_diagnostic_budget(): void
    {
        $originalWindows = [];
        $currentWindows = [];
        $clock = static fn (int $minute): string => sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
        foreach (range(0, 499) as $number) {
            $minute = $number * 2;
            $originalWindows[] = [$clock($minute), $clock($minute + 1)];
            $currentWindows[] = [$clock($minute + 1), $clock($minute + 2)];
        }
        config()->set('workdays.definitions.profiles.warehouse.working_hours', array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], $originalWindows));
        $snapshot = $this->makeSnapshot($this->workPlan(0), '2026-11-02T00:00:00Z', 31);
        $payload = $snapshot->toArray();
        $this->assertLessThanOrEqual(CalendarFingerprint::MAX_BYTES, strlen(json_encode($payload, JSON_THROW_ON_ERROR)));
        $restored = DeliveryPromiseSnapshot::fromArray($payload);
        $this->assertSame($payload, $restored->toArray());
        $this->assertFalse(Workday::compareDeliveryPromise($restored)->estimateChanged);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], $currentWindows));

        $comparison = Workday::compareDeliveryPromise($restored);

        $this->assertSame('resolved', $comparison->status);
        $this->assertSame('later', $comparison->direction);
        $this->assertSame(60, $comparison->deltaSeconds);
        $this->assertGreaterThan(CalendarFingerprint::MAX_BYTES, strlen(json_encode($comparison->toArray(), JSON_THROW_ON_ERROR)));
        $this->assertNotEmpty($comparison->changedCalendarDays);
    }

    public function test_comparison_validates_public_snapshot_object_construction_too(): void
    {
        $valid = $this->makeSnapshot();
        $invalid = new DeliveryPromiseSnapshot(2, $valid->calendarVersion, $valid->receivedAt, $valid->plan, $valid->horizonDays, $valid->originalResult, $valid->calendarEvidence, $valid->runtimeMetadata);
        $this->expectException(InvalidArgumentException::class);

        Workday::compareDeliveryPromise($invalid);
    }

    private function profile(): array
    {
        return [
            'weekends' => ['Saturday', 'Sunday'],
            'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
            'custom_holidays' => [], 'extra_working_days' => [],
            'working_hours' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], [['09:00', '17:00']]),
            'cutoff' => ['timezone' => 'UTC', 'time' => '14:00'],
        ];
    }

    private function referencePlan(): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC', 'carrier' => 'UTC'], 'stages' => [
            ['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 120],
            ['id' => 'transit', 'type' => 'business_days', 'profile' => 'carrier', 'days' => 1],
            ['id' => 'finish', 'type' => 'work', 'profile' => 'carrier', 'minutes' => 60],
        ]];
    }

    private function workPlan(int $minutes, string $profile = 'warehouse'): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => [$profile => 'UTC'], 'stages' => [['id' => 'prepare', 'type' => 'work', 'profile' => $profile, 'minutes' => $minutes]]];
    }

    private function dayPlan(int $days): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => ['carrier' => 'UTC'], 'stages' => [['id' => 'transit', 'type' => 'business_days', 'profile' => 'carrier', 'days' => $days]]];
    }

    private function makeSnapshot(?array $plan = null, string $received = '2026-11-02T13:00:00Z', int $horizon = 7): DeliveryPromiseSnapshot
    {
        return Workday::captureDeliveryPromise($received, $plan ?? $this->referencePlan(), ['horizon_days' => $horizon]);
    }

    private function changedDay(DeliveryPromiseComparison $comparison, string $profile, string $date): array
    {
        $matches = array_values(array_filter($comparison->changedCalendarDays, static fn (array $day): bool => $day['profile'] === $profile && $day['date'] === $date));
        $this->assertCount(1, $matches);

        return $matches[0];
    }

    private function evidenceDay(DeliveryPromiseSnapshot $snapshot, string $profile, string $date): array
    {
        $days = $snapshot->toArray()['calendar_evidence']['profiles'][$profile]['days'];
        $matches = array_values(array_filter($days, static fn (array $day): bool => $day['date'] === $date));
        $this->assertCount(1, $matches);

        return $matches[0];
    }

    private function rehash(array $payload): array
    {
        $payload['calendar_version'] = CalendarFingerprint::hash($payload['calendar_evidence']);
        unset($payload['integrity_hash']);
        $payload['integrity_hash'] = CalendarFingerprint::integrity($payload);

        return $payload;
    }

    private function format(CarbonImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s.uP');
    }
}
