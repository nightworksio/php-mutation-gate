<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

/**
 * How a split stream's child came out of a test (ADR-0117).
 *
 * @internal
 */
enum ChildEnd
{
    case Passed;
    case Failed;
    case Ended;
    case TimedOut;

    /** Whether it kills its mutant, where the parent passed the test. */
    public function kills(): bool
    {
        return $this === self::Failed || $this === self::Ended;
    }
}
