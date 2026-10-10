<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use Closure;

use function count;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * A list, each of whose entries has the same shape, and where the list is
 * distinct, each kept once.
 *
 * @template-covariant T of object|scalar
 *
 * @implements Shape<Listed<T>>
 */
final readonly class Items implements Shape
{
    private const string AT_LEAST_ONE = 'expected at least one entry, got none; leave the key out to take its default';

    /**
     * @param Shape<T>                      $item
     * @param (Closure(T): string)|Absent $identity
     * @param int<0, max>                   $least    how many entries the list must hold
     */
    private function __construct(private Shape $item, private Closure|Absent $identity, private int $least)
    {
    }

    /**
     * @template U of object|scalar
     *
     * @param  Shape<U> $item
     * @return self<U>
     */
    public static function of(Shape $item): self
    {
        return new self($item, Absent::setting(), 0);
    }

    /**
     * A list whose entries are each kept once, as their identity says: one equal to an entry before it is dropped.
     *
     * @template U of object|scalar
     *
     * @param  Shape<U>              $item
     * @param  Closure(U): string    $identity
     * @return self<U>
     */
    public static function distinct(Shape $item, Closure $identity): self
    {
        return new self($item, $identity, 0);
    }

    /**
     * A distinct list that holds at least one entry, where leaving the key out says what an empty list would.
     *
     * @template U of object|scalar
     *
     * @param  Shape<U>              $item
     * @param  Closure(U): string    $identity
     * @return self<U>
     */
    public static function distinctAtLeastOne(Shape $item, Closure $identity): self
    {
        return new self($item, $identity, 1);
    }

    public function read(Node $at): Reading
    {
        if ($at->kind() !== Kind::List && $at->kind() !== Kind::Empty) {
            return Reading::refused($at->mismatch($this->expected()));
        }

        $values = [];
        $readings = [];

        foreach ($at->items() as $item) {
            $reading = $this->item->read($item);
            $readings[] = $reading;
            $value = $reading->value();

            if (! $value instanceof Absent) {
                $values[] = $value;
            }
        }

        $problems = Reading::problemsIn(...$readings);

        $listed = Listed::of(...$values);

        return match (true) {
            $problems instanceof Invalid => Reading::invalid($problems),
            count($listed) < $this->least => Reading::refused(Problem::at($at->at(), self::AT_LEAST_ONE)),
            $this->identity instanceof Closure => Reading::of(Listed::of()->and($listed, $this->identity)),
            default => Reading::of($listed),
        };
    }

    public function expected(): string
    {
        return 'a list';
    }

    public function schema(): Json
    {
        $schema = Json::object(Member::of('type', 'array'))->with(Member::of('items', $this->item->schema()));

        return $this->least > 0 ? $schema->with(Member::of('minItems', $this->least)) : $schema;
    }

    public function effects(): array
    {
        $effects = [];

        foreach ($this->item->effects() as $path => $effect) {
            $effects[sprintf('[]%s', $path)] = $effect;
        }

        return $effects;
    }
}
