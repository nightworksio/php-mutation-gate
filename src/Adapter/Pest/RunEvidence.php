<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function count;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Mutant\TestShare;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * The evidence a mutant's own Pest run left (ADR-0014): that a split stream
 * judged it (ADR-0117); otherwise, for a kill, how far its own run went and,
 * where it names no killer, how its process ended, and the test files it
 * loaded with no filter where a patched run left its filter out; with the
 * part of the test files that owns it.
 *
 * @internal
 */
final readonly class RunEvidence
{
    public function __construct(private Project $project)
    {
    }

    /** The evidence of this mutant, as its own run left it. */
    public function of(Mutant $judged, PlannedMutant $planned, Records $records): Evidence
    {
        $run = $records->runOf($planned);
        $given = $run->isMarked(RecordEvent::Streamed)
            ? Evidence::none()->streamed()
            : $this->unfiltered($this->evidenceOf($judged, $planned, $records), $planned, $records);

        return $given->sharing(TestShare::of(
            owned: $run->isMarked(RecordEvent::OwnedPart),
            otherOwned: $run->isMarked(RecordEvent::OtherPart),
        ));
    }

    /**
     * The evidence of a kill (ADR-0014): how far its own run
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
     * Evidence, with the test files a mutant's own run loaded with no
     * filter, where a patched run left its filter out: those it was narrowed
     * to, or every one.
     */
    private function unfiltered(Evidence $evidence, PlannedMutant $planned, Records $records): Evidence
    {
        $files = $records->runOf($planned)->loadedUnfiltered();

        return match (true) {
            $files instanceof NotGiven => $evidence,
            $files === [] => $evidence->loadingUnfiltered(WholeSuite::tests()),
            default => $evidence->loadingUnfiltered(Paths::of(...array_map($this->project->relative(...), $files))),
        };
    }
}
