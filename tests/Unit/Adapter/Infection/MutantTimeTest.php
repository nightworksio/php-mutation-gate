<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\MutantTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\InfectionMutant;

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

it('gives a mutant\'s run the silence limit of its slowest test, between the floor and Infection\'s timeout, and none where one is untimed or the gate names no floor', function (): void {
    $timed = [InfectionMutant::test('T::a', 1.0), InfectionMutant::test('U::b', 2.0)];
    $before = MutantTime::silence($timed, 300.0);
    putenv(sprintf('%s=1', ChildVariable::MutantFloor->value));

    expect($before)->toEqual(NotGiven::value())
        ->and(MutantTime::silence($timed, 300.0))->toEqual(Seconds::of(11.0))
        ->and(MutantTime::silence($timed, 8.0))->toEqual(Seconds::of(8.0))
        ->and(MutantTime::silence([...$timed, InfectionMutant::untimed('V::c')], 300.0))->toEqual(NotGiven::value())
        ->and(MutantTime::silence([], 300.0))->toEqual(NotGiven::value());
});
