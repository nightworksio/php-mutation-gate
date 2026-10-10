<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\CoverageMeasured;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\KeptCoverage;
use NightWorksIO\MutationGate\Cli\Flow\KeptFrom;
use NightWorksIO\MutationGate\Cli\Flow\StoredCoverage;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Coverage;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Remeasured;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Access;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The flows' files with `tests/HeldTest.php`, whose test the fixture's map
 * holds, and these files changed besides.
 *
 * @param array<string, string> $changed
 *
 * @return array<string, string>
 */
function keptFiles(array $changed = []): array
{
    return [...Flows::FILES, 'tests/HeldTest.php' => "<?php\n\nit('doubles', fn () => expect(2)->toBe(2));\n", ...$changed];
}

/**
 * A project holding these files on disk.
 *
 * @param array<string, string> $files
 */
function keptProject(array $files): string
{
    $project = Scratch::directory();

    foreach ($files as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    return $project;
}

/**
 * A checkout at the flows' commit, its working tree holding these files and
 * that commit the kept ones, and this change since it.
 *
 * @param array<string, string> $files
 */
function keptCheckout(array $files, Changes $since): ChangeSourceFake
{
    return new ChangeSourceFake(Revision::ref(Flows::HEAD), $since, [
        Revision::workingTree()->name() => $files,
        Flows::HEAD => keptFiles(),
    ]);
}

/** Where the default branch's map was kept: at the flows' commit, in a clean tree. */
function keptAt(): MeasuredAt
{
    return MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: false);
}

/** The map `MoneyTest::adds` leaves when it runs again, now running line 15 of src/Money.php, in 3 seconds. */
function remeasuredMoney(): CoverageMap
{
    return CoverageMap::of(CoveredLine::of(Path::of('src/Money.php'), 15, 'MoneyTest::adds'))
        ->timedEach(TimedTest::of('MoneyTest::adds', 3.0));
}

/**
 * The project of these files, measured through this runner, with what it
 * holds, as the flows' settings with these parts read it.
 *
 * @param array<string, string> $files
 *
 * @return array{KeptCoverage, Inventory|CannotJudge, string}
 */
function keptFlow(array $files, CoverageAsked $runner, Changes $since, Setting ...$parts): array
{
    $project = keptProject($files);
    $adapters = Flows::adapters($project, [], $runner, keptCheckout($files, $since));
    $settings = Flows::settings(...$parts);

    return [new KeptCoverage($adapters, $settings, Flows::setup()), Inventory::of($adapters, $settings), $project];
}

/** A map kept with the entry keys the kept files give it, where it was measured. */
function keptMap(CoverageMap $map, MeasuredAt|Unplaced $at): KeptMap
{
    [$kept, $inventory] = keptFlow(keptFiles(), new CoverageAsked(ScriptedRunner::fixture(), $map), Changes::none());
    $keys = $inventory instanceof Inventory ? KeptCoverage::keysOf($kept->entries($inventory), $map) : $inventory;

    return KeptMap::of($map, $at, $keys instanceof EntryKeys ? $keys : EntryKeys::none());
}

/**
 * What a run over these files measures against a kept map: what it reads
 * the map from and the line it says, as an array to compare.
 *
 * @param array<string, string> $files
 *
 * @return array{CoverageRun|CoverageRead, string|NotGiven}|CannotJudge
 */
function keptMeasured(
    array $files,
    CoverageAsked $runner,
    KeptMap|Missing|CannotJudge $map,
    bool $ownMap = false,
    Changes|NotGiven $since = new NotGiven(),
): array|CannotJudge {
    [$kept, $inventory] = keptFlow($files, $runner, $since instanceof Changes ? $since : Changes::none());
    $from = $ownMap ? KeptFrom::LastRound : KeptFrom::DefaultBranch;
    $measured = $inventory instanceof Inventory ? $kept->measuring($kept->entries($inventory), $map, $from) : $inventory;

    return $measured instanceof CoverageMeasured ? [$measured->request(), $measured->said()] : $measured;
}

/** The map a project's run reads, as written, without where it was measured. */
function keptWritten(string $project): CoverageMap|CannotJudge
{
    return CoverageMapFile::decode((string) file_get_contents(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project)), HandedMaps::limits());
}

