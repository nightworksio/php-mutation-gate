<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
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
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\NeverProved;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\Carry;
use NightWorksIO\MutationGate\Core\Verdict\Carrying;
use NightWorksIO\MutationGate\Core\Verdict\Uncounted;
use NightWorksIO\MutationGate\Tests\Support\Moment;

/** A digest named by a word, so two of one word are one digest. */
function carryDigest(string $word): Digest
{
    return Digest::sha256Of($word);
}

/** A mutant of src/Money.php, on this line, so reported. */
function carryMutant(int $line, MutantStatus $status): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', sprintf('%d', $line), 0),
        sprintf('%d', $line),
        Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        $status,
        Unmeasured::duration(),
    );
}

/** A kill of src/Money.php on this line, by these tests. */
function carryKill(int $line, TestId ...$killers): ProvedKill
{
    return ProvedKill::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', sprintf('%d', $line), 0),
        Path::of('src/Money.php'),
        Line::of($line),
        'Plus',
        TestIds::of(...$killers),
    );
}

/** The newest proof of src/Money.php, at this base, recording these inputs. */
function carryProof(string $base, Inputs|Undigested $inputs): Proof
{
    return Proof::held(
        carryDigest('old key'),
        Path::of('src/Money.php'),
        Mutants::none(),
        ProvedKills::none(),
        Run::of('main', Moment::at('2026-09-29T10:00:00Z'), carryDigest($base)),
    )->withInputs($inputs);
}

$recorded = Inputs::of(carryDigest('source'), carryDigest('mutation'))
    ->withTest(Path::of('tests/MoneyTest.php'), carryDigest('money test'))
    ->withTest(Path::of('tests/TaxTest.php'), carryDigest('tax test'))
    ->withTest(Path::of('tests/RateTest.php'), carryDigest('rate test'));
$now = Digests::of(carryDigest('mutation'))
    ->withSource(Path::of('src/Money.php'), carryDigest('source'))
    ->withTest(Path::of('tests/MoneyTest.php'), carryDigest('money test'))
    ->withTest(Path::of('tests/TaxTest.php'), carryDigest('tax test changed'))
    ->withTest(Path::of('tests/NewTest.php'), carryDigest('new test'));
$names = TestNames::none()
    ->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'adds'))
    ->with(TestId::of('TaxTest::rounds'), TestName::in(Path::of('tests/TaxTest.php'), 'rounds'))
    ->with(TestId::of('NewTest::adds'), TestName::in(Path::of('tests/NewTest.php'), 'adds'))
    ->with(TestId::of('RateTest::rates'), TestName::in(Path::of('tests/RateTest.php'), 'rates'));
$map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(7), TestId::of('MoneyTest::adds'));
$carrying = Carrying::against($now, carryDigest('base'), $names, $map);

it('counts a unit by its newest result where its source and mutant set are unchanged', function () use ($carrying, $recorded): void {
    $proof = carryProof('base', $recorded);

    expect($carrying->counted($proof))->toBe($proof);
});

it('counts no unit by a result that cannot stand for the code on disk, and says why', function (
    Proof|NeverProved $newest,
    Digests|Undigested $now,
    Uncounted $why,
) use ($names, $map): void {
    expect(Carrying::against($now, carryDigest('base'), $names, $map)->counted($newest))->toBe($why);
})->with([
    'no result anywhere' => [NeverProved::unit(Path::of('src/Money.php')), Digests::of(carryDigest('mutation')), Uncounted::NoResult],
    'a result of an earlier ledger format' => [carryProof('base', Undigested::proof()), Digests::of(carryDigest('mutation')), Uncounted::NoDigests],
    'a run with no digests' => [carryProof('base', Inputs::of(carryDigest('source'), carryDigest('mutation'))), Undigested::proof(), Uncounted::NoDigests],
    'new code, the result of the code before' => [
        carryProof('base', Inputs::of(carryDigest('source before'), carryDigest('mutation'))),
        Digests::of(carryDigest('mutation'))->withSource(Path::of('src/Money.php'), carryDigest('source')),
        Uncounted::SourceChanged,
    ],
    'a source the run has no digest of' => [
        carryProof('base', Inputs::of(carryDigest('source'), carryDigest('mutation'))),
        Digests::of(carryDigest('mutation')),
        Uncounted::SourceChanged,
    ],
    'a changed mutator config' => [
        carryProof('base', Inputs::of(carryDigest('source'), carryDigest('mutators before'))),
        Digests::of(carryDigest('mutation'))->withSource(Path::of('src/Money.php'), carryDigest('source')),
        Uncounted::MutationChanged,
    ],
]);

