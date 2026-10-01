<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_combine;
use function array_intersect_key;
use function array_map;
use function array_slice;
use function array_values;
use function mb_strtolower;

/**
 * Fully qualified names of classes and functions, each once and in lower case,
 * because PHP resolves both without regard to case.
 */
final readonly class Names
{
    /** The tokens PHP spells a name with, but for one relative to the namespace. */
    public const array UNRELATIVE = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /** The tokens PHP spells a name with. */
    public const array TOKENS = [...self::UNRELATIVE, T_NAME_RELATIVE];

    /** @param array<string, string> $names each name, by itself */
    private function __construct(private array $names)
    {
    }

    public static function of(string ...$names): self
    {
        $lower = array_map(mb_strtolower(...), $names);

        return new self(array_combine($lower, $lower));
    }

    public function merge(self ...$others): self
    {
        $names = $this->names;

        foreach ($others as $other) {
            $names += $other->names;
        }

        return new self($names);
    }

    /** @return list<string> each name, in lower case */
    public function all(): array
    {
        return array_values($this->names);
    }

    /** The first of these names alone, which PHP tries before the others; none where there are none. */
    public function first(): self
    {
        return new self(array_slice($this->names, 0, 1));
    }

    /** Whether these and the others share a name. */
    public function meet(self $other): bool
    {
        return array_intersect_key($this->names, $other->names) !== [];
    }
}
