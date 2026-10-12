<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('says a program that cannot be started did not succeed, and why', function (): void {
    $ran = new LocalProcesses(new SystemClock())->run(ProcessCommand::of('/nowhere/at/all', PHP_BINARY, '-v'));

    expect($ran->succeeded())->toBeFalse()
        ->and($ran->output())->toContain('/nowhere/at/all');
});
