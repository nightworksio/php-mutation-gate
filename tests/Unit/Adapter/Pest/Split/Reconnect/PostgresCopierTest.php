<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\HeldConnections;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Reach;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Core\NotGiven;

it('copies no PostgreSQL database where its connection names none, or no maintenance database is reached beside it', function (string $database, bool $reached): void {
    $connection = Connection::of(Driver::Postgres, 'pgsql:host=127.0.0.1;port=1;dbname=app', 'postgres', '', [], []);
    $reach = new Reach(
        Driver::Postgres,
        $connection,
        $database,
        apart: false,
        where: 'the default connection',
        elsewhere: static fn(string $other): Connection|NotGiven => $reached && $other === 'postgres' ? $connection : NotGiven::value(),
    );

    try {
        $copied = HeldConnections::copier(Driver::Postgres)->copied($connection, $reach, 'a1b2', '');
    } catch (PDOException $refused) {
        $copied = $refused->getMessage();
    }

    expect($copied)->toBeInstanceOf(NotGiven::class);
})->with([
    'naming no database' => ['', true],
    'reaching no maintenance database' => ['app', false],
]);
