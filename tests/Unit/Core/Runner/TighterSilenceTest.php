<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('lists the hang-prone mutators by the names Pest, the default set and Infection give them, with a floor of seven seconds', function (): void {
    $standard = TighterSilence::standard();

    expect([...$standard])->toBe(TighterSilence::MUTATORS)
        ->and($standard->floor())->toEqual(Seconds::of(7.0))
        ->and([...TighterSilence::none()])->toBe([]);
});

it('keeps the floor of a mutator it lists to the lower of the two, and any other to its own', function (string $mutator, float $floor): void {
    expect(TighterSilence::of(Seconds::of(7.0), 'RemoveArrayItem')->floorOf(RunnerMutatorName::of($mutator), Seconds::of(10.0)))
        ->toEqual(Seconds::of($floor))
        ->and(TighterSilence::of(Seconds::of(7.0), 'RemoveArrayItem')->floorOf(RunnerMutatorName::of($mutator), Seconds::of(5.0)))
        ->toEqual(Seconds::of(5.0));
})->with([
    'a class name' => ['Runner\Mutators\RemoveArrayItem', 7.0],
    'the default set\'s name' => ['default/RemoveArrayItem', 7.0],
    'a short name' => ['RemoveArrayItem', 7.0],
    'another mutator' => ['Runner\Mutators\RemoveArrayItemOrNot', 10.0],
]);
