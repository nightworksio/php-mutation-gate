<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

/**
 * No coverage map says what the tests run, so nothing can be read from one.
 *
 * @internal
 */
final readonly class NoMap
{
    public static function toRead(): self
    {
        return new self();
    }
}
