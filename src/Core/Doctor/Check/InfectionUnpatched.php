<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\ComposerSetup;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\InfectionPatch;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

/** Infection as the runner, without `infection:patch` (ADR-0008, decision 2). */
final readonly class InfectionUnpatched
{
    private const string FOUND = 'The runner is Infection, and the installed Infection does not carry infection:patch.';

    private const string WHY
        = 'Infection then gives each mutant its own limit with no floor, so a quick mutant can time out under load.';

    private const string FIX
        = 'Add @php vendor/bin/mutation-gate infection:patch to post-install-cmd and post-update-cmd, then install.';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->settings();
        $composer = $observed->files()->composer();
        $infection = $settings instanceof Settings
            && $settings->runner()->choice()->use()->value() === BuiltinRunner::Infection->value;

        return $infection && $composer instanceof ComposerSetup && $composer->infection() === InfectionPatch::Missing
            ? Findings::of(Finding::of(Slug::InfectionUnpatched, Severity::Advice, self::FOUND, self::WHY, self::FIX))
            : Findings::none();
    }
}
