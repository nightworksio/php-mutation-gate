<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_values;

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Test\TestId;

/**
 * What one test added to what its process had loaded (ADR-0113): the Composer packages whose files first loaded by
 * the time it finished, by name, and whether it started another process, whose loads no process of the run sees.
 */
final readonly class TestLoad
{
    /** @param list<string> $packages */
    private function __construct(private TestId $test, private array $packages, private bool $startsProcesses)
    {
    }

    /** This test, which first loaded these packages of its process. */
    public static function of(string $test, string ...$packages): self
    {
        return new self(TestId::of($test), array_values($packages), startsProcesses: false);
    }

    /** The same test, which started a process. */
    public function startingProcesses(): self
    {
        return new self($this->test, $this->packages, startsProcesses: true);
    }

    public function test(): TestId
    {
        return $this->test;
    }

    /** @return Listed<string> */
    public function packages(): Listed
    {
        return Listed::of(...$this->packages);
    }

    public function startsProcesses(): bool
    {
        return $this->startsProcesses;
    }
}
