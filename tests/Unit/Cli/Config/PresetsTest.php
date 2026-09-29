<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\Presets;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$json = static fn(Document|CannotJudge $preset): string => $preset instanceof Document
    ? $preset->json()
    : $preset->why();

it('is a config fragment, as data', function (Document|CannotJudge $preset, string $expected) use ($json): void {
    expect($json($preset))->toBe($expected);
})->with([
    'library' => [
        fn(): Document|CannotJudge => Presets::library(),
        '{"treeSource":"phpunit","newCode":{"floor":100},"timeouts":{"seconds":10}}',
    ],
    'laravel' => [
        fn(): Document|CannotJudge => Presets::laravel(),
        '{"treeSource":{"use":"phpunit","with":{"fallback":["app"]}},"newCode":{"floor":100},'
        . '"reach":{"everything":["bootstrap/**","config/**","routes/**",".env.testing"]},"timeouts":{"seconds":30}}',
    ],
    'symfony, whose tree keeps src/Kernel.php' => [
        fn(): Document|CannotJudge => Presets::symfony(),
        '{"treeSource":{"use":"phpunit","with":{"fallback":["src"]}},"newCode":{"floor":100},'
        . '"reach":{"everything":["config/**",".env.test","tests/bootstrap.php"]},"timeouts":{"seconds":30}}',
    ],
]);

it('is a valid config once a runner is chosen', function (Document|CannotJudge $preset) use ($json): void {
    $config = json_decode($json($preset), associative: true);

    expect(Configs::validated([...(is_array($config) ? $config : []), 'runner' => 'pest']))
        ->toBeInstanceOf(Settings::class);
})->with([
    'library' => [fn(): Document|CannotJudge => Presets::library()],
    'laravel' => [fn(): Document|CannotJudge => Presets::laravel()],
    'symfony' => [fn(): Document|CannotJudge => Presets::symfony()],
]);
