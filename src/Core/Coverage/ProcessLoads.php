<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_any;
use function array_flip;
use function array_key_exists;
use function array_values;
use function count;
use function min;

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The tests one process of a coverage run ran, in their order, and what each loaded (ADR-0115). A process keeps what it
 * loaded, so a package counts as loaded by the test that first loaded it and by every test after it there. A test that
 * started a process counts as loading every package.
 */
final readonly class ProcessLoads
{
    /** @param list<TestLoad> $tests */
    private function __construct(private array $tests)
    {
    }

    /** The tests of one process, in the order it ran them. */
    public static function of(TestLoad ...$tests): self
    {
        return new self(array_values($tests));
    }

    /** @return Listed<TestLoad> each test, in the order the process ran them */
    public function tests(): Listed
    {
        return Listed::of(...$this->tests);
    }

    /**
     * The tests that loaded any of these packages, or could have.
     *
     * @param Listed<string> $packages
     */
    public function testsLoading(Listed $packages): TestIds
    {
        $first = $this->firstLoad($packages);
        $tests = [];

        foreach ($this->tests as $at => $test) {
            if ($at >= $first || $test->startsProcesses()) {
                $tests[] = $test->test();
            }
        }

        return TestIds::of(...$tests);
    }

    /**
     * Where in the process any of these packages first loaded; past its last test where none did.
     *
     * @param Listed<string> $packages
     */
    private function firstLoad(Listed $packages): int
    {
        $asked = array_flip([...$packages]);
        $first = count($this->tests);

        foreach ($this->tests as $at => $test) {
            $loaded = array_any(
                [...$test->packages()],
                static fn(string $package): bool => array_key_exists($package, $asked),
            );
            $first = $loaded ? min($first, $at) : $first;
        }

        return $first;
    }
}
