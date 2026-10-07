<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/** A run no survivor has yet made certain to fail, or one that cannot stop once one does. */
final readonly class Undoomed
{
    public static function run(): self
    {
        return new self();
    }
}
