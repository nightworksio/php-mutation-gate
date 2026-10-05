<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_replace;

use IteratorAggregate;
use Traversable;

/**
 * What a process is told on top of the environment it inherits: each variable
 * set to a value, or unset, by its name.
 *
 * @implements IteratorAggregate<string, string|false>
 */
final readonly class Environment implements IteratorAggregate
{
    /** @param array<string, string|false> $variables by name, false where unset */
    private function __construct(private array $variables)
    {
    }

    /** Nothing told. */
    public static function none(): self
    {
        return new self([]);
    }

    /** One variable told a value. */
    public static function telling(string $name, string $value): self
    {
        return new self([$name => $value]);
    }

    /** One variable unset, whether or not the process would inherit it. */
    public static function unsetting(string $name): self
    {
        return new self([$name => false]);
    }

    /** What this tells, and what another tells besides; a variable both tell is told as the other tells it. */
    public function and(self $more): self
    {
        return new self(array_replace($this->variables, $more->variables));
    }

    /** @return Traversable<string, string|false> each variable's value by its name, false where it is unset */
    public function getIterator(): Traversable
    {
        yield from $this->variables;
    }
}
