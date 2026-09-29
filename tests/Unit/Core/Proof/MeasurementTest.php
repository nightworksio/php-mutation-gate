<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('is the time a shard spent mutating, which runner spent it, and when', function (): void {
    $at = Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z'));
    $measured = Measurement::of(Seconds::of(540.5), 'pest', $at);

    expect($measured->spent())->toEqual(Seconds::of(540.5))
        ->and($measured->runner())->toBe('pest')
        ->and($measured->at())->toBe($at);
});
