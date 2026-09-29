<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_is_list;
use function is_array;

use NightWorksIO\MutationGate\Core\Config\Absent;

use function sprintf;

/** A list, each of whose entries is read the same way. */
final readonly class Items implements Node
{
    private function __construct(private Node $item)
    {
    }

    public static function of(Node $item): self
    {
        return new self($item);
    }

    public function read(mixed $value, string $at): Reading
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return Reading::mismatch($at, $this->expected(), $value);
        }

        $values = [];
        $shown = [];
        $results = [];
        $problems = [];

        foreach ($value as $index => $item) {
            $reading = $this->item->read($item, At::index($at, $index));
            $values[] = $reading->value();
            $shown[] = $reading->shown();
            $results[] = $reading->results();
            $problems = [...$problems, ...$reading->problems()];
        }

        return match (true) {
            $problems !== [] => Reading::refused($problems),
            $results !== [] && ! $results[0] instanceof Absent => Reading::affecting($values, $shown, $results),
            default => Reading::of($values, $shown),
        };
    }

    public function expected(): string
    {
        return 'a list';
    }

    public function schema(): array
    {
        return ['type' => 'array', 'items' => $this->item->schema()];
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
