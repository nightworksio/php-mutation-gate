<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Cli\Flow\KeptFrom;
use NightWorksIO\MutationGate\Cli\Flow\StoredCoverage;
use NightWorksIO\MutationGate\Core\Analysis\AsWritten;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
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
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Extension\LibraryChange;
use NightWorksIO\MutationGate\Extension\RunnerContract;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\RunnerContracts;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

$holds = [
    'holds:src/Adapter/Pest',
    'holds:src/Core/Coverage/Remeasured.php',
    'holds:src/Core/Mutant/Evidences.php',
    'holds:src/Core/Mutant/Mutators.php',
    'holds:src/Core/Plan/Considering.php',
    'holds:src/Core/Plan/OwnOnly.php',
    'holds:src/Core/Runner/MutationRequest.php',
    'holds:src/Core/Runner/Reproduction.php',
    'holds:src/Core/Test/TestIds.php',
    'holds:src/Extension/Checks',
    'holds:src/Extension/LibraryChange.php',
    'holds:src/Extension/RunnerContract.php',
    'holds:src/Extension/RunnerLibrary.php',
];

// What every runner reports over the fixture library in fixture/: in
// src/Money.php a killed, a survived, an uncovered and a timed-out mutant, and
// in src/Held.php one held by the group holds:src/Held.php, which
// RunnerContract holds every runner to, the gate's own and an extension's
// alike, and the rest of what each of the gate's own runners reports. Each runs against
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

/** The libraries whose runner reads a map from disk: every one but the fake. */
$onDisk = [
    'pest' => fn(): Library => Library::installed('pest'),
    'infection' => fn(): Library => Library::installed('infection'),
    'phpunit' => fn(): Library => Library::installed('phpunit'),
    'phpspec' => fn(): Library => Library::installed('phpspec'),
    'codeception' => fn(): Library => Library::installed('codeception'),
];

$libraries = ['the fake' => fn(): Library => Library::fake(), ...$onDisk];

$makeMoney = static fn(): Closure => RunnerContracts::money(...);

it('keeps the runner contract', function (Library $library): void {
    expect([...RunnerContract::failures($library->runner(), $library->spelling())])->toBe([]);
})->with($libraries)->group(...$holds);

it('behaves consistently when patched: every key reads only groups of its suite', function (Library $library): void {
    $groups = $library->runner()->listing(Withheld::standard(), Suites::all());
    $behaviour = $library->runner()->behaviour();

    foreach ($behaviour->readByEveryKey() as $group) {
        expect($groups instanceof TestListing && $groups->groups()->has($group))->toBeTrue();
    }

    expect($behaviour->readByEveryKey()->count() === 0 || ! $behaviour->opensEachShard())->toBeTrue();
})->with([
    'pest patched' => fn(): Library => Library::installed('pest patched'),
])->group(...$holds);

it('lists the canary among the suite\'s groups where its tests hold it', function (Library $library): void {
    $groups = $library->runner()->listing(Withheld::standard(), Suites::all());

    expect($groups instanceof TestListing && $groups->groups()->has(Library::canary()))->toBe($library->isCanaried());
})->with($libraries)->group(...$holds);

it('warns of the four mutants only where its runner runs unpatched', function (Library $library) use ($makeMoney): void {
    $money = $makeMoney();

    $result = $money($library);

    expect($result instanceof MutationResult ? array_map(static fn(Warning $warning): string => $warning->text(), [...$result->warnings()]) : ['cannot judge'])
        ->toBe($library->runner() instanceof Infection ? [RunnerContracts::INFECTION_UNPATCHED] : []);
})->with($libraries)->group(...$holds);

it('makes no mutant of a mutator a request prunes in a file, and every other, where its runner is patched to leave them out', function (Library $library): void {
    if ($library->runner() instanceof Pest) {
        Patch::applyIn(Library::vendor());
    }

    $mutators = $library->mutators('adds', 'large', 'unused', 'drains');
    $adds = [...$library->mutators('adds')];
    $pruned = Pruned::of(MutatorNames::of(...$adds), Paths::of(Path::of('src/Money.php')));
    $result = $library->mutate('pruned', MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($mutators)->pruning($pruned)));

    // The Infection library runs unpatched here, and an unpatched runner makes every mutant; the verdict then carries
    // nothing over them.
    expect($result instanceof MutationResult ? Library::records($result->mutants()) : [])->toEqualCanonicalizing(
        $library->runner() instanceof Infection
            ? $library->expected('adds', 'large', 'unused', 'drains')
            : $library->expected('large', 'unused', 'drains'),
    );
})->with($libraries)->group(...$holds);

