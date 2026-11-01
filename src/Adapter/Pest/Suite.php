<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function count;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;

/**
 * The tests a coverage map ran, each with its class and its test method,
 * counted by both, read once for every mutant a filter is built for: the
 * tests a filter names exactly can then be named by a class, or a data set,
 * they hold whole (see Wholes). None where it names no test.
 *
 * @internal
 */
final readonly class Suite
{
    /**
     * @param array<string, array{string, string}> $tests   each test's class and method, by its id
     * @param array<string, int>                   $classes how many tests each class has, by its name
     * @param array<string, int>                   $methods how many rows each method's data set has, by its id
     */
    private function __construct(private array $tests, private array $classes, private array $methods)
    {
    }

    public static function none(): self
    {
        return new self([], [], []);
    }

    /** The suite of these tests, each counted once. */
    public static function of(TestIds $tests): self
    {
        return self::named(array_map(static fn(TestId $test): string => $test->value(), [...$tests]));
    }

    /**
     * The suite of these tests, by their ids, each counted once.
     *
     * @param list<string> $tests
     */
    public static function named(array $tests): self
    {
        $parts = [];

        foreach ($tests as $test) {
            $method = TestMethod::of(TestId::of($test));
            $parts += $method instanceof TestMethod
                ? [$test => [$method->className(), TestMethod::id($method->className(), $method->method())->value()]]
                : [];
        }

        $classes = [];
        $methods = [];

        foreach ($parts as [$class, $id]) {
            $classes[$class] = (array_key_exists($class, $classes) ? $classes[$class] : 0) + 1;
            $methods[$id] = (array_key_exists($id, $methods) ? $methods[$id] : 0) + 1;
        }

        return new self($parts, $classes, $methods);
    }

    /**
     * Which classes and data sets these tests hold whole: every test of them
     * the suite names is among them.
     *
     * @param list<string> $tests the tests, by their ids
     */
    public function holding(array $tests): Wholes
    {
        $classes = [];
        $methods = [];

        foreach (array_intersect_key($this->tests, array_flip($tests)) as $test => [$class, $id]) {
            $classes[$class][$test] = true;
            $methods[$id][$test] = true;
        }

        return Wholes::of($this->whole($classes, $this->classes), $this->whole($methods, $this->methods));
    }

    /**
     * The groups whose every test is held.
     *
     * @param  array<string, array<string, true>> $held  the tests held of each group, by the group
     * @param  array<string, int>                 $sizes how many tests each group has, by the group
     * @return list<string>
     */
    private function whole(array $held, array $sizes): array
    {
        $whole = [];

        foreach ($held as $group => $tests) {
            $whole = [...$whole, ...count($tests) === $sizes[$group] ? [$group] : []];
        }

        return $whole;
    }
}
