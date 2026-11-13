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

    expect($named)->toHaveKeys(['foreign_keys', 'recursive_triggers', 'synchronous', 'cache_size'])
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
    touch(sprintf('%s-journal', $database));

    if ($copy instanceof Copy) {
        $copy->dropped();
    }

    expect($database)->toBe(sprintf('%s/copy-a1b2.sqlite', $directory))
        ->and($rows)->toBe([['42']])
        ->and(file_exists($database))->toBeFalse()
        ->and(file_exists(sprintf('%s-journal', $database)))->toBeFalse();
});

it('leaves nothing of a copy it cannot write, and says why', function (): void {
    $directory = Scratch::directory();
    $file = sprintf('%s/money.sqlite', $directory);
    sqliteCopierSession($file, 'CREATE TABLE money (cents INTEGER)');

    expect(sqliteCopied($file, sprintf('%s/missing', $directory)))->toBeString()
        ->and(glob(sprintf('%s/missing/copy-a1b2.sqlite*', $directory)))->toBe([]);
});
