<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Tests\Fakes\ConfigLoaderFake;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// What every config loader answers: its format's fixture file read into the
// same untyped tree as every other format's, and cannot judge a file that is
// not there. Each implementation names the fixture file it reads.

$loaders = [
    'the fake' => fn(): array => [ConfigLoaderFake::ofTheFixture(), 'mutation-gate.fake'],
    'JsonConfig' => fn(): array => [new JsonConfig(), Tree::at('tests/Fixtures/Config/mutation-gate.json')],
    'PhpConfig' => fn(): array => [new PhpConfig(), Tree::at('tests/Fixtures/Config/mutation-gate.php')],
    'YamlConfig' => fn(): array => [new YamlConfig(), Tree::at('tests/Fixtures/Config/mutation-gate.yaml')],
    'NeonConfig' => fn(): array => [new NeonConfig(), Tree::at('tests/Fixtures/Config/mutation-gate.neon')],
];

it('reads its fixture into the tree every format shares', function (ConfigLoader $loader, string $fixture): void {
    $document = $loader->load(Path::of($fixture));

    expect($document)->toBeInstanceOf(Document::class)
        ->and(json_decode($document instanceof Document ? $document->json() : '', associative: true))->toBe([
            'runner' => 'pest',
            'trees' => [['path' => 'src', 'floor' => 100]],
            'newCode' => ['floor' => 100],
        ]);
})->with($loaders);

it('cannot judge a config file that is not there', function (ConfigLoader $loader, string $fixture): void {
    expect($loader->load(Path::of(sprintf('missing/%s', $fixture))))->toBeInstanceOf(CannotJudge::class);
})->with($loaders);
