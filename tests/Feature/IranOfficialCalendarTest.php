<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Calendars\IranOfficialCalendar;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class IranOfficialCalendarTest extends TestCase
{
    public function test_can_list_available_iran_official_calendar_years(): void
    {
        $this->assertContains(1405, (new IranOfficialCalendar)->availableYears());
    }

    public function test_can_load_iran_official_calendar_1405(): void
    {
        $dataset = (new IranOfficialCalendar)->forYear(1405);

        $this->assertSame('IR', $dataset['country']);
        $this->assertSame('jalali', $dataset['calendar']);
        $this->assertSame(1405, $dataset['year']);
        $this->assertCount(26, $dataset['holidays']);
    }

    public function test_dataset_1405_has_source_metadata(): void
    {
        $source = (new IranOfficialCalendar)->forYear(1405)['source'];

        $this->assertSame('University of Tehran Calendar Center', $source['name']);
        $this->assertStringContainsString('Calendar-1405.pdf', $source['url']);
        $this->assertSame('2026-06-28', $source['retrieved_at']);
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

        (new IranOfficialCalendar)->forYear(1404);
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

    /**
     * @return array<string, mixed>
     */
    private function validDataset(): array
    {
        return (new IranOfficialCalendar)->forYear(1405);
    }

    /**
     * @return array<string, mixed>
     */
    private function holidayByJalaliDate(string $date): array
    {
        foreach ((new IranOfficialCalendar)->holidaysForYear(1405) as $holiday) {
            if ($holiday['date'] === $date) {
                return $holiday;
            }
        }

        $this->fail(sprintf('Iran official calendar holiday [%s] was not found.', $date));
    }
}
