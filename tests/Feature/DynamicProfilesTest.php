<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Configuration\CalculationContextFactory;
use Zarbinco\LaravelWorkdays\Data\ResolvedProfile;
use Zarbinco\LaravelWorkdays\Data\TimeWindow;
use Zarbinco\LaravelWorkdays\Delivery\DeliveryPlanner;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigResolver;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class DynamicProfilesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Pacific/Honolulu');
        config()->set('workdays.definitions.default_profile', 'warehouse_base');
        config()->set('workdays.driver', 'config');
        config()->set('workdays.max_scan_days', 31);
        config()->set('workdays.definitions.profiles', [
            'warehouse_base' => $this->baseProfile(),
            'warehouse_tehran' => ['extends' => 'warehouse_base', 'working_hours' => ['Thursday' => [['09:00', '12:00']]], 'cutoff' => ['time' => '13:00']],
            'warehouse_isfahan' => ['extends' => 'warehouse_base'],
            'express_carrier' => $this->baseProfile(),
        ]);
        config()->set('workdays.definitions.profile_routing', $this->routing());
    }

    public function test_child_inherits_calendar_and_monday_but_replaces_thursday_and_cutoff_time(): void
    {
        $before = config('workdays');
        $child = Workday::profile('warehouse_tehran');
        $parent = Workday::profile('warehouse_base');

        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $this->windows($child, '2026-11-02'));
        $this->assertSame([['start' => '09:00', 'end' => '12:00']], $this->windows($child, '2026-11-05'));
        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $this->windows($parent, '2026-11-05'));
        $this->assertTrue($child->isGregorianHoliday('2027-01-01'));
        $this->assertFalse($child->isBusinessDay('2027-01-01'));
        $cutoff = $child->resolveCutoff('2026-11-02 13:00');
        $this->assertSame('warehouse_tehran', $cutoff->profile);
        $this->assertSame('UTC', $cutoff->timezone);
        $this->assertSame('13:00', $cutoff->cutoffAt->format('H:i'));
        $this->assertFalse($cutoff->sameDayEligible);
        $this->assertTrue($parent->resolveCutoff('2026-11-02 13:00')->sameDayEligible);
        $this->assertSame('2026-11-06 10:00:00', $child->addBusinessMinutes($this->at('2026-11-05 11:00'), 120)->toDateTimeString());
        $this->assertSame($before, config('workdays'));
    }

    public function test_multiple_levels_keep_parent_settings_and_apply_the_most_specific_override(): void
    {
        $profiles = [
            'base' => $this->baseProfile(),
            'regional' => ['extends' => 'base', 'custom_holidays' => ['2026-11-03' => 'Region closure'], 'cutoff' => ['time' => '13:00']],
            'warehouse' => ['extends' => 'regional', 'working_hours' => [1 => [['10:00', '16:00']]], 'cutoff' => ['time' => '12:00']],
        ];
        config()->set('workdays.definitions.profiles', $profiles);
        $calculator = Workday::profile('warehouse');

        $this->assertSame([['start' => '10:00', 'end' => '16:00']], $this->windows($calculator, '2026-11-02'));
        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $this->windows($calculator, '2026-11-04'));
        $this->assertTrue($calculator->isCustomHoliday('2026-11-03'));
        $this->assertTrue($calculator->isGregorianHoliday('2027-01-01'));
        $this->assertSame('12:00', $calculator->resolveCutoff('2026-11-02 11:00')->cutoffAt->format('H:i'));
        $this->assertSame($profiles, config('workdays.definitions.profiles'));
    }

    public function test_weekday_alias_override_replaces_the_complete_parent_window_list(): void
    {
        $effective = $this->resolveChild(
            ['working_hours' => ['Monday' => [['09:00', '12:00'], ['13:00', '17:00']], 'Tuesday' => [['09:00', '17:00']]]],
            ['working_hours' => [1 => [['10:00', '16:00']]]],
        );

        $this->assertSame([['10:00', '16:00']], $effective['working_hours'][1]);
        $this->assertSame([['09:00', '17:00']], $effective['working_hours'][2]);
        $this->assertCount(2, $effective['working_hours']);
    }

    public function test_child_weekday_empty_list_retains_closed_new_shift_semantics(): void
    {
        $calculator = $this->childCalculator([], ['working_hours' => ['mon' => []]]);

        $this->assertTrue($calculator->isBusinessDay('2026-11-02'));
        $this->assertSame([], $this->windows($calculator, '2026-11-02'));
        $this->assertSame('2026-11-03 09:00:00', $calculator->nextBusinessTime($this->at('2026-11-02 10:00'))->toDateTimeString());
    }

    #[DataProvider('clearedSections')]
    public function test_explicit_empty_section_clears_inherited_values(string $section, mixed $parentValue): void
    {
        $effective = $this->resolveChild([$section => $parentValue], [$section => []]);

        $this->assertSame([], $effective[$section]);
    }

    public static function clearedSections(): array
    {
        return [
            ['weekends', ['Saturday', 'Sunday']],
            ['working_hours', ['Monday' => [['09:00', '17:00']]]],
            ['holidays', ['gregorian' => ['01-01' => 'New Year'], 'jalali' => ['01-01' => 'Nowruz']]],
            ['custom_holidays', ['2026-11-03' => 'Closure']],
            ['extra_working_days', ['2026-11-07' => 'Reopening']],
            ['special_working_hours', ['2026-11-02' => [['10:00', '12:00']]]],
            ['seasonal_working_hours', [['from' => '2026-11-01', 'through' => '2026-11-30', 'weekdays' => ['Monday' => [['08:00', '20:00']]]]]],
            ['extra_working_day_hours', [['09:00', '12:00'], ['13:00', '17:00']]],
        ];
    }

    public function test_empty_weekends_and_entire_weekly_schedule_have_distinct_meanings(): void
    {
        $calculator = $this->childCalculator([], ['weekends' => [], 'working_hours' => []]);

        $this->assertTrue($calculator->isBusinessDay('2026-11-07'));
        $this->assertFalse($calculator->isWeekend('2026-11-07'));
        $this->assertSame([], $this->windows($calculator, '2026-11-07'));
    }

    public function test_recurring_holidays_merge_calendar_and_date_keys_without_losing_null_labels(): void
    {
        $effective = $this->resolveChild([
            'holidays' => ['gregorian' => ['01-01' => 'New Year', '12-25' => 'Parent Christmas'], 'jalali' => ['01-01' => 'Nowruz'], 'hijri' => ['10-01' => 'Eid']],
        ], ['holidays' => ['gregorian' => ['12-25' => null, '07-04' => 'New child holiday']]]);

        $this->assertSame(['01-01' => 'New Year', '12-25' => null, '07-04' => 'New child holiday'], $effective['holidays']['gregorian']);
        $this->assertSame(['01-01' => 'Nowruz'], $effective['holidays']['jalali']);
        $this->assertSame(['10-01' => 'Eid'], $effective['holidays']['hijri']);
    }

    public function test_empty_recurring_calendar_clears_only_that_calendar(): void
    {
        $effective = $this->resolveChild(['holidays' => ['gregorian' => ['01-01' => 'New Year'], 'jalali' => ['01-01' => 'Nowruz']]], ['holidays' => ['gregorian' => []]]);

        $this->assertSame([], $effective['holidays']['gregorian']);
        $this->assertSame(['01-01' => 'Nowruz'], $effective['holidays']['jalali']);
    }

    public function test_date_maps_merge_by_date_and_null_holiday_labels_remain_real_entries(): void
    {
        $calculator = $this->childCalculator([
            'custom_holidays' => ['2026-11-02' => 'Parent title', '2026-11-03' => 'Preserved closure'],
            'extra_working_days' => ['2026-11-07' => 'Parent reopening'],
        ], ['custom_holidays' => ['2026-11-02' => null], 'extra_working_days' => ['2026-11-07' => null, '2026-11-08' => 'Additional reopening']]);

        $this->assertTrue($calculator->isCustomHoliday('2026-11-02'));
        $this->assertTrue($calculator->isCustomHoliday('2026-11-03'));
        $this->assertTrue($calculator->isExtraWorkingDay('2026-11-07'));
        $this->assertTrue($calculator->isBusinessDay('2026-11-07'));
        $this->assertTrue($calculator->isExtraWorkingDay('2026-11-08'));
        $this->assertNull($calculator->explain('2026-11-02')->reasons[0]->title);
        $this->assertSame('child', $calculator->explain('2026-11-02')->profile);
    }

    public function test_special_date_windows_replace_as_a_list_and_explicit_empty_date_is_preserved(): void
    {
        $calculator = $this->childCalculator([
            'special_working_hours' => ['2026-11-02' => [['09:00', '12:00'], ['13:00', '17:00']], '2026-11-03' => [['10:00', '12:00']], '2026-11-04' => [['10:00', '13:00']]],
        ], ['special_working_hours' => ['2026-11-02' => [['11:00', '14:00']], '2026-11-03' => []]]);

        $this->assertSame([['start' => '11:00', 'end' => '14:00']], $this->windows($calculator, '2026-11-02'));
        $this->assertSame([], $this->windows($calculator, '2026-11-03'));
        $this->assertSame([['start' => '10:00', 'end' => '13:00']], $this->windows($calculator, '2026-11-04'));
    }

    public function test_seasonal_and_fallback_window_lists_are_replaced_entirely(): void
    {
        $season = ['from' => '2026-11-01', 'through' => '2026-11-30', 'weekdays' => ['Monday' => [['08:00', '20:00']]]];
        $childSeason = ['from' => '2026-12-01', 'through' => '2026-12-31', 'weekdays' => ['Monday' => [['10:00', '14:00']]]];
        $effective = $this->resolveChild(['seasonal_working_hours' => [$season], 'extra_working_day_hours' => [['09:00', '12:00'], ['13:00', '17:00']]], [
            'seasonal_working_hours' => [$childSeason], 'extra_working_day_hours' => [['10:00', '14:00']],
        ]);

        $this->assertSame([$childSeason], $effective['seasonal_working_hours']);
        $this->assertSame([['10:00', '14:00']], $effective['extra_working_day_hours']);
    }

    public function test_unspecified_fields_are_inherited_and_arbitrary_child_arrays_are_replaced(): void
    {
        $effective = $this->resolveChild(['label' => 'Shared profile label', 'application_metadata' => ['first', 'second']], ['application_metadata' => ['replacement']]);

        $this->assertSame('Shared profile label', $effective['label']);
        $this->assertSame(['replacement'], $effective['application_metadata']);
        $this->assertArrayNotHasKey('extends', $effective);
    }

    public function test_cutoff_weekdays_merge_aliases_preserve_null_and_inherit_timezone(): void
    {
        $calculator = $this->childCalculator(['cutoff' => ['timezone' => 'UTC', 'time' => '14:00', 'weekdays' => ['Monday' => '12:00', 'Tuesday' => '15:00']]], [
            'cutoff' => ['time' => '13:00', 'weekdays' => [1 => null, 'wed' => '11:00']],
        ]);

        $monday = $calculator->resolveCutoff('2026-11-02 10:00');
        $this->assertSame('cutoff_disabled', $monday->reason);
        $this->assertNull($monday->cutoffAt);
        $this->assertSame('2026-11-03', $monday->effectiveBusinessDate->toDateString());
        $this->assertSame('15:00', $calculator->resolveCutoff('2026-11-03 10:00')->cutoffAt->format('H:i'));
        $this->assertSame('11:00', $calculator->resolveCutoff('2026-11-04 10:00')->cutoffAt->format('H:i'));
        $this->assertSame('13:00', $calculator->resolveCutoff('2026-11-05 10:00')->cutoffAt->format('H:i'));
        $this->assertSame('UTC', $calculator->resolveCutoff('2026-11-05 10:00')->timezone);
    }

    public function test_empty_cutoff_weekdays_clear_inherited_overrides(): void
    {
        $calculator = $this->childCalculator(['cutoff' => ['timezone' => 'UTC', 'time' => '14:00', 'weekdays' => ['Monday' => null]]], ['cutoff' => ['weekdays' => []]]);

        $this->assertTrue($calculator->resolveCutoff('2026-11-02 13:00')->sameDayEligible);
        $this->assertSame('14:00', $calculator->resolveCutoff('2026-11-02 13:00')->cutoffAt->format('H:i'));
    }

    #[DataProvider('lazyInvalidCutoffs')]
    public function test_inherited_invalid_cutoff_remains_lazy_for_day_and_time_calls(mixed $cutoff): void
    {
        $calculator = $this->childCalculator(['cutoff' => $cutoff], []);

        $this->assertTrue($calculator->isBusinessDay('2026-11-02'));
        $this->assertTrue($calculator->isBusinessTime($this->at('2026-11-02 10:00')));
        $this->expectException(WorkdayConfigurationException::class);

        $calculator->resolveCutoff('2026-11-02 10:00');
    }

    public static function lazyInvalidCutoffs(): array
    {
        return [
            ['invalid policy'], [['timezone' => 'Mars/Olympus', 'time' => '14:00']], [['timezone' => 'UTC', 'time' => 'invalid']],
            [['timezone' => 'UTC', 'time' => '14:00', 'weekdays' => false]],
            [['timezone' => 'UTC', 'time' => '14:00', 'weekdays' => ['Funday' => '12:00']]],
            [['timezone' => 'UTC', 'time' => '14:00', 'weekdays' => ['Monday' => '12:00', 1 => '13:00']]],
        ];
    }

    #[DataProvider('invalidParents')]
    public function test_invalid_inheritance_parent_values_are_rejected_with_child_context(mixed $parent): void
    {
        config()->set('workdays.definitions.profiles.child', ['extends' => $parent]);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('child');

        Workday::profile('child');
    }

    public static function invalidParents(): array
    {
        return [[''], [' '], [null], [false], [true], [12], [1.5], [[]], [new \stdClass], ['missing_parent']];
    }

    #[DataProvider('invalidParentDefinitions')]
    public function test_invalid_parent_definitions_are_identified_in_the_hierarchy(mixed $definition): void
    {
        config()->set('workdays.definitions.profiles.bad_parent', $definition);
        config()->set('workdays.definitions.profiles.child', ['extends' => 'bad_parent']);

        try {
            Workday::profile('child');
            $this->fail('An invalid parent definition must not be hidden by child resolution.');
        } catch (WorkdayConfigurationException $exception) {
            $this->assertStringContainsString('child', $exception->getMessage());
            $this->assertStringContainsString('bad_parent', $exception->getMessage());
        }
    }

    public static function invalidParentDefinitions(): array
    {
        return [[null], ['not an array'], [false], [['working_hours' => []]]];
    }

    #[DataProvider('cyclicHierarchies')]
    public function test_cycles_are_rejected_with_the_relevant_profile_chain(array $profiles, array $names): void
    {
        config()->set('workdays.definitions.profiles', $profiles);

        try {
            Workday::profile('child');
            $this->fail('A cyclic inheritance chain must fail deterministically.');
        } catch (WorkdayConfigurationException $exception) {
            foreach ($names as $name) {
                $this->assertStringContainsString($name, $exception->getMessage());
            }
        }
    }

    public static function cyclicHierarchies(): array
    {
        return [
            [['child' => ['extends' => 'child']], ['child']],
            [['child' => ['extends' => 'middle'], 'middle' => ['extends' => 'parent'], 'parent' => ['extends' => 'child']], ['child', 'middle', 'parent']],
        ];
    }

    public function test_sixteen_profiles_are_supported_but_seventeen_are_rejected(): void
    {
        $profiles = ['p0' => $this->baseProfile()];
        foreach (range(1, 16) as $index) {
            $profiles['p'.$index] = ['extends' => 'p'.($index - 1)];
        }
        config()->set('workdays.definitions.profiles', $profiles);

        $this->assertTrue(Workday::profile('p15')->isBusinessDay('2026-11-02'));
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('16');

        Workday::profile('p16');
    }

    #[DataProvider('invalidChildSchedules')]
    public function test_invalid_layer_or_effective_schedules_are_rejected(array $parent, array $child): void
    {
        $this->expectException(WorkdayConfigurationException::class);

        $this->childCalculator($parent, $child);
    }

    public static function invalidChildSchedules(): array
    {
        return [
            'duplicate child aliases' => [[], ['working_hours' => ['Monday' => [['09:00', '17:00']], 1 => [['10:00', '12:00']]]]],
            'unknown child weekday' => [[], ['working_hours' => ['Funday' => []]]],
            'overlapping child windows' => [[], ['working_hours' => ['Monday' => [['09:00', '12:00'], ['11:00', '17:00']]]]],
            'invalid parent is not repaired by child' => [['working_hours' => ['Monday' => [['22:00', '06:00']]]], ['working_hours' => []]],
            'disable inherited overnight without replacing windows' => [['allow_overnight_working_hours' => true, 'working_hours' => ['Monday' => [['22:00', '06:00']]]], ['allow_overnight_working_hours' => false]],
            'invalid inherited flag' => [['allow_overnight_working_hours' => 'true'], []],
            'overlapping inherited seasons' => [['seasonal_working_hours' => [['from' => '2026-11-01', 'through' => '2026-11-30', 'weekdays' => []]]], ['seasonal_working_hours' => [['from' => '2026-11-01', 'through' => '2026-11-30', 'weekdays' => []], ['from' => '2026-11-15', 'through' => '2026-12-01', 'weekdays' => []]]]],
        ];
    }

    public function test_a_child_can_disable_overnight_after_replacing_the_requiring_schedule(): void
    {
        $calculator = $this->childCalculator(['allow_overnight_working_hours' => true, 'working_hours' => ['Monday' => [['22:00', '06:00']]]], [
            'allow_overnight_working_hours' => false, 'working_hours' => ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]],
        ]);

        $this->assertSame('2026-11-02 11:00:30', $calculator->addBusinessMinutes($this->at('2026-11-02 09:59:30'), 1)->toDateTimeString());
    }

    public function test_inherited_false_only_flag_uses_the_same_exact_precision_as_other_schedules(): void
    {
        $calculator = $this->childCalculator(['allow_overnight_working_hours' => false, 'working_hours' => ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]]], []);

        // Refactor 03 intentionally removes the old partial-minute discard in the child too.
        $this->assertSame('2026-11-02 11:00:30', $calculator->addBusinessMinutes($this->at('2026-11-02 09:59:30'), 1)->toDateTimeString());
        $this->assertSame(1, $calculator->diffBusinessMinutes($this->at('2026-11-02 09:59:30'), $this->at('2026-11-02 11:00:30')));
    }

    public function test_routing_selects_the_most_specific_rule_even_when_it_appears_last(): void
    {
        $result = Workday::resolveProfile(['kind' => 'warehouse', 'region' => 'tehran', 'warehouse_id' => 42, 'seller_id' => 'seller-7']);

        $this->assertSame(['profile' => 'warehouse_tehran', 'source' => 'rule', 'rule_id' => 'tehran_warehouse_42'], $result->toArray());
    }

    public function test_equal_specificity_uses_configuration_order_and_never_profile_name_sorting(): void
    {
        config()->set('workdays.definitions.profile_routing.rules', [
            ['id' => 'first', 'match' => ['kind' => 'warehouse'], 'profile' => 'warehouse_tehran'],
            ['id' => 'second', 'match' => ['kind' => 'warehouse'], 'profile' => 'express_carrier'],
        ]);

        $this->assertSame('first', Workday::resolveProfile(['kind' => 'warehouse'])->ruleId);
        $this->assertSame('warehouse_tehran', Workday::resolveProfile(['kind' => 'warehouse'])->profile);
    }

    #[DataProvider('strictRoutingContexts')]
    public function test_context_matching_is_strict_case_sensitive_and_requires_every_rule_field(array $context, string $profile, ?string $rule): void
    {
        $result = Workday::resolveProfile($context);

        $this->assertSame($profile, $result->profile);
        $this->assertSame($rule, $result->ruleId);
        $this->assertSame($rule === null ? 'fallback' : 'rule', $result->source);
    }

    public static function strictRoutingContexts(): array
    {
        return [
            [[], 'warehouse_base', null],
            [['kind' => 'seller'], 'warehouse_base', null],
            [['warehouse_id' => 42, 'region' => 'tehran'], 'warehouse_base', null],
            [['kind' => 'warehouse'], 'warehouse_base', 'general_warehouse'],
            [['kind' => 'warehouse', 'region' => 'tehran'], 'warehouse_tehran', 'tehran_region'],
            [['kind' => 'warehouse', 'region' => 'tehran', 'warehouse_id' => '42'], 'warehouse_tehran', 'tehran_region'],
            [['kind' => 'warehouse', 'region' => 'Tehran', 'warehouse_id' => 42], 'warehouse_base', 'general_warehouse'],
            [['kind' => 'Warehouse', 'region' => 'tehran', 'warehouse_id' => 42], 'warehouse_base', null],
            [['kind' => ' warehouse'], 'warehouse_base', null],
            [['kind' => 'carrier', 'carrier' => 'express'], 'express_carrier', 'express'],
        ];
    }

    public function test_empty_rule_list_uses_the_explicit_fallback_instead_of_default_profile(): void
    {
        config()->set('workdays.definitions.default_profile', 'express_carrier');
        config()->set('workdays.definitions.profile_routing', ['fallback_profile' => 'warehouse_isfahan', 'rules' => []]);

        $this->assertSame(['profile' => 'warehouse_isfahan', 'source' => 'fallback', 'rule_id' => null], Workday::resolveProfile([])->toArray());
    }

    public function test_nonempty_whitespace_values_are_compared_exactly_without_normalization(): void
    {
        config()->set('workdays.definitions.profile_routing.rules', [['id' => 'space', 'match' => ['custom_identifier' => ' '], 'profile' => 'warehouse_tehran']]);

        $this->assertSame('space', Workday::resolveProfile(['custom_identifier' => ' '])->ruleId);
        $this->assertSame('fallback', Workday::resolveProfile(['custom_identifier' => '  '])->source);
    }

    public function test_wildcard_and_regex_shaped_values_have_no_pattern_matching_behavior(): void
    {
        config()->set('workdays.definitions.profile_routing.rules', [
            ['id' => 'star', 'match' => ['region' => '*'], 'profile' => 'warehouse_tehran'],
            ['id' => 'regex', 'match' => ['region' => '/teh.*/'], 'profile' => 'warehouse_tehran'],
        ]);

        $this->assertSame('fallback', Workday::resolveProfile(['region' => 'tehran'])->source);
        $this->assertSame('star', Workday::resolveProfile(['region' => '*'])->ruleId);
        $this->assertSame('regex', Workday::resolveProfile(['region' => '/teh.*/'])->ruleId);
    }

    #[DataProvider('invalidRoutingConfigurations')]
    public function test_invalid_routing_configuration_is_rejected(mixed $routing): void
    {
        config()->set('workdays.definitions.profile_routing', $routing);
        $this->expectException(WorkdayConfigurationException::class);

        Workday::resolveProfile(['kind' => 'warehouse']);
    }

    public static function invalidRoutingConfigurations(): iterable
    {
        $valid = ['fallback_profile' => 'warehouse_base', 'rules' => []];
        foreach (['null' => null, 'scalar' => 'warehouse_base', 'boolean' => true, 'list' => ['warehouse_base']] as $name => $value) {
            yield $name => [$value];
        }
        foreach (['fallback_profile', 'rules'] as $missing) {
            $config = $valid;
            unset($config[$missing]);
            yield 'missing '.$missing => [$config];
        }
        foreach (['unknown fallback' => 'not_configured', 'empty fallback' => '', 'null fallback' => null, 'integer fallback' => 42] as $name => $value) {
            yield $name => [array_replace($valid, ['fallback_profile' => $value])];
        }
        yield 'unsupported top level key' => [$valid + ['automatic_routing' => true]];
        yield 'rules not list' => [array_replace($valid, ['rules' => ['named' => ['id' => 'x', 'match' => ['kind' => 'warehouse'], 'profile' => 'warehouse_base']]])];
        yield 'rules sparse list' => [array_replace($valid, ['rules' => [1 => ['id' => 'x', 'match' => ['kind' => 'warehouse'], 'profile' => 'warehouse_base']]])];
        yield 'rules scalar' => [array_replace($valid, ['rules' => false])];
    }

    #[DataProvider('invalidRoutingRules')]
    public function test_invalid_rule_shapes_targets_and_match_values_are_rejected(mixed $rule): void
    {
        config()->set('workdays.definitions.profile_routing.rules', [$rule]);
        $this->expectException(WorkdayConfigurationException::class);

        Workday::resolveProfile(['kind' => 'warehouse']);
    }

    public static function invalidRoutingRules(): iterable
    {
        $valid = ['id' => 'rule', 'match' => ['kind' => 'warehouse'], 'profile' => 'warehouse_base'];
        yield 'rule scalar' => [false];
        foreach (['id', 'match', 'profile'] as $missing) {
            $rule = $valid;
            unset($rule[$missing]);
            yield 'missing '.$missing => [$rule];
        }
        foreach (['empty id' => '', 'null id' => null, 'numeric id' => 1] as $name => $value) {
            yield $name => [array_replace($valid, ['id' => $value])];
        }
        yield 'unknown rule field' => [$valid + ['priority' => 10]];
        yield 'unknown target' => [array_replace($valid, ['profile' => 'not_configured'])];
        yield 'invalid target' => [array_replace($valid, ['profile' => false])];
        foreach (['empty match' => [], 'match list' => ['warehouse'], 'match scalar' => 'warehouse'] as $name => $match) {
            yield $name => [array_replace($valid, ['match' => $match])];
        }
        foreach (['empty key' => '', 'invalid key' => 'warehouse-id', 'spaced key' => ' kind', 'numeric key' => 12] as $name => $key) {
            yield $name => [array_replace($valid, ['match' => [$key => 'warehouse']])];
        }
        foreach (['boolean' => true, 'float' => 42.0, 'null' => null, 'array' => [], 'object' => new \stdClass, 'empty string' => '', 'callable' => static fn (): string => 'warehouse'] as $name => $value) {
            yield 'invalid match '.$name => [array_replace($valid, ['match' => ['kind' => $value]])];
        }
    }

    public function test_duplicate_routing_rule_ids_are_rejected(): void
    {
        config()->set('workdays.definitions.profile_routing.rules', [
            ['id' => 'same', 'match' => ['kind' => 'warehouse'], 'profile' => 'warehouse_base'],
            ['id' => 'same', 'match' => ['kind' => 'carrier'], 'profile' => 'express_carrier'],
        ]);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('unique');

        Workday::resolveProfile([]);
    }

    #[DataProvider('invalidContexts')]
    public function test_invalid_context_keys_and_values_are_rejected(array $context): void
    {
        $this->expectException(InvalidArgumentException::class);

        Workday::resolveProfile($context);
    }

    public static function invalidContexts(): iterable
    {
        foreach (['empty' => '', 'hyphen' => 'warehouse-id', 'numeric' => 4, 'leading digit' => '4warehouse', 'space' => ' region', 'newline' => "region\n"] as $name => $key) {
            yield 'key '.$name => [[$key => 'value']];
        }
        foreach (['boolean' => false, 'float' => 42.0, 'null' => null, 'array' => [], 'object' => new \stdClass, 'empty' => '', 'callable' => static fn (): string => 'warehouse'] as $name => $value) {
            yield 'value '.$name => [['kind' => $value]];
        }
    }

    public function test_missing_routing_errors_only_when_the_new_apis_are_explicitly_used(): void
    {
        $config = config('workdays');
        unset($config['definitions']['profile_routing']);
        config()->set('workdays', $config);
        $this->assertTrue(Workday::isBusinessDay('2026-11-02'));
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('profile_routing');

        Workday::profileFor([]);
    }

    public function test_malformed_unrelated_routing_and_profiles_do_not_break_ordinary_apis(): void
    {
        config()->set('workdays.definitions.profile_routing', ['broken' => true]);
        config()->set('workdays.definitions.profiles.unrelated', ['extends' => 'missing']);

        $this->assertTrue(Workday::profile('warehouse_base')->isBusinessDay('2026-11-02'));
        $this->assertTrue(Workday::profile('warehouse_tehran')->isBusinessTime($this->at('2026-11-02 10:00')));
        $this->assertSame('2026-11-02 11:00:00', Workday::addBusinessMinutes($this->at('2026-11-02 10:00'), 60)->toDateTimeString());
    }

    public function test_result_is_immutable_has_stable_fields_and_exposes_no_raw_context(): void
    {
        $result = Workday::resolveProfile(['kind' => 'warehouse', 'region' => 'tehran', 'warehouse_id' => 42, 'private_reference' => 'sensitive-identifier']);

        $this->assertInstanceOf(ResolvedProfile::class, $result);
        $this->assertSame(['profile' => 'warehouse_tehran', 'source' => 'rule', 'rule_id' => 'tehran_warehouse_42'], $result->toArray());
        $this->assertSame(['profile', 'source', 'ruleId'], array_keys(get_object_vars($result)));
        $this->assertStringNotContainsString('sensitive-identifier', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        $this->expectException(Error::class);

        $result->profile = 'warehouse_base';
    }

    public function test_manager_facade_and_selected_calculator_share_the_same_profile_semantics(): void
    {
        $context = ['kind' => 'warehouse', 'region' => 'tehran', 'warehouse_id' => 42];
        $manager = $this->app->make(WorkdayManager::class);
        $selection = Workday::resolveProfile($context);
        $calculator = Workday::profileFor($context);

        $this->assertSame($selection->toArray(), $manager->resolveProfile($context)->toArray());
        $this->assertInstanceOf(BusinessDayCalculator::class, $calculator);
        $this->assertSame(Workday::profile($selection->profile)->explain('2026-11-02')->toArray(), $calculator->explain('2026-11-02')->toArray());
        $this->assertSame($this->windows($calculator, '2026-11-05'), $this->windows($manager->profileFor($context), '2026-11-05'));
    }

    public function test_config_routing_does_not_query_the_database_and_invalid_sources_cannot_bypass_selection(): void
    {
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();

        $this->assertSame('warehouse_tehran', Workday::resolveProfile(['kind' => 'warehouse', 'region' => 'tehran'])->profile);
        $this->assertSame([], $connection->getQueryLog());
        $connection->disableQueryLog();
        config()->set('workdays.driver', 'invalid_storage');
        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('workdays.driver');
        Workday::resolveProfile([]);
    }

    public function test_configuration_changes_are_observed_on_new_calls_and_never_mutate_old_calculators(): void
    {
        $old = Workday::profile('warehouse_tehran');
        config()->set('workdays.definitions.profiles.warehouse_base.working_hours.Monday', [['10:00', '12:00']]);

        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $this->windows($old, '2026-11-02'));
        $this->assertSame([['start' => '10:00', 'end' => '12:00']], $this->windows(Workday::profile('warehouse_tehran'), '2026-11-02'));
        $this->assertSame('warehouse_tehran', Workday::resolveProfile(['kind' => 'warehouse', 'region' => 'tehran'])->profile);
        config()->set('workdays.definitions.profile_routing.rules', []);
        $this->assertSame('warehouse_base', Workday::resolveProfile(['kind' => 'warehouse', 'region' => 'tehran'])->profile);
    }

    public function test_config_holidays_and_extra_days_are_inherited_under_selected_child_identity(): void
    {
        $calculator = $this->childCalculator(['custom_holidays' => ['2026-11-03' => 'Parent config closure'], 'extra_working_days' => ['2026-11-07' => 'Parent config extra day'], 'extra_working_day_hours' => [['10:00', '14:00']]], []);

        $this->assertTrue($calculator->isGregorianHoliday('2027-01-01'));
        $this->assertTrue($calculator->isCustomHoliday('2026-11-03'));
        $this->assertTrue($calculator->isBusinessDay('2026-11-07'));
        $this->assertSame([['start' => '10:00', 'end' => '14:00']], $this->windows($calculator, '2026-11-07'));
        $this->assertSame('child', $calculator->explain('2026-11-03')->profile);
    }

    public function test_logical_holidays_from_parent_and_child_are_both_inherited_under_child_identity(): void
    {
        config()->set('workdays.definitions.profiles.warehouse_base.custom_holidays', ['2026-11-02' => 'Parent logical holiday']);
        config()->set('workdays.definitions.profiles.warehouse_tehran.custom_holidays', ['2026-11-03' => 'Child logical holiday']);
        $child = Workday::profile('warehouse_tehran');

        $this->assertFalse($child->isBusinessDay('2026-11-02'));
        $this->assertFalse($child->isBusinessDay('2026-11-03'));
        $this->assertFalse(Workday::profile('warehouse_base')->isBusinessDay('2026-11-02'));
        $this->assertSame('warehouse_tehran', $child->explain('2026-11-03')->profile);
        $this->assertSame('Child logical holiday', $child->explain('2026-11-03')->reasons[0]->title);
        $this->assertSame('Parent logical holiday', $child->explain('2026-11-02')->reasons[0]->title);
    }

    public function test_child_logical_reopening_overrides_inherited_closure_without_a_mixed_source(): void
    {
        config()->set('workdays.definitions.profiles.warehouse_base.custom_holidays', ['2026-11-02' => 'Inherited closure', '2026-11-03' => 'Parent closure']);
        config()->set('workdays.definitions.profiles.warehouse_tehran.extra_working_days', ['2026-11-02' => 'Child reopening']);
        config()->set('workdays.definitions.profiles.warehouse_tehran.custom_holidays', ['2026-11-04' => 'Child closure']);
        $child = Workday::profile('warehouse_tehran');

        $this->assertTrue($child->isBusinessDay('2026-11-02'));
        $this->assertFalse($child->isBusinessDay('2026-11-03'));
        $this->assertFalse($child->isBusinessDay('2026-11-04'));
        $this->assertFalse($child->isBusinessDay('2027-01-01'));
        $this->assertTrue($child->explain('2026-11-02')->isExtraWorkingDay);
    }

    #[DataProvider('cutoffDeliveryPlans')]
    public function test_delivery_plans_use_inherited_cutoff_and_child_overrides(string $profile, string $receivedAt, string $expected): void
    {
        $plan = $this->workPlan($profile, 60);
        $plan['cutoff_profile'] = $profile;

        $result = Workday::planDelivery($receivedAt, $plan);

        $this->assertSame($expected, $result->estimatedCompletionAt->toDateTimeString());
        $this->assertSame($profile, $result->cutoffResult->profile);
    }

    public static function cutoffDeliveryPlans(): array
    {
        return [
            ['warehouse_tehran', '2026-11-02T13:00:00Z', '2026-11-03 10:00:00'],
            ['warehouse_isfahan', '2026-11-02T14:00:00Z', '2026-11-03 10:00:00'],
            ['warehouse_base', '2026-11-02T13:00:00Z', '2026-11-02 14:00:00'],
        ];
    }

    public function test_delivery_plan_uses_inherited_overnight_metadata_for_exact_horizon_capacity(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $this->childCalculator(['allow_overnight_working_hours' => true, 'working_hours' => ['Monday' => [['22:00', '06:00']]]], []);

        $result = Workday::planDelivery('2026-11-02T22:00:00Z', $this->workPlan('child', 480));

        $this->assertSame('2026-11-03 06:00:00', $result->estimatedCompletionAt->toDateTimeString());
        $this->assertSame([['start' => '00:00', 'end' => '06:00']], $this->windows(Workday::profile('child'), '2026-11-03'));
    }

    public function test_delivery_plan_respects_child_disabling_overnight_and_replacing_night_windows(): void
    {
        $this->childCalculator(['allow_overnight_working_hours' => true, 'working_hours' => ['Monday' => [['22:00', '06:00']]]], [
            'allow_overnight_working_hours' => false, 'working_hours' => ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]],
        ]);

        $result = Workday::planDelivery('2026-11-02T09:59:30Z', $this->workPlan('child', 1));

        $this->assertSame('2026-11-02 11:00:30', $result->estimatedCompletionAt->toDateTimeString());
    }

    public function test_explicit_planner_dependencies_use_one_context_without_a_constructor_fallback(): void
    {
        $context = $this->app->make(CalculationContextFactory::class)->open();
        $planner = new DeliveryPlanner($context->calculator(...), $context->configuration(...), $context->maxScanDays, $context->hasProfile(...));
        config()->set('workdays.definitions.profiles', []);

        $this->assertSame('2026-11-02 11:00:00', $planner->plan('2026-11-02T10:00:00Z', $this->workPlan('warehouse_base', 60))->estimatedCompletionAt->toDateTimeString());
    }

    public function test_delivery_slots_use_inherited_calendar_and_exact_date_overrides(): void
    {
        $this->childCalculator(['custom_holidays' => ['2026-11-03' => 'Inherited closure']], ['special_working_hours' => ['2026-11-02' => [['10:00', '14:00']]]]);
        $result = Workday::deliverySlots('2026-11-02T09:00:00Z', $this->slotOptions(['days' => 2]));

        $this->assertSame([
            ['2026-11-02 10:00', '2026-11-02 12:00'], ['2026-11-02 11:00', '2026-11-02 13:00'], ['2026-11-02 12:00', '2026-11-02 14:00'],
        ], $this->slotIntervals($result->toArray()['slots']));
    }

    public function test_inherited_seasonal_hours_apply_until_exact_child_date_takes_precedence(): void
    {
        $calculator = $this->childCalculator(['seasonal_working_hours' => [['from' => '2026-11-01', 'through' => '2026-11-30', 'weekdays' => ['Monday' => [['08:00', '20:00']]]]]], ['special_working_hours' => ['2026-11-02' => []]]);

        $this->assertSame([], $this->windows($calculator, '2026-11-02'));
        $this->assertSame([['start' => '08:00', 'end' => '20:00']], $this->windows($calculator, '2026-11-09'));
        $this->assertCount(11, Workday::deliverySlots('2026-11-09T08:00:00Z', $this->slotOptions())->slots);
    }

    public function test_resolved_names_can_feed_existing_plan_and_slot_contracts_without_automatic_routing(): void
    {
        $warehouse = Workday::resolveProfile(['kind' => 'warehouse', 'region' => 'tehran', 'warehouse_id' => 42]);
        $carrier = Workday::resolveProfile(['kind' => 'carrier', 'carrier' => 'express']);
        $before = config('workdays');
        $plan = [
            'timezone' => 'UTC', 'profile_timezones' => [$warehouse->profile => 'UTC', $carrier->profile => 'UTC'],
            'stages' => [
                ['id' => 'ready_for_collection', 'type' => 'work', 'profile' => $warehouse->profile, 'minutes' => 120],
                ['id' => 'collection_handoff', 'type' => 'handoff', 'profiles' => [$warehouse->profile, $carrier->profile]],
            ],
        ];

        $readiness = Workday::planDelivery('2026-11-02T10:00:00Z', $plan);
        $slots = Workday::deliverySlots($readiness->estimatedCompletionAt, $this->slotOptions(['profile' => $warehouse->profile]));

        $this->assertSame('2026-11-02 12:00:00', $readiness->estimatedCompletionAt->toDateTimeString());
        $this->assertSame('2026-11-02T12:00:00.000000+00:00', $slots->toArray()['slots'][0]['starts_at']);
        $this->assertSame($before, config('workdays'));
    }

    private function baseProfile(): array
    {
        return [
            'weekends' => ['Saturday', 'Sunday'],
            'holidays' => ['gregorian' => ['01-01' => 'New Year'], 'jalali' => [], 'hijri' => []],
            'custom_holidays' => [], 'extra_working_days' => [],
            'working_hours' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], [['09:00', '17:00']]),
            'cutoff' => ['timezone' => 'UTC', 'time' => '14:00'],
        ];
    }

    private function routing(): array
    {
        return ['fallback_profile' => 'warehouse_base', 'rules' => [
            ['id' => 'general_warehouse', 'match' => ['kind' => 'warehouse'], 'profile' => 'warehouse_base'],
            ['id' => 'tehran_region', 'match' => ['kind' => 'warehouse', 'region' => 'tehran'], 'profile' => 'warehouse_tehran'],
            ['id' => 'tehran_warehouse_42', 'match' => ['kind' => 'warehouse', 'region' => 'tehran', 'warehouse_id' => 42], 'profile' => 'warehouse_tehran'],
            ['id' => 'express', 'match' => ['kind' => 'carrier', 'carrier' => 'express'], 'profile' => 'express_carrier'],
        ]];
    }

    private function resolveChild(array $parent, array $child): array
    {
        $profiles = ['parent' => array_replace($this->baseProfile(), $parent), 'child' => array_replace(['extends' => 'parent'], $child)];

        return (new ProfileConfigResolver(static fn (string $name): array => $profiles[$name]))->resolve('child');
    }

    private function childCalculator(array $parent, array $child): BusinessDayCalculator
    {
        config()->set('workdays.definitions.profiles', ['parent' => array_replace($this->baseProfile(), $parent), 'child' => array_replace(['extends' => 'parent'], $child)]);

        return Workday::profile('child');
    }

    private function at(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }

    private function windows(BusinessDayCalculator $calculator, string $date): array
    {
        return array_map(static fn (TimeWindow $window): array => $window->toArray(), $calculator->workingWindowsFor($this->at($date)));
    }

    private function workPlan(string $profile, int $minutes): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => [$profile => 'UTC'], 'stages' => [['id' => 'ready_for_collection', 'type' => 'work', 'profile' => $profile, 'minutes' => $minutes]]];
    }

    private function slotOptions(array $overrides = []): array
    {
        return array_replace(['profile' => 'child', 'timezone' => 'UTC', 'days' => 1, 'duration_minutes' => 120, 'step_minutes' => 60, 'max_slots' => 50], $overrides);
    }

    private function slotIntervals(array $slots): array
    {
        return array_map(static fn (array $slot): array => [CarbonImmutable::parse($slot['starts_at'])->format('Y-m-d H:i'), CarbonImmutable::parse($slot['ends_at'])->format('Y-m-d H:i')], $slots);
    }
}
