<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\Running;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('says why a program in a directory that is not there is not started', function (): void {
    $ran = Running::start(ProcessCommand::of('/nowhere/at/all', PHP_BINARY, '-v'), 0, WorkerSlot::alone(), 0.0);

    expect($ran)->toEqual(Ran::finished(succeeded: false, output: 'The provided cwd "/nowhere/at/all" does not exist.')
        ->took(Seconds::of(0.0)));
});
