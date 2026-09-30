<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Removal\Removable;

/**
 * What the gate found beyond each survivor's first hint, by the survivor's
 * id: the weak tests that let it through, or a callee it may be deleted
 * with (ADR-0025, decisions 6, 7, 11 and 12).
 */
final readonly class Findings
{
    /** @param array<string, WeaklyAsserted|Removable> $found by mutant id */
    private function __construct(private array $found)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, with a finding for this survivor in place of any it had. */
    public function with(MutantId $survivor, WeaklyAsserted|Removable $finding): self
    {
        $found = $this->found;
        $found[$survivor->value()] = $finding;

        return new self($found);
    }

    /** These, and those others found, the others' in place of these where both found something of a survivor. */
    public function and(self $others): self
    {
        return new self($others->found + $this->found);
    }

    /** What was found of this survivor; nothing where nothing was. */
    public function of(MutantId $survivor): WeaklyAsserted|Removable|NoFinding
    {
        return array_key_exists($survivor->value(), $this->found)
            ? $this->found[$survivor->value()]
            : NoFinding::survivor();
    }
}
