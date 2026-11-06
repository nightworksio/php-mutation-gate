<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Recheck\RecheckShare;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$money = static fn(): RecheckShare => RecheckShare::of(
    Paths::of(Path::of('src/Money.php')),
    Paths::of(Path::of('src/Cart.php')),
    Verdicts::mutant('src/Money.php:7', 'Plus', MutatorFamily::None, '-1+2'),
);

it('reads back the files it ran, those it did not reach and every mutant it made', function () use ($money): void {
    $read = RecheckShare::decode($money()->encode());

    expect($read)->toEqual($money())
        ->and(array_map(static fn(Path $file): string => $file->value(), [...$read->ran()]))->toBe(['src/Money.php'])
        ->and(array_map(static fn(Path $file): string => $file->value(), [...$read->unreached()]))->toBe(['src/Cart.php'])
        ->and($read->mutants())->toEqual([Verdicts::mutant('src/Money.php:7', 'Plus', MutatorFamily::None, '-1+2')]);
});

it('writes format 1, reads it and refuses the formats either side of it', function () use ($money): void {
    $text = static fn(int $format): string => sprintf(
        '{"format": %d, "ran": ["src/Money.php"], "unreached": [], "mutants": []}',
        $format,
    );
    $ran = static fn(string $file): array => array_map(
        static fn(Path $path): string => $path->value(),
        [...RecheckShare::decode($file)->ran()],
    );

    expect(Node::decode($money()->encode())->field('format')->integer())->toBe(1)
        ->and($ran($text(1)))->toBe(['src/Money.php'])
        ->and($ran($text(0)))->toBe([])
        ->and($ran($text(2)))->toBe([]);
});

it('joins two shares into one that did what both did', function () use ($money): void {
    $price = RecheckShare::of(
        Paths::of(Path::of('src/Price.php')),
        Paths::of(Path::of('src/Tax.php')),
        Verdicts::mutant('src/Price.php:9', 'Minus', MutatorFamily::None, ''),
    );
    $both = $money()->and($price);

    expect(array_map(static fn(Path $file): string => $file->value(), [...$both->ran()]))->toBe(['src/Money.php', 'src/Price.php'])
        ->and(array_map(static fn(Path $file): string => $file->value(), [...$both->unreached()]))->toBe(['src/Cart.php', 'src/Tax.php'])
        ->and($both->mutants())->toEqual([...$money()->mutants(), ...$price->mutants()])
        ->and(RecheckShare::none()->and($price))->toEqual($price);
});

it('reads a file of another format, not whole or not JSON as a share that reached nothing', function (string $text): void {
    expect(RecheckShare::decode($text))->toEqual(RecheckShare::none());
})->with([
    'another format' => ['{"format": 2, "ran": [], "unreached": [], "mutants": []}'],
    'no format' => ['{"ran": [], "unreached": [], "mutants": []}'],
    'no mutants' => ['{"format": 1, "ran": ["src/Money.php"], "unreached": []}'],
    'a mutant cut short' => ['{"format": 1, "ran": [], "unreached": [], "mutants": [{"id": "x"}]}'],
    'a path that is no text' => ['{"format": 1, "ran": [7], "unreached": [], "mutants": []}'],
    'not JSON' => ['{"format": 1,'],
]);
