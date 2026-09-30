<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\SarifReportFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Report\Sarif;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes SARIF to the path its entry names', function (): void {
    $file = sprintf('%s/build/mutation.sarif', Scratch::directory());

    expect(SarifReportFile::fromOptions(Options::ofJson((string) json_encode(['path' => $file]))))->toEqual(SarifReportFile::at($file))
        ->and(SarifReportFile::at($file)->report(Verdicts::failing()))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(Sarif::json(Verdicts::failing()));
});

it('needs a path', function (): void {
    expect(SarifReportFile::fromOptions(Options::none()))->toEqual(Invalid::because(Problem::at(
        'path',
        'The SARIF report is written to a file, whose `path` the entry names.',
    )));
});
