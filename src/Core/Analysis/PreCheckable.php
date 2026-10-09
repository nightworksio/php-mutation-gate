<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A mutant a runner made, offered to static analysis before its tests: the
 * mutant, as its analyser reads it, and how long the tests that would judge
 * it take, which a rejection saves.
 */
final readonly class PreCheckable
{
    private function __construct(private Mutant $mutant, private Checkable $checkable, private Seconds $tests)
    {
    }

    public static function of(Mutant $mutant, Checkable $checkable, Seconds $tests): self
    {
        return new self($mutant, $checkable, $tests);
    }

    public function mutant(): Mutant
    {
        return $this->mutant;
    }

    public function checkable(): Checkable
    {
        return $this->checkable;
    }

    /** How long the tests that would judge it take. */
    public function tests(): Seconds
    {
        return $this->tests;
    }
}
