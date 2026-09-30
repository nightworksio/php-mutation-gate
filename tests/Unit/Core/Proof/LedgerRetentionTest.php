<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Bases;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerRetention;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$base = static fn(int $at): Digest => Digest::of(hash('sha256', sprintf('base %d', $at)));
$proof = static fn(string $key, int $base, string $at = '2026-09-29T20:00:00Z'): Proof => Proof::of(
    Digest::of(hash('sha256', $key)),
    Path::of('src/A.php'),
    Mutants::none(),
    Run::of('local', Moment::at($at), Digest::of(hash('sha256', sprintf('base %d', $base)))),
);
$keys = static fn(Proof ...$proofs): array => array_map(static fn(Proof $proof): string => $proof->key()->value(), $proofs);

it('keeps the five bases seen most recently', function () use ($base): void {
    $ledger = Ledger::empty();

    foreach (range(1, 7) as $at) {
        $ledger = $ledger->atBase($base($at));
    }

    expect(LedgerRetention::standard()->basesOf($ledger))->toEqual(Bases::of($base(7), $base(6), $base(5), $base(4), $base(3)))
        ->and(LedgerRetention::standard()->basesOf(Ledger::empty()))->toEqual(Bases::none());
});

it('keeps the proofs of its kept bases, the newest first, and of two as new the one held first', function () use ($base, $proof, $keys): void {
    $ledger = Ledger::empty()
        ->withProofs(Proofs::of(
            $proof('old', 1, '2026-01-01T00:00:00Z'),
            $proof('first', 2),
            $proof('second', 1),
            $proof('gone', 9),
            $proof('newest', 2, '2026-09-30T00:00:00Z'),
        ))
        ->atBase($base(1))
        ->atBase($base(2));

    expect($keys(...LedgerRetention::standard()->proofsOf($ledger)))->toBe($keys(
        $proof('newest', 2, '2026-09-30T00:00:00Z'),
        $proof('first', 2),
        $proof('second', 1),
        $proof('old', 1, '2026-01-01T00:00:00Z'),
    ));
});

it('keeps no proof of a ledger no run has keyed at a base', function () use ($proof): void {
    expect(LedgerRetention::standard()->proofsOf(Ledger::empty()->withProof($proof('any', 1))))->toBe([]);
});
