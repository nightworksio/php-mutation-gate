<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Runner\PerTestVariables;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('tells a patched runner\'s process the multiple and the floor, and reads them back as told', function (): void {
    $limit = PerTestLimit::of(4, Seconds::of(2.5));
    $told = PerTestVariables::of($limit);

    expect($told)->toBe([
        ChildVariable::TestMultiple->value => '4',
        ChildVariable::TestFloor->value => '2.500000',
    ])
        ->and(PerTestVariables::read($told[ChildVariable::TestMultiple->value], $told[ChildVariable::TestFloor->value]))
        ->toEqual($limit);
});

it('tells nothing where the limit is off', function (): void {
    expect(PerTestVariables::of(PerTestLimit::off()))->toBe([]);
});

it('reads no limit where a process was told nothing, or a multiple or a floor it cannot read', function (string|false $multiple, string|false $floor): void {
    expect(PerTestVariables::read($multiple, $floor))->toEqual(PerTestLimit::off());
})->with([
    'nothing told' => [false, false],
    'no multiple' => [false, '3'],
    'no floor' => ['3', false],
    'a multiple that is no whole number' => ['2.5', '3'],
    'a negative multiple' => ['-3', '3'],
    'an empty multiple' => ['', '3'],
    'a floor of nought' => ['3', '0'],
]);
