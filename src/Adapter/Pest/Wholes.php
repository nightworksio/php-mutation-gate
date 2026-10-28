<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_flip;
use function array_key_exists;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestMethod;

/**
 * The classes, and the test methods' data sets, that some tests hold whole:
 * every test of them a suite names is among those tests (see Suite). A
 * filter names each of those whole, and still selects exactly the tests.
 */
final readonly class Wholes
{
    /**
     * @param array<string, int> $classes each class the tests hold whole, by its name
     * @param array<string, int> $methods each method whose data set they hold whole, by its id
     */
    private function __construct(private array $classes, private array $methods)
    {
    }

    /**
     * @param list<string> $classes the classes the tests hold whole
     * @param list<string> $methods the methods whose data sets they hold whole, by their ids
     */
    public static function of(array $classes, array $methods): self
    {
        return new self(array_flip($classes), array_flip($methods));
    }

    /** Whether the tests hold the class of a test, by its id, whole. */
    public function holdClassOf(string $test): bool
    {
        $method = TestMethod::of(TestId::of($test));

        return $method instanceof TestMethod && array_key_exists($method->className(), $this->classes);
    }

    /**
     * The row of its data set a test's id names, as PHPUnit names it; none
     * where it names none, or the tests hold every row of its data set.
     */
    public function rowOf(string $test): string
    {
        $method = TestMethod::of(TestId::of($test));
        $whole = $method instanceof TestMethod
            && array_key_exists(TestMethod::id($method->className(), $method->method())->value(), $this->methods);

        return $method instanceof TestMethod && ! $whole ? $method->dataSet() : '';
    }
}
