<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;

afterEach(function (): void {
    Scratch::sweep();
});

it('says a program that cannot be started did not succeed, and why', function (): void {
    $ran = new LocalProcesses(new SystemClock())->run(ProcessCommand::of('/nowhere/at/all', PHP_BINARY, '-v'));

    expect($ran->succeeded())->toBeFalse()
        ->and($ran->output())->toContain('/nowhere/at/all');
});

it('starts the commands waiting behind one that cannot be started in the same look, before the time to start them runs out', function (): void {
    $printing = static fn(string $said): ProcessCommand => ProcessCommand::of(Scratch::directory(), PHP_BINARY, '-r', sprintf('echo "%s";', $said));

    $ends = new LocalProcesses(new TickingClock('2026-10-26T10:00:00Z', 1))->sideBySide(
        WorkerSlots::of(ProcessCount::of(3), 'run'),
        Seconds::of(3.5),
        $printing('first'),
        ProcessCommand::of('/nowhere/at/all', PHP_BINARY, '-v'),
        $printing('third'),
    );

    expect(array_map(static fn(Ran $ran): bool => $ran->succeeded(), [...$ends]))->toBe([true, false, true])
        ->and([...$ends][2]->output())->toContain('third');
});
