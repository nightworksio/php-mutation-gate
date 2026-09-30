<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\Savings;
use NightWorksIO\MutationGate\Core\Cost\Untimed;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * What a run took and saved, in the one headline every summary shows: the
 * console's last line, a line of the step summary, and the PR comment's line
 * under the verdict. It carries no money, which stays in the cost section
 * (ADR-0017, decision 13).
 */
final readonly class SavingsText
{
    /** How many days back the savings a default branch shows are counted. */
    public const int DAYS = 30;

    private const string JUDGED = 'Judged in %s wall, %s runner time%s.';

    private const string ESTIMATED = ' (estimated)';

    private const string FULL_RUN = 'A full one-job run: %s (%d%% measured). Reach saved %s, proofs %s%s';

    private const string SHARDING = '; sharding cut the wait by %s and cost %s of setup.';

    private const string NO_HISTORY = 'No history yet, so nothing saved is shown.';

    private const string LATELY = 'In the last %d days the gate saved %s of runner time.';

    public static function headline(RunTimings $timings, Savings|NoHistory $savings): string
    {
        $spent = $timings->spent();
        $judged = sprintf(
            self::JUDGED,
            $spent->wall()->text(),
            $spent->runner()->text(),
            $spent->isMeasured() ? '' : self::ESTIMATED,
        );

        return sprintf('%s %s', $judged, $savings instanceof Savings ? self::saved($savings) : self::NO_HISTORY);
    }

    /**
     * The headline of a verdict whose run was timed, and under it what the
     * default branch saved lately where that is known; nothing for an untimed
     * run.
     */
    public static function of(Verdict $verdict, Seconds|NoHistory $lately): string
    {
        $timings = $verdict->account()->timings();

        return match (true) {
            $timings instanceof Untimed => '',
            $lately instanceof NoHistory => self::headline($timings, $verdict->account()->savings()),
            default => sprintf(
                "%s\n%s",
                self::headline($timings, $verdict->account()->savings()),
                sprintf(self::LATELY, self::DAYS, $lately->text()),
            ),
        };
    }

    private static function saved(Savings $savings): string
    {
        return sprintf(
            self::FULL_RUN,
            $savings->fullRun()->text(),
            $savings->measured()->wholePercent(),
            $savings->reach()->text(),
            $savings->proofs()->text(),
            $savings->isSharded()
                ? sprintf(self::SHARDING, $savings->waitSaved()->text(), $savings->shardingSetup()->text())
                : '.',
        );
    }
}
