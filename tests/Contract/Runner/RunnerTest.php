<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Cli\Flow\KeptCoverage;
use NightWorksIO\MutationGate\Cli\Flow\KeptFrom;
use NightWorksIO\MutationGate\Core\Analysis\AsWritten;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Remeasured;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Considering;
use NightWorksIO\MutationGate\Core\Plan\OwnOnly;
use NightWorksIO\MutationGate\Core\Proof\Access;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Recording;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\Unmade;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\RunnerContracts;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// What every runner reports over the fixture library in fixture/: in
// src/Money.php a killed, a survived, an uncovered and a timed-out mutant, and
// in src/Held.php one held by the group holds:src/Held.php. Each runs against
// the fake (RunnerFake) and against every adapter whose runner is installed:
// the Pest adapter (Pest) once the runner contracts job has installed the
// library, the Infection adapter (Infection) once its job has installed
// infection-fixture/, the same code tested by PHPUnit, and the PHPUnit runner
// (PhpUnit) once its steps have installed phpunit-fixture/, whose library/
// holds the same code tested by PHPUnit. The Pest and Infection libraries also
// hold their runner's own ignore marker in marked/Marked.php; the PHPUnit
// runner has none. Every library holds beside/Ledger.php too, outside src/,
// which its phpunit.xml names as source and tests/LedgerSpec.php tests, and
// beside/Stock.php, which plugins/stock/tests/StockSpec.php tests, in a
// testsuite directory its phpunit.xml gives as plugins/*/tests. Each adapter
// is told the directories of its library's tests as the flows read them from
// that phpunit.xml. The runs are real, so each request runs once per library.

afterEach(function (): void {
    Scratch::sweep();
});

$libraries = ['the fake' => fn(): Library => Library::fake()];

if (Library::isInstalled()) {
    $libraries['pest'] = fn(): Library => Library::pest(Patching::off());
}

if (Library::isInfectionInstalled()) {
    $libraries['infection'] = fn(): Library => Library::infection(Seconds::of(10.0));
}

if (Library::isPhpUnitInstalled()) {
    $libraries['phpunit'] = fn(): Library => Library::phpunit();
}

/** The libraries whose runner reads a map from disk: every one but the fake. */
$onDisk = array_diff_key($libraries, ['the fake' => true]);

$money = RunnerContracts::money(...);
$files = RunnerContracts::files(...);

it('answers the same identity every time it is asked', function (Library $library): void {
    $identity = $library->runner()->identity(Withheld::standard());

    expect($identity)->toBeInstanceOf(Identity::class)
        ->and($library->runner()->identity(Withheld::standard()))->toEqual($identity)
        ->and($identity instanceof Identity ? $identity->runner() : '')->not->toBe('')
        ->and($identity instanceof Identity ? count($identity->versions()) : 0)->toBeGreaterThan(0);
})->with($libraries);

it('behaves consistently: every key reads only groups of its suite', function (Library $library): void {
    $groups = $library->runner()->groups(Withheld::standard());
    $behaviour = $library->runner()->behaviour();

    foreach ($behaviour->readByEveryKey() as $group) {
        expect($groups instanceof Groups && $groups->has($group))->toBeTrue();
    }

    expect($behaviour->readByEveryKey()->count() === 0 || ! $behaviour->opensEachShard())->toBeTrue();
})->with([
    ...$libraries,
    ...Library::isInstalled() ? ['pest patched' => fn(): Library => Library::pest(Patching::on(Library::canary()))] : [],
]);

it('lists the group that holds a path, and the canary, among the suite\'s groups', function (Library $library): void {
    $groups = $library->runner()->groups(Withheld::standard());

    expect($groups instanceof Groups && $groups->has(Group::named('holds:src/Held.php')))->toBeTrue()
        ->and($groups instanceof Groups && $groups->has(Library::canary()))->toBeTrue();
})->with($libraries);

it('times a run of no test, started as a mutant\'s own run', function (Library $library): void {
    $startUp = $library->runner()->startUp(Path::of('src/Money.php'), Withheld::standard());

    expect($startUp)->toBeInstanceOf(Seconds::class)
        ->and($startUp instanceof Seconds ? $startUp->seconds() : 0.0)->toBeGreaterThan(0.0);
})->with($libraries);

