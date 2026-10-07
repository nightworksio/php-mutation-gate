<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
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
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Carrying;
use NightWorksIO\MutationGate\Core\Verdict\ChangesSince;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\DoomedBy;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\LeftUnjudged;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$survivor = Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'LessThan', '1', 0),
    'a1',
    Location::of(Path::of('src/Money.php'), Line::of(3), Line::of(3)),
    Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
    MutantStatus::Survived,
    Unmeasured::duration(),
);
$killBy = static fn(string $mutator, int $line, string $test): ProvedKill => ProvedKill::of(
    MutantId::hash(Path::of('src/Money.php'), $mutator, sprintf('%d', $line), 0),
    Path::of('src/Money.php'),
    Line::of($line),
    $mutator,
    TestIds::of(TestId::of($test)),
);
$standing = $killBy('Plus', 7, 'MoneyTest::adds');
$stale = $killBy('Minus', 9, 'TaxTest::rounds');
$inputsOf = static fn(string $source): Inputs => Inputs::of(Digest::sha256Of($source), Digest::sha256Of('mutation'))
    ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'))
    ->withTest(Path::of('tests/TaxTest.php'), Digest::sha256Of('tax test'));
$proofOf = static fn(string $unit, string $source, Mutants $reported, ProvedKills $kills): Proof => Proof::held(
    Digest::sha256Of($unit),
    Path::of($unit),
    $reported,
    $kills,
    Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
)->withInputs($inputsOf($source));
$timedOut = Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'Minus', '5', 0),
    'a5',
    Location::of(Path::of('src/Money.php'), Line::of(5), Line::of(5)),
    Mutation::of('Minus', MutatorFamily::Arithmetic, "-+\n+-"),
    MutantStatus::TimedOut,
    Unmeasured::duration(),
);
$newest = Proofs::of(
    $proofOf('src/Money.php', 'money', Mutants::of($survivor, $timedOut), ProvedKills::of($standing, $stale)),
    $proofOf('src/Tax.php', 'tax before', Mutants::none(), ProvedKills::none()),
)->newest();
$carrying = Carrying::against(
    Digests::of(Digest::sha256Of('mutation'))
        ->withSource(Path::of('src/Money.php'), Digest::sha256Of('money'))
        ->withSource(Path::of('src/Tax.php'), Digest::sha256Of('tax'))
        ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'))
        ->withTest(Path::of('tests/TaxTest.php'), Digest::sha256Of('tax test changed')),
    Digest::sha256Of('base'),
    TestNames::none()
        ->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'adds'))
        ->with(TestId::of('TaxTest::rounds'), TestName::in(Path::of('tests/TaxTest.php'), 'rounds')),
    CoverageMap::empty(),
    ChangesSince::none(),
);
$units = Units::of(Unit::file(Path::of('src/Money.php')), Unit::file(Path::of('src/Tax.php')), Unit::file(Path::of('src/New.php')));

it('counts a unit its newest result stands for by it, each mutant standing or unjudged', function () use ($units, $newest, $carrying, $survivor, $timedOut, $standing, $stale): void {
    expect([...LeftUnjudged::of($units, $newest, $carrying)->results()])->toEqual([UnitResult::held(
        Unit::file(Path::of('src/Money.php')),
        Origin::Carried,
        Mutants::of($survivor, $timedOut->unjudged(OutOfTime::BeforeMutating)),
        ProvedKills::of($standing, $stale->unjudged(OutOfTime::BeforeMutating)),
        Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )]);
});

it('fails the verdict for each unit its newest result cannot stand for, saying why and what judges it', function () use ($units, $newest, $carrying): void {
    expect(LeftUnjudged::of($units, $newest, $carrying)->failures())->toEqual(Failures::of(
        Failure::that(
            "src/Tax.php is unjudged: the time budget ran out before this run mutated it.\n"
            . 'Its newest result is of other source, so its mutants are not this code\'s. '
            . 'More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
        ),
        Failure::that(
            "src/New.php is unjudged: the time budget ran out before this run mutated it.\n"
            . 'No ledger holds a result of it to count. More time judges it: vendor/bin/mutation-gate run --budget=<duration>',
        ),
    ));
});

it('counts nothing and fails nothing where the budget left no unit unjudged', function () use ($newest, $carrying): void {
    $none = LeftUnjudged::of(Units::none(), $newest, $carrying);

    expect($none->results())->toHaveCount(0)
        ->and($none->failures())->toHaveCount(0);
});

it('carries a kill by static analysis of this base with its rejection, and one that records no finding as unjudged', function () use ($proofOf, $carrying, $timedOut): void {
    $rejected = $timedOut->rejected(Rejection::by('phpstan', Finding::error(Path::of('src/Money.php'), 'return.type', 'Method Money::sub() should return int.')));
    $unplaced = Mutant::of(
        $timedOut->id(),
        $timedOut->nativeId(),
        $timedOut->location(),
        $timedOut->mutation(),
        MutantStatus::KilledByStaticAnalysis,
        Unmeasured::duration(),
    );
    $carried = static function (Mutant $mutant) use ($proofOf, $carrying): array {
        $newest = Proofs::of($proofOf('src/Money.php', 'money', Mutants::of($mutant), ProvedKills::none()))->newest();
        $results = [...LeftUnjudged::of(Units::of(Unit::file(Path::of('src/Money.php'))), $newest, $carrying)->results()];

        return $results === [] ? [] : [...$results[0]->mutants()];
    };

    expect($carried($rejected))->toEqual([$rejected])
        ->and($carried($unplaced))->toEqual([$unplaced->unjudged(OutOfTime::BeforeMutating)]);
});

it('counts a unit a doomed run stopped before by its newest result, each mutant that does not stand unjudged by that stop', function () use ($units, $newest, $carrying, $survivor, $timedOut, $standing, $stale): void {
    $doomed = Doomed::of(Path::of('src/Late.php'), MutantId::hash(Path::of('src/Late.php'), 'Plus', '1', 0), Path::of('src'), Floor::whole(), DoomedBy::Tree);
    $stopped = LeftUnjudged::stoppedBy($doomed, $units, $newest, $carrying);

    expect([...$stopped->results()])->toEqual([UnitResult::held(
        Unit::file(Path::of('src/Money.php')),
        Origin::Carried,
        Mutants::of($survivor, $timedOut->unjudged($doomed->left())),
        ProvedKills::of($standing, $stale->unjudged($doomed->left())),
        Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )])
        ->and($stopped->failures())->toHaveCount(0)
        ->and(array_map(
            static fn(Mutant|ProvedKill $left): string => $left->reason() instanceof Reason ? $left->reason()->text() : '',
            [...[...$stopped->results()][0]->mutants(), ...[...$stopped->results()][0]->kills()],
        ))->toBe(['', $doomed->left()->text(), '', $doomed->left()->text()])
        ->and(OutOfTime::left($timedOut->unjudged($doomed->left())))->toBeFalse()
        ->and($doomed->left()->text())->toBe(sprintf(
            'The run stopped before this unit, once mutant %s of src/Late.php made it certain to fail. Kill that mutant and run again.',
            $doomed->mutant()->value(),
        ));
});
