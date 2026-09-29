<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_key_exists;
use function count;

use Countable;

/** Mutants by the gate's id, each once. */
final readonly class MutantIds implements Countable
{
    /** @param array<string, MutantId> $ids by value */
    private function __construct(private array $ids)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(MutantId ...$ids): self
    {
        $collected = [];

        foreach ($ids as $id) {
            $collected[$id->value()] = $id;
        }

        return new self($collected);
    }

    public function has(MutantId $id): bool
    {
        return array_key_exists($id->value(), $this->ids);
    }

    public function count(): int
    {
        return count($this->ids);
    }
}
