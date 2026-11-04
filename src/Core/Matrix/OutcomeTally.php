<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use function array_key_exists;
use function array_sum;
use function count;

use Countable;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** How many tests had each outcome with one mutant in place (ADR-0014). */
final readonly class OutcomeTally implements Countable
{
    /** @param array<string, int<0, max>> $tests how many tests had each outcome they had, by the outcome */
    private function __construct(private array $tests)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** This tally, with these tests more, which had this outcome. */
    public function with(Outcome $outcome, TestIds $tests): self
    {
        $tally = $this->tests;
        $tally[$outcome->value] = $this->of($outcome) + count($tests);

        return new self($tally);
    }

    /**
     * How many tests had this outcome.
     *
     * @return int<0, max>
     */
    public function of(Outcome $outcome): int
    {
        return array_key_exists($outcome->value, $this->tests) ? $this->tests[$outcome->value] : 0;
    }

    /** Whether any test had this outcome. */
    public function has(Outcome $outcome): bool
    {
        return $this->of($outcome) > 0;
    }

    /** How many tests ran to an answer: they killed the mutant, or passed with it. */
    public function ran(): int
    {
        return $this->of(Outcome::Killed) + $this->of(Outcome::Passed);
    }

    /** How many tests it counts. */
    public function count(): int
    {
        return array_sum($this->tests);
    }
}
