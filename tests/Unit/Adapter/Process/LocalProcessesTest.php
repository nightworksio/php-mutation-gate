<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\ErrorOutputLog;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Adapter\Process\Saying;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Runner\EnvironmentRead;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\GaugeFake;
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

it('says once a minute, on the clock it says by, which process runs longest and what its watch tells of it', function (): void {
    $file = sprintf('%s/said.log', Scratch::directory());
    $watch = new readonly class implements ProcessWatch {
        public function look(): void
        {
        }

        public function progress(): string
        {
            return 'halfway';
        }
    };

    new LocalProcesses(new SystemClock(), Saying::on(ErrorOutputLog::to($file), new TickingClock('2026-10-26T10:00:00Z', 30), GaugeFake::silent()))
        ->run(ProcessCommand::of(Scratch::directory(), PHP_BINARY, '-r', 'usleep(300000);'), $watch);
    $said = is_file($file) ? explode("\n", (string) file_get_contents($file)) : [];

    expect($said[0])->toStartWith('mutation-gate: 0 of 1 processes ended, 1 running; the longest for ')
        ->and($said[0])->toEndWith(sprintf('%s -r usleep(300000);; halfway', PHP_BINARY));
});

it('starts a test process that sees neither the runtime token, the artifact service, nor its handoff, whatever the gate inherited', function (): void {
    $handed = [
        'ACTIONS_RUNTIME_TOKEN' => 'runtime-token',
        'ACTIONS_RESULTS_URL' => 'https://results.example/',
        'MUTATION_GATE_ARTIFACT_SERVICE' => '/runner/temp/mutation-gate-artifact-service.json',
    ];

    foreach ($handed as $name => $value) {
        putenv(sprintf('%s=%s', $name, $value));
    }

    $inherited = getenv();
    $seen = 'echo json_encode([getenv("ACTIONS_RUNTIME_TOKEN"), getenv("ACTIONS_RESULTS_URL"), getenv("MUTATION_GATE_ARTIFACT_SERVICE"), getenv("PATH") !== false]);';
    $ran = new LocalProcesses(new SystemClock())->run(
        ProcessCommand::of(Scratch::directory(), PHP_BINARY, '-r', $seen)
            ->with(EnvironmentRead::of(Withholding::of(Withheld::standard(), $inherited))),
    );

    foreach (array_keys($handed) as $name) {
        putenv($name);
    }

    expect($inherited)->toHaveKey('ACTIONS_RUNTIME_TOKEN')
        ->and($ran->succeeded())->toBeTrue()
        ->and(trim($ran->printed()))->toBe('[false,false,false,true]');
});
