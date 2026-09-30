<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

/** What mutation says of one test, over the mutants it judged with a known result (ADR-0014, decisions 2 and 3). */
enum Standing: string
{
    /** Every mutant it judged with a known result passed it: it certainly caught none. */
    case KillsNothing = 'kills-nothing';
    /** It judged killed mutants and killed none of them first: a suspicion, never a finding. */
    case NeverFirst = 'never-first';
    /** It killed a mutant. */
    case Useful = 'useful';
    /** It judged no mutant with a known result. */
    case NotAssessed = 'not-assessed';

    /** What a whole test comes to from its rows: useless only when every row is. */
    public function and(self $row): self
    {
        return match (true) {
            $this === self::Useful || $row === self::Useful => self::Useful,
            $this === self::NotAssessed => $row,
            $row === self::NotAssessed => $this,
            default => $this === self::NeverFirst || $row === self::NeverFirst ? self::NeverFirst : self::KillsNothing,
        };
    }

    /** Whether the report lists a test that stands so. */
    public function isUseless(): bool
    {
        return $this === self::KillsNothing || $this === self::NeverFirst;
    }
}
