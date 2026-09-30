<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Testing;

use LogicException;

use function sprintf;

/** A test handed the testing kit code that is not PHP, which is a mistake in the test. */
final class NotParsed extends LogicException
{
    public static function because(string $why): self
    {
        return new self(sprintf('The code a mutator was tested on is not PHP: %s', $why));
    }
}
