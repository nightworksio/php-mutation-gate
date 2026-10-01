<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use NightWorksIO\MutationGate\Mutator\MutatorSet;

/**
 * The engine over a set's mutators, each constructed with no arguments, as
 * the set registers them by class (ADR-0021). Constructing a mutator by the
 * name a set holds is this file's whole purpose.
 */
final readonly class SetEngine
{
    public static function of(MutatorSet $set): Engine
    {
        $mutators = [];

        foreach ($set as $class) {
            $mutators[] = new $class();
        }

        return Engine::with(...$mutators);
    }
}
