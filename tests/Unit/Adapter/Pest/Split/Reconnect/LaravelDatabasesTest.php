<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copies;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\HeldConnections;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\LaravelDatabases;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\PdoStatements;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Databases;
use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\LaravelTesting;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    LaravelTesting::left();
    Scratch::sweep();
});

/** An SQLite database in a file of its own in this directory, by this name, holding one row: the file. */
function laravelDatabase(string $directory, string $name): string
{
    $file = sprintf('%s/%s.sqlite', $directory, $name);
    Databases::made(sprintf('sqlite:%s', $file), 'CREATE TABLE money (cents INTEGER)', 'INSERT INTO money VALUES (42)');

    return $file;
}

/**
 * The application's databases, its manager holding a connection of each of these configs, each connected and these
 * run on it: a statement, or what is done to its PDO.
 *
 * @param array<string, array<string, string>> $configs
 * @param string|Closure(PDO): mixed            ...$run
 */
function laravelDatabases(array $configs, string|Closure ...$run): LaravelDatabases
{
    $manager = LaravelTesting::managing($configs);

    foreach ($manager->getConnections() as $connection) {
        $pdo = $connection->getPdo();

        foreach ($run as $each) {
            is_string($each) ? $pdo->exec($each) : $each($pdo);
        }
    }

    $databases = LaravelDatabases::current();

    return $databases instanceof LaravelDatabases ? $databases : throw new LogicException('No application serves databases.');
}

/**
 * Whether the databases read this connection in a transaction outside one, and inside one it began and then rolled
 * back.
 *
 * @return array{bool, bool}
 */
function laravelTransactions(LaravelDatabases $databases, object $connection): array
{
    $pdo = $databases->pdo($connection);

    if (! $pdo instanceof PDO) {
        throw new LogicException('The connection is not connected.');
    }

    $outside = $databases->inTransaction($connection, $pdo);
    $pdo->beginTransaction();
    $inside = $databases->inTransaction($connection, $pdo);
    $pdo->rollBack();

    return [$outside, $inside];
}

/** A PDO that reports no errors. */
function laravelSilenced(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
}

/** The manager the current application binds. */
function laravelManager(): DatabaseManager
{
    $manager = Container::getInstance()->make('db');

    return $manager instanceof DatabaseManager ? $manager : throw new LogicException('No manager is bound.');
}

it('reads no databases where no application is current', function (): void {
    LaravelTesting::left();

    expect(LaravelDatabases::current())->toBeInstanceOf(NotGiven::class);
});

it('reads each connection the manager holds, where it reaches, its PDO while it is connected, and whether it is in a transaction', function (): void {
    $file = laravelDatabase(Scratch::directory(), 'main');
    $databases = laravelDatabases(['main' => ['driver' => 'sqlite', 'database' => $file], 'apart' => ['driver' => 'sqlite', 'database' => $file, 'read' => 'replica']], 'SELECT 1');
    ['main' => $main, 'apart' => $apart] = $databases->open();
    [$outside, $inside] = laravelTransactions($databases, $main);
    $databases->closed('main');

    expect([$databases->reach($main)->driver, $databases->reach($main)->database, $databases->reach($main)->apart])->toBe([Driver::Sqlite, $file, false])
        ->and($databases->reach($apart)->apart)->toBeTrue()
        ->and([$outside, $inside])->toBe([false, true])
        ->and($databases->pdo($main))->toBeInstanceOf(NotGiven::class)
        ->and(laravelManager()->reconnected)->toBe(['main']);
});

