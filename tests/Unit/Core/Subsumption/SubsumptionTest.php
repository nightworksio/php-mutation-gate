<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\SubsumptionRecord;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Subsumed;

/**
 * What a subsumption holds, each file's pairs by the values of the mutants' ids.
 *
 * @return array<string, list<array{string, string}>>
 */
function pairsOf(Subsumption $subsumption): array
{
    $pairs = [];

    foreach ($subsumption as $file => $subsumed) {
        $pairs[$file] = array_map(static fn(array $pair): array => [$pair[0]->value(), $pair[1]->value()], $subsumed);
    }

    return $pairs;
}

it('learns that a killed mutant subsumes another of its file every test that killed it also killed, keeping the one with the fewest killers', function (): void {
    $narrow = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $wide = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');
    $widest = Subsumed::killed(Subsumed::diff('a', 'd'), 'T1', 'T2', 'T3');
    $apart = Subsumed::killed(Subsumed::diff('a', 'e'), 'T4');
    $survived = Subsumed::mutant('src/Money.php', 1, Subsumed::diff('a', 'f'), MutantStatus::Survived);
    $elsewhere = Subsumed::mutant('src/Tax.php', 1, Subsumed::diff('a', 'g'), MutantStatus::Killed, 'T1', 'T2');

    expect(pairsOf(Subsumption::learned($narrow, $wide, $widest, $apart, $survived, $elsewhere)))->toBe([
        'src/Money.php' => [
            ...array_map(
                static fn(Mutant $mutant): array => [$mutant->id()->value(), $narrow->id()->value()],
                sortedById($wide, $widest),
            ),
        ],
    ]);
});

/**
 * Mutants in the order of their ids' keys, as a subsumption keeps them.
 *
 * @return list<Mutant>
 */
function sortedById(Mutant ...$mutants): array
{
    usort($mutants, static fn(Mutant $one, Mutant $other): int => $one->id()->key() <=> $other->id()->key());

    return $mutants;
}

it('learns that of two mutants the same tests killed the one with the lower id subsumes the other, and nothing of a mutant killed by none', function (): void {
    $one = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $two = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1');
    $unkilled = Subsumed::killed(Subsumed::diff('a', 'd'));
    [$low, $high] = $one->id()->value() < $two->id()->value() ? [$one, $two] : [$two, $one];

    expect(pairsOf(Subsumption::learned($one, $two, $unkilled)))
        ->toBe(['src/Money.php' => [[$high->id()->value(), $low->id()->value()]]]);
});

it('names the subsumer of a mutant of a file it knows, and none of another', function (): void {
    $by = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $of = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');
    $learned = Subsumption::learned($by, $of);
    $file = Path::of('src/Money.php');

    expect([$learned->knows($file), $learned->knows(Path::of('src/Tax.php'))])->toBe([true, false])
        ->and($learned->subsumerOf($file, $of->id()))->toEqual($by->id())
        ->and($learned->subsumerOf($file, $by->id()))->toEqual(NotGiven::value())
        ->and($learned->subsumerOf(Path::of('src/Tax.php'), $of->id()))->toEqual(NotGiven::value());
});

it('learns from a run each file whose every mutant it judged, in place of what it held, and keeps what it held of a file the run left a mutant of unjudged', function (): void {
    $by = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $of = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');
    $tax = Subsumed::mutant('src/Tax.php', 1, Subsumed::diff('a', 'b'), MutantStatus::Killed, 'T1');
    $taxOf = Subsumed::mutant('src/Tax.php', 1, Subsumed::diff('a', 'c'), MutantStatus::Killed, 'T1', 'T2');
    $held = Subsumption::learned($by, $of, $tax, $taxOf);
    $unjudged = Subsumed::mutant('src/Tax.php', 2, Subsumed::diff('x', 'y'))->unjudged(OutOfTime::BeforeMutating);
    $fresh = UnitResults::of(
        UnitResult::of(Unit::file(Path::of('src/Money.php')), Origin::Run, Mutants::of($by, Subsumed::killed(Subsumed::diff('a', 'c'), 'T3'))),
        UnitResult::of(Unit::file(Path::of('src/Tax.php')), Origin::Run, Mutants::of($unjudged)),
    );

    expect(array_keys(pairsOf($held->afterRun($fresh))))->toBe(['src/Tax.php'])
        ->and(pairsOf($held->afterRun($fresh))['src/Tax.php'])->toBe(pairsOf($held)['src/Tax.php']);
});

it('reads another ledger\'s files it holds nothing of beneath its own', function (): void {
    $by = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $of = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');
    $tax = Subsumed::mutant('src/Tax.php', 1, Subsumed::diff('a', 'b'), MutantStatus::Killed, 'T1');
    $taxOf = Subsumed::mutant('src/Tax.php', 1, Subsumed::diff('a', 'c'), MutantStatus::Killed, 'T1', 'T2');
    $mine = Subsumption::learned($by, $of);
    $theirs = Subsumption::learned($tax, $taxOf)->with(Path::of('src/Money.php'), $by->id(), $of->id());

    expect(pairsOf($mine->and($theirs)))->toBe([
        'src/Money.php' => [[$of->id()->value(), $by->id()->value()]],
        'src/Tax.php' => [[$taxOf->id()->value(), $tax->id()->value()]],
    ])
        ->and(pairsOf(Subsumption::none()))->toBe([])
        ->and(Subsumption::none()->subsumerOf(Path::of('src/Money.php'), MutantId::hash(Path::of('src/Money.php'), 'Plus', 'x', 0)))
        ->toEqual(NotGiven::value());
});

it('learns nothing of a mutant a test failed beside but did not kill', function (): void {
    $killed = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1', 'T2');
    $timedOut = Subsumed::mutant('src/Money.php', 1, Subsumed::diff('a', 'c'), MutantStatus::TimedOut, 'T1');

    expect(pairsOf(Subsumption::learned($killed, $timedOut)))->toBe([]);
});

it('keeps what it learned of a file in the order of the subsumed mutants\' ids, whatever order the run gave them in', function (): void {
    $narrow = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $wide = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');
    $widest = Subsumed::killed(Subsumed::diff('a', 'd'), 'T1', 'T2', 'T3');

    expect(SubsumptionRecord::of(Subsumption::learned($widest, $wide, $narrow)))
        ->toBe(SubsumptionRecord::of(Subsumption::learned($narrow, $wide, $widest)));
});

it('keeps the files it learned of in the order of their paths, whatever order the run gave them in', function (): void {
    $tax = Subsumed::mutant('src/Tax.php', 1, Subsumed::diff('a', 'b'), MutantStatus::Killed, 'T1');
    $taxOf = Subsumed::mutant('src/Tax.php', 1, Subsumed::diff('a', 'c'), MutantStatus::Killed, 'T1', 'T2');
    $money = Subsumed::killed(Subsumed::diff('a', 'b'), 'T3');
    $moneyOf = Subsumed::killed(Subsumed::diff('a', 'c'), 'T3', 'T4');

    expect(array_keys(SubsumptionRecord::of(Subsumption::learned($tax, $taxOf, $money, $moneyOf))))
        ->toBe(['src/Money.php', 'src/Tax.php']);
});
