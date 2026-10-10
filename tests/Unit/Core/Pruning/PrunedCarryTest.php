<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\PrunedCarry;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\PruningCases;

/**
 * Each result's mutants by their native ids, its kills by their mutators, and what it carries for a pruned mutator.
 *
 * @return list<array{list<string>, list<string>, int}>
 */
function carriedIn(UnitResults $results): array
{
    return array_map(static fn(UnitResult $result): array => [
        array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), [...$result->mutants()]),
        array_map(static fn(ProvedKill $kill): string => $kill->mutator(), [...$result->kills()]),
        count($result->carriedPruned()),
    ], [...$results]);
}

$fresh = static fn(): UnitResults => UnitResults::of(
    UnitResult::of(Unit::file(Path::of('src/A.php')), Origin::Run, Mutants::of(PruningCases::mutant('src/A.php', 'Minus', 3))),
    UnitResult::of(Unit::file(Path::of('src/B.php')), Origin::Run, Mutants::of(PruningCases::mutant('src/B.php', 'Minus', 3))),
);
$last = static fn(string $source = 'a'): Proofs => Proofs::of(PruningCases::proof(
    'src/A.php',
    '2026-10-05T00:00:00Z',
    $source,
    Mutants::of(
        PruningCases::mutant('src/A.php', 'Plus', 1, MutantStatus::Survived),
        PruningCases::mutant('src/A.php', 'Minus', 3),
        PruningCases::mutant('src/A.php', 'Plus', 7),
    ),
));
$makePruned = static fn(): Pruned => Pruned::of(MutatorNames::of('Plus'), Paths::of(Path::of('src/A.php')));

it('carries the last result\'s mutants of a pruned mutator into a pruned unit, and nothing into another', function () use ($fresh, $last, $makePruned): void {
    $pruned = $makePruned();

    $results = PrunedCarry::of($pruned, $last()->newest(), PruningCases::now(...['src/A.php' => 'a', 'src/B.php' => 'b']))->into($fresh());

    expect(carriedIn($results))->toBe([
        [['Minus-3', 'Plus-1', 'Plus-7'], [], 2],
        [['Minus-3'], [], 0],
    ]);
});

it('carries nothing into a unit the plan does not prune, though its last result stands and holds a pruned mutator\'s mutant', function () use ($fresh, $makePruned): void {
    $pruned = $makePruned();

    $last = Proofs::of(PruningCases::proof('src/B.php', '2026-10-05T00:00:00Z', 'b', Mutants::of(PruningCases::mutant('src/B.php', 'Plus', 1))));

    expect(carriedIn(PrunedCarry::of($pruned, $last->newest(), PruningCases::now(...['src/A.php' => 'a', 'src/B.php' => 'b']))->into($fresh()))[1])
        ->toBe([['Minus-3'], [], 0]);
});

it('carries nothing over a mutant of a pruned mutator the run made itself, as an unpatched runner does', function () use ($last, $makePruned): void {
    $pruned = $makePruned();

    $ran = UnitResults::of(UnitResult::of(Unit::file(Path::of('src/A.php')), Origin::Run, Mutants::of(
        PruningCases::mutant('src/A.php', 'Plus', 1),
        PruningCases::mutant('src/A.php', 'Plus', 7),
    )));

    expect(carriedIn(PrunedCarry::of($pruned, $last()->newest(), PruningCases::now(...['src/A.php' => 'a']))->into($ran)))
        ->toBe([[['Plus-1', 'Plus-7'], [], 0]]);
});

it('carries nothing where the last result is of other code, nothing is pruned, or the code on disk has no digests', function (Pruned $pruning, string $source, bool $digested) use ($fresh, $last): void {
    $now = $digested ? PruningCases::now(...['src/A.php' => 'a']) : Undigested::proof();

    expect(carriedIn(PrunedCarry::of($pruning, $last($source)->newest(), $now)->into($fresh())))
        ->toBe([[['Minus-3'], [], 0], [['Minus-3'], [], 0]]);
})->with([
    'other code' => [fn(): Pruned => Pruned::of(MutatorNames::of('Plus'), Paths::of(Path::of('src/A.php'))), 'a then', true],
    'nothing pruned' => [fn(): Pruned => Pruned::none(), 'a', true],
    'no digests' => [fn(): Pruned => Pruned::of(MutatorNames::of('Plus'), Paths::of(Path::of('src/A.php'))), 'a', false],
]);

it('carries the kills a ledger proved of a pruned mutator, as it holds them, and no other kill', function () use ($fresh, $makePruned): void {
    $pruned = $makePruned();

    $plus = ProvedKill::of(MutantId::hash(Path::of('src/A.php'), 'Plus', '-2', 0), Path::of('src/A.php'), Line::of(2), 'Plus', TestIds::none());
    $minus = ProvedKill::of(MutantId::hash(Path::of('src/A.php'), 'Minus', '-4', 0), Path::of('src/A.php'), Line::of(4), 'Minus', TestIds::none());
    $held = Proof::held(
        Digest::of('src-a'),
        Path::of('src/A.php'),
        Mutants::none(),
        ProvedKills::of($plus, $minus),
        Run::of('main', Moment::at('2026-10-05T00:00:00Z'), Digest::of(str_repeat('b', 64))),
    )->withInputs(Inputs::of(Digest::sha256Of('a'), Digest::sha256Of('mutation')));

    expect(carriedIn(PrunedCarry::of($pruned, Proofs::of($held)->newest(), PruningCases::now(...['src/A.php' => 'a']))->into($fresh()))[0])
        ->toBe([['Minus-3'], ['Plus'], 1]);
});

it('keeps a unit whose last result holds no mutant of a pruned mutator as the run left it', function () use ($fresh): void {
    $last = Proofs::of(PruningCases::proof('src/A.php', '2026-10-05T00:00:00Z', 'a', Mutants::of(PruningCases::mutant('src/A.php', 'Minus', 3))));
    $pruned = Pruned::of(MutatorNames::of('Plus'), Paths::of(Path::of('src/A.php')));

    expect(carriedIn(PrunedCarry::of($pruned, $last->newest(), PruningCases::now(...['src/A.php' => 'a']))->into($fresh()))[0])
        ->toBe([['Minus-3'], [], 0]);
});

it('names every mutant and kill a result carries for a pruned mutator', function (): void {
    $kill = ProvedKill::of(MutantId::hash(Path::of('src/A.php'), 'Plus', '-2', 0), Path::of('src/A.php'), Line::of(2), 'Plus', TestIds::none());
    $mutant = PruningCases::mutant('src/A.php', 'Plus', 1);
    $result = UnitResult::of(Unit::file(Path::of('src/A.php')), Origin::Run, Mutants::none())
        ->carryingPruned(Mutants::of($mutant), ProvedKills::of($kill));

    expect($result->carriedPruned()->has($mutant->id()))->toBeTrue()
        ->and($result->carriedPruned()->has($kill->id()))->toBeTrue()
        ->and(count($result->carriedPruned()))->toBe(2);
});
