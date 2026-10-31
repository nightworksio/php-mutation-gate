<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

/** The chosen runner installed at releases the gate does not drive, which every run refuses before it starts. */
final readonly class UnsupportedRunner
{
    private const string WHY
        = 'Every run reads the releases Composer installed first, and refuses those it does not drive.';

    private const string FIX
        = 'Require the releases the requirements list for this runner, or choose a runner the installed releases suit.';

    public static function in(Observations $observed): Findings
    {
        $runners = $observed->runners();
        $unsupported = $runners instanceof InstalledRunners ? $runners->unsupported() : $runners;

        return $unsupported instanceof CannotJudge
            ? Findings::of(
                Finding::of(Slug::RunnerUnsupported, Severity::WillFail, $unsupported->why(), self::WHY, self::FIX),
            )
            : Findings::none();
    }
}
