<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\TestPart;

it('is part 1 of 1 where a run cuts no test files, and whole', function (): void {
    expect(TestPart::whole()->number())->toBe(1)
        ->and(TestPart::whole()->parts())->toBe(1)
        ->and(TestPart::whole()->isWhole())->toBeTrue();
});

it('names its part and how many there are, and is whole only as the one part of one', function (): void {
    expect(TestPart::of(2, 3)->number())->toBe(2)
        ->and(TestPart::of(2, 3)->parts())->toBe(3)
        ->and(TestPart::of(2, 3)->isWhole())->toBeFalse()
        ->and(TestPart::of(1, 1)->isWhole())->toBeTrue();
});

it('holds a part to one there is', function (int $number, int $parts, int $held, int $of): void {
    expect([TestPart::of($number, $parts)->number(), TestPart::of($number, $parts)->parts()])->toBe([$held, $of]);
})->with([
    'past the last part' => [4, 3, 3, 3],
    'before the first' => [0, 3, 1, 3],
    'of no parts' => [1, 0, 1, 1],
]);
