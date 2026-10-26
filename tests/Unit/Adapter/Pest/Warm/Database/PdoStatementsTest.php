<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\PdoStatements;
use NightWorksIO\MutationGate\Tests\Support\Databases;

/** A database holding the rows of `kept`: text, a number, a value that is none, and an empty text. */
$kept = static fn(): PDO => Databases::made(
    'sqlite::memory:',
    'CREATE TABLE kept (name TEXT, size INTEGER)',
    "INSERT INTO kept VALUES ('a', 1), (NULL, 2), ('', 3)",
);

it('reads each row\'s values as text, a value that is none as empty text', function () use ($kept): void {
    expect(Databases::rows(PdoStatements::over($kept()), 'SELECT name, size FROM kept ORDER BY size'))
        ->toBe([['a', '1'], ['', '2'], ['', '3']]);
});

it('reads a number with a fraction as PHP writes it', function () use ($kept): void {
    expect(Databases::rows(PdoStatements::over($kept()), 'SELECT 1.5'))->toBe([['1.5']]);
});

it('digests the rows of a query alike in any order, and a value that is none apart from empty text', function () use ($kept): void {
    $statements = PdoStatements::over($kept());
    $other = $kept();
    Databases::ran($other, 'UPDATE kept SET name = NULL WHERE size = 3');

    expect(Databases::digest($statements, 'SELECT name, size FROM kept ORDER BY size'))
        ->toBe(Databases::digest($statements, 'SELECT name, size FROM kept ORDER BY size DESC'))
        ->and(Databases::digest($statements, 'SELECT name, size FROM kept'))
        ->not->toBe(Databases::digest(PdoStatements::over($other), 'SELECT name, size FROM kept'));
});

it('digests one value holding what joins two apart from those two values', function (): void {
    $two = Databases::made('sqlite::memory:', 'CREATE TABLE kept (a TEXT, b TEXT)', "INSERT INTO kept VALUES ('x', 'y')");
    $statements = PdoStatements::over(Databases::made('sqlite::memory:', 'CREATE TABLE kept (a TEXT)'));
    Databases::run($statements, sprintf('INSERT INTO kept VALUES (%s)', $statements->quoted("x\x1f\x01y")));

    expect(Databases::digest($statements, 'SELECT a FROM kept'))
        ->not->toBe(Databases::digest(PdoStatements::over($two), 'SELECT a, b FROM kept'));
});

it('runs a statement, and quotes a value as a literal the database reads back', function () use ($kept): void {
    $statements = PdoStatements::over($kept());
    Databases::run($statements, sprintf('INSERT INTO kept VALUES (%s, 4)', $statements->quoted("it's")));

    expect(Databases::rows($statements, 'SELECT name FROM kept WHERE size = 4'))->toBe([["it's"]]);
});

it('refuses a query the database cannot run', function () use ($kept): void {
    expect(Databases::refuses(PdoStatements::over($kept()), 'SELECT * FROM missing'))->toBeTrue()
        ->and(Databases::refuses(PdoStatements::over($kept()), 'SELECT * FROM kept'))->toBeFalse();
});

it('reads a boolean as 1 or 0, a stream as its bytes, and what PDO never hands as empty text', function (): void {
    $stream = fopen('php://memory', 'w+b');
    $written = is_resource($stream) && fwrite($stream, "\x00bytes") !== false && rewind($stream);

    expect($written)->toBeTrue()
        ->and(array_map(PdoStatements::text(...), [true, false, 1.5, $stream, new stdClass()]))
        ->toBe(['1', '0', '1.5', "\x00bytes", '']);
});
