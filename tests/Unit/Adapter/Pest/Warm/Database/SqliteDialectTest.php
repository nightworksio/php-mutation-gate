<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\PdoStatements;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\SqliteDialect;
use NightWorksIO\MutationGate\Tests\Support\AnsweredStatements;
use NightWorksIO\MutationGate\Tests\Support\Databases;

it('lists every table, its counters\' own among them, quoted as SQLite quotes a name', function (): void {
    $pdo = Databases::made(
        'sqlite::memory:',
        'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)',
        'CREATE TABLE "odd""name" (id INTEGER)',
    );

    expect(Databases::tables(new SqliteDialect(), PdoStatements::over($pdo)))->toBe(['"users"', '"sqlite_sequence"', '"odd""name"']);
});

it('has no counter a rolled-back insert leaves moved, and sets nothing back', function (): void {
    $statements = new AnsweredStatements([]);
    Databases::restored(new SqliteDialect(), $statements, ['"users"' => '3'], ['"users"' => '4']);

    expect(Databases::counters(new SqliteDialect(), $statements))->toBe([])
        ->and($statements->ran())->toBe([]);
});
