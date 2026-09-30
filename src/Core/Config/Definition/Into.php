<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use Closure;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * A value read in one shape, then made into what a layer holds: `uncovered`
 * read as a word, and kept as the part of the floors it sets.
 *
 * @template T of object|scalar
 * @template-covariant U of object|scalar
 *
 * @implements Shape<U>
 */
final readonly class Into implements Shape
{
    /**
     * @param Shape<T>        $shape
     * @param Closure(T): U   $into
     */
    private function __construct(private Shape $shape, private Closure $into)
    {
    }

    /**
     * @template A of object|scalar
     * @template B of object|scalar
     *
     * @param  Shape<A>       $shape
     * @param  Closure(A): B  $into
     * @return self<A, B>
     */
    public static function of(Shape $shape, Closure $into): self
    {
        return new self($shape, $into);
    }

    public function read(Node $at): Reading
    {
        $reading = $this->shape->read($at);
        $value = $reading->value();

        return $value instanceof Absent ? $reading->withoutValue() : Reading::of(($this->into)($value));
    }

    public function expected(): string
    {
        return $this->shape->expected();
    }

    public function schema(): Json
    {
        return $this->shape->schema();
    }

    public function effects(): array
    {
        return $this->shape->effects();
    }
}
