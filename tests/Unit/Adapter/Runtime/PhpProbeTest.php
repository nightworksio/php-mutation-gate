<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Runtime\PhpProbe;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\FakePhp;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the extensions, the settings, the php.ini and the drivers installed beside them, with the runner\'s options', function (): void {
    $directory = FakePhp::printing('');
    Scratch::write($directory, 'extensions/pcov.so', '');
    file_put_contents(sprintf('%s/output.txt', $directory), Described::loading(
        '/etc/php.ini',
        ['Core' => '8.5.0', 'xdebug' => '3.5.0', 'Zend OPcache' => '8.5.0'],
        [
            'opcache.enable_cli' => '1',
            'opcache.file_cache' => '',
            'xdebug.mode' => 'develop',
            'extension_dir' => sprintf('%s/extensions', $directory),
        ],
    ));
    $php = PhpProbe::of(sprintf('%s/php', $directory), ['PATH' => '/usr/bin:/bin', 'XDEBUG_MODE' => 'coverage'])
        ->describe(Withheld::standard(), '-d', 'memory_limit=1G');

    expect($php)->toBeInstanceOf(RunnerPhp::class)
        ->and($php instanceof RunnerPhp ? [$php->loads('xdebug'), $php->loads('zend opcache'), $php->loads('pcov'), $php->offers('pcov'), $php->offers('xdebug')] : [])
        ->toBe([true, true, false, true, true])
        ->and($php instanceof RunnerPhp ? [$php->iniFile(), $php->valueOf('opcache.enable_cli'), $php->valueOf('opcache.file_cache'), $php->valueOf('xdebug.mode')] : [])
        ->toBe(['/etc/php.ini', '1', '', 'develop'])
        ->and($php instanceof RunnerPhp ? $php->variable('XDEBUG_MODE') : '')->toBe('coverage')
        ->and(trim((string) file_get_contents(sprintf('%s/arguments.txt', $directory))))
        ->toBe(sprintf('-d memory_limit=1G -r %s', Platform::describing()[1]));
});

it('reads the platform a PHP describes, which is the one the real PHP describes of itself', function (): void {
    $fake = PhpProbe::of(sprintf('%s/php', FakePhp::printing(Described::output())), []);
    $real = PhpProbe::of(PHP_BINARY, [])->platform(Withheld::standard(), '-d', 'precision=7');
    $names = get_loaded_extensions();
    sort($names);

    expect($fake->platform(Withheld::standard()))->toEqual(Described::platform())
        ->and($real instanceof Platform ? $real->settings()['precision'] : '')->toBe('7')
        ->and($real instanceof Platform ? array_keys($real->extensions()) : [])->toBe($names);
});

it('never hands the PHP a variable withheld, nor sees Xdebug\'s mode where that is withheld', function (): void {
    $directory = FakePhp::printing(Described::output([], ['extension_dir' => '/nowhere']));
    $php = PhpProbe::of(sprintf('%s/php', $directory), ['GITHUB_TOKEN' => 'secret', 'XDEBUG_MODE' => 'debug', 'KEPT' => 'yes'])
        ->describe(Withheld::standard()->and(Withheld::of('XDEBUG_*')));
    $seen = (string) file_get_contents(sprintf('%s/environment.txt', $directory));

    expect($php instanceof RunnerPhp ? $php->variable('XDEBUG_MODE') : '')->toEqual(NotGiven::value())
        ->and($php instanceof RunnerPhp ? [$php->offers('pcov'), $php->iniFile()] : [])->toBe([false, 'the php.ini this PHP loads'])
        ->and(str_contains($seen, 'GITHUB_TOKEN'))->toBeFalse()
        ->and(str_contains($seen, 'XDEBUG_MODE'))->toBeFalse();
});

it('cannot judge a PHP that fails to describe itself, describes itself in another form, or cannot start', function (): void {
    $failing = sprintf('%s/php', FakePhp::printing('broken', exit: 3));
    $silent = sprintf('%s/php', FakePhp::printing("Deprecated: something\n"));
    $garbled = sprintf('%s/php', FakePhp::printing("mutation-gate platform {\"php\": 8}\n"));

    expect(PhpProbe::of($failing, [])->describe(Withheld::standard(), '-d', 'memory_limit=1G'))
        ->toEqual(CannotJudge::because(sprintf('%s -d memory_limit=1G -r could not describe itself: broken', $failing)))
        ->and(PhpProbe::of($silent, [])->platform(Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf(
            "%s -r could not describe itself: it printed no description of itself:\nDeprecated: something",
            $silent,
        )))
        ->and(PhpProbe::of($garbled, [])->platform(Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf(
            '%s -r could not describe itself: its description is not in the form the gate reads: the file.php is not text.',
            $garbled,
        )))
        ->and(PhpProbe::of('/nowhere/php', [])->describe(Withheld::standard()))->toBeInstanceOf(CannotJudge::class);
});

it('cannot judge a PHP that takes longer than it is allowed to describe itself', function (): void {
    $directory = FakePhp::printing('');
    $slow = sprintf('%s/slow', $directory);
    file_put_contents($slow, "#!/bin/sh\nsleep 5\n");
    chmod($slow, 0o755);
    $described = new PhpProbe($slow, [], Seconds::of(0.2))->describe(Withheld::standard());

    expect($described instanceof CannotJudge ? $described->why() : '')->toStartWith(sprintf('%s -r could not describe itself: ', $slow));
});
