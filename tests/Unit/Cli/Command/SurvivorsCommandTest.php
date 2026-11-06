<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Cli\Command\SurvivorsCommand;
use NightWorksIO\MutationGate\Cli\Flow\LastRun;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\DeliveryFile;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Port\TreeSource;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** A store whose feature branch's last run left the fixture's mutants of the money file. */
$store = static function (): ProofStoreFake {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('feature'), Ledger::empty()->withProof(Proof::of(
        Digest::sha256Of('src/Money.php earlier'),
        Path::of('src/Money.php'),
        Flows::mutantsOf('src/Money.php'),
        Run::of('github:earlier', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )));

    return $store;
};

$feature = static fn(): CiPlanFake => new CiPlanFake(RunOn::at(Scope::branch('feature'), Scope::branch('main')));

it('re-checks the branch\'s last survivors, says what became of each, writes no ledger, and exits 0', function (string $options) use ($store, $feature): void {
    $project = FlowCommands::project();
    $proofs = $store();
    $composition = FlowCommands::composition($project, ScriptedRunner::fixture(), $proofs, $feature());
    FlowCommands::run(PlanCommand::command($composition), '--shards=1');

    $ran = FlowCommands::run(SurvivorsCommand::command($composition), $options);

    expect([$ran->code, $ran->errors])->toBe([0, ''])
        ->and($ran->output)->toBe(implode("\n", [
            'Survivors re-checked: 2 of 2 still survive.',
            '  still survives: src/Money.php:16, GreaterThan, 49e02fb39669',
            '  still survives: src/Money.php:21, Minus, 95e61bd8bf62',
            '',
        ]))
        ->and($proofs->read(Scope::branch('feature')))->toEqual($store()->read(Scope::branch('feature')))
        ->and($proofs->read(Scope::branch('main')))->toEqual(Ledger::empty());
})->with(['with the plan' => '--plan=.mutation-gate/plan.json', 'planning first' => '']);

it('says after the survivors what each report it is configured with made of the re-check', function () use ($store, $feature): void {
    $project = FlowCommands::project('"reports": [{"use": "github-summary"}]');
    $composition = FlowCommands::composition($project, ScriptedRunner::fixture(), $store(), $feature());
    FlowCommands::run(PlanCommand::command($composition), '--shards=1');
    $summary = sprintf('%s/summary.md', Scratch::directory());
    $kept = getenv('GITHUB_STEP_SUMMARY');
    putenv(sprintf('GITHUB_STEP_SUMMARY=%s', $summary));

    try {
        $ran = FlowCommands::run(SurvivorsCommand::command($composition));
    } finally {
        putenv($kept === false ? 'GITHUB_STEP_SUMMARY' : sprintf('GITHUB_STEP_SUMMARY=%s', $kept));
    }

    expect($ran->output)->toEndWith(sprintf("95e61bd8bf62\nWrote %s.\n", $summary))
        ->and(file_get_contents($summary))->toContain('49e02fb39669');
});

it('says why it re-checks none on the default branch, and exits 0', function () use ($store): void {
    $project = FlowCommands::project();
    $composition = FlowCommands::composition($project, ScriptedRunner::fixture(), $store(), Flows::ci());

    $ran = FlowCommands::run(SurvivorsCommand::command($composition));

    expect([$ran->code, $ran->output])->toBe([0, "A run of the default branch re-checks no survivors first.\n"]);
});

it('cannot re-check without the plan it is named, or with --security or --suite beside one, or where the runner cannot run', function (
    string $options,
    bool $runs,
    string $why,
) use ($store, $feature): void {
    $project = FlowCommands::project();
    $runner = $runs ? ScriptedRunner::fixture() : ScriptedRunner::fixture()->refusing('Pest is not installed.');
    $composition = FlowCommands::composition($project, $runner, $store(), $feature());

    $ran = FlowCommands::run(SurvivorsCommand::command($composition), $options);

    expect([$ran->code, $ran->output, $ran->errors])->toBe([2, '', $why]);
})->with([
    'no plan' => ['--plan=none.json', true, "There is no plan at none.json. Run vendor/bin/mutation-gate plan, and hand its plan to every job.\n"],
    'security' => ['--plan=none.json --security', true, "survivors --plan re-checks what its plan was made for, so it takes no --security. Give it to plan.\n"],
    'a suite' => ['--plan=none.json --suite=unit', true, "survivors --plan re-checks what its plan was made for, so it takes no --suite. Give it to plan.\n"],
    'a runner that cannot run' => ['', false, "Pest is not installed.\n"],
]);

