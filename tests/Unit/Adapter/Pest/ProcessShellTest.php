<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell;
use NightWorksIO\MutationGate\Core\Runner\EnvironmentRead;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Fakes\ProcessesFake;

it('runs a command through the processes, in its directory, with its environment and deadline, and ends as it ends', function (): void {
    $processes = new ProcessesFake(static fn(): Ran => Ran::exited(3, 'no'));
    $command = Command::of(PHP_BINARY, '-v')->with(['GATE' => 'on'])->within(Seconds::of(4.0));

    $ran = new ProcessShell($processes, '/project')->run($command);

    expect($ran)->toEqual(Ran::exited(3, 'no'))
        ->and($processes->ran())->toEqual([
            ProcessCommand::of('/project', PHP_BINARY, '-v')->with(EnvironmentRead::of($command->environment()))->within(Seconds::of(4.0)),
        ]);
});

it('runs a command in another directory once moved there', function (): void {
    $processes = new ProcessesFake(static fn(ProcessCommand $command): Ran => Ran::exited(0, $command->directory()));

    expect(new ProcessShell($processes, '/')->in('/elsewhere')->run(Command::of(PHP_BINARY, '-v'))->output())
        ->toBe('/elsewhere');
});

it('runs commands side by side through the processes, each told its place, with its ends in the order given', function (): void {
    $processes = new ProcessesFake(static fn(ProcessCommand $command): Ran => Ran::exited(0, (string) iterator_to_array($command->environment(), preserve_keys: true)['TEST_TOKEN']));
    $command = Command::of(PHP_BINARY, '-v');

    $ends = new ProcessShell($processes, '/project')
        ->sideBySide(WorkerSlots::of(ProcessCount::of(2), 'run'), Unlimited::time(), $command, $command, $command);

    expect(array_map(static fn(Ran $ran): string => $ran->output(), [...$ends]))->toBe(['1', '2', '1'])
        ->and(array_map(static fn(ProcessCommand $ran): string => $ran->directory(), $processes->ran()))
        ->toBe(['/project', '/project', '/project']);
});
