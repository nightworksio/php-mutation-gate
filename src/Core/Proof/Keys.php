<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_map;
use function count;

use Countable;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * Each considered unit's content key, or why it has none, by unit path in the
 * order they were added; a later key of a path replaces the earlier.
 */
final readonly class Keys implements Countable
{
    /** Why a unit the run did not consider has no key. */
    private const string NOT_CONSIDERED = '%s was not considered, so it has no key.';

    /** @param ByPath<Digest|Unkeyed> $keys each unit's key */
    private function __construct(private ByPath $keys)
    {
    }

    public static function none(): self
    {
        return new self(ByPath::none());
    }

    public function with(Path $unit, Digest|Unkeyed $key): self
    {
        return new self($this->keys->with($unit, $key));
    }

    /** These keys and the others', a later key of a path replacing the earlier. */
    public function and(self ...$others): self
    {
        return new self($this->keys->and(...array_map(static fn(self $other): ByPath => $other->keys, $others)));
    }

    public function keyOf(Path $unit): Digest|Unkeyed
    {
        return $this->keys->at($unit, Unkeyed::because(sprintf(self::NOT_CONSIDERED, $unit->value())));
    }

    /** These keys of these units alone, in their order; a unit without one has why. */
    public function of(Units $units): self
    {
        $keys = self::none();

        foreach ($units as $unit) {
            $keys = $keys->with($unit->path(), $this->keyOf($unit->path()));
        }

        return $keys;
    }

    /** Whether others hold the same key, or the same reason for none, of the same units in the same order. */
    public function equals(self $other): bool
    {
        return $this->written() === $other->written();
    }

    /** The units these keys are of, in the order they were added. */
    public function units(): Paths
    {
        return $this->keys->paths();
    }

    public function count(): int
    {
        return count($this->keys);
    }

    /** @return list<array{string, string, bool}> each unit, its key or why it has none, and whether it has one */
    private function written(): array
    {
        $written = [];

        foreach ($this->units() as $unit) {
            $key = $this->keyOf($unit);
            $written[] = $key instanceof Digest
                ? [$unit->value(), $key->value(), true]
                : [$unit->value(), $key->why(), false];
        }

        return $written;
    }
}
