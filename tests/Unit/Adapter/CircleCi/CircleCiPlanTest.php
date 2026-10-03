<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\CircleCi\CircleCiPlan;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\LogCommands;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

it('prints the plan as the generic JSON', function (): void {
    expect(CircleCiPlan::in(Variables::of([]))->publish(ShardedPlan::of(2)))
        ->toEqual(Publication::printed(PlanListing::of(ShardedPlan::of(2))));
});

it('prints the plan, built from its options', function (): void {
    expect(CircleCiPlan::fromOptions(Options::none())->publish(ShardedPlan::of(1)))
        ->toEqual(Publication::printed(PlanListing::of(ShardedPlan::of(1))));
});

it('reads a pull request from its address, and a branch otherwise', function (): void {
    $unnamed = CannotTell::because('CircleCI does not name the default branch. Set ci.defaultBranch.');
    $pullRequest = Variables::of([
        'CIRCLE_BRANCH' => 'feature',
        'CIRCLE_PULL_REQUEST' => 'https://github.com/acme/app/pull/31',
    ]);
    $branch = Variables::of(['CIRCLE_BRANCH' => 'feature']);

    expect(CircleCiPlan::in($pullRequest)->runOn())->toEqual(RunOn::pullRequest(PullRequestNumber::parse('31'), $unnamed))
        ->and(CircleCiPlan::in($branch)->runOn())->toEqual(RunOn::branch('feature', $unnamed));
});

it('gives no scope to a tag, which is no branch the gate writes for', function (): void {
    expect(CircleCiPlan::in(Variables::of(['CIRCLE_TAG' => 'v1']))->runOn())->toEqual(RunOn::detached(
        CannotTell::because('CircleCI does not name the default branch. Set ci.defaultBranch.'),
    ));
});

it('is run by the config CircleCI reads from the repository', function (): void {
    expect(CircleCiPlan::in(Variables::of([]))->definitions())->toEqual(Paths::of(Path::of('.circleci/config.yml')));
});

it('withholds the job\'s OpenID Connect tokens', function (): void {
    expect(CircleCiPlan::withheld())->toEqual(Withheld::of('CIRCLE_OIDC_TOKEN*'));
});

it('prints a plan whose paths hold log commands so a CI\'s log reads none, and every reader reads the paths back', function (): void {
    $printed = CircleCiPlan::in(Variables::of([]))->publish(ShardedPlan::hostile())->text();

    expect(LogCommands::in($printed))->toBe([])
        ->and(Decoded::at($printed, 'shards', 0, 'units'))->toBe([ShardedPlan::HOSTILE]);
});
