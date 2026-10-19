<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Calendar;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\CutoffResult;
use Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Data\DeliveryStageResult;
use Zarbinco\LaravelWorkdays\Delivery\DeliveryPlanner;
use Zarbinco\LaravelWorkdays\Support\CutoffDateTimeNormalizer;
use Zarbinco\LaravelWorkdays\Support\CutoffPolicy;
use Zarbinco\LaravelWorkdays\Support\HolidayKeyValidator;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;
use Zarbinco\LaravelWorkdays\Support\WorkingHours;
use Zarbinco\LaravelWorkdays\Support\WorkingTimeBoundary;

/** Validates portable data without looking up current profile configuration. */
final class DeliveryPromiseCodec
{
    /** @param array<string, mixed> $payload */
    public static function restore(array $payload): DeliveryPromiseSnapshot
    {
        CalendarFingerprint::assertJson($payload);
        $payload = json_decode(CalendarFingerprint::canonical($payload), true, 34, JSON_THROW_ON_ERROR);
        self::keys($payload, ['schema_version', 'calendar_version', 'received_at', 'plan', 'horizon_days', 'original_result', 'calendar_evidence', 'runtime_metadata', 'integrity_hash'], 'snapshot');
        self::require($payload['schema_version'] === 1, 'Unsupported snapshot schema_version.');
        self::hash($payload['calendar_version'], 'calendar_version');
        self::hash($payload['integrity_hash'], 'integrity_hash');
        $unsigned = $payload;
        unset($unsigned['integrity_hash']);
        self::require(hash_equals($payload['integrity_hash'], CalendarFingerprint::integrity($unsigned)), 'Snapshot integrity_hash does not match its payload.');
        self::require(is_int($payload['horizon_days']) && $payload['horizon_days'] >= 1 && $payload['horizon_days'] <= 31, 'Snapshot horizon_days must be an integer from 1 to 31.');
        $received = self::timestamp($payload['received_at'], 'received_at');
        self::require(is_array($payload['plan']), 'Snapshot plan must be an array.');
        $plan = $payload['plan'];
        self::require(is_array($plan['profile_timezones'] ?? null), 'Snapshot plan requires profile_timezones.');
        $known = array_map('strval', array_keys($plan['profile_timezones']));
        $planner = new DeliveryPlanner(static function (string $profile, int $limit): BusinessDayCalculator {
            throw new InvalidArgumentException('Snapshot structural validation must not construct calculators.');
        });
        $planner->validatePlan($plan, $known);
        $referenced = [];
        $needsHours = [];

        foreach ($plan['stages'] as $stage) {
            $profiles = $stage['type'] === 'handoff' ? $stage['profiles'] : [$stage['profile']];

            foreach ($profiles as $profile) {
                $referenced[$profile] = true;
                $needsHours[$profile] = ($needsHours[$profile] ?? false) || $stage['type'] !== 'business_days';
            }
        }

        self::require(count($referenced) <= 8, 'Snapshot may reference at most eight profiles.');
        self::require(is_array($payload['calendar_evidence']), 'Snapshot calendar_evidence must be an array.');
        self::runtime($payload['runtime_metadata']);
        $tzdbMatches = is_string($payload['runtime_metadata']['timezone_database_version'])
            && function_exists('timezone_version_get') && $payload['runtime_metadata']['timezone_database_version'] === timezone_version_get();
        $anchor = self::date($payload['calendar_evidence']['anchor_date'] ?? null, 'calendar_evidence.anchor_date');
        $receivedDate = self::date($received->setTimezone($plan['timezone'])->toDateString(), 'received plan-local date');
        self::require($tzdbMatches ? $anchor->equalTo($receivedDate) : abs((int) $anchor->diffInDays($receivedDate, false)) <= 2, 'Snapshot anchor_date differs from its original received date.');
        self::evidence($payload['calendar_evidence'], $plan, $payload['horizon_days'], $referenced, $needsHours, $tzdbMatches);
        self::require(hash_equals($payload['calendar_version'], CalendarFingerprint::hash($payload['calendar_evidence'])), 'Snapshot calendar_version does not match its evidence.');
        $result = self::result($payload['original_result'], $plan, $received, $payload['horizon_days'], $tzdbMatches);
        $snapshot = new DeliveryPromiseSnapshot(1, $payload['calendar_version'], $received, $plan, $payload['horizon_days'], $result, $payload['calendar_evidence'], $payload['runtime_metadata']);
        self::require(CalendarFingerprint::canonical($snapshot->toArray()) === CalendarFingerprint::canonical($payload), 'Snapshot contains noncanonical or inconsistent serialized result fields.');
        DeliveryPromiseAuditor::validateFrozen($snapshot);

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $plan
     * @param  array<array-key, bool>  $referenced
     * @param  array<array-key, bool>  $needsHours
     */
    private static function evidence(array $evidence, array $plan, int $horizon, array $referenced, array $needsHours, bool $tzdbMatches): void
    {
        self::keys($evidence, ['schema_version', 'plan_timezone', 'anchor_date', 'horizon_days', 'hijri', 'profiles'], 'calendar_evidence');
        self::require($evidence['schema_version'] === 1 && $evidence['plan_timezone'] === $plan['timezone'] && $evidence['horizon_days'] === $horizon, 'Snapshot evidence schema, timezone or horizon is inconsistent.');
        self::date($evidence['anchor_date'], 'calendar_evidence.anchor_date');
        self::require(is_array($evidence['hijri']), 'Snapshot Hijri configuration must be an array.');
        self::keys($evidence['hijri'], ['method', 'adjustment'], 'calendar_evidence.hijri');
        self::require(is_string($evidence['hijri']['method']) && is_int($evidence['hijri']['adjustment']), 'Snapshot Hijri configuration has invalid types.');
        new HijriCalendarAdapter($evidence['hijri']['method'], $evidence['hijri']['adjustment']);
        self::require(is_array($evidence['profiles']) && count($evidence['profiles']) === count($referenced), 'Snapshot evidence must contain exactly the referenced profiles.');

        foreach ($evidence['profiles'] as $key => $profile) {
            $name = (string) $key;
            self::require(trim($name) !== '' && array_key_exists($name, $referenced) && is_array($profile), 'Snapshot evidence contains an invalid or unreferenced profile.');
            self::keys($profile, ['timezone', 'needs_working_hours', 'config', 'coverage', 'holiday_inputs', 'days'], 'profile evidence '.$name);
            self::require($profile['timezone'] === $plan['profile_timezones'][$name] && $profile['needs_working_hours'] === $needsHours[$name], 'Snapshot profile timezone or working-hours requirement is inconsistent.');
            self::timezone($profile['timezone'], 'profile '.$name.' timezone');
            self::require(is_array($profile['config']), 'Snapshot profile config must be an array.');
            $allowed = ['weekends', 'working_hours', 'extra_working_day_hours', 'allow_overnight_working_hours', 'special_working_hours', 'seasonal_working_hours', 'cutoff'];
            self::allowedKeys($profile['config'], $allowed, 'profile '.$name.' config');
            ProfileConfigValidator::validate($name, $profile['config']);
            self::require(! $needsHours[$name] || array_key_exists('working_hours', $profile['config']), 'Snapshot time-based profile lacks working_hours.');
            if (! $needsHours[$name]) {
                self::allowedKeys($profile['config'], ['weekends'], 'business-date-only profile config');
            }

            if (($plan['cutoff_profile'] ?? null) === $name) {
                self::require(is_array($profile['config']['cutoff'] ?? null), 'Snapshot cutoff policy must be an array.');
                self::keys($profile['config']['cutoff'], ['timezone', 'time', 'weekdays'], 'cutoff policy');
                $policy = CutoffPolicy::fromProfileConfig($name, $profile['config']);
                self::require($policy->timezone === $profile['timezone'], 'Snapshot cutoff timezone must match its profile timezone.');
            } else {
                self::require(! array_key_exists('cutoff', $profile['config']), 'Snapshot contains an unused cutoff policy.');
            }

            self::require(is_array($profile['coverage']), 'Snapshot profile coverage must be an array.');
            self::keys($profile['coverage'], ['from', 'through', 'provider_from'], 'profile '.$name.' coverage');
            $from = self::date($profile['coverage']['from'], 'coverage.from');
            $through = self::date($profile['coverage']['through'], 'coverage.through');
            $providerFrom = self::date($profile['coverage']['provider_from'], 'coverage.provider_from');
            $count = (int) $from->diffInDays($through, false) + 1;
            self::require($count >= 1 && $count <= 40 && $providerFrom->toDateString() === $from->subDay()->toDateString(), 'Snapshot coverage must be ordered, bounded to forty dates and include one provider-owner date.');
            $anchor = self::date($evidence['anchor_date'], 'anchor_date');
            self::require(abs((int) $anchor->diffInDays($from, false)) <= 5 && abs((int) $anchor->addDays($horizon + 1)->diffInDays($through, false)) <= 5, 'Snapshot profile coverage lies outside its bounded global horizon.');
            foreach ($profile['config']['special_working_hours'] ?? [] as $date => $_) {
                self::require($date >= $providerFrom->toDateString() && $date <= $through->toDateString(), 'Snapshot special working hours exceed captured coverage.');
            }
            foreach ($profile['config']['seasonal_working_hours'] ?? [] as $season) {
                self::require($season['from'] >= $providerFrom->toDateString() && $season['through'] <= $through->toDateString(), 'Snapshot seasonal working hours exceed captured coverage.');
            }
            self::holidayInputs($profile['holiday_inputs'], $name, $providerFrom->toDateString(), $through->toDateString());
            self::require(is_array($profile['days']) && array_is_list($profile['days']) && count($profile['days']) === $count, 'Snapshot profile days must cover every date exactly once.');

            foreach ($profile['days'] as $index => $row) {
                self::day($row, $name, $from->addDays($index)->toDateString(), $needsHours[$name], $profile, $tzdbMatches);
            }
        }
    }

    private static function holidayInputs(mixed $inputs, string $profile, string $from, string $through): void
    {
        self::require(is_array($inputs), 'Snapshot holiday_inputs must be an array.');
        self::keys($inputs, ['recurring', 'custom', 'extra'], 'holiday_inputs');
        self::require(is_array($inputs['recurring']), 'Snapshot recurring holidays must be an array.');
        self::keys($inputs['recurring'], ['gregorian', 'jalali', 'hijri'], 'recurring holidays');

        foreach ($inputs['recurring'] as $calendar => $rules) {
            self::require(is_array($rules), 'Snapshot recurring holiday calendar must be a map.');

            foreach ($rules as $key => $metadata) {
                self::require(is_string($key), 'Snapshot recurring holiday keys must be MM-DD strings.');
                HolidayKeyValidator::validate($calendar, $key, $profile);
                self::holidayMetadata($metadata);
            }
        }

        foreach (['custom', 'extra'] as $section) {
            self::require(is_array($inputs[$section]), 'Snapshot dated holiday inputs must be maps.');

            foreach ($inputs[$section] as $date => $metadata) {
                self::date($date, 'holiday date');
                self::require($date >= $from && $date <= $through, 'Snapshot dated holiday input exceeds provider coverage.');
                self::holidayMetadata($metadata);
            }
        }
    }

    private static function holidayMetadata(mixed $metadata): void
    {
        self::require(is_array($metadata), 'Snapshot holiday metadata must be an array.');
        self::keys($metadata, ['title', 'source'], 'holiday metadata');
        self::require(($metadata['title'] === null || is_string($metadata['title'])) && in_array($metadata['source'], ['config', 'database'], true), 'Snapshot holiday title or source is invalid.');
    }

    private static function day(mixed $row, string $profile, string $date, bool $needsHours, array $definition, bool $tzdbMatches): void
    {
        self::require(is_array($row), 'Snapshot calendar day must be an array.');
        self::keys($row, ['profile', 'date', 'exists', 'is_business_day', 'is_holiday', 'is_extra_working_day', 'effective_working_windows', 'holiday_reasons'], 'calendar day');
        self::require($row['profile'] === $profile && $row['date'] === $date, 'Snapshot calendar days must have matching profiles and contiguous Gregorian dates.');

        foreach (['exists', 'is_business_day', 'is_holiday', 'is_extra_working_day'] as $field) {
            self::require(is_bool($row[$field]), 'Snapshot calendar-day flags must be booleans.');
        }

        self::require($row['is_holiday'] !== $row['is_business_day'] && (! $row['is_extra_working_day'] || $row['is_business_day']), 'Snapshot calendar-day flags contradict each other.');
        self::require(is_array($row['holiday_reasons']) && array_is_list($row['holiday_reasons']) && count($row['holiday_reasons']) <= 8, 'Snapshot holiday reasons must be a bounded ordered list.');

        $reasonTypes = ['weekend', 'gregorian_holiday', 'jalali_holiday', 'hijri_holiday', 'custom_holiday', 'extra_working_day'];
        $seenTypes = [];
        foreach ($row['holiday_reasons'] as $reason) {
            self::require(is_array($reason), 'Snapshot holiday reason must be an array.');
            self::keys($reason, ['type', 'title', 'source', 'calendar', 'key', 'overridden', 'overridden_by'], 'holiday reason');
            self::require(in_array($reason['type'], $reasonTypes, true) && ! isset($seenTypes[$reason['type']]) && is_bool($reason['overridden']), 'Snapshot holiday reason type/override flag is invalid.');
            $seenTypes[$reason['type']] = true;

            foreach (['title', 'source', 'calendar', 'key', 'overridden_by'] as $field) {
                self::require($reason[$field] === null || is_string($reason[$field]), 'Snapshot holiday reason metadata must be strings or null.');
            }
            $extra = $reason['type'] === 'extra_working_day';
            self::require($reason['overridden'] === (! $extra && $row['is_extra_working_day'])
                && $reason['overridden_by'] === ($reason['overridden'] ? 'extra_working_day' : null), 'Snapshot holiday reason override metadata is inconsistent.');
            if ($reason['type'] === 'weekend') {
                self::require($reason['source'] === 'profile' && $reason['title'] === 'Weekend' && $reason['calendar'] === null && $reason['key'] === null, 'Snapshot weekend reason metadata is invalid.');
            } elseif (in_array($reason['type'], ['custom_holiday', 'extra_working_day'], true)) {
                $metadata = $definition['holiday_inputs'][$extra ? 'extra' : 'custom'][$date] ?? null;
                self::require($reason['calendar'] === null && $reason['key'] === $date && $metadata !== null && $reason['source'] === $metadata['source'] && $reason['title'] === $metadata['title'], 'Snapshot dated holiday reason disagrees with captured inputs.');
            } else {
                $calendar = substr($reason['type'], 0, -8);
                self::require($reason['calendar'] === $calendar && $reason['source'] === 'holiday_provider' && is_string($reason['key']), 'Snapshot recurring holiday reason metadata is invalid.');
                HolidayKeyValidator::validate($calendar, $reason['key'], $profile);
                $metadata = $definition['holiday_inputs']['recurring'][$calendar][$reason['key']] ?? null;
                self::require($metadata !== null && $reason['title'] === $metadata['title'] && ($calendar !== 'gregorian' || $reason['key'] === substr($date, 5)), 'Snapshot recurring holiday reason disagrees with captured inputs.');
            }
        }
        if ($row['exists']) {
            self::require($row['is_extra_working_day'] === isset($seenTypes['extra_working_day']) && $row['is_holiday'] === (! $row['is_extra_working_day'] && $seenTypes !== []), 'Snapshot calendar flags disagree with holiday reasons.');
        }

        $windows = $row['effective_working_windows'];
        self::require($needsHours ? is_array($windows) && array_is_list($windows) && count($windows) <= 1440 : $windows === null, 'Snapshot effective windows do not match stage requirements.');
        $previousEnd = null;

        foreach ($windows ?? [] as $window) {
            self::require(is_array($window), 'Snapshot effective window must be an array.');
            self::keys($window, ['start', 'end'], 'effective window');
            $start = self::timestamp($window['start'], 'window.start');
            $end = self::timestamp($window['end'], 'window.end');
            self::require($end->greaterThan($start) && $end->getTimestamp() - $start->getTimestamp() <= 172800 && ($previousEnd === null || $start->greaterThanOrEqualTo($previousEnd)), 'Snapshot effective windows must be positive, ordered and non-overlapping.');
            $rowDate = self::date($date, 'calendar day');
            self::require(abs((int) $rowDate->diffInDays($start->startOfDay(), false)) <= 2 && abs((int) $rowDate->diffInDays($end->startOfDay(), false)) <= 2, 'Snapshot window lies outside its civil-date neighborhood.');
            if ($tzdbMatches) {
                $zone = new DateTimeZone($definition['timezone']);
                $nextLabel = WorkingTimeBoundary::labelAfter($date, 1);
                $dayStart = WorkingTimeBoundary::date($date, $zone);
                self::require($dayStart !== null, 'Snapshot skipped local date cannot contain a working window.');
                $lastBoundary = WorkingHours::hasEnhancedConfig($definition['config'])
                    ? CarbonImmutable::instance(DateTimeImmutable::createFromFormat('!Y-m-d', $nextLabel, $zone))
                    : $dayStart->setTimezone('UTC')->addSeconds(86400);
                self::require($start->greaterThanOrEqualTo($dayStart) && $start->lessThan($lastBoundary) && $end->lessThanOrEqualTo($lastBoundary), 'Snapshot window exceeds its effective profile-date interval.');
            }
            $previousEnd = $end;
        }

        if (! $row['exists']) {
            self::require(! $row['is_business_day'] && $row['is_holiday'] && ! $row['is_extra_working_day'] && ($windows === null || $windows === []) && $row['holiday_reasons'] === [], 'Snapshot skipped civil date must not contain fabricated calendar evidence.');
        }
    }

    private static function runtime(mixed $runtime): void
    {
        self::require(is_array($runtime), 'Snapshot runtime_metadata must be an array.');
        self::keys($runtime, ['calculation_schema_version', 'php_version', 'carbon_version', 'verta_version', 'islamic_calendar_version', 'timezone_database_version'], 'runtime_metadata');
        self::require($runtime['calculation_schema_version'] === 1 && is_string($runtime['php_version']) && $runtime['php_version'] !== '', 'Snapshot runtime schema or PHP version is invalid.');

        foreach (['carbon_version', 'verta_version', 'islamic_calendar_version', 'timezone_database_version'] as $field) {
            self::require($runtime[$field] === null || (is_string($runtime[$field]) && $runtime[$field] !== ''), 'Snapshot runtime versions must be non-empty strings or null.');
        }
    }

    /** @param array<string, mixed> $plan */
    private static function result(mixed $value, array $plan, CarbonImmutable $received, int $horizon, bool $tzdbMatches): DeliveryPlanResult
    {
        self::require(is_array($value), 'Snapshot original_result must be an array.');
        self::keys($value, ['timezone', 'received_at', 'planning_start_at', 'cutoff_result', 'stages', 'estimated_completion_at'], 'original_result');
        self::require($value['timezone'] === $plan['timezone'], 'Snapshot original-result timezone differs from its plan.');
        $resultReceived = self::timestamp($value['received_at'], 'original_result.received_at');
        $planningStart = self::timestamp($value['planning_start_at'], 'original_result.planning_start_at');
        $completion = self::timestamp($value['estimated_completion_at'], 'original_result.estimated_completion_at');
        self::require($resultReceived->equalTo($received) && $planningStart->greaterThanOrEqualTo($received) && $completion->greaterThanOrEqualTo($planningStart), 'Snapshot original-result timestamps are inconsistent.');
        self::require($completion->getTimestamp() - $received->getTimestamp() <= ($horizon + 3) * 86400, 'Snapshot original estimate exceeds its bounded horizon.');
        $lastDate = WorkingTimeBoundary::labelAfter($received->setTimezone($plan['timezone'])->toDateString(), $horizon);
        foreach ([$planningStart, $completion] as $instant) {
            self::require(! $tzdbMatches || $instant->setTimezone($plan['timezone'])->toDateString() <= $lastDate, 'Snapshot original result exceeds its plan-local horizon.');
        }
        $cutoff = self::cutoff($value['cutoff_result'], $plan, $received, $planningStart, $tzdbMatches);
        self::require($cutoff !== null || $planningStart->equalTo($received), 'Snapshot planning start cannot move without cutoff routing.');
        self::require($cutoff === null || ! $cutoff->sameDayEligible || $planningStart->equalTo($received), 'Snapshot eligible cutoff must preserve the original planning instant.');
        self::require(is_array($value['stages']) && array_is_list($value['stages']) && count($value['stages']) === count($plan['stages']), 'Snapshot stage results must match its plan.');
        $stages = [];
        $cursor = $planningStart;

        foreach ($value['stages'] as $index => $stage) {
            self::require(is_array($stage), 'Snapshot stage result must be an array.');
            self::keys($stage, ['id', 'type', 'profiles', 'ready_at', 'started_at', 'completed_at'], 'stage result');
            $definition = $plan['stages'][$index];
            $profiles = $definition['type'] === 'handoff' ? $definition['profiles'] : [$definition['profile']];
            self::require($stage['id'] === $definition['id'] && $stage['type'] === $definition['type'] && $stage['profiles'] === $profiles, 'Snapshot stage result identity differs from its plan.');
            $ready = self::timestamp($stage['ready_at'], 'stage.ready_at');
            $start = self::timestamp($stage['started_at'], 'stage.started_at');
            $end = self::timestamp($stage['completed_at'], 'stage.completed_at');
            self::require($ready->equalTo($cursor) && $start->greaterThanOrEqualTo($ready) && $end->greaterThanOrEqualTo($start), 'Snapshot stage chronology is inconsistent.');
            self::require(! $tzdbMatches || ($start->setTimezone($plan['timezone'])->toDateString() <= $lastDate && $end->setTimezone($plan['timezone'])->toDateString() <= $lastDate), 'Snapshot stage exceeds its plan-local horizon.');
            self::require($definition['type'] !== 'handoff' || $start->equalTo($end), 'Snapshot handoff must be instantaneous.');
            self::require($definition['type'] !== 'business_days' || $start->equalTo($ready), 'Snapshot business_days stage must retain its ready instant as start.');
            self::require($definition['type'] !== 'work' || ($definition['minutes'] === 0 ? $start->equalTo($end) : $end->greaterThan($start)), 'Snapshot work-stage duration is inconsistent.');
            self::require($definition['type'] !== 'business_days' || ($definition['days'] === 0 ? $start->equalTo($end) : $end->greaterThan($start)), 'Snapshot business-day stage duration is inconsistent.');
            $stages[] = new DeliveryStageResult($stage['id'], $stage['type'], $profiles, $ready, $start, $end);
            $cursor = $end;
        }

        self::require($cursor->equalTo($completion), 'Snapshot final estimate differs from its final stage.');

        return new DeliveryPlanResult($plan['timezone'], $received, $planningStart, $cutoff, $stages, $completion);
    }

    /** @param array<string, mixed> $plan */
    private static function cutoff(mixed $value, array $plan, CarbonImmutable $received, CarbonImmutable $planningStart, bool $tzdbMatches): ?CutoffResult
    {
        if (! array_key_exists('cutoff_profile', $plan)) {
            self::require($value === null, 'Snapshot has a cutoff result without cutoff routing.');

            return null;
        }

        self::require(is_array($value), 'Snapshot cutoff routing requires a cutoff result.');
        self::keys($value, ['profile', 'timezone', 'received_at', 'cutoff_at', 'effective_business_date', 'same_day_eligible', 'reason'], 'cutoff_result');
        self::require($value['profile'] === $plan['cutoff_profile'] && $value['timezone'] === $plan['profile_timezones'][$plan['cutoff_profile']], 'Snapshot cutoff identity/timezone differs from its plan.');
        self::timezone($value['timezone'], 'cutoff timezone');
        self::require(is_bool($value['same_day_eligible']) && in_array($value['reason'], ['before_cutoff', 'non_business_day', 'cutoff_disabled', 'cutoff_reached'], true) && $value['same_day_eligible'] === ($value['reason'] === 'before_cutoff'), 'Snapshot cutoff eligibility or reason is inconsistent.');
        $cutoffReceived = self::timestamp($value['received_at'], 'cutoff.received_at', false);
        self::require($cutoffReceived->equalTo($received), 'Snapshot cutoff received instant is inconsistent.');
        $cutoffAt = $value['cutoff_at'] === null ? null : self::timestamp($value['cutoff_at'], 'cutoff.cutoff_at', false);
        if ($tzdbMatches) {
            self::require($cutoffReceived->setTimezone($value['timezone'])->format('Y-m-d\TH:i:s.uP') === $value['received_at']
                && ($cutoffAt === null || $cutoffAt->setTimezone($value['timezone'])->format('Y-m-d\TH:i:s.uP') === $value['cutoff_at']), 'Snapshot cutoff offsets disagree with its policy timezone.');
            $cutoffReceived = $cutoffReceived->setTimezone($value['timezone']);
            $cutoffAt = $cutoffAt?->setTimezone($value['timezone']);
        }
        self::require(! $value['same_day_eligible'] || ($cutoffAt !== null && $cutoffReceived->lessThan($cutoffAt)), 'Snapshot eligible cutoff requires a future deadline.');
        self::require($value['reason'] !== 'cutoff_disabled' || $cutoffAt === null, 'Snapshot disabled cutoff must not contain a deadline.');
        $nominalDate = self::date($value['effective_business_date'], 'cutoff.effective_business_date');
        self::require($value['same_day_eligible']
            ? $value['effective_business_date'] === $cutoffReceived->toDateString()
            : $value['effective_business_date'] > $cutoffReceived->toDateString(), 'Snapshot cutoff effective date is inconsistent with its eligibility.');
        if ($tzdbMatches) {
            $effective = $value['same_day_eligible']
                ? $cutoffReceived->setTimezone($value['timezone'])->startOfDay()
                : $planningStart->setTimezone($value['timezone']);
            self::require($effective->toDateString() === $value['effective_business_date'] && $effective->equalTo($effective->startOfDay()), 'Snapshot cutoff effective date disagrees with its planning start.');
        } elseif (! $value['same_day_eligible']) {
            // The original planner already stored this exact routed midnight instant.
            $offset = $nominalDate->getTimestamp() - $planningStart->getTimestamp();
            self::require($planningStart->format('u') === '000000' && abs($offset) <= 86400, 'Snapshot cutoff effective date exceeds its stored planning boundary.');
            $seconds = abs($offset);
            $zone = new DateTimeZone(sprintf('%s%02d:%02d:%02d', $offset < 0 ? '-' : '+', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60));
            $effective = $planningStart->setTimezone($zone);
        } else {
            // The legacy date-only field does not encode a historical midnight offset.
            $effective = CarbonImmutable::instance(DateTimeImmutable::createFromFormat('!Y-m-d', $value['effective_business_date'], $cutoffReceived->getTimezone()));
        }

        return new CutoffResult($value['profile'], $value['timezone'], $cutoffReceived, $cutoffAt, $effective, $value['same_day_eligible'], $value['reason']);
    }

    private static function timestamp(mixed $value, string $label, bool $utc = true): CarbonImmutable
    {
        self::require(is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}[+-][0-9]{2}:[0-9]{2}\z/', $value) === 1, 'Snapshot '.$label.' must be an absolute timestamp with microseconds and an explicit offset.');

        try {
            $parsed = CutoffDateTimeNormalizer::toImmutable($value, 'UTC');
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Invalid snapshot '.$label.' timestamp.', previous: $exception);
        }

        if ($utc) {
            self::require($parsed->format('Y-m-d\TH:i:s.uP') === $value, 'Snapshot '.$label.' must use canonical UTC timestamps.');

            return $parsed;
        }

        return CarbonImmutable::instance(DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $value));
    }

    private static function date(mixed $value, string $label): CarbonImmutable
    {
        self::require(is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)), 'Invalid snapshot '.$label.' Gregorian date.');

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
    }

    private static function timezone(mixed $value, string $label): void
    {
        self::require(is_string($value) && in_array($value, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true), 'Snapshot '.$label.' must be an IANA timezone identifier.');
    }

    private static function hash(mixed $value, string $label): void
    {
        self::require(is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1, 'Snapshot '.$label.' must be a SHA-256 hex string.');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $expected
     */
    private static function keys(array $value, array $expected, string $label): void
    {
        self::allowedKeys($value, $expected, $label);
        self::require(count($value) === count($expected), 'Snapshot '.$label.' has missing fields.');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $allowed
     */
    private static function allowedKeys(array $value, array $allowed, string $label): void
    {
        foreach (array_keys($value) as $key) {
            self::require(is_string($key) && in_array($key, $allowed, true), 'Snapshot '.$label.' contains unsupported fields.');
        }
    }

    private static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
