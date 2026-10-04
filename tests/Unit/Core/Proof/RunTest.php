<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Time\Instant;

it('is the run that established a proof, when, and the base it keyed at', function (): void {
    $at = Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z'));
    $run = Run::of('github:5813/2', $at, Digest::of(str_repeat('b', 64)));

    expect($run->id())->toBe('github:5813/2')
        ->and($run->at())->toBe($at)
        ->and($run->base())->toEqual(Digest::of(str_repeat('b', 64)));
});

it('records first killers unless it is asked to record every one', function (): void {
    $run = Run::of('local', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));

    expect($run->matrix())->toBe(MatrixKind::FirstKiller)
        ->and($run->recording(MatrixKind::Full)->matrix())->toBe(MatrixKind::Full)
        ->and($run->recording(MatrixKind::Full)->id())->toBe('local')
        ->and($run->matrix())->toBe(MatrixKind::FirstKiller);
});