it('reads a fresh connection of a connection\'s config as the manager makes one, and nothing where it cannot connect', function (): void {
    $file = laravelDatabase(Scratch::directory(), 'main');
    LaravelTesting::managing(['main' => ['driver' => 'sqlite', 'database' => $file], 'nowhere' => ['driver' => 'sqlite', 'database' => Scratch::directory()]]);
    $databases = LaravelDatabases::current();
    ['main' => $main, 'nowhere' => $nowhere] = $databases instanceof LaravelDatabases ? $databases->open() : [];
    $read = static fn(PDO $fresh): array => Databases::rows(PdoStatements::over($fresh), 'SELECT cents FROM money');

    expect($databases instanceof LaravelDatabases ? $databases->freshly('main', $main, $read) : $databases)->toBe([['42']])
        ->and($databases instanceof LaravelDatabases ? $databases->freshly('nowhere', $nowhere, $read) : $databases)->toBeInstanceOf(NotGiven::class);
});

it('points each connection to the database a reach names at its copy, in the config and in the manager, and no other', function (): void {
    $directory = Scratch::directory();
    $file = laravelDatabase($directory, 'main');
    $other = laravelDatabase($directory, 'other');
    $databases = laravelDatabases([
        'main' => ['driver' => 'sqlite', 'database' => $file],
        'same' => ['driver' => 'sqlite', 'database' => $file],
        'other' => ['driver' => 'sqlite', 'database' => $other],
    ]);
    $open = $databases->open();

    $databases->redirected($databases->reach($open['main']), 'copy.sqlite');
    $config = Container::getInstance()->make('config');
    $connections = $config instanceof Repository ? $config->get('database.connections') : [];

    expect(array_map(static fn(object $connection): mixed => $connection instanceof Connection ? $connection->getConfig()['database'] : $connection, $open))
        ->toBe(['main' => 'copy.sqlite', 'same' => 'copy.sqlite', 'other' => $other])
        ->and(is_array($connections) ? array_map(static fn(mixed $each): mixed => is_array($each) ? $each['database'] ?? null : $each, array_values($connections)) : $connections)
        ->toBe(['copy.sqlite', 'copy.sqlite', $other])
        ->and(laravelManager()->reconnected)->toBe(['main', 'same']);
});

it('holds each connected connection a child can fork with a copy of, and leaves out one to a database held in memory', function (): void {
    $file = laravelDatabase(Scratch::directory(), 'main');
    $held = HeldConnections::of(laravelDatabases(['main' => ['driver' => 'sqlite', 'database' => $file], 'memory' => ['driver' => 'sqlite', 'database' => ':memory:']], 'SELECT 1'));

    expect(is_array($held) ? array_keys($held) : $held)->toBe(['main'])
        ->and(is_array($held) ? [$held['main'][0]->database, $held['main'][1]] : $held)->toBe([$file, Driver::Sqlite]);
});

it('holds no connection where one is in a transaction, reads and writes apart, keeps state of its own, or holds a setting a fresh one does not', function (string $read, string ...$statements): void {
    $file = laravelDatabase(Scratch::directory(), 'main');
    $config = ['driver' => 'sqlite', 'database' => $file, ...($read === '' ? [] : ['read' => $read])];

    expect(HeldConnections::of(laravelDatabases(['main' => $config], ...$statements)))->toBeInstanceOf(NotGiven::class);
})->with([
    'in a transaction' => ['', 'BEGIN'],
    'read and written apart' => ['replica'],
    'keeping a temporary table' => ['', 'CREATE TEMPORARY TABLE scratch (id INTEGER)'],
    'holding a setting of its own' => ['', 'PRAGMA foreign_keys = ON'],
]);

it('holds no connection whose database cannot tell its settings', function (): void {
    $garbage = sprintf('%s/garbage.sqlite', Scratch::directory());
    file_put_contents($garbage, str_repeat('not a database ', 100));
    $untold = HeldConnections::of(laravelDatabases(['main' => ['driver' => 'sqlite', 'database' => $garbage]]));

    expect($untold)->toBeInstanceOf(NotGiven::class);
});

it('holds no connection whose PDO reports no errors, as no session of it can be read', function (): void {
    $file = laravelDatabase(Scratch::directory(), 'main');

    expect(HeldConnections::of(laravelDatabases(
        ['main' => ['driver' => 'sqlite', 'database' => $file]],
        laravelSilenced(...),
    )))->toBeInstanceOf(NotGiven::class);
});

