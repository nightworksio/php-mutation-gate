<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Recheck;

/**
 * A survivor the re-check did not run again: its budget was spent before its
 * file's turn, or the job that took its file left no result (ADR-0106).
 *
 * @internal
 */
final readonly class Unreached
{
    public static function value(): self
    {
        return new self();
    }
}
