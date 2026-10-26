<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\PostgresDialect;
use NightWorksIO\MutationGate\Tests\Support\AnsweredStatements;
use NightWorksIO\MutationGate\Tests\Support\Databases;

/** What PostgreSQL answers of a database with one table and two sequences, one never drawn from. */
$database = static fn(): AnsweredStatements => new AnsweredStatements([
    "SELECT format('%I.%I', schemaname, tablename) FROM pg_tables\nWHERE schemaname = ANY (current_schemas(false))" => [
        ['public.users'],
    ],
    "SELECT format('%I.%I', schemaname, sequencename), last_value FROM pg_sequences\nWHERE schemaname = ANY (current_schemas(false))" => [
        ['public.users_id_seq', '2'],
        ['public.unused_seq', ''],
    ],
]);

it('lists each table of the schemas the connection searches, as PostgreSQL names it', function () use ($database): void {
    expect(Databases::tables(new PostgresDialect(), $database()))->toBe(['public.users']);
});

it('reads each sequence\'s last value, and none for one never drawn from', function () use ($database): void {
    expect(Databases::counters(new PostgresDialect(), $database()))->toBe(['public.users_id_seq' => '2', 'public.unused_seq' => '']);
});

it('sets a drawn sequence back to its value and restarts one that was never drawn from, leaving the rest', function () use ($database): void {
    $statements = $database();

    Databases::restored(
        new PostgresDialect(),
        $statements,
        ['public.users_id_seq' => '2', 'public.unused_seq' => '', 'public.same_seq' => '4', 'public.gone_seq' => '1'],
        ['public.users_id_seq' => '6', 'public.unused_seq' => '1', 'public.same_seq' => '4'],
    );

    expect($statements->ran())->toBe([
        "SELECT setval('public.users_id_seq', 2, true)",
        'ALTER SEQUENCE public.unused_seq RESTART',
    ]);
});
