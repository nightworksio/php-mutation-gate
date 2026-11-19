<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copy;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Reach;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Session;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\SqliteCopier;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\PdoStatements;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Databases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A session of an SQLite database in a file of its own, made by running these statements on it. */
function sqliteCopierSession(string $file, string ...$statements): Session
{
    $session = Session::of(Databases::made(sprintf('sqlite:%s', $file), ...$statements));

    return $session instanceof Session ? $session : throw new LogicException('The session reports no errors.');
}

/** The copy of the SQLite database at this file the copier makes into a directory, or why it made none. */
function sqliteCopied(string $file, string $directory): Copy|string
{
    $original = Connection::of(Driver::Sqlite, sprintf('sqlite:%s', $file), '', '', [], []);
    $reach = new Reach(Driver::Sqlite, $original, $file, apart: false, where: 'the default connection', elsewhere: static fn(): NotGiven => NotGiven::value());

    try {
        return new SqliteCopier()->copied($original, $reach, 'a1b2', $directory);
    } catch (PDOException $refused) {
        return $refused->getMessage();
    }
}

it('holds state a copy lacks only where the session holds a temporary table or another database attached', function (): void {
    $directory = Scratch::directory();
    $copier = new SqliteCopier();

    expect($copier->holdsState(sqliteCopierSession(sprintf('%s/plain.sqlite', $directory), 'CREATE TABLE money (cents INTEGER)')))->toBeFalse()
        ->and($copier->holdsState(sqliteCopierSession(sprintf('%s/temporary.sqlite', $directory), 'CREATE TEMPORARY TABLE scratch (id INTEGER)')))->toBeTrue()
        ->and($copier->holdsState(sqliteCopierSession(sprintf('%s/attached.sqlite', $directory), sprintf("ATTACH DATABASE '%s/other.sqlite' AS other", $directory))))->toBeTrue();
});

it('names each pragma a connection holds for itself, with its value', function (): void {
    $settings = new SqliteCopier()->settings(sqliteCopierSession(sprintf('%s/money.sqlite', Scratch::directory())));
    $named = is_array($settings) ? array_column($settings, 1, 0) : [];

    expect(array_keys($named))->toBe([
        'foreign_keys',
        'defer_foreign_keys',
        'recursive_triggers',
        'ignore_check_constraints',
        'legacy_alter_table',
        'reverse_unordered_selects',
        'automatic_index',
        'query_only',
        'synchronous',
        'temp_store',
        'cache_size',
    ])
        ->and($named['foreign_keys'])->toBe('0');
});

it('names no setting where the database cannot tell its pragmas', function (): void {
    $file = sprintf('%s/garbage.sqlite', Scratch::directory());
    file_put_contents($file, str_repeat('not a database ', 100));

    expect(new SqliteCopier()->settings(sqliteCopierSession($file)))->toBeInstanceOf(NotGiven::class);
});

it('copies a file\'s committed state into the stream\'s directory, and drops the copy with every file beside it', function (): void {
    $directory = Scratch::directory();
    $file = sprintf('%s/money.sqlite', $directory);
    sqliteCopierSession($file, 'CREATE TABLE money (cents INTEGER)', 'INSERT INTO money VALUES (42)');
    $copy = sqliteCopied($file, $directory);
    $database = $copy instanceof Copy ? $copy->database : $copy;
    $rows = Databases::rows(PdoStatements::over(Databases::made(sprintf('sqlite:%s', $database))), 'SELECT cents FROM money');
    foreach (['-journal', '-wal', '-shm'] as $beside) {
        touch(sprintf('%s%s', $database, $beside));
    }

    if ($copy instanceof Copy) {
        $copy->dropped();
    }

    expect($database)->toBe(sprintf('%s/copy-a1b2.sqlite', $directory))
        ->and($rows)->toBe([['42']])
        ->and(glob(sprintf('%s*', $database)))->toBe([]);
});

it('leaves nothing of a copy it cannot write, and says why', function (): void {
    $directory = Scratch::directory();
    $file = sprintf('%s/money.sqlite', $directory);
    sqliteCopierSession($file, 'CREATE TABLE money (cents INTEGER)');

    expect(sqliteCopied($file, sprintf('%s/missing', $directory)))->toBeString()
        ->and(glob(sprintf('%s/missing/copy-a1b2.sqlite*', $directory)))->toBe([]);
});

it('drops what stands where it was to write a copy, where the copy cannot be written there', function (): void {
    $directory = Scratch::directory();
    $file = sprintf('%s/money.sqlite', $directory);
    sqliteCopierSession($file, 'CREATE TABLE money (cents INTEGER)');
    file_put_contents(sprintf('%s/copy-a1b2.sqlite', $directory), 'what stood there');

    expect(sqliteCopied($file, $directory))->toBeString()
        ->and(glob(sprintf('%s/copy-a1b2.sqlite*', $directory)))->toBe([]);
});
