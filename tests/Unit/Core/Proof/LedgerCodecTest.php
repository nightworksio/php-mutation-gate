<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\NoZstandard;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Proof\Compression;
use NightWorksIO\MutationGate\Core\Proof\LedgerCodec;
use NightWorksIO\MutationGate\Tests\Fakes\ZstandardFake;

it('names auto, gzip and zstd as proofs.compression writes them', function (): void {
    expect(array_map(static fn(Compression $compression): string => $compression->value, Compression::cases()))
        ->toBe(['auto', 'gzip', 'zstd']);
});

it('packs gzip at the level asked, gzip\'s highest past it, and zstd where it is named', function (): void {
    $text = str_repeat('a ledger of proofs ', 2_000);

    expect(LedgerCodec::of(Compression::Gzip, 1, ZstandardFake::here())->pack($text))->toBe(Gzip::packAt($text, 1))
        ->and(LedgerCodec::of(Compression::Gzip, 15, ZstandardFake::here())->pack($text))->toBe(Gzip::packAt($text, 9))
        ->and(LedgerCodec::of(Compression::Zstd, 15, ZstandardFake::here())->pack($text))
        ->toBe(sprintf('%sfake:15:%s', ZstandardFake::MAGIC, $text))
        ->and(LedgerCodec::gzipOnly()->pack($text))->toBe(Gzip::packAt($text, LedgerCodec::LEVEL));
});

it('packs zstd by itself where it is here and gzip where it is not, and refuses zstd named where it is not', function (): void {
    expect(LedgerCodec::of(Compression::Auto, 3, ZstandardFake::here())->pack('text'))->toBe(sprintf('%sfake:03:text', ZstandardFake::MAGIC))
        ->and(LedgerCodec::of(Compression::Auto, 3, ZstandardFake::absent())->pack('text'))->toBe(Gzip::packAt('text', 3))
        ->and(LedgerCodec::of(Compression::Zstd, 3, ZstandardFake::absent())->pack('text'))
        ->toEqual(CannotJudge::because('fake is not here'));
});

it('reads gzip or zstd by a file\'s first bytes, whatever wrote it', function (): void {
    $codec = LedgerCodec::of(Compression::Gzip, 9, ZstandardFake::here());
    $zstd = sprintf('%sfake:09:text', ZstandardFake::MAGIC);

    expect($codec->unpackAtMost(Gzip::pack('text'), 'The ledger', 100))->toBe('text')
        ->and($codec->unpackAtMost($zstd, 'The ledger', 100))->toBe('text')
        ->and($codec->unpackAtMost($zstd, 'The ledger', 2))->toEqual(TooLarge::because('The ledger inflates past 2'))
        ->and(LedgerCodec::gzipOnly()->unpackAtMost($zstd, 'The ledger', 100))
        ->toEqual(NoZstandard::here()->unpackAtMost($zstd, 'The ledger', 100))
        ->and(LedgerCodec::gzipOnly()->unpackAtMost('text', 'The ledger', 100))
        ->toEqual(CannotJudge::because('The ledger is not a whole gzip stream.'));
});
