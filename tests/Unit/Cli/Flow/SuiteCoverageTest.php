<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\SuiteCoverage;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\HoldingSuites;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The tests the holding suites' runner lists over Process: src/Held.php held by HeldTest, or nothing held. */
function processListing(bool $holds): TestListing
{
    $listing = TestListing::of(TestIds::of(TestId::of(HoldingSuites::STARTS)));

    return $holds ? $listing->grouping(Group::named('holds:src/Held.php'), TestIds::of(TestId::of(HoldingSuites::STARTS))) : $listing;
}

/**
 * The coverage the holding-suites project's suites may judge, with its
 * runner answering every coverage run with this map, and that runner.
 *
 * @return array{SuiteCoverage, CoverageAsked}
 */
function suiteCoverage(CoverageMap $map, bool $holds = true, string $project = ''): array
{
    $runner = new CoverageAsked(HoldingSuites::runner(TestListing::none(), processListing($holds), $map), $map);
    $adapters = HoldingSuites::adapters($project === '' ? HoldingSuites::project() : $project, HoldingSuites::checkout(), $runner);
    $inventory = Inventory::of($adapters, Flows::settings());

    return [$inventory instanceof Inventory ? SuiteCoverage::of($adapters, $inventory) : throw new LogicException($inventory->why()), $runner];
}

/** Every test of each suite running a line of src/Held.php and one of src/Money.php, each timed. */
function everySuitesMap(): CoverageMap
{
    return CoverageMap::of(
        CoveredLine::of(Path::of('src/Held.php'), 4, HoldingSuites::ADDS, HoldingSuites::STARTS, HoldingSuites::RULES),
        CoveredLine::of(Path::of('src/Money.php'), 7, HoldingSuites::ADDS, HoldingSuites::STARTS, HoldingSuites::RULES),
        CoveredLine::of(Path::of('src/Money.php'), 9),
    )->timedEach(
        TimedTest::of(HoldingSuites::ADDS, 0.1),
        TimedTest::of(HoldingSuites::STARTS, 2.0),
        TimedTest::of(HoldingSuites::RULES, 0.5),
    )->executing(Path::of('src/Held.php'), ExecutedMethod::of('start', 3, 5));
}

it('keeps a judging suite\'s test on every line, a holding suite\'s on the lines of what it holds, and no test of a suite no list names', function (): void {
    [$suites] = suiteCoverage(everySuitesMap());

    expect($suites->admitted(everySuitesMap()))->toEqual(CoverageMap::of(
        CoveredLine::of(Path::of('src/Held.php'), 4, HoldingSuites::ADDS, HoldingSuites::STARTS),
        CoveredLine::of(Path::of('src/Money.php'), 7, HoldingSuites::ADDS),
        CoveredLine::of(Path::of('src/Money.php'), 9),
    )->timedEach(
        TimedTest::of(HoldingSuites::ADDS, 0.1),
        TimedTest::of(HoldingSuites::STARTS, 2.0),
    )->executing(Path::of('src/Held.php'), ExecutedMethod::of('start', 3, 5)));
});

it('keeps no holding suite\'s test where nothing is held from it, and a test no file holds as it is', function (): void {
    [$suites] = suiteCoverage(everySuitesMap(), holds: false);
    $map = CoverageMap::of(
        CoveredLine::of(Path::of('src/Held.php'), 4, HoldingSuites::STARTS, 'Elsewhere::runs'),
    )->timedEach(TimedTest::of(HoldingSuites::STARTS, 2.0), TimedTest::of('Elsewhere::runs', 1.0));

    expect($suites->admitted($map))->toEqual(
        CoverageMap::of(CoveredLine::of(Path::of('src/Held.php'), 4, 'Elsewhere::runs'))
            ->timedEach(TimedTest::of('Elsewhere::runs', 1.0)),
    );
});

it('cannot admit a map where a hold from the holding suites runs no line of what it holds', function (): void {
    [$suites] = suiteCoverage(everySuitesMap());
    $map = CoverageMap::of(CoveredLine::of(Path::of('src/Money.php'), 7, HoldingSuites::ADDS, HoldingSuites::STARTS));

    expect($suites->admitted($map))->toEqual(CannotJudge::because(<<<'SAID'
        holds:src/Held.php in the holding suites runs no line of src/Held.php, so it judges none of its mutants.
        Add the test that runs it to the group, or remove the hold.
        SAID));
});

it('cannot admit a map where the runner cannot tell which tests a file holds', function (): void {
    [$suites, $runner] = suiteCoverage(everySuitesMap());
    $unplacing = $runner->unplacing('The runner cannot place its tests.');
    $adapters = HoldingSuites::adapters(HoldingSuites::project(), HoldingSuites::checkout(), $unplacing);
    $inventory = Inventory::of($adapters, Flows::settings());

    expect($inventory instanceof Inventory ? SuiteCoverage::of($adapters, $inventory)->admitted(everySuitesMap()) : $inventory)
        ->toEqual(CannotJudge::because('The runner cannot place its tests.'));
});

it('admits a map as it is where every test file is a judging suite\'s and none is held from', function (): void {
    $map = everySuitesMap();
    $runner = new CoverageAsked(HoldingSuites::runner(TestListing::none(), TestListing::none(), $map), $map)
        ->unplacing('Asked to place a test.');
    $adapters = Flows::adapters(HoldingSuites::project(), [], HoldingSuites::checkout(), $runner);
    $inventory = Inventory::of($adapters, Flows::settings());

    expect($inventory instanceof Inventory ? SuiteCoverage::of($adapters, $inventory)->admitted($map) : $inventory)->toBe($map);
});