it('leaves a proof of what it judged, which a last-run change carries while its source reads as it did then, and reaches once it does not', function (Library $library) use ($makeMoney): void {
    $money = $makeMoney();

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
})->with($libraries)->group(...$holds);

it('keys how far a kill went where its runner can tell', function (Library $library) use ($makeMoney): void {
    $money = $makeMoney();

    $result = $money($library);
    $killed = array_values(array_filter(
        $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [],
        static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Killed,
    ));
    $evidence = $result instanceof MutationResult && $killed !== [] ? $result->evidence()->of($killed[0]->id()) : Evidence::none();
    $prefix = $evidence->prefix();

    expect($library->runner() instanceof Infection ? $prefix instanceof NotGiven : $prefix instanceof Prefix)->toBeTrue()
        ->and($prefix instanceof Prefix && is_string($prefix->key()) ? $prefix->key() : '')
        ->toMatch($library->runner() instanceof Infection || $library->runner() instanceof RunnerFake ? '/^$/' : '/^[0-9a-f]{12}$/')
        ->and($result instanceof MutationResult ? count($result->evidence()) : -1)->toBe($library->runner() instanceof Infection ? 0 : 1);
})->with($libraries)->group(...$holds);

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
})->skip(fn(): bool => ! Library::isPhpUnitInstalled() || ! function_exists('pcntl_fork'), 'the runner contracts job installs the PHPUnit library, on a PHP that forks')->group(...$holds);

// PHPUnit fails a run for an option it deprecates only where the project's
// config fails on its own deprecations. The phpunit library's does, so a
// runner that passed one would kill the mutant its tests let survive.
it('lets a mutant survive in a project that fails on PHPUnit\'s own deprecations', function () use ($makeMoney): void {
    $money = $makeMoney();

    $config = (string) file_get_contents(Tree::at(sprintf('%s/phpunit.xml', Library::PHPUNIT_DIRECTORY)));
    $library = Library::phpunit();
    $result = $money($library);

    expect($config)->toContain('failOnPhpunitDeprecation="true"')
        ->and($result instanceof MutationResult ? Library::records($result->mutants()) : [])
        ->toContain(...$library->expected('large'));
})->skip(fn(): bool => ! Library::isPhpUnitInstalled(), 'the PHPUnit runner contracts steps install its library')->group(...$holds);

it('holds a timed-out mutant to a limit of five seconds at least', function (Library $library) use ($makeMoney): void {
    $money = $makeMoney();

    $result = $money($library);
    $limits = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $limit = $mutant->limit();
        $limits[$mutant->status()->value] = $limit instanceof Seconds && $limit->seconds() >= 5.0;
    }

    expect($limits)
        ->toEqualCanonicalizing(['killed' => false, 'survived' => false, 'uncovered' => false, 'timed-out' => true]);
})->with($libraries)->group(...$holds);

it('gives a survivor as a static analyser checks it, against the library\'s file as written', function (Library $library) use ($makeMoney): void {
    $money = $makeMoney();

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
    $change = LibraryChange::Large;

    expect(str_replace($change->added(), $change->removed(), $mutantText))->toBe($originalText);
})->with($libraries)->group(...$holds);

it('reproduces a survivor printing what its runner printed', function (Library $library) use ($makeMoney): void {
    $money = $makeMoney();

    $result = $money($library);
    $printed = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $reproduced = $mutant->status() === MutantStatus::Survived
            ? $library->runner()->reproduce(Reproducible::of($mutant), MutationRequest::of(Paths::none(), WholeSuite::tests())->withholding(Withheld::standard()), Seconds::of(60.0))
            : null;
        $printed = $reproduced instanceof Reproduction ? [...$printed, $reproduced->printed()] : $printed;
    }

    expect($printed)->toHaveCount(1)
        ->and($printed[0] ?? '')->toContain($library->prints());
})->with($libraries)->group(...$holds);

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
})->with($onDisk)->group(...$holds);

// pcov, left unset, collects from src/ alone, so the lines of beside/ would
// read as run by no test.
it('measures a tree outside src/ that the project names as source, naming the test files that judge it', function (Library $library): void {
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $judges = $map instanceof CoverageMap ? $library->runner()->judges(Path::of('beside/Ledger.php'), $map) : $map;

    expect($judges)->toEqual(Paths::of($library->testFile('tests/LedgerSpec.php')));
})->with($onDisk)->group(...$holds);

// A testsuite directory with a wildcard is each directory it matches, as
// PHPUnit runs it, and the runner is told those directories, as the flows
// tell it; read as written, plugins/*/tests is no directory, and its tests
// would judge nothing.
it('names a test file in a testsuite directory the config gives as a glob as one that judges what it covers', function (Library $library): void {
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $judges = $map instanceof CoverageMap ? $library->runner()->judges(Path::of('beside/Stock.php'), $map) : $map;

    expect($judges)->toEqual(Paths::of($library->testFile('plugins/stock/tests/StockSpec.php')));
})->with($onDisk)->group(...$holds);

