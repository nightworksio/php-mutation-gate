<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\SchemaSwitch;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('keeps the mutant in force of each file, and turns one file\'s off alone', function (): void {
    SchemaSwitch::on('a money test', 1);
    SchemaSwitch::on('a tax test', 2);
    $both = [SchemaSwitch::is('a money test', 1), SchemaSwitch::is('a tax test', 2), SchemaSwitch::is('a tax test', 1)];
    SchemaSwitch::off('a tax test');
    $money = [SchemaSwitch::is('a money test', 1), SchemaSwitch::is('a tax test', 2)];
    SchemaSwitch::off('a money test');

    expect([$both, $money, SchemaSwitch::is('a money test', 1)])->toBe([[true, true, false], [true, false], false]);
});

it('serves the schema a mutant\'s run was told, its switch in force by the schema\'s name, and its own copy where it was told none it can serve', function (string $schema, string $on, bool $served): void {
    $file = sprintf('%s/schema.php', Scratch::directory());
    file_put_contents($file, '<?php');

    $inForce = SchemaSwitch::inForce('/original.php', '/copy.php', $schema === 'there' ? $file : $schema, $on);
    $switched = SchemaSwitch::is('schema', (int) $on);
    SchemaSwitch::off('schema');

    expect($inForce)->toBe($served ? $file : '/copy.php')
        ->and($switched)->toBe($served);
})->with([
    'a schema there and an id' => ['there', '7', true],
    'a schema there and the first id' => ['there', '1', true],
    'no schema' => ['', '7', false],
    'a schema not there' => ['/nowhere/schema.php', '7', false],
    'no id' => ['there', '', false],
    'an id of nought' => ['there', '0', false],
    'an id that is no whole number' => ['there', '7 or so', false],
]);
