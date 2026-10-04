<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;

/**
 * What one suite alone kills: of the mutants its tests cover, how many one
 * of them killed (ADR-0025, decision 8). It is exact under a full kill
 * matrix, and otherwise a lower bound, read from first killers, which the
 * reports show as *at least*. It is reported, never judged (decision 10).
 */
final readonly class SuiteScore
{
    private function __construct(private string $suite, private int $covered, private int $killed, private bool $exact)
    {
    }

    /** A suite's tally: the mutants its tests cover, those they killed, and whether every killer was recorded. */
    public static function of(string $suite, int $covered, int $killed, bool $exact): self
    {
        return new self($suite, $covered, $killed, $exact);
    }

    /** The suite's name, as the PHPUnit config declares it. */
    public function suite(): string
    {
        return $this->suite;
    }

    /** How many mutants the suite's tests cover, with a known outcome. */
    public function covered(): int
    {
        return $this->covered;
    }

    /** How many of those a test of the suite killed. */
    public function killed(): int
    {
        return $this->killed;
    }

    /** Killed over covered; nothing to mutate where its tests cover no mutant. */
    public function score(): Score|NothingToMutate
    {
        return Score::of($this->killed, $this->covered);
    }

    /** Whether the score is exact, as a full kill matrix makes it; a lower bound otherwise. */
    public function isExact(): bool
    {
        return $this->exact;
    }
}
