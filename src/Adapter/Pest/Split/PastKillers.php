<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

/**
 * What a split stream forks a mutant past its likely killer for, in later
 * tests (ADR-0174, ADR-0175): a killer it fell back on, which forks
 * nothing, and a mutant whose site a test reached holding a descriptor a
 * child would share.
 *
 * @internal
 */
final class PastKillers
{
    /** @var array<string, true> the tests the stream fell back on so far, by their ids */
    private array $tests = [];

    /** @var array<string, true> the mutants a test held a descriptor at the site of, by their ids */
    private array $mutants = [];

    /** The stream fell back on this test. */
    public function fellBackOn(string $test): void
    {
        $this->tests[$test] = true;
    }

    /** A test held a descriptor a child would share at this mutant's site. */
    public function heldAt(string $mutant): void
    {
        $this->mutants[$mutant] = true;
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
}
