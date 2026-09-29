<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use LogicException;

use function sprintf;

/**
 * A setting was read as a type its definition does not produce. The
 * definition reads every value it hands on, so reaching this is a mistake in
 * the code that asked.
 */
final class MisreadSetting extends LogicException
{
    public static function as(string $key, string $what): self
    {
        return new self(sprintf('The setting "%s" was read as %s, which its definition does not make.', $key, $what));
    }
}
