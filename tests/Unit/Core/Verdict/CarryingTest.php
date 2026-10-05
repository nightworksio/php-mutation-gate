<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\NeverProved;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Reach\FileRoles;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\Carry;
use NightWorksIO\MutationGate\Core\Verdict\Carrying;
use NightWorksIO\MutationGate\Core\Verdict\ChangeReach;
use NightWorksIO\MutationGate\Core\Verdict\ChangesSince;
use NightWorksIO\MutationGate\Core\Verdict\Uncounted;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;

/** A digest named by a word, so two of one word are one digest. */
function carryDigest(string $word): Digest
{
    return Digest::sha256Of($word);
}

/** A mutant of src/Money.php, from this line to the last, so reported. */
function carryMutant(int $line, MutantStatus $status, int $last = 0): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', sprintf('%d', $line), 0),
        sprintf('%d', $line),
        Location::of(Path::of('src/Money.php'), Line::of($line), Line::of(max($line, $last))),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        $status,
        Unmeasured::duration(),
    );
}

/** A mutant of src/Money.php on this line, which PHPStan rejected by a finding in this file. */
function carryRejected(int $line, string $file = 'src/Money.php'): Mutant
{
    return carryMutant($line, MutantStatus::Survived)
        ->rejected(Rejection::by('phpstan', Finding::error(Path::of($file), 'return.type', 'Method Money::add() should return int.')));
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

/** A commit named by a word. */
function carryCommit(string $word): Revision
{
    return Revision::ref(mb_substr(hash('sha256', $word), 0, 40));
}

/**
 * What a change to one file of src reaches, where src/Money.php uses the
 * trait src/Equals.php declares, and nothing names src/Tax.php.
 */
function carryChanged(string $file): ChangeReach
{
    $sources = [
        'src/Money.php' => "<?php\nfinal class Money\n{\n    use Equals;\n}\n",
        'src/Equals.php' => "<?php\ntrait Equals\n{\n}\n",
        'src/Tax.php' => "<?php\nfinal class Tax\n{\n}\n",
    ];
    $read = array_map(static fn(string $source): PhpFile => PhpFile::read(Contents::of($source)), $sources);
    $changed = ByPath::none()->with(Path::of($file), Contents::of($sources[$file]));

    return ChangeReach::of(
        Changes::of(Change::modified(Path::of($file), Lines::none())),
        $changed,
        $changed,
        NamedFiles::read($read),
        FileRoles::of(Layout::standard(Paths::none()), Packages::of(Flows::trees()), Paths::none()),
    );
}

$since = ChangesSince::none()
    ->with(carryCommit('unrelated'), carryChanged('src/Tax.php'))
    ->with(carryCommit('callee'), carryChanged('src/Equals.php'))
    ->with(carryCommit('shallow'), CannotTell::because('The clone is shallow.'));
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
$carrying = Carrying::against($now, carryDigest('base'), $names, $map, $since);

it('counts a unit by its newest result where its source and mutant set are unchanged', function () use ($carrying, $recorded): void {
    $proof = carryProof('base', $recorded);

    expect($carrying->counted($proof))->toBe($proof);
});

it('counts no unit by a result that cannot stand for the code on disk, and says why', function (
    Proof|NeverProved $newest,
    Digests|Undigested $now,
    Uncounted $why,
) use ($names, $map, $since): void {
    expect(Carrying::against($now, carryDigest('base'), $names, $map, $since)->counted($newest))->toBe($why);
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
    'a kill by a test that changed' => ['base', carryKill(3, TestId::of('TaxTest::rounds')), Carry::KillerChanged],
    'a kill by one test unchanged and one changed' => ['base', carryKill(3, TestId::of('MoneyTest::adds'), TestId::of('TaxTest::rounds')), Carry::KillerChanged],
    'a kill by one test changed and one unchanged' => ['base', carryKill(3, TestId::of('TaxTest::rounds'), TestId::of('MoneyTest::adds')), Carry::KillerChanged],
    'a kill by one test unknown and one changed' => ['base', carryKill(3, TestId::of('GoneTest::adds'), TestId::of('TaxTest::rounds')), Carry::KillerUnknown],
    'a kill by a test its result recorded no digest of' => ['base', carryKill(3, TestId::of('NewTest::adds')), Carry::KillerChanged],
    'a kill by a test file the run no longer has' => ['base', carryKill(3, TestId::of('RateTest::rates')), Carry::KillerChanged],
    'a kill by a deleted test the run cannot name' => ['base', carryKill(3, TestId::of('GoneTest::adds')), Carry::KillerUnknown],
    'a kill no test is known for' => ['base', carryKill(3), Carry::KillerUnknown],
    'a kill by static analysis at the same base' => ['base', carryRejected(3), Carry::Stands],
    'a kill by static analysis in another file, at the same base' => ['base', carryRejected(3, 'src/Wallet.php'), Carry::Stands],
    'a kill by static analysis that records no finding, as Infection reports one' => ['base', carryMutant(3, MutantStatus::KilledByStaticAnalysis), Carry::RejectionUnknown],
    'a kill by static analysis in a file outside the repository' => ['base', carryRejected(3, '/elsewhere/Lib.php'), Carry::FindingOutside],
    'a kill by static analysis in a file above the repository' => ['base', carryRejected(3, '../lib/Lib.php'), Carry::FindingOutside],
    'a timeout, which triage may count a kill' => ['base', carryMutant(3, MutantStatus::TimedOut), Carry::KillerUnknown],
    'out of memory, which triage may count a kill' => ['base', carryMutant(3, MutantStatus::OutOfMemory), Carry::KillerUnknown],
    'a crash, which counts a kill' => ['base', carryMutant(3, MutantStatus::Errored), Carry::KillerUnknown],
    'a survivor, at another base' => ['other base', carryMutant(3, MutantStatus::Survived), Carry::Stands],
    'an uncovered mutant no test covers now' => ['base', carryMutant(3, MutantStatus::Uncovered), Carry::Stands],
    'an uncovered mutant a test covers now' => ['base', carryMutant(7, MutantStatus::Uncovered), Carry::NowCovered],
    'an uncovered mutant a test covers a later line of now' => ['base', carryMutant(5, MutantStatus::Uncovered, last: 7), Carry::NowCovered],
    'an uncovered mutant that ends before the line a test covers now' => ['base', carryMutant(5, MutantStatus::Uncovered, last: 6), Carry::Stands],
    'a mutant marked ignored' => ['base', carryMutant(3, MutantStatus::IgnoredByMarker), Carry::Stands],
    'a mutant skipped' => ['base', carryMutant(3, MutantStatus::Skipped), Carry::Stands],
    'a mutant unjudged' => ['base', carryMutant(3, MutantStatus::Unjudged), Carry::Stands],
]);

it('carries a kill from another base only where nothing changed since its commit reaches its unit or its tests by name', function (
    Inputs $inputs,
    Mutant|ProvedKill $kill,
    Carry $carry,
) use ($carrying): void {
    expect($carrying->carry(carryProof('other base', $inputs), $kill))->toBe($carry);
})->with([
    'an unrelated class changed' => [$recorded->takenAt(carryCommit('unrelated')), carryKill(3, TestId::of('MoneyTest::adds')), Carry::Stands],
    'a kill a run reported, an unrelated class changed' => [
        $recorded->takenAt(carryCommit('unrelated')),
        carryMutant(3, MutantStatus::Killed)->killedBy(TestIds::of(TestId::of('MoneyTest::adds'))),
        Carry::Stands,
    ],
    'the trait its unit uses changed' => [$recorded->takenAt(carryCommit('callee')), carryKill(3, TestId::of('MoneyTest::adds')), Carry::Reached],
    'git cannot say what changed since' => [$recorded->takenAt(carryCommit('shallow')), carryKill(3, TestId::of('MoneyTest::adds')), Carry::ChangeUnknown],
    'a commit the verdict did not read' => [$recorded->takenAt(carryCommit('unread')), carryKill(3, TestId::of('MoneyTest::adds')), Carry::ChangeUnknown],
    'a result from a changed working tree, which records no commit' => [$recorded, carryKill(3, TestId::of('MoneyTest::adds')), Carry::NoCommit],
    'a killing test that changed, whatever changed since' => [$recorded->takenAt(carryCommit('unrelated')), carryKill(3, TestId::of('TaxTest::rounds')), Carry::KillerChanged],
    'a killing test unknown, whatever changed since' => [$recorded->takenAt(carryCommit('unrelated')), carryKill(3), Carry::KillerUnknown],
]);

it('carries a kill by static analysis from another base only where nothing changed since reaches its unit or the file its finding sits in', function (
    Inputs $inputs,
    Mutant $rejected,
    Carry $carry,
) use ($carrying): void {
    expect($carrying->carry(carryProof('other base', $inputs), $rejected))->toBe($carry);
})->with([
    'an unrelated class changed' => [$recorded->takenAt(carryCommit('unrelated')), carryRejected(3), Carry::Stands],
    'the trait its unit uses changed' => [$recorded->takenAt(carryCommit('callee')), carryRejected(3), Carry::Reached],
    'the file its finding sits in changed, which nothing of its unit names' => [
        $recorded->takenAt(carryCommit('unrelated')),
        carryRejected(3, 'src/Tax.php'),
        Carry::Reached,
    ],
    'git cannot say what changed since' => [$recorded->takenAt(carryCommit('shallow')), carryRejected(3), Carry::ChangeUnknown],
    'a result from a changed working tree, which records no commit' => [$recorded, carryRejected(3), Carry::NoCommit],
    'a finding outside the repository, whatever changed since' => [
        $recorded->takenAt(carryCommit('unrelated')),
        carryRejected(3, '/elsewhere/Lib.php'),
        Carry::FindingOutside,
    ],
]);

it('carries no kill where the run cannot name its tests, nor a proof without digests', function () use ($now, $map, $recorded, $names, $since): void {
    $kill = carryKill(3, TestId::of('MoneyTest::adds'));

    expect(Carrying::against($now, carryDigest('base'), CannotJudge::because('Unnamed.'), $map, $since)->carry(carryProof('base', $recorded), $kill))
        ->toBe(Carry::KillerUnknown)
        ->and(Carrying::against($now, carryDigest('base'), $names, $map, $since)->carry(carryProof('base', Undigested::proof()), $kill))
        ->toBe(Carry::KillerChanged)
        ->and(Carrying::against(Undigested::proof(), carryDigest('base'), $names, $map, $since)->carry(carryProof('base', $recorded), $kill))
        ->toBe(Carry::KillerChanged);
});

it('carries no uncovered mutant where the run has no coverage map', function () use ($now, $names, $recorded, $since): void {
    expect(Carrying::against($now, carryDigest('base'), $names, CannotJudge::because('No map.'), $since)->carry(carryProof('base', $recorded), carryMutant(3, MutantStatus::Uncovered)))
        ->toBe(Carry::CoverageUnknown);
});