/**
 * What these coverage requests ask for: each read, or run for the whole suite or for some test files.
 *
 * @return list<string>
 */
function keptAsked(CoverageAsked $runner): array
{
    return array_map(static fn(CoverageRun|CoverageRead|CoverageRan $asked): string => match (true) {
        $asked instanceof CoverageRead, $asked instanceof CoverageRan => $asked->directory()->value(),
        $asked->tests() instanceof TestPaths => implode(' ', array_map(
            static fn(Path $file): string => $file->value(),
            [...$asked->tests()->files()],
        )),
        default => $asked->tests()::class,
    }, $runner->asked());
}

it('builds the whole suite\'s map where a run reads the map', function (): void {
    expect(KeptCoverage::built()->tests())->toEqual(WholeSuite::tests())
        ->and(KeptCoverage::built()->directory())->toEqual(Workspace::coverage());
});

it('measures again only the test files whose entries moved, and writes the kept map with theirs replaced', function (
    string $changed,
    string $file,
    string $test,
): void {
    $files = keptFiles([$changed => "<?php\n\n// changed\n"]);
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map())->runningFiles(remeasuredMoney());
    [$kept, $inventory, $project] = keptFlow($files, $runner, Changes::none());
    $measured = $inventory instanceof Inventory
        ? $kept->measuring($kept->entries($inventory), keptMap(Flows::map(), keptAt()), KeptFrom::DefaultBranch)
        : $inventory;

    expect($measured)->toEqual(CoverageMeasured::of(
        CoverageRead::from(Workspace::coverage()),
        "Coverage: measured 1 of 2 test files again; kept the rest from the default branch's map.",
    ))
        ->and(keptAsked($runner))->toBe([$file])
        ->and($runner->ran()[0]->directory())->toEqual(Workspace::remeasuredCoverage())
        ->and(keptWritten($project))->toEqual(
            Remeasured::over(Flows::map(), TestIds::of(TestId::of($test)), remeasuredMoney()),
        );
})->with([
    'a test file' => ['tests/MoneyTest.php', 'tests/MoneyTest.php', 'MoneyTest::adds'],
    'a source only one test file executed' => ['src/Held.php', 'tests/HeldTest.php', 'HeldTest::doubles'],
]);

it('measures no test where no entry moved, and writes the kept map as measured now', function (): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    [$kept, $inventory, $project] = keptFlow(keptFiles(), $runner, Changes::none());
    $measured = $inventory instanceof Inventory
        ? $kept->measuring($kept->entries($inventory), keptMap(Flows::map(), keptAt()), KeptFrom::DefaultBranch)
        : $inventory;
    $written = (string) file_get_contents(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project));

    expect($measured)->toEqual(CoverageMeasured::of(
        CoverageRead::from(Workspace::coverage()),
        "Coverage: measured 0 of 2 test files again; kept the rest from the default branch's map.",
    ))
        ->and($runner->asked())->toBe([])
        ->and(keptWritten($project))->toEqual(CoverageMapFile::decode(CoverageMapFile::encode(Flows::map(), keptAt()), HandedMaps::limits()))
        ->and(MeasuredAt::recordedIn($written, HandedMaps::limits()))->toEqual(keptAt());
});

it('drops the entries of a gone test file, measuring nothing for it', function (): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    [$kept, $inventory, $project] = keptFlow(Flows::FILES, $runner, Changes::none());
    $measured = $inventory instanceof Inventory
        ? $kept->measuring($kept->entries($inventory), keptMap(Flows::map(), keptAt()), KeptFrom::DefaultBranch)
        : $inventory;

    expect($measured)->toEqual(CoverageMeasured::of(
        CoverageRead::from(Workspace::coverage()),
        "Coverage: measured 0 of 1 test files again; kept the rest from the default branch's map.",
    ))
        ->and($runner->asked())->toBe([])
        ->and(keptWritten($project))->toEqual(
            Remeasured::over(Flows::map(), TestIds::of(TestId::of('HeldTest::doubles')), CoverageMap::empty()),
        );
});

