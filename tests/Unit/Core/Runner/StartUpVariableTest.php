<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\StartUpVariable;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('tells a patched runner\'s process the start-up its run measured, and reads it back as told', function (): void {
    $told = StartUpVariable::of(LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))->startingIn(Seconds::of(2.25)));

    expect($told)->toBe([ChildVariable::MutantStartUp->value => '2.250000'])
        ->and(StartUpVariable::read($told[ChildVariable::MutantStartUp->value]))->toEqual(Seconds::of(2.25));
});

it('tells nothing where no start-up was measured', function (): void {
    expect(StartUpVariable::of(LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))))->toBe([]);
});

it('reads none where a process was told nothing, or no positive number of seconds', function (string|false $told): void {
    expect(StartUpVariable::read($told))->toBeInstanceOf(Unmeasured::class);
})->with([
    'nothing told' => [false],
    'empty' => [''],
    'no number' => ['soon'],
    'no time' => ['0'],
    'less than none' => ['-1.5'],
]);
