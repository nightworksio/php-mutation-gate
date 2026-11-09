<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use RuntimeException;

use function sprintf;

/**
 * A split stream's directory, which the gate could not make as its own: the
 * path was already there, or a link, or could not be made (ADR-0117).
 *
 * @internal
 */
final class UnmadeDirectory extends RuntimeException
{
    private const string UNMADE = 'The gate cannot make %s as a directory of its own for its split streams.';

    public static function at(string $directory): self
    {
        return new self(sprintf(self::UNMADE, $directory));
    }
}
