<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use NightWorksIO\MutationGate\Mutator\Mutator;

/**
 * One mutator's change to one node, with the mutator that made it.
 *
 * @internal the engine's own
 */
final readonly class Change
{
    private function __construct(private Mutator $mutator, private Edit $edit)
    {
    }

    public static function of(Mutator $mutator, Edit $edit): self
    {
        return new self($mutator, $edit);
    }

    public function mutator(): Mutator
    {
        return $this->mutator;
    }

    public function edit(): Edit
    {
        return $this->edit;
    }
}
