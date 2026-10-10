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

$makeSince = static fn(): ChangesSince => ChangesSince::none()
    ->with(carryCommit('unrelated'), carryChanged('src/Tax.php'))
    ->with(carryCommit('callee'), carryChanged('src/Equals.php'))
    ->with(carryCommit('shallow'), CannotTell::because('The clone is shallow.'));
$makeRecorded = static fn(): Inputs => Inputs::of(carryDigest('source'), carryDigest('mutation'))
    ->withTest(Path::of('tests/MoneyTest.php'), carryDigest('money test'))
    ->withTest(Path::of('tests/TaxTest.php'), carryDigest('tax test'))
    ->withTest(Path::of('tests/RateTest.php'), carryDigest('rate test'));
$makeNow = static fn(): Digests => Digests::of(carryDigest('mutation'))
    ->withSource(Path::of('src/Money.php'), carryDigest('source'))
    ->withTest(Path::of('tests/MoneyTest.php'), carryDigest('money test'))
    ->withTest(Path::of('tests/TaxTest.php'), carryDigest('tax test changed'))
    ->withTest(Path::of('tests/NewTest.php'), carryDigest('new test'));
$makeNames = static fn(): TestNames => TestNames::none()
    ->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'adds'))
    ->with(TestId::of('TaxTest::rounds'), TestName::in(Path::of('tests/TaxTest.php'), 'rounds'))
    ->with(TestId::of('NewTest::adds'), TestName::in(Path::of('tests/NewTest.php'), 'adds'))
    ->with(TestId::of('RateTest::rates'), TestName::in(Path::of('tests/RateTest.php'), 'rates'));
$makeMap = static fn(): CoverageMap => CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(7), TestId::of('MoneyTest::adds'));
$makeCarrying = static fn(): Carrying => Carrying::against($makeNow(), carryDigest('base'), $makeNames(), $makeMap(), $makeSince());

it('counts a unit by its newest result where its source and mutant set are unchanged', function () use ($makeCarrying, $makeRecorded): void {
    $carrying = $makeCarrying();
    $recorded = $makeRecorded();

    $proof = carryProof('base', $recorded);

    expect($carrying->counted($proof))->toBe($proof);
});

it('counts no unit by a result that cannot stand for the code on disk, and says why', function (
    Proof|NeverProved $newest,
    Digests|Undigested $now,
    Uncounted $why,
) use ($makeNames, $makeMap, $makeSince): void {
    $names = $makeNames();
    $map = $makeMap();
    $since = $makeSince();

    expect(Carrying::against($now, carryDigest('base'), $names, $map, $since)->counted($newest))->toBe($why);
})->with([
    'no result anywhere' => [fn(): NeverProved => NeverProved::unit(Path::of('src/Money.php')), fn(): Digests => Digests::of(carryDigest('mutation')), Uncounted::NoResult],
    'a result of an earlier ledger format' => [fn(): Proof => carryProof('base', Undigested::proof()), fn(): Digests => Digests::of(carryDigest('mutation')), Uncounted::NoDigests],
    'a run with no digests' => [fn(): Proof => carryProof('base', Inputs::of(carryDigest('source'), carryDigest('mutation'))), fn(): Undigested => Undigested::proof(), Uncounted::NoDigests],
    'new code, the result of the code before' => [
        fn(): Proof => carryProof('base', Inputs::of(carryDigest('source before'), carryDigest('mutation'))),
        fn(): Digests => Digests::of(carryDigest('mutation'))->withSource(Path::of('src/Money.php'), carryDigest('source')),
        Uncounted::SourceChanged,
    ],
    'a source the run has no digest of' => [
        fn(): Proof => carryProof('base', Inputs::of(carryDigest('source'), carryDigest('mutation'))),
        fn(): Digests => Digests::of(carryDigest('mutation')),
        Uncounted::SourceChanged,
    ],
    'a changed mutator config' => [
        fn(): Proof => carryProof('base', Inputs::of(carryDigest('source'), carryDigest('mutators before'))),
        fn(): Digests => Digests::of(carryDigest('mutation'))->withSource(Path::of('src/Money.php'), carryDigest('source')),
        Uncounted::MutationChanged,
    ],
]);

