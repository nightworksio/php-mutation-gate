<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function count;
use function implode;
use function is_array;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Overrun;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * A Pest mutation run read as the gate's records, failing closed: a run that
 * failed, records that do not add up to Pest's own summary, or a filter too
 * long to start a mutant with while `pest:patch` is off is cannot judge, and
 * one whose own process ran out of the memory cap says to raise it. A run
 * stopped at its deadline keeps every result it had, and leaves the rest
 * unjudged. So does a covering test Pest's filter cannot select, because Pest
 * would have called that mutant killed or uncovered without running the test;
 * a mutant whose own process had loaded its file before the mutant was in
 * place, because its tests ran the original code; and a survivor whose own
 * process said it ran no test, because nothing judged it. Each kill keeps
 * the evidence its own run recorded (ADR-0014). A mutant the run's part of
 * the test files left to another part is not reached, and each other keeps
 * how many of its covering tests that part held (ADR-0117).
 *
 * @internal
 */
final readonly class Interpretation
{
    private const string TOO_LONG = 'Pest cannot pass the filter of the %d tests covering %s:%d. Turn on pest.patch.';

    private const string UNSELECTED = "Pest's --filter cannot select %s, so Pest cannot run it against this mutant.";

    private const string RAN_NO_TEST = 'its own run ran no test';

    /** What a run that counts its forks' states says of them (H5). */
    private const string FORK_STATES = <<<'SAID'
        Split streams read the state %d forks left in parent and child;
        %d of them found it alike, so the fork changed nothing the call returned or held (outbreak.forkStates).
        SAID;

    public function __construct(
        private Project $project,
        private Patching $patching,
        private MemoryCap $cap,
        private Bridges $bridges = new Bridges(),
        private OpeningIssues|NoRerun $opening = NoRerun::Opening,
        private HandOff|NotGiven $handOff = new NotGiven(),
    ) {
    }

    /** A run's records, read with the covering tests of the run's opening map. */
    public function of(Ran $ran, string $results, Covering|CannotJudge $coverage): MutationResult|CannotJudge
    {
        $records = new RunRecords($this->cap, $this->opening)->of($ran, $results);

        $result = match (true) {
            $records instanceof CannotJudge => $records,
            $coverage instanceof CannotJudge => $coverage,
            default => $this->mutants($records, $coverage),
        };

        $counted = $records instanceof Records ? $records->forkStates() : NotGiven::value();
        $result = $result instanceof MutationResult && is_array($counted)
            ? $result->withWarnings(Warnings::of(Warning::that(sprintf(self::FORK_STATES, $counted[0], $counted[1]))))
            : $result;

        return $result instanceof MutationResult && $ran->wasStopped() ? $result->stoppedAtDeadline() : $result;
    }

    /** A mutant the gate made before, now unjudged, saying why. */
    public static function unjudged(Mutant $mutant, Reason $reason): Mutant
    {
        return Mutant::of(
            $mutant->id(),
            $mutant->nativeId(),
            $mutant->location(),
            $mutant->mutation(),
            MutantStatus::Unjudged,
            $mutant->duration(),
        )->because($reason);
    }

    private function mutants(Records $records, Covering $coverage): MutationResult|CannotJudge
    {
        $mutants = [];
        $evidence = Evidences::none();
        $planned = $records->planned();
        $ids = Identities::of(Root::of($this->project->root()), $planned);

        $suite = Suite::of($coverage->tests());

        foreach ($planned as $at => $made) {
            $mutant = $this->handOff instanceof HandOff ? $this->handOff->judgedAs($made) : $made;
            $selection = $this->patching->selecting(
                $coverage->testsCovering($mutant->file(), $mutant->start(), $mutant->end()),
                $suite,
            );

            if (! $selection->fits() && ! $this->patching->isOn()) {
                return CannotJudge::because(sprintf(
                    self::TOO_LONG,
                    $selection->count(),
                    $this->project->relative($mutant->file()->value())->value(),
                    $mutant->start()->number(),
                ));
            }

            $judged = $this->rejected($this->mutant($ids[$at], $mutant, $records, $selection), $mutant);
            $mutants[] = $judged;
            $evidence = $evidence->with($judged->id(), new RunEvidence($this->project)->of($judged, $mutant, $records));
        }

        return MutationResult::skippingNone(Mutants::of(...$mutants))->withEvidence($evidence);
    }

    /**
     * A mutant's status as Pest ended it, or killed where Pest timed it out
     * after its own process recorded a failing test, as one that runs on
     * past its limit after its first failure under a full kill matrix does
     * (ADR-0014); a kill whose process ran out of exactly the
     * gate's memory cap is out of memory (ADR-0004); one the run's part of
     * the test files left to another part is not reached (ADR-0117).
     */
    private function statusOf(PlannedMutant $planned, Records $records): MutantStatus
    {
        if ($records->runOf($planned)->isMarked(RecordEvent::Unreached)) {
            return MutantStatus::NotReached;
        }

        $run = $records->runOf($planned);
        $status = $records->statusOf($planned)->status();
        $status = $status === MutantStatus::TimedOut && count($run->killers()) > 0 ? MutantStatus::Killed : $status;

        return $status === MutantStatus::Killed && Exhaustion::isOf($run->exhaustion(), $this->cap)
            ? MutantStatus::OutOfMemory
            : $status;
    }

    /** The one place a Pest mutant becomes the gate's. */
    private function mutant(MutantId $gate, PlannedMutant $planned, Records $records, Selection $selection): Mutant
    {
        $unselected = $selection->fits() ? $selection->unselected() : TestIds::none();
        $file = $this->project->relative($planned->file()->value());
        $run = $records->runOf($planned);
        $ranTheOriginal = $run->ranTheOriginal();
        $ranNoTest = $records->statusOf($planned)->status() === MutantStatus::Survived && $run->ranNoTest();
        $judged = count($unselected) === 0 && ! $ranTheOriginal && ! $ranNoTest;
        $mutant = Mutant::of(
            $gate,
            $planned->id(),
            Location::of($file, $planned->start(), $planned->end()),
            $planned->mutation($this->bridges),
            $judged ? $this->statusOf($planned, $records) : MutantStatus::Unjudged,
            $records->durationOf($planned),
        );

        $limit = $records->limitOf($planned);
        $status = $mutant->status();
        $limited = match (true) {
            $status === MutantStatus::OutOfMemory => $mutant->withLimit($this->cap),
            $status === MutantStatus::TimedOut && $limit instanceof Seconds => $this->silenced(
                $mutant->withLimit($limit),
                $records->silenceOf($planned),
            ),
            $status === MutantStatus::Killed => $mutant->killedBy($run->killers()),
            default => $mutant,
        };
        $names = array_map(static fn(TestId $test): string => $test->value(), [...$unselected]);

        return match (true) {
            $ranTheOriginal => $limited->because(Reason::preloaded($file)),
            $ranNoTest => $limited->because(Reason::that(self::RAN_NO_TEST)),
            $judged => $limited,
            default => $limited->because(Reason::that(sprintf(self::UNSELECTED, implode(', ', $names)))),
        };
    }

    /**
     * A mutant `outbreak.so` proved equivalent before its tests, never run
     * (ADR-0130); one whose mutated copy static analysis rejected before its
     * tests, killed by it (ADR-0020), whether Pest ran it or not; as it was
     * otherwise.
     */
    private function rejected(Mutant $mutant, PlannedMutant $planned): Mutant
    {
        $handOff = $this->handOff;
        $proof = $handOff instanceof HandOff ? $handOff->equivalenceOf($planned) : NotGiven::value();
        $rejection = $handOff instanceof HandOff ? $handOff->rejectionOf($planned) : NotGiven::value();

        return match (true) {
            $proof instanceof Reason => $mutant->provenEquivalent($proof),
            $rejection instanceof Rejection => $mutant->rejected($rejection),
            default => $mutant,
        };
    }

    /**
     * A timed-out mutant, saying its run was stopped at its silence limit, or
     * where a test of it ran past its own limit, where it was.
     */
    private function silenced(Mutant $mutant, Seconds|Overrun|NotGiven $silence): Mutant
    {
        return match (true) {
            $silence instanceof Seconds => $mutant->because(Reason::silent($silence)),
            $silence instanceof Overrun => $mutant->because(Reason::overran($silence->test(), $silence->limit())),
            default => $mutant,
        };
    }
}
