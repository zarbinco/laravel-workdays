<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Calendars\IranOfficialCalendar;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class IranOfficialCalendarTest extends TestCase
{
    public function test_can_list_available_iran_official_calendar_years(): void
    {
        $calendarPath = $this->createTemporaryIranOfficialCalendarFixture();

        $this->assertContains(1405, (new IranOfficialCalendar($calendarPath))->availableYears());
    }

    public function test_can_load_iran_official_calendar_1405(): void
    {
        $dataset = (new IranOfficialCalendar($this->createTemporaryIranOfficialCalendarFixture()))->forYear(1405);

        $this->assertSame('IR', $dataset['country']);
        $this->assertSame('jalali', $dataset['calendar']);
        $this->assertSame(1405, $dataset['year']);
        $this->assertCount(1, $dataset['holidays']);
    }

    public function test_dataset_1405_has_source_metadata(): void
    {
        $source = (new IranOfficialCalendar($this->createTemporaryIranOfficialCalendarFixture()))->forYear(1405)['source'];

        $this->assertSame('Laravel Workdays Test Fixture', $source['name']);
        $this->assertStringContainsString('1405.php', $source['url']);
        $this->assertSame('2026-03-21', $source['retrieved_at']);
    }

    public function test_dataset_1405_contains_verified_nowruz_or_eid_fitr_holiday(): void
    {
        $holiday = $this->holidayByJalaliDate('1405-01-01');

        $this->assertSame('2026-03-21', $holiday['gregorian_date']);
        $this->assertSame('عید سعید فطر و آغاز نوروز', $holiday['title']);
        $this->assertTrue($holiday['is_official_holiday']);
    }

    public function test_dataset_rejects_unknown_year(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Iran official calendar dataset for year [1404] is not available.');

        (new IranOfficialCalendar($this->createTemporaryIranOfficialCalendarFixture()))->forYear(1404);
    }

    public function test_dataset_requires_year(): void
    {
        $dataset = $this->validDataset();
        unset($dataset['year']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must include an integer year');

        (new IranOfficialCalendar)->validateDataset($dataset);
    }

    public function test_dataset_requires_holidays_array(): void
    {
        $dataset = $this->validDataset();
        $dataset['holidays'] = 'invalid';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must include a holidays array');

        (new IranOfficialCalendar)->validateDataset($dataset);
    }

    public function test_dataset_requires_iso_gregorian_dates(): void
    {
        $dataset = $this->validDataset();
        $dataset['holidays'][0]['gregorian_date'] = '2026/03/21';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a Gregorian date');

        (new IranOfficialCalendar)->validateDataset($dataset);
    }

    public function test_dataset_requires_jalali_dates(): void
    {
        $dataset = $this->validDataset();
        $dataset['holidays'][0]['date'] = '1405/01/01';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a Jalali date');

        (new IranOfficialCalendar)->validateDataset($dataset);
    }

    public function test_dataset_requires_official_holiday_title(): void
    {
        $dataset = $this->validDataset();
        $dataset['holidays'][0]['title'] = '';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a non-empty title');

        (new IranOfficialCalendar)->validateDataset($dataset);
    }

    #[DataProvider('invalidDateCases')]
    public function test_dataset_rejects_invalid_dates_and_correspondence(string $jalali, string $gregorian): void
    {
        $dataset = $this->validDataset();
        $dataset['holidays'][0]['date'] = $jalali;
        $dataset['holidays'][0]['gregorian_date'] = $gregorian;
        $this->expectException(InvalidArgumentException::class);
        (new IranOfficialCalendar)->validateDataset($dataset);
    }

    public static function invalidDateCases(): array
    {
        return [['1405-01-01', '2026-02-30'], ['1405-01-01', '2026-03-22'], ['1405-12-30', '2027-03-21'], ['1405-07-31', '2026-10-23'], ['1404-01-01', '2025-03-21']];
    }

    public function test_dataset_rejects_duplicate_dates_even_when_titles_differ(): void
    {
        $dataset = $this->validDataset();
        $dataset['holidays'][] = [...$dataset['holidays'][0], 'title' => 'Different duplicate'];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate holiday date');
        (new IranOfficialCalendar)->validateDataset($dataset);
    }

    public function test_dataset_metadata_is_bounded_data_with_an_actual_http_source(): void
    {
        $dataset = $this->validDataset();
        $dataset['source']['url'] = 'javascript:alert(1)';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute HTTP or HTTPS URL');
        (new IranOfficialCalendar)->validateDataset($dataset);
    }

    #[DataProvider('invalidDatasetFields')]
    public function test_dataset_rejects_malformed_metadata_and_holiday_flags(string $path, mixed $value, string $message): void
    {
        $dataset = $this->validDataset();
        data_set($dataset, $path, $value);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new IranOfficialCalendar)->validateDataset($dataset);
    }

    public static function invalidDatasetFields(): array
    {
        return [['source', null, 'must include source metadata'], ['country', 'US', 'country must be [IR]'],
            ['calendar', 'gregorian', 'calendar must be [jalali]'], ['source.name', '', 'non-empty [name]'],
            ['source.retrieved_at', '2026-02-30', 'Invalid date'], ['holidays.0.type', 'unofficial', 'type must be [official_holiday]'],
            ['holidays.0.is_official_holiday', false, 'must be marked as an official holiday']];
    }

    /**
     * @return array<string, mixed>
     */
    private function validDataset(): array
    {
        return (new IranOfficialCalendar($this->createTemporaryIranOfficialCalendarFixture()))->forYear(1405);
    }

    /**
     * @return array<string, mixed>
     */
    private function holidayByJalaliDate(string $date): array
    {
        foreach ((new IranOfficialCalendar($this->createTemporaryIranOfficialCalendarFixture()))->holidaysForYear(1405) as $holiday) {
            if ($holiday['date'] === $date) {
                return $holiday;
            }
        }

        $this->fail(sprintf('Iran official calendar holiday [%s] was not found.', $date));
    }
}
