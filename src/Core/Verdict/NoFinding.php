<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/** A survivor whose tests say nothing beyond its hint's first sentence. */
final readonly class NoFinding
{
    private function __construct()
    {
    }

    public static function survivor(): self
    {
        return new self();
    }
}
