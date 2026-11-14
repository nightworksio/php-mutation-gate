<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\PackageDirectory;
use NightWorksIO\MutationGate\Adapter\Infection\Patch as InfectionPatch;
use NightWorksIO\MutationGate\Adapter\Infection\Release;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Records;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayRecord;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSettings;
use NightWorksIO\MutationGate\Cli\Flow\MergedUnits;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Config\PestWorkers;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\LineRecording;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package as TreePackage;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\InfectionSource;
use NightWorksIO\MutationGate\Tests\Support\MutatePlugin;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;

$holds = [
    'holds:src/Adapter/Pest',
    'holds:src/Core/Composer/VendorPatch.php',
];

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

it('patches the Infection library\'s release and its include-interceptor, whichever its leg installed, into the files the pristine fixtures of those releases patch into', function (): void {
    $release = InfectionSource::installedRelease();
    $interceptor = InfectionSource::installedRelease(Package::IncludeInterceptor);
    $installed = InfectionSource::installed()->vendor();
    $pristine = InfectionSource::pristine($release, $interceptor)->vendor();
    $read = static fn(string $vendor): array => [
        ...array_map(
            static fn(string $file): string => (string) file_get_contents(sprintf('%s/infection/infection/src/%s', $vendor, $file)),
            InfectionSource::FILES,
        ),
        (string) file_get_contents(sprintf('%s/infection/include-interceptor/src/%s', $vendor, InfectionSource::INTERCEPTOR)),
    ];

    expect(Release::tryFrom($release))->toBeInstanceOf(Release::class)
        ->and(array_key_exists($interceptor, InfectionSource::INTERCEPTOR_SHIPS_AS))->toBeTrue()
        ->and(InfectionPatch::applyIn($installed, new PackageDirectory()))->toBe('infection:patch patched 4 of the 4 files it changes in infection. infection:patch patched 1 of the 1 files it changes in include-interceptor.')
        ->and(InfectionPatch::applyIn($pristine, new PackageDirectory()))->toBe('infection:patch patched 4 of the 4 files it changes in infection. infection:patch patched 1 of the 1 files it changes in include-interceptor.')
        ->and($read($installed))->toBe($read($pristine));
})->skip(fn(): bool => ! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library')->group(...$holds);

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
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('opens a patched shard on the canary group and reads the map the planning job handed over', function (): void {
    $patched = Patch::applyIn(Library::vendor(), new PackageDirectory());
    $library = Library::pest(Patching::on(Library::canary()));
    $planned = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/planned'));
    $map = $library->runner()->coverage($planned);
    $handedOver = CoverageMapFile::in(Path::of('.mutation-gate/planned'))->value();
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, $handedOver)),
        CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::of(), Unplaced::map()),
    );
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds', 'large')))
        ->reusingCoverage(Handed::maps(Path::of('.mutation-gate/planned'), Path::of('.mutation-gate/planned')));
    $result = $library->mutate('shared', $request);

    expect($patched)->toBeString()
        ->and(Patch::isAppliedIn(Library::vendor(), new PackageDirectory()))->toBeTrue()
        ->and($map)->toBeInstanceOf(CoverageMap::class)
        ->and($result instanceof MutationResult ? Library::records($result->mutants()) : $result)
        ->toEqualCanonicalizing($library->expected('adds', 'large'));
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

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

    Library::patched();
    $runner = Library::pest(Patching::on(Library::canary()))->runner();
    $expected = $together ? Library::acting(...array_map(static fn(string $file): string => sprintf('tests/Reach/%s', $file), $files)) : [];

    try {
        $map = $runner->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/reach')));
        file_put_contents(
            Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in(Path::of('.mutation-gate/reach'))->value())),
            CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::of(), Unplaced::map()),
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
])->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('hands each mutant\'s own run the test files its covering tests need as paths, and no other', function (): void {
    Library::patched();
    $library = Library::pest(Patching::on(Library::canary()));
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/planned')));
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in(Path::of('.mutation-gate/planned'))->value())),
        CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::of(), Unplaced::map()),
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
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('vouches for a narrowed kill by its own run, replayed unmutated in the order it took, stopped after its last killer', function (): void {
    Library::patched();
    $library = Library::pest(Patching::on(Library::canary()));
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/replayed')));
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in(Path::of('.mutation-gate/replayed'))->value())),
        CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::of(), Unplaced::map()),
    );
    $result = $library->runner()->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds')))
        ->reusingCoverage(Handed::maps(Path::of('.mutation-gate/replayed'), Path::of('.mutation-gate/replayed'))));
    $killed = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];
    $prefix = $result instanceof MutationResult && $killed !== [] ? $result->evidence()->of($killed[0]->id())->prefix() : NotGiven::value();
    $replays = glob(Tree::at(sprintf('%s/.mutation-gate/pest/results.jsonl.replay-????????????????', Library::DIRECTORY)));
    $replay = ReplayRecord::in(is_array($replays) && $replays !== [] ? $replays[0] : '', '');

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : $result)
        ->toBe($library->expected('adds'))
        ->and($replays)->toHaveCount(1)
        ->and($replay->failed())->toBeFalse()
        ->and($replay->ran())->toBe($prefix instanceof Prefix ? $prefix->position() : -1)
        ->and($replay->order())->toBeString();
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('runs again, patched, only the mutants the file it hands over names, on the map the invocation read', function (): void {
    Library::patched();
    $library = Library::pest(Patching::on(Library::canary()));
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/planned')));
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in(Path::of('.mutation-gate/planned'))->value())),
        CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::of(), Unplaced::map()),
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
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

