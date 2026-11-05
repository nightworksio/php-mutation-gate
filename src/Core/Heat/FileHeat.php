<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Heat;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/**
 * One file of the heatmap (ADR-0084): its score, those of the functions
 * that hold its mutants, lowest first, the floor of the tree it is in, and
 * whether the change reaches it: whether one of its mutants is on a
 * changed line.
 */
final readonly class FileHeat
{
    /** @param Listed<Heat> $functions lowest score first */
    private function __construct(
        private Heat $heat,
        private Listed $functions,
        private Floor|Exempt|Undeclared $floor,
        private bool $touched,
    ) {
    }

    /** @param Listed<Heat> $functions */
    public static function of(Heat $heat, Listed $functions, Floor|Exempt|Undeclared $floor, bool $touched): self
    {
        return new self($heat, Listed::of(...Heats::lowestFirst([...$functions])), $floor, $touched);
    }

    /** This, its score and each function's compared with the base's file. */
    public function comparedWith(self $base): self
    {
        $before = [];

        foreach ($base->functions as $function) {
            $before[$function->name()] = $function->score();
        }

        $functions = [];

        foreach ($this->functions as $function) {
            $functions[] = array_key_exists($function->name(), $before)
                ? $function->comparedWith($before[$function->name()])
                : $function;
        }

        return new self(
            $this->heat->comparedWith($base->heat->score()),
            Listed::of(...$functions),
            $this->floor,
            $this->touched,
        );
    }

    public function path(): Path
    {
        return Path::of($this->heat->name());
    }

    public function heat(): Heat
    {
        return $this->heat;
    }

    /** @return Listed<Heat> lowest score first */
    public function functions(): Listed
    {
        return $this->functions;
    }

    /** Whether one of its mutants is on a changed line. */
    public function isTouched(): bool
    {
        return $this->touched;
    }

    /** Whether its score is below the floor of its tree. */
    public function isBelowFloor(): bool
    {
        $score = $this->heat->score();

        return $this->floor instanceof Floor
            && $score instanceof Score
            && $score->hundredths() < $this->floor->hundredths();
    }
}
