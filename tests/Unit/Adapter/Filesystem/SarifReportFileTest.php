<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\SarifReportFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Report\Sarif;
use NightWorksIO\MutationGate\Core\Report\SourceRoot;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes SARIF to the path its entry names, naming no root in CI', function (): void {
    $file = sprintf('%s/build/mutation.sarif', Scratch::directory());
    $options = Configs::commandLine((string) json_encode(['path' => $file]));

    expect(Environment::during(['CI' => 'true'], static fn(): object => SarifReportFile::configured($options, Guide::unreleased())))->toEqual(SarifReportFile::at($file, Guide::unreleased()))
        ->and(SarifReportFile::at($file, Guide::unreleased())->report(Verdicts::failing()))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(Sarif::json(Verdicts::failing(), Guide::unreleased()));
});

it('names the directory the run started in as the root outside CI, for an editor', function (): void {
    $file = sprintf('%s/mutation.sarif', Scratch::directory());
    $options = Configs::commandLine((string) json_encode(['path' => $file]));
    $rooted = SarifReportFile::rootedAt($file, (string) getcwd(), Guide::unreleased());

    expect(Environment::during(['CI' => null], static fn(): object => SarifReportFile::configured($options, Guide::unreleased())))->toEqual($rooted)
        ->and(Environment::during(['CI' => ''], static fn(): object => SarifReportFile::configured($options, Guide::unreleased())))->toEqual($rooted);
});

it('writes the root it names into the report', function (): void {
    $file = sprintf('%s/mutation.sarif', Scratch::directory());

    expect(SarifReportFile::rootedAt($file, '/work/gate', Guide::unreleased())->report(Verdicts::failing()))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(Sarif::rootedAt(Verdicts::failing(), SourceRoot::at('/work/gate'), Guide::unreleased()));
});

it('needs a path', function (): void {
    expect(SarifReportFile::configured(Options::none(), Guide::unreleased()))->toEqual(Invalid::because(Problem::at(
        'path',
        'The SARIF report is written to a file, whose `path` the entry names.',
    )));
});
