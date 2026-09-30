<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Doctor\Check\Config;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$refused = static fn(string $found): Findings => Findings::of(Finding::of(
    Slug::ConfigRefused,
    Severity::WillFail,
    $found,
    'Every command reads the config first, so none can run until it is right.',
    'Correct each problem named, then run mutation-gate config:show to see the config the gate would use.',
));

it('finds a config every command refuses, with each problem at its path', function () use ($refused): void {
    $invalid = Invalid::because(Problem::at('budget', 'is not a duration.'), Problem::at('', 'Something is wrong.'));

    expect(Config::in(Observations::none()->withSettings($invalid)))
        ->toEqual($refused('The config cannot be used: budget: is not a duration. Something is wrong.'))
        ->and(Config::in(Observations::none()->withSettings(CannotJudge::because('mutation-gate.php threw.'))))
        ->toEqual($refused('The config cannot be used: mutation-gate.php threw.'));
});

it('leaves two runners with no choice to the runners check, and finds nothing in a usable config', function (): void {
    $open = Observations::none()
        ->withSettings(CannotJudge::because('Both are installed.'))
        ->withRunners(InstalledRunners::of(pest: true, infection: true, chosen: false));

    expect(Config::in($open))->toEqual(Findings::none())
        ->and(Config::in(Observations::none()->withSettings(Configs::settings(['runner' => 'pest']))))->toEqual(Findings::none())
        ->and(Config::in(Observations::none()))->toEqual(Findings::none());
});
