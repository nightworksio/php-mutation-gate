<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Bytes;

it('counts bytes, not characters', function (): void {
    expect(Bytes::length('€uro'))->toBe(6)
        ->and(Bytes::length(''))->toBe(0);
});

it('finds text from a byte offset on, at its byte offset, and says where it does not occur', function (): void {
    expect(Bytes::find("€\na\nb", "\n", 0))->toBe(3)
        ->and(Bytes::find("€\na\nb", "\n", 4))->toBe(5)
        ->and(Bytes::find("€\na\nb", "\n", 6))->toBeFalse();
});

it('cuts bytes from an offset, as many as asked or as there are', function (): void {
    expect(Bytes::slice("€\0ab", 3, 2))->toBe("\0a")
        ->and(Bytes::slice('€', 0, 2))->toBe("\xE2\x82")
        ->and(Bytes::slice('ab', 1, 5))->toBe('b');
});
