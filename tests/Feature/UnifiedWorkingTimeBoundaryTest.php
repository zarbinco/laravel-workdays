<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class UnifiedWorkingTimeBoundaryTest extends TestCase
{
    #[DataProvider('splitCivilDates')]
    public function test_split_civil_date_histories_fail_explicitly_instead_of_returning_partial_accounting(string $source, string $zone, string $offsetBefore, string $offsetAfter, string $method): void
    {
        $calculator = $this->calculator($source);
        $first = CarbonImmutable::parse('1988-10-30T00:00:00'.$offsetBefore)->setTimezone($zone);
        $later = CarbonImmutable::parse('1988-10-29T23:00:00'.$offsetAfter)->setTimezone($zone);
        $this->assertTrue($later->greaterThan($first));
        $this->assertSame('1988-10-30', $first->toDateString());
        $this->assertSame('1988-10-29', $later->toDateString());
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('moves backward across a civil-date label');

        match ($method) {
            'difference' => $calculator->diffBusinessMinutes($first, $later),
            'next' => $calculator->nextBusinessTime($first),
            'previous' => $calculator->previousBusinessTime($later),
            'addition' => $calculator->addBusinessMinutes($first, 1),
        };
    }

    #[DataProvider('sources')]
    public function test_an_ordinary_repeated_hour_remains_representable_with_exact_elapsed_capacity(string $source): void
    {
        $calculator = $this->calculator($source, ['working_hours' => ['Sunday' => [['00:00', '04:00']]]]);
        $start = CarbonImmutable::parse('2026-11-01T00:00:00-04:00')->setTimezone('America/New_York');
        $end = CarbonImmutable::parse('2026-11-01T04:00:00-05:00')->setTimezone('America/New_York');
        $lastHour = CarbonImmutable::parse('2026-11-01T02:30:00-05:00')->setTimezone('America/New_York');

        $this->assertSame(300, $calculator->diffBusinessMinutes($start, $end));
        $this->assertSame('2026-11-01T03:30:00.000000-05:00', $calculator->addBusinessMinutes($lastHour, 60)->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame($lastHour->format('U.u'), $calculator->nextBusinessTime($lastHour)->format('U.u'));
        $this->assertSame($lastHour->format('U.u'), $calculator->previousBusinessTime($lastHour)->format('U.u'));
    }

    public static function splitCivilDates(): iterable
    {
        foreach (['config', 'database'] as $source) {
            foreach (['America/St_Johns' => ['-01:30', '-03:30'], 'America/Goose_Bay' => ['-02:00', '-04:00']] as $zone => [$before, $after]) {
                foreach (['difference', 'next', 'previous', 'addition'] as $method) {
                    yield $source.' '.$zone.' '.$method => [$source, $zone, $before, $after, $method];
                }
            }
        }
    }

    public static function sources(): array
    {
        return [['config'], ['database']];
    }

    private function calculator(string $source, array $options = []): BusinessDayCalculator
    {
        $definitions = ['default_profile' => 'hours', 'include_start_date' => false, 'hijri' => ['method' => 'mathematical', 'adjustment' => 0], 'profile_routing' => null,
            'profiles' => ['hours' => array_replace(['weekends' => [], 'holidays' => [], 'working_hours' => array_fill_keys(range(1, 7), [['09:00', '17:00']]), 'special_working_hours' => ['1988-10-29' => [['22:30', '23:30']]]], $options)]];
        config()->set('workdays.max_scan_days', 1);
        config()->set('workdays.driver', $source);
        config()->set('workdays.definitions', $definitions);
        if ($source === 'database') {
            $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
            $this->app->make(DatabaseDefinitionRepository::class)->publish($definitions, expectedRevisionId: null);
        }

        return Workday::profile('hours');
    }
}
