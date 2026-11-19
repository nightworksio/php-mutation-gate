<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\KillControlsAlone;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$observed = static fn(array $flaky): Observations => Observations::none()
    ->withSettings(Configs::settings(['runner' => 'pest', 'flaky' => $flaky]));

it('advises against controlling a kill without its order', function () use ($observed): void {
    expect(KillControlsAlone::in($observed(['killControls' => 'alone'])))->toEqual(Findings::of(Finding::of(
        Slug::KillControlsAlone,
        Severity::Advice,
        'flaky.killControls is alone, so a kill is controlled without the order it happened in.',
        'A test that fails only after the tests before it then counts as a kill, and no survivor shows it.',
        'Remove flaky.killControls, or set it to order, to replay each kill in its order.',
    )));
});

it('finds nothing where kills replay their order, or no settings could be read', function () use ($observed): void {
    expect(KillControlsAlone::in($observed(['killControls' => 'order'])))->toEqual(Findings::none())
        ->and(KillControlsAlone::in($observed(['confirmSurvivors' => true])))->toEqual(Findings::none())
        ->and(KillControlsAlone::in(Observations::none()))->toEqual(Findings::none());
});