it('points no connection anywhere where it copied nothing', function (): void {
    $file = laravelDatabase(Scratch::directory(), 'main');
    laravelDatabases(['main' => ['driver' => 'sqlite', 'database' => $file]]);

    Copies::none()->reconnected();

    expect(laravelManager()->getConnections()['main']->getConfig()['database'])->toBe($file)
        ->and(laravelManager()->reconnected)->toBe([]);
});

it('takes no copy where no application serves databases, or it holds no connection a copy stands in for', function (): void {
    LaravelTesting::left();
    $unserved = Copies::taken(Descriptors::held(), Scratch::directory());
    laravelDatabases(['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]);

    expect($unserved)->toBeInstanceOf(NotGiven::class)
        ->and(Copies::taken(Descriptors::held(), Scratch::directory()))->toBeInstanceOf(NotGiven::class);
});

it('copies the database each held connection reaches for a child, points the connections at the copy, and drops it', function (): void {
    $directory = Scratch::directory();
    $file = laravelDatabase($directory, 'main');

    $answer = json_decode(FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copies;
        use NightWorksIO\MutationGate\Tests\Support\LaravelTesting;

        $manager = LaravelTesting::managing(['main' => ['driver' => 'sqlite', 'database' => %1$s], 'same' => ['driver' => 'sqlite', 'database' => %1$s]]);

        foreach ($manager->getConnections() as $connection) {
            $connection->getPdo()->exec('SELECT 1');
        }

        $copies = Copies::taken(Descriptors::held(), %2$s);
        $copies instanceof Copies && $copies->reconnected();
        $databases = array_map(static fn(object $connection): string => $connection->getConfig()['database'], $manager->getConnections());
        $copy = $databases['main'];
        $cents = new PDO(sprintf('sqlite:%%s', $copy))->query('SELECT cents FROM money')->fetchColumn();
        $copies instanceof Copies && $copies->dropped();

        echo json_encode([$copies instanceof Copies, array_values(array_unique($databases)), (string) $cents, file_exists($copy)]);
        PHP, var_export($file, return: true), var_export($directory, return: true))), associative: true);
    [$copied, $databases, $cents, $left] = is_array($answer) ? $answer : [false, [], '', true];

    expect([$copied, $cents, $left])->toBe([true, '42', false])
        ->and(is_array($databases) && count($databases) === 1 && is_string($databases[0]) ? dirname($databases[0]) : $databases)->toBe($directory)
        ->and($databases)->not->toBe([$file]);
});

it('copies nothing for a child where the process shares no descriptor, and refuses where it does and the stream does not reconnect', function (): void {
    expect(FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copies;

        $start = Descriptors::held();
        $none = Copies::atFork($start, true, %1$s);
        $held = tmpfile();
        $refused = Copies::atFork($start, false, %1$s);

        echo json_encode([$none == Copies::none(), $refused instanceof Copies]);
        PHP, var_export(Scratch::directory(), return: true))))->toBe('[true,false]');
});

it('takes no copy, its connections closed, where the process still holds a file a child would share, or a database cannot be copied', function (bool $holding): void {
    $file = laravelDatabase(Scratch::directory(), 'main');
    $into = $holding ? Scratch::directory() : '/nowhere/copies';

    expect(FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copies;
        use NightWorksIO\MutationGate\Tests\Support\LaravelTesting;

        $manager = LaravelTesting::managing(['main' => ['driver' => 'sqlite', 'database' => %s]]);
        $manager->getConnections()['main']->getPdo();
        $start = Descriptors::held();
        $held = %s ? tmpfile() : null;

        echo json_encode([Copies::taken($start, %s) instanceof Copies, $manager->reconnected]);
        PHP, var_export($file, return: true), var_export($holding, return: true), var_export($into, return: true))))->toBe('[false,["main"]]');
})->with([
    'still holding a file' => [true],
    'into a directory that is not there' => [false],
]);
