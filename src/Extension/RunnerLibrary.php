<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

use function sprintf;

/**
 * The runner contract's library as one runner spells it: the name it gives the mutator of each `LibraryChange`,
 * what its test files end in, and whether it reports the line a mutant ends on and how long each mutant took.
 */
final readonly class RunnerLibrary
{
    private function __construct(
        private string $adds,
        private string $large,
        private string $unused,
        private string $drains,
        private string $held,
        private string $testEnding,
        private bool $measured,
    ) {
    }

    /**
     * The library as a runner names the mutator of each change, with its test files ending in this, a runner
     * that reports the line each mutant ends on and how long it took.
     */
    public static function spelling(
        string $adds,
        string $large,
        string $unused,
        string $drains,
        string $held,
        string $testEnding = SuiteDirectory::SUFFIX,
    ): self {
        return new self($adds, $large, $unused, $drains, $held, $testEnding, measured: true);
    }

    /** The library as a runner spells it that reports neither the line a mutant ends on nor how long it took. */
    public function unmeasured(): self
    {
        return new self(
            $this->adds,
            $this->large,
            $this->unused,
            $this->drains,
            $this->held,
            $this->testEnding,
            measured: false,
        );
    }

    /** The runner's name for the mutator that makes a change. */
    public function mutatorOf(LibraryChange $change): string
    {
        return match ($change) {
            LibraryChange::Adds => $this->adds,
            LibraryChange::Large => $this->large,
            LibraryChange::Unused => $this->unused,
            LibraryChange::Drains => $this->drains,
            LibraryChange::Held => $this->held,
        };
    }

    /** The runner's mutators for these changes. */
    public function mutators(LibraryChange $change, LibraryChange ...$more): Mutators
    {
        return Mutators::named(
            $this->mutatorOf($change),
            ...array_map($this->mutatorOf(...), $more),
        );
    }

    /** Where a test file is from the library's root. */
    public function testFile(LibraryTest $test): Path
    {
        return Path::of(sprintf('%s%s', $test->value, $this->testEnding));
    }

    /** Whether the runner reports the line each mutant ends on, and how long each mutant it ran took. */
    public function measures(): bool
    {
        return $this->measured;
    }
}