it('reports a killed, a survived, an uncovered and a timed-out mutant', function (Library $library) use ($money): void {
    $result = $money($library);

    expect($result)->toBeInstanceOf(MutationResult::class)
        ->and($result instanceof MutationResult ? $result->skipped() : -1)->toBe(0)
        ->and($result instanceof MutationResult ? Library::records($result->mutants()) : [])
        ->toEqualCanonicalizing($library->expected('adds', 'large', 'unused', 'drains'))
        ->and($result instanceof MutationResult ? array_map(static fn(Warning $warning): string => $warning->text(), [...$result->warnings()]) : ['cannot judge'])
        ->toBe($library->runner() instanceof Infection ? [RunnerContracts::INFECTION_UNPATCHED] : []);
})->with($libraries);

it('leaves a proof of what it judged, which a last-run change carries while its source reads as it did then, and reaches once it does not', function (Library $library) use ($money): void {
    $result = $money($library);
    $unit = Path::of('src/Money.php');
    $mutation = Digest::sha256Of('mutation');
    $asRun = Digests::of($mutation)->withSource($unit, Digest::sha256Of('money as it ran'));
    $proof = $result instanceof MutationResult
        ? Recording::of(
            Digest::sha256Of('money'),
            $unit,
            $result->mutants(),
            MutantIds::none(),
            Run::of('contract', Moment::at('2026-10-01T10:00:00Z'), Digest::sha256Of('base')),
            $asRun->inputsOf($unit, Paths::none()),
        )
        : $result;
    $carrying = static fn(Digests $now): Considering => Considering::of(
        Units::of(Unit::file($unit)),
        Reach::nothing(Packages::of(Trees::none())),
        Proofs::none(),
        $proof instanceof Proof ? Proofs::of($proof) : Proofs::none(),
        OwnOnly::of(Paths::of($unit), $now),
    );

    expect($proof)->toBeInstanceOf(Proof::class)
        ->and(count($carrying($asRun)->carried()))->toBe(1)
        ->and(count($carrying(Digests::of($mutation)->withSource($unit, Digest::sha256Of('money since')))->considered()))->toBe(1);
})->with($libraries);

it('gives a kill how far its run went where its runner can tell, and no ending where it names a killer', function (Library $library) use ($money): void {
    $result = $money($library);
    $killed = array_values(array_filter(
        $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [],
        static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Killed,
    ));
    $evidence = $result instanceof MutationResult && $killed !== [] ? $result->evidence()->of($killed[0]->id()) : Evidence::none();
    $prefix = $evidence->prefix();

    expect($killed)->toHaveCount(1)
        ->and($evidence->ended())->toBeInstanceOf(NotGiven::class)
        ->and($library->runner() instanceof Infection ? $prefix instanceof NotGiven : $prefix instanceof Prefix && $prefix->position() >= 1)
        ->toBeTrue()
        ->and($prefix instanceof Prefix && is_string($prefix->key()) ? $prefix->key() : '')
        ->toMatch($library->runner() instanceof Infection || $library->runner() instanceof RunnerFake ? '/^$/' : '/^[0-9a-f]{12}$/')
        ->and($result instanceof MutationResult ? count($result->evidence()) : -1)->toBe($library->runner() instanceof Infection ? 0 : 1);
})->with($libraries);

it('names the steps its time went to, in the order they started, the mutants\' run among them with every mutant it judged', function (Library $library) use ($money): void {
    $result = $money($library);
    $steps = $result instanceof MutationResult ? [...$result->steps()] : [];
    $since = array_map(static fn(StepTime $step): float => $step->since()->seconds(), $steps);
    $ordered = $since;
    sort($ordered);
    $mutation = array_values(array_filter($steps, static fn(StepTime $step): bool => $step->step() === Step::Mutation));

    expect($mutation)->toHaveCount(1)
        ->and($mutation[0]->count())->toBe($result instanceof MutationResult ? count($result->mutants()) : -1)
        ->and($since)->toBe($ordered)
        ->and(array_filter($steps, static fn(StepTime $step): bool => $step->took()->seconds() < 0.0))->toBe([]);
})->with($libraries);

