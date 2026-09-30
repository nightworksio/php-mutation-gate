<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\TestsReportFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\TestsReport;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Killings;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the tests report as JSON at its path, and as Markdown beside it', function (): void {
    $root = Scratch::directory();
    $verdict = Killings::verdict(MatrixKind::Full);
    $options = Options::ofJson((string) json_encode(['path' => sprintf('%s/build/tests.json', $root)]));

    expect(TestsReportFile::fromOptions($options))->toEqual(TestsReportFile::at(sprintf('%s/build/tests.json', $root)))
        ->and(TestsReportFile::at(sprintf('%s/build/tests.json', $root))->report($verdict))->toEqual(Written::to(sprintf('%s/build/tests.md', $root)))
        ->and(file_get_contents(sprintf('%s/build/tests.json', $root)))->toBe(TestsReport::json($verdict))
        ->and(file_get_contents(sprintf('%s/build/tests.md', $root)))->toBe(TestsReport::markdown($verdict));
});

it('puts the Markdown twin beside a path that does not end in .json', function (): void {
    $root = Scratch::directory();

    expect(TestsReportFile::at(sprintf('%s/tests-report', $root))->report(Killings::verdict(MatrixKind::Full)))->toEqual(Written::to(sprintf('%s/tests-report.md', $root)))
        ->and(file_exists(sprintf('%s/tests-report', $root)))->toBeTrue();
});

it('says why it could not write either file', function (): void {
    $root = Scratch::directory();
    mkdir(sprintf('%s/taken.json', $root));
    mkdir(sprintf('%s/twin.md', $root));

    expect(TestsReportFile::at(sprintf('%s/taken.json', $root))->report(Killings::verdict(MatrixKind::Full)))
        ->toEqual(NotWritten::because(sprintf('%s/taken.json could not be written.', $root)))
        ->and(TestsReportFile::at(sprintf('%s/twin.json', $root))->report(Killings::verdict(MatrixKind::Full)))
        ->toEqual(NotWritten::because(sprintf('%s/twin.md could not be written.', $root)));
});

it('needs a path', function (): void {
    expect(TestsReportFile::fromOptions(Options::none()))->toEqual(Invalid::because(Problem::at(
        'path',
        'The tests report is written to a file, whose `path` the entry names.',
    )));
});
