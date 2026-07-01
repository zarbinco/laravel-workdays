<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Zarbinco\LaravelWorkdays\Calendars\IranOfficialCalendar;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;

final class ImportIranCalendarCommand extends Command
{
    private const TYPE_HOLIDAY = 'holiday';

    private const MISSING_TABLES_MESSAGE = 'Workdays database storage tables are not available. Publish and run the workdays migrations before importing Iran official calendars.';

    protected $signature = 'workdays:import-iran-calendar
        {year : Jalali year to import}
        {--profile=iran : Target workdays profile}
        {--calendar-path= : Directory containing Iran official calendar dataset PHP files named by Jalali year, for example 1405.php}
        {--dry-run : Show import actions without writing records}
        {--force : Overwrite existing special-date titles}';

    protected $description = 'Import an official Iran yearly calendar dataset into workday special dates.';

    public function handle(): int
    {
        $year = $this->year();

        if ($year === null) {
            return SymfonyCommand::FAILURE;
        }

        $profile = trim((string) $this->option('profile'));

        if ($profile === '') {
            $this->error('The --profile option must be a non-empty string.');

            return SymfonyCommand::FAILURE;
        }

        if (! $this->specialDatesTableExists()) {
            return SymfonyCommand::FAILURE;
        }

        $calendar = new IranOfficialCalendar($this->calendarPath());

        try {
            $dataset = $calendar->forYear($year);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return SymfonyCommand::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($dataset['holidays'] as $holiday) {
            try {
                $result = $this->importHoliday($profile, $dataset, $holiday, $dryRun, $force);
            } catch (QueryException) {
                $this->error(self::MISSING_TABLES_MESSAGE);

                return SymfonyCommand::FAILURE;
            }

            match ($result) {
                'created' => $created++,
                'updated' => $updated++,
                default => $skipped++,
            };
        }

        if ($dryRun) {
            $this->info(sprintf(
                'Iran official calendar %d dry-run summary for profile [%s]: %d would be created, %d would be updated, %d would be skipped.',
                $year,
                $profile,
                $created,
                $updated,
                $skipped,
            ));

            return SymfonyCommand::SUCCESS;
        }

        $this->info(sprintf(
            'Iran official calendar %d import summary for profile [%s]: %d created, %d updated, %d skipped.',
            $year,
            $profile,
            $created,
            $updated,
            $skipped,
        ));

        return SymfonyCommand::SUCCESS;
    }

    private function year(): ?int
    {
        $year = (string) $this->argument('year');

        if (preg_match('/^\d+$/', $year) !== 1) {
            $this->error(sprintf('Invalid Iran official calendar year [%s]. Expected a Jalali year such as 1405.', $year));

            return null;
        }

        return (int) $year;
    }

    private function specialDatesTableExists(): bool
    {
        try {
            if (Schema::hasTable('workday_special_dates')) {
                return true;
            }
        } catch (QueryException) {
            // Fall through to the common user-facing error below.
        }

        $this->error(self::MISSING_TABLES_MESSAGE);

        return false;
    }

    private function calendarPath(): ?string
    {
        $optionPath = $this->option('calendar-path');

        if (is_string($optionPath) && trim($optionPath) !== '') {
            return trim($optionPath);
        }

        $configPath = config('workdays.iran_official.calendar_path');

        if (is_string($configPath) && trim($configPath) !== '') {
            return trim($configPath);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @param  array<string, mixed>  $holiday
     */
    private function importHoliday(string $profile, array $dataset, array $holiday, bool $dryRun, bool $force): string
    {
        $date = (string) $holiday['gregorian_date'];
        $title = (string) $holiday['title'];
        $existing = WorkdaySpecialDate::query()
            ->where('profile', $profile)
            ->whereDate('date', $date)
            ->where('type', self::TYPE_HOLIDAY)
            ->first();

        if (! $existing instanceof WorkdaySpecialDate) {
            $this->line(sprintf('%s create %s (%s)', $dryRun ? 'Would' : 'Will', $date, $title));

            if (! $dryRun) {
                WorkdaySpecialDate::create([
                    'profile' => $profile,
                    'date' => $date,
                    'type' => self::TYPE_HOLIDAY,
                    'title' => $title,
                    'is_active' => true,
                    'meta' => $this->meta($dataset, $holiday),
                ]);
            }

            return 'created';
        }

        if ($existing->title === $title && (bool) $existing->is_active) {
            $this->line(sprintf('Skipping %s (%s already exists)', $date, $title));

            return 'skipped';
        }

        if (! $force) {
            $this->line(sprintf('Skipping %s (existing record differs; use --force to update)', $date));

            return 'skipped';
        }

        $this->line(sprintf('%s update %s (%s)', $dryRun ? 'Would' : 'Will', $date, $title));

        if (! $dryRun) {
            $existing->update([
                'title' => $title,
                'is_active' => true,
                'meta' => $this->meta($dataset, $holiday),
            ]);
        }

        return 'updated';
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @param  array<string, mixed>  $holiday
     * @return array<string, mixed>
     */
    private function meta(array $dataset, array $holiday): array
    {
        /** @var array<string, mixed> $source */
        $source = $dataset['source'];

        return [
            'source' => 'iran_official_calendar',
            'source_name' => $source['name'],
            'source_url' => $source['url'],
            'source_retrieved_at' => $source['retrieved_at'],
            'country' => $dataset['country'],
            'calendar' => $dataset['calendar'],
            'jalali_year' => $dataset['year'],
            'jalali_date' => $holiday['date'],
            'holiday_type' => $holiday['type'],
        ];
    }
}
