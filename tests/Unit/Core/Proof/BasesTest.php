<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Bases;

$digest = static fn(string $of): Digest => Digest::of(str_repeat($of, 64));
$values = static fn(Bases $bases): array => array_map(static fn(Digest $base): string => $base->value()[0], iterator_to_array($bases, preserve_keys: true));

it('holds no base to begin with', function (): void {
    expect(Bases::none())->toHaveCount(0);
});

it('holds each base once, where it was first named', function () use ($digest, $values): void {
    $bases = Bases::of($digest('a'), $digest('b'), $digest('a'), $digest('1'));

    expect($values($bases))->toBe(['a', 'b', '1'])
        ->and($bases)->toHaveCount(3)
        ->and($bases->has($digest('1')))->toBeTrue()
        ->and($bases->has($digest('c')))->toBeFalse();
});

it('puts a base it sees first, once, and leaves the bases it came from as they were', function () use ($digest, $values): void {
    $bases = Bases::of($digest('a'), $digest('b'));

    expect($values($bases->seen($digest('b'))))->toBe(['b', 'a'])
        ->and($values($bases->seen($digest('c'))))->toBe(['c', 'a', 'b'])
        ->and($values($bases))->toBe(['a', 'b']);
});

it('joins another\'s bases after its own', function () use ($digest, $values): void {
    expect($values(Bases::of($digest('a'), $digest('b'))->and(Bases::of($digest('c'), $digest('a')))))->toBe(['a', 'b', 'c']);
});
