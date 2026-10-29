<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\WorkerVariable;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\ProcessesFake;
use NightWorksIO\MutationGate\Tests\Support\InfectionShells;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();

    foreach (InfectionShells::PROBES as $name) {
        putenv($name);
        unset($_SERVER[$name]);
    }
});

it('answers a process that cannot start as a failure, with the reason', function (): void {
    $ran = new ProcessShell(new LocalProcesses(new SystemClock()), '/nowhere/at/all', [])->run(Command::php('-r', 'echo "never";'));

    expect($ran)->toEqual(Ran::finished(succeeded: false, output: 'The provided cwd "/nowhere/at/all" does not exist.')
        ->took(Seconds::of(0.0)));
});

it('runs a command through the processes, in its directory, with its deadline, and ends as it ends', function (): void {
    $processes = new ProcessesFake(static fn(): Ran => Ran::exited(3, 'no'));

    $ran = new ProcessShell($processes, '/project', [])->run(Command::php('-v')->within(Seconds::of(4.0)));
    [$command] = $processes->ran();

    expect($ran)->toEqual(Ran::exited(3, 'no'))
        ->and($processes->ran())->toHaveCount(1)
        ->and($command->directory())->toBe('/project')
        ->and([...$command->arguments()])->toBe([PHP_BINARY, '-v'])
        ->and($command->deadline())->toEqual(Seconds::of(4.0));
});

it('tells a process only what it must not inherit, that it is no worker, and its PATH, PHP\'s first', function (): void {
    $processes = new ProcessesFake(static fn(): Ran => Ran::exited(0, ''));
    new ProcessShell($processes, '/project', ['HOME' => '/home', 'MUTATION_GATE_PROBE' => 'other run'])->run(Command::php('-v'));
    [$command] = $processes->ran();

    expect(iterator_to_array($command->environment(), preserve_keys: true))->toBe([
        'MUTATION_GATE_PROBE' => false,
        ...array_fill_keys(WorkerVariable::names(), value: false),
        'PATH' => sprintf('%s%s', dirname(PHP_BINARY), PATH_SEPARATOR),
    ]);
});

it('stops a command at its silence limit where it has one, and only at its deadline where it has none', function (): void {
    $processes = new ProcessesFake(static fn(): Ran => Ran::exited(0, ''));
    $silence = SilenceLimit::of(Seconds::of(2.0), '/project/events.txt');

    $shell = new ProcessShell($processes, '/project', []);
    $shell->run(Command::php('-v')->silencedAfter($silence));
    $shell->run(Command::php('-v'));
    [$silenced, $plain] = $processes->ran();

    expect([$silenced->silence(), $plain->silence()])->toEqual([$silence, NotGiven::value()]);
});
