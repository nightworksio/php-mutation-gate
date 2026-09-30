<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Alert\Signature;

it('signs the time and the body together, so a receiver can refuse an old post', function (): void {
    $now = new DateTimeImmutable('2026-09-30T12:00:00Z');

    expect(Signature::of('{"format":1}', 'sesame', $now))
        ->toBe(sprintf('t=1790769600,sha256=%s', hash_hmac('sha256', '1790769600.{"format":1}', 'sesame')))
        ->and(Signature::of('{"format":1}', 'sesame', $now->modify('+1 second')))
        ->toStartWith('t=1790769601,sha256=')
        ->and(Signature::HEADER)->toBe('X-Mutation-Gate-Signature');
});

it('differs from a signature of the body alone, and from one under another secret', function (): void {
    $now = new DateTimeImmutable('2026-09-30T12:00:00Z');
    $signature = Signature::of('{}', 'sesame', $now);

    expect($signature)->not->toContain(hash_hmac('sha256', '{}', 'sesame'))
        ->and($signature)->not->toBe(Signature::of('{}', 'other', $now));
});
