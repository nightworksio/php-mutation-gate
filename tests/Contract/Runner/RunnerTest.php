<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
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
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;

// What every runner reports over the fixture library in fixture/: in
// src/Money.php a killed, a survived, an uncovered and a timed-out mutant, and
// in src/Held.php one held by the group holds:src/Held.php. Each runs against
// the fake (RunnerFake) and against every adapter whose runner is installed:
// the Pest adapter (Pest) once the runner contracts job has installed the
// library, and the Infection adapter (Infection) once its job has installed
// infection-fixture/, the same code tested by PHPUnit. Each library also
// holds its runner's own ignore marker in marked/Marked.php. The runs are
// real, so each request runs once per library.

$libraries = ['the fake' => fn(): Library => Library::fake()];

if (Library::isInstalled()) {
    $libraries['pest'] = fn(): Library => Library::pest(Patching::off());
}

if (Library::isInfectionInstalled()) {
    $libraries['infection'] = fn(): Library => Library::infection(Seconds::of(10.0));
}

/** The libraries whose runner reads a map from disk: every one but the fake. */
$onDisk = array_diff_key($libraries, ['the fake' => true]);

/** Money's four mutants, as a library's runner reports them. */
$money = static fn(Library $library): MutationResult|CannotJudge => $library->mutate(
    'money',
    MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->onlyMutators($library->mutators('adds', 'large', 'unused', 'drains')),
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

it('lists the group that holds a path, and the canary, among the suite\'s groups', function (Library $library): void {
    $groups = $library->runner()->groups(Withheld::standard());

    expect($groups instanceof Groups && $groups->has(Group::named('holds:src/Held.php')))->toBeTrue()
        ->and($groups instanceof Groups && $groups->has(Library::canary()))->toBeTrue();
})->with($libraries);

it('reports a killed, a survived, an uncovered and a timed-out mutant', function (Library $library) use ($money): void {
    $result = $money($library);

    expect($result)->toBeInstanceOf(MutationResult::class)
        ->and($result instanceof MutationResult ? $result->skipped() : -1)->toBe(0)
        ->and($result instanceof MutationResult ? Library::records($result->mutants()) : [])
        ->toEqualCanonicalizing($library->expected('adds', 'large', 'unused', 'drains'));
})->with($libraries);

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
        ->onlyMutators($library->mutators('adds'));
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
        ->onlyMutators($library->mutators('held'));
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

    $retried = $library->runner()->retry($survivors, Seconds::of(60.0), WholeSuite::tests(), Withheld::standard());

    expect(count($survivors))->toBe(1)
        ->and($retried instanceof Mutants ? Library::records($retried) : [])->toBe(Library::records($survivors));
})->with($libraries);

