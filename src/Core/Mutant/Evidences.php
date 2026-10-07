<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_key_exists;
use function array_replace;
use function count;

use Countable;

/**
 * The evidence of a run's kills, by each mutant's id, the last given for a
 * mutant winning; a mutant it holds none of has none.
 */
final readonly class Evidences implements Countable
{
    /** @param array<array-key, Evidence> $byId by each mutant's id, kept as a number where it reads as one */
    private function __construct(private array $byId)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, and a mutant's evidence; evidence that says nothing is not kept. */
    public function with(MutantId $id, Evidence $evidence): self
    {
        $byId = $this->byId;
        unset($byId[$id->value()]);

        if (! $evidence->isEmpty()) {
            $byId[$id->value()] = $evidence;
        }

        return new self($byId);
    }

    /** These, and those, those winning for a mutant both hold. */
    public function and(self $those): self
    {
        return new self(array_replace($this->byId, $those->byId));
    }

    /**
     * These, kept only for each mutant of the after that is the very mutant of
     * the before, so a mutant put in another's place has none of its evidence.
     */
    public function keptFor(Mutants $before, Mutants $after): self
    {
        $was = [];
        $kept = [];

        foreach ($before as $mutant) {
            $was[$mutant->id()->value()] = $mutant;
        }

        foreach ($after as $mutant) {
            $id = $mutant->id()->value();

            if (array_key_exists($id, $this->byId) && array_key_exists($id, $was) && $was[$id] === $mutant) {
                $kept[$id] = $this->byId[$id];
            }
        }

        return new self($kept);
    }

    /** A mutant's evidence, none where it holds none. */
    public function of(MutantId $id): Evidence
    {
        return array_key_exists($id->value(), $this->byId) ? $this->byId[$id->value()] : Evidence::none();
    }

    public function count(): int
    {
        return count($this->byId);
    }
}
