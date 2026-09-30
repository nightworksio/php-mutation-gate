<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use ArrayIterator;

use function count;

use Countable;

use function in_array;

use IteratorAggregate;
use Traversable;

/**
 * Every `#[Holds]` a test file writes, in the order it writes them.
 *
 * @implements IteratorAggregate<int, HoldsAttribute>
 */
final readonly class HoldsAttributes implements Countable, IteratorAggregate
{
    /** Where a `#[Holds]` is a declaration a runner can select by its class or method. */
    private const array SELECTABLE = [Standing::TestClass, Standing::TestMethod];

    /** @param list<HoldsAttribute> $attributes */
    private function __construct(private array $attributes)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function with(HoldsAttribute $attribute): self
    {
        return new self([...$this->attributes, $attribute]);
    }

    /**
     * The holding each `#[Holds]` on a class or method declares, held by the
     * class or method it stands on, with the path as a literal holds it or
     * as the expression is written.
     */
    public function holdings(): Holdings
    {
        $holdings = Holdings::none();

        foreach ($this->attributes as $attribute) {
            $holdings = in_array($attribute->standing(), self::SELECTABLE, strict: true)
                ? $holdings->with(Holding::byAttribute($attribute->path()->text(), $attribute->holder()))
                : $holdings;
        }

        return $holdings;
    }

    public function count(): int
    {
        return count($this->attributes);
    }

    /** @return Traversable<int, HoldsAttribute> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->attributes);
    }
}
