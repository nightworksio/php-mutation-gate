<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use LogicException;

use function sprintf;

/**
 * A setting was asked for a value its reading does not hold: one that must
 * be written, read with problems, or a part a layer does not hold. A layer
 * is read whole, so reaching this is a mistake in the code that asked.
 */
final class MisreadSetting extends LogicException
{
    public static function as(string $key, string $what): self
    {
        return new self(sprintf('The setting "%s" was read as %s, which its definition does not make.', $key, $what));
    }

    /** A value asked of a reading that holds none. */
    public static function nothingIn(string $what): self
    {
        return new self(sprintf('The reading holds no value for %s, though it has no problem.', $what));
    }
}
