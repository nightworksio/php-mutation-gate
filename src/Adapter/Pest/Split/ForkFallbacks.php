<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

/**
 * What a split stream's parent falls back on, each written to its results
 * (ADR-0117, ADR-0175): a test it forks nothing in, and a mutant whose site
 * a test reached holding a descriptor a child would share, which it forks
 * nowhere in that test; and the mutants it forks past their likely killer
 * in later tests for either (ADR-0174).
 *
 * @internal
 */
final class ForkFallbacks
{
    /** @var array<string, true> the tests the stream fell back on so far, by their ids */
    private array $tests = [];

    /** @var array<string, true> the mutants a test held a descriptor at the site of, by their ids */
    private array $mutants = [];

    public function __construct(private readonly StreamPlan $plan, private readonly ParentLines $lines)
    {
    }

    /** A test began: one the stream cannot fork in falls back. */
    public function began(CurrentTest $test): void
    {
        if (! $test->isForkable()) {
            $this->fellBackOn($test);
        }
    }

    /**
     * The test runs in a process of its own, or reached what a child would
     * share: it falls back in the parent where it had not, and forks nothing
     * more.
     */
    public function isolated(CurrentTest $test, bool $parent): void
    {
        $this->lines->isolated($test->id);

        if ($test->isForkable() && $parent) {
            $this->fellBackOn($test);
        }

        $test->isolate();
    }

    /**
     * The stream cannot fork this site of this mutant in the parent's test:
     * a test that started a PHP process, or began holding a descriptor a
     * child would share, which it may hold at every site, forks nothing
     * more; one that holds a descriptor it opened since forks the mutant
     * nowhere else in it, which reached the site unmutated, and the stream
     * forks it at later tests.
     */
    public function unforked(CurrentTest $test, bool $ran, string $file, int $id, string $mutant): void
    {
        $ran || $test->beganHolding() ? $this->isolated($test, parent: true) : $this->held($test, $file, $id, $mutant);
    }

    /**
     * Whether the stream forks this mutant, whose likely killer this is, ''
     * where the ledger names none, at this test: at any test where it names
     * none, or forks past it; otherwise at that killer alone.
     */
    public function forks(string $mutant, string $killer, string $test): bool
    {
        return $killer === ''
            || $killer === $test
            || array_key_exists($killer, $this->tests)
            || array_key_exists($mutant, $this->mutants);
    }

    private function held(CurrentTest $test, string $file, int $id, string $mutant): void
    {
        $test->forkAt($file, $id);
        $this->mutants[$mutant] = true;
        $this->lines->isolated($test->id);
        $this->plan->recorded(StreamLine::fallback($test->id, $mutant));
    }

    private function fellBackOn(CurrentTest $test): void
    {
        $this->tests[$test->id] = true;
        $this->plan->recorded(StreamLine::fallback($test->id));
    }
}
