<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Hook\Hook;
use NightWorksIO\MutationGate\Core\Verb;

it('names the gate Composer linked, one a shell runs itself, and one PHP runs', function (): void {
    expect(GateCommand::installed()->gate())->toBe('vendor/bin/mutation-gate')
        ->and(GateCommand::at(Path::of('tools/mutation-gate'))->gate())->toBe('tools/mutation-gate')
        ->and(GateCommand::at(Path::of('mutation-gate.phar'))->gate())->toBe('./mutation-gate.phar')
        ->and(GateCommand::byPhp(Path::of('mutation-gate.phar'))->gate())->toBe('php mutation-gate.phar')
        ->and(GateCommand::at(Path::of('/opt/my tools/mutation-gate'))->gate())->toBe("'/opt/my tools/mutation-gate'");
});

it('writes each command it prints for itself after the gate as it was started', function (): void {
    $gate = GateCommand::byPhp(Path::of('tools/mutation-gate'));

    expect($gate->reproduce('abc123'))->toBe('php tools/mutation-gate reproduce abc123')
        ->and($gate->explain('abc123'))->toBe('php tools/mutation-gate explain abc123')
        ->and($gate->stub('c-1'))->toBe('php tools/mutation-gate stub c-1')
        ->and($gate->baselineWritten())->toBe('php tools/mutation-gate baseline --write')
        ->and($gate->budgeted())->toBe('php tools/mutation-gate run --budget=<duration>')
        ->and($gate->fullMatrix())->toBe('php tools/mutation-gate run --kill-matrix=full')
        ->and($gate->planned(Verb::Run, '--shard=2'))->toBe('php tools/mutation-gate run --plan=.mutation-gate/plan.json --shard=2')
        ->and($gate->plannedVerdict())->toBe('php tools/mutation-gate verdict --plan=.mutation-gate/plan.json --results=.mutation-gate/results')
        ->and($gate->with(Hook::PrePush))->toBe('php tools/mutation-gate pre-push');
});

it('runs a command as a Composer script under Composer\'s own PHP, whichever way the gate was started', function (): void {
    expect(GateCommand::installed()->scripted(Verb::PestPatch))->toBe('@php vendor/bin/mutation-gate pest:patch')
        ->and(GateCommand::byPhp(Path::of('tools/mutation-gate'))->scripted(Verb::InfectionPatch))
        ->toBe('@php tools/mutation-gate infection:patch');
});
