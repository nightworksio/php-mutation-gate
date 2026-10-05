<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\TooLarge;

/** Bytes that do not compress, the same each run: 32,000 of them. */
function gzipNoise(): string
{
    return implode('', array_map(static fn(int $at): string => hash('sha256', (string) $at, binary: true), range(1, 1_000)));
}

it('packs text smaller, and unpacks what it packs', function (): void {
    $text = str_repeat('{"unit":"src/Money.php"}', 100);

    expect(Gzip::unpackAtMost(Gzip::pack($text), 'the ledger', strlen($text)))->toBe($text)
        ->and(Gzip::unpackAtMost(Gzip::pack(''), 'the ledger', 0))->toBe('')
        ->and(strlen(Gzip::pack($text)))->toBeLessThan(strlen($text));
});

it('unpacks a stream that inflates to no more than the limit', function (): void {
    $text = str_repeat('{"unit":"src/Money.php"}', 1_000);

    expect(Gzip::unpackAtMost(Gzip::pack($text), 'the ledger', strlen($text)))->toBe($text)
        ->and(Gzip::unpackAtMost(Gzip::pack(''), 'the ledger', 0))->toBe('');
});

it('says a stream that inflates past the limit is too large', function (): void {
    $text = str_repeat('{"unit":"src/Money.php"}', 1_000);

    expect(Gzip::unpackAtMost(Gzip::pack($text), 'the ledger', strlen($text) - 1))
        ->toEqual(TooLarge::because(sprintf('the ledger inflates to more than %d bytes.', strlen($text) - 1)));
});

it('stops inflating a small stream that inflates without end soon past the limit', function (): void {
    $bomb = Gzip::pack(str_repeat('0', 50_000_000));
    memory_reset_peak_usage();
    $before = memory_get_peak_usage();

    $unpacked = Gzip::unpackAtMost($bomb, 'the ledger', 1_000_000);

    expect(strlen($bomb))->toBeLessThan(100_000)
        ->and($unpacked)->toEqual(TooLarge::because('the ledger inflates to more than 1000000 bytes.'))
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(16_000_000);
});

it('cannot unpack within a limit what is not a whole gzip stream', function (string $bytes): void {
    expect(Gzip::unpackAtMost($bytes, 'the ledger', 1_000_000))
        ->toEqual(CannotJudge::because('the ledger is not a whole gzip stream.'));
})->with([
    'text' => ['{"format": 2}'],
    'nothing' => [''],
    'a stream cut short' => [substr(Gzip::pack(str_repeat('a', 1000)), 0, 12)],
    'a stream cut short past a step' => [substr(Gzip::pack(gzipNoise()), 0, 10_000)],
    'the magic alone' => ["\x1f\x8b"],
    'a stream with a broken body' => [substr_replace(Gzip::pack(str_repeat('abc', 20_000)), str_repeat("\xff", 64), 20, 64)],
]);

it('leaves what follows a stream unread, wherever the stream ends', function (string $after): void {
    $ends = [];
    $unpacked = [];
    $expected = [];

    foreach (range(4_060, 4_090) as $length) {
        $text = substr(gzipNoise(), 0, $length);
        $packed = Gzip::pack($text);
        $ends[] = strlen($packed);
        $followed = sprintf('%s%s', $packed, $after);
        $unpacked[] = Gzip::unpackAtMost($followed, 'the ledger', 1_000_000);
        $expected[] = $text;
    }

    expect($ends)->toContain(4_095, 4_096, 4_097)
        ->and($unpacked)->toBe($expected);
})->with([
    'bytes that are not gzip' => ['xyz'],
    'a second stream' => [Gzip::pack('more')],
]);