it('reports the same four mutants from warm workers, and warns of nothing, with PHPUnit', function (): void {
    $library = Library::phpunit();
    $result = $library->mutate(
        'money warm',
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
            ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds', 'large', 'unused', 'drains')))
            ->across(Pool::of(ProcessCount::of(2), Workers::Fork)),
    );

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : [])
        ->toEqualCanonicalizing($library->expected('adds', 'large', 'unused', 'drains'))
        ->and($result instanceof MutationResult ? [...$result->warnings()] : ['cannot judge'])->toBe([]);
})->skip(! Library::isPhpUnitInstalled() || ! function_exists('pcntl_fork'), 'the runner contracts job installs the PHPUnit library, on a PHP that forks');

// PHPUnit fails a run for an option it deprecates only where the project's
// config fails on its own deprecations. The phpunit library's does, so a
// runner that passed one would kill the mutant its tests let survive.
it('lets a mutant survive in a project that fails on PHPUnit\'s own deprecations', function () use ($money): void {
    $config = (string) file_get_contents(Tree::at(sprintf('%s/phpunit.xml', Library::PHPUNIT_DIRECTORY)));
    $library = Library::phpunit();
    $result = $money($library);

    expect($config)->toContain('failOnPhpunitDeprecation="true"')
        ->and($result instanceof MutationResult ? Library::records($result->mutants()) : [])
        ->toContain(...$library->expected('large'));
})->skip(! Library::isPhpUnitInstalled(), 'the PHPUnit runner contracts steps install its library');

it('measures each mutant it ran, and gives a timed-out one its limit', function (Library $library) use ($money): void {
    $result = $money($library);
    $measured = [];
    $limits = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $duration = $mutant->duration();
        $limit = $mutant->limit();
        $measured[$mutant->status()->value] = $duration instanceof Seconds && $duration->seconds() > 0.0;
        $limits[$mutant->status()->value] = $limit instanceof Seconds && $limit->seconds() >= 5.0;
    }

    $ran = $library->measures();

    expect($measured)
        ->toEqualCanonicalizing(['killed' => $ran, 'survived' => $ran, 'uncovered' => false, 'timed-out' => $ran])
        ->and($limits)
        ->toEqualCanonicalizing(['killed' => false, 'survived' => false, 'uncovered' => false, 'timed-out' => true]);
})->with($libraries);

it('reports only the files it was asked for, less the paths left out', function (Library $library) use ($files): void {
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src')), Narrowing::none()->toMutators($library->mutators('adds')));
    $everything = $library->mutate('src', $request);
    $leftOut = $library->mutate('src less held', $request->leavingOut(Paths::of(Path::of('src/Held.php'))));

    $asked = $everything instanceof MutationResult ? $files($everything->mutants()) : [];

    expect($asked)->toEqualCanonicalizing(['src/Money.php', 'src/Held.php'])
        ->and($leftOut instanceof MutationResult ? $files($leftOut->mutants()) : [])->toBe(['src/Money.php']);
})->with($libraries);

it('judges each file alike in a chunk of its own and in one beside another file, as a doomed run\'s chunks rely on', function (Library $library): void {
    $request = static fn(Path ...$files): MutationRequest => MutationRequest::of(Paths::of(...$files), WholeSuite::tests())
        ->narrowedTo(Paths::of(...$files), Narrowing::none()->toMutators($library->mutators('adds')));
    $records = static fn(MutationResult|CannotJudge $result): array => $result instanceof MutationResult
        ? Library::records($result->mutants())
        : [$result->why()];
    $together = $library->mutate('money and held', $request(Path::of('src/Money.php'), Path::of('src/Held.php')));
    $money = $library->mutate('money alone', $request(Path::of('src/Money.php')));
    $held = $library->mutate('held alone', $request(Path::of('src/Held.php')));

    expect($records($together))->not->toBe([])
        ->and($records($together))->toEqualCanonicalizing([...$records($money), ...$records($held)])
        ->and($records($money))->not->toBe([])
        ->and($records($held))->not->toBe([]);
})->with($libraries);

it('gives every mutant an id of its own in the gate\'s spelling', function (Library $library) use ($money): void {
    $result = $money($library);
    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];
    $ids = array_map(static fn(Mutant $mutant): string => $mutant->id()->value(), $mutants);

    expect($ids)->not->toBe([])
        ->and(array_unique($ids))->toBe($ids)
        ->and(array_filter($ids, static fn(string $id): bool => ! MutantId::parse($id) instanceof MutantId))->toBe([]);
})->with($libraries);

