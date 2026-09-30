<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

/** What an assertion checks (ADR-0025, decision 5). */
enum AssertionKind: string
{
    /** That something is there, or is not: `assertNotNull`, `->toBeTruthy()`. */
    case Existence = 'existence';
    /** A type, a class, a size or a format: `assertIsArray`, `->toHaveCount()`. */
    case Shape = 'shape';
    /** A value, an exception or a snapshot: `assertSame`, `->toBe()`. */
    case Value = 'value';
}