// composer.json allows pest-plugin-mutate from 5.0.2. 5.0.1 reads each line's
// covering tests from php-code-coverage 14.3's map as hit counts rather than test
// ids, and passes an int to preg_match, a TypeError under every Pest ^5.1.
it('reports the same four mutants from warm workers, and warns of nothing, with Pest patched', function (): void {
    Library::patched();
    $library = Library::installed('pest patched');
    $result = $library->mutate(
        'money warm',
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
            ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds', 'large', 'unused', 'drains')))
            ->across(Pool::of(ProcessCount::of(2), Workers::Fork)),
    );

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : [])
        ->toEqualCanonicalizing($library->expected('adds', 'large', 'unused', 'drains'))
        ->and($result instanceof MutationResult ? [...$result->warnings()] : ['cannot judge'])->toBe([]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'warm workers need a PHP that forks')->group(...$holds);

it('reports the same four mutants from warm workers that cache through opcache as from workers that do not', function (bool $caches): void {
    Library::patched();
    $library = Library::pest(Patching::on(Library::canary())->working(PestWorkers::of(workerOpcache: $caches)));
    $result = $library->mutate(
        sprintf('money warm %s', $caches ? 'cached' : 'uncached'),
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
            ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds', 'large', 'unused', 'drains')))
            ->across(Pool::of(ProcessCount::of(2), Workers::Fork)),
    );

    expect($result instanceof MutationResult ? Library::records($result->mutants()) : [])
        ->toEqualCanonicalizing($library->expected('adds', 'large', 'unused', 'drains'))
        ->and($result instanceof MutationResult ? [...$result->warnings()] : ['cannot judge'])->toBe([]);
})->with([
    'caching through opcache' => [true],
    'with opcache off' => [false],
])->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and warm workers need a PHP that forks',
)->group(...$holds);

it('reports the same records for every mutant Pest makes of the library\'s files with schemata as with a copy for each', function (): void {
    Library::patched();
    $records = static function (bool $compiles): array {
        $result = Library::pest(Patching::on(Library::canary())->working(PestWorkers::of(schemata: $compiles)))->mutate(
            sprintf('every mutant %s', $compiles ? 'schemata' : 'copies'),
            MutationRequest::of(Paths::of(Path::of('src/Money.php'), Path::of('src/Shapes.php')), WholeSuite::tests())
                ->across(Pool::of(ProcessCount::of(2), Workers::Fork)),
        );

        return $result instanceof MutationResult ? Library::records($result->mutants()) : [$result];
    };
    $copies = $records(compiles: false);

    expect($records(compiles: true))->toEqualCanonicalizing($copies)
        ->and(count($copies))->toBeGreaterThan(4);
})->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and warm workers need a PHP that forks',
)->group(...$holds);

