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

it('gives a pure expression\'s site its mutated value where its mutant is in force, its original\'s elsewhere, and a split stream\'s choice where none of its file is', function (): void {
    $original = static fn(): string => 'original';
    $mutated = static fn(): string => 'mutated';
    SchemaSwitch::on('a money test', 4);
    $inForce = [SchemaSwitch::value('a money test', 4, $original, $mutated), SchemaSwitch::value('a money test', 5, $original, $mutated)];
    SchemaSwitch::off('a money test');
    $unsplit = SchemaSwitch::value('a money test', 4, $original, $mutated);
    $seen = [];
    SchemaSwitch::splitting(
        static fn(string $file, int $id): bool => $file === 'a money test' && $id === 4,
        static function (string $file, int $id, mixed $value, Closure $mutated) use (&$seen): mixed {
            $seen = [$file, $id, $value, $mutated()];

            return 'chosen';
        },
    );
    $split = SchemaSwitch::value('a tax test', 6, $original, $mutated);
    SchemaSwitch::unsplit();

    expect([$inForce, $unsplit, $split, $seen])->toBe([['mutated', 'original'], 'original', 'chosen', ['a tax test', 6, 'original', 'mutated']]);
});