it('judges a held path by its group alone, where a test outside it would kill it', function (Library $library): void {
    $request = MutationRequest::of(Paths::of(Path::of('src/Held.php')), Group::named('holds:src/Held.php'))
        ->narrowedTo(Paths::of(Path::of('src/Held.php')), Narrowing::none()->toMutators($library->mutators('held')));
    $result = $library->mutate('held', $request);

    $records = $result instanceof MutationResult ? Library::records($result->mutants()) : [];

    expect($records)->toBe($library->expected('held'));
})->with($libraries);

it('runs a survivor again alone and matches it back by the gate\'s id', function (Library $library) use ($money): void {
    $result = $money($library);
    $survivors = Mutants::none();

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $survivors = $mutant->status() === MutantStatus::Survived ? $survivors->with($mutant) : $survivors;
    }

    $retried = $library->runner()->retry(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $survivors, Seconds::of(60.0));

    expect(count($survivors))->toBe(1)
        ->and($retried instanceof Mutants ? Library::records($retried) : [])->toBe(Library::records($survivors));
})->with($libraries);

it('gives a survivor as a static analyser checks it: its original with only its change made', function (Library $library) use ($money): void {
    $result = $money($library);
    $survivor = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $survivor = $mutant->status() === MutantStatus::Survived ? [$mutant] : $survivor;
    }

    $checkable = $survivor === [] ? CannotJudge::because('No survivor.') : $library->runner()->checkable($survivor[0]);
    $original = $checkable instanceof Checkable ? $checkable->original() : Contents::of('');
    $originalText = $original instanceof AsWritten
        ? sprintf('%s', file_get_contents(Tree::at(sprintf('%s/src/Money.php', Library::DIRECTORY))))
        : $original->text();
    $mutantText = $checkable instanceof Checkable ? $checkable->mutant()->text() : '';
    $change = Library::CHANGES['large'];

    expect($checkable)->toBeInstanceOf(Checkable::class)
        ->and($mutantText)->toContain($change['added'])
        ->and($mutantText)->not->toContain($change['removed'])
        ->and(str_replace($change['added'], $change['removed'], $mutantText))->toBe($originalText);
})->with($libraries);

it('cannot give a mutant as an analyser checks it where its file is gone', function (Library $library) use ($money): void {
    $result = $money($library);
    $gone = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $gone = [Mutant::of(
            $mutant->id(),
            $mutant->nativeId(),
            Location::of(Path::of('src/Gone.php'), $mutant->location()->start(), $mutant->location()->end()),
            $mutant->mutation(),
            $mutant->status(),
            $mutant->duration(),
        )];
    }

    $answers = array_map(static fn(Mutant $mutant): string => $library->runner()->checkable($mutant)::class, $gone);

    expect($answers)->toBe([CannotJudge::class]);
})->with($libraries);

it('reproduces a survivor on its own, matched back by the gate\'s id, with what the runner printed', function (Library $library) use ($money): void {
    $result = $money($library);
    $survivors = Mutants::none();

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $survivors = $mutant->status() === MutantStatus::Survived ? $survivors->with($mutant) : $survivors;
    }

    $reproduced = [];

    foreach ($survivors as $survivor) {
        $reproduced[] = $library->runner()->reproduce(Reproducible::of($survivor), MutationRequest::of(Paths::none(), WholeSuite::tests())->withholding(Withheld::standard()), Seconds::of(60.0));
    }

    expect($reproduced)->toHaveCount(1)
        ->and($reproduced[0] instanceof Reproduction && $reproduced[0]->mutant() instanceof Mutant ? Library::records(Mutants::of($reproduced[0]->mutant())) : $reproduced)->toBe(Library::records($survivors))
        ->and($reproduced[0] instanceof Reproduction ? $reproduced[0]->printed() : '')->toContain($library->prints());
})->with($libraries);

