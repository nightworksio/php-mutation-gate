<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Baseline;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Databases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

/**
 * A database freshly migrated in a file of its own, and how to reach it.
 *
 * @return array{PDO, Connection}
 */
$migrated = static function (): array {
    $file = sprintf('%s/testing.sqlite', Scratch::directory());
    $pdo = Databases::made(sprintf('sqlite:%s', $file), 'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

    return [$pdo, Connection::of(Driver::Sqlite, sprintf('sqlite:%s', $file), '', '', [], [])];
};

it('holds while every database stands as it did when it was taken', function () use ($migrated): void {
    [, $first] = $migrated();
    [, $second] = $migrated();
    $baseline = Baseline::taken([$first, $second]);

    expect($baseline instanceof Baseline && $baseline->holds())->toBeTrue();
});

it('holds no more once one of its databases holds a row more', function () use ($migrated): void {
    [, $first] = $migrated();
    [$pdo, $second] = $migrated();
    $baseline = Baseline::taken([$first, $second]);
    Databases::ran($pdo, "INSERT INTO users (name) VALUES ('committed')");

    expect($baseline instanceof Baseline && $baseline->holds())->toBeFalse();
});

it('takes no baseline of no database, or of one it cannot reach', function (): void {
    $unreachable = Connection::of(Driver::Sqlite, sprintf('sqlite:%s/missing/testing.sqlite', Scratch::directory()), '', '', [], []);

    expect(Baseline::taken([]))->toEqual(NotGiven::value())
        ->and(Baseline::taken([$unreachable]))->toEqual(NotGiven::value());
});

it('reads back the baseline it wrote, and none from a list of no database', function () use ($migrated): void {
    [, $connection] = $migrated();
    $baseline = Baseline::taken([$connection]);

    expect($baseline instanceof Baseline ? Baseline::read(Node::decode($baseline->written()->line())) : $baseline)->toEqual($baseline)
        ->and(Baseline::read(Node::decode('[]')))->toEqual(NotGiven::value());
});
