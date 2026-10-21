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
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\CutoffResult;
use Zarbinco\LaravelWorkdays\Data\Holiday;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Holidays\HolidayProviderInterface;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class CutoffPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('workdays.definitions.default_profile', 'warehouse');
        config()->set('workdays.driver', 'config');
        config()->set('workdays.definitions.include_start_date', false);
        config()->set('workdays.max_scan_days', 14);
        config()->set('workdays.definitions.profiles', ['warehouse' => $this->warehouseConfig()]);
    }

    #[DataProvider('acceptanceCases')]
    public function test_explicit_acceptance_fixture(string $receivedAt, bool $eligible, string $effectiveDate, string $reason): void
    {
        $result = Workday::profile('warehouse')->resolveCutoff($receivedAt);

        $this->assertSame($eligible, $result->sameDayEligible);
        $this->assertSame($effectiveDate, $result->effectiveBusinessDate->toDateString());
        $this->assertSame('00:00:00.000000', $result->effectiveBusinessDate->format('H:i:s.u'));
        $this->assertSame($reason, $result->reason);
    }

    public static function acceptanceCases(): array
    {
        return [
            'before default deadline' => ['2026-06-29 13:59:59', true, '2026-06-29', 'before_cutoff'],
            'default boundary' => ['2026-06-29 14:00:00', false, '2026-06-30', 'cutoff_reached'],
            'disabled future Wednesday' => ['2026-06-30 14:00:00', false, '2026-07-02', 'cutoff_reached'],
            'disabled current Wednesday' => ['2026-07-01 10:00:00', false, '2026-07-02', 'cutoff_disabled'],
            'before Thursday override' => ['2026-07-02 10:59:59', true, '2026-07-02', 'before_cutoff'],
            'Thursday boundary skips holiday' => ['2026-07-02 11:00:00', false, '2026-07-04', 'cutoff_reached'],
            'holiday before nominal cutoff' => ['2026-07-03 10:00:00', false, '2026-07-04', 'non_business_day'],
            'extra working Saturday' => ['2026-07-04 10:00:00', true, '2026-07-04', 'before_cutoff'],
        ];
    }

    #[DataProvider('microsecondBoundaries')]
    public function test_exclusive_cutoff_preserves_microsecond_precision(string $clock, bool $eligible): void
    {
        $result = Workday::resolveCutoff('2026-06-29 '.$clock);

        $this->assertSame($clock, $result->receivedAt->format('H:i:s.u'));
        $this->assertSame($eligible, $result->sameDayEligible);
        $this->assertSame($eligible ? '2026-06-29' : '2026-06-30', $result->effectiveBusinessDate->toDateString());
        $this->assertSame($eligible ? 'before_cutoff' : 'cutoff_reached', $result->reason);
        $this->assertSame('2026-06-29 14:00:00.000000', $result->cutoffAt?->format('Y-m-d H:i:s.u'));
    }

    public static function microsecondBoundaries(): array
    {
        return [
            ['13:59:59.999999', true],
            ['14:00:00.000000', false],
            ['14:00:00.000001', false],
            ['14:00:01.000000', false],
        ];
    }

    public function test_default_manager_facade_and_explicit_profile_route_consistently(): void
    {
        $receivedAt = '2026-06-29 10:00';
        $expected = Workday::profile('warehouse')->resolveCutoff($receivedAt)->toArray();

        $this->assertSame($expected, Workday::resolveCutoff($receivedAt)->toArray());
        $this->assertSame($expected, $this->app->make(WorkdayManager::class)->resolveCutoff($receivedAt)->toArray());
    }

    public function test_profiles_have_isolated_cutoff_policies(): void
    {
        config()->set('workdays.definitions.profiles.retail', array_replace($this->warehouseConfig(), [
            'cutoff' => ['timezone' => 'Asia/Tehran', 'time' => '12:00'],
        ]));

        $warehouse = Workday::resolveCutoff('2026-06-29T10:00:00Z');
        $retail = Workday::profile('retail')->resolveCutoff('2026-06-29T10:00:00Z');

        $this->assertTrue($warehouse->sameDayEligible);
        $this->assertSame('warehouse', $warehouse->profile);
        $this->assertSame('UTC', $warehouse->timezone);
        $this->assertFalse($retail->sameDayEligible);
        $this->assertSame('retail', $retail->profile);
        $this->assertSame('Asia/Tehran', $retail->timezone);
        $this->assertSame('2026-06-30', $retail->effectiveBusinessDate->toDateString());
    }

    public function test_result_types_and_serialization_include_offset_microseconds_boolean_and_input_date_cutoff(): void
    {
        $this->setPolicy(['timezone' => 'Asia/Tehran', 'time' => '14:00']);

        $result = Workday::resolveCutoff('2026-06-29T10:30:00.000001Z');

        $this->assertInstanceOf(CutoffResult::class, $result);
        $this->assertInstanceOf(CarbonImmutable::class, $result->receivedAt);
        $this->assertInstanceOf(CarbonImmutable::class, $result->cutoffAt);
        $this->assertInstanceOf(CarbonImmutable::class, $result->effectiveBusinessDate);
        $this->assertSame([
            'profile' => 'warehouse',
            'timezone' => 'Asia/Tehran',
            'received_at' => '2026-06-29T14:00:00.000001+03:30',
            'cutoff_at' => '2026-06-29T14:00:00.000000+03:30',
            'effective_business_date' => '2026-06-30',
            'same_day_eligible' => false,
            'reason' => 'cutoff_reached',
        ], $result->toArray());
    }

    public function test_result_is_immutable(): void
    {
        $result = Workday::resolveCutoff('2026-06-29 10:00');
        $result->receivedAt->addDay();

        $this->assertSame('2026-06-29', $result->receivedAt->toDateString());
        $this->expectException(Error::class);

        $result->reason = 'changed';
    }

    public function test_mutable_datetime_input_and_its_timezone_remain_unchanged(): void
    {
        $this->setPolicy(['timezone' => 'Asia/Tehran', 'time' => '14:00']);
        $input = new DateTime('2026-06-29 10:29:59.123456', new DateTimeZone('UTC'));
        $before = $input->format('Y-m-d H:i:s.u e');

        $result = Workday::resolveCutoff($input);

        $this->assertSame($before, $input->format('Y-m-d H:i:s.u e'));
        $this->assertSame('2026-06-29 13:59:59.123456 Asia/Tehran', $result->receivedAt->format('Y-m-d H:i:s.u e'));
        $this->assertTrue($result->sameDayEligible);
    }

    public function test_disabled_cutoff_is_null_in_result_and_serialization(): void
    {
        $result = Workday::resolveCutoff('2026-07-01 10:00');

        $this->assertNull($result->cutoffAt);
        $this->assertNull($result->toArray()['cutoff_at']);
        $this->assertFalse($result->toArray()['same_day_eligible']);
        $this->assertTrue(Workday::isBusinessDay('2026-07-01'));
    }

    public function test_non_business_day_reason_precedes_disabled_cutoff(): void
    {
        $this->setPolicy(['timezone' => 'UTC', 'time' => '14:00', 'weekdays' => ['Friday' => null]]);

        $result = Workday::resolveCutoff('2026-07-03 10:00');

        $this->assertSame('non_business_day', $result->reason);
        $this->assertNull($result->cutoffAt);
        $this->assertSame('2026-07-04', $result->effectiveBusinessDate->toDateString());
    }

    public function test_holiday_nominal_cutoff_remains_diagnostic_for_input_date(): void
    {
        $result = Workday::resolveCutoff('2026-07-03 10:00');

        $this->assertFalse($result->sameDayEligible);
        $this->assertSame('2026-07-03 14:00:00', $result->cutoffAt?->toDateTimeString());
        $this->assertSame('2026-07-04', $result->effectiveBusinessDate->toDateString());
    }

    public function test_weekend_without_extra_working_day_rolls_forward(): void
    {
        $result = Workday::resolveCutoff('2026-07-05 10:00');

        $this->assertSame('non_business_day', $result->reason);
        $this->assertSame('2026-07-06', $result->effectiveBusinessDate->toDateString());
    }

    public function test_extra_working_day_reopens_custom_holiday(): void
    {
        $this->setProfile([
            'custom_holidays' => ['2026-06-29' => 'Company closure'],
            'extra_working_days' => ['2026-06-29' => 'Reopened'],
        ]);

        $result = Workday::resolveCutoff('2026-06-29 10:00');

        $this->assertTrue($result->sameDayEligible);
        $this->assertSame('before_cutoff', $result->reason);
    }

    public function test_extra_working_day_cannot_override_disabled_cutoff(): void
    {
        $this->setProfile([
            'custom_holidays' => ['2026-07-01' => 'Company closure'],
            'extra_working_days' => ['2026-07-01' => 'Reopened'],
        ]);

        $this->assertTrue(Workday::isBusinessDay('2026-07-01'));
        $result = Workday::resolveCutoff('2026-07-01 10:00');

        $this->assertFalse($result->sameDayEligible);
        $this->assertSame('cutoff_disabled', $result->reason);
        $this->assertSame('2026-07-02', $result->effectiveBusinessDate->toDateString());
    }

    public function test_optional_weekday_overrides_default_to_empty_and_inherit_default_time(): void
    {
        $this->setPolicy(['timezone' => 'UTC', 'time' => '14:00']);

        $result = Workday::resolveCutoff('2026-07-01 13:59');

        $this->assertTrue($result->sameDayEligible);
        $this->assertSame('14:00', $result->cutoffAt?->format('H:i'));
    }

    #[DataProvider('weekdayAliases')]
    public function test_weekday_overrides_use_existing_normalizer(int|string $weekday): void
    {
        $this->setPolicy(['timezone' => 'UTC', 'time' => '14:00', 'weekdays' => [$weekday => '11:00']]);

        $result = Workday::resolveCutoff('2026-07-02 11:00');

        $this->assertFalse($result->sameDayEligible);
        $this->assertSame('cutoff_reached', $result->reason);
        $this->assertSame('11:00', $result->cutoffAt?->format('H:i'));
    }

    public static function weekdayAliases(): array
    {
        return [[4], ['Thursday'], ['thu'], ['پنج‌شنبه']];
    }

    public function test_midnight_has_no_window_and_is_skipped_during_future_search(): void
    {
        $this->setPolicy(['timezone' => 'UTC', 'time' => '00:00', 'weekdays' => ['Tuesday' => '14:00']]);

        $atMidnight = Workday::resolveCutoff('2026-06-29 00:00');
        $fromSunday = Workday::resolveCutoff('2026-06-28 10:00');

        $this->assertFalse($atMidnight->sameDayEligible);
        $this->assertSame('cutoff_reached', $atMidnight->reason);
        $this->assertSame('00:00:00.000000', $atMidnight->cutoffAt?->format('H:i:s.u'));
        $this->assertSame('2026-06-30', $atMidnight->effectiveBusinessDate->toDateString());
        $this->assertSame('2026-06-30', $fromSunday->effectiveBusinessDate->toDateString());
    }

    public function test_midnight_has_no_window_even_when_timezone_repeats_midnight(): void
    {
        $this->setProfile([
            'weekends' => [],
            'cutoff' => ['timezone' => 'Antarctica/Casey', 'time' => '00:00', 'weekdays' => ['Friday' => '14:00']],
        ]);
        config()->set('workdays.max_scan_days', 1);

        $result = Workday::resolveCutoff('2023-03-09T00:30:00+11:00');

        $this->assertFalse($result->sameDayEligible);
        $this->assertSame('cutoff_reached', $result->reason);
        $this->assertSame('2023-03-10', $result->effectiveBusinessDate->toDateString());
        $this->assertSame('00:00:00.000000', $result->cutoffAt?->format('H:i:s.u'));
    }

    #[DataProvider('unavailablePolicies')]
    public function test_no_eligible_future_date_throws_instead_of_returning_fallback(array $policy): void
    {
        $this->setPolicy($policy);
        config()->set('workdays.max_scan_days', 3);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('warehouse');

        Workday::resolveCutoff('2026-06-29 15:00');
    }

    public static function unavailablePolicies(): array
    {
        return [
            'all disabled' => [['timezone' => 'UTC', 'time' => '14:00', 'weekdays' => array_fill_keys(range(1, 7), null)]],
            'all midnight' => [['timezone' => 'UTC', 'time' => '00:00']],
        ];
    }

    public function test_include_start_date_cannot_change_current_or_strictly_later_assignment(): void
    {
        foreach (['2026-06-29 10:00', '2026-06-30 14:00'] as $receivedAt) {
            config()->set('workdays.definitions.include_start_date', false);
            $excludingStart = Workday::resolveCutoff($receivedAt)->toArray();
            config()->set('workdays.definitions.include_start_date', true);

            $this->assertSame($excludingStart, Workday::resolveCutoff($receivedAt)->toArray());
        }
    }

    public function test_selected_provider_is_reused_and_date_exactly_at_scan_limit_succeeds(): void
    {
        $provider = new CutoffRecordingHolidayProvider('2026-07-01');
        $calculator = new BusinessDayCalculator('warehouse', $this->plainProfileConfig(), maxScanDays: 2, holidayProvider: $provider, includeStartDate: false, jalaliCalendar: new JalaliCalendarAdapter, hijriCalendar: new HijriCalendarAdapter('umm_al_qura', 0));

        $result = $calculator->resolveCutoff('2026-06-29 10:00');

        $this->assertSame('2026-07-01', $result->effectiveBusinessDate->toDateString());
        $this->assertSame('non_business_day', $result->reason);
        $this->assertSame(['2026-06-29', '2026-06-30', '2026-07-01'], array_values(array_unique($provider->examinedDates)));
    }

    public function test_scan_limit_one_examines_tomorrow_but_never_the_following_date(): void
    {
        $provider = new CutoffRecordingHolidayProvider('2026-07-01');
        $calculator = new BusinessDayCalculator('warehouse', $this->plainProfileConfig(), maxScanDays: 1, holidayProvider: $provider, includeStartDate: false, jalaliCalendar: new JalaliCalendarAdapter, hijriCalendar: new HijriCalendarAdapter('umm_al_qura', 0));

        try {
            $calculator->resolveCutoff('2026-06-29 10:00');
            $this->fail('Expected failure because the first eligible date exceeds the scan limit.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('warehouse', $exception->getMessage());
            $this->assertStringContainsString('1', $exception->getMessage());
        }

        $this->assertSame(['2026-06-29', '2026-06-30'], array_values(array_unique($provider->examinedDates)));
    }

    #[DataProvider('workingHoursCases')]
    public function test_cutoff_is_independent_of_working_hours(array $overrides, string $receivedAt): void
    {
        $this->setProfile($overrides);

        $result = Workday::resolveCutoff($receivedAt);

        $this->assertTrue($result->sameDayEligible);
        $this->assertSame('before_cutoff', $result->reason);
        $this->assertSame('14:00', $result->cutoffAt?->format('H:i'));
    }

    public static function workingHoursCases(): array
    {
        return [
            'no working hours' => [[], '2026-06-29 10:00'],
            'before opening' => [['working_hours' => ['Monday' => [['09:00', '17:00']]]], '2026-06-29 08:00'],
            'split shift break' => [['working_hours' => ['Monday' => [['09:00', '12:00'], ['13:00', '17:00']]]], '2026-06-29 12:30'],
            'empty weekday windows' => [['working_hours' => ['Monday' => []]], '2026-06-29 10:00'],
            'after closing before cutoff' => [['working_hours' => ['Monday' => [['09:00', '12:00']]]], '2026-06-29 13:00'],
        ];
    }

    public function test_missing_cutoff_policy_reports_configuration_error_only_on_new_api(): void
    {
        $profile = $this->warehouseConfig();
        unset($profile['cutoff']);
        config()->set('workdays.definitions.profiles.warehouse', $profile);
        $this->assertTrue(Workday::isBusinessDay('2026-06-29'));
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('warehouse');

        Workday::resolveCutoff('2026-06-29 10:00');
    }

    #[DataProvider('invalidPolicies')]
    public function test_malformed_policy_is_rejected_with_profile_context(mixed $policy): void
    {
        $this->setProfile(['cutoff' => $policy]);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('warehouse');

        Workday::resolveCutoff('2026-06-29 10:00');
    }

    public static function invalidPolicies(): array
    {
        $valid = ['timezone' => 'UTC', 'time' => '14:00'];

        return [
            'null policy' => [null],
            'string policy' => ['14:00'],
            'boolean policy' => [false],
            'empty policy' => [[]],
            'missing timezone' => [['time' => '14:00']],
            'null timezone' => [array_replace($valid, ['timezone' => null])],
            'non-string timezone' => [array_replace($valid, ['timezone' => 3])],
            'empty timezone' => [array_replace($valid, ['timezone' => ''])],
            'invalid timezone' => [array_replace($valid, ['timezone' => 'Mars/Olympus'])],
            'offset instead of identifier' => [array_replace($valid, ['timezone' => '+03:30'])],
            'missing time' => [['timezone' => 'UTC']],
            'null time' => [array_replace($valid, ['time' => null])],
            'numeric time' => [array_replace($valid, ['time' => 1400])],
            'non-padded time' => [array_replace($valid, ['time' => '9:00'])],
            'hour overflow' => [array_replace($valid, ['time' => '24:00'])],
            'minute overflow' => [array_replace($valid, ['time' => '14:60'])],
            'seconds in policy time' => [array_replace($valid, ['time' => '14:00:00'])],
            'whitespace in policy time' => [array_replace($valid, ['time' => ' 14:00'])],
            'null weekdays' => [$valid + ['weekdays' => null]],
            'non-array weekdays' => [$valid + ['weekdays' => 'Thursday']],
            'invalid weekday name' => [$valid + ['weekdays' => ['Funday' => '11:00']]],
            'zero ISO weekday' => [$valid + ['weekdays' => [0 => '11:00']]],
            'out-of-range ISO weekday' => [$valid + ['weekdays' => [8 => '11:00']]],
            'duplicate weekday names' => [$valid + ['weekdays' => ['Thursday' => '11:00', 'thu' => '12:00']]],
            'duplicate integer and name' => [$valid + ['weekdays' => [4 => '11:00', 'Thursday' => null]]],
            'boolean override' => [$valid + ['weekdays' => ['Thursday' => false]]],
            'numeric override' => [$valid + ['weekdays' => ['Thursday' => 1100]]],
            'malformed override' => [$valid + ['weekdays' => ['Thursday' => '11:60']]],
        ];
    }

    #[DataProvider('legacyPolicies')]
    public function test_legacy_day_time_explain_and_calculate_apis_ignore_unconfigured_or_malformed_cutoff(bool $hasCutoff, mixed $policy): void
    {
        $profile = $this->warehouseConfig();
        $profile['working_hours'] = ['Monday' => [['09:00', '17:00']], 'Tuesday' => [['09:00', '17:00']]];
        if ($hasCutoff) {
            $profile['cutoff'] = $policy;
        } else {
            unset($profile['cutoff']);
        }
        config()->set('workdays.definitions.profiles.warehouse', $profile);

        $calculator = Workday::profile('warehouse');

        $this->assertTrue($calculator->isBusinessDay('2026-06-29'));
        $this->assertSame('2026-06-30', $calculator->addBusinessDays('2026-06-29', 1)->toDateString());
        $this->assertTrue($calculator->explain('2026-06-29')->isBusinessDay);
        $this->assertSame('2026-06-30', $calculator->calculate('2026-06-29', 1)->resultDate->toDateString());
        $this->assertTrue($calculator->isBusinessTime('2026-06-29 10:00'));
        $this->assertSame('2026-06-29 11:00:00', $calculator->addBusinessMinutes('2026-06-29 10:00', 60)->toDateTimeString());
        $this->assertSame(60, $calculator->diffBusinessMinutes('2026-06-29 10:00', '2026-06-29 11:00'));
        $this->expectException(WorkdayConfigurationException::class);

        $calculator->resolveCutoff('2026-06-29 10:00');
    }

    public static function legacyPolicies(): array
    {
        return [
            'absent' => [false, null],
            'malformed scalar' => [true, 'invalid'],
            'malformed timezone and weekday' => [true, ['timezone' => 'Mars/Olympus', 'time' => 'broken', 'weekdays' => 'invalid']],
        ];
    }

    #[DataProvider('supportedTimestamps')]
    public function test_supported_absolute_input_formats(string $input, string $normalized): void
    {
        $result = Workday::resolveCutoff($input);

        $this->assertSame($normalized, $result->receivedAt->format('Y-m-d\TH:i:s.uP'));
        $this->assertTrue($result->sameDayEligible);
    }

    public static function supportedTimestamps(): array
    {
        return [
            ['2026-06-29 10:00', '2026-06-29T10:00:00.000000+00:00'],
            ['2026-06-29 10:00:59', '2026-06-29T10:00:59.000000+00:00'],
            ['2026-06-29 10:00:59.1', '2026-06-29T10:00:59.100000+00:00'],
            ['2026-06-29 10:00:59.123456', '2026-06-29T10:00:59.123456+00:00'],
            ['2026-06-29T10:00Z', '2026-06-29T10:00:00.000000+00:00'],
            ['2026-06-29T10:00:59Z', '2026-06-29T10:00:59.000000+00:00'],
            ['2026-06-29T13:30:59.123456+03:30', '2026-06-29T10:00:59.123456+00:00'],
        ];
    }

    public function test_equivalent_explicit_offset_instants_produce_identical_decisions(): void
    {
        $expected = Workday::resolveCutoff('2026-06-29T13:59:59.123456Z')->toArray();

        $this->assertSame($expected, Workday::resolveCutoff('2026-06-29T17:29:59.123456+03:30')->toArray());
        $this->assertSame($expected, Workday::resolveCutoff('2026-06-29T09:59:59.123456-04:00')->toArray());
    }

    public function test_offset_conversion_determines_local_date_before_calendar_and_cutoff(): void
    {
        $this->setPolicy(['timezone' => 'Asia/Tehran', 'time' => '14:00']);

        $result = Workday::resolveCutoff('2026-06-29T21:00:00Z');

        $this->assertSame('2026-06-30 00:30:00', $result->receivedAt->toDateTimeString());
        $this->assertSame('2026-06-30', $result->effectiveBusinessDate->toDateString());
        $this->assertSame('2026-06-30 14:00:00', $result->cutoffAt?->toDateTimeString());
        $this->assertTrue($result->sameDayEligible);
    }

    public function test_naive_timestamp_uses_policy_timezone_when_application_and_php_timezone_differ(): void
    {
        $this->setPolicy(['timezone' => 'Asia/Tehran', 'time' => '14:00']);
        config()->set('app.timezone', 'Pacific/Honolulu');
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Honolulu');

        try {
            $result = Workday::resolveCutoff('2026-06-29 13:59:59.999999');

            $this->assertSame('2026-06-29T13:59:59.999999+03:30', $result->toArray()['received_at']);
            $this->assertTrue($result->sameDayEligible);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[DataProvider('invalidTimestamps')]
    public function test_invalid_relative_partial_or_ambiguous_timestamp_is_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Workday::resolveCutoff($input);
    }

    public static function invalidTimestamps(): array
    {
        return [
            [''], ['   '], ['2026-06-29'], ['now'], ['tomorrow'], ['next Monday'],
            ['2026-02-30 10:00'], ['2025-02-29 10:00'], ['2026-13-01 10:00'],
            ['2026-06-29 24:00'], ['2026-06-29 10:60'], ['2026-06-29 10:00:60'],
            ['2026-06-29 9:00'], ['2026-06-29 10'], ['06/29/2026 10:00'],
            ['2026-06-29T10:00:00+24:00'], ['2026-06-29T10:00:00+03:60'],
            ['2026-06-29T10:00:00+3:30'], ['2026-06-29 10:00:00.1234567'],
        ];
    }

    #[DataProvider('daylightSavingTransitions')]
    public function test_future_assignment_advances_local_calendar_days_across_dst(string $receivedAt, string $expectedMidnight, string $expectedCutoff): void
    {
        $this->setProfile([
            'weekends' => [],
            'cutoff' => ['timezone' => 'America/New_York', 'time' => '14:00'],
        ]);
        config()->set('workdays.max_scan_days', 1);

        $result = Workday::resolveCutoff($receivedAt);

        $this->assertSame($expectedMidnight, $result->effectiveBusinessDate->format('Y-m-d\TH:i:sP'));
        $this->assertSame($expectedCutoff, $result->cutoffAt?->format('Y-m-d\TH:i:sP'));
        $this->assertSame('cutoff_reached', $result->reason);
    }

    public static function daylightSavingTransitions(): array
    {
        return [
            'spring forward' => ['2026-03-08T14:00:00-04:00', '2026-03-09T00:00:00-04:00', '2026-03-08T14:00:00-04:00'],
            'fall back' => ['2026-11-01T14:00:00-05:00', '2026-11-02T00:00:00-05:00', '2026-11-01T14:00:00-05:00'],
        ];
    }

    public function test_midnight_transition_uses_the_start_of_each_selected_calendar_date(): void
    {
        $this->setProfile([
            'weekends' => [],
            'cutoff' => ['timezone' => 'America/Santiago', 'time' => '14:00'],
        ]);
        config()->set('workdays.max_scan_days', 1);

        $transitionDate = Workday::resolveCutoff('2026-09-05 14:00');
        $followingDate = Workday::resolveCutoff('2026-09-06 14:00');

        $this->assertSame('2026-09-06T01:00:00-03:00', $transitionDate->effectiveBusinessDate->format('Y-m-d\TH:i:sP'));
        $this->assertSame('2026-09-07T00:00:00-03:00', $followingDate->effectiveBusinessDate->format('Y-m-d\TH:i:sP'));
        $this->assertSame('2026-09-06T14:00:00-03:00', $followingDate->cutoffAt?->format('Y-m-d\TH:i:sP'));
    }

    public function test_future_cutoff_window_skips_a_transition_date_whose_start_equals_cutoff(): void
    {
        $this->setProfile(array_replace($this->plainProfileConfig(), [
            'cutoff' => ['timezone' => 'America/Santiago', 'time' => '01:00'],
        ]));
        config()->set('workdays.max_scan_days', 2);

        $result = Workday::resolveCutoff('2026-09-05 01:00');

        $this->assertSame('2026-09-07T00:00:00-03:00', $result->effectiveBusinessDate->format('Y-m-d\TH:i:sP'));
        $this->assertFalse($result->sameDayEligible);
        $this->assertSame('cutoff_reached', $result->reason);
        $this->assertSame('2026-09-05T01:00:00-04:00', $result->cutoffAt?->format('Y-m-d\TH:i:sP'));
    }

    public function test_future_cutoff_window_cannot_extend_one_day_budget_past_empty_transition_date(): void
    {
        $this->setProfile(array_replace($this->plainProfileConfig(), [
            'cutoff' => ['timezone' => 'America/Santiago', 'time' => '01:00'],
        ]));
        config()->set('workdays.max_scan_days', 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('within [1] future calendar days for profile [warehouse]');

        Workday::resolveCutoff('2026-09-05 01:00');
    }

    public function test_future_cutoff_window_accepts_a_positive_minute_after_skipped_midnight(): void
    {
        $this->setProfile(array_replace($this->plainProfileConfig(), [
            'cutoff' => ['timezone' => 'America/Santiago', 'time' => '01:01'],
        ]));
        config()->set('workdays.max_scan_days', 1);

        $result = Workday::resolveCutoff('2026-09-05 01:01');

        $this->assertSame('2026-09-06T01:00:00-03:00', $result->effectiveBusinessDate->format('Y-m-d\TH:i:sP'));
        $this->assertFalse($result->sameDayEligible);
        $this->assertSame('cutoff_reached', $result->reason);
    }

    public function test_skipped_civil_date_cannot_allow_a_candidate_beyond_scan_limit(): void
    {
        $provider = new CutoffRecordingHolidayProvider('2011-12-01');
        $profile = array_replace($this->plainProfileConfig(), [
            'cutoff' => ['timezone' => 'Pacific/Apia', 'time' => '14:00'],
        ]);
        $calculator = new BusinessDayCalculator('warehouse', $profile, maxScanDays: 1, holidayProvider: $provider, includeStartDate: false, jalaliCalendar: new JalaliCalendarAdapter, hijriCalendar: new HijriCalendarAdapter('umm_al_qura', 0));

        try {
            $calculator->resolveCutoff('2011-12-29 14:00');
            $this->fail('The skipped December 30 must consume the single available calendar-date search slot.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('warehouse', $exception->getMessage());
        }

        $this->assertSame(['2011-12-29'], array_values(array_unique($provider->examinedDates)));
    }

    public function test_skipped_civil_date_allows_next_real_date_exactly_at_limit_two(): void
    {
        $this->setProfile([
            'weekends' => [],
            'cutoff' => ['timezone' => 'Pacific/Apia', 'time' => '14:00'],
        ]);
        config()->set('workdays.max_scan_days', 2);

        $result = Workday::resolveCutoff('2011-12-29 14:00');

        $this->assertSame('2011-12-31T00:00:00+14:00', $result->effectiveBusinessDate->format('Y-m-d\TH:i:sP'));
        $this->assertSame('2011-12-29T14:00:00-10:00', $result->cutoffAt?->format('Y-m-d\TH:i:sP'));
        $this->assertSame('cutoff_reached', $result->reason);
    }

    public function test_nonexistent_cutoff_wall_time_is_a_configuration_error(): void
    {
        $this->setProfile(['weekends' => [], 'cutoff' => ['timezone' => 'America/New_York', 'time' => '02:30']]);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('warehouse');

        Workday::resolveCutoff('2026-03-08 00:30');
    }

    public function test_nonexistent_naive_input_wall_time_is_rejected(): void
    {
        $this->setPolicy(['timezone' => 'America/New_York', 'time' => '14:00']);
        $this->expectException(InvalidArgumentException::class);

        Workday::resolveCutoff('2026-03-08 02:30');
    }

    public function test_source_defined_holiday_affects_cutoff_assignment(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays.2026-06-29', 'Warehouse closure');

        $result = Workday::resolveCutoff('2026-06-29 10:00');

        $this->assertSame('non_business_day', $result->reason);
        $this->assertSame('2026-06-30', $result->effectiveBusinessDate->toDateString());
    }

    public function test_single_source_extra_working_day_preserves_holiday_override_precedence(): void
    {
        $this->setProfile(['custom_holidays' => ['2026-06-29' => 'Config closure']]);
        config()->set('workdays.definitions.profiles.warehouse.extra_working_days.2026-06-29', 'Source reopening');

        $result = Workday::resolveCutoff('2026-06-29 10:00');

        $this->assertTrue($result->sameDayEligible);
        $this->assertSame('before_cutoff', $result->reason);
        $this->assertSame('2026-06-29', $result->effectiveBusinessDate->toDateString());
    }

    private function warehouseConfig(): array
    {
        return [
            'weekends' => ['Saturday', 'Sunday'],
            'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
            'custom_holidays' => ['2026-07-03' => 'Company holiday'],
            'extra_working_days' => ['2026-07-04' => 'Extra working Saturday'],
            'cutoff' => [
                'timezone' => 'UTC',
                'time' => '14:00',
                'weekdays' => ['Wednesday' => null, 'Thursday' => '11:00'],
            ],
        ];
    }

    private function plainProfileConfig(): array
    {
        return array_replace($this->warehouseConfig(), [
            'weekends' => [],
            'custom_holidays' => [],
            'extra_working_days' => [],
            'cutoff' => ['timezone' => 'UTC', 'time' => '14:00'],
        ]);
    }

    private function setProfile(array $overrides): void
    {
        config()->set('workdays.definitions.profiles.warehouse', array_replace($this->warehouseConfig(), $overrides));
    }

    private function setPolicy(array $policy): void
    {
        $this->setProfile(['cutoff' => $policy]);
    }
}

final class CutoffRecordingHolidayProvider implements HolidayProviderInterface
{
    public array $examinedDates = [];

    public function __construct(private readonly string $firstBusinessDate) {}

    public function recurringHolidays(string $profile, string $calendar): array
    {
        return [];
    }

    public function customHoliday(string $profile, CarbonImmutable $date): ?Holiday
    {
        return $date->toDateString() < $this->firstBusinessDate ? new Holiday($date, 'Closed before fixture business date') : null;
    }

    public function hasCustomHoliday(string $profile, CarbonImmutable $date): bool
    {
        return $this->customHoliday($profile, $date) !== null;
    }

    public function extraWorkingDay(string $profile, CarbonImmutable $date): ?Holiday
    {
        return null;
    }

    public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool
    {
        $this->examinedDates[] = $date->toDateString();

        return false;
    }
}
