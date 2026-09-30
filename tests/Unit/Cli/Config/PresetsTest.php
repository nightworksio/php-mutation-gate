<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\Presets;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$json = static fn(mixed $preset): string => match (true) {
    $preset instanceof Document => $preset->json(),
    $preset instanceof CannotJudge => $preset->why(),
    default => get_debug_type($preset),
};

it('is a config fragment, as data', function (Closure $preset, string $expected) use ($json): void {
    expect($json($preset()))->toBe($expected);
})->with([
    'library' => [
        Presets::library(...),
        '{"treeSource":"phpunit","newCode":{"floor":100},"timeouts":{"seconds":10}}',
    ],
    'laravel' => [
        Presets::laravel(...),
        '{"treeSource":{"use":"phpunit","with":{"fallback":["app"]}},"newCode":{"floor":100},'
        . '"reach":{"everything":["bootstrap/**","config/**","routes/**",".env.testing"]},"timeouts":{"seconds":30}}',
    ],
    'symfony, whose tree keeps src/Kernel.php' => [
        Presets::symfony(...),
        '{"treeSource":{"use":"phpunit","with":{"fallback":["src"]}},"newCode":{"floor":100},'
        . '"reach":{"everything":["config/**",".env.test","tests/bootstrap.php"]},"timeouts":{"seconds":30}}',
    ],
]);

it('is a valid config once a runner is chosen', function (Closure $preset) use ($json): void {
    $config = json_decode($json($preset()), associative: true);

    expect(Configs::validated([...(is_array($config) ? $config : []), 'runner' => 'pest']))
        ->toBeInstanceOf(Settings::class);
})->with([
    'library' => [Presets::library(...)],
    'laravel' => [Presets::laravel(...)],
    'symfony' => [Presets::symfony(...)],
]);
