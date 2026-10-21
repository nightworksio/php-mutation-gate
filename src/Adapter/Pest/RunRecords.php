<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Ran;

use function sprintf;

/**
 * The records of a Pest mutation run the gate judges, failing closed: a run
 * that failed, or records that do not add up to Pest's own summary, cannot
 * be judged; one whose own process ran out of the memory cap says to raise
 * it.
 */
final readonly class RunRecords
{
    private const string FAILED = "Pest's mutation run failed. Pest said:\n%s";

    private const string UNCOUNTED = "Pest's records of its mutants do not add up to its summary. Pest said:\n%s";

    private const string STOPPED_EARLY = 'Pest was stopped at its deadline before it had made its mutants.';

    private const string OUT_OF_MEMORY
        = "Pest ran out of the %s memory cap in its own process, so the run did not finish. %s Pest said:\n%s";

    public function __construct(private MemoryCap $cap, private OpeningIssues|NoRerun $opening)
    {
    }

    /**
     * The records of a run that reached its end, whatever its exit code, since
     * a project's own minimum score fails a run that finished; of a run
     * stopped at its deadline once every mutant was written; and no others.
     */
    public function of(Ran $ran, string $results): Records|CannotJudge
    {
        $records = Records::in($results);

        return match (true) {
            $records instanceof CannotJudge => $ran->succeeded() || $ran->wasStopped()
                ? $records
                : $this->failed($ran),
            $ran->wasStopped() => $records->allMade() ? $records : CannotJudge::because(self::STOPPED_EARLY),
            $ran->succeeded() || $records->ended() => $this->counted($records, $ran),
            default => $this->failed($ran),
        };
    }

    /**
     * Why a run that failed cannot be judged: Pest's own process out of the
     * memory cap; the issues of an opening run that failed no test, where
     * the run ended after it (see OpeningIssues); or what Pest said.
     */
    private function failed(Ran $ran): CannotJudge
    {
        $output = $ran->output();

        return match (true) {
            Exhaustion::isOf(Exhaustion::in($output), $this->cap) => CannotJudge::because(
                sprintf(self::OUT_OF_MEMORY, $this->cap->written(), Exhaustion::ADVICE, $output),
            ),
            $this->opening instanceof OpeningIssues && $this->opening->endedAfterOpening($ran)
                => $this->opening->why($ran),
            default => CannotJudge::because(sprintf(self::FAILED, $output)),
        };
    }

    private function counted(Records $records, Ran $ran): Records|CannotJudge
    {
        $summary = Summary::in($ran->output());

        if ($summary instanceof CannotJudge) {
            return $summary;
        }

        return $records->addUpTo($summary) ? $records : CannotJudge::because(sprintf(self::UNCOUNTED, $ran->output()));
    }
}
