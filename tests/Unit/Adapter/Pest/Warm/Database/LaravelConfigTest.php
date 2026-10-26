<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\LaravelConfig;
use NightWorksIO\MutationGate\Core\NotGiven;

it('reaches a MySQL database by the first host the config lists, with the attributes PDO takes', function (): void {
    expect(LaravelConfig::connection([
        'driver' => 'mysql',
        'host' => ['primary', 'replica'],
        'port' => 3306,
        'database' => 'testing_test_2',
        'username' => 'gate',
        'password' => 's3cret',
        'options' => [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_CASE => 'lower', PDO::ATTR_PERSISTENT => false, 'named' => 1, PDO::ATTR_STRINGIFY_FETCHES => [1]],
    ]))->toEqual(Connection::of(
        Driver::MySql,
        'mysql:host=primary;port=3306;dbname=testing_test_2',
        'gate',
        's3cret',
        [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_CASE => 'lower', PDO::ATTR_PERSISTENT => false],
        [],
    ));
});

it('searches the schemas a PostgreSQL config names, by either key Laravel reads', function (): void {
    $config = ['driver' => 'pgsql', 'host' => 'db', 'port' => '5432', 'database' => 'testing', 'username' => 'gate'];

    expect(LaravelConfig::connection([...$config, 'search_path' => 'tenant, "public"']))->toEqual(
        Connection::of(Driver::Postgres, 'pgsql:host=db;port=5432;dbname=testing', 'gate', '', [], ['tenant', 'public']),
    )->and(LaravelConfig::connection([...$config, 'schema' => ['tenant', 3]]))->toEqual(
        Connection::of(Driver::Postgres, 'pgsql:host=db;port=5432;dbname=testing', 'gate', '', [], ['tenant']),
    );
});

it('reaches no database held in memory, or of a kind the worker cannot tell', function (): void {
    expect(LaravelConfig::connection(['driver' => 'sqlite', 'database' => ':memory:']))->toEqual(NotGiven::value())
        ->and(LaravelConfig::connection(['driver' => 'sqlsrv', 'database' => 'testing']))->toEqual(NotGiven::value())
        ->and(LaravelConfig::connection([]))->toEqual(NotGiven::value());
});

it('searches no schemas a PostgreSQL config names by neither a list nor text', function (): void {
    $config = ['driver' => 'pgsql', 'host' => 'db', 'port' => '5432', 'database' => 'testing', 'search_path' => 5];

    expect(LaravelConfig::connection($config))->toEqual(
        Connection::of(Driver::Postgres, 'pgsql:host=db;port=5432;dbname=testing', '', '', [], []),
    );
});
