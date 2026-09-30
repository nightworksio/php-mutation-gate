<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/**
 * A line coverage can speak for: executable, or declaring nothing that code
 * elsewhere reads, such as a global constant, which runs where it stands.
 */
final readonly class Executable
{
    public static function line(): self
    {
        return new self();
    }
}
