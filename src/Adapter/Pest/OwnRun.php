<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_diff;
use function array_map;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * What the plugin recorded of a mutant's own process, by the mutated copy it
 * ran on: the tests that failed or errored in it, in the order they did,
 * which of them errored, and the test files it was narrowed to load. Any two
 * mutants that leave the same source share their mutated copy, and so this,
 * even under different mutators.
 */
final readonly class OwnRun
{
    /**
     * @param list<string> $killers
     * @param list<string> $errored
     * @param list<string> $loaded
     */
    private function __construct(private array $killers, private array $errored, private array $loaded)
    {
    }

    /**
     * @param list<string> $killers the tests that failed or errored, in order, by their ids
     * @param list<string> $errored those of them that errored rather than fail an assertion
     * @param list<string> $loaded  the test files it was narrowed to load, or none where it loaded every one
     */
    public static function of(array $killers, array $errored, array $loaded): self
    {
        return new self($killers, $errored, $loaded);
    }

    /** The tests that failed in it, in the order they failed: the first killed the mutant. */
    public function killers(): TestIds
    {
        return TestIds::of(...array_map(TestId::of(...), $this->killers));
    }

    /**
     * Whether every test named as a killer errored rather than fail an
     * assertion, as a test does whose own code a run could not load.
     */
    public function killedByErrorsOnly(): bool
    {
        return $this->killers !== [] && array_diff($this->killers, $this->errored) === [];
    }

    /**
     * The test files it was narrowed to load, by their paths on disk, or none
     * where it loaded every test file.
     *
     * @return list<string>
     */
    public function narrowedTo(): array
    {
        return $this->loaded;
    }
}
