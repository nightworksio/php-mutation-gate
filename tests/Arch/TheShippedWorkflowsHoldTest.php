<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
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

/** Whether a mapping hands on the job's token or a secret in any of its values. */
function holdsACredential(Node $mapping): bool
{
    return namesASecret($mapping)
        || array_any(Lenient::entries($mapping), fn(Node $value): bool => str_contains(Lenient::text($value), 'github.token'));
}

/** Whether a step's `run` starts the project's installed gate on a command that runs or loads the project's code. */
function runsTheProject(Node $step): bool
{
    return preg_match('/"\$\{GATE\}" (plan|run|survivors|verdict|config:show|pest:patch|infection:patch)\b/', Lenient::text($step->field('run'))) === 1;
}

/**
 * Every step of the action and of each job of the reusable workflow, named by where it stands.
 *
 * @return array<string, Node>
 */
function everyShippedStep(): array
{
    $steps = [];

    foreach (Lenient::items(WorkflowFile::at('action.yml')->field('runs')->field('steps')) as $at => $step) {
        $steps[sprintf('action.yml/%d %s', $at, Lenient::text($step->field('name')))] = $step;
    }

    foreach (Lenient::entries(WorkflowFile::at(REUSABLE)->field('jobs')) as $id => $job) {
        foreach (jobSteps($job) as $at => $step) {
            $steps[sprintf('%s/%d %s', $id, $at, Lenient::text($step->field('name')))] = $step;
        }
    }

    return $steps;
}

