<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A config file, in the directory it is in. */
$file = static fn(string $path): ConfigFile => ConfigFile::at(Path::of($path), Path::of(dirname($path)));

/** @return array<mixed> what a NEON file reads into, or every problem in it */
$read = static function (string $yaml) use ($file): array {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.neon', $yaml);
    $layer = new NeonConfig()->load($file(sprintf('%s/mutation-gate.neon', $project)));
    $read = $layer instanceof Layer ? Configs::decoded($layer) : Configs::problems($layer);

    return is_array($read) ? $read : [];
};

it('reads a date as the day it names', function () use ($read): void {
    expect($read(<<<'NEON'
        ignores:
            entries:
                - {mutant: '3f9a1c2b7d04', reason: Equivalent, expires: 2027-03-31}
        NEON))->toBe(['ignores' => ['entries' => [
        ['mutant' => '3f9a1c2b7d04', 'reason' => 'Equivalent', 'expires' => '2027-03-31'],
    ]]]);
});

it('refuses an entity, which is not data', function () use ($file): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.neon', "runner: Pest(fast)\n");
    $path = sprintf('%s/mutation-gate.neon', $project);

    expect(new NeonConfig()->load($file($path)))->toEqual(CannotJudge::because(sprintf(
        '%s holds an object, Nette\\Neon\\Entity, and a config holds data only.',
        $path,
    )));
});

it('cannot judge a file that is not NEON, naming it', function () use ($file): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.neon', "runner: [pest\n");
    $path = sprintf('%s/mutation-gate.neon', $project);
    $loaded = new NeonConfig()->load($file($path));

    expect($loaded instanceof CannotJudge ? $loaded->why() : '')->toStartWith(sprintf('%s is not NEON: ', $path));
});

it('cannot judge a file that is not there', function () use ($file): void {
    expect(new NeonConfig()->load($file('/nowhere/mutation-gate.neon')))
        ->toEqual(CannotJudge::because('/nowhere/mutation-gate.neon could not be read.'));
});

it('writes a config as NEON that reads back as the same config', function () use ($read): void {
    $config = [
        'runner' => ['use' => 'Acme\\Runner', 'with' => ['workers' => 4]],
        'trees' => [['path' => 'src', 'floor' => 83.5]],
        'costs' => ['secondsPerLine' => ['' => 0.2]],
        'ignores' => ['entries' => [['mutant' => '123456789012', 'reason' => 'Equivalent', 'expires' => '2027-03-31']]],
    ];
    $layer = Configs::layer($config);
    $pest = Configs::layer(['runner' => 'pest']);

    expect($pest instanceof Layer ? new NeonConfig()->render($pest->written(ProjectRoot::origin())) : $pest)
        ->toBe("runner: pest\n")
        ->and($read($layer instanceof Layer ? new NeonConfig()->render($layer->written(ProjectRoot::origin())) : ''))
        ->toBe($config);
});