it('reuses the map the last round left though it was measured in a dirty tree, saying so', function (): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());

    expect(keptMeasured(keptFiles(), $runner, keptMap(Flows::map(), MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: true)), ownMap: true))
        ->toEqual([
            CoverageRead::from(Workspace::coverage()),
            "Coverage: measured 0 of 2 test files again; kept the rest from the last round's map.",
        ]);
});

it('measures every test where it cannot reuse the kept map, saying why', function (
    KeptMap|Missing|CannotJudge $map,
    string $said,
): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());

    expect(keptMeasured(keptFiles(), $runner, $map))->toEqual([KeptCoverage::built(), $said])
        ->and($runner->asked())->toBe([]);
})->with([
    'none is kept' => [
        fn(): Missing => Missing::at(Path::of('refs/heads/main/coverage.json.gz')),
        'Coverage: there is no kept map, so every test was measured.',
    ],
    'it cannot be read' => [
        fn(): CannotJudge => CannotJudge::because('The kept coverage map could not be read.'),
        'Coverage: The kept coverage map could not be read. So every test was measured.',
    ],
    'it was measured in a dirty tree' => [
        fn(): KeptMap => keptMap(Flows::map(), MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: true)),
        'Coverage: the kept map was measured in a dirty working tree, so every test was measured.',
    ],
    'it does not say where it was measured' => [
        fn(): KeptMap => keptMap(Flows::map(), Unplaced::map()),
        'Coverage: the kept map was measured in a dirty working tree, so every test was measured.',
    ],
    'it holds a test no test file holds' => [
        fn(): KeptMap => keptMap(
            Flows::map()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('GoneTest::went')),
            keptAt(),
        ),
        'Coverage: the kept map holds a test that no test file holds now, so every test was measured.',
    ],
]);

it('measures every test where coverage.incremental is false, whatever is kept', function (): void {
    [$kept, $inventory] = keptFlow(
        keptFiles(),
        new CoverageAsked(ScriptedRunner::fixture(), Flows::map()),
        Changes::none(),
        Coverage::full(),
    );
    $measured = $inventory instanceof Inventory
        ? $kept->measuring($kept->entries($inventory), keptMap(Flows::map(), keptAt()), KeptFrom::DefaultBranch)
        : $inventory;

    expect($measured)->toEqual(CoverageMeasured::of(
        KeptCoverage::built(),
        'Coverage: coverage.incremental is false, so every test was measured.',
    ));
});

it('measures every test where every entry moved, naming a changed file every entry reads', function (
    Changes $since,
    string $said,
): void {
    $files = keptFiles(['composer.json' => "{\"name\": \"changed\"}\n"]);
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());

    expect(keptMeasured($files, $runner, keptMap(Flows::map(), keptAt()), since: $since))
        ->toEqual([KeptCoverage::built(), $said])
        ->and($runner->asked())->toBe([]);
})->with([
    'a change that names it' => [
        fn(): Changes => Changes::of(
            Change::modified(Path::of('src/Money.php'), Lines::none()),
            Change::modified(Path::of('composer.json'), Lines::none()),
        ),
        'Coverage: `composer.json` changed, and every coverage entry reads it, so every test was measured.',
    ],
    'a change that names none' => [
        fn(): Changes => Changes::of(Change::modified(Path::of('src/Money.php'), Lines::none())),
        "Coverage: measured 2 of 2 test files again; kept the rest from the default branch's map.",
    ],
]);

it('names the last round\'s map where every entry moved and the change names no file every entry reads', function (): void {
    $files = keptFiles(['composer.json' => "{\"name\": \"changed\"}\n"]);
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());

    expect(keptMeasured($files, $runner, keptMap(Flows::map(), keptAt()), ownMap: true))->toEqual([
        KeptCoverage::built(),
        "Coverage: measured 2 of 2 test files again; kept the rest from the last round's map.",
    ]);
});

it('measures nothing in a project of no test file, where the kept map holds no test', function (): void {
    $files = array_filter(keptFiles(), static fn(string $path): bool => ! str_starts_with($path, 'tests/'), ARRAY_FILTER_USE_KEY);
    $runner = new CoverageAsked(ScriptedRunner::fixture(), CoverageMap::empty());

    expect(keptMeasured($files, $runner, keptMap(CoverageMap::empty(), keptAt())))->toEqual([
        CoverageRead::from(Workspace::coverage()),
        "Coverage: measured 0 of 0 test files again; kept the rest from the default branch's map.",
    ])
        ->and($runner->asked())->toBe([]);
});

