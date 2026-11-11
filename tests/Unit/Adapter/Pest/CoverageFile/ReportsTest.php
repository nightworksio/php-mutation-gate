<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\CoverageReports;

// Each report of the corpus is read into the map the reading wrote before it read each line's tests
// against the report's list once, byte for byte, and admitted into the map the suites admitted then.

it('reads each report into the map it read before', function (): void {
    foreach (CoverageReports::all() as $name => $directory) {
        expect(MapText::of(CoverageReports::map($directory))->written(Unplaced::map(), NotGiven::value()))
            ->toBe(CoverageReports::text($directory, 'pest.json'), $name);
    }
})->skip(fn(): bool => ! CoverageReports::areReadable(), 'the installed php-code-coverage reads another serialization format than the corpus');

it('admits each report as the suites admitted it before', function (): void {
    foreach (CoverageReports::admitting() as $name => $directory) {
        $map = CoverageReports::map($directory);

        expect(MapText::of(CoverageReports::admission($directory, MapOutline::of($map))->applied($map))->written(Unplaced::map(), NotGiven::value()))
            ->toBe(CoverageReports::text($directory, 'admitted.json'), $name);
    }
})->skip(fn(): bool => ! CoverageReports::areReadable(), 'the installed php-code-coverage reads another serialization format than the corpus');
