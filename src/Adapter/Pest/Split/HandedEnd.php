<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

/**
 * How a test's own process, forked from a stream's booted application,
 * ended the test (ADR-0117): passed, failed, or never ended it.
 *
 * @internal
 */
enum HandedEnd
{
    case Passed;
    case Failed;
    case Unended;

    public static function of(bool $passed): self
    {
        return $passed ? self::Passed : self::Failed;
    }
}
