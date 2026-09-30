<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Measurement;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * Measuring the suite under `--measure` (ADR-0017, decisions 4 and 9): a run
 * begins by finding the units and running the whole suite under coverage,
 * so where that fails, or covers nothing, every run fails with it.
 */
final readonly class CoverageRun
{
    private const string FAILED = 'Measuring the suite failed. %s';

    private const string FAILED_WHY
        = 'A run begins the same way, finding the units and running the suite under coverage, or cannot judge.';

    private const string FAILED_FIX
        = 'Run vendor/bin/mutation-gate coverage to see the same failure, and fix the test or the driver it names.';

    private const string EMPTY = 'The suite\'s coverage run passed, and covered no line of any file.';

    private const string EMPTY_WHY = 'A mutant no test covers is never killed, so no run could judge one.';

    private const string EMPTY_FIX = <<<'FIX'
        Collect coverage where the gate runs, with XDEBUG_MODE=coverage or pcov.enabled=1,
        and list the trees in the <source> of phpunit.xml.
        FIX;

    public static function in(Observations $observed): Findings
    {
        $measured = $observed->asked()->measurement();
        $coverage = $measured instanceof Measurement ? $measured->coverage() : false;

        return match (true) {
            $coverage instanceof CannotJudge => self::willFail(
                Slug::CoverageRunFailed,
                sprintf(self::FAILED, $coverage->why()),
                self::FAILED_WHY,
                self::FAILED_FIX,
            ),
            $coverage !== false && count($coverage->files()) === 0 => self::willFail(
                Slug::CoverageEmpty,
                self::EMPTY,
                self::EMPTY_WHY,
                self::EMPTY_FIX,
            ),
            default => Findings::none(),
        };
    }

    private static function willFail(Slug $slug, string $found, string $why, string $fix): Findings
    {
        return Findings::of(Finding::of($slug, Severity::WillFail, $found, $why, $fix));
    }
}
