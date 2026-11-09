<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\PostLimits;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('reads the waits an alert recorded, none at all and a part of a second among them', function (): void {
    expect(PostLimits::recorded(Node::decode('{"timeout": 0, "longestWait": 0.5}')))
        ->toEqual(PostLimits::of(Seconds::of(0.0), Seconds::of(0.5)));
});

it('refuses a wait that is negative', function (): void {
    expect(static fn(): PostLimits => PostLimits::recorded(Node::decode('{"timeout": -0.5}')))->toThrow(NotInShape::class);
});
