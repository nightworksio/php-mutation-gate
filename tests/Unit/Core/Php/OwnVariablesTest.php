<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\OwnVariables;
use NightWorksIO\MutationGate\Tests\Support\Assigning;

it('holds each variable assigned what runs nothing, whatever order its statements stand in', function (): void {
    $own = Assigning::own('$all = [...$cheap, ...$dear];', '$cheap = [[1]];', '$dear = [[2]];', '$money = Money::of(5);');

    expect(array_map(
        static fn(string $name): bool => $own->has(new PhpToken(T_VARIABLE, $name)),
        ['$all', '$cheap', '$dear', '$money', '$other'],
    ))->toBe([true, true, true, false, false]);
});

it('holds none of variables that only assign each other, or one assigned what reads a variable not its own', function (): void {
    $own = Assigning::own('$a = $b;', '$b = $a;', '$c = [$d];');

    expect(array_map(
        static fn(string $name): bool => $own->has(new PhpToken(T_VARIABLE, $name)),
        ['$a', '$b', '$c'],
    ))->toBe([false, false, false]);
});

it('holds no token that is not a variable, though it spells one', function (): void {
    expect(Assigning::own('$rows = [[1]];')->has(new PhpToken(T_CONSTANT_ENCAPSED_STRING, '$rows')))->toBeFalse()
        ->and(OwnVariables::none()->has(new PhpToken(T_VARIABLE, '$rows')))->toBeFalse();
});
