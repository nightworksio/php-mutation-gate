<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Laravel;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Databases;
use NightWorksIO\MutationGate\Tests\Support\LaravelTesting;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('tells whether RefreshDatabase holds its database migrated, and tells it so', function (): void {
    LaravelTesting::left();
    $before = Laravel::migrated();
    Laravel::markMigrated();

    expect($before)->toBeFalse()
        ->and(Laravel::migrated())->toBeTrue()
        ->and(RefreshDatabaseState::isMigrated())->toBeTrue();
});

it('calls back as RefreshDatabase asks for its transactions\' manager, before any other autoloader can load it, and not before', function (): void {
    $called = 0;
    $first = false;
    $spies = LaravelTesting::spiesOf(static function () use (&$called, &$first): void {
        $before = spl_autoload_functions();
        Laravel::beforeFirstTransaction(static function () use (&$called): void {
            $called++;
        });
        $first = ! in_array(spl_autoload_functions()[0], $before, strict: true);
        class_exists('Illuminate\Foundation\Testing\RefreshDatabase');
        class_exists(LaravelTesting::TRANSACTIONS);
    });

    expect($called)->toBe(1)
        ->and($spies)->toBe(1)
        ->and($first)->toBeTrue();
});

it('reaches again each connection the application holds open outside a transaction', function (): void {
    $file = LaravelTesting::serving();

    expect(Laravel::connections())->toEqual([Connection::of(Driver::Sqlite, sprintf('sqlite:%s', $file), '', '', [], [])]);
});

it('reaches none where a connection is inside a transaction, or there is no application', function (): void {
    $file = sprintf('%s/testing.sqlite', Scratch::directory());
    $pdo = Databases::made(sprintf('sqlite:%s', $file), 'CREATE TABLE users (id INTEGER PRIMARY KEY)');
    LaravelTesting::connected($pdo, $file);
    Databases::ran($pdo, 'BEGIN');
    $inside = Laravel::connections();
    Databases::ran($pdo, 'ROLLBACK');
    LaravelTesting::left();

    expect($inside)->toEqual(NotGiven::value())
        ->and(Laravel::connections())->toEqual(NotGiven::value());
});
