<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function array_values;

use ArrayIterator;

use function implode;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\NotGiven;
use Traversable;

/**
 * The contexts whose blocks a run lays over the rest of each layer, in order, later winning (ADR-0071).
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

    /** A run in CI: `ci`, then the CI's own, where it is known, then the kind of run's, where it has one. */
    public static function inCi(Context|NotGiven $ci, Context|NotGiven $kind): self
    {
        return new self([
            Context::Ci,
            ...$ci instanceof Context ? [$ci] : [],
            ...$kind instanceof Context ? [$kind] : [],
        ]);
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
