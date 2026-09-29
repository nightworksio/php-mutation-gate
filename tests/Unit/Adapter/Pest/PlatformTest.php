<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Platform;
use NightWorksIO\MutationGate\Core\File\Digest;

it('reads its facts one to a line, extensions and settings in name order', function (): void {
    $platform = Platform::of(
        '8.5.0',
        ['pcov' => '1.0.12', 'Core' => '8.5.0'],
        ['precision' => '14', 'memory_limit' => '-1'],
        'Linux',
        'x86_64',
    );

    expect($platform->text())->toBe(implode("\n", [
        'php 8.5.0',
        'os Linux x86_64',
        'extension Core 8.5.0',
        'extension pcov 1.0.12',
        'ini memory_limit=-1',
        'ini precision=14',
    ]));
});

it('digests what it reads, so two platforms that read alike are one', function (): void {
    $platform = Platform::of('8.5.0', ['Core' => '8.5.0'], ['precision' => '14'], 'Linux', 'x86_64');

    expect($platform->digest())->toEqual(Digest::sha256Of($platform->text()))
        ->and(Platform::of('8.5.1', ['Core' => '8.5.0'], ['precision' => '14'], 'Linux', 'x86_64')->digest())
        ->not->toEqual($platform->digest());
});

it('is the PHP running this process, with every extension and setting it has', function (): void {
    $text = Platform::current()->text();
    $settings = ini_get_all(details: false);

    expect($text)->toStartWith(sprintf("php %s\nos %s %s\n", PHP_VERSION, PHP_OS_FAMILY, php_uname('m')))
        ->and($text)->toContain(sprintf("\nextension Core %s\n", PHP_VERSION))
        ->and($text)->toContain(sprintf("\nini precision=%s", ini_get('precision')))
        ->and(substr_count($text, "\nextension "))->toBe(count(get_loaded_extensions()))
        ->and(substr_count($text, "\nini "))->toBe(is_array($settings) ? count($settings) : 0);
});
