<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Azure;

/**
 * The statuses of a pull request's thread the gate sets, as Azure Repos numbers them.
 *
 * @internal
 */
enum ThreadStatus: int
{
    case Active = 1;
    case Closed = 4;
}
