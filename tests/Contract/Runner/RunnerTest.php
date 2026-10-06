<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Records;
use NightWorksIO\MutationGate\Core\Analysis\AsWritten;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Remeasured;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
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
use NightWorksIO\MutationGate\Core\Test\Filter;
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
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\MutatePlugin;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;

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
// which its phpunit.xml names as source and tests/LedgerSpec.php tests. The
// runs are real, so each request runs once per library.

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

/**
 * Which of the fixtures' marks a run leaves: whether it loaded MoneySpec,
 * loaded it through a user-space `file://` wrapper, and ran one of its tests.
 * Symfony's Process hands a child the variables in $_ENV, whatever putenv() did.
 *
 * @param  Closure(): void                                    $run
 * @return array{loaded: bool, wrapped: bool, ran: bool}
 */
function contractMarks(Closure $run): array
{
    $marks = ['loaded' => 'CONTRACT_LOADED', 'wrapped' => 'CONTRACT_WRAPPED', 'ran' => 'CONTRACT_RAN'];
    $directory = Scratch::directory();

    foreach ($marks as $mark => $variable) {
        $_ENV[$variable] = sprintf('%s/%s', $directory, $mark);
    }

    $run();
    $left = [];

    foreach ($marks as $mark => $variable) {
        $left[$mark] = is_file(sprintf('%s/%s', $directory, $mark));
        unset($_ENV[$variable]);
    }

    return $left;
}

/** The libraries whose runner reads a map from disk: every one but the fake. */
$onDisk = array_diff_key($libraries, ['the fake' => true]);

/** Money's four mutants, as a library's runner reports them. */
$money = static fn(Library $library): MutationResult|CannotJudge => $library->mutate(
    'money',
    MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds', 'large', 'unused', 'drains'))),
);

/** @return list<string> */
$files = static fn(Mutants $mutants): array => array_values(array_unique(array_map(
    static fn(Mutant $mutant): string => $mutant->location()->file()->value(),
    iterator_to_array($mutants, preserve_keys: false),
)));

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
        ->and($result instanceof MutationResult ? [...$result->warnings()] : ['cannot judge'])->toBe([]);
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

/** @return array<string, list<string>> each covered line of a map, by its file and line, with its tests sorted */
function contractLines(CoverageMap $map): array
{
    $lines = [];

    foreach ($map->lines() as $line) {
        $tests = [...$line];
        sort($tests);
        $lines[sprintf('%s:%d', $line->file()->value(), $line->line())] = $tests;
    }

    ksort($lines);

    return $lines;
}

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
        ->and($merged instanceof CoverageMap ? contractLines($merged) : $merged)
        ->toBe($full instanceof CoverageMap ? contractLines($full) : $full);
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
    $fresh = $library->outside()->rootedAt($library->package());
    $unreached = $fresh instanceof CannotJudge ? $fresh : $fresh->identity(Withheld::standard());
    ini_set('memory_limit', $before);

    expect($unreached)->toEqual($identity);
})->with($libraries);

it('answers as the runner built in a package when rooted in it from the directory around it', function (Library $library): void {
    $rooted = $library->outside()->rootedAt($library->package());
    $runner = $library->runner();

    expect($rooted instanceof CannotJudge ? $rooted : $rooted->identity(Withheld::standard()))
        ->toEqual($runner->identity(Withheld::standard()))
        ->and($rooted instanceof CannotJudge ? $rooted : $rooted->definitions())->toEqual($runner->definitions())
        ->and($rooted instanceof CannotJudge ? $rooted : $rooted->groups(Withheld::standard()))
        ->toEqual($runner->groups(Withheld::standard()));
})->with($libraries);

it('cannot judge a directory that holds no project it can run', function (Library $library): void {
    expect($library->runner()->rootedAt(Path::of('src')))->toBeInstanceOf(CannotJudge::class)
        ->and($library->outside()->rootedAt(Path::of('nowhere')))->toBeInstanceOf(CannotJudge::class);
})->with($libraries);