it('keeps its map beside a pull request\'s ledger, and reads it back first, from that scope, as a full run\'s', function (Library $library): void {
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    $full = $map instanceof CoverageMap ? $map : CoverageMap::empty();
    $bytes = CoverageMapFile::keeping(KeptMap::of($full, MeasuredAt::of(Revision::ref('HEAD'), dirty: false), EntryKeys::none()), MapLimits::standard());
    $store = new ProofStoreFake();
    $store->keep(Scope::branch('main'), Companion::Coverage, Contents::of(CoverageMapFile::encode(CoverageMap::empty(), Unplaced::map())));
    $store->keep(Scope::pullRequest(7), Companion::Coverage, Contents::of(is_string($bytes) ? $bytes : ''));
    $read = StoredCoverage::in($store, Access::of(Scope::pullRequest(7), Scope::branch('main'), Writing::Auto));
    $kept = $read->map();

    expect($map)->toBeInstanceOf(CoverageMap::class)
        ->and($read->from())->toBe(KeptFrom::OwnScope)
        ->and($kept instanceof KeptMap ? RunnerContracts::lines($kept->map()) : $kept)->toBe(RunnerContracts::lines($full));
})->with($onDisk)->group(...$holds);

it('finds its runner\'s own ignore marker', function (Library $library): void {
    $found = $library->runner()->markers(Paths::of(Path::of('marked')));
    $where = $found instanceof Markers
        ? array_map(static fn(Marker $marker): string => $marker->where(), iterator_to_array($found, preserve_keys: false))
        : [];

    expect($where)->toBe($library->markers());
})->with($libraries)->group(...$holds);

it('names the library\'s own files among those that define it', function (Library $library): void {
    $definitions = $library->runner()->definitions();

    foreach ($library->defining() as $file) {
        expect($definitions->has($file))->toBeTrue();
    }
})->with($libraries)->group(...$holds);

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
})->with($libraries)->group(...$holds);

it('keys the PHP it starts, which a setting of the gate\'s own PHP never reaches', function (Library $library): void {
    $identity = $library->runner()->identity(Withheld::standard());
    $before = ini_get('memory_limit');
    ini_set('memory_limit', $before === '-1' ? '1G' : '-1');
    $fresh = $library->outside()->rootedAt($library->package(), $library->packageTests());
    $unreached = $fresh instanceof CannotJudge ? $fresh : $fresh->identity(Withheld::standard());
    ini_set('memory_limit', $before);

    expect($unreached)->toEqual($identity);
})->with($libraries)->group(...$holds);

it('answers as the runner built in a package when rooted in it from the directory around it', function (Library $library): void {
    $rooted = $library->outside()->rootedAt($library->package(), $library->packageTests());
    $runner = $library->runner();

    expect($rooted instanceof CannotJudge ? $rooted : $rooted->identity(Withheld::standard()))
        ->toEqual($runner->identity(Withheld::standard()))
        ->and($rooted instanceof CannotJudge ? $rooted : $rooted->definitions())->toEqual($runner->definitions())
        ->and($rooted instanceof CannotJudge ? $rooted : $rooted->listing(Withheld::standard(), Suites::all()))
        ->toEqual($runner->listing(Withheld::standard(), Suites::all()));
})->with($libraries)->group(...$holds);

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

    expect($judges)->toEqual(Paths::of($library->testFile('plugins/stock/tests/StockSpec.php')));
})->with($onDisk)->group(...$holds);

it('cannot judge a directory that holds no project it can run, rooted from the directory around it', function (Library $library): void {
    expect($library->outside()->rootedAt(Path::of('nowhere'), $library->packageTests()))->toBeInstanceOf(CannotJudge::class);
})->with($libraries)->group(...$holds);

it('has the Codeception library installed wherever the Codeception runner contracts run', function (): void {
    expect(Library::isCodeceptionInstalled())->toBeTrue();
})->skip(fn(): bool => getenv('RUNNER_CONTRACTS') !== 'codeception', 'only the Codeception runner contracts job installs its library')->group(...$holds);

it('has the PhpSpec library installed wherever the PhpSpec runner contracts run', function (): void {
    expect(Library::isPhpSpecInstalled())->toBeTrue();
})->skip(fn(): bool => getenv('RUNNER_CONTRACTS') !== 'phpspec', 'only the PhpSpec runner contracts job installs its library')->group(...$holds);
