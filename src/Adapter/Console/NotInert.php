<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use LogicException;

/**
 * Code handed the inert console a standard error that is no stream, which it
 * cannot make inert. The gate hands it none, so reaching this is a mistake in
 * the code that did.
 */
final class NotInert extends LogicException
{
    public static function handed(): self
    {
        return new self('The inert console writes standard error to a stream alone, which it makes inert.');
    }
}
