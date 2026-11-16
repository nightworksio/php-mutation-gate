<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\PhpIni;
use NightWorksIO\MutationGate\Adapter\PhpStan\TurboPlatform;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('sets Xdebug\'s variable to the mode its settings name, over the one the environment has', function (): void {
    expect(PhpIni::of(Node::decode(JsonText::compact(['xdebug.mode' => 'off'])), turbo: false)->environment(['XDEBUG_MODE' => 'coverage', 'HOME' => '/home/ci']))
        ->toBe(['XDEBUG_MODE' => 'off', 'HOME' => '/home/ci']);
});

it('leaves the environment as it is where its settings name no mode', function (): void {
    expect(PhpIni::of(Node::decode(JsonText::compact(['pcov.enabled' => false])), turbo: false)->environment(['XDEBUG_MODE' => 'coverage']))
        ->toBe(['XDEBUG_MODE' => 'coverage'])
        ->and(PhpIni::none()->environment([]))->toBe([]);
});

it('passes each setting as a -d argument, spelling booleans and numbers as PHP does, and leaving what is no single value empty', function (): void {
    $written = ['pcov.enabled' => false, 'opcache.enable_cli' => true, 'xdebug.mode' => 'off', 'memory_limit' => 512, 'precision' => 14.5, 'a.list' => [1]];

    expect(PhpIni::of(Node::decode(JsonText::compact($written)), turbo: false)->arguments('/project', '/tmp/cache'))
        ->toBe(['-dpcov.enabled=0', '-dopcache.enable_cli=1', '-dxdebug.mode=off', '-dmemory_limit=512', '-dprecision=14.5', '-da.list=']);
});

it('names a file cache under the directory it is given, made, where its settings ask for a file cache alone and name none', function (array $written, string $expected, bool $made): void {
    $cache = sprintf('%s/opcache', Scratch::directory());

    expect(implode(' ', PhpIni::of(Node::decode(JsonText::compact($written)), turbo: false)->arguments('/project', $cache)))
        ->toBe(sprintf($expected, $cache))
        ->and(is_dir($cache))->toBe($made);
})->with([
    'alone, naming none' => [['opcache.file_cache_only' => true], '-dopcache.file_cache_only=1 -dopcache.file_cache=%s', true],
    'alone, naming one' => [['opcache.file_cache_only' => true, 'opcache.file_cache' => '/kept'], '-dopcache.file_cache_only=1 -dopcache.file_cache=/kept', false],
    'not alone' => [['opcache.file_cache_only' => false], '-dopcache.file_cache_only=0', false],
]);

it('loads PHPStan\'s turbo build for this machine where the project ships one and the settings ask for it, and never under none', function (bool $turbo, bool $shipped, bool $loaded): void {
    $root = Scratch::directory();
    $platform = TurboPlatform::here();
    $build = sprintf(
        '%s/vendor/phpstan/phpstan/turbo-ext/%s/phpstan_turbo-%d.%d%s.%s',
        $root,
        $platform,
        PHP_MAJOR_VERSION,
        PHP_MINOR_VERSION,
        PHP_ZTS === 1 ? '-zts' : '',
        PHP_OS_FAMILY === 'Windows' ? 'dll' : 'so',
    );

    if ($shipped) {
        Scratch::write(dirname($build), basename($build), 'a turbo build');
    }

    expect(PhpIni::of(Node::decode(JsonText::compact(['memory_limit' => 512])), $turbo)->arguments($root, '/tmp/cache'))
        ->toBe($loaded ? ['-dmemory_limit=512', sprintf('-dextension=%s', $build)] : ['-dmemory_limit=512'])
        ->and(PhpIni::none()->arguments($root, '/tmp/cache'))->toBe([]);
})->with([
    'asked and shipped' => [true, true, true],
    'asked, none shipped' => [true, false, false],
    'shipped, not asked' => [false, true, false],
])->skip(fn(): bool => TurboPlatform::here() === '' || extension_loaded('phpstan_turbo'), 'PHPStan ships no turbo build for this machine, or this PHP has it loaded');
