<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Testing;

/**
 * Which nodes a runner offers a mutator, and how it removes a statement.
 *
 * @internal the testing kit's own
 */
enum Offered
{
    /** Pest offers every node, and removes a statement. */
    case Everywhere;

    /** Infection offers only nodes in a class method or on its signature, and empties a statement it removes. */
    case InClassMethods;
}
