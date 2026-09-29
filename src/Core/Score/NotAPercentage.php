<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

use LogicException;

use function sprintf;

/**
 * A floor or a score was built from a number outside 0 to 100. A config's
 * floor is checked where the config is validated, so reaching this is a
 * mistake in the code that built it.
 */
final class NotAPercentage extends LogicException
{
    public static function of(float $percent): self
    {
        return new self(sprintf('%s is not a percentage from 0 to 100.', $percent));
    }
}
