<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Extension\ConfigLoaderContract;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Tests\Fakes\ConfigLoaderFake;
use NightWorksIO\MutationGate\Tests\Support\Tree;

$holds = [
    'holds:src/Adapter/Json',
    'holds:src/Adapter/Neon',
    'holds:src/Adapter/Php',
    'holds:src/Adapter/Yaml',
    'holds:src/Config/Gate.php',
    'holds:src/Config/Ignore.php',
    'holds:src/Config/Tree.php',
    'holds:src/Core/Config/Definition/Number.php',
    'holds:src/Core/Config/Definition/Reading.php',
    'holds:src/Core/Config/Options.php',
    'holds:src/Extension/ConfigLoaderContract.php',
];

// What every config loader answers, the gate's own and an extension's alike:
// the contract an extension's tests hold its loader to, on its fixtures.

it('keeps the config loader contract', function (ConfigLoader $loader, string $fixtures, string $extension): void {
    expect([...ConfigLoaderContract::failures($loader, Path::of($fixtures), $extension)])->toBe([]);
})->with([
    'the fake' => fn(): array => [ConfigLoaderFake::ofTheFixture(), '/fixtures/Config', 'fake'],
    'JsonConfig' => fn(): array => [new JsonConfig(), Tree::at('tests/Fixtures/Config'), 'json'],
    'PhpConfig' => fn(): array => [new PhpConfig(), Tree::at('tests/Fixtures/Config'), 'php'],
    'YamlConfig' => fn(): array => [new YamlConfig(), Tree::at('tests/Fixtures/Config'), 'yaml'],
    'NeonConfig' => fn(): array => [new NeonConfig(), Tree::at('tests/Fixtures/Config'), 'neon'],
])->group(...$holds);
