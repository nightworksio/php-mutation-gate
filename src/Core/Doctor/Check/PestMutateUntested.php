<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/** The Pest runner, over a pest-plugin-mutate release other than the one the gate is tested with. */
final readonly class PestMutateUntested
{
    /** The pest-plugin-mutate release the gate is tested with, the least its Composer package allows. */
    public const string TESTED = '5.0.2';

    private const string FOUND = 'pest-plugin-mutate %s is installed, and the gate is tested with %s.';

    private const string WHY = <<<'SAID'
        The Pest runner reads how pest-plugin-mutate runs each mutant, and pest:patch changes it in place. A release it
        is not tested with can run its mutants otherwise, and pest:patch refuses one whose code it does not find.
        SAID;

    private const string FIX
        = 'Require pestphp/pest-plugin-mutate %s, or run doctor again once a gate release tests yours.';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->settings();
        $runners = $observed->runners();
        $pest = $settings instanceof Settings
            && $settings->runner()->choice()->use()->value() === BuiltinRunner::Pest->value;
        $installed = $pest && $runners instanceof InstalledRunners
            ? $runners->pestMutateOtherThan(self::TESTED)
            : NotGiven::value();

        return $installed instanceof Version
            ? Findings::of(Finding::of(
                Slug::PestMutateUntested,
                Severity::Advice,
                sprintf(self::FOUND, $installed->release(), self::TESTED),
                self::WHY,
                sprintf(self::FIX, self::TESTED),
            ))
            : Findings::none();
    }
}
