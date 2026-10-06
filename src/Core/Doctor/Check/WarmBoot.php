<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Doctor\WarmRefusal;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

/**
 * Warm workers the last run's guard refused, so every mutant ran in a fresh
 * process and paid the boot (ADR-0023, decision 13). It names the bootstrap
 * file, and its line where PHP can tell, as the run kept the reason.
 */
final readonly class WarmBoot
{
    private const string WHY = <<<'WHY'
        A warm worker boots the autoloader and the bootstrap once and forks a child for each mutant. A boot that
        leaves a connection open, starts PHPUnit's events, or loads code the run mutates cannot be forked from
        safely, so each mutant paid the whole boot again.
        WHY;

    private const string FIX = <<<'FIX'
        Have the bootstrap open its connections lazily and leave the code under test unloaded, migrate a
        deprecated PHPUnit configuration with `--migrate-configuration`, or set `runner.workers: fresh` to stop
        trying.
        FIX;

    public static function in(Observations $observed): Findings
    {
        $refusal = $observed->files()->warmRefusal();

        return $refusal instanceof WarmRefusal
            ? Findings::of(Finding::of(Slug::WarmBootRefused, Severity::Slow, $refusal->reason(), self::WHY, self::FIX))
            : Findings::none();
    }
}
