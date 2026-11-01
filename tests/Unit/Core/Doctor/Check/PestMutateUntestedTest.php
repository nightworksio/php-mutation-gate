<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Check\PestMutateUntested;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** What doctor observes of a project with these settings and this pest-plugin-mutate release installed. */
function pestMutateIn(Settings $settings, string $installed): Observations
{
    return Observations::none()
        ->withSettings($settings)
        ->withRunners(InstalledRunners::of(pest: true, infection: false, chosen: true)
            ->withPestMutate(Version::of('pestphp/pest-plugin-mutate', $installed, 'abc123')));
}

it('advises of a pest-plugin-mutate release newer than any the gate is tested with, under the Pest runner', function (string $installed, string $release): void {
    expect(PestMutateUntested::in(pestMutateIn(Configs::settings(['runner' => 'pest']), $installed)))->toEqual(Findings::of(Finding::of(
        Slug::PestMutateUntested,
        Severity::Advice,
        sprintf('pest-plugin-mutate %s is installed, and the newest release the gate is tested with is 5.0.2.', $release),
        "The Pest runner reads how pest-plugin-mutate runs each mutant, and pest:patch changes it in place. A release it\nis not tested with can run its mutants otherwise, and pest:patch refuses one whose code it does not find.",
        'Require pestphp/pest-plugin-mutate 5.0.2, or run doctor again once a gate release tests yours.',
    )));
})->with([
    'a newer patch release' => ['v5.0.3', '5.0.3'],
    'a newer minor release' => ['5.1.0', '5.1.0'],
    'a newer major release' => ['v6.0.0', '6.0.0'],
]);

it('advises nothing of a tested release, a branch, another runner, or nothing observed', function (Observations $observed): void {
    expect(PestMutateUntested::in($observed))->toEqual(Findings::none());
})->with([
    'the newest tested release' => [fn(): Observations => pestMutateIn(Configs::settings(['runner' => 'pest']), 'v5.0.2')],
    'an older release' => [fn(): Observations => pestMutateIn(Configs::settings(['runner' => 'pest']), 'v5.0.1')],
    'a branch' => [fn(): Observations => pestMutateIn(Configs::settings(['runner' => 'pest']), 'dev-main')],
    'the Infection runner' => [fn(): Observations => pestMutateIn(Configs::settings(['runner' => 'infection']), 'v5.0.3')],
    'no settings' => [fn(): Observations => Observations::none()->withRunners(InstalledRunners::of(pest: true, infection: false, chosen: true)
        ->withPestMutate(Version::of('pestphp/pest-plugin-mutate', 'v5.0.3', 'abc123')))],
    'nothing observed' => [fn(): Observations => Observations::none()->withSettings(Configs::settings(['runner' => 'pest']))],
]);

it('is tested with releases the package allows, from the least to below the next major', function (): void {
    $manifest = json_decode((string) file_get_contents(sprintf('%s/composer.json', dirname(__DIR__, 5))), associative: true);
    $conflict = is_array($manifest) && is_array($manifest['conflict'] ?? null) ? $manifest['conflict'] : [];
    $pin = $conflict['pestphp/pest-plugin-mutate'] ?? null;
    preg_match('/^<(\S+) \|\| >=(\d+)\.0$/D', is_string($pin) ? $pin : '', $bounds);

    expect(version_compare($bounds[1] ?? '', PestMutateUntested::NEWEST, '<='))->toBeTrue()
        ->and(version_compare(PestMutateUntested::NEWEST, sprintf('%s.0', $bounds[2] ?? '0'), '<'))->toBeTrue();
});