it('measures the whole suite among the judging suites, and the holding suites too where something is held from them', function (): void {
    [$holding] = suiteCoverage(everySuitesMap());
    [$unheld] = suiteCoverage(everySuitesMap(), holds: false);
    $run = CoverageRun::of(WholeSuite::tests(), Workspace::coverage());

    expect($holding->whole($run)->suites())->toEqual(Suites::listed('Unit', 'Process'))
        ->and($unheld->whole($run)->suites())->toEqual(Suites::listed('Unit'))
        ->and($holding->whole($run)->directory())->toEqual(Workspace::coverage());
});

it('measures again only the files of the listed suites, a holding suite\'s where something is held from it', function (): void {
    [$holding] = suiteCoverage(everySuitesMap());
    [$unheld] = suiteCoverage(everySuitesMap(), holds: false);
    $files = Paths::of(Path::of('tests/Arch/RulesTest.php'), Path::of('tests/Process/HeldTest.php'), Path::of('tests/Unit/MoneyTest.php'));

    expect($holding->measured($files))->toEqual(TestPaths::of(Paths::of(Path::of('tests/Unit/MoneyTest.php'), Path::of('tests/Process/HeldTest.php'))))
        ->and($unheld->measured($files))->toEqual(TestPaths::of(Paths::of(Path::of('tests/Unit/MoneyTest.php'))));
});

it('hands a run of a unit a hold from the holding suites holds the gate\'s map, admitted, and any other request as it is', function (): void {
    $project = HoldingSuites::project();
    [$suites, $runner] = suiteCoverage(everySuitesMap(), project: $project);
    $held = MutationRequest::of(Paths::of(Path::of('src/Held.php')), WholeSuite::tests());
    $money = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    $grouped = MutationRequest::of(Paths::of(Path::of('src/Held.php')), Group::named('holds:src/Held.php'));
    $handed = $suites->handing($held);
    $written = CoverageMapFile::decode(
        (string) file_get_contents(sprintf('%s/%s', $project, CoverageMapFile::in(Workspace::admittedCoverage())->value())),
        HandedMaps::limits(),
    );

    expect($handed instanceof MutationRequest ? $handed->coverage() : $handed)
        ->toEqual(Handed::maps(Workspace::admittedCoverage(), Workspace::admittedCoverage()))
        ->and($written)->toEqual($suites->admitted(everySuitesMap()))
        ->and($runner->asked())->toHaveCount(1)
        ->and($runner->asked()[0] ?? null)->toEqual($suites->whole(CoverageRun::of(WholeSuite::tests(), Workspace::suiteCoverage())))
        ->and($suites->handing($money))->toBe($money)
        ->and($suites->handing($grouped))->toBe($grouped)
        ->and($runner->asked())->toHaveCount(1);
});

it('cannot hand a run the gate\'s map where the suite cannot be measured, or the map cannot be written', function (): void {
    $project = HoldingSuites::project();
    $runner = new CoverageAsked(HoldingSuites::runner(TestListing::none(), processListing(holds: true), everySuitesMap()), CannotJudge::because('Pest failed.'));
    $adapters = HoldingSuites::adapters($project, HoldingSuites::checkout(), $runner);
    $inventory = Inventory::of($adapters, Flows::settings());
    $held = MutationRequest::of(Paths::of(Path::of('src/Held.php')), WholeSuite::tests());
    [$writable] = suiteCoverage(everySuitesMap(), project: $blocked = HoldingSuites::project());
    mkdir(sprintf('%s/%s', $blocked, CoverageMapFile::in(Workspace::admittedCoverage())->value()), recursive: true);

    expect($inventory instanceof Inventory ? SuiteCoverage::of($adapters, $inventory)->handing($held) : $inventory)
        ->toEqual(CannotJudge::because('Pest failed.'))
        ->and($writable->handing($held))->toBeInstanceOf(CannotJudge::class);
});

it('keeps no test of a suite no list names where no suite holds tests to judge only what they hold', function (): void {
    $map = everySuitesMap();
    $runner = new CoverageAsked(HoldingSuites::runner(TestListing::none(), TestListing::none(), $map), $map);
    $adapters = Flows::adapters(HoldingSuites::project(), [], HoldingSuites::checkout(), $runner)
        ->judgedAmong(JudgingSuites::judging(Suites::listed('Unit', 'Process')));
    $inventory = $adapters instanceof Adapters ? Inventory::of($adapters, Flows::settings()) : $adapters;

    expect($inventory instanceof Inventory ? SuiteCoverage::of($adapters, $inventory)->admitted($map) : $inventory)
        ->toEqual(CoverageMap::of(
            CoveredLine::of(Path::of('src/Held.php'), 4, HoldingSuites::ADDS, HoldingSuites::STARTS),
            CoveredLine::of(Path::of('src/Money.php'), 7, HoldingSuites::ADDS, HoldingSuites::STARTS),
            CoveredLine::of(Path::of('src/Money.php'), 9),
        )->timedEach(
            TimedTest::of(HoldingSuites::ADDS, 0.1),
            TimedTest::of(HoldingSuites::STARTS, 2.0),
        )->executing(Path::of('src/Held.php'), ExecutedMethod::of('start', 3, 5)));
});
