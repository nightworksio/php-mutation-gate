<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\MySqlDialect;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\PdoStatements;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Snapshot;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\AnsweredStatements;
use NightWorksIO\MutationGate\Tests\Support\Databases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

/**
 * A database freshly migrated in a file, and its snapshot as it stands.
 *
 * @return array{PDO, Snapshot}
 */
$migrated = static function (): array {
    $file = sprintf('%s/testing.sqlite', Scratch::directory());
    $pdo = Databases::made(
        sprintf('sqlite:%s', $file),
        'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)',
        "INSERT INTO users (name) VALUES ('a')",
    );
    $connection = Connection::of(Driver::Sqlite, sprintf('sqlite:%s', $file), '', '', [], []);

    return [$pdo, Snapshot::of($connection, Databases::imaged(PdoStatements::over($pdo), $connection->dialect()))];
};

it('holds while the database stands as it did, read over a connection of its own', function () use ($migrated): void {
    [$pdo, $snapshot] = $migrated();
    Databases::ran($pdo, 'BEGIN', "INSERT INTO users (name) VALUES ('rolled back')", 'ROLLBACK');

    expect($snapshot->holds())->toBeTrue();
});

it('holds no more once a row was committed', function () use ($migrated): void {
    [$pdo, $snapshot] = $migrated();
    Databases::ran($pdo, "INSERT INTO users (name) VALUES ('committed')");

    expect($snapshot->holds())->toBeFalse();
});

it('holds no more once its database cannot be reached', function () use ($migrated): void {
    [$pdo] = $migrated();
    $gone = Connection::of(Driver::Sqlite, sprintf('sqlite:%s/missing/testing.sqlite', Scratch::directory()), '', '', [], []);

    expect(Snapshot::of($gone, Databases::imaged(PdoStatements::over($pdo), $gone->dialect()))->holds())->toBeFalse();
});

it('reads back the snapshot it wrote', function () use ($migrated): void {
    [, $snapshot] = $migrated();

    expect(Snapshot::read(Node::decode($snapshot->written()->line())))->toEqual($snapshot);
});

it('sets each counter that moved back to where it stood before it reads the database again', function (): void {
    $answers = static fn(string $counter): AnsweredStatements => new AnsweredStatements([
        "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" => [['users', 'BASE TABLE']],
        'SELECT * FROM `users`' => [],
        "SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES\nWHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT IS NOT NULL" => [['users', $counter]],
    ]);
    $connection = Connection::of(Driver::MySql, 'mysql:host=db;port=3306;dbname=testing', '', '', [], []);
    $moved = $answers('7');

    Databases::holdsOn(Snapshot::of($connection, Databases::imaged($answers('3'), new MySqlDialect())), $moved);

    expect($moved->ran())->toContain('ALTER TABLE `users` AUTO_INCREMENT = 3');
});