it('judges whatever the plan did, so a plan that cannot judge never leaves the protected check skipped', function (): void {
    $verdict = WorkflowFile::at(REUSABLE)->field('jobs')->field('verdict');
    $first = jobSteps($verdict)[0];

    expect(Lenient::text($verdict->field('if')))->toBe('${{ !cancelled() && needs.plan.result != \'skipped\' }}')
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

it('hands the survivors re-check every coverage map the plan made before it re-checks, so it runs no suite for one', function (): void {
    $steps = WorkflowFile::at(REUSABLE)->field('jobs')->field('survivors')->field('steps');
    $coverage = Workspace::coverage()->value();
    $downloads = WorkflowFile::stepsWhere($steps, 'uses', static fn(string $uses): bool => str_starts_with($uses, 'actions/download-artifact@'));
    $handed = array_values(array_filter(
        $downloads,
        static fn(int $at): bool => Lenient::text(Lenient::items($steps)[$at]->field('with')->field('pattern')) === 'mutation-gate-coverage',
    ));
    $rechecked = WorkflowFile::stepsWhere($steps, 'id', static fn(string $id): bool => $id === 'recheck');

    expect($handed)->toHaveCount(1)
        ->and(Lenient::text(Lenient::items($steps)[$handed[0]]->field('with')->field('path')))->toBe($coverage)
        ->and(dirname(Workspace::verdictCoverage()->value()))->toBe($coverage)
        ->and($rechecked)->toHaveCount(1)
        ->and($handed[0])->toBeLessThan($rechecked[0]);
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
        expect(Lenient::text(Lenient::items($steps)[$at]->field('if')))->toStartWith("inputs.deliver != 'true' && inputs.shard == '' && ");
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

it('hands no step that runs the project\'s code a token or a secret, in the action or the reusable workflow', function (): void {
    $running = array_filter(everyShippedStep(), runsTheProject(...));
    $holding = array_filter($running, static fn(Node $step): bool => holdsACredential($step->field('env')) || holdsACredential($step->field('with')));

    expect(count($running))->toBeGreaterThan(8)
        ->and(array_keys($holding))->toBe([]);
});

it('gives the action\'s token only to naming the default branch, before the project installs, and to deliver, in a job of its own', function (): void {
    $steps = Lenient::items(WorkflowFile::at('action.yml')->field('runs')->field('steps'));
    $holding = [];
    $installs = 0;

    foreach ($steps as $at => $step) {
        $installs = Lenient::text($step->field('name')) === "Install the project's dependencies" ? $at : $installs;
        $holding = holdsACredential($step->field('env')) ? [...$holding, $at] : $holding;
    }

    [$default, $deliver] = $holding;
    $delivering = Lenient::text($steps[$deliver]->field('run'));

    expect($holding)->toHaveCount(2)
        ->and(Lenient::text($steps[$default]->field('id')))->toBe('default')
        ->and($default)->toBeLessThan($installs)
        ->and(Lenient::text($steps[$deliver]->field('if')))->toBe("inputs.deliver == 'true'")
        ->and($delivering)->toContain('php "${GITHUB_ACTION_PATH}/bin/mutation-gate" deliver --from=')
        ->and(Lenient::text($steps[$installs]->field('if')))->toBe("inputs.deliver != 'true'");
});

it('installs the gate the action delivers with from the gate\'s own lock, running none of its scripts or plugins', function (): void {
    $steps = WorkflowFile::at('action.yml')->field('runs')->field('steps');
    $installing = WorkflowFile::stepsWhere($steps, 'name', static fn(string $name): bool => $name === "Install the gate's own copy");
    $run = Lenient::text(Lenient::items($steps)[$installing[0]]->field('run'));

    expect($run)->toContain('composer install --working-dir="${GITHUB_ACTION_PATH}"')
        ->and($run)->toContain('--no-scripts')
        ->and($run)->toContain('--no-plugins');
});

it('caches the ledger and the kept coverage map the verdict left, keyed by both, in the action and the reusable workflow', function (): void {
    $saving = static fn(Node $steps): Node => Lenient::items($steps)[WorkflowFile::stepsWhere(
        $steps,
        'uses',
        static fn(string $uses): bool => str_starts_with($uses, 'actions/cache/save@'),
    )[0]];
    $keeping = static fn(Node $steps): string => Lenient::text(Lenient::items($steps)[WorkflowFile::stepsWhere(
        $steps,
        'name',
        static fn(string $name): bool => $name === 'Keep what the verdict left for this scope',
    )[0]]->field('run'));
    $both = sprintf(
        "hashFiles(format('{0}/%s', steps.resolve.outputs.own_dir), format('{0}/%s', steps.resolve.outputs.own_dir))",
        LedgerFile::NAME,
        Companion::Coverage->value,
    );
    $files = sprintf('for file in %s %s; do', LedgerFile::NAME, Companion::Coverage->value);

    foreach ([WorkflowFile::at('action.yml')->field('runs')->field('steps'), WorkflowFile::at(REUSABLE)->field('jobs')->field('verdict')->field('steps')] as $steps) {
        expect(Lenient::text($saving($steps)->field('with')->field('key')))->toContain($both)
            ->and(Lenient::text($saving($steps)->field('if')))->toContain(sprintf('%s != \'\'', $both))
            ->and($keeping($steps))->toContain($files);
    }
});

it('lets only fetch and deliver ask for an OIDC token, with Azure\'s and GCS\'s federation from the repository\'s variables', function (): void {
    $federation = ['AZURE_TENANT_ID', 'AZURE_CLIENT_ID', 'MUTATION_GATE_GCS_PROVIDER', 'MUTATION_GATE_GCS_SERVICE_ACCOUNT'];
    $asking = [];
    $federated = [];

    foreach (Lenient::entries(WorkflowFile::at(REUSABLE)->field('jobs')) as $id => $job) {
        $asking = Lenient::text($job->field('permissions')->field('id-token')) === 'write' ? [...$asking, $id] : $asking;

        foreach (jobSteps($job) as $step) {
            $env = Lenient::entries($step->field('env'));
            $named = array_intersect_key($env, array_flip($federation));
            $federated = $named === [] ? $federated : [
                ...$federated,
                [$id, array_map(Lenient::text(...), $named)],
            ];
            $requested = array_intersect(array_keys($env), ['ACTIONS_ID_TOKEN_REQUEST_URL', 'ACTIONS_ID_TOKEN_REQUEST_TOKEN']);
            expect($requested)->toBe([]);
        }
    }

    $fromVariables = array_combine($federation, array_map(static fn(string $name): string => sprintf('${{ vars.%s }}', $name), $federation));

    expect($asking)->toBe(['fetch', 'deliver'])
        ->and($federated)->toBe([['fetch', $fromVariables], ['deliver', $fromVariables]]);
});

it('writes, for init --single, a run job that hands the action no credential, and a deliver job that alone holds them', function (): void {
    $jobs = Lenient::entries(WorkflowFile::at('resources/ci/github/single.yml')->field('jobs'));
    $holding = [];

    foreach ($jobs as $id => $job) {
        foreach (jobSteps($job) as $step) {
            $credited = holdsACredential($step->field('env')) || holdsACredential($step->field('with'));
            $holding[] = [$id, Lenient::text($step->field('with')->field('deliver')), $credited];
        }

        expect(holdsACredential($job->field('env')))->toBeFalse();
    }

    expect($holding)->toBe([['mutation', '', false], ['mutation', '', false], ['deliver', 'true', true]])
        ->and(Lenient::text($jobs['mutation']->field('permissions')->field('pull-requests')))->toBe('')
        ->and(Lenient::text($jobs['deliver']->field('needs')))->toBe('mutation')
        ->and(Lenient::text($jobs['deliver']->field('permissions')->field('id-token')))->toBe('write');
});

it('runs every job that needs another by a status function of its own and a check of what it needs, so a skipped job skips none after it', function (): void {
    $bare = [];

    foreach (Lenient::entries(WorkflowFile::at(REUSABLE)->field('jobs')) as $id => $job) {
        $needs = $job->field('needs');
        $needed = $needs->isPresent() ? [...Lenient::items($needs), $needs] : [];
        $names = array_values(array_filter(array_map(Lenient::text(...), $needed), static fn(string $need): bool => $need !== ''));
        $if = Lenient::text($job->field('if'));
        $checked = array_any($names, static fn(string $need): bool => str_contains($if, sprintf('needs.%s.result', $need)));
        $bare = $names === [] || ((str_contains($if, '!cancelled()') || str_contains($if, 'always()')) && $checked)
            ? $bare
            : [...$bare, $id];
    }

    expect($bare)->toBe([]);
});

it('grants the reusable workflow, wherever this repository calls it, every permission its jobs ask for, or no job of it starts', function (string $workflow, string $id): void {
    $caller = WorkflowFile::at($workflow)->field('jobs')->field($id);
    $asked = [];

    foreach (Lenient::entries(WorkflowFile::at(REUSABLE)->field('jobs')) as $job) {
        foreach (Lenient::entries($job->field('permissions')) as $scope => $level) {
            $asked[$scope] = Lenient::text($level) === 'write' || ($asked[$scope] ?? '') === 'write' ? 'write' : 'read';
        }
    }

    $granted = array_map(Lenient::text(...), Lenient::entries($caller->field('permissions')));
    $enough = ['read' => ['read', 'write'], 'write' => ['write']];
    $short = array_filter(
        $asked,
        static fn(string $level, string $scope): bool => ! in_array($granted[$scope] ?? 'none', $enough[$level], strict: true),
        ARRAY_FILTER_USE_BOTH,
    );

    expect(Lenient::text($caller->field('uses')))->toStartWith(sprintf('./%s', REUSABLE))
        ->and($asked)->not->toBe([])
        ->and($short)->toBe([]);
})->with([
    'the self-gate' => ['.github/workflows/ci.yml', 'mutation'],
    'the release' => ['.github/workflows/release.yml', 'gate'],
]);

it('stops the other shards where one is doomed, failing it fast only after its result is uploaded, with no job writing to Actions', function (): void {
    $jobs = WorkflowFile::at(REUSABLE)->field('jobs');
    $shard = $jobs->field('shard');
    $steps = $shard->field('steps');
    $result = WorkflowFile::stepsWhere($steps, 'uses', static fn(string $uses): bool => str_starts_with($uses, 'actions/upload-artifact@'));
    $doomed = WorkflowFile::stepsWhere($steps, 'run', static fn(string $run): bool => str_contains($run, 'jq -e \'.doomed\' "${RESULT}"'));
    $writing = array_filter(
        Lenient::entries($jobs),
        static fn(Node $job): bool => Lenient::text($job->field('permissions')->field('actions')) === 'write',
    );
    $at = count(Lenient::items($steps)) - 1;
    $last = Lenient::items($steps)[$at];

    expect(Lenient::boolean($shard->field('strategy')->field('fail-fast'), otherwise: false))->toBeTrue()
        ->and($doomed)->toBe([$at])
        ->and($result)->not->toBe([])
        ->and($result)->each->toBeLessThan($at)
        ->and(Lenient::text($last->field('if')))->toBe('${{ !cancelled() }}')
        ->and(Lenient::text($last->field('env')->field('RESULT')))->toBe('.mutation-gate/results/${{ matrix.shard.id }}.json')
        ->and(Lenient::text($last->field('run')))->toContain('exit 1')
        ->and($writing)->toBe([]);
});
