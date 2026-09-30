<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/**
 * What became of a set of mutants held to a floor. A set with nothing to
 * mutate passes, and says so rather than showing 100%. An exempt tree is not
 * mutated, and is printed with its reason.
 */
enum Judgement: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case NothingToMutate = 'nothing-to-mutate';
    case Exempt = 'exempt';

    /** A score held to a floor. With no floor anywhere the score passes; a run in CI refuses that before it judges. */
    public static function of(Floor|Exempt|Undeclared $floor, Score|NothingToMutate $score): self
    {
        return match (true) {
            $floor instanceof Exempt => self::Exempt,
            $score instanceof NothingToMutate => self::NothingToMutate,
            $floor instanceof Floor && $score->hundredths() < $floor->hundredths() => self::Failed,
            default => self::Passed,
        };
    }
}
