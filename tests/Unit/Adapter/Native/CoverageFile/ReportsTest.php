<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\CoverageReports;

// Each report of the corpus is read into the map the reading wrote before it read each line's tests
// against the report's list once, byte for byte.

it('reads each report into the map it read before', function (): void {
    $project = Project::at(CoverageReports::ROOT, Paths::none(), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit);

    foreach (CoverageReports::all() as $name => $directory) {
        $map = CoverageFile::read($project, sprintf('%s/coverage.php', $directory));

        expect(MapText::of($map instanceof CoverageMap ? $map : CoverageMap::of())->written(Unplaced::map(), NotGiven::value()))
            ->toBe(CoverageReports::text($directory, 'native.json'), $name);
    }
})->skip(fn(): bool => ! CoverageReports::areReadable(), 'the installed php-code-coverage reads another serialization format than the corpus');
