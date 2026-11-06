<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Sample\SampledRun;

it('is named as sampling.autoRuns lists it', function (): void {
    expect(array_map(static fn(SampledRun $run): string => $run->value, SampledRun::cases()))
        ->toBe(['pullRequests', 'mergeGroups', 'branches']);
});

it('is the kind of a pull request\'s, a merge group\'s, or another branch\'s run', function (RunOn $run, SampledRun $kind): void {
    expect(SampledRun::of($run))->toBe($kind);
})->with([
    'a pull request' => fn(): array => [RunOn::at(Scope::pullRequest(7), Scope::branch('main')), SampledRun::PullRequests],
    'a merge group' => fn(): array => [RunOn::mergeGroup(Revision::ref('base'), Scope::branch('main')), SampledRun::MergeGroups],
    'another branch' => fn(): array => [RunOn::at(Scope::branch('feat/x'), Scope::branch('main')), SampledRun::Branches],
]);

it('is no kind for the default branch, a tag, a detached run, or a branch whose default it cannot tell', function (RunOn $run): void {
    expect(SampledRun::of($run))->toEqual(NotGiven::value());
})->with([
    'the default branch' => fn(): RunOn => RunOn::at(Scope::branch('main'), Scope::branch('main')),
    'a tag' => fn(): RunOn => RunOn::at(Scope::of('refs/tags/v1.0.0'), Scope::branch('main')),
    'detached' => fn(): RunOn => RunOn::detached(Scope::branch('main')),
    'no default branch' => fn(): RunOn => RunOn::at(Scope::branch('feat/x'), CannotTell::because('no default branch')),
]);
