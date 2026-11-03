<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

/**
 * Why the verdict of a commit that passed stands for a plan that runs
 * nothing, each by the key the plan file records the commit under: nothing
 * the gate judges changed since it (ADR-0005), or the change since it reaches
 * no unit and every unit carries its result from it (ADR-0062).
 */
enum StandsBecause: string
{
    case NothingJudgedChanged = 'unchangedSince';
    case NothingReached = 'reachedNothingSince';

    /** Why the plan runs nothing, given the commit that passed and when. */
    public function template(): string
    {
        return match ($this) {
            self::NothingJudgedChanged
                => 'Nothing the gate judges changed since %s, whose verdict passed at %s, so its verdict stands.',
            self::NothingReached
                => 'The change since %s, whose verdict passed at %s, reaches no unit, so its verdict stands.',
        };
    }

    /** What every report says in place of the project's score, given the commit whose verdict stands. */
    public function headline(): string
    {
        return match ($this) {
            self::NothingJudgedChanged => 'The earlier verdict at %s stands.',
            self::NothingReached => 'The default branch\'s earlier verdict at %s stands.',
        };
    }
}