it('follows the plan it is named, as its shards do, and cannot re-check by a suite the PHPUnit config does not declare', function () use ($store, $feature): void {
    $project = FlowCommands::project();
    Scratch::write($project, 'phpunit.xml', <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="unit"><directory>tests</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);
    LastRun::keep(Directory::at($project), Planned::twoShards()->briefed(Briefing::standard()->inSuite(SuiteName::of('e2e'))));
    $composition = FlowCommands::composition($project, ScriptedRunner::fixture(), $store(), $feature());

    $ran = FlowCommands::run(SurvivorsCommand::command($composition), '--plan=.mutation-gate/plan.json');

    expect([$ran->code, $ran->errors])->toBe([2, "--suite=e2e names no test suite. The PHPUnit config declares: unit.\n"]);
});

it('begins the survivors stage\'s delivery under --deliver-later, emptying what an earlier run left', function () use ($store, $feature): void {
    $project = FlowCommands::project();
    $composition = FlowCommands::composition($project, ScriptedRunner::fixture(), $store(), $feature());
    FlowCommands::run(PlanCommand::command($composition), '--shards=1');
    Scratch::write($project, '.mutation-gate/delivery/survivors/delivery.json', '{"format": 1, "comment": "an earlier run\'s"}');

    $ran = FlowCommands::run(SurvivorsCommand::command($composition), '--plan=.mutation-gate/plan.json --deliver-later');

    expect([$ran->code, $ran->errors])->toBe([0, ''])
        ->and(DeliveryFile::decode((string) file_get_contents(sprintf('%s/.mutation-gate/delivery/survivors/delivery.json', $project))))
        ->toEqual(Delivery::none());
});

it('prints the jobs a CI shares the re-check among, one per file of the survivors taken, and runs none of them', function () use ($store, $feature): void {
    $project = FlowCommands::project();
    $runner = ScriptedRunner::fixture();
    $composition = FlowCommands::composition($project, $runner, $store(), $feature());
    FlowCommands::run(PlanCommand::command($composition), '--shards=1');
    $before = count($runner->requests());

    $ran = FlowCommands::run(SurvivorsCommand::command($composition), '--plan=.mutation-gate/plan.json --jobs');
    $main = FlowCommands::run(
        SurvivorsCommand::command(FlowCommands::composition($project, ScriptedRunner::fixture(), $store(), Flows::ci())),
        '--jobs',
    );

    expect([$ran->code, $ran->output, $ran->errors])->toBe([0, "{\"shares\":[1],\"minutes\":30}\n", ''])
        ->and(count($runner->requests()))->toBe($before)
        ->and([$main->code, $main->output])->toBe([0, "{\"shares\":[],\"minutes\":30}\n"]);
});

it('prints one job, and says aside why, where it cannot tell which survivors the re-check takes', function () use ($store, $feature): void {
    $project = FlowCommands::project();
    FlowCommands::run(PlanCommand::command(FlowCommands::composition($project, ScriptedRunner::fixture(), $store(), $feature())), '--shards=1');
    $unread = FlowCommands::registered(
        FlowCommands::registry(Flows::trees(), ScriptedRunner::fixture(), $store(), $feature(), RepositoryFake::onMain(Revision::ref(Flows::HEAD)), Flows::checkout())
            ->withTreeSource(Name::of('phpunit'), static fn(): TreeSource => new TreeSourceFake(CannotJudge::because('No tree is declared.'))),
        $project,
        Variables::of([]),
    );

    $ran = FlowCommands::run(SurvivorsCommand::command($unread), '--plan=.mutation-gate/plan.json --jobs');

    expect([$ran->code, $ran->output, $ran->errors])->toBe([
        0,
        "{\"shares\":[1],\"minutes\":30}\n",
        "The last run's survivors could not be re-checked first: No tree is declared.\n",
    ]);
});

