<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\Scoring;

/**
 * A tree of a sampled run estimated (ADR-0020): the
 * units its sample ran tallied as its sampled stratum, with those it left
 * out at the mutants each is estimated to hold, and every other unit it
 * judged counted exactly. A tree of a run that was not sampled is a census.
 */
final readonly class Estimating
{
    private function __construct(private Sample $sample, private Trees $trees, private Uncovered $uncovered)
    {
    }

    public static function of(Sample $sample, Trees $trees, Uncovered $uncovered): self
    {
        return new self($sample, $trees, $uncovered);
    }

    /** What the run estimates of a tree, from the units it judged in it and their mutants. */
    public function tree(Tree $tree, JudgedUnits $units, JudgedMutants $mutants): Estimate|Census
    {
        if (! $this->sample->isDrawn()) {
            return new Census();
        }

        $byFile = $this->talliesByFile($mutants);
        $whole = Tally::none();

        foreach ($byFile as $tally) {
            $whole = $whole->plus($tally);
        }

        $sampled = $this->sampledIn($tree, $byFile);
        $sum = Spread::of(...$sampled)->sum();
        $left = 0;
        $leftMutants = 0;

        foreach ($this->sample->unsampled() as $unsampled) {
            $held = $this->holds($tree, $unsampled->unit());
            $left += $held ? 1 : 0;
            $leftMutants += $held ? $unsampled->mutants() : 0;
        }

        return Estimate::of(
            Tally::of($whole->killed() - $sum->killed(), $whole->counted() - $sum->counted()),
            count($units) - count($sampled),
            Stratum::of(count($sampled) + $left, $sum->counted() + $leftMutants, Spread::of(...$sampled)),
            $this->sample,
        );
    }

    /**
     * The tally of each unit of this tree the sample ran.
     *
     * @param  array<string, Tally> $byFile
     * @return list<Tally>
     */
    private function sampledIn(Tree $tree, array $byFile): array
    {
        $sampled = [];

        foreach ($this->sample->sampled() as $unit) {
            $sampled = $this->holds($tree, $unit) ? [...$sampled, $this->tallyOf($unit, $byFile)] : $sampled;
        }

        return $sampled;
    }

    private function holds(Tree $tree, Unit $unit): bool
    {
        $holding = $this->trees->holding($unit->path());

        return $holding instanceof Tree && $holding->path()->equals($tree->path());
    }

    /** @param array<string, Tally> $byFile */
    private function tallyOf(Unit $unit, array $byFile): Tally
    {
        if (! $unit->isHeld()) {
            return array_key_exists($unit->path()->value(), $byFile) ? $byFile[$unit->path()->value()] : Tally::none();
        }

        $tally = Tally::none();

        foreach ($byFile as $file => $counted) {
            $tally = Path::of($file)->within($unit->path()) ? $tally->plus($counted) : $tally;
        }

        return $tally;
    }

    /** @return array<string, Tally> each file's mutants, killed of counted, by its path */
    private function talliesByFile(JudgedMutants $mutants): array
    {
        $byFile = [];

        foreach ($mutants as $mutant) {
            $file = $mutant->mutant()->location()->file()->value();
            $scoring = $mutant->judgement()->scoring($this->uncovered);
            $one = Tally::of($scoring === Scoring::Killed ? 1 : 0, $scoring === Scoring::LeftOut ? 0 : 1);
            $byFile[$file] = array_key_exists($file, $byFile) ? $byFile[$file]->plus($one) : $one;
        }

        return $byFile;
    }
}
