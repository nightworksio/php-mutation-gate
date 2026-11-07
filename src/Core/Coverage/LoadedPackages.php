<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * What the tests of a coverage run loaded, process by process, where its runner recorded it (ADR-0113). A package
 * loaded only in a process no test of the run started, such as a worker already running, is not seen.
 */
final readonly class LoadedPackages
{
    /** @param list<ProcessLoads> $processes */
    private function __construct(private array $processes)
    {
    }

    /** No record: the run's runner kept none, or the map was measured without `reach.lockMoves: loaded`. */
    public static function unrecorded(): self
    {
        return new self([]);
    }

    public static function of(ProcessLoads ...$processes): self
    {
        return new self(array_values($processes));
    }

    /** These processes, and those of a later run of some of the tests. */
    public function and(self $later): self
    {
        return new self([...$this->processes, ...$later->processes]);
    }

    /** @return Listed<ProcessLoads> */
    public function processes(): Listed
    {
        return Listed::of(...$this->processes);
    }

    /** Whether every one of these tests is recorded, so what the record does not name none of them loaded. */
    public function covers(TestIds $tests): bool
    {
        return $this->processes !== [] && count($tests->without($this->recorded())) === 0;
    }

    /**
     * The tests that loaded any of these packages, or could have.
     *
     * @param Listed<string> $packages
     */
    public function testsLoading(Listed $packages): TestIds
    {
        $tests = TestIds::none();

        foreach ($this->processes as $process) {
            $tests = $tests->and($process->testsLoading($packages));
        }

        return $tests;
    }

    private function recorded(): TestIds
    {
        $tests = TestIds::none();

        foreach ($this->processes as $process) {
            foreach ($process->tests() as $test) {
                $tests = $tests->with($test->test());
            }
        }

        return $tests;
    }
}
