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
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\LeftUnjudged;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;

$survivor = Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'LessThan', '1', 0),
    'a1',
    Location::of(Path::of('src/Money.php'), Line::of(3), Line::of(3)),
    Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
    MutantStatus::Survived,
    Unmeasured::duration(),
);
$kill = ProvedKill::of(MutantId::hash(Path::of('src/Money.php'), 'Plus', '2', 0), Path::of('src/Money.php'), Line::of(7), 'Plus', TestIds::none());
$newest = Proofs::of(Proof::held(
    Digest::sha256Of('money'),
    Path::of('src/Money.php'),
    Mutants::of($survivor),
    ProvedKills::of($kill),
    Run::of('main', Instant::at(new DateTimeImmutable('2026-09-29T10:00:00Z')), Digest::sha256Of('base')),
))->newest();
$units = Units::of(Unit::file(Path::of('src/Money.php')), Unit::file(Path::of('src/New.php')));

it('counts each unit a ledger holds a result of by its newest one, every mutant of it unjudged', function () use ($units, $newest, $survivor, $kill): void {
    $results = [...LeftUnjudged::of($units, $newest)->results()];

    expect($results)->toEqual([UnitResult::of(Unit::file(Path::of('src/Money.php')), Origin::Carried, Mutants::of(
        $survivor->unjudged(OutOfTime::BeforeMutating),
        Mutant::of(
            $kill->id(),
            '',
            $kill->location(),
            Mutation::of('Plus', MutatorFamily::Unknown, ''),
            MutantStatus::Unjudged,
            Unmeasured::duration(),
        )->unjudged(OutOfTime::BeforeMutating),
    ))]);
});

it('fails the verdict for each unit no ledger holds a result of, saying what judges it', function () use ($units, $newest): void {
    expect(LeftUnjudged::of($units, $newest)->failures())->toEqual(Failures::of(Failure::that(
        "src/New.php is unjudged: the time budget ran out before this run mutated it.\n"
        . 'No ledger holds a result of it to count as not killed. More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
    )));
});

it('counts nothing and fails nothing where the budget left no unit unjudged', function () use ($newest): void {
    $none = LeftUnjudged::of(Units::none(), $newest);

    expect($none->results())->toHaveCount(0)
        ->and($none->failures())->toHaveCount(0);
});
