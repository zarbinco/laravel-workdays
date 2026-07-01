<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use InvalidArgumentException;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Calendars\IranOfficialCalendar;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class ReleaseReadinessTest extends TestCase
{
    public function test_max_scan_days_exists_in_default_config(): void
    {
        $this->assertSame(3660, config('workdays.max_scan_days'));
    }

    public function test_max_scan_days_exists_in_iran_config(): void
    {
        $config = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'workdays-iran.php';

        $this->assertSame(3660, $config['max_scan_days']);
    }

    public function test_iran_official_calendar_is_disabled_by_default(): void
    {
        $this->assertSame([
            'enabled' => false,
            'year' => null,
            'profile' => null,
            'calendar_path' => null,
        ], config('workdays.iran_official'));
    }

    public function test_default_config_does_not_select_1405_official_year(): void
    {
        $defaultConfig = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'workdays.php';
        $iranConfig = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'workdays-iran.php';

        $this->assertFalse($defaultConfig['iran_official']['enabled']);
        $this->assertNull($defaultConfig['iran_official']['year']);
        $this->assertNull($defaultConfig['iran_official']['profile']);
        $this->assertNull($defaultConfig['iran_official']['calendar_path']);
        $this->assertFalse($iranConfig['iran_official']['enabled']);
        $this->assertNull($iranConfig['iran_official']['year']);
        $this->assertNull($iranConfig['iran_official']['profile']);
        $this->assertNull($iranConfig['iran_official']['calendar_path']);
    }

    public function test_default_iran_profile_does_not_auto_load_1405_dataset(): void
    {
        $calculator = Workday::profile('iran');

        $this->assertFalse((new IranOfficialCalendar)->hasYear(1405));
        $this->assertNotContains(1405, (new IranOfficialCalendar)->availableYears());
        $this->assertTrue($calculator->isBusinessDay('2026-04-14'));
        $this->assertFalse($calculator->isCalendarHoliday('2026-04-14'));
        $this->assertFalse($calculator->isCustomHoliday('2026-04-14'));
    }

    public function test_1405_dataset_is_available_from_custom_calendar_path(): void
    {
        $calendar = new IranOfficialCalendar($this->createTemporaryIranOfficialCalendarFixture());

        $this->assertTrue($calendar->hasYear(1405));
        $this->assertContains(1405, $calendar->availableYears());
        $this->assertFalse(config('workdays.iran_official.enabled'));
        $this->assertNull(config('workdays.iran_official.year'));
        $this->assertNull(config('workdays.iran_official.calendar_path'));
    }

    public function test_max_scan_days_must_be_positive_integer(): void
    {
        config()->set('workdays.max_scan_days', 0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The workdays max_scan_days config value must be a positive integer.');

        Workday::profile('global');
    }

    public function test_max_scan_days_must_be_an_integer(): void
    {
        config()->set('workdays.max_scan_days', '3660');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The workdays max_scan_days config value must be a positive integer.');

        Workday::profile('global');
    }

    public function test_next_business_day_throws_when_no_business_day_found_within_max_scan_days(): void
    {
        $this->configureImpossibleBusinessDayProfile();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->maxScanMessage());

        Workday::profile('global')->nextBusinessDay('2026-06-26');
    }

    public function test_previous_business_day_throws_when_no_business_day_found_within_max_scan_days(): void
    {
        $this->configureImpossibleBusinessDayProfile();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->maxScanMessage());

        Workday::profile('global')->previousBusinessDay('2026-06-26');
    }

    public function test_add_business_days_throws_when_no_business_day_found_within_max_scan_days(): void
    {
        $this->configureImpossibleBusinessDayProfile();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->maxScanMessage());

        Workday::profile('global')->addBusinessDays('2026-06-26', 1);
    }

    public function test_calculate_throws_when_no_business_day_found_within_max_scan_days(): void
    {
        $this->configureImpossibleBusinessDayProfile();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->maxScanMessage());

        Workday::profile('global')->calculate('2026-06-26', 1);
    }

    public function test_invalid_custom_holiday_date_key_throws_exception(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-02-31' => 'Invalid holiday',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid custom_holidays date key [2026-02-31]');

        Workday::profile('global');
    }

    public function test_invalid_custom_holiday_date_format_throws_exception(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026/01/01' => 'Invalid holiday',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid custom_holidays date key [2026/01/01]');

        Workday::profile('global');
    }

    public function test_invalid_extra_working_day_date_key_throws_exception(): void
    {
        $this->setProfileConfig('global', [
            'extra_working_days' => [
                '2026-13-01' => 'Invalid working day',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid extra_working_days date key [2026-13-01]');

        Workday::profile('global');
    }

    public function test_profile_config_must_be_array(): void
    {
        config()->set('workdays.profiles.global', 'invalid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The workdays profile [global] config must be an array.');

        Workday::profile('global');
    }

    public function test_profiles_config_must_be_array(): void
    {
        config()->set('workdays.profiles', 'invalid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The workdays profiles config value must be an array.');

        Workday::profile('global');
    }

    public function test_weekends_config_must_be_array(): void
    {
        $this->setProfileConfig('global', [
            'weekends' => 'Friday',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The weekends config for profile [global] must be an array.');

        Workday::profile('global');
    }

    public function test_invalid_weekday_name_in_weekends_throws_exception(): void
    {
        $this->setProfileConfig('global', [
            'weekends' => ['Funday'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid weekend value [Funday] for profile [global].');

        Workday::profile('global');
    }

    public function test_invalid_weekend_iso_value_throws_exception(): void
    {
        $this->setProfileConfig('global', [
            'weekends' => [8],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid weekend value [8] for profile [global].');

        Workday::profile('global');
    }

    public function test_holidays_config_must_be_array(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => 'invalid',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The holidays config for profile [global] must be an array.');

        Workday::profile('global');
    }

    public function test_unknown_holiday_calendar_key_throws_exception(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => [
                'jallali' => [
                    '01-01' => 'Typo calendar',
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported holidays calendar [jallali] for profile [global]. Supported calendars are: gregorian, jalali, hijri.');

        Workday::profile('global');
    }

    public function test_invalid_gregorian_holiday_key_format_throws_exception(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => [
                'gregorian' => [
                    '2026-01-01' => 'Invalid recurring holiday',
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid gregorian recurring holiday key [2026-01-01]');

        Workday::profile('global');
    }

    public function test_invalid_jalali_holiday_key_format_throws_exception(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => [
                'jalali' => [
                    '1-01' => 'Invalid recurring holiday',
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid jalali recurring holiday key [1-01]');

        Workday::profile('global');
    }

    public function test_invalid_hijri_holiday_key_format_throws_exception(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => [
                'hijri' => [
                    '01/09' => 'Invalid recurring holiday',
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid hijri recurring holiday key [01/09]');

        Workday::profile('global');
    }

    public function test_gregorian_holidays_config_must_be_array(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => [
                'gregorian' => 'invalid',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The holidays.gregorian config for profile [global] must be an array.');

        Workday::profile('global');
    }

    public function test_jalali_holidays_config_must_be_array(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => [
                'jalali' => 'invalid',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The holidays.jalali config for profile [global] must be an array.');

        Workday::profile('global');
    }

    public function test_hijri_holidays_config_must_be_array(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => [
                'hijri' => 'invalid',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The holidays.hijri config for profile [global] must be an array.');

        Workday::profile('global');
    }

    public function test_license_file_exists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'LICENSE');
    }

    public function test_github_actions_workflow_exists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'workflows'.DIRECTORY_SEPARATOR.'tests.yml');
    }

    public function test_english_documentation_includes_database_storage_documentation(): void
    {
        $documentation = $this->englishDocumentation();

        $this->assertStringContainsString('Database Storage', $documentation);
        $this->assertStringContainsString('workday_holiday_rules', $documentation);
        $this->assertStringContainsString('workday_special_dates', $documentation);
    }

    public function test_english_documentation_includes_iran_preset_warning(): void
    {
        $documentation = $this->englishDocumentation();

        $this->assertStringContainsString('not an exact official calendar generator', $documentation);
        $this->assertStringContainsString('Hijri dates may differ', $documentation);
    }

    public function test_english_documentation_includes_max_scan_days_troubleshooting(): void
    {
        $documentation = $this->englishDocumentation();

        $this->assertStringContainsString('No business day found within max_scan_days', $documentation);
        $this->assertStringContainsString('max_scan_days', $documentation);
    }

    private function configureImpossibleBusinessDayProfile(): void
    {
        config()->set('workdays.max_scan_days', 3);
        $this->setProfileConfig('global', [
            'weekends' => [1, 2, 3, 4, 5, 6, 7],
            'holidays' => [
                'gregorian' => [],
                'jalali' => [],
                'hijri' => [],
            ],
            'custom_holidays' => [],
            'extra_working_days' => [],
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

    private function maxScanMessage(): string
    {
        return 'Unable to resolve a business day within [3] calendar days for profile [global]. Check weekends, holidays, extra working days, and max_scan_days config.';
    }

    private function englishDocumentation(): string
    {
        return file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'en'.DIRECTORY_SEPARATOR.'README.md') ?: '';
    }
}
