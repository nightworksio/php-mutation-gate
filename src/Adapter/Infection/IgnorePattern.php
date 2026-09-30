<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

/**
 * One value of `ignore` or `ignoreSourceCodeByRegex` under Infection's
 * `mutators`: the key it is under, the mutator it applies to, and the pattern,
 * over mutated names or over source.
 */
final readonly class IgnorePattern
{
    private function __construct(
        private string $key,
        private string|AnyMutator $mutator,
        private string $pattern,
        private bool $overSource,
    ) {
    }

    /** A pattern over the names of the code a mutant is in, as `ignore` lists them. */
    public static function overNames(string $key, string|AnyMutator $mutator, string $pattern): self
    {
        return new self($key, $mutator, $pattern, overSource: false);
    }

    /** A pattern over source, as `ignoreSourceCodeByRegex` lists them. */
    public static function overSource(string $key, string|AnyMutator $mutator, string $pattern): self
    {
        return new self($key, $mutator, $pattern, overSource: true);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function mutator(): string|AnyMutator
    {
        return $this->mutator;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function isOverSource(): bool
    {
        return $this->overSource;
    }
}
