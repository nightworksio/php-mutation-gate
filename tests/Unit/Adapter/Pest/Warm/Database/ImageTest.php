<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Image;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\MySqlDialect;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\PdoStatements;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\SqliteDialect;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\AnsweredStatements;
use NightWorksIO\MutationGate\Tests\Support\Databases;

/** A database freshly migrated: one table, seeded with two rows. */
$migrated = static fn(): PDO => Databases::made(
    'sqlite::memory:',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)',
    "INSERT INTO users (name) VALUES ('a'), ('b')",
);

/** What MySQL answers of a database whose one table's counter stands at this value. */
$mysql = static fn(string $counter): AnsweredStatements => new AnsweredStatements([
    "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" => [['users', 'BASE TABLE']],
    'SELECT * FROM `users`' => [['1', 'a']],
    "SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES\nWHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT IS NOT NULL" => [['users', $counter]],
]);

it('shows two databases alike where every table holds the same rows', function () use ($migrated): void {
    $other = $migrated();
    Databases::ran($other, 'BEGIN', "INSERT INTO users (name) VALUES ('rolled back')", 'ROLLBACK');

    expect(Databases::imaged(PdoStatements::over($migrated()), new SqliteDialect())
        ->isAlike(Databases::imaged(PdoStatements::over($other), new SqliteDialect())))->toBeTrue();
});

it('shows a database apart where a table holds a row more', function () use ($migrated): void {
    $other = $migrated();
    Databases::ran($other, "INSERT INTO users (name) VALUES ('kept')");

    expect(Databases::imaged(PdoStatements::over($migrated()), new SqliteDialect())
        ->isAlike(Databases::imaged(PdoStatements::over($other), new SqliteDialect())))->toBeFalse();
});

it('shows a database apart where a counter stands elsewhere, though its rows are the same', function () use ($mysql): void {
    $image = Databases::imaged($mysql('3'), new MySqlDialect());

    expect($image->isAlike(Databases::imaged($mysql('4'), new MySqlDialect())))->toBeFalse()
        ->and($image->isAlike(Databases::imaged($mysql('3'), new MySqlDialect())))->toBeTrue()
        ->and($image->counters())->toBe(['`users`' => '3']);
});

it('reads back the image it wrote', function () use ($mysql): void {
    $image = Databases::imaged($mysql('3'), new MySqlDialect());

    expect(Image::read(Node::decode($image->written()->line())))->toEqual($image);
});

it('shows a database alike whichever order it lists its tables and counters in', function (): void {
    $listed = static fn(bool $reversed): AnsweredStatements => new AnsweredStatements([
        "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" => $reversed
            ? [['posts', 'BASE TABLE'], ['users', 'BASE TABLE']]
            : [['users', 'BASE TABLE'], ['posts', 'BASE TABLE']],
        'SELECT * FROM `users`' => [['1', 'a']],
        'SELECT * FROM `posts`' => [['1', 'b']],
        "SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES\nWHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT IS NOT NULL" => $reversed
            ? [['posts', '2'], ['users', '2']]
            : [['users', '2'], ['posts', '2']],
    ]);
    $image = Databases::imaged($listed(reversed: false), new MySqlDialect());
    $reversed = Databases::imaged($listed(reversed: true), new MySqlDialect());

    expect($image->isAlike($reversed))->toBeTrue();
});
