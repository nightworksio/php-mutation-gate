<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

/** A mutator's survivors get the sentence of its family (ADR-0009 decision 7). */
final readonly class FamilyHint
{
    private function __construct()
    {
    }

    public static function ofItsFamily(): self
    {
        return new self();
    }
}
