<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\InertSetting;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Tests\Support\Described;

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

it('is the PHP that described itself, whatever else it printed', function (): void {
    expect(Platform::describedBy(Described::output()))->toEqual(Described::platform())
        ->and(Described::platform()->text())->toContain("\nini memory_limit=128M\n")
        ->and(Described::platform()->text())->not->toContain('display_errors');
});

it('cannot be read from a PHP that did not describe itself, or described itself in another form', function (): void {
    expect(Platform::describedBy("Segmentation fault\n"))->toEqual(CannotJudge::because(
        "it printed no description of itself:\nSegmentation fault",
    ))
        ->and(Platform::describedBy('mutation-gate platform {"php": 8}'))->toEqual(CannotJudge::because(
            'its description is not in the form the gate reads: the file.php is not text.',
        ));
});

it('holds every setting and the php.ini it loads, though its text reads neither the inert settings nor php.ini', function (): void {
    $platform = Platform::describedBy(Described::loading('/etc/php.ini'));

    expect($platform instanceof Platform ? $platform->settings() : [])->toBe([
        'display_errors' => 'STDOUT',
        'memory_limit' => '128M',
        'precision' => '14',
    ])
        ->and($platform instanceof Platform ? $platform->iniFile() : '')->toBe('/etc/php.ini')
        ->and(Described::platform()->iniFile())->toEqual(NotGiven::value())
        ->and($platform instanceof Platform ? $platform->text() : '')->toBe(Described::platform()->text())
        ->and(Described::platform()->text())->not->toContain('display_errors');
});

it('leaves out only the settings named inert', function (): void {
    expect(array_column(InertSetting::cases(), 'value'))->toBe([
        'display_errors',
        'display_startup_errors',
        'html_errors',
        'log_errors',
        'error_log',
        'error_log_mode',
        'docref_root',
        'docref_ext',
        'cli.pager',
        'cli.prompt',
    ]);
});
