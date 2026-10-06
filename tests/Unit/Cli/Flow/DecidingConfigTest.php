<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project whose config file holds this, read as the gate reads it, by the run that reads it from `mutation-gate.json`. */
$deciding = static function (string $config): array {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', $config);
    $effective = new Effective(
        $project,
        Registered::config(new Extensions(Origin::of('nightworksio/mutation-gate')), static fn(): bool => true),
        new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project))),
        new DateTimeImmutable(Configs::NOW),
    );

    return [$project, DecidingConfig::read($effective, CommandLine::nothing(), $project, Path::of('mutation-gate.json'))];
};

$changed = static fn(): Change => Change::modified(Path::of('mutation-gate.json'), Lines::of(Line::of(1)));

it('marks the config file deciding alike where its change moved no setting that affects results', function () use ($deciding, $changed): void {
    [$project, $config] = $deciding('{"runner": "pest", "trees": [{"path": "src", "floor": 90}]}');

    $sources = $config->marking(Sources::none(), $changed(), Contents::of('{"runner": "pest", "trees": [{"path": "src", "floor": 70}]}'));

    expect($sources->decidesAlike(Path::of('mutation-gate.json')))->toBeTrue()
        ->and(is_dir(sprintf('%s/.mutation-gate/base', $project)) ? scandir(sprintf('%s/.mutation-gate/base', $project)) : [])
        ->toBe(['.', '..']);
});

it('marks it under its old name too, where it was renamed', function () use ($deciding): void {
    [, $config] = $deciding('{"runner": "pest"}');

    $sources = $config->marking(
        Sources::none(),
        Change::renamed(Path::of('mutation-gate.yaml'), Path::of('mutation-gate.json'), Lines::none()),
        Contents::of("runner: pest\n"),
    );

    expect($sources->decidesAlike(Path::of('mutation-gate.json')))->toBeTrue()
        ->and($sources->decidesAlike(Path::of('mutation-gate.yaml')))->toBeTrue();
});

it('marks nothing where the change moved a setting that affects results, or the base cannot be read', function (string $before) use ($deciding, $changed): void {
    [, $config] = $deciding('{"runner": "pest", "trees": [{"path": "src"}]}');

    expect($config->marking(Sources::none(), $changed(), Contents::of($before))->decidesAlike(Path::of('mutation-gate.json')))
        ->toBeFalse();
})->with([
    'another tree' => ['{"runner": "pest", "trees": [{"path": "lib"}]}'],
    'not a config' => ['{"runner": 3}'],
    'not JSON' => ['{'],
]);

it('marks nothing for a file the run does not read its config from, one gone, or one with no base', function (Change $change, Contents|Missing $before) use ($deciding): void {
    [, $config] = $deciding('{"runner": "pest"}');

    expect($config->marking(Sources::none(), $change, $before)->decidesAlike($change->path()))->toBeFalse();
})->with([
    'another file' => [fn(): Change => Change::modified(Path::of('ci/gate.json'), Lines::none()), fn(): Contents => Contents::of('{"runner": "pest"}')],
    'deleted' => [fn(): Change => Change::deleted(Path::of('mutation-gate.json')), fn(): Contents => Contents::of('{"runner": "pest"}')],
    'new' => [fn(): Change => Change::added(Path::of('mutation-gate.json'), Lines::none()), fn(): Missing => Missing::at(Path::of('mutation-gate.json'))],
]);

it('marks nothing where the run reads no config file', function () use ($changed): void {
    expect(DecidingConfig::unread()->marking(Sources::none(), $changed(), Contents::of('{}'))->decidesAlike(Path::of('mutation-gate.json')))
        ->toBeFalse();
});
