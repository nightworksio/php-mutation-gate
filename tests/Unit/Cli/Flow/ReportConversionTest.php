<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\ReportConversion;
use NightWorksIO\MutationGate\Cli\Flow\SuiteCoverage;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\Coverage\PendingOutline;
use NightWorksIO\MutationGate\Core\Coverage\RecordedBeside;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\ConvertsPhpReports;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\MapEngine;
use NightWorksIO\MutationGate\Tests\Fakes\MapEngineFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Gunzipped;
use NightWorksIO\MutationGate\Tests\Support\HoldingSuites;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The holding-suites project with a PHP report a run left in build/suite, every test of each suite running
 * src/Held.php line 4, HeldTest, which holds it, where it runs it, and src/Money.php line 7.
 *
 * @return array{string, RanReport}
 */
function reported(bool $heldRuns = true): array
{
    $project = HoldingSuites::project();
    $tests = [HoldingSuites::ADDS, HoldingSuites::STARTS, HoldingSuites::RULES];
    Scratch::write($project, 'build/suite/.keep', '');
    CoverageMaps::write(
        sprintf('%s/build/suite/coverage.php', $project),
        sprintf('%s/', realpath($project)),
        ['src/Held.php' => [4 => $heldRuns ? [0, 1, 2] : [0, 2]], 'src/Money.php' => [7 => [0, 1, 2], 9 => [], 10 => null]],
        $tests,
        [HoldingSuites::ADDS => 0.1, HoldingSuites::STARTS => 2.0, HoldingSuites::RULES => 0.5],
    );
    $report = RanReport::at(
        sprintf('%s/build/suite/coverage.php', $project),
        4,
        RecordedBeside::nothing(),
        pestProject($project)->relative(...),
    );

    return [$project, $report];
}

