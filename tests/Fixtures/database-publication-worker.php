<?php

declare(strict_types=1);

/** Isolated integration worker. Only a parent-created disposable database is accepted. */
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionConflictException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$input = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (($input['isolated_workdays_test'] ?? null) !== true) {
    throw new RuntimeException('This worker requires an explicitly isolated test database.');
}
$container = new Container;
$container->instance('config', new Repository);
$capsule = new Manager($container);
$capsule->addConnection($input['connection'], 'workdays_test');
$capsule->getDatabaseManager()->setDefaultConnection('workdays_test');
$capsule->setEventDispatcher(new Dispatcher($capsule->getContainer()));
$config = $capsule->getContainer()['config'];
$config->set('workdays.database.connection', 'workdays_test');
$database = $capsule->getDatabaseManager();
$connection = $database->connection();
if ($connection->getDriverName() === 'sqlite') {
    $connection->statement('PRAGMA busy_timeout = 3000');
}
$armed = true;
$connection->listen(static function (QueryExecuted $query) use (&$armed, $input): void {
    // SQLite's deferred read is deliberately interleaved before either candidate inserts.
    // MySQL row locks serialize this point; its workers instead synchronize before publish.
    if ($armed && ($input['barrier_after_state_read'] ?? false) && str_starts_with($query->sql, 'select * from "workday_definition_state"')) {
        $armed = false;
        file_put_contents($input['ready'], 'ready');
        $until = microtime(true) + 10;
        while (! is_file($input['other_ready'])) {
            if (microtime(true) > $until) {
                throw new RuntimeException('Publication interleave barrier timed out.');
            }
            usleep(10000);
        }
    }
});
if (! ($input['barrier_after_state_read'] ?? false)) {
    file_put_contents($input['ready'], 'ready');
    $until = microtime(true) + 10;
    while (! is_file($input['other_ready'])) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Publication start barrier timed out.');
        }
        usleep(10000);
    }
}
$repository = new DatabaseDefinitionRepository($config, $database);
try {
    $result = $repository->publish($input['definitions'], $input['expected']);
    echo json_encode(['outcome' => 'published', 'revisionId' => $result->revisionId, 'changed' => $result->changed], JSON_THROW_ON_ERROR);
} catch (DefinitionConflictException $error) {
    echo json_encode(['outcome' => 'conflict', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['outcome' => 'error', 'type' => $error::class, 'message' => $error->getMessage(), 'cause' => $error->getPrevious()?->getMessage()], JSON_THROW_ON_ERROR);
    exit(2);
}
