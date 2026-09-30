<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// The README's Configuration section shows one config in four formats. Each
// reads into the same effective config, but for the JSON Schema only JSON names.

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

/** @return array<mixed> the effective config an example reads into, without the JSON Schema it names */
$effective = static function (string $format, string $example, ConfigLoader $loader): array {
    $project = Scratch::directory();
    Scratch::write($project, sprintf('mutation-gate.%s', $format), $example);
    $document = $loader->load(Path::of(sprintf('%s/mutation-gate.%s', $project, $format)));
    $shown = $document instanceof Document
        ? json_decode(Configs::settings($document->json())->effective(), associative: true)
        : [$document->why()];

    return array_diff_key(is_array($shown) ? $shown : [], ['$schema' => true]);
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
        ->and($php)->toMatchArray(['preset' => 'laravel', 'runner' => 'pest']);
})->with([
    'JSON' => ['json', fn(): ConfigLoader => new JsonConfig()],
    'YAML' => ['yaml', fn(): ConfigLoader => new YamlConfig()],
    'NEON' => ['neon', fn(): ConfigLoader => new NeonConfig()],
]);
