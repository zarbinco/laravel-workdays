<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;
use Zarbinco\LaravelWorkdays\Rules\WorkdayRule;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class ValidationRulesTest extends TestCase
{
    public function test_workday_rule_factory_returns_validation_rule_instances(): void
    {
        $this->assertInstanceOf(ValidationRule::class, WorkdayRule::businessDay());
        $this->assertInstanceOf(ValidationRule::class, WorkdayRule::businessTime());
        $this->assertInstanceOf(ValidationRule::class, WorkdayRule::afterBusinessDays(1));
    }

    public function test_business_day_rule_passes_for_business_day(): void
    {
        $validator = $this->validator('delivery_date', '2026-06-29', WorkdayRule::businessDay('global'));

        $this->assertFalse($validator->fails());
    }

    public function test_business_day_rule_fails_for_weekend(): void
    {
        $validator = $this->validator('delivery_date', '2026-06-27', WorkdayRule::businessDay('global'));

        $this->assertTrue($validator->fails());
        $this->assertSame('The delivery date must be a business day.', $validator->errors()->first('delivery_date'));
    }

    public function test_non_working_day_rule_passes_for_weekend(): void
    {
        $validator = $this->validator('closed_at', '2026-06-27', WorkdayRule::nonWorkingDay('global'));

        $this->assertFalse($validator->fails());
    }

    public function test_calendar_holiday_rule_passes_for_configured_holiday(): void
    {
        $validator = $this->validator('holiday', '2026-12-25', WorkdayRule::calendarHoliday('global'));

        $this->assertFalse($validator->fails());
    }

    public function test_not_calendar_holiday_rule_fails_for_calendar_holiday(): void
    {
        $validator = $this->validator('holiday', '2026-12-25', WorkdayRule::notCalendarHoliday('global'));

        $this->assertTrue($validator->fails());
        $this->assertSame('The holiday must not be a calendar holiday.', $validator->errors()->first('holiday'));
    }

    public function test_custom_holiday_rule_passes_for_custom_holiday(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-06-29' => 'Company holiday',
            ],
        ]);

        $validator = $this->validator('holiday', '2026-06-29', WorkdayRule::customHoliday('global'));

        $this->assertFalse($validator->fails());
    }

    public function test_extra_working_day_rule_passes_for_extra_working_day(): void
    {
        $this->setProfileConfig('global', [
            'extra_working_days' => [
                '2026-06-27' => 'Compensation working day',
            ],
        ]);

        $validator = $this->validator('workday', '2026-06-27', WorkdayRule::extraWorkingDay('global'));

        $this->assertFalse($validator->fails());
    }

    public function test_weekend_rule_passes_for_weekend(): void
    {
        $validator = $this->validator('date', '2026-06-27', WorkdayRule::weekend('global'));

        $this->assertFalse($validator->fails());
    }

    public function test_not_weekend_rule_fails_for_weekend(): void
    {
        $validator = $this->validator('date', '2026-06-27', WorkdayRule::notWeekend('global'));

        $this->assertTrue($validator->fails());
        $this->assertSame('The date must not be a weekend.', $validator->errors()->first('date'));
    }

    public function test_business_time_rule_passes_inside_working_window(): void
    {
        $this->setGlobalWorkingHours();

        $validator = $this->validator('starts_at', '2026-06-29 10:00', WorkdayRule::businessTime('global'));

        $this->assertFalse($validator->fails());
    }

    public function test_business_time_rule_fails_outside_working_window(): void
    {
        $this->setGlobalWorkingHours();

        $validator = $this->validator('starts_at', '2026-06-29 08:59', WorkdayRule::businessTime('global'));

        $this->assertTrue($validator->fails());
        $this->assertSame('The starts at must be within business hours.', $validator->errors()->first('starts_at'));
    }

    public function test_business_time_rule_fails_at_window_end(): void
    {
        $this->setGlobalWorkingHours();

        $validator = $this->validator('starts_at', '2026-06-29 17:00', WorkdayRule::businessTime('global'));

        $this->assertTrue($validator->fails());
    }

    public function test_business_time_rule_fails_gracefully_when_working_hours_missing(): void
    {
        $validator = $this->validator('starts_at', '2026-06-29 10:00', WorkdayRule::businessTime('global'));

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'The starts at could not be validated because working hours are not configured.',
            $validator->errors()->first('starts_at'),
        );
    }

    public function test_after_business_days_rule_passes_when_value_is_after_target(): void
    {
        $validator = $this->validator('due_date', '2026-07-01', WorkdayRule::afterBusinessDays(3, '2026-06-26', 'global'));

        $this->assertFalse($validator->fails());
    }

    public function test_after_business_days_rule_fails_when_value_is_before_target(): void
    {
        $validator = $this->validator('due_date', '2026-06-30', WorkdayRule::afterBusinessDays(3, '2026-06-26', 'global'));

        $this->assertTrue($validator->fails());
        $this->assertSame('The due date must be after 3 business days.', $validator->errors()->first('due_date'));
    }

    public function test_before_business_days_rule_passes_when_value_is_before_target(): void
    {
        $validator = $this->validator('start_date', '2026-07-03', WorkdayRule::beforeBusinessDays(3, '2026-07-08', 'global'));

        $this->assertFalse($validator->fails());
    }

    public function test_before_business_days_rule_fails_when_value_is_after_target(): void
    {
        $validator = $this->validator('start_date', '2026-07-06', WorkdayRule::beforeBusinessDays(3, '2026-07-08', 'global'));

        $this->assertTrue($validator->fails());
        $this->assertSame('The start date must be before 3 business days.', $validator->errors()->first('start_date'));
    }

    public function test_relative_business_day_rules_reject_negative_days(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Business days must be zero or greater.');

        WorkdayRule::afterBusinessDays(-1);
    }

    public function test_rule_fails_for_invalid_date_string(): void
    {
        $validator = $this->validator('delivery_date', '2026-02-31', WorkdayRule::businessDay('global'));

        $this->assertTrue($validator->fails());
        $this->assertSame('The delivery date is not a valid date.', $validator->errors()->first('delivery_date'));
    }

    public function test_rule_fails_for_invalid_datetime_string(): void
    {
        $this->setGlobalWorkingHours();

        $validator = $this->validator('starts_at', '2026-02-31 10:00', WorkdayRule::businessTime('global'));

        $this->assertTrue($validator->fails());
        $this->assertSame('The starts at is not a valid date.', $validator->errors()->first('starts_at'));
    }

    public function test_rule_supports_carbon_instance_when_used_manually(): void
    {
        $validator = $this->validator('delivery_date', CarbonImmutable::parse('2026-06-29'), WorkdayRule::businessDay('global'));

        $this->assertFalse($validator->fails());
    }

    public function test_rule_uses_default_profile_when_profile_is_null(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-06-29' => 'Company holiday',
            ],
        ]);
        config()->set('workdays.default_profile', 'global');

        $validator = $this->validator('delivery_date', '2026-06-29', WorkdayRule::businessDay());

        $this->assertTrue($validator->fails());
    }

    public function test_rule_uses_given_profile(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-06-29' => 'Company holiday',
            ],
        ]);
        config()->set('workdays.default_profile', 'global');

        $validator = $this->validator('delivery_date', '2026-06-29', WorkdayRule::businessDay('iran'));

        $this->assertFalse($validator->fails());
    }

    public function test_custom_message_can_be_used_if_supported(): void
    {
        $validator = $this->validator(
            'delivery_date',
            '2026-06-27',
            WorkdayRule::businessDay('global')->message('Please choose a working day.'),
        );

        $this->assertTrue($validator->fails());
        $this->assertSame('Please choose a working day.', $validator->errors()->first('delivery_date'));
    }

    public function test_validation_rule_respects_imported_official_iran_holiday(): void
    {
        $this->loadWorkdayMigrations();
        config()->set('workdays.storage.driver', 'database');
        Artisan::call('workdays:import-iran-calendar', ['year' => 1405]);

        $validator = $this->validator('delivery_date', '2026-03-21', WorkdayRule::businessDay('iran'));

        $this->assertTrue($validator->fails());
    }

    public function test_business_time_rule_respects_extra_working_day_over_holiday(): void
    {
        $this->loadWorkdayMigrations();
        config()->set('workdays.storage.driver', 'database');
        $this->setProfileConfig('iran', [
            'working_hours' => [
                'Saturday' => [['09:00', '17:00']],
            ],
        ]);
        Artisan::call('workdays:import-iran-calendar', ['year' => 1405]);
        WorkdaySpecialDate::create([
            'profile' => 'iran',
            'date' => '2026-03-21',
            'type' => 'working_day',
            'title' => 'Compensation working day',
            'is_active' => true,
        ]);

        $validator = $this->validator('starts_at', '2026-03-21 10:00', WorkdayRule::businessTime('iran'));

        $this->assertFalse($validator->fails());
    }

    private function validator(string $attribute, mixed $value, ValidationRule $rule): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make(
            [$attribute => $value],
            [$attribute => ['required', $rule]],
        );
    }

    private function setGlobalWorkingHours(): void
    {
        $this->setProfileConfig('global', [
            'working_hours' => [
                'Monday' => [['09:00', '17:00']],
                'Tuesday' => [['09:00', '17:00']],
                'Wednesday' => [['09:00', '17:00']],
                'Thursday' => [['09:00', '17:00']],
                'Friday' => [['09:00', '17:00']],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function setProfileConfig(string $profile, array $overrides): void
    {
        $config = config('workdays');
        $config['profiles'][$profile] = array_replace_recursive($config['profiles'][$profile], $overrides);

        config()->set('workdays', $config);
    }

    private function loadWorkdayMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
    }
}
