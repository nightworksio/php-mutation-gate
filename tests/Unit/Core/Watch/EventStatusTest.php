<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Watch\EventStatus;

it('says what a run reported of a mutant, and nothing of one a marker ignored', function (): void {
    $said = [];

    foreach (MutantStatus::cases() as $status) {
        $event = EventStatus::of($status);
        $said[$status->name] = $event instanceof EventStatus ? $event->value : null;
    }

    expect($said)->toEqual([
        'Killed' => 'killed',
        'KilledByStaticAnalysis' => 'killed',
        'Errored' => 'killed',
        'OutOfMemory' => 'killed',
        'Survived' => 'survived',
        'Uncovered' => 'survived',
        'TimedOut' => 'timeout',
        'Skipped' => 'timeout',
        'Unjudged' => 'pending',
        'FailsUnmutated' => 'pending',
        'NotReached' => 'pending',
        'Equivalent' => 'equivalent',
        'IgnoredByMarker' => null,
    ])
        ->and(EventStatus::of(MutantStatus::IgnoredByMarker))->toBeInstanceOf(NotGiven::class);
});
