<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\WorkflowFile;

// What the action, the reusable workflow and the release workflow promise a
// project that runs them (ADR-0011, decision 8; ADR-0019, decision 15), held
// to the YAML they ship.

/** The reusable workflow's path. */
const REUSABLE = '.github/workflows/mutation-gate.yml';

/** How a step's `run` starts the gate's plan or its verdict, the steps that read the proof store. */
const READS_THE_STORE = ['"${GATE}" plan ', '"${GATE}" verdict '];

/**
 * Every step of a job, by its position.
 *
 * @return list<Node>
 */
function jobSteps(Node $job): array
{
    return Lenient::items($job->field('steps'));
}

/** Whether a mapping names a secret in any of its values. */
function namesASecret(Node $mapping): bool
{
    return array_any(Lenient::entries($mapping), fn(Node $value): bool => str_contains(Lenient::text($value), 'secrets.'));
}

it('judges whatever the plan did, so a plan that cannot judge never leaves the protected check skipped', function (): void {
    $verdict = WorkflowFile::at(REUSABLE)->field('jobs')->field('verdict');
    $first = jobSteps($verdict)[0];

    expect(Lenient::text($verdict->field('if')))->toBe('${{ !cancelled() }}')
        ->and(Lenient::text($first->field('if')))->toBe("needs.plan.result != 'success'")
        ->and(Lenient::text($first->field('run')))->toContain('echo "verdict=cannot-judge" >> "${GITHUB_OUTPUT}"')
        ->and(Lenient::text($first->field('run')))->toEndWith("exit 2\n")
        ->and(Lenient::text($verdict->field('outputs')->field('verdict')))
        ->toBe(sprintf('${{ steps.outputs.outputs.verdict || steps.%s.outputs.verdict }}', Lenient::text($first->field('id'))));
});

it('publishes nothing where the plan did not succeed, since there is then nothing to publish', function (): void {
    $publish = WorkflowFile::at(REUSABLE)->field('jobs')->field('publish');

    expect(Lenient::text($publish->field('if')))->toContain("!cancelled() && needs.plan.result == 'success' && ");
});

it('hands each secret only to the step that uses it, never to a whole job or a shard', function (): void {
    $jobs = Lenient::entries(WorkflowFile::at(REUSABLE)->field('jobs'));
    $wholeJobs = [];
    $given = [];

    foreach ($jobs as $id => $job) {
        $wholeJobs[$id] = namesASecret($job->field('env'));

        foreach (jobSteps($job) as $step) {
            $run = Lenient::text($step->field('run'));
            $given[] = [$id, array_any(READS_THE_STORE, static fn(string $reads): bool => str_contains($run, $reads)), namesASecret($step->field('env'))];
        }
    }

    $secretSteps = array_values(array_filter($given, static fn(array $step): bool => $step[2]));

    expect(array_filter($wholeJobs))->toBe([])
        ->and(array_map(static fn(array $step): array => [$step[0], $step[1]], $secretSteps))
        ->toBe([['plan', true], ['verdict', true]]);
});

it('alerts and traces from the verdict step alone', function (): void {
    $jobs = Lenient::entries(WorkflowFile::at(REUSABLE)->field('jobs'));
    $holding = [];

    foreach ($jobs as $id => $job) {
        foreach (jobSteps($job) as $step) {
            foreach (array_keys(Lenient::entries($step->field('env'))) as $name) {
                if (str_starts_with((string) $name, 'OTEL_') || str_starts_with((string) $name, 'MUTATION_GATE_')) {
                    $holding[] = sprintf('%s/%s', $id, Lenient::text($step->field('id')));
                }
            }
        }
    }

    expect(array_values(array_unique($holding)))->toBe(['verdict/verdict']);
});

it('hands the verdict the coverage map the plan made for it, and no shard\'s, before it judges', function (): void {
    $steps = WorkflowFile::at(REUSABLE)->field('jobs')->field('verdict')->field('steps');
    $verdict = Workspace::verdictCoverage()->value();
    $downloads = WorkflowFile::stepsWhere($steps, 'uses', static fn(string $uses): bool => str_starts_with($uses, 'actions/download-artifact@'));
    $coverage = array_values(array_filter(
        $downloads,
        static fn(int $at): bool => Lenient::text(Lenient::items($steps)[$at]->field('with')->field('pattern')) === 'mutation-gate-coverage',
    ));
    $kept = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, sprintf('! -name %s ', basename($verdict))));
    $judged = WorkflowFile::stepsWhere($steps, 'id', static fn(string $id): bool => $id === 'verdict');

    expect($coverage)->toHaveCount(1)
        ->and(Lenient::text(Lenient::items($steps)[$coverage[0]]->field('with')->field('path')))->toBe(dirname($verdict))
        ->and($kept)->toHaveCount(1)
        ->and($coverage[0])->toBeLessThan($kept[0])
        ->and($kept[0])->toBeLessThan($judged[0]);
});

it('checks the workflow out under .mutation-gate, which the gate leaves out of every change and key', function (): void {
    expect(Lenient::text(WorkflowFile::at(REUSABLE)->field('env')->field('GATE_ACTION')))->toStartWith('.mutation-gate/');
});

it('restores no ledger for one shard, which reads none', function (): void {
    $steps = WorkflowFile::at('action.yml')->field('runs')->field('steps');
    $restores = WorkflowFile::stepsWhere($steps, 'uses', static fn(string $uses): bool => str_starts_with($uses, 'actions/cache/restore@'));

    expect($restores)->toHaveCount(2);

    foreach ($restores as $at) {
        expect(Lenient::text(Lenient::items($steps)[$at]->field('if')))->toStartWith("inputs.shard == '' && ");
    }
});

it('publishes the commit it verified and judged, never the tag read again by name', function (): void {
    $publish = WorkflowFile::at('.github/workflows/release.yml')->field('jobs')->field('publish');
    $steps = jobSteps($publish);
    $runs = implode("\n", array_map(static fn(Node $step): string => Lenient::text($step->field('run')), $steps));

    expect(Lenient::text($steps[0]->field('with')->field('ref')))->toBe('${{ github.sha }}')
        ->and(Lenient::text($steps[1]->field('env')->field('COMMIT')))->toBe('${{ github.sha }}')
        ->and(Lenient::text($steps[1]->field('run')))->toContain('if [ "${tagged}" != "${COMMIT}" ]; then')
        ->and($runs)->not->toContain('git rev-parse HEAD');
});

it('never resets a release branch a dispatch finds, which may hold the maintainer\'s edits', function (): void {
    $draft = WorkflowFile::at('.github/workflows/release.yml')->field('jobs')->field('draft');
    $runs = implode("\n", array_map(static fn(Node $step): string => Lenient::text($step->field('run')), jobSteps($draft)));

    expect($runs)->not->toContain('force=true')
        ->and($runs)->toContain('exists, and may hold edits of its changelog section.');
});
