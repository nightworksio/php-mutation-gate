<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Zstd\ZstdHere;
use NightWorksIO\MutationGate\Core\Format\NoZstandard;
use NightWorksIO\MutationGate\Tests\Fakes\ZstandardFake;

it('packs and unpacks with the extension where it is loaded, the program where it is not, and nothing where neither is here', function (ZstdHere $here, string $by): void {
    $packed = $here->pack('text', 9);

    expect($packed)->toBe(sprintf('%s%s:09:text', ZstandardFake::MAGIC, $by))
        ->and($here->unpackAtMost(is_string($packed) ? $packed : '', 'The ledger', 9))->toBe('text')
        ->and($here->isHere())->toBeTrue();
})->with([
    'both' => fn(): array => [ZstdHere::of(ZstandardFake::here('extension'), ZstandardFake::here('program')), 'extension'],
    'the extension alone' => fn(): array => [ZstdHere::of(ZstandardFake::here('extension'), ZstandardFake::absent('program')), 'extension'],
    'the program alone' => fn(): array => [ZstdHere::of(ZstandardFake::absent('extension'), ZstandardFake::here('program')), 'program'],
]);

it('packs and unpacks nothing where neither is here, saying what would', function (): void {
    $here = ZstdHere::of(ZstandardFake::absent('extension'), ZstandardFake::absent('program'));

    expect($here->isHere())->toBeFalse()
        ->and($here->pack('text', 9))->toEqual(NoZstandard::here()->pack('text', 9))
        ->and($here->unpackAtMost('bytes', 'The ledger', 9))->toEqual(NoZstandard::here()->unpackAtMost('bytes', 'The ledger', 9));
});

it('finds this machine\'s extension and program', function (): void {
    expect(ZstdHere::found())->toBeInstanceOf(ZstdHere::class);
});