it('reports the same records for every mutant Pest makes of the library\'s files from split streams as from each mutant\'s own run, the streams judging most', function (bool $changeCheck, bool $killersFirst, bool $killerStreams): void {
    Library::patched();
    $run = static fn(StreamSettings $streams): MutationResult|CannotJudge => Library::pest(Patching::on(Library::canary())->splitting($streams))->mutate(
        sprintf('every mutant, split %s', json_encode($streams->variables())),
        MutationRequest::of(Paths::of(Path::of('src/Money.php'), Path::of('src/Shapes.php')), WholeSuite::tests())
            ->across(Pool::of(ProcessCount::of(2), Workers::Fork)),
    );
    $own = $run(StreamSettings::off());
    $split = $run(StreamSettings::of(enabled: true, children: 1, changeCheck: $changeCheck, killersFirst: $killersFirst)
        ->streamingKillers($killerStreams));
    $streamed = $split instanceof MutationResult
        ? array_filter(
            iterator_to_array($split->mutants(), preserve_keys: false),
            static fn(Mutant $mutant): bool => $split->evidence()->of($mutant->id())->isStreamed(),
        )
        : [];

    expect($split instanceof MutationResult ? Library::records($split->mutants()) : [$split])
        ->toEqualCanonicalizing($own instanceof MutationResult ? Library::records($own->mutants()) : [$own])
        ->and($own instanceof MutationResult ? count($own->mutants()) : 0)->toBeGreaterThan(4)
        ->and(count($streamed) * 2)->toBeGreaterThan($own instanceof MutationResult ? count($own->mutants()) : 0);
})->with([
    'checking changes, killers first' => [true, true, false],
    'forking unchecked, every test alike' => [false, false, false],
    'killers first, a first pass of killers alone' => [true, true, true],
])->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);

// Every part reads one coverage map, handed over as a plan hands its shards
// theirs, so each cuts the test files alike.
it('reports the same records for every mutant Pest makes of the library\'s files from split streams across parts of the test files, merged as the verdict merges them, as from one part', function (int $parts): void {
    Library::patched();
    $files = Paths::of(Path::of('src/Money.php'), Path::of('src/Shapes.php'));
    $library = Library::pest(Patching::on(Library::canary())->splitting(
        StreamSettings::of(enabled: true, children: 1, changeCheck: true, killersFirst: true),
    ));
    $handed = Path::of('.mutation-gate/parted');
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), $handed));
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in($handed)->value())),
        CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::of(), Unplaced::map()),
    );
    $run = static fn(TestPart $part): MutationResult|CannotJudge => $library->mutate(
        sprintf('every mutant, split, part %d of %d', $part->number(), $part->parts()),
        MutationRequest::of($files, WholeSuite::tests())
            ->narrowedTo($files, Narrowing::none()->inTestPart($part))
            ->across(Pool::of(ProcessCount::of(2), Workers::Fork))
            ->reusingCoverage(Handed::maps($handed, $handed)),
    );
    $whole = $run(TestPart::whole());
    $shards = [];
    $read = [];

    $unrun = [];
    $unreached = 0;

    foreach (range(1, $parts) as $number) {
        $shard = Shard::of(
            ShardId::of($number),
            TreePackage::at(Path::root()),
            Units::of(Unit::file(Path::of('src/Money.php')), Unit::file(Path::of('src/Shapes.php'))),
            Seconds::of(1.0),
            sprintf('src, part %d of %d', $number, $parts),
        )->inPart(TestPart::of($number, $parts));
        $mutated = $run($shard->testPart());
        $shards[] = $shard;

        if (! $mutated instanceof MutationResult) {
            $unrun[] = $mutated->why();

            continue;
        }

        $measured = Measurement::of(Seconds::of(1.0), 'pest', Instant::at(new DateTimeImmutable()));
        $read[] = [$shard, ShardResult::of(Digest::sha256Of('plan'), $shard->id(), Keys::none(), $mutated, $measured), $mutated];
        $unreached += $mutated->mutants()->counting(MutantStatus::NotReached);
    }

    $merged = MergedUnits::of(Plan::of(Revision::ref('HEAD'), Digest::sha256Of('base'), Keys::none(), Shards::of(...$shards)), $read);
    $records = $merged instanceof CannotJudge ? [$merged->why()] : Library::records(Mutants::of(...array_merge(...array_map(
        static fn(UnitResult $unit): array => [...$unit->mutants()],
        [...$merged],
    ))));

    expect($unrun)->toBe([])
        ->and($records)->toEqualCanonicalizing($whole instanceof MutationResult ? Library::records($whole->mutants()) : [$whole])
        ->and($whole instanceof MutationResult ? $whole->mutants()->counting(MutantStatus::NotReached) : 1)->toBe(0)
        ->and($unreached)->toBeGreaterThan(0);
})->with([
    'two parts' => [2],
    'three parts' => [3],
])->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);