it('measures every test where the moved test files cannot be measured, or the map cannot be written', function (
    bool $blocked,
    CoverageMap|CannotJudge $remeasured,
    string $said,
): void {
    $files = keptFiles(['tests/MoneyTest.php' => "<?php\n\n// changed\n"]);
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map())->runningFiles($remeasured);
    [$kept, $inventory, $project] = keptFlow($files, $runner, Changes::none());

    if ($blocked) {
        mkdir(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project), recursive: true);
    }

    $measured = $inventory instanceof Inventory
        ? $kept->measuring($kept->entries($inventory), keptMap(Flows::map(), keptAt()), KeptFrom::DefaultBranch)
        : $inventory;

    expect($measured)->toEqual(CoverageMeasured::of(KeptCoverage::built(), sprintf($said, $project)));
})->with([
    'the run fails' => [false, fn(): CannotJudge => CannotJudge::because('1 test failed.'), 'Coverage: 1 test failed. So every test was measured.'],
    'the map cannot be written' => [
        true,
        fn(): CoverageMap => remeasuredMoney(),
        'Coverage: %s/.mutation-gate/coverage/map.json.gz could not be written. So every test was measured.',
    ],
]);

it('measures every test where the entries cannot be keyed', function (CoverageAsked $runner, string $said): void {
    expect(keptMeasured(keptFiles(), $runner, KeptMap::of(Flows::map(), keptAt(), EntryKeys::none())))
        ->toEqual([KeptCoverage::built(), $said]);
})->with([
    'the runner cannot name itself' => [
        fn(): CoverageAsked => new CoverageAsked(ScriptedRunner::fixture()->unnamed('No name.'), Flows::map()),
        'Coverage: No name. So every test was measured.',
    ],
    'the runner cannot tell a file\'s tests' => [
        fn(): CoverageAsked => new CoverageAsked(ScriptedRunner::fixture(), Flows::map())->unplacing('No tests.'),
        'Coverage: No tests. So every test was measured.',
    ],
]);

it('measures every test where the project cannot be listed', function (): void {
    [$kept] = keptFlow(keptFiles(), new CoverageAsked(ScriptedRunner::fixture(), Flows::map()), Changes::none());

    $unlisted = CannotJudge::because('No tree is declared.');

    expect($kept->forRun($unlisted, $kept->entries($unlisted), KeptCoverage::built(), ownMap: false))
        ->toEqual(CoverageMeasured::of(KeptCoverage::built(), 'Coverage: No tree is declared. So every test was measured.'));
});

it('reads the map the default branch\'s runs keep from a store, where the run\'s own scope keeps none', function (
    Scope|Detached $on,
): void {
    $store = new ProofStoreFake();
    $kept = keptMap(Flows::map(), keptAt());
    $bytes = CoverageMapFile::keeping($kept, MapLimits::standard());
    $store->keep(Scope::branch('main'), Companion::Coverage, Contents::of(is_string($bytes) ? $bytes : ''));

    expect(KeptCoverage::fromStore($store, Access::of($on, Scope::branch('main'), Writing::Auto)))
        ->toEqual(StoredCoverage::of($kept, KeptFrom::DefaultBranch));
})->with([
    'the default branch' => [fn(): Scope => Scope::branch('main')],
    'a pull request' => [fn(): Scope => Scope::pullRequest(7)],
    'a detached HEAD' => [fn(): Detached => Detached::head()],
]);

