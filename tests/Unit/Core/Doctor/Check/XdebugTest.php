<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\Xdebug;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

$xdebug = static fn(): RunnerPhp => RunnerPhp::at('/usr/bin/php')->loading('xdebug')->loadingIni('/etc/php.ini');

$slowMode = static fn(string $mode): Findings => Findings::of(Finding::of(
    Slug::XdebugSlowsTests,
    Severity::Slow,
    sprintf('Xdebug runs in the mode %s in /usr/bin/php.', $mode),
    'Every mode but coverage slows each test, and the runner runs tests again for every mutant.',
    'Set XDEBUG_MODE=coverage where the gate runs, or xdebug.mode=coverage in /etc/php.ini.',
));

it('finds Xdebug slowing tests in any mode but coverage or off, the variable\'s over the setting\'s', function (RunnerPhp $php, string $mode) use ($slowMode): void {
    expect(Xdebug::in(Observations::none()->withPhp($php)))->toEqual($slowMode($mode));
})->with([
    'Xdebug\'s default' => [fn(): RunnerPhp => $xdebug(), 'develop'],
    'a setting' => [fn(): RunnerPhp => $xdebug()->setting('xdebug.mode', 'debug'), 'debug'],
    'a variable over a setting' => [fn(): RunnerPhp => $xdebug()->setting('xdebug.mode', 'coverage')->seeing('XDEBUG_MODE', 'coverage,profile'), 'coverage,profile'],
    'an empty variable, then the setting' => [fn(): RunnerPhp => $xdebug()->setting('xdebug.mode', 'trace')->seeing('XDEBUG_MODE', ''), 'trace'],
]);

it('finds Xdebug collecting coverage where pcov is installed beside it', function () use ($xdebug): void {
    expect(Xdebug::in(Observations::none()->withPhp($xdebug()->setting('xdebug.mode', 'coverage')->offering('pcov'))))
        ->toEqual(Findings::of(Finding::of(
            Slug::XdebugSlowsTests,
            Severity::Slow,
            'Xdebug collects coverage in /usr/bin/php, although pcov is installed beside it.',
            'pcov collects line coverage several times faster than Xdebug.',
            'Add extension=pcov to /etc/php.ini: php-code-coverage collects with pcov wherever both are loaded.',
        )));
});

it('finds nothing where Xdebug costs nothing, pcov collects, or there is no Xdebug', function (RunnerPhp $php): void {
    expect(Xdebug::in(Observations::none()->withPhp($php)))->toEqual(Findings::none());
})->with([
    'coverage mode' => [fn(): RunnerPhp => $xdebug()->setting('xdebug.mode', 'coverage')],
    'off, spaced' => [fn(): RunnerPhp => $xdebug()->seeing('XDEBUG_MODE', 'off, coverage')],
    'pcov loaded too' => [fn(): RunnerPhp => $xdebug()->setting('xdebug.mode', 'coverage')->loading('pcov')],
    'no Xdebug' => [fn(): RunnerPhp => RunnerPhp::at('php')->loading('pcov')],
]);

it('finds nothing where the PHP was not observed', function (): void {
    expect(Xdebug::in(Observations::none()))->toEqual(Findings::none());
});
