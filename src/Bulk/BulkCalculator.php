<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Bulk;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hekmatinasser\Jalali\Jalali;
use Hekmatinasser\Verta\Verta;
use InvalidArgumentException;
use IslamicNetwork\Calendar\Models\Astronomical\Calculator as HijriCalculator;
use PDOException;
use ReflectionClass;
use RuntimeException;
use Throwable;
use Zarbinco\LaravelWorkdays\Data\BulkCalculationItemResult;
use Zarbinco\LaravelWorkdays\Data\BulkCalculationResult;
use Zarbinco\LaravelWorkdays\Delivery\DeliveryPlanner;
use Zarbinco\LaravelWorkdays\Delivery\DeliverySlotGenerator;
use Zarbinco\LaravelWorkdays\Delivery\ReverseDeliveryPlanner;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Recurring\RecurringScheduleGenerator;
use Zarbinco\LaravelWorkdays\Sla\SlaEvaluator;

/** Explicit, synchronous, bounded dispatch to the existing engines. No batch state is retained. */
final readonly class BulkCalculator
{
    private const FIELDS = [
        'is_business_day' => ['profile', 'date'],
        'working_windows' => ['profile', 'date'],
        'add_business_days' => ['profile', 'date', 'days'],
        'add_business_minutes' => ['profile', 'datetime', 'minutes'],
        'resolve_cutoff' => ['profile', 'received_at'],
        'plan_delivery' => ['received_at', 'plan'],
        'delivery_slots' => ['ready_at', 'options'],
        'recurring_schedule' => ['anchor_date', 'options'],
        'evaluate_sla' => ['started_at', 'evaluated_at', 'options'],
        'reverse_plan_delivery' => ['desired_completion_at', 'plan', 'options'],
    ];

    private ?Closure $observer;

    /** @internal Optional allocation observer for isolated tests/benchmarks, never a payload option. */
    public function __construct(?callable $onConstruction = null)
    {
        $this->observer = $onConstruction === null ? null : Closure::fromCallable($onConstruction);
    }

    public function calculate(array $operations, array $options = []): BulkCalculationResult
    {
        $options = $this->envelope($operations, $options);
        $references = $this->references($operations);

        if (count($references) > 50) {
            throw new WorkdayConfigurationException('A bulk calculation may reference at most 50 distinct profiles.');
        }

        // Snapshot inputs before the first query can invoke application listeners. Invalid deep
        // domain containers are item failures, without permitting recursive/unbounded copies.
        $inputs = [];

        foreach ($operations as $item) {
            try {
                $nodes = 0;
                $inputs[] = ['id' => $item['id'], 'operation' => $item['operation'], 'value' => $this->copy($item, 0, $nodes)];
            } catch (InvalidArgumentException $exception) {
                $inputs[] = ['id' => $item['id'], 'operation' => $item['operation'], 'error' => $exception];
            }
        }

        $configuration = config('workdays', []);

        if (! is_array($configuration)) {
            throw new WorkdayConfigurationException('The workdays configuration must be an array.');
        }

        $context = new BulkContext($configuration, $references, $options['prefetch'] ?? null, $this->observer);
        $results = [];

        foreach ($inputs as $input) {
            try {
                if (isset($input['error'])) {
                    throw $input['error'];
                }

                $value = $this->dispatch($input['value'], $context);
                $results[] = new BulkCalculationItemResult($input['id'], $input['operation'], true, $value, null);
            } catch (InvalidArgumentException|RuntimeException $exception) {
                if (! $this->recognized($exception)) {
                    throw $exception;
                }

                if ($options['on_error'] === 'throw') {
                    throw new RuntimeException('Bulk item ['.$input['id'].'] ('.$input['operation'].') failed: '.$exception->getMessage(), previous: $exception);
                }

                $results[] = new BulkCalculationItemResult($input['id'], $input['operation'], false, null, [
                    'type' => $exception::class, 'message' => mb_substr(mb_convert_encoding($exception->getMessage(), 'UTF-8', 'UTF-8'), 0, 2048, 'UTF-8'),
                ]);
            }
        }

        return new BulkCalculationResult($results);
    }

    private function envelope(array $operations, array $options): array
    {
        if (! array_is_list($operations) || count($operations) < 1 || count($operations) > 1000) {
            throw new WorkdayConfigurationException('Bulk operations must be a nonempty indexed list of at most 1000 items.');
        }

        foreach (array_keys($options) as $key) {
            if (! in_array($key, ['on_error', 'prefetch'], true)) {
                throw new WorkdayConfigurationException('Unknown bulk option ['.$key.'].');
            }
        }

        $options += ['on_error' => 'collect'];

        if (! in_array($options['on_error'], ['collect', 'throw'], true)) {
            throw new WorkdayConfigurationException('Bulk on_error must be collect or throw.');
        }

        if (array_key_exists('prefetch', $options)) {
            $range = $options['prefetch'];

            if (! is_array($range) || count($range) !== 2 || array_diff(array_keys($range), ['from', 'through']) !== []
                || ! $this->dateLabel($range['from'] ?? null) || ! $this->dateLabel($range['through'] ?? null)
                || $range['from'] > $range['through']) {
                throw new WorkdayConfigurationException('Bulk prefetch requires only strict Gregorian from and through dates, in ascending order.');
            }

            $from = new DateTimeImmutable($range['from'], new DateTimeZone('UTC'));
            $through = new DateTimeImmutable($range['through'], new DateTimeZone('UTC'));

            if ((int) $from->diff($through)->days + 1 > 62) {
                throw new WorkdayConfigurationException('Bulk prefetch is limited to 62 inclusive Gregorian date labels.');
            }
        }

        $ids = [];

        foreach ($operations as $item) {
            if (! is_array($item) || array_is_list($item)
                || ! is_string($item['id'] ?? null) || trim($item['id']) === ''
                || preg_match('//u', $item['id']) !== 1 || mb_strlen($item['id'], 'UTF-8') > 128
                || isset($ids[$item['id']])) {
                throw new WorkdayConfigurationException('Each bulk item must be an associative array with a unique nonempty UTF-8 string id of at most 128 characters.');
            }

            $ids[$item['id']] = true;
            $operation = $item['operation'] ?? null;

            if (! is_string($operation) || ! array_key_exists($operation, self::FIELDS)) {
                throw new WorkdayConfigurationException('Each bulk item must name a supported operation.');
            }

            foreach (array_keys($item) as $key) {
                if (! in_array($key, ['id', 'operation', ...self::FIELDS[$operation]], true)) {
                    throw new WorkdayConfigurationException('Bulk item ['.$item['id'].'] has an unknown or inappropriate key ['.$key.'].');
                }
            }
        }

        return array_key_exists('prefetch', $options)
            ? ['on_error' => $options['on_error'], 'prefetch' => ['from' => $options['prefetch']['from'], 'through' => $options['prefetch']['through']]]
            : ['on_error' => $options['on_error']];
    }

    private function references(array $operations): array
    {
        $names = [];
        $add = static function (mixed $name) use (&$names): void {
            if (is_string($name)) {
                $names[$name] = true;
            }
        };

        foreach ($operations as $item) {
            $add($item['profile'] ?? null);

            if (is_array($item['options'] ?? null)) {
                $add($item['options']['profile'] ?? null);
            }

            if (is_array($item['plan'] ?? null)) {
                $add($item['plan']['cutoff_profile'] ?? null);
                $stages = $item['plan']['stages'] ?? [];

                // An oversized/malformed stage list cannot resolve a calculator in the
                // existing planner. Leave its domain error to dispatch without traversing it.
                if (! is_array($stages) || ! array_is_list($stages) || count($stages) > 32) {
                    continue;
                }

                foreach ($stages as $stage) {
                    if (! is_array($stage)) {
                        continue;
                    }

                    $add($stage['profile'] ?? null);

                    $stageProfiles = $stage['profiles'] ?? [];

                    foreach (is_array($stageProfiles) && count($stageProfiles) <= 2 ? $stageProfiles : [] as $profile) {
                        $add($profile);
                    }
                }
            }
        }

        return array_map('strval', array_keys($names));
    }

    private function dispatch(array $item, BulkContext $context): mixed
    {
        foreach (self::FIELDS[$item['operation']] as $field) {
            if (! array_key_exists($field, $item)) {
                throw new InvalidArgumentException('Bulk item requires argument ['.$field.'].');
            }

            $value = $item[$field];
            $valid = match ($field) {
                'profile' => is_string($value),
                'days', 'minutes' => is_int($value),
                'options', 'plan' => is_array($value),
                default => is_string($value) || $value instanceof DateTimeInterface,
            };

            if (! $valid) {
                throw new InvalidArgumentException('Bulk argument ['.$field.'] has an invalid type.');
            }
        }

        $operation = $item['operation'];

        if ($operation === 'reverse_plan_delivery') {
            // The individual manager validates the registry before entering the reverse engine.
            $context->validateProfileRegistry();
        }

        if (in_array($operation, ['is_business_day', 'working_windows', 'add_business_days', 'add_business_minutes', 'resolve_cutoff'], true)) {
            $calculator = $context->calculator($item['profile']);

            return match ($operation) {
                'is_business_day' => $calculator->isBusinessDay($item['date']),
                'working_windows' => $calculator->workingWindowsFor($item['date']),
                'add_business_days' => $calculator->addBusinessDays($item['date'], $item['days']),
                'add_business_minutes' => $calculator->addBusinessMinutes($item['datetime'], $item['minutes']),
                'resolve_cutoff' => $calculator->resolveCutoff($item['received_at']),
            };
        }

        $scanSettings = $context->scanSettings();
        $resolve = $context->calculator(...);
        $config = $context->configuration(...);
        $forward = new DeliveryPlanner($resolve, $config, knownProfiles: $context->knownProfiles, scanSettings: $scanSettings);

        return match ($operation) {
            'plan_delivery' => $forward->plan($item['received_at'], $item['plan']),
            'delivery_slots' => (new DeliverySlotGenerator($resolve, $scanSettings))->generate($item['ready_at'], $item['options']),
            'recurring_schedule' => (new RecurringScheduleGenerator($resolve, $scanSettings))->generate($item['anchor_date'], $item['options']),
            'evaluate_sla' => (new SlaEvaluator($resolve, $config, $scanSettings))->evaluate($item['started_at'], $item['evaluated_at'], $item['options']),
            'reverse_plan_delivery' => (new ReverseDeliveryPlanner($forward, $resolve, $config, $scanSettings))->plan($item['desired_completion_at'], $item['plan'], $item['options']),
        };
    }

    /** Known domain failures are collectable; infrastructure and programming errors escape. */
    private function recognized(Throwable $exception): bool
    {
        $leaf = $exception;
        $causes = [];

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException || $cause instanceof \Error) {
                return false;
            }

            $leaf = $cause;
            $causes[] = $cause;
        }

        $root = str_replace('\\', '/', dirname(__DIR__));
        $sources = [$root];

        // Composer dependencies may be siblings of this package or live in path repositories.
        // Resolve their actual source locations only on this exception-handling path.
        foreach ([[CarbonImmutable::class, 1], [Verta::class, 1], [Jalali::class, 1], [HijriCalculator::class, 3]] as [$class, $levels]) {
            $source = (new ReflectionClass($class))->getFileName();

            if ($source !== false) {
                $sources[] = str_replace('\\', '/', dirname($source, $levels));
            }
        }

        foreach ($causes as $cause) {
            $origin = str_replace('\\', '/', $cause->getFile());
            $trusted = false;

            foreach ($sources as $source) {
                if (str_starts_with($origin, $source.'/')) {
                    $trusted = true;
                    break;
                }
            }

            // An application wrapper or listener remains unexpected even if it wraps
            // an otherwise legitimate date/calendar failure from a known dependency.
            if (! $trusted) {
                return false;
            }
        }

        $file = str_replace('\\', '/', $leaf->getFile());
        $message = $leaf->getMessage();

        if ($exception instanceof InvalidArgumentException) {
            return true;
        }

        if ($file === $root.'/Calculator/BusinessDayCalculator.php') {
            return str_starts_with($message, 'Unable to resolve a business ') || str_starts_with($message, 'Unable to resolve a cut-off eligible business date ');
        }

        if ($file === $root.'/Delivery/DeliveryPlanner.php') {
            return str_contains($message, 'global planning horizon') || str_starts_with($message, 'The stage exceeds the last permitted plan date ')
                || str_starts_with($message, 'The business_days target wall time ');
        }

        if ($file === $root.'/Recurring/RecurringScheduleGenerator.php' || $file === $root.'/Sla/SlaEvaluator.php') {
            return true;
        }

        return $file === $root.'/Delivery/ReverseDeliveryPlanner.php'
            && (str_contains($message, 'budget') || str_contains($message, 'cannot be represented')
                || str_contains($message, 'backward across a civil-date label') || str_contains($message, 'ambiguous midnight') || str_contains($message, 'spill past civil date'));
    }

    private function copy(mixed $value, int $depth, int &$nodes): mixed
    {
        if ($depth > 32 || ++$nodes > 100000) {
            throw new InvalidArgumentException('Bulk item exceeds the supported domain-container nesting or node budget.');
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $result = [];

        foreach ($value as $key => $entry) {
            $result[$key] = $this->copy($entry, $depth + 1, $nodes);
        }

        return $result;
    }

    private function dateLabel(mixed $date): bool
    {
        return is_string($date) && preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
