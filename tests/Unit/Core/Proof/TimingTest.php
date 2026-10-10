<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\GateRelease;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('is what mutating a unit took, which runner took it, and when it was measured', function (): void {
    $at = Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z'));
    $timing = Timing::of(Path::of('src/Money.php'), Seconds::of(12.4), 'infection', $at);

    expect($timing->unit()->value())->toBe('src/Money.php')
        ->and($timing->seconds())->toEqual(Seconds::of(12.4))
        ->and($timing->runner())->toBe('infection')
        ->and($timing->at())->toBe($at);
});

it('records which gate measured it, and none where a ledger did not say', function (): void {
    $at = Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z'));
    $timing = Timing::of(Path::of('src/Money.php'), Seconds::of(12.4), 'infection', $at);
    $measured = $timing->measuredBy(GateRelease::spelt('nightworksio/mutation-gate 1.2.0'));

    expect($timing->gate())->toEqual(GateRelease::unrecorded())
        ->and($measured->gate()->value())->toBe('nightworksio/mutation-gate 1.2.0')
        ->and([$measured->unit(), $measured->seconds(), $measured->runner(), $measured->at()])
        ->toEqual([$timing->unit(), $timing->seconds(), $timing->runner(), $timing->at()])
        ->and($measured->smoothedOver($measured)->gate()->value())->toBe('nightworksio/mutation-gate 1.2.0');
});
