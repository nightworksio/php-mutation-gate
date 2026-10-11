<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use NightWorksIO\MutationGate\Tests\Support\WorkflowFile;

// ADR-0004: with pest.patch on, a mutation run refuses an unpatched
// pest-plugin-mutate. Where the effective config runs Pest with the patches on,
// the action patches the vendor directory its job installed, after installing
// it and before the gate runs a mutant. The reusable workflow runs mutants in
// its shard jobs, which run the action, and in its survivors job, which
// patches as the action does.

/** How a step's `run` starts the gate's mutation runs. */
const RUNS_MUTANTS = '"${GATE}" run ';

/** How a step's `run` starts the re-check of the last run's survivors, which runs mutants too. */
const RECHECKS = '"${GATE}" survivors ';

it('patches Pest after installing and before running a mutant, where the config turns the patches on', function (): void {
    $steps = WorkflowFile::at('action.yml')->field('runs')->field('steps');
    $install = WorkflowFile::stepsWhere($steps, 'uses', static fn(string $uses): bool => str_starts_with($uses, 'ramsey/composer-install@'));
    $config = WorkflowFile::stepsWhere($steps, 'id', static fn(string $id): bool => $id === 'config');
    $patch = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => $run === '"${GATE}" pest:patch');
    $mutating = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, RUNS_MUTANTS));
    $rechecking = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, RECHECKS));

    expect($install)->toHaveCount(1)
        ->and($rechecking)->toHaveCount(1)
        ->and($rechecking[0])->toBeGreaterThan($patch[0])
        ->and($config)->toHaveCount(1)
        ->and($patch)->toHaveCount(1)
        ->and($mutating)->toHaveCount(2)
        ->and(Lenient::text(Lenient::items($steps)[$patch[0]]->field('if')))->toBe("inputs.deliver != 'true' && steps.config.outputs.pest_patch == 'true'")
        ->and($install[0])->toBeLessThan($config[0])
        ->and($config[0])->toBeLessThan($patch[0])
        ->and($mutating[0])->toBeGreaterThan($patch[0])
        ->and((string) file_get_contents(Tree::at('resources/action/gate_action.py')))
        ->toContain('"pest_patch": "true" if patches_pest(config) else "false"');
});

it('runs mutants in the reusable workflow through the action, and in the survivors job after it patches Pest', function (): void {
    $jobs = Lenient::entries(WorkflowFile::at('.github/workflows/mutation-gate.yml')->field('jobs'));
    $mutating = [];
    $rechecking = [];
    $throughTheAction = [];

    foreach ($jobs as $id => $job) {
        $steps = $job->field('steps');
        $mutating = [...$mutating, ...WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, RUNS_MUTANTS))];
        $rechecking[$id] = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, RECHECKS));
        $throughTheAction[$id] = WorkflowFile::stepsWhere($steps, 'uses', static fn(string $uses): bool => $uses === './.mutation-gate/workflow');
    }

    $survivors = $jobs['survivors']->field('steps');
    $install = WorkflowFile::stepsWhere($survivors, 'uses', static fn(string $uses): bool => str_starts_with($uses, 'ramsey/composer-install@'));
    $patch = WorkflowFile::stepsWhere($survivors, 'run', static fn(string $run): bool => $run === '"${GATE}" pest:patch');

    expect(array_keys($jobs))->toBe(['fetch', 'plan', 'deliver-plan', 'shard', 'survivors', 'deliver-survivors', 'verdict', 'deliver', 'publish'])
        ->and($mutating)->toBe([])
        ->and(array_keys(array_filter($throughTheAction)))->toBe(['shard'])
        ->and(array_keys(array_filter($rechecking)))->toBe(['survivors'])
        ->and($patch)->toHaveCount(1)
        ->and(Lenient::text(Lenient::items($survivors)[$patch[0]]->field('if')))->toBe("steps.config.outputs.pest_patch == 'true'")
        ->and($install[0])->toBeLessThan($patch[0])
        ->and($rechecking['survivors'][0])->toBeGreaterThan($patch[0]);
});

// ADR-0004: Infection keeps its own limit for each mutant unless
// infection:patch has given it the gate's. Where the effective config runs
// Infection, the action patches the vendor directory its job installed, as it
// patches Pest, and the survivors job does the same. The action script reads
// the command's exit: a release it does not patch runs unpatched with a
// warning, and one it cannot patch fails the step.

/** How a step's `run` starts the Infection patch, keeping what it says for the action script. */
const INFECTION_PATCH = '"${GATE}" infection:patch 2> "${said}" || code=$?';

/** How a step hands the patch's exit to the action script, which warns or refuses by it. */
const INFECTION_OUTCOME = 'CODE="${code}" SAID="${said}" python3 ';

it('patches Infection after installing and before running a mutant, where the config runs Infection', function (): void {
    $steps = WorkflowFile::at('action.yml')->field('runs')->field('steps');
    $config = WorkflowFile::stepsWhere($steps, 'id', static fn(string $id): bool => $id === 'config');
    $patch = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, INFECTION_PATCH));
    $mutating = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, RUNS_MUTANTS));
    $rechecking = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, RECHECKS));

    expect($patch)->toHaveCount(1)
        ->and(Lenient::text(Lenient::items($steps)[$patch[0]]->field('if')))
        ->toBe("inputs.deliver != 'true' && steps.config.outputs.infection_patch == 'true'")
        ->and($config[0])->toBeLessThan($patch[0])
        ->and($mutating[0])->toBeGreaterThan($patch[0])
        ->and($rechecking[0])->toBeGreaterThan($patch[0])
        ->and((string) file_get_contents(Tree::at('resources/action/gate_action.py')))
        ->toContain('"infection_patch": "true" if patches_infection(config) else "false"')
        ->and(Lenient::text(Lenient::items($steps)[$patch[0]]->field('run')))->toContain(INFECTION_OUTCOME);
});

it('patches Infection in the survivors job before it re-checks, where the config runs Infection', function (): void {
    $survivors = Lenient::entries(WorkflowFile::at('.github/workflows/mutation-gate.yml')->field('jobs'))['survivors']->field('steps');
    $install = WorkflowFile::stepsWhere($survivors, 'uses', static fn(string $uses): bool => str_starts_with($uses, 'ramsey/composer-install@'));
    $patch = WorkflowFile::stepsWhere($survivors, 'run', static fn(string $run): bool => str_contains($run, INFECTION_PATCH));
    $rechecking = WorkflowFile::stepsWhere($survivors, 'run', static fn(string $run): bool => str_contains($run, RECHECKS));

    expect($patch)->toHaveCount(1)
        ->and(Lenient::text(Lenient::items($survivors)[$patch[0]]->field('if')))->toBe("steps.config.outputs.infection_patch == 'true'")
        ->and(Lenient::text(Lenient::items($survivors)[$patch[0]]->field('run')))->toContain(INFECTION_OUTCOME)
        ->and($install[0])->toBeLessThan($patch[0])
        ->and($rechecking[0])->toBeGreaterThan($patch[0]);
});
