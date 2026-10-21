<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Calendars;

use Hekmatinasser\Verta\Verta;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Configuration\WorkdayDefinitions;
use Zarbinco\LaravelWorkdays\Support\DateNormalizer;

final class IranOfficialCalendar
{
    public function __construct(
        private readonly ?string $calendarPath = null,
    ) {}

    /**
     * @return array<int, int>
     */
    public function availableYears(): array
    {
        $files = glob($this->calendarPath().DIRECTORY_SEPARATOR.'*.php') ?: [];
        $years = [];

        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);

            if (preg_match('/^\d{4}$/', $filename) === 1) {
                $years[] = (int) $filename;
            }
        }

        sort($years);

        return $years;
    }

    public function hasYear(int $year): bool
    {
        return is_file($this->datasetPath($year));
    }

    /**
     * @return array<string, mixed>
     */
    public function forYear(int $year): array
    {
        if (! $this->hasYear($year)) {
            throw new InvalidArgumentException(sprintf('Iran official calendar dataset for year [%d] is not available.', $year));
        }

        $dataset = require $this->datasetPath($year);

        if (! is_array($dataset)) {
            throw new InvalidArgumentException(sprintf('Iran official calendar dataset for year [%d] must return an array.', $year));
        }

        $dataset = $this->validateDataset($dataset);

        if ($dataset['year'] !== $year) {
            throw new InvalidArgumentException(sprintf('Iran official calendar dataset filename year [%d] does not match dataset year [%d].', $year, $dataset['year']));
        }

        return $dataset;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function holidaysForYear(int $year): array
    {
        /** @var array<int, array<string, mixed>> $holidays */
        $holidays = $this->forYear($year)['holidays'];

        return $holidays;
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    public function validateDataset(array $dataset): array
    {
        $dataset = WorkdayDefinitions::detach($dataset, 'Iran official calendar dataset');
        if (strlen(json_encode($dataset, JSON_THROW_ON_ERROR)) > 4194304) {
            throw new InvalidArgumentException('Iran official calendar dataset exceeds the 4 MiB limit.');
        }
        if (! isset($dataset['year']) || ! is_int($dataset['year']) || $dataset['year'] < 1 || $dataset['year'] > 3177) {
            throw new InvalidArgumentException('Iran official calendar dataset must include an integer year.');
        }

        if (($dataset['country'] ?? null) !== 'IR') {
            throw new InvalidArgumentException('Iran official calendar dataset country must be [IR].');
        }

        if (($dataset['calendar'] ?? null) !== 'jalali') {
            throw new InvalidArgumentException('Iran official calendar dataset calendar must be [jalali].');
        }

        if (! isset($dataset['source']) || ! is_array($dataset['source'])) {
            throw new InvalidArgumentException('Iran official calendar dataset must include source metadata.');
        }

        $this->validateSource($dataset['source']);

        if (! isset($dataset['holidays']) || ! is_array($dataset['holidays']) || ! array_is_list($dataset['holidays'])) {
            throw new InvalidArgumentException('Iran official calendar dataset must include a holidays array.');
        }

        $seen = [];
        foreach ($dataset['holidays'] as $index => $holiday) {
            if (! is_array($holiday)) {
                throw new InvalidArgumentException(sprintf('Iran official calendar holiday at index [%d] must be an array.', $index));
            }

            $this->validateHoliday($holiday, $index);
            if ((int) substr($holiday['date'], 0, 4) !== $dataset['year']) {
                throw new InvalidArgumentException('Iran official calendar holiday date must belong to dataset year ['.$dataset['year'].'].');
            }
            if (isset($seen[$holiday['gregorian_date']])) {
                throw new InvalidArgumentException('Iran official calendar dataset contains duplicate holiday date ['.$holiday['gregorian_date'].'].');
            }
            $seen[$holiday['gregorian_date']] = true;
        }

        return $dataset;
    }

    private function calendarPath(): string
    {
        return $this->calendarPath ?? dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'calendars'.DIRECTORY_SEPARATOR.'iran';
    }

    private function datasetPath(int $year): string
    {
        return $this->calendarPath().DIRECTORY_SEPARATOR.$year.'.php';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function validateSource(array $source): void
    {
        foreach (['name', 'url', 'retrieved_at'] as $key) {
            if (! isset($source[$key]) || ! is_string($source[$key]) || trim($source[$key]) === '' || strlen($source[$key]) > 4096) {
                throw new InvalidArgumentException(sprintf('Iran official calendar source metadata requires a non-empty [%s].', $key));
            }
        }

        DateNormalizer::toImmutable($source['retrieved_at']);
        if (filter_var($source['url'], FILTER_VALIDATE_URL) === false || ! in_array(parse_url($source['url'], PHP_URL_SCHEME), ['https', 'http'], true)) {
            throw new InvalidArgumentException('Iran official calendar source URL must be an absolute HTTP or HTTPS URL.');
        }
    }

    /**
     * @param  array<string, mixed>  $holiday
     */
    private function validateHoliday(array $holiday, int $index): void
    {
        if (! isset($holiday['date']) || ! is_string($holiday['date']) || ! $this->isJalaliDate($holiday['date'])) {
            throw new InvalidArgumentException(sprintf('Iran official calendar holiday at index [%d] requires a Jalali date in YYYY-MM-DD format.', $index));
        }

        if (
            ! isset($holiday['gregorian_date'])
            || ! is_string($holiday['gregorian_date'])
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday['gregorian_date']) !== 1
        ) {
            throw new InvalidArgumentException(sprintf('Iran official calendar holiday at index [%d] requires a Gregorian date in Y-m-d format.', $index));
        }

        DateNormalizer::toImmutable($holiday['gregorian_date']);

        [$year, $month, $day] = array_map('intval', explode('-', $holiday['date']));
        try {
            $gregorian = (new JalaliCalendarAdapter)->jalaliDateToGregorian($year, $month, $day);
            if (Verta::instance($gregorian)->format('Y-m-d') !== $holiday['date'] || $gregorian->toDateString() !== $holiday['gregorian_date']) {
                throw new InvalidArgumentException('Gregorian and Jalali dates must agree and both must be actual calendar dates.');
            }
        } catch (\Throwable $error) {
            throw new InvalidArgumentException('Iran official calendar holiday at index ['.$index.'] has invalid Gregorian/Jalali date correspondence: '.$error->getMessage(), previous: $error);
        }

        if (! isset($holiday['title']) || ! is_string($holiday['title']) || trim($holiday['title']) === '' || strlen($holiday['title']) > 4096) {
            throw new InvalidArgumentException(sprintf('Iran official calendar holiday at index [%d] requires a non-empty title.', $index));
        }

        if (($holiday['calendar'] ?? null) !== 'jalali') {
            throw new InvalidArgumentException(sprintf('Iran official calendar holiday at index [%d] calendar must be [jalali].', $index));
        }

        if (($holiday['type'] ?? null) !== 'official_holiday') {
            throw new InvalidArgumentException(sprintf('Iran official calendar holiday at index [%d] type must be [official_holiday].', $index));
        }

        if (($holiday['is_official_holiday'] ?? null) !== true) {
            throw new InvalidArgumentException(sprintf('Iran official calendar holiday at index [%d] must be marked as an official holiday.', $index));
        }
    }

    private function isJalaliDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) !== 1) {
            return false;
        }

        $month = (int) $matches[2];
        $day = (int) $matches[3];

        return $month >= 1
            && $month <= 12
            && $day >= 1
            && $day <= ($month <= 6 ? 31 : 30);
    }
}
