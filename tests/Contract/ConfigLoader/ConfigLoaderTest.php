<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Tests\Fakes\ConfigLoaderFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// What every config loader answers: its format's fixture file read into the
// same layer as every other format's, with every path named from the file's
// own directory, and cannot judge a file that is not there. Each
// implementation names the fixture file it reads.

$loaders = [
    'the fake' => fn(): array => [ConfigLoaderFake::ofTheFixture(), '/fixtures/Config/mutation-gate.fake'],
    'JsonConfig' => fn(): array => [new JsonConfig(), Tree::at('tests/Fixtures/Config/mutation-gate.json')],
    'PhpConfig' => fn(): array => [new PhpConfig(), Tree::at('tests/Fixtures/Config/mutation-gate.php')],
    'YamlConfig' => fn(): array => [new YamlConfig(), Tree::at('tests/Fixtures/Config/mutation-gate.yaml')],
    'NeonConfig' => fn(): array => [new NeonConfig(), Tree::at('tests/Fixtures/Config/mutation-gate.neon')],
];

/** The fixture as a file of the project in its own directory, or in the one above it. */
$file = static fn(string $fixture, bool $above = false): ConfigFile => ConfigFile::at(
    Path::of($fixture),
    Path::of($above ? dirname($fixture, 2) : dirname($fixture)),
);

it('reads its fixture into the layer every format reads into', function (ConfigLoader $loader, string $fixture) use (
    $file,
): void {
    $layer = $loader->load($file($fixture));

    expect($layer instanceof Layer ? Configs::decoded($layer) : Configs::problems($layer))->toBe([
        'runner' => 'pest',
        'trees' => [['path' => 'src', 'floor' => 100]],
        'newCode' => ['floor' => 100],
    ]);
})->with($loaders);

it('names every path from the file\'s own directory', function (ConfigLoader $loader, string $fixture) use (
    $file,
): void {
    $layer = $loader->load($file($fixture, above: true));
    $trees = $layer instanceof Layer ? $layer->floors()->trees() : Absent::setting();

    expect(array_map(
        static fn(DeclaredTree $tree): string => $tree->path()->value(),
        $trees instanceof Absent ? [] : [...$trees],
    ))->toBe(['Config/src']);
})->with($loaders);

it('cannot judge a config file that is not there', function (ConfigLoader $loader, string $fixture) use (
    $file,
): void {
    expect($loader->load($file(sprintf('/missing/%s', basename($fixture)))))->toBeInstanceOf(CannotJudge::class);
})->with($loaders);
