<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;
use function array_keys;
use function array_map;
use function count;

use Countable;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function sprintf;

/**
 * Each considered unit's content key, or why it has none, by unit path in the
 * order they were added; a later key of a path replaces the earlier.
 */
final readonly class Keys implements Countable
{
    /** @param array<string, Digest|Unkeyed> $keys by unit path */
    private function __construct(private array $keys)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function with(Path $unit, Digest|Unkeyed $key): self
    {
        $keys = $this->keys;
        $keys[$unit->value()] = $key;

        return new self($keys);
    }

    public function keyOf(Path $unit): Digest|Unkeyed
    {
        return array_key_exists($unit->value(), $this->keys)
            ? $this->keys[$unit->value()]
            : Unkeyed::because(sprintf('%s was not considered, so it has no key.', $unit->value()));
    }

    /** The units these keys are of, in the order they were added. */
    public function units(): Paths
    {
        return Paths::of(...array_map(
            static fn(int|string $unit): Path => Path::of(sprintf('%s', $unit)),
            array_keys($this->keys),
        ));
    }

    public function count(): int
    {
        return count($this->keys);
    }
}
