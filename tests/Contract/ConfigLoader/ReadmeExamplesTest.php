<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// The README's Configuration section shows one config in four formats. Each
// reads into the same effective config.

afterEach(function (): void {
    Scratch::sweep();
});

/** @return array<string, string> the example of each format, by its format */
$examples = static function (): array {
    $readme = (string) file_get_contents(Tree::at('README.md'));
    $configuration = (string) strstr($readme, '## Configuration');
    $section = (string) strstr($configuration, '### Configuration reference', before_needle: true);
    preg_match_all('/^```(php|json|yaml|neon)\n(.*?)^```$/ms', $section, $blocks, PREG_SET_ORDER);
    $examples = [];

    foreach ($blocks as $block) {
        $examples[$block[1]] = $block[2];
    }

    return $examples;
};

/** @return array<mixed> the effective config an example reads into */
$effective = static function (string $format, string $example, ConfigLoader $loader): array {
    $project = Scratch::directory();
    Scratch::write($project, sprintf('mutation-gate.%s', $format), $example);
    $layer = $loader->load(
        ConfigFile::at(Path::of(sprintf('%s/mutation-gate.%s', $project, $format)), Path::of($project)),
    );
    $settings = $layer instanceof Layer ? Settings::settled($layer, new DateTimeImmutable(Configs::NOW)) : $layer;
    $shown = $settings instanceof Settings ? Configs::decoded($settings->effective()) : Configs::problems($settings);

    return is_array($shown) ? $shown : [];
};

it('shows the config in all four formats', function () use ($examples): void {
    expect(array_keys($examples()))->toBe(['php', 'json', 'yaml', 'neon']);
});

it('shows one config in every format', function (string $format, ConfigLoader $loader) use (
    $examples,
    $effective,
): void {
    $all = $examples();
    $example = static fn(string $format): string => $all[$format] ?? '';
    $php = $effective('php', $example('php'), new PhpConfig());

    expect($effective($format, $example($format), $loader))->toBe($php)
        ->and($php)->toMatchArray(['preset' => 'laravel', 'runner' => ['use' => 'pest', 'memory' => '1G']]);
})->with([
    'JSON' => ['json', fn(): ConfigLoader => new JsonConfig()],
    'YAML' => ['yaml', fn(): ConfigLoader => new YamlConfig()],
    'NEON' => ['neon', fn(): ConfigLoader => new NeonConfig()],
]);
