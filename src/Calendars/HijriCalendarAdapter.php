<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Calendars;

use Carbon\CarbonImmutable;
use DateTime;
use InvalidArgumentException;
use IslamicNetwork\Calendar\Models\Astronomical\Diyanet;
use IslamicNetwork\Calendar\Models\Astronomical\HighJudiciaryCouncilOfSaudiArabia;
use IslamicNetwork\Calendar\Models\Astronomical\UmmAlQura;
use IslamicNetwork\Calendar\Models\Mathematical\Calculator as MathematicalCalculator;
use IslamicNetwork\Calendar\Types\Hijri\Date as HijriDate;
use Throwable;

final readonly class HijriCalendarAdapter
{
    public const METHOD_MATHEMATICAL = 'mathematical';
    public const METHOD_UMM_AL_QURA = 'umm_al_qura';
    public const METHOD_HIGH_JUDICIARY = 'high_judiciary';
    public const METHOD_DIYANET = 'diyanet';

    /**
     * @var array<int, string>
     */
    private const SUPPORTED_METHODS = [
        self::METHOD_MATHEMATICAL,
        self::METHOD_UMM_AL_QURA,
        self::METHOD_HIGH_JUDICIARY,
        self::METHOD_DIYANET,
    ];

    public function __construct(
        private string $method = self::METHOD_UMM_AL_QURA,
        private int $adjustment = 0,
    ) {
        $this->validateConfig($method, $adjustment);
    }

    public static function fromConfig(): self
    {
        $method = config('workdays.hijri.method', self::METHOD_UMM_AL_QURA);
        $adjustment = config('workdays.hijri.adjustment', 0);

        if (! is_string($method)) {
            throw new InvalidArgumentException('The workdays hijri.method config value must be a string.');
        }

        if (! is_int($adjustment)) {
            throw new InvalidArgumentException('The workdays hijri.adjustment config value must be an integer.');
        }

        return new self($method, $adjustment);
    }

    public function monthDayFromGregorian(CarbonImmutable $date): string
    {
        $hijriDate = $this->convertGregorianToHijri($date);

        return sprintf('%02d-%02d', $hijriDate->month->number, $hijriDate->day->number);
    }

    public function hijriDateToGregorian(int $year, int $month, int $day): CarbonImmutable
    {
        $date = $this->convertHijriToGregorian(sprintf('%02d-%02d-%04d', $day, $month, $year));

        return CarbonImmutable::instance($date)->startOfDay();
    }

    private function convertGregorianToHijri(CarbonImmutable $date): HijriDate
    {
        $dateString = $date->format('d-m-Y');
        $calculator = $this->calculator();

        try {
            if ($calculator instanceof MathematicalCalculator) {
                return $calculator->gToH($dateString, $this->adjustment);
            }

            return $calculator->gToH($dateString);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(sprintf(
                'Unable to convert Gregorian date [%s] to Hijri using method [%s]: %s',
                $date->toDateString(),
                $this->method,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    private function convertHijriToGregorian(string $date): DateTime
    {
        $calculator = $this->calculator();

        try {
            if ($calculator instanceof MathematicalCalculator) {
                return $calculator->hToG($date, $this->adjustment);
            }

            return $calculator->hToG($date);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(sprintf(
                'Unable to convert Hijri date [%s] to Gregorian using method [%s]: %s',
                $date,
                $this->method,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    private function calculator(): MathematicalCalculator|UmmAlQura|HighJudiciaryCouncilOfSaudiArabia|Diyanet
    {
        return match ($this->method) {
            self::METHOD_MATHEMATICAL => new MathematicalCalculator(),
            self::METHOD_UMM_AL_QURA => new UmmAlQura(),
            self::METHOD_HIGH_JUDICIARY => new HighJudiciaryCouncilOfSaudiArabia(),
            self::METHOD_DIYANET => new Diyanet(),
        };
    }

    private function validateConfig(string $method, int $adjustment): void
    {
        if (! in_array($method, self::SUPPORTED_METHODS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid Hijri calculation method [%s]. Supported methods are: %s.',
                $method,
                implode(', ', self::SUPPORTED_METHODS),
            ));
        }

        if ($adjustment !== 0 && $method !== self::METHOD_MATHEMATICAL) {
            throw new InvalidArgumentException('Non-zero Hijri adjustment is only supported with the mathematical method.');
        }
    }
}
