<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Throwable;
use Zarbinco\LaravelWorkdays\Data\DefinitionPublication;
use Zarbinco\LaravelWorkdays\Data\DefinitionRevision;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionConflictException;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Support\ProfileRouter;

/** Explicit Database administration and pinned runtime reads. No active-pointer/process cache. */
final readonly class DatabaseDefinitionRepository
{
    public function __construct(private Repository $config, private DatabaseManager $database) {}

    /** Read-only candidate validation. Does not connect to a database. */
    public function validate(array $definitions): DefinitionDocument
    {
        return DefinitionDocument::fromArray($definitions);
    }

    public function current(): ?DefinitionRevision
    {
        $connection = $this->connection();

        return $this->read(function () use ($connection): ?DefinitionRevision {
            $head = $this->head($connection);

            return $head === null ? null : $this->complete($connection, $head);
        });
    }

    public function revision(int $revisionId): DefinitionRevision
    {
        if ($revisionId < 1) {
            throw new InvalidArgumentException('Revision ID must be a positive native integer.');
        }
        $connection = $this->connection();

        return $this->read(function () use ($connection, $revisionId): DefinitionRevision {
            $row = $connection->table('workday_definition_revisions')->useWritePdo()->where('id', $revisionId)->first();
            if ($row === null) {
                throw new DefinitionSourceException('The requested immutable definition revision does not exist.');
            }

            return $this->complete($connection, $row);
        });
    }

    /** Required precondition: null initializes only; a positive ID edits that exact head. */
    public function publish(array $definitions, ?int $expectedRevisionId, array $metadata = []): DefinitionPublication
    {
        if ($expectedRevisionId !== null && $expectedRevisionId < 1) {
            throw new InvalidArgumentException('Expected revision must be null for initialization or a positive native integer.');
        }
        $candidate = $this->validate($definitions);
        $metadata = self::metadata($metadata);
        $settings = $candidate->definitions;
        unset($settings['profiles']);
        $settingsJson = DefinitionDocument::encode($settings);
        $connection = $this->connection();
        $attempt = 0;
        try {
            // Nested application transactions use savepoints and remain caller-owned.
            return $connection->transaction(function () use ($connection, $candidate, $expectedRevisionId, $settingsJson, $settings, $metadata, &$attempt): DefinitionPublication {
                // Give a competing SQLite writer time to commit before a fresh retry snapshot.
                // Laravel owns rollback/retry detection; caller-owned transactions are never retried.
                if (++$attempt > 1) {
                    usleep(($attempt - 1) * 10000);
                }
                $state = $connection->table('workday_definition_state')->useWritePdo()->where('id', 1)->lockForUpdate()->first();
                if ($state === null) {
                    throw new DefinitionSourceException('Definition state row is missing; run the Workdays definitions migration on the configured connection.');
                }
                $active = $state->active_revision_id === null ? null : self::id($state->active_revision_id);
                if ($active !== $expectedRevisionId) {
                    throw new DefinitionConflictException('Definition revision conflict: expected '.($expectedRevisionId ?? 'uninitialized').', current '.($active ?? 'uninitialized').'. Retrieve current(), resolve the edit, and publish with its revisionId.');
                }
                if ($active !== null) {
                    $row = $connection->table('workday_definition_revisions')->useWritePdo()->where('id', $active)->first();
                    if ($row === null) {
                        throw new DefinitionSourceException('Active definition revision is missing.');
                    }
                    $this->header($row);
                    if (hash_equals($row->content_hash, $candidate->contentHash)) {
                        // Full read verifies that a corrupt release cannot masquerade as a no-op.
                        $this->complete($connection, $row);

                        return new DefinitionPublication($active, false, $candidate->contentHash);
                    }
                }
                $revisionId = (int) $connection->table('workday_definition_revisions')->insertGetId([
                    'schema_version' => DefinitionDocument::FORMAT_VERSION,
                    'settings_json' => $settingsJson,
                    'settings_hash' => DefinitionDocument::hash($settings, 'settings'),
                    'content_hash' => $candidate->contentHash,
                    'profile_count' => count($candidate->definitions['profiles']),
                    'metadata_json' => DefinitionDocument::encode($metadata),
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $rows = [];
                $position = 0;
                foreach ($candidate->definitions['profiles'] as $name => $profile) {
                    $name = (string) $name;
                    $rows[] = ['revision_id' => $revisionId, 'name_hash' => hash('sha256', $name), 'profile_name' => $name,
                        'position' => $position++, 'definition_json' => DefinitionDocument::encode($profile), 'content_hash' => DefinitionDocument::hash($profile, 'profile')];
                    if (count($rows) === 100) {
                        $connection->table('workday_definition_profiles')->insert($rows);
                        $rows = [];
                    }
                }
                if ($rows !== []) {
                    $connection->table('workday_definition_profiles')->insert($rows);
                }
                $pointer = $connection->table('workday_definition_state')->where('id', 1);
                $expectedRevisionId === null ? $pointer->whereNull('active_revision_id') : $pointer->where('active_revision_id', $expectedRevisionId);
                if ($pointer->update(['active_revision_id' => $revisionId]) !== 1) {
                    throw new DefinitionConflictException('Definition revision conflict while switching the active pointer.');
                }

                return new DefinitionPublication($revisionId, true, $candidate->contentHash);
            }, $connection->transactionLevel() === 0 ? 5 : 1);
        } catch (DefinitionConflictException|DefinitionSourceException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new DefinitionSourceException('Database publication failed. Inspect current() before retrying with a fresh revision; transaction failures roll back, while an application callback after commit cannot undo that commit.', previous: $error);
        }
    }

    /** One primary connection + committed revision; no transaction remains open for user code. */
    public function openView(): WorkdayDefinitions
    {
        $connection = $this->connection();

        return $this->read(function () use ($connection): WorkdayDefinitions {
            $head = $this->head($connection);
            if ($head === null) {
                throw new DefinitionSourceException('Database definitions are uninitialized. Run the definitions migration and explicitly publish an initial release (expectedRevisionId: null).');
            }
            $settings = $this->header($head);
            $revisionId = self::id($head->id);
            $profiles = [];
            $required = [$settings['default_profile'] => true];
            if (is_array($settings['profile_routing'] ?? null)) {
                $routing = $settings['profile_routing'];
                if (is_string($routing['fallback_profile'] ?? null)) {
                    $required[$routing['fallback_profile']] = true;
                }
                foreach ($routing['rules'] ?? [] as $rule) {
                    if (is_string($rule['profile'] ?? null)) {
                        $required[$rule['profile']] = true;
                    }
                }
            }
            // Captures the connection object and revision, not a mutable connection name/head.
            $load = function (string $name) use ($connection, $revisionId, &$profiles, &$required): ?array {
                if (! array_key_exists($name, $profiles)) {
                    $profiles[$name] = $this->read(function () use ($connection, $revisionId, $name, &$required): ?array {
                        $row = $connection->table('workday_definition_profiles')->useWritePdo()->where('revision_id', $revisionId)->where('name_hash', hash('sha256', $name))->first();
                        if ($row === null) {
                            if (isset($required[$name])) {
                                throw new DefinitionSourceException('A required profile ['.$name.'] is missing from immutable revision ['.$revisionId.'].');
                            }

                            return null;
                        }
                        $profile = $this->profileRow($row, $revisionId, $name);
                        if (is_string($profile['extends'] ?? null)) {
                            $required[$profile['extends']] = true;
                        }

                        return $profile;
                    });
                }
                if ($profiles[$name] === null && isset($required[$name])) {
                    throw new DefinitionSourceException('A referenced profile ['.$name.'] is missing from immutable revision ['.$revisionId.'].');
                }

                return $profiles[$name];
            };
            $view = new WorkdayDefinitions($settings,
                static fn (string $name): array => $load($name) ?? throw new DefinitionSourceException('Required persisted profile missing.'),
                static fn (string $name): bool => $load($name) !== null,
                source: 'database', revisionId: $revisionId);

            // Structural globals/routing were validated on publication; checksum/constructor detect corruption.
            return $view;
        });
    }

    /** @internal Bounds and types for optional app-supplied audit data, outside content fingerprints. */
    public static function metadata(array $metadata): array
    {
        $metadata = DefinitionDocument::data($metadata);
        if (array_diff(array_keys($metadata), ['actor', 'reason', 'import']) !== []) {
            throw new InvalidArgumentException('Publication metadata supports actor, reason and import only.');
        }
        foreach (['actor' => 255, 'reason' => 1000] as $key => $maximum) {
            if (array_key_exists($key, $metadata) && (! is_string($metadata[$key]) || trim($metadata[$key]) === '' || strlen($metadata[$key]) > $maximum)) {
                throw new InvalidArgumentException('Publication metadata '.$key.' must be a nonblank string of at most '.$maximum.' bytes.');
            }
        }
        if (array_key_exists('import', $metadata) && ! is_array($metadata['import'])) {
            throw new InvalidArgumentException('Publication import metadata must be native JSON array data.');
        }
        if (strlen(DefinitionDocument::encode($metadata)) > 16384) {
            throw new InvalidArgumentException('Publication metadata exceeds 16 KiB.');
        }

        return $metadata;
    }

    private function connection(): Connection
    {
        try {
            $name = $this->config->get('workdays.database.connection');
            if ($name !== null && (! is_string($name) || trim($name) === '')) {
                throw new InvalidArgumentException('workdays.database.connection must be null or a nonblank configured connection name.');
            }
            $name ??= $this->database->getDefaultConnection();
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('No default Database connection is configured.');
            }

            return $this->database->connection($name);
        } catch (Throwable $error) {
            throw new DefinitionSourceException('Unable to resolve the configured Workdays Database connection; no default fallback is used for an invalid explicit connection.', previous: $error);
        }
    }

    private function head(Connection $connection): ?object
    {
        $row = $connection->table('workday_definition_state as s')->useWritePdo()
            ->leftJoin('workday_definition_revisions as r', 'r.id', '=', 's.active_revision_id')
            ->where('s.id', 1)->select('s.active_revision_id', 'r.*')
            ->selectSub($connection->table('workday_definition_profiles as p')->selectRaw('count(*)')->whereColumn('p.revision_id', 'r.id'), 'actual_profile_count')
            ->first();
        if ($row === null) {
            throw new DefinitionSourceException('Definition state is missing; run the Workdays definitions migration on the configured connection.');
        }
        if ($row->active_revision_id === null) {
            return null;
        }
        if ($row->id === null || self::id($row->id) !== self::id($row->active_revision_id)) {
            throw new DefinitionSourceException('Active definition revision is missing or corrupt.');
        }
        if ((int) $row->actual_profile_count !== self::id($row->profile_count)) {
            throw new DefinitionSourceException('Persisted definition profile count is inconsistent; an immutable release is corrupt.');
        }

        return $row;
    }

    private function header(object $row): array
    {
        if (self::id($row->schema_version) !== DefinitionDocument::FORMAT_VERSION || ! self::checksum($row->content_hash)
            || ! self::checksum($row->settings_hash) || self::id($row->profile_count) > DefinitionDocument::MAX_PROFILES) {
            throw new DefinitionSourceException('Persisted definition revision header is corrupt or has an unsupported schema version.');
        }
        $settings = DefinitionDocument::decode($row->settings_json);
        if (! is_array($settings) || ! hash_equals($row->settings_hash, DefinitionDocument::hash($settings, 'settings'))) {
            throw new DefinitionSourceException('Persisted definition settings failed their integrity check.');
        }
        new WorkdayDefinitions($settings, static fn (): array => [], static fn (): bool => false, source: 'database', revisionId: self::id($row->id));
        if (($settings['profile_routing'] ?? null) !== null) {
            (new ProfileRouter(static fn (string $name): bool => true))->resolve([], $settings['profile_routing']);
        }
        self::metadata(DefinitionDocument::decode($row->metadata_json));

        return $settings;
    }

    private function profileRow(object $row, int $revisionId, ?string $expectedName = null): array
    {
        $name = $row->profile_name;
        if (! is_string($name) || ($expectedName !== null && $name !== $expectedName)
            || self::id($row->revision_id) !== $revisionId || ! hash_equals(hash('sha256', $name), $row->name_hash)
            || ! self::checksum($row->content_hash)) {
            throw new DefinitionSourceException('Persisted profile identity/hash is corrupt.');
        }
        DefinitionDocument::profileName($name);
        $profile = DefinitionDocument::decode($row->definition_json);
        DefinitionDocument::rawProfile($name, $profile);
        if (! hash_equals($row->content_hash, DefinitionDocument::hash($profile, 'profile'))) {
            throw new DefinitionSourceException('Persisted profile ['.$name.'] failed its integrity check.');
        }

        return $profile;
    }

    private function complete(Connection $connection, object $row): DefinitionRevision
    {
        $settings = $this->header($row);
        $revisionId = self::id($row->id);
        $profiles = [];
        $rows = $connection->table('workday_definition_profiles')->useWritePdo()->where('revision_id', $revisionId)->orderBy('position')->get();
        if (count($rows) !== self::id($row->profile_count)) {
            throw new DefinitionSourceException('Persisted revision profile count is inconsistent.');
        }
        foreach ($rows as $position => $profileRow) {
            if ((string) $profileRow->position !== (string) $position || array_key_exists($profileRow->profile_name, $profiles)) {
                throw new DefinitionSourceException('Persisted revision profile order/identity is inconsistent.');
            }
            $profiles[$profileRow->profile_name] = $this->profileRow($profileRow, $revisionId);
        }
        $definitions = $settings + ['profiles' => $profiles];
        $document = DefinitionDocument::fromArray($definitions);
        if (! hash_equals($row->content_hash, $document->contentHash)) {
            throw new DefinitionSourceException('Persisted definition release failed its integrity check.');
        }

        return new DefinitionRevision($revisionId, $document->definitions, $document->contentHash, DefinitionDocument::decode($row->metadata_json));
    }

    private function read(callable $read): mixed
    {
        try {
            return $read();
        } catch (DefinitionSourceException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new DefinitionSourceException('Unable to read Database definitions. Run the definitions migration and initial publication on the configured connection; repair corrupt persisted data rather than falling back.', previous: $error);
        }
    }

    private static function checksum(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1;
    }

    private static function id(mixed $id): int
    {
        if ((! is_int($id) && (! is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1)) || (int) $id < 1 || (string) (int) $id !== (string) $id) {
            throw new DefinitionSourceException('Persisted revision ID is invalid or exceeds the supported integer range.');
        }

        return (int) $id;
    }
}
