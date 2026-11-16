<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\EndedProcesses;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;

it('answers how each command ended as it was handed, stopped or not', function (): void {
    $ends = ProcessEnds::of(Ran::exited(0, 'first'), Ran::exited(1, 'second'));
    $ended = EndedProcesses::of($ends);
    $ended->stop();

    expect($ended->ends())->toBe($ends);
});
