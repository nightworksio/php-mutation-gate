<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function array_values;

use ArrayIterator;

use function implode;

use IteratorAggregate;
use Traversable;

/**
 * The contexts whose blocks a run lays over the rest of each layer, in order, later winning (ADR-0070).
 *
 * @implements IteratorAggregate<int, Context>
 */
final readonly class ContextChain implements IteratorAggregate
{
    /** @param list<Context> $contexts */
    private function __construct(private array $contexts)
    {
    }

    public static function of(Context ...$contexts): self
    {
        return new self(array_values($contexts));
    }

    /** No context: every layer without its blocks. */
    public static function none(): self
    {
        return new self([]);
    }

    /** @return Traversable<int, Context> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->contexts);
    }

    /** The contexts' keys, comma-separated: `ci, pr`. */
    public function spelt(): string
    {
        return implode(', ', array_map(static fn(Context $context): string => $context->value, $this->contexts));
    }
}
