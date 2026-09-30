<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads a date without quotes as the day it names, and an unquoted id as what it was read as', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.yaml', <<<'YAML'
        ignores:
          entries:
            - mutant: 12e456789012
              reason: Equivalent
              expires: 2027-03-31
            - mutant: 123456789012
              reason: Equivalent
        YAML);
    $document = new YamlConfig()->load(Path::of(sprintf('%s/mutation-gate.yaml', $project)));

    expect($document instanceof Document ? json_decode($document->json(), associative: true) : $document)->toBe([
        'ignores' => ['entries' => [
            ['mutant' => 'INF', 'reason' => 'Equivalent', 'expires' => '2027-03-31'],
            ['mutant' => 123456789012, 'reason' => 'Equivalent'],
        ]],
    ]);
});

it('cannot judge a file that is not YAML, naming it', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.yaml', "runner: [pest\n");
    $file = sprintf('%s/mutation-gate.yaml', $project);
    $loaded = new YamlConfig()->load(Path::of($file));

    expect($loaded instanceof CannotJudge ? $loaded->why() : '')->toStartWith(sprintf('%s is not YAML: ', $file));
});

it('cannot judge a file that is not there', function (): void {
    expect(new YamlConfig()->load(Path::of('/nowhere/mutation-gate.yaml')))
        ->toEqual(CannotJudge::because('/nowhere/mutation-gate.yaml could not be read.'));
});

it('writes a config as YAML that reads back as the same config', function (): void {
    $config = [
        'runner' => ['use' => 'Acme\\Runner', 'with' => ['workers' => 4]],
        'trees' => [['path' => 'src', 'floor' => 83.5]],
        'ignores' => ['entries' => [['mutant' => '123456789012', 'reason' => 'Equivalent', 'expires' => '2027-03-31']]],
        'costs' => ['secondsPerLine' => ['' => 0.2]],
        'extensions' => [],
    ];
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.yaml', new YamlConfig()->render(Configs::document($config)));
    $read = new YamlConfig()->load(Path::of(sprintf('%s/mutation-gate.yaml', $project)));

    expect(new YamlConfig()->render(Configs::document(['runner' => 'pest'])))->toBe("runner: pest\n")
        ->and($read instanceof Document ? json_decode($read->json(), associative: true) : $read)->toBe($config);
});