it('reproduces a mutant by the tests that judged its unit, and says the run made none where it no longer makes it', function (Library $library) use ($money): void {
    $result = $money($library);
    $survivor = null;

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $survivor = $mutant->status() === MutantStatus::Survived ? $mutant : $survivor;
    }

    $runner = $library->runner();
    $held = $survivor instanceof Mutant ? $runner->reproduce(Reproducible::of($survivor), MutationRequest::of(Paths::none(), Group::named('holds:src/Held.php'))->withholding(Withheld::standard()), Seconds::of(60.0)) : null;
    $gone = $survivor instanceof Mutant ? $runner->reproduce(
        Reproducible::of(Mutant::of(MutantId::hash(Path::of('src/Money.php'), 'gone', '-a', 0), '', $survivor->location(), $survivor->mutation(), MutantStatus::Survived, $survivor->duration())),
        MutationRequest::of(Paths::none(), WholeSuite::tests()),
        Seconds::of(60.0),
    ) : null;

    // The group holds another file, so none of its tests reaches the survivor.
    expect($held instanceof Reproduction && $held->mutant() instanceof Mutant ? $held->mutant()->status() : $held)->toBe(MutantStatus::Uncovered)
        ->and($gone instanceof Reproduction ? $gone->mutant() : $gone)->toBeInstanceOf(Unmade::class);
})->with($libraries);

it('retries a mutant by the tests that judged its unit', function (Library $library) use ($money): void {
    $result = $money($library);
    $survivors = Mutants::none();

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $survivors = $mutant->status() === MutantStatus::Survived ? $survivors->with($mutant) : $survivors;
    }

    $retried = $library->runner()->retry(MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Held.php')), $survivors, Seconds::of(60.0));
    $statuses = [];

    foreach ($retried instanceof Mutants ? $retried : Mutants::none() as $mutant) {
        $statuses[] = $mutant->status();
    }

    // The group holds another file, so none of its tests reaches the survivor.
    expect($statuses)->toBe([MutantStatus::Uncovered]);
})->with($libraries);

it('reads back the gate\'s own map of what it ran, and cannot judge a map that is not there', function (
    Library $library,
): void {
    $ran = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $written = CoverageMapFile::encode($ran instanceof CoverageMap ? $ran : CoverageMap::empty(), Unplaced::map());
    $handed = Path::of('.mutation-gate/handed');
    $file = sprintf('%s/%s', $library->root(), CoverageMapFile::in($handed)->value());
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), recursive: true);
    }
    file_put_contents($file, $written);
    $read = $library->runner()->coverage(CoverageRead::from($handed));
    $missing = $library->runner()->coverage(CoverageRead::from(Path::of('.mutation-gate/nowhere')));
    unlink($file);

    expect($ran)->toBeInstanceOf(CoverageMap::class)
        ->and($read)->toEqual(CoverageMapFile::decode($written, HandedMaps::limits()))
        ->and($missing)->toBeInstanceOf(CannotJudge::class);
})->with($onDisk === [] ? ['none installed' => fn(): Library => Library::fake()] : $onDisk)
    ->skip($onDisk === [], 'the runner contracts jobs install the libraries whose runner reads from disk');

it('names the test files that judge a covered file, and none for an uncovered one', function (Library $library): void {
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $map = $library->runner()->coverage($request);
    $covered = $map instanceof CoverageMap ? $library->runner()->judges(Path::of('src/Money.php'), $map) : $map;
    $uncovered = $map instanceof CoverageMap ? $library->runner()->judges(Path::of('src/Nowhere.php'), $map) : $map;

    expect($covered)->toEqual(Paths::of(Path::of('tests/DrainSpec.php'), Path::of('tests/MoneySpec.php')))
        ->and($uncovered)->toEqual(Paths::none());
})->with($libraries);

// pcov, left unset, collects from src/ alone, so the lines of beside/ would
// read as run by no test.
it('measures a tree outside src/ that the project names as source, naming the test files that judge it', function (Library $library): void {
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $judges = $map instanceof CoverageMap ? $library->runner()->judges(Path::of('beside/Ledger.php'), $map) : $map;

    expect($judges)->toEqual(Paths::of(Path::of('tests/LedgerSpec.php')));
})->with($onDisk === [] ? ['none installed' => fn(): Library => Library::fake()] : $onDisk)
    ->skip($onDisk === [], 'the runner contracts jobs install the libraries whose runner reads from disk');

