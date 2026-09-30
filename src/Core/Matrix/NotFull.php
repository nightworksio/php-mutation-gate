<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

/** Why a kill matrix holds first killers only, which the `tests` report says in place of its removable tests. */
enum NotFull
{
    /** The run recorded first killers; `run --kill-matrix=full` records every killer. */
    case FirstKillers;
    /** The runner is Infection, which stops each mutant at its first failing test (ADR-0014, decision 7). */
    case Infection;

    private const string NEEDS = 'Needs a full kill matrix: run `mutation-gate run --kill-matrix=full`.';

    private const string CANNOT
        = 'Infection cannot produce a full kill matrix: it stops each mutant at its first failing test.';

    /** What the report says in place of the removable tests. */
    public function sentence(): string
    {
        return match ($this) {
            self::FirstKillers => self::NEEDS,
            self::Infection => self::CANNOT,
        };
    }
}
