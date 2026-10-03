<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\NotGiven;

it('holds the extensions a PHP loads and offers, in any case, its settings and the variables it sees', function (): void {
    $php = RunnerPhp::at('/usr/bin/php')
        ->loading('Core', 'Zend OPcache')
        ->offering('pcov')
        ->setting('xdebug.mode', 'develop')
        ->seeing('XDEBUG_MODE', 'coverage');

    expect($php->binary())->toBe('/usr/bin/php')
        ->and([$php->loads('zend opcache'), $php->loads('pcov'), $php->loads('xdebug')])->toBe([true, false, false])
        ->and([$php->offers('PCOV'), $php->offers('core'), $php->offers('xdebug')])->toBe([true, true, false])
        ->and($php->valueOf('xdebug.mode'))->toBe('develop')
        ->and($php->valueOf('opcache.enable_cli'))->toEqual(NotGiven::value())
        ->and($php->variable('XDEBUG_MODE'))->toBe('coverage')
        ->and($php->variable('PATH'))->toEqual(NotGiven::value());
});

it('names the php.ini it loads, or describes it where it loads none', function (): void {
    $php = RunnerPhp::at('/usr/bin/php');

    expect($php->loadingIni('/etc/php/8.5/cli/php.ini')->iniFile())->toBe('/etc/php/8.5/cli/php.ini')
        ->and($php->iniFile())->toBe('the php.ini this PHP loads');
});

it('says whether the runner turns opcache off on each mutant\'s command line, keeping all else it holds', function (): void {
    $php = RunnerPhp::at('/usr/bin/php')->loading('Zend OPcache')->setting('opcache.enable_cli', '1');
    $off = $php->turningOpcacheOff();

    expect($php->turnsOpcacheOff())->toBeFalse()
        ->and($off->turnsOpcacheOff())->toBeTrue()
        ->and([$off->binary(), $off->loads('zend opcache'), $off->valueOf('opcache.enable_cli')])->toBe(['/usr/bin/php', true, '1']);
});
