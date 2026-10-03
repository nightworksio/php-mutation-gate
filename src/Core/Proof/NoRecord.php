<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;

use function sprintf;

/** No ledger read holds a mutant the id or prefix names. */
final readonly class NoRecord
{
    private const string WHY = <<<'SAID'
        No ledger read holds a mutant %s names. Run mutation-gate on the code that has it to record it first.
        SAID;

    private function __construct(private IdPrefix $sought)
    {
    }

    public static function of(IdPrefix $sought): self
    {
        return new self($sought);
    }

    public function sought(): IdPrefix
    {
        return $this->sought;
    }

    /** That no record holds it, and how to make one. */
    public function why(): string
    {
        return sprintf(self::WHY, $this->sought->value());
    }
}