it('re-checks one share and leaves what it made, then reports the shares as one re-check, a share that left nothing not reached', function () use ($store, $feature): void {
    $project = FlowCommands::project();
    $composition = FlowCommands::composition($project, ScriptedRunner::fixture(), $store(), $feature());
    FlowCommands::run(PlanCommand::command($composition), '--shards=1');

    $first = FlowCommands::run(SurvivorsCommand::command($composition), '--plan=.mutation-gate/plan.json --share=1 --of=2');
    $second = FlowCommands::run(SurvivorsCommand::command($composition), '--plan=.mutation-gate/plan.json --share=2 --of=2');
    $combined = FlowCommands::run(SurvivorsCommand::command($composition), '--plan=.mutation-gate/plan.json --from=.mutation-gate/survivors --of=2');
    $none = FlowCommands::run(SurvivorsCommand::command($composition), '--plan=.mutation-gate/plan.json --from=nowhere --of=2');

    expect([$first->code, $first->output, $first->errors])
        ->toBe([0, "Share 1 of the re-check: files run again 1, not reached 0. Wrote .mutation-gate/survivors/1.json.\n", ''])
        ->and([$second->code, $second->output])->toBe([0, "Share 2 of the re-check: files run again 0, not reached 0. Wrote .mutation-gate/survivors/2.json.\n"])
        ->and(is_file(sprintf('%s/.mutation-gate/survivors/1.json', $project)))->toBeTrue()
        ->and([$combined->code, $combined->output])->toBe([0, implode("\n", [
            'Survivors re-checked: 2 of 2 still survive.',
            '  still survives: src/Money.php:16, GreaterThan, 49e02fb39669',
            '  still survives: src/Money.php:21, Minus, 95e61bd8bf62',
            '',
        ])])
        ->and([$none->code, $none->output])->toBe([0, implode("\n", [
            'Survivors re-checked: 0 of 0 still survive. 2 not re-checked: no job ran their files within its budget.',
            '  not re-checked: src/Money.php:16, GreaterThan, 49e02fb39669',
            '  not re-checked: src/Money.php:21, Minus, 95e61bd8bf62',
            '',
        ])]);
});

it('cannot share a re-check among jobs that name no share', function (string $options, string $why) use ($store, $feature): void {
    $project = FlowCommands::project();
    $composition = FlowCommands::composition($project, ScriptedRunner::fixture(), $store(), $feature());
    FlowCommands::run(PlanCommand::command($composition), '--shards=1');

    $ran = FlowCommands::run(SurvivorsCommand::command($composition), sprintf('--plan=.mutation-gate/plan.json %s', $options));

    expect([$ran->code, $ran->output, $ran->errors])->toBe([2, '', $why]);
})->with([
    'a share past the jobs' => ['--share=3 --of=2', "--share=3 --of=2 names no share: give whole numbers, the share at most --of.\n"],
    'a share of no jobs' => ['--share=1', "--share=1 --of= names no share: give whole numbers, the share at most --of.\n"],
    'shares of no jobs' => ['--from=.mutation-gate/survivors', "--of= names no number of jobs: give a whole number of 1 or more.\n"],
]);

it('counts the files a share had no time left for, and leaves no share where it re-checks none', function () use ($store, $feature): void {
    $project = FlowCommands::project('"survivorsFirst": {"budget": "0s"}');
    $composition = FlowCommands::composition($project, ScriptedRunner::fixture(), $store(), $feature());
    FlowCommands::run(PlanCommand::command($composition), '--shards=1');

    $late = FlowCommands::run(SurvivorsCommand::command($composition), '--plan=.mutation-gate/plan.json --share=1 --of=1');
    $main = FlowCommands::run(
        SurvivorsCommand::command(FlowCommands::composition(FlowCommands::project(), ScriptedRunner::fixture(), $store(), Flows::ci())),
        '--share=1 --of=1',
    );

    expect([$late->code, $late->output])->toBe([0, "Share 1 of the re-check: files run again 0, not reached 1. Wrote .mutation-gate/survivors/1.json.\n"])
        ->and([$main->code, $main->output])->toBe([0, "A run of the default branch re-checks no survivors first.\n"]);
});

it('cannot re-check a share it cannot leave what it made of', function () use ($store, $feature): void {
    $project = FlowCommands::project();
    $composition = FlowCommands::composition($project, ScriptedRunner::fixture(), $store(), $feature());
    FlowCommands::run(PlanCommand::command($composition), '--shards=1');
    Scratch::write($project, '.mutation-gate/survivors', 'a file where the shares\' directory goes');

    $ran = FlowCommands::run(SurvivorsCommand::command($composition), '--plan=.mutation-gate/plan.json --share=1 --of=1');

    expect([$ran->code, $ran->output])->toBe([2, ''])
        ->and($ran->errors)->toContain('.mutation-gate/survivors/1.json');
});