it('carries each mutant of a counted result as it stands, or unjudged, and says why', function (
    string $base,
    Mutant|ProvedKill $mutant,
    Carry $carry,
) use ($carrying, $recorded): void {
    expect($carrying->carry(carryProof($base, $recorded), $mutant))->toBe($carry);
})->with([
    'a kill at the same base by a test unchanged' => ['base', carryKill(3, TestId::of('MoneyTest::adds')), Carry::Stands],
    'a kill a run reported, by a test unchanged' => ['base', carryMutant(3, MutantStatus::Killed)->killedBy(TestIds::of(TestId::of('MoneyTest::adds'))), Carry::Stands],
    'a kill at another base, as when a class its test calls changed' => ['other base', carryKill(3, TestId::of('MoneyTest::adds')), Carry::OtherBase],
    'a kill by a test that changed' => ['base', carryKill(3, TestId::of('TaxTest::rounds')), Carry::KillerChanged],
    'a kill by one test unchanged and one changed' => ['base', carryKill(3, TestId::of('MoneyTest::adds'), TestId::of('TaxTest::rounds')), Carry::KillerChanged],
    'a kill by one test changed and one unchanged' => ['base', carryKill(3, TestId::of('TaxTest::rounds'), TestId::of('MoneyTest::adds')), Carry::KillerChanged],
    'a kill by one test unknown and one changed' => ['base', carryKill(3, TestId::of('GoneTest::adds'), TestId::of('TaxTest::rounds')), Carry::KillerUnknown],
    'a kill by a test its result recorded no digest of' => ['base', carryKill(3, TestId::of('NewTest::adds')), Carry::KillerChanged],
    'a kill by a test file the run no longer has' => ['base', carryKill(3, TestId::of('RateTest::rates')), Carry::KillerChanged],
    'a kill by a deleted test the run cannot name' => ['base', carryKill(3, TestId::of('GoneTest::adds')), Carry::KillerUnknown],
    'a kill no test is known for' => ['base', carryKill(3), Carry::KillerUnknown],
    'a timeout, which triage may count a kill' => ['base', carryMutant(3, MutantStatus::TimedOut), Carry::KillerUnknown],
    'a crash, which counts a kill' => ['base', carryMutant(3, MutantStatus::Errored), Carry::KillerUnknown],
    'a survivor, at another base' => ['other base', carryMutant(3, MutantStatus::Survived), Carry::Stands],
    'an uncovered mutant no test covers now' => ['base', carryMutant(3, MutantStatus::Uncovered), Carry::Stands],
    'an uncovered mutant a test covers now' => ['base', carryMutant(7, MutantStatus::Uncovered), Carry::NowCovered],
    'a mutant marked ignored' => ['base', carryMutant(3, MutantStatus::IgnoredByMarker), Carry::Stands],
    'a mutant skipped' => ['base', carryMutant(3, MutantStatus::Skipped), Carry::Stands],
    'a mutant unjudged' => ['base', carryMutant(3, MutantStatus::Unjudged), Carry::Stands],
]);

it('carries no kill where the run cannot name its tests, nor a proof without digests', function () use ($now, $map, $recorded, $names): void {
    $kill = carryKill(3, TestId::of('MoneyTest::adds'));

    expect(Carrying::against($now, carryDigest('base'), CannotJudge::because('Unnamed.'), $map)->carry(carryProof('base', $recorded), $kill))
        ->toBe(Carry::KillerUnknown)
        ->and(Carrying::against($now, carryDigest('base'), $names, $map)->carry(carryProof('base', Undigested::proof()), $kill))
        ->toBe(Carry::KillerChanged)
        ->and(Carrying::against(Undigested::proof(), carryDigest('base'), $names, $map)->carry(carryProof('base', $recorded), $kill))
        ->toBe(Carry::KillerChanged);
});

it('carries no uncovered mutant where the run has no coverage map', function () use ($now, $names, $recorded): void {
    expect(Carrying::against($now, carryDigest('base'), $names, CannotJudge::because('No map.'))->carry(carryProof('base', $recorded), carryMutant(3, MutantStatus::Uncovered)))
        ->toBe(Carry::CoverageUnknown);
});
