<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Runner\TighterVariables;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('tells a patched runner\'s process its floor and mutators, and reads them back as told', function (): void {
    $tighter = TighterSilence::of(Seconds::of(7.5), 'RemoveArrayItem', 'Foreach_');
    $told = TighterVariables::of($tighter);

    expect($told)->toBe([
        ChildVariable::TighterFloor->value => '7.500000',
        ChildVariable::TighterMutators->value => 'RemoveArrayItem,Foreach_',
    ])
        ->and(TighterVariables::read($told[ChildVariable::TighterFloor->value], $told[ChildVariable::TighterMutators->value]))
        ->toEqual($tighter);
});

it('tells nothing where no mutator is listed', function (): void {
    expect(TighterVariables::of(TighterSilence::none()))->toBe([]);
});

it('reads none where a process was told nothing, or a floor that is no positive number of seconds', function (string|false $floor, string|false $mutators): void {
    expect(TighterVariables::read($floor, $mutators))->toEqual(TighterSilence::none());
})->with([
    'nothing told' => [false, false],
    'no floor' => [false, 'RemoveArrayItem'],
    'no mutators' => ['7', false],
    'a floor of nought' => ['0', 'RemoveArrayItem'],
    'a floor that is no number' => ['seven', 'RemoveArrayItem'],
    'an empty list' => ['7', ''],
]);
