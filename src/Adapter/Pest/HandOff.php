<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_keys;

use Closure;

use function count;
use function file_get_contents;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Verdicts;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckable;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckables;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * The hand-off of a patched Pest run's mutants to static analysis before
 * their tests (ADR-0020, decision 12). While Pest runs, each look reads the
 * results file; once the plugin has written every mutant Pest planned, and
 * waits, the hand-off offers each covered mutant that is no twin to the
 * pre-checker, as Pest prints it, behind its covering tests' own time, and
 * writes the verdicts file the plugin waits for: the mutated copy of each
 * mutant rejected, whose run Pest then skips. It writes the file however the
 * checks end, so Pest never waits on a check that failed. A mutant and its
 * twins share their copy, and so their rejection.
 */
final class HandOff implements ProcessWatch
{
    /** @var array<string, Rejection> each rejection, by the mutated copy of the mutant it rejected */
    private array $rejected = [];

    private bool $handed = false;

    /** @param Closure(): (Covering|CannotJudge) $covering the run's covering tests, read once all are planned */
    public function __construct(
        private readonly Project $project,
        private readonly string $results,
        private readonly PreChecker $checker,
        private readonly ProcessCount $side,
        private readonly Closure $covering,
        private readonly Bridges $bridges,
    ) {
    }

    public function look(): void
    {
        if ($this->handed) {
            return;
        }

        $records = Records::in($this->results);

        if (! $records instanceof Records || ! $records->allMade()) {
            return;
        }

        $this->handed = true;

        try {
            $this->rejected = $this->checked($records);
        } finally {
            Verdicts::write(Verdicts::beside($this->results), ...array_keys($this->rejected));
        }
    }

    /** What rejected a planned mutant, or a twin, by its mutated copy; nothing where nothing did. */
    public function rejectionOf(PlannedMutant $mutant): Rejection|NotGiven
    {
        $copy = $mutant->mutated()->value();

        return array_key_exists($copy, $this->rejected) ? $this->rejected[$copy] : NotGiven::value();
    }

    /**
     * Each covered planned mutant offered to the pre-checker, and what it
     * rejected, by mutated copy.
     *
     * @return array<string, Rejection>
     */
    private function checked(Records $records): array
    {
        $covering = ($this->covering)();

        if ($covering instanceof CannotJudge) {
            return [];
        }

        $planned = $records->planned();
        $ids = Identities::of(Root::of($this->project->root()), $planned);
        $map = $covering->map($this->project);
        $prints = [];
        $offered = [];
        $copies = [];

        foreach ($planned as $at => $mutant) {
            $tests = $covering->testsCovering($mutant->file(), $mutant->start(), $mutant->end());
            $gate = $this->unrun($ids[$at], $mutant);
            $file = $gate->location()->file()->value();
            $prints[$file] ??= $this->printOf($gate);
            $checkable = $this->checkable($prints[$file], $mutant);

            if ($mutant->isTwin() || count($tests) === 0 || ! $checkable instanceof Checkable) {
                continue;
            }

            $offered[] = PreCheckable::of($gate, $checkable, $this->seconds(OwnTime::of($map, $tests)));
            $copies[$gate->id()->value()] = $mutant->mutated()->value();
        }

        $rejections = $this->checker->rejected(PreCheckables::of(...$offered), $this->side);
        $rejected = [];

        foreach ($offered as $offer) {
            $rejection = $rejections->of($offer->mutant()->id());
            $rejected = $rejection instanceof Rejection
                ? [...$rejected, $copies[$offer->mutant()->id()->value()] => $rejection]
                : $rejected;
        }

        return $rejected;
    }

    /** A planned mutant as the gate names it, before its run: unjudged, unmeasured. */
    private function unrun(MutantId $id, PlannedMutant $planned): Mutant
    {
        return Mutant::of(
            $id,
            $planned->id(),
            Location::of($this->project->relative($planned->file()->value()), $planned->start(), $planned->end()),
            $planned->mutation($this->bridges),
            MutantStatus::Unjudged,
            Unmeasured::duration(),
        );
    }

    /** A mutant's file as Pest prints it, or why it cannot be printed. */
    private function printOf(Mutant $mutant): Contents|CannotJudge
    {
        $file = $mutant->location()->file();
        $written = file_get_contents($this->project->absolute($file));

        return is_string($written) ? Printed::of(Contents::of($written), $file) : CannotJudge::because($file->value());
    }

    /** A mutant as the analyser reads it: the mutated copy Pest serves, judged against the print of its file. */
    private function checkable(Contents|CannotJudge $print, PlannedMutant $mutant): Checkable|CannotJudge
    {
        $copy = $print instanceof Contents ? file_get_contents($mutant->mutated()->value()) : false;

        return match (true) {
            $print instanceof CannotJudge => $print,
            ! is_string($copy) => CannotJudge::because($mutant->mutated()->value()),
            default => Checkable::printed($print, Contents::of($copy)),
        };
    }

    private function seconds(Seconds|Unmeasured $took): Seconds
    {
        return $took instanceof Seconds ? $took : Seconds::of(0.0);
    }
}
