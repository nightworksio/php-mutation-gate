<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\LogCommands;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

it('prints the plan as the generic JSON', function (): void {
    $plan = ShardedPlan::of(2);
    $published = JsonPlan::in(Variables::of([]))->publish($plan);

    expect($published)->toEqual(Publication::printed(PlanListing::of($plan)))
        ->and(json_decode($published->text(), associative: true))->toBe([
            'plan' => $plan->digest()->value(),
            'commit' => '5eeca8f',
            'shards' => [
                ['id' => 1, 'label' => 'src, part 1 of 2', 'seconds' => 61, 'units' => ['src/1.php']],
                ['id' => 2, 'label' => 'src, part 2 of 2', 'seconds' => 121, 'units' => ['src/2.php']],
            ],
        ]);
});

it('prints even a plan with no shards, built from its options', function (): void {
    expect(JsonPlan::fromOptions(Options::none())->publish(ShardedPlan::of(0)))
        ->toEqual(Publication::printed(PlanListing::of(ShardedPlan::of(0))));
});

$on = static fn(Variables $variables): JsonPlan => JsonPlan::in($variables);

it('leaves the run to git', function (): void {
    expect(JsonPlan::in(Variables::of(['GITHUB_REF' => 'refs/heads/main']))->runOn())
        ->toEqual(CannotTell::because('The JSON plan knows nothing of the run, so git names its branch.'));
});

it('is run by no definition of its own', function (): void {
    expect(JsonPlan::in(Variables::of([]))->definitions())->toEqual(Paths::none());
});

it('withholds nothing of its own', function (): void {
    expect(JsonPlan::withheld())->toEqual(Withheld::nothing());
});

it('prints a plan whose paths hold log commands so a CI\'s log reads none, and every reader reads the paths back', function (): void {
    $printed = JsonPlan::in(Variables::of([]))->publish(ShardedPlan::hostile())->text();

    expect(LogCommands::in($printed))->toBe([])
        ->and(Decoded::at($printed, 'shards', 0, 'units'))->toBe([ShardedPlan::HOSTILE])
        ->and(Decoded::at($printed, 'shards', 0, 'label'))->toBe(ShardedPlan::HOSTILE);
});
