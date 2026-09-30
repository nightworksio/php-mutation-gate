<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\CodeQualityReportFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Report\CodeQuality;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes GitLab\'s Code Quality JSON to the path its entry names', function (): void {
    $file = sprintf('%s/build/gl-code-quality.json', Scratch::directory());

    expect(CodeQualityReportFile::fromOptions(Configs::commandLine((string) json_encode(['path' => $file]))))
        ->toEqual(CodeQualityReportFile::at($file))
        ->and(CodeQualityReportFile::at($file)->report(Verdicts::failing()))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(CodeQuality::json(Verdicts::failing()));
});

it('needs a path', function (): void {
    expect(CodeQualityReportFile::fromOptions(Options::none()))->toEqual(Invalid::because(Problem::at(
        'path',
        'The GitLab Code Quality report is written to a file, whose `path` the entry names.',
    )));
});
