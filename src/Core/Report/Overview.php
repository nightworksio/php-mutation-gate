<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;
use function in_array;

use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * What every report reads off a verdict beyond its parts: the whole
 * project's score, how uncovered mutants count, which mutants are in a set
 * that failed, whether a tree below its floor, new code or a security set
 * below its own, and which an ignore or a proof left out.
 */
final readonly class Overview
{
    /**
     * @param array<string, true> $failing  the ids of the mutants in a set that failed
     * @param array<string, true> $security the ids of the security mutants
     */
    private function __construct(
        private Verdict $verdict,
        private Uncovered $uncovered,
        private array $failing,
        private array $security,
    ) {
    }

    public static function of(Verdict $verdict): self
    {
        $uncovered = Uncovered::Count;
        $failing = [];

        foreach ($verdict->trees() as $tree) {
            $uncovered = $tree->uncovered();
            $failing += self::idsIf($tree->judgement(), $tree->mutants());
        }

        foreach ($verdict->sets()->newCode() as $set) {
            $failing += self::idsIf($set->judgement(), $set->mutants());
        }

        $security = [];

        foreach ($verdict->sets()->security() as $set) {
            $failing += self::idsIf($set->judgement(), $set->mutants());
            $security += self::ids($set->mutants());
        }

        return new self($verdict, $uncovered, $failing, $security);
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

    /**
     * Every mutant an ignore left out of the score, by the config or by a
     * runner's own marker, which every report lists (ADR-0008, decision 4).
     *
     * @return list<JudgedMutant>
     */
    public function ignored(): array
    {
        return $this->judgedAs(MutantJudgement::Ignored, MutantJudgement::IgnoredByMarker);
    }

    /**
     * Every mutant a proof found equivalent (ADR-0013, decision 12).
     *
     * @return list<JudgedMutant>
     */
    public function equivalent(): array
    {
        return $this->judgedAs(MutantJudgement::Equivalent);
    }

    /** Whether a mutant is in a set that failed: a tree below its floor, or new code or security below its own. */
    public function isFailing(JudgedMutant $mutant): bool
    {
        return array_key_exists($mutant->mutant()->id()->value(), $this->failing);
    }

    /** Whether a mutant is a security mutant, which its package's security set holds (ADR-0021, decision 16). */
    public function isSecurity(JudgedMutant $mutant): bool
    {
        return array_key_exists($mutant->mutant()->id()->value(), $this->security);
    }

    /**
     * The security mutants the score counts as not killed, those on changed lines first.
     *
     * @return list<JudgedMutant>
     */
    public function securitySurvivors(): array
    {
        $survivors = [];

        foreach ($this->survivors() as $mutant) {
            $survivors = $this->isSecurity($mutant) ? [...$survivors, $mutant] : $survivors;
        }

        return $survivors;
    }

    /**
     * The mutants reported in full that are judged one of these ways, in the
     * verdict's order; a kill a ledger proved is never one.
     *
     * @return list<JudgedMutant>
     */
    private function judgedAs(MutantJudgement ...$judgements): array
    {
        $judged = [];

        foreach ($this->verdict->trees()->mutants() as $mutant) {
            if ($mutant instanceof JudgedMutant && in_array($mutant->judgement(), $judgements, strict: true)) {
                $judged[] = $mutant;
            }
        }

        return $judged;
    }

    /** @return array<string, true> the ids of these mutants, where their set failed */
    private static function idsIf(Judgement $judgement, JudgedMutants $mutants): array
    {
        return $judgement === Judgement::Failed ? self::ids($mutants) : [];
    }

    /** @return array<string, true> */
    private static function ids(JudgedMutants $mutants): array
    {
        $ids = [];

        foreach ($mutants as $mutant) {
            $ids[$mutant->mutant()->id()->value()] = true;
        }

        return $ids;
    }
}
