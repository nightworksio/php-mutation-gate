<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Check\Php;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('finds a PHP that could not describe itself, saying what it said', function (): void {
    expect(Php::in(Observations::none()->withPhp(CannotJudge::because('/usr/bin/php -m could not describe itself: segfault'))))
        ->toEqual(Findings::of(Finding::of(
            Slug::PhpNotRead,
            Severity::WillFail,
            '/usr/bin/php -m could not describe itself: segfault',
            'A run starts the same PHP with the same options, so it would fail the same way.',
            'Run the PHP the error names with -m by hand, and correct what stops it.',
        )));
});

it('finds nothing where the PHP described itself, or was not observed', function (): void {
    expect(Php::in(Observations::none()->withPhp(RunnerPhp::at('php'))))->toEqual(Findings::none())
        ->and(Php::in(Observations::none()))->toEqual(Findings::none());
});