it('times a run of no test that loads every test file through the mutant\'s wrapper and runs none, with Pest', function (): void {
    $marks = contractMarks(static function (): void {
        Library::pest(Patching::off())->runner()->startUp(Path::of('src/Money.php'), Withheld::standard());
    });

    expect($marks)->toBe(['loaded' => true, 'wrapped' => true, 'ran' => false]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the library');

it('times a run of no test that loads no test file, through the mutant\'s wrapper, where a filtered run loads them all, with Infection', function (): void {
    $library = Library::infection(Seconds::of(10.0));
    $startUp = contractMarks(static function () use ($library): void {
        $library->runner()->startUp(Path::of('src/Money.php'), Withheld::standard());
    });
    $filtered = contractMarks(static function () use ($library): void {
        $library->runner()->coverage(CoverageRun::of(Filter::nothing(), Path::of('.mutation-gate/loaded')));
    });

    expect($startUp)->toBe(['loaded' => false, 'wrapped' => true, 'ran' => false])
        ->and($filtered)->toBe(['loaded' => true, 'wrapped' => false, 'ran' => false]);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('judges a held path by the tests its #[Holds] filter names, with Infection', function (): void {
    $library = Library::infection(Seconds::of(10.0));
    $request = MutationRequest::of(Paths::of(Path::of('src/Held.php')), Filter::matching('HeldSpec'))
        ->narrowedTo(Paths::of(Path::of('src/Held.php')), Narrowing::none()->toMutators($library->mutators('held')));
    $result = $library->mutate('held by filter', $request);

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : $result)
        ->toBe($library->expected('held'));
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('names the test that killed a mutant, as the coverage map names it, with Infection', function () use ($money): void {
    $library = Library::infection(Seconds::of(10.0));
    $result = $money($library);
    $killers = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $killers[$mutant->location()->start()->number()] = array_map(
            static fn(TestId $test): string => $test->value(),
            [...$mutant->killers()],
        );
    }

    expect($killers)->toBe([11 => ['Tests\\MoneySpec::addsTwoAmounts'], 16 => [], 21 => [], 27 => []]);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('judges a mutant on a method\'s signature by the map the planning job handed over as by its own coverage, with Infection', function (): void {
    $library = Library::infection(Seconds::of(10.0));
    $handedOver = Path::of('.mutation-gate/planned');
    $planned = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), $handedOver));
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::INFECTION_DIRECTORY, CoverageMapFile::in($handedOver)->value())),
        CoverageMapFile::encode($planned instanceof CoverageMap ? $planned : CoverageMap::empty(), Unplaced::map()),
    );
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named('PublicVisibility')));
    $own = $library->mutate('signatures', $request);
    $reused = $library->mutate('signatures reused', $request->reusingCoverage(Handed::maps($handedOver, $handedOver)));
    $killed = [];

    foreach ($reused instanceof MutationResult ? $reused->mutants() : Mutants::none() as $mutant) {
        $killed = $mutant->status() === MutantStatus::Killed
            ? [...$killed, $mutant->location()->start()->number()]
            : $killed;
    }

    expect($reused instanceof MutationResult ? Library::records($reused->mutants()) : $reused)
        ->toEqualCanonicalizing($own instanceof MutationResult ? Library::records($own->mutants()) : $own)
        ->and($killed)->toContain(9);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('names the test that killed a mutant, as the coverage map names it, with Pest', function () use ($money): void {
    $library = Library::pest(Patching::off());
    $result = $money($library);
    $killers = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $killers[$mutant->location()->start()->number()] = array_map(
            static fn(TestId $test): string => $test->value(),
            [...$mutant->killers()],
        );
    }

    expect($killers)->toBe([11 => ['P\\Tests\\MoneySpec::__pest_evaluable_it_adds_two_amounts'], 16 => [], 21 => [], 27 => []]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('reports a mutant Infection skips at timeouts.most, allowed it, and judges it when run again at a higher most', function (): void {
    $library = Library::infection(Seconds::of(1.0));
    $request = MutationRequest::of(Paths::of(Path::of('src/Slow.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Slow.php')), Narrowing::none()->toMutators(Mutators::named('Minus')));
    $result = $library->mutate('slow', $request);
    $skipped = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $limits = array_map(
        static fn(Mutant $mutant): float => $mutant->limit() instanceof Seconds ? $mutant->limit()->seconds() : 0.0,
        iterator_to_array($skipped, preserve_keys: false),
    );
    $again = $library->runner()->retry(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $skipped, Seconds::of(3.0));
    $statuses = static fn(Mutants $mutants): array => array_map(
        static fn(Mutant $mutant): string => $mutant->status()->value,
        iterator_to_array($mutants, preserve_keys: false),
    );

    expect($statuses($skipped))->toBe([MutantStatus::Skipped->value])
        ->and($limits)->toBe([1.0])
        ->and($again instanceof Mutants ? $statuses($again) : [])->toBe([MutantStatus::Killed->value]);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('allows a mutant Infection times out its own five seconds and five times its tests\' time, and runs it again only at timeouts.most', function (): void {
    $library = Library::infection(Seconds::of(10.0));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('drains')));
    $result = $library->mutate('drains', $request);
    $timedOut = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $limits = array_map(
        static fn(Mutant $mutant): float => $mutant->limit() instanceof Seconds ? $mutant->limit()->seconds() : 0.0,
        iterator_to_array($timedOut, preserve_keys: false),
    );
    $again = $library->runner()->retry(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $timedOut, Seconds::of(20.0));

    expect(Library::records($timedOut))->toBe($library->expected('drains'))
        ->and($limits[0] ?? 0.0)->toBeGreaterThan(5.0)->toBeLessThan(6.0)
        ->and($again)->toEqual($timedOut);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('allows a mutant patched Pest times out the floor its quick tests fall under, and runs it again within a raised most', function (): void {
    Patch::applyIn(Library::vendor());
    $library = Library::pest(Patching::on(Library::canary()));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('drains')));
    $limits = static fn(Mutants $mutants): array => array_map(
        static fn(Mutant $mutant): float => $mutant->limit() instanceof Seconds ? $mutant->limit()->seconds() : 0.0,
        iterator_to_array($mutants, preserve_keys: false),
    );
    $result = $library->mutate('drains patched', $request);
    $timedOut = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $again = $library->runner()->retry($request, $timedOut, Seconds::of(20.0));

    expect(Library::records($timedOut))->toBe($library->expected('drains'))
        ->and($limits($timedOut))->toBe([10.0])
        ->and($again instanceof Mutants ? Library::records($again) : [])->toBe($library->expected('drains'))
        ->and($again instanceof Mutants ? $limits($again) : [])->toBe([10.0]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

// A floor of a millisecond is less than any covering test takes, so a
// limit the floor capped would stop each mutant's run before its tests end.
it('judges a mutant whose covering tests take longer than the floor, rather than stopping it at the floor, with PHPUnit', function (): void {
    $library = Library::phpunitWithin(LimitBounds::between(Seconds::of(0.001), Seconds::of(300.0)), 'phpunit under a floor of a millisecond');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds')));
    $result = $library->mutate('adds', $request);

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : [])->toBe($library->expected('adds'));
})->skip(! Library::isPhpUnitInstalled(), 'the PHPUnit runner contracts steps install its library');

it('judges a mutant whose covering tests take longer than the floor, rather than stopping it at the floor, with patched Pest', function (): void {
    Patch::applyIn(Library::vendor());
    $library = Library::pestWithin(
        Patching::on(Library::canary()),
        LimitBounds::between(Seconds::of(0.001), Seconds::of(300.0)),
        'pest patched under a floor of a millisecond',
    );
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds')));
    $result = $library->mutate('adds', $request);

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : [])->toBe($library->expected('adds'));
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('judges a mutant whose covering class takes longer than timeouts.seconds, skipping only at timeouts.most, with Infection', function (): void {
    $library = Library::infection(Seconds::of(3.0));
    $request = MutationRequest::of(Paths::of(Path::of('src/Slow.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Slow.php')), Narrowing::none()->toMutators(Mutators::named('Minus')));
    $result = $library->mutate('slow', $request);

    expect($result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): string => $mutant->status()->value,
        iterator_to_array($result->mutants(), preserve_keys: false),
    ) : [])->toBe([MutantStatus::Killed->value]);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('leaves a mutant unjudged, naming the test, when Pest\'s filter cannot select a covering test', function (): void {
    $library = Library::pest(Patching::off());
    $request = MutationRequest::of(Paths::of(Path::of('src/Legacy.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Legacy.php')), Narrowing::none()->toMutators($library->mutators('unused')));
    $result = $library->mutate('legacy', $request);
    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];

    $statuses = array_map(static fn(Mutant $mutant): string => $mutant->status()->value, $mutants);
    $reasons = array_map(static fn(Mutant $mutant): Reason|Rejection|Unreported => $mutant->reason(), $mutants);

    expect($statuses)->toBe([MutantStatus::Unjudged->value])
        ->and($reasons)->toEqual([Reason::that(
            "Pest's --filter cannot select LegacySpec::decrements, so Pest cannot run it against this mutant.",
        )]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('opens a patched shard on the canary group and reads the map the planning job handed over', function (): void {
    $patched = Patch::applyIn(Library::vendor());
    $library = Library::pest(Patching::on(Library::canary()));
    $planned = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/planned'));
    $map = $library->runner()->coverage($planned);
    $handedOver = CoverageMapFile::in(Path::of('.mutation-gate/planned'))->value();
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, $handedOver)),
        CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::empty(), Unplaced::map()),
    );
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds', 'large')))
        ->reusingCoverage(Handed::maps(Path::of('.mutation-gate/planned'), Path::of('.mutation-gate/planned')));
    $result = $library->mutate('shared', $request);

    expect($patched)->toBeString()
        ->and(Patch::isAppliedIn(Library::vendor()))->toBeTrue()
        ->and($map)->toBeInstanceOf(CoverageMap::class)
        ->and($result instanceof MutationResult ? Library::records($result->mutants()) : $result)
        ->toEqualCanonicalizing($library->expected('adds', 'large'));
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

// A test that needs a test file beside its own works where that file is
// loaded first: here it sorts first, holds a test of its own, which Pest's
// --parallel needs to load it at all, and the suite is covered in one
// process. A mutant only such a test covers, which no test kills, survives.
// Where the test needs the file for a name it spells, a helper function, a
// constant, a base test case or a trait of tests, its own run loads the file
// with the test's. Where the file acts on what other files find, a hook or a
// trait `->in()` registers, state it sets, at its top, in a describe body or
// in a dataset closure, or a constant it defines by call, every narrowed run
// loads it, even where the test falls back from what it finds missing. Where
// the test calls a helper by a name built at run time, the run narrowed
// without it cannot vouch for the kill, and the mutant is run again with
// every test file.
it('narrows a mutant\'s own run over a test that needs another test file, loaded first, and kills nothing by what it left out', function (bool $together, string ...$files): void {
    $into = Tree::at(sprintf('%s/tests/Reach', Library::DIRECTORY));
    $source = Tree::at(sprintf('%s/src/Reach.php', Library::DIRECTORY));
    mkdir($into);
    copy(Tree::at('tests/Contract/Runner/reach/src/Reach.php'), $source);

    foreach ($files as $file) {
        copy(Tree::at(sprintf('tests/Contract/Runner/reach/tests/Reach/%s', $file)), sprintf('%s/%s', $into, $file));
    }

    Patch::applyIn(Library::vendor());
    $runner = Library::pest(Patching::on(Library::canary()))->runner();
    $expected = $together ? Library::acting(...array_map(static fn(string $file): string => sprintf('tests/Reach/%s', $file), $files)) : [];

    try {
        $map = $runner->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/reach')));
        file_put_contents(
            Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in(Path::of('.mutation-gate/reach'))->value())),
            CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::empty(), Unplaced::map()),
        );
        $result = $runner->mutate(MutationRequest::of(Paths::of(Path::of('src/Reach.php')), WholeSuite::tests())
            ->narrowedTo(Paths::of(Path::of('src/Reach.php')), Narrowing::none()->toMutators(Mutators::named(PlusToMinus::class)))
            ->reusingCoverage(Handed::maps(Path::of('.mutation-gate/reach'), Path::of('.mutation-gate/reach'))));
    } finally {
        array_map(static fn(string $file): bool => unlink(sprintf('%s/%s', $into, $file)), $files);
        rmdir($into);
        unlink($source);
    }

    // The records of the last run: the narrowed run, or the run again with every test file.
    $records = Records::in(Tree::at(sprintf('%s/.mutation-gate/pest/results.jsonl', Library::DIRECTORY)));
    $loaded = array_map(
        static fn(PlannedMutant $mutant): array => $records instanceof Records ? $records->runOf($mutant)->narrowedTo() : [],
        $records instanceof Records ? $records->planned() : [],
    );

    expect($map instanceof CannotJudge ? $map->why() : $map)->toBeInstanceOf(CoverageMap::class)
        ->and($result instanceof MutationResult ? array_map(
            static fn(Mutant $mutant): MutantStatus => $mutant->status(),
            [...$result->mutants()],
        ) : $result)->toBe([MutantStatus::Survived])
        ->and($loaded)->toBe([$expected]);
})->with([
    'a helper function' => [true, 'ReachAHelpersSpec.php', 'ReachZHelpedSpec.php'],
    'a constant' => [true, 'ReachAConstantsSpec.php', 'ReachZConstantSpec.php'],
    'a defined constant' => [true, 'ReachADefinesSpec.php', 'ReachZDefinedSpec.php'],
    'a base test case' => [true, 'ReachABaseSpec.php', 'ReachZInheritedSpec.php'],
    'a trait of tests' => [true, 'ReachAAssertsSpec.php', 'ReachZTraitedSpec.php'],
    'a helper called by a name built at run time' => [false, 'ReachADynamicSpec.php', 'ReachZDynamicSpec.php'],
    'a hook pest()->in() registers' => [true, 'ReachAHooksSpec.php', 'ReachZHookedSpec.php'],
    'a trait uses()->in() adds' => [true, 'ReachAUsesSpec.php', 'ReachZUsingSpec.php'],
    'a variable set in $_ENV' => [true, 'ReachAEnvSpec.php', 'ReachZEnvSpec.php'],
    'a variable put in the environment' => [true, 'ReachAPutenvSpec.php', 'ReachZPutenvSpec.php'],
    'a global set in $GLOBALS' => [true, 'ReachAGlobalsSpec.php', 'ReachZGlobalsSpec.php'],
    'a value a hook sets, which the test falls back from' => [true, 'ReachADefaultSpec.php', 'ReachZDefaultedSpec.php'],
    'a global a describe body sets, which the test falls back from' => [true, 'ReachADescribeSpec.php', 'ReachZDescribedSpec.php'],
    'a global a dataset closure sets, which the test falls back from' => [true, 'ReachADatasetSpec.php', 'ReachZDatasetSpec.php'],
])->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('hands each mutant\'s own run the test files its covering tests need as paths, and no other', function (): void {
    Patch::applyIn(Library::vendor());
    $library = Library::pest(Patching::on(Library::canary()));
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/planned')));
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in(Path::of('.mutation-gate/planned'))->value())),
        CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::empty(), Unplaced::map()),
    );
    $argv = sprintf('%s/argv', Scratch::directory());
    $_ENV['CONTRACT_ARGV'] = $argv;

    try {
        $result = $library->runner()->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
            ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('large')))
            ->reusingCoverage(Handed::maps(Path::of('.mutation-gate/planned'), Path::of('.mutation-gate/planned'))));
    } finally {
        unset($_ENV['CONTRACT_ARGV']);
    }

    $paths = array_map(
        static fn(string $line): array => array_values(array_filter(
            explode(' ', $line),
            static fn(string $argument): bool => str_ends_with($argument, '.php') && str_contains($argument, '/tests/'),
        )),
        array_values(array_filter(
            explode("\n", (string) file_get_contents($argv)),
            static fn(string $line): bool => $line !== '',
        )),
    );

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : $result)
        ->toBe($library->expected('large'))
        ->and($paths)->toBe([Library::acting('tests/MoneySpec.php')]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('runs again, patched, only the mutants the file it hands over names, on the map the invocation read', function (): void {
    Patch::applyIn(Library::vendor());
    $library = Library::pest(Patching::on(Library::canary()));
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/planned')));
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in(Path::of('.mutation-gate/planned'))->value())),
        CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::empty(), Unplaced::map()),
    );
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds', 'large')))
        ->reusingCoverage(Handed::maps(Path::of('.mutation-gate/planned'), Path::of('.mutation-gate/planned')));
    $result = $library->mutate('shared', $request);
    $survivors = Mutants::none();
    $killed = Mutants::none();

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $survivors = $mutant->status() === MutantStatus::Survived ? $survivors->with($mutant) : $survivors;
        $killed = $mutant->status() === MutantStatus::Killed ? $killed->with($mutant) : $killed;
    }

    $again = $library->runner()->retry($request, $survivors, Seconds::of(60.0));
    // The run again recorded what it made: the survivor, and not the mutant the first run killed.
    $recorded = (string) file_get_contents(Tree::at(sprintf('%s/.mutation-gate/pest/results.jsonl', Library::DIRECTORY)));
    $listed = (string) file_get_contents(Tree::at(sprintf('%s/.mutation-gate/pest/results.jsonl.only', Library::DIRECTORY)));

    expect([count($survivors), count($killed)])->toBe([1, 1])
        ->and($listed)->toBe(implode("\n", array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), [...$survivors])))
        ->and($again instanceof Mutants ? Library::records($again) : $again)->toBe(Library::records($survivors))
        ->and(array_map(static fn(Mutant $mutant): bool => str_contains($recorded, $mutant->nativeId()), [...$survivors, ...$killed]))
        ->toBe([true, false]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

// composer.json allows pest-plugin-mutate 5.0.2 alone. 5.0.1 reads each line's
// covering tests from php-code-coverage 14.3's map as hit counts rather than test
// ids, and passes an int to preg_match, a TypeError under every Pest ^5.1.
it('holds the library to the pest-plugin-mutate the package allows', function (): void {
    $conflict = static function (string $manifest): mixed {
        $decoded = json_decode((string) file_get_contents(Tree::at($manifest)), associative: true);

        return is_array($decoded) && array_key_exists('conflict', $decoded) ? $decoded['conflict'] : [];
    };

    expect($conflict(sprintf('%s/composer.json', Library::DIRECTORY)))->toBe($conflict('composer.json'))
        ->and($conflict('composer.json'))
        ->toBe([
            'pestphp/pest' => '<5.1',
            'pestphp/pest-plugin-mutate' => '<5.0.2 || >5.0.2',
            'phpunit/phpunit' => '<12.5.8 || >=12.5.21 <12.5.22 || >=13.1.5 <13.1.6',
            'symfony/yaml' => '<7.4.12 || >=8.0 <8.0.12',
        ]);
})->skip(getenv('RUNNER_CANARY') === '1', 'the runner canary frees the library from the pin');

// The lowest resolution of the Infection library installs the lowest PHPUnit the
// package allows, 12.5.8, not the lowest its own constraint would.
it('holds the Infection library to the PHPUnit the package allows', function (): void {
    $phpunit = static function (string $manifest): mixed {
        $decoded = json_decode((string) file_get_contents(Tree::at($manifest)), associative: true);
        $conflict = is_array($decoded) && array_key_exists('conflict', $decoded) ? $decoded['conflict'] : [];

        return is_array($conflict) && array_key_exists('phpunit/phpunit', $conflict) ? $conflict['phpunit/phpunit'] : null;
    };

    expect($phpunit(sprintf('%s/composer.json', Library::INFECTION_DIRECTORY)))->toBe($phpunit('composer.json'))
        ->and($phpunit(sprintf('%s/phpunit-12/composer.json', Library::INFECTION_DIRECTORY)))->toBe($phpunit('composer.json'))
        ->and($phpunit('composer.json'))->toStartWith('<12.5.8 ');
});

// The Infection library installs with PHPUnit 13 by its own manifest and with
// PHPUnit 12 by the one in phpunit-12/, into the same vendor directory: the
// same Infection, the same code and tests, and only PHPUnit's major apart.
it('installs the Infection library with PHPUnit 12 as with PHPUnit 13', function (): void {
    $read = static function (string $manifest): array {
        $decoded = json_decode((string) file_get_contents(Tree::at(sprintf('%s/%s', Library::INFECTION_DIRECTORY, $manifest))), associative: true);

        return is_array($decoded) ? $decoded : [];
    };
    $thirteen = $read('composer.json');
    $twelve = $read('phpunit-12/composer.json');
    $field = static fn(array $manifest, string $key): array => is_array($manifest[$key] ?? null) ? $manifest[$key] : [];
    $unprefixed = static fn(array $paths): string => str_replace('../', '', (string) json_encode($paths, JSON_UNESCAPED_SLASHES));

    expect([...$field($twelve, 'require'), 'phpunit/phpunit' => '^13.0'])->toBe($field($thirteen, 'require'))
        ->and($field($twelve, 'require')['phpunit/phpunit'] ?? '')->toBe('^12.0')
        ->and($unprefixed($field($twelve, 'autoload')))->toBe($unprefixed($field($thirteen, 'autoload')))
        ->and($unprefixed($field($twelve, 'autoload-dev')))->toBe($unprefixed($field($thirteen, 'autoload-dev')))
        ->and($field($twelve, 'config')['vendor-dir'] ?? '')->toBe('../vendor');
})->skip(getenv('RUNNER_CANARY') === '1', 'the runner canary frees the library from the pin');

// The unit suite patches pest-plugin-mutate's files as the allowed version ships
// them, from tests/Fixtures. Patching the installed plugin, which this
// repository's Composer hook has patched already where it ran, ends in the same
// files, so the fixture is the installed version's source.
it('patches the installed pest-plugin-mutate into the files the pristine fixture patches into', function (): void {
    $installed = MutatePlugin::installed()->vendor();
    $pristine = MutatePlugin::pristine()->vendor();
    $read = static fn(string $vendor): array => array_map(
        static fn(string $file): string => (string) file_get_contents(
            sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $vendor, $file),
        ),
        MutatePlugin::FILES,
    );

    expect(Patch::applyIn($installed))->toBeString()
        ->and(Patch::applyIn($pristine))->toBe('pest:patch patched 3 of the 3 files it changes in pest-plugin-mutate.')
        ->and($read($installed))->toBe($read($pristine));
});

// The adapter reads the maps the library's Pest writes with the
// php-code-coverage it is installed beside. In a user's project that is one
// vendor directory; here it is two, and the job installs the library at this
// package's php-code-coverage so both read one serialization format.
it('reads coverage in the serialization format the library writes it in', function (): void {
    $serializer = sprintf('%s/phpunit/php-code-coverage/src/Serialization/Serializer.php', Library::vendor());

    expect((string) file_get_contents($serializer))
        ->toContain(sprintf('SERIALIZATION_FORMAT = %d;', Serializer::SERIALIZATION_FORMAT));
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('has the library installed wherever the runner contracts run', function (): void {
    expect(Library::isInstalled())->toBeTrue();
})->skip(getenv('RUNNER_CONTRACTS') !== 'pest', 'only the runner contracts job installs the fixture library');

it('has the Infection library installed wherever the Infection runner contracts run', function (): void {
    expect(Library::isInfectionInstalled())->toBeTrue();
})->skip(getenv('RUNNER_CONTRACTS') !== 'infection', 'only the Infection runner contracts job installs its library');
