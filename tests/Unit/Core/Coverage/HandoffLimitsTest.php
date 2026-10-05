<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\HandoffLimits;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\TooLarge;

it('reads a handed map at up to 256 MB compressed, a reader taking one byte past, and a 23rd of the memory decompressed', function (): void {
    $under = HandoffLimits::under('230M');

    expect($under->packed())->toBe(256_000_000)
        ->and($under->readable())->toBe(256_000_001)
        ->and($under->unpacked())->toBe(intdiv(230 * 1_048_576, 23))
        ->and(HandoffLimits::under('-1')->unpacked())->toBe(1_024 * 1_048_576)
        ->and(HandoffLimits::under(memoryLimit: false)->unpacked())->toBe(1_024 * 1_048_576)
        ->and(HandoffLimits::under('0')->unpacked())->toBe(0)
        ->and($under->inflated(Gzip::pack('{"format": 1}')))->toBe('{"format": 1}');
});

it('inflates a map no further than 300 times its compressed bytes under the standard limits', function (): void {
    $packed = Gzip::pack(str_repeat('a', 100_000));

    expect(HandoffLimits::under('-1')->inflated($packed))
        ->toEqual(TooLarge::because(sprintf('The coverage map inflates to more than %d bytes.', 300 * strlen($packed))));
});

it('inflates a map within its limits, to the byte, and says why it does not one past any of them', function (): void {
    $packed = Gzip::pack(str_repeat('a', 100));
    $limits = HandoffLimits::of(strlen($packed), 100, 1_000);

    expect($limits->inflated($packed))->toBe(str_repeat('a', 100))
        ->and(HandoffLimits::of(strlen($packed) - 1, 100, 1_000)->inflated($packed))->toEqual(TooLarge::because(sprintf(
            'The coverage map is larger than %d bytes, so no line of it is read.',
            strlen($packed) - 1,
        )))
        ->and(HandoffLimits::of(strlen($packed), 99, 1_000)->inflated($packed))
        ->toEqual(TooLarge::because('The coverage map inflates to more than 99 bytes.'))
        ->and(HandoffLimits::of(strlen($packed), 100, 1)->inflated($packed))
        ->toEqual(TooLarge::because(sprintf('The coverage map inflates to more than %d bytes.', strlen($packed))))
        ->and($limits->inflated('not gzip'))
        ->toEqual(CannotJudge::because('The coverage map is not a whole gzip stream.'));
});

it('has a reader take a byte of a map whose limit is below nothing', function (): void {
    expect(HandoffLimits::of(-5, 1, 1)->readable())->toBe(1);
});
