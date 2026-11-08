<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Unchanged;

it('is unchanged where the mutated code gives the original value, and changed where it gives another', function (): void {
    $a = 2;
    $b = 2;

    expect([
        Unchanged::by($a + $b, static fn(): int => $a * $b),
        Unchanged::by($a + 1, static fn(): int => $a - 1),
        Unchanged::by(1, static fn(): float => 1.0),
    ])->toBe([true, false, false]);
});

it('is changed where the mutated code throws or errs, and keeps its error from the run', function (): void {
    $zero = 0;
    $values = ['a' => 1];
    $key = str_repeat('b', random_int(1, 1));

    expect([
        Unchanged::by(0, static fn(): int => intdiv(1, $zero)),
        Unchanged::by(null, static fn(): mixed => $values[$key]),
    ])->toBe([false, false])
        ->and(error_get_last())->toBeNull();
});

it('is changed, without running the code, where a variable it reads holds an object', function (): void {
    $ran = false;
    $object = new ArrayObject();
    $nested = [[new ArrayObject()]];
    $long = range(1, 65);
    $short = range(1, 64);

    expect([
        Unchanged::by(1, static function () use ($object, &$ran): int {
            $ran = true;

            return count($object) + 1;
        }),
        Unchanged::by(1, static fn(): int => count($nested)),
        Unchanged::by(1, static fn(): int => count($long) - 64),
        Unchanged::by(1, static fn(): int => count($short) - 63),
    ])->toBe([false, false, false, true])
        ->and($ran)->toBeFalse();
});