it('reads the map its own scope keeps first, and the default branch\'s where its own cannot be read', function (): void {
    $store = new ProofStoreFake();
    $main = keptMap(Flows::map(), keptAt());
    $own = keptMap(remeasuredMoney(), keptAt());
    $bytes = static fn(KeptMap $map): string => (static fn(mixed $kept): string => is_string($kept) ? $kept : '')(
        CoverageMapFile::keeping($map, MapLimits::standard()),
    );
    $access = Access::of(Scope::pullRequest(7), Scope::branch('main'), Writing::Auto);
    $store->keep(Scope::branch('main'), Companion::Coverage, Contents::of($bytes($main)));
    $store->keep(Scope::pullRequest(7), Companion::Coverage, Contents::of($bytes($own)));
    $first = KeptCoverage::fromStore($store, $access);
    $store->keep(Scope::pullRequest(7), Companion::Coverage, Contents::of('not a map'));

    expect($first)->toEqual(StoredCoverage::of($own, KeptFrom::OwnScope))
        ->and(KeptCoverage::fromStore($store, $access))->toEqual(StoredCoverage::of($main, KeptFrom::DefaultBranch));
});

it('reads no map from a store that keeps none, and says why one that is no map cannot be read', function (): void {
    $store = new ProofStoreFake();
    $access = Access::of(Scope::branch('main'), Scope::branch('main'), Writing::Auto);
    $none = KeptCoverage::fromStore($store, $access)->map();
    $store->keep(Scope::branch('main'), Companion::Coverage, Contents::of('not a map'));

    expect($none)->toBeInstanceOf(Missing::class)
        ->and(KeptCoverage::fromStore($store, $access)->map())->toBeInstanceOf(CannotJudge::class);
});

it('reads the default branch\'s map where a run asks for the whole suite, and the last round\'s for watch', function (): void {
    $files = keptFiles(['tests/MoneyTest.php' => "<?php\n\n// changed\n"]);
    $project = keptProject($files);
    $store = new ProofStoreFake();
    $bytes = CoverageMapFile::keeping(keptMap(Flows::map(), keptAt()), MapLimits::standard());
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map())->runningFiles(remeasuredMoney());
    $adapters = Flows::adapters($project, [], $runner, keptCheckout($files, Changes::none()), $store);
    $flow = new KeptCoverage($adapters, Flows::settings(), Flows::setup());
    $inventory = Inventory::of($adapters, Flows::settings());
    $said = static fn(bool $ownMap): string|NotGiven|CannotJudge => $inventory instanceof Inventory
        ? $flow->forRun($inventory, $flow->entries($inventory), KeptCoverage::built(), $ownMap)->said()
        : $inventory;
    Scratch::write($project, '.mutation-gate/coverage/map.json.gz', is_string($bytes) ? $bytes : '');
    $lastRound = $said(ownMap: true);
    $store->keep(Scope::branch('main'), Companion::Coverage, Contents::of(is_string($bytes) ? $bytes : ''));

    expect($lastRound)->toBe("Coverage: measured 1 of 2 test files again; kept the rest from the last round's map.")
        ->and($said(ownMap: false))->toBe("Coverage: measured 1 of 2 test files again; kept the rest from the default branch's map.");
});

it('measures against its own scope\'s map where it keeps one, saying so, and marks what it measured as its own scope\'s', function (): void {
    $files = keptFiles(['tests/MoneyTest.php' => "<?php\n\n// changed\n"]);
    $project = keptProject($files);
    $store = new ProofStoreFake();
    $bytes = CoverageMapFile::keeping(keptMap(Flows::map(), keptAt()), MapLimits::standard());
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map())->runningFiles(remeasuredMoney());
    $adapters = Flows::adapters($project, [], $runner, keptCheckout($files, Changes::none()), $store, new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main'))));
    $flow = new KeptCoverage($adapters, Flows::settings(), Flows::setup());
    $inventory = Inventory::of($adapters, Flows::settings());
    $store->keep(Scope::branch('main'), Companion::Coverage, Contents::of(is_string($bytes) ? $bytes : ''));
    $measured = static fn(): CoverageMeasured|CannotJudge => $inventory instanceof Inventory
        ? $flow->forRun($inventory, $flow->entries($inventory), KeptCoverage::built(), ownMap: false)
        : $inventory;
    $fromMain = $measured();
    $store->keep(Scope::pullRequest(7), Companion::Coverage, Contents::of(is_string($bytes) ? $bytes : ''));
    $fromOwn = $measured();

    expect($fromMain instanceof CoverageMeasured ? [$fromMain->said(), $fromMain->isFromOwnScope()] : $fromMain)
        ->toBe(["Coverage: measured 1 of 2 test files again; kept the rest from the default branch's map.", false])
        ->and($fromOwn instanceof CoverageMeasured ? [$fromOwn->said(), $fromOwn->isFromOwnScope()] : $fromOwn)
        ->toBe(["Coverage: measured 1 of 2 test files again; kept the rest from this scope's map.", true]);
});

