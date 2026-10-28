<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cluster\ClusterId;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Hook\Hook;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
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
    $mutant = MutantId::firstOf(Path::of('src/Money.php'), 'Plus', "-a\n+b\n");
    $cluster = ClusterId::of(MutantIds::of($mutant));

    expect($gate->reproduce($mutant))->toBe(sprintf('php tools/mutation-gate reproduce %s', $mutant->value()))
        ->and($gate->explain($mutant))->toBe(sprintf('php tools/mutation-gate explain %s', $mutant->value()))
        ->and($gate->explain($cluster))->toBe(sprintf('php tools/mutation-gate explain %s', $cluster->value()))
        ->and($gate->stub($cluster))->toBe(sprintf('php tools/mutation-gate stub %s', $cluster->value()))
        ->and($gate->triage(Path::of('src/My Money.php')))->toBe("php tools/mutation-gate triage 'src/My Money.php'")
        ->and($gate->command(Verb::Watch))->toBe('php tools/mutation-gate watch')
        ->and($gate->imported(Path::of('infection.json5')))->toBe('php tools/mutation-gate init --from=infection.json5')
        ->and($gate->changedSince(Revision::ref('origin/main')))->toBe('php tools/mutation-gate run --changed-since=origin/main')
        ->and($gate->changedSince(Revision::ref('my base')))->toBe("php tools/mutation-gate run --changed-since='my base'")
        ->and($gate->baselineWritten())->toBe('php tools/mutation-gate baseline --write')
        ->and($gate->budgeted())->toBe('php tools/mutation-gate run --budget=<duration>')
        ->and($gate->fullMatrix())->toBe('php tools/mutation-gate run --kill-matrix=full')
        ->and($gate->jobs()->run())->toBe('php tools/mutation-gate run --plan=.mutation-gate/plan.json')
        ->and($gate->jobs()->shard(ShardId::of(2)))->toBe('php tools/mutation-gate run --plan=.mutation-gate/plan.json --shard=2')
        ->and($gate->jobs()->verdict())->toBe('php tools/mutation-gate verdict --plan=.mutation-gate/plan.json --results=.mutation-gate/results')
        ->and($gate->command(Hook::PrePush))->toBe('php tools/mutation-gate pre-push');
});

it('runs a command as a Composer script under Composer\'s own PHP, whichever way the gate was started', function (): void {
    expect(GateCommand::installed()->scripted(Verb::PestPatch))->toBe('@php vendor/bin/mutation-gate pest:patch')
        ->and(GateCommand::byPhp(Path::of('tools/mutation-gate'))->scripted(Verb::InfectionPatch))
        ->toBe('@php tools/mutation-gate infection:patch');
});
