<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\KeptCoverage;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\Remeasured;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** The flows' checkout, holding at `HEAD` and on disk what the flows' project holds. */
function keptCheckout(): ChangeSourceFake
{
    return new ChangeSourceFake(
        Revision::head(),
        Changes::none(),
        [Revision::workingTree()->name() => Flows::FILES, Revision::HEAD => Flows::FILES],
    );
}

/** The map `MoneyTest::adds` leaves when it runs again, now running line 15 of src/Money.php, in 3 seconds. */
function remeasuredMoney(): CoverageMap
{
    return CoverageMap::of(CoveredLine::of(Path::of('src/Money.php'), 15, 'MoneyTest::adds'))
        ->timedEach(TimedTest::of('MoneyTest::adds', 3.0));
}

/** The map the kept one is written as in a project, or why it cannot be read. */
function keptIn(string $project): CoverageMap|CannotJudge
{
    $read = Directory::at($project)->read(CoverageMapFile::in(Workspace::coverage()));

    return $read instanceof Contents ? CoverageMapFile::decode($read->text()) : CannotJudge::because('no map');
}

/** A map as a written one reads back. */
function asWritten(CoverageMap $map): CoverageMap|CannotJudge
{
    return CoverageMapFile::decode(CoverageMapFile::encode($map, Unplaced::map()));
}

/**
 * What these coverage requests ask for, each read, run for the whole suite or run for some test files.
 *
 * @return list<string|Paths>
 */
function keptAsked(CoverageAsked $runner): array
{
    return array_map(static fn(CoverageRun|CoverageRead $asked): string|Paths => match (true) {
        $asked instanceof CoverageRead => $asked->directory()->value(),
        $asked->tests() instanceof TestPaths => $asked->tests()->files(),
        default => $asked->tests()::class,
    }, $runner->asked());
}

it('measures the tests of a changed test file again, and writes the kept map with their entries replaced', function (): void {
    $project = Flows::project();
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map())->runningFiles(remeasuredMoney());
    $adapters = Flows::adapters($project, [], $runner, keptCheckout());
    $kept = new KeptCoverage($adapters, Flows::settings());

    $read = $kept->after(Changes::of(Change::modified(Path::of('tests/MoneyTest.php'), Lines::none())));

    expect($read)->toEqual(CoverageRead::from(Workspace::coverage()))
        ->and(keptAsked($runner))->toEqual([Workspace::coverage()->value(), Paths::of(Path::of('tests/MoneyTest.php'))])
        ->and($runner->ran()[0]->directory())->toEqual(Workspace::remeasuredCoverage())
        ->and($runner->ran()[0]->withheld())->toEqual($adapters->withheld)
        ->and(keptIn($project))->toEqual(asWritten(
            Remeasured::over(Flows::map(), TestIds::of(TestId::of('MoneyTest::adds')), remeasuredMoney()),
        ));
});

it('leaves out the entries of a gone test file, and runs nothing where no file of it is left', function (): void {
    $project = Flows::project();
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $kept = new KeptCoverage(Flows::adapters($project, [], $runner, keptCheckout()), Flows::settings());

    $read = $kept->after(Changes::of(Change::deleted(Path::of('tests/HeldTest.php'))));

    expect($read)->toEqual(CoverageRead::from(Workspace::coverage()))
        ->and(keptAsked($runner))->toBe([Workspace::coverage()->value()])
        ->and(keptIn($project))->toEqual(asWritten(
            Remeasured::over(Flows::map(), TestIds::of(TestId::of('HeldTest::doubles')), CoverageMap::empty()),
        ));
});

it('measures again the tests that used support gone from disk, by what it declared at HEAD', function (): void {
    $fake = "<?php\n\nnamespace Tests\\Fakes;\n\nfinal class ClockFake {}\n";
    $ticking = "<?php\n\nuse Tests\\Fakes\\ClockFake;\n\nit('ticks', fn () => new ClockFake());\n";
    $project = Flows::project();
    Scratch::write($project, 'tests/ClockTest.php', $ticking);
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $checkout = new ChangeSourceFake(Revision::head(), Changes::none(), [
        Revision::workingTree()->name() => [...Flows::FILES, 'tests/ClockTest.php' => $ticking],
        Revision::HEAD => [...Flows::FILES, 'tests/ClockTest.php' => $ticking, 'tests/Fakes/ClockFake.php' => $fake],
    ]);
    $kept = new KeptCoverage(Flows::adapters($project, [], $runner, $checkout), Flows::settings());

    $kept->after(Changes::of(Change::deleted(Path::of('tests/Fakes/ClockFake.php'))));

    expect(keptAsked($runner))->toEqual([Workspace::coverage()->value(), Paths::of(Path::of('tests/ClockTest.php'))]);
});

it('reads the kept map as it is after a change that touches no test', function (): void {
    $project = Flows::project();
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $kept = new KeptCoverage(Flows::adapters($project, [], $runner, keptCheckout()), Flows::settings());

    $read = $kept->after(Changes::of(Change::modified(Path::of('src/Money.php'), Lines::none())));

    expect($read)->toEqual(CoverageRead::from(Workspace::coverage()))
        ->and($runner->asked())->toBe([])
        ->and(keptIn($project))->toBeInstanceOf(CannotJudge::class);
});

it('builds the map again after a change to a file that decides how the gate runs', function (): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $kept = new KeptCoverage(Flows::adapters(Flows::project(), [], $runner, keptCheckout()), Flows::settings());

    $built = $kept->after(Changes::of(Change::modified(Path::of('tests/Pest.php'), Lines::none())));

    expect($built)->toEqual(KeptCoverage::built())
        ->and($runner->asked())->toBe([]);
});

it('builds the whole suite\'s map where a round reads the map', function (): void {
    expect(KeptCoverage::built()->tests())->toEqual(WholeSuite::tests())
        ->and(KeptCoverage::built()->directory())->toEqual(Workspace::coverage());
});

it('builds the map again where the kept one cannot be read, or the run of the test files fails', function (
    CoverageAsked $runner,
): void {
    $kept = new KeptCoverage(Flows::adapters(Flows::project(), [], $runner, keptCheckout()), Flows::settings());

    expect($kept->after(Changes::of(Change::modified(Path::of('tests/MoneyTest.php'), Lines::none()))))
        ->toEqual(KeptCoverage::built());
})->with([
    'unread' => fn(): CoverageAsked => new CoverageAsked(ScriptedRunner::fixture(), CannotJudge::because('No map.')),
    'failed' => fn(): CoverageAsked => new CoverageAsked(ScriptedRunner::fixture(), Flows::map())
        ->runningFiles(CannotJudge::because('1 test failed.')),
]);

it('builds the map again where the repository cannot be read for what the change touched', function (): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $adapters = Flows::adapters(
        Flows::project(),
        [],
        $runner,
        keptCheckout(),
        new TreeSourceFake(CannotJudge::because('No trees.')),
    );

    expect(new KeptCoverage($adapters, Flows::settings())->after(Changes::of(
        Change::modified(Path::of('tests/MoneyTest.php'), Lines::none()),
    )))->toEqual(KeptCoverage::built())
        ->and($runner->asked())->toBe([]);
});
