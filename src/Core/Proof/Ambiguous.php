<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;

use function sprintf;

/** A prefix that names more than one recorded mutant, with each of them. */
final readonly class Ambiguous
{
    private function __construct(private IdPrefix $sought, private MutantIds $candidates)
    {
    }

    public static function of(IdPrefix $sought, MutantIds $candidates): self
    {
        return new self($sought, $candidates);
    }

    public function candidates(): MutantIds
    {
        return $this->candidates;
    }

    /** The candidates, and how to name one of them. */
    public function why(): string
    {
        $ids = [];

        foreach ($this->candidates as $id) {
            $ids[] = $id->value();
        }

        return sprintf(
            '%s names %d recorded mutants: %s. Give more of the id.',
            $this->sought->value(),
            count($this->candidates),
            implode(', ', $ids),
        );
    }
}
