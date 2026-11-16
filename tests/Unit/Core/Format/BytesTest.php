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

it('finds where text last occurs wholly before a byte offset, and says where it does not', function (): void {
    expect(Bytes::findBefore("€\na\nb", "\n", 6))->toBe(5)
        ->and(Bytes::findBefore("€\na\nb", "\n", 5))->toBe(3)
        ->and(Bytes::findBefore("€\na\nb", "\n", 3))->toBeFalse()
        ->and(Bytes::findBefore("\nab", "\n", 1))->toBe(0)
        ->and(Bytes::findBefore('aXbXc', 'Xb', 3))->toBe(1)
        ->and(Bytes::findBefore('aXbXc', 'Xb', 2))->toBeFalse()
        ->and(Bytes::findBefore('', "\n", 0))->toBeFalse();
});

it('finds where the line holding a byte offset starts and ends', function (): void {
    expect([Bytes::lineStartOf("ab\n€\nc", 0), Bytes::lineStartOf("ab\n€\nc", 2), Bytes::lineStartOf("ab\n€\nc", 3)])
        ->toBe([0, 0, 3])
        ->and([Bytes::lineStartOf("ab\n€\nc", 6), Bytes::lineStartOf("ab\n€\nc", 7)])->toBe([3, 7])
        ->and([Bytes::lineEndOf("ab\n€\nc", 0), Bytes::lineEndOf("ab\n€\nc", 2), Bytes::lineEndOf("ab\n€\nc", 3)])
        ->toBe([2, 2, 6])
        ->and([Bytes::lineEndOf("ab\n€\nc", 7), Bytes::lineEndOf("ab\n€\nc", 8)])->toBe([8, 8]);
});

it('cuts bytes from an offset, as many as asked or as there are', function (): void {
    expect(Bytes::slice("€\0ab", 3, 2))->toBe("\0a")
        ->and(Bytes::slice('€', 0, 2))->toBe("\xE2\x82")
        ->and(Bytes::slice('ab', 1, 5))->toBe('b');
});

it('gives the bytes of a text from an offset to its end, counting each byte of a character', function (): void {
    expect(Bytes::from('aÜb', 1))->toBe('Üb')
        ->and(Bytes::from('aÜb', 2))->toBe("\x9Cb")
        ->and(Bytes::from('ab', 2))->toBe('');
});

it('has a reader take a byte past a limit, and one of a limit below none', function (): void {
    expect([Bytes::onePast(3), Bytes::onePast(0), Bytes::onePast(-5)])->toBe([4, 1, 1]);
});
