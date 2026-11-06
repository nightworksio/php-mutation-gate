<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Zstd\ZstdExtension;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\TooLarge;

$loaded = static fn(): bool => function_exists('zstd_uncompress_add');
$ledger = static fn(int $entries): string => implode(' ', array_map(static fn(int $i): string => hash('crc32b', (string) $i), range(0, $entries - 1)));

it('packs text smaller and unpacks what it packs, a step at a time, reading to the very limit', function () use ($ledger): void {
    $text = $ledger(50_000);
    $packed = ZstdExtension::loaded()->pack($text, 3);
    $bytes = is_string($packed) ? $packed : '';

    expect(strlen($bytes))->toBeGreaterThan(Gzip::STEP)->toBeLessThan(strlen($text))
        ->and(ZstdExtension::loaded()->unpackAtMost($bytes, 'The ledger', strlen($text)))->toBe($text);
})->skip(fn(): bool => ! $loaded(), 'The PHP extension zstd is not loaded.');

it('packs at the level it is asked, or at the nearest one it has', function () use ($ledger): void {
    $text = $ledger(4_000);
    $extension = ZstdExtension::loaded();

    expect($extension->pack($text, 0))->toBe($extension->pack($text, 1))
        ->and($extension->pack($text, 1))->not->toBe($extension->pack($text, 2))
        ->and($extension->pack($text, 25))->toBe($extension->pack($text, 19));
})->skip(fn(): bool => ! $loaded(), 'The PHP extension zstd is not loaded.');

it('says a stream is too large once a step takes it to the limit and more of it follows', function () use ($ledger): void {
    $packed = ZstdExtension::loaded()->pack($ledger(50_000), 3);
    $steps = str_split(is_string($packed) ? $packed : '', Gzip::STEP);
    $context = zstd_uncompress_init();
    $most = 0;

    while ($most === 0 && $context !== false && $steps !== []) {
        $piece = zstd_uncompress_add($context, array_shift($steps));
        $most = is_string($piece) ? strlen($piece) : -1;
    }

    expect($most)->toBeGreaterThan(0)
        ->and($steps)->not->toBe([])
        ->and(ZstdExtension::loaded()->unpackAtMost(is_string($packed) ? $packed : '', 'The ledger', $most))
        ->toEqual(TooLarge::because(sprintf('The ledger inflates to more than %d bytes.', $most)));
})->skip(fn(): bool => ! $loaded(), 'The PHP extension zstd is not loaded.');

it('says a stream that inflates past the limit is too large, and one that is broken is no whole stream, raising no warning', function (): void {
    $packed = ZstdExtension::loaded()->pack(str_repeat('x', 100_000), 3);
    $bytes = is_string($packed) ? $packed : '';
    error_clear_last();

    expect(ZstdExtension::loaded()->unpackAtMost($bytes, 'The ledger', 99_999))
        ->toEqual(TooLarge::because('The ledger inflates to more than 99999 bytes.'))
        ->and(ZstdExtension::loaded()->unpackAtMost("\x28\xb5\x2f\xfdnot a frame", 'The ledger', 100))
        ->toEqual(CannotJudge::because('The ledger is not a whole zstd stream.'))
        ->and(error_get_last())->toBeNull();
})->skip(fn(): bool => ! $loaded(), 'The PHP extension zstd is not loaded.');

it('is here only where the extension is loaded at a version that reads a stream a step at a time', function (): void {
    expect(ZstdExtension::loaded()->isHere())->toBe(function_exists('zstd_uncompress_add'));
});