// A testsuite directory with a wildcard is each directory it matches, as
// PHPUnit runs it, and the runner is told those directories, as the flows
// tell it; read as written, plugins/*/tests is no directory, and its tests
// would judge nothing.
it('names a test file in a testsuite directory the config gives as a glob as one that judges what it covers', function (Library $library): void {
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $judges = $map instanceof CoverageMap ? $library->runner()->judges(Path::of('beside/Stock.php'), $map) : $map;

    expect($judges)->toEqual(Paths::of(Path::of('plugins/stock/tests/StockSpec.php')));
})->with($onDisk === [] ? ['none installed' => fn(): Library => Library::fake()] : $onDisk)
    ->skip($onDisk === [], 'the runner contracts jobs install the libraries whose runner reads from disk');

it('keeps its map beside a pull request\'s ledger, and reads it back first, from that scope, as a full run\'s', function (Library $library): void {
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $full = $map instanceof CoverageMap ? $map : CoverageMap::empty();
    $bytes = CoverageMapFile::keeping(KeptMap::of($full, MeasuredAt::of(Revision::ref('HEAD'), dirty: false), EntryKeys::none()), MapLimits::standard());
    $store = new ProofStoreFake();
    $store->keep(Scope::branch('main'), Companion::Coverage, Contents::of(CoverageMapFile::encode(CoverageMap::empty(), Unplaced::map())));
    $store->keep(Scope::pullRequest(7), Companion::Coverage, Contents::of(is_string($bytes) ? $bytes : ''));
    $read = KeptCoverage::fromStore($store, Access::of(Scope::pullRequest(7), Scope::branch('main'), Writing::Auto));
    $kept = $read->map();

    expect($map)->toBeInstanceOf(CoverageMap::class)
        ->and($read->from())->toBe(KeptFrom::OwnScope)
        ->and($kept instanceof KeptMap ? RunnerContracts::lines($kept->map()) : $kept)->toBe(RunnerContracts::lines($full));
})->with($onDisk === [] ? ['none installed' => fn(): Library => Library::fake()] : $onDisk)
    ->skip($onDisk === [], 'the runner contracts jobs install the libraries whose runner reads from disk');

it('measures a test file again, names the tests it holds, and the merged map is a full run\'s', function (Library $library): void {
    $runner = $library->runner();
    $full = $runner->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $covering = $full instanceof CoverageMap
        ? $full->testsCoveringFile(Path::of('src/Money.php'))
        : TestIds::none();
    $first = [...$covering][0] ?? TestId::of('none');
    $names = $runner->names(TestIds::of($first), Withheld::standard());
    $name = $names instanceof TestNames ? $names->testOf($first) : $first;
    $files = Paths::of($name instanceof TestName ? $name->file() : Path::of('none'));
    $held = $full instanceof CoverageMap ? $runner->testsIn($files, $full) : $full;
    $again = $runner->coverage(CoverageRun::of(TestPaths::of($files), Path::of('.mutation-gate/again')));
    $merged = $full instanceof CoverageMap && $held instanceof TestIds && $again instanceof CoverageMap
        ? Remeasured::over($full, $held, $again)
        : $again;

    expect($held instanceof TestIds && $held->has($first))->toBeTrue()
        ->and($again instanceof CoverageMap ? count($again->tests()) : $again)->toBeGreaterThan(0)
        ->and($merged instanceof CoverageMap ? RunnerContracts::lines($merged) : $merged)
        ->toBe($full instanceof CoverageMap ? RunnerContracts::lines($full) : $full);
})->with($libraries);

it('places every test of a full run\'s map in the test files that hold them, so a kept map can be measured by file', function (Library $library): void {
    $runner = $library->runner();
    $full = $runner->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $tests = $full instanceof CoverageMap ? $full->tests() : TestIds::none();
    $names = $runner->names($tests, Withheld::standard());
    $files = Paths::none();

    foreach ($tests as $test) {
        $name = $names instanceof TestNames ? $names->testOf($test) : $test;
        $files = $name instanceof TestName ? $files->with($name->file()) : $files;
    }

    $placed = $full instanceof CoverageMap ? $runner->testsIn($files, $full) : $full;

    expect(count($tests))->toBeGreaterThan(0)
        ->and($placed instanceof TestIds ? count($tests->without($placed)) : $placed)->toBe(0);
})->with($libraries);

