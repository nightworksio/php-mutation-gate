<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/** A function or method no file read declares: outside the project, or one a name cannot be followed to. */
final readonly class Undeclared
{
    private function __construct()
    {
    }

    public static function callee(): self
    {
        return new self();
    }
}
