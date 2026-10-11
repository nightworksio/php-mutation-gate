<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Runner\InertSetting;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use Symfony\Component\Process\Process;

$holds = [
    'holds:src/Core/Runner/Platform.php',
];

function digestOf(Platform|CannotJudge $platform): Digest|CannotJudge
{
    return $platform instanceof Platform ? $platform->digest() : $platform;
}

it('is the PHP a runner starts, which a setting of the gate\'s own PHP never reaches', function (): void {
    $describe = static fn(string ...$options): string => new Process([PHP_BINARY, ...$options, ...Platform::describing()])
        ->mustRun()
        ->getOutput();
    $settings = ini_get_all();
    $before = ini_get('memory_limit');
    ini_set('memory_limit', $before === '-1' ? '1G' : '-1');
    $started = Platform::describedBy($describe());
    ini_set('memory_limit', $before);
    $text = $started instanceof Platform ? $started->text() : '';

    expect($text)->toStartWith(sprintf("php %s\nos %s %s\n", PHP_VERSION, PHP_OS_FAMILY, php_uname('m')))
        ->and($text)->toContain(sprintf("\nextension Core %s\n", PHP_VERSION))
        ->and($text)->toContain(sprintf("\nini memory_limit=%s\n", $before))
        ->and($text)->toContain(sprintf("\nini include_path=%s\n", ini_get('include_path')))
        ->and($text)->not->toContain("\nini display_errors=")
        ->and(substr_count($text, "\nextension "))->toBe(count(get_loaded_extensions()))
        ->and(substr_count($text, "\nini "))->toBe(count(array_diff(
            array_keys(is_array($settings) ? $settings : []),
            array_column(InertSetting::cases(), 'value'),
        )))
        ->and(digestOf(Platform::describedBy($describe('-d', 'precision=7'))))->not->toEqual(digestOf($started))
        ->and(digestOf(Platform::describedBy($describe('-d', 'display_errors=0'))))->toEqual(digestOf($started));
})->group(...$holds);
