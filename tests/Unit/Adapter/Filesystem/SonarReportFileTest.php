<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\SonarReportFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Sonar;
use NightWorksIO\MutationGate\Core\Report\Sources;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes SonarQube\'s generic issues, read against the project\'s files, and says how many under each top directory', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    $file = sprintf('%s/build/mutation-sonar.json', $project);
    $guide = Guide::ofInstalled('0.1.0');

    expect(SonarReportFile::at($file, $project, $guide)->report(Verdicts::failing()))
        ->toEqual(Written::noting($file, 'Issues by top directory: 5 under src.'))
        ->and(file_get_contents($file))->toBe(Sonar::json(
            Verdicts::failing(),
            Sources::none()->with(Path::of('src/Money.php'), Contents::of(Verdicts::MONEY)),
            $guide,
        ));
});

it('says only where it wrote a report with no issue', function (): void {
    $file = sprintf('%s/mutation-sonar.json', Scratch::directory());

    expect(SonarReportFile::at($file, Scratch::directory(), Guide::unreleased())->report(Verdicts::passing()))->toEqual(Written::to($file));
});

it('says why it could not write the report, and nothing of its issues', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'build', 'a file where the directory would be');

    expect(SonarReportFile::at(sprintf('%s/build/mutation-sonar.json', $project), $project, Guide::unreleased())->report(Verdicts::failing()))
        ->toBeInstanceOf(NotWritten::class);
});

it('takes the path its entry names, and needs one', function (): void {
    $guide = Guide::unreleased();

    expect(SonarReportFile::configured(Configs::commandLine('{"path": "build/mutation-sonar.json"}'), $guide))
        ->toEqual(SonarReportFile::at('build/mutation-sonar.json', '.', $guide))
        ->and(SonarReportFile::configured(Options::none(), $guide))->toEqual(Invalid::because(Problem::at(
            'path',
            'The SonarQube report is written to a file, whose `path` the entry names.',
        )));
});
