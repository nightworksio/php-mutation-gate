<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * A list, each of whose entries has the same shape.
 *
 * @template-covariant T of object|scalar
 *
 * @implements Shape<Listed<T>>
 */
final readonly class Items implements Shape
{
    /** @param Shape<T> $item */
    private function __construct(private Shape $item)
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
        return new self($item);
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

        return $problems instanceof Invalid ? Reading::invalid($problems) : Reading::of(Listed::of($values));
    }

    public function expected(): string
    {
        return 'a list';
    }

    public function schema(): Json
    {
        return Json::object()->with('type', 'array')->with('items', $this->item->schema());
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
