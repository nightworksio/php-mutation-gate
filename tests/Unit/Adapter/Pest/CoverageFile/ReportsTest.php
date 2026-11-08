<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\TimedTests;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\CoverageReports;

// Each report of the corpus is read into the map the reading wrote before it read each line's tests
// against the report's list once, byte for byte, and admitted into the map the suites admitted then.

/** The map Pest's reading builds of a report, with what the run timed beside it. */
$read = static function (string $directory): CoverageMap {
    $file = CoverageFile::at(sprintf('%s/coverage.php', $directory));
    $project = Project::at(CoverageReports::ROOT, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));

    return TimedTests::onto(
        $file instanceof CoverageFile ? $file->map($project) : CoverageMap::of(),
        sprintf('%s/timed-tests.jsonl', $directory),
    );
};

it('reads each report into the map it read before', function () use ($read): void {
    foreach (CoverageReports::all() as $name => $directory) {
        expect(MapText::of($read($directory))->written(Unplaced::map(), NotGiven::value()))
            ->toBe(CoverageReports::text($directory, 'pest.json'), $name);
    }
});

it('admits each report as the suites admitted it before', function () use ($read): void {
    foreach (CoverageReports::admitting() as $name => $directory) {
        expect(MapText::of(CoverageReports::admission($directory)->applied($read($directory)))->written(Unplaced::map(), NotGiven::value()))
            ->toBe(CoverageReports::text($directory, 'admitted.json'), $name);
    }
});
