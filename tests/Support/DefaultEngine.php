<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGateDefault\DefaultExtension;

/** The engine of the gate's default mutator set, as the default extension registers it. */
final readonly class DefaultEngine
{
    public static function made(): Engine
    {
        $set = new DefaultExtension()->extend(Extensions::of(Origin::of('nightworksio/mutation-gate')))
            ->registered(ExtensionPoint::MutatorSet, MutatorSet::defaultName());

        return Enabled::of($set instanceof MutatorSet ? $set : MutatorSet::of())->engine();
    }
}