it('carries each mutant of a counted result as it stands, or unjudged, and says why', function (
    string $base,
    Mutant|ProvedKill $mutant,
    Carry $carry,
) use ($makeCarrying, $makeRecorded): void {
    $carrying = $makeCarrying();
    $recorded = $makeRecorded();

    expect($carrying->carry(carryProof($base, $recorded), $mutant))->toBe($carry);
})->with([
    'a kill at the same base by a test unchanged' => ['base', fn(): ProvedKill => carryKill(3, TestId::of('MoneyTest::adds')), Carry::Stands],
    'a kill a run reported, by a test unchanged' => ['base', fn(): Mutant => carryMutant(3, MutantStatus::Killed)->killedBy(TestIds::of(TestId::of('MoneyTest::adds'))), Carry::Stands],
    'a kill by a test that changed' => ['base', fn(): ProvedKill => carryKill(3, TestId::of('TaxTest::rounds')), Carry::KillerChanged],
    'a kill by one test unchanged and one changed' => ['base', fn(): ProvedKill => carryKill(3, TestId::of('MoneyTest::adds'), TestId::of('TaxTest::rounds')), Carry::KillerChanged],
    'a kill by one test changed and one unchanged' => ['base', fn(): ProvedKill => carryKill(3, TestId::of('TaxTest::rounds'), TestId::of('MoneyTest::adds')), Carry::KillerChanged],
    'a kill by one test unknown and one changed' => ['base', fn(): ProvedKill => carryKill(3, TestId::of('GoneTest::adds'), TestId::of('TaxTest::rounds')), Carry::KillerUnknown],
    'a kill by a test its result recorded no digest of' => ['base', fn(): ProvedKill => carryKill(3, TestId::of('NewTest::adds')), Carry::KillerChanged],
    'a kill by a test file the run no longer has' => ['base', fn(): ProvedKill => carryKill(3, TestId::of('RateTest::rates')), Carry::KillerChanged],
    'a kill by a deleted test the run cannot name' => ['base', fn(): ProvedKill => carryKill(3, TestId::of('GoneTest::adds')), Carry::KillerUnknown],
    'a kill no test is known for' => ['base', fn(): ProvedKill => carryKill(3), Carry::KillerUnknown],
    'a kill by static analysis at the same base' => ['base', fn(): Mutant => carryRejected(3), Carry::Stands],
    'a kill by static analysis in another file, at the same base' => ['base', fn(): Mutant => carryRejected(3, 'src/Wallet.php'), Carry::Stands],
    'a kill by static analysis that records no finding, as Infection reports one' => ['base', fn(): Mutant => carryMutant(3, MutantStatus::KilledByStaticAnalysis), Carry::RejectionUnknown],
    'a kill by static analysis in a file outside the repository' => ['base', fn(): Mutant => carryRejected(3, '/elsewhere/Lib.php'), Carry::FindingOutside],
    'a kill by static analysis in a file above the repository' => ['base', fn(): Mutant => carryRejected(3, '../lib/Lib.php'), Carry::FindingOutside],
    'a timeout, which triage may count a kill' => ['base', fn(): Mutant => carryMutant(3, MutantStatus::TimedOut), Carry::KillerUnknown],
    'out of memory, which triage may count a kill' => ['base', fn(): Mutant => carryMutant(3, MutantStatus::OutOfMemory), Carry::KillerUnknown],
    'a crash, which counts a kill' => ['base', fn(): Mutant => carryMutant(3, MutantStatus::Errored), Carry::KillerUnknown],
    'a survivor, at another base' => ['other base', fn(): Mutant => carryMutant(3, MutantStatus::Survived), Carry::Stands],
    'an uncovered mutant no test covers now' => ['base', fn(): Mutant => carryMutant(3, MutantStatus::Uncovered), Carry::Stands],
    'an uncovered mutant a test covers now' => ['base', fn(): Mutant => carryMutant(7, MutantStatus::Uncovered), Carry::NowCovered],
    'an uncovered mutant a test covers a later line of now' => ['base', fn(): Mutant => carryMutant(5, MutantStatus::Uncovered, last: 7), Carry::NowCovered],
    'an uncovered mutant that ends before the line a test covers now' => ['base', fn(): Mutant => carryMutant(5, MutantStatus::Uncovered, last: 6), Carry::Stands],
    'a mutant marked ignored' => ['base', fn(): Mutant => carryMutant(3, MutantStatus::IgnoredByMarker), Carry::Stands],
    'a mutant skipped' => ['base', fn(): Mutant => carryMutant(3, MutantStatus::Skipped), Carry::Stands],
    'a mutant unjudged' => ['base', fn(): Mutant => carryMutant(3, MutantStatus::Unjudged), Carry::Stands],
]);

