<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A config file, in the directory it is in. */
$file = static fn(string $path): ConfigFile => ConfigFile::at(Path::of($path), Path::of(dirname($path)));

/** @return array<mixed> what a YAML file reads into, or every problem in it */
$read = static function (string $yaml) use ($file): array {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.yaml', $yaml);
    $layer = new YamlConfig()->load($file(sprintf('%s/mutation-gate.yaml', $project)));
    $read = $layer instanceof Layer ? Configs::decoded($layer) : Configs::problems($layer);

    return is_array($read) ? $read : [];
};

it('reads a date without quotes as the day it names', function () use ($read): void {
    expect($read(<<<'YAML'
        ignores:
          entries:
            - mutant: 3f9a1c2b7d04
              reason: Equivalent
              expires: 2027-03-31
        YAML))->toBe(['ignores' => ['entries' => [
        ['mutant' => '3f9a1c2b7d04', 'reason' => 'Equivalent', 'expires' => '2027-03-31'],
    ]]]);
});

it('names an unquoted id as what YAML read it as', function () use ($read): void {
    expect($read(<<<'YAML'
        ignores:
          entries:
            - mutant: 12e456789012
              reason: Equivalent
            - mutant: 123456789012
              reason: Equivalent
        YAML))->toBe([
        'ignores.entries[0].mutant: expected a mutant id, twelve lowercase hex characters in quotes, got "INF"',
        'ignores.entries[1].mutant: expected a mutant id, twelve lowercase hex characters in quotes, got 123456789012',
    ]);
});

it('cannot judge a file that is not YAML, naming it', function () use ($file): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.yaml', "runner: [pest\n");
    $path = sprintf('%s/mutation-gate.yaml', $project);
    $loaded = new YamlConfig()->load($file($path));

    expect($loaded instanceof CannotJudge ? $loaded->why() : '')->toStartWith(sprintf('%s is not YAML: ', $path));
});

it('cannot judge a file that is not there', function () use ($file): void {
    expect(new YamlConfig()->load($file('/nowhere/mutation-gate.yaml')))
        ->toEqual(CannotJudge::because('/nowhere/mutation-gate.yaml could not be read.'));
});

it('writes a config as YAML that reads back as the same config', function () use ($read): void {
    $config = [
        'runner' => ['use' => 'Acme\\Runner', 'with' => ['workers' => 4]],
        'trees' => [['path' => 'src', 'floor' => 83.5]],
        'costs' => ['secondsPerLine' => ['' => 0.2]],
        'ignores' => ['entries' => [['mutant' => '123456789012', 'reason' => 'Equivalent', 'expires' => '2027-03-31']]],
    ];
    $layer = Configs::layer($config);
    $pest = Configs::layer(['runner' => 'pest']);

    expect($pest instanceof Layer ? new YamlConfig()->render($pest->written(ProjectRoot::origin())) : $pest)
        ->toBe("runner: pest\n")
        ->and($read($layer instanceof Layer ? new YamlConfig()->render($layer->written(ProjectRoot::origin())) : ''))
        ->toBe($config);
});

it('writes ten levels of a config as blocks, and only what lies deeper on one line', function (): void {
    $nested = Json::at(implode('.', array_map(static fn(int $level): string => sprintf('l%d', $level), range(1, 11))), 1);
    $blocks = implode('', array_map(
        static fn(int $level): string => sprintf("%sl%d:\n", str_repeat('  ', $level - 1), $level),
        range(1, 9),
    ));

    expect(new YamlConfig()->render($nested))->toBe(sprintf("%s%sl10: { l11: 1 }\n", $blocks, str_repeat('  ', 9)));
});

it('reads no other file, as the gate\'s YAML names none', function () use ($file): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.yaml', "runner: pest\n");

    expect(new YamlConfig()->reads($file(sprintf('%s/mutation-gate.yaml', $project))))->toEqual(ConfigReads::none());
});
