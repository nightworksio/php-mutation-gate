<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Gzip;

it('unpacks what it packs', function (): void {
    $text = str_repeat('{"unit":"src/Money.php"}', 100);

    expect(Gzip::unpack(Gzip::pack($text), 'the ledger'))->toBe($text)
        ->and(Gzip::unpack(Gzip::pack(''), 'the ledger'))->toBe('')
        ->and(strlen(Gzip::pack($text)))->toBeLessThan(strlen($text));
});

it('cannot unpack what is not a whole gzip stream', function (string $bytes): void {
    expect(Gzip::unpack($bytes, 'the ledger'))->toEqual(CannotJudge::because('the ledger is not a whole gzip stream.'));
})->with([
    'text' => ['{"format": 2}'],
    'nothing' => [''],
    'a stream cut short' => [substr(Gzip::pack(str_repeat('a', 1000)), 0, 12)],
    'the magic alone' => ["\x1f\x8b"],
]);
