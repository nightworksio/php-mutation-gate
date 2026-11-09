<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\PhpStanRestart;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$xdebug = static fn(): RunnerPhp => RunnerPhp::at('/usr/bin/php')->loading('xdebug')->loadingIni('/etc/php.ini');

$restarts = static fn(string $mode): Findings => Findings::of(Finding::of(
    Slug::PhpStanRestarts,
    Severity::Slow,
    'PHPStan restarts itself without Xdebug for every check in /usr/bin/php.',
    sprintf('Xdebug is loaded in the mode %s for it, so each check boots PHP twice before it analyses anything.', $mode),
    'Leave xdebug.mode at off in staticCheck.php, as by default.',
));

it('finds PHPStan restarting where staticCheck.php leaves Xdebug on, its mode over the variable\'s and the setting\'s', function (array $config, RunnerPhp $php, string $mode) use ($restarts): void {
    expect(PhpStanRestart::in(Observations::none()->withPhp($php)->withSettings(Configs::settings($config))))
        ->toEqual($restarts($mode));
})->with([
    'no mode named, Xdebug\'s default' => [['staticCheck' => ['php' => []]], fn(): RunnerPhp => $xdebug(), 'develop'],
    'the variable over the setting' => [['staticCheck' => ['php' => []]], fn(): RunnerPhp => $xdebug()->setting('xdebug.mode', 'debug')->seeing('XDEBUG_MODE', 'coverage'), 'coverage'],
    'a mode named' => [['staticCheck' => ['tool' => 'phpstan', 'php' => ['xdebug.mode' => 'debug']]], fn(): RunnerPhp => $xdebug()->seeing('XDEBUG_MODE', 'off'), 'debug'],
]);

it('finds nothing where PHPStan runs Xdebug off, another analyser checks, or Xdebug is not loaded', function (array $config, RunnerPhp $php): void {
    expect(PhpStanRestart::in(Observations::none()->withPhp($php)->withSettings(Configs::settings($config))))
        ->toEqual(Findings::none());
})->with([
    'the default staticCheck.php' => [[], fn(): RunnerPhp => $xdebug()->seeing('XDEBUG_MODE', 'debug')],
    'no mode named, the variable off' => [['staticCheck' => ['php' => []]], fn(): RunnerPhp => $xdebug()->seeing('XDEBUG_MODE', 'off')],
    'psalm' => [['staticCheck' => ['tool' => 'psalm', 'php' => []]], fn(): RunnerPhp => $xdebug()],
    'no Xdebug' => [['staticCheck' => ['php' => []]], fn(): RunnerPhp => RunnerPhp::at('php')],
]);

it('finds nothing where the PHP or the settings were not observed', function () use ($xdebug): void {
    expect(PhpStanRestart::in(Observations::none()->withPhp($xdebug())))->toEqual(Findings::none())
        ->and(PhpStanRestart::in(Observations::none()->withSettings(Configs::settings([]))))->toEqual(Findings::none());
});
