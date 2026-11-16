<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Where a file builds a reflection class that can read a class constant,
 * read once with the file (see Reflections): the token of the class's name,
 * the class in lower case, the class its first argument writes out, where
 * it writes one out, and the constant's name a `ReflectionClassConstant` is
 * built with, where it is written out.
 *
 * @internal
 */
final readonly class ReflectionSite
{
    private function __construct(
        private int $at,
        private string $class,
        private Names|NotGiven $reflected,
        private string|NotGiven $named,
    ) {
    }

    public static function of(int $at, string $class, Names|NotGiven $reflected, string|NotGiven $named): self
    {
        return new self($at, $class, $reflected, $named);
    }

    public function at(): int
    {
        return $this->at;
    }

    public function class(): string
    {
        return $this->class;
    }

    /** The class the first argument writes out; none where it writes out none. */
    public function reflected(): Names|NotGiven
    {
        return $this->reflected;
    }

    /** The constant's name it is built with; none where it is not written out. */
    public function named(): string|NotGiven
    {
        return $this->named;
    }
}
