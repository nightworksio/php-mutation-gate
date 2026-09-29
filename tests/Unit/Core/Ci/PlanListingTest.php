<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

it('lists the plan, its commit and each shard with its label, whole seconds and units', function (): void {
    $plan = Plan::of(Revision::ref('5eeca8f'), Keys::none(), Shards::of(
        Shard::of(
            ShardId::of(1),
            Package::at(Path::root()),
            Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php'))),
            Seconds::of(12.4),
            'src, part 1 of 2',
        ),
        Shard::of(
            ShardId::of(2),
            Package::at(Path::root()),
            Units::of(Unit::file(Path::of('src/C.php'))),
            Seconds::of(12.6),
            'src, part 2 of 2',
        ),
        Shard::empty(ShardId::of(3)),
    ));

    expect(PlanListing::of($plan))->toBe(sprintf(<<<'JSON'
        {
            "plan": "%s",
            "commit": "5eeca8f",
            "shards": [
                {
                    "id": 1,
                    "label": "src, part 1 of 2",
                    "seconds": 12,
                    "units": [
                        "src/A.php",
                        "src/B.php"
                    ]
                },
                {
                    "id": 2,
                    "label": "src, part 2 of 2",
                    "seconds": 13,
                    "units": [
                        "src/C.php"
                    ]
                },
                {
                    "id": 3,
                    "label": "nothing to mutate",
                    "seconds": 0,
                    "units": []
                }
            ]
        }
        JSON, $plan->digest()->value()));
});

it('lists a plan with no shards', function (): void {
    $plan = Plan::of(Revision::ref('5eeca8f'), Keys::none(), Shards::none());

    expect(PlanListing::of($plan))->toBe(sprintf(
        "{\n    \"plan\": \"%s\",\n    \"commit\": \"5eeca8f\",\n    \"shards\": []\n}",
        $plan->digest()->value(),
    ));
});
