<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\MySqlDialect;
use NightWorksIO\MutationGate\Tests\Support\AnsweredStatements;
use NightWorksIO\MutationGate\Tests\Support\Databases;

/** What MySQL answers of a database whose tables are `users` and `odd``name`, refusing these statements. */
$database = static fn(string ...$refused): AnsweredStatements => new AnsweredStatements([
    "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" => [['users', 'BASE TABLE'], ['odd`name', 'BASE TABLE']],
    "SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES\nWHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT IS NOT NULL" => [
        ['users', '3'],
        ['odd`name', '1'],
    ],
], array_values($refused));

it('lists each table, quoted as MySQL quotes a name', function () use ($database): void {
    expect(Databases::tables(new MySqlDialect(), $database()))->toBe(['`users`', '`odd``name`']);
});

it('reads each table\'s counter live, asking MySQL not to answer from its cache', function () use ($database): void {
    $statements = $database();

    expect(Databases::counters(new MySqlDialect(), $statements))->toBe(['`users`' => '3', '`odd``name`' => '1'])
        ->and($statements->ran())->toBe(['SET SESSION information_schema_stats_expiry = 0']);
});

it('reads the counters of MariaDB, which has no cache to ask about', function () use ($database): void {
    $statements = $database('SET SESSION information_schema_stats_expiry = 0');

    expect(Databases::counters(new MySqlDialect(), $statements))->toBe(['`users`' => '3', '`odd``name`' => '1'])
        ->and($statements->ran())->toBe([]);
});

it('sets back only the counters that moved, waiting for no lock long', function () use ($database): void {
    $statements = $database();

    Databases::restored(
        new MySqlDialect(),
        $statements,
        ['`users`' => '3', '`odd``name`' => '1', '`gone`' => '7'],
        ['`users`' => '5', '`odd``name`' => '1'],
    );

    expect($statements->ran())->toBe(['SET SESSION lock_wait_timeout = 10', 'ALTER TABLE `users` AUTO_INCREMENT = 3']);
});
