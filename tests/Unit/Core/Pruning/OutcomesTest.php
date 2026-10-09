<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Pruning\Outcome;
use NightWorksIO\MutationGate\Core\Pruning\Outcomes;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\PruningCases;

it('counts every kind of kill as killed, and a survivor, an uncovered and an unjudged mutant as let through', function (MutantStatus $status, bool|string $through): void {
    $mutant = PruningCases::mutant('src/A.php', 'Plus', 1, $status);
    $outcomes = Outcomes::of(UnitResults::of(UnitResult::of(Unit::file(Path::of('src/A.php')), Origin::Run, Mutants::of($mutant))));

    expect(array_map(static fn(Outcome $outcome): array => [$outcome->mutator(), $outcome->mutant(), $outcome->letThrough()], $outcomes))
        ->toBe(is_bool($through) ? [['Plus', $mutant->id()->value(), $through]] : []);
})->with([
    'killed' => [MutantStatus::Killed, false],
    'killed by static analysis' => [MutantStatus::KilledByStaticAnalysis, false],
    'timed out' => [MutantStatus::TimedOut, false],
    'out of memory' => [MutantStatus::OutOfMemory, false],
    'errored' => [MutantStatus::Errored, false],
    'survived' => [MutantStatus::Survived, true],
    'uncovered' => [MutantStatus::Uncovered, true],
    'unjudged' => [MutantStatus::Unjudged, true],
    'ignored by a marker' => [MutantStatus::IgnoredByMarker, 'not judged'],
    'skipped' => [MutantStatus::Skipped, 'not judged'],
]);

it('counts a flaky kill as let through, learns nothing of a carried mutant, and orders what it learns by mutant id', function (): void {
    $flaky = PruningCases::mutant('src/A.php', 'Plus', 1);
    $carried = PruningCases::mutant('src/A.php', 'Plus', 2);
    $kills = [PruningCases::mutant('src/A.php', 'Minus', 3), PruningCases::mutant('src/A.php', 'Minus', 4)];
    $result = UnitResult::of(Unit::file(Path::of('src/A.php')), Origin::Run, Mutants::of($kills[1], $flaky, $kills[0]))
        ->withFlaky(MutantIds::of($flaky->id()))
        ->carryingPruned(Mutants::of($carried), ProvedKills::none());
    $ids = [$flaky->id()->value(), $kills[0]->id()->value(), $kills[1]->id()->value()];
    sort($ids);

    $outcomes = Outcomes::of(UnitResults::of($result));

    expect(array_map(static fn(Outcome $outcome): string => $outcome->mutant(), $outcomes))->toBe($ids)
        ->and(array_values(array_filter($outcomes, static fn(Outcome $outcome): bool => $outcome->letThrough())))
        ->toEqual([Outcome::through('Plus', $flaky->id()->value())]);
});
