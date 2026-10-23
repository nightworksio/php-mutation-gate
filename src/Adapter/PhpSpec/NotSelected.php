<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use PhpSpec\Exception\Example\SkippingException;

/** An example the run does not select, skipped as PhpSpec skips one: before anything of it runs. */
final class NotSelected extends SkippingException
{
    private const string NOT_SELECTED = 'The gate runs only the examples it selects.';

    public static function byTheRun(): self
    {
        return new self(self::NOT_SELECTED);
    }
}
