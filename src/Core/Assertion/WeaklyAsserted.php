<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use NightWorksIO\MutationGate\Core\Php\Nameless;

/**
 * What a survivor an assertion of value would kill says of its tests: those
 * of them that are weak, and the function whose result such an assertion
 * would check (ADR-0025, decisions 6 and 7).
 */
final readonly class WeaklyAsserted
{
    private function __construct(private string|Nameless $function, private WeakTest $first, private WeakTests $tests)
    {
    }

    /** Asserted weakly around a function, or outside one, by one weak test at least. */
    public static function by(string|Nameless $function, WeakTest $first, WeakTest ...$more): self
    {
        return new self($function, $first, WeakTests::of($first, ...$more));
    }

    /** The first weak test, the one a hint names. */
    public function first(): WeakTest
    {
        return $this->first;
    }

    /** Every weak test, the first among them. */
    public function tests(): WeakTests
    {
        return $this->tests;
    }

    /** The function around the survivor, whose result an assertion of value would check; nothing outside one. */
    public function function(): string|Nameless
    {
        return $this->function;
    }
}
