<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\ComposerSetup;
use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Patched;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Core\Verb;

use function sprintf;

/** `pest.patch: true` while the installed pest-plugin-mutate does not carry `pest:patch` (ADR-0004). */
final readonly class PestUnpatched
{
    /** What adds a patch command of the gate to the project's install scripts. */
    public const string FIX = 'Add %s to post-install-cmd and post-update-cmd, then install.';
    private const string FOUND = 'pest.patch is on, and the installed pest-plugin-mutate does not carry pest:patch.';

    private const string WHY
        = 'A patched shard opens on the canary group, and refuses to where the plugin is not patched.';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->settings();
        $composer = $observed->files()->composer();
        $patching = $settings instanceof Settings
            && $settings->runner()->choice()->use()->value() === BuiltinRunner::Pest->value
            && $settings->effective()->pest()->patch();

        return $patching && $composer instanceof ComposerSetup && $composer->pest() === Patched::Missing
            ? Findings::of(Finding::of(
                Slug::PestUnpatched,
                Severity::WillFail,
                self::FOUND,
                self::WHY,
                sprintf(self::FIX, DoctorRun::gateOf($observed)->scripted(Verb::PestPatch)),
            ))
            : Findings::none();
    }
}
