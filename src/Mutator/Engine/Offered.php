<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

/**
 * Which nodes a mutator is offered, and how a removed statement goes.
 *
 * @internal the engine's and the testing kit's own
 */
enum Offered
{
    /** Every node, and a removed statement is gone: as Pest offers, and as the gate's own engine does. */
    case Everywhere;

    /** Only nodes in a class method or on its signature, and a removed statement is emptied: as Infection offers. */
    case InClassMethods;
}