it('reports the same records for every mutant Pest makes of the library\'s files from split streams that record each test\'s lines as from each mutant\'s own run, and leaves the lines recorded', function (bool $standsIn): void {
    Library::patched();
    $files = Paths::of(Path::of('src/Money.php'), Path::of('src/Shapes.php'));
    $streams = StreamSettings::of(enabled: true, children: 1, changeCheck: true, killersFirst: false);
    $library = Library::pest(Patching::on(Library::canary())->splitting($streams));
    $handed = Path::of('.mutation-gate/recorded-map');
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), $handed));
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in($handed)->value())),
        CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::of(), Unplaced::map()),
    );
    $into = Scratch::directory();
    $recorded = $library->mutate(
        sprintf('every mutant, split, its streams recording %s', $standsIn ? 'standing in' : 'for the map'),
        MutationRequest::of($files, WholeSuite::tests())
            ->narrowedTo($files, Narrowing::none()->recordingInto(
                $standsIn ? LineRecording::standingIn($into) : LineRecording::forTheMap($into),
            ))
            ->across(Pool::of(ProcessCount::of(2), Workers::Fork))
            ->reusingCoverage(Handed::maps($handed, $handed)),
    );
    $own = Library::pest(Patching::on(Library::canary()))->mutate(
        'every mutant, its own run',
        MutationRequest::of($files, WholeSuite::tests())->across(Pool::of(ProcessCount::of(2), Workers::Fork)),
    );

    $lines = glob(sprintf('%s/recorded-*.jsonl', $into));
    $text = implode('', array_map(file_get_contents(...), is_array($lines) ? $lines : []));

    expect($recorded instanceof MutationResult ? Library::records($recorded->mutants()) : [$recorded])
        ->toEqualCanonicalizing($own instanceof MutationResult ? Library::records($own->mutants()) : [$own])
        ->and($lines)->not->toBe([])
        ->and($text)->toContain('/src/Money.php":[')
        ->and($text)->not->toContain(sprintf('%s.served', Path::PHP));
})->with([
    'for a quick round\'s map' => [false],
    'standing in for the suite\'s coverage report' => [true],
])->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);

it('reports the same records for every mutant Pest makes of the library\'s files from mutants\' workers that boot the project as from workers that do not', function (): void {
    Library::patched();
    $records = static function (bool $boots): array {
        $result = Library::pest(Patching::on(Library::canary())->working(PestWorkers::of(bootMutants: $boots)))->mutate(
            sprintf('every mutant %s', $boots ? 'booted' : 'unbooted'),
            MutationRequest::of(Paths::of(Path::of('src/Money.php'), Path::of('src/Shapes.php')), WholeSuite::tests())
                ->across(Pool::of(ProcessCount::of(2), Workers::Fork)),
        );

        return $result instanceof MutationResult ? [Library::records($result->mutants()), [...$result->warnings()]] : [[$result], ['cannot judge']];
    };
    [$unbooted] = $records(boots: false);
    [$booted, $warned] = $records(boots: true);

    expect($booted)->toEqualCanonicalizing($unbooted)
        ->and(count($unbooted))->toBeGreaterThan(4)
        ->and($warned)->toBe([]);
})->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and warm workers need a PHP that forks',
)->group(...$holds);

