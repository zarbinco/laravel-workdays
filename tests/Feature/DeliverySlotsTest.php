<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Data\DeliverySlot;
use Zarbinco\LaravelWorkdays\Data\DeliverySlotsResult;
use Zarbinco\LaravelWorkdays\Data\Holiday;
use Zarbinco\LaravelWorkdays\Delivery\DeliverySlotGenerator;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Holidays\ConfigHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\HolidayProviderInterface;
use Zarbinco\LaravelWorkdays\Models\WorkdayHolidayRule;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class DeliverySlotsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Pacific/Honolulu');
        config()->set('workdays.default_profile', 'last_mile');
        config()->set('workdays.storage.driver', 'config');
        config()->set('workdays.max_scan_days', 30);
        config()->set('workdays.profiles', [
            'last_mile' => $this->profile($this->hours([['09:00', '12:00'], ['13:00', '17:00']])),
            'warehouse' => $this->profile($this->hours([['09:00', '17:00']])) + [
                'cutoff' => ['timezone' => 'Asia/Tehran', 'time' => '14:00'],
            ],
        ]);
    }

    public function test_a_split_windows_produce_the_complete_expected_ordered_list(): void
    {
        $result = Workday::deliverySlots('2026-06-29T10:30:00Z', $this->slotOptions());

        $this->assertSame([
            ['2026-06-29 13:00', '2026-06-29 15:00'],
            ['2026-06-29 14:00', '2026-06-29 16:00'],
            ['2026-06-29 15:00', '2026-06-29 17:00'],
            ['2026-06-30 09:00', '2026-06-30 11:00'],
            ['2026-06-30 10:00', '2026-06-30 12:00'],
            ['2026-06-30 13:00', '2026-06-30 15:00'],
            ['2026-06-30 14:00', '2026-06-30 16:00'],
            ['2026-06-30 15:00', '2026-06-30 17:00'],
        ], $this->intervals($result));
        $this->assertCount(8, array_unique(array_map(static fn (DeliverySlot $slot): string => $slot->startsAt->format('U.u').'/'.$slot->endsAt->format('U.u'), $result->slots)));

        foreach ($result->slots as $slot) {
            $this->assertTrue($slot->startsAt->greaterThanOrEqualTo($result->eligibleFromAt));
            $this->assertSame(7200, $slot->endsAt->getTimestamp() - $slot->startsAt->getTimestamp());
        }
    }

    public function test_b_notice_is_elapsed_once_and_filters_an_unchanged_grid(): void
    {
        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['min_notice_minutes' => 90]));

        $this->assertSame('2026-06-29T09:30:00.000000+00:00', $this->format($result->eligibleFromAt));
        $this->assertSame(['2026-06-29 10:00', '2026-06-29 12:00'], $this->intervals($result)[0]);
        $this->assertSame(['2026-06-30 09:00', '2026-06-30 11:00'], $this->intervals($result)[4]);
        $this->assertCount(9, $result->slots);
    }

    #[DataProvider('exactThresholds')]
    public function test_c_thresholds_and_half_open_window_boundaries(string $readyAt, array $expected): void
    {
        $result = Workday::deliverySlots($readyAt, $this->slotOptions(['days' => 1]));

        $this->assertSame($expected, $this->intervals($result));
    }

    public static function exactThresholds(): array
    {
        return [
            'opening is included' => ['2026-06-29T09:00:00Z', [
                ['2026-06-29 09:00', '2026-06-29 11:00'], ['2026-06-29 10:00', '2026-06-29 12:00'],
                ['2026-06-29 13:00', '2026-06-29 15:00'], ['2026-06-29 14:00', '2026-06-29 16:00'], ['2026-06-29 15:00', '2026-06-29 17:00'],
            ]],
            'one microsecond after opening' => ['2026-06-29T09:00:00.000001Z', [
                ['2026-06-29 10:00', '2026-06-29 12:00'], ['2026-06-29 13:00', '2026-06-29 15:00'],
                ['2026-06-29 14:00', '2026-06-29 16:00'], ['2026-06-29 15:00', '2026-06-29 17:00'],
            ]],
            'lunch begins at noon' => ['2026-06-29T12:00:00Z', [
                ['2026-06-29 13:00', '2026-06-29 15:00'], ['2026-06-29 14:00', '2026-06-29 16:00'], ['2026-06-29 15:00', '2026-06-29 17:00'],
            ]],
            'exact afternoon start' => ['2026-06-29T13:00:00Z', [
                ['2026-06-29 13:00', '2026-06-29 15:00'], ['2026-06-29 14:00', '2026-06-29 16:00'], ['2026-06-29 15:00', '2026-06-29 17:00'],
            ]],
            'end exactly at closing' => ['2026-06-29T15:00:00Z', [['2026-06-29 15:00', '2026-06-29 17:00']]],
            'one microsecond misses final start' => ['2026-06-29T15:00:00.000001Z', []],
            'no two hour slot remains at sixteen' => ['2026-06-29T16:00:00Z', []],
            'closing cannot start a slot' => ['2026-06-29T17:00:00Z', []],
        ];
    }

    public function test_d_holiday_and_weekend_are_skipped_within_calendar_date_horizon(): void
    {
        config()->set('workdays.profiles.last_mile.custom_holidays', ['2026-07-03' => 'Fixture Friday closure']);

        $result = Workday::deliverySlots('2026-07-02T17:01:00Z', $this->slotOptions(['days' => 5]));

        $this->assertSame([
            ['2026-07-06 09:00', '2026-07-06 11:00'], ['2026-07-06 10:00', '2026-07-06 12:00'],
            ['2026-07-06 13:00', '2026-07-06 15:00'], ['2026-07-06 14:00', '2026-07-06 16:00'], ['2026-07-06 15:00', '2026-07-06 17:00'],
        ], $this->intervals($result));
    }

    public function test_e_half_day_has_only_the_two_morning_slots(): void
    {
        config()->set('workdays.profiles.last_mile.working_hours.Thursday', [['09:00', '12:00']]);

        $result = Workday::deliverySlots('2026-07-02T08:00:00Z', $this->slotOptions(['days' => 1]));

        $this->assertSame([['2026-07-02 09:00', '2026-07-02 11:00'], ['2026-07-02 10:00', '2026-07-02 12:00']], $this->intervals($result));
    }

    public function test_f_extra_working_day_reopens_a_holiday_with_existing_fallback_hours(): void
    {
        config()->set('workdays.profiles.last_mile.custom_holidays', ['2026-07-04' => 'Configured closure']);
        config()->set('workdays.profiles.last_mile.extra_working_days', ['2026-07-04' => 'Extra Saturday']);
        config()->set('workdays.profiles.last_mile.extra_working_day_hours', [['10:00', '14:00']]);

        $result = Workday::deliverySlots('2026-07-04T08:00:00Z', $this->slotOptions(['days' => 1]));

        $this->assertSame([
            ['2026-07-04 10:00', '2026-07-04 12:00'], ['2026-07-04 11:00', '2026-07-04 13:00'], ['2026-07-04 12:00', '2026-07-04 14:00'],
        ], $this->intervals($result));
    }

    public function test_f_extra_working_day_without_any_hours_preserves_the_configuration_error(): void
    {
        config()->set('workdays.profiles.last_mile.extra_working_days', ['2026-07-04' => 'Extra Saturday']);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('extra_working_day_hours');

        Workday::deliverySlots('2026-07-04T08:00:00Z', $this->slotOptions(['days' => 1]));
    }

    public function test_extra_working_day_prefers_configured_weekday_hours_over_fallback(): void
    {
        config()->set('workdays.profiles.last_mile.extra_working_days', ['2026-07-04' => 'Extra Saturday']);
        config()->set('workdays.profiles.last_mile.working_hours.Saturday', [['09:00', '11:00']]);
        config()->set('workdays.profiles.last_mile.extra_working_day_hours', [['10:00', '14:00']]);

        $result = Workday::deliverySlots('2026-07-04T08:00:00Z', $this->slotOptions(['days' => 1]));

        $this->assertSame([['2026-07-04 09:00', '2026-07-04 11:00']], $this->intervals($result));
    }

    public function test_g_short_windows_produce_a_valid_empty_result(): void
    {
        config()->set('workdays.profiles.last_mile.working_hours', $this->hours([['09:00', '10:00']]));

        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 1]));

        $this->assertSame([], $result->slots);
        $this->assertSame([
            'profile' => 'last_mile', 'timezone' => 'UTC',
            'ready_at' => '2026-06-29T08:00:00.000000+00:00',
            'eligible_from_at' => '2026-06-29T08:00:00.000000+00:00',
            'requested_days' => 1, 'slots' => [],
        ], $result->toArray());
    }

    public function test_touching_windows_do_not_allow_a_slot_to_span_two_windows(): void
    {
        config()->set('workdays.profiles.last_mile.working_hours', $this->hours([['09:00', '10:00'], ['10:00', '11:00']]));

        $this->assertSame([], Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 1]))->slots);
    }

    public function test_grid_is_anchored_to_each_non_round_window_opening(): void
    {
        config()->set('workdays.profiles.last_mile.working_hours', $this->hours([['09:10', '11:00'], ['13:25', '16:00']]));
        $options = $this->slotOptions(['days' => 1, 'duration_minutes' => 60, 'step_minutes' => 45]);

        $first = Workday::deliverySlots('2026-06-29T09:12:01Z', $options);
        $second = Workday::deliverySlots('2026-06-29T09:12:59.123456Z', $options);

        $this->assertSame([
            ['2026-06-29 09:55', '2026-06-29 10:55'], ['2026-06-29 13:25', '2026-06-29 14:25'],
            ['2026-06-29 14:10', '2026-06-29 15:10'], ['2026-06-29 14:55', '2026-06-29 15:55'],
        ], $this->intervals($first));
        $this->assertSame($this->intervals($first), $this->intervals($second));
    }

    public function test_step_may_exceed_duration_and_need_not_divide_window_length(): void
    {
        config()->set('workdays.profiles.last_mile.working_hours', $this->hours([['09:00', '12:00']]));

        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 1, 'duration_minutes' => 30, 'step_minutes' => 100]));

        $this->assertSame([['2026-06-29 09:00', '2026-06-29 09:30'], ['2026-06-29 10:40', '2026-06-29 11:10']], $this->intervals($result));
    }

    public function test_notice_preserves_seconds_and_microseconds_without_rounding_backwards(): void
    {
        $result = Workday::deliverySlots('2026-06-29T08:00:00.000001Z', $this->slotOptions(['days' => 1, 'min_notice_minutes' => 60]));

        $this->assertSame('2026-06-29T09:00:00.000001+00:00', $this->format($result->eligibleFromAt));
        $this->assertSame('2026-06-29 10:00', $result->slots[0]->startsAt->format('Y-m-d H:i'));
    }

    public function test_optional_defaults_are_applied_without_requiring_cutoff_configuration(): void
    {
        $options = $this->slotOptions(['days' => 31]);
        unset($options['min_notice_minutes'], $options['max_slots']);

        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $options);

        $this->assertCount(50, $result->slots);
        $this->assertSame($this->format($result->readyAt), $this->format($result->eligibleFromAt));
        $this->assertArrayNotHasKey('cutoff', config('workdays.profiles.last_mile'));
    }

    #[DataProvider('requiredOptions')]
    public function test_missing_required_options_are_rejected(string $key): void
    {
        $options = $this->slotOptions();
        unset($options[$key]);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage($key);

        Workday::deliverySlots('2026-06-29T08:00:00Z', $options);
    }

    public static function requiredOptions(): array
    {
        return array_map(static fn (string $key): array => [$key], ['profile', 'timezone', 'days', 'duration_minutes', 'step_minutes']);
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_option_types_and_ranges_are_rejected_without_coercion(string $key, mixed $value): void
    {
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage($key);

        Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions([$key => $value]));
    }

    public static function invalidOptions(): iterable
    {
        foreach (['days', 'duration_minutes', 'step_minutes', 'min_notice_minutes', 'max_slots'] as $key) {
            foreach (['numeric string' => '1', 'float' => 1.0, 'true' => true, 'false' => false, 'null' => null, 'array' => [], 'object' => new \stdClass] as $label => $value) {
                yield $key.' '.$label => [$key, $value];
            }
        }

        foreach (['days' => [0, -1, 32], 'duration_minutes' => [0, -1, 1440], 'step_minutes' => [0, -1, 1440], 'min_notice_minutes' => [-1], 'max_slots' => [0, -1, 201]] as $key => $values) {
            foreach ($values as $value) {
                yield $key.' outside range '.$value => [$key, $value];
            }
        }

        foreach (['profile', 'timezone'] as $key) {
            foreach (['empty' => '', 'whitespace' => ' ', 'integer' => 1, 'float' => 1.5, 'boolean' => false, 'null' => null, 'array' => [], 'object' => new \stdClass] as $label => $value) {
                yield $key.' '.$label => [$key, $value];
            }
        }

        yield 'unknown profile' => ['profile', 'not_configured'];
        yield 'invalid IANA timezone' => ['timezone', 'Mars/Olympus'];
        yield 'fixed offset is not an IANA timezone' => ['timezone', '+03:30'];
    }

    public function test_unsupported_option_is_rejected(): void
    {
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('booking_capacity');

        Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['booking_capacity' => 3]));
    }

    public function test_numeric_unknown_option_key_is_rejected(): void
    {
        $this->expectException(WorkdayConfigurationException::class);

        Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions([123 => 'unsupported']));
    }

    #[DataProvider('invalidScanLimits')]
    public function test_invalid_global_scan_limit_is_reported_as_slot_configuration_error(mixed $limit): void
    {
        config()->set('workdays.max_scan_days', $limit);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('max_scan_days');

        Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions());
    }

    public static function invalidScanLimits(): array
    {
        return [[0], [-1], ['30'], [30.0], [true], [null], [[]], [new \stdClass]];
    }

    public function test_days_minus_one_must_not_exceed_the_scan_limit(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $this->assertCount(10, Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 2]))->slots);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('max_scan_days');

        Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 3]));
    }

    #[DataProvider('invalidWorkingHours')]
    public function test_slot_calls_require_valid_working_hours(mixed $hours): void
    {
        config()->set('workdays.profiles.last_mile.working_hours', $hours);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('working_hours');

        Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions());
    }

    public static function invalidWorkingHours(): array
    {
        return [[null], ['09:00-17:00'], [['Monday' => [['17:00', '09:00']]]], [['Monday' => [['09:00', '12:00'], ['11:00', '13:00']]]]];
    }

    public function test_missing_working_hours_is_rejected_even_if_notice_excludes_the_whole_range(): void
    {
        $profile = config('workdays.profiles.last_mile');
        unset($profile['working_hours']);
        config()->set('workdays.profiles.last_mile', $profile);
        $this->assertTrue(Workday::profile('last_mile')->isBusinessDay('2026-06-29'));
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('Working hours');

        Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['min_notice_minutes' => 1000000]));
    }

    #[DataProvider('invalidTimestamps')]
    public function test_invalid_and_relative_timestamp_strings_are_rejected(string $input, string $timezone): void
    {
        $this->expectException(InvalidArgumentException::class);

        Workday::deliverySlots($input, $this->slotOptions(['timezone' => $timezone]));
    }

    public static function invalidTimestamps(): array
    {
        return [
            ['', 'UTC'], ['now', 'UTC'], ['tomorrow', 'UTC'], ['2026-06-29', 'UTC'],
            ['2026-02-30 09:00', 'UTC'], ['2026-13-01 09:00', 'UTC'], ['2026-06-29 24:00', 'UTC'],
            ['2026-06-29T09:00:00+24:00', 'UTC'], ['2026-06-29T09:00:00+03:60', 'UTC'],
            ['2026-06-29T09:00:00', 'UTC'], ['2026-06-29 09:00Z', 'UTC'],
            ['2026-06-29T09:00:00.1234567Z', 'UTC'], ['2026-06-29T09:00:60Z', 'UTC'],
            ['2026-03-08 02:30', 'America/New_York'], ['2026-11-01 01:30', 'America/New_York'],
            ['2011-12-30 10:00', 'Pacific/Apia'],
        ];
    }

    public function test_result_classes_are_immutable_utc_with_exact_stable_local_serialization(): void
    {
        $result = Workday::deliverySlots('2026-06-29 09:00', $this->slotOptions(['timezone' => 'Asia/Tehran', 'days' => 1, 'max_slots' => 1]));
        $expectedSlot = [
            'starts_at' => '2026-06-29T05:30:00.000000+00:00', 'ends_at' => '2026-06-29T07:30:00.000000+00:00',
            'local_starts_at' => '2026-06-29T09:00:00.000000+03:30', 'local_ends_at' => '2026-06-29T11:00:00.000000+03:30',
        ];

        $this->assertInstanceOf(DeliverySlotsResult::class, $result);
        $this->assertInstanceOf(DeliverySlot::class, $result->slots[0]);
        $this->assertSame($expectedSlot, $result->slots[0]->toArray());
        $this->assertSame([
            'profile' => 'last_mile', 'timezone' => 'Asia/Tehran',
            'ready_at' => '2026-06-29T05:30:00.000000+00:00', 'eligible_from_at' => '2026-06-29T05:30:00.000000+00:00',
            'requested_days' => 1, 'slots' => [$expectedSlot],
        ], $result->toArray());

        foreach ([$result->readyAt, $result->eligibleFromAt, $result->slots[0]->startsAt, $result->slots[0]->endsAt] as $instant) {
            $this->assertInstanceOf(CarbonImmutable::class, $instant);
            $this->assertSame(0, $instant->getOffset());
            $this->assertSame('UTC', $instant->getTimezone()->getName());
        }

        $result->readyAt->addDay();
        $result->eligibleFromAt->addHour();
        $result->slots[0]->startsAt->addHour();
        $this->assertSame($expectedSlot, $result->slots[0]->toArray());
        $this->assertSame('2026-06-29T05:30:00.000000+00:00', $this->format($result->readyAt));
    }

    public function test_result_properties_are_readonly(): void
    {
        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions());
        $this->expectException(Error::class);

        $result->requestedDays = 100;
    }

    public function test_slot_properties_are_readonly(): void
    {
        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions());
        $this->expectException(Error::class);

        $result->slots[0]->startsAt = CarbonImmutable::parse('2026-06-30 00:00', 'UTC');
    }

    public function test_result_slot_list_cannot_be_replaced_or_appended_to(): void
    {
        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions());
        $this->expectException(Error::class);

        $result->slots[] = $result->slots[0];
    }

    public function test_mutable_caller_datetime_is_neither_changed_nor_retained_by_result(): void
    {
        $input = new DateTime('2026-06-29 09:00:12.123456', new DateTimeZone('Asia/Tehran'));
        $before = $input->format('Y-m-d\TH:i:s.uP');

        $result = Workday::deliverySlots($input, $this->slotOptions(['timezone' => 'Asia/Tehran', 'max_slots' => 1]));
        $serialized = $result->toArray();

        $this->assertSame($before, $input->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('Asia/Tehran', $input->getTimezone()->getName());
        $this->assertSame('2026-06-29T05:30:12.123456+00:00', $this->format($result->readyAt));
        $input->modify('+10 days')->setTimezone(new DateTimeZone('Pacific/Apia'));
        $this->assertSame($serialized, $result->toArray());
    }

    public function test_equivalent_absolute_and_naive_inputs_share_profile_timezone_not_app_timezone(): void
    {
        $options = $this->slotOptions(['timezone' => 'Asia/Tehran', 'max_slots' => 1]);
        $expected = Workday::deliverySlots('2026-06-29 09:30:12.123456', $options)->toArray();

        $this->assertSame($expected, Workday::deliverySlots('2026-06-29T06:00:12.123456Z', $options)->toArray());
        $this->assertSame($expected, Workday::deliverySlots('2026-06-29T10:00:12.123456+04:00', $options)->toArray());
        $this->assertSame('2026-06-29T06:00:12.123456+00:00', $expected['ready_at']);
        $this->assertSame('2026-06-29T10:00:00.000000+03:30', $expected['slots'][0]['local_starts_at']);
    }

    public function test_explicit_offset_uses_the_actual_profile_local_date_for_the_search(): void
    {
        $options = $this->slotOptions(['timezone' => 'Asia/Tehran', 'days' => 1]);
        $result = Workday::deliverySlots('2026-06-28T22:30:00Z', $options);

        $this->assertCount(5, $result->slots);
        $this->assertSame('2026-06-29 09:00', $result->slots[0]->startsAt->setTimezone('Asia/Tehran')->format('Y-m-d H:i'));
        $this->assertSame([], Workday::deliverySlots('2026-06-30T00:00:00+09:00', $options)->slots);
    }

    public function test_manager_and_facade_route_the_same_options_without_mutating_configuration(): void
    {
        $before = config('workdays');
        $options = $this->slotOptions();

        $this->assertSame(
            Workday::deliverySlots('2026-06-29T10:30:00Z', $options)->toArray(),
            $this->app->make(WorkdayManager::class)->deliverySlots('2026-06-29T10:30:00Z', $options)->toArray(),
        );
        $this->assertSame($before, config('workdays'));
    }

    public function test_profile_selection_is_isolated_across_calls(): void
    {
        config()->set('workdays.profiles.warehouse.custom_holidays', ['2026-06-29' => 'Warehouse closure']);
        $first = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 1]));

        $this->assertSame([], Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['profile' => 'warehouse', 'days' => 1]))->slots);
        $this->assertSame($first->toArray(), Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 1]))->toArray());
    }

    public function test_one_day_search_has_no_next_day_fallback(): void
    {
        $this->assertSame([], Workday::deliverySlots('2026-06-29T17:00:00Z', $this->slotOptions(['days' => 1]))->slots);
    }

    public function test_seven_days_means_exactly_seven_calendar_dates_including_ready_date(): void
    {
        $this->everyDay([['09:00', '11:00']]);

        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 7, 'max_slots' => 200]));

        $this->assertSame(['2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04', '2026-07-05'], array_map(static fn (DeliverySlot $slot): string => $slot->startsAt->toDateString(), $result->slots));
        $this->assertSame(7, $result->requestedDays);
    }

    public function test_only_the_final_requested_date_may_succeed_without_extending_the_range(): void
    {
        $this->everyDay([['09:00', '11:00']]);
        $closures = [];
        foreach (range(0, 5) as $offset) {
            $closures[CarbonImmutable::parse('2026-06-29', 'UTC')->addDays($offset)->toDateString()] = 'Closed';
        }
        config()->set('workdays.profiles.last_mile.custom_holidays', $closures);

        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 7, 'max_slots' => 200]));

        $this->assertSame([['2026-07-05 09:00', '2026-07-05 11:00']], $this->intervals($result));
    }

    public function test_long_holiday_period_returns_empty_without_searching_for_an_extra_business_day(): void
    {
        $this->everyDay([['09:00', '11:00']]);
        $closures = [];
        foreach (range(0, 30) as $offset) {
            $closures[CarbonImmutable::parse('2026-06-01', 'UTC')->addDays($offset)->toDateString()] = 'Closed';
        }
        config()->set('workdays.profiles.last_mile.custom_holidays', $closures);

        $this->assertSame([], Workday::deliverySlots('2026-06-01T08:00:00Z', $this->slotOptions(['days' => 31, 'max_slots' => 200]))->slots);
    }

    public function test_notice_beyond_requested_range_returns_empty_without_moving_date_anchor(): void
    {
        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 2, 'min_notice_minutes' => 2880]));

        $this->assertSame('2026-07-01T08:00:00.000000+00:00', $this->format($result->eligibleFromAt));
        $this->assertSame([], $result->slots);
        $this->assertSame(2, $result->requestedDays);
    }

    public function test_large_notice_buffer_is_elapsed_and_empty_without_an_arbitrary_business_day_limit(): void
    {
        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 1, 'min_notice_minutes' => 1000000]));

        $this->assertSame(60000000, $result->eligibleFromAt->getTimestamp() - $result->readyAt->getTimestamp());
        $this->assertSame([], $result->slots);
    }

    public function test_unrepresentable_notice_timestamp_is_reported_without_integer_wraparound(): void
    {
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('min_notice_minutes');
        $this->expectExceptionMessage('representable datetime');

        Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['min_notice_minutes' => PHP_INT_MAX]));
    }

    public function test_pre_epoch_notice_can_have_a_representable_sum_without_overflowing_intermediate_seconds(): void
    {
        $minutes = intdiv(PHP_INT_MAX, 60) + 1;

        $result = Workday::deliverySlots('1969-01-01T00:00:00.123456Z', $this->slotOptions(['days' => 1, 'min_notice_minutes' => $minutes]));

        $this->assertSame(PHP_INT_MAX - 31535947, $result->eligibleFromAt->getTimestamp());
        $this->assertSame('123456', $result->eligibleFromAt->format('u'));
        $this->assertSame([], $result->slots);
    }

    public function test_max_slots_one_returns_the_first_option_and_stops_before_later_bad_calendar_date(): void
    {
        config()->set('workdays.profiles.last_mile.extra_working_days', ['2026-07-04' => 'Missing Saturday hours']);

        $result = Workday::deliverySlots('2026-07-03T08:00:00Z', $this->slotOptions(['max_slots' => 1]));

        $this->assertSame([['2026-07-03 09:00', '2026-07-03 11:00']], $this->intervals($result));
    }

    public function test_maximum_days_duration_and_step_are_inclusive(): void
    {
        $this->everyDay([['00:00', '23:59']]);

        $result = Workday::deliverySlots('2026-06-01T00:00:00Z', $this->slotOptions(['days' => 31, 'duration_minutes' => 1439, 'step_minutes' => 1439, 'max_slots' => 200]));

        $this->assertCount(31, $result->slots);
        $this->assertSame(['2026-07-01 00:00', '2026-07-01 23:59'], $this->intervals($result)[30]);
    }

    public function test_maximum_returned_slots_is_honored_with_dense_candidates(): void
    {
        $this->everyDay([['00:00', '23:59']]);

        $result = Workday::deliverySlots('2026-06-29T00:00:00Z', $this->slotOptions(['days' => 1, 'duration_minutes' => 1, 'step_minutes' => 1, 'max_slots' => 200]));

        $this->assertCount(200, $result->slots);
        $this->assertSame(['2026-06-29 03:19', '2026-06-29 03:20'], $this->intervals($result)[199]);
    }

    public function test_recurring_config_holiday_is_reused_through_the_calendar(): void
    {
        config()->set('workdays.profiles.last_mile.holidays.gregorian', ['06-29' => 'Annual closure']);

        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions());

        $this->assertCount(5, $result->slots);
        $this->assertSame('2026-06-30 09:00', $result->slots[0]->startsAt->format('Y-m-d H:i'));
    }

    public function test_database_holidays_and_recurring_rules_are_reused_without_writing_bookings(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
        config()->set('workdays.storage.driver', 'database');
        WorkdaySpecialDate::create(['profile' => 'last_mile', 'date' => '2026-06-29', 'type' => 'holiday', 'title' => 'Stored closure', 'is_active' => true]);
        WorkdayHolidayRule::create(['profile' => 'last_mile', 'calendar_type' => 'gregorian', 'month' => 6, 'day' => 30, 'title' => 'Annual stored closure', 'is_active' => true]);
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();

        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 3]));

        $this->assertSame('2026-07-01 09:00', $result->slots[0]->startsAt->format('Y-m-d H:i'));
        $this->assertCount(5, $result->slots);
        foreach ($connection->getQueryLog() as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
        $connection->disableQueryLog();
        $this->assertSame(1, WorkdaySpecialDate::count());
        $this->assertSame(1, WorkdayHolidayRule::count());
    }

    public function test_chain_database_working_day_overrides_config_holiday_and_weekend(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
        config()->set('workdays.storage.driver', 'chain');
        config()->set('workdays.profiles.last_mile.custom_holidays', ['2026-07-04' => 'Configured closure']);
        config()->set('workdays.profiles.last_mile.extra_working_day_hours', [['10:00', '14:00']]);
        WorkdaySpecialDate::create(['profile' => 'last_mile', 'date' => '2026-07-04', 'type' => 'working_day', 'title' => 'Stored reopening', 'is_active' => true]);

        $result = Workday::deliverySlots('2026-07-04T08:00:00Z', $this->slotOptions(['days' => 1]));

        $this->assertSame([
            ['2026-07-04 10:00', '2026-07-04 12:00'], ['2026-07-04 11:00', '2026-07-04 13:00'], ['2026-07-04 12:00', '2026-07-04 14:00'],
        ], $this->intervals($result));
    }

    public function test_chain_config_extra_working_day_retains_precedence_over_database_holiday(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
        config()->set('workdays.storage.driver', 'chain');
        config()->set('workdays.profiles.last_mile.extra_working_days', ['2026-06-29' => 'Configured reopening']);
        WorkdaySpecialDate::create(['profile' => 'last_mile', 'date' => '2026-06-29', 'type' => 'holiday', 'title' => 'Stored closure', 'is_active' => true]);

        $this->assertCount(5, Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions(['days' => 1]))->slots);
    }

    public function test_database_queries_are_per_date_and_new_calls_observe_new_holiday_data(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
        config()->set('workdays.storage.driver', 'database');
        config()->set('workdays.profiles.last_mile.working_hours', $this->hours([['09:00', '17:00']]));
        $options = $this->slotOptions(['duration_minutes' => 60, 'step_minutes' => 5, 'max_slots' => 200]);
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();

        $result = Workday::deliverySlots('2026-06-29T08:00:00Z', $options);

        $this->assertCount(170, $result->slots);
        $this->assertCount(3, $connection->getQueryLog());
        $connection->disableQueryLog();
        WorkdaySpecialDate::create(['profile' => 'last_mile', 'date' => '2026-06-29', 'type' => 'holiday', 'title' => 'New closure', 'is_active' => true]);

        $nextResult = Workday::deliverySlots('2026-06-29T08:00:00Z', $options);

        $this->assertCount(85, $nextResult->slots);
        $this->assertSame('2026-06-30', $nextResult->slots[0]->startsAt->toDateString());
    }

    public function test_one_calculator_is_resolved_and_provider_dates_are_not_evaluated_per_candidate(): void
    {
        $profile = $this->profile($this->hours([['09:00', '17:00']]));
        config()->set('workdays.profiles.last_mile', $profile);
        $provider = new class(new ConfigHolidayProvider(['last_mile' => $profile])) implements HolidayProviderInterface
        {
            public array $dateEvaluations = [];

            public function __construct(private HolidayProviderInterface $inner) {}

            public function recurringHolidays(string $profile, string $calendar): array
            {
                return $this->inner->recurringHolidays($profile, $calendar);
            }

            public function customHoliday(string $profile, CarbonImmutable $date): ?Holiday
            {
                return $this->inner->customHoliday($profile, $date);
            }

            public function extraWorkingDay(string $profile, CarbonImmutable $date): ?Holiday
            {
                return $this->inner->extraWorkingDay($profile, $date);
            }

            public function hasCustomHoliday(string $profile, CarbonImmutable $date): bool
            {
                $this->dateEvaluations[] = $date->toDateString();

                return $this->inner->hasCustomHoliday($profile, $date);
            }

            public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool
            {
                $this->dateEvaluations[] = $date->toDateString();

                return $this->inner->hasExtraWorkingDay($profile, $date);
            }
        };
        $resolved = [];
        $generator = new DeliverySlotGenerator(function (string $name) use (&$resolved, $profile, $provider): BusinessDayCalculator {
            $resolved[] = $name;

            return new BusinessDayCalculator($name, $profile, maxScanDays: 30, holidayProvider: $provider);
        });

        $result = $generator->generate('2026-06-29T08:00:00Z', $this->slotOptions(['duration_minutes' => 60, 'step_minutes' => 5, 'max_slots' => 200]));

        $this->assertCount(170, $result->slots);
        $this->assertSame(['last_mile'], $resolved);
        $this->assertSame(['2026-06-29', '2026-06-30'], array_values(array_unique($provider->dateEvaluations)));
        $this->assertLessThanOrEqual(8, count($provider->dateEvaluations));
    }

    #[DataProvider('dstHourSlots')]
    public function test_dst_gap_and_fold_have_only_unambiguous_constant_offset_candidates(string $readyAt, array $expected): void
    {
        $this->everyDay([['00:00', '05:00']]);

        $result = Workday::deliverySlots($readyAt, $this->slotOptions(['timezone' => 'America/New_York', 'days' => 1, 'duration_minutes' => 60]));

        $this->assertSame($expected, array_map(static fn (DeliverySlot $slot): array => $slot->toArray(), $result->slots));
        foreach ($result->slots as $slot) {
            $this->assertSame(3600, $slot->endsAt->getTimestamp() - $slot->startsAt->getTimestamp());
            $this->assertSame($slot->startsAt->setTimezone('America/New_York')->getOffset(), $slot->endsAt->setTimezone('America/New_York')->getOffset());
        }
    }

    public static function dstHourSlots(): array
    {
        return [
            'spring gap retains valid slots before and after' => ['2026-03-08T00:00:00-05:00', [
                self::serializedSlot('2026-03-08', '05:00', '06:00', '00:00', '01:00', '-05:00'),
                self::serializedSlot('2026-03-08', '07:00', '08:00', '03:00', '04:00', '-04:00'),
                self::serializedSlot('2026-03-08', '08:00', '09:00', '04:00', '05:00', '-04:00'),
            ]],
            'fall fold rejects either ambiguous boundary' => ['2026-11-01T00:00:00-04:00', [
                self::serializedSlot('2026-11-01', '07:00', '08:00', '02:00', '03:00', '-05:00'),
                self::serializedSlot('2026-11-01', '08:00', '09:00', '03:00', '04:00', '-05:00'),
                self::serializedSlot('2026-11-01', '09:00', '10:00', '04:00', '05:00', '-05:00'),
            ]],
        ];
    }

    #[DataProvider('dstCrossingSlots')]
    public function test_candidate_with_unambiguous_endpoints_is_still_rejected_when_it_crosses_transition(string $readyAt, string $expectedStart, string $expectedEnd): void
    {
        $this->everyDay([['00:00', '06:00']]);

        $result = Workday::deliverySlots($readyAt, $this->slotOptions(['timezone' => 'America/New_York', 'days' => 1, 'duration_minutes' => 180, 'step_minutes' => 180]));

        $this->assertSame([[$expectedStart, $expectedEnd]], $this->intervals($result));
        $this->assertSame(10800, $result->slots[0]->endsAt->getTimestamp() - $result->slots[0]->startsAt->getTimestamp());
    }

    public static function dstCrossingSlots(): array
    {
        return [
            'spring' => ['2026-03-08T00:00:00-05:00', '2026-03-08 07:00', '2026-03-08 10:00'],
            'fall' => ['2026-11-01T00:00:00-04:00', '2026-11-01 08:00', '2026-11-01 11:00'],
        ];
    }

    #[DataProvider('invalidWindowBoundaries')]
    public function test_invalid_window_boundary_does_not_remove_other_unambiguous_candidates(string $readyAt, array $window, array $expectedLocal): void
    {
        $this->everyDay([$window]);

        $result = Workday::deliverySlots($readyAt, $this->slotOptions(['timezone' => 'America/New_York', 'days' => 1, 'duration_minutes' => 60, 'step_minutes' => 30]));

        $this->assertSame($expectedLocal, $this->intervals($result, 'America/New_York'));
    }

    public static function invalidWindowBoundaries(): array
    {
        return [
            'nonexistent opening keeps later candidates' => ['2026-03-08T00:00:00-05:00', ['02:30', '05:00'], [
                ['2026-03-08 03:00', '2026-03-08 04:00'], ['2026-03-08 03:30', '2026-03-08 04:30'], ['2026-03-08 04:00', '2026-03-08 05:00'],
            ]],
            'ambiguous opening keeps later candidates' => ['2026-11-01T00:00:00-04:00', ['01:30', '04:00'], [
                ['2026-11-01 02:00', '2026-11-01 03:00'], ['2026-11-01 02:30', '2026-11-01 03:30'], ['2026-11-01 03:00', '2026-11-01 04:00'],
            ]],
            'nonexistent close keeps earlier candidates' => ['2026-03-08T00:00:00-05:00', ['00:00', '02:30'], [
                ['2026-03-08 00:00', '2026-03-08 01:00'], ['2026-03-08 00:30', '2026-03-08 01:30'],
            ]],
            'ambiguous close keeps earlier candidates' => ['2026-11-01T00:00:00-04:00', ['00:00', '01:30'], []],
        ];
    }

    #[DataProvider('dstNoticeBuffers')]
    public function test_notice_is_actual_utc_elapsed_minutes_across_dst(string $readyAt, int $notice, string $expectedEligibility, string $expectedLocalStart): void
    {
        $this->everyDay([['00:00', '05:00']]);

        $result = Workday::deliverySlots($readyAt, $this->slotOptions(['timezone' => 'America/New_York', 'days' => 1, 'duration_minutes' => 60, 'min_notice_minutes' => $notice]));

        $this->assertSame($expectedEligibility, $this->format($result->eligibleFromAt));
        $this->assertSame($notice * 60, $result->eligibleFromAt->getTimestamp() - $result->readyAt->getTimestamp());
        $this->assertSame($expectedLocalStart, $result->slots[0]->startsAt->setTimezone('America/New_York')->format('Y-m-d H:iP'));
    }

    public static function dstNoticeBuffers(): array
    {
        return [
            'spring real hour jumps from one to three' => ['2026-03-08T01:00:00-05:00', 60, '2026-03-08T07:00:00.000000+00:00', '2026-03-08 03:00-04:00'],
            'fall real two hours reaches second one thirty' => ['2026-11-01T00:30:00-04:00', 120, '2026-11-01T06:30:00.000000+00:00', '2026-11-01 02:00-05:00'],
        ];
    }

    #[DataProvider('disambiguatedFoldInputs')]
    public function test_explicit_offsets_or_datetime_inputs_disambiguate_fold(string|DateTimeInterface $input, string $expectedReady): void
    {
        $this->everyDay([['02:00', '05:00']]);

        $result = Workday::deliverySlots($input, $this->slotOptions(['timezone' => 'America/New_York', 'days' => 1, 'duration_minutes' => 60]));

        $this->assertSame($expectedReady, $this->format($result->readyAt));
        $this->assertSame('2026-11-01T07:00:00.000000+00:00', $this->format($result->slots[0]->startsAt));
    }

    public static function disambiguatedFoldInputs(): array
    {
        return [
            ['2026-11-01T01:30:00-04:00', '2026-11-01T05:30:00.000000+00:00'],
            ['2026-11-01T01:30:00-05:00', '2026-11-01T06:30:00.000000+00:00'],
            [new DateTime('2026-11-01T01:30:00-05:00'), '2026-11-01T06:30:00.000000+00:00'],
        ];
    }

    public function test_apia_skipped_civil_date_never_extends_the_requested_date_labels(): void
    {
        $this->everyDay([['09:00', '17:00']]);
        $options = $this->slotOptions(['timezone' => 'Pacific/Apia', 'days' => 2]);

        $this->assertSame([], Workday::deliverySlots('2011-12-29T23:30:00-10:00', $options)->slots);

        $result = Workday::deliverySlots('2011-12-29T23:30:00-10:00', array_replace($options, ['days' => 3]));

        $this->assertCount(7, $result->slots);
        $this->assertSame('2011-12-30T19:00:00.000000+00:00', $this->format($result->slots[0]->startsAt));
        $this->assertSame(['2011-12-31'], array_values(array_unique(array_map(static fn (DeliverySlot $slot): string => $slot->startsAt->setTimezone('Pacific/Apia')->toDateString(), $result->slots))));
        $this->assertSame('+14:00', $result->slots[0]->startsAt->setTimezone('Pacific/Apia')->format('P'));
        $this->assertSame(3, $result->requestedDays);
    }

    public function test_midnight_gap_keeps_existing_date_and_unambiguous_later_slots(): void
    {
        $this->everyDay([['00:00', '03:00']]);

        $result = Workday::deliverySlots('2026-09-06T03:30:00Z', $this->slotOptions(['timezone' => 'America/Santiago', 'days' => 2, 'duration_minutes' => 60]));

        $this->assertSame([
            ['2026-09-06 01:00', '2026-09-06 02:00'], ['2026-09-06 02:00', '2026-09-06 03:00'],
        ], $this->intervals($result, 'America/Santiago'));
        $this->assertSame('2026-09-06T04:00:00.000000+00:00', $this->format($result->slots[0]->startsAt));
        $this->assertSame(-10800, $result->slots[0]->startsAt->setTimezone('America/Santiago')->getOffset());
    }

    public function test_phase_two_readiness_completion_can_feed_slots_without_reapplying_cutoff_or_preparation(): void
    {
        config()->set('workdays.profiles.last_mile.cutoff', ['timezone' => 'Asia/Tehran', 'time' => '10:00']);
        $plan = [
            'timezone' => 'Asia/Tehran', 'profile_timezones' => ['warehouse' => 'Asia/Tehran'], 'cutoff_profile' => 'warehouse',
            'stages' => [['id' => 'ready_for_collection', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 120]],
        ];

        $readiness = Workday::planDelivery('2026-06-29 13:00', $plan);
        $slots = Workday::deliverySlots($readiness->estimatedCompletionAt, $this->slotOptions(['timezone' => 'Asia/Tehran', 'days' => 7, 'max_slots' => 20]));

        $this->assertInstanceOf(DeliveryPlanResult::class, $readiness);
        $this->assertInstanceOf(DeliverySlotsResult::class, $slots);
        $this->assertSame('ready_for_collection', $readiness->stages[0]->id);
        $this->assertSame('2026-06-29T11:30:00.000000+00:00', $this->format($readiness->estimatedCompletionAt));
        $this->assertSame($this->format($readiness->estimatedCompletionAt), $this->format($slots->readyAt));
        $this->assertSame($this->format($slots->readyAt), $this->format($slots->eligibleFromAt));
        $this->assertSame(['2026-06-29 15:00', '2026-06-29 17:00'], $this->intervals($slots, 'Asia/Tehran')[0]);
        foreach ($slots->slots as $slot) {
            $this->assertTrue($slot->startsAt->greaterThanOrEqualTo($readiness->estimatedCompletionAt));
        }
        $this->assertArrayNotHasKey('cutoff_result', $slots->toArray());
        $this->assertArrayNotHasKey('slots', $readiness->toArray());
        $this->assertTrue($readiness->cutoffResult->sameDayEligible);
    }

    public function test_existing_calendar_cutoff_planning_and_business_time_behavior_remains_unchanged(): void
    {
        $calculator = Workday::profile('warehouse');
        $before = [
            $calculator->resolveCutoff('2026-06-29 14:00')->toArray(),
            array_map(static fn ($window): array => $window->toArray(), $calculator->workingWindowsFor('2026-06-29')),
            $calculator->isBusinessTime('2026-06-29 17:00'),
            $calculator->nextBusinessTime('2026-06-29 17:00')->format('Y-m-d H:i'),
            $calculator->addBusinessMinutes('2026-06-29 16:30', 90)->format('Y-m-d H:i'),
            $calculator->addBusinessDays('2026-06-29', 1)->toDateString(),
        ];

        Workday::deliverySlots('2026-06-29T08:00:00Z', $this->slotOptions());

        $this->assertSame($before, [
            $calculator->resolveCutoff('2026-06-29 14:00')->toArray(),
            array_map(static fn ($window): array => $window->toArray(), $calculator->workingWindowsFor('2026-06-29')),
            $calculator->isBusinessTime('2026-06-29 17:00'),
            $calculator->nextBusinessTime('2026-06-29 17:00')->format('Y-m-d H:i'),
            $calculator->addBusinessMinutes('2026-06-29 16:30', 90)->format('Y-m-d H:i'),
            $calculator->addBusinessDays('2026-06-29', 1)->toDateString(),
        ]);
        $this->assertFalse($before[2]);
        $this->assertSame('2026-06-30 09:00', $before[3]);
        $this->assertSame('2026-06-30 10:00', $before[4]);
        $this->assertSame('2026-06-30', $before[5]);
    }

    private function slotOptions(array $overrides = []): array
    {
        return array_replace([
            'profile' => 'last_mile', 'timezone' => 'UTC', 'days' => 2,
            'duration_minutes' => 120, 'step_minutes' => 60, 'min_notice_minutes' => 0, 'max_slots' => 10,
        ], $overrides);
    }

    private function profile(array $hours): array
    {
        return [
            'weekends' => ['Saturday', 'Sunday'],
            'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
            'custom_holidays' => [], 'extra_working_days' => [], 'working_hours' => $hours,
        ];
    }

    private function hours(array $windows): array
    {
        return array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], $windows);
    }

    private function everyDay(array $windows): void
    {
        config()->set('workdays.profiles.last_mile.weekends', []);
        config()->set('workdays.profiles.last_mile.working_hours', array_fill_keys(range(1, 7), $windows));
    }

    private function intervals(DeliverySlotsResult $result, string $timezone = 'UTC'): array
    {
        return array_map(static fn (DeliverySlot $slot): array => [
            $slot->startsAt->setTimezone($timezone)->format('Y-m-d H:i'),
            $slot->endsAt->setTimezone($timezone)->format('Y-m-d H:i'),
        ], $result->slots);
    }

    private static function serializedSlot(string $date, string $utcStart, string $utcEnd, string $localStart, string $localEnd, string $offset): array
    {
        return [
            'starts_at' => $date.'T'.$utcStart.':00.000000+00:00', 'ends_at' => $date.'T'.$utcEnd.':00.000000+00:00',
            'local_starts_at' => $date.'T'.$localStart.':00.000000'.$offset, 'local_ends_at' => $date.'T'.$localEnd.':00.000000'.$offset,
        ];
    }

    private function format(CarbonImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s.uP');
    }
}