/** The project as Pest's runner reads it. */
function pestProject(string $project): Project
{
    return Project::at($project, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
}

/** The gate's map of the report a run left, as Pest's reading builds it. */
function readByGate(string $project): CoverageMap
{
    $file = CoverageFile::at(sprintf('%s/build/suite/coverage.php', $project));

    return $file instanceof CoverageFile ? $file->map(pestProject($project)) : CoverageMap::of();
}

/**
 * What converting a project's report writes at a path, its runner naming the report or not, and the suites'
 * coverage. The conversion starts before the inventory is taken, as the command starts it.
 *
 * @return array{Closure(Path): (Written|NotWritten|CannotJudge), SuiteCoverage}
 */
function conversionOf(string $project, RanReport|NotGiven $report, MapEngine|NotGiven $engine = new NotGiven()): array
{
    $map = readByGate($project);
    $process = TestListing::of(TestIds::of(TestId::of(HoldingSuites::STARTS)))
        ->grouping(Group::named('holds:src/Held.php'), TestIds::of(TestId::of(HoldingSuites::STARTS)));
    $runner = new CoverageAsked(HoldingSuites::runner(TestListing::none(), $process, $map), $map);
    $adapters = HoldingSuites::adapters(
        $project,
        HoldingSuites::checkout(),
        $report instanceof RanReport ? $runner->reporting($report) : $runner,
        $engine instanceof MapEngine ? $engine : new MapEngineFake([], $project),
    );
    $started = ReportConversion::started($adapters, CoverageRan::in(Path::of('build/suite')));
    $inventory = Inventory::of($adapters, Flows::settings());
    $suites = $inventory instanceof Inventory ? SuiteCoverage::of($adapters, $inventory) : throw new LogicException($inventory->why());
    $written = static fn(Path $into): Written|NotWritten|CannotJudge => $started instanceof ReportConversion
        ? $started->written($suites, NotGiven::value(), $into, Unplaced::map())
        : $started;

    return [$written, $suites];
}

it('has the engine write the map of a run\'s report as the gate writes it, admitted as the suites admit it', function (): void {
    [$project, $report] = reported();
    [$conversion, $suites] = conversionOf($project, $report);
    $admitted = $suites->admitted(readByGate($project));

    expect($admitted)->toBeInstanceOf(CoverageMap::class)
        ->and($conversion(Path::of('.mutation-gate/map.json.gz')))
        ->toEqual(Written::to(sprintf('%s/.mutation-gate/map.json.gz', $project)))
        ->and(Gunzipped::of((string) file_get_contents(sprintf('%s/.mutation-gate/map.json.gz', $project))))
        ->toBe(MapText::of($admitted instanceof CoverageMap ? $admitted : CoverageMap::of())->written(Unplaced::map(), NotGiven::value()))
        ->and(sprintf('%s/.mutation-gate/converted/map.json.gz', $project))->not->toBeFile();
});

it('writes the engine\'s map where it was asked only as the gate writes its own, never out of the project', function (): void {
    [$project, $report] = reported();
    [$conversion] = conversionOf($project, $report);

    expect($conversion(Path::of('../out/map.json.gz')))
        ->toEqual(CannotJudge::because(sprintf('../out/map.json.gz leads out of %s, so the gate does not read or write it.', $project)))
        ->and(sprintf('%s/../out/map.json.gz', $project))->not->toBeFile()
        ->and(sprintf('%s/.mutation-gate/converted/map.json.gz', $project))->not->toBeFile();
});

it('writes nothing where the engine says it wrote a map the gate cannot read', function (): void {
    [$project, $report] = reported();
    $claiming = new readonly class ($project) implements ConvertsPhpReports, MapEngine {
        public function __construct(private string $root)
        {
        }

        public function vouches(Contents $text): MapVouch
        {
            return MapVouch::Unvouched;
        }

        public function split(Path $whole, MapSlices $slices): NotWritten
        {
            return NotWritten::because('No split.');
        }

        public function outlining(RanReport $report): PendingOutline
        {
            return new MapEngineFake([], $this->root)->outlining($report);
        }

        public function convert(RanReport $report, Conversion $conversion, Path $into): Written
        {
            return Written::to($into->value());
        }
    };
    [$conversion] = conversionOf($project, $report, $claiming);

    expect($conversion(Path::of('map.json.gz')))
        ->toEqual(NotWritten::because('The gate cannot read the map the engine wrote.'))
        ->and(sprintf('%s/map.json.gz', $project))->not->toBeFile();
});

it('writes nothing where the runner leaves no PHP report, the engine converts none, or it reads none', function (): void {
    [$project, $report] = reported();
    [$unreported] = conversionOf($project, NotGiven::value());
    [$unconverting] = conversionOf($project, $report, new readonly class implements MapEngine {
        public function vouches(Contents $text): MapVouch
        {
            return MapVouch::Unvouched;
        }

        public function split(Path $whole, MapSlices $slices): NotWritten
        {
            return NotWritten::because('No split.');
        }
    });
    unlink($report->file());
    [$unread] = conversionOf($project, $report);

    expect($unreported(Path::of('map.json.gz')))
        ->toEqual(NotWritten::because('The runner leaves no PHP report for an engine to read.'))
        ->and($unconverting(Path::of('map.json.gz')))
        ->toEqual(NotWritten::because('The map engine converts no PHP report.'))
        ->and($unread(Path::of('map.json.gz')))->toBeInstanceOf(NotWritten::class)
        ->and(sprintf('%s/map.json.gz', $project))->not->toBeFile();
});

it('cannot write the map where a hold from the holding suites runs no line of what it holds', function (): void {
    [$project, $report] = reported(heldRuns: false);
    [$conversion] = conversionOf($project, $report);

    expect($conversion(Path::of('map.json.gz')))
        ->toEqual(CannotJudge::because(<<<'SAID'
            holds:src/Held.php in the holding suites runs no line of src/Held.php, so it judges none of its mutants.
            Add the test that runs it to the group, or remove the hold.
            SAID))
        ->and(sprintf('%s/map.json.gz', $project))->not->toBeFile();
});
