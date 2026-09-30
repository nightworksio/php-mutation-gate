<?php

declare(strict_types=1);

namespace Library;

/**
 * The line every shape of held test runs: a mutant turns its true to false,
 * which no held test asserts, so under the group that mutant survives and each
 * held test runs in the mutant's own process.
 */
final readonly class Shapes
{
    /** The path the class-constant shape holds, which the plugin evaluates. */
    public const string PATH = 'src/Shapes.php';

    public function seen(): bool
    {
        return true;
    }
}
