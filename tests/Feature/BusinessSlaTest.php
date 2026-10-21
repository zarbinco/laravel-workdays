<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\Holiday;
use Zarbinco\LaravelWorkdays\Data\SlaResult;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Holidays\HolidayProviderInterface;
use Zarbinco\LaravelWorkdays\Holidays\LogicalHolidayProvider;
use Zarbinco\LaravelWorkdays\Sla\SlaEvaluator;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class BusinessSlaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Pacific/Honolulu');
        config()->set('workdays.definitions.default_profile', 'support');
        config()->set('workdays.driver', 'config');
        config()->set('workdays.max_scan_days', 31);
        config()->set('workdays.definitions.hijri', ['method' => 'mathematical', 'adjustment' => 0]);
        config()->set('workdays.definitions.profiles', ['support' => $this->profile()]);
    }

    #[DataProvider('ordinaryDstWallClockCases')]
    public function test_microfix_ordinary_dst_working_boundaries_use_configured_wall_clock_times(string $timezone, array $hours, string $started, string $evaluated, string $due, int $consumed, int $remaining): void
    {
        config()->set('workdays.definitions.profiles.support', array_replace($this->profile(), ['weekends' => [], 'working_hours' => $hours]));

        $result = $this->evaluate($started, $evaluated, ['timezone' => $timezone, 'target_minutes' => 60]);

        $this->assertSame($due, $this->format($result->dueAt), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        $this->assertSame($consumed, $result->consumedWorkingMinutes);
        $this->assertSame($remaining, $result->remainingWorkingMinutes);
        $this->assertSame(0, $result->pausedWorkingMinutes);
        $this->assertFalse($result->breached);
        $this->assertSame('active', $result->status);
    }

    public static function ordinaryDstWallClockCases(): array
    {
        $newYorkHours = ['Sunday' => [['00:00', '04:00']], 'Monday' => [['09:00', '17:00']]];

        return [
            'A New York fall back' => ['America/New_York', $newYorkHours, '2026-11-01T02:30:00-05:00', '2026-11-01T03:30:00-05:00', '2026-11-01T08:30:00.000000+00:00', 60, 0],
            'B New York spring forward' => ['America/New_York', $newYorkHours, '2026-03-08T03:30:00-04:00', '2026-03-08T04:30:00-04:00', '2026-03-09T13:30:00.000000+00:00', 30, 30],
            'C Santiago skipped midnight' => ['America/Santiago', ['Sunday' => [['09:00', '17:00']], 'Monday' => [['09:00', '17:00']]], '2026-09-06T09:00:00-03:00', '2026-09-06T10:00:00-03:00', '2026-09-06T13:00:00.000000+00:00', 60, 0],
        ];
    }

    #[DataProvider('ordinaryInvalidDstBoundaries')]
    public function test_microfix_ordinary_profiles_reject_nonexistent_and_ambiguous_scheduled_boundaries(string $started, array $hours): void
    {
        config()->set('workdays.definitions.profiles.support', array_replace($this->profile(), ['weekends' => [], 'working_hours' => $hours]));
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('support');

        $this->evaluate($started, $started, ['timezone' => 'America/New_York', 'target_minutes' => 60]);
    }

    public static function ordinaryInvalidDstBoundaries(): array
    {
        return [
            'nonexistent spring opening' => ['2026-03-08T00:00:00-05:00', ['Sunday' => [['02:30', '04:00']]]],
            'ambiguous fall closing' => ['2026-11-01T00:00:00-04:00', ['Sunday' => [['00:00', '01:30']]]],
        ];
    }

    public function test_overnight_derived_midnight_fragment_uses_first_real_instant_on_skipped_midnight_date(): void
    {
        config()->set('workdays.definitions.profiles.support', array_replace($this->profile(), [
            'weekends' => [], 'allow_overnight_working_hours' => true,
            'working_hours' => ['Saturday' => [['22:00', '03:00']]],
        ]));

        $result = $this->evaluate('2026-09-06T01:00:00-03:00', '2026-09-06T03:00:00-03:00', ['timezone' => 'America/Santiago', 'target_minutes' => 120]);

        $this->assertSame('2026-09-06T06:00:00.000000+00:00', $this->format($result->dueAt));
        $this->assertSame(120, $result->consumedWorkingMinutes);
        $this->assertSame(0, $result->remainingWorkingMinutes);
        $this->assertFalse($result->breached);
    }

    #[DataProvider('ordinaryDstPauseCases')]
    public function test_microfix_ordinary_dst_pauses_use_elapsed_intersections_and_exact_closing_breach_boundary(string $started, string $evaluated, array $pause, int $target, string $due): void
    {
        config()->set('workdays.definitions.profiles.support', array_replace($this->profile(), ['weekends' => [], 'working_hours' => ['Sunday' => [['00:00', '04:00']]]]));
        $options = ['timezone' => 'America/New_York', 'target_minutes' => $target, 'pauses' => [$pause]];

        $exact = $this->evaluate($started, $evaluated, $options);
        $after = $this->evaluate($started, CarbonImmutable::parse($evaluated)->addMicrosecond(), $options);

        $this->assertSame($due, $this->format($exact->dueAt));
        $this->assertSame($target, $exact->consumedWorkingMinutes);
        $this->assertSame(60, $exact->pausedWorkingMinutes);
        $this->assertSame(0, $exact->remainingWorkingMinutes);
        $this->assertFalse($exact->breached);
        $this->assertSame($due, $this->format($after->dueAt));
        $this->assertSame($target, $after->consumedWorkingMinutes);
        $this->assertSame(0, $after->overdueWorkingMinutes);
        $this->assertTrue($after->breached);
        $this->assertSame('breached', $after->status);
    }

    public static function ordinaryDstPauseCases(): array
    {
        return [
            'spring gap pause' => ['2026-03-08T00:00:00-05:00', '2026-03-08T04:00:00-04:00', ['started_at' => '2026-03-08T01:30:00-05:00', 'ended_at' => '2026-03-08T03:30:00-04:00'], 120, '2026-03-08T08:00:00.000000+00:00'],
            'fall fold pause' => ['2026-11-01T00:00:00-04:00', '2026-11-01T04:00:00-05:00', ['started_at' => '2026-11-01T01:30:00-04:00', 'ended_at' => '2026-11-01T01:30:00-05:00'], 240, '2026-11-01T09:00:00.000000+00:00'],
        ];
    }

    public function test_a_unpaused_sla_consumes_only_working_time_and_projects_the_deadline(): void
    {
        $result = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T10:30:00Z');

        $this->assertInstanceOf(SlaResult::class, $result);
        $this->assertSame('2026-11-03T11:00:00.000000+00:00', $this->format($result->dueAt));
        $this->assertSame(210, $result->consumedWorkingMinutes);
        $this->assertSame(0, $result->pausedWorkingMinutes);
        $this->assertSame(30, $result->remainingWorkingMinutes);
        $this->assertSame(0, $result->overdueWorkingMinutes);
        $this->assertFalse($result->paused);
        $this->assertFalse($result->breached);
        $this->assertSame('active', $result->status);
    }

    public function test_b_closed_working_pause_extends_the_deadline_by_eligible_time(): void
    {
        $result = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T11:30:00Z', [
            'pauses' => [$this->pause('2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z')],
        ]);

        $this->assertSame('2026-11-03T12:00:00.000000+00:00', $this->format($result->dueAt));
        $this->assertSame(210, $result->consumedWorkingMinutes);
        $this->assertSame(60, $result->pausedWorkingMinutes);
        $this->assertSame(30, $result->remainingWorkingMinutes);
        $this->assertFalse($result->breached);
        $this->assertFalse($result->paused);
        $this->assertSame('active', $result->status);
    }

    #[DataProvider('equivalentPauseUnions')]
    public function test_c_overlapping_duplicate_unsorted_touching_and_offset_pauses_have_the_same_union(array $pauses): void
    {
        $expected = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T11:30:00Z', [
            'pauses' => [$this->pause('2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z')],
        ]);

        $actual = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T11:30:00Z', ['pauses' => $pauses]);

        $this->assertSame($expected->toArray(), $actual->toArray());
    }

    public static function equivalentPauseUnions(): array
    {
        return [
            'overlap' => [[['started_at' => '2026-11-03T10:00:00Z', 'ended_at' => '2026-11-03T10:45:00Z'], ['started_at' => '2026-11-03T10:30:00Z', 'ended_at' => '2026-11-03T11:00:00Z']]],
            'duplicates' => [array_fill(0, 3, ['started_at' => '2026-11-03T10:00:00Z', 'ended_at' => '2026-11-03T11:00:00Z'])],
            'unsorted touching' => [[['started_at' => '2026-11-03T10:30:00Z', 'ended_at' => '2026-11-03T11:00:00Z'], ['started_at' => '2026-11-03T10:00:00Z', 'ended_at' => '2026-11-03T10:30:00Z']]],
            'equivalent offsets' => [[['started_at' => '2026-11-03T13:30:00+03:30', 'ended_at' => '2026-11-03T06:00:00-05:00']]],
        ];
    }

    public function test_d_open_pause_has_unknown_due_date_until_the_caller_supplies_a_real_resume(): void
    {
        $open = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T11:30:00Z', [
            'pauses' => [$this->pause('2026-11-03T10:00:00Z', null)],
        ]);
        $later = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T12:00:00Z', [
            'pauses' => [$this->pause('2026-11-03T10:00:00Z', null)],
        ]);
        $resumed = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T12:00:00Z', [
            'pauses' => [$this->pause('2026-11-03T10:00:00Z', '2026-11-03T11:30:00Z')],
        ]);

        $this->assertSame(180, $open->consumedWorkingMinutes);
        $this->assertSame(60, $open->remainingWorkingMinutes);
        $this->assertSame(90, $open->pausedWorkingMinutes);
        $this->assertNull($open->dueAt);
        $this->assertTrue($open->paused);
        $this->assertFalse($open->breached);
        $this->assertSame('paused', $open->status);
        $this->assertNull($later->dueAt);
        $this->assertSame(180, $later->consumedWorkingMinutes);
        $this->assertSame(120, $later->pausedWorkingMinutes);
        $this->assertSame('2026-11-03T12:30:00.000000+00:00', $this->format($resumed->dueAt));
        $this->assertFalse($resumed->paused);
        $this->assertSame(210, $resumed->consumedWorkingMinutes);
    }

    #[DataProvider('deadlineBoundaries')]
    public function test_e_exact_deadline_and_microsecond_after_use_instant_comparison(string $started, string $evaluated, int $target, string $due, bool $breached, int $overdue): void
    {
        $result = $this->evaluate($started, $evaluated, ['target_minutes' => $target]);

        $this->assertSame($due, $this->format($result->dueAt));
        $this->assertSame($breached, $result->breached);
        $this->assertSame($breached ? 'breached' : 'active', $result->status);
        $this->assertSame(0, $result->remainingWorkingMinutes);
        $this->assertSame($overdue, $result->overdueWorkingMinutes);
    }

    public static function deadlineBoundaries(): array
    {
        return [
            ['2026-11-02T15:00:00Z', '2026-11-03T11:00:00.000000Z', 240, '2026-11-03T11:00:00.000000+00:00', false, 0],
            ['2026-11-02T15:00:00Z', '2026-11-03T11:00:00.000001Z', 240, '2026-11-03T11:00:00.000000+00:00', true, 0],
            ['2026-11-02T16:00:00Z', '2026-11-02T17:00:00.000000Z', 60, '2026-11-02T17:00:00.000000+00:00', false, 0],
            ['2026-11-02T16:00:00Z', '2026-11-02T17:00:00.000001Z', 60, '2026-11-02T17:00:00.000000+00:00', true, 0],
            ['2026-11-02T16:00:00Z', '2026-11-03T09:01:59.999999Z', 60, '2026-11-02T17:00:00.000000+00:00', true, 1],
        ];
    }

    public function test_f_pause_after_breach_never_retroactively_extends_the_historical_due_date(): void
    {
        $result = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T12:00:00Z', [
            'target_minutes' => 60, 'pauses' => [$this->pause('2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z')],
        ]);

        $this->assertSame('2026-11-02T16:00:00.000000+00:00', $this->format($result->dueAt));
        $this->assertTrue($result->breached);
        $this->assertSame('breached', $result->status);
        $this->assertSame(240, $result->consumedWorkingMinutes);
        $this->assertSame(60, $result->pausedWorkingMinutes);
        $this->assertSame(180, $result->overdueWorkingMinutes);
        $this->assertSame(0, $result->remainingWorkingMinutes);
    }

    public function test_g_pause_outside_working_hours_has_no_eligible_duration_or_due_date_effect(): void
    {
        $result = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T10:30:00Z', [
            'pauses' => [$this->pause('2026-11-02T18:00:00Z', '2026-11-02T22:00:00Z')],
        ]);

        $this->assertSame('2026-11-03T11:00:00.000000+00:00', $this->format($result->dueAt));
        $this->assertSame(0, $result->pausedWorkingMinutes);
        $this->assertSame(210, $result->consumedWorkingMinutes);
    }

    public function test_h_pause_spanning_lunch_deducts_only_its_two_working_intersections(): void
    {
        config()->set('workdays.definitions.profiles.support.working_hours', $this->hours([['09:00', '12:00'], ['13:00', '17:00']]));

        $result = $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T14:00:00Z', [
            'pauses' => [$this->pause('2026-11-02T11:30:00Z', '2026-11-02T13:30:00Z')],
        ]);

        $this->assertSame(60, $result->pausedWorkingMinutes);
        $this->assertSame(180, $result->consumedWorkingMinutes);
        $this->assertSame(60, $result->remainingWorkingMinutes);
        $this->assertSame('2026-11-02T15:00:00.000000+00:00', $this->format($result->dueAt));
    }

    #[DataProvider('openPauseBoundaries')]
    public function test_open_pause_preserves_a_preexisting_or_exact_boundary_deadline(string $pauseStart, string $evaluated, ?string $due, bool $breached, string $status): void
    {
        $result = $this->evaluate('2026-11-02T15:00:00Z', $evaluated, [
            'target_minutes' => 60, 'pauses' => [$this->pause($pauseStart, null)],
        ]);

        $this->assertSame($due, $result->dueAt === null ? null : $this->format($result->dueAt));
        $this->assertTrue($result->paused);
        $this->assertSame($breached, $result->breached);
        $this->assertSame($status, $result->status);
    }

    public static function openPauseBoundaries(): array
    {
        return [
            ['2026-11-02T15:30:00Z', '2026-11-02T15:30:00Z', null, false, 'paused'],
            ['2026-11-02T16:00:00Z', '2026-11-02T16:00:00Z', '2026-11-02T16:00:00.000000+00:00', false, 'paused'],
            ['2026-11-02T16:00:00Z', '2026-11-02T16:00:00.000001Z', '2026-11-02T16:00:00.000000+00:00', true, 'breached'],
            ['2026-11-03T10:00:00Z', '2026-11-03T12:00:00Z', '2026-11-02T16:00:00.000000+00:00', true, 'breached'],
        ];
    }

    public function test_open_and_closed_overlapping_pauses_are_unioned_without_discarding_open_state(): void
    {
        $result = $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T12:00:00Z', [
            'pauses' => [$this->pause('2026-11-02T10:30:00Z', null), $this->pause('2026-11-02T10:00:00Z', '2026-11-02T11:00:00Z')],
        ]);

        $this->assertSame(60, $result->consumedWorkingMinutes);
        $this->assertSame(120, $result->pausedWorkingMinutes);
        $this->assertSame(180, $result->remainingWorkingMinutes);
        $this->assertTrue($result->paused);
        $this->assertNull($result->dueAt);
    }

    public function test_pause_spanning_weekend_and_holiday_only_deducts_eligible_working_time(): void
    {
        config()->set('workdays.definitions.profiles.support.custom_holidays', ['2026-11-09' => 'Monday closure']);

        $result = $this->evaluate('2026-11-06T16:00:00Z', '2026-11-10T11:00:00Z', [
            'pauses' => [$this->pause('2026-11-06T16:30:00Z', '2026-11-10T09:30:00Z')],
        ]);

        $this->assertSame(60, $result->pausedWorkingMinutes);
        $this->assertSame(120, $result->consumedWorkingMinutes);
        $this->assertSame('2026-11-10T13:00:00.000000+00:00', $this->format($result->dueAt));
    }

    public function test_pause_boundaries_at_opening_closing_and_evaluation_are_half_open(): void
    {
        $result = $this->evaluate('2026-11-02T09:00:00Z', '2026-11-03T10:00:00Z', [
            'target_minutes' => 60,
            'pauses' => [$this->pause('2026-11-02T09:00:00Z', '2026-11-02T17:00:00Z'), $this->pause('2026-11-03T09:00:00Z', '2026-11-03T10:00:00Z')],
        ]);

        $this->assertSame(0, $result->consumedWorkingMinutes);
        $this->assertSame(540, $result->pausedWorkingMinutes);
        $this->assertSame('2026-11-03T11:00:00.000000+00:00', $this->format($result->dueAt));
        $this->assertFalse($result->paused);
    }

    #[DataProvider('equivalentOvernightCapabilities')]
    public function test_no_pause_sla_and_core_share_exact_arithmetic_for_equivalent_overnight_capabilities(?bool $overnight): void
    {
        config()->set('workdays.definitions.profiles.support.working_hours', ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]]);
        if ($overnight !== null) {
            config()->set('workdays.definitions.profiles.support.allow_overnight_working_hours', $overnight);
        }
        $before = Workday::profile('support')->addBusinessMinutes('2026-11-02 09:59:30.123456', 2);

        $result = $this->evaluate('2026-11-02T09:59:30.123456Z', '2026-11-02T11:00:30.123456Z', ['target_minutes' => 2]);

        $this->assertSame('2026-11-02T11:01:30.123456+00:00', $this->format($result->dueAt));
        $this->assertSame(1, $result->consumedWorkingMinutes);
        $this->assertSame(1, $result->remainingWorkingMinutes);
        $this->assertSame($this->format($before), $this->format(Workday::profile('support')->addBusinessMinutes('2026-11-02 09:59:30.123456', 2)));
        $this->assertSame($this->format($result->dueAt), $this->format($before));
    }

    public static function equivalentOvernightCapabilities(): array
    {
        return [[null], [false], [true]];
    }

    public function test_sub_minute_active_and_paused_fragments_are_accumulated_before_rounding(): void
    {
        config()->set('workdays.definitions.profiles.support.working_hours', ['Monday' => [['09:00', '09:01'], ['09:02', '09:03']]]);

        $result = $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T09:03:00Z', [
            'target_minutes' => 1,
            'pauses' => [$this->pause('2026-11-02T09:00:20Z', '2026-11-02T09:00:50Z'), $this->pause('2026-11-02T09:02:20Z', '2026-11-02T09:02:50Z')],
        ]);

        $this->assertSame(1, $result->consumedWorkingMinutes);
        $this->assertSame(1, $result->pausedWorkingMinutes);
        $this->assertSame(0, $result->remainingWorkingMinutes);
        $this->assertSame('2026-11-02T09:03:00.000000+00:00', $this->format($result->dueAt));
        $this->assertFalse($result->breached);
    }

    public function test_fractional_remaining_and_overdue_minutes_use_ceil_and_floor_of_exact_totals(): void
    {
        $remaining = $this->evaluate('2026-11-02T09:00:00.123456Z', '2026-11-02T09:01:00.123455Z', ['target_minutes' => 1]);
        $overdue = $this->evaluate('2026-11-02T09:00:00.123456Z', '2026-11-02T09:02:00.123455Z', ['target_minutes' => 1]);

        $this->assertSame(0, $remaining->consumedWorkingMinutes);
        $this->assertSame(1, $remaining->remainingWorkingMinutes);
        $this->assertFalse($remaining->breached);
        $this->assertSame('2026-11-02T09:01:00.123456+00:00', $this->format($remaining->dueAt));
        $this->assertSame(1, $overdue->consumedWorkingMinutes);
        $this->assertSame(0, $overdue->overdueWorkingMinutes);
        $this->assertTrue($overdue->breached);
    }

    #[DataProvider('calendarAvailabilityCases')]
    public function test_existing_calendar_hours_holidays_and_overrides_determine_sla_deadlines(array $configuration, string $started, int $target, string $due): void
    {
        foreach ($configuration as $key => $value) {
            config()->set('workdays.definitions.profiles.support.'.$key, $value);
        }

        $result = $this->evaluate($started, $started, ['target_minutes' => $target]);

        $this->assertSame($due.'.000000+00:00', $this->format($result->dueAt));
        $this->assertSame(0, $result->consumedWorkingMinutes);
        $this->assertSame($target, $result->remainingWorkingMinutes);
    }

    public static function calendarAvailabilityCases(): array
    {
        return [
            'before opening' => [[], '2026-11-02T08:00:00Z', 60, '2026-11-02T10:00:00'],
            'after closing' => [[], '2026-11-02T18:00:00Z', 60, '2026-11-03T10:00:00'],
            'weekend rollover' => [[], '2026-11-06T16:00:00Z', 120, '2026-11-09T10:00:00'],
            'lunch start' => [['working_hours' => ['Monday' => [['09:00', '12:00'], ['13:00', '17:00']]]], '2026-11-02T12:30:00Z', 60, '2026-11-02T14:00:00'],
            'custom holiday start' => [['custom_holidays' => ['2026-11-02' => 'Closed']], '2026-11-02T10:00:00Z', 60, '2026-11-03T10:00:00'],
            'consecutive holidays' => [['custom_holidays' => ['2026-11-02' => 'Closed', '2026-11-03' => 'Closed']], '2026-11-02T10:00:00Z', 60, '2026-11-04T10:00:00'],
            'gregorian recurring holiday' => [['holidays.gregorian' => ['11-02' => 'Annual closure']], '2026-11-02T10:00:00Z', 60, '2026-11-03T10:00:00'],
            'jalali recurring holiday' => [['holidays.jalali' => ['04-01' => 'Jalali closure']], '2026-06-22T10:00:00Z', 60, '2026-06-23T10:00:00'],
            'half day' => [['working_hours.Monday' => [['09:00', '12:00']]], '2026-11-02T11:00:00Z', 120, '2026-11-03T10:00:00'],
            'extra working Saturday' => [['extra_working_days' => ['2026-11-07' => 'Reopened'], 'extra_working_day_hours' => [['09:00', '11:00']]], '2026-11-07T08:00:00Z', 60, '2026-11-07T10:00:00'],
            'explicitly closed special day' => [['special_working_hours' => ['2026-11-02' => []]], '2026-11-02T10:00:00Z', 60, '2026-11-03T10:00:00'],
            'seasonal extended hours' => [['seasonal_working_hours' => [['from' => '2026-11-02', 'through' => '2026-11-02', 'weekdays' => ['Monday' => [['09:00', '20:00']]]]]], '2026-11-02T16:00:00Z', 120, '2026-11-02T18:00:00'],
            'seasonal shortened hours' => [['seasonal_working_hours' => [['from' => '2026-11-02', 'through' => '2026-11-02', 'weekdays' => ['Monday' => [['09:00', '12:00']]]]]], '2026-11-02T11:00:00Z', 120, '2026-11-03T10:00:00'],
            'special beats seasonal' => [['special_working_hours' => ['2026-11-02' => [['10:00', '11:00']]], 'seasonal_working_hours' => [['from' => '2026-11-02', 'through' => '2026-11-02', 'weekdays' => ['Monday' => [['09:00', '20:00']]]]]], '2026-11-02T10:00:00Z', 120, '2026-11-03T10:00:00'],
            'special does not reopen holiday' => [['custom_holidays' => ['2026-11-02' => 'Closed'], 'special_working_hours' => ['2026-11-02' => [['09:00', '20:00']]]], '2026-11-02T10:00:00Z', 60, '2026-11-03T10:00:00'],
        ];
    }

    public function test_hijri_recurring_holiday_uses_the_existing_selected_calendar_method(): void
    {
        $holiday = (new HijriCalendarAdapter('mathematical', 0))->hijriDateToGregorian(1446, 8, 15);
        config()->set('workdays.definitions.profiles.support.weekends', []);
        config()->set('workdays.definitions.profiles.support.working_hours', array_fill_keys(range(1, 7), [['09:00', '17:00']]));
        config()->set('workdays.definitions.profiles.support.holidays.hijri', ['08-15' => 'Hijri closure']);

        $result = $this->evaluate($holiday->toDateString().'T10:00:00Z', $holiday->toDateString().'T10:00:00Z', ['target_minutes' => 60]);

        $this->assertSame($holiday->addDay()->toDateString().'T10:00:00.000000+00:00', $this->format($result->dueAt));
    }

    public function test_overnight_pause_spans_midnight_once_and_carry_in_survives_receiving_holiday(): void
    {
        config()->set('workdays.definitions.profiles.support', array_replace($this->profile(), [
            'allow_overnight_working_hours' => true, 'working_hours' => ['Monday' => [['22:00', '06:00']]],
            'custom_holidays' => ['2026-11-03' => 'Receiving closure'],
        ]));
        $unpaused = $this->evaluate('2026-11-02T23:00:00Z', '2026-11-03T02:00:00Z', ['target_minutes' => 180]);
        $paused = $this->evaluate('2026-11-02T23:00:00Z', '2026-11-03T02:00:00Z', [
            'target_minutes' => 180, 'pauses' => [$this->pause('2026-11-02T23:30:00Z', '2026-11-03T00:30:00Z')],
        ]);

        $this->assertSame('2026-11-03T02:00:00.000000+00:00', $this->format($unpaused->dueAt));
        $this->assertSame('2026-11-03T03:00:00.000000+00:00', $this->format($paused->dueAt));
        $this->assertSame(120, $paused->consumedWorkingMinutes);
        $this->assertSame(60, $paused->pausedWorkingMinutes);
        $this->assertFalse(Workday::profile('support')->isBusinessDay('2026-11-03'));
    }

    public function test_midnight_fractional_arithmetic_and_overlapping_carry_in_are_not_double_counted(): void
    {
        config()->set('workdays.definitions.profiles.support', array_replace($this->profile(), [
            'allow_overnight_working_hours' => true,
            'working_hours' => ['Monday' => [['22:00', '06:00']], 'Tuesday' => [['05:00', '09:00']]],
        ]));
        $fractional = $this->evaluate('2026-11-02T23:59:30.123456Z', '2026-11-03T00:00:30.123456Z', ['target_minutes' => 1]);
        $continuous = $this->evaluate('2026-11-02T23:00:00Z', '2026-11-03T09:00:00Z', ['target_minutes' => 600]);

        $this->assertSame('2026-11-03T00:00:30.123456+00:00', $this->format($fractional->dueAt));
        $this->assertSame(1, $fractional->consumedWorkingMinutes);
        $this->assertSame('2026-11-03T09:00:00.000000+00:00', $this->format($continuous->dueAt));
        $this->assertSame(600, $continuous->consumedWorkingMinutes);
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_sla_options_use_configuration_exceptions(array $options): void
    {
        $this->expectException(WorkdayConfigurationException::class);

        Workday::evaluateSla('2026-11-02T09:00:00Z', '2026-11-02T10:00:00Z', $options);
    }

    public static function invalidOptions(): array
    {
        $valid = ['profile' => 'support', 'timezone' => 'UTC', 'target_minutes' => 240];
        $cases = [[], ['timezone' => 'UTC', 'target_minutes' => 240], ['profile' => 'support', 'target_minutes' => 240], ['profile' => 'support', 'timezone' => 'UTC']];
        foreach (['', 'missing', null, false, 1, []] as $value) {
            $cases[] = array_replace($valid, ['profile' => $value]);
        }
        foreach (['Mars/Olympus', '+03:30', '', null, false, []] as $value) {
            $cases[] = array_replace($valid, ['timezone' => $value]);
        }
        foreach ([0, -1, 1000001, PHP_INT_MAX, '240', 240.0, true, false, null, []] as $value) {
            $cases[] = array_replace($valid, ['target_minutes' => $value]);
        }
        $cases[] = $valid + ['unexpected' => true];
        foreach ([null, false, 'none', ['started_at' => '2026-11-02T09:00:00Z', 'ended_at' => null], [1 => ['started_at' => '2026-11-02T09:00:00Z', 'ended_at' => null]]] as $pauses) {
            $cases[] = $valid + ['pauses' => $pauses];
        }

        return array_map(static fn (array $options): array => [$options], $cases);
    }

    #[DataProvider('invalidPauseShapes')]
    public function test_pause_objects_require_exactly_the_two_supported_fields(mixed $pause): void
    {
        $this->expectException(WorkdayConfigurationException::class);

        $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T10:00:00Z', ['pauses' => [$pause]]);
    }

    public static function invalidPauseShapes(): array
    {
        return [[null], [false], ['09:00-10:00'], [[]], [['started_at' => '2026-11-02T09:00:00Z']], [['ended_at' => null]],
            [['started_at' => '2026-11-02T09:00:00Z', 'ended_at' => null, 'reason' => 'Unsupported']],
            [['2026-11-02T09:00:00Z', '2026-11-02T10:00:00Z']],
        ];
    }

    #[DataProvider('invalidTimestamps')]
    public function test_strict_timestamp_rules_apply_to_start_evaluation_and_pauses(string $invalid): void
    {
        foreach (['start', 'evaluation', 'pause start', 'pause end'] as $field) {
            try {
                $this->evaluate($field === 'start' ? $invalid : '2026-11-02T09:00:00Z', $field === 'evaluation' ? $invalid : '2026-11-02T12:00:00Z', [
                    'pauses' => in_array($field, ['pause start', 'pause end'], true)
                        ? [$this->pause($field === 'pause start' ? $invalid : '2026-11-02T10:00:00Z', $field === 'pause end' ? $invalid : '2026-11-02T11:00:00Z')]
                        : [],
                ]);
                $this->fail('Invalid timestamp accepted for '.$field.': '.$invalid);
            } catch (InvalidArgumentException $exception) {
                $this->assertNotInstanceOf(WorkdayConfigurationException::class, $exception);
            }
        }
    }

    public static function invalidTimestamps(): array
    {
        return array_map(static fn (string $value): array => [$value], [
            '', 'tomorrow', '+1 day', '2026-11-02', '2026-02-30T10:00:00Z', '0000-11-02T10:00:00Z',
            '2026-11-02T24:00:00Z', '2026-11-02T09:60:00Z', '2026-11-02T09:00:60Z',
            '2026-11-02T09:00:00+24:00', '2026-11-02T09:00:00+03:60', '2026-11-02T09:00:00+0330',
            '2026-11-02T09:00:00', '2026-11-02 09:00:00Z', '2026-11-02T09:00:00.1234567Z', "2026-11-02T09:00:00Z\n",
        ]);
    }

    #[DataProvider('invalidPauseChronology')]
    public function test_invalid_interval_chronology_is_rejected(array $pauses): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T12:00:00Z', ['pauses' => $pauses]);
    }

    public static function invalidPauseChronology(): array
    {
        return [
            [[['started_at' => '2026-11-02T08:59:59.999999Z', 'ended_at' => '2026-11-02T10:00:00Z']]],
            [[['started_at' => '2026-11-02T12:00:00.000001Z', 'ended_at' => null]]],
            [[['started_at' => '2026-11-02T10:00:00Z', 'ended_at' => '2026-11-02T12:00:00.000001Z']]],
            [[['started_at' => '2026-11-02T10:00:00Z', 'ended_at' => '2026-11-02T09:00:00Z']]],
            [[['started_at' => '2026-11-02T10:00:00Z', 'ended_at' => '2026-11-02T10:00:00Z']]],
            [[['started_at' => '2026-11-02T10:00:00Z', 'ended_at' => null], ['started_at' => '2026-11-02T11:00:00Z', 'ended_at' => null]]],
        ];
    }

    public function test_evaluation_cannot_precede_the_sla_start_even_by_one_microsecond(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->evaluate('2026-11-02T09:00:00.000001Z', '2026-11-02T09:00:00.000000Z');
    }

    public function test_exactly_one_thousand_submitted_pauses_are_allowed_and_normalized_once(): void
    {
        $pause = $this->pause('2026-11-02T10:00:00Z', '2026-11-02T11:00:00Z');

        $result = $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T12:00:00Z', ['pauses' => array_fill(0, 1000, $pause)]);

        $this->assertSame(60, $result->pausedWorkingMinutes);
        $this->assertSame(120, $result->consumedWorkingMinutes);
        $this->assertSame('2026-11-02T14:00:00.000000+00:00', $this->format($result->dueAt));
    }

    public function test_pause_cap_applies_before_merging_duplicate_submissions(): void
    {
        $this->expectException(WorkdayConfigurationException::class);

        $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T12:00:00Z', [
            'pauses' => array_fill(0, 1001, $this->pause('2026-11-02T10:00:00Z', '2026-11-02T11:00:00Z')),
        ]);
    }

    public function test_naive_inputs_use_the_explicit_timezone_and_equivalent_offsets_preserve_instants(): void
    {
        $naive = $this->evaluate('2026-11-02 09:00:12.123456', '2026-11-02 10:00:12.123456', ['timezone' => 'Asia/Tehran', 'target_minutes' => 120]);
        $explicit = $this->evaluate('2026-11-02T00:30:12.123456-05:00', '2026-11-02T06:30:12.123456Z', ['timezone' => 'Asia/Tehran', 'target_minutes' => 120]);

        $this->assertSame($naive->toArray(), $explicit->toArray());
        $this->assertSame('2026-11-02T05:30:12.123456+00:00', $this->format($naive->startedAt));
        $this->assertSame('2026-11-02T07:30:12.123456+00:00', $this->format($naive->dueAt));
        $this->assertSame('Asia/Tehran', $naive->timezone);
    }

    #[DataProvider('invalidDstNaiveInputs')]
    public function test_nonexistent_and_ambiguous_naive_inputs_are_rejected(string $invalid, string $valid, string $timezone): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->evaluate($invalid, $valid, ['timezone' => $timezone]);
    }

    public static function invalidDstNaiveInputs(): array
    {
        return [
            ['2026-03-08 02:30', '2026-03-08T08:00:00Z', 'America/New_York'],
            ['2026-11-01 01:30', '2026-11-01T08:00:00Z', 'America/New_York'],
            ['2026-09-06 00:30', '2026-09-06T05:00:00Z', 'America/Santiago'],
            ['2011-12-30 10:00', '2011-12-31T10:00:00+14:00', 'Pacific/Apia'],
        ];
    }

    public function test_ambiguous_naive_evaluation_and_pause_timestamps_are_rejected_too(): void
    {
        foreach (['evaluation', 'pause start', 'pause end'] as $field) {
            try {
                $this->evaluate('2026-11-01T00:00:00-04:00', $field === 'evaluation' ? '2026-11-01 01:30' : '2026-11-01T03:00:00-05:00', [
                    'timezone' => 'America/New_York',
                    'pauses' => $field === 'evaluation' ? [] : [$this->pause($field === 'pause start' ? '2026-11-01 01:30' : '2026-11-01T00:30:00-04:00', $field === 'pause end' ? '2026-11-01 01:30' : '2026-11-01T02:30:00-05:00')],
                ]);
                $this->fail('Ambiguous naive timestamp accepted for '.$field);
            } catch (InvalidArgumentException $exception) {
                $this->assertNotInstanceOf(WorkdayConfigurationException::class, $exception);
            }
        }
    }

    public function test_fold_instants_with_explicit_offsets_and_datetime_objects_are_accepted(): void
    {
        config()->set('workdays.definitions.profiles.support.weekends', []);
        config()->set('workdays.definitions.profiles.support.working_hours', ['Sunday' => [['00:00', '04:00']]]);
        $start = new DateTimeImmutable('2026-11-01T01:30:00-04:00');
        $evaluated = new DateTimeImmutable('2026-11-01T01:30:00-05:00');

        $objects = $this->evaluate($start, $evaluated, ['timezone' => 'America/New_York', 'target_minutes' => 60]);
        $strings = $this->evaluate('2026-11-01T01:30:00-04:00', '2026-11-01T01:30:00-05:00', ['timezone' => 'America/New_York', 'target_minutes' => 60]);

        $this->assertSame($strings->toArray(), $objects->toArray());
        $this->assertSame(60, $objects->consumedWorkingMinutes);
        $this->assertSame('2026-11-01T06:30:00.000000+00:00', $this->format($objects->dueAt));
        $this->assertFalse($objects->breached);
    }

    #[DataProvider('dstOvernightCases')]
    public function test_dst_overnight_windows_and_pauses_use_actual_elapsed_minutes(string $start, string $end, int $minutes, string $timezone, ?array $pause): void
    {
        config()->set('workdays.definitions.profiles.support', array_replace($this->profile(), [
            'weekends' => [], 'allow_overnight_working_hours' => true, 'working_hours' => ['Saturday' => [['22:00', '06:00']]],
        ]));
        $result = $this->evaluate($start, $end, ['timezone' => $timezone, 'target_minutes' => $minutes, 'pauses' => $pause === null ? [] : [$pause]]);

        $this->assertSame($minutes, $result->consumedWorkingMinutes);
        $this->assertSame($this->format(CarbonImmutable::parse($end)->utc()), $this->format($result->dueAt));
        $this->assertSame($pause === null ? 0 : 60, $result->pausedWorkingMinutes);
        $this->assertFalse($result->breached);
    }

    public static function dstOvernightCases(): array
    {
        return [
            'spring forward' => ['2026-03-07T22:00:00-05:00', '2026-03-08T06:00:00-04:00', 420, 'America/New_York', null],
            'fall back' => ['2026-10-31T22:00:00-04:00', '2026-11-01T06:00:00-05:00', 540, 'America/New_York', null],
            'spring pause' => ['2026-03-07T22:00:00-05:00', '2026-03-08T06:00:00-04:00', 360, 'America/New_York', ['started_at' => '2026-03-08T01:30:00-05:00', 'ended_at' => '2026-03-08T03:30:00-04:00']],
            'fold pause' => ['2026-10-31T22:00:00-04:00', '2026-11-01T06:00:00-05:00', 480, 'America/New_York', ['started_at' => '2026-11-01T01:30:00-04:00', 'ended_at' => '2026-11-01T01:30:00-05:00']],
            'Santiago synthetic midnight gap' => ['2026-09-05T22:00:00-04:00', '2026-09-06T06:00:00-03:00', 420, 'America/Santiago', null],
        ];
    }

    #[DataProvider('invalidWorkingBoundaries')]
    public function test_invalid_or_ambiguous_configured_dst_window_boundaries_do_not_fabricate_time(string $start, string $timezone, array $hours): void
    {
        config()->set('workdays.definitions.profiles.support.weekends', []);
        config()->set('workdays.definitions.profiles.support.special_working_hours', []);
        config()->set('workdays.definitions.profiles.support.working_hours', $hours);
        $this->expectException(WorkdayConfigurationException::class);

        $this->evaluate($start, $start, ['timezone' => $timezone, 'target_minutes' => 60]);
    }

    public static function invalidWorkingBoundaries(): array
    {
        return [
            ['2026-03-08T00:00:00-05:00', 'America/New_York', ['Sunday' => [['02:30', '04:00']]]],
            ['2026-11-01T00:00:00-04:00', 'America/New_York', ['Sunday' => [['01:30', '04:00']]]],
            ['2026-09-06T01:00:00-03:00', 'America/Santiago', ['Sunday' => [['00:00', '03:00']]]],
        ];
    }

    public function test_deadline_on_final_allowed_profile_date_is_valid_and_horizon_is_not_changed(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $result = $this->evaluate('2026-11-02T16:00:00Z', '2026-11-03T10:00:00Z', ['target_minutes' => 120]);

        $this->assertSame('2026-11-03T10:00:00.000000+00:00', $this->format($result->dueAt));
        $this->assertSame(1, config('workdays.max_scan_days'));
    }

    public function test_horizon_does_not_restart_after_each_pause_or_working_window(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('support');

        $this->evaluate('2026-11-02T16:00:00Z', '2026-11-03T16:00:00Z', [
            'target_minutes' => 181,
            'pauses' => [$this->pause('2026-11-02T16:30:00Z', '2026-11-02T17:00:00Z'), $this->pause('2026-11-03T09:00:00Z', '2026-11-03T16:00:00Z')],
        ]);
    }

    public function test_unavailable_calendar_fails_with_profile_and_limit_instead_of_inventing_a_due_date(): void
    {
        config()->set('workdays.max_scan_days', 3);
        config()->set('workdays.definitions.profiles.support.working_hours', []);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/(?=.*support)(?=.*3)/s');

        $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T10:00:00Z');
    }

    public function test_open_pause_with_unknown_due_date_does_not_require_future_availability(): void
    {
        config()->set('workdays.max_scan_days', 1);
        config()->set('workdays.definitions.profiles.support.working_hours', []);

        $result = $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T10:00:00Z', [
            'target_minutes' => 1000000, 'pauses' => [$this->pause('2026-11-02T09:00:00Z', null)],
        ]);

        $this->assertNull($result->dueAt);
        $this->assertSame('paused', $result->status);
        $this->assertSame(1000000, $result->remainingWorkingMinutes);
        $this->assertSame(0, $result->consumedWorkingMinutes);
    }

    #[DataProvider('openPauseModes')]
    public function test_evaluation_beyond_horizon_is_rejected_even_if_already_breached_or_currently_paused(bool $open): void
    {
        config()->set('workdays.max_scan_days', 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('support');

        $this->evaluate('2026-11-02T09:00:00Z', '2026-11-04T09:00:00Z', [
            'target_minutes' => 1, 'pauses' => $open ? [$this->pause('2026-11-02T10:00:00Z', null)] : [],
        ]);
    }

    public static function openPauseModes(): array
    {
        return [[false], [true]];
    }

    public function test_next_midnight_deadline_beyond_final_local_date_is_rejected(): void
    {
        config()->set('workdays.max_scan_days', 1);
        config()->set('workdays.definitions.profiles.support.allow_overnight_working_hours', true);
        config()->set('workdays.definitions.profiles.support.working_hours', ['Tuesday' => [['23:00', '24:00']]]);
        $this->expectException(RuntimeException::class);

        $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T09:00:00Z', ['target_minutes' => 60]);
    }

    public function test_skipped_apia_date_does_not_extend_the_date_label_horizon(): void
    {
        config()->set('workdays.max_scan_days', 1);
        config()->set('workdays.definitions.profiles.support.weekends', []);
        config()->set('workdays.definitions.profiles.support.working_hours', array_fill_keys(range(1, 7), [['09:00', '17:00']]));
        try {
            $this->evaluate('2011-12-29T18:00:00-10:00', '2011-12-29T18:00:00-10:00', ['timezone' => 'Pacific/Apia', 'target_minutes' => 60]);
            $this->fail('Skipped civil date extended the one-day horizon.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('support', $exception->getMessage());
        }
        config()->set('workdays.max_scan_days', 2);

        $result = $this->evaluate('2011-12-29T18:00:00-10:00', '2011-12-29T18:00:00-10:00', ['timezone' => 'Pacific/Apia', 'target_minutes' => 60]);

        $this->assertSame('2011-12-30T20:00:00.000000+00:00', $this->format($result->dueAt));
        $this->assertSame('2011-12-31 10:00', $result->dueAt->setTimezone('Pacific/Apia')->format('Y-m-d H:i'));
    }

    public function test_date_label_horizon_is_measured_in_the_explicit_profile_timezone(): void
    {
        config()->set('workdays.max_scan_days', 1);

        $result = $this->evaluate('2026-11-02T20:00:00Z', '2026-11-03T06:30:00Z', ['timezone' => 'Asia/Tehran', 'target_minutes' => 60]);

        $this->assertSame('2026-11-03T06:30:00.000000+00:00', $this->format($result->dueAt));
        $this->assertFalse($result->breached);
    }

    public function test_result_serialization_is_stable_utc_immutable_and_detached_from_mutable_inputs(): void
    {
        $start = new DateTime('2026-11-02T12:30:12.123456+03:30');
        $evaluated = new DateTime('2026-11-02T13:30:12.123456+03:30');
        $pauseStart = new DateTime('2026-11-02T13:00:12.123456+03:30');
        $pauseEnd = new DateTime('2026-11-02T13:15:12.123456+03:30');
        $before = array_map(static fn (DateTime $value): string => $value->format('Y-m-d\TH:i:s.uP'), [$start, $evaluated, $pauseStart, $pauseEnd]);
        $options = $this->slaOptions(['target_minutes' => 60, 'pauses' => [$this->pause($pauseStart, $pauseEnd)]]);
        $result = Workday::evaluateSla($start, $evaluated, $options);

        $this->assertSame($before, array_map(static fn (DateTime $value): string => $value->format('Y-m-d\TH:i:s.uP'), [$start, $evaluated, $pauseStart, $pauseEnd]));
        $this->assertSame([
            'profile' => 'support', 'timezone' => 'UTC',
            'started_at' => '2026-11-02T09:00:12.123456+00:00', 'evaluated_at' => '2026-11-02T10:00:12.123456+00:00',
            'target_minutes' => 60, 'consumed_working_minutes' => 45, 'paused_working_minutes' => 15,
            'remaining_working_minutes' => 15, 'overdue_working_minutes' => 0,
            'due_at' => '2026-11-02T10:15:12.123456+00:00', 'paused' => false, 'breached' => false, 'status' => 'active',
        ], $result->toArray());
        $this->assertSame($result->toArray(), json_decode(json_encode($result->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        foreach ([$result->startedAt, $result->evaluatedAt, $result->dueAt] as $instant) {
            $this->assertInstanceOf(CarbonImmutable::class, $instant);
            $this->assertSame(0, $instant->getOffset());
        }
        $payload = $result->toArray();
        foreach ([$start, $evaluated, $pauseStart, $pauseEnd] as $value) {
            $value->modify('+10 days')->setTimezone(new DateTimeZone('Pacific/Apia'));
        }
        $this->assertSame($payload, $result->toArray());
        $this->expectException(Error::class);

        $result->targetMinutes = 120;
    }

    public function test_repeated_evaluations_and_manager_facade_calls_are_deterministic_without_config_mutation(): void
    {
        $before = config('workdays');
        $options = $this->slaOptions();
        $first = app(WorkdayManager::class)->evaluateSla('2026-11-02T15:00:00Z', '2026-11-03T10:30:00Z', $options);
        $second = Workday::evaluateSla('2026-11-02T15:00:00Z', '2026-11-03T10:30:00Z', $options);

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame($before, config('workdays'));
        $this->assertSame(['profile' => 'support', 'timezone' => 'UTC', 'target_minutes' => 240], $options);
    }

    public function test_config_holidays_recurring_rules_and_reopenings_require_no_database_access(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        config()->set('workdays.definitions.profiles.support.custom_holidays', ['2026-11-03' => 'Tuesday closure']);
        config()->set('workdays.definitions.profiles.support.holidays.gregorian', ['11-04' => 'Wednesday recurring closure']);
        config()->set('workdays.definitions.profiles.support.extra_working_days', ['2026-11-07' => 'Extra Saturday']);
        config()->set('workdays.definitions.profiles.support.extra_working_day_hours', [['10:00', '14:00']]);
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        $closed = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T10:00:00Z');
        $reopened = $this->evaluate('2026-11-07T09:00:00Z', '2026-11-07T10:00:00Z', ['target_minutes' => 60]);

        $this->assertSame('2026-11-05T11:00:00.000000+00:00', $this->format($closed->dueAt));
        $this->assertSame('2026-11-07T11:00:00.000000+00:00', $this->format($reopened->dueAt));
        $this->assertSame([], $connection->getQueryLog());
        foreach ($connection->getQueryLog() as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
        $connection->disableQueryLog();
    }

    public function test_config_extra_day_reopens_its_holiday_and_later_calls_observe_removal(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        config()->set('workdays.definitions.profiles.support.custom_holidays', ['2026-11-03' => 'Config closure']);
        config()->set('workdays.definitions.profiles.support.extra_working_days', ['2026-11-03' => 'Config reopening']);

        $first = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T10:00:00Z');
        config()->set('workdays.definitions.profiles.support.extra_working_days', []);
        $second = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T10:00:00Z');

        $this->assertSame('2026-11-03T11:00:00.000000+00:00', $this->format($first->dueAt));
        $this->assertSame('2026-11-04T11:00:00.000000+00:00', $this->format($second->dueAt));
    }

    public function test_config_holiday_cannot_be_used_when_database_schema_is_missing(): void
    {
        config()->set('workdays.driver', 'database');
        config()->set('workdays.definitions.profiles.support.custom_holidays', ['2026-11-03' => 'Config ignored by database driver']);
        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('definitions migration');

        $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T10:00:00Z');
    }

    public function test_inherited_config_child_schedule_ignores_conflicting_database_definitions_and_unused_routing(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        config()->set('workdays.definitions.profiles', ['support_base' => $this->profile(), 'support_priority' => [
            'extends' => 'support_base', 'working_hours' => ['Thursday' => [['09:00', '20:00']]],
        ]]);
        config()->set('workdays.definitions.profile_routing', ['invalid' => true]);
        $options = ['profile' => 'support_priority', 'target_minutes' => 180];

        $result = $this->evaluate('2026-11-05T16:00:00Z', '2026-11-05T17:00:00Z', $options);
        config()->set('workdays.definitions.profiles.support_priority.custom_holidays', ['2026-11-05' => 'Selected child closure']);
        $closed = $this->evaluate('2026-11-05T16:00:00Z', '2026-11-05T17:00:00Z', $options);

        $this->assertSame('support_priority', $result->profile);
        $this->assertSame('2026-11-05T19:00:00.000000+00:00', $this->format($result->dueAt));
        $this->assertSame('2026-11-06T12:00:00.000000+00:00', $this->format($closed->dueAt));
    }

    public function test_config_sla_with_many_pause_fragments_performs_no_database_reads(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $plain = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T11:30:00Z');
        $plainQueries = $connection->getQueryLog();
        $connection->flushQueryLog();

        $paused = $this->evaluate('2026-11-02T15:00:00Z', '2026-11-03T11:30:00Z', [
            'pauses' => array_fill(0, 1000, $this->pause('2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z')),
        ]);
        $pausedQueries = $connection->getQueryLog();

        $this->assertSame('2026-11-03T11:00:00.000000+00:00', $this->format($plain->dueAt));
        $this->assertSame('2026-11-03T12:00:00.000000+00:00', $this->format($paused->dueAt));
        $this->assertCount(0, $plainQueries);
        $this->assertCount(0, $pausedQueries);
        foreach (array_merge($plainQueries, $pausedQueries) as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
    }

    public function test_one_calculator_and_effective_profile_are_resolved_and_calendar_dates_are_scanned_once(): void
    {
        $profile = $this->profile();
        $provider = new class(new LogicalHolidayProvider(static fn (string $name): array => $profile)) implements HolidayProviderInterface
        {
            public array $dates = [];

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
                $this->dates[] = $date->toDateString();

                return $this->inner->hasCustomHoliday($profile, $date);
            }

            public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool
            {
                $this->dates[] = $date->toDateString();

                return $this->inner->hasExtraWorkingDay($profile, $date);
            }
        };
        $resolved = [];
        $configured = [];
        $evaluator = new SlaEvaluator(function (string $name, int $limit) use (&$resolved, $profile, $provider): BusinessDayCalculator {
            $resolved[] = [$name, $limit];

            return new BusinessDayCalculator($name, $profile, maxScanDays: $limit, holidayProvider: $provider, includeStartDate: false, jalaliCalendar: new JalaliCalendarAdapter, hijriCalendar: new HijriCalendarAdapter('umm_al_qura', 0));
        }, function (string $name) use (&$configured, $profile): array {
            $configured[] = $name;

            return $profile;
        }, 31);

        $result = $evaluator->evaluate('2026-11-02T15:00:00Z', '2026-11-03T11:30:00Z', $this->slaOptions([
            'pauses' => array_fill(0, 1000, $this->pause('2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z')),
        ]));

        $this->assertSame('2026-11-03T12:00:00.000000+00:00', $this->format($result->dueAt));
        $this->assertSame([['support', 31]], $resolved);
        $this->assertSame(['support'], $configured);
        $this->assertSame(['2026-11-02', '2026-11-03'], array_values(array_unique($provider->dates)));
        $this->assertLessThanOrEqual(8, count($provider->dates));
    }

    public function test_missing_working_hours_is_an_sla_configuration_error_without_changing_ordinary_date_methods(): void
    {
        $profile = $this->profile();
        unset($profile['working_hours']);
        config()->set('workdays.definitions.profiles.support', $profile);
        $this->assertTrue(Workday::profile('support')->isBusinessDay('2026-11-02'));
        $this->expectException(WorkdayConfigurationException::class);

        $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T10:00:00Z');
    }

    #[DataProvider('invalidProfileConfigurations')]
    public function test_invalid_selected_profile_configuration_is_reported_as_an_sla_configuration_error(array $configuration): void
    {
        config()->set('workdays.definitions.profiles.support', $configuration);
        $this->expectException(WorkdayConfigurationException::class);

        $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T10:00:00Z');
    }

    public static function invalidProfileConfigurations(): array
    {
        return [[['weekends' => 'Saturday']], [['weekends' => [], 'working_hours' => '09:00-17:00']], [['extends' => 'missing']],
            [['weekends' => [], 'working_hours' => ['Monday' => [['17:00', '09:00']]]]],
        ];
    }

    public function test_bad_sla_options_do_not_change_existing_delivery_or_calendar_apis(): void
    {
        $calculator = Workday::profile('support');
        $before = $calculator->addBusinessMinutes('2026-11-02 09:59:30.123456', 1);
        try {
            $this->evaluate('2026-11-02T09:00:00Z', '2026-11-02T10:00:00Z', ['target_minutes' => '240']);
            $this->fail('Numeric-string SLA budget was accepted.');
        } catch (WorkdayConfigurationException) {
            $this->assertTrue($calculator->isBusinessDay('2026-11-02'));
        }

        $this->assertSame($this->format($before), $this->format($calculator->addBusinessMinutes('2026-11-02 09:59:30.123456', 1)));
        $this->assertSame('2026-11-02 10:00:30', Workday::planDelivery('2026-11-02T09:59:30Z', [
            'timezone' => 'UTC', 'profile_timezones' => ['support' => 'UTC'],
            'stages' => [['id' => 'work', 'type' => 'work', 'profile' => 'support', 'minutes' => 1]],
        ])->estimatedCompletionAt->toDateTimeString());
    }

    private function profile(): array
    {
        return [
            'weekends' => ['Saturday', 'Sunday'],
            'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
            'custom_holidays' => [], 'extra_working_days' => [],
            'working_hours' => $this->hours([['09:00', '17:00']]),
        ];
    }

    private function hours(array $windows): array
    {
        return array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], $windows);
    }

    private function slaOptions(array $overrides = []): array
    {
        return array_replace(['profile' => 'support', 'timezone' => 'UTC', 'target_minutes' => 240], $overrides);
    }

    private function evaluate(string|\DateTimeInterface $started, string|\DateTimeInterface $evaluated, array $overrides = []): SlaResult
    {
        return Workday::evaluateSla($started, $evaluated, $this->slaOptions($overrides));
    }

    private function pause(string|\DateTimeInterface $started, string|\DateTimeInterface|null $ended): array
    {
        return ['started_at' => $started, 'ended_at' => $ended];
    }

    private function format(CarbonImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s.uP');
    }
}
