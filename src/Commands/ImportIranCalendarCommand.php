<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Calendars\IranOfficialCalendar;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionConflictException;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigResolver;

final class ImportIranCalendarCommand extends Command
{
    protected $signature = 'workdays:import-iran-calendar
        {year : Jalali dataset year}
        {--profile=iran : Existing target profile in the active database revision}
        {--calendar-path= : Trusted local PHP dataset directory; files named by Jalali year}
        {--dry-run : Preview a pinned Database revision, or validate only the dataset in Config mode}
        {--expected-revision= : Required active revision ID for a database write}
        {--force : Overwrite conflicting holiday values; never bypasses revision checks}';

    protected $description = 'Validate a trusted local Iran dataset and atomically publish exact holidays to the Database source.';

    public function handle(DatabaseDefinitionRepository $repository): int
    {
        try {
            $driver = config('workdays.driver');
            $dryRun = (bool) $this->option('dry-run');
            if (! in_array($driver, ['config', 'database'], true)) {
                throw new InvalidArgumentException('Iran import requires workdays.driver=config or database.');
            }
            if ($driver === 'config' && ! $dryRun) {
                throw new InvalidArgumentException('Iran calendar writes require workdays.driver=database. Config mode supports dataset-only --dry-run.');
            }
            $year = (string) $this->argument('year');
            if (preg_match('/\A[0-9]{1,4}\z/', $year) !== 1) {
                throw new InvalidArgumentException('Invalid Iran official calendar year ['.$year.']. Expected a Jalali year such as 1405.');
            }
            $profile = (string) $this->option('profile');
            if (trim($profile) === '') {
                throw new InvalidArgumentException('The --profile option must be a non-empty string.');
            }
            $dataset = (new IranOfficialCalendar($this->calendarPath()))->forYear((int) $year);
            if ($driver === 'config') {
                $this->info(sprintf('Iran official calendar %d dataset validation for intended profile [%s]: %d holiday entries validated.', $dataset['year'], $profile, count($dataset['holidays'])));
                $this->line('Dataset-only validation. No database records or import conflicts were inspected; no records were written.');

                return self::SUCCESS;
            }
            $expected = $this->expectedRevision($dryRun);
            $current = $repository->current();
            if ($current === null) {
                throw new InvalidArgumentException('Initialize complete database definitions before importing an Iran dataset.');
            }
            if ($expected !== null && $expected !== $current->revisionId) {
                throw new DefinitionConflictException('Definition revision conflict: expected '.$expected.', current '.$current->revisionId.'.');
            }
            $definitions = $current->definitions;
            if (! array_key_exists($profile, $definitions['profiles'])) {
                throw new InvalidArgumentException('Database target profile ['.$profile.'] is not configured.');
            }
            $effective = $this->effectiveProfile($definitions, $profile);
            $expectedCustomHolidays = $effective['custom_holidays'] ?? [];
            $counts = ['inserts' => 0, 'updates' => 0, 'skips' => 0, 'conflicts' => 0];
            foreach ($dataset['holidays'] as $holiday) {
                $date = $holiday['gregorian_date'];
                [$present, $title] = $this->existingHoliday($effective, $definitions, $date);
                if ($present && $title === $holiday['title']) {
                    $counts['skips']++;

                    continue;
                }
                if ($present && ! (bool) $this->option('force')) {
                    $counts['conflicts']++;
                    $this->warn('Holiday conflict ['.$date.']: existing value differs; use --force to overwrite.');

                    continue;
                }
                $counts[$present ? 'updates' : 'inserts']++;
                $definitions['profiles'][$profile]['custom_holidays'][$date] = $holiday['title'];
                $expectedCustomHolidays[$date] = $holiday['title'];
            }
            $this->line(sprintf('Revision %d, profile [%s]: %d inserts, %d updates, %d skips, %d conflicts.', $current->revisionId, $profile, ...array_values($counts)));
            if ($counts['conflicts'] > 0) {
                $this->error('No publication was made because holiday conflicts remain.');

                return self::FAILURE;
            }
            $candidateCustomHolidays = $this->effectiveProfile($definitions, $profile)['custom_holidays'] ?? [];
            $unexpectedDates = [];
            foreach ($candidateCustomHolidays + $expectedCustomHolidays as $date => $_) {
                if (! array_key_exists($date, $expectedCustomHolidays) || ! array_key_exists($date, $candidateCustomHolidays)
                    || $expectedCustomHolidays[$date] !== $candidateCustomHolidays[$date]) {
                    $unexpectedDates[] = $date;
                    if (count($unexpectedDates) === 5) {
                        break;
                    }
                }
            }
            if ($unexpectedDates !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Iran import would change unintended effective custom holidays for profile [%s] at [%s] (at most five dates shown). Resolve the profile\'s inheritance policy deliberately or choose a suitable target before retrying. No publication was made.',
                    $profile,
                    implode(', ', $unexpectedDates),
                ));
            }
            $repository->validate($definitions);
            $metadata = DatabaseDefinitionRepository::metadata([
                'reason' => 'Iran official calendar import',
                'import' => ['year' => $dataset['year'], 'profile' => $profile, 'source' => $dataset['source']],
            ]);
            if ($dryRun) {
                $this->info('Dry-run completed against revision '.$current->revisionId.'. No rows were written.');

                return self::SUCCESS;
            }
            $publication = $repository->publish($definitions, expectedRevisionId: $expected, metadata: $metadata);
            $this->info(sprintf('Iran calendar %s at revision %d.', $publication->changed ? 'published' : 'unchanged (no-op)', $publication->revisionId));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function effectiveProfile(array $definitions, string $profile): array
    {
        return (new ProfileConfigResolver(static function (string $name) use ($definitions): array {
            if (! array_key_exists($name, $definitions['profiles'])) {
                throw new InvalidArgumentException('Unknown parent profile ['.$name.'].');
            }

            return $definitions['profiles'][$name];
        }))->resolve($profile);
    }

    private function expectedRevision(bool $dryRun): ?int
    {
        $expected = $this->option('expected-revision');
        if ($dryRun && $expected === null) {
            return null;
        }
        if (! is_string($expected) || preg_match('/\A[1-9][0-9]*\z/', $expected) !== 1 || filter_var($expected, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('A positive --expected-revision=<id> is required for a database-writing Iran import.');
        }

        return (int) $expected;
    }

    /** @return array{bool, mixed} */
    private function existingHoliday(array $effective, array $definitions, string $date): array
    {
        if (array_key_exists($date, $effective['custom_holidays'] ?? [])) {
            return [true, $effective['custom_holidays'][$date]];
        }
        $day = CarbonImmutable::parse($date, 'UTC');
        $keys = ['gregorian' => $day->format('m-d'), 'jalali' => (new JalaliCalendarAdapter)->monthDayFromGregorian($day),
            'hijri' => (new HijriCalendarAdapter($definitions['hijri']['method'], $definitions['hijri']['adjustment']))->monthDayFromGregorian($day)];
        foreach ($keys as $calendar => $key) {
            if (array_key_exists($key, $effective['holidays'][$calendar] ?? [])) {
                return [true, $effective['holidays'][$calendar][$key]];
            }
        }

        return [false, null];
    }

    private function calendarPath(): ?string
    {
        $optionPath = $this->option('calendar-path');
        if (is_string($optionPath) && trim($optionPath) !== '') {
            return trim($optionPath);
        }
        $configPath = config('workdays.iran_official.calendar_path');

        return is_string($configPath) && trim($configPath) !== '' ? trim($configPath) : null;
    }
}
