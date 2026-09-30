<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;

use function sprintf;

/**
 * `doctor`'s findings as a person reads them: each with what it found, why
 * it matters, the fix and its link, and a line that counts them. `plan` and
 * a one-process `run` begin with the brief, one line for each finding that
 * fails or slows a run.
 */
final readonly class DoctorText
{
    private const string HEADING = '%s  %s';

    private const string AT_STAKE = '%s (%s at stake)';

    private const string FIX = 'Fix: %s';

    private const string BRIEF = '%s: %s %s';

    private const string NOTHING = 'Nothing would fail or slow a run, and there is no advice.';

    private const string COUNTED = '%d would fail a run, %d would slow it, and %d %s advice.';

    public static function of(Findings $findings, Guide $guide): string
    {
        $blocks = [];

        foreach ($findings as $finding) {
            $blocks[] = implode("\n", [
                sprintf(self::HEADING, self::severityOf($finding), $finding->slug()->value),
                sprintf('  %s', $finding->found()),
                sprintf('  %s', $finding->why()),
                sprintf('  %s', sprintf(self::FIX, $finding->fix())),
                sprintf('  %s', $guide->link($finding->slug())),
            ]);
        }

        return implode("\n\n", [...$blocks, self::counted($findings)]);
    }

    /** One line for each finding that fails or slows a run, and none for advice. */
    public static function brief(Findings $findings, Guide $guide): string
    {
        $lines = [];

        foreach ($findings->thatAre(Severity::WillFail)->and($findings->thatAre(Severity::Slow)) as $finding) {
            $lines[] = sprintf(
                self::BRIEF,
                self::severityOf($finding),
                $finding->found(),
                $guide->link($finding->slug()),
            );
        }

        return implode("\n", $lines);
    }

    private static function severityOf(Finding $finding): string
    {
        $seconds = $finding->atStake();

        return $seconds instanceof Seconds
            ? sprintf(self::AT_STAKE, $finding->severity()->said(), $seconds->text())
            : $finding->severity()->said();
    }

    private static function counted(Findings $findings): string
    {
        $advice = count($findings->thatAre(Severity::Advice));

        return count($findings) === 0
            ? self::NOTHING
            : sprintf(
                self::COUNTED,
                count($findings->thatAre(Severity::WillFail)),
                count($findings->thatAre(Severity::Slow)),
                $advice,
                $advice === 1 ? 'is' : 'are',
            );
    }
}
