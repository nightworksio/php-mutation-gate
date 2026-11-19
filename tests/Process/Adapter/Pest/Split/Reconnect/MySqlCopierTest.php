<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\CopyName;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\HeldConnections;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Reach;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Tests\Support\Servers;

$holds = [
    'holds:src/Adapter/Pest/Split/Reconnect/MySqlCopier.php',
];

it('holds no state a copy lacks in a fresh MySQL session, and names its settings', function (): void {
    $copier = HeldConnections::copier(Driver::MySql);
    $session = Servers::session(Driver::MySql);
    $settings = $copier->settings($session);

    expect($copier->holdsState($session))->toBeFalse()
        ->and(is_array($settings) ? array_column($settings, 0) : $settings)->toContain('sql_mode');
})->skip(fn(): bool => Servers::missing(Driver::MySql), 'no MySQL server runs')->group(...$holds);

it('holds state where a MySQL session holds what a copy of the database would lack', function (string $statement): void {
    expect(HeldConnections::copier(Driver::MySql)->holdsState(Servers::session(Driver::MySql, $statement)))->toBeTrue();
})->with([
    'a temporary table' => ['CREATE TEMPORARY TABLE scratch (id INT) ENGINE=InnoDB'],
    'a user variable' => ['SET @kept = 1'],
    'a lock it took' => ["DO GET_LOCK('pmg', 0)"],
    'a statement prepared by name' => ["PREPARE named FROM 'SELECT 1'"],
])->skip(fn(): bool => Servers::missing(Driver::MySql), 'no MySQL server runs')->group(...$holds);

it('holds state in every MariaDB session whose Performance Schema is off, as it lists none of what a session keeps', function (): void {
    expect(HeldConnections::copier(Driver::MariaDb)->holdsState(Servers::session(Driver::MariaDb)))->toBeTrue();
})->skip(fn(): bool => Servers::missing(Driver::MariaDb), 'no MariaDB server runs')->group(...$holds);

it('copies a MySQL database as its committed state stands, its counters, foreign keys and generated columns, and drops the copy', function (): void {
    expect(Servers::copied(
        Driver::MySql,
        'SELECT m.id, o.name, m.cents, m.doubled FROM money m JOIN owner o ON o.id = m.owner',
        'CREATE TABLE owner (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20))',
        'CREATE TABLE money (id INT AUTO_INCREMENT PRIMARY KEY, owner INT, cents INT, doubled INT AS (cents * 2), FOREIGN KEY (owner) REFERENCES owner (id))',
        "INSERT INTO owner (name) VALUES ('ada')",
        'INSERT INTO money (owner, cents) VALUES (1, 21)',
    ))->toBe([[['1', 'ada', '21', '42']], false]);
})->skip(fn(): bool => Servers::missing(Driver::MySql), 'no MySQL server runs')->group(...$holds);

it('copies a MariaDB database as its committed state stands, and drops the copy', function (): void {
    expect(Servers::copied(Driver::MariaDb, 'SELECT id, cents FROM money', 'CREATE TABLE money (id INT AUTO_INCREMENT PRIMARY KEY, cents INT)', 'INSERT INTO money (cents) VALUES (21)'))
        ->toBe([[['1', '21']], false]);
})->skip(fn(): bool => Servers::missing(Driver::MariaDb), 'no MariaDB server runs')->group(...$holds);

it('copies no MySQL database holding a view, a trigger, a routine or an event', function (): void {
    expect(Servers::copied(Driver::MySql, 'SELECT 1', 'CREATE TABLE money (cents INT)', 'CREATE VIEW cents AS SELECT cents FROM money'))
        ->toBe(['no copy', false]);
})->skip(fn(): bool => Servers::missing(Driver::MySql), 'no MySQL server runs')->group(...$holds);

it('copies as many rows of a MySQL table whose every column is generated as it holds, each as the copy generates it, and none of one that holds none', function (): void {
    expect(Servers::copied(
        Driver::MySql,
        'SELECT (SELECT COUNT(*) FROM fixed), (SELECT SUM(one) FROM fixed), (SELECT COUNT(*) FROM unused)',
        'CREATE TABLE fixed (one INT AS (1) STORED)',
        'CREATE TABLE unused (one INT AS (2) STORED)',
        'INSERT INTO fixed () VALUES (), (), ()',
    ))->toBe([[['3', '3', '0']], false]);
})->skip(fn(): bool => Servers::missing(Driver::MySql), 'no MySQL server runs')->group(...$holds);

it('drops a MySQL copy it could not fill, and says why', function (): void {
    $database = Servers::made(Driver::MySql, Servers::own('pmg_unfilled'), 'CREATE TABLE money (cents INT)', 'INSERT INTO money (cents) VALUES (21)');
    $user = Servers::own('pmg_filler');
    Servers::opened(
        Driver::MySql,
        'mysql',
        sprintf("DROP USER IF EXISTS '%s'@'%%'", $user),
        sprintf("CREATE USER '%s'@'%%'", $user),
        sprintf("GRANT ALL ON `%s`.* TO '%s'@'%%'", str_replace('_', '\\_', $database), $user),
        sprintf("GRANT CREATE, DROP ON *.* TO '%s'@'%%'", $user),
    );
    $reach = new Reach(
        Driver::MySql,
        Servers::connection(Driver::MySql, $database, $user),
        $database,
        apart: false,
        where: 'the default connection',
        elsewhere: static fn(string $other): Connection => Servers::connection(Driver::MySql, $other, $user),
    );

    try {
        HeldConnections::copier(Driver::MySql)->copied(Servers::connection(Driver::MySql, $database, $user), $reach, 'f1', '');
        $refused = '';
    } catch (PDOException $failed) {
        $refused = $failed->getMessage();
    }

    expect($refused)->toContain('INSERT command denied')
        ->and(Servers::holds(Driver::MySql, CopyName::of($database, 'f1')))->toBeFalse();
})->skip(fn(): bool => Servers::missing(Driver::MySql), 'no MySQL server runs')->group(...$holds);
