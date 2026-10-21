<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;

final class ExportDefinitionsCommand extends Command
{
    protected $signature = 'workdays:definitions:export
        {path : Destination JSON file}
        {--source= : Required explicit source: config or database}
        {--revision= : Historical database revision ID; database source only}
        {--force : Overwrite an existing destination file}';

    protected $description = 'Export one complete raw definition document as versioned JSON, without changing the operational source.';

    public function handle(DatabaseDefinitionRepository $repository): int
    {
        try {
            $source = $this->option('source');
            if (! in_array($source, ['config', 'database'], true)) {
                throw new InvalidArgumentException('An explicit --source=config or --source=database is required.');
            }
            $revision = $this->option('revision');
            if ($source === 'config') {
                if ($revision !== null) {
                    throw new InvalidArgumentException('--revision is available only with --source=database.');
                }
                $definitions = config('workdays.definitions');
                if (! is_array($definitions)) {
                    throw new InvalidArgumentException('Config source requires a workdays.definitions document.');
                }
            } else {
                $current = $revision === null ? $repository->current() : $repository->revision($this->revisionId($revision));
                if ($current === null) {
                    throw new InvalidArgumentException('No database definitions have been published. Initialize definitions explicitly first.');
                }
                $definitions = $current->definitions;
            }
            $document = $repository->validate($definitions);
            $json = $document->toJson();
            $path = (string) $this->argument('path');
            $handle = @fopen($path, (bool) $this->option('force') ? 'wb' : 'xb');
            if ($handle === false) {
                throw new InvalidArgumentException('Cannot create export file ['.$path.']; existing files require --force and the parent directory must be writable.');
            }
            try {
                if (fwrite($handle, $json) !== strlen($json)) {
                    throw new InvalidArgumentException('Could not write the complete definition export.');
                }
            } finally {
                fclose($handle);
            }
            $this->info('Definitions exported from '.$source.' to ['.$path.']; content hash '.$document->contentHash.'.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function revisionId(mixed $value): int
    {
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1 || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('The --revision option must be a positive integer revision ID.');
        }

        return (int) $value;
    }
}
