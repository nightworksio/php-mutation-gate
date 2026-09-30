<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * What every report reads off a verdict beyond its parts: the whole
 * project's score, how uncovered mutants count, and which mutants are in a
 * set that failed, whether a tree below its floor or new code below its own.
 */
final readonly class Overview
{
    /** @param array<string, true> $failing the ids of the mutants in a set that failed */
    private function __construct(private Verdict $verdict, private Uncovered $uncovered, private array $failing)
    {
    }

    public static function of(Verdict $verdict): self
    {
        $uncovered = Uncovered::Count;
        $failing = [];

        foreach ($verdict->trees() as $tree) {
            $uncovered = $tree->uncovered();
            $failing += self::idsIf($tree->judgement(), $tree->mutants());
        }

        foreach ($verdict->newCode() as $set) {
            $failing += self::idsIf($set->judgement(), $set->mutants());
        }

        return new self($verdict, $uncovered, $failing);
    }

    /** How uncovered mutants count: as every tree counts them, which one setting decides. */
    public function uncovered(): Uncovered
    {
        return $this->uncovered;
    }

    /** The score over every mutant of the project, by the same formula as a tree's (ADR-0009, decision 5). */
    public function score(): Score|NothingToMutate
    {
        return $this->verdict->trees()->mutants()->counts()->score($this->uncovered);
    }

    /** Every mutant the score counts as not killed, those on changed lines first. */
    public function survivors(): Survivors
    {
        return $this->verdict->trees()->mutants()->survivors($this->uncovered);
    }

    /** Whether a mutant is in a set that failed: a tree below its floor, or new code below its own. */
    public function isFailing(JudgedMutant $mutant): bool
    {
        return array_key_exists($mutant->mutant()->id()->value(), $this->failing);
    }

    /** @return array<string, true> */
    private static function idsIf(Judgement $judgement, JudgedMutants $mutants): array
    {
        $ids = [];

        foreach ($judgement === Judgement::Failed ? $mutants : [] as $mutant) {
            $ids[$mutant->mutant()->id()->value()] = true;
        }

        return $ids;
    }
}
