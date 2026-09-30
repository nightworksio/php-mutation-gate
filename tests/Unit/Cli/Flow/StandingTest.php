<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Unnamed;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A CI that says nothing of the run. */
$silent = static fn(): CiPlanFake => new CiPlanFake(ShardId::of(1), CannotTell::because('No CI runs this.'));

/** Where a run stands. */
function standingOf(CiPlanFake $ci, RepositoryFake $repository, string|Absent $configured): Standing
{
    $standing = Standing::of($ci, $repository, $configured);

    return $standing instanceof Standing ? $standing : throw new RuntimeException($standing->why());
}

it('cannot tie a run to a commit where HEAD cannot be read', function () use ($silent): void {
    expect(Standing::of($silent(), Flows::lost(), Absent::setting()))->toEqual(CannotJudge::because(
        'The commit HEAD is at cannot be read, so the run cannot be tied to one. git is not installed.',
    ));
});

it('stands where the CI says, at the commit HEAD is at', function (): void {
    $ci = new CiPlanFake(ShardId::of(1), RunOn::at(Scope::pullRequest(7), Scope::branch('trunk')));
    $at = standingOf($ci, RepositoryFake::onMain(Revision::ref('head')), Absent::setting());

    expect($at->head())->toEqual(Revision::ref('head'))
        ->and($at->runOn()->scope())->toEqual(Scope::pullRequest(7))
        ->and($at->runOn()->isPullRequest())->toBeTrue()
        ->and($at->defaultBranch())->toEqual(Scope::branch('trunk'))
        ->and($at->runOn()->commit())->toEqual(Unnamed::commit());
});

it('gives no scope to a default-branch run whose checkout is not the commit the CI builds', function (): void {
    $run = RunOn::at(Scope::branch('main'), Scope::branch('main'))->withCommit(Revision::ref('built'));
    $at = standingOf(
        new CiPlanFake(ShardId::of(1), $run),
        RepositoryFake::onMain(Revision::ref('head')),
        Absent::setting(),
    );

    expect($at->runOn()->scope())->toEqual(Detached::head());
});

it('stands on git\'s branch where the CI says nothing, and on no ref when detached', function () use ($silent): void {
    $onBranch = new RepositoryFake(Revision::ref('head'), Scope::branch('feature'), Scope::branch('main'));
    $detached = RepositoryFake::detachedAt(Revision::ref('head'));

    expect(standingOf($silent(), $onBranch, Absent::setting())->runOn()->scope())->toEqual(Scope::branch('feature'))
        ->and(standingOf($silent(), $onBranch, Absent::setting())->runOn()->isPullRequest())->toBeFalse()
        ->and(standingOf($silent(), $detached, Absent::setting())->runOn()->scope())->toEqual(Detached::head());
});

it('takes the default branch from the config, then the CI, then git, then main', function (
    string|Absent $configured,
    Scope|CannotTell $fromCi,
    Scope|CannotTell $fromGit,
    Scope $default,
): void {
    $ci = new CiPlanFake(ShardId::of(1), RunOn::at(Scope::branch('feature'), $fromCi));
    $repository = new RepositoryFake(Revision::ref('head'), Scope::branch('feature'), $fromGit);
    $at = standingOf($ci, $repository, $configured);

    expect($at->defaultBranch())->toEqual($default)
        ->and($at->runOn()->defaultBranch())->toEqual($default);
})->with([
    'the config' => ['trunk', Scope::branch('ci'), Scope::branch('git'), Scope::branch('trunk')],
    'the CI' => [Absent::setting(), Scope::branch('ci'), Scope::branch('git'), Scope::branch('ci')],
    'git' => [Absent::setting(), CannotTell::because('unnamed'), Scope::branch('git'), Scope::branch('git')],
    'main' => [
        Absent::setting(),
        CannotTell::because('unnamed'),
        CannotTell::because('unnamed'),
        Scope::branch('main'),
    ],
    'a config that names no branch' => ['..', Scope::branch('ci'), Scope::branch('git'), Scope::branch('ci')],
]);

it('stands where a plan says it was made', function (): void {
    $run = RunOn::at(Scope::pullRequest(7), Scope::branch('trunk'));
    $plan = Plan::of(Revision::ref('made'), Digest::sha256Of('base'), Keys::none(), Shards::none());
    $at = Standing::planned($plan->on($run));

    expect($at->head())->toEqual(Revision::ref('made'))
        ->and($at->runOn())->toEqual($run)
        ->and($at->defaultBranch())->toEqual(Scope::branch('trunk'));
});

it('takes main as the default branch of a plan that names none', function (): void {
    $plan = Plan::of(Revision::ref('made'), Digest::sha256Of('base'), Keys::none(), Shards::none())
        ->on(RunOn::detached(CannotTell::because('unnamed')));

    expect(Standing::planned($plan)->defaultBranch())->toEqual(Scope::branch('main'));
});