it('reads the map a run asks to read as it is, saying nothing', function (): void {
    [$kept, $inventory] = keptFlow(keptFiles(), new CoverageAsked(ScriptedRunner::fixture(), Flows::map()), Changes::none());
    $read = CoverageRead::from(Path::of('handed'));

    expect($inventory instanceof Inventory ? $kept->forRun($inventory, $kept->entries($inventory), $read, ownMap: false) : $inventory)
        ->toEqual(CoverageMeasured::asked($read));
});

it('reads no map the last round left where there is none, and says why one that is no map cannot be read', function (): void {
    [$kept, , $project] = keptFlow(keptFiles(), new CoverageAsked(ScriptedRunner::fixture(), Flows::map()), Changes::none());
    $none = $kept->fromWorkspace();
    Scratch::write($project, '.mutation-gate/coverage/map.json.gz', 'not a map');

    expect($none)->toBeInstanceOf(Missing::class)
        ->and($kept->fromWorkspace())->toBeInstanceOf(CannotJudge::class);
});

it('keys each test file\'s entries over a map, and none where coverage.incremental is false or they cannot be told', function (
    CoverageAsked $runner,
    Coverage $coverage,
    array|string $keyed,
): void {
    [$kept, $inventory] = keptFlow(keptFiles(), $runner, Changes::none(), $coverage);
    $keys = $inventory instanceof Inventory ? KeptCoverage::keysOf($kept->entries($inventory), Flows::map()) : $inventory;

    expect($keys instanceof EntryKeys ? array_keys($keys->written()) : $keys::class)->toBe($keyed);
})->with([
    'keyed' => [
        fn(): CoverageAsked => new CoverageAsked(ScriptedRunner::fixture(), Flows::map()),
        fn(): Coverage => Coverage::incremental(),
        ['tests/HeldTest.php', 'tests/MoneyTest.php'],
    ],
    'coverage.incremental is false' => [
        fn(): CoverageAsked => new CoverageAsked(ScriptedRunner::fixture(), Flows::map()),
        fn(): Coverage => Coverage::full(),
        NotGiven::class,
    ],
    'the runner cannot name itself' => [
        fn(): CoverageAsked => new CoverageAsked(ScriptedRunner::fixture()->unnamed('No name.'), Flows::map()),
        fn(): Coverage => Coverage::incremental(),
        NotGiven::class,
    ],
    'the runner cannot tell a file\'s tests' => [
        fn(): CoverageAsked => new CoverageAsked(ScriptedRunner::fixture(), Flows::map())->unplacing('No tests.'),
        fn(): Coverage => Coverage::incremental(),
        NotGiven::class,
    ],
]);

/**
 * What keeping the map a project's plan handed on does, on this scope, as
 * the flows' settings with these parts read it.
 *
 * @return array{string, ProofStoreFake, Written|NotWritten|ReadsOnly}
 */
function keptKeeping(string|NotGiven $handed, Scope $on, Setting ...$parts): array
{
    $project = keptProject(keptFiles());
    $store = new ProofStoreFake();

    if (is_string($handed)) {
        Scratch::write($project, '.mutation-gate/coverage/map.json.gz', $handed);
    }

    $adapters = Flows::adapters($project, [], $store);
    $kept = new KeptCoverage($adapters, Flows::settings(...$parts), Flows::setup());

    return [$project, $store, $kept->keep(Access::of($on, Scope::branch('main'), Writing::Auto))];
}

it('keeps the map the plan handed on beside the default branch\'s ledger, for a run on that branch', function (): void {
    $handed = CoverageMapFile::encode(Flows::map(), keptAt(), EntryKeys::none()->with(Path::of('tests/MoneyTest.php'), Digest::sha256Of('key')));
    [, $store, $kept] = keptKeeping($handed, Scope::branch('main'));
    $stored = $store->companion(Scope::branch('main'), Companion::Coverage);

    expect($kept)->toEqual(Written::to('memory:refs/heads/main/coverage.json.gz'))
        ->and($stored instanceof Contents ? CoverageMapFile::kept($stored->text(), MapLimits::standard()) : $stored)
        ->toEqual(CoverageMapFile::kept($handed, MapLimits::standard()));
});

