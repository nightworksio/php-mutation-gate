<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\HeldConnections;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Tests\Support\Servers;

$holds = [
    'holds:src/Adapter/Pest/Split/Reconnect/PostgresCopier.php',
];

it('holds no state a copy lacks in a fresh PostgreSQL session, and names its settings', function (): void {
    $copier = HeldConnections::copier(Driver::Postgres);
    $session = Servers::session(Driver::Postgres);
    $settings = $copier->settings($session);

    expect($copier->holdsState($session))->toBeFalse()
        ->and(is_array($settings) ? array_column($settings, 0) : $settings)->toContain('search_path');
})->skip(fn(): bool => Servers::missing(Driver::Postgres), 'no PostgreSQL server runs')->group(...$holds);

it('holds state where a PostgreSQL session holds what a copy of the database would lack', function (string ...$statements): void {
    expect(HeldConnections::copier(Driver::Postgres)->holdsState(Servers::session(Driver::Postgres, ...$statements)))->toBeTrue();
})->with([
    'a temporary table' => ['CREATE TEMPORARY TABLE scratch (id INT)'],
    'an advisory lock' => ['SELECT pg_advisory_lock(1)'],
    'a channel it listens on' => ['LISTEN changes'],
    'a statement prepared by SQL' => ['PREPARE named AS SELECT 1'],
    'a cursor it holds' => ['BEGIN', 'DECLARE held CURSOR WITH HOLD FOR SELECT 1', 'COMMIT'],
])->skip(fn(): bool => Servers::missing(Driver::Postgres), 'no PostgreSQL server runs')->group(...$holds);

it('copies a PostgreSQL database as its committed state stands, and drops the copy', function (): void {
    expect(Servers::copied(Driver::Postgres, 'SELECT id, cents FROM money', 'CREATE TABLE money (id SERIAL PRIMARY KEY, cents INT)', 'INSERT INTO money (cents) VALUES (21)'))
        ->toBe([[['1', '21']], false]);
})->skip(fn(): bool => Servers::missing(Driver::Postgres), 'no PostgreSQL server runs')->group(...$holds);
