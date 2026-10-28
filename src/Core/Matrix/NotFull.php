<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use NightWorksIO\MutationGate\Core\GateCommand;

use function sprintf;

/** Why a kill matrix holds first killers only, which the `tests` report says in place of its removable tests. */
enum NotFull
{
    /** The run recorded first killers; `run --kill-matrix=full` records every killer. */
    case FirstKillers;
    /** The runner is Infection, which stops each mutant at its first failing test (ADR-0014). */
    case Infection;

    private const string NEEDS = 'Needs a full kill matrix: run `%s`.';

    private const string CANNOT
        = 'Infection cannot produce a full kill matrix: it stops each mutant at its first failing test.';

    /** What the report says in place of the removable tests. */
    public function sentence(GateCommand $gate): string
    {
        return match ($this) {
            self::FirstKillers => sprintf(self::NEEDS, $gate->fullMatrix()),
            self::Infection => self::CANNOT,
        };
    }
}
