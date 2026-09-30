<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

/** An assertion the table does not hold, such as a project's own, which leaves its test not assessed. */
final readonly class Unclassified
{
    private function __construct()
    {
    }

    public static function assertion(): self
    {
        return new self();
    }
}
