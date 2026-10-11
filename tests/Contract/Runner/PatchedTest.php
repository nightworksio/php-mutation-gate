<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Adapter\Infection\Patch as InfectionPatch;
use NightWorksIO\MutationGate\Adapter\Infection\Release;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Records;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayRecord;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
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
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\InfectionSource;
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
        ->and(InfectionPatch::applyIn($installed))->toBe('infection:patch patched 3 of the 3 files it changes in infection. infection:patch patched 1 of the 1 files it changes in include-interceptor.')
        ->and(InfectionPatch::applyIn($pristine))->toBe('infection:patch patched 3 of the 3 files it changes in infection. infection:patch patched 1 of the 1 files it changes in include-interceptor.')
        ->and($read($installed))->toBe($read($pristine));
})->skip(fn(): bool => ! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

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
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

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
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

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
])->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

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
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('vouches for a narrowed kill by its own run, replayed unmutated in the order it took, stopped after its last killer', function (): void {
    Patch::applyIn(Library::vendor());
    $library = Library::pest(Patching::on(Library::canary()));
    $map = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/replayed')));
    file_put_contents(
        Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in(Path::of('.mutation-gate/replayed'))->value())),
        CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::empty(), Unplaced::map()),
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
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

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
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

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
})->skip(fn(): bool => getenv('RUNNER_CANARY') === '1', 'the runner canary frees the library from the pin');

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
})->skip(fn(): bool => getenv('RUNNER_CANARY') === '1', 'the runner canary frees the library from the pin');

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
        ->and(Patch::applyIn($pristine))->toBe('pest:patch patched 4 of the 4 files it changes in pest-plugin-mutate.')
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
})->skip(fn(): bool => ! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('has the library installed wherever the runner contracts run', function (): void {
    expect(Library::isInstalled())->toBeTrue();
})->skip(fn(): bool => getenv('RUNNER_CONTRACTS') !== 'pest', 'only the runner contracts job installs the fixture library');

it('has the Infection library installed wherever the Infection runner contracts run', function (): void {
    expect(Library::isInfectionInstalled())->toBeTrue();
})->skip(fn(): bool => getenv('RUNNER_CONTRACTS') !== 'infection', 'only the Infection runner contracts job installs its library');
