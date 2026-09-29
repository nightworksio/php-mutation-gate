<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Time\Instant;

it('is the run that established a proof, and when', function (): void {
    $at = Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z'));
    $run = Run::of('github:5813/2', $at);

    expect($run->id())->toBe('github:5813/2')
        ->and($run->at())->toBe($at);
});
