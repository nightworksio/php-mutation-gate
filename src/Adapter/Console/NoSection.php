<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use LogicException;

/**
 * Code asked the inert console for a section, which writes past its lines
 * and so could print one that is not inert. The gate draws no section, so
 * reaching this is a mistake in the code that asked.
 */
final class NoSection extends LogicException
{
    public static function drawn(): self
    {
        return new self('The gate writes no console section: one would write past Inert.');
    }
}
