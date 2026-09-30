<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\Variables;
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
    $plan = ShardedPlan::of(2);

    expect(JsonPlan::printing($file, Variables::of([]))->publish($plan))->toEqual(Written::to($file))
        ->and(json_decode((string) file_get_contents($file), associative: true))->toBe([
            'plan' => $plan->digest()->value(),
            'commit' => '5eeca8f',
            'shards' => [
                ['id' => 1, 'label' => 'src, part 1 of 2', 'seconds' => 61, 'units' => ['src/1.php']],
                ['id' => 2, 'label' => 'src, part 2 of 2', 'seconds' => 121, 'units' => ['src/2.php']],
            ],
        ]);
});

it('prints to the output', function (): void {
    ob_start();
    $written = JsonPlan::fromOptions(Options::none())->publish(ShardedPlan::of(0));
    $printed = ob_get_clean();

    expect($written)->toEqual(Written::to('php://output'))
        ->and($printed)->toBe(PlanListing::of(ShardedPlan::of(0)));
});

it('cannot judge a plan it cannot print', function (): void {
    $root = Scratch::directory();
    $json = JsonPlan::printing(sprintf('%s/missing/plan.json', $root), Variables::of([]));
    set_error_handler(static fn(): bool => true);
    $written = $json->publish(ShardedPlan::of(1));
    restore_error_handler();

    expect($written)->toEqual(CannotJudge::because(sprintf('%s/missing/plan.json could not be written.', $root)));
});

$on = static fn(Variables $variables): JsonPlan => JsonPlan::printing('', $variables);

it('names the shard a job was started as, or the generic parallel variables say', function () use ($on): void {
    expect($on(Variables::of(['SHARD' => '2']))->shard(ShardedPlan::of(2)))->toEqual(ShardId::of(2))
        ->and($on(Variables::of(['CI_NODE_INDEX' => '2', 'CI_NODE_TOTAL' => '2']))->shard(ShardedPlan::of(2)))
        ->toEqual(ShardId::of(2))
        ->and($on(Variables::of(['BUILDKITE_PARALLEL_JOB' => '0']))->shard(ShardedPlan::of(2)))
        ->toEqual(ShardId::of(1));
});

it('leaves the run to git', function (): void {
    expect(JsonPlan::printing('', Variables::of(['GITHUB_REF' => 'refs/heads/main']))->runOn())
        ->toEqual(CannotTell::because('The JSON plan knows nothing of the run, so git names its branch.'));
});

it('is run by no definition of its own', function (): void {
    expect(JsonPlan::printing('', Variables::of([]))->definitions())->toEqual(Paths::none());
});

it('withholds nothing of its own', function (): void {
    expect(JsonPlan::printing('', Variables::of([]))->withheld())->toEqual(Withheld::nothing());
});
