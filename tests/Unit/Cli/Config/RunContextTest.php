<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\RunContext;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Context;
use NightWorksIO\MutationGate\Core\Config\ContextChain;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** A registry whose `fake` CI plan says this of the run. */
$registry = static fn(RunOn|CannotTell $run): Extensions => Extensions::of(Origin::of('acme/gate'))->withCiPlan(
    Name::of('fake'),
    static fn(): CiPlan => new CiPlanFake($run),
    CiPlanFake::withheld(),
    CiPlanFake::marker(),
);

/** The contexts of a run the fake plan says this of, in CI or not, with these settings over the fake plan. */
$contexts = static fn(
    RunOn|CannotTell $run,
    bool $inCi,
    array $config,
    CommandLine $given,
): ContextChain|Invalid|CannotJudge => new RunContext(Variables::of($inCi ? ['CI' => 'true'] : []))->chain(
    $given,
    static fn(): Settings => Configs::settings(['runner' => 'pest', 'ci' => ['plan' => 'fake', ...$config]]),
    $registry($run),
);

it('tells the contexts of a run from the environment and what the CI plan says of it', function (
    RunOn|CannotTell $run,
    bool $inCi,
    array $config,
    string $spelt,
) use ($contexts): void {
    $chain = $contexts($run, $inCi, $config, CommandLine::nothing());

    expect($chain instanceof ContextChain ? $chain->spelt() : $chain)->toBe($spelt);
})->with([
    'outside CI, on any branch' => [fn(): RunOn => RunOn::at(Scope::branch('main'), Scope::branch('main')), false, [], 'local'],
    'a push to a feature branch' => [fn(): RunOn => RunOn::at(Scope::branch('feature'), Scope::branch('main')), true, [], 'ci'],
    'a push to the default branch' => [fn(): RunOn => RunOn::at(Scope::branch('main'), Scope::branch('main')), true, [], 'ci, main'],
    'a pull request' => [
        fn(): RunOn|CannotTell => RunOn::pullRequest(PullRequestNumber::of(7), Scope::branch('main'), Scope::branch('main')),
        true,
        [],
        'ci, pr',
    ],
    'a merge group' => [fn(): RunOn => RunOn::mergeGroup(Revision::ref('abc123'), Scope::branch('main')), true, [], 'ci, pr'],
    'the default branch ci.defaultBranch names' => [
        fn(): RunOn => RunOn::at(Scope::branch('trunk'), Scope::branch('main')),
        true,
        ['defaultBranch' => 'trunk'],
        'ci, main',
    ],
    'a run the CI plan says nothing of' => [fn(): CannotTell => CannotTell::because('A fake run.'), true, [], 'ci'],
]);

it('lays the block of the CI a built-in plan is for between ci and the kind of run', function (): void {
    $chain = new RunContext(Variables::of(['CI' => 'true', 'GITLAB_CI' => 'true']))->chain(
        CommandLine::nothing(),
        static fn(): Settings => Configs::settings(['runner' => 'pest', 'ci' => ['plan' => 'gitlab']]),
        Extensions::of(Origin::of('acme/gate')),
    );

    expect($chain instanceof ContextChain ? $chain->spelt() : $chain)->toStartWith('ci, gitlab');
});

it('puts the run in the context --context names, whatever the environment says', function () use ($contexts): void {
    $pullRequest = $contexts(
        CannotTell::because('A fake run.'),
        false,
        [],
        CommandLine::nothing()->defaultingTo(Context::PullRequest),
    );

    expect($pullRequest instanceof ContextChain ? $pullRequest->spelt() : $pullRequest)->toBe('ci, pr');
});
