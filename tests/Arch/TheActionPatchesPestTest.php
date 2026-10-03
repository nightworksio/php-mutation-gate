<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use NightWorksIO\MutationGate\Tests\Support\WorkflowFile;

// ADR-0004: with pest.patch on, a mutation run refuses an unpatched
// pest-plugin-mutate. Where the effective config runs Pest with the patches on,
// the action patches the vendor directory its job installed, after installing
// it and before the gate runs a mutant. The reusable workflow runs mutants in
// its shard jobs only, and those run the action.

/** How a step's `run` starts the gate's mutation runs. */
const RUNS_MUTANTS = '"${GATE}" run ';

it('patches Pest after installing and before running a mutant, where the config turns the patches on', function (): void {
    $steps = WorkflowFile::at('action.yml')->field('runs')->field('steps');
    $install = WorkflowFile::stepsWhere($steps, 'uses', static fn(string $uses): bool => str_starts_with($uses, 'ramsey/composer-install@'));
    $config = WorkflowFile::stepsWhere($steps, 'id', static fn(string $id): bool => $id === 'config');
    $patch = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => $run === '"${GATE}" pest:patch');
    $mutating = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, RUNS_MUTANTS));

    expect($install)->toHaveCount(1)
        ->and($config)->toHaveCount(1)
        ->and($patch)->toHaveCount(1)
        ->and($mutating)->toHaveCount(2)
        ->and(Lenient::text(Lenient::items($steps)[$patch[0]]->field('if')))->toBe("steps.config.outputs.pest_patch == 'true'")
        ->and($install[0])->toBeLessThan($config[0])
        ->and($config[0])->toBeLessThan($patch[0])
        ->and($mutating[0])->toBeGreaterThan($patch[0])
        ->and((string) file_get_contents(Tree::at('.github/scripts/gate_action.py')))
        ->toContain('"pest_patch": "true" if patches_pest(config) else "false"');
});

it('runs mutants in the reusable workflow only through the action', function (): void {
    $jobs = Lenient::entries(WorkflowFile::at('.github/workflows/mutation-gate.yml')->field('jobs'));
    $mutating = [];
    $throughTheAction = [];

    foreach ($jobs as $id => $job) {
        $steps = $job->field('steps');
        $mutating = [...$mutating, ...WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, RUNS_MUTANTS))];
        $throughTheAction[$id] = WorkflowFile::stepsWhere($steps, 'uses', static fn(string $uses): bool => $uses === './.mutation-gate/workflow');
    }

    expect(array_keys($jobs))->toBe(['plan', 'shard', 'verdict', 'publish'])
        ->and($mutating)->toBe([])
        ->and(array_keys(array_filter($throughTheAction)))->toBe(['shard']);
});
