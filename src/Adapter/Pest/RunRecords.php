<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function is_file;
use function is_int;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\Ending;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Ran;

use function sprintf;

/**
 * The records of a Pest mutation run the gate judges, failing closed: a run
 * that failed, or records that do not add up to Pest's own summary, cannot
 * be judged; one whose own process ran out of the memory cap says to raise
 * it. One that crashed after it had made its mutants hands its records on (ADR-0330).
 *
 * @internal
 */
final readonly class RunRecords
{
    private const string FAILED = "Pest's mutation run failed%s. Pest said:\n%s";

    private const string EXITED = ' (exit code %d)';

    private const string SIGNALLED = ' (ended by signal %d, exit code %d)';

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
     * stopped at its deadline once every mutant was written; and no others,
     * one the deadline stopped before Pest made or wrote its mutants saying
     * so (ADR-0300).
     */
    public function of(Ran $ran, string $results): Records|CannotJudge
    {
        $records = Records::in($results);

        return match (true) {
            $records instanceof CannotJudge => $this->unread($ran, $records, $results),
            $ran->wasStopped() => $records->allMade() ? $records : $this->stoppedEarly($ran),
            $ran->succeeded() || $records->ended() => $this->counted($records, $ran),
            $this->crashed($ran, $records) !== '' => $records,
            default => $this->failed($ran),
        };
    }

    /**
     * What a run that crashed said: one that failed, without reaching its
     * end, after Pest had written every mutant it made, other than by the
     * issues of its opening run. Nothing of any other run (ADR-0330).
     */
    public function crashed(Ran $ran, Records $records): string
    {
        $crashed = $ran->ending() === Ending::Failed
            && ! $records->ended()
            && $records->allMade()
            && (!$this->opening instanceof OpeningIssues || !$this->opening->endedAfterOpening($ran));

        return $crashed ? $this->died($ran) : '';
    }

    /**
     * Why a run whose records cannot be read cannot be judged: the deadline,
     * where it stopped the run before it wrote any; why they cannot be read,
     * where the run succeeded or was stopped; and why it failed otherwise.
     */
    private function unread(Ran $ran, CannotJudge $unread, string $results): CannotJudge
    {
        return match (true) {
            $this->atTheDeadline($ran) && ! is_file($results) => CannotJudge::atTheDeadline($unread->why()),
            $ran->succeeded() || $ran->wasStopped() => $unread,
            default => $this->failed($ran),
        };
    }

    /** Why a run stopped before Pest made its mutants cannot be judged, saying where the deadline stopped it. */
    private function stoppedEarly(Ran $ran): CannotJudge
    {
        return $this->atTheDeadline($ran)
            ? CannotJudge::atTheDeadline(self::STOPPED_EARLY)
            : CannotJudge::because(self::STOPPED_EARLY);
    }

    /** Whether the run was stopped at its deadline, the time left it was given, and not at its silence limit. */
    private function atTheDeadline(Ran $ran): bool
    {
        return $ran->wasStopped() && ! $ran->wasSilenced();
    }

    /**
     * Why a run that failed cannot be judged: Pest's own process out of the
     * memory cap; the issues of an opening run that failed no test, where
     * the run ended after it (see OpeningIssues); or what Pest said.
     */
    private function failed(Ran $ran): CannotJudge
    {
        return $this->opening instanceof OpeningIssues && $this->opening->endedAfterOpening($ran)
            ? $this->opening->why($ran)
            : CannotJudge::because($this->died($ran));
    }

    /** How a run died: its own process out of the memory cap, or what Pest said and how its process ended. */
    private function died(Ran $ran): string
    {
        $output = $ran->output();

        return Exhaustion::isOf(Exhaustion::in($output), $this->cap)
            ? $this->exhausted($output)
            : sprintf(self::FAILED, $this->ended($ran), $output);
    }

    /** That Pest's own process ran out of the memory cap, with what it said. */
    private function exhausted(string $output): string
    {
        return sprintf(self::OUT_OF_MEMORY, $this->cap->written(), Exhaustion::ADVICE, $output);
    }

    /** How the run's process ended: its exit code, and the signal where one ended it; nothing where none was read. */
    private function ended(Ran $ran): string
    {
        $code = $ran->exitCode();

        return match (true) {
            ! is_int($code) => '',
            $ran->endedBySignal() => sprintf(self::SIGNALLED, $code - Ran::SIGNALLED, $code),
            default => sprintf(self::EXITED, $code),
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
