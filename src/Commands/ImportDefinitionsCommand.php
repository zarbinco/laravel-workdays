<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Configuration\DefinitionDocument;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionConflictException;

final class ImportDefinitionsCommand extends Command
{
    protected $signature = 'workdays:definitions:import
        {path : Versioned definition JSON file}
        {--initialize : Publish only when there is no active revision}
        {--expected-revision= : Required current revision ID when replacing definitions}
        {--dry-run : Validate and check the revision precondition without writing}';

    protected $description = 'Validate and atomically publish a complete definition document to Database; never switches the runtime driver.';

    public function handle(DatabaseDefinitionRepository $repository): int
    {
        try {
            $initialize = (bool) $this->option('initialize');
            $expected = $this->option('expected-revision');
            if ($initialize === ($expected !== null)) {
                throw new InvalidArgumentException('Specify exactly one of --initialize or --expected-revision=<id>, including for --dry-run.');
            }
            if (! $initialize && (! is_string($expected) || preg_match('/\A[1-9][0-9]*\z/', $expected) !== 1 || filter_var($expected, FILTER_VALIDATE_INT) === false)) {
                throw new InvalidArgumentException('The --expected-revision option must be a positive integer revision ID.');
            }
            $expected = $initialize ? null : (int) $expected;
            $path = (string) $this->argument('path');
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                throw new InvalidArgumentException('Cannot read definition JSON file ['.$path.'].');
            }
            try {
                $json = stream_get_contents($handle, 4194305);
            } finally {
                fclose($handle);
            }
            if (! is_string($json) || strlen($json) > 4194304) {
                throw new InvalidArgumentException('Definition import exceeds the 4 MiB JSON limit.');
            }
            $document = DefinitionDocument::fromJson($json);
            $document = $repository->validate($document->definitions);
            if ((bool) $this->option('dry-run')) {
                $current = $repository->current();
                if ($current?->revisionId !== $expected) {
                    throw new DefinitionConflictException('Definition revision conflict: expected '.($expected ?? 'uninitialized').', current '.($current?->revisionId ?? 'uninitialized').'.');
                }
                $this->info('Dry-run validated definitions; content hash '.$document->contentHash.'. No rows were written; the runtime driver is unchanged.');

                return self::SUCCESS;
            }
            $publication = $repository->publish($document->definitions, expectedRevisionId: $expected, metadata: ['reason' => 'Definition JSON import']);
            $this->info(sprintf('Definitions %s at revision %d; content hash %s. Runtime driver unchanged.', $publication->changed ? 'published' : 'unchanged (no-op)', $publication->revisionId, $publication->contentHash));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