it('finds its runner\'s own ignore marker, and none in code without one', function (Library $library): void {
    $found = $library->runner()->markers(Paths::of(Path::of('marked')));
    $none = $library->runner()->markers(Paths::of(Path::of('src/Money.php')));
    $where = $found instanceof Markers
        ? array_map(static fn(Marker $marker): string => $marker->where(), iterator_to_array($found, preserve_keys: false))
        : [];

    expect($where)->toBe($library->markers())
        ->and($none instanceof Markers ? count($none) : -1)->toBe(0);
})->with($libraries);

it('names the files that define it, the library\'s own among them, from the project\'s root', function (Library $library): void {
    $definitions = $library->runner()->definitions();

    foreach ($definitions as $definition) {
        expect($definition->value())->not->toStartWith('/')
            ->and($definition->value())->not->toBe('.');
    }

    foreach ($library->defining() as $file) {
        expect($definitions->has($file))->toBeTrue();
    }
})->with($libraries);

it('names each test by its file and description, and a data set row by the test it folds into', function (Library $library): void {
    $asked = TestIds::of(...array_map(TestId::of(...), array_keys($library->naming())));
    $answer = $library->runner()->names($asked, Withheld::standard());
    $names = $answer instanceof TestNames ? $answer : TestNames::none();
    $named = [];
    $folded = [];
    $tests = [];

    foreach ($asked as $test) {
        $name = $names->nameOf($test);
        $named[$test->value()] = $name->value();
        $folded[$test->value()] = $names->testOf($test)->value();
        $tests[$test->value()] = $name instanceof TestRow ? $name->test()->value() : $name->value();
    }

    expect($answer)->toBeInstanceOf(TestNames::class)
        ->and($named)->toBe($library->naming())
        ->and($folded)->toBe($tests);
})->with($libraries);

it('keys the PHP it starts, which a setting of the gate\'s own PHP never reaches', function (Library $library): void {
    $identity = $library->runner()->identity(Withheld::standard());
    $before = ini_get('memory_limit');
    ini_set('memory_limit', $before === '-1' ? '1G' : '-1');
    $fresh = $library->outside()->rootedAt($library->package(), $library->packageTests());
    $unreached = $fresh instanceof CannotJudge ? $fresh : $fresh->identity(Withheld::standard());
    ini_set('memory_limit', $before);

    expect($unreached)->toEqual($identity);
})->with($libraries);

it('answers as the runner built in a package when rooted in it from the directory around it', function (Library $library): void {
    $rooted = $library->outside()->rootedAt($library->package(), $library->packageTests());
    $runner = $library->runner();

    expect($rooted instanceof CannotJudge ? $rooted : $rooted->identity(Withheld::standard()))
        ->toEqual($runner->identity(Withheld::standard()))
        ->and($rooted instanceof CannotJudge ? $rooted : $rooted->definitions())->toEqual($runner->definitions())
        ->and($rooted instanceof CannotJudge ? $rooted : $rooted->groups(Withheld::standard()))
        ->toEqual($runner->groups(Withheld::standard()));
})->with($libraries);

// A runner rooted in a package from the directory around it is told the
// tests the package's own PHPUnit config names, read as the root's is: here
// plugins/*/tests, which the directory around it, holding no config, would
// read as the conventional tests alone.
it('judges by the tests a package\'s own PHPUnit config names when rooted in it from the directory around it', function (Library $library): void {
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $rooted = $library->outside()->rootedAt($library->package(), $library->packageTests());
    $judges = $map instanceof CoverageMap && ! $rooted instanceof CannotJudge
        ? $rooted->judges(Path::of('beside/Stock.php'), $map)
        : $map;

    expect($judges)->toEqual(Paths::of(Path::of('plugins/stock/tests/StockSpec.php')));
})->with($onDisk === [] ? ['none installed' => fn(): Library => Library::fake()] : $onDisk)
    ->skip($onDisk === [], 'the runner contracts jobs install the libraries whose runner reads from disk');

it('cannot judge a directory that holds no project it can run', function (Library $library): void {
    expect($library->runner()->rootedAt(Path::of('src'), $library->packageTests()))->toBeInstanceOf(CannotJudge::class)
        ->and($library->outside()->rootedAt(Path::of('nowhere'), $library->packageTests()))->toBeInstanceOf(CannotJudge::class);
})->with($libraries);
