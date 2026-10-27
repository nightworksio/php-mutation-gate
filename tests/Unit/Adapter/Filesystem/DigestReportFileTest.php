<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\DigestReportFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Report\Digest\Digest;
use NightWorksIO\MutationGate\Core\Report\Digest\SurvivorsShown;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the digest\'s overview, rendered from the JSON report, to the path its entry names', function (): void {
    $file = sprintf('%s/build/mutation-gate-digest.txt', Scratch::directory());

    expect(DigestReportFile::fromOptions(Configs::commandLine((string) json_encode(['path' => $file]))))->toEqual(DigestReportFile::at($file))
        ->and(DigestReportFile::at($file)->report(Verdicts::failing()))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(Digest::overview(Node::decode(JsonReport::encode(Verdicts::failing())), SurvivorsShown::first(), GateCommand::installed()));
});

it('needs a path', function (): void {
    expect(DigestReportFile::fromOptions(Options::none()))->toEqual(Invalid::because(Problem::at(
        'path',
        'The digest is written to a file, whose `path` the entry names.',
    )));
});
