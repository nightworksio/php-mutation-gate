<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
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
