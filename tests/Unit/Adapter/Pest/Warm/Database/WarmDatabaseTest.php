<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Baseline;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\WarmDatabase;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Databases;
use NightWorksIO\MutationGate\Tests\Support\LaravelTesting;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

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
    $before = spl_autoload_functions();
    WarmDatabase::begun();

    expect(spl_autoload_functions())->toBe($before);
});

it('takes the baseline the moment RefreshDatabase has migrated, and reports it at once, to its owner alone', function (): void {
    $file = LaravelTesting::serving();
    $report = sprintf('%s/1.database', Scratch::directory());
    $spies = LaravelTesting::spiesOf(static function () use ($report): void {
        WarmDatabase::handed(NotGiven::value(), $report);
        WarmDatabase::begun();
        RefreshDatabaseState::migrate();
        class_exists(LaravelTesting::TRANSACTIONS);
    });
    $mode = fileperms($report) & 0o777;

    expect($spies)->toBe(1)
        ->and($mode)->toBe(0o600)
        ->and(WarmDatabase::after(NotGiven::value(), $report))
        ->toEqual(Baseline::taken([Connection::of(Driver::Sqlite, sprintf('sqlite:%s', $file), '', '', [], [])]));
});

it('reports no baseline where RefreshDatabase has not migrated as it asks for its transactions', function (): void {
    LaravelTesting::serving();
    $report = sprintf('%s/1.database', Scratch::directory());
    LaravelTesting::spiesOf(static function () use ($report): void {
        WarmDatabase::handed(NotGiven::value(), $report);
        WarmDatabase::begun();
        class_exists(LaravelTesting::TRANSACTIONS);
    });

    expect(is_file($report))->toBeFalse();
});

it('starts migrated where the databases stand as the baseline does', function (): void {
    $file = LaravelTesting::serving();
    $taken = Baseline::taken([Connection::of(Driver::Sqlite, sprintf('sqlite:%s', $file), '', '', [], [])]);
    $spies = LaravelTesting::spiesOf(static function () use ($taken): void {
        WarmDatabase::handed($taken, sprintf('%s/1.database', Scratch::directory()));
        WarmDatabase::begun();
    });

    expect($taken)->toBeInstanceOf(Baseline::class)
        ->and(RefreshDatabaseState::isMigrated())->toBeTrue()
        ->and($spies)->toBe(0);
});
