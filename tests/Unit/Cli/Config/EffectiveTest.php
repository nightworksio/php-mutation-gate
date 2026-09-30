<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Given;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Origin;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/** The effective config of a project, with this package's own extension, and YAML and NEON installed or not. */
$effective = static fn(string $project, bool $installed = true): Effective => new Effective(
    $project,
    Registered::config(new Extensions(Origin::of('nightworksio/mutation-gate')), static fn(): bool => $installed),
    new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project))),
    new DateTimeImmutable(Configs::NOW),
);

/** Nothing said on the command line. */
$nothing = static fn(): Given => new Given('', '', [], '', '', firstPartyOnly: false);

/** @return array<mixed> */
$shown = static fn(Settings|Invalid|CannotJudge $settings): array => $settings instanceof Settings
    ? (static fn(mixed $shown): array => is_array($shown) ? $shown : [])(
        json_decode($settings->effective(), associative: true),
    )
    : ['not settings' => Configs::problems($settings)];

it('finds the preset and the runner of a project with no config', function () use (
    $effective,
    $nothing,
    $shown,
): void {
    $settings = $effective(Tree::at('tests/Fixtures/Projects/Laravel'))->settings($nothing());

    expect($shown($settings))->toMatchArray([
        'preset' => 'laravel',
        'runner' => 'pest',
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]],
        'reach' => ['everything' => ['bootstrap/**', 'config/**', 'routes/**', '.env.testing']],
        'timeouts' => ['mode' => 'confirm', 'seconds' => 30, 'retries' => 20],
    ]);
});

it('lays the config file over its presets, and the command line over both', function () use (
    $effective,
    $shown,
): void {
    $settings = $effective(Tree::at('tests/Fixtures/Projects/Configured'))
        ->settings(new Given('', 'pest', ['json:build/mutation.json'], '5m', 'github', firstPartyOnly: false));

    expect($shown($settings))->toMatchArray([
        '$schema' => 'vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json',
        'preset' => 'symfony',
        'runner' => 'pest',
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['src']]],
        'trees' => [['path' => 'src/Domain', 'floor' => 100]],
        'reach' => ['everything' => ['config/**', '.env.test', 'tests/bootstrap.php', 'migrations/**']],
        'budget' => '5m',
        'timeouts' => ['mode' => 'confirm', 'seconds' => 30, 'retries' => 20],
        'reports' => [
            ['use' => 'sarif', 'path' => 'build/mutation.sarif'],
            ['use' => 'json', 'path' => 'build/mutation.json'],
        ],
    ])->and($settings instanceof Settings ? $settings->ci()->plan() : $settings)
        ->toEqual(Choice::of('github', '{}'));
});

it('takes the runner from the config file before looking for one', function () use ($effective, $nothing): void {
    $settings = $effective(Tree::at('tests/Fixtures/Projects/Configured'))->settings($nothing());

    expect($settings instanceof Settings ? $settings->runner() : $settings)->toEqual(Choice::of('infection', '{}'));
});

it('applies a list of presets in order, the later winning', function () use (
    $effective,
    $nothing,
    $shown,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"preset": ["symfony", "laravel"], "runner": "pest"}');

    expect($shown($effective($project)->settings($nothing())))->toMatchArray([
        'preset' => ['symfony', 'laravel'],
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]],
        'reach' => ['everything' => [
            'config/**',
            '.env.test',
            'tests/bootstrap.php',
            'bootstrap/**',
            'routes/**',
            '.env.testing',
        ]],
    ]);
});

it('chooses the library preset for a project without composer.json', function () use (
    $effective,
    $nothing,
    $shown,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.yaml', "runner: infection\n");

    expect($shown($effective($project)->settings($nothing())))->toMatchArray([
        'preset' => 'library',
        'runner' => 'infection',
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => []]],
        'timeouts' => ['mode' => 'confirm', 'seconds' => 10, 'retries' => 20],
    ]);
});

it('reports every problem of an invalid config', function () use ($effective, $nothing): void {
    expect(Configs::problems($effective(Tree::at('tests/Fixtures/Projects/Invalid'))->settings($nothing())))->toBe([
        'trees[0].floor: expected a number from 0 to 100, got "80"',
        'newcode: unknown key, did you mean newCode?',
    ]);
});

it('cannot judge what the project leaves undecided', function (string $project, Given $given, string $why) use (
    $effective,
): void {
    $root = Tree::at(sprintf('tests/Fixtures/Projects/%s', $project));

    expect($effective($root, installed: false)->settings($given))->toEqual(CannotJudge::because(sprintf($why, $root)));
})->with([
    'two config files' => [
        'TwoConfigs',
        new Given('', '', [], '', '', firstPartyOnly: false),
        'More than one config file is here: mutation-gate.json, mutation-gate.yaml. '
        . 'Keep one, or name one with --config.',
    ],
    'two runners' => [
        'TwoRunners',
        new Given('', '', [], '', '', firstPartyOnly: false),
        'Both pestphp/pest-plugin-mutate and infection/infection are installed. '
        . 'Choose one: set runner in the config, or pass --runner.',
    ],
    'a YAML config without its library' => [
        'TwoConfigs',
        new Given('mutation-gate.yaml', '', [], '', '', firstPartyOnly: false),
        '%s/mutation-gate.yaml is YAML, which needs symfony/yaml to be read. '
        . 'Install it: composer require --dev symfony/yaml',
    ],
]);

it('lets the command line choose the runner where two are installed', function () use ($effective): void {
    $settings = $effective(Tree::at('tests/Fixtures/Projects/TwoRunners'))
        ->settings(new Given('', 'infection', [], '', '', firstPartyOnly: false));

    expect($settings instanceof Settings ? $settings->runner() : $settings)->toEqual(Choice::of('infection', '{}'));
});

it('cannot judge with a preset nothing registered', function () use ($effective, $nothing): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"preset": "larvel", "runner": "pest"}');

    expect($effective($project)->settings($nothing()))
        ->toEqual(CannotJudge::because('No preset is registered as "larvel".'));
});

it('cannot judge a config file it cannot read', function () use ($effective, $nothing): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"runner": ');

    expect($effective($project)->settings($nothing()))->toEqual(CannotJudge::because(sprintf(
        '%s/mutation-gate.json: A config was read into text that is not JSON: Syntax error.',
        $project,
    )));
});

it('loads the extensions the config file names, unless told to load none', function () use ($effective): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', (string) json_encode([
        'extensions' => [ExtensionFake::class, 'Acme\\Missing\\Extension'],
        'runner' => 'pest',
    ]));

    expect($effective($project)->settings(new Given('', '', [], '', '', firstPartyOnly: true)))
        ->toBeInstanceOf(Settings::class)
        ->and($effective($project)->settings(new Given('', '', [], '', '', firstPartyOnly: false)))
        ->toEqual(CannotJudge::because(
            'the config file names Acme\\Missing\\Extension in extensions, and it is not a class that implements '
            . 'NightWorksIO\\MutationGate\\Extension\\Extension.',
        ));
});

it('reads the config file --config names', function () use ($effective, $shown): void {
    $project = Scratch::directory();
    Scratch::write($project, 'ci/gate.neon', "runner: pest\nbudget: 90s\n");

    expect($shown($effective($project)->settings(new Given('ci/gate.neon', '', [], '', '', firstPartyOnly: false))))
        ->toMatchArray(['runner' => 'pest', 'budget' => '90s']);
});
