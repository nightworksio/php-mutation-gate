<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\CircleCi\CircleCiPlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

afterEach(function (): void {
    Scratch::sweep();
});

it('prints the plan as the generic JSON', function (): void {
    $file = sprintf('%s/plan.json', Scratch::directory());

    expect(CircleCiPlan::printing($file, Variables::of([]))->publish(ShardedPlan::of(2)))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(PlanListing::of(ShardedPlan::of(2)));
});

it('prints to the output', function (): void {
    ob_start();
    $written = CircleCiPlan::fromOptions(Options::none())->publish(ShardedPlan::of(1));
    $printed = ob_get_clean();

    expect($written)->toEqual(Written::to('php://output'))
        ->and($printed)->toBe(PlanListing::of(ShardedPlan::of(1)));
});

it('cannot judge a plan it cannot print', function (): void {
    $root = Scratch::directory();
    $circle = CircleCiPlan::printing(sprintf('%s/missing/plan.json', $root), Variables::of([]));
    set_error_handler(static fn(): bool => true);
    $written = $circle->publish(ShardedPlan::of(1));
    restore_error_handler();

    expect($written)->toEqual(CannotJudge::because(sprintf('%s/missing/plan.json could not be written.', $root)));
});

it('runs the shard after the node\'s index, which counts from nought', function (): void {
    $node = Variables::of(['CIRCLE_NODE_INDEX' => '0', 'CIRCLE_NODE_TOTAL' => '2']);

    expect(CircleCiPlan::printing('', $node)->shard(ShardedPlan::of(2)))->toEqual(ShardId::of(1));
});

it('cannot judge nodes that are not as many as the plan\'s shards', function (): void {
    $node = Variables::of(['CIRCLE_NODE_INDEX' => '0', 'CIRCLE_NODE_TOTAL' => '4']);

    expect(CircleCiPlan::printing('', $node)->shard(ShardedPlan::of(2)))->toEqual(CannotJudge::because(
        'CIRCLE_NODE_TOTAL is 4, and the plan holds 2 shards. Plan with --shards=4, so each job has a shard.',
    ));
});

it('reads a pull request from its address, and a branch otherwise', function (): void {
    $unnamed = CannotTell::because('CircleCI does not name the default branch. Set ci.defaultBranch.');
    $pullRequest = Variables::of([
        'CIRCLE_BRANCH' => 'feature',
        'CIRCLE_PULL_REQUEST' => 'https://github.com/acme/app/pull/31',
    ]);
    $branch = Variables::of(['CIRCLE_BRANCH' => 'feature']);

    expect(CircleCiPlan::printing('', $pullRequest)->runOn())->toEqual(RunOn::pullRequest('31', $unnamed))
        ->and(CircleCiPlan::printing('', $branch)->runOn())->toEqual(RunOn::branch('feature', $unnamed));
});

it('gives no scope to a tag, which is no branch the gate writes for', function (): void {
    expect(CircleCiPlan::printing('', Variables::of(['CIRCLE_TAG' => 'v1']))->runOn())->toEqual(RunOn::detached(
        CannotTell::because('CircleCI does not name the default branch. Set ci.defaultBranch.'),
    ));
});

it('is run by the config CircleCI reads from the repository', function (): void {
    expect(CircleCiPlan::printing('', Variables::of([]))->definitions())->toEqual(Paths::of(Path::of('.circleci/config.yml')));
});

it('withholds the job\'s OpenID Connect tokens', function (): void {
    expect(CircleCiPlan::printing('', Variables::of([]))->withheld())->toEqual(Withheld::of('CIRCLE_OIDC_TOKEN*'));
});
