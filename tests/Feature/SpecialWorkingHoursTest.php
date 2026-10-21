<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\DeliverySlot;
use Zarbinco\LaravelWorkdays\Data\DeliverySlotsResult;
use Zarbinco\LaravelWorkdays\Data\Holiday;
use Zarbinco\LaravelWorkdays\Data\TimeWindow;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Holidays\HolidayProviderInterface;
use Zarbinco\LaravelWorkdays\Holidays\LogicalHolidayProvider;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class SpecialWorkingHoursTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Pacific/Honolulu');
        config()->set('workdays.definitions.default_profile', 'warehouse');
        config()->set('workdays.driver', 'config');
        config()->set('workdays.max_scan_days', 14);
        config()->set('workdays.definitions.profiles', ['warehouse' => $this->baseProfile()]);
    }

    public function test_exact_date_hours_replace_regular_hours_and_leave_other_mondays_unchanged(): void
    {
        $calculator = $this->configure(['special_working_hours' => ['2026-11-02' => [['10:00', '12:00']]]]);

        $this->assertSame([['start' => '10:00', 'end' => '12:00']], $this->windows($calculator, '2026-11-02'));
        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $this->windows($calculator, '2026-11-09'));
        $this->assertFalse($calculator->isBusinessTime($this->at('2026-11-02 09:00')));
        $this->assertTrue($calculator->isBusinessTime($this->at('2026-11-02 10:00')));
        $this->assertFalse($calculator->isBusinessTime($this->at('2026-11-02 12:00')));
        $this->assertSame('2026-11-02 10:00:00', $calculator->nextBusinessTime($this->at('2026-11-02 09:00'))->toDateTimeString());
        $this->assertSame('2026-11-03 09:30:00', $calculator->addBusinessMinutes($this->at('2026-11-02 11:00'), 90)->toDateTimeString());
        $this->assertSame(120, $calculator->diffBusinessMinutes($this->at('2026-11-02 09:00'), $this->at('2026-11-02 17:00')));
        $this->assertTrue($calculator->isBusinessDay('2026-11-02'));
    }

    public function test_empty_special_hours_close_new_shifts_without_changing_business_date_or_cutoff(): void
    {
        $calculator = $this->configure(['special_working_hours' => ['2026-11-02' => []]]);

        $this->assertSame([], $this->windows($calculator, '2026-11-02'));
        $this->assertTrue($calculator->isBusinessDay('2026-11-02'));
        $this->assertFalse($calculator->isHoliday('2026-11-02'));
        $this->assertFalse($calculator->isWeekend('2026-11-02'));
        $this->assertFalse($calculator->isBusinessTime($this->at('2026-11-02 10:00')));
        $this->assertTrue($calculator->resolveCutoff('2026-11-02 13:00')->sameDayEligible);
        $this->assertSame('2026-11-03 09:00:00', $calculator->nextBusinessTime($this->at('2026-11-02 09:00'))->toDateTimeString());
        $this->assertSame('2026-10-30 16:59:59', $calculator->previousBusinessTime($this->at('2026-11-03 08:00'))->toDateTimeString());
        $this->assertSame('2026-11-03', $calculator->resolveCutoff('2026-11-02 14:00')->effectiveBusinessDate->toDateString());
    }

    public function test_empty_exact_override_prevents_extra_working_day_fallback(): void
    {
        $calculator = $this->configure([
            'special_working_hours' => ['2026-11-07' => []],
            'extra_working_days' => ['2026-11-07' => 'Reopened Saturday'],
            'extra_working_day_hours' => [['10:00', '14:00']],
        ]);

        $this->assertTrue($calculator->isBusinessDay('2026-11-07'));
        $this->assertSame([], $this->windows($calculator, '2026-11-07'));
    }

    public function test_seasonal_hours_replace_only_explicit_weekdays_and_exact_date_wins(): void
    {
        $calculator = $this->configure([
            'special_working_hours' => ['2026-11-02' => [['10:00', '12:00']]],
            'seasonal_working_hours' => [$this->season('2026-11-01', '2026-11-30', ['Monday' => [['08:00', '20:00']], 'Tuesday' => []])],
        ]);

        $this->assertSame([['start' => '10:00', 'end' => '12:00']], $this->windows($calculator, '2026-11-02'));
        $this->assertSame([['start' => '08:00', 'end' => '20:00']], $this->windows($calculator, '2026-11-09'));
        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $this->windows($calculator, '2026-11-04'));
        $this->assertSame([], $this->windows($calculator, '2026-11-03'));
        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $this->windows($calculator, '2026-12-07'));
        $this->assertSame('2026-11-09 19:00:00', $calculator->addBusinessMinutes($this->at('2026-11-09 18:00'), 60)->toDateTimeString());
    }

    public function test_season_boundaries_are_inclusive_and_may_cross_a_year_boundary(): void
    {
        $calculator = $this->configure(['seasonal_working_hours' => [
            $this->season('2026-12-28', '2027-01-04', [1 => [['08:00', '20:00']]]),
        ]]);

        foreach (['2026-12-28', '2027-01-04'] as $date) {
            $this->assertSame([['start' => '08:00', 'end' => '20:00']], $this->windows($calculator, $date));
        }
        foreach (['2026-12-21', '2027-01-11'] as $date) {
            $this->assertSame([['start' => '09:00', 'end' => '17:00']], $this->windows($calculator, $date));
        }
    }

    public function test_single_date_and_adjacent_nonoverlapping_seasons_are_valid(): void
    {
        $calculator = $this->configure(['seasonal_working_hours' => [
            $this->season('2026-11-02', '2026-11-02', ['Monday' => [['08:00', '10:00']]]),
            $this->season('2026-11-03', '2026-11-09', ['Monday' => [['14:00', '16:00']]]),
        ]]);

        $this->assertSame([['start' => '08:00', 'end' => '10:00']], $this->windows($calculator, '2026-11-02'));
        $this->assertSame([['start' => '14:00', 'end' => '16:00']], $this->windows($calculator, '2026-11-09'));
    }

    public function test_seasonal_empty_weekday_prevents_fallback_but_omitted_weekday_preserves_it(): void
    {
        $calculator = $this->configure([
            'extra_working_days' => ['2026-11-07' => 'Saturday', '2026-11-08' => 'Sunday'],
            'extra_working_day_hours' => [['10:00', '14:00']],
            'seasonal_working_hours' => [$this->season('2026-11-01', '2026-11-30', ['Saturday' => []])],
        ]);

        $this->assertSame([], $this->windows($calculator, '2026-11-07'));
        $this->assertSame([['start' => '10:00', 'end' => '14:00']], $this->windows($calculator, '2026-11-08'));
    }

    #[DataProvider('closedDateOverrides')]
    public function test_hours_do_not_reopen_a_calendar_holiday_or_weekend_without_existing_override(array $overrides, string $date): void
    {
        $calculator = $this->configure($overrides);

        $this->assertFalse($calculator->isBusinessDay($date));
        $this->assertSame([], $this->windows($calculator, $date));
        config()->set('workdays.definitions.profiles.warehouse.extra_working_days', [$date => 'Reopened']);
        $reopened = Workday::profile('warehouse');
        $this->assertTrue($reopened->isBusinessDay($date));
        $this->assertSame([['start' => '10:00', 'end' => '12:00']], $this->windows($reopened, $date));
    }

    public static function closedDateOverrides(): array
    {
        return [
            'exact weekend' => [['special_working_hours' => ['2026-11-07' => [['10:00', '12:00']]]], '2026-11-07'],
            'exact holiday' => [['custom_holidays' => ['2026-11-02' => 'Closed'], 'special_working_hours' => ['2026-11-02' => [['10:00', '12:00']]]], '2026-11-02'],
            'season weekend' => [['seasonal_working_hours' => [['from' => '2026-11-01', 'through' => '2026-11-30', 'weekdays' => ['Saturday' => [['10:00', '12:00']]]]]], '2026-11-07'],
        ];
    }

    #[DataProvider('overnightBoundaries')]
    public function test_overnight_effective_half_open_boundaries(string $time, bool $expected): void
    {
        $calculator = $this->nightProfile();

        $this->assertSame($expected, $calculator->isBusinessTime($this->at($time)));
    }

    public static function overnightBoundaries(): array
    {
        return [
            ['2026-11-02 21:59:59.999999', false], ['2026-11-02 22:00:00', true],
            ['2026-11-02 23:59:59.999999', true], ['2026-11-03 00:00:00', true],
            ['2026-11-03 02:00:00', true], ['2026-11-03 05:59:59.999999', true], ['2026-11-03 06:00:00', false],
        ];
    }

    public function test_overnight_public_windows_are_ordered_same_date_slices(): void
    {
        $calculator = $this->nightProfile();

        $this->assertSame([['start' => '22:00', 'end' => '24:00']], $this->windows($calculator, '2026-11-02'));
        $this->assertSame([['start' => '00:00', 'end' => '06:00']], $this->windows($calculator, '2026-11-03'));
        $this->assertSame([], $this->windows($calculator, '2026-11-04'));
    }

    public function test_overnight_arithmetic_consumes_midnight_once_and_preserves_signed_diff_and_zero_behavior(): void
    {
        $calculator = $this->nightProfile();

        $this->assertSame('2026-11-03 02:00:00', $calculator->addBusinessMinutes($this->at('2026-11-02 23:00'), 180)->toDateTimeString());
        $this->assertSame('2026-11-03 00:00:00', $calculator->addBusinessMinutes($this->at('2026-11-02 23:00'), 60)->toDateTimeString());
        $this->assertSame('2026-11-03 00:05:00', $calculator->addBusinessMinutes($this->at('2026-11-02 23:55'), 10)->toDateTimeString());
        $this->assertSame('2026-11-03 02:00:00', $calculator->addBusinessHours($this->at('2026-11-02 23:00'), 3)->toDateTimeString());
        $this->assertSame(240, $calculator->diffBusinessMinutes($this->at('2026-11-02 23:00'), $this->at('2026-11-03 03:00')));
        $this->assertSame(-240, $calculator->diffBusinessMinutes($this->at('2026-11-03 03:00'), $this->at('2026-11-02 23:00')));
        $this->assertSame(4.0, $calculator->diffBusinessHours($this->at('2026-11-02 23:00'), $this->at('2026-11-03 03:00')));
        $this->assertSame('2026-11-02 21:12:13.123456', $calculator->addBusinessMinutes($this->at('2026-11-02 21:12:13.123456'), 0)->format('Y-m-d H:i:s.u'));
    }

    public function test_artificial_midnight_slices_preserve_fractional_minutes_and_microseconds(): void
    {
        $calculator = $this->nightProfile();
        $start = $this->at('2026-11-02 23:59:30.123456');
        $end = $this->at('2026-11-03 00:00:30.123456');

        $this->assertSame('2026-11-03 00:00:30.123456', $calculator->addBusinessMinutes($start, 1)->format('Y-m-d H:i:s.u'));
        $this->assertSame(1, $calculator->diffBusinessMinutes($start, $end));
        $this->assertSame(-1, $calculator->diffBusinessMinutes($end, $start));
    }

    public function test_overnight_forward_and_backward_traversal_use_carry_in_and_existing_closing_precision(): void
    {
        $calculator = $this->nightProfile();

        $this->assertSame('2026-11-02 22:00:00', $calculator->nextBusinessTime($this->at('2026-11-02 21:00'))->toDateTimeString());
        $this->assertSame('2026-11-03 02:00:00', $calculator->nextBusinessTime($this->at('2026-11-03 02:00'))->toDateTimeString());
        $this->assertSame('2026-11-09 22:00:00', $calculator->nextBusinessTime($this->at('2026-11-03 06:00'))->toDateTimeString());
        $this->assertSame('2026-11-03 05:59:59', $calculator->previousBusinessTime($this->at('2026-11-03 06:00'))->toDateTimeString());
        $this->assertSame('2026-11-03 05:59:59', $calculator->previousBusinessTime($this->at('2026-11-04 10:00'))->toDateTimeString());
        $this->assertSame('2026-11-03 00:00:00', $calculator->previousBusinessTime($this->at('2026-11-03 00:00'))->toDateTimeString());
    }

    public function test_overnight_carry_in_survives_receiving_weekend_holiday_and_empty_exact_hours(): void
    {
        $calculator = $this->nightProfile([
            'working_hours' => ['Friday' => [['22:00', '06:00']]],
            'custom_holidays' => ['2026-11-07' => 'Receiving-date closure'],
            'special_working_hours' => ['2026-11-07' => []],
        ]);

        $this->assertFalse($calculator->isBusinessDay('2026-11-07'));
        $this->assertTrue($calculator->isHoliday('2026-11-07'));
        $this->assertTrue($calculator->isWeekend('2026-11-07'));
        $this->assertSame([['start' => '00:00', 'end' => '06:00']], $this->windows($calculator, '2026-11-07'));
        $this->assertTrue($calculator->isBusinessTime($this->at('2026-11-07 02:00')));
        $this->assertFalse($calculator->isBusinessTime($this->at('2026-11-07 06:00')));
        $this->assertSame('2026-11-07 02:00:00', $calculator->nextBusinessTime($this->at('2026-11-07 02:00'))->toDateTimeString());
        $this->assertSame(360, $calculator->diffBusinessMinutes($this->at('2026-11-07 00:00'), $this->at('2026-11-07 12:00')));
    }

    public function test_holiday_on_shift_owner_prevents_carry_in_until_existing_extra_day_reopens_it(): void
    {
        $this->nightProfile(['working_hours' => ['Friday' => [['22:00', '06:00']]], 'custom_holidays' => ['2026-11-06' => 'Owner closed']]);
        $closed = Workday::profile('warehouse');

        $this->assertSame([], $this->windows($closed, '2026-11-06'));
        $this->assertSame([], $this->windows($closed, '2026-11-07'));
        config()->set('workdays.definitions.profiles.warehouse.extra_working_days', ['2026-11-06' => 'Owner reopened']);
        $open = Workday::profile('warehouse');
        $this->assertSame([['start' => '22:00', 'end' => '24:00']], $this->windows($open, '2026-11-06'));
        $this->assertSame([['start' => '00:00', 'end' => '06:00']], $this->windows($open, '2026-11-07'));
    }

    public function test_previous_date_exact_and_seasonal_precedence_determine_carry_in(): void
    {
        $calculator = $this->nightProfile([
            'special_working_hours' => ['2026-11-02' => [['21:00', '03:00']], '2026-11-03' => []],
            'seasonal_working_hours' => [$this->season('2026-11-02', '2026-11-02', ['Monday' => [['20:00', '02:00']]])],
        ]);

        $this->assertSame([['start' => '21:00', 'end' => '24:00']], $this->windows($calculator, '2026-11-02'));
        $this->assertSame([['start' => '00:00', 'end' => '03:00']], $this->windows($calculator, '2026-11-03'));
    }

    public function test_season_end_does_not_truncate_incoming_overnight_shift(): void
    {
        $calculator = $this->nightProfile(['seasonal_working_hours' => [$this->season('2026-11-02', '2026-11-02', ['Monday' => [['20:00', '02:00']]])]]);

        $this->assertSame([['start' => '00:00', 'end' => '02:00']], $this->windows($calculator, '2026-11-03'));
    }

    public function test_extra_working_day_fallback_can_start_an_overnight_shift(): void
    {
        $calculator = $this->nightProfile([
            'working_hours' => [], 'extra_working_days' => ['2026-11-07' => 'Extra Saturday'],
            'extra_working_day_hours' => [['22:00', '06:00']],
        ]);

        $this->assertSame([['start' => '22:00', 'end' => '24:00']], $this->windows($calculator, '2026-11-07'));
        $this->assertSame([['start' => '00:00', 'end' => '06:00']], $this->windows($calculator, '2026-11-08'));
    }

    public function test_overlapping_carry_in_and_same_day_shift_are_unioned_without_double_counting(): void
    {
        $calculator = $this->nightProfile(['working_hours' => ['Monday' => [['22:00', '06:00']], 'Tuesday' => [['04:00', '09:00']]]]);

        $this->assertSame([['start' => '00:00', 'end' => '09:00']], $this->windows($calculator, '2026-11-03'));
        $this->assertSame(540, $calculator->diffBusinessMinutes($this->at('2026-11-03 00:00'), $this->at('2026-11-03 10:00')));
        $this->assertSame('2026-11-03 09:00:00', $calculator->addBusinessMinutes($this->at('2026-11-02 23:00'), 600)->toDateTimeString());
        $slots = Workday::deliverySlots('2026-11-03T00:00:00Z', $this->slotOptions());
        $this->assertCount(8, $slots->slots);
        $this->assertCount(8, array_unique(array_column($slots->toArray()['slots'], 'starts_at')));
    }

    public function test_touching_carry_and_same_day_windows_stay_separate_for_slot_generation(): void
    {
        $calculator = $this->nightProfile(['working_hours' => ['Monday' => [['22:00', '06:00']], 'Tuesday' => [['06:00', '09:00']]]]);

        $this->assertSame([['start' => '00:00', 'end' => '06:00'], ['start' => '06:00', 'end' => '09:00']], $this->windows($calculator, '2026-11-03'));
        $this->assertSame(540, $calculator->diffBusinessMinutes($this->at('2026-11-03 00:00'), $this->at('2026-11-03 09:00')));
        $this->assertSame('2026-11-03 06:30:00', $calculator->addBusinessMinutes($this->at('2026-11-03 05:30'), 60)->toDateTimeString());
        $slots = Workday::deliverySlots('2026-11-03T00:00:00Z', $this->slotOptions());
        $this->assertCount(7, $slots->slots);
        $this->assertNotContains(['2026-11-03 05:00', '2026-11-03 07:00'], $this->slotIntervals($slots));
    }

    public function test_same_owner_morning_and_overnight_windows_can_coexist_when_not_overlapping(): void
    {
        $calculator = $this->nightProfile(['working_hours' => ['Monday' => [['00:00', '03:00'], ['22:00', '06:00']]]]);

        $this->assertSame([['start' => '00:00', 'end' => '03:00'], ['start' => '22:00', 'end' => '24:00']], $this->windows($calculator, '2026-11-02'));
        $this->assertSame([['start' => '00:00', 'end' => '06:00']], $this->windows($calculator, '2026-11-03'));
    }

    public function test_full_day_window_and_midnight_ending_window_have_no_zero_length_carry_in(): void
    {
        $calculator = $this->nightProfile(['working_hours' => ['Monday' => [['00:00', '24:00']], 'Tuesday' => [['23:00', '00:00']]]]);

        $this->assertSame([['start' => '00:00', 'end' => '24:00']], $this->windows($calculator, '2026-11-02'));
        $this->assertSame([['start' => '23:00', 'end' => '24:00']], $this->windows($calculator, '2026-11-03'));
        $this->assertSame([], $this->windows($calculator, '2026-11-04'));
        $this->assertSame(1440, $calculator->diffBusinessMinutes($this->at('2026-11-02 00:00'), $this->at('2026-11-03 00:00')));
        $this->assertSame('2026-11-03 00:00:00', $calculator->addBusinessMinutes($this->at('2026-11-02 00:00'), 1440)->toDateTimeString());
    }

    #[DataProvider('invalidExactOverrides')]
    public function test_invalid_exact_date_overrides_are_rejected(mixed $overrides): void
    {
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('special_working_hours');

        $this->configure(['special_working_hours' => $overrides]);
    }

    public static function invalidExactOverrides(): array
    {
        return [
            [null], [true], ['2026-11-02'], [[['09:00', '17:00']]],
            [['2026-02-29' => []]], [['2026-02-30' => []]], [['2026-13-01' => []]],
            [['2026-1-02' => []]], [['2026-11-02 00:00' => []]], [['1405-01-01' => 'No conversion']],
            [['2026-11-02' => null]], [['2026-11-02' => '09:00-17:00']],
        ];
    }

    public function test_valid_gregorian_leap_date_is_accepted(): void
    {
        $calculator = $this->configure(['weekends' => [], 'special_working_hours' => ['2028-02-29' => [['10:00', '12:00']]]]);

        $this->assertSame([['start' => '10:00', 'end' => '12:00']], $this->windows($calculator, '2028-02-29'));
    }

    #[DataProvider('invalidSeasons')]
    public function test_invalid_season_shapes_dates_keys_aliases_and_overlaps_are_rejected(mixed $seasons): void
    {
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('seasonal_working_hours');

        $this->configure(['seasonal_working_hours' => $seasons]);
    }

    public static function invalidSeasons(): iterable
    {
        $valid = ['from' => '2026-11-01', 'through' => '2026-11-30', 'weekdays' => ['Monday' => [['08:00', '20:00']]]];
        foreach (['null' => null, 'scalar' => 'November', 'boolean' => true, 'map' => ['season' => $valid], 'sparse list' => [1 => $valid], 'non-array item' => [false]] as $name => $value) {
            yield $name => [$value];
        }
        foreach (['from', 'through', 'weekdays'] as $missing) {
            $season = $valid;
            unset($season[$missing]);
            yield 'missing '.$missing => [[$season]];
        }
        foreach (['from' => null, 'through' => false, 'weekdays' => null, 'unsupported' => true] as $key => $value) {
            yield 'invalid '.$key => [[array_replace($valid, [$key => $value])]];
        }
        yield 'invalid Gregorian date' => [[array_replace($valid, ['from' => '2026-02-30'])]];
        yield 'reversed range' => [[array_replace($valid, ['from' => '2026-12-01'])]];
        yield 'invalid weekday' => [[array_replace($valid, ['weekdays' => ['Funday' => []]])]];
        yield 'duplicate normalized aliases' => [[array_replace($valid, ['weekdays' => ['Monday' => [], 1 => []]])]];
        yield 'overlap different weekdays' => [[$valid, ['from' => '2026-11-15', 'through' => '2026-12-15', 'weekdays' => ['Tuesday' => []]]]];
        yield 'shared endpoint is overlap' => [[$valid, ['from' => '2026-11-30', 'through' => '2026-12-15', 'weekdays' => []]]];
        yield 'nested date range' => [[$valid, ['from' => '2026-11-10', 'through' => '2026-11-11', 'weekdays' => []]]];
    }

    #[DataProvider('invalidOvernightFlags')]
    public function test_overnight_enablement_is_a_strict_boolean(mixed $enabled): void
    {
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage(is_object($enabled) ? 'Definition profile [warehouse] must contain only arrays and scalar/null data' : 'allow_overnight_working_hours');

        $this->configure(['allow_overnight_working_hours' => $enabled]);
    }

    public static function invalidOvernightFlags(): array
    {
        return [[null], [0], [1], ['true'], ['false'], ['1'], [1.0], [[]], [new \stdClass]];
    }

    #[DataProvider('invalidWindowDefinitions')]
    public function test_invalid_windows_are_rejected_in_every_opted_in_schedule(string $scope, mixed $windows): void
    {
        $config = ['allow_overnight_working_hours' => true];
        $config = array_replace($config, match ($scope) {
            'weekly' => ['working_hours' => ['Monday' => $windows]],
            'special' => ['special_working_hours' => ['2026-11-02' => $windows]],
            'seasonal' => ['seasonal_working_hours' => [$this->season('2026-11-01', '2026-11-30', ['Monday' => $windows])]],
            'fallback' => ['extra_working_day_hours' => $windows],
        });
        $this->expectException(WorkdayConfigurationException::class);

        $this->configure($config);
    }

    public static function invalidWindowDefinitions(): iterable
    {
        $invalid = [
            'scalar' => '09:00-17:00', 'null' => null, 'scalar member' => ['09:00'],
            'missing end' => [['09:00']], 'too many boundaries' => [['09:00', '17:00', '18:00']],
            '24 as start' => [['24:00', '06:00']], 'invalid 25 hour end' => [['09:00', '25:00']],
            'invalid 24 minute end' => [['09:00', '24:01']], 'invalid short hour' => [['9:00', '17:00']],
            'newline after start' => [["09:00\n", '17:00']], 'newline after end' => [['09:00', "17:00\n"]],
            'equal boundaries' => [['22:00', '22:00']], 'equal midnight' => [['00:00', '00:00']],
            'scalar boundary' => [[900, '17:00']], 'boolean boundary' => [['09:00', true]],
            'overlapping daytime' => [['09:00', '12:00'], ['11:00', '17:00']],
            'overlapping overnight' => [['20:00', '06:00'], ['22:00', '05:00']],
        ];
        foreach (['weekly', 'special', 'seasonal', 'fallback'] as $scope) {
            foreach ($invalid as $name => $windows) {
                yield $scope.' '.$name => [$scope, $windows];
            }
        }
    }

    #[DataProvider('legacyInvalidWindows')]
    public function test_without_overnight_opt_in_existing_invalid_windows_remain_rejected(array $window, bool $explicitFalse): void
    {
        $config = ['working_hours' => ['Monday' => [$window]]];
        if ($explicitFalse) {
            $config['allow_overnight_working_hours'] = false;
        }
        $this->expectException(WorkdayConfigurationException::class);

        $this->configure($config);
    }

    public static function legacyInvalidWindows(): array
    {
        return [[['22:00', '06:00'], false], [['00:00', '24:00'], false], [['22:00', '06:00'], true], [['00:00', '24:00'], true]];
    }

    #[DataProvider('overnightScheduleScopes')]
    public function test_valid_overnight_windows_are_supported_in_each_schedule_scope(string $scope): void
    {
        $config = match ($scope) {
            'weekly' => [],
            'special' => ['working_hours' => [], 'special_working_hours' => ['2026-11-02' => [['22:00', '06:00']]]],
            'seasonal' => ['working_hours' => [], 'seasonal_working_hours' => [$this->season('2026-11-01', '2026-11-30', ['Monday' => [['22:00', '06:00']]])]],
            'fallback' => ['working_hours' => [], 'extra_working_days' => ['2026-11-02' => 'Reopened'], 'extra_working_day_hours' => [['22:00', '06:00']]],
        };
        $calculator = $this->nightProfile($config);

        $this->assertSame([['start' => '22:00', 'end' => '24:00']], $this->windows($calculator, '2026-11-02'));
        $this->assertSame([['start' => '00:00', 'end' => '06:00']], $this->windows($calculator, '2026-11-03'));
    }

    public static function overnightScheduleScopes(): array
    {
        return [['weekly'], ['special'], ['seasonal'], ['fallback']];
    }

    #[DataProvider('dstOvernightShifts')]
    public function test_valid_overnight_shifts_use_elapsed_minutes_across_dst(string $start, string $end, int $minutes, string $utcEnd): void
    {
        $calculator = $this->nightProfile(['weekends' => [], 'working_hours' => ['Saturday' => [['22:00', '06:00']]]]);
        $startAt = $this->at($start, 'America/New_York');
        $endAt = $this->at($end, 'America/New_York');

        $this->assertSame($minutes, $calculator->diffBusinessMinutes($startAt, $endAt));
        $this->assertSame(-$minutes, $calculator->diffBusinessMinutes($endAt, $startAt));
        $completion = $calculator->addBusinessMinutes($startAt, $minutes);
        $this->assertSame($utcEnd, $completion->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame($endAt->getTimestamp(), $completion->getTimestamp());
        $this->assertSame($minutes * 60, $completion->getTimestamp() - $startAt->getTimestamp());
    }

    public static function dstOvernightShifts(): array
    {
        return [
            'spring missing hour' => ['2026-03-07 22:00', '2026-03-08 06:00', 420, '2026-03-08 10:00:00'],
            'fall repeated hour' => ['2026-10-31 22:00', '2026-11-01 06:00', 540, '2026-11-01 11:00:00'],
        ];
    }

    public function test_opted_in_daytime_boundaries_use_wall_time_after_spring_transition(): void
    {
        $calculator = $this->configure(['weekends' => [], 'special_working_hours' => ['2026-03-08' => [['03:00', '06:00']]]]);

        $this->assertTrue($calculator->isBusinessTime($this->at('2026-03-08 03:00', 'America/New_York')));
        $this->assertFalse($calculator->isBusinessTime($this->at('2026-03-08 06:00', 'America/New_York')));
        $this->assertSame(180, $calculator->diffBusinessMinutes($this->at('2026-03-08 03:00', 'America/New_York'), $this->at('2026-03-08 06:00', 'America/New_York')));
    }

    #[DataProvider('invalidDstBoundaries')]
    public function test_nonexistent_and_ambiguous_scheduled_boundaries_fail_with_profile_date_and_time(string $date, array $window, string $boundary): void
    {
        $calculator = $this->configure(['weekends' => [], 'special_working_hours' => [$date => [$window]]]);

        try {
            $calculator->workingWindowsFor($this->at($date.' 12:00', 'America/New_York'));
            $this->fail('Invalid scheduled boundaries must not be normalized or disambiguated silently.');
        } catch (RuntimeException|InvalidArgumentException $exception) {
            $this->assertStringContainsString('warehouse', $exception->getMessage());
            $this->assertStringContainsString($date, $exception->getMessage());
            $this->assertStringContainsString($boundary, $exception->getMessage());
        }
    }

    public static function invalidDstBoundaries(): array
    {
        return [
            ['2026-03-08', ['02:30', '04:00'], '02:30'],
            ['2026-03-08', ['00:00', '02:30'], '02:30'],
            ['2026-11-01', ['01:30', '04:00'], '01:30'],
            ['2026-11-01', ['00:00', '01:30'], '01:30'],
        ];
    }

    public function test_invalid_overnight_end_boundary_is_reported_on_its_actual_next_date(): void
    {
        $calculator = $this->nightProfile(['weekends' => [], 'working_hours' => ['Saturday' => [['22:00', '02:30']]]]);

        try {
            $calculator->workingWindowsFor($this->at('2026-03-08 12:00', 'America/New_York'));
            $this->fail('The nonexistent overnight closing boundary must not be normalized.');
        } catch (RuntimeException|InvalidArgumentException $exception) {
            $this->assertStringContainsString('warehouse', $exception->getMessage());
            $this->assertStringContainsString('2026-03-08', $exception->getMessage());
            $this->assertStringContainsString('02:30', $exception->getMessage());
        }
    }

    #[DataProvider('ambiguousMidnightOperations')]
    public function test_split_civil_midnight_fails_consistently_in_effective_window_apis(string $method): void
    {
        $calculator = $this->nightProfile(['weekends' => [], 'working_hours' => ['Saturday' => [['22:00', '06:00']]]]);
        $input = CarbonImmutable::parse('1988-10-30T03:30:00Z')->setTimezone('America/Goose_Bay');

        $this->assertSame('1988-10-29 23:30:00-04:00', $input->format('Y-m-d H:i:sP'));
        try {
            $calculator->{$method}($input);
            $this->fail('An ambiguous synthetic midnight must not create conflicting daily intervals.');
        } catch (RuntimeException|InvalidArgumentException $exception) {
            $this->assertStringContainsString('warehouse', $exception->getMessage());
            $this->assertStringContainsString('1988-10-29', $exception->getMessage());
            $this->assertStringContainsString('America/Goose_Bay', $exception->getMessage());
            $this->assertStringContainsString('moves backward across a civil-date label', $exception->getMessage());
        }
    }

    public static function ambiguousMidnightOperations(): array
    {
        return [['workingWindowsFor'], ['isBusinessTime'], ['nextBusinessTime']];
    }

    public function test_skipped_midnight_uses_first_real_day_instant_for_carry_in(): void
    {
        $calculator = $this->nightProfile(['weekends' => [], 'working_hours' => ['Saturday' => [['22:00', '06:00']]]]);
        $start = $this->at('2026-09-05 22:00', 'America/Santiago');
        $end = $this->at('2026-09-06 06:00', 'America/Santiago');

        $this->assertSame([['start' => '00:00', 'end' => '06:00']], $this->windows($calculator, $this->at('2026-09-06 12:00', 'America/Santiago')));
        $this->assertSame(420, $calculator->diffBusinessMinutes($start, $end));
        $this->assertSame($end->getTimestamp(), $calculator->addBusinessMinutes($start, 420)->getTimestamp());
        $this->assertTrue($calculator->isBusinessTime($this->at('2026-09-06 01:00', 'America/Santiago')));
        $this->assertSame('2026-09-06 03:00:00', $calculator->addBusinessMinutes($this->at('2026-09-05 23:00', 'America/Santiago'), 180)->toDateTimeString());
    }

    public function test_skipped_civil_date_does_not_extend_forward_search_date_labels(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $calculator = $this->configure(['weekends' => [], 'special_working_hours' => [], 'working_hours' => array_fill_keys(range(1, 7), [['09:00', '17:00']])]);

        $this->expectException(RuntimeException::class);
        $calculator->nextBusinessTime($this->at('2011-12-29 18:00', 'Pacific/Apia'));
    }

    public function test_skipped_civil_date_counts_as_a_label_without_fabricating_working_minutes(): void
    {
        config()->set('workdays.max_scan_days', 2);
        $calculator = $this->configure(['weekends' => [], 'special_working_hours' => ['2011-12-30' => [['00:00', '23:00']]], 'working_hours' => array_fill_keys(range(1, 7), [['09:00', '17:00']])]);

        $this->assertSame('2011-12-31 09:00:00+14:00', $calculator->nextBusinessTime($this->at('2011-12-29 18:00', 'Pacific/Apia'))->format('Y-m-d H:i:sP'));
        $this->assertSame(960, $calculator->diffBusinessMinutes($this->at('2011-12-29 09:00', 'Pacific/Apia'), $this->at('2011-12-31 17:00', 'Pacific/Apia')));
        $this->assertSame('2011-12-29 16:59:59-10:00', $calculator->previousBusinessTime($this->at('2011-12-31 08:00', 'Pacific/Apia'))->format('Y-m-d H:i:sP'));
    }

    public function test_skipped_civil_date_does_not_extend_backward_search_date_labels(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $calculator = $this->configure(['weekends' => [], 'special_working_hours' => [], 'working_hours' => array_fill_keys(range(1, 7), [['09:00', '17:00']])]);

        $this->expectException(RuntimeException::class);
        $calculator->previousBusinessTime($this->at('2011-12-31 08:00', 'Pacific/Apia'));
    }

    public function test_all_closed_enhanced_schedule_keeps_bounded_search_failure(): void
    {
        config()->set('workdays.max_scan_days', 2);
        $calculator = $this->configure(['special_working_hours' => [], 'working_hours' => []]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[2]');

        $calculator->nextBusinessTime($this->at('2026-11-02 08:00'));
    }

    public function test_config_owner_holiday_prevents_shift_and_existing_calculator_definition_view_is_preserved(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        $calculator = $this->nightProfile(['working_hours' => ['Friday' => [['22:00', '06:00']]], 'custom_holidays' => ['2026-11-06' => 'Configured owner closure']]);

        $this->assertSame([], $this->windows($calculator, '2026-11-07'));
        $this->assertSame([], $this->windows($calculator, '2026-11-06'));
        config()->set('workdays.definitions.profiles.warehouse.extra_working_days', ['2026-11-06' => 'Configured reopening']);
        $this->assertSame([], $this->windows($calculator, '2026-11-07'));
        $newCalculator = Workday::profile('warehouse');
        $this->assertSame([['start' => '00:00', 'end' => '06:00']], $this->windows($newCalculator, '2026-11-07'));
    }

    public function test_config_reopening_overrides_its_owner_holiday_for_special_shift(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        $calculator = $this->nightProfile([
            'custom_holidays' => ['2026-11-06' => 'Configured closure'],
            'extra_working_days' => ['2026-11-06' => 'Configured reopening'],
            'working_hours' => [], 'special_working_hours' => ['2026-11-06' => [['21:00', '05:00']]],
        ]);

        $this->assertSame([['start' => '21:00', 'end' => '24:00']], $this->windows($calculator, '2026-11-06'));
        $this->assertSame([['start' => '00:00', 'end' => '05:00']], $this->windows($calculator, '2026-11-07'));
    }

    public function test_config_receiving_holiday_does_not_cancel_valid_carry_in(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        $calculator = $this->nightProfile(['working_hours' => ['Friday' => [['22:00', '06:00']]], 'custom_holidays' => ['2026-11-07' => 'Receiving closure']]);

        $this->assertSame([['start' => '00:00', 'end' => '06:00']], $this->windows($calculator, '2026-11-07'));
        $this->assertFalse($calculator->isBusinessDay('2026-11-07'));
    }

    public function test_dense_config_overnight_slots_perform_no_database_access(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        config()->set('workdays.definitions.profiles.warehouse', array_replace($this->baseProfile(), [
            'allow_overnight_working_hours' => true,
            'working_hours' => ['Monday' => [['22:00', '06:00']], 'Tuesday' => [['22:00', '06:00']]],
        ]));
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();

        $result = Workday::deliverySlots('2026-11-02T22:00:00Z', $this->slotOptions(['days' => 2, 'duration_minutes' => 30, 'step_minutes' => 5, 'max_slots' => 200]));

        $this->assertCount(105, $result->slots);
        $this->assertCount(0, $connection->getQueryLog());
        foreach ($connection->getQueryLog() as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
        $connection->disableQueryLog();
    }

    public function test_phase_two_overnight_preparation_and_post_midnight_handoff_use_both_profiles(): void
    {
        $this->nightProfile();
        config()->set('workdays.definitions.profiles.carrier', array_replace($this->baseProfile(), ['working_hours' => ['Tuesday' => [['01:00', '04:00']]], 'special_working_hours' => []]));
        $plan = [
            'timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC', 'carrier' => 'UTC'],
            'stages' => [
                ['id' => 'prepare_for_collection', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 180],
                ['id' => 'carrier_handoff', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']],
            ],
        ];

        $result = Workday::planDelivery('2026-11-02T23:00:00Z', $plan);

        $this->assertSame('2026-11-03T02:00:00.000000+00:00', $result->stages[0]->completedAt->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('2026-11-03T02:00:00.000000+00:00', $result->stages[1]->startedAt->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame($result->stages[1]->completedAt->getTimestamp(), $result->estimatedCompletionAt->getTimestamp());
    }

    public function test_phase_two_handoff_during_carry_in_waits_for_carrier_opening(): void
    {
        $this->nightProfile();
        config()->set('workdays.definitions.profiles.carrier', array_replace($this->baseProfile(), ['working_hours' => ['Tuesday' => [['02:00', '04:00']]]]));
        $plan = ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC', 'carrier' => 'UTC'], 'stages' => [['id' => 'handoff', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']]]];

        $result = Workday::planDelivery('2026-11-02T23:00:00Z', $plan);

        $this->assertSame('2026-11-03 02:00:00', $result->estimatedCompletionAt->toDateTimeString());
    }

    public function test_phase_two_handoff_cannot_use_touching_closing_and_opening_boundaries(): void
    {
        config()->set('workdays.max_scan_days', 2);
        $this->nightProfile();
        config()->set('workdays.definitions.profiles.carrier', array_replace($this->baseProfile(), ['working_hours' => ['Tuesday' => [['06:00', '08:00']]]]));
        $plan = ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC', 'carrier' => 'UTC'], 'stages' => [['id' => 'handoff', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']]]];
        $this->expectException(RuntimeException::class);

        Workday::planDelivery('2026-11-03T05:00:00Z', $plan);
    }

    public function test_phase_two_work_can_use_carry_in_on_closed_weekend_date(): void
    {
        $this->nightProfile(['working_hours' => ['Friday' => [['22:00', '06:00']]]]);

        $result = Workday::planDelivery('2026-11-07T02:00:00Z', $this->workPlan(120));

        $this->assertSame('2026-11-07 02:00:00', $result->stages[0]->startedAt->toDateTimeString());
        $this->assertSame('2026-11-07 04:00:00', $result->estimatedCompletionAt->toDateTimeString());
        $this->assertFalse(Workday::profile('warehouse')->isBusinessDay('2026-11-07'));
    }

    public function test_phase_two_capacity_check_keeps_minutes_at_artificial_midnight_split(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $this->nightProfile();

        $result = Workday::planDelivery('2026-11-02T22:00:00Z', $this->workPlan(480));

        $this->assertSame('2026-11-03 06:00:00', $result->estimatedCompletionAt->toDateTimeString());
    }

    public function test_phase_two_fractional_midnight_work_preserves_exact_completion_instant(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $this->nightProfile();

        $result = Workday::planDelivery('2026-11-02T23:59:30.123456Z', $this->workPlan(1));

        $this->assertSame('2026-11-03T00:00:30.123456+00:00', $result->estimatedCompletionAt->format('Y-m-d\TH:i:s.uP'));
    }

    public function test_phase_two_profile_local_slices_preserve_capacity_at_partial_global_horizon(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $this->nightProfile(['working_hours' => ['Tuesday' => [['22:00', '06:00']]]]);
        $plan = [
            'timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'Asia/Tehran'],
            'stages' => [
                ['id' => 'calendar_transit', 'type' => 'business_days', 'profile' => 'warehouse', 'days' => 1],
                ['id' => 'ready_for_collection', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 329],
            ],
        ];

        $result = Workday::planDelivery('2026-11-02T18:30:00.123456Z', $plan);

        $this->assertSame('2026-11-03T23:59:00.123456+00:00', $result->estimatedCompletionAt->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('2026-11-04 03:29:00.123456+03:30', $result->estimatedCompletionAt->setTimezone('Asia/Tehran')->format('Y-m-d H:i:s.uP'));
    }

    #[DataProvider('planningOverrides')]
    public function test_phase_two_work_automatically_uses_exact_or_seasonal_hours(array $overrides, string $input, int $minutes, string $expected): void
    {
        $this->configure($overrides);

        $result = Workday::planDelivery($input, $this->workPlan($minutes));

        $this->assertSame($expected, $result->estimatedCompletionAt->toDateTimeString());
    }

    public static function planningOverrides(): array
    {
        return [
            'exact shorter day' => [['special_working_hours' => ['2026-11-02' => [['10:00', '12:00']]]], '2026-11-02T11:00:00Z', 120, '2026-11-03 10:00:00'],
            'season extended day' => [['seasonal_working_hours' => [['from' => '2026-11-01', 'through' => '2026-11-30', 'weekdays' => ['Monday' => [['08:00', '20:00']]]]]], '2026-11-09T18:00:00Z', 60, '2026-11-09 19:00:00'],
        ];
    }

    public function test_phase_two_business_day_stage_remains_independent_of_explicit_closed_hours(): void
    {
        $this->configure(['special_working_hours' => ['2026-11-03' => []]]);
        $plan = ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC'], 'stages' => [['id' => 'calendar_transit', 'type' => 'business_days', 'profile' => 'warehouse', 'days' => 1]]];

        $result = Workday::planDelivery('2026-11-02T10:00:00Z', $plan);

        $this->assertSame('2026-11-03 10:00:00', $result->estimatedCompletionAt->toDateTimeString());
        $this->assertSame([], Workday::profile('warehouse')->workingWindowsFor($this->at('2026-11-03')));
    }

    public function test_phase_three_exact_and_seasonal_overrides_change_eligible_slots(): void
    {
        $this->configure([
            'special_working_hours' => ['2026-11-02' => [['10:00', '12:00']]],
            'seasonal_working_hours' => [$this->season('2026-11-01', '2026-11-30', ['Monday' => [['08:00', '20:00']]])],
        ]);

        $this->assertSame([['2026-11-02 10:00', '2026-11-02 12:00']], $this->slotIntervals(Workday::deliverySlots('2026-11-02T08:00:00Z', $this->slotOptions())));
        $seasonal = Workday::deliverySlots('2026-11-09T18:00:00Z', $this->slotOptions());
        $this->assertSame([['2026-11-09 18:00', '2026-11-09 20:00']], $this->slotIntervals($seasonal));
    }

    public function test_phase_three_daily_fragments_allow_24_end_but_never_a_cross_midnight_slot(): void
    {
        $this->nightProfile();
        $result = Workday::deliverySlots('2026-11-02T21:00:00Z', $this->slotOptions(['days' => 2]));

        $this->assertSame([
            ['2026-11-02 22:00', '2026-11-03 00:00'],
            ['2026-11-03 00:00', '2026-11-03 02:00'], ['2026-11-03 01:00', '2026-11-03 03:00'],
            ['2026-11-03 02:00', '2026-11-03 04:00'], ['2026-11-03 03:00', '2026-11-03 05:00'], ['2026-11-03 04:00', '2026-11-03 06:00'],
        ], $this->slotIntervals($result));
        $this->assertNotContains(['2026-11-02 23:00', '2026-11-03 01:00'], $this->slotIntervals($result));
        $oneDay = Workday::deliverySlots('2026-11-02T21:00:00Z', $this->slotOptions());
        $this->assertCount(1, $oneDay->slots);
        $this->assertSame('2026-11-03T00:00:00.000000+00:00', $oneDay->slots[0]->toArray()['ends_at']);
        $this->assertSame([], Workday::deliverySlots('2026-11-03T06:00:00Z', $this->slotOptions())->slots);
    }

    public function test_phase_three_full_day_windows_preserve_notice_max_slots_and_date_limits(): void
    {
        $this->nightProfile(['working_hours' => ['Monday' => [['00:00', '24:00']]]]);
        $result = Workday::deliverySlots('2026-11-02T21:00:00Z', $this->slotOptions(['min_notice_minutes' => 60, 'max_slots' => 1]));

        $this->assertSame([['2026-11-02 22:00', '2026-11-03 00:00']], $this->slotIntervals($result));
        $this->assertSame('2026-11-02T22:00:00.000000+00:00', $result->eligibleFromAt->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame(1, $result->requestedDays);
    }

    public function test_phase_three_midnight_gap_does_not_fabricate_a_slot_ending_at_invalid_24_boundary(): void
    {
        $this->nightProfile(['weekends' => [], 'working_hours' => ['Saturday' => [['22:00', '06:00']]]]);
        $result = Workday::deliverySlots('2026-09-05T21:00:00-04:00', $this->slotOptions(['timezone' => 'America/Santiago', 'days' => 2, 'step_minutes' => 120]));

        $this->assertSame([
            ['2026-09-06 02:00', '2026-09-06 04:00'], ['2026-09-06 04:00', '2026-09-06 06:00'],
        ], $this->slotIntervals($result, 'America/Santiago'));
        foreach ($result->slots as $slot) {
            $this->assertSame(7200, $slot->endsAt->getTimestamp() - $slot->startsAt->getTimestamp());
        }
    }

    public function test_enhanced_business_time_uses_input_timezone_and_never_mutates_caller_datetime(): void
    {
        $calculator = $this->nightProfile();
        $input = new DateTime('2026-11-02 23:00:12.123456', new DateTimeZone('Asia/Tehran'));
        $before = $input->format('Y-m-d H:i:s.uP');

        $completion = $calculator->addBusinessMinutes($input, 180);

        $this->assertSame('2026-11-03 02:00:12.123456+03:30', $completion->format('Y-m-d H:i:s.uP'));
        $this->assertSame('Asia/Tehran', $completion->getTimezone()->getName());
        $this->assertSame($before, $input->format('Y-m-d H:i:s.uP'));
        $this->assertInstanceOf(CarbonImmutable::class, $completion);
    }

    public function test_daytime_profiles_do_not_gain_previous_day_provider_lookups(): void
    {
        $profile = $this->baseProfile();
        $provider = new class(new LogicalHolidayProvider(static fn (string $name): array => $profile)) implements HolidayProviderInterface
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
        $calculator = new BusinessDayCalculator('warehouse', $profile, holidayProvider: $provider, includeStartDate: false, maxScanDays: 3660, jalaliCalendar: new JalaliCalendarAdapter, hijriCalendar: new HijriCalendarAdapter('umm_al_qura', 0));

        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $this->windows($calculator, '2026-11-02'));
        $this->assertSame(['2026-11-02', '2026-11-02'], $provider->dateEvaluations);
    }

    public function test_absent_and_false_flags_use_exact_fractional_minute_arithmetic(): void
    {
        $hours = ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]];
        $legacy = $this->configure(['working_hours' => $hours]);
        $falseOnly = $this->configure(['working_hours' => $hours, 'allow_overnight_working_hours' => false]);
        $input = $this->at('2026-11-02 09:59:30');

        // Refactor 03 corrects the historical discarded partial minute (11:01:00).
        $this->assertSame('2026-11-02 11:00:30', $legacy->addBusinessMinutes($input, 1)->toDateTimeString());
        $this->assertSame('2026-11-02 11:00:30', $falseOnly->addBusinessMinutes($input, 1)->toDateTimeString());
    }

    public function test_absent_and_false_flags_share_microsecond_navigation_and_total_difference(): void
    {
        $hours = ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]];
        $legacy = $this->configure(['working_hours' => $hours]);
        $falseOnly = $this->configure(['working_hours' => $hours, 'allow_overnight_working_hours' => false]);
        $snapshot = function (BusinessDayCalculator $calculator): array {
            return [
                'windows' => $this->windows($calculator, '2026-11-02'),
                'next_at_closing' => $calculator->nextBusinessTime($this->at('2026-11-02 10:00'))->format('Y-m-d H:i:s.u'),
                'previous_at_closing' => $calculator->previousBusinessTime($this->at('2026-11-02 10:00'))->format('Y-m-d H:i:s.u'),
                'diff' => $calculator->diffBusinessMinutes($this->at('2026-11-02 09:59:30'), $this->at('2026-11-02 11:00:30')),
                'inside' => $calculator->isBusinessTime($this->at('2026-11-02 09:59:30')),
                'closed' => $calculator->isBusinessTime($this->at('2026-11-02 10:00')),
            ];
        };
        $expected = $snapshot($legacy);

        $this->assertSame([
            'windows' => [['start' => '09:00', 'end' => '10:00'], ['start' => '11:00', 'end' => '12:00']],
            'next_at_closing' => '2026-11-02 11:00:00.000000',
            'previous_at_closing' => '2026-11-02 09:59:59.999999',
            'diff' => 1, 'inside' => true, 'closed' => false,
        ], $expected);
        $this->assertSame($expected, $snapshot($falseOnly));
    }

    public function test_absent_and_false_flags_share_date_local_holiday_provider_lookups(): void
    {
        $profile = $this->baseProfile();
        $legacyReads = $this->calendarLookupTrace($profile);

        $this->assertSame(['extra:2026-11-02', 'holiday:2026-11-02'], $legacyReads);
        $this->assertSame($legacyReads, $this->calendarLookupTrace($profile + ['allow_overnight_working_hours' => false]));
    }

    #[DataProvider('equivalentScheduleConfigurations')]
    public function test_feature_keys_do_not_select_numerical_algorithms(array $profile): void
    {
        $calculator = $this->configure(array_replace(['working_hours' => ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]]], $profile));
        $this->assertSame('2026-11-02 11:00:30.123456', $calculator->addBusinessMinutes($this->at('2026-11-02 09:59:30.123456'), 1)->format('Y-m-d H:i:s.u'));
        $this->assertSame(1, $calculator->diffBusinessMinutes($this->at('2026-11-02 09:59:30'), $this->at('2026-11-02 11:00:30')));
    }

    public static function equivalentScheduleConfigurations(): array
    {
        return [
            'no new keys' => [[]],
            'false flag only' => [['allow_overnight_working_hours' => false]],
            'true flag only' => [['allow_overnight_working_hours' => true]],
            'exact hours with false flag' => [['allow_overnight_working_hours' => false, 'special_working_hours' => []]],
            'seasonal hours with false flag' => [['allow_overnight_working_hours' => false, 'seasonal_working_hours' => []]],
            'exact hours without flag' => [['special_working_hours' => []]],
            'seasonal hours without flag' => [['seasonal_working_hours' => []]],
        ];
    }

    #[DataProvider('enhancedFlagConfigurations')]
    public function test_overnight_permission_and_overlays_use_the_unified_schedule(array $config, string $expected): void
    {
        $calculator = $this->configure(array_replace(['working_hours' => ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]]], $config));

        $this->assertSame($expected, $calculator->addBusinessMinutes($this->at('2026-11-02 09:59:30'), 1)->toDateTimeString());
    }

    public static function enhancedFlagConfigurations(): array
    {
        return [
            'true flag keeps enhanced fractional minutes' => [['allow_overnight_working_hours' => true], '2026-11-02 11:00:30'],
            'explicit exact key with false keeps enhanced precision' => [['allow_overnight_working_hours' => false, 'special_working_hours' => []], '2026-11-02 11:00:30'],
            'explicit season key with false keeps enhanced precision' => [['allow_overnight_working_hours' => false, 'seasonal_working_hours' => []], '2026-11-02 11:00:30'],
            'exact override with false stays effective' => [['allow_overnight_working_hours' => false, 'special_working_hours' => ['2026-11-02' => [['10:00', '12:00']]]], '2026-11-02 10:01:00'],
            'seasonal override with false stays effective' => [['allow_overnight_working_hours' => false, 'seasonal_working_hours' => [['from' => '2026-11-01', 'through' => '2026-11-30', 'weekdays' => ['Monday' => [['10:00', '12:00']]]]]], '2026-11-02 10:01:00'],
        ];
    }

    private function calendarLookupTrace(array $profile): array
    {
        $reads = [];
        $provider = $this->createStub(HolidayProviderInterface::class);
        $provider->method('recurringHolidays')->willReturn([]);
        $provider->method('hasCustomHoliday')->willReturnCallback(static function (string $profile, CarbonImmutable $date) use (&$reads): bool {
            $reads[] = 'holiday:'.$date->toDateString();

            return false;
        });
        $provider->method('hasExtraWorkingDay')->willReturnCallback(static function (string $profile, CarbonImmutable $date) use (&$reads): bool {
            $reads[] = 'extra:'.$date->toDateString();

            return false;
        });
        $calculator = new BusinessDayCalculator('warehouse', $profile, holidayProvider: $provider, includeStartDate: false, maxScanDays: 3660, jalaliCalendar: new JalaliCalendarAdapter, hijriCalendar: new HijriCalendarAdapter('umm_al_qura', 0));
        $this->windows($calculator, '2026-11-02');

        return $reads;
    }

    private function baseProfile(): array
    {
        return [
            'weekends' => ['Saturday', 'Sunday'],
            'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
            'custom_holidays' => [], 'extra_working_days' => [],
            'working_hours' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], [['09:00', '17:00']]),
            'cutoff' => ['timezone' => 'UTC', 'time' => '14:00'],
        ];
    }

    private function configure(array $overrides): BusinessDayCalculator
    {
        config()->set('workdays.definitions.profiles.warehouse', array_replace($this->baseProfile(), $overrides));

        return Workday::profile('warehouse');
    }

    private function nightProfile(array $overrides = []): BusinessDayCalculator
    {
        return $this->configure(array_replace(['allow_overnight_working_hours' => true, 'working_hours' => ['Monday' => [['22:00', '06:00']]]], $overrides));
    }

    private function season(string $from, string $through, array $weekdays): array
    {
        return ['from' => $from, 'through' => $through, 'weekdays' => $weekdays];
    }

    private function at(string $value, string $timezone = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse($value, $timezone);
    }

    private function windows(BusinessDayCalculator $calculator, string|DateTimeInterface $date): array
    {
        return array_map(static fn (TimeWindow $window): array => $window->toArray(), $calculator->workingWindowsFor(is_string($date) ? $this->at($date) : $date));
    }

    private function workPlan(int $minutes): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC'], 'stages' => [['id' => 'ready_for_collection', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => $minutes]]];
    }

    private function slotOptions(array $overrides = []): array
    {
        return array_replace(['profile' => 'warehouse', 'timezone' => 'UTC', 'days' => 1, 'duration_minutes' => 120, 'step_minutes' => 60, 'max_slots' => 50], $overrides);
    }

    private function slotIntervals(DeliverySlotsResult $result, string $timezone = 'UTC'): array
    {
        return array_map(static fn (DeliverySlot $slot): array => [$slot->startsAt->setTimezone($timezone)->format('Y-m-d H:i'), $slot->endsAt->setTimezone($timezone)->format('Y-m-d H:i')], $result->slots);
    }
}
