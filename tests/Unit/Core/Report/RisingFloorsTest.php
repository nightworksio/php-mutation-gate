<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\RisingFloors;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('names each floor a score raises, trees first, then each security set by its package', function (): void {
    expect(RisingFloors::of(Verdicts::passing()))->toEqual([['src', Floor::of(100)]])
        ->and(RisingFloors::of(Verdicts::secured()))->toEqual([['security set of packages/billing', Floor::of(100)]])
        ->and(RisingFloors::of(Verdicts::empty()))->toBe([]);
});
