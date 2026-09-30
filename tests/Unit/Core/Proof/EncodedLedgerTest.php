<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\EncodedLedger;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerJson;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\LedgerRetention;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Moment;

/** A ledger of this many proofs at one base, the one of each later minute newer. */
function encodedLedgerOf(int $proofs): Ledger
{
    $base = Digest::of(str_repeat('b', 64));
    $ledger = Ledger::empty()->atBase($base);

    for ($at = 0; $at < $proofs; $at++) {
        $unit = sprintf('src/Unit%03d.php', $at);
        $run = Run::of('main', Moment::at(sprintf('2026-09-30T%02d:%02d:00Z', intdiv($at, 60), $at % 60)), $base);
        $ledger = $ledger->withProof(Proof::of(Digest::sha256Of($unit), Path::of($unit), Mutants::none(), $run));
    }

    return $ledger;
}

/**
 * The units a ledger holds proofs of.
 *
 * @return list<string>
 */
function encodedLedgerUnits(Ledger $ledger): array
{
    $units = [];

    foreach ($ledger->proofs() as $proof) {
        $units[] = $proof->unit()->value();
    }

    sort($units);

    return $units;
}

it('writes all that retention keeps where it is within the limits, and says nothing more', function (): void {
    $ledger = encodedLedgerOf(200);
    $encoded = EncodedLedger::within($ledger, LedgerLimits::standard());

    expect(encodedLedgerUnits(LedgerFile::decode($encoded->bytes())))->toBe(encodedLedgerUnits($ledger))
        ->and($encoded->written(Written::to('ledger.json.gz'))->said())->toBe('Wrote ledger.json.gz.');
});

it('drops the oldest proofs until a ledger past a limit is within it, reads back, and says so', function (
    int $packedShare,
    int $textShare,
): void {
    $ledger = encodedLedgerOf(200);
    $whole = LedgerJson::text($ledger, LedgerRetention::standard());
    $limits = LedgerLimits::of(
        intdiv(strlen(Gzip::pack($whole)), $packedShare),
        intdiv(strlen($whole), $textShare),
        60.0,
    );
    $encoded = EncodedLedger::within($ledger, $limits);
    $read = LedgerFile::read($encoded->bytes(), $limits);
    $kept = $read instanceof Ledger ? encodedLedgerUnits($read) : [];

    expect(count($kept))->toBeGreaterThan(0)->toBeLessThan(200)
        ->and($kept)->toBe(array_slice(encodedLedgerUnits($ledger), 200 - count($kept)))
        ->and($encoded->written(Written::to('ledger.json.gz'))->said())->toBe(sprintf(
            'Wrote ledger.json.gz. It keeps the newest %d of 200 proofs, so a run can still read the ledger.',
            count($kept),
        ));
})->with([
    'past the compressed limit' => [2, 1],
    'past the decompressed limit' => [1, 3],
]);

it('keeps no proof where even one is past the limits, and still ends', function (): void {
    $encoded = EncodedLedger::within(encodedLedgerOf(20), LedgerLimits::of(1, 1, 60.0));

    expect(LedgerFile::decode($encoded->bytes())->proofs())->toHaveCount(0)
        ->and($encoded->written(Written::to('ledger.json.gz'))->said())
        ->toBe('Wrote ledger.json.gz. It keeps the newest 0 of 20 proofs, so a run can still read the ledger.');
});

it('steps down to one proof, and past it to none where one is still past the limits', function (): void {
    $two = encodedLedgerOf(2);
    $whole = strlen(Gzip::pack(LedgerJson::text($two, LedgerRetention::standard())));
    $one = strlen(Gzip::pack(LedgerJson::text($two, LedgerRetention::standard()->keepingAtMost(1))));
    $limits = LedgerLimits::of(intdiv($whole, 2) + 1, 38_000_000, 60.0);
    $encoded = EncodedLedger::within($two, $limits);

    expect($one)->toBeGreaterThan(intdiv($whole, 2) + 1)
        ->and(LedgerFile::decode($encoded->bytes())->proofs())->toHaveCount(0)
        ->and($encoded->written(Written::to('ledger.json.gz'))->said())
        ->toBe('Wrote ledger.json.gz. It keeps the newest 0 of 2 proofs, so a run can still read the ledger.');
});
