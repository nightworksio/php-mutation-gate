<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

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
 * the evidence its own run recorded (ADR-0014, decision 16).
 */
final readonly class Interpretation
{
    private const string TOO_LONG = 'Pest cannot pass the filter of the %d tests covering %s:%d. Turn on pest.patch.';

    private const string UNSELECTED = "Pest's --filter cannot select %s, so Pest cannot run it against this mutant.";

    private const string RAN_NO_TEST = 'its own run ran no test';

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

        return match (true) {
            $records instanceof CannotJudge => $records,
            $coverage instanceof CannotJudge => $coverage,
            default => $this->mutants($records, $coverage),
        };
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

        foreach ($planned as $at => $mutant) {
            $selection = Selection::of($coverage->testsCovering($mutant->file(), $mutant->start(), $mutant->end()));

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
            $evidence = $evidence->with($judged->id(), $this->evidenceOf($judged, $mutant, $records));
        }

        return MutationResult::of(Mutants::of(...$mutants), 0)->withEvidence($evidence);
    }

    /**
     * The evidence of a kill (ADR-0014, decision 16): how far its own run
     * went, keyed by the test files it was narrowed to, or by none where it
     * loaded every one; and, where it names no killer, how its process
     * ended. None of a mutant that is not killed, or that shares its mutated
     * copy with another, whose runs cannot be told apart.
     */
    private function evidenceOf(Mutant $judged, PlannedMutant $planned, Records $records): Evidence
    {
        if ($judged->status() !== MutantStatus::Killed || $records->sharesItsCopy($planned)) {
            return Evidence::none();
        }

        $run = $records->runOf($planned);
        $files = Paths::of(...array_map(
            $this->project->relative(...),
            $run->narrowedTo(),
        ));
        $prefix = $run->prefix($files);
        $ended = $run->ended();
        $evidence = $prefix instanceof Prefix ? Evidence::none()->withPrefix($prefix) : Evidence::none();

        return $ended instanceof Ended && count($run->killers()) === 0 ? $evidence->withEnded($ended) : $evidence;
    }

    /**
     * A mutant's status as Pest ended it, or killed where Pest timed it out
     * after its own process recorded a failing test, as one that runs on
     * past its limit after its first failure under a full kill matrix does
     * (ADR-0014, decision 7); a kill whose process ran out of exactly the
     * gate's memory cap is out of memory (ADR-0004, decision 9).
     */
    private function statusOf(PlannedMutant $planned, Records $records): MutantStatus
    {
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
     * A mutant whose mutated copy static analysis rejected before its tests,
     * killed by it (ADR-0020, decision 12), whether Pest ran it or not; as it
     * was otherwise.
     */
    private function rejected(Mutant $mutant, PlannedMutant $planned): Mutant
    {
        $rejection = $this->handOff instanceof HandOff ? $this->handOff->rejectionOf($planned) : NotGiven::value();

        return $rejection instanceof Rejection ? $mutant->rejected($rejection) : $mutant;
    }

    /** A timed-out mutant, saying its run was stopped at its silence limit where it was. */
    private function silenced(Mutant $mutant, Seconds|NotGiven $silence): Mutant
    {
        return $silence instanceof Seconds ? $mutant->because(Reason::silent($silence)) : $mutant;
    }
}