it('keeps the map of a run on a pull request beside its own scope\'s ledger, and never the default branch\'s', function (): void {
    $handed = CoverageMapFile::encode(Flows::map(), keptAt(), EntryKeys::none()->with(Path::of('tests/MoneyTest.php'), Digest::sha256Of('key')));
    [, $store, $kept] = keptKeeping($handed, Scope::pullRequest(7));

    expect($kept)->toEqual(Written::to('memory:refs/pull/7/coverage.json.gz'))
        ->and($store->companion(Scope::branch('main'), Companion::Coverage))->toBeInstanceOf(Missing::class);
});

it('keeps no map where it is not to be kept, saying why', function (
    string|NotGiven $handed,
    Scope $on,
    Coverage $coverage,
    Written|NotWritten|ReadsOnly $why,
): void {
    [, $store, $kept] = keptKeeping($handed, $on, $coverage);

    expect($kept)->toEqual($why)
        ->and($store->companion(Scope::branch('main'), Companion::Coverage))->toBeInstanceOf(Missing::class);
})->with([
    'coverage.incremental is false' => [
        fn(): string => CoverageMapFile::encode(Flows::map(), keptAt()),
        fn(): Scope => Scope::branch('main'),
        fn(): Coverage => Coverage::full(),
        fn(): ReadsOnly => ReadsOnly::because('coverage.incremental is false, so no coverage map is kept.'),
    ],
    'no map handed on' => [
        fn(): NotGiven => NotGiven::value(),
        fn(): Scope => Scope::branch('main'),
        fn(): Coverage => Coverage::incremental(),
        fn(): NotWritten => NotWritten::because('The plan handed on no coverage map at .mutation-gate/coverage, so none is kept.'),
    ],
    'a map that is no map' => [
        'not a map',
        fn(): Scope => Scope::branch('main'),
        fn(): Coverage => Coverage::incremental(),
        fn(): NotWritten => NotWritten::because('The coverage map is not a whole gzip stream.'),
    ],
    'a map measured in a dirty tree' => [
        fn(): string => CoverageMapFile::encode(Flows::map(), MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: true)),
        fn(): Scope => Scope::branch('main'),
        fn(): Coverage => Coverage::incremental(),
        fn(): ReadsOnly => ReadsOnly::because('The coverage map was measured in a dirty working tree, so it is not kept.'),
    ],
]);

it('measures every test where it is given no entries or entries that cannot be told', function (
    CannotJudge|NotGiven $entries,
    string $said,
): void {
    [$kept] = keptFlow(keptFiles(), new CoverageAsked(ScriptedRunner::fixture(), Flows::map()), Changes::none());

    expect($kept->measuring($entries, keptMap(Flows::map(), keptAt()), KeptFrom::DefaultBranch))
        ->toEqual(CoverageMeasured::of(KeptCoverage::built(), $said));
})->with([
    'none, as coverage.incremental false gives' => [
        fn(): NotGiven => NotGiven::value(),
        'Coverage: coverage.incremental is false, so every test was measured.',
    ],
    'entries that cannot be told' => [
        fn(): CannotJudge => CannotJudge::because('No name.'),
        'Coverage: No name. So every test was measured.',
    ],
]);

it('gives no entries where coverage.incremental is false, and says why where the project cannot be listed', function (): void {
    [$off, $inventory] = keptFlow(
        keptFiles(),
        new CoverageAsked(ScriptedRunner::fixture(), Flows::map()),
        Changes::none(),
        Coverage::full(),
    );
    [$on] = keptFlow(keptFiles(), new CoverageAsked(ScriptedRunner::fixture(), Flows::map()), Changes::none());

    expect($off->entries($inventory))->toEqual(NotGiven::value())
        ->and($on->entries(CannotJudge::because('No tree.')))->toEqual(CannotJudge::because('No tree.'));
});
