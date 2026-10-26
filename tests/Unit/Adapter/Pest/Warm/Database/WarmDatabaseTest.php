<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Baseline;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\WarmDatabase;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Databases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

/** A baseline of a database freshly migrated in a file of its own. */
$baseline = static function (): Baseline|NotGiven {
    $file = sprintf('%s/testing.sqlite', Scratch::directory());
    Databases::made(sprintf('sqlite:%s', $file), 'CREATE TABLE users (id INTEGER PRIMARY KEY)');

    return Baseline::taken([Connection::of(Driver::Sqlite, sprintf('sqlite:%s', $file), '', '', [], [])]);
};

/** A child's report with this text, in a file of its own. */
$report = static function (string $text): string {
    $file = sprintf('%s/1.database', Scratch::directory());
    file_put_contents($file, $text);

    return $file;
};

it('keeps the baseline a child took, and removes its report, which holds the passwords', function () use ($baseline, $report): void {
    $taken = $baseline();
    $file = $report(sprintf('{"baseline":%s}', $taken instanceof Baseline ? $taken->written()->line() : '[]'));

    expect(WarmDatabase::after(NotGiven::value(), $file))->toEqual($taken)
        ->and(is_file($file))->toBeFalse();
});

it('keeps the baseline it kept after a child that took none, or whose report is not one', function () use ($baseline, $report): void {
    $kept = $baseline();

    expect(WarmDatabase::after($kept, sprintf('%s/never', Scratch::directory())))->toBe($kept)
        ->and(WarmDatabase::after($kept, $report('{"baseline":')))->toBe($kept)
        ->and(WarmDatabase::after($kept, $report('{"baseline":[]}')))->toBe($kept)
        ->and(WarmDatabase::after(NotGiven::value(), $report('[1]')))->toEqual(NotGiven::value());
});

it('readies nothing in a process no worker forked', function (): void {
    WarmDatabase::begun();

    expect(class_exists(RefreshDatabaseState::class, autoload: false))->toBeFalse();
});
