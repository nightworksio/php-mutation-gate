<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Tests\Support\Commands;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A config as printed, read back by the loader of its format into the layer every format reads into.
 *
 * @return array<mixed>
 */
$read = static function (string $format, string $printed, ConfigLoader $loader): array {
    $project = Scratch::directory();
    Scratch::write($project, sprintf('mutation-gate.%s', $format), $printed);
    $layer = $loader->load(
        ConfigFile::at(Path::of(sprintf('%s/mutation-gate.%s', $project, $format)), Path::of($project)),
    );
    $read = $layer instanceof Layer ? Configs::decoded($layer) : Configs::problems($layer);

    return is_array($read) ? $read : [];
};

/**
 * `config:show` run in a fixture project.
 *
 * @param array<string, mixed> $input
 */
$show = static fn(string $fixture, array $input = []): Commands => Commands::run(
    Tree::at(sprintf('tests/Fixtures/Projects/%s', $fixture)),
    'config:show',
    $input,
);

/** @return array<mixed> */
$decoded = static fn(string $json): array => (static fn(mixed $tree): array => is_array($tree) ? $tree : [])(
    json_decode($json, associative: true),
);

it('prints the effective config as JSON, every setting with its value', function () use ($show): void {
    $shown = $show('Configured');
    $settings = Configs::settings([
        'preset' => 'symfony',
        'runner' => ['use' => 'infection', 'memory' => '1G'],
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['src']]],
        'trees' => [['path' => 'src/Domain', 'floor' => 100]],
        'budget' => '15m',
        'reach' => ['everything' => ['config/**', '.env.test', 'tests/bootstrap.php', 'migrations/**']],
        'timeouts' => ['seconds' => 30],
        'reports' => [['use' => 'sarif', 'path' => 'build/mutation.sarif']],
        'mutators' => ['sets' => ['symfony', 'security']],
    ]);

    expect([$shown->code, $shown->output, $shown->errors])->toBe([0, sprintf("%s\n", Configs::effective($settings)), '']);
});

it('lays the command line over the config file', function () use ($show, $decoded): void {
    $shown = $show('Configured', ['--runner' => 'pest', '--report' => ['json:build/mutation.json']]);

    expect($decoded($shown->output))->toMatchArray([
        'runner' => ['use' => 'pest', 'memory' => '1G'],
        'reports' => [
            ['use' => 'sarif', 'path' => 'build/mutation.sarif'],
            ['use' => 'json', 'path' => 'build/mutation.json'],
        ],
    ]);
});

it('shows zero-config where there is no config file', function () use ($show, $decoded): void {
    expect($decoded($show('Laravel')->output))->toMatchArray([
        'preset' => 'laravel',
        'runner' => ['use' => 'pest', 'memory' => '1G'],
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]],
    ]);
});

it('prints the effective config in the format --format names', function () use ($show, $decoded, $read): void {
    $json = $show('Configured')->output;
    $yaml = $show('Configured', ['--format' => 'yaml'])->output;
    $neon = $show('Configured', ['--format' => 'neon'])->output;
    $php = $show('Configured', ['--format' => 'php'])->output;

    expect($read('yaml', $yaml, new YamlConfig()))->toBe($decoded($json))
        ->and($read('neon', $neon, new NeonConfig()))->toBe($decoded($json))
        ->and($php)->toStartWith("<?php\n\ndeclare(strict_types=1);\n")
        ->and($php)->toContain(
            "use NightWorksIO\\MutationGate\\Core\\Runner\\MemoryCap;\n"
            . "use NightWorksIO\\MutationGate\\Core\\Runner\\MemoryUnit;\n",
        )
        ->and($php)->toContain(
            "    ->preset(Preset::symfony())\n"
            . "    ->runner(Runner::infection()->cappedAt(MemoryCap::of(1, MemoryUnit::Gigabytes)))\n",
        );
});

it('prints every problem of an invalid config, each on a line of its own, and exits 2', function () use ($show): void {
    $shown = $show('Invalid');

    expect([$shown->code, $shown->output, $shown->errors])->toBe([
        2,
        '',
        "trees[0].floor: expected a number from 0 to 100, got \"80\"\nnewcode: unknown key, did you mean newCode?\n",
    ]);
});

it('says why it cannot show a config, and exits 2', /** @param array<string, mixed> $input */ function (
    string $fixture,
    array $input,
    string $why,
) use (
    $show,
): void {
    $shown = $show($fixture, $input);

    $laravel = Path::of(Tree::at('tests/Fixtures/Projects/Laravel'))->value();

    expect([$shown->code, $shown->output, $shown->errors])->toBe([2, '', sprintf("%s\n", sprintf($why, $laravel))]);
})->with([
    'a format it does not know' => [
        'Configured',
        ['--format' => 'toml'],
        '--format is php, json, yaml or neon, not "toml".',
    ],
    'a config file that is not there' => [
        'Laravel',
        ['--config' => 'missing.json'],
        '%s/missing.json could not be read.',
    ],
    'two config files' => [
        'TwoConfigs',
        [],
        'More than one config file is here: mutation-gate.json, mutation-gate.yaml. '
        . 'Keep one, or name one with --config.',
    ],
]);
