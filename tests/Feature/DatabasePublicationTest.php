<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Configuration\DefinitionDocument;
use Zarbinco\LaravelWorkdays\Data\DefinitionPublication;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionConflictException;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class DatabasePublicationTest extends TestCase
{
    private DatabaseDefinitionRepository $repository;

    private array $definitions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        $this->repository = $this->app->make(DatabaseDefinitionRepository::class);
        $this->definitions = [
            'default_profile' => 'support', 'include_start_date' => false,
            'hijri' => ['method' => 'mathematical', 'adjustment' => 0], 'profile_routing' => null,
            'profiles' => ['support' => [
                'weekends' => ['Saturday', 'Sunday'],
                'working_hours' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], [['09:00', '17:00']]),
                'cutoff' => ['timezone' => 'UTC', 'time' => '16:00'],
                'custom_holidays' => ['2026-11-04' => 'Parent holiday'],
            ]],
        ];
    }

    protected function tearDown(): void
    {
        config()->set('workdays.database.connection', null);
        parent::tearDown();
    }

    public function test_initial_publication_edits_noop_conflict_and_restore_create_immutable_revisions(): void
    {
        $this->assertNull($this->repository->current());
        $first = $this->repository->publish($this->definitions, expectedRevisionId: null, metadata: ['actor' => 'test:editor', 'reason' => 'Initialize']);
        $this->assertInstanceOf(DefinitionPublication::class, $first);
        $this->assertTrue($first->changed);
        $raw = $this->repository->current();
        $this->assertSame($this->definitions, $raw->definitions);
        $this->assertSame(['actor' => 'test:editor', 'reason' => 'Initialize'], $raw->metadata);
        $noop = $this->repository->publish($this->definitions, $first->revisionId, ['reason' => 'Metadata does not change operational content']);
        $this->assertFalse($noop->changed);
        $this->assertSame($first->revisionId, $noop->revisionId);
        $next = $raw->definitions;
        $next['profiles']['support']['working_hours']['Monday'] = [['09:00', '16:00']];
        $second = $this->repository->publish($next, $first->revisionId);
        $this->assertGreaterThan($first->revisionId, $second->revisionId);
        $this->assertSame($this->definitions, $this->repository->revision($first->revisionId)->definitions);
        try {
            $this->repository->publish($next, $first->revisionId);
            $this->fail('A stale identical document must still conflict.');
        } catch (DefinitionConflictException) {
            $this->assertSame($second->revisionId, $this->repository->current()->revisionId);
        }
        $restored = $this->repository->publish($this->repository->revision($first->revisionId)->definitions, $second->revisionId);
        $this->assertGreaterThan($second->revisionId, $restored->revisionId);
        $this->assertSame($first->contentHash, $restored->contentHash);
        $this->assertSame(3, $this->app['db']->table('workday_definition_revisions')->count());
    }

    public function test_null_is_initialization_only_and_two_editors_never_silently_rebase(): void
    {
        $first = $this->repository->publish($this->definitions, null);
        foreach ([null, $first->revisionId + 100] as $expected) {
            try {
                $this->repository->publish($this->definitions, $expected);
                $this->fail('Explicit initialization or an incorrect token cannot replace a release.');
            } catch (DefinitionConflictException) {
                $this->assertSame($first->revisionId, $this->repository->current()->revisionId);
            }
        }
        $a = $this->repository->current();
        $b = $this->repository->current();
        $aDefinitions = $a->definitions;
        $aDefinitions['profiles']['support']['custom_holidays'] = [];
        $this->repository->publish($aDefinitions, $a->revisionId);
        $this->expectException(DefinitionConflictException::class);
        $this->repository->publish($b->definitions, $b->revisionId);
    }

    public function test_omitted_null_false_empty_aliases_and_raw_parent_ownership_survive_export_import(): void
    {
        $this->definitions['profiles']['child'] = [
            'extends' => 'support', 'working_hours' => ['monday' => []],
            'allow_overnight_working_hours' => false,
            'cutoff' => ['weekdays' => ['Monday' => null]],
            'extra_working_days' => ['2026-11-04' => null],
            'seasonal_working_hours' => [],
        ];
        $this->definitions['profiles']['day_only'] = ['weekends' => []];
        $first = $this->repository->publish($this->definitions, null);
        $raw = $this->repository->current()->definitions;
        $this->assertSame($this->definitions, $raw);
        $this->assertArrayNotHasKey('special_working_hours', $raw['profiles']['child']);
        $document = DefinitionDocument::fromJson(DefinitionDocument::fromArray($raw)->toJson());
        $this->assertSame($raw, $document->definitions);
        $this->assertSame($first->contentHash, $document->contentHash);
        config()->set('workdays.driver', 'database');
        $this->assertSame([], Workday::profile('child')->workingWindowsFor('2026-11-02'));
        $this->assertTrue(Workday::profile('child')->isBusinessDay('2026-11-04'));
        $this->assertTrue(Workday::profile('day_only')->isBusinessDay('2026-11-04'));
    }

    #[DataProvider('invalidCandidates')]
    public function test_invalid_publications_leave_the_existing_complete_release_unchanged(string $kind): void
    {
        $first = $this->repository->publish($this->definitions, null);
        $bad = $this->definitions;
        switch ($kind) {
            case 'default missing': $bad['default_profile'] = 'missing';
                break;
            case 'include string': $bad['include_start_date'] = 'false';
                break;
            case 'hijri method': $bad['hijri']['method'] = 'unsupported';
                break;
            case 'hijri numeric string': $bad['hijri']['adjustment'] = '0';
                break;
            case 'unknown root': $bad['unknown'] = 1;
                break;
            case 'unknown profile field': $bad['profiles']['support']['unknown'] = 1;
                break;
            case 'parent missing': $bad['profiles']['child'] = ['extends' => 'missing'];
                break;
            case 'cycle': $bad['profiles']['support']['extends'] = 'support';
                break;
            case 'default deleted': unset($bad['profiles']['support']);
                break;
            case 'routing dangling': $bad['profile_routing'] = ['fallback_profile' => 'missing', 'rules' => []];
                break;
            case 'routing target': $bad['profile_routing'] = ['fallback_profile' => 'support', 'rules' => [['id' => 'x', 'match' => ['channel' => 'x'], 'profile' => 'missing']]];
                break;
            case 'bad hours': $bad['profiles']['support']['working_hours']['Monday'] = [['17:00', '09:00']];
                break;
            case 'weekday duplicates': $bad['profiles']['support']['working_hours'][1] = [];
                break;
            case 'weekend duplicates': $bad['profiles']['support']['weekends'] = [1, 'Monday'];
                break;
            case 'cutoff aliases': $bad['profiles']['support']['cutoff']['weekdays'] = ['Monday' => '12:00', 1 => null];
                break;
            case 'cutoff timezone': $bad['profiles']['support']['cutoff']['timezone'] = 'Wrong/Zone';
                break;
            case 'cutoff time': $bad['profiles']['support']['cutoff']['time'] = '25:00';
                break;
            case 'cutoff unknown': $bad['profiles']['support']['cutoff']['unknown'] = true;
                break;
            case 'exact invalid': $bad['profiles']['support']['custom_holidays']['2026-02-30'] = 'Bad';
                break;
            case 'extra invalid': $bad['profiles']['support']['extra_working_days'] = ['2026-13-01' => 'Bad'];
                break;
            case 'recurring key': $bad['profiles']['support']['holidays'] = ['gregorian' => ['02-30' => 'Bad']];
                break;
            case 'calendar': $bad['profiles']['support']['holidays'] = ['unsupported' => []];
                break;
            case 'holiday title': $bad['profiles']['support']['holidays'] = ['gregorian' => ['01-01' => 2]];
                break;
            case 'season overlap': $bad['profiles']['support']['seasonal_working_hours'] = array_fill(0, 2, ['from' => '2026-11-01', 'through' => '2026-11-03', 'weekdays' => []]);
                break;
            case 'inherited descendant invalid':
                $bad['profiles']['child'] = ['extends' => 'support', 'working_hours' => ['Tuesday' => [['22:00', '06:00']]]];
                $bad['profiles']['support']['allow_overnight_working_hours'] = false;
                break;
            case 'object': $bad['profiles']['support']['custom_holidays'] = new \stdClass;
                break;
            case 'callback': $bad['profiles']['support']['unused'] = static fn () => true;
                break;
            case 'nan': $bad['profiles']['support']['unused'] = NAN;
                break;
            case 'infinity': $bad['profiles']['support']['unused'] = INF;
                break;
            case 'invalid utf8': $bad['profiles']['support']['custom_holidays'] = ['2026-11-04' => "bad\xFF"];
                break;
            case 'invalid name': $bad['profiles'][str_repeat('n', 256)] = ['weekends' => []];
                break;
        }
        try {
            $this->repository->publish($bad, $first->revisionId);
            $this->fail('Invalid candidate was published: '.$kind);
        } catch (InvalidArgumentException) {
            $this->assertSame($first->revisionId, $this->repository->current()->revisionId);
            $this->assertSame($this->definitions, $this->repository->current()->definitions);
            $this->assertSame(1, $this->app['db']->table('workday_definition_revisions')->count());
        }
    }

    public static function invalidCandidates(): array
    {
        return array_map(static fn (string $case): array => [$case], ['default missing', 'include string', 'hijri method', 'hijri numeric string',
            'unknown root', 'unknown profile field', 'parent missing', 'cycle', 'default deleted', 'routing dangling', 'routing target', 'bad hours',
            'weekday duplicates', 'weekend duplicates', 'cutoff aliases', 'cutoff timezone', 'cutoff time', 'cutoff unknown', 'exact invalid', 'extra invalid', 'recurring key',
            'calendar', 'holiday title', 'season overlap', 'inherited descendant invalid', 'object', 'callback', 'nan', 'infinity', 'invalid utf8', 'invalid name']);
    }

    #[DataProvider('failurePoints')]
    public function test_failure_inserting_profiles_or_switching_pointer_rolls_back_every_candidate_row(string $point): void
    {
        $first = $this->repository->publish($this->definitions, null);
        $armed = true;
        $connection = $this->app['db']->connection();
        $connection->listen(static function (QueryExecuted $query) use ($point, &$armed): void {
            if ($armed && str_starts_with($query->sql, $point)) {
                $armed = false;
                throw new \RuntimeException('Injected atomic publication failure.');
            }
        });
        $next = $this->definitions;
        $next['profiles']['support']['custom_holidays'] = [];
        try {
            $this->repository->publish($next, $first->revisionId);
            $this->fail('Injected publication failure was swallowed.');
        } catch (DefinitionSourceException $error) {
            $this->assertSame('Injected atomic publication failure.', $error->getPrevious()->getMessage());
            $this->assertFalse($armed);
            $this->assertSame($first->revisionId, $this->repository->current()->revisionId);
            $this->assertSame(1, $connection->table('workday_definition_revisions')->count());
            $this->assertSame(1, $connection->table('workday_definition_profiles')->count());
            $this->assertSame(0, $connection->transactionLevel());
        }
    }

    public static function failurePoints(): array
    {
        return [['insert into "workday_definition_profiles"'], ['update "workday_definition_state"']];
    }

    public function test_publication_inside_caller_transaction_is_provisional_and_never_commits_outer_work(): void
    {
        $first = $this->repository->publish($this->definitions, null);
        $connection = $this->app['db']->connection();
        $connection->beginTransaction();
        try {
            $next = $this->definitions;
            $next['profiles']['support']['custom_holidays'] = [];
            $second = $this->repository->publish($next, $first->revisionId);
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertSame($second->revisionId, $this->repository->current()->revisionId);
        } finally {
            $connection->rollBack();
        }
        $this->assertSame($first->revisionId, $this->repository->current()->revisionId);
        $this->assertSame(1, $connection->table('workday_definition_revisions')->count());
    }

    public function test_validation_and_versioned_export_are_readonly_bounded_and_reject_duplicate_json_members(): void
    {
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $document = $this->repository->validate($this->definitions);
        $this->assertSame([], $connection->getQueryLog());
        $this->assertSame($this->definitions, DefinitionDocument::fromJson($document->toJson())->definitions);
        $this->assertSame([], $connection->getQueryLog());
        $invalid = ['{"format_version":1,"format_version":1,"definitions":{}}', '{"format_version":1,"definitions":{},"bootstrap":{}}', '{"format_version":2,"definitions":{}}', '{invalid', str_repeat(' ', DefinitionDocument::MAX_BYTES + 1)];
        foreach ($invalid as $json) {
            try {
                DefinitionDocument::fromJson($json);
                $this->fail('Invalid JSON transfer was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertNull($this->repository->current());
            }
        }
        $large = $this->definitions;
        $large['profiles']['support']['custom_holidays']['2026-11-04'] = str_repeat('x', DefinitionDocument::MAX_BYTES);
        $this->expectException(InvalidArgumentException::class);
        $this->repository->validate($large);
    }

    public function test_maps_are_canonical_but_meaningful_routing_list_order_changes_the_hash(): void
    {
        $a = $this->definitions;
        $a['profile_routing'] = ['fallback_profile' => 'support', 'rules' => [
            ['id' => 'one', 'match' => ['channel' => 'x'], 'profile' => 'support'],
            ['id' => 'two', 'match' => ['channel' => 'x'], 'profile' => 'support'],
        ]];
        $b = array_reverse($a, true);
        $this->assertSame(DefinitionDocument::fromArray($a)->contentHash, DefinitionDocument::fromArray($b)->contentHash);
        $b['profile_routing']['rules'] = array_reverse($b['profile_routing']['rules']);
        $this->assertNotSame(DefinitionDocument::fromArray($a)->contentHash, DefinitionDocument::fromArray($b)->contentHash);
    }

    #[DataProvider('invalidSharedFields')]
    public function test_config_and_database_publication_reject_the_same_malformed_shared_fields(string $kind): void
    {
        if ($kind === 'weekend aliases') {
            $this->definitions['profiles']['support']['weekends'] = [1, 'Monday'];
        } else {
            $this->definitions['profiles']['support']['holidays'] = ['gregorian' => ['01-01' => $kind === 'integer title' ? 2 : false]];
        }
        config()->set('workdays.driver', 'config');
        config()->set('workdays.definitions', $this->definitions);
        $configFailure = null;
        try {
            Workday::profile('support')->isBusinessDay('2026-11-02');
        } catch (InvalidArgumentException $error) {
            $configFailure = $error;
        }
        $this->assertInstanceOf(InvalidArgumentException::class, $configFailure);
        try {
            $this->repository->publish($this->definitions, null);
            $this->fail('The same malformed logical definitions must not publish.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame($configFailure->getMessage(), $error->getMessage());
            $this->assertNull($this->repository->current());
        }
    }

    public static function invalidSharedFields(): array
    {
        return [['weekend aliases'], ['integer title'], ['boolean title']];
    }

    public function test_missing_nondefault_persisted_profile_is_source_corruption_instead_of_a_bulk_item_error(): void
    {
        $this->definitions['profiles']['other'] = ['weekends' => []];
        $first = $this->repository->publish($this->definitions, null);
        $this->app['db']->table('workday_definition_profiles')->where('revision_id', $first->revisionId)->where('profile_name', 'other')->delete();
        config()->set('workdays.driver', 'database');
        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('profile count');
        Workday::bulkCalculate([['id' => 'removed-row', 'operation' => 'is_business_day', 'profile' => 'other', 'date' => '2026-11-02']]);
    }

    public function test_malformed_persisted_integer_is_not_silently_coerced(): void
    {
        $first = $this->repository->publish($this->definitions, null);
        $this->app['db']->table('workday_definition_revisions')->where('id', $first->revisionId)->update(['schema_version' => '1junk']);
        $this->expectException(DefinitionSourceException::class);
        $this->repository->openView();
    }

    public function test_large_publishable_json_roundtrips_and_rejects_duplicate_members_after_long_strings(): void
    {
        $this->definitions['profiles']['support']['custom_holidays']['2026-11-04'] = str_repeat('x', 3900000);
        $document = $this->repository->validate($this->definitions);
        $json = $document->toJson();
        $this->assertLessThan(DefinitionDocument::MAX_BYTES, strlen($json));
        $this->assertSame($document->definitions, DefinitionDocument::fromJson($json)->definitions);
        $this->assertSame($document->contentHash, DefinitionDocument::fromJson($json)->contentHash);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate JSON object member');
        DefinitionDocument::fromJson(substr($json, 0, -1).',"format_version":1}');
    }

    public function test_duplicate_json_members_use_decoded_key_identity_even_when_escaped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate JSON object member');
        DefinitionDocument::fromJson('{"format_version":1,"\\u0066ormat_version":1,"definitions":{}}');
    }
}
