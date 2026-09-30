<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Check\CoverageDriver;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

$php = static fn(): RunnerPhp => RunnerPhp::at('/usr/bin/php')->setting(RunnerPhp::LOADED_INI, '/etc/php.ini');

it('finds no driver in a PHP that loads neither pcov nor Xdebug, and says how to get one', function () use ($php): void {
    $found = static fn(string $fix): Findings => Findings::of(Finding::of(
        Slug::NoCoverageDriver,
        Severity::WillFail,
        'The PHP the runner runs its tests on, /usr/bin/php, loads neither pcov nor Xdebug.',
        'The gate learns which tests run each line from coverage, so without a driver no run can judge a mutant.',
        $fix,
    ));

    expect(CoverageDriver::in(Observations::none()->withPhp($php())))
        ->toEqual($found('Install pcov with pecl install pcov, then add extension=pcov to /etc/php.ini.'))
        ->and(CoverageDriver::in(Observations::none()->withPhp($php()->offering('pcov'))))
        ->toEqual($found('Load pcov, which is installed: add extension=pcov to /etc/php.ini.'));
});

it('finds nothing in a PHP with a driver, or one it could not read', function (Observations $observations): void {
    expect(CoverageDriver::in($observations))->toEqual(Findings::none());
})->with([
    'pcov' => [Observations::none()->withPhp(RunnerPhp::at('php')->loading('pcov'))],
    'Xdebug' => [Observations::none()->withPhp(RunnerPhp::at('php')->loading('xdebug'))],
    'unread' => [Observations::none()->withPhp(CannotJudge::because('no PHP'))],
    'not observed' => [Observations::none()],
]);
