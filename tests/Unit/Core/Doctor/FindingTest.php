<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('holds what a check found, why, the fix, and the time at stake once measured', function (): void {
    $finding = Finding::of(Slug::NoTree, Severity::WillFail, 'found', 'why', 'fix');

    expect([$finding->slug(), $finding->severity(), $finding->found(), $finding->why(), $finding->fix()])
        ->toBe([Slug::NoTree, Severity::WillFail, 'found', 'why', 'fix'])
        ->and($finding->atStake())->toEqual(Unmeasured::duration())
        ->and($finding->costing(Seconds::of(90.0))->atStake())->toEqual(Seconds::of(90.0))
        ->and($finding->costing(Seconds::of(90.0))->found())->toBe('found');
});