it('retries a mutant by the tests that judged its unit', function (Library $library) use ($money): void {
    $result = $money($library);
    $survivors = Mutants::none();

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $survivors = $mutant->status() === MutantStatus::Survived ? $survivors->with($mutant) : $survivors;
    }

    $retried = $library->runner()->retry($survivors, Seconds::of(60.0), Group::named('holds:src/Held.php'), Withheld::standard());
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
    $written = CoverageMapFile::encode($ran instanceof CoverageMap ? $ran : CoverageMap::empty());
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
        ->and($read)->toEqual(CoverageMapFile::decode($written))
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

it('finds its runner\'s own ignore marker, and none in code without one', function (Library $library): void {
    $found = $library->runner()->markers(Paths::of(Path::of('marked')));
    $none = $library->runner()->markers(Paths::of(Path::of('src/Money.php')));
    $where = $found instanceof Markers
        ? array_map(static fn(Marker $marker): string => $marker->where(), iterator_to_array($found, preserve_keys: false))
        : [];

    expect($where)->toBe([Library::MARKER])
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

it('judges a held path by the tests its #[Holds] filter names, with Infection', function (): void {
    $library = Library::infection(Seconds::of(10.0));
    $request = MutationRequest::of(Paths::of(Path::of('src/Held.php')), Filter::matching('HeldSpec'))
        ->onlyMutators($library->mutators('held'));
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
        CoverageMapFile::encode($planned instanceof CoverageMap ? $planned : CoverageMap::empty()),
    );
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->onlyMutators(Mutators::named('PublicVisibility'));
    $own = $library->mutate('signatures', $request);
    $reused = $library->mutate('signatures reused', $request->reusingCoverage($handedOver));
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

it('reports a mutant Infection skips, allowed the cap, and judges it when run again at a higher cap', function (): void {
    $library = Library::infection(Seconds::of(1.0));
    $request = MutationRequest::of(Paths::of(Path::of('src/Slow.php')), WholeSuite::tests())
        ->onlyMutators(Mutators::named('Minus'));
    $result = $library->mutate('slow', $request);
    $skipped = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $limits = array_map(
        static fn(Mutant $mutant): float => $mutant->limit() instanceof Seconds ? $mutant->limit()->seconds() : 0.0,
        iterator_to_array($skipped, preserve_keys: false),
    );
    $again = $library->runner()->retry($skipped, Seconds::of(3.0), WholeSuite::tests(), Withheld::standard());
    $statuses = static fn(Mutants $mutants): array => array_map(
        static fn(Mutant $mutant): string => $mutant->status()->value,
        iterator_to_array($mutants, preserve_keys: false),
    );

    expect($statuses($skipped))->toBe([MutantStatus::Skipped->value])
        ->and($limits)->toBe([1.0])
        ->and($again instanceof Mutants ? $statuses($again) : [])->toBe([MutantStatus::Killed->value]);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('allows a mutant Infection times out five seconds and five times its tests\' time, and runs it again only at the cap', function (): void {
    $library = Library::infection(Seconds::of(10.0));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->onlyMutators($library->mutators('drains'));
    $result = $library->mutate('drains', $request);
    $timedOut = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $limits = array_map(
        static fn(Mutant $mutant): float => $mutant->limit() instanceof Seconds ? $mutant->limit()->seconds() : 0.0,
        iterator_to_array($timedOut, preserve_keys: false),
    );
    $again = $library->runner()->retry($timedOut, Seconds::of(20.0), WholeSuite::tests(), Withheld::standard());

    expect(Library::records($timedOut))->toBe($library->expected('drains'))
        ->and($limits[0] ?? 0.0)->toBeGreaterThan(5.0)->toBeLessThan(6.0)
        ->and($again)->toEqual($timedOut);
})->skip(! Library::isInfectionInstalled(), 'the Infection runner contracts job installs the Infection library');

it('leaves a mutant unjudged, naming the test, when Pest\'s filter cannot select a covering test', function (): void {
    $library = Library::pest(Patching::off());
    $request = MutationRequest::of(Paths::of(Path::of('src/Legacy.php')), WholeSuite::tests())
        ->onlyMutators($library->mutators('unused'));
    $result = $library->mutate('legacy', $request);
    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];

    $statuses = array_map(static fn(Mutant $mutant): string => $mutant->status()->value, $mutants);
    $reasons = array_map(static fn(Mutant $mutant): Reason|Unreported => $mutant->reason(), $mutants);

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
        CoverageMapFile::encode($map instanceof CoverageMap ? $map : CoverageMap::empty()),
    );
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->onlyMutators($library->mutators('adds', 'large'))
        ->reusingCoverage(Path::of('.mutation-gate/planned'));
    $result = $library->mutate('shared', $request);

    expect($patched)->toBeString()
        ->and(Patch::isAppliedIn(Library::vendor()))->toBeTrue()
        ->and($map)->toBeInstanceOf(CoverageMap::class)
        ->and($result instanceof MutationResult ? Library::records($result->mutants()) : $result)
        ->toEqualCanonicalizing($library->expected('adds', 'large'));
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
