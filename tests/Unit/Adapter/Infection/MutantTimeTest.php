<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\MutantTime;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;

afterEach(function (): void {
    putenv(ChildVariable::MutantFloor->value);
});

it('allows a mutant the gate\'s 5 s and three times its tests\' time, between the floor it names and Infection\'s timeout', function (float $tests, float $limit): void {
    putenv(sprintf('%s=10', ChildVariable::MutantFloor->value));

    expect(MutantTime::of($tests, 300.0))->toBe($limit)
        ->and(MutantTime::bounded())->toBeTrue();
})->with([
    'quick tests get the floor' => [0.2, 10.0],
    'tests of ten seconds' => [10.0, 35.0],
    'tests past the most get the most' => [120.0, 300.0],
]);

it('keeps Infection\'s own limit where the gate names no floor, as outside the gate', function (string $floor): void {
    putenv(sprintf('%s=%s', ChildVariable::MutantFloor->value, $floor));

    expect(MutantTime::of(1.0, 300.0))->toBe(10.0)
        ->and(MutantTime::of(0.2, 300.0))->toBe(6.0)
        ->and(MutantTime::bounded())->toBeFalse();
})->with(['nothing' => [''], 'not a number' => ['10s'], 'no seconds' => ['0']]);
