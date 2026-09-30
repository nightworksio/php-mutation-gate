<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\JUnitReportFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Report\JUnit;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes JUnit XML to the path its entry names', function (): void {
    $file = sprintf('%s/build/junit.xml', Scratch::directory());

    expect(JUnitReportFile::fromOptions(Configs::commandLine((string) json_encode(['path' => $file]))))->toEqual(JUnitReportFile::at($file))
        ->and(JUnitReportFile::at($file)->report(Verdicts::failing()))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(JUnit::xml(Verdicts::failing()));
});

it('needs a path', function (): void {
    expect(JUnitReportFile::fromOptions(Options::none()))->toEqual(Invalid::because(Problem::at(
        'path',
        'The JUnit report is written to a file, whose `path` the entry names.',
    )));
});
