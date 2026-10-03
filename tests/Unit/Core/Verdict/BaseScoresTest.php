<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
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
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\BaseScores;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/** A unit's mutants, one a line, each killed or survived as it says. */
function baseMutants(string $file, MutantStatus ...$statuses): Mutants
{
    $mutants = [];
    $line = 0;

    foreach ($statuses as $status) {
        ++$line;
        $mutants[] = Mutant::of(
            MutantId::hash(Path::of($file), 'LessThan', sprintf('%d', $line), 0),
            sprintf('%s:%d', $file, $line),
            Location::of(Path::of($file), Line::of($line), Line::of($line)),
            Mutation::of('LessThan', MutatorFamily::Boundary, ''),
            $status,
            Unmeasured::duration(),
        );
    }

    return Mutants::of(...$mutants);
}

/** A proof of a unit's mutants, by a run at this hour of the day. */
function baseProof(string $unit, int $hour, Mutants $mutants): Proof
{
    $run = Run::of('main', Instant::at(new DateTimeImmutable(sprintf('2026-09-30T%02d:00:00Z', $hour))), Digest::of(str_repeat('b', 64)));

    return Proof::of(Digest::sha256Of(sprintf('%s@%d', $unit, $hour)), Path::of($unit), $mutants, $run);
}

$root = Package::at(Path::root());
$trees = Trees::of(
    Tree::at(Path::of('app'), Floor::of(50), $root),
    Tree::at(Path::of('lib'), Floor::of(50), $root),
    Tree::at(Path::of('empty'), Floor::of(50), $root),
);
$units = Units::of(Unit::file(Path::of('app/A.php')), Unit::file(Path::of('app/B.php')), Unit::file(Path::of('lib/C.php')));
$judge = Judge::of($trees, Baseline::none(), Reach::nothing(Packages::of($trees)), Uncovered::Count, TimeoutMode::Confirm, Ignoring::none());

/** Each tree's base score, as the verdicts compared with them say. */
$bases = static fn(TreeVerdicts $verdicts): array => array_map(
    static fn(TreeVerdict $verdict): Score|NothingToMutate|Unrecorded => $verdict->base(),
    [...$verdicts],
);

it('scores each tree on the newest result of every one of its units on the default branch', function () use ($judge, $trees, $units, $bases): void {
    $defaultBranch = Proofs::of(
        baseProof('app/A.php', 9, baseMutants('app/A.php', MutantStatus::Survived, MutantStatus::Survived)),
        baseProof('app/A.php', 10, baseMutants('app/A.php', MutantStatus::Killed, MutantStatus::Survived)),
        baseProof('app/B.php', 9, baseMutants('app/B.php', MutantStatus::Killed, MutantStatus::Killed)),
        baseProof('lib/C.php', 9, baseMutants('lib/C.php', MutantStatus::Killed)),
    );
    $now = $judge->trees(UnitResults::none());

    expect($bases(BaseScores::of($judge, $trees, $units, $defaultBranch)->compare($now)))->toEqual([
        Score::ofHundredths(7_500),
        Score::ofHundredths(10_000),
        NothingToMutate::found(),
    ]);
});

it('gives no base score to a tree with a unit the default branch has no result for', function () use ($judge, $trees, $units, $bases): void {
    $defaultBranch = Proofs::of(baseProof('app/A.php', 9, baseMutants('app/A.php', MutantStatus::Killed)));
    $now = $judge->trees(UnitResults::of(UnitResult::of(Unit::file(Path::of('lib/C.php')), Origin::Run, baseMutants('lib/C.php', MutantStatus::Killed))));
    $compared = BaseScores::of($judge, $trees, $units, $defaultBranch)->compare($now);

    expect($bases($compared))->toEqual([Unrecorded::floor(), Unrecorded::floor(), NothingToMutate::found()])
        ->and([...$compared][1]->score())->toEqual(Score::ofHundredths(10_000));
});

it('compares nothing where no tree has a base score', function () use ($judge, $bases): void {
    expect($bases(BaseScores::none()->compare($judge->trees(UnitResults::none()))))
        ->toEqual([Unrecorded::floor(), Unrecorded::floor(), Unrecorded::floor()]);
});
