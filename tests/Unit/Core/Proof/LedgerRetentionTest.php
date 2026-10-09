<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Proof\Bases;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerRetention;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Moment;

/**
 * A kill history that learned one killer for each number from the first to the last, in turn, merged in halves so
 * that building a large one stays fast.
 *
 * @param Closure(int): KillHistory $one
 */
function learnedInTurn(int $from, int $to, Closure $one): KillHistory
{
    $middle = intdiv($from + $to, 2);

    return $from === $to ? $one($from) : learnedInTurn($middle + 1, $to, $one)->and(learnedInTurn($from, $middle, $one));
}

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

it('keeps the kill history of the mutants its kept proofs hold, and of its functions', function () use ($base): void {
    $held = MutantId::hash(Path::of('src/A.php'), 'Plus', "-a\n+b", 0);
    $gone = MutantId::hash(Path::of('src/A.php'), 'Plus', "-a\n+c", 0);
    $killed = Mutant::of(
        $held,
        'n',
        Location::of(Path::of('src/A.php'), Line::of(3), Line::of(3)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-a\n+b"),
        MutantStatus::Killed,
        Unmeasured::duration(),
    );
    $ranking = Ranking::of(Kills::of(TestId::of('ATest::a'), 1));
    $function = Enclosing::named(Path::of('src/A.php'), 'a');
    $ledger = Ledger::empty()
        ->withProof(Proof::of(
            Digest::of(hash('sha256', 'held')),
            Path::of('src/A.php'),
            Mutants::of($killed),
            Run::of('local', Moment::at('2026-09-29T20:00:00Z'), $base(1)),
        ))
        ->atBase($base(1))
        ->withLearned(KillHistory::none()->withMutant($held, $ranking)->withMutant($gone, $ranking)->withFunction($function, $ranking));

    expect(LedgerRetention::standard()->killersOf($ledger))
        ->toEqual(KillHistory::none()->withMutant($held, $ranking)->withFunction($function, $ranking));
});

it('keeps no more proofs than it is told, the newest, and never more than the standard cap', function () use ($proof, $keys): void {
    $ledger = Ledger::empty()->atBase(Digest::of(hash('sha256', 'base 1')))->withProofs(Proofs::of(
        $proof('old', 1, '2026-09-29T20:00:00Z'),
        $proof('mid', 1, '2026-09-29T21:00:00Z'),
        $proof('new', 1, '2026-09-29T22:00:00Z'),
    ));

    expect($keys(...LedgerRetention::standard()->keepingAtMost(2)->proofsOf($ledger)))
        ->toBe($keys($proof('new', 1), $proof('mid', 1)))
        ->and(LedgerRetention::standard()->keepingAtMost(0)->proofsOf($ledger))->toBe([])
        ->and(LedgerRetention::standard()->keepingAtMost(30_000))->toEqual(LedgerRetention::standard());
});

it('keeps the killers of at most the twenty thousand mutants and five thousand functions that learned one most recently', function () use ($base): void {
    $ranking = Ranking::of(Kills::of(TestId::of('ATest::a'), 1));
    $id = static fn(int $made): MutantId => MutantId::hash(Path::of('src/A.php'), 'Plus', sprintf("-a\n+%d", $made), 0);
    $held = array_map(static fn(int $made): Mutant => Mutant::of(
        $id($made),
        'n',
        Location::of(Path::of('src/A.php'), Line::of(3), Line::of(3)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-a\n+b"),
        MutantStatus::Killed,
        Unmeasured::duration(),
    ), range(0, 20_000));
    $ledger = Ledger::empty()
        ->withProof(Proof::of(
            Digest::of(hash('sha256', 'held')),
            Path::of('src/A.php'),
            Mutants::of(...$held),
            Run::of('local', Moment::at('2026-09-29T20:00:00Z'), $base(1)),
        ))
        ->atBase($base(1))
        ->withLearned(learnedInTurn(0, 20_000, static fn(int $made): KillHistory => KillHistory::none()->withMutant($id($made), $ranking))
            ->and(learnedInTurn(0, 5_000, static fn(int $made): KillHistory => KillHistory::none()
                ->withFunction(Enclosing::named(Path::of('src/A.php'), sprintf('f%d', $made)), $ranking))));
    $kept = LedgerRetention::standard()->killersOf($ledger);

    expect(iterator_count($kept->mutants()))->toBe(20_000)
        ->and(iterator_count($kept->functions()))->toBe(5_000)
        ->and($kept->likelyKillers($id(0), Nameless::code())->count())->toBe(0)
        ->and($kept->likelyKillers($id(20_000), Nameless::code())->count())->toBe(1);
});
