<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\StoredZip;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The archive of these files, as hexadecimal digits.
 *
 * @param array<string, string> $files
 */
function storedHex(array $files): string
{
    $digits = unpack('H*', StoredZip::of($files));

    return is_array($digits) && is_string($digits[1] ?? null) ? $digits[1] : '';
}

it('stores each file as it is, under its name, in an archive zip reads back', function (): void {
    $archive = sprintf('%s/files.zip', Scratch::directory());
    file_put_contents($archive, StoredZip::of(['progress.txt' => "half way\n", 'empty' => '']));
    $zip = new ZipArchive();
    $opened = $zip->open($archive, ZipArchive::CHECKCONS);

    expect($opened)->toBeTrue()
        ->and($zip->count())->toBe(2)
        ->and($zip->getNameIndex(0))->toBe('progress.txt')
        ->and($zip->getFromName('progress.txt'))->toBe("half way\n")
        ->and($zip->getFromName('empty'))->toBe('')
        ->and($zip->statName('progress.txt'))->toMatchArray(['comp_method' => ZipArchive::CM_STORE, 'size' => 9, 'crc' => crc32("half way\n")]);
})->skip(fn(): bool => ! class_exists(ZipArchive::class), 'reading a zip takes the zip extension');

it('makes the same bytes of the same files, dated the first day zip can date', function (): void {
    expect(storedHex(['a' => 'b']))->toBe(implode('', [
        '504b0304', '1400', '0000', '0000', '0000', '2100', 'f9efbe71', '01000000', '01000000', '0100', '0000', '61', '62',
        '504b0102', '1400', '1400', '0000', '0000', '0000', '2100', 'f9efbe71', '01000000', '01000000', '0100',
        '0000', '0000', '0000', '0000', '00000000', '00000000', '61',
        '504b0506', '0000', '0000', '0100', '0100', '2f000000', '20000000', '0000',
    ]));
});

it('makes of no file the end of a central directory alone', function (): void {
    expect(storedHex([]))->toBe(sprintf('504b0506%s', str_repeat('00', 18)));
});
