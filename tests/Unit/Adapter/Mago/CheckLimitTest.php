<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Mago\CheckLimit;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('leaves the seconds of the limit not yet spent', function (): void {
    expect(CheckLimit::from(Seconds::of(3_600))->left()->seconds())->toBeGreaterThan(3_599.0)->toBeLessThanOrEqual(3_600.0);
});

it('leaves a millisecond once the limit has passed, since Symfony\'s Process reads a limit of 0 as none', function (): void {
    expect(CheckLimit::from(Seconds::of(0))->left()->seconds())->toBe(0.001)
        ->and(CheckLimit::from(Seconds::of(-5))->left()->seconds())->toBe(0.001);
});

it('says the check did not finish in its limit', function (): void {
    expect(CheckLimit::from(Seconds::of(30))->unfinished())
        ->toEqual(CannotJudge::because(sprintf('Mago did not finish a check in %s.', Seconds::of(30)->written())));
});
