<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\MySqlDialect;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\PostgresDialect;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\SqliteDialect;
use NightWorksIO\MutationGate\Core\NotGiven;

it('names each database PDO reaches by host and port, or by MySQL\'s socket, or by its file', function (): void {
    expect(Driver::MySql->dsn('db', '3306', 'testing_test_1', '', ''))->toBe('mysql:host=db;port=3306;dbname=testing_test_1')
        ->and(Driver::MariaDb->dsn('db', '3306', 'testing', '/run/mysqld.sock', ''))->toBe('mysql:unix_socket=/run/mysqld.sock;dbname=testing')
        ->and(Driver::Postgres->dsn('db', '5432', 'testing', '', ''))->toBe('pgsql:host=db;port=5432;dbname=testing')
        ->and(Driver::Postgres->dsn('db', '5432', 'testing', '', 'require'))->toBe('pgsql:host=db;port=5432;dbname=testing;sslmode=require')
        ->and(Driver::Sqlite->dsn('', '', '/srv/database.sqlite', '', ''))->toBe('sqlite:/srv/database.sqlite');
});

it('names no SQLite database held in memory, which no other process can reach', function (): void {
    expect(Driver::Sqlite->dsn('', '', ':memory:', '', ''))->toEqual(NotGiven::value())
        ->and(Driver::Sqlite->dsn('', '', 'file:testing?mode=memory&cache=shared', '', ''))->toEqual(NotGiven::value());
});

it('reads each database\'s tables and counters as its kind lists them', function (): void {
    expect(Driver::MySql->dialect())->toEqual(new MySqlDialect())
        ->and(Driver::MariaDb->dialect())->toEqual(new MySqlDialect())
        ->and(Driver::Postgres->dialect())->toEqual(new PostgresDialect())
        ->and(Driver::Sqlite->dialect())->toEqual(new SqliteDialect());
});