it('carries a kill from another base only where nothing changed since its commit reaches its unit or its tests by name', function (
    Inputs $inputs,
    Mutant|ProvedKill $kill,
    Carry $carry,
) use ($makeCarrying): void {
    $carrying = $makeCarrying();

    expect($carrying->carry(carryProof('other base', $inputs), $kill))->toBe($carry);
})->with([
    'an unrelated class changed' => [fn(): Inputs => $makeRecorded()->takenAt(carryCommit('unrelated')), fn(): ProvedKill => carryKill(3, TestId::of('MoneyTest::adds')), Carry::Stands],
    'a kill a run reported, an unrelated class changed' => [
        fn(): Inputs => $makeRecorded()->takenAt(carryCommit('unrelated')),
        fn(): Mutant => carryMutant(3, MutantStatus::Killed)->killedBy(TestIds::of(TestId::of('MoneyTest::adds'))),
        Carry::Stands,
    ],
    'the trait its unit uses changed' => [fn(): Inputs => $makeRecorded()->takenAt(carryCommit('callee')), fn(): ProvedKill => carryKill(3, TestId::of('MoneyTest::adds')), Carry::Reached],
    'git cannot say what changed since' => [fn(): Inputs => $makeRecorded()->takenAt(carryCommit('shallow')), fn(): ProvedKill => carryKill(3, TestId::of('MoneyTest::adds')), Carry::ChangeUnknown],
    'a commit the verdict did not read' => [fn(): Inputs => $makeRecorded()->takenAt(carryCommit('unread')), fn(): ProvedKill => carryKill(3, TestId::of('MoneyTest::adds')), Carry::ChangeUnknown],
    'a result from a changed working tree, which records no commit' => [fn(): Inputs => $makeRecorded(), fn(): ProvedKill => carryKill(3, TestId::of('MoneyTest::adds')), Carry::NoCommit],
    'a killing test that changed, whatever changed since' => [fn(): Inputs => $makeRecorded()->takenAt(carryCommit('unrelated')), fn(): ProvedKill => carryKill(3, TestId::of('TaxTest::rounds')), Carry::KillerChanged],
    'a killing test unknown, whatever changed since' => [fn(): Inputs => $makeRecorded()->takenAt(carryCommit('unrelated')), fn(): ProvedKill => carryKill(3), Carry::KillerUnknown],
]);

it('carries a kill by static analysis from another base only where nothing changed since reaches its unit or the file its finding sits in', function (
    Inputs $inputs,
    Mutant $rejected,
    Carry $carry,
) use ($makeCarrying): void {
    $carrying = $makeCarrying();

    expect($carrying->carry(carryProof('other base', $inputs), $rejected))->toBe($carry);
})->with([
    'an unrelated class changed' => [fn(): Inputs => $makeRecorded()->takenAt(carryCommit('unrelated')), fn(): Mutant => carryRejected(3), Carry::Stands],
    'the trait its unit uses changed' => [fn(): Inputs => $makeRecorded()->takenAt(carryCommit('callee')), fn(): Mutant => carryRejected(3), Carry::Reached],
    'the file its finding sits in changed, which nothing of its unit names' => [
        fn(): Inputs => $makeRecorded()->takenAt(carryCommit('unrelated')),
        fn(): Mutant => carryRejected(3, 'src/Tax.php'),
        Carry::Reached,
    ],
    'git cannot say what changed since' => [fn(): Inputs => $makeRecorded()->takenAt(carryCommit('shallow')), fn(): Mutant => carryRejected(3), Carry::ChangeUnknown],
    'a result from a changed working tree, which records no commit' => [fn(): Inputs => $makeRecorded(), fn(): Mutant => carryRejected(3), Carry::NoCommit],
    'a finding outside the repository, whatever changed since' => [
        fn(): Inputs => $makeRecorded()->takenAt(carryCommit('unrelated')),
        fn(): Mutant => carryRejected(3, '/elsewhere/Lib.php'),
        Carry::FindingOutside,
    ],
]);

it('carries no kill where the run cannot name its tests, nor a proof without digests', function () use ($makeNow, $makeMap, $makeRecorded, $makeNames, $makeSince): void {
    $now = $makeNow();
    $map = $makeMap();
    $recorded = $makeRecorded();
    $names = $makeNames();
    $since = $makeSince();

    $kill = carryKill(3, TestId::of('MoneyTest::adds'));

    expect(Carrying::against($now, carryDigest('base'), CannotJudge::because('Unnamed.'), $map, $since)->carry(carryProof('base', $recorded), $kill))
        ->toBe(Carry::KillerUnknown)
        ->and(Carrying::against($now, carryDigest('base'), $names, $map, $since)->carry(carryProof('base', Undigested::proof()), $kill))
        ->toBe(Carry::KillerChanged)
        ->and(Carrying::against(Undigested::proof(), carryDigest('base'), $names, $map, $since)->carry(carryProof('base', $recorded), $kill))
        ->toBe(Carry::KillerChanged);
});

it('carries no uncovered mutant where the run has no coverage map', function () use ($makeNow, $makeNames, $makeRecorded, $makeSince): void {
    $now = $makeNow();
    $names = $makeNames();
    $recorded = $makeRecorded();
    $since = $makeSince();

    expect(Carrying::against($now, carryDigest('base'), $names, CannotJudge::because('No map.'), $since)->carry(carryProof('base', $recorded), carryMutant(3, MutantStatus::Uncovered)))
        ->toBe(Carry::CoverageUnknown);
});
