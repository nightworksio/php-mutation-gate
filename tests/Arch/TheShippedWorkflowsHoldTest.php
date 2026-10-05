<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Tests\Support\WorkflowFile;

// What the action, the reusable workflow and the release workflow promise a
// project that runs them (ADR-0011, decision 8; ADR-0019, decision 15), held
// to the YAML they ship.

/** The reusable workflow's path. */
const REUSABLE = '.github/workflows/mutation-gate.yml';

/** How a step's `run` starts the gate from its own installation, which loads none of the project's code. */
const OWN_GATE = 'php "${GATE_ACTION}/bin/mutation-gate" ';

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

it('hands each secret only to the step that fetches or delivers, never to a whole job or the project\'s code', function (): void {
    $jobs = Lenient::entries(WorkflowFile::at(REUSABLE)->field('jobs'));
    $wholeJobs = [];
    $given = [];

    foreach ($jobs as $id => $job) {
        $wholeJobs[$id] = namesASecret($job->field('env'));

        foreach (jobSteps($job) as $step) {
            $run = Lenient::text($step->field('run'));
            $given[] = [$id, Lenient::text($step->field('id')), str_starts_with($run, OWN_GATE), namesASecret($step->field('env'))];
        }
    }

    $secretSteps = array_values(array_filter($given, static fn(array $step): bool => $step[3]));

    expect(array_filter($wholeJobs))->toBe([])
        ->and(array_map(static fn(array $step): array => [$step[0], $step[1], $step[2]], $secretSteps))
        ->toBe([['fetch', 'fetch', true], ['deliver', 'deliver', true]]);
});

it('alerts and traces from the deliver step alone', function (): void {
    $jobs = Lenient::entries(WorkflowFile::at(REUSABLE)->field('jobs'));
    $holding = [];

    foreach ($jobs as $id => $job) {
        foreach (jobSteps($job) as $step) {
            foreach (array_keys(Lenient::entries($step->field('env'))) as $name) {
                if (preg_match('/^(OTEL_|MUTATION_GATE_[A-Z]+_URL$|MUTATION_GATE_WEBHOOK_SECRET$)/', (string) $name) === 1) {
                    $holding[] = sprintf('%s/%s', $id, Lenient::text($step->field('id')));
                }
            }
        }
    }

    expect(array_values(array_unique($holding)))->toBe(['deliver/deliver']);
});

it('hands the verdict every coverage map the plan made, its own and each shard\'s, before it judges', function (): void {
    $steps = WorkflowFile::at(REUSABLE)->field('jobs')->field('verdict')->field('steps');
    $coverage = Workspace::coverage()->value();
    $downloads = WorkflowFile::stepsWhere($steps, 'uses', static fn(string $uses): bool => str_starts_with($uses, 'actions/download-artifact@'));
    $handed = array_values(array_filter(
        $downloads,
        static fn(int $at): bool => Lenient::text(Lenient::items($steps)[$at]->field('with')->field('pattern')) === 'mutation-gate-coverage',
    ));
    $touching = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, $coverage));
    $judged = WorkflowFile::stepsWhere($steps, 'id', static fn(string $id): bool => $id === 'verdict');

    expect($handed)->toHaveCount(1)
        ->and(Lenient::text(Lenient::items($steps)[$handed[0]]->field('with')->field('path')))->toBe($coverage)
        ->and(dirname(Workspace::verdictCoverage()->value()))->toBe($coverage)
        ->and(dirname(Workspace::shardCoverage(ShardId::of(1))->value()))->toBe($coverage)
        ->and($touching)->toBe([])
        ->and($handed[0])->toBeLessThan($judged[0]);
});

it('hands each shard the plan\'s whole map beside its own, removing only the other shards\' directories', function (): void {
    $jobs = WorkflowFile::at(REUSABLE)->field('jobs');
    $coverage = Workspace::coverage()->value();
    $whole = CoverageMapFile::in(Workspace::coverage())->value();
    $shard = $jobs->field('shard')->field('steps');
    $touching = WorkflowFile::stepsWhere($shard, 'run', static fn(string $run): bool => str_contains($run, $coverage));
    $removing = Lenient::text(Lenient::items($shard)[$touching[0]]->field('run'));
    $plan = $jobs->field('plan')->field('steps');
    $uploads = WorkflowFile::stepsWhere($plan, 'uses', static fn(string $uses): bool => str_starts_with($uses, 'actions/upload-artifact@'));
    $uploaded = array_values(array_filter(
        $uploads,
        static fn(int $at): bool => Lenient::text(Lenient::items($plan)[$at]->field('with')->field('name')) === 'mutation-gate-coverage',
    ));
    $paths = array_map(trim(...), explode("\n", trim(Lenient::text(Lenient::items($plan)[$uploaded[0]]->field('with')->field('path')))));
    $excluded = array_filter($paths, static fn(string $path): bool => str_starts_with($path, '!') && fnmatch(substr($path, 1), $whole));

    expect($touching)->toHaveCount(1)
        ->and(dirname($whole))->toBe($coverage)
        ->and($removing)->toContain(sprintf('find %s -mindepth 1 -maxdepth 1 -type d ! -name "shard-${SHARD}"', $coverage))
        ->and($paths)->toContain(sprintf('%s/', $coverage))
        ->and($excluded)->toBe([]);
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
