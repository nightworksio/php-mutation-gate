<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Yaml\Yaml;

// ADR-0004: with pest.patch on, a mutation run refuses an unpatched
// pest-plugin-mutate. Where the effective config runs Pest with the patches on,
// the action patches the vendor directory its job installed, after installing
// it and before the gate runs a mutant. The reusable workflow runs mutants in
// its shard jobs only, and those run the action.

/** How a step's `run` starts the gate's mutation runs. */
const RUNS_MUTANTS = '"${GATE}" run ';

/** A YAML file of the repository, read. */
function yamlAt(string $path): Node
{
    return Node::decode((string) json_encode(Yaml::parseFile(Tree::at($path))));
}

/**
 * The positions of the steps whose field a test picks.
 *
 * @param Closure(string): bool $picks
 * @return list<int>
 */
function stepsWhere(Node $steps, string $field, Closure $picks): array
{
    $found = [];

    foreach (Lenient::items($steps) as $at => $step) {
        if ($picks(Lenient::text($step->field($field)))) {
            $found[] = $at;
        }
    }

    return $found;
}

it('patches Pest after installing and before running a mutant, where the config turns the patches on', function (): void {
    $steps = yamlAt('action.yml')->field('runs')->field('steps');
    $install = stepsWhere($steps, 'uses', static fn(string $uses): bool => str_starts_with($uses, 'ramsey/composer-install@'));
    $config = stepsWhere($steps, 'id', static fn(string $id): bool => $id === 'config');
    $patch = stepsWhere($steps, 'run', static fn(string $run): bool => $run === '"${GATE}" pest:patch');
    $mutating = stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, RUNS_MUTANTS));

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
    $jobs = Lenient::entries(yamlAt('.github/workflows/mutation-gate.yml')->field('jobs'));
    $mutating = [];
    $throughTheAction = [];

    foreach ($jobs as $id => $job) {
        $steps = $job->field('steps');
        $mutating = [...$mutating, ...stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, RUNS_MUTANTS))];
        $throughTheAction[$id] = stepsWhere($steps, 'uses', static fn(string $uses): bool => $uses === './.mutation-gate-action');
    }

    expect(array_keys($jobs))->toBe(['plan', 'shard', 'verdict', 'publish'])
        ->and($mutating)->toBe([])
        ->and(array_keys(array_filter($throughTheAction)))->toBe(['shard']);
});
