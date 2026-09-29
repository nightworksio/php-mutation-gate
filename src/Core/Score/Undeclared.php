<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

/** No floor was declared. The gate refuses to hold a tree to a floor nobody chose. */
final readonly class Undeclared
{
    public static function floor(): self
    {
        return new self();
    }
}
