<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The kinds of control that asked for one unmutated control, in the order
 * they asked (ADR-0167).
 *
 * @implements IteratorAggregate<int, ControlKind>
 */
final readonly class AskingKinds implements Countable, IteratorAggregate
{
    /** @param list<ControlKind> $kinds */
    private function __construct(private array $kinds)
    {
    }

    public static function of(ControlKind ...$kinds): self
    {
        return new self(array_values($kinds));
    }

    public function count(): int
    {
        return count($this->kinds);
    }

    /** @return Traversable<int, ControlKind> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->kinds);
    }
}
