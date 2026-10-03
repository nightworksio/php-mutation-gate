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
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\Unfinished;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$mutant = static fn(string $file, int $line, MutantStatus $status): Mutant => Mutant::of(
    MutantId::hash(Path::of($file), 'Plus', sprintf('%d', $line), 0),
    sprintf('%d', $line),
    Location::of(Path::of($file), Line::of($line), Line::of($line)),
    Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
    $status,
    Unmeasured::duration(),
);

it('fails each unit with a mutant or a kill a time budget left unjudged, counting them', function () use ($mutant): void {
    $kill = ProvedKill::of(MutantId::hash(Path::of('src/Tax.php'), 'Plus', '9', 0), Path::of('src/Tax.php'), Line::of(9), 'Plus', TestIds::none());
    $results = UnitResults::of(
        UnitResult::of(Unit::file(Path::of('src/Money.php')), Origin::Run, Mutants::of(
            $mutant('src/Money.php', 1, MutantStatus::Survived)->unjudged(OutOfTime::BeforeConfirming),
            $mutant('src/Money.php', 2, MutantStatus::TimedOut)->unjudged(OutOfTime::BeforeRetrying),
            $mutant('src/Money.php', 3, MutantStatus::Killed),
        )),
        UnitResult::held(Unit::file(Path::of('src/Tax.php')), Origin::Carried, Mutants::none(), ProvedKills::of($kill->unjudged(OutOfTime::BeforeMutating)), Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base'))),
        UnitResult::of(Unit::file(Path::of('src/Rate.php')), Origin::Run, Mutants::of(
            $mutant('src/Rate.php', 1, MutantStatus::Unjudged)->because(Reason::that('No test could be named.')),
        )),
    );

    expect(Unfinished::failures($results))->toEqual(Failures::of(
        Failure::that("The time budget left 2 of the mutants of src/Money.php unjudged, so this run cannot pass it.\nMore time judges them: vendor/bin/mutation-gate run --budget=<duration>"),
        Failure::that("The time budget left 1 of the mutants of src/Tax.php unjudged, so this run cannot pass it.\nMore time judges them: vendor/bin/mutation-gate run --budget=<duration>"),
    ));
});
