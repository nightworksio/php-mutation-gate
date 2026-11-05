<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Heat;

use function max;

use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;

/**
 * The score of a file or of one of its functions, as its tree counts it,
 * how many of its mutants the score counts as not killed, and its score on
 * the base, for its change against it, where the base has one (ADR-0084).
 */
final readonly class Heat
{
    private function __construct(
        private string $name,
        private Score|NothingToMutate $score,
        private int $notKilled,
        private Score|NothingToMutate|Unrecorded $base,
    ) {
    }

    /** These mutants scored, as a tree that counts uncovered mutants so scores them. */
    public static function of(string $name, JudgedMutants $mutants, Uncovered $uncovered): self
    {
        $counts = $mutants->counts();

        return new self($name, $counts->score($uncovered), $counts->notKilled($uncovered), Unrecorded::floor());
    }

    /** This, with its score on the base. */
    public function comparedWith(Score|NothingToMutate $base): self
    {
        return new self($this->name, $this->score, $this->notKilled, $base);
    }

    /** A file's path, or a function's name. */
    public function name(): string
    {
        return $this->name;
    }

    public function score(): Score|NothingToMutate
    {
        return $this->score;
    }

    public function notKilled(): int
    {
        return $this->notKilled;
    }

    public function base(): Score|NothingToMutate|Unrecorded
    {
        return $this->base;
    }

    /** How many hundredths of a percent its score fell since the base; none where it rose, held or is unscored. */
    public function drop(): int
    {
        return $this->score instanceof Score && $this->base instanceof Score
            ? max(0, $this->base->hundredths() - $this->score->hundredths())
            : 0;
    }
}
