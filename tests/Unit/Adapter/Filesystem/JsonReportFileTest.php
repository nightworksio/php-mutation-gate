<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\JsonReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\JUnitReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\SarifReportFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Report\EncodedReport;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

beforeEach(function (): void {
    Scratch::entered();
});

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
        ->and(SarifReportFile::configured(Options::none(), Guide::unreleased()))->toBeInstanceOf(Invalid::class);
});

it('writes the text of the JSON report it is handed, building none of its own', function (): void {
    $file = sprintf('%s/build/mutation.json', Scratch::directory());

    expect(JsonReportFile::at($file)->reportFrom(Verdicts::failing(), EncodedReport::of(Verdicts::passing())))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(JsonReport::encode(Verdicts::passing()));
});

it('empties the lists of tests its entry leaves out of each mutant, adding none a mutant does not write', function (string $tests, array $first, array $second): void {
    $file = sprintf('%s/build/mutation.json', Scratch::directory());
    $reporter = JsonReportFile::fromOptions(Configs::commandLine((string) json_encode(['path' => $file, 'tests' => $tests])));
    $written = $reporter instanceof JsonReportFile ? $reporter->report(Verdicts::failing()) : $reporter;
    $mutants = Node::decode((string) file_get_contents($file))->field('mutants')->items();
    $listed = static fn(Node $mutant): array => array_map(
        static fn(Node $list): array => $list->integers(),
        array_intersect_key($mutant->entries(), array_flip(['coveredBy', 'judgedBy', 'killedBy', 'failingUnmutated'])),
    );

    expect($written)->toEqual(Written::to($file))
        ->and($listed($mutants[0]))->toBe($first)
        ->and($listed($mutants[1]))->toBe($second);
})->with([
    'all' => ['all', ['coveredBy' => [], 'judgedBy' => [0], 'killedBy' => []], ['coveredBy' => [0], 'killedBy' => [0]]],
    'killers' => ['killers', ['coveredBy' => [], 'judgedBy' => [], 'killedBy' => []], ['coveredBy' => [], 'killedBy' => [0]]],
    'none' => ['none', ['coveredBy' => [], 'judgedBy' => [], 'killedBy' => []], ['coveredBy' => [], 'killedBy' => []]],
]);
