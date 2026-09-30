<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\JsonReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\JUnitReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\SarifReportFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the JSON report to the path its entry names', function (): void {
    $file = sprintf('%s/build/mutation.json', Scratch::directory());
    $reporter = JsonReportFile::fromOptions(Configs::commandLine((string) json_encode(['path' => $file])));

    expect($reporter)->toEqual(JsonReportFile::at($file))
        ->and(JsonReportFile::at($file)->report(Verdicts::failing()))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(JsonReport::encode(Verdicts::failing()));
});

it('needs a path', function (): void {
    expect(JsonReportFile::fromOptions(Options::none()))->toEqual(Invalid::because(Problem::at(
        'path',
        'The JSON report is written to a file, whose `path` the entry names.',
    )))
        ->and(JUnitReportFile::fromOptions(Options::none()))->toBeInstanceOf(Invalid::class)
        ->and(SarifReportFile::fromOptions(Options::none()))->toBeInstanceOf(Invalid::class);
});
