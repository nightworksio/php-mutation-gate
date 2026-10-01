<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

/** The original file of a mutant as the project holds it, which the warm-up already analysed. */
final readonly class AsWritten
{
    public static function file(): self
    {
        return new self();
    }
}
