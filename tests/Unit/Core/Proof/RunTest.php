<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Time\Instant;

it('is the run that established a proof, when, and the base it keyed at', function (): void {
    $at = Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z'));
    $run = Run::of('github:5813/2', $at, Digest::of(str_repeat('b', 64)));

    expect($run->id())->toBe('github:5813/2')
        ->and($run->at())->toBe($at)
        ->and($run->base())->toEqual(Digest::of(str_repeat('b', 64)));
});
