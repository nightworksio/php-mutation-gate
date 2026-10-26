<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\SqliteDialect;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\Databases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('reads back the connection it wrote, every attribute of its kind', function (): void {
    $connection = Connection::of(
        Driver::Postgres,
        'pgsql:host=db;port=5432;dbname=testing',
        'gate',
        's3cret',
        [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_PERSISTENT => false, PDO::ATTR_CASE => 'lower'],
        ['tenant', 'public'],
    );

    expect(Connection::read(Node::decode($connection->written()->line())))->toEqual($connection);
});

it('opens a connection to its database, reading it as its kind does', function (): void {
    $file = sprintf('%s/testing.sqlite', Scratch::directory());
    Databases::made(sprintf('sqlite:%s', $file), 'CREATE TABLE users (id INTEGER PRIMARY KEY)');
    $connection = Connection::of(Driver::Sqlite, sprintf('sqlite:%s', $file), '', '', [], []);

    expect(Databases::rows(Databases::opened($connection), "SELECT name FROM sqlite_master WHERE type = 'table'"))->toBe([['users']])
        ->and($connection->dialect())->toEqual(new SqliteDialect());
});

it('cannot open a connection to a database that refuses it', function (): void {
    $connection = Connection::of(Driver::Sqlite, sprintf('sqlite:%s/missing/testing.sqlite', Scratch::directory()), '', '', [], []);

    expect(Databases::refusesOpening($connection))->toBeTrue();
});

it('asks its database to search the schemas its config names, which SQLite, having none, refuses', function (): void {
    $file = sprintf('%s/testing.sqlite', Scratch::directory());
    Databases::made(sprintf('sqlite:%s', $file), 'CREATE TABLE users (id INTEGER PRIMARY KEY)');

    expect(Databases::refusesOpening(Connection::of(Driver::Sqlite, sprintf('sqlite:%s', $file), '', '', [], ['public'])))->toBeTrue();
});

it('sets the schemas PostgreSQL searches, each quoted as a name, and none where its config names none', function (): void {
    $connection = static fn(string ...$search): Connection => Connection::of(Driver::Postgres, 'pgsql:host=db', '', '', [], array_values($search));

    expect($connection('tenant "a"', 'public')->searching())->toBe(['SET search_path TO "tenant ""a""", "public"'])
        ->and($connection()->searching())->toBe([]);
});
