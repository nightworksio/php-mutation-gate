<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\WarmBoot;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Doctor\WarmRefusal;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('says why the last run\'s warm workers forked nothing, why that slows each mutant, and how to boot for them', function (): void {
    $refused = WarmRefusal::of('The boot loaded src/Money.php, which this run mutates, at tests/bootstrap.php:12, so each mutant ran fresh.');

    expect(WarmBoot::in(Observations::none()->withFiles(ProjectFiles::none()->withWarmRefusal($refused))))->toEqual(Findings::of(Finding::of(
        Slug::WarmBootRefused,
        Severity::Slow,
        'The boot loaded src/Money.php, which this run mutates, at tests/bootstrap.php:12, so each mutant ran fresh.',
        "A warm worker boots the autoloader and the bootstrap once and forks a child for each mutant. A boot that\n"
        . "leaves a connection open, starts PHPUnit's events, or loads code the run mutates cannot be forked from\n"
        . 'safely, so each mutant paid the whole boot again.',
        "Have the bootstrap open its connections lazily and leave the code under test unloaded, migrate a\n"
        . "deprecated PHPUnit configuration with `--migrate-configuration`, or set `runner.workers: fresh` to stop\n"
        . 'trying.',
    )));
});

it('finds nothing where the last run\'s workers forked, or no run kept why not', function (): void {
    expect(WarmBoot::in(Observations::none()->withFiles(ProjectFiles::none())))->toEqual(Findings::none())
        ->and(WarmBoot::in(Observations::none()))->toEqual(Findings::none());
});

it('is kept in the PHPUnit runner\'s directory of the gate\'s', function (): void {
    expect(WarmRefusal::file()->value())->toBe('.mutation-gate/phpunit/warm-refused.txt');
});
