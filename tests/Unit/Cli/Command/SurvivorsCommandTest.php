<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Cli\Command\SurvivorsCommand;
use NightWorksIO\MutationGate\Cli\Flow\LastRun;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
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
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
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
    'no plan' => ['--plan=none.json', true, "There is no plan at none.json. Run mutation-gate plan, and hand its plan to every job.\n"],
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
