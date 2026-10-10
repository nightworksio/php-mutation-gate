<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_key_exists;
use function array_map;

use Closure;

use function count;

/**
 * The tests each test method depends on, as PHPUnit's `#[Depends]`
 * attributes name them: PHPUnit skips a test whose dependency did not run
 * before it in the same run, so a run that selects a test must select what it
 * depends on too.
 */
final readonly class TestDependencies
{
    /** @param array<string, list<string>> $dependencies each method's dependencies, by `<class>::<method>` */
    private function __construct(private array $dependencies)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, with a method depending on another. */
    public function with(string $method, string $dependency): self
    {
        $dependencies = $this->dependencies;
        $dependencies[$method][] = $dependency;

        return new self($dependencies);
    }

    /** These, with every dependency the others record. */
    public function and(self $more): self
    {
        $dependencies = $this->dependencies;

        foreach ($more->dependencies as $method => $each) {
            $before = array_key_exists($method, $dependencies) ? $dependencies[$method] : [];
            $dependencies[$method] = [...$before, ...$each];
        }

        return new self($dependencies);
    }

    /**
     * These tests and every test they depend on, in turn, each test file read
     * once for the tests it is asked about: a row of a data set depends on
     * what its method depends on.
     *
     * @param Closure(TestIds): self $read the dependencies the files of these tests' classes record
     */
    public static function closure(TestIds $tests, Closure $read): TestIds
    {
        $all = $tests;
        $asked = $tests;
        $known = self::none();

        while (count($asked) > 0) {
            $known = $known->and($read($asked));
            $more = TestIds::none();

            foreach ($asked as $test) {
                $more = $more->and($known->of($test));
            }

            $asked = $more->without($all);
            $all = $all->and($asked);
        }

        return $all;
    }

    /** What one test depends on directly; nothing for an id that names no test method. */
    private function of(TestId $test): TestIds
    {
        $method = TestMethod::of($test);
        $key = $method instanceof TestMethod
            ? TestMethod::id($method->className(), $method->method())->value()
            : $test->value();

        return TestIds::of(...array_map(
            TestId::of(...),
            array_key_exists($key, $this->dependencies) ? $this->dependencies[$key] : [],
        ));
    }
}
