<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_key_exists;
use function array_values;
use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Unmutated controls, each once, in the order first asked for (see Control).
 *
 * @implements IteratorAggregate<int, Control>
 */
final readonly class Controls implements Countable, IteratorAggregate
{
    /** @param array<string, Control> $controls by key */
    private function __construct(private array $controls)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Control ...$controls): self
    {
        $keyed = [];

        foreach ($controls as $control) {
            $keyed += [$control->key() => $control];
        }

        return new self($keyed);
    }

    /** These, and one more where it is not one of them. */
    public function with(Control $control): self
    {
        return new self($this->controls + [$control->key() => $control]);
    }

    /** Whether a control is one of these. */
    public function has(Control $control): bool
    {
        return array_key_exists($control->key(), $this->controls);
    }

    public function count(): int
    {
        return count($this->controls);
    }

    public function getIterator(): Traversable
    {
        yield from array_values($this->controls);
    }
}