it('holds the library to the pest-plugin-mutate the package allows', function (): void {
    $conflict = static function (string $manifest): mixed {
        $decoded = json_decode((string) file_get_contents(Tree::at($manifest)), associative: true);

        return is_array($decoded) && array_key_exists('conflict', $decoded) ? $decoded['conflict'] : [];
    };

    $package = $conflict('composer.json');

    expect($conflict(sprintf('%s/composer.json', Library::DIRECTORY)))
        ->toBe(is_array($package) ? array_diff_key($package, [Package::Testo->value => true]) : $package)
        ->and($package)
        ->toBe([
            'pestphp/pest' => '<5.1',
            'pestphp/pest-plugin-mutate' => '<5.0.2 || >=6.0',
            'phpunit/phpunit' => '<12.5.8 || >=12.5.21 <12.5.22 || >=13.1.5 <13.1.6',
            'symfony/yaml' => '<7.4.23 || >=8.0 <8.1.11',
            'testo/testo' => '<0.10.56 || >=0.11',
        ]);
})->skip(fn(): bool => getenv('RUNNER_CANARY') === '1', 'the runner canary frees the library from the pin')->group(...$holds);

// The Testo library requires the minor range the package's own development
// requires, which the package's conflict holds a user's project to (ADR-0047).
it('holds the Testo library to the Testo range the package allows', function (): void {
    $field = static function (string $manifest, string $key): mixed {
        $decoded = json_decode((string) file_get_contents(Tree::at($manifest)), associative: true);
        $packages = is_array($decoded) && is_array($decoded[$key] ?? null) ? $decoded[$key] : [];

        return $packages[Package::Testo->value] ?? null;
    };

    expect($field(sprintf('%s/../composer.json', Library::TESTO_DIRECTORY), 'require'))->toBe($field('composer.json', 'require-dev'))
        ->and($field('composer.json', 'require-dev'))->toBe('~0.10.56')
        ->and($field('composer.json', 'conflict'))->toBe('<0.10.56 || >=0.11');
})->skip(fn(): bool => getenv('RUNNER_CANARY') === '1', 'the runner canary frees the library from the pin')->group(...$holds);

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
})->group(...$holds);

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
})->skip(fn(): bool => getenv('RUNNER_CANARY') === '1', 'the runner canary frees the library from the pin')->group(...$holds);

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

    expect(Patch::applyIn($installed, new PackageDirectory()))->toBeString()
        ->and(Patch::applyIn($pristine, new PackageDirectory()))->toBe('pest:patch patched 6 of the 6 files it changes in pest-plugin-mutate. pest:patch patched 3 of the 3 files it changes in pest.')
        ->and($read($installed))->toBe($read($pristine));
})->group(...$holds);

// The adapter reads the maps the library's Pest writes with the
// php-code-coverage it is installed beside. In a user's project that is one
// vendor directory; here it is two, and the job installs the library at this
// package's php-code-coverage so both read one serialization format.
it('reads coverage in the serialization format the library writes it in', function (): void {
    $serializer = sprintf('%s/phpunit/php-code-coverage/src/Serialization/Serializer.php', Library::vendor());

    expect((string) file_get_contents($serializer))
        ->toContain(sprintf('SERIALIZATION_FORMAT = %d;', Serializer::SERIALIZATION_FORMAT));
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library')->group(...$holds);

it('has the library installed wherever the runner contracts run', function (): void {
    expect(Library::isInstalled())->toBeTrue();
})->skip(fn(): bool => getenv('RUNNER_CONTRACTS') !== 'pest', 'only the runner contracts job installs the fixture library')->group(...$holds);

it('has the Infection library installed wherever the Infection runner contracts run', function (): void {
    expect(Library::isInfectionInstalled())->toBeTrue();
})->skip(fn(): bool => getenv('RUNNER_CONTRACTS') !== 'infection', 'only the Infection runner contracts job installs its library')->group(...$holds);
