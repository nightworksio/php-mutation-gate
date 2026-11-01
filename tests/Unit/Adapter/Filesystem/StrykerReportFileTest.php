<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\StrykerReportFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Sources;
use NightWorksIO\MutationGate\Core\Report\Stryker;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the verdict in the mutation-testing-report-schema format, with each mutated file\'s source, to its path', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    $file = sprintf('%s/build/mutation-report.json', $project);

    expect(StrykerReportFile::at($file, $project)->report(Verdicts::failing()))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(Stryker::json(
            Verdicts::failing(),
            Sources::none()->with(Path::of('src/Money.php'), Contents::of(Verdicts::MONEY)),
        ));
});

it('is configured from its entry\'s path, read from where the gate runs', function (): void {
    $file = sprintf('%s/build/mutation-report.json', Scratch::directory());

    expect(StrykerReportFile::fromOptions(Configs::commandLine((string) json_encode(['path' => $file]))))
        ->toEqual(StrykerReportFile::at($file, '.'));
});

it('needs a path', function (): void {
    expect(StrykerReportFile::fromOptions(Options::none()))->toEqual(Invalid::because(Problem::at(
        'path',
        'The Stryker report is written to a file, whose `path` the entry names.',
    )));
});

it('says where it could not write', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'build/mutation-report.json/in-the-way', '');
    $file = sprintf('%s/build/mutation-report.json', $root);

    expect(StrykerReportFile::at($file, $root)->report(Verdicts::failing()))
        ->toEqual(NotWritten::because(sprintf('%s could not be written.', $file)));
});
