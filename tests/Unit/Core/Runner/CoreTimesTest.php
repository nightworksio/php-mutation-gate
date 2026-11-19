<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\CoreTimes;

it('says the cores busy on average between two readings, of all the machine has', function (): void {
    $earlier = CoreTimes::of(500, 2_000, 4);

    expect(CoreTimes::of(600, 2_400, 4)->busySince($earlier))->toBe('cores 1.0 of 4 busy')
        ->and(CoreTimes::of(900, 2_400, 4)->busySince($earlier))->toBe('cores 4.0 of 4 busy')
        ->and(CoreTimes::of(500, 2_400, 4)->busySince($earlier))->toBe('cores 0.0 of 4 busy')
        ->and(CoreTimes::of(650, 2_400, 2)->busySince($earlier))->toBe('cores 0.8 of 2 busy');
});

it('says nothing where no tick passed or the machine names no core, and never fewer than none busy', function (): void {
    $earlier = CoreTimes::of(500, 2_000, 4);

    expect(CoreTimes::of(500, 2_000, 4)->busySince($earlier))->toBe('')
        ->and(CoreTimes::of(600, 1_900, 4)->busySince($earlier))->toBe('')
        ->and(CoreTimes::of(600, 2_400, 0)->busySince($earlier))->toBe('')
        ->and(CoreTimes::of(400, 2_400, 4)->busySince($earlier))->toBe('cores 0.0 of 4 busy');
});
