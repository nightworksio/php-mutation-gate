<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

/**
 * How a test failed a run, as PHPUnit names it in a JUnit log's elements and
 * in the lists it prints after a run.
 *
 * @internal
 */
enum Defect: string
{
    /** An assertion the test failed. */
    case Failure = 'failure';

    /** An error the test met. */
    case Error = 'error';

    /** Whether a JUnit log's element, or a printed list's kind, names a defect. */
    public static function names(string $name): bool
    {
        return self::tryFrom($name) instanceof self;
    }
}
