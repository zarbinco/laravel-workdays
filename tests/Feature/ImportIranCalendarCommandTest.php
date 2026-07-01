<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Zarbinco\LaravelWorkdays\Calendars\IranOfficialCalendar;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class ImportIranCalendarCommandTest extends TestCase
{
    private ?string $calendarPath = null;

    public function test_import_iran_calendar_requires_existing_database_tables_or_reports_clear_error(): void
    {
        $exitCode = Artisan::call('workdays:import-iran-calendar', ['year' => 1405]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Publish and run the workdays migrations before importing Iran official calendars.',
            Artisan::output(),
        );
    }

    public function test_import_iran_calendar_reports_missing_dataset_when_year_is_unavailable(): void
    {
        $this->loadWorkdayMigrations();
        $calendarPath = $this->createTemporaryIranOfficialCalendarFixture(1404);

        $exitCode = Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $calendarPath,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Iran official calendar dataset for year [1405] is not available.',
            Artisan::output(),
        );
    }

    public function test_import_iran_calendar_dry_run_does_not_write_records(): void
    {
        $this->loadWorkdayMigrations();

        $exitCode = Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->calendarPath(),
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, WorkdaySpecialDate::query()->count());
        $this->assertStringContainsString('Would create 2026-03-21', $output);
        $this->assertStringContainsString('1 would be created, 0 would be updated, 0 would be skipped', $output);
    }

    public function test_import_iran_calendar_uses_configured_calendar_path(): void
    {
        $this->loadWorkdayMigrations();
        config()->set('workdays.iran_official.calendar_path', $this->calendarPath());

        $exitCode = Artisan::call('workdays:import-iran-calendar', ['year' => 1405]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($this->holidayCount(), WorkdaySpecialDate::query()->count());
    }

    public function test_import_iran_calendar_calendar_path_option_overrides_configured_path(): void
    {
        $this->loadWorkdayMigrations();
        config()->set('workdays.iran_official.calendar_path', $this->createTemporaryIranOfficialCalendarFixture(1404));

        $exitCode = Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->calendarPath(),
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($this->holidayCount(), WorkdaySpecialDate::query()->count());
    }

    public function test_import_iran_calendar_creates_special_dates(): void
    {
        $this->loadWorkdayMigrations();

        $exitCode = Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->calendarPath(),
        ]);
        $specialDate = WorkdaySpecialDate::query()
            ->where('profile', 'iran')
            ->whereDate('date', '2026-03-21')
            ->where('type', 'holiday')
            ->first();

        $this->assertSame(0, $exitCode);
        $this->assertSame($this->holidayCount(), WorkdaySpecialDate::query()->count());
        $this->assertInstanceOf(WorkdaySpecialDate::class, $specialDate);
        $this->assertSame('عید سعید فطر و آغاز نوروز', $specialDate->title);
        $this->assertSame('iran_official_calendar', $specialDate->meta['source']);
        $this->assertSame('1405-01-01', $specialDate->meta['jalali_date']);
    }

    public function test_import_iran_calendar_is_idempotent(): void
    {
        $this->loadWorkdayMigrations();

        $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->calendarPath(),
        ]));
        $this->assertSame(0, Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->calendarPath(),
        ]));

        $this->assertSame($this->holidayCount(), WorkdaySpecialDate::query()->count());
        $this->assertStringContainsString('0 created, 0 updated, 1 skipped', Artisan::output());
    }

    public function test_import_iran_calendar_supports_profile_option(): void
    {
        $this->loadWorkdayMigrations();

        $exitCode = Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--profile' => 'iran-official-1405',
            '--calendar-path' => $this->calendarPath(),
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($this->holidayCount(), WorkdaySpecialDate::query()->where('profile', 'iran-official-1405')->count());
        $this->assertSame(0, WorkdaySpecialDate::query()->where('profile', 'iran')->count());
    }

    public function test_import_iran_calendar_does_not_overwrite_existing_title_without_force(): void
    {
        $this->loadWorkdayMigrations();
        $this->createSpecialDate('iran', '2026-03-21', 'User title');

        $exitCode = Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->calendarPath(),
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('User title', $this->specialDateTitle('iran', '2026-03-21'));
        $this->assertSame($this->holidayCount(), WorkdaySpecialDate::query()->count());
        $this->assertStringContainsString('existing record differs; use --force to update', Artisan::output());
    }

    public function test_import_iran_calendar_can_force_update_existing_title(): void
    {
        $this->loadWorkdayMigrations();
        $this->createSpecialDate('iran', '2026-03-21', 'User title');

        $exitCode = Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->calendarPath(),
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('عید سعید فطر و آغاز نوروز', $this->specialDateTitle('iran', '2026-03-21'));
        $this->assertSame($this->holidayCount(), WorkdaySpecialDate::query()->count());
    }

    public function test_explain_reports_imported_iran_official_holiday(): void
    {
        $this->loadWorkdayMigrations();
        Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->calendarPath(),
        ]);
        config()->set('workdays.storage.driver', 'database');

        $info = Workday::profile('iran')->explain('2026-03-21');
        $reason = $info->toArray()['reasons'][0];

        $this->assertFalse($info->isBusinessDay);
        $this->assertTrue($info->isCustomHoliday);
        $this->assertSame('custom_holiday', $reason['type']);
        $this->assertSame('عید سعید فطر و آغاز نوروز', $reason['title']);
        $this->assertSame('database', $reason['source']);
    }

    public function test_extra_working_day_still_overrides_imported_official_holiday(): void
    {
        $this->loadWorkdayMigrations();
        Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->calendarPath(),
        ]);
        $this->createSpecialDate('iran', '2026-03-21', 'Compensation working day', 'working_day');
        config()->set('workdays.storage.driver', 'database');

        $info = Workday::profile('iran')->explain('2026-03-21');
        $customHolidayReason = $this->reason($info->toArray()['reasons'], 'custom_holiday');
        $extraWorkingDayReason = $this->reason($info->toArray()['reasons'], 'extra_working_day');

        $this->assertTrue($info->isBusinessDay);
        $this->assertTrue($info->isCustomHoliday);
        $this->assertTrue($info->isExtraWorkingDay);
        $this->assertTrue($customHolidayReason['overridden']);
        $this->assertSame('extra_working_day', $customHolidayReason['overridden_by']);
        $this->assertSame('Compensation working day', $extraWorkingDayReason['title']);
    }

    private function loadWorkdayMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
    }

    private function createSpecialDate(string $profile, string $date, string $title, string $type = 'holiday'): WorkdaySpecialDate
    {
        return WorkdaySpecialDate::create([
            'profile' => $profile,
            'date' => $date,
            'type' => $type,
            'title' => $title,
            'is_active' => true,
        ]);
    }

    private function specialDateTitle(string $profile, string $date): ?string
    {
        return WorkdaySpecialDate::query()
            ->where('profile', $profile)
            ->whereDate('date', $date)
            ->where('type', 'holiday')
            ->value('title');
    }

    private function holidayCount(): int
    {
        return count((new IranOfficialCalendar($this->calendarPath()))->holidaysForYear(1405));
    }

    private function calendarPath(): string
    {
        return $this->calendarPath ??= $this->createTemporaryIranOfficialCalendarFixture();
    }

    /**
     * @param  array<int, array<string, mixed>>  $reasons
     * @return array<string, mixed>
     */
    private function reason(array $reasons, string $type): array
    {
        foreach ($reasons as $reason) {
            if (($reason['type'] ?? null) === $type) {
                return $reason;
            }
        }

        $this->fail(sprintf('Day reason [%s] was not found.', $type));
    }
}
