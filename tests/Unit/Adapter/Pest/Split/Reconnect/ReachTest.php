<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Reach;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Core\NotGiven;

it('reaches an SQLite database in memory only where it has no connection of its own to it', function (): void {
    $none = static fn(): NotGiven => NotGiven::value();
    $file = Connection::of(Driver::Sqlite, 'sqlite:/tmp/testing.sqlite', '', '', [], []);

    expect(new Reach(Driver::Sqlite, NotGiven::value(), ':memory:', apart: false, where: 'the default connection', elsewhere: $none)->inMemory())->toBeTrue()
        ->and(new Reach(Driver::Sqlite, $file, 'testing', apart: false, where: 'the default connection', elsewhere: $none)->inMemory())->toBeFalse()
        ->and(new Reach(Driver::MySql, NotGiven::value(), 'testing', apart: false, where: 'the default connection', elsewhere: $none)->inMemory())->toBeFalse();
});

it('connects to another database on the same server as its config says', function (): void {
    $asked = [];
    $reach = new Reach(Driver::MySql, NotGiven::value(), 'testing', apart: true, where: 'the default connection', elsewhere: static function (string $database) use (&$asked): NotGiven {
        $asked[] = $database;

        return NotGiven::value();
    });

    expect($reach->elsewhere('testing_mg_a1'))->toBeInstanceOf(NotGiven::class)
        ->and($asked)->toBe(['testing_mg_a1']);
});
