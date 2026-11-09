<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\RecordedBeside;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\RanReport;

it('names the report, the format its runner reads, what was recorded beside it, and each file\'s path', function (): void {
    $recorded = RecordedBeside::nothing();
    $report = RanReport::at(
        '/project/.mutation-gate/coverage.php',
        4,
        $recorded,
        static fn(string $file): Path => Path::of(substr($file, strlen('/project/'))),
    );

    expect($report->file())->toBe('/project/.mutation-gate/coverage.php')
        ->and($report->format())->toBe(4)
        ->and($report->recorded())->toBe($recorded)
        ->and($report->relative('/project/src/A.php'))->toEqual(Path::of('src/A.php'));
});
