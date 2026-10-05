<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;

it('reads twice what a ledger at the retention cap measures, for a minute at most', function (): void {
    $limits = LedgerLimits::standard();

    expect($limits->admitsPacked(11_000_000))->toBeTrue()
        ->and($limits->admitsPacked(11_000_001))->toBeFalse()
        ->and($limits->packed())->toBe(11_000_000)
        ->and($limits->inflated(Gzip::pack(str_repeat('0', 38_000_001)), 'The ledger'))
        ->toEqual(TooLarge::because('The ledger inflates to more than 38000000 bytes.'))
        ->and($limits->seconds())->toBe(60.0)
        ->and($limits->pastPacked())->toBe('it is larger than 11000000 bytes');
});

it('reads as far as limits of its own, and no further', function (): void {
    $limits = LedgerLimits::of(25, 100, 1.5);

    expect([$limits->admitsPacked(25), $limits->admitsPacked(26)])->toBe([true, false])
        ->and([$limits->packed(), $limits->seconds(), $limits->pastPacked()])->toBe([25, 1.5, 'it is larger than 25 bytes'])
        ->and($limits->inflated(Gzip::pack(str_repeat('a', 100)), 'The ledger'))->toBe(str_repeat('a', 100))
        ->and($limits->inflated(Gzip::pack(str_repeat('a', 101)), 'The ledger'))
        ->toEqual(TooLarge::because('The ledger inflates to more than 100 bytes.'))
        ->and($limits->inflated(str_repeat('a', 26), 'The ledger'))->toEqual(TooLarge::because('it is larger than 25 bytes'));
});

it('admits a written ledger within both limits, and none past either', function (): void {
    $limits = LedgerLimits::of(10, 20, 60.0);

    expect($limits->admitsWritten(str_repeat('t', 20), str_repeat('b', 10)))->toBeTrue()
        ->and($limits->admitsWritten(str_repeat('t', 21), str_repeat('b', 10)))->toBeFalse()
        ->and($limits->admitsWritten(str_repeat('t', 20), str_repeat('b', 11)))->toBeFalse();
});

it('fits as many proofs as the tighter limit allows in proportion, always fewer, never below none', function (): void {
    $limits = LedgerLimits::of(100, 1_000, 60.0);

    expect($limits->fitting(40, str_repeat('t', 1_000), str_repeat('b', 400)))->toBe(10)
        ->and($limits->fitting(40, str_repeat('t', 4_000), str_repeat('b', 200)))->toBe(10)
        ->and($limits->fitting(40, str_repeat('t', 1_000), str_repeat('b', 101)))->toBe(39)
        ->and($limits->fitting(40, str_repeat('t', 1_000), str_repeat('b', 100)))->toBe(39)
        ->and($limits->fitting(1, str_repeat('t', 9_000), str_repeat('b', 900)))->toBe(0)
        ->and($limits->fitting(0, '', ''))->toBe(0);
});

it('gathers a ledger\'s pieces up to the limit, to the byte, and takes none past the piece that passes it', function (): void {
    $taken = 0;
    $endless = static function () use (&$taken): Generator {
        while (true) {
            $taken++;

            yield 'abcd';
        }
    };
    $limits = LedgerLimits::of(10, 100, 1.0);

    expect($limits->gathered(['abcd', 'efgh', 'ij']))->toBe('abcdefghij')
        ->and($limits->gathered(['abcd', 'efgh', 'ijk']))->toEqual(TooLarge::because('it is larger than 10 bytes'))
        ->and($limits->gathered([]))->toBe('')
        ->and($limits->gathered($endless()))->toEqual(TooLarge::because('it is larger than 10 bytes'))
        ->and($taken)->toBe(3);
});
