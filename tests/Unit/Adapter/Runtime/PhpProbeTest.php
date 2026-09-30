<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Runtime\PhpProbe;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\FakePhp;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the extensions, the settings and the drivers installed beside them, with the runner\'s options', function (): void {
    $directory = FakePhp::answering(
        "[PHP Modules]\nCore\nxdebug\n\n[Zend Modules]\nXdebug\nZend OPcache\n",
        "Loaded Configuration File => /etc/php.ini\nopcache.enable_cli => On => On\nopcache.file_cache => no value => no value\nxdebug.mode => develop => develop\nextension_dir => EXT => EXT\nPHP License\n",
    );
    $extensions = sprintf('%s/extensions', $directory);
    Scratch::write($directory, 'extensions/pcov.so', '');
    file_put_contents(sprintf('%s/info.txt', $directory), str_replace('EXT', $extensions, (string) file_get_contents(sprintf('%s/info.txt', $directory))));
    $php = PhpProbe::of(sprintf('%s/php', $directory), ['PATH' => '/usr/bin:/bin', 'XDEBUG_MODE' => 'coverage'])
        ->describe(Withheld::standard(), '-d', 'memory_limit=1G');

    expect($php)->toBeInstanceOf(RunnerPhp::class)
        ->and($php instanceof RunnerPhp ? [$php->loads('xdebug'), $php->loads('zend opcache'), $php->loads('pcov'), $php->offers('pcov'), $php->offers('xdebug')] : [])
        ->toBe([true, true, false, true, true])
        ->and($php instanceof RunnerPhp ? [$php->iniFile(), $php->valueOf('opcache.enable_cli'), $php->valueOf('opcache.file_cache'), $php->valueOf('xdebug.mode')] : [])
        ->toBe(['/etc/php.ini', 'On', '', 'develop'])
        ->and($php instanceof RunnerPhp ? $php->variable('XDEBUG_MODE') : '')->toBe('coverage')
        ->and(trim((string) file_get_contents(sprintf('%s/arguments.txt', $directory))))->toBe('-d memory_limit=1G -i');
});

it('never hands the PHP a variable withheld, nor sees Xdebug\'s mode where that is withheld', function (): void {
    $directory = FakePhp::answering("[PHP Modules]\nCore\n", "extension_dir => /nowhere => /nowhere\n");
    $php = PhpProbe::of(sprintf('%s/php', $directory), ['GITHUB_TOKEN' => 'secret', 'XDEBUG_MODE' => 'debug', 'KEPT' => 'yes'])
        ->describe(Withheld::standard()->and(Withheld::of('XDEBUG_*')));
    $seen = (string) file_get_contents(sprintf('%s/environment.txt', $directory));

    expect($php instanceof RunnerPhp ? $php->variable('XDEBUG_MODE') : '')->toEqual(NotGiven::value())
        ->and($php instanceof RunnerPhp ? $php->offers('pcov') : true)->toBeFalse()
        ->and(str_contains($seen, 'GITHUB_TOKEN'))->toBeFalse()
        ->and(str_contains($seen, 'XDEBUG_MODE'))->toBeFalse();
});

it('cannot judge a PHP that fails to describe itself, or cannot start', function (): void {
    $directory = FakePhp::answering('broken', 'broken', exit: 3);

    expect(PhpProbe::of(sprintf('%s/php', $directory), [])->describe(Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf('%s/php -m could not describe itself: broken', $directory)))
        ->and(PhpProbe::of('/nowhere/php', [])->describe(Withheld::standard()))->toBeInstanceOf(CannotJudge::class);
});

it('cannot judge a PHP that takes longer than it is allowed to describe itself', function (): void {
    $directory = FakePhp::answering('', '');
    file_put_contents(sprintf('%s/modules.txt', $directory), '');
    $slow = sprintf('%s/slow', $directory);
    file_put_contents($slow, "#!/bin/sh\nsleep 5\n");
    chmod($slow, 0o755);
    $described = new PhpProbe($slow, [], Seconds::of(0.2))->describe(Withheld::standard());

    expect($described instanceof CannotJudge ? $described->why() : '')->toStartWith(sprintf('%s -m could not describe itself: ', $slow));
});
