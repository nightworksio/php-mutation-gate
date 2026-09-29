<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_combine;
use function array_intersect_key;
use function array_map;
use function mb_strtolower;

/**
 * Fully qualified names of classes and functions, each once and in lower case,
 * because PHP resolves both without regard to case.
 */
final readonly class Names
{
    /** @param array<string, string> $names each name, by itself */
    private function __construct(private array $names)
    {
    }

    public static function of(string ...$names): self
    {
        $lower = array_map(mb_strtolower(...), $names);

        return new self(array_combine($lower, $lower));
    }

    public function merge(self $other): self
    {
        return new self($this->names + $other->names);
    }

    /** Whether these and the others share a name. */
    public function meet(self $other): bool
    {
        return array_intersect_key($this->names, $other->names) !== [];
    }
}
