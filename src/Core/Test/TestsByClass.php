<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_key_exists;
use function array_keys;
use function array_push;
use function count;
use function sort;

/**
 * Some tests, each found by the class it is in, so that asking for the tests
 * of a few classes reads those classes' tests alone, and the files that may
 * declare them are told once.
 */
final readonly class TestsByClass
{
    /**
     * @param list<TestId>             $tests     in their order
     * @param array<string, list<int>> $positions where each class's tests are among them, by the class
     */
    private function __construct(private array $tests, private array $positions, private TestClassFiles $wanting)
    {
    }

    public static function of(TestIds $tests): self
    {
        $ordered = [];
        $positions = [];

        foreach ($tests as $test) {
            $positions[TestMethod::classOf($test)][] = count($ordered);
            $ordered[] = $test;
        }

        return new self($ordered, $positions, TestClassFiles::wanting(array_keys($positions)));
    }

    /** The test files that may declare one of the classes, none found yet. */
    public function wanting(): TestClassFiles
    {
        return $this->wanting;
    }

    /**
     * The tests in these classes, in their order.
     *
     * @param list<string> $classes
     */
    public function in(array $classes): TestIds
    {
        $at = [];

        foreach ($classes as $class) {
            if (array_key_exists($class, $this->positions)) {
                array_push($at, ...$this->positions[$class]);
            }
        }

        sort($at);
        $tests = [];

        foreach ($at as $position) {
            $tests[] = $this->tests[$position];
        }

        return TestIds::of(...$tests);
    }
}
