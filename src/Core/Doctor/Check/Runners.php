<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

/** Both runners installed, and neither the config nor the command line chooses one. */
final readonly class Runners
{
    private const string FOUND
        = 'Both Pest\'s mutation plugin and Infection are installed, and nothing chooses between them.';

    private const string WHY
        = 'Zero-config chooses the runner from what is installed, so with both it cannot, and no command can run.';

    private const string FIX
        = 'Set runner: pest or runner: infection in the config, or pass --runner=pest or --runner=infection.';

    public static function in(Observations $observed): Findings
    {
        $runners = $observed->runners();

        return $runners instanceof InstalledRunners && $runners->leaveTheChoiceOpen()
            ? Findings::of(Finding::of(Slug::TwoRunners, Severity::WillFail, self::FOUND, self::WHY, self::FIX))
            : Findings::none();
    }
}
