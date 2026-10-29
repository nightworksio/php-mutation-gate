<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_slice;
use function count;
use function min;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\CheckableSurvivor;
use NightWorksIO\MutationGate\Core\Analysis\CheckTime;
use NightWorksIO\MutationGate\Core\Analysis\DifferingPrint;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\MutantChecks;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use Psr\Clock\ClockInterface;

/**
 * One round of static analysis's checks of a shard's survivors, after the
 * analyser's run over the originals (ADR-0020): every survivor its runner
 * gives checked side by side on the machine's cores, against its original's
 * findings, as many as the time left has room for. A survivor with an error
 * its original does not have is killed by static analysis; one the analyser
 * cannot check, or the time left has no room for, stays a survivor, and the
 * checks say why.
 */
final readonly class SurvivorBatch
{
    public function __construct(
        private Adapters $adapters,
        private ClockInterface $clock,
        private Deadline|Unlimited $deadline,
        private Seconds $perCheck,
    ) {
    }

    /**
     * Every survivor checked against its original's findings, side by side
     * on the machine's cores: the warm-up's for a file as written, and for
     * one printed as the runner prints, the print's, found once for each
     * file, side by side too. The survivors are taken in turn while the
     * time left has room for their checks (see CheckRoom); the rest are left.
     */
    public function checked(WarmedUp $warm, Mutants $mutants, Mutants $survivors): Checked
    {
        $checks = SurvivorChecks::none();
        $offered = [];

        foreach ($survivors as $survivor) {
            $checkable = $this->adapters->runner->checkable($survivor);

            if ($checkable instanceof Checkable) {
                $offered[] = CheckableSurvivor::of($survivor, $checkable);

                continue;
            }

            $checks = $checks->leaving(UncheckedSurvivor::of(Unchecked::NoMutant, $survivor->location()->file()));
        }

        $fitting = new CheckRoom($this->deadline, $this->clock, $this->adapters->cores)->fitting($offered, $warm->took);

        foreach (array_slice($offered, count($fitting)) as $left) {
            $checks = $checks->leaving($this->left(Unchecked::OutOfTime, $left));
        }

        return $this->batched($warm, $mutants, $survivors, $fitting, $checks);
    }

    /**
     * These survivors' checks, side by side, each against its original's
     * findings: a survivor whose original or code the analyser cannot read
     * is left, with why.
     *
     * @param list<CheckableSurvivor> $fitting
     */
    private function batched(
        WarmedUp $warm,
        Mutants $mutants,
        Mutants $survivors,
        array $fitting,
        SurvivorChecks $checks,
    ): Checked {
        [$planned, $judged, $checks] = $this->planned($warm, $fitting, $checks);
        $started = $this->clock->now();
        $batch = MutantChecks::of(...$planned);
        $answers = $planned === [] ? [] : [...$warm->checker->checks($batch, $this->adapters->cores)->padded($batch)];
        $took = Seconds::between($started, $this->clock->now());

        foreach ($planned as $check) {
            $this->adapters->project->remove($check->mutant());
        }

        $timed = $checks->timing($this->timed($warm, $took, count($judged)));

        return $this->answered($warm, $mutants, $survivors, $judged, $answers, $timed);
    }

    /**
     * Each survivor's check, written where it reads it, with its original's
     * findings; a survivor whose original or code the analyser cannot read
     * left, with why.
     *
     * @param  list<CheckableSurvivor>                                                            $fitting
     * @return array{list<MutantCheck>, list<array{CheckableSurvivor, Findings}>, SurvivorChecks}
     */
    private function planned(WarmedUp $warm, array $fitting, SurvivorChecks $checks): array
    {
        $baselines = new PrintBaselines($this->adapters, $warm, $this->perCheck)->of($fitting, $this->adapters->cores);
        $planned = [];
        $judged = [];

        foreach ($fitting as $survivor) {
            $baseline = $baselines->baseline($survivor);
            $check = $baseline instanceof Findings ? $this->written($warm, $survivor) : $baseline;
            $checks = $check instanceof MutantCheck ? $checks : $checks->leaving($this->left($check, $survivor));

            if ($check instanceof MutantCheck) {
                $planned[] = $check;
                $judged[] = [$survivor, $baseline];
            }
        }

        return [$planned, $judged, $checks];
    }

    /** What the checks taught of the analyser: how many it made, and the time the cores spent on them together. */
    private function timed(WarmedUp $warm, Seconds $took, int $count): AnalyserHistory
    {
        return AnalyserHistory::of($warm->identity->analyser())->withTime(
            CheckTime::of($count, Seconds::of($took->seconds() * min($this->adapters->cores->count(), $count))),
        );
    }

    /**
     * Each checked survivor killed by its first new error, or left as it
     * was, or left with why its check could not run.
     *
     * @param list<array{CheckableSurvivor, Findings}> $judged
     * @param list<Findings|OutOfScope|CannotJudge>   $answers one in the place of each judged survivor's check
     */
    private function answered(
        WarmedUp $warm,
        Mutants $mutants,
        Mutants $survivors,
        array $judged,
        array $answers,
        SurvivorChecks $checks,
    ): Checked {
        $rejected = Mutants::none();

        foreach ($judged as $at => [$survivor, $baseline]) {
            $answer = $answers[$at];

            if (! $answer instanceof Findings) {
                $checks = $checks->leaving($this->left($answer, $survivor));

                continue;
            }

            foreach ($answer->newErrors($baseline) as $error) {
                $rejected = $rejected->with(
                    $survivor->mutant()->rejected(Rejection::by($warm->identity->analyser(), $error)),
                );

                break;
            }
        }

        return new Checked($mutants->replacing($rejected), $checks, $survivors);
    }

    /**
     * A survivor written where its check reads it, with the dependents it
     * can break where the analyser reads them; or why it cannot be written.
     */
    private function written(WarmedUp $warm, CheckableSurvivor $survivor): MutantCheck|CannotJudge
    {
        $at = Workspace::checkedMutant($survivor->mutant()->id());
        $written = $this->adapters->project->write($at, $survivor->checkable()->mutant());
        $check = MutantCheck::of($survivor->mutant()->location()->file(), $at)
            ->within($this->perCheck)
            ->withholding($this->adapters->withheld);

        return match (true) {
            $written instanceof CannotJudge => $written,
            $warm->checker->readsDependents() => $check->withDependents(
                PrintBaselines::dependents($this->adapters, $warm, $survivor),
            ),
            default => $check,
        };
    }

    /** Why a survivor is left unchecked: what its original or its check came to. */
    private function left(
        Unchecked|DifferingPrint|OutOfScope|CannotJudge $why,
        CheckableSurvivor $survivor,
    ): UncheckedSurvivor {
        $file = $survivor->mutant()->location()->file();

        return match (true) {
            $why instanceof Unchecked => UncheckedSurvivor::of($why, $file),
            $why instanceof DifferingPrint => UncheckedSurvivor::differing($why, $file),
            $why instanceof OutOfScope => UncheckedSurvivor::of(Unchecked::OutOfScope, $file),
            default => UncheckedSurvivor::failed($why->why(), $file),
        };
    }
}
